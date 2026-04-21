<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Shopwalk\Ucp\Api\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;

/**
 * Read-only order retrieval for UCP consumers.
 *
 * Transforms Magento orders into the UCP order schema with quantity
 * objects, fulfillment expectations/events, adjustments, and typed totals.
 */
class Order implements OrderInterface
{
    public function __construct(
        private OrderRepositoryInterface     $orderRepository,
        private SearchCriteriaBuilder        $searchCriteriaBuilder,
        private StoreManagerInterface        $storeManager,
        private ShipmentRepositoryInterface  $shipmentRepository,
        private CreditmemoRepositoryInterface $creditmemoRepository
    ) {
    }

    /* ------------------------------------------------------------------
     *  LIST
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function getList(?int $limit = 25, ?int $offset = 0): array
    {
        $limit  = max(1, min(100, $limit ?? 25));
        $offset = max(0, $offset ?? 0);

        $this->searchCriteriaBuilder->setPageSize($limit);
        $this->searchCriteriaBuilder->setCurrentPage((int) floor($offset / $limit) + 1);
        $searchCriteria = $this->searchCriteriaBuilder->create();

        $result = $this->orderRepository->getList($searchCriteria);
        $total  = $result->getTotalCount();

        $orders = [];
        foreach ($result->getItems() as $order) {
            $orders[] = $this->formatOrder($order);
        }

        return UcpResponse::ok([
            'orders' => $orders,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /* ------------------------------------------------------------------
     *  DETAIL
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function get(int $id): array
    {
        try {
            $order = $this->orderRepository->get($id);
        } catch (\Exception $e) {
            return UcpResponse::error('order_not_found', 'Order not found.');
        }

        return UcpResponse::ok($this->formatOrder($order));
    }

    /* ------------------------------------------------------------------
     *  EVENTS
     * ----------------------------------------------------------------*/

    /**
     * @inheritdoc
     */
    public function getEvents(int $id): array
    {
        try {
            $order = $this->orderRepository->get($id);
        } catch (\Exception $e) {
            return UcpResponse::error('order_not_found', 'Order not found.');
        }

        $events = [];

        // Status history (order comments)
        $statusHistory = $order->getStatusHistories();
        if ($statusHistory) {
            foreach ($statusHistory as $history) {
                $events[] = [
                    'type'       => 'status_change',
                    'status'     => $history->getStatus(),
                    'comment'    => $history->getComment() ?: '',
                    'created_at' => $history->getCreatedAt(),
                    'is_visible' => (bool) $history->getIsVisibleOnFront(),
                ];
            }
        }

        // Shipment tracking events
        $shipments = $order->getShipmentsCollection();
        if ($shipments) {
            foreach ($shipments as $shipment) {
                $tracks = $shipment->getAllTracks();
                foreach ($tracks as $track) {
                    $events[] = [
                        'type'            => 'shipment',
                        'shipment_id'     => (int) $shipment->getEntityId(),
                        'carrier'         => $track->getCarrierCode(),
                        'carrier_title'   => $track->getTitle(),
                        'tracking_number' => $track->getTrackNumber(),
                        'created_at'      => $shipment->getCreatedAt(),
                    ];
                }

                // If shipment has no tracks, still record the shipment event
                if (empty($tracks) || count($tracks) === 0) {
                    $events[] = [
                        'type'        => 'shipment',
                        'shipment_id' => (int) $shipment->getEntityId(),
                        'carrier'     => null,
                        'tracking_number' => null,
                        'created_at'  => $shipment->getCreatedAt(),
                    ];
                }
            }
        }

        // Sort events by created_at descending
        usort($events, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return UcpResponse::ok([
            'order_id' => $id,
            'events'   => $events,
        ]);
    }

    /* ------------------------------------------------------------------
     *  PRIVATE HELPERS
     * ----------------------------------------------------------------*/

    /**
     * Format a Magento order into the UCP order schema.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return mixed[]
     */
    private function formatOrder($order): array
    {
        $baseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');

        // Build line items with quantity objects and fulfillment status
        $lineItems   = [];
        $items       = $order->getItems();
        $shipmentMap = $this->buildShipmentQuantityMap($order);

        $index = 0;
        foreach ($items as $item) {
            // Skip child items of configurables
            if ($item->getParentItemId()) {
                continue;
            }

            $fulfilled = $shipmentMap[(int) $item->getItemId()] ?? 0;
            $lineItems[] = UcpResponse::buildOrderLineItem($item, $index, $fulfilled);
            $index++;
        }

        // Fulfillment expectations from shipping description
        $fulfillmentExpectations = [];
        $shippingDescription = $order->getShippingDescription();
        if ($shippingDescription) {
            $fulfillmentExpectations[] = [
                'method'      => $shippingDescription,
                'description' => $shippingDescription,
            ];
        }

        // Adjustments from credit memos
        $adjustments = [];
        $creditmemos = $order->getCreditmemosCollection();
        if ($creditmemos) {
            foreach ($creditmemos as $creditmemo) {
                $adjustments[] = [
                    'type'       => 'refund',
                    'amount'     => UcpResponse::toCents((float) $creditmemo->getGrandTotal()),
                    'reason'     => 'Credit memo #' . $creditmemo->getIncrementId(),
                    'created_at' => $creditmemo->getCreatedAt(),
                ];
            }
        }

        // Typed totals
        $totals = UcpResponse::buildTotals(
            (float) $order->getSubtotal(),
            (float) $order->getShippingAmount(),
            (float) $order->getTaxAmount(),
            abs((float) $order->getDiscountAmount()),
            (float) $order->getGrandTotal()
        );
        $totals['currency'] = $order->getOrderCurrencyCode();

        // Derive overall order status for UCP
        $ucpStatus = $this->mapOrderStatus($order->getStatus());

        return [
            'id'                       => (int) $order->getEntityId(),
            'increment_id'             => $order->getIncrementId(),
            'status'                   => $ucpStatus,
            'magento_status'           => $order->getStatus(),
            'permalink_url'            => $baseUrl . '/sales/order/view/order_id/' . $order->getEntityId(),
            'line_items'               => $lineItems,
            'totals'                   => $totals,
            'fulfillment_expectations' => $fulfillmentExpectations,
            'adjustments'              => $adjustments,
            'buyer'                    => [
                'email'      => $order->getCustomerEmail(),
                'first_name' => $order->getCustomerFirstname(),
                'last_name'  => $order->getCustomerLastname(),
            ],
            'created_at'               => $order->getCreatedAt(),
            'updated_at'               => $order->getUpdatedAt(),
        ];
    }

    /**
     * Build a map of order-item ID => total shipped quantity from all shipments.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return array<int, int>
     */
    private function buildShipmentQuantityMap($order): array
    {
        $map       = [];
        $shipments = $order->getShipmentsCollection();

        if ($shipments) {
            foreach ($shipments as $shipment) {
                foreach ($shipment->getAllItems() as $shipmentItem) {
                    $orderItemId = (int) $shipmentItem->getOrderItemId();
                    $qty         = (int) $shipmentItem->getQty();
                    $map[$orderItemId] = ($map[$orderItemId] ?? 0) + $qty;
                }
            }
        }

        return $map;
    }

    /**
     * Map Magento order status to UCP status.
     */
    private function mapOrderStatus(string $magentoStatus): string
    {
        return match ($magentoStatus) {
            'pending', 'pending_payment', 'payment_review' => 'pending',
            'processing', 'fraud'                          => 'processing',
            'complete'                                     => 'fulfilled',
            'closed'                                       => 'refunded',
            'canceled'                                     => 'canceled',
            'holded'                                       => 'on_hold',
            default                                        => 'processing',
        };
    }
}
