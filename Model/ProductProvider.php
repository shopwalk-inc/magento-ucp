<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Phrase;
use Shopwalk\Ucp\Api\ProductsInterface;

class ProductProvider implements ProductsInterface
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductMapper $mapper,
        private readonly UcpEnvelope $envelope,
    ) {}

    public function getList(
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?string $category = null,
        ?string $search = null,
        ?bool $inStock = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('status', 1);
        $collection->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);

        if ($category !== null && $category !== '') {
            if (ctype_digit($category)) {
                $collection->addCategoriesFilter(['in' => [(int) $category]]);
            } else {
                $collection->joinField(
                    'category_name',
                    'catalog_category_product',
                    'name',
                    'product_id=entity_id',
                    null,
                    'left'
                );
                $collection->addFieldToFilter('category_name', ['like' => '%' . $category . '%']);
            }
        }

        if ($search !== null && $search !== '') {
            $collection->addAttributeToFilter([
                ['attribute' => 'name', 'like' => '%' . $search . '%'],
                ['attribute' => 'sku', 'like' => '%' . $search . '%'],
            ]);
        }

        $total = $collection->getSize();
        $collection->setPage($page, $perPage);

        $items = [];
        foreach ($collection as $product) {
            /** @var ProductInterface $product */
            $arr = $this->mapper->toArray($product, false);
            if ($inStock !== null && $arr['in_stock'] !== $inStock) {
                continue;
            }
            $items[] = $arr;
        }

        return [
            'products' => $items,
            'total' => (int) $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            'ucp' => $this->envelope->build(['dev.ucp.shopping.catalog']),
        ];
    }

    public function getById(string $productId): array
    {
        if (!ctype_digit($productId)) {
            throw new WebapiException(new Phrase('Invalid product ID'), 0, WebapiException::HTTP_BAD_REQUEST);
        }
        try {
            $product = $this->productRepository->getById((int) $productId);
        } catch (NoSuchEntityException) {
            throw new WebapiException(new Phrase('Product not found'), 0, WebapiException::HTTP_NOT_FOUND);
        }
        $data = $this->mapper->toArray($product, true);
        $data['ucp'] = $this->envelope->build(['dev.ucp.shopping.catalog']);
        return $data;
    }
}
