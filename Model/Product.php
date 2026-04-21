<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Shopwalk\Ucp\Api\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Helper\Image as ImageHelper;

/**
 * Paginated, read-only product catalog for UCP consumers.
 */
class Product implements ProductInterface
{
    public function __construct(
        private ProductCollectionFactory   $productCollectionFactory,
        private StockRegistryInterface     $stockRegistry,
        private CategoryRepositoryInterface $categoryRepository,
        private StoreManagerInterface      $storeManager,
        private ImageHelper                $imageHelper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getList(int $page = 1, int $perPage = 100): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(250, $perPage));

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect([
            'name', 'sku', 'type_id', 'status', 'visibility',
            'price', 'special_price', 'special_from_date', 'special_to_date',
            'description', 'short_description', 'url_key',
            'image', 'small_image', 'thumbnail',
            'manufacturer', 'created_at', 'updated_at',
        ]);
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
        $collection->addAttributeToFilter(
            'visibility',
            ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]]
        );
        $collection->addMediaGalleryData();

        $total = $collection->getSize();
        $pages = (int) ceil($total / $perPage);

        $collection->setPageSize($perPage);
        $collection->setCurPage($page);

        $products = [];
        foreach ($collection as $magentoProduct) {
            $products[] = $this->formatProduct($magentoProduct);
        }

        return UcpResponse::ok([
            'products' => $products,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
        ]);
    }

    /**
     * Format a single Magento product into the UCP product schema.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return mixed[]
     */
    private function formatProduct($product): array
    {
        $regularPrice = (float) $product->getPrice();
        $specialPrice = $product->getSpecialPrice() ? (float) $product->getSpecialPrice() : null;

        // Determine if special price is currently active
        $onSale       = false;
        $currentPrice = $regularPrice;
        if ($specialPrice !== null && $specialPrice < $regularPrice) {
            $now  = time();
            $from = $product->getSpecialFromDate() ? strtotime($product->getSpecialFromDate()) : 0;
            $to   = $product->getSpecialToDate() ? strtotime($product->getSpecialToDate()) : PHP_INT_MAX;
            if ($now >= $from && $now <= $to) {
                $onSale       = true;
                $currentPrice = $specialPrice;
            }
        }

        // Stock info
        $inStock       = false;
        $stockQuantity = null;
        try {
            $stockItem     = $this->stockRegistry->getStockItem($product->getId());
            $inStock       = (bool) $stockItem->getIsInStock();
            $stockQuantity = $stockItem->getManageStock() ? (int) $stockItem->getQty() : null;
        } catch (\Exception $e) {
            // Product may not have stock data; default to out of stock
        }

        // Categories
        $categoryNames = [];
        $categoryIds   = $product->getCategoryIds();
        foreach ($categoryIds as $catId) {
            try {
                $category        = $this->categoryRepository->get($catId);
                $categoryNames[] = $category->getName();
            } catch (\Exception $e) {
                // skip unavailable categories
            }
        }

        // Images
        $imageUrl    = null;
        $galleryUrls = [];
        $mediaGallery = $product->getMediaGalleryImages();
        if ($mediaGallery && $mediaGallery->getSize() > 0) {
            foreach ($mediaGallery as $image) {
                $url = $image->getUrl();
                if ($imageUrl === null) {
                    $imageUrl = $url;
                }
                $galleryUrls[] = $url;
            }
        }

        // Fallback to product image attribute
        if ($imageUrl === null && $product->getImage() && $product->getImage() !== 'no_selection') {
            $imageUrl = $this->imageHelper
                ->init($product, 'product_base_image')
                ->getUrl();
        }

        // Brand (manufacturer attribute)
        $brand = null;
        if ($product->getAttributeText('manufacturer')) {
            $brand = $product->getAttributeText('manufacturer');
        }

        // Status text
        $statusText = ((int) $product->getStatus() === ProductStatus::STATUS_ENABLED) ? 'enabled' : 'disabled';

        return [
            'id'                => (int) $product->getId(),
            'name'              => $product->getName(),
            'slug'              => $product->getUrlKey() ?: '',
            'type'              => $product->getTypeId(),
            'status'            => $statusText,
            'sku'               => $product->getSku(),
            'description'       => $product->getDescription() ?: '',
            'short_description' => $product->getShortDescription() ?: '',
            'price'             => $currentPrice,
            'regular_price'     => $regularPrice,
            'sale_price'        => $onSale ? $specialPrice : null,
            'on_sale'           => $onSale,
            'in_stock'          => $inStock,
            'stock_quantity'    => $stockQuantity,
            'categories'        => $categoryNames,
            'image_url'         => $imageUrl,
            'gallery_urls'      => $galleryUrls,
            'permalink'         => $product->getProductUrl(),
            'brand'             => $brand,
            'date_created'      => $product->getCreatedAt() ?: '',
            'date_modified'     => $product->getUpdatedAt() ?: '',
        ];
    }
}
