# Build State — EventKoi Tickets Importer

**Single source of truth for build progress AND the step-by-step implementation
plan.** Every build session MUST update this file as its final action (see "Update
protocol" below). Read this first before starting any step. This file inlines the
condensed version of each build step so progress and plan travel together in one
top-level document.

- **Branch:** `main`
- **Plugin version:** 1.2.0
- **Last updated:** 2026-08-23
- **Overall status:** ✅ Step 7 complete — **Ticket Details Sync** shipped
  and executed on dev: 664 ticket rows backfilled with sale windows +
  capacity, 33 multi-product events reconciled into 40 new + 37 converted
  distinct ticket types, 291 product bindings written — fully audited,
  undo verified, idempotent. Ready for the ticket import pipeline
  (Auto-Match → mapping review → dry run → live import).

## Shared project facts (true for every step)

- **Plugin name:** EventKoi Tickets Importer
- **Text domain / slug:** `eventkoi-tickets-importer`
- **Prefix:** `ekti_` (functions/options/hooks), `EKTI_` (constants)
- **Author:** One Dog Solutions (https://onedog.solutions/)
- **License:** GPL-2.0-or-later · **Min WP:** 6.0 · **Min PHP:** 8.0
- **Target environment:** WordPress with The Events Calendar (Event Tickets /
  Event Tickets Plus) + WooCommerce + EventKoi installed
- **Architecture:** Single-file plugin (`tec-to-eventkoi-tickets-importer.php`,
  singleton class `EventKoi_Tickets_Importer`) + vanilla jQuery admin JS
  (`assets/admin.js`) + admin CSS (`assets/admin.css`). No build step, no
  React, no `@wordpress/scripts` — this is a lightweight migration utility,
  not a settings-heavy plugin. Admin page registered under Tools.
- **Persistence:** `ekti_event_mapping` (option, TEC event ID → EventKoi event
  ID), `ekti_migration_state` (option, batch progress), per-attendee
  `_eventkoi_imported_from_tec` post meta, per-ticket-type
  `_ekti_tec_product_{tec_event_id}_{product_id}` options.
- **Logging:** File-based at `wp-content/uploads/eventkoi-import.log`,
  append-only with `LOCK_EX`.
- **Standards:** WordPress Coding Standards; escape on output, sanitize on
  input, nonce + `manage_options` capability check on every AJAX handler,
  `ABSPATH` guard at the top of every PHP file. Direct `$wpdb` access is
  acceptable here (migration tool reading/writing custom tables and post meta
  that have no WP-API abstraction).

## Progress

| Step | Deliverable | Status | Commit |
|------|-------------|--------|--------|
| 1 | Bootstrap + singleton class + admin menu | ✅ Done | c303f8a |
| 2 | Database layer (TEC attendees, EventKoi events/tickets, mapping) | ✅ Done | b2a2594 |
| 3 | Migration engine (batch processing, dry run, rollback) | ✅ Done | b2a2594 |
| 4 | Event auto-match + duplicate TEC import handling | ✅ Done | 2bdb3c7, f63942a |
| 5 | WooCommerce order linking (parent orders, charges, composite keys, WC meta) | ✅ Done | 0ca72b3 |
| 6 | Pre-Import Cleanup (stale ticket dedupe + duplicate event merge, audit + undo) | ✅ Done | 7a1ee56 |
| 7 | Ticket Details Sync (sale windows, capacity, multi-product reconciliation) | ✅ Done | TBD |

Status legend: ⬜ Not started · 🟡 In progress · ✅ Done · ⚠️ Blocked

## Next action

**Step 7 (Ticket Details Sync) executed + verified on dev (2026-08-23).**

- **Proceed with the ticket import** on dev: Auto-Match → review mapping →
  dry run → live import. Sync scan now reports 0 actionable items
  (attendee imports resolve via `_ekti_tec_product_*` bindings).
- **3 review items remain** (inverted sale windows — TEC event published
  after the computed off-sale cutoff; window skipped, fields left NULL):
  Pie (TEC 44302), Fondant Hamburger Cake (TEC 48791), + 1 similar.
- **4 report-only review items** (single ticket whose price ≠ current TEC
  price; not auto-fixed by design): Pan Dulce Workshop Series II (750 vs
  700), Frosting Feast (55 vs 0), Pan Dulce Workshop Series (750 vs 800),
  Dad & Me Flippin Awesome (35 vs 15).
- **Pre-existing orphans** (predating this cleanup, from the earlier
  manual pre-delete approach): 38 ticket rows + 2 `hold` test orders
  reference events that were hard-deleted long ago (no post exists to
  untrash). Ticket 980 is guard-protected because the 2 hold rows point at
  it. Decide whether to purge these manually before/after import.

Remaining before wider release:

- **Live QA** on a WordPress install with real TEC + EventKoi + WooCommerce
  data — confirm the full pipeline (stats → auto-match → mapping review →
  dry run → live import → EventKoi Orders/Attendees/QR check-in → WC order
  meta visible → rollback including WC meta) works end-to-end.
- **Idempotency QA** — run the importer twice on the same dataset; the
  second run should report all attendees as "Already imported" and create
  zero new parent orders, charges, or WC meta writes.
- **Status sync QA** — after import, change a WC order status (e.g. to
  `refunded`) and verify EventKoi's `on_order_status_changed` and
  `on_order_refunded` handlers pick up the change via the `_eventkoi_synced`
  flag.
- **readme.txt** (WordPress.org-style) if distributing.
- **`.pot` translation template** if internationalization is needed.
- **Uninstall cleanup** (`uninstall.php`) — currently the plugin leaves
  `ekti_event_mapping`, `ekti_migration_state`, and `_ekti_tec_product_*`
  options behind on deletion. These are low-risk (no tables created, no
  persistent hooks) but should be cleaned up for a polished release.

---

## Implementation steps

### Step 1 — Bootstrap + singleton class + admin menu ✅
`tec-to-eventkoi-tickets-importer.php`: plugin header (name/version/author/
license/text-domain), `ABSPATH` guard, constants `EKTI_VERSION` / `EKTI_LOG_DIR`
/ `EKTI_LOG_FILE` / `EKTI_BATCH_SIZE`. Singleton `EventKoi_Tickets_Importer`
class with `instance()` factory. `__construct()` wires `admin_menu`,
`admin_enqueue_scripts`, and all `wp_ajax_*` handlers. Admin page registered
under Tools via `add_management_page()` (slug `eventkoi-ticket-importer`, cap
`manage_options`).

### Step 2 — Database layer ✅
All data access lives in the singleton class as private methods:
- **TEC side:** `get_tec_attendees($offset, $limit)` — pivoted `postmeta` query
  over `tribe_wooticket` CPT extracting full name, email, TEC event ID,
  product ID, WC order ID, paid price, check-in status, product name,
  security code, ticket meta, and order item ID. `count_tec_attendees()` for
  totals. `get_tec_event_ids_with_attendees()` for distinct TEC event IDs.
- **EventKoi side:** `get_eventkoi_events()` — all `eventkoi_event` posts
  (published, expired, draft — excluding trash) so past events can still
  receive imported attendees. `get_eventkoi_events_with_tickets()` — only
  published events that have rows in `wp_eventkoi_tickets`.
  `get_eventkoi_tickets_for_event($event_id)` — existing ticket types.
- **Mapping:** `get_saved_mapping()` / `save_mapping()` — reads/writes the
  `ekti_event_mapping` option (associative array: TEC event ID → EventKoi
  event ID).
- **Source mapping:** `get_tec_import_source_mapping()` — reads
  `_tec_import_source_id` post meta left by EventKoi's native event importer.
  Handles duplicate imports (same TEC event imported multiple times into
  EventKoi) by preferring published over expired/draft, then lowest post ID.
- **Helpers:** `ek_table($name)` prefixes `eventkoi_` onto table names.
  `get_wc_order_date($order_id)` reads the WC order creation date via
  `wc_get_order()`. `get_product_name_for_tec_event()` recovers the product
  name from `_tribe_deleted_product_name` meta when the TEC event post is
  deleted.

### Step 3 — Migration engine (batch processing, dry run, rollback) ✅
- **State machine:** `get_migration_state()` / `update_migration_state()` /
  `reset_migration_state()` — `ekti_migration_state` option tracks offset,
  processed count, tickets/attendees/orders/charges created, skipped,
  errors, completion flag, dry-run flag, and start time.
- **Batch processor:** `process_batch($dry_run)` — see Step 5 for the
  Phase 2 3-phase rework. In summary: reads `EKTI_BATCH_SIZE` (30)
  attendees from the current offset, groups by WC order, creates one parent
  `wp_eventkoi_orders` row + one `wp_eventkoi_charges` row per WC order,
  then one `wp_eventkoi_ticket_orders` row per attendee with the composite
  `order_id = 'wc_' . $wc_order_id . ':' . $ticket_id . ':' . $checkin_code`
  and `payment_status = 'complete'`. Checks both composite and legacy
  `tec_import_` keys for duplicates. Updates `quantity_sold` via recount.
  Marks source attendees with `_eventkoi_imported_from_tec` post meta.
- **Ticket creation:** `get_or_create_ticket()` — checks for a previously
  created ticket (via `_ekti_tec_product_{tec_event_id}_{product_id}` option),
  falls back to matching existing EventKoi tickets by event + name, and if
  neither matches, inserts a new row into `wp_eventkoi_tickets` with
  `quantity_sold` pre-set to the existing attendee count for that TEC
  event/product combo.
- **Dry run:** same code path but skips DB writes; results array contains
  `dry_run_create` actions with the would-be composite key, WC order ID,
  and check-in code.
- **Rollback:** `rollback_import()` — deletes importer-created rows from
  five tables (`wp_eventkoi_order_notes`, `wp_eventkoi_charges`,
  `wp_eventkoi_orders`, `wp_eventkoi_ticket_orders`, `wp_eventkoi_tickets`),
  cleans `_eventkoi_imported_from_tec` source meta, cleans `_eventkoi_*`
  meta on WC `shop_order` posts, resets migration state. See Step 5 for
  full table/pattern details.
- **AJAX handlers:** `ajax_run_batch`, `ajax_rollback`, `ajax_get_stats`,
  `ajax_get_log`, `ajax_clear_log` — all nonce + `manage_options` gated.

### Step 4 — Event auto-match + duplicate TEC import handling ✅
- **Auto-match strategies** (in priority order):
  1. **`_tec_import_source_id`** — authoritative mapping left by EventKoi's
     native event importer. Handles duplicates by preferring published events,
     then lowest post ID.
  2. **Exact title match** — `sanitize_title()` comparison between TEC event
     title and EventKoi event titles. Falls back to `_tribe_deleted_product_name`
     when the TEC event post has been deleted.
  3. **Fuzzy title match** — `similar_text()` with ≥85% threshold.
- **UI mapping badges:** the admin table shows a green checkmark ("import log")
  for source-matched events and an amber tilde ("title/fuzzy") for title-matched
  or manually assigned events.
- **Duplicate handling** (commit `f63942a`): `get_tec_import_source_mapping()`
  groups all `_tec_import_source_id` entries by TEC event ID, sorts duplicates
  by status priority (publish > eventkoi_expired > draft) then by post ID
  ascending, and keeps only the winner. Logged when duplicates are detected.
  `get_eventkoi_events()` includes expired and draft statuses (excluding only
  trash/auto-draft) so past-class attendees can still be mapped.

---

### Step 5 — WooCommerce order linking (Phase 2) ✅
Brings the importer's output into structural parity with EventKoi's native
`WooCommerce_Checkout::on_payment_complete()` + `Ticket_Order_Sync::sync_order_to_local()`
flow so imported attendees appear correctly in EventKoi's Orders list,
Attendees tab, sales history, QR check-in, and status sync.

- **New helper methods** on the singleton class:
  - `wc_is_available()` — `function_exists('wc_get_order') && class_exists('WooCommerce')`
    guard. Resolves the long-standing WC-dependency blocker.
  - `get_wc_order($order_id)` — safe wrapper that returns `null` when WC is
    inactive or the order does not exist.
  - `get_wc_order_date($order_id)` — rewritten on top of `get_wc_order()`;
    no longer fatals when WC is absent.
  - `generate_checkin_code()` — 12-char codes using EventKoi's human-friendly
    alphabet `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no ambiguous chars `0`, `O`,
    `1`, `I`). Cryptographically random via `random_bytes()`.
  - `create_parent_order(...)` — upserts a row into `wp_eventkoi_orders` with
    `checkout_id = 'wc_' . $wc_order_id`, lowercase currency, INT unix
    timestamps, `gateway = 'woocommerce'`, `payment_status = 'complete'`, and
    billing info pulled from the WC order (falling back to the first
    attendee). Also inserts an `order_completed` row into
    `wp_eventkoi_order_notes`. Idempotent via `checkout_id` UNIQUE KEY.
  - `create_charge_row(...)` — upserts into `wp_eventkoi_charges` with
    `charge_id = 'wc_charge_' . $wc_order_id`, `status = 'succeeded'`,
    `gateway = 'woocommerce'`. Idempotent via `charge_id` UNIQUE KEY.
  - `set_wc_order_meta(...)` — writes `_eventkoi_event_id`,
    `_eventkoi_instance_ts`, `_eventkoi_event_title`,
    `_eventkoi_ticket_items`, `_eventkoi_master_checkin_code`, and
    `_eventkoi_synced = 'yes'` on the WC order. Skips when `_eventkoi_synced`
    is already `'yes'` (idempotent). Enables EventKoi's admin to link back to
    the WC order, suppress duplicate emails, and react to status changes and
    refunds.
- **`process_batch()` reworked into 3 phases:**
  - *Phase 1 — resolve & group*: validates mapping, gets/creates ticket
    types, and groups attendees by `wc_order_id` while accumulating per-order
    totals and ticket items.
  - *Phase 2 — parent order + charge per WC order*: calls
    `create_parent_order()`, `create_charge_row()`, and `set_wc_order_meta()`
    once per unique WC order.
  - *Phase 3 — ticket_orders rows per attendee*: builds the composite
    `order_id = 'wc_' . $wc_order_id . ':' . $ticket_id . ':' . $checkin_code`,
    sets `payment_status = 'complete'` (was `'paid'` — `'paid'` is invisible
    to EventKoi's `sync_quantity_sold()` IN-clause), sets
    `charge_id = 'wc_charge_' . $wc_order_id`, `payment_intent_id = null`,
    and `checkin_token` to the generated 12-char code. Free/comp attendees
    without a WC order fall back to the legacy `tec_import_{id}` key.
  - Duplicate detection now checks **both** the new composite key **and** the
    legacy `tec_import_{id}` key so v1.0.0 imports are not re-imported.
  - `quantity_sold` is now recounted via
    `SELECT COALESCE(SUM(quantity), 0) ... WHERE payment_status IN ('complete','completed','succeeded','partially_refunded')`
    — matching EventKoi's own `sync_quantity_sold()` semantics — instead of
    the v1.0.0 `quantity_sold + 1` increment.
- **Migration state tracking** gained `orders_created` and `charges_created`
  counters (in both `get_migration_state()` defaults and
  `reset_migration_state()`). Surfaced in the progress status line and the
  completion summary in `admin.js`.
- **`ajax_get_stats()`** now returns `wc_order_count` (COUNT DISTINCT WC
  orders for mapped events) and `wc_available` (boolean). The admin page
  renders a new "WC Orders to Link" stat tile and shows a dismissible
  warning notice when WooCommerce is not active.
- **Rollback** now cleans up all five tables and WC order meta:
  `wp_eventkoi_order_notes` (for importer parent orders),
  `wp_eventkoi_charges` (`gateway='woocommerce'` + `wc_charge_%`),
  `wp_eventkoi_orders` (`gateway='woocommerce'` + `wc_%` checkout_id),
  `wp_eventkoi_ticket_orders` (`wc_%` OR `tec_import_%`), importer-created
  ticket types, `_eventkoi_imported_from_tec` source meta, AND the
  `_eventkoi_*` meta on WC `shop_order` posts.
- **admin.js** updates: new stat tile population (`#stat-wc-orders`), WC
  warning show/hide, composite keys shown in console for both `created` and
  `dry_run_create` results, progress status line includes Orders count,
  completion summary includes Orders + Charges, rollback confirmation and
  per-table deletion counts updated.
- **admin.css** — no changes required; the `.ekti-stats-grid` uses
  `grid-template-columns: repeat(auto-fill, minmax(180px, 1fr))` which
  absorbs the new tile automatically.

---

### Step 6 — Pre-Import Cleanup (stale ticket dedupe + duplicate event merge) ✅
EventKoi's native TEC event importer re-ran on 2026-08-23 and produced
817 `eventkoi_event` posts with **151 duplicate pairs** (same
`_tec_import_source_id`, identical `start_timestamp`) and **794 events
carrying two same-name "General admission" tickets** (one stale from the
2026-08-11 import with the outdated price, one fresh matching the current
WC product `_price`). Verified beforehand: TEC has exactly one ticket
product per event, 0 tickets sold, 0 attendee rows on any duplicate.

New backend methods on the singleton class:
- `get_tec_price_map()` — bulk TEC-event → current-price map via attendee
  `_tribe_wooticket_product` → WC `_price`; fallback: single distinct
  `_paid_price`; recovers names via `_tribe_deleted_product_name`.
- `scan_cleanup()` — returns `stale_pairs` (same-name 2-ticket events with
  no sales; keep = price matching TEC current price, else later
  `created_at`/higher ID), `review` items (sales present, or single ticket
  whose price ≠ TEC price), and `dup_groups` (by `_tec_import_source_id`,
  canonical first using the same publish > eventkoi_expired > draft, then
  lowest-ID resolver as `get_tec_import_source_mapping()`; flagged when
  `start_timestamp` diverges within a group).
- `run_ticket_dedupe($chunk=50)` — re-verifies hard guards
  (`quantity_sold = 0` AND no `ticket_orders` rows) at execution time,
  deletes the stale ticket, and records an `insert_ticket` audit op with
  the full deleted row.
- `run_event_merge($chunk=25)` — per group, single DB transaction:
  matching name+price loser ticket → repoint any `ticket_orders.ticket_id`,
  delete loser ticket (audited); drifted ticket → `UPDATE event_id` to
  canonical instead. Repoints remaining `ticket_orders.event_id`, recounts
  `quantity_sold` with EventKoi's `payment_status IN (...)` semantics,
  adopts `quantity_available` only if canonical is NULL, repoints
  `_ekti_tec_product_*` options, unions `event_cal` terms onto canonical
  (append-only), repoints `_eventkoi_event_id` shop_order meta and
  `ekti_event_mapping` values, then `wp_trash_post()` the loser (never
  hard-deleted).
- `undo_cleanup()` — replays the `ekti_cleanup_audit` ledger in reverse
  (op types: `insert_ticket`, `update_ticket_event`, `update_ticket_fields`,
  `update_ticket_orders_ticket`, `update_ticket_orders_event`,
  `update_option`, `update_postmeta`, `set_terms`, `untrash_post`).
- AJAX: `ajax_scan_cleanup`, `ajax_run_ticket_dedupe`, `ajax_run_event_merge`,
  `ajax_undo_cleanup` — all nonce + `manage_options` gated via `check_ajax()`.
- New admin panel **"Pre-Import Cleanup"** (fifth panel): Scan button renders
  three tables (stale pairs, review items, duplicate groups with canonical
  badge); Run Ticket Dedupe / Run Event Merge buttons with JS confirm and
  chunked progress reuse; Undo Last Cleanup. `admin.js` gained
  `scanCleanup()` / `loopCleanup()` / `runDedupe()` / `runMerge()` /
  `undoCleanup()`. Version bumped to 1.1.0.

**Dev execution results (2026-08-23):** Phase 1 deleted 794 stale tickets
(ticket rows 1790 → 996, remaining stale pairs 0). Phase 2 merged 151/151
groups with 0 flags and 0 errors (remaining groups 0; publish events
793 → 642, trash = 151). Audit ledger: 1096 ops (945 `insert_ticket` =
794 P1 + 151 P2 loser tickets; 151 `untrash_post`). Conservation verified:
no event retains 2 tickets, `SUM(quantity_sold)` = 0 before and after,
0 rows reference any event trashed by this cleanup, spot-checked canonical
events show single ticket at current price with calendars intact.

---

### Step 7 — Ticket Details Sync (sale windows, capacity, reconciliation) ✅
Backfills `sale_start`, `sale_end`, `quantity_available` on EventKoi ticket
rows (all 845 NULL on dev) and reconciles the 33 mapped TEC events that
have multiple distinct WC ticket products into per-product EventKoi ticket
types. Verified beforehand: all 33 multi-product events carry genuinely
distinct ticket types (33/33 distinct names, 0 identical price pairs);
EventKoi stores both window fields as UTC `'Y-m-d H:i:s'` datetimes and
`quantity_available` as a TOTAL (remaining = available − sold − held).

Field rules (per ticket):
- `sale_start` = TEC event `post_date` (original publish moment, site-local
  → UTC; fallback EventKoi `post_date`).
- `sale_end` = event-start calendar date (EventKoi `start_timestamp`;
  fallback TEC `_EventStartDate`) minus 1 day at 06:00 site time → UTC.
- `quantity_available` = product `_tribe_ticket_capacity` when int ≥ 0;
  `''`/`-1` → NULL (unlimited). WC `_stock` is unusable (TEC markers).
- Guards: only fill currently-NULL fields (non-NULL conflicts → review);
  inverted windows (`sale_end <= sale_start`) → review + skip.

Reconciliation per mapped event (products via `_tribe_wooticket_for_event`
backlink ∪ attendee `_tribe_wooticket_product`, sorted by product ID):
- Single-product events: keep existing ticket as-is, bind the
  `_ekti_tec_product_*` option, backfill fields. No rename (minimal blast
  radius).
- Multi-product events: name-match → use; else first unconverted
  placeholder with equal price (hard-guarded `quantity_sold = 0` AND zero
  `ticket_orders` rows, re-verified at execution) → convert (rename); else
  create a new ticket row (price = product `_price`, USD, active). Leftover
  placeholders → review, never auto-deleted.

New backend methods: `get_tec_product_map()`, `local_to_utc()` /
`utc_to_local()`, `compute_sale_window()` / `compute_sale_window_for_event()`,
`ticket_defaults()`, `queue_field_updates()`, `scan_ticket_details()`
(read-only; returns per-event items of type create/convert/bind/update/
review + counts), `apply_ticket_update()`, `run_ticket_details_sync($chunk=50)`
(chunked, per-event DB transactions, re-scans each call so the pending list
shrinks), `audit_push_sync()`, `undo_sync()`. `undo_cleanup()` and
`undo_sync()` now share `replay_audit_ops()` (new op types `delete_ticket`,
`add_option`). `get_or_create_ticket()` now populates the three fields on
insert via `ticket_defaults()` and no longer reads `_stock` — closing the
latent duplicate-ticket bug where attendee imports could never match the
"General admission" placeholder by name (option binding is checked first).
AJAX: `ajax_scan_ticket_details`, `ajax_run_ticket_details_sync`,
`ajax_undo_sync` — nonce + `manage_options` gated via `check_ajax()`.

New admin panel **"Ticket Details Sync"** (sixth panel): Scan renders three
tables (creates/converts with price + capacity + site-local window; field
updates shown current → proposed; review items with reasons); Run Sync with
JS confirm, chunked progress, per-event console tallies; Undo Last Sync.
`admin.js` gained `scanTicketDetails()` / `loopTicketSync()` / `runSync()` /
`undoSync()`. Version bumped to 1.2.0.

**Dev execution results (2026-08-23):** Scan found 664 field updates,
40 creates, 37 converts, 291 binds across 665 pending events, 3 review
items (inverted windows). Sync ran in 14 chunks of ≤50 events with 0
errors and tallies exactly matching the scan. Verified: fixture TEC 42192
→ qty 15, `2025-06-18 20:27:33` / `2025-08-22 11:00:00` UTC; Pan Dulce
3-product events now carry Bundle/Early Bird/VIP ticket types; unlimited
(`-1`) capacities stayed NULL; future event on sale, past event off sale,
remaining = capacity − sold. Undo restored the exact baseline (845 rows,
all NULL, 426 bindings, converted names reverted, Step 6 ledger intact);
re-sync reproduced identical results and a third scan reports 0 actions
(idempotent). Dry-run `get_or_create_ticket()` resolves distinct products
via bindings (no duplicate ticket creation). The 181 rows still NULL are
out of scope: 38 pre-existing orphans (hard-deleted event posts) plus
tickets attached to non-event posts (WC refund/attachment/attendee-format
posts, no TEC source).

---

## Admin UI ✅

Server-rendered PHP page (`render_admin_page()`) with six panels:
1. **Overview** — stats grid (TEC events, attendees, EventKoi events, mapped/
   unmapped events, attendees to import, WC orders to link, already imported)
   loaded via AJAX. Dismissible warning notice when WooCommerce is inactive.
2. **Event Mapping** — Auto-Match button, Load/Save Mapping buttons, a
   `wp-list-table` with TEC event ID/title/attendee count and an EventKoi
   event `<select>` per row. Status badges show match source.
3. **Run Migration** — Dry Run checkbox, Start Import / Resume / Rollback
   buttons, progress bar, live console output with color-coded log entries
   (green=success, red=error, yellow=warn, blue=info). Console shows
   composite keys and WC order IDs per attendee.
4. **Import Log File** — Load/Clear buttons, `<pre>` viewer.
5. **Pre-Import Cleanup** (v1.1.0) — Scan / Run Ticket Dedupe / Run Event
   Merge / Undo Last Cleanup buttons, progress bar reuse, three review
   tables (stale ticket pairs with keep-vs-delete + TEC current price,
   review items, duplicate event groups with canonical badge and member
   stats).
6. **Ticket Details Sync** (v1.2.0) — Scan / Run Sync / Undo Last Sync
   buttons, progress bar reuse, three tables (ticket types to create or
   convert with price/capacity/site-local sale window, field updates with
   current → proposed values, review items).

`admin.js` (jQuery IIFE): AJAX helper, console appender, stats loader
(including WC order count + WC availability check), mapping loader/saver,
auto-match runner, recursive batch runner with progress tracking showing
attendees/orders/skipped/errors, completion summary with charges, rollback
with per-table deletion counts.

`admin.css`: panel cards, auto-filling stats grid, progress bar, dark console
with syntax coloring, mapping table overflow scroll, CSS spinner.

---

## Decisions & deviations log

- 2026-08-18: Initial STATE.md created retroactively to document the completed
  1.0.0 build. All four steps were implemented across commits `c303f8a` →
  `f63942a`.

- 2026-08-20: **Phase 2 — WooCommerce order linking.** Deep inspection of
  EventKoi Lite's source (`class-woocommerce-checkout.php`,
  `class-ticket-order-sync.php`, and the three table schema classes) showed
  that the v1.0.0 importer was producing orphaned `wp_eventkoi_ticket_orders`
  rows: wrong `order_id` format (`tec_import_{id}` instead of the composite
  `wc_{wc_order_id}:{ticket_id}:{checkin_code}`), wrong `payment_status`
  (`'paid'` instead of `'complete'` — invisible to `sync_quantity_sold()`'s
  IN-clause), missing parent rows in `wp_eventkoi_orders`, missing rows in
  `wp_eventkoi_charges`, missing `order_completed` notes, and missing
  `_eventkoi_*` meta on the WC order itself. The result was that imported
  attendees were invisible to EventKoi's admin Orders list, Attendees tab,
  QR check-in, and status/refund sync. Phase 2 rewrites `process_batch()`
  as a 3-phase pipeline (resolve+group → parent order+charge per WC order →
  ticket_orders row per attendee with composite key), adds seven helper
  methods, extends rollback to five tables plus WC meta, and guards every
  `wc_get_order()` call behind `function_exists()` checks — closing the
  WooCommerce-dependency blocker called out in Open questions since 1.0.0.

- Commit `2bdb3c7` (auto-match enhancement): added `_tec_import_source_id` as
  the primary auto-match strategy. Before this, only title-based matching was
  available. The source mapping is authoritative because it's written by
  EventKoi's own event importer at import time — if EventKoi says "this
  EventKoi event came from TEC event X," that's the ground truth.

- Commit `f63942a` (duplicate handling): `get_eventkoi_events()` was widened
  from `post_status = 'publish'` to `NOT IN ('trash', 'auto-draft')` so
  expired and draft EventKoi events can still receive imported attendees.
  `get_tec_import_source_mapping()` gained duplicate resolution logic —
  multiple EventKoi events can share the same `_tec_import_source_id` when
  the same TEC event was imported more than once; the resolver picks the
  published one (or the earliest import when statuses are equal).

- 2026-08-23: **Step 6 — Pre-Import Cleanup.** Implemented the two-phase
  cleanup as a reusable plugin panel (rather than one-off SQL) so it can be
  replayed at the production cutover with full audit + undo. Key design
  decision: **no hard-coded dates in detection logic** — a stale ticket is
  identified as the same-name pair member whose price does NOT match the
  current TEC product `_price` (fallback: earlier `created_at`). Canonical
  event selection mirrors the existing `get_tec_import_source_mapping()`
  resolver so Auto-Match and the merge agree. Divergent `start_timestamp`
  groups and any ticket with sales/order rows are excluded from automation
  and surfaced as review items only.

- 2026-08-23: **Dev deployment note.** The 1.0.0 plugin lived on dev in a
  legacy directory `wp-content/plugins/tec-to-eventkoi-tickets-importer/`
  while the zip builds as `eventkoi-tickets-importer/`. `wp plugin install
  --force --activate` fataled on class redeclaration with both present;
  resolved by deactivating the legacy plugin, activating the new one, and
  deleting the legacy directory. Dev now runs 1.1.0 from
  `wp-content/plugins/eventkoi-tickets-importer/`.

- 2026-08-23: **Pre-existing orphan rows discovered during verification.**
  38 ticket rows and 2 `hold`-status test `ticket_orders` rows reference
  event IDs with NO post at all — victims of the earlier manual
  "pre-delete duplicates" approach (posts hard-deleted, tickets left
  behind). These predate this cleanup and are unrecoverable (nothing to
  repoint to, nothing to untrash). Left in place; flagged in Next action.

- 2026-08-23: **Step 7 — separate sync audit ledger (deviation from the
  original plan).** The approved plan recorded sync ops in
  `ekti_cleanup_audit` so "Undo Last Cleanup" would reverse the sync. But
  that ledger still holds the verified Step 6 ops (945 `insert_ticket` +
  151 `untrash_post`), so a shared undo would also reinsert the stale
  tickets and untrash the merged events. Implemented instead as a separate
  `ekti_sync_audit` option with its own `undo_sync()` and an "Undo Last
  Sync" button; the replay mechanism is shared (`replay_audit_ops()`).

- 2026-08-23: **Multi-ticket verification (user-requested).** All 33
  multi-product TEC events were audited before implementation: 33/33
  distinct product names (e.g. "1 House 1 Decorator" vs "1 House 2
  Decorators"; Pan Dulce Bundle/Early Bird/VIP), 26/33 distinct prices,
  0 identical pairs, one 3-product event. The Step 6 ledger confirms all
  945 deleted tickets were named "General admission" — no distinct ticket
  type was ever deleted; they simply never existed in EventKoi (native
  importer only created a default placeholder per event).

- **No REST API:** the plugin uses `admin-ajax.php` rather than WP REST. This
  is deliberate for a migration tool — all operations are admin-initiated,
  the data flow is unidirectional (TEC → EventKoi), and there's no external
  consumer for these endpoints. REST would add complexity (route registration,
  schema validation, permission callbacks) with no benefit.

- **jQuery over React:** the admin UI is simple enough (four panels, a table,
  progress bar, console) that a React build pipeline would be overkill. No
  `@wordpress/scripts`, no `npm install`, no `build/` directory — the plugin
  ships as-is.

- **GPL-2.0-or-later** (not 3.0): matches the WordPress plugin directory's
  default license preference. The reference project (`simple-performance-for-wordpress`)
  uses GPL-3.0-or-later; this project deliberately chose GPL-2.0-or-later for
  broader compatibility with The Events Calendar's own GPL-2.0 licensing.

---

## Open questions / blockers

- **Uninstall cleanup:** no `uninstall.php` exists. The plugin creates several
  options (`ekti_event_mapping`, `ekti_migration_state`, `_ekti_tec_product_*`)
  and post meta (`_eventkoi_imported_from_tec`) that should be cleaned up on
  plugin deletion. Low priority since these are inert when the plugin is
  inactive.
- **No i18n:** all strings are hard-coded in English. A `.pot` file and
  `load_plugin_textdomain()` call would be needed for translation.
- **Log file in uploads:** the log file lives at
  `wp-content/uploads/eventkoi-import.log` which is web-accessible. Consider
  adding an `.htaccess` deny or moving to a non-public path.
- ~~**WooCommerce dependency**~~: resolved in Phase 2. All `wc_get_order()`
  call sites are now wrapped in `wc_is_available()` / `get_wc_order()` which
  use `function_exists('wc_get_order') && class_exists('WooCommerce')`. The
  importer degrades gracefully when WC is absent: the admin page shows a
  warning notice, parent orders and charges are skipped, and ticket_orders
  rows fall back to the legacy `tec_import_{id}` key.

---

## Update protocol (every build session, read this)

When you finish a step (or stop partway), before ending your turn you MUST:

1. Flip that step's **Status** in the Progress table (🟡 while working, ✅ when its
   acceptance criteria pass) and fill in the short commit hash. Also mark the
   matching `### Step N` heading above with ✅ (or leave unmarked/🟡 if paused).
2. Update **Overall status**, **Last updated** (today's date), and **Next action**.
3. Append any surprises to **Decisions & deviations log** and any unresolved items to
   **Open questions / blockers**.
4. Commit STATE.md **in the same commit** as the step's code so state never drifts
   from the tree, then push.

Do not mark a step ✅ unless its acceptance criteria (in the step's spec file, or the
condensed instructions above) are actually met. If you stop mid-step, leave it 🟡
and note exactly where you paused under Decisions & deviations so the next session
can resume cleanly.
