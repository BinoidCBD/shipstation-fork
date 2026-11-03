# Fork upgrade: upstream 5.2.0 → 5.4.0

**Branch:** `update/5.4.0` (merge of `original/5.4.0` into `main`)
**Source:** `woocommerce-shipstation-integration.5.4.0.zip` (upstream, 2026-10-14)
**Result:** `5.4.0-forked-customized`

> **Headline:** This upgrade is a **3-way git merge**, not a factory drop. The 5.2.0 factory drop (`5df0f0e`) overwrote fork files and silently reverted two customizations (see `FORK-REGRESSION-AUDIT-5.2.0.md`). Merging against a pristine upstream branch keeps both sides and leaves only true overlaps to resolve by hand. All fork customizations survived.

---

## Method (repeat this for the next upgrade)

```bash
# 1. Pristine upstream branch, built on the previous pristine branch.
git worktree add --no-track -b original/5.4.0 <tmp>/wt origin/original/5.2.0
rsync -a --delete --exclude .git --exclude .gitignore --exclude wordpress_org_assets/ \
  <unzipped>/woocommerce-shipstation-integration/ <tmp>/wt/
git -C <tmp>/wt add -A && git -C <tmp>/wt commit -m "Factory 5.4.0 commit"
git worktree remove <tmp>/wt

# 2. Merge it into the fork.
git checkout -b update/5.4.0 main
git merge --no-ff original/5.4.0

# 3. Prove the fork delta survived: these two diffs should match
#    (apart from version strings and anything listed under "Added" below).
git diff original/5.2.0 main         -- includes woocommerce-shipstation.php readme.txt
git diff original/5.4.0 update/5.4.0 -- includes woocommerce-shipstation.php readme.txt
```

`original/5.4.0` is the merge base for the next upgrade, so push it alongside the update branch.

---

## Conflicts and how they were resolved

| File | Conflict | Resolution |
|---|---|---|
| `woocommerce-shipstation.php` | `Version:` header and `WC_SHIPSTATION_VERSION` | `5.4.0-forked-customized`. The fork's Plugin Name, `Update URI: false` and GH-9 activate/deactivate hooks merged cleanly, as did upstream's requirement bumps. |
| `readme.txt` | `Stable tag:` | `5.4.0-forked-customized`. Upstream's header and changelog taken. |

These merged cleanly; each was checked by hand:

- `includes/api/rest/class-orders-controller.php`: upstream's `use` block (`Enum_Helper` replaces `OrderStatus`, plus `Prepaid_Duties`) is kept next to the fork's `Features` and `Shipment_Queue` imports. Upstream's new `Order_Util::flush_*()` calls in `update_orders_shipments()` land after the fork's `process_single_notification()` loop.
- `includes/class-features.php`: `is_shipment_queue_enabled()` is kept after upstream's new methods.
- `includes/class-main.php`: the `Shipment_Queue` require and boot calls are kept next to upstream's new requires and the `maybe_defer_shipment_emails` hook.
- `includes/class-wc-shipstation-integration.php`: `shipment_queue_depth` is kept.
- `includes/api/rest/class-diagnostics-controller.php`: upstream didn't touch this file (5.2.0 == 5.4.0), so the SWR + single-flight lock carried over as is.

## Added during the merge (fork code, not conflicts)

Upstream 5.3.4/5.3.9 flush two per-process refund-read memos at the end of every shipnotify request: `Order_Util::flush_qty_refund_failure_log()` and `Order_Util::flush_degraded_refund_primes()`. The fork has two more paths that process shipment notifications, so both now flush too:

- `Orders_Controller::enqueue_orders_shipments()`, after the loop, because its synchronous fallback can process notifications.
- `Shipment_Queue::run_batch()`, after each batch, because the Action Scheduler worker is the long-lived process the upstream docblock warns about.

---

## How upstream 5.3.x/5.4.0 interacts with the fork's GH-9 queue

- **Retry de-duplication (5.3.1)** lives in `process_items()` and uses the `_shipstation_processed_shipments` order meta. The worker reaches it through `process_single_notification()`, so a queue retry of a shipment that is already recorded now skips the note, the tracking write and the shipped-item counter. This makes the worker's retry/backoff safer.
- **Failure containment (5.3.3)**: `Order_Util::*_safely()` wraps the note, email, status transition and third-party hooks. The worker gets this automatically.
- **Deferred shipment emails (5.3.3)**: `Main::maybe_defer_shipment_emails()` matches the `POST /orders/shipments` URL. In queue mode that request only writes queue rows, so the deferral does nothing. In the worker, emails go out inline under upstream's failure containment. No change needed.
- **Requirements bump:** WP ≥ 7.0 and WC ≥ 11.0 (tested up to 7.1 / 11.2). Check production before deploying.

## Verification checklist

- [x] `php -l` passes on every changed PHP file.
- [x] `git diff original/5.4.0 update/5.4.0` touches only the fork's plugin files plus fork docs/tooling (no vendor, language or asset files).
- [ ] Staging: the plugin activates and the ShipStation settings tab shows the connection section.
- [ ] Staging: `GET /wp-json/wc-shipstation/v1/diagnostics/details` returns `plugin_version: 5.4.0-forked-customized` and `shipment_queue_depth`.
- [ ] Staging, queue off: `POST /orders/shipments` processes in the request. A resend of the same `notification_id` logs "already recorded".
- [ ] Staging, `WC_SHIPSTATION_SHIPMENT_QUEUE` true: POST returns success right away, and the Action Scheduler worker drains the row and completes the order.
