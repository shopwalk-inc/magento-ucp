<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Thin HTTP client for all outbound calls to shopwalk-api. Keeps the auth
 * headers, error semantics, and retries in one place.
 */
class SyncClient
{
    private const USER_AGENT = 'shopwalk-ucp-magento/' . Config::MODULE_VERSION;

    public function __construct(
        private readonly Curl $curl,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
    ) {}

    /**
     * @param mixed[] $products
     * @throws \RuntimeException on non-2xx.
     */
    public function postBatch(array $products, string $syncType = 'incremental'): array
    {
        $body = [
            'site_url' => $this->storeManager->getStore()->getBaseUrl(),
            'sync_type' => $syncType,
            'total_products' => count($products),
            'products' => $products,
        ];
        return $this->post('/plugin/sync/batch', $body);
    }

    /**
     * @param mixed[] $data
     */
    public function post(string $path, array $data): array
    {
        $url = $this->config->getApiBase() . $path;
        $this->curl->setHeaders($this->defaultHeaders());
        $this->curl->setOption(CURLOPT_TIMEOUT, 30);
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
        $this->curl->post($url, (string) json_encode($data, JSON_UNESCAPED_SLASHES));
        $status = (int) $this->curl->getStatus();
        $body = (string) $this->curl->getBody();

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('shopwalk-api %s returned %d: %s', $path, $status, $body));
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
            'X-SW-Domain' => (string) (parse_url($this->storeManager->getStore()->getBaseUrl(), PHP_URL_HOST) ?: ''),
        ];
        $key = $this->config->getLicenseKey();
        if ($key !== '') {
            $headers['X-SW-License-Key'] = $key;
        }
        return $headers;
    }
}
