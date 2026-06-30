# GH-9 Remediation Plan — Batch ShipStation Incoming Shipment Webhooks

**Issue:** [BinoidCBD/shipstation-fork#9](https://github.com/BinoidCBD/shipstation-fork/issues/9) — _"Shipstation - Add Incoming Order Status updates into managed batches"_
**Plugin:** ShipStation for WooCommerce (Forked & Customized), v5.2.0
**Author of this plan:** Claude Code (Opus) — analysis driven by the `graphify-out/` knowledge graph + source read of the request-handling path.
**Status:** Proposed — not yet implemented.

---

## 1. TL;DR

ShipStation dumps **up to ~1,076 shipment-update POSTs per minute** at 5–6 AM Pacific. Each POST is processed **synchronously** end-to-end (order load → meta writes → order-note insert → status transition → full `woocommerce_order_status_changed` cascade: emails, Klaviyo, other integrations). Hundreds of these in one minute serialize write locks across `wp_posts`/HPOS order tables, `wp_postmeta`, `wp_comments`, and `wp_woocommerce_order_items`, spiking MySQL `queue_depth` to ~1,460 for ~11 minutes.

**Fix:** Intercept the shipment endpoint, **persist each notification to a lightweight queue table, acknowledge `200` immediately**, and drain the queue with an **Action Scheduler** worker in small, rate-controlled batches. This converts a 1-minute lock storm into a steady ~15–30 minute trickle.

The whole mechanism sits behind a **feature-flag kill-switch** so it can be disabled instantly in production, falling back to today's synchronous behavior.

---

## 2. Correction to the issue's root-cause reference (important)

The issue states each POST triggers `WC_Shipstation_API_Shipnotify::request()` and names [includes/api/requests/class-wc-shipstation-api-shipnotify.php](includes/api/requests/class-wc-shipstation-api-shipnotify.php) as "the handler that needs to be intercepted."

That is the **legacy XML handler**, reached only in **XML API mode** via `/?wc-api=wc_shipstation&action=shipnotify` (dispatched by [WC_Shipstation_API::request()](includes/class-wc-shipstation-api.php#L71) on an `auth_key` + `action` query string).

The traffic in the report hits `/?rest_route=/wc-shipstation/v1/orders/shipments`, which is the **new REST path**. That route is registered by [Orders_Controller::register_routes()](includes/api/rest/class-orders-controller.php#L239) and handled by:

- [Orders_Controller::update_orders_shipments()](includes/api/rest/class-orders-controller.php#L1745) — parses the JSON `notifications[]` batch, then per notification calls…
- [Orders_Controller::process_items()](includes/api/rest/class-orders-controller.php#L1946) — **this is the real hot path**: `save_meta_data()`, `add_order_note()` (a `wp_comments` insert), and `update_status()` (the cascade).

Both handlers do equivalent work; the DB-lock symptoms are the same. **This plan targets the REST handler** (the one actually receiving the burst) and treats the XML handler as an optional secondary (see §9). Confirm production `api_mode` is `REST` (it is, given the observed URL) before scoping out XML.

> Note: the endpoint already accepts a **batch** (`{ "notifications": [ ... ] }`) and loops it. The problem is not batch-vs-single payloads — it's that ShipStation fires **many POSTs concurrently** and each one processes **synchronously inside the request**. Queuing decouples "accept" from "process."

---

## 3. Why this is the right shape (reuse what already exists)

This codebase **already contains every primitive** this fix needs. The remediation should look native, not bolted-on. Mapping:

| Need | Existing pattern to model on | Location |
|---|---|---|
| Custom DB table, version-gated install | `Connection_Log` (`TABLE`, `DB_VERSION`, `DB_VERSION_OPTION`, `table_name()`, `maybe_install()`, `install()` with `dbDelta` + post-install column verify) | [includes/class-connection-log.php](includes/class-connection-log.php#L116) |
| Recurring background worker | `Auth_Controller` orphan-key prune via **Action Scheduler** (`PRUNE_HOOK`, `PRUNE_GROUP`, `register_orphan_prune()`, `schedule_orphan_prune()` with `as_has_scheduled_action`/`as_schedule_recurring_action`, `unschedule_orphan_prune()`) | [includes/class-auth-controller.php](includes/class-auth-controller.php#L996) |
| Feature flag / kill-switch | `Features::is_*_enabled()` = `apply_filters('wc_shipstation_*', false)` + constant override | [includes/class-features.php](includes/class-features.php#L24) |
| Single-flight / claim-without-double-processing | Diagnostics stale-while-revalidate **lock-ownership token** (commits `72c2844`, `bb39c38`) | [includes/api/rest/class-diagnostics-controller.php](includes/api/rest/class-diagnostics-controller.php) |
| Activation/deactivation wiring | `woocommerce_shipstation_activate()` / `_deactivate()` | [woocommerce-shipstation.php](woocommerce-shipstation.php#L69-L97) |
| Bootstrap sequence | `Main::init()` on `plugins_loaded` → `load_files()`, `Connection_Log::maybe_install()`, `Auth_Controller::register_orphan_prune()` | [includes/class-main.php](includes/class-main.php#L84) |
| Structured logging | `Logger::debug/info/warning/error` | [includes/class-logger.php](includes/class-logger.php) |

**Action Scheduler ships with WooCommerce** and is already used in-tree (orphan-key prune), so no new dependency.

---

## 4. Target architecture

```
ShipStation burst (≈538 POSTs/min)
        │
        ▼
POST /wc-shipstation/v1/orders/shipments
  permission_callback  ──► check_update_permission()      (UNCHANGED — auth still gates)
        │
        ▼
update_orders_shipments()
  if Features::is_shipment_queue_enabled():
        ├─ validate minimally (notification_id, order_id present)
        ├─ Shipment_Queue::enqueue( raw notification JSON )   ── dedupe on notification_id
        ├─ kick async drain (as_enqueue_async_action) if none pending
        └─ return 200 { notification_results: [{id, status:'success'}] }   ◄── immediate ACK
  else:
        └─ (today's synchronous path — unchanged fallback)

   ── meanwhile, decoupled ───────────────────────────────────────────────

Action Scheduler
  recurring heartbeat (≈60s)  +  self-chained async follow-ups while backlog remains
        │
        ▼
Shipment_Queue::run_batch()
  ├─ claim N pending rows  (single UPDATE … SET status='processing', locked_by=<token>)
  ├─ for each: Orders_Controller::process_single_notification( decode(raw) )   ◄── SAME logic
  ├─ mark done / on error: attempts++, backoff, requeue or mark failed
  └─ reclaim stale 'processing' rows; prune old 'done' rows
        │
        ▼
  Order meta + order note + status transition + downstream cascade
  — now spread over ~15–30 min instead of ~1 min
```

Key property: **the order-side effects are byte-for-byte the same** whether synchronous or queued, because both call the *same* `process_single_notification()`. Queuing only changes *when* and *how fast*, never *what*.

---

## 5. Component design

### 5.1 Feature flag (kill-switch) — `Features`
Add to [includes/class-features.php](includes/class-features.php):

```php
public static function is_shipment_queue_enabled(): bool {
    if ( defined( 'WC_SHIPSTATION_SHIPMENT_QUEUE' ) ) {
        return (bool) WC_SHIPSTATION_SHIPMENT_QUEUE;   // hard override (wp-config)
    }
    /** @since 5.x */
    return (bool) apply_filters( 'wc_shipstation_shipment_queue_enabled', false );
}
```
Default **off**. Ship dark, enable via filter/constant after the table + worker are proven. Flipping it off at any time reverts to synchronous processing with zero data migration.

### 5.2 Queue table — `Shipment_Queue` (new: `includes/class-shipment-queue.php`)
Model `maybe_install()` / `install()` directly on `Connection_Log`. Schema:

```sql
CREATE TABLE {$prefix}wc_shipstation_shipment_queue (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    notification_id varchar(191) NOT NULL DEFAULT '',   -- ShipStation idempotency key
    order_ref       varchar(64)  NOT NULL DEFAULT '',    -- order_id/number for logs & ordering
    payload         longtext     NOT NULL,               -- RAW notification JSON (lightweight, unparsed)
    status          varchar(16)  NOT NULL DEFAULT 'pending', -- pending|processing|done|failed
    attempts        smallint(5) unsigned NOT NULL DEFAULT 0,
    locked_by       varchar(40)  NOT NULL DEFAULT '',     -- single-flight claim token
    locked_at       datetime     NULL DEFAULT NULL,
    available_at    datetime     NOT NULL DEFAULT '1970-01-01 00:00:00', -- retry backoff gate
    created_at      datetime     NOT NULL DEFAULT '1970-01-01 00:00:00',
    updated_at      datetime     NOT NULL DEFAULT '1970-01-01 00:00:00',
    last_error      text         NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY notification_id (notification_id),         -- dedupe (see note)
    KEY status_available (status, available_at),          -- claim query
    KEY locked (status, locked_at)                        -- stale-claim reclaim
) {$charset_collate};
```

Public API (all `static`, mirroring `Connection_Log` ergonomics):
- `table_name()`, `maybe_install()`, `install()` — version-gated via a `DB_VERSION` / `…_db_version` option.
- `enqueue(string $notification_id, string $order_ref, string $payload_json): bool` — `INSERT … ON DUPLICATE KEY UPDATE` semantics so a ShipStation retry of an **already-queued** id is a no-op (idempotent), but an id that's already `done` is **not** re-run.
- `claim_batch(int $limit, string $token): array` — single-flight claim:
  ```sql
  UPDATE … SET status='processing', locked_by=%s, locked_at=NOW(), updated_at=NOW()
  WHERE status='pending' AND available_at <= NOW()
  ORDER BY id ASC LIMIT %d;
  -- then SELECT … WHERE locked_by=%s AND status='processing'
  ```
  The conditional `UPDATE` is the atomic claim — two overlapping AS runs can't grab the same rows (the diagnostics lock-token idea, applied per-row).
- `mark_done(int $id)`, `mark_failed_or_retry(int $id, string $error, int $max_attempts)` — increments `attempts`; on `< max` sets `status='pending'` with exponential `available_at` backoff; on `>= max` sets `status='failed'` and `Logger::error()`s.
- `reclaim_stale(int $older_than_seconds)` — `processing` rows whose `locked_at` is too old (crashed runner) → back to `pending`.
- `prune_done(int $older_than_days)` — housekeeping.
- `depth(): int` — count of `pending` (for observability).

> **Dedupe note:** the `UNIQUE(notification_id)` index assumes ShipStation always sends a `notification_id` (the REST handler already `continue`s when it's empty — see [line 1778](includes/api/rest/class-orders-controller.php#L1778)). If empty ids ever occur, synthesize one from `sha1(order_id + tracking_number + ship_date)` at enqueue time so dedupe still holds.

### 5.3 Refactor for reuse — extract `process_single_notification()`
Today the per-notification body lives inside the `foreach` in [update_orders_shipments()](includes/api/rest/class-orders-controller.php#L1759-L1927) (lines ~1759–1927). Extract it verbatim into:

```php
public function process_single_notification( array $notification ): array {
    // (everything currently inside the foreach: validate → wc_get_order →
    //  normalize items/addresses/notes → $this->process_items(...) )
    // returns the per-item result row: ['notification_id','status', ...]
}
```
Then both paths converge:
- `update_orders_shipments()` (synchronous fallback, flag off) loops and calls it — **no behavior change**.
- `Shipment_Queue` worker decodes each stored raw payload and calls the *same* method.

This guarantees parity and avoids duplicating the normalization/`process_items()` logic. `process_items()` is already `public`, so no further visibility changes are needed.

### 5.4 Interception in `update_orders_shipments()`
At the top of the existing method, after `fire_legacy_api_action()` and JSON parse:

```php
if ( Features::is_shipment_queue_enabled() ) {
    $results = array();
    foreach ( $notifications as $n ) {
        // minimal cheap validation only (id + order ref present)
        Shipment_Queue::enqueue( $n['notification_id'], (string) ($n['order_id'] ?? $n['order_number'] ?? ''), wp_json_encode( $n ) );
        $results[] = array( 'notification_id' => $n['notification_id'], 'status' => 'success' );
    }
    Shipment_Queue::kick(); // schedule an async drain if none pending
    return new WP_REST_Response( array( 'notification_results' => $results ), 200 );
}
// …existing synchronous loop unchanged below…
```

The `permission_callback` ([check_update_permission()](includes/api/rest/class-orders-controller.php#L266)) is untouched, so **only authenticated ShipStation requests can enqueue**. Auth stays at the REST layer.

### 5.5 Worker scheduling — model on `Auth_Controller`
New `Shipment_Queue_Worker` (or static methods on `Shipment_Queue`), wired from [Main::init()](includes/class-main.php#L84) next to `Auth_Controller::register_orphan_prune()`:

```php
const WORKER_HOOK  = 'woocommerce_shipstation_drain_shipment_queue';
const WORKER_GROUP = 'woocommerce-shipstation';

public static function register_worker(): void {
    add_action( self::WORKER_HOOK, array( __CLASS__, 'run_batch' ) );
    add_action( 'init', array( __CLASS__, 'schedule_worker' ) ); // heartbeat
}
public static function schedule_worker(): void {
    if ( ! function_exists( 'as_schedule_recurring_action' ) ) return;
    if ( as_has_scheduled_action( self::WORKER_HOOK, array(), self::WORKER_GROUP ) ) return;
    as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, self::WORKER_HOOK, array(), self::WORKER_GROUP );
}
```
- `kick()` (called from the enqueue path) uses `as_enqueue_async_action()` to start draining **immediately** rather than waiting for the next heartbeat — important because the 5–6 AM window is **low-traffic**, so traffic-driven WP-Cron may otherwise lag (see §7.2).
- `run_batch()` chains the next batch via another async action while `depth() > 0`, so a burst drains promptly then goes idle.
- `unschedule` on deactivation in [woocommerce_shipstation_deactivate()](woocommerce-shipstation.php#L69) (`as_unschedule_all_actions`).
- `install()` on activation in [woocommerce_shipstation_activate()](woocommerce-shipstation.php#L87), plus `Shipment_Queue::maybe_install()` in `Main::init()` for existing installs (mirrors `Connection_Log`).

### 5.6 Rate control & load adaptivity (the `query_guard` hook)
`query_guard` is **not in this repo** — it's external production infra the issue references. Keep the plugin decoupled but **integration-ready** via filters:

```php
$batch_size = (int) apply_filters( 'wc_shipstation_shipment_queue_batch_size', 25 );
$interval   = (int) apply_filters( 'wc_shipstation_shipment_queue_interval', MINUTE_IN_SECONDS );
$max_attempts = (int) apply_filters( 'wc_shipstation_shipment_queue_max_attempts', 5 );
```
The production `query_guard` mu-plugin can hook `…_batch_size` and return a smaller number when its load level is high, throttling the drain automatically — exactly the behavior the issue describes ("during high DB load, reduce batch size automatically"), without this plugin depending on it.

### 5.7 Observability
- `Logger` lines on enqueue counts, batch drains, retries, and permanent failures.
- Surface **queue depth** through the integration's existing diagnostics hook so ops/ShipStation can see backlog with no new endpoint: extend [add_more_diagnostics_details()](includes/class-wc-shipstation-integration.php#L773) (already wired to `woocommerce_shipstation_diagnostics_controller_get_details`) with `shipment_queue_depth` + `oldest_pending_age`.
- Optional WP admin notice when `failed` rows accumulate beyond a threshold.

---

## 6. The one consequential trade-off — response contract

ShipStation's webhook expects `200` + `{ notification_results: [ { notification_id, status, order_id } ] }` and treats that as "done." When we queue, we return `status: 'success'` **before** processing.

**Consequence:** we forfeit ShipStation-driven retries (it already got its `200`). Reliability becomes *our* responsibility:
- Durable storage (the queue table survives PHP/worker death).
- In-queue retry with backoff + attempt cap (`mark_failed_or_retry`).
- Loud failure surfacing (`Logger::error`, diagnostics depth, optional admin notice) so a stuck item is visible.

This is **explicitly accepted by the issue** ("if processing fails, keep the item in the queue for retry") and is the entire point — we do *not* want ShipStation re-hammering the box. Documented here so the reviewer signs off on it consciously. (If desired, a stricter variant returns a `202`-style body, but ShipStation's contract is fixed, so we keep the expected shape.)

Ordering: process FIFO by `id`. Multiple notifications for the **same order** therefore still apply in arrival order. (A future refinement could serialize per-order claims; FIFO is sufficient for v1 since `process_items()` is additive on `_shipstation_shipped_item_count`.)

---

## 7. Edge cases & correctness

### 7.1 Reads pass through untouched
The diagnostics GET (`/wc-shipstation/v1/diagnostics/details`) and refund-polling GETs are `WP_REST_Server::READABLE`; we only intercept the `CREATABLE` `/orders/shipments`. **Reads are never queued** — satisfying the issue's requirement directly.

> ⚠️ The diagnostics GET is only **partially** de-amplified today. PR #7's stale-while-revalidate + single-flight lock (commits `bb39c38`/`72c2844`) was **reverted by the `5df0f0e` factory 5.2.0 drop** — the current [get_details()](includes/api/rest/class-diagnostics-controller.php#L109) is a plain 5-min transient cache with **no dogpile protection**. Under this burst, a diagnostics GET is paired with every shipment POST, so a cold/expired cache can be rebuilt by hundreds of concurrent reads at once. **Re-applying the single-flight lock (tracked in the [fork regression audit](FORK-REGRESSION-AUDIT-5.2.0.md) / [#10](https://github.com/BinoidCBD/shipstation-fork/issues/10)) complements this queue work** and should land alongside it.

### 7.2 Low-traffic drain window
5–6 AM Pacific is low-traffic, so default WP-Cron (page-load-driven) may not fire AS promptly. Mitigations, in order of reliability: (a) `as_enqueue_async_action()` kick on enqueue — each incoming webhook is itself a request that can spin up the loopback runner; (b) recurring 1-min heartbeat as a safety net; (c) **recommend a real system cron** hitting the AS runner (`wp action-scheduler run`) for best results — document in the rollout notes.

### 7.3 Idempotency / duplicates
`UNIQUE(notification_id)` + insert-or-ignore. A `done` id is never re-run; a still-`pending` id is a no-op on retry.

### 7.4 Crash recovery
`reclaim_stale()` returns orphaned `processing` rows to `pending` after a timeout, so a fatal mid-batch never strands work.

### 7.5 HPOS
Order writes already go through WC's HPOS-compatible APIs (plugin declares `custom_order_tables` compat in [Main::declare_hpos_compatibility()](includes/class-main.php#L322)). The queue table is independent of order storage. No HPOS concerns.

### 7.6 Concurrency cap
Action Scheduler runs a bounded number of concurrent actions; the single-flight claim guarantees no row is processed twice even if two runners overlap.

---

## 8. Implementation phases (PR-sized, each independently shippable)

| Phase | Deliverable | Files | Risk |
|---|---|---|---|
| **0** | Feature flag (default off) | [includes/class-features.php](includes/class-features.php) | none |
| **1** | Extract `process_single_notification()`; sync path calls it (no behavior change) | [includes/api/rest/class-orders-controller.php](includes/api/rest/class-orders-controller.php) | low (pure refactor; parity test) |
| **2** | `Shipment_Queue` model: install/enqueue/claim/complete/retry/reclaim/prune | new `includes/class-shipment-queue.php`; load in [Main::load_files()](includes/class-main.php#L175); `maybe_install()` in [Main::init()](includes/class-main.php#L84); `install()` in [activation hook](woocommerce-shipstation.php#L87) | low (additive) |
| **3** | Worker: register/schedule/run_batch/kick + chaining; deactivation unschedule | new `includes/class-shipment-queue-worker.php` (or statics on the queue); wire in [Main::init()](includes/class-main.php#L84) + [deactivate hook](woocommerce-shipstation.php#L69) | medium |
| **4** | Interception branch in `update_orders_shipments()` (gated by flag) | [includes/api/rest/class-orders-controller.php](includes/api/rest/class-orders-controller.php#L1745) | medium (behavior change — behind flag) |
| **5** | Rate-control filters + diagnostics depth + logging | queue/worker + [class-wc-shipstation-integration.php](includes/class-wc-shipstation-integration.php#L773) | low |
| **6** | Tests + staged rollout | `tests/` | — |

Phases 0–2 are safe to merge anytime (dark). The behavior only changes when the flag is turned on after Phase 4.

---

## 9. Optional: also cover the legacy XML path
If production may ever run **XML mode**, apply the same enqueue-then-`200` interception at the top of [WC_Shipstation_API_Shipnotify::request()](includes/api/requests/class-wc-shipstation-api-shipnotify.php#L88): capture `php://input` + `$_GET`, enqueue a normalized payload, `status_header(200)`. The worker would branch on payload type (REST JSON vs XML envelope). **Defer unless XML mode is in use** — the observed burst is 100% REST. Out of scope for v1.

---

## 10. Testing strategy

- **Parity (Phase 1):** assert `process_single_notification()` produces identical order state (meta `_shipstation_shipped_item_count`, the order note text, and the resulting status) as the pre-refactor inline loop, for: full items, empty items, unknown order, non-numeric order_id, WC Shipment Tracking present/absent.
- **Queue unit tests:** enqueue dedupe (same `notification_id` twice → one row); claim atomicity (two `claim_batch` calls with different tokens never overlap); reclaim of stale `processing`; retry backoff + attempt cap → `failed`; `prune_done`.
- **Endpoint behavior:** with flag **on**, a POST of N notifications returns `200` with N `success` results and writes N `pending` rows **without** touching orders; with flag **off**, today's synchronous behavior is unchanged.
- **Drain integration:** seed 538 rows, run the worker on the configured cadence, assert all orders reach the correct state and the table empties; assert no row processed twice.
- **Load test (staging):** replay a captured 5 AM burst against staging; confirm `queue_depth` stays flat and the drain completes in the expected window.

---

## 11. Rollout

1. Merge Phases 0–2 (dark). Verify table installs on a staging deploy and on a fresh activation.
2. Merge Phases 3–5 (flag still off).
3. **Staging:** enable `wc_shipstation_shipment_queue_enabled`, replay a burst, watch `queue_depth` and the diagnostics `shipment_queue_depth`.
4. **Production:** enable during a low-risk window; monitor the next 5–6 AM cycle for `queue_depth` (should no longer spike to ~1,460), drain time, and any `failed` rows.
5. Ensure a **system cron** drives Action Scheduler (don't rely on low-traffic WP-Cron at 5 AM) — see §7.2.
6. Keep the kill-switch documented for ops: set `define('WC_SHIPSTATION_SHIPMENT_QUEUE', false);` to instantly revert to synchronous processing.

---

## 12. Files touched (summary)

**New**
- `includes/class-shipment-queue.php` — table + queue ops (models `Connection_Log`).
- `includes/class-shipment-queue-worker.php` — Action Scheduler worker (models `Auth_Controller` prune). _(May be folded into the queue class.)_

**Modified**
- [includes/class-features.php](includes/class-features.php) — `is_shipment_queue_enabled()`.
- [includes/api/rest/class-orders-controller.php](includes/api/rest/class-orders-controller.php) — extract `process_single_notification()`; add gated enqueue branch.
- [includes/class-main.php](includes/class-main.php) — `load_files()` include; `maybe_install()` + worker registration in `init()`.
- [woocommerce-shipstation.php](woocommerce-shipstation.php) — `install()` on activation; `unschedule` on deactivation.
- [includes/class-wc-shipstation-integration.php](includes/class-wc-shipstation-integration.php) — queue depth in diagnostics details.
- `uninstall.php` — drop the queue table + options on uninstall (mirror existing cleanup).

---

## 13. Out of scope (per issue)
- Changing ShipStation's send pattern (vendor-side; cannot be configured).
- Nginx rate limiting (would make ShipStation retry → worse).
- Building `query_guard` itself (external; we only expose the filter it hooks).
