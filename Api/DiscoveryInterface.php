<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Api;

/**
 * UCP Discovery endpoint.
 *
 * Returns the discovery document describing the store's UCP capabilities,
 * supported API versions, available endpoints, and authentication methods.
 *
 * @api
 */
interface DiscoveryInterface
{
    /**
     * Get UCP discovery document.
     *
     * @return mixed[]
     */
    public function getDiscovery(): array;
}
