<!-- ask_self:managed architecture_doc_v1 -->

# ShipStation for WooCommerce — Architecture Overview

=== ShipStation for WooCommerce ===

## Indexed stats (ask-self ingest)
- These counts appear to describe the ask-self indexed corpus, not the full working tree.
- They are useful as ingest metadata, but should not be treated as authoritative repo totals.
- Git: branch `main` at `e0fe911`

## Top-level layout
- `ask_self/` — local ask-self integration config.
- `assets/` — checkout/auth JS, CSS, and plugin assets.
- `includes/` — core plugin runtime, legacy API handlers, REST controllers, checkout code, and settings data.
- `languages/` — translation template.
- `scripts/` — ask-self wrapper scripts.
- `templates/` — admin auth modal template.
- `wordpress_org_assets/` — WordPress.org listing assets, not runtime code.
- Root files — plugin bootstrap (`woocommerce-shipstation.php`), docs, and plugin metadata (`readme.txt`, `changelog.txt`).

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

### Source: plugin-readme
- `readme.txt` (priority 5, 3 chunks)

### Source: template
- `templates/auth-modal.php` (priority 4, 2 chunks)

### Source: changelog
- `changelog.txt` (priority 5, 1 chunk)

### Source: checkout
- `includes/checkout/class-checkout-rates-shipping-method.php` (priority 4, 1 chunk)

### Source: doc
- `AGENTS.md` (priority 4, 1 chunk)

### Source: plugin-bootstrap
- `woocommerce-shipstation.php` (priority 5, 1 chunk)

## Freshness
- Generated at: `2026-05-11T02:24:21+00:00`
- Generated from commit: `e0fe911`
- Current HEAD at ingest: `e0fe911`
- Working tree at ingest: dirty (4 files)
- This document should be considered stale once the repo moves to a different HEAD or the working tree changes materially.

## How it fits together
The plugin boots from `woocommerce-shipstation.php`, which defines path/version constants, loads `includes/class-main.php`, and returns `Main::instance()`. `Main` is the top-level coordinator: it waits for `plugins_loaded`, registers the WooCommerce integration class, wires the legacy `woocommerce_api_wc_shipstation` endpoint, adds plugin action links, declares HPOS compatibility, and later initializes the custom REST API and optional shipping-method registration.

`Main::load_files()` is where the runtime is assembled. It loads `Features`, `Order_Util`, `WC_ShipStation_Integration`, `Auth_Controller`, `Logger`, `WC_ShipStation_Privacy`, `WC_Shipstation_API`, and the REST loader. It conditionally loads `Checkout` only when WooCommerce is `9.7.0` or newer, and it conditionally exposes the `shipstation_checkout_rates` shipping method when the `wc_shipstation_checkout_rates_enabled` feature flag is enabled. This means the plugin has three distinct runtime surfaces: WooCommerce integration/settings, a legacy ShipStation XML-style API, and a newer custom REST surface.

The legacy ShipStation API path is handled by `WC_Shipstation_API` plus `includes/api/requests/`. Requests arrive through the WooCommerce legacy API action, require `auth_key`, and dispatch only `action=export` or `action=shipnotify`. `WC_Shipstation_API_Export` produces XML order exports for ShipStation, while `WC_Shipstation_API_Shipnotify` consumes shipment notifications, adds tracking notes, updates shipped-item counters, fires integration hooks, and optionally changes the WooCommerce order status.

The custom REST API lives under `wc-shipstation/v1` and is registered by `REST_API_Loader`. `Orders_Controller` handles order export (`GET /orders`) and shipment ingestion (`POST /orders/shipments`); `Inventory_Controller` provides inventory read and stock update endpoints; `Diagnostics_Controller` exposes site details and a lightweight validation endpoint. The REST order flow is not a thin wrapper around the legacy XML export: it has its own payload shaping, status-mapping logic, and compatibility bridge that deliberately fires the legacy `woocommerce_api_wc_shipstation` action so third-party plugins that hook the old export path can still influence REST exports.

`WC_ShipStation_Integration` is the plugin's configuration and state hub rather than just admin glue. It creates and stores the ShipStation auth key, loads settings from `includes/data/data-settings.php`, tracks export statuses, shipped status, gift enablement, API mode, and ShipStation-to-WooCommerce status mappings, and updates those settings in response to REST order requests. It also augments diagnostics output and contains admin-notice/auth-display wiring used on the WooCommerce integration screen.

Authentication for the admin connection flow is handled by `Auth_Controller` plus `templates/auth-modal.php` and `assets/js/auth-display.js`. This controller does not simply store credentials in options: it generates WooCommerce REST API credentials by inserting into the `woocommerce_api_keys` table, stores only the generated key ID in an option, and exposes the consumer key/secret only at creation time through authenticated AJAX responses. It also renders the modal used to display the store URL, auth key, and newly generated REST credentials.

Checkout integration is broader than a single gift-message field. `Checkout` adds gift fields to both classic and block checkout, persists values through AJAX/session helpers, validates and stores the data, renders it in admin/customer/email contexts, and cleans up session/meta state after checkout. The export path then reads that order metadata so ShipStation exports can include `Gift`, `GiftMessage`, `CustomerNotes`, and `InternalNotes` where applicable.

`Order_Util` is the cross-cutting compatibility layer used throughout the plugin. It abstracts differences between legacy orders and HPOS, resolves order IDs from order numbers for common sequential-order plugins, counts shippable items, normalizes address and shipping-method export data, and retrieves order notes. Both the legacy XML handlers and the REST controllers depend on it to keep order access and export behavior consistent.

Privacy and logging are handled separately. `WC_ShipStation_Privacy` registers WooCommerce privacy exporters/erasers for tracking-related order meta, while `Logger` centralizes debug/error logging and respects the integration's logging setting. These are supporting subsystems rather than the core request flow, but they are part of the operational surface of the plugin.

---
_Generated by ask-self ingest at 2026-05-11T02:24:21+00:00 using ollama:qwen3:8b. Embed model: Qwen/Qwen3-Embedding-0.6B (dim=1024). Indexed chunks: 100._

_Chunks by source: php=35, rest-controller=28, api-request=16, style=4, script=4, plugin-readme=3, config=3, template=2, plugin-bootstrap=1, overview=1, doc=1, checkout=1, changelog=1._
