<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Shopwalk\Ucp\Api\OAuthInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * OAuth 2.0 token endpoint (authorization_code + refresh_token grants)
 * and RFC 7009 revocation.
 *
 * Tokens are stored hashed (PASSWORD_BCRYPT). The plaintext is returned
 * only in the token response and never persisted.
 */
class OAuth implements OAuthInterface
{
    private const CLIENTS_TABLE = 'shopwalk_ucp_oauth_clients';
    private const TOKENS_TABLE  = 'shopwalk_ucp_oauth_tokens';

    private const ACCESS_TOKEN_TTL_SECONDS  = 3600;      // 1 hour
    private const REFRESH_TOKEN_TTL_SECONDS = 2592000;    // 30 days
    private const AUTH_CODE_TTL_SECONDS     = 600;        // 10 minutes

    private AdapterInterface $connection;

    public function __construct(
        private ResourceConnection $resource,
        private DateTime           $dateTime
    ) {
        $this->connection = $resource->getConnection();
    }

    /* ------------------------------------------------------------------
     *  TOKEN
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function token(
        string  $grantType,
        ?string $code = null,
        ?string $redirectUri = null,
        ?string $refreshToken = null,
        ?string $clientId = null,
        ?string $clientSecret = null
    ): array {
        // Validate client credentials
        if (!$clientId || !$clientSecret) {
            return UcpResponse::error('invalid_client', 'client_id and client_secret are required.');
        }

        $client = $this->loadClient($clientId);
        if (!$client) {
            return UcpResponse::error('invalid_client', 'Client not found.');
        }

        if (!password_verify($clientSecret, $client['client_secret_hash'])) {
            return UcpResponse::error('invalid_client', 'Invalid client credentials.');
        }

        return match ($grantType) {
            'authorization_code' => $this->handleAuthorizationCode(
                $code,
                $redirectUri,
                $client
            ),
            'refresh_token' => $this->handleRefreshToken(
                $refreshToken,
                $client
            ),
            default => UcpResponse::error(
                'unsupported_grant_type',
                sprintf('Grant type "%s" is not supported.', $grantType)
            ),
        };
    }

    /* ------------------------------------------------------------------
     *  REVOKE (RFC 7009)
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function revoke(string $token): array
    {
        // Find the token by verifying against all non-revoked tokens
        $select = $this->connection->select()
            ->from($this->resource->getTableName(self::TOKENS_TABLE))
            ->where('revoked_at IS NULL')
            ->where('token_type IN (?)', ['access', 'refresh']);

        $rows = $this->connection->fetchAll($select);

        foreach ($rows as $row) {
            if (password_verify($token, $row['token_hash'])) {
                $this->connection->update(
                    $this->resource->getTableName(self::TOKENS_TABLE),
                    ['revoked_at' => $this->dateTime->gmtDate()],
                    ['id = ?' => $row['id']]
                );

                return UcpResponse::ok(['revoked' => true]);
            }
        }

        // Per RFC 7009, respond with success even if the token is not found
        return UcpResponse::ok(['revoked' => true]);
    }

    /* ------------------------------------------------------------------
     *  GRANT HANDLERS
     * ----------------------------------------------------------------*/

    /**
     * Exchange an authorization code for access + refresh tokens.
     *
     * @param mixed[] $client
     * @return mixed[]
     */
    private function handleAuthorizationCode(
        ?string $code,
        ?string $redirectUri,
        array   $client
    ): array {
        if (!$code) {
            return UcpResponse::error('invalid_request', 'Authorization code is required.');
        }

        // Find the authorization code token
        $codeRow = $this->findValidToken($code, 'authorization_code', $client['client_id']);
        if (!$codeRow) {
            return UcpResponse::error('invalid_grant', 'Invalid or expired authorization code.');
        }

        // Validate redirect_uri if one was used during authorization
        $allowedUris = json_decode($client['redirect_uris'], true) ?: [];
        if ($redirectUri && !empty($allowedUris) && !in_array($redirectUri, $allowedUris, true)) {
            return UcpResponse::error('invalid_grant', 'redirect_uri does not match.');
        }

        // Revoke the authorization code (single use)
        $this->connection->update(
            $this->resource->getTableName(self::TOKENS_TABLE),
            ['revoked_at' => $this->dateTime->gmtDate()],
            ['id = ?' => $codeRow['id']]
        );

        // Issue access + refresh tokens
        $scopes = $codeRow['scopes'];
        $userId = $codeRow['user_id'];

        $accessToken  = $this->generateToken();
        $refreshToken = $this->generateToken();

        $this->storeToken(
            'access',
            $accessToken,
            $client['client_id'],
            $userId,
            $scopes,
            self::ACCESS_TOKEN_TTL_SECONDS
        );

        $this->storeToken(
            'refresh',
            $refreshToken,
            $client['client_id'],
            $userId,
            $scopes,
            self::REFRESH_TOKEN_TTL_SECONDS
        );

        $scopeString = is_string($scopes) ? $scopes : implode(' ', json_decode($scopes, true) ?: []);

        return [
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
            'refresh_token' => $refreshToken,
            'scope'         => $scopeString,
        ];
    }

    /**
     * Exchange a refresh token for a new access token.
     *
     * @param mixed[] $client
     * @return mixed[]
     */
    private function handleRefreshToken(?string $refreshToken, array $client): array
    {
        if (!$refreshToken) {
            return UcpResponse::error('invalid_request', 'refresh_token is required.');
        }

        $tokenRow = $this->findValidToken($refreshToken, 'refresh', $client['client_id']);
        if (!$tokenRow) {
            return UcpResponse::error('invalid_grant', 'Invalid or expired refresh token.');
        }

        // Issue a new access token
        $accessToken = $this->generateToken();

        $this->storeToken(
            'access',
            $accessToken,
            $client['client_id'],
            $tokenRow['user_id'],
            $tokenRow['scopes'],
            self::ACCESS_TOKEN_TTL_SECONDS
        );

        $scopeString = is_string($tokenRow['scopes'])
            ? $tokenRow['scopes']
            : implode(' ', json_decode($tokenRow['scopes'], true) ?: []);

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => self::ACCESS_TOKEN_TTL_SECONDS,
            'scope'        => $scopeString,
        ];
    }

    /* ------------------------------------------------------------------
     *  PRIVATE HELPERS
     * ----------------------------------------------------------------*/

    /**
     * Load an OAuth client by client_id.
     *
     * @return mixed[]|null
     */
    private function loadClient(string $clientId): ?array
    {
        $select = $this->connection->select()
            ->from($this->resource->getTableName(self::CLIENTS_TABLE))
            ->where('client_id = ?', $clientId);

        $row = $this->connection->fetchRow($select);

        return $row ?: null;
    }

    /**
     * Find a valid (non-revoked, non-expired) token by verifying the
     * plaintext against stored hashes.
     *
     * @return mixed[]|null
     */
    private function findValidToken(string $plainToken, string $type, string $clientId): ?array
    {
        $now = $this->dateTime->gmtDate();

        $select = $this->connection->select()
            ->from($this->resource->getTableName(self::TOKENS_TABLE))
            ->where('token_type = ?', $type)
            ->where('client_id = ?', $clientId)
            ->where('revoked_at IS NULL')
            ->where('expires_at > ?', $now);

        $rows = $this->connection->fetchAll($select);

        foreach ($rows as $row) {
            if (password_verify($plainToken, $row['token_hash'])) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Generate a cryptographically random token string.
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash and store a token in the database.
     */
    private function storeToken(
        string  $type,
        string  $plainToken,
        string  $clientId,
        ?int    $userId,
        string  $scopes,
        int     $ttlSeconds
    ): void {
        $now       = $this->dateTime->gmtDate();
        $expiresAt = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            strtotime('+' . $ttlSeconds . ' seconds')
        );

        $this->connection->insert(
            $this->resource->getTableName(self::TOKENS_TABLE),
            [
                'token_type' => $type,
                'token_hash' => password_hash($plainToken, PASSWORD_BCRYPT),
                'client_id'  => $clientId,
                'user_id'    => $userId,
                'scopes'     => $scopes,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ]
        );
    }
}
