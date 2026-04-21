<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Helper;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Shopwalk\Ucp\Model\UcpResponse;

/**
 * Builds full order payloads and manages webhook delivery with exponential backoff.
 */
class WebhookDelivery
{
    /**
     * Maximum delivery attempts before marking as permanently failed.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Backoff intervals in minutes: attempt 1 => 1m, 2 => 2m, 3 => 4m, 4 => 8m, 5 => 16m.
     */
    private const BACKOFF_MINUTES = [1, 2, 4, 8, 16];

    /**
     * HTTP timeout for webhook delivery in seconds.
     */
    private const HTTP_TIMEOUT = 15;

    /**
     * UCP Agent header value.
     */
    private const UCP_AGENT = 'Shopwalk-UCP-Magento/1.0';

    public function __construct(
        private ResourceConnection $resourceConnection,
        private OrderRepositoryInterface $orderRepository,
        private CurlFactory $curlFactory,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Build the full UCP order payload and queue a webhook event for each
     * matching subscription.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $eventType  UCP event name (e.g. order.created, order.processing)
     */
    public function queueEvent($order, string $eventType): void
    {
        $connection = $this->resourceConnection->getConnection();
        $payload = $this->buildOrderPayload($order, $eventType);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Find all webhook subscriptions that match this event type
        $subscriptionTable = $this->resourceConnection->getTableName('shopwalk_ucp_webhook_subscriptions');
        $select = $connection->select()
            ->from($subscriptionTable, ['id', 'event_types'])
            ->where('client_id = ?', $order->getData('shopwalk_ucp_client_id') ?? '');

        $subscriptions = $connection->fetchAll($select);

        $queueTable = $this->resourceConnection->getTableName('shopwalk_ucp_webhook_queue');

        foreach ($subscriptions as $subscription) {
            $eventTypes = json_decode($subscription['event_types'], true) ?? [];

            // Check if the subscription listens for this event type or uses a wildcard
            if (!in_array($eventType, $eventTypes, true) && !in_array('*', $eventTypes, true)) {
                continue;
            }

            $connection->insert($queueTable, [
                'subscription_id' => $subscription['id'],
                'event_type'      => $eventType,
                'payload'         => $payloadJson,
                'attempts'        => 0,
                'next_attempt_at' => null,
                'created_at'      => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Attempt to deliver a single queued webhook item.
     *
     * @param int $queueId  Queue entry ID.
     * @return bool True on successful delivery.
     */
    public function deliver(int $queueId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName('shopwalk_ucp_webhook_queue');
        $subscriptionTable = $this->resourceConnection->getTableName('shopwalk_ucp_webhook_subscriptions');

        // Load queue item
        $queueItem = $connection->fetchRow(
            $connection->select()
                ->from($queueTable)
                ->where('id = ?', $queueId)
        );

        if (!$queueItem) {
            $this->logger->warning(sprintf('Shopwalk UCP: Webhook queue item %d not found.', $queueId));
            return false;
        }

        // Load subscription (for callback_url and secret)
        $subscription = $connection->fetchRow(
            $connection->select()
                ->from($subscriptionTable)
                ->where('id = ?', $queueItem['subscription_id'])
        );

        if (!$subscription) {
            // Subscription was deleted; mark queue item as failed
            $connection->update($queueTable, [
                'failed_at'  => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'last_error' => 'Subscription not found (deleted).',
            ], ['id = ?' => $queueId]);
            return false;
        }

        $body = $queueItem['payload'];
        $webhookId = sprintf('wh_evt_%d', $queueId);
        $timestamp = time();

        // Build signature headers
        $signatureHeaders = $this->buildSignature(
            $body,
            $subscription['secret'],
            $webhookId,
            $timestamp
        );

        $headers = array_merge($signatureHeaders, [
            'Content-Type'        => 'application/json',
            'Webhook-Id'          => $webhookId,
            'Webhook-Timestamp'   => (string) $timestamp,
            'UCP-Agent'           => self::UCP_AGENT,
            'UCP-Event'           => $queueItem['event_type'],
        ]);

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::HTTP_TIMEOUT);

            foreach ($headers as $name => $value) {
                $curl->addHeader($name, $value);
            }

            $curl->post($subscription['callback_url'], $body);
            $httpStatus = $curl->getStatus();

            if ($httpStatus >= 200 && $httpStatus < 300) {
                // Success
                $connection->update($queueTable, [
                    'delivered_at' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'attempts'     => (int) $queueItem['attempts'] + 1,
                ], ['id = ?' => $queueId]);

                return true;
            }

            // Non-2xx response counts as failure
            $error = sprintf('HTTP %d: %s', $httpStatus, substr($curl->getBody(), 0, 500));
            $this->recordFailure($queueId, $queueItem, $error);
            return false;
        } catch (\Exception $e) {
            $this->recordFailure($queueId, $queueItem, $e->getMessage());
            return false;
        }
    }

    /**
     * Build the HMAC signature headers for webhook payload verification.
     *
     * @param string $body       JSON payload body.
     * @param string $secret     HMAC signing secret.
     * @param string $webhookId  Unique webhook event identifier.
     * @param int    $timestamp  Unix timestamp.
     * @return array{Content-Digest: string, Signature-Input: string, Signature: string}
     */
    private function buildSignature(string $body, string $secret, string $webhookId, int $timestamp): array
    {
        $digest = base64_encode(hash('sha256', $body, true));
        $signedContent = $webhookId . '.' . $timestamp . '.' . $body;
        $signature = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));

        return [
            'Content-Digest'  => 'sha-256=:' . $digest . ':',
            'Signature-Input' => 'sig1=("content-digest" "webhook-id" "webhook-timestamp");keyid="store-hmac";alg="hmac-sha256"',
            'Signature'       => 'sig1=:' . $signature . ':',
        ];
    }

    /**
     * Build the full UCP order entity payload for the webhook event.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $eventType
     * @return array
     */
    private function buildOrderPayload($order, string $eventType): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'product_id'  => (int) $item->getProductId(),
                'sku'         => $item->getSku(),
                'name'        => $item->getName(),
                'quantity'    => (int) $item->getQtyOrdered(),
                'unit_price'  => (int) round($item->getPrice() * 100),
                'total'       => (int) round($item->getRowTotal() * 100),
            ];
        }

        $shippingAddress = $order->getShippingAddress();
        $fulfillment = [];
        if ($shippingAddress) {
            $fulfillment = [
                'method'  => $order->getShippingMethod() ?? '',
                'address' => [
                    'address_1' => $shippingAddress->getStreetLine(1),
                    'address_2' => $shippingAddress->getStreetLine(2),
                    'city'      => $shippingAddress->getCity(),
                    'state'     => $shippingAddress->getRegion(),
                    'postcode'  => $shippingAddress->getPostcode(),
                    'country'   => $shippingAddress->getCountryId(),
                ],
            ];
        }

        return [
            'event'      => $eventType,
            'timestamp'  => (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'data'       => [
                'order_id'       => (int) $order->getEntityId(),
                'increment_id'   => $order->getIncrementId(),
                'status'         => $order->getStatus(),
                'currency'       => $order->getOrderCurrencyCode(),
                'subtotal'       => (int) round($order->getSubtotal() * 100),
                'shipping_total' => (int) round($order->getShippingAmount() * 100),
                'tax_total'      => (int) round($order->getTaxAmount() * 100),
                'total'          => (int) round($order->getGrandTotal() * 100),
                'items'          => $items,
                'buyer'          => [
                    'email'      => $order->getCustomerEmail(),
                    'first_name' => $order->getCustomerFirstname(),
                    'last_name'  => $order->getCustomerLastname(),
                ],
                'fulfillment'    => $fulfillment,
                'created_at'     => $order->getCreatedAt(),
                'updated_at'     => $order->getUpdatedAt(),
            ],
        ];
    }

    /**
     * Record a delivery failure with exponential backoff scheduling.
     *
     * @param int   $queueId
     * @param array $queueItem Current queue row data.
     * @param string $error    Error message.
     */
    private function recordFailure(int $queueId, array $queueItem, string $error): void
    {
        $connection = $this->resourceConnection->getConnection();
        $queueTable = $this->resourceConnection->getTableName('shopwalk_ucp_webhook_queue');

        $attempts = (int) $queueItem['attempts'] + 1;
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $updateData = [
            'attempts'   => $attempts,
            'last_error' => substr($error, 0, 65535),
        ];

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Permanently failed
            $updateData['failed_at'] = $now->format('Y-m-d H:i:s');
            $this->logger->error(
                sprintf('Shopwalk UCP: Webhook queue item %d permanently failed after %d attempts: %s', $queueId, $attempts, $error)
            );
        } else {
            // Schedule next attempt with exponential backoff
            $backoffMinutes = self::BACKOFF_MINUTES[$attempts - 1] ?? 16;
            $nextAttempt = (clone $now)->modify(sprintf('+%d minutes', $backoffMinutes));
            $updateData['next_attempt_at'] = $nextAttempt->format('Y-m-d H:i:s');
        }

        $connection->update($queueTable, $updateData, ['id = ?' => $queueId]);
    }
}
