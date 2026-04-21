<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

/**
 * UCP Direct Checkout.
 *
 * Creates an order in a single call and returns a payment URL so the
 * buyer can complete payment externally (e.g. inside a Shopwalk agent flow).
 *
 * @api
 */
interface DirectCheckoutInterface
{
    /**
     * Create order directly and return payment URL.
     *
     * Input $data structure:
     *  - items             (array)  Each: {product_id (int), quantity (int), variant_id? (int)}
     *  - customer          (array)  {email, first_name, last_name, phone?}
     *  - shipping_address  (array)  {address_1, city, state, postcode, country}
     *  - return_url        (string) URL to redirect after payment
     *  - metadata          (array)  Optional key-value pairs
     *
     * Response structure (monetary values in cents):
     *  - order_id       (int)
     *  - order_key      (string)
     *  - status         (string)
     *  - payment_url    (string)
     *  - subtotal       (int)     Cents
     *  - shipping_total (int)     Cents
     *  - tax_total      (int)     Cents
     *  - total          (int)     Cents
     *  - currency       (string)  ISO 4217
     *  - items          (array)   Line items with resolved product details
     *  - expires_at     (string)  ISO 8601 expiry for the payment URL
     *
     * @param mixed[] $data Checkout payload.
     * @return mixed[]
     */
    public function create(array $data): array;
}
