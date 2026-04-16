<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

interface SyncTriggerInterface
{
    /**
     * POST /rest/V1/ucp/sync/trigger — force a full catalog sync.
     *
     * HMAC-SHA256 signature is required in the X-Shopwalk-Signature header,
     * computed against the raw body using the partner webhook_secret.
     *
     * @param mixed[] $data Trigger payload (reason, requested_at).
     * @return mixed[] { status, product_count, reason }
     */
    public function trigger(array $data): array;
}
