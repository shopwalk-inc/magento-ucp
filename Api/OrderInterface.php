<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

/**
 * UCP Order retrieval.
 *
 * Provides read-only access to orders placed through UCP checkout sessions,
 * including listing, detail retrieval, and fulfillment event history.
 *
 * @api
 */
interface OrderInterface
{
    /**
     * List orders for the authenticated buyer.
     *
     * @param int|null $limit  Maximum number of results (default 25).
     * @param int|null $offset Number of results to skip (default 0).
     * @return mixed[]
     */
    public function getList(?int $limit = 25, ?int $offset = 0): array;

    /**
     * Get order by ID.
     *
     * @param int $id Order entity ID.
     * @return mixed[]
     */
    public function get(int $id): array;

    /**
     * Get fulfillment events for an order.
     *
     * @param int $id Order entity ID.
     * @return mixed[]
     */
    public function getEvents(int $id): array;
}
