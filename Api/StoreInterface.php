<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

/**
 * UCP Store metadata.
 *
 * Returns public store information: name, URL, description, currency,
 * product count, UCP/plugin versions, and Shopwalk connection status.
 *
 * @api
 */
interface StoreInterface
{
    /**
     * Get store metadata.
     *
     * Returns an associative array containing:
     *  - name              (string)  Store display name
     *  - url               (string)  Store base URL
     *  - description       (string)  Store description
     *  - currency          (string)  ISO 4217 currency code
     *  - product_count     (int)     Total visible product count
     *  - ucp_version       (string)  UCP protocol version
     *  - plugin_version    (string)  Magento module version
     *  - shopwalk_connected (bool)   Whether the store is linked to Shopwalk
     *  - shopwalk_partner_id (string|null) Shopwalk partner identifier
     *
     * @return mixed[]
     */
    public function getStore(): array;
}
