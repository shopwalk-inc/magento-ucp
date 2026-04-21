<?php

declare(strict_types=1);

namespace Shopwalk\Ucp\Controller\WellKnown;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Shopwalk\Ucp\Api\DiscoveryInterface;

/**
 * Frontend controller for /.well-known/ucp.
 *
 * Magento's webapi.xml cannot register .well-known paths, so this uses
 * a standard frontend controller with a URL rewrite:
 *
 *   Apache (.htaccess):
 *     RewriteRule ^\.well-known/ucp$ /shopwalk_ucp/wellknown/ucp [L]
 *
 *   Nginx:
 *     location = /.well-known/ucp {
 *         rewrite ^ /shopwalk_ucp/wellknown/ucp last;
 *     }
 */
class Ucp implements HttpGetActionInterface
{
    public function __construct(
        private JsonFactory $jsonFactory,
        private DiscoveryInterface $discovery
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        return $result->setData($this->discovery->getDiscovery());
    }
}
