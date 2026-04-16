<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Observer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Shopwalk\Ucp\Model\Config;
use Shopwalk\Ucp\Model\SyncQueue;

class ProductDeleteAfter implements ObserverInterface
{
    public function __construct(
        private readonly SyncQueue $queue,
        private readonly Config $config,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isConnected()) {
            return;
        }
        $product = $observer->getEvent()->getProduct();
        if (!$product instanceof ProductInterface || !$product->getId()) {
            return;
        }
        $this->queue->push(['op' => 'delete', 'product_id' => (int) $product->getId()]);
    }
}
