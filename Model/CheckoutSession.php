<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Shopwalk\Ucp\Api\CheckoutSessionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Full checkout-session lifecycle: create, get, update, complete, cancel.
 *
 * Sessions are persisted in shopwalk_ucp_checkout_sessions. Completing a
 * session creates a real Magento order via QuoteManagement::submit().
 */
class CheckoutSession implements CheckoutSessionInterface
{
    private const TABLE = 'shopwalk_ucp_checkout_sessions';
    private const STATUS_INCOMPLETE          = 'incomplete';
    private const STATUS_READY_FOR_COMPLETE  = 'ready_for_complete';
    private const STATUS_COMPLETED           = 'completed';
    private const STATUS_CANCELED            = 'canceled';
    private const SESSION_TTL_HOURS          = 24;

    private AdapterInterface $connection;

    public function __construct(
        private ResourceConnection          $resource,
        private ProductRepositoryInterface  $productRepository,
        private StockRegistryInterface      $stockRegistry,
        private CartManagementInterface     $cartManagement,
        private CartRepositoryInterface     $cartRepository,
        private QuoteFactory                $quoteFactory,
        private StoreManagerInterface       $storeManager,
        private OrderRepositoryInterface    $orderRepository,
        private ScopeConfigInterface        $scopeConfig,
        private DateTime                    $dateTime
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
        // Idempotency-Key support
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                return UcpResponse::ok($this->formatSession($existing));
            }
        }

        // Validate line items
        $lineItems = $data['line_items'] ?? [];
        if (empty($lineItems)) {
            return UcpResponse::error('invalid_request', 'line_items is required and cannot be empty.');
        }

        $validatedItems = [];
        $subtotal       = 0.0;

        foreach ($lineItems as $index => $item) {
            $productId = $item['product_id'] ?? null;
            $quantity  = (int) ($item['quantity'] ?? 1);

            if (!$productId) {
                return UcpResponse::error(
                    'invalid_line_item',
                    sprintf('line_items[%d] requires product_id.', $index)
                );
            }

            try {
                $product = $this->productRepository->getById($productId);
            } catch (\Exception $e) {
                return UcpResponse::error(
                    'product_not_found',
                    sprintf('Product %d not found.', $productId)
                );
            }

            // Stock check
            try {
                $stockItem = $this->stockRegistry->getStockItem($product->getId());
                if (!$stockItem->getIsInStock()) {
                    return UcpResponse::error(
                        'out_of_stock',
                        sprintf('Product "%s" is out of stock.', $product->getName())
                    );
                }
                if ($stockItem->getManageStock() && $stockItem->getQty() < $quantity) {
                    return UcpResponse::error(
                        'insufficient_stock',
                        sprintf(
                            'Only %d of "%s" available (requested %d).',
                            (int) $stockItem->getQty(),
                            $product->getName(),
                            $quantity
                        )
                    );
                }
            } catch (\Exception $e) {
                // Stock data unavailable; proceed cautiously
            }

            $price      = (float) $product->getFinalPrice();
            $itemTotal   = $price * $quantity;
            $subtotal   += $itemTotal;

            $validatedItems[] = [
                'index'      => $index,
                'product_id' => (int) $product->getId(),
                'variant_id' => $item['variant_id'] ?? null,
                'name'       => $product->getName(),
                'sku'        => $product->getSku(),
                'quantity'   => $quantity,
                'unit_price' => UcpResponse::toCents($price),
                'subtotal'   => UcpResponse::toCents($itemTotal),
            ];
        }

        $sessionId = 'chk_' . bin2hex(random_bytes(16));
        $now       = $this->dateTime->gmtDate();
        $expiresAt = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            strtotime('+' . self::SESSION_TTL_HOURS . ' hours')
        );

        $buyer       = $data['buyer'] ?? new \stdClass();
        $fulfillment = $data['fulfillment'] ?? new \stdClass();
        $payment     = $data['payment'] ?? new \stdClass();
        $totals      = UcpResponse::buildTotals($subtotal, 0.0, 0.0, 0.0, $subtotal);

        $currency = $this->storeManager->getStore()->getCurrentCurrencyCode();
        $totals['currency'] = $currency;

        $row = [
            'id'              => $sessionId,
            'client_id'       => $data['client_id'] ?? null,
            'user_id'         => $data['user_id'] ?? null,
            'status'          => self::STATUS_INCOMPLETE,
            'line_items'      => json_encode($validatedItems),
            'buyer'           => json_encode($buyer),
            'fulfillment'     => json_encode($fulfillment),
            'payment'         => json_encode($payment),
            'totals'          => json_encode($totals),
            'messages'        => json_encode([]),
            'order_id'        => null,
            'idempotency_key' => $idempotencyKey,
            'created_at'      => $now,
            'updated_at'      => $now,
            'expires_at'      => $expiresAt,
        ];

        $this->connection->insert($this->resource->getTableName(self::TABLE), $row);

        return UcpResponse::ok($this->formatSession($row));
    }

    /* ------------------------------------------------------------------
     *  GET
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function get(string $id): array
    {
        $session = $this->loadSession($id);
        if (!$session) {
            return UcpResponse::error('session_not_found', 'Checkout session not found.');
        }

        return UcpResponse::ok($this->formatSession($session));
    }

    /* ------------------------------------------------------------------
     *  UPDATE (full replace)
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function update(string $id, array $data): array
    {
        $session = $this->loadSession($id);
        if (!$session) {
            return UcpResponse::error('session_not_found', 'Checkout session not found.');
        }

        if (in_array($session['status'], [self::STATUS_COMPLETED, self::STATUS_CANCELED], true)) {
            return UcpResponse::error(
                'session_closed',
                sprintf('Session is already %s.', $session['status'])
            );
        }

        $updates = [];

        if (isset($data['buyer'])) {
            $updates['buyer'] = json_encode($data['buyer']);
        }
        if (isset($data['fulfillment'])) {
            $updates['fulfillment'] = json_encode($data['fulfillment']);
        }
        if (isset($data['payment'])) {
            $updates['payment'] = json_encode($data['payment']);
        }
        if (isset($data['line_items'])) {
            $updates['line_items'] = json_encode($data['line_items']);
        }

        // Recalculate totals if line_items changed
        if (isset($data['line_items'])) {
            $subtotal = 0.0;
            foreach ($data['line_items'] as $item) {
                $subtotal += (float) ($item['subtotal'] ?? 0) / 100;
            }
            $shipping  = (float) ($data['shipping_total'] ?? 0);
            $tax       = (float) ($data['tax_total'] ?? 0);
            $discount  = (float) ($data['discount_total'] ?? 0);
            $total     = $subtotal + $shipping + $tax - $discount;
            $currency  = $this->storeManager->getStore()->getCurrentCurrencyCode();
            $totals    = UcpResponse::buildTotals($subtotal, $shipping, $tax, $discount, $total);
            $totals['currency'] = $currency;
            $updates['totals']  = json_encode($totals);
        }

        // Auto-transition to ready_for_complete when buyer + fulfillment + payment all present
        $buyerData       = isset($updates['buyer'])
            ? json_decode($updates['buyer'], true)
            : json_decode($session['buyer'], true);
        $fulfillmentData = isset($updates['fulfillment'])
            ? json_decode($updates['fulfillment'], true)
            : json_decode($session['fulfillment'], true);
        $paymentData     = isset($updates['payment'])
            ? json_decode($updates['payment'], true)
            : json_decode($session['payment'], true);

        $hasRequiredFields = !empty($buyerData) && is_array($buyerData)
            && !empty($fulfillmentData) && is_array($fulfillmentData)
            && !empty($paymentData) && is_array($paymentData)
            && !empty($buyerData['email']);

        if ($hasRequiredFields) {
            $updates['status'] = self::STATUS_READY_FOR_COMPLETE;
        }

        $updates['updated_at'] = $this->dateTime->gmtDate();

        $this->connection->update(
            $this->resource->getTableName(self::TABLE),
            $updates,
            ['id = ?' => $id]
        );

        $session = $this->loadSession($id);

        return UcpResponse::ok($this->formatSession($session));
    }

    /* ------------------------------------------------------------------
     *  COMPLETE — create a real Magento order
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function complete(string $id, array $data = []): array
    {
        $session = $this->loadSession($id);
        if (!$session) {
            return UcpResponse::error('session_not_found', 'Checkout session not found.');
        }

        if ($session['status'] === self::STATUS_COMPLETED) {
            return UcpResponse::error('already_completed', 'Session is already completed.');
        }
        if ($session['status'] === self::STATUS_CANCELED) {
            return UcpResponse::error('session_canceled', 'Session has been canceled.');
        }

        $lineItems   = json_decode($session['line_items'], true);
        $buyer       = json_decode($session['buyer'], true);
        $fulfillment = json_decode($session['fulfillment'], true);
        $payment     = json_decode($session['payment'], true);

        if (empty($buyer['email'])) {
            return UcpResponse::error('missing_buyer', 'Buyer email is required to complete checkout.');
        }

        try {
            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();

            // Create a new quote
            $quote = $this->quoteFactory->create();
            $quote->setStoreId($storeId);
            $quote->setIsMultiShipping(false);

            // Set customer as guest
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerEmail($buyer['email']);
            $quote->setCustomerFirstname($buyer['first_name'] ?? 'Guest');
            $quote->setCustomerLastname($buyer['last_name'] ?? 'Shopper');

            // Add items
            foreach ($lineItems as $item) {
                $product = $this->productRepository->getById($item['product_id']);
                $product->setPrice($item['unit_price'] / 100);
                $quote->addProduct($product, (int) $item['quantity']);
            }

            // Shipping address
            $shippingAddress = $fulfillment['destination'] ?? $fulfillment['shipping_address'] ?? [];
            $billingAddress  = $buyer['billing_address'] ?? $shippingAddress;

            $quote->getBillingAddress()->addData($this->mapAddress($billingAddress, $buyer));
            $quote->getShippingAddress()->addData($this->mapAddress($shippingAddress, $buyer));

            // Shipping method
            $shippingMethod = $fulfillment['shipping_method'] ?? 'flatrate_flatrate';
            $quote->getShippingAddress()
                ->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod($shippingMethod);

            // Payment method
            $paymentMethod = $payment['method'] ?? $data['payment_method'] ?? 'checkmo';
            $quote->setPaymentMethod($paymentMethod);
            $quote->getPayment()->importData(['method' => $paymentMethod]);

            $quote->collectTotals();
            $this->cartRepository->save($quote);

            // Place the order
            $orderId = $this->cartManagement->placeOrder($quote->getId());
            $order   = $this->orderRepository->get($orderId);

            // Mark session completed
            $this->connection->update(
                $this->resource->getTableName(self::TABLE),
                [
                    'status'     => self::STATUS_COMPLETED,
                    'order_id'   => (int) $order->getEntityId(),
                    'updated_at' => $this->dateTime->gmtDate(),
                ],
                ['id = ?' => $id]
            );

            $baseUrl = rtrim($store->getBaseUrl(), '/');

            return UcpResponse::ok([
                'session_id'    => $id,
                'status'        => self::STATUS_COMPLETED,
                'order'         => [
                    'id'            => (int) $order->getEntityId(),
                    'increment_id'  => $order->getIncrementId(),
                    'status'        => $order->getStatus(),
                    'permalink_url' => $baseUrl . '/sales/order/view/order_id/' . $order->getEntityId(),
                    'totals'        => UcpResponse::buildTotals(
                        (float) $order->getSubtotal(),
                        (float) $order->getShippingAmount(),
                        (float) $order->getTaxAmount(),
                        abs((float) $order->getDiscountAmount()),
                        (float) $order->getGrandTotal()
                    ),
                ],
            ]);
        } catch (\Exception $e) {
            return UcpResponse::error(
                'order_creation_failed',
                'Failed to create order: ' . $e->getMessage(),
                'fatal'
            );
        }
    }

    /* ------------------------------------------------------------------
     *  CANCEL
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function cancel(string $id): array
    {
        $session = $this->loadSession($id);
        if (!$session) {
            return UcpResponse::error('session_not_found', 'Checkout session not found.');
        }

        if ($session['status'] === self::STATUS_COMPLETED) {
            return UcpResponse::error(
                'session_completed',
                'Cannot cancel a completed session.'
            );
        }

        $this->connection->update(
            $this->resource->getTableName(self::TABLE),
            [
                'status'     => self::STATUS_CANCELED,
                'updated_at' => $this->dateTime->gmtDate(),
            ],
            ['id = ?' => $id]
        );

        $session['status'] = self::STATUS_CANCELED;

        return UcpResponse::ok($this->formatSession($session));
    }

    /* ------------------------------------------------------------------
     *  PRIVATE HELPERS
     * ----------------------------------------------------------------*/

    /**
     * Load a session row from the database.
     *
     * @return mixed[]|null
     */
    private function loadSession(string $id): ?array
    {
        $select = $this->connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('id = ?', $id);

        $row = $this->connection->fetchRow($select);

        return $row ?: null;
    }

    /**
     * Find a session by idempotency key.
     *
     * @return mixed[]|null
     */
    private function findByIdempotencyKey(string $key): ?array
    {
        $select = $this->connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('idempotency_key = ?', $key);

        $row = $this->connection->fetchRow($select);

        return $row ?: null;
    }

    /**
     * Format a database row into the UCP session response shape.
     *
     * @param mixed[] $session
     * @return mixed[]
     */
    private function formatSession(array $session): array
    {
        return [
            'id'          => $session['id'],
            'status'      => $session['status'],
            'line_items'  => $this->jsonDecode($session['line_items']),
            'buyer'       => $this->jsonDecode($session['buyer']),
            'fulfillment' => $this->jsonDecode($session['fulfillment']),
            'payment'     => $this->jsonDecode($session['payment']),
            'totals'      => $this->jsonDecode($session['totals']),
            'messages'    => $this->jsonDecode($session['messages']),
            'order_id'    => $session['order_id'] ? (int) $session['order_id'] : null,
            'created_at'  => $session['created_at'],
            'updated_at'  => $session['updated_at'],
            'expires_at'  => $session['expires_at'],
        ];
    }

    /**
     * Map UCP/generic address fields to Magento quote address format.
     *
     * @param mixed[] $address
     * @param mixed[] $buyer
     * @return mixed[]
     */
    private function mapAddress(array $address, array $buyer): array
    {
        return [
            'firstname'            => $buyer['first_name'] ?? 'Guest',
            'lastname'             => $buyer['last_name'] ?? 'Shopper',
            'email'                => $buyer['email'] ?? '',
            'telephone'            => $buyer['phone'] ?? '0000000000',
            'street'               => $address['streetAddress']
                                      ?? $address['address_1']
                                      ?? ($address['street'] ?? ''),
            'city'                 => $address['addressLocality']
                                      ?? $address['city']
                                      ?? '',
            'region'               => $address['addressRegion']
                                      ?? $address['state']
                                      ?? ($address['region'] ?? ''),
            'postcode'             => $address['postalCode']
                                      ?? $address['postcode']
                                      ?? '',
            'country_id'           => $address['addressCountry']
                                      ?? $address['country_id']
                                      ?? ($address['country'] ?? 'US'),
        ];
    }

    /**
     * Safely decode a JSON string; return empty structure on failure.
     *
     * @return mixed
     */
    private function jsonDecode(string $json): mixed
    {
        $decoded = json_decode($json, true);
        return $decoded ?? [];
    }
}
