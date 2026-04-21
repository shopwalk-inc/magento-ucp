<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Shopwalk\Ucp\Api\DirectCheckoutInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Creates a Magento order in a single call (no session) and returns a payment URL.
 *
 * This mirrors the WooCommerce direct-checkout endpoint: validate items,
 * build a quote programmatically, place the order, return the payment link.
 */
class DirectCheckout implements DirectCheckoutInterface
{
    private const PAYMENT_URL_TTL_HOURS = 1;

    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private StockRegistryInterface     $stockRegistry,
        private CartManagementInterface    $cartManagement,
        private CartRepositoryInterface    $cartRepository,
        private QuoteFactory               $quoteFactory,
        private StoreManagerInterface      $storeManager,
        private OrderRepositoryInterface   $orderRepository,
        private DateTime                   $dateTime
    ) {
    }

    /**
     * @inheritdoc
     */
    public function create(array $data): array
    {
        // --- Validate required fields ---
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return UcpResponse::error('invalid_request', 'items is required and cannot be empty.');
        }

        $customer = $data['customer'] ?? [];
        if (empty($customer['email'])) {
            return UcpResponse::error('invalid_request', 'customer.email is required.');
        }

        $shippingAddress = $data['shipping_address'] ?? [];
        if (empty($shippingAddress)) {
            return UcpResponse::error('invalid_request', 'shipping_address is required.');
        }

        $returnUrl = $data['return_url'] ?? null;
        $metadata  = $data['metadata'] ?? [];

        try {
            $store   = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            $currency = $store->getCurrentCurrencyCode();

            // --- Create quote ---
            $quote = $this->quoteFactory->create();
            $quote->setStoreId($storeId);
            $quote->setIsMultiShipping(false);

            // Guest customer
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerEmail($customer['email']);
            $quote->setCustomerFirstname($customer['first_name'] ?? 'Guest');
            $quote->setCustomerLastname($customer['last_name'] ?? 'Shopper');

            // --- Validate and add items ---
            $resolvedItems = [];
            foreach ($items as $index => $item) {
                $productId = $item['product_id'] ?? null;
                $quantity  = (int) ($item['quantity'] ?? 1);

                if (!$productId) {
                    return UcpResponse::error(
                        'invalid_line_item',
                        sprintf('items[%d] requires product_id.', $index)
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

                // Stock validation
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
                    // Stock data unavailable; proceed
                }

                $quote->addProduct($product, $quantity);

                $resolvedItems[] = [
                    'product_id' => (int) $product->getId(),
                    'name'       => $product->getName(),
                    'sku'        => $product->getSku(),
                    'quantity'   => $quantity,
                    'unit_price' => UcpResponse::toCents((float) $product->getFinalPrice()),
                    'subtotal'   => UcpResponse::toCents((float) $product->getFinalPrice() * $quantity),
                ];
            }

            // --- Addresses ---
            $addressData = $this->mapAddress($shippingAddress, $customer);
            $billingData = isset($data['billing_address'])
                ? $this->mapAddress($data['billing_address'], $customer)
                : $addressData;

            $quote->getBillingAddress()->addData($billingData);
            $quote->getShippingAddress()->addData($addressData);

            // --- Shipping method ---
            $shippingMethod = $data['shipping_method'] ?? 'flatrate_flatrate';
            $quote->getShippingAddress()
                ->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod($shippingMethod);

            // --- Payment method (Shopwalk UCP gateway or fallback) ---
            $paymentMethod = $data['payment_method'] ?? 'checkmo';
            $quote->setPaymentMethod($paymentMethod);
            $quote->getPayment()->importData(['method' => $paymentMethod]);

            // --- Collect totals and save ---
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            // --- Place order ---
            $orderId = $this->cartManagement->placeOrder($quote->getId());
            $order   = $this->orderRepository->get($orderId);

            // Store Shopwalk metadata in the order comments
            if (!empty($metadata)) {
                $metaString = json_encode($metadata);
                $order->addCommentToStatusHistory(
                    'Shopwalk UCP Direct Checkout metadata: ' . $metaString
                );
                $this->orderRepository->save($order);
            }

            // Build payment URL
            $baseUrl    = rtrim($store->getBaseUrl(), '/');
            $paymentUrl = $baseUrl . '/shopwalk/payment/process/order_id/'
                        . $order->getEntityId()
                        . '/key/' . $order->getProtectCode();

            if ($returnUrl) {
                $paymentUrl .= '?return_url=' . urlencode($returnUrl);
            }

            $expiresAt = $this->dateTime->gmtDate(
                'c',
                strtotime('+' . self::PAYMENT_URL_TTL_HOURS . ' hour')
            );

            return UcpResponse::ok([
                'order_id'       => (int) $order->getEntityId(),
                'order_key'      => $order->getIncrementId(),
                'status'         => $order->getStatus(),
                'payment_url'    => $paymentUrl,
                'subtotal'       => UcpResponse::toCents((float) $order->getSubtotal()),
                'shipping_total' => UcpResponse::toCents((float) $order->getShippingAmount()),
                'tax_total'      => UcpResponse::toCents((float) $order->getTaxAmount()),
                'total'          => UcpResponse::toCents((float) $order->getGrandTotal()),
                'currency'       => $currency,
                'items'          => $resolvedItems,
                'expires_at'     => $expiresAt,
            ]);
        } catch (\Exception $e) {
            return UcpResponse::error(
                'checkout_failed',
                'Direct checkout failed: ' . $e->getMessage(),
                'fatal'
            );
        }
    }

    /**
     * Map UCP/generic address fields to Magento quote address data.
     *
     * @param mixed[] $address
     * @param mixed[] $customer
     * @return mixed[]
     */
    private function mapAddress(array $address, array $customer): array
    {
        return [
            'firstname'  => $customer['first_name'] ?? 'Guest',
            'lastname'   => $customer['last_name'] ?? 'Shopper',
            'email'      => $customer['email'] ?? '',
            'telephone'  => $customer['phone'] ?? '0000000000',
            'street'     => $address['address_1'] ?? ($address['street'] ?? ''),
            'city'       => $address['city'] ?? '',
            'region'     => $address['state'] ?? ($address['region'] ?? ''),
            'postcode'   => $address['postcode'] ?? '',
            'country_id' => $address['country'] ?? ($address['country_id'] ?? 'US'),
        ];
    }
}
