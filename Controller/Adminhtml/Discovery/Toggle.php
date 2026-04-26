<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Controller\Adminhtml\Discovery;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Shopwalk\Ucp\Model\DiscoveryToggle;

/**
 * AJAX endpoint hit by the dashboard pause/resume toggle.
 *
 * Body: { "enable": "1" | "0" }
 */
class Toggle extends Action
{
    public const ADMIN_RESOURCE = 'Shopwalk_Ucp::sync';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly DiscoveryToggle $toggle,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $enable = $this->getRequest()->getParam('enable') === '1';
        $ok = $enable ? $this->toggle->resume() : $this->toggle->pause();
        if (!$ok) {
            return $result->setHttpResponseCode(502)->setData([
                'success' => false,
                'message' => 'Could not reach Shopwalk. Try again in a moment.',
            ]);
        }
        return $result->setData([
            'success' => true,
            'paused' => !$enable,
        ]);
    }
}
