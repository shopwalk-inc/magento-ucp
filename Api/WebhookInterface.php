<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

/**
 * UCP Webhook subscription management.
 *
 * Allows external consumers (e.g. Shopwalk platform) to subscribe to
 * store events via webhook callbacks.
 *
 * @api
 */
interface WebhookInterface
{
    /**
     * Create a webhook subscription.
     *
     * Input $data structure:
     *  - url          (string)   Callback URL receiving POST payloads
     *  - event_types  (string[]) Events to subscribe to (e.g. order.created, product.updated)
     *
     * Response includes the created subscription with:
     *  - id           (string)
     *  - url          (string)
     *  - event_types  (string[])
     *  - secret       (string)   HMAC signing secret for payload verification
     *  - created_at   (string)   ISO 8601
     *
     * @param mixed[] $data Subscription payload.
     * @return mixed[]
     */
    public function create(array $data): array;

    /**
     * Get webhook subscription by ID.
     *
     * @param string $id Subscription identifier.
     * @return mixed[]
     */
    public function get(string $id): array;

    /**
     * Delete webhook subscription.
     *
     * @param string $id Subscription identifier.
     * @return mixed[]
     */
    public function delete(string $id): array;
}
