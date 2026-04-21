<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Shopwalk\Ucp\Api\DiscoveryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Returns the UCP discovery document (spec version 2026-04-08).
 *
 * The document describes all UCP services the store exposes, their
 * transport, endpoint URL, capabilities, and plugin metadata.
 */
class Discovery implements DiscoveryInterface
{
    private const UCP_VERSION = '2026-04-08';

    public function __construct(
        private StoreManagerInterface $storeManager,
        private ScopeConfigInterface  $scopeConfig
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getDiscovery(): array
    {
        $baseUrl  = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
        $endpoint = $baseUrl . '/rest/V1/shopwalk/ucp';

        $version = $this->scopeConfig->getValue(
            'shopwalk/ucp/plugin_version',
            ScopeInterface::SCOPE_STORE
        ) ?? '1.0.0';

        $specBase = 'https://ucp.dev/latest/specification';

        return [
            'ucp' => [
                'version'  => self::UCP_VERSION,
                'services' => [
                    'dev.ucp.shopping.checkout' => [
                        'version'   => self::UCP_VERSION,
                        'spec'      => $specBase . '/checkout-rest/',
                        'transport' => 'rest',
                        'endpoint'  => $endpoint,
                    ],
                    'dev.ucp.shopping.order' => [
                        'version'   => self::UCP_VERSION,
                        'spec'      => $specBase . '/order-rest/',
                        'transport' => 'rest',
                        'endpoint'  => $endpoint,
                    ],
                    'dev.ucp.shopping.catalog' => [
                        'version'   => self::UCP_VERSION,
                        'spec'      => $specBase . '/catalog-rest/',
                        'transport' => 'rest',
                        'endpoint'  => $endpoint,
                    ],
                    'dev.ucp.common.identity_linking' => [
                        'version'   => self::UCP_VERSION,
                        'spec'      => $specBase . '/identity-linking-rest/',
                        'transport' => 'rest',
                        'endpoint'  => $endpoint,
                    ],
                ],
                'capabilities' => [
                    'dev.ucp.shopping.checkout' => [
                        'version' => self::UCP_VERSION,
                        'spec'    => $specBase . '/checkout-rest/',
                    ],
                    'dev.ucp.shopping.order' => [
                        'version' => self::UCP_VERSION,
                        'spec'    => $specBase . '/order-rest/',
                    ],
                    'dev.ucp.shopping.catalog' => [
                        'version' => self::UCP_VERSION,
                        'spec'    => $specBase . '/catalog-rest/',
                    ],
                    'dev.ucp.common.identity_linking' => [
                        'version' => self::UCP_VERSION,
                        'spec'    => $specBase . '/identity-linking-rest/',
                    ],
                ],
                'payment_handlers' => new \stdClass(),
                'signing_keys'     => [],
            ],
            'platform' => 'magento',
            'plugin'   => [
                'name'    => 'Shopwalk AI — UCP Adapter for Magento',
                'version' => $version,
            ],
        ];
    }
}
