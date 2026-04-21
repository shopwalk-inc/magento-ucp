<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Centralized configuration helper for the Shopwalk UCP module.
 */
class Config
{
    public const XML_PATH_ENABLED        = 'shopwalk/ucp/enabled';
    public const XML_PATH_LICENSE_KEY    = 'shopwalk/ucp/license_key';
    public const XML_PATH_API_URL        = 'shopwalk/ucp/api_url';
    public const XML_PATH_SIGNING_SECRET = 'shopwalk/ucp/signing_secret';

    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Check whether the UCP module is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get the license key.
     */
    public function getLicenseKey(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_LICENSE_KEY,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get the Shopwalk API URL.
     */
    public function getApiUrl(): string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_API_URL,
            ScopeInterface::SCOPE_STORE
        ) ?? 'https://api.shopwalk.com';
    }

    /**
     * Get the webhook signing secret.
     */
    public function getSigningSecret(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SIGNING_SECRET,
            ScopeInterface::SCOPE_STORE
        );
    }
}
