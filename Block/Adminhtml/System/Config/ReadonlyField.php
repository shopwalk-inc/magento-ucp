<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a config field as read-only text (e.g. partner_id, last_sync_at).
 */
class ReadonlyField extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $value = (string) ($element->getValue() ?? '');
        if ($value === '') {
            $value = '—';
        }
        return sprintf(
            '<span id="%s" style="font-family:monospace">%s</span>',
            $element->getHtmlId(),
            $this->escapeHtml($value)
        );
    }
}
