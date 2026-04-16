<?php
/**
 * Copyright © Shopwalk Inc. All rights reserved.
 * Licensed under GPL-2.0-or-later.
 */
declare(strict_types=1);

namespace Shopwalk\Ucp\Model;

use Magento\Payment\Model\Method\AbstractMethod;

/**
 * Offline payment method for UCP orders. Actual charge is made by Shopwalk
 * (Stripe Connect) — the store sees a "Shopwalk UCP" payment and receives
 * a payout minus the platform commission. Displayed in admin, hidden from
 * the storefront checkout.
 */
class UcpPaymentMethod extends AbstractMethod
{
    protected $_code = 'shopwalk_ucp';
    protected $_isOffline = true;
    protected $_isInitializeNeeded = false;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canRefund = false;
    protected $_canUseCheckout = false;
    protected $_canUseInternal = false;

    public function getTitle(): string
    {
        return 'Shopwalk UCP (AI Agent Purchase)';
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null): bool
    {
        // Never offered on the storefront checkout — only set server-side.
        return false;
    }
}
