<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

interface CheckoutInterface
{
    /**
     * POST /rest/V1/ucp/checkout-sessions — create a UCP checkout session.
     *
     * @param mixed[] $data Request payload (product_id, variant_id, quantity, shipping_address).
     * @return mixed[] { session_id, status, subtotal, shipping, tax, total, currency, expires_at, ucp }
     */
    public function create(array $data): array;

    /**
     * GET /rest/V1/ucp/checkout-sessions/:sessionId — current status.
     *
     * @param string $sessionId
     * @return mixed[]
     */
    public function getStatus(string $sessionId): array;

    /**
     * POST /rest/V1/ucp/checkout-sessions/:sessionId/complete — convert quote to order.
     *
     * @param string $sessionId
     * @param mixed[] $data Completion payload (payment_id, paid_amount, currency).
     * @return mixed[]
     */
    public function complete(string $sessionId, array $data): array;
}
