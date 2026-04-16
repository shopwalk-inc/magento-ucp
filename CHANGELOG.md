# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
