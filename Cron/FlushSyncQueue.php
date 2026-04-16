<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Cron;

use Shopwalk\Ucp\Model\SyncQueue;

/**
 * Scheduled by etc/crontab.xml every 5 minutes. Drains at most one
 * BATCH_SIZE chunk per tick so Magento cron doesn't stall on a large queue —
 * the admin "Sync Now" path runs multiple flushes in a loop instead.
 */
class FlushSyncQueue
{
    public function __construct(private readonly SyncQueue $queue) {}

    public function execute(): void
    {
        $this->queue->flush('incremental');
    }
}
