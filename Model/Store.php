<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Shopwalk\Ucp\Api\StoreInterface;

class Store implements StoreInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly Config $config,
        private readonly UcpEnvelope $envelope,
    ) {}

    public function getInfo(): array
    {
        $store = $this->storeManager->getStore();

        $productCount = $this->productCollectionFactory->create()
            ->addAttributeToFilter('status', 1)
            ->getSize();

        $categoryCount = $this->categoryCollectionFactory->create()
            ->addAttributeToFilter('is_active', 1)
            ->getSize();

        return [
            'name' => (string) $this->scopeConfig->getValue(
                'general/store_information/name',
                ScopeInterface::SCOPE_STORE
            ),
            'url' => $store->getBaseUrl(),
            'platform' => 'magento2',
            'platform_version' => $this->productMetadata->getVersion(),
            'ucp_version' => Config::UCP_VERSION,
            'currency' => $store->getCurrentCurrencyCode(),
            'country' => (string) $this->scopeConfig->getValue(
                'general/country/default',
                ScopeInterface::SCOPE_STORE
            ),
            'product_count' => (int) $productCount,
            'categories_count' => (int) $categoryCount,
            'shopwalk_connected' => $this->config->isConnected(),
            'shopwalk_partner_id' => $this->config->getPartnerId(),
            'ucp' => $this->envelope->build([]),
        ];
    }
}
