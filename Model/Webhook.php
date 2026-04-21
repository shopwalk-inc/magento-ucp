<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Shopwalk\Ucp\Api\WebhookInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * CRUD for webhook subscriptions.
 *
 * Creates subscriptions with HTTPS-only callback URLs, validates event
 * types, generates HMAC signing secrets, and stores everything in
 * shopwalk_ucp_webhook_subscriptions.
 */
class Webhook implements WebhookInterface
{
    private const TABLE = 'shopwalk_ucp_webhook_subscriptions';

    /**
     * Event types this module supports.
     */
    private const ALLOWED_EVENT_TYPES = [
        'order.created',
        'order.updated',
        'order.completed',
        'order.canceled',
        'order.refunded',
        'product.created',
        'product.updated',
        'product.deleted',
        'checkout.completed',
        'checkout.canceled',
        'inventory.updated',
    ];

    private AdapterInterface $connection;

    public function __construct(
        private ResourceConnection $resource,
        private DateTime           $dateTime
    ) {
        $this->connection = $resource->getConnection();
    }

    /* ------------------------------------------------------------------
     *  CREATE
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function create(array $data): array
    {
        $url        = $data['url'] ?? '';
        $eventTypes = $data['event_types'] ?? [];

        // Validate URL (must be HTTPS)
        if (empty($url)) {
            return UcpResponse::error('invalid_request', 'url is required.');
        }

        $parsed = parse_url($url);
        if (!$parsed || ($parsed['scheme'] ?? '') !== 'https') {
            return UcpResponse::error(
                'invalid_url',
                'Webhook callback URL must use HTTPS.'
            );
        }

        // Validate event types
        if (empty($eventTypes) || !is_array($eventTypes)) {
            return UcpResponse::error('invalid_request', 'event_types is required and must be a non-empty array.');
        }

        $invalid = array_diff($eventTypes, self::ALLOWED_EVENT_TYPES);
        if (!empty($invalid)) {
            return UcpResponse::error(
                'invalid_event_type',
                sprintf(
                    'Unsupported event types: %s. Allowed: %s',
                    implode(', ', $invalid),
                    implode(', ', self::ALLOWED_EVENT_TYPES)
                )
            );
        }

        // Generate subscription ID and HMAC secret
        $subscriptionId = 'wh_' . bin2hex(random_bytes(16));
        $secret         = 'whsec_' . bin2hex(random_bytes(24));
        $now            = $this->dateTime->gmtDate();

        $row = [
            'id'          => $subscriptionId,
            'client_id'   => $data['client_id'] ?? '',
            'callback_url' => $url,
            'event_types' => json_encode(array_values($eventTypes)),
            'secret'      => $secret,
            'created_at'  => $now,
        ];

        $this->connection->insert(
            $this->resource->getTableName(self::TABLE),
            $row
        );

        return UcpResponse::ok([
            'id'          => $subscriptionId,
            'url'         => $url,
            'event_types' => array_values($eventTypes),
            'secret'      => $secret,
            'created_at'  => $now,
        ]);
    }

    /* ------------------------------------------------------------------
     *  GET
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function get(string $id): array
    {
        $row = $this->loadSubscription($id);
        if (!$row) {
            return UcpResponse::error('not_found', 'Webhook subscription not found.');
        }

        return UcpResponse::ok($this->formatSubscription($row));
    }

    /* ------------------------------------------------------------------
     *  DELETE
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function delete(string $id): array
    {
        $row = $this->loadSubscription($id);
        if (!$row) {
            return UcpResponse::error('not_found', 'Webhook subscription not found.');
        }

        $this->connection->delete(
            $this->resource->getTableName(self::TABLE),
            ['id = ?' => $id]
        );

        return UcpResponse::ok(['deleted' => true, 'id' => $id]);
    }

    /* ------------------------------------------------------------------
     *  PRIVATE HELPERS
     * ----------------------------------------------------------------*/

    /**
     * @return mixed[]|null
     */
    private function loadSubscription(string $id): ?array
    {
        $select = $this->connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('id = ?', $id);

        $row = $this->connection->fetchRow($select);

        return $row ?: null;
    }

    /**
     * @param mixed[] $row
     * @return mixed[]
     */
    private function formatSubscription(array $row): array
    {
        return [
            'id'          => $row['id'],
            'url'         => $row['callback_url'],
            'event_types' => json_decode($row['event_types'], true) ?: [],
            'secret'      => $row['secret'],
            'created_at'  => $row['created_at'],
        ];
    }
}
