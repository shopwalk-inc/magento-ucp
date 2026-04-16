<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Controller\Adminhtml\Sync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Shopwalk\Ucp\Model\SyncQueue;

/**
 * AJAX endpoint hit by the dashboard "Sync Now" button. Triggers a full sync
 * and drains one batch immediately — subsequent batches follow on the cron.
 */
class Now extends Action
{
    public const ADMIN_RESOURCE = 'Shopwalk_Ucp::sync';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly SyncQueue $queue,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $count = $this->queue->fullSync();
            $this->queue->flush('full');
            return $result->setData([
                'success' => true,
                'queued' => $count,
                'message' => sprintf('%d products queued for sync.', $count),
            ]);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
