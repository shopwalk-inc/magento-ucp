<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Persisted sync queue for product upserts/deletes. Entries are stored as JSON
 * in core_config_data under shopwalk_ucp/sync/queue; the cron and admin "Sync
 * Now" action both call flush() to drain it in batches of 100.
 */
class SyncQueue
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ConfigWriter $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductMapper $mapper,
        private readonly SyncClient $client,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array{op: string, product_id: int} $event
     */
    public function push(array $event): void
    {
        $queue = $this->getQueue();
        $queue[] = $event;
        $this->saveQueue($queue);
    }

    /**
     * Queue every visible + enabled product. Returns the number queued.
     */
    public function fullSync(): int
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);
        $collection->addAttributeToFilter('status', 1);

        $queue = [];
        foreach ($collection->getAllIds() as $id) {
            $queue[] = ['op' => 'upsert', 'product_id' => (int) $id];
        }
        $this->saveQueue($queue);
        $this->config->setSyncState('syncing');
        return count($queue);
    }

    /**
     * Drain BATCH_SIZE entries and POST them to shopwalk-api. Idempotent:
     * re-running on a partial failure picks up from the saved queue tail.
     */
    public function flush(string $syncType = 'incremental'): void
    {
        if (!$this->config->isConnected()) {
            return;
        }
        $queue = $this->getQueue();
        if (!$queue) {
            if ($this->config->getSyncState() !== 'idle') {
                $this->config->setSyncState('idle');
            }
            return;
        }

        $batch = array_splice($queue, 0, self::BATCH_SIZE);
        $this->saveQueue($queue);

        $products = $this->loadBatch($batch);
        if (!$products) {
            if (!$queue) {
                $this->config->setSyncState('idle');
            }
            return;
        }

        try {
            $this->client->postBatch($products, $syncType);
            $this->config->setLastSyncAt(gmdate('c'));
            if (!$queue) {
                $this->config->setSyncState('idle');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('shopwalk-ucp: sync batch failed', ['err' => $e->getMessage()]);
            // Restore the failed batch to the front of the queue so the next
            // flush retries. Fail-open: idempotent retries are safer than
            // silently dropping products.
            $queue = array_merge($batch, $queue);
            $this->saveQueue($queue);
            $this->config->setSyncState('error');
        }
    }

    /**
     * @param array<int, array{op: string, product_id: int}> $batch
     * @return mixed[]
     */
    private function loadBatch(array $batch): array
    {
        $out = [];
        foreach ($batch as $entry) {
            $productId = (int) ($entry['product_id'] ?? 0);
            $op = (string) ($entry['op'] ?? 'upsert');
            if ($productId <= 0) {
                continue;
            }
            if ($op === 'delete') {
                $out[] = ['external_id' => (string) $productId, 'op' => 'delete'];
                continue;
            }
            try {
                $product = $this->productRepository->getById($productId);
            } catch (NoSuchEntityException) {
                continue;
            }
            $data = $this->mapper->toArray($product, true);
            $data['op'] = 'upsert';
            $out[] = $data;
        }
        return $out;
    }

    /**
     * @return array<int, array{op: string, product_id: int}>
     */
    public function getQueue(): array
    {
        $raw = (string) $this->scopeConfig->getValue(Config::PATH_SYNC_QUEUE);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getQueueDepth(): int
    {
        return count($this->getQueue());
    }

    /**
     * @param array<int, array{op: string, product_id: int}> $queue
     */
    private function saveQueue(array $queue): void
    {
        $this->configWriter->save(Config::PATH_SYNC_QUEUE, json_encode($queue, JSON_UNESCAPED_SLASHES));
        $this->reinitableConfig->reinit();
    }
}
