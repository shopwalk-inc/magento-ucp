<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Shopwalk\Ucp\Model\Config;
use Shopwalk\Ucp\Model\SyncClient;

/**
 * sales_order_save_after — notify shopwalk-api when a UCP order changes
 * status. Only fires for shopwalk_ucp-method orders, and only when the
 * status actually changed.
 */
class OrderStatusChange implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SyncClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getPayment() || $order->getPayment()->getMethod() !== 'shopwalk_ucp') {
            return;
        }
        if (!$order->dataHasChangedFor('status')) {
            return;
        }
        if (!$this->config->isConnected()) {
            return;
        }

        $sessionId = (string) $order->getPayment()->getAdditionalInformation('ucp_session_id');
        $payload = [
            'session_id' => $sessionId,
            'order_id' => $order->getIncrementId(),
            'status' => (string) $order->getStatus(),
            'tracking' => $this->collectTracking($order),
        ];
        try {
            $this->client->post('/plugin/orders/status', $payload);
        } catch (\Throwable $e) {
            $this->logger->warning('shopwalk-ucp: order status push failed', [
                'order_id' => $order->getIncrementId(),
                'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, array{carrier: string, number: string, title: string}>
     */
    private function collectTracking(Order $order): array
    {
        $out = [];
        foreach ($order->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getAllTracks() as $track) {
                $out[] = [
                    'carrier' => (string) $track->getCarrierCode(),
                    'number' => (string) $track->getTrackNumber(),
                    'title' => (string) $track->getTitle(),
                ];
            }
        }
        return $out;
    }
}
