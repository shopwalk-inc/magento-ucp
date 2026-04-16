<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Maps a Magento Product into the UCP wire format. Shared between the REST
 * endpoint and the sync queue so both produce identical payloads.
 */
class ProductMapper
{
    public function __construct(
        private readonly ConfigurableType $configurableType,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager,
    ) {}

    /**
     * @return mixed[]
     */
    public function toArray(ProductInterface $product, bool $fullDetail = false): array
    {
        $typeId = $product->getTypeId();
        $price = (float) $product->getFinalPrice();
        $regular = (float) $product->getPrice();
        $compareAt = $regular > $price ? $regular : 0.0;

        $stock = $this->resolveStock($product);

        return [
            'id' => (string) $product->getId(),
            'external_id' => (string) $product->getId(),
            'name' => (string) $product->getName(),
            'description' => $fullDetail ? (string) ($product->getDescription() ?? '') : '',
            'short_description' => (string) ($product->getShortDescription() ?? ''),
            'sku' => (string) $product->getSku(),
            'price' => $price,
            'compare_at_price' => $compareAt,
            'currency' => $this->storeManager->getStore()->getCurrentCurrencyCode(),
            'in_stock' => $stock['in_stock'],
            'stock_quantity' => $stock['qty'],
            'url' => $product->getProductUrl(),
            'source_url' => $product->getProductUrl(),
            'categories' => $this->resolveCategories($product),
            'images' => $this->resolveImages($product),
            'average_rating' => (float) ($product->getData('rating_summary') ?? 0),
            'rating_count' => (int) ($product->getData('reviews_count') ?? 0),
            'type' => $typeId,
            'variants' => $typeId === ConfigurableType::TYPE_CODE
                ? $this->resolveVariants($product)
                : null,
        ];
    }

    /**
     * @return array{in_stock: bool, qty: int}
     */
    private function resolveStock(ProductInterface $product): array
    {
        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($product->getSku());
            return [
                'in_stock' => (bool) $stockItem->getIsInStock() && (bool) $product->isSalable(),
                'qty' => (int) $stockItem->getQty(),
            ];
        } catch (\Throwable $e) {
            // Fall back to product-level isSalable if stock registry is unavailable.
            return ['in_stock' => (bool) $product->isSalable(), 'qty' => 0];
        }
    }

    /**
     * @return string[]
     */
    private function resolveCategories(ProductInterface $product): array
    {
        $out = [];
        foreach ($product->getCategoryIds() as $catId) {
            try {
                $out[] = $this->categoryRepository->get((int) $catId)->getName();
            } catch (NoSuchEntityException) {
                // skip
            }
        }
        return array_values(array_filter(array_unique($out)));
    }

    /**
     * @return array<int, array{url: string, alt: string, position: int}>
     */
    private function resolveImages(ProductInterface $product): array
    {
        $out = [];
        $entries = $product->getMediaGalleryEntries() ?? [];
        foreach ($entries as $i => $entry) {
            $file = $entry->getFile();
            if (!$file) {
                continue;
            }
            $url = $this->storeManager->getStore()->getBaseUrl(
                \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
            ) . 'catalog/product' . $file;
            $out[] = [
                'url' => $url,
                'alt' => (string) ($entry->getLabel() ?? ''),
                'position' => (int) ($entry->getPosition() ?? $i),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{id: string, sku: string, attributes: array<string, string>, price: float, in_stock: bool}>
     */
    private function resolveVariants(ProductInterface $product): array
    {
        $out = [];
        try {
            $children = $this->configurableType->getUsedProducts($product);
        } catch (\Throwable) {
            return [];
        }
        $configAttrs = $this->configurableType->getConfigurableAttributes($product);

        foreach ($children as $child) {
            $attrs = [];
            foreach ($configAttrs as $attr) {
                $code = $attr->getProductAttribute()->getAttributeCode();
                $value = $child->getAttributeText($code);
                if ($value !== null && $value !== false) {
                    $attrs[$code] = (string) $value;
                }
            }
            $stock = $this->resolveStock($child);
            $out[] = [
                'id' => (string) $child->getId(),
                'sku' => (string) $child->getSku(),
                'attributes' => $attrs,
                'price' => (float) $child->getFinalPrice(),
                'in_stock' => $stock['in_stock'],
            ];
        }
        return $out;
    }
}
