<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Cron;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Runs hourly. Cancels incomplete checkout sessions that have expired.
 */
class SessionCleanup
{
    public function __construct(
        private ResourceConnection $resourceConnection,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Find checkout sessions where status = 'incomplete' and expires_at < NOW(),
     * then set status to 'canceled'.
     */
    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('shopwalk_ucp_checkout_sessions');

        try {
            $affected = $connection->update(
                $table,
                ['status' => 'canceled'],
                [
                    'status = ?' => 'incomplete',
                    'expires_at < NOW()',
                ]
            );

            if ($affected > 0) {
                $this->logger->info(
                    sprintf('Shopwalk UCP: Canceled %d expired checkout session(s).', $affected)
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Shopwalk UCP SessionCleanup error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
