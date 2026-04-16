<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

interface ProductsInterface
{
    /**
     * GET /rest/V1/ucp/products — paginated listing.
     *
     * @param int $page        1-indexed page number.
     * @param int $perPage     Items per page (clamped to 1..100).
     * @param string|null $category Category name or ID filter.
     * @param string|null $search   Full-text search against name + SKU.
     * @param bool|null $inStock    Filter to in-stock items when provided.
     * @return mixed[] { products: ProductItem[], total, page, per_page, total_pages, ucp }
     */
    public function getList(
        int $page = 1,
        int $perPage = 25,
        ?string $category = null,
        ?string $search = null,
        ?bool $inStock = null
    ): array;

    /**
     * GET /rest/V1/ucp/products/:productId — single product detail.
     *
     * @param string $productId Magento product entity ID (as string for URL compatibility).
     * @return mixed[]
     */
    public function getById(string $productId): array;
}
