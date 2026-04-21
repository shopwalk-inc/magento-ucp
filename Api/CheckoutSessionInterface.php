<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

/**
 * UCP Checkout Session management.
 *
 * Manages the lifecycle of a checkout session: creation, retrieval,
 * update, completion (finalize order), and cancellation.
 *
 * @api
 */
interface CheckoutSessionInterface
{
    /**
     * Create a new checkout session.
     *
     * @param mixed[] $data Session payload (items, customer, shipping, etc.)
     * @return mixed[]
     */
    public function create(array $data): array;

    /**
     * Get checkout session by ID.
     *
     * @param string $id Session identifier.
     * @return mixed[]
     */
    public function get(string $id): array;

    /**
     * Update checkout session (full replace).
     *
     * @param string $id   Session identifier.
     * @param mixed[] $data Updated session payload.
     * @return mixed[]
     */
    public function update(string $id, array $data): array;

    /**
     * Complete checkout session (finalize order).
     *
     * @param string $id   Session identifier.
     * @param mixed[] $data Optional completion data (payment confirmation, etc.)
     * @return mixed[]
     */
    public function complete(string $id, array $data = []): array;

    /**
     * Cancel checkout session.
     *
     * @param string $id Session identifier.
     * @return mixed[]
     */
    public function cancel(string $id): array;
}
