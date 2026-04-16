<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Controller\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RouterInterface;

/**
 * Custom router that maps /.well-known/ucp onto our WellKnown controller.
 * Magento's StandardRouter can't match a path segment beginning with a dot,
 * so this router runs at sortOrder=10 (before the standard router at 20)
 * and short-circuits the dispatch.
 */
class WellKnownRouter implements RouterInterface
{
    public function __construct(private readonly ActionFactory $actionFactory) {}

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof HttpRequest) {
            return null;
        }
        $path = trim($request->getPathInfo(), '/');
        if ($path !== '.well-known/ucp') {
            return null;
        }
        $request->setModuleName('shopwalk_ucp')
            ->setControllerName('wellknown')
            ->setActionName('ucp');
        return $this->actionFactory->create(\Shopwalk\Ucp\Controller\WellKnown\Ucp::class);
    }
}
