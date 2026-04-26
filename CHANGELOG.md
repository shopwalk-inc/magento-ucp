# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1]

### Added
- "Allow Shopwalk to surface my store in AI discovery" toggle on the connected-state dashboard. POSTs to the new `/api/v1/plugin/discovery/{disable,enable}` endpoints; mirrors the API state to `shopwalk_ucp/discovery/paused` config so the dashboard renders the right toggle position. Existing orders are unaffected; sync pauses while paused.
- `Shopwalk\Ucp\Model\DiscoveryToggle` for the API call + local mirror.
- `Shopwalk\Ucp\Controller\Adminhtml\Discovery\Toggle` AJAX endpoint at `shopwalk_ucp/discovery/toggle`.

## [1.0.0]

### Added
- Initial Magento 2 module: `Shopwalk_Ucp`
- UCP discovery at `/.well-known/ucp`
- Read endpoints: `/rest/V1/ucp/{store,products,categories}`
- Checkout endpoints under `/rest/V1/ucp/checkout-sessions`
- Sync trigger with HMAC-SHA256 verification
- Real-time sync observers for product save/delete + 5-minute cron flush
- Admin dashboard under **Shopwalk UCP**
- Tier-2 Shopwalk integration (license activation, full-catalog sync, order status webhooks)
- Configurable product variants with per-variant price / stock / attributes
- MSI (Multi-Source Inventory) stock with legacy fallback

## [1.0.0] - TBD

First stable release.
