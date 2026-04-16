# Magento UCP

**Universal Commerce Protocol (UCP) adapter for Magento 2.** Makes any Magento 2 store fully discoverable and purchasable by AI shopping agents.

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)
[![Magento 2](https://img.shields.io/badge/Magento-2.4.6%2B-orange.svg)](https://magento.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)

Same architecture as [woocommerce-ucp](https://github.com/shopwalk-inc/woocommerce-ucp) — different platform.

---

## What It Is

A Magento 2 module (`Shopwalk_Ucp`) that exposes a standards-compliant UCP surface:

- **Discovery** via `/.well-known/ucp` (UCP profile per [ucp.dev](https://ucp.dev) spec)
- **Catalog** via `/rest/V1/ucp/products`, `/rest/V1/ucp/products/:id`, `/rest/V1/ucp/categories`
- **Store info** via `/rest/V1/ucp/store`
- **Checkout** via `/rest/V1/ucp/checkout-sessions` (OAuth-authenticated)
- **Sync trigger** via `/rest/V1/ucp/sync/trigger` (HMAC-authenticated)

Works with any UCP-compatible AI agent. Ships with optional Shopwalk integration (license activation, product sync, brand voice, analytics).

---

## Two-Tier Architecture

**Tier 1 — UCP Core (open standard)**
- REST endpoints, `.well-known/ucp` discovery, OAuth client registration
- Works with ANY UCP-compatible agent

**Tier 2 — Shopwalk Integration (optional)**
- License activation, product sync to Shopwalk catalog
- Partner Portal dashboard, auto-updates from GitHub releases
- Removable — Tier 1 stands on its own

---

## Requirements

- Magento 2.4.6 or later (Magento 2.4.7 recommended)
- PHP 8.1 or later
- Magento cron enabled (5-minute interval for sync queue flush)

---

## Installation

### Via Composer (recommended)

```bash
composer require shopwalk-inc/module-ucp
bin/magento module:enable Shopwalk_Ucp
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Manual

1. Download the latest release from [Releases](https://github.com/shopwalk-inc/magento-ucp/releases)
2. Extract into `app/code/Shopwalk/Ucp/`
3. `bin/magento module:enable Shopwalk_Ucp && bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush`

---

## Configuration

**Admin → Stores → Configuration → Shopwalk → UCP Settings**

| Field | Notes |
|---|---|
| Enable UCP Endpoints | Exposes the `/rest/V1/ucp/*` surface |
| License Key | From the Shopwalk Partner Portal (optional — Tier 2 only) |
| Partner ID | Populated on license activation |

The UCP endpoints are accessible without a license. Tier 2 integration only activates when a valid license is present.

---

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/.well-known/ucp` | none | UCP profile (services, capabilities, keys) |
| GET | `/rest/V1/ucp/store` | none | Store info |
| GET | `/rest/V1/ucp/products` | none | Paginated product listing |
| GET | `/rest/V1/ucp/products/:id` | none | Product detail with variants |
| GET | `/rest/V1/ucp/categories` | none | Category tree |
| POST | `/rest/V1/ucp/checkout-sessions` | OAuth | Create checkout session |
| GET | `/rest/V1/ucp/checkout-sessions/:id` | OAuth | Checkout session status |
| POST | `/rest/V1/ucp/sync/trigger` | HMAC | Trigger full catalog sync |

See [ucp.dev](https://ucp.dev) for the canonical protocol spec.

---

## Product Types

| Magento Type | UCP Representation |
|---|---|
| Simple | Single product, no variants |
| Configurable | Parent product with a `variants[]` array of children |
| Grouped | Each member synced as a separate product |
| Bundle | Single product; options embedded in description |
| Virtual / Downloadable | Single product, `shippable=false` |

---

## Development

```bash
composer install
vendor/bin/phpcs --standard=Magento2 .
vendor/bin/phpstan analyse
```

---

## License

GPL-2.0-or-later — same as Magento 2.

---

## Links

- [ucp.dev](https://ucp.dev) — Universal Commerce Protocol specification
- [shopwalk.com](https://shopwalk.com) — Shopwalk Partner Portal
- [woocommerce-ucp](https://github.com/shopwalk-inc/woocommerce-ucp) — WordPress/WooCommerce equivalent
