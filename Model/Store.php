<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Shopwalk\Ucp\Api\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;

/**
 * Returns public store metadata for UCP consumers.
 */
class Store implements StoreInterface
{
    private const UCP_VERSION = '2026-04-08';

    public function __construct(
        private StoreManagerInterface    $storeManager,
        private ProductCollectionFactory $productCollectionFactory,
        private ScopeConfigInterface     $scopeConfig,
        private StockRegistryInterface   $stockRegistry
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getStore(): array
    {
        $store    = $this->storeManager->getStore();
        $baseUrl  = rtrim($store->getBaseUrl(), '/');

        $storeName = $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        ) ?: $store->getName();

        $description = $this->scopeConfig->getValue(
            'design/head/default_description',
            ScopeInterface::SCOPE_STORE
        ) ?: '';

        $currency = $store->getCurrentCurrencyCode();

        $pluginVersion = $this->scopeConfig->getValue(
            'shopwalk/ucp/plugin_version',
            ScopeInterface::SCOPE_STORE
        ) ?? '1.0.0';

        $partnerId = $this->scopeConfig->getValue(
            'shopwalk/ucp/partner_id',
            ScopeInterface::SCOPE_STORE
        );

        $licenseKey = $this->scopeConfig->getValue(
            'shopwalk/ucp/license_key',
            ScopeInterface::SCOPE_STORE
        );

        // Total visible product count
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
        $collection->addAttributeToFilter(
            'visibility',
            ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]]
        );
        $productCount = $collection->getSize();

        // In-stock count
        $inStockCollection = $this->productCollectionFactory->create();
        $inStockCollection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
        $inStockCollection->addAttributeToFilter(
            'visibility',
            ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]]
        );
        $inStockCollection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            '{{table}}.is_in_stock=1'
        );
        $inStockCount = $inStockCollection->getSize();

        return [
            'name'                => $storeName,
            'url'                 => $baseUrl,
            'description'         => $description,
            'currency'            => $currency,
            'product_count'       => $productCount,
            'in_stock_count'      => $inStockCount,
            'shopwalk_connected'  => !empty($licenseKey),
            'shopwalk_partner_id' => $partnerId,
            'ucp_version'         => self::UCP_VERSION,
            'plugin_version'      => $pluginVersion,
        ];
    }
}
