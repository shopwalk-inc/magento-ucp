<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Shopwalk\Ucp\Model\Config;
use Shopwalk\Ucp\Model\License;

/**
 * Fired when admin saves Stores → Config → Shopwalk UCP. If a license key is
 * present but we don't yet have a partner_id, run activation. One-shot — no
 * retries on save; the admin dashboard has a re-activate button.
 */
class ConfigSave implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly License $license,
        private readonly MessageManager $messenger,
    ) {}

    public function execute(Observer $observer): void
    {
        $key = $this->config->getLicenseKey();
        if ($key === '' || $this->config->getPartnerId() !== '') {
            return;
        }
        $result = $this->license->activate($key);
        if ($result['ok']) {
            $this->messenger->addSuccessMessage(
                __('Shopwalk UCP activated. Partner ID: %1', $result['partner_id'])
            );
        } else {
            $this->messenger->addErrorMessage(
                __('Shopwalk UCP activation failed: %1', $result['message'] ?? 'unknown error')
            );
        }
    }
}
