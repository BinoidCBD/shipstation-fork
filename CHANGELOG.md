# Changelog (BinoidCBD fork)

All notable fork-specific changes are documented here. Upstream WooCommerce
ShipStation changes are tracked in [readme.txt](readme.txt).

Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] — `dev/5.0.4-ask-self`

Coordinated response to [universal-child-theme-oct-2024#810](https://github.com/BinoidCBD/universal-child-theme-oct-2024/issues/810):
ShipStation REST-API sync was generating ~24,765 reqs/day on binoidcbd.com (15× the
legacy XML API baseline) and contributing to the April 26, 2026 server-degradation
incident. Upstream 5.0.4 cut per-request DB cost but did not reduce request volume.
The three fixes below stack to reduce volume directly.

### Added
- `woocommerce_shipstation_modified_after_floor` filter — cap the lookback window
  ShipStation's wide `modified_after` parameter is allowed to express. Seconds,
  default `48 * HOUR_IN_SECONDS`. Return `0` to disable. ([PR #2](https://github.com/BinoidCBD/shipstation-fork/pull/2))
- `woocommerce_shipstation_orders_response_cache_ttl` filter — TTL (seconds) of
  the new orders-endpoint response cache. Default `90`. Return `0` to disable.
  ([PR #3](https://github.com/BinoidCBD/shipstation-fork/pull/3))
- `woocommerce_shipstation_invalidate_orders_cache` filter — toggle automatic
  cache invalidation on order changes. Default `true`. ([PR #4](https://github.com/BinoidCBD/shipstation-fork/pull/4))
- `wcss_orders_cache_version` option — versioned cache namespace so order
  changes invalidate every cached response in O(1) without scanning the
  transient store. ([PR #4](https://github.com/BinoidCBD/shipstation-fork/pull/4))

### Changed
- `/wc-shipstation/v1/orders`: clamp `modified_after` to `NOW - 48h` (filterable)
  before building the `wc_get_orders` query. Logs a single line per clamp event
  so the behavior is visible in the ShipStation log. ShipStation's page-1
  incremental cursor is unaffected — only the wide 14-day fallback is clamped.
  ([PR #2](https://github.com/BinoidCBD/shipstation-fork/pull/2))
- `/wc-shipstation/v1/orders`: cache successful responses in short-TTL
  transients keyed on `(version, modified_after, page, per_page, status_mapping)`.
  Cache is checked after the existing `woocommerce_shipstation_get_orders_before_process_request`
  action so third-party hooks still fire. ([PR #3](https://github.com/BinoidCBD/shipstation-fork/pull/3))
- `woocommerce_new_order`, `woocommerce_order_status_changed`,
  `woocommerce_refund_created`, and `woocommerce_refund_deleted` now bump the
  orders-cache version, making these specific mutations immediately visible to
  the next ShipStation pull. Refund hooks are included because partial refunds
  do not fire `status_changed` but do change the `returns` payload — issue
  #810's primary traffic concern. Other order saves (notes, address tweaks,
  arbitrary meta) intentionally fall through the 90s TTL rather than
  invalidating on every save; invalidating on every save would collapse cache
  hit rate during ShipStation's ~38-minute sync bursts. ([PR #4](https://github.com/BinoidCBD/shipstation-fork/pull/4))

### Notes
- Expected combined effect on binoidcbd.com: per-cycle DB load drops sharply
  from the clamp; repeated paginated queries within a cycle drop to ~0 DB
  cost from the cache; new orders, status changes, and refund mutations
  invalidate the cache immediately, while other order saves are bounded by
  the 90s TTL.
- All three filters are independently disable-able; the fork is reversible
  by setting each filter to its no-op value.
- See the parent issue [#810](https://github.com/BinoidCBD/universal-child-theme-oct-2024/issues/810)
  for the access-log evidence and the upstream support ticket
  (ShipStation tickets #9616548, #9654766).
