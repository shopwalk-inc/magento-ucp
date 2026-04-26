<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Psr\Log\LoggerInterface;

/**
 * Pause/resume AI discoverability for the connected store. POSTs to
 * shopwalk-api /plugin/discovery/{disable,enable} with the local license key
 * and mirrors the result to PATH_DISCOVERY_PAUSED so the dashboard renders
 * the right toggle position without a round-trip on every load.
 */
class DiscoveryToggle
{
    public function __construct(
        private readonly SyncClient $client,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function pause(): bool
    {
        return $this->call('disable', true);
    }

    public function resume(): bool
    {
        return $this->call('enable', false);
    }

    private function call(string $action, bool $nextPaused): bool
    {
        if (!$this->config->isConnected()) {
            return false;
        }
        try {
            $this->client->post('/plugin/discovery/' . $action, [
                'plugin_key' => $this->config->getLicenseKey(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('shopwalk-ucp: discovery toggle failed', [
                'action' => $action,
                'err' => $e->getMessage(),
            ]);
            return false;
        }
        $this->config->setDiscoveryPaused($nextPaused);
        return true;
    }
}
