<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

interface CategoriesInterface
{
    /**
     * GET /rest/V1/ucp/categories — full category tree.
     *
     * @return mixed[] { categories: CategoryNode[], ucp }
     */
    public function getTree(): array;
}
