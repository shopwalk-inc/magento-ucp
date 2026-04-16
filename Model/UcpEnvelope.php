<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

/**
 * Builds the standard `ucp` object the protocol requires on every response.
 * See https://ucp.dev — the business MUST return the negotiated version and
 * the capabilities it advertises.
 */
class UcpEnvelope
{
    /**
     * @param string[] $capabilityNames e.g. ['dev.ucp.shopping.catalog']
     * @return mixed[]
     */
    public function build(array $capabilityNames = []): array
    {
        $capabilities = [];
        foreach ($capabilityNames as $name) {
            $capabilities[$name] = [['version' => Config::UCP_VERSION]];
        }
        return [
            'version' => Config::UCP_VERSION,
            'capabilities' => (object) $capabilities,
        ];
    }
}
