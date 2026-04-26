<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Thin reader/writer for every `shopwalk_ucp/*` config path the module touches.
 * Keeps raw scopeConfig->getValue calls out of the rest of the code.
 */
class Config
{
    public const UCP_VERSION = '2026-04-08';
    public const MODULE_VERSION = '1.0.1';

    public const PATH_ENABLED = 'shopwalk_ucp/general/enabled';
    public const PATH_LICENSE_KEY = 'shopwalk_ucp/license/key';
    public const PATH_PARTNER_ID = 'shopwalk_ucp/license/partner_id';
    public const PATH_WEBHOOK_SECRET = 'shopwalk_ucp/license/webhook_secret';
    public const PATH_API_BASE = 'shopwalk_ucp/sync/api_base';
    public const PATH_SYNC_STATE = 'shopwalk_ucp/sync/state';
    public const PATH_LAST_SYNC_AT = 'shopwalk_ucp/sync/last_sync_at';
    public const PATH_SYNC_QUEUE = 'shopwalk_ucp/sync/queue';
    public const PATH_DISCOVERY_PAUSED = 'shopwalk_ucp/discovery/paused';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ConfigWriter $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getLicenseKey(): string
    {
        return (string) $this->scopeConfig->getValue(self::PATH_LICENSE_KEY);
    }

    public function getPartnerId(): string
    {
        return (string) $this->scopeConfig->getValue(self::PATH_PARTNER_ID);
    }

    public function getWebhookSecret(): string
    {
        return (string) $this->scopeConfig->getValue(self::PATH_WEBHOOK_SECRET);
    }

    public function getApiBase(): string
    {
        $base = (string) $this->scopeConfig->getValue(self::PATH_API_BASE);
        return rtrim($base !== '' ? $base : 'https://api.shopwalk.com/api/v1', '/');
    }

    public function getSyncState(): string
    {
        return (string) ($this->scopeConfig->getValue(self::PATH_SYNC_STATE) ?: 'idle');
    }

    public function getLastSyncAt(): ?string
    {
        $v = (string) $this->scopeConfig->getValue(self::PATH_LAST_SYNC_AT);
        return $v !== '' ? $v : null;
    }

    public function isConnected(): bool
    {
        return $this->getLicenseKey() !== '' && $this->getPartnerId() !== '';
    }

    public function setPartnerId(string $partnerId): void
    {
        $this->configWriter->save(self::PATH_PARTNER_ID, $partnerId);
        $this->reinitableConfig->reinit();
    }

    public function setWebhookSecret(string $secret): void
    {
        $this->configWriter->save(self::PATH_WEBHOOK_SECRET, $secret);
        $this->reinitableConfig->reinit();
    }

    public function setSyncState(string $state): void
    {
        $this->configWriter->save(self::PATH_SYNC_STATE, $state);
        $this->reinitableConfig->reinit();
    }

    public function setLastSyncAt(string $iso): void
    {
        $this->configWriter->save(self::PATH_LAST_SYNC_AT, $iso);
        $this->reinitableConfig->reinit();
    }

    public function isDiscoveryPaused(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::PATH_DISCOVERY_PAUSED);
    }

    public function setDiscoveryPaused(bool $paused): void
    {
        $this->configWriter->save(self::PATH_DISCOVERY_PAUSED, $paused ? '1' : '0');
        $this->reinitableConfig->reinit();
    }
}
