<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Static helper for building UCP-compliant response envelopes.
 *
 * Mirrors the WooCommerce UCP_Response utility: every API response is
 * wrapped in a standard envelope with optional capability annotations.
 */
class UcpResponse
{
    private const UCP_VERSION = '2026-04-08';

    /**
     * Wrap a successful payload in the UCP envelope.
     *
     * @param mixed[] $data
     * @param mixed[] $capabilities
     * @return mixed[]
     */
    public static function ok(array $data, array $capabilities = []): array
    {
        $envelope = [
            'ucp_version' => self::UCP_VERSION,
            'status'      => 'ok',
            'data'        => $data,
        ];

        if (!empty($capabilities)) {
            $envelope['capabilities'] = $capabilities;
        }

        return $envelope;
    }

    /**
     * Build a UCP error response.
     *
     * @param string $code     Machine-readable error code (e.g. "invalid_request").
     * @param string $message  Human-readable description.
     * @param string $severity One of: fatal, recoverable, warning.
     * @return mixed[]
     */
    public static function error(
        string $code,
        string $message,
        string $severity = 'recoverable'
    ): array {
        return [
            'ucp_version' => self::UCP_VERSION,
            'status'      => 'error',
            'error'       => [
                'code'     => $code,
                'message'  => $message,
                'severity' => $severity,
            ],
        ];
    }

    /**
     * Convert a monetary amount to minor units (cents).
     */
    public static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Build a typed totals array in minor units.
     *
     * @return mixed[]
     */
    public static function buildTotals(
        float $subtotal,
        float $shipping,
        float $tax,
        float $discount,
        float $total
    ): array {
        return [
            'subtotal'       => self::toCents($subtotal),
            'shipping'       => self::toCents($shipping),
            'tax'            => self::toCents($tax),
            'discount'       => self::toCents($discount),
            'total'          => self::toCents($total),
            'currency'       => 'USD', // overridden by caller when needed
        ];
    }

    /**
     * Format a shipping/billing address into schema.org PostalAddress.
     *
     * @param mixed[] $address
     * @return mixed[]
     */
    public static function toDestination(array $address, string $id = 'dest_1'): array
    {
        return [
            'id'                  => $id,
            '@type'               => 'PostalAddress',
            'streetAddress'       => $address['street'] ?? ($address['address_1'] ?? ''),
            'addressLocality'     => $address['city'] ?? '',
            'addressRegion'       => $address['region'] ?? ($address['state'] ?? ''),
            'postalCode'          => $address['postcode'] ?? '',
            'addressCountry'      => $address['country_id'] ?? ($address['country'] ?? ''),
        ];
    }

    /**
     * Build a UCP line-item from a Magento order item (checkout context).
     *
     * @return mixed[]
     */
    public static function buildLineItem(OrderItem $item, int $index = 0): array
    {
        return [
            'index'        => $index,
            'product_id'   => (int) $item->getProductId(),
            'variant_id'   => $item->getParentItemId() ? (int) $item->getProductId() : null,
            'name'         => $item->getName(),
            'sku'          => $item->getSku(),
            'quantity'     => (int) $item->getQtyOrdered(),
            'unit_price'   => self::toCents((float) $item->getPrice()),
            'subtotal'     => self::toCents((float) $item->getRowTotal()),
            'tax'          => self::toCents((float) $item->getTaxAmount()),
            'total'        => self::toCents((float) $item->getRowTotalInclTax()),
            'image_url'    => null,
        ];
    }

    /**
     * Build a UCP order line-item with quantity object and derived fulfillment status.
     *
     * @return mixed[]
     */
    public static function buildOrderLineItem(
        OrderItem $item,
        int $index,
        int $fulfilled = 0
    ): array {
        $ordered = (int) $item->getQtyOrdered();
        $cancelled = (int) $item->getQtyCanceled();
        $refunded = (int) $item->getQtyRefunded();
        $total = $ordered - $cancelled;

        $status = 'processing';
        if ($fulfilled >= $total && $total > 0) {
            $status = 'fulfilled';
        } elseif ($fulfilled > 0) {
            $status = 'partially_fulfilled';
        } elseif ($cancelled >= $ordered) {
            $status = 'canceled';
        } elseif ($refunded >= $ordered) {
            $status = 'refunded';
        }

        return [
            'index'      => $index,
            'product_id' => (int) $item->getProductId(),
            'variant_id' => $item->getParentItemId() ? (int) $item->getProductId() : null,
            'name'       => $item->getName(),
            'sku'        => $item->getSku(),
            'quantity'   => [
                'original'  => $ordered,
                'total'     => $total,
                'fulfilled' => $fulfilled,
                'canceled'  => $cancelled,
                'refunded'  => $refunded,
            ],
            'unit_price' => self::toCents((float) $item->getPrice()),
            'subtotal'   => self::toCents((float) $item->getRowTotal()),
            'tax'        => self::toCents((float) $item->getTaxAmount()),
            'total'      => self::toCents((float) $item->getRowTotalInclTax()),
            'status'     => $status,
        ];
    }
}
