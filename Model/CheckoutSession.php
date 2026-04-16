<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Shopwalk\Ucp\Api\CheckoutInterface;

/**
 * Checkout session lifecycle: create quote → confirm payment → place order.
 *
 * Sessions are keyed by a random id and mapped to Magento quote IDs in
 * core_config_data under shopwalk_ucp/checkout/sessions. Sessions expire
 * after 30 minutes.
 */
class CheckoutSession implements CheckoutInterface
{
    private const SESSION_TTL = 1800;
    private const SESSIONS_PATH = 'shopwalk_ucp/checkout/sessions';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ConfigurableType $configurableType,
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CartManagementInterface $quoteManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ConfigWriter $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly UcpEnvelope $envelope,
    ) {}

    public function create(array $data): array
    {
        $productId = (int) ($data['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new WebapiException(new Phrase('product_id is required'), 0, WebapiException::HTTP_BAD_REQUEST);
        }
        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            throw new WebapiException(new Phrase('Product not found'), 0, WebapiException::HTTP_NOT_FOUND);
        }
        if (!$product->isSalable()) {
            throw new WebapiException(new Phrase('Product is out of stock'), 0, WebapiException::HTTP_CONFLICT);
        }

        $quote = $this->quoteFactory->create();
        $quote->setStoreId((int) $this->storeManager->getStore()->getId());

        $request = new DataObject(['qty' => max(1, (int) ($data['quantity'] ?? 1))]);
        if (!empty($data['variant_id'])) {
            $superAttrs = $this->resolveVariantAttributes($product, (int) $data['variant_id']);
            if ($superAttrs) {
                $request->setData('super_attribute', $superAttrs);
            }
        }

        try {
            $quote->addProduct($product, $request);
        } catch (LocalizedException $e) {
            throw new WebapiException(new Phrase($e->getMessage()), 0, WebapiException::HTTP_BAD_REQUEST);
        }

        $shipping = $data['shipping_address'] ?? [];
        if (!$shipping) {
            throw new WebapiException(new Phrase('shipping_address is required'), 0, WebapiException::HTTP_BAD_REQUEST);
        }
        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->addData($shipping);
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();

        $rateCode = $this->pickCheapestRate($shippingAddress);
        if ($rateCode !== null) {
            $shippingAddress->setShippingMethod($rateCode);
        }

        $quote->getPayment()->setMethod('shopwalk_ucp');
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail((string) ($shipping['email'] ?? 'ucp-agent@shopwalk.com'));
        $quote->collectTotals();
        $this->quoteRepository->save($quote);

        $sessionId = 'ucp_sess_' . bin2hex(random_bytes(16));
        $this->saveSession($sessionId, (int) $quote->getId());

        return [
            'session_id' => $sessionId,
            'status' => 'pending',
            'subtotal' => (float) $quote->getSubtotal(),
            'shipping' => (float) $shippingAddress->getShippingAmount(),
            'tax' => (float) $shippingAddress->getTaxAmount(),
            'total' => (float) $quote->getGrandTotal(),
            'currency' => (string) $quote->getQuoteCurrencyCode(),
            'checkout_url' => rtrim($this->storeManager->getStore()->getBaseUrl(), '/') . '/ucp/checkout/' . $sessionId,
            'expires_at' => gmdate('c', time() + self::SESSION_TTL),
            'ucp' => $this->envelope->build(['dev.ucp.shopping.checkout']),
        ];
    }

    public function getStatus(string $sessionId): array
    {
        $map = $this->loadSessions();
        $entry = $map[$sessionId] ?? null;
        if (!$entry) {
            throw new WebapiException(new Phrase('Session not found'), 0, WebapiException::HTTP_NOT_FOUND);
        }
        if (!empty($entry['order_id'])) {
            return [
                'session_id' => $sessionId,
                'status' => 'confirmed',
                'order_id' => $entry['order_id'],
                'ucp' => $this->envelope->build(['dev.ucp.shopping.checkout']),
            ];
        }
        if ((int) $entry['created_at'] + self::SESSION_TTL < time()) {
            return [
                'session_id' => $sessionId,
                'status' => 'expired',
                'ucp' => $this->envelope->build(['dev.ucp.shopping.checkout']),
            ];
        }
        return [
            'session_id' => $sessionId,
            'status' => 'pending',
            'ucp' => $this->envelope->build(['dev.ucp.shopping.checkout']),
        ];
    }

    public function complete(string $sessionId, array $data): array
    {
        $map = $this->loadSessions();
        $entry = $map[$sessionId] ?? null;
        if (!$entry) {
            throw new WebapiException(new Phrase('Session not found'), 0, WebapiException::HTTP_NOT_FOUND);
        }
        if (!empty($entry['order_id'])) {
            return [
                'session_id' => $sessionId,
                'status' => 'confirmed',
                'order_id' => $entry['order_id'],
                'ucp' => $this->envelope->build(['dev.ucp.shopping.checkout']),
            ];
        }
        $quoteId = (int) $entry['quote_id'];
        $quote = $this->quoteRepository->get($quoteId);
        $orderId = $this->quoteManagement->placeOrder($quoteId);
        $order = $this->orderRepository->get($orderId);

        $payment = $order->getPayment();
        $payment->setAdditionalInformation('ucp_session_id', $sessionId);
        if (!empty($data['payment_id'])) {
            $payment->setAdditionalInformation('ucp_payment_id', (string) $data['payment_id']);
        }
        if (isset($data['paid_amount'])) {
            $payment->setAmountPaid((float) $data['paid_amount']);
        }
        $order->setState(Order::STATE_PROCESSING)->setStatus('processing');
        $this->orderRepository->save($order);

        $map[$sessionId]['order_id'] = $order->getIncrementId();
        $this->writeSessions($map);

        return [
            'session_id' => $sessionId,
            'status' => 'confirmed',
            'order_id' => $order->getIncrementId(),
            'ucp' => $this->envelope->build(['dev.ucp.shopping.checkout', 'dev.ucp.shopping.order']),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function resolveVariantAttributes(\Magento\Catalog\Api\Data\ProductInterface $product, int $variantId): array
    {
        if ($product->getTypeId() !== ConfigurableType::TYPE_CODE) {
            return [];
        }
        try {
            $variant = $this->productRepository->getById($variantId);
        } catch (NoSuchEntityException) {
            return [];
        }
        $out = [];
        foreach ($this->configurableType->getConfigurableAttributes($product) as $attr) {
            $attrId = (int) $attr->getAttributeId();
            $code = $attr->getProductAttribute()->getAttributeCode();
            $out[$attrId] = (string) $variant->getData($code);
        }
        return $out;
    }

    private function pickCheapestRate(\Magento\Quote\Model\Quote\Address $address): ?string
    {
        $cheapest = null;
        $cheapestPrice = PHP_FLOAT_MAX;
        foreach ($address->getGroupedAllShippingRates() as $rates) {
            foreach ($rates as $rate) {
                $price = (float) $rate->getPrice();
                if ($price < $cheapestPrice) {
                    $cheapestPrice = $price;
                    $cheapest = (string) $rate->getCode();
                }
            }
        }
        return $cheapest;
    }

    /**
     * @return array<string, array{quote_id: int, created_at: int, order_id?: string}>
     */
    private function loadSessions(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::SESSIONS_PATH);
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function saveSession(string $sessionId, int $quoteId): void
    {
        $map = $this->loadSessions();
        // Evict sessions older than 2×TTL to keep the JSON small.
        $cutoff = time() - self::SESSION_TTL * 2;
        foreach ($map as $id => $entry) {
            if ((int) ($entry['created_at'] ?? 0) < $cutoff && empty($entry['order_id'])) {
                unset($map[$id]);
            }
        }
        $map[$sessionId] = ['quote_id' => $quoteId, 'created_at' => time()];
        $this->writeSessions($map);
    }

    /**
     * @param array<string, array{quote_id: int, created_at: int, order_id?: string}> $map
     */
    private function writeSessions(array $map): void
    {
        $this->configWriter->save(self::SESSIONS_PATH, json_encode($map));
        $this->reinitableConfig->reinit();
    }
}
