<!-- ask_self:managed architecture_doc_v1 -->

# ShipStation for WooCommerce — Architecture Overview

=== ShipStation for WooCommerce ===

## Repo stats (approximate)
- Files indexed in repo: ~37
- Total lines of code: ~8,226
- By language:
  - PHP: 22 files, 6,689 LOC
  - Text: 2 files, 666 LOC
  - JavaScript: 2 files, 409 LOC
  - JSON: 2 files, 191 LOC
  - Markdown: 2 files, 183 LOC
  - Shell: 2 files, 81 LOC
  - CSS: 4 files, 4 LOC
  - Other: 1 files, 3 LOC
- Git: branch `main` at `6f69c73`

## Top-level layout
- `ask_self/` (~3 files)
- `assets/` (~9 files)
- `includes/` (~20 files)
- `scripts/` (~2 files)
- `templates/` (~1 files)

## Most important files
### Source: php
- `includes/class-checkout.php` (priority 4, 12 chunks)
- `includes/class-wc-shipstation-integration.php` (priority 4, 7 chunks)
- `includes/class-auth-controller.php` (priority 4, 4 chunks)
- `includes/class-order-util.php` (priority 4, 4 chunks)
- `includes/class-main.php` (priority 4, 2 chunks)
- `includes/class-wc-shipstation-privacy.php` (priority 4, 2 chunks)
- `includes/class-features.php` (priority 4, 1 chunk)
- `includes/class-logger.php` (priority 4, 1 chunk)
- `includes/class-rest-api-loader.php` (priority 4, 1 chunk)
- `includes/class-wc-shipstation-api.php` (priority 4, 1 chunk)

### Source: rest-controller
- `includes/api/rest/class-orders-controller.php` (priority 4, 20 chunks)
- `includes/api/rest/class-inventory-controller.php` (priority 4, 5 chunks)
- `includes/api/rest/class-diagnostics-controller.php` (priority 4, 2 chunks)
- `includes/api/rest/class-api-controller.php` (priority 4, 1 chunk)

### Source: api-request
- `includes/api/requests/class-wc-shipstation-api-export.php` (priority 4, 8 chunks)
- `includes/api/requests/class-wc-shipstation-api-shipnotify.php` (priority 4, 5 chunks)
- `includes/api/requests/class-wc-safe-domdocument.php` (priority 4, 2 chunks)
- `includes/api/requests/class-wc-shipstation-api-request.php` (priority 4, 1 chunk)

### Source: script
- `assets/js/auth-display.js` (priority 3, 3 chunks)
- `assets/js/checkout.js` (priority 3, 1 chunk)

### Source: style
- `assets/css/admin.css` (priority 2, 1 chunk)
- `assets/css/auth-display.css` (priority 2, 1 chunk)
- `assets/css/block-checkout.css` (priority 2, 1 chunk)
- `assets/css/classic-checkout.css` (priority 2, 1 chunk)

### Source: config
- `includes/data/data-settings.php` (priority 3, 3 chunks)

### Source: doc
- `ARCHITECTURE.md` (priority 4, 2 chunks)
- `AGENTS.md` (priority 4, 1 chunk)

### Source: plugin-readme
- `readme.txt` (priority 5, 3 chunks)

### Source: template
- `templates/auth-modal.php` (priority 4, 2 chunks)

### Source: changelog
- `changelog.txt` (priority 5, 1 chunk)

### Source: checkout
- `includes/checkout/class-checkout-rates-shipping-method.php` (priority 4, 1 chunk)

### Source: plugin-bootstrap
- `woocommerce-shipstation.php` (priority 5, 1 chunk)

## Freshness
- Generated at: `2026-05-11T02:58:18+00:00`
- Generated from commit: `6f69c73`
- Current HEAD at ingest: `6f69c73`
- Working tree at ingest: clean
- This document should be considered stale once the repo moves to a different HEAD or the working tree changes materially.

## How it fits together
The plugin boots from `woocommerce-shipstation.php`, which defines path/version constants, loads `includes/class-main.php`, and returns `Main::instance()`. `Main` is the top-level coordinator: it waits for `plugins_loaded`, registers the WooCommerce integration class, wires the legacy `woocommerce_api_wc_shipstation` endpoint, adds plugin action links, declares HPOS compatibility, and later initializes the custom REST API and optional shipping-method registration.

`Main::load_files()` assembles the runtime by loading `Features`, `Order_Util`, `WC_ShipStation_Integration`, `Auth_Controller`, `Logger`, `WC_ShipStation_Privacy`, `WC_Shipstation_API`, and the REST loader class. It conditionally loads `Checkout` only when WooCommerce is `9.7.0` or newer, and it conditionally exposes the `shipstation_checkout_rates` shipping method when the `wc_shipstation_checkout_rates_enabled` feature flag is enabled. The plugin therefore has three primary runtime surfaces: WooCommerce integration/settings, a legacy ShipStation XML-style API, and a newer custom REST surface.

The legacy ShipStation API path is handled by `WC_Shipstation_API` plus `includes/api/requests/`. Requests arrive through the WooCommerce legacy API action, require `auth_key`, and dispatch only `action=export` or `action=shipnotify`. `WC_Shipstation_API_Export` produces XML order exports for ShipStation, while `WC_Shipstation_API_Shipnotify` consumes shipment notifications, adds tracking notes, updates shipped-item counters, fires integration hooks, and optionally changes the WooCommerce order status.

The custom REST API lives under `wc-shipstation/v1` and is registered by `REST_API_Loader`. `Orders_Controller` handles order export (`GET /orders`) and shipment ingestion (`POST /orders/shipments`); `Inventory_Controller` provides inventory read and stock update endpoints; `Diagnostics_Controller` exposes site details and a lightweight validation endpoint under `/diagnostics/details` and `/diagnostics/validate`. The REST order flow is not a thin wrapper around the legacy XML export: it has its own payload shaping and status-mapping logic, and it deliberately fires the legacy `woocommerce_api_wc_shipstation` action so third-party plugins that hook the old export path can still influence REST exports.

`WC_ShipStation_Integration` is the plugin's configuration and state hub rather than just admin glue. It creates and stores the ShipStation auth key, loads settings from `includes/data/data-settings.php`, tracks export statuses, shipped status, gift enablement, API mode, and ShipStation-to-WooCommerce status mappings, and updates API mode and status mappings when the REST `GET /orders` flow sends those request parameters. It also augments diagnostics output and contains admin-notice/auth-display wiring used on the WooCommerce integration screen.

Authentication for the admin connection flow is handled by `Auth_Controller` plus `templates/auth-modal.php` and `assets/js/auth-display.js`. This controller does not simply store credentials in options: it generates WooCommerce REST API credentials by inserting into the `woocommerce_api_keys` table, stores only the generated key ID in an option, and exposes the consumer key/secret only at creation time through authenticated AJAX responses. It also renders the modal used to display the store URL, auth key, and newly generated REST credentials.

Checkout integration is broader than a single gift-message field. `Checkout` adds gift fields to both classic and block checkout, persists values through AJAX/session helpers, validates and stores the data, renders it in admin, customer, and email contexts, and cleans up session/meta state after checkout. The export path then reads that order metadata so ShipStation exports can include `Gift`, `GiftMessage`, `CustomerNotes`, and `InternalNotes` where applicable.

`Order_Util` is the cross-cutting compatibility layer used throughout the plugin. It abstracts differences between legacy orders and HPOS, resolves order IDs from order numbers for common sequential-order plugins, counts shippable items, normalizes address and shipping-method export data, and retrieves order notes. Both the legacy XML handlers and the REST controllers depend on it to keep order access and export behavior consistent.

Privacy and logging are handled separately. `WC_ShipStation_Privacy` registers WooCommerce privacy exporters and erasers for tracking-related order meta, while `Logger` centralizes debug and error logging and respects the integration's logging setting. These are supporting subsystems rather than the core request flow, but they are part of the operational surface of the plugin.

---
_Generated by ask-self ingest at 2026-05-11T02:58:18+00:00 using ollama:qwen3:8b. Embed model: Qwen/Qwen3-Embedding-0.6B (dim=1024). Indexed chunks: 102._

_Chunks by source: php=35, rest-controller=28, api-request=16, style=4, script=4, plugin-readme=3, doc=3, config=3, template=2, plugin-bootstrap=1, overview=1, checkout=1, changelog=1._
