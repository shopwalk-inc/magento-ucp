<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Cron;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Shopwalk\Ucp\Helper\WebhookDelivery;

/**
 * Runs every minute. Delivers pending webhook queue items with exponential backoff.
 */
class WebhookFlush
{
    /**
     * Maximum number of queue items to process per cron run.
     */
    private const BATCH_LIMIT = 50;

    public function __construct(
        private ResourceConnection $resourceConnection,
        private WebhookDelivery $webhookDelivery,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Find all pending queue items where next_attempt_at <= NOW(),
     * delivered_at IS NULL, failed_at IS NULL, and attempts < 5.
     * Deliver each via WebhookDelivery::deliver().
     */
    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('shopwalk_ucp_webhook_queue');

        try {
            $select = $connection->select()
                ->from($table, ['id'])
                ->where('delivered_at IS NULL')
                ->where('failed_at IS NULL')
                ->where('attempts < ?', 5)
                ->where('next_attempt_at IS NULL OR next_attempt_at <= NOW()')
                ->order('next_attempt_at ASC')
                ->limit(self::BATCH_LIMIT);

            $queueIds = $connection->fetchCol($select);

            foreach ($queueIds as $queueId) {
                try {
                    $this->webhookDelivery->deliver((int) $queueId);
                } catch (\Exception $e) {
                    $this->logger->error(
                        sprintf('Shopwalk UCP WebhookFlush: Failed to deliver queue item %d: %s', $queueId, $e->getMessage()),
                        ['exception' => $e]
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Shopwalk UCP WebhookFlush error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
