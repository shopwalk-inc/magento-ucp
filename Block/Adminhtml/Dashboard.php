<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Shopwalk\Ucp\Model\Config;
use Shopwalk\Ucp\Model\SyncQueue;

class Dashboard extends Template
{
    protected $_template = 'Shopwalk_Ucp::dashboard.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly SyncQueue $queue,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getSyncNowUrl(): string
    {
        return $this->getUrl('shopwalk_ucp/sync/now');
    }

    public function getPartnerPortalUrl(): string
    {
        return 'https://shopwalk.com/partners/dashboard';
    }

    public function isConnected(): bool
    {
        return $this->config->isConnected();
    }

    public function getPartnerId(): string
    {
        return $this->config->getPartnerId() ?: '—';
    }

    public function getLastSyncAt(): string
    {
        return $this->config->getLastSyncAt() ?: 'Never';
    }

    public function getSyncState(): string
    {
        return $this->config->getSyncState();
    }

    public function getQueueDepth(): int
    {
        return $this->queue->getQueueDepth();
    }

    public function getUcpVersion(): string
    {
        return Config::UCP_VERSION;
    }

    public function getModuleVersion(): string
    {
        return Config::MODULE_VERSION;
    }

    public function getDiscoveryToggleUrl(): string
    {
        return $this->getUrl('shopwalk_ucp/discovery/toggle');
    }

    public function isDiscoveryPaused(): bool
    {
        return $this->config->isDiscoveryPaused();
    }
}
