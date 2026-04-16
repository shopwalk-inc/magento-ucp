<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Shopwalk license activation. Called when the merchant saves a new license
 * key in admin. Stores partner_id + webhook_secret returned by shopwalk-api
 * so subsequent sync calls can authenticate.
 */
class License
{
    public function __construct(
        private readonly SyncClient $client,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{ok: bool, partner_id?: string, message?: string}
     */
    public function activate(string $licenseKey): array
    {
        if ($licenseKey === '') {
            return ['ok' => false, 'message' => 'License key is empty'];
        }
        try {
            $resp = $this->client->post('/plugin/activate', [
                'license_key' => $licenseKey,
                'site_url' => $this->storeManager->getStore()->getBaseUrl(),
                'platform' => 'magento2',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('shopwalk-ucp: activation failed', ['err' => $e->getMessage()]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $partnerId = (string) ($resp['partner_id'] ?? '');
        if ($partnerId === '') {
            return ['ok' => false, 'message' => 'Activation response missing partner_id'];
        }
        $this->config->setPartnerId($partnerId);
        if (!empty($resp['webhook_secret'])) {
            $this->config->setWebhookSecret((string) $resp['webhook_secret']);
        }
        return ['ok' => true, 'partner_id' => $partnerId];
    }

    public function isValid(): bool
    {
        return $this->config->isConnected();
    }
}
