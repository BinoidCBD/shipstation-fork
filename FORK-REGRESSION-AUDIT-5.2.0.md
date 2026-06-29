# Post-5.2.0 Fork Regression Audit — which closed fixes survived the factory drop?

**Repo:** BinoidCBD/shipstation-fork
**Remote issue:** [BinoidCBD/shipstation-fork#10](https://github.com/BinoidCBD/shipstation-fork/issues/10)
**Trigger:** `5df0f0e Factory 5.2.0 commit` (parent `0fe9e1c`) dropped vendor 5.2.0 on top of the fork — **54 files, +10,363 / −951**. A vendor factory drop overwrites files wholesale, so any fork customization living in a touched file can be silently reverted.
**Goal:** For every fix closed in a prior issue/PR, determine (a) is it present in the current 5.2.0 tree, (b) was it clobbered by the factory commit, and (c) **should** it be re-applied — given ShipStation has since changed its server-side behavior (issue [#6](https://github.com/BinoidCBD/shipstation-fork/issues/6)).

> **Headline:** Two fork customizations were **silently reverted** by the factory drop and should be re-applied (diagnostics SWR + single-flight lock; the fork plugin-header branding). Three older polling-load fixes (#2/#3/#4) are **absent but should NOT be re-applied** — issue #6 shows they are now inert/counterproductive. One (#8) needs a product decision before any code.

---

## Method (so this is reproducible)

```bash
# Ancestry: is the fix commit even in the 5.2.0 branch lineage?
git merge-base --is-ancestor <fix_sha> HEAD && echo IN || echo ABSENT

# Did the factory drop touch the file?
git show --stat 5df0f0e | rg <file>

# What did the fork have before the drop vs now?
git show 0fe9e1c:<file>   # pre-factory (last fork commit)
git show HEAD:<file>      # current

# Is the behavior actually present in the working tree today?
rg -n "<marker>" <file>
```

All findings below were verified this way on `dev/5.2.0-order-status-incoming-batches`.

---

## Findings matrix

| # | Fix (closed issue / merged PR) | In 5.2.0 tree? | Clobbered by factory drop? | Re-apply? | Why |
|---|---|---|---|---|---|
| **PR #7** | Diagnostics `/details` **stale-while-revalidate + single-flight lock token** | **Partial** — plain transient cache only | **Yes** — SWR + lock removed | ✅ **YES** | Genuine regression; matters under #9 burst (diagnostics GET paired with every shipment POST → dogpile on cold cache) |
| **Branding** | Plugin header / version / stable-tag = "Forked & Customized" (commits `cda26e8`, `0fe9e1c`) | **No** — reverted to vendor strings | **Yes** | ✅ **YES** | Prod can no longer be identified as the fork from the plugin header — #6 used exactly this to confirm deployment |
| **Issue #8** | Write tracking number to **order meta** even without WC Shipment Tracking | **N/A** — handled by the WC Shipment Tracking extension (active in prod) | Pre-existing gap (not a clobber) | ✅ **Resolved — Option A, no code** | Prod confirmed running WooCommerce Shipment Tracking, so the existing `class_exists('WC_Shipment_Tracking')` branch writes the meta |
| **PR #2** | Clamp `modified_after` to 48h | **No** (not in lineage) | n/a (never in this branch) | ❌ **NO** | Inert per #6 — ShipStation lookback now **16h < 48h**, clamp never fires |
| **PR #3** | Orders-response 90s transient cache | **No** | n/a | ❌ **NO** | Inert per #6 — cache key includes `page`, so **0 hits** within a poll cycle (design flaw) |
| **PR #4** | Invalidate orders cache on order/refund changes | **No** | n/a | ❌ **NO** | **Net-negative** per #6 — writes `wp_options` on every order event for a cache that is never read |
| Issue #5 | "Mid May Patch & Fork Notes" (umbrella for #2/#3/#4) | n/a (notes) | — | — | Superseded by #6 |
| Issue #1 | Upstream comparison 4.9.8 → 5.0.4 | n/a (analysis) | — | 🔁 redo | Recommend a fresh 5.0.4 → 5.2.0 comparison (see "Broader sweep") |

---

## The decisive context: issue #6 (ShipStation changed its behavior)

Issue [#6](https://github.com/BinoidCBD/shipstation-fork/issues/6) documents that ShipStation **changed its server-side polling** (in response to support tickets):

| | Before (#802/#810) | After (#6, current logs) |
|--|--|--|
| Lookback window | 14 days | **16 hours** |
| Polling interval | ~100 min | **~61 min** |
| Daily requests (Binoid) | ~24,765 | **~1,200** |
| Pages per cycle | ~243 | **1–4** |

Consequence (verbatim from #6): the three read-path patches are **"all inert in practice"** — the 48h clamp never fires (16h < 48h), the 90s cache never hits (page in key), and the invalidation hooks write to `wp_options` for a cache that is never read.

**This negates the need to re-apply PRs #2/#3/#4.** Re-applying them would add write overhead (PR #4) and dead code for no benefit. They are recorded here as a *deliberate* do-not-re-apply so the decision is auditable rather than a silent omission.

> Note the axis shift: #2/#3/#4 target the **outbound `/orders` read/polling** path. The *current* live problem (issue [#9](https://github.com/BinoidCBD/shipstation-fork/issues/9)) is the **inbound `/orders/shipments` write/burst** path. Different mechanism, different fix (see `GH-9-REMEDIATION.md`).

---

## Item detail + evidence

### ✅ PR #7 — diagnostics SWR + single-flight lock (REGRESSED — re-apply)
- **Status:** In HEAD ancestry (`bb39c38`, `72c2844`), **but the factory commit re-wrote** [includes/api/rest/class-diagnostics-controller.php](includes/api/rest/class-diagnostics-controller.php) (233 lines in `git show --stat 5df0f0e`).
- **Evidence of revert:** pre-factory `git show 0fe9e1c:…class-diagnostics-controller.php` contained:
  > `* Read the cached site_info, with stale-while-revalidate and a`
  > `* wp_cache_add() single-flight lock to prevent a thundering herd`
  > `$lock_key = 'wcss_diagnostics_lock_v1'; … $token = wp_generate_uuid4(); $won_lock = wp_cache_add( $lock_key, $token, '', 30 );`

  Current [get_details()](includes/api/rest/class-diagnostics-controller.php#L109) is a plain `get_transient` / `set_transient` (5-min TTL) cache-aside — **no SWR, no lock, no token.**
- **Why it still matters (ties to #9):** issue #9 reports a **diagnostics GET paired with every shipment POST**. During the 5–6 AM burst, hundreds of concurrent diagnostics reads can land on a cold/expired cache; without the single-flight lock each one independently rebuilds the payload (which calls `get_plugin_data()` for *every* active plugin → disk reads). The lock is precisely the dogpile protection that burst needs.
- **Action:** port the SWR + `wp_cache_add()` lock-token logic from `0fe9e1c` onto the vendor 5.2.0 `get_details()`. Keep the vendor's direct-read payload; re-add the stale-serve + single-flight wrapper.

### ✅ Branding — "Forked & Customized" header (REGRESSED — re-apply)
- **Evidence:**
  | | pre-factory `0fe9e1c` | current HEAD |
  |--|--|--|
  | Plugin Name | `ShipStation for WooCommerce - Forked & Customized` | `ShipStation for WooCommerce` |
  | Version | `5.0.4-forked-customized` | `5.2.0` |
  | readme Stable tag | `5.0.4-forked-customized` | `5.2.0` |
- **Action:** restore the fork identity in [woocommerce-shipstation.php](woocommerce-shipstation.php) and [readme.txt](readme.txt), e.g. Plugin Name "… - Forked & Customized", Version `5.2.0-forked-customized`, Stable tag `5.2.0-forked-customized`. (Keep the 5.2.0 base; re-add the fork suffix/name.) This restores the header signal #6 uses to confirm which build is deployed.

### ✅ Issue #8 — tracking number to order meta (RESOLVED — Option A, no code)
- **Resolution (confirmed 2026-06-29):** production runs the official **WooCommerce Shipment Tracking** extension. The existing [process_items()](includes/api/rest/class-orders-controller.php#L2027) and [Shipnotify::request()](includes/api/requests/class-wc-shipstation-api-shipnotify.php#L251) `class_exists('WC_Shipment_Tracking')` branch therefore fires and calls `wc_st_add_tracking_number()`, which writes the tracking meta. **No plugin change needed.**
- **Standing dependency (note, not an action):** this fix lives in the *extension*, not the fork. If WC Shipment Tracking is ever deactivated, the plugin silently reverts to note-only and the #8 gap returns. Keep the extension active; if it is ever dropped, re-open #8 and implement Option B (drop the `class_exists` gate, write `_tracking_number` / `_tracking_provider` / `_date_shipped` unconditionally — fold into the #9 shipnotify work since both touch `process_items()` and the XML handler).

### ❌ PRs #2 / #3 / #4 — read-path polling mitigations (DO NOT re-apply)
- **Status:** confirmed absent — `git merge-base --is-ancestor` returns ABSENT for all three (`7de9138`, `dca2e1e`, `e27fd71`); `rg` finds no `*_transient` caching, no 48h clamp, and no order-change invalidation hooks anywhere in [includes/](includes/).
- **Decision:** **do not re-apply.** Per #6 they are inert (clamp, cache) or net-negative (invalidation write overhead) under ShipStation's current 16h/61-min behavior. If outbound `/orders` load ever regresses, revisit — and redesign the cache key to be **page-agnostic** (the #6 flaw) before re-introducing #3/#4.

---

## Broader sweep (recommended, beyond the GH-tracked fixes)

The factory drop touched 54 files; only PR #7 + branding were fork customizations in this lineage, and **both were reverted** — a 100% clobber rate for fork edits in touched files. Before trusting 5.2.0, do a targeted review of the factory diff for any *other* silently-reverted local change:

```bash
git diff 0fe9e1c 5df0f0e -- ':!vendor' ':!languages' ':!*.min.js'
```
Most of that diff is legitimate vendor upgrade (new checkout-rates, connection-log, SHIPSTN-142 auth/credentials redesign, etc.). The audit task is to scan for **deletions/reverts of fork-authored lines**, not the additions. Pay attention to any file the fork had edited: [class-diagnostics-controller.php](includes/api/rest/class-diagnostics-controller.php) (known), [woocommerce-shipstation.php](woocommerce-shipstation.php) (known: branding), [readme.txt](readme.txt) (known: stable tag), and confirm nothing else fork-specific (custom filters, log lines, meta keys) was dropped.

Also refresh issue [#1](https://github.com/BinoidCBD/shipstation-fork/issues/1) as a **5.0.4 → 5.2.0** comparison so the new vendor surface (and its risk) is documented the way 4.9.8 → 5.0.4 was.

---

## Re-apply checklist

- [ ] **Diagnostics SWR + single-flight lock** — port `0fe9e1c` SWR/`wp_cache_add` token logic onto 5.2.0 [get_details()](includes/api/rest/class-diagnostics-controller.php#L109). (Complements the #9 batch-queue work.)
- [ ] **Fork branding** — restore "Forked & Customized" name + `*-forked-customized` version/stable-tag in [woocommerce-shipstation.php](woocommerce-shipstation.php) + [readme.txt](readme.txt).
- [x] **Issue #8** — RESOLVED: prod runs WC Shipment Tracking (Option A) → no code needed. Standing dependency: keep the extension active.
- [ ] **Record (no code):** PRs #2/#3/#4 intentionally **not** re-applied — obsolete per #6.
- [ ] **Broader sweep** — review `git diff 0fe9e1c 5df0f0e` (excluding vendor/lang/min) for any other reverted fork edits.
- [ ] **Refresh #1** as a 5.0.4 → 5.2.0 upstream comparison.

---

## Decision log (do-not-re-apply, recorded so it's auditable)

| Item | Decision | Source |
|---|---|---|
| PR #2 clamp `modified_after` 48h | Do not re-apply (inert: 16h < 48h) | #6 |
| PR #3 orders 90s response cache | Do not re-apply (0 hits: page-keyed) | #6 |
| PR #4 orders cache invalidation hooks | Do not re-apply (write overhead, never read) | #6 |

---

_Audit by Claude Code (Opus): ancestry checks + working-tree verification + pre/post-factory diff against commit `5df0f0e`, cross-referenced with issues #1/#5/#6/#8 and PRs #2/#3/#4/#7. Canonical copy committed at `FORK-REGRESSION-AUDIT-5.2.0.md`._
