# Plan: Transient Cache for `/wc-shipstation/v1/diagnostics/details`

> Status: **Proposal, rev 4 — branching off upstream baseline to minimize blast radius.**
> Author: Claude (Opus 4.7), 2026-05-13.
> Reviewers: Copilot (#1), Gemini (#2). Plan sound; all open questions resolved.
>
> **Changelog from rev 3:**
> - §5 #5: branch off `origin/original/5.0.4`, **not** `dev/5.0.4-ask-self`.
>   Reason: the dev branch carries 147 lines of inert orders-cache + clamp +
>   invalidation hooks that the May 13 analysis recommended removing. Building
>   the diagnostics fix on top of that code bundles two unrelated concerns and
>   inflates the diff. Issue #6 also confirms Binoid runs stock 5.0.4, so a
>   baseline-relative PR applies to Binoid without dragging the inert patches
>   into a production it doesn't currently use.
> - §3.1: removed the "mirror dev-branch conventions" framing — irrelevant now
>   that we're branching off upstream.
>
> **Changelog from rev 2:**
> - §5: all seven open questions resolved by reviewer consensus. Section now
>   reads as decisions, not questions.
> - §3.2: added a one-line pointer near the top of the code block so future
>   readers don't miss that the stampede mitigation is in the implementation.
>
> **Changelog from rev 1 (kept for history):**
> - §2 / §3.3: "request-independent" softened to "base payload is request-independent; final response is filterable".
> - §3.1: dropped the false claim that the orders-cache pattern lives in this branch — it only exists on `dev/5.0.4-ask-self`. Removed the redundant "filter to disable caching entirely" bullet (TTL=0 already disables).
> - §3.2: replaced naive `get_transient` / `set_transient` with **stale-while-revalidate + `wp_cache_add` single-flight** to prevent the cold-cache stampede the reviewer flagged.
> - §4: reworked impact numbers to show worst-case under both stampede and single-flight scenarios.
> - §7: `CHANGELOG.md` → `changelog.txt`.

---

## 1. Background

### 1.1 The original fork rationale (now mostly inert)

The fork `BinoidCBD/shipstation-fork` was created to mitigate ShipStation's
poll-driven load on `bloomzhemp.com` (and presumably `binoidcbd.com`). It ships
three patches on top of WooCommerce ShipStation 5.0.4:

1. **48h clamp on `modified_after`** ([includes/api/rest/class-orders-controller.php:423](includes/api/rest/class-orders-controller.php#L423))
2. **90-second transient cache of the orders REST response** (same file, lines 340–522)
3. **Cache-invalidation hooks** on order/refund events ([includes/class-main.php](includes/class-main.php))

A May 13, 2026 log analysis (see issue
[#6](https://github.com/BinoidCBD/shipstation-fork/issues/6)) showed that
ShipStation changed their server-side polling **before any of the fork patches
landed**:

| | Pre-change (documented in #802/#810) | Post-change (May 11–13 logs) |
|--|--|--|
| Lookback | 14 days | **16 hours** |
| Poll interval | ~100 min | **~61 min** |
| Pages per cycle | ~243 | 1–4 |

Consequences for the fork:

- **48h clamp** — never fires (16h < 48h).
- **Orders response cache** — never hits, because the cache key includes
  `page` and ShipStation never repeats the same `(page, per_page,
  modified_after)` tuple inside the 90s TTL window.
- **Invalidation hooks** — bump a `wp_options` version for a cache that has
  zero reads. Net effect: extra writes per order event for no benefit.

### 1.2 The real cost center on Binoid

The May 13 `pt-query-digest` over ~12 hours of WP Engine slow-query logs
attributes **90.6 % of accumulated slow-query time** to a single endpoint:

```
ShipStation → WC Diagnostics (/wc-shipstation/v1/diagnostics/details)
    20,817s (5.8 hours) across 1,870 calls — avg 11s per call
```

The endpoint dispatches an internal call to `/wc/v3/system_status`, which runs
the WooCommerce system-status report. Two of its component queries are the
bottleneck on a site with a 3.68M-row `wp_posts` table:

- `GROUP BY post_type` over all of `wp_posts`
- A WooCommerce Subscriptions payment-method-count JOIN

During a **40-minute catch-up burst on May 13, 05:29–06:08 PDT**, ShipStation
called the endpoint at ~22 req/min, peaking at 8.5× DB concurrency.

The P2 "catch-up burst" item in issue #6 is almost certainly downstream of
this same query cost: each diagnostics call takes ~11s of DB time, so a burst
of them is expensive *because individual calls are expensive*. Fix the per-call
cost and the burst should stop being a problem on its own.

---

## 2. The Handler

File: [includes/api/rest/class-diagnostics-controller.php](includes/api/rest/class-diagnostics-controller.php)
(identical on `main` and `origin/dev/5.0.4-ask-self`; unmodified by the fork.)

Route registration ([class-diagnostics-controller.php:41-64](includes/api/rest/class-diagnostics-controller.php#L41-L64)):

```php
register_rest_route(
    $this->namespace,                              // 'wc-shipstation/v1'
    '/' . $this->rest_base . '/details',           // 'diagnostics/details'
    array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => array( $this, 'get_details' ),
        'permission_callback' => array( $this, 'check_get_permission' ),
    )
);
```

The handler ([class-diagnostics-controller.php:105-152](includes/api/rest/class-diagnostics-controller.php#L105-L152)):

```php
public function get_details( WP_REST_Request $request ): WP_REST_Response {
    $report      = wc_get_container()->get( RestApiUtil::class )
                       ->get_endpoint_data( '/wc/v3/system_status' );        // ← the expensive call
    $environment = isset( $report['environment'] ) && is_array( $report['environment'] )
                       ? $report['environment'] : array();

    // ... transforms $report['active_plugins'] into a flat list ...

    $site_info = array(
        'source_details' => array(
            'plugin_version'      => WC_SHIPSTATION_VERSION,
            'woocommerce_version' => ...,
            'php_version'         => ...,
            'wordpress_version'   => ...,
            'memory_limit'        => ...,
            'active_plugins'      => implode( ', ', $active_plugins ),
        ),
    );

    return new WP_REST_Response(
        apply_filters(
            'woocommerce_shipstation_diagnostics_controller_get_details',
            $site_info,
            $request
        ),
        200
    );
}
```

Key observations:

- The **base payload** (`$site_info` before the filter) is request-independent.
  None of its fields depend on `$request` query/body params, headers, or
  current user. The cache key can therefore be a single constant per site.
- The **final response** is *not* guaranteed to be request-independent —
  it passes through `apply_filters( 'woocommerce_shipstation_diagnostics_controller_get_details', $site_info, $request )`,
  and this plugin itself registers a callback for that filter at
  [class-wc-shipstation-integration.php:203](includes/class-wc-shipstation-integration.php#L203)
  (implementation at [class-wc-shipstation-integration.php:439](includes/class-wc-shipstation-integration.php#L439))
  which injects `status_mapping` and `status_mode` from the integration
  settings. Today that callback ignores `$request`, but a future third-party
  filter might not. **Design implication:** cache the pre-filter `$site_info`,
  always re-run the filter on output.
- The whole expensive blob from `/wc/v3/system_status` is fetched and then
  **only six scalar fields are used**. Caching the small post-transform
  `$site_info` is enough — we do not need to cache the raw system_status
  payload.
- The output is essentially static between WP/WC core upgrades, integration
  settings changes, and plugin activations. A 5-minute (or longer) TTL has
  no realistic accuracy cost for what ShipStation does with this data.
  Integration-settings changes (status mapping/mode) are applied via the
  filter on every read, so they take effect immediately regardless of cache
  state.

---

## 3. Proposed Patch (sketch)

### 3.1 Style match

Branching off `origin/original/5.0.4` means this PR introduces the first
cache helpers in the branch tree. Conventions used:

- Transient key prefix `wcss_`.
- Filterable TTL via a `woocommerce_shipstation_*` filter; **TTL = 0 is the
  documented disable mechanism** (no separate on/off filter needed).
- Small private helpers in the controller class; no new files.

(The dev branch's orders-cache code uses similar conventions, but is
unrelated to this PR.)

### 3.2 Patch

**Why this is not a simple `get_transient` / `set_transient` pair**
(reviewer #1's "Major" finding): the burst the doc describes can hit the
endpoint at ~22 req/min. At each TTL boundary (every 5 min, plus the
initial cold start), a naive cache would let every concurrent request
re-run the 11-second `system_status` query before the first one finishes
and writes the transient. Across the 40-minute burst that is up to
~5 concurrent slow queries × 9 TTL boundaries ≈ **45 slow calls instead
of 1 per boundary**.

To deliver the promised single-call-per-boundary behavior, the patch uses a
**stale-while-revalidate** pattern with a `wp_cache_add()`-based
single-flight lock:

- **Fresh** transient (`wcss_diagnostics_details_fresh_v1`), TTL = 5 min.
  Hit ⇒ return immediately.
- **Stale** transient (`wcss_diagnostics_details_stale_v1`), TTL = 1 hour.
  Hit only when fresh has expired; returned to **late readers** while
  exactly one process refreshes.
- **Lock** via `wp_cache_add()` (`wcss_diagnostics_lock_v1`, 30 s TTL). The
  first request through a TTL boundary wins the lock and refreshes both
  transients; all others see the lock and return the stale copy. On hosts
  with persistent object cache (WP Engine has Memcached), `wp_cache_add` is
  atomic across processes. On hosts without persistent object cache, the
  lock degrades to per-request and the pattern falls back to "one stampede
  per TTL boundary," which is still vastly better than the current state.

The very first request ever (no fresh, no stale, no lock holder) is the
only case that ever runs the slow path with no fallback — acceptable.

```php
public function get_details( WP_REST_Request $request ): WP_REST_Response {
    // Stampede mitigation lives inside get_cached_site_info():
    // wp_cache_add() single-flight + a 1-hour stale fallback transient.
    $cache_ttl = $this->get_diagnostics_cache_ttl();

    if ( $cache_ttl <= 0 ) {
        // Caching disabled — always rebuild.
        $site_info = $this->build_site_info();
    } else {
        $site_info = $this->get_cached_site_info( $cache_ttl );
    }

    return new WP_REST_Response(
        apply_filters(
            'woocommerce_shipstation_diagnostics_controller_get_details',
            $site_info,
            $request
        ),
        200
    );
}

/**
 * Stale-while-revalidate read with single-flight refresh.
 */
private function get_cached_site_info( int $fresh_ttl ): array {
    $fresh_key = 'wcss_diagnostics_details_fresh_v1';
    $stale_key = 'wcss_diagnostics_details_stale_v1';
    $lock_key  = 'wcss_diagnostics_lock_v1';
    $stale_ttl = (int) apply_filters(
        'woocommerce_shipstation_diagnostics_stale_ttl',
        HOUR_IN_SECONDS
    );

    $fresh = get_transient( $fresh_key );
    if ( is_array( $fresh ) ) {
        return $fresh;
    }

    $stale = get_transient( $stale_key );

    // wp_cache_add returns true only for the process that wrote the key.
    // On hosts with a persistent object cache this is atomic across PHP
    // workers; without one, it falls back to per-request scope and we lose
    // single-flight (but still hit the cache for everything within the TTL).
    $won_lock = wp_cache_add( $lock_key, 1, '', 30 );

    if ( ! $won_lock && is_array( $stale ) ) {
        return $stale; // Another worker is refreshing — serve stale.
    }

    // Either we won the lock, or there is no stale copy to fall back to.
    $site_info = $this->build_site_info();

    set_transient( $fresh_key, $site_info, $fresh_ttl );
    set_transient( $stale_key, $site_info, $stale_ttl );
    wp_cache_delete( $lock_key );

    return $site_info;
}

/**
 * Build the base $site_info payload by calling /wc/v3/system_status.
 * Extracted so the cache path and the disabled path share one implementation.
 */
private function build_site_info(): array {
    $report      = wc_get_container()->get( RestApiUtil::class )
                       ->get_endpoint_data( '/wc/v3/system_status' );
    $environment = isset( $report['environment'] ) && is_array( $report['environment'] )
                       ? $report['environment'] : array();

    if ( ! empty( $report['active_plugins'] ) && is_array( $report['active_plugins'] ) ) {
        $active_plugins = array_map(
            function ( $plugin_info ) {
                $info = array();
                if ( ! empty( $plugin_info['name'] ) ) {
                    $info[] = $plugin_info['name'];
                }
                if ( ! empty( $plugin_info['version'] ) ) {
                    $info[] = $plugin_info['version'];
                }
                return implode( ' ', $info );
            },
            $report['active_plugins']
        );
    } else {
        $active_plugins = array();
    }

    return array(
        'source_details' => array(
            'plugin_version'      => WC_SHIPSTATION_VERSION,
            'woocommerce_version' => isset( $environment['version'] ) ? esc_html( $environment['version'] ) : '',
            'php_version'         => isset( $environment['php_version'] ) ? esc_html( $environment['php_version'] ) : '',
            'wordpress_version'   => isset( $environment['wp_version'] ) ? esc_html( $environment['wp_version'] ) : '',
            'memory_limit'        => isset( $environment['wp_memory_limit'] ) ? esc_html( size_format( $environment['wp_memory_limit'] ) ) : '',
            'active_plugins'      => implode( ', ', $active_plugins ),
        ),
    );
}

/**
 * TTL (seconds) for the fresh diagnostics/details response.
 *
 * Filter `woocommerce_shipstation_diagnostics_cache_ttl`. Return 0 to disable.
 * Default 5 * MINUTE_IN_SECONDS.
 */
private function get_diagnostics_cache_ttl(): int {
    return (int) apply_filters(
        'woocommerce_shipstation_diagnostics_cache_ttl',
        5 * MINUTE_IN_SECONDS
    );
}
```

### 3.3 Why these choices

- **Cache `$site_info`, not the raw `/wc/v3/system_status` payload.** The raw
  payload is large and 99 % unused; caching the transformed array keeps the
  transient row small and avoids serializing data we never read.
- **Cache the pre-filter payload; always re-run the filter.** The base
  payload is request-independent, but the `woocommerce_shipstation_diagnostics_controller_get_details`
  filter is not — running it on every response preserves both this plugin's
  own status-mapping injection (see §2) and any third-party customization
  that may depend on `$request`.
- **Stable, versioned cache keys** (`..._fresh_v1`, `..._stale_v1`,
  `..._lock_v1`). Trailing `_v1` lets us bump the keyspace if the response
  shape ever changes — same trick as `get_orders_cache_version()` on
  `dev/5.0.4-ask-self`.
- **5-minute default TTL.** The data only changes on plugin/WP/WC version
  changes and the active-plugins list. With ShipStation polling every ~61
  minutes, even a 1-hour TTL would be safe — but 5 minutes keeps the response
  fresh enough for ad-hoc admin use of the endpoint without anyone noticing.
- **Stale-while-revalidate with single-flight.** Bounds worst-case slow
  calls to **one per TTL boundary** (assuming a persistent object cache).
  Stale TTL of 1 hour means even if the slow query itself takes 11 s,
  late-arriving requests during that 11 s window see a stale copy rather
  than queuing on the DB.
- **No explicit invalidation hooks.** Unlike the orders cache (which had a
  legitimate invalidation requirement and now compounds the bug by writing
  for a cache that doesn't hit), the diagnostics response is informational.
  Up to 5 minutes of staleness across a plugin (de)activation is fine, and
  avoiding invalidation hooks means zero `wp_options` write overhead per
  order event.

---

## 4. Expected Impact

If the May 13 slow-query profile holds, three scenarios bracket the
expected outcome:

| Scenario | Slow calls during 40-min burst | Slow-query time | % of original load |
|--|--|--|--|
| **Current (no cache)** | ~880 (22 req/min × 40 min) | ~9,680 s | 100 % (baseline) |
| Naive `get_transient` / `set_transient` (rev 1's sketch) | ~40 (≈5 stampede × 8 TTL boundaries) | ~440 s | ~4.5 % |
| **Stale-while-revalidate + single-flight (rev 2, proposed)** | ~8–9 (one per TTL boundary) | ~88–99 s | ~1 % |

Under steady-state polling (one request per ~61 min), the difference between
the two cache designs disappears — both behave identically. The
single-flight design earns its complexity specifically for the catch-up
burst scenario, which is the P2 line item from issue #6.

Caveats:

- On a host without a persistent object cache, `wp_cache_add` is per-request
  and the single-flight degrades to the naive case. Either way the system
  still drops below **5 % of original load**.
- The first request after a cold start (no fresh, no stale) still pays the
  full 11 s cost. In practice this is a one-time event after deployment.

The **P2 catch-up trigger** itself is worth still investigating, but is
likely no longer user-visible once every burst request is either a fresh
cache hit, a stale read, or one of ~8 single-flight refreshes.

---

## 5. Resolved Decisions (post-review)

All seven open questions from rev 2 have unanimous answers across reviewers
#1 (Copilot) and #2 (Gemini). Recording them as decisions so the
implementation PR is unblocked:

1. **TTL value: 5 minutes.** Short enough that support debugging
   plugin-activation issues sees fresh data within one polling cycle; long
   enough to absorb 99 % of polling load.
2. **No invalidation hooks.** Skip `bump_diagnostics_cache_version()` and
   the `upgrader_process_complete` / `activated_plugin` /
   `deactivated_plugin` hooks. Over-engineered for a 5-minute TTL on an
   informational endpoint.
3. **Object cache assumption documented, not required.** WP Engine has
   Memcached so `wp_cache_add` is atomic on the targeted hosts. On hosts
   without persistent object cache, `set_transient` falls back to
   `wp_options` (microscopic overhead vs. the 11 s aggregation) and the
   single-flight lock degrades to per-request — still strictly better
   than the no-cache baseline.
4. **Cache pre-filter, run filter on every request.** Confirmed correct
   choice — caching post-filter would break any third-party callback that
   depends on `$request`.
5. **Branch:** `fix/diagnostics-response-cache` off
   `origin/original/5.0.4` → PR into `original/5.0.4` (or the agreed
   release line — confirm before pushing). Deliberately *not* on top of
   `dev/5.0.4-ask-self` to keep the diagnostics fix isolated from the inert
   orders-cache patches the May 13 analysis flagged for removal.
6. **Standalone PR.** Do not bundle removal of the inert orders-cache code.
   Keep the win isolated; clean up dead code in a separate PR after this
   one merges.
7. **Subscriptions JOIN: defer.** Once the cache is in place the JOIN runs
   ≤ once per 5 min, and it will likely fall off the slow-query radar
   completely. Re-measure before deciding whether to filter it out of the
   REST response.

---

## 6. Out of Scope (for this PR)

- The P2 "10,000+ comments IN() clause" item from issue #6 (WC order notes).
  Different code path, separate investigation.
- The decision about whether to keep or remove the fork's existing orders
  cache + 48h clamp + invalidation hooks. Tracked separately.
- Changes to upstream WooCommerce `/wc/v3/system_status`. Out of fork scope.

---

## 7. Files Touched (anticipated)

- `includes/api/rest/class-diagnostics-controller.php` — refactor
  `get_details()` to delegate to a new `build_site_info()` helper; add the
  `get_cached_site_info()` stale-while-revalidate logic and a
  `get_diagnostics_cache_ttl()` filter helper.
- **`changelog.txt`** — *not modified* in this PR. Branching off
  `origin/original/5.0.4` means the changelog is in upstream's voice;
  writing fork-only entries there mixes provenance. The fork context goes
  in the PR description and commit message instead.

No new files. No DB schema. New hooks introduced:

- `woocommerce_shipstation_diagnostics_cache_ttl` (int seconds, default
  `5 * MINUTE_IN_SECONDS`; return `0` to disable caching).
- `woocommerce_shipstation_diagnostics_stale_ttl` (int seconds, default
  `HOUR_IN_SECONDS`; controls the stale-fallback window).
