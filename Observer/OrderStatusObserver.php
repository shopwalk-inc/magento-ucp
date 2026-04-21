<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Shopwalk\Ucp\Helper\WebhookDelivery;

/**
 * Fires a webhook when an order created through UCP changes status.
 */
class OrderStatusObserver implements ObserverInterface
{
    /**
     * UCP event map: Magento order status => UCP event name.
     */
    private const EVENT_MAP = [
        'pending'    => 'order.created',
        'processing' => 'order.processing',
        'complete'   => 'order.delivered',
        'closed'     => 'order.refunded',
        'canceled'   => 'order.canceled',
    ];

    public function __construct(
        private WebhookDelivery $webhookDelivery
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();

        // Only fire for orders created through UCP
        if (!$order->getData('shopwalk_ucp_order')) {
            return;
        }

        $oldStatus = $order->getOrigData('status');
        $newStatus = $order->getStatus();

        if ($oldStatus === $newStatus) {
            return;
        }

        $event = self::EVENT_MAP[$newStatus] ?? null;
        if ($event === null) {
            return;
        }

        $this->webhookDelivery->queueEvent($order, $event);
    }
}
