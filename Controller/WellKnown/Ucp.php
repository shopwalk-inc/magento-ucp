<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Controller\WellKnown;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Shopwalk\Ucp\Model\Config;

/**
 * GET /.well-known/ucp — UCP discovery profile.
 * Served with Cache-Control: public, max-age=60 per the ucp.dev contract.
 * Lives under /.well-known so AI agents can discover UCP support with a
 * single HTTPS fetch at the site root.
 */
class Ucp implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
    ) {}

    public function execute(): ResultInterface
    {
        $base = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
        $apiBase = $base . '/rest/V1/ucp';

        $profile = [
            'ucp' => [
                'version' => Config::UCP_VERSION,
                'services' => [
                    'dev.ucp.shopping' => [
                        'endpoint' => $apiBase,
                        'transport' => 'rest',
                        'spec' => 'https://ucp.dev/latest/specification/overview/',
                    ],
                ],
                'capabilities' => [
                    'dev.ucp.shopping.catalog' => [
                        ['version' => Config::UCP_VERSION,
                         'spec' => 'https://ucp.dev/latest/specification/capabilities/catalog/'],
                    ],
                    'dev.ucp.shopping.checkout' => [
                        ['version' => Config::UCP_VERSION,
                         'spec' => 'https://ucp.dev/latest/specification/capabilities/checkout/'],
                    ],
                    'dev.ucp.shopping.order' => [
                        ['version' => Config::UCP_VERSION,
                         'spec' => 'https://ucp.dev/latest/specification/capabilities/order/'],
                    ],
                ],
                'payment_handlers' => (object) [],
                'signing_keys' => [],
                'platform' => 'magento2',
                'module' => 'Shopwalk_Ucp',
                'module_version' => Config::MODULE_VERSION,
                'shopwalk_enabled' => $this->config->isConnected(),
            ],
            'endpoints' => [
                'store' => $apiBase . '/store',
                'products' => $apiBase . '/products',
                'product_detail' => $apiBase . '/products/{id}',
                'categories' => $apiBase . '/categories',
                'checkout_sessions' => $apiBase . '/checkout-sessions',
                'sync_trigger' => $apiBase . '/sync/trigger',
            ],
            'authentication' => [
                'type' => 'oauth2',
                'token_endpoint' => $base . '/rest/V1/integration/admin/token',
            ],
        ];

        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(200);
        $result->setHeader('Cache-Control', 'public, max-age=60', true);
        $result->setHeader('Content-Type', 'application/ucp+json', true);
        $result->setData($profile);
        return $result;
    }
}
