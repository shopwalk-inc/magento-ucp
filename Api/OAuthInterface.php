<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

/**
 * UCP OAuth token management.
 *
 * Handles OAuth 2.0 token exchange (authorization_code and refresh_token
 * grant types) and token revocation per RFC 7009.
 *
 * @api
 */
interface OAuthInterface
{
    /**
     * Exchange credentials for an access token.
     *
     * Supported grant types:
     *  - authorization_code: requires code, redirect_uri, client_id, client_secret
     *  - refresh_token:      requires refresh_token, client_id, client_secret
     *
     * Response structure:
     *  - access_token  (string)
     *  - token_type    (string)  "Bearer"
     *  - expires_in    (int)     Seconds until expiry
     *  - refresh_token (string)  Only for authorization_code grant
     *  - scope         (string)
     *
     * @param string      $grantType    The OAuth grant type.
     * @param string|null $code         Authorization code (authorization_code grant).
     * @param string|null $redirectUri  Redirect URI used in the authorization request.
     * @param string|null $refreshToken Refresh token (refresh_token grant).
     * @param string|null $clientId     Client identifier.
     * @param string|null $clientSecret Client secret.
     * @return mixed[]
     */
    public function token(
        string $grantType,
        ?string $code = null,
        ?string $redirectUri = null,
        ?string $refreshToken = null,
        ?string $clientId = null,
        ?string $clientSecret = null
    ): array;

    /**
     * Revoke an access or refresh token (RFC 7009).
     *
     * @param string $token The token to revoke.
     * @return mixed[]
     */
    public function revoke(string $token): array;
}
