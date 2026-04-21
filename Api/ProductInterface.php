<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

/**
 * UCP Product catalog.
 *
 * Provides paginated, read-only access to the store's product catalog
 * for AI agents and external consumers.
 *
 * @api
 */
interface ProductInterface
{
    /**
     * Get paginated product list.
     *
     * Each product entry contains:
     *  - id                (int)
     *  - name              (string)
     *  - slug              (string)
     *  - type              (string)    e.g. simple, configurable, grouped, bundle
     *  - status            (string)    enabled / disabled
     *  - sku               (string)
     *  - description       (string)
     *  - short_description (string)
     *  - price             (float)     Current effective price
     *  - regular_price     (float)
     *  - sale_price        (float|null)
     *  - on_sale           (bool)
     *  - in_stock          (bool)
     *  - stock_quantity    (int|null)
     *  - categories        (string[])
     *  - image_url         (string|null)
     *  - gallery_urls      (string[])
     *  - permalink         (string)
     *  - brand             (string|null)
     *  - date_created      (string)    ISO 8601
     *  - date_modified     (string)    ISO 8601
     *
     * Response structure:
     *  - products  (array)  Product entries
     *  - total     (int)    Total product count matching criteria
     *  - page      (int)    Current page number
     *  - per_page  (int)    Items per page
     *  - pages     (int)    Total number of pages
     *
     * @param int $page    Page number (1-based, default 1).
     * @param int $perPage Items per page (default 100).
     * @return mixed[]
     */
    public function getList(int $page = 1, int $perPage = 100): array;
}
