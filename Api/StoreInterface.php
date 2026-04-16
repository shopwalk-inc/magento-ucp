<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

interface StoreInterface
{
    /**
     * GET /rest/V1/ucp/store — store info consumed by AI agents during discovery.
     *
     * @return mixed[] Associative array with store name, url, currency, counts, and UCP envelope.
     */
    public function getInfo(): array;
}
