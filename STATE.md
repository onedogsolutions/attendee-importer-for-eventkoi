# Build State — EventKoi Tickets Importer

**Single source of truth for build progress AND the step-by-step implementation
plan.** Every build session MUST update this file as its final action (see "Update
protocol" below). Read this first before starting any step. This file inlines the
condensed version of each build step so progress and plan travel together in one
top-level document.

- **Branch:** `main`
- **Plugin version:** 1.11.1
- **Last updated:** 2026-10-19
- **Overall status:** ✅ **v1.11.1 legacy Advanced tools removed and
  QA-passed on ott-dev (2026-10-19).** The wizard is the only import
  path. A full wizard run on 1.11.1 from a restored pre-import backup
  passed every results check (656 events, 686 tickets, 2,299 WC-linked
  rows, 2,081 sold). See Step 19. Run Migration,
  Event Mapping, Pre-Import Cleanup, Ticket Details Sync, legacy Calendar
  Sync and the single-pass Event Sync runner are gone; the wizard is the
  only import path. See Step 19. Before that: ✅ **v1.11.0 EventKoi setup shipped and QA-passed on
  ott-dev (2026-10-19).** Preflight now detects TEC's WooCommerce ticket
  sales and sets EventKoi's ticket checkout method to WooCommerce, and it
  saves the calendar defaults from EventKoi's onboarding wizard (without
  its demo event). This fixes EventKoi's events list showing "0 tickets"
  for imported sales. See Step 18. Before that: **v1.10.0 attendee fidelity
  (2026-10-19).** Imported attendees now carry EventKoi's event
  instance, so the storefront counts imported sales. Seat holders and
  TEC registration answers appear as EventKoi checkout fields in the
  attendee export. Builds on the v1.9.0 Import Wizard. The Tools page now leads with a 6-step wizard (Preflight →
  Locations & Instructors → Events → Tickets → Order matching → Finish)
  with review stops after events, tickets and orders. The importer can
  now create EventKoi's Locations (Saved Locations) and Instructors
  taxonomies itself and match TEC venues/organizers to terms before any
  event exists. Staged run on dev reproduced the Step 15 acceptance
  numbers exactly; per-stage and full undo restore the baseline. See
  Step 16.

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
  ID), `ekti_event_sync_audit` (undo ledger, wizard ops tagged by stage), per-attendee
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
| 7 | Ticket Details Sync (sale windows, capacity, multi-product reconciliation) | ✅ Done | eb57243 |
| 8 | Calendar Sync (TEC locations → EventKoi calendars mapper) | ✅ Done | bfed7b7 |
| 9 | Orphan skip (v1.4.0) + attendee idempotency (v1.4.1) + WC meta fix (v1.4.2) + live import on dev | ✅ Done | — |
| 10 | Taxonomy Sync — Locations (TEC venues → EventKoi `locations` taxonomy) | ✅ Done | — |
| 11 | Taxonomy Sync — Instructors (TEC organizers → EventKoi `instructors` taxonomy) | ✅ Done | — |
| 12 | Organizer custom field cleanup (backup + remove native-import Organizer field group) | ✅ Done | — |
| 13 | Saved Locations term field sync (TEC venue addresses → builder term fields, overwrite + undo) | ✅ Done | — |
| 14 | Event Sync (TEC events → EventKoi events — native-importer port, Critical-Error fix, chunked runner, adoption + undo) | ✅ Done | — |
| 15 | Ticket data parity rewrite (native ticket/capacity model, inline WC-linked attendee import, status-aware rows, shared writer for Run Migration) | ✅ Done | — |
| 16 | One-shot Import Wizard (staged events → tickets → orders with 3 review stops) + taxonomy provisioning from the UI | ✅ Done | — |
| 17 | Attendee fidelity: instance-tagged rows (storefront inventory), per-seat attendee identity, TEC registration fields | ✅ Done | — |
| 18 | EventKoi setup in Preflight: WooCommerce checkout method from TEC sales, onboarding calendar defaults (no demo data), stage guard, events-list count check | ✅ Done | — |
| 19 | Remove pre-wizard import paths from Advanced tools (v1.11.1) | ✅ Done | 30d7cd1 |

Status legend: ⬜ Not started · 🟡 In progress · ✅ Done · ⚠️ Blocked

## Next action

**Step 19 done (2026-10-19, v1.11.1).** The user restored ott-dev to a
pre-import backup, then v1.11.1 was deployed and the full wizard was run
through the UI. ott-dev is now fully imported again with all 5 steps
approved: 656 events, 686 tickets, 2,299 WC-linked rows, 2,081 sold,
1,590 linked orders, 3 location and 32 instructor terms, checkout method
WooCommerce. (The backup has 691 TEC events and 2,299 Woo attendees,
against 693 and 2,306 before, so Steps 16–18 figures are slightly
higher.)

1. **Attendee Fields plugin installed** (Order matching → Install
   Attendee Fields, 2026-10-19): `mu-plugins/ekti-attendee-fields.php`
   with 6 fields (Attendees, Phone, Zip/Post Code, class-before,
   Consent, Comments), 0 rows needing repair. The re-run results check
   shows "installed" and "Checks passed".
2. TEC event cleaner on ott-dev is back at 12 months (Preflight warns).
3. The v1.7.0 plugin folder is backed up at
   `wp-content/ekti-backup-1.7.0` on ott-dev; delete it once 1.11.1 is
   accepted.
4. Branch `claude/intelligent-hertz-8fb9b8` is not merged to `main` yet.

**Step 18 done (2026-10-19, v1.11.0):** ott-dev's EventKoi checkout
method is now WooCommerce and the events list shows imported sales
(event 54644: 1 sold; 328/328 events match). UI click-through passed.

1. **Production:** run Preflight first; approving step 1 saves the setup
   before any tickets or orders are imported.

**Step 17 done (2026-10-19, v1.10.0, option B2):** dev is fully
imported with the v1.10 writer: orders stage re-run, all checks pass,
and the attendee fields mu-plugin is installed. For production, run the
wizard as-is; for a site imported with v1.8–v1.9, use "Update Existing
Attendees" in the Order matching step, then "Install Attendee Fields".

**Step 16 (2026-10-19):** dev is left in the fully imported state from
the UI wizard run (all 5 steps approved): 658 events, 688 tickets, 2,306
WC-linked rows, 2,088 sold, 3 location + 32 instructor terms, Saved
Locations active.

1. **"Undo Entire Import" was not clicked in the UI.** The same sequence
   passed in backend QA. Run it from the Finish step if dev needs
   resetting (budget about 6 minutes).
2. **TEC cleaner on dev is back at 12 months** (`trash-past-events` =
   12). Preflight flags it as a warning. Raise it to 24 (or off) before
   relying on dev data long-term.
3. Earlier items below still apply.

**Step 15 QA complete (2026-10-18) — results under Step 15 below.** Dev is
left in the fully synced v1.8.0 state.

1. **Status-sync QA (not run):** change a WC order to refunded/cancelled on
   a test order and confirm EventKoi's `on_order_status_changed` /
   `on_order_refunded` update the imported rows and recount. Not run on dev
   because a status change on a real customer order can send WC emails —
   use a throwaway test order.
2. **Admin UI pass:** run Event Sync / Run Migration / Rollback from the
   Tools page once (QA drove the private methods via reflection; the JS
   output lines for Woo attendees are new).
3. **Re-run Taxonomy Sync** (Locations, Instructors, Saved Locations
   fields) — the reset dev EventKoi has no builder taxonomies configured
   yet, so 0 events carry location/instructor terms.
4. **Send the upstream bug report to EventKoi** (Step 14 draft), plus
   Step 15 note: native `import_event_attendees()` imports refunded /
   failed WooCommerce attendees as `complete` manual orders.
5. **Production cutover prep** — readme/changelog, uninstall cleanup
   (new options: `ekti_migration_audit`; WC meta `_ekti_wc_linked`), then
   the prod checklist. Pipeline order on prod: Event Sync (now brings
   attendees) → Adopt Native Imports (if native imports exist) →
   Auto-Match → Taxonomy Syncs → Run Migration (only needed for mapped
   events Event Sync did not create).
6. `ott-dev.py`'s stored application password is stale (HTTP 401) — use
   the Novamira MCP connection, or refresh the credential.

- **Verification results:** all 2421 order_ids distinct, 0 rows outside
  the mapping, 0 null ticket_ids, 0 orphan tickets, all `quantity_sold`
  values match, checked_in 1/1 matches TEC, `_eventkoi_imported_from_tec`
  flags on all 2421, per-event live comparison 0 mismatches, inventory
  snapshot comparison 0 drift. The 5 mapped events absent from the
  snapshot (45355, 45306, 47077, 49647, 49187) are the restored-from-
  trash events carrying 22 attendees — reconciling 2399 + 22 + 856
  orphans = 3277 total.
- **Dev server state (post Step 14 QA, 2026-10-18):** EventKoi v1.4.7.0;
  importer v1.7.0 ACTIVE (deployed QA build = rebuilt zip). Event Sync
  applied: 662 EventKoi events (626 ours + 36 native; 623 publish /
  39 draft), 662 `_tec_import_source_id` markers, 692 tickets (652 ours
  + 40 native), 9 rsvps rows (2 duplicate emails merged), 652
  `_ekti_tec_product_*` bindings, `ekti_event_mapping` 626 entries (ours
  only — the 36 native imports are marker-mapped but not in the option),
  48 adopted native attendee flags, 2,620-op `ekti_event_sync_audit`
  ledger, 19 calendars (7 created by sync), upcoming_index 662 rows.
  Run Migration left at its baseline (48 manual ticket_orders / 102
  manual orders / 204 notes / 0 charges) — its QA artifacts were rolled
  back after each interop test. 0 events carry location/instructor
  terms (the v1.5.0 assignments went with the pre-cleanup event
  population — re-run Taxonomy Sync to repopulate). TEC side unchanged:
  744 posts, 3522 published wooticket attendees, 281 orphan TC
  attendees (all TC source events were permanently deleted by the old
  cleanup crons — report-only), 35 events without `_EventStartDate`
  (sync errors, retried on each full run via the UI reset flag).
  EventKoi's native TEC importer remains broken on dev data
  (Critical Error, see Step 14) — our Event Sync is the working
  replacement. `trash-past-events` 24 months; 333 native-importer clone
  events trashed (2026-08-29, reversible ledger
  `ekti_event_dedupe_audit`); Saved Locations designation active on the
  `locations` builder taxonomy (8/8 slots mapped).
- **5 of 419 mapping targets are DRAFT events** (Buche De Noel, Kids
  Class: Holiday Poptarts, Family Day Daddy Daughter Valentines, 2× Pan
  Dulce Workshop Series II) — the EventKoi counterparts of the 5
  restored-from-trash TEC events, holding the 22 attendees outside the
  inventory snapshot. Publishing them is a content decision, not a
  data-integrity issue.
- **32 WC orders span multiple TEC events** (legacy artifact): parent
  order + WC meta carry the first event; each ticket_orders row keeps
  its attendee's true event (EventKoi's native model is one event per
  order).
- **Production cutover checklist addition:** TEC's cleaner settings are
  per-site — verify on prod that `trash-past-events` ≥ 24 (or disabled)
  and `delete-past-events` is absent/off BEFORE running any import
  there. The 24-month window starts eating the oldest events
  (~Aug 2027 on dev); the same applies to prod on its own clock.
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
  untrash). ~~Decide whether to purge these manually before/after
  import.~~ RESOLVED: the 2026-10-18 pre-Step-14 cleanup removed them;
  verified post-QA — 0 orphan tickets, 0 hold orders, and the 40 native
  tickets all attach to live native events.

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

### Step 8 — Calendar Sync (TEC locations → EventKoi calendars) ✅
Translates The Events Calendar **locations** into EventKoi **calendars**.
Discovery on dev (via ott-dev): TEC stores locations as `tribe_venue`
CPT posts linked by `_EventVenueID` event meta (3 venues: Austin,
San Antonio – Central, The Bake Lab; 412/419 mapped events carry the
meta, the other 7 still have the location name in EventKoi's `location`
post meta from the native import). EventKoi calendars are `event_cal`
taxonomy terms; events hold multiple calendars (category calendars were
already synced by EventKoi's native importer — names AND assignments
match the 19 `tribe_events_cat` terms), but the 3 location calendars
existed with ZERO assignments. Assignment is **append-only union**
(never removes existing calendars), idempotent, and audited.

New backend methods on the singleton class:
- `normalize_location_name()` — entity-decodes, unifies en/em dashes,
  lowercases, collapses to a `sanitize_key()`-safe slug key. Single
  normalizer for both location keys and calendar-name comparison
  ("San Antonio &#8211; Central" → `san-antonio-central`).
- `get_tec_locations()` — distinct locations across `ekti_event_mapping`:
  venue title via `_EventVenueID`, fallback EK `location` meta `name`.
  Returns key → [ name, venue_id, events (tec_id → ek_id) ].
- `get_eventkoi_calendars()` — all `event_cal` terms sorted by name.
- `auto_map_locations()` — priority: exact normalized match →
  containment (longest shared name) → `similar_text()` ≥ 85% (same
  threshold as event fuzzy match). Per-location suggestion +
  `match_source` (exact/fuzzy/none).
- `scan_calendar_sync()` — read-only preview: locations with auto-map
  suggestions (saved mapping wins over suggestion), per-location pending
  counts (unique EK events lacking the target term), summary counts.
- `create_eventkoi_calendar()` — case-insensitive dedup against existing
  terms, else `wp_insert_term()` + copies EventKoi's display term-meta
  set from a template calendar (first `event_cal` term with a `display`
  meta key); audits a `create_term` op.
- `run_calendar_sync($mapping, $chunk=100)` — resolves `new:<name>`
  targets first (audited), persists the resolved mapping, then
  union-appends each target term via `wp_set_object_terms( ..., true )`
  (same pattern as `merge_loser_into()`'s calendar union). Dedupes by
  ek_id+term_id, skips already-assigned events, audits each write as the
  existing `set_terms` op. Chunked with an applied/errors/done protocol.
- `undo_calendar_sync()` — replays the separate `ekti_cal_sync_audit`
  ledger (same rationale as Step 7's separate `ekti_sync_audit`).
- `replay_audit_ops()` gained the `create_term` case → `wp_delete_term()`.
- `get_saved_location_mapping()` / `save_location_mapping()` /
  `sanitize_location_mapping()` — `ekti_location_mapping` option
  (location key → int term_id, or `new:<name>`).
- AJAX: `ajax_scan_calendar_sync`, `ajax_save_location_mapping`,
  `ajax_run_calendar_sync` (request mapping payload wins, else saved),
  `ajax_undo_calendar_sync` — all nonce + `manage_options` gated.

New admin panel **"Calendar Sync (Locations)"** (seventh panel): Scan
renders one row per location (name + source, mapped-event count,
auto-match badge ✓ exact / ~ fuzzy, calendar `<select>` with all
calendars + "— None —" + "➕ Create new calendar…" revealing a name
input); Save Mapping / Run Calendar Sync (JS confirm) / Undo Last
Calendar Sync. `admin.js` gained `scanCalendarSync()` / `collectCalMapping()`
/ `saveCalMapping()` / `loopCalSync()` (with a no-progress stop guard) /
`runCalSync()` / `undoCalSync()`. Version bumped to 1.3.0.

**Dev execution results (2026-08-23):** Scan found exactly 3 locations
(419 mapped events): Austin → "Austin" exact, The Bake Lab → "The Bake
Lab" exact, San Antonio – Central → "San Antonio" fuzzy; 414 assignments
pending. Sync ran in 5 chunks of ≤100: 414 applied, 0 errors; calendar
relationship counts Austin 209, San Antonio 156, The Bake Lab 49
(term `count` shows 47/… because it only counts published posts).
Spot-checked mapped events keep their category calendars AND gain the
location calendar; unmapped EK events are untouched. Re-run applied 0
(idempotent); re-scan reports 0 pending. Undo replayed all 414
`set_terms` ops restoring exact prior term sets (counts back to 0).
Create-new QA: mapped a location to a fresh "EKTI QA Test Calendar" —
term created with the full 16-key display term-meta set copied from the
template, 49 events assigned (47 publish + 2 draft relationships),
undo deleted the term and restored every event (0 leftover
relationships). QA calendar removed; production sync re-applied — dev
left in the synced state.

### Step 13 — Saved Locations term field sync (v1.6.0) ✅

EventKoi v1.4.6 shipped the Taxonomy Builder + Saved Locations
designation: `locations` is a builder taxonomy whose term fields
(`eventkoi_field_*` term meta) map to address slots via
`eventkoi_location_taxonomy_map`, and `Event::get_locations()` resolves
an event's address from its assigned term (mode `taxonomy` by default;
legacy `location` post meta kept as manual-mode fallback). The importer
now copies TEC venue addresses onto those term fields:

- **Detection (option reads only, no EventKoi class dependency):**
  `get_location_taxonomy_slug()` follows the `eventkoi_location_taxonomy`
  designation (validated against `eventkoi_event`) with a stock
  `locations` fallback; `get_saved_location_field_map()` returns the
  slot→field map + field definitions; `get_venue_address_data()` builds
  the slot-keyed venue payload (state via `_VenueStateProvince` →
  `_VenueState`); `split_venue_address()` puts a trailing unit segment
  ("Ste 203", "#102", …) on address line 2.
- **Writer:** `sync_location_term_fields()` — fill-empty by default
  (empty TEC values never write or clear), overwrite as an explicit
  checkbox; every write audited as a `set_termmeta` op (`old` = prior
  value or null). `create_taxonomy_term()` populates a new term's
  fields from its venue and no longer copies `eventkoi_field_*` meta
  from the template term (a new location must not inherit another
  location's address).
- **Scan:** `saved_locations` block (designation state + setup warning,
  slot map, field defs), per-source address analysis (pending fills +
  conflicts with current→proposed per slot), counts
  `field_updates`/`field_conflicts`, and a report-only count of events
  still carrying legacy `location` meta (no cleanup by design —
  EventKoi keeps it as manual-mode fallback and term rows win).
- **Run:** `run_taxonomy_sync(…, $overwrite, $sync_fields)` applies the
  field sync once per mapped term on the first chunk (JS stops sending
  `sync_fields` afterwards, so overwrite never logs duplicate ops);
  created terms get their fields at creation time and those writes are
  surfaced in the results/console (`→ term #X: wrote N address
  field(s)`).
- **Undo:** `replay_audit_ops()` gained the `set_termmeta` case —
  `old === null` deletes the meta, otherwise restores the exact prior
  value; reverse replay order restores fields before `create_term`
  deletes the term.
- **Admin UI:** Locations section gained the overwrite checkbox, a
  designation status line, and an "Address Data" column (✓ complete /
  N fields to fill / M conflicts — review, with a slot-diff tooltip);
  the run confirm dialog mentions field writes and completion counts
  include them.

**Dev QA results (2026-10-14, importer v1.6.0 / EventKoi v1.4.6.1):**
Scan: designation active, 8/8 slots mapped, 0 pending field writes, 11
review-only conflicts (Austin zip 78757 vs 78758, "#203" vs "Ste 203",
"Texas" vs "TX", San Antonio + Bake Lab longitude/address variants),
659 events with legacy meta reported. Create-with-fields: "QA Test
Venue" created with 7 TEC fields; `Event::get_locations()` returned the
term-derived row alongside the existing term; undo deleted the term and
replayed all 7 `set_termmeta` ops. Fill-empty: blanked city → exactly 1
refill, second run wrote 0 (idempotent); undo deleted all 8 metas
(old=null branch proven on a live term). Overwrite: bogus city → TEC
value written with `old:"BOGUS CITY"` recorded; undo restored the exact
prior value (old≠null branch). Final restore run re-applied all 658
assignments with 0 field writes / 11 conflicts skipped; term meta,
ledger, and scan output verified identical to the pre-QA baseline; QA
terms removed; importer deactivated (pre-test state).

### Step 14 — Event Sync (TEC events → EventKoi events, v1.7.0) 🟡

Imports TEC events into EventKoi — a port of EventKoi's native
`TEC_Importer` pipeline (v1.4.7.0) that fixes the Critical Error blocking
the native importer on this dataset. **Root cause:**
`import_native_rsvp_attendees()` inserts one `wp_eventkoi_rsvps` row per
TEC RSVP attendee; events with duplicate attendee emails violate the
`event_instance_email_uniq (event_id, instance_ts, email)` key, and
EventKoi's StellarWP wrapper throws `DatabaseQueryException` on any SQL
error (native's `$duplicate++` false-return branch is unreachable) —
`run_import()` has no try/catch → uncaught fatal → REST 500. Compounding
native bugs: the `_tec_import_source_id` marker is written AFTER attendee
import (each failed run strands an unmarked duplicate draft), no
per-event isolation (one bad event kills the whole run), and one
synchronous unchunked request for all ~661 events (max_execution_time=30).

**Implementation** (single-file plugin + admin.js, ~+1000 lines):

- **Ported pipeline** (`es_*`-prefixed helpers + `*_sync` writers, each
  with a provenance comment): dates/times/timezones/all-day (inclusive
  last day pinned to 23:59:59), multi-day spans → `continuous`/
  `selected` standard_type + event_days rows, occurrence-days fallback
  (`wp_tec_occurrences`), recurrence-rule conversion (TEC Pro), venue →
  locations array (address3 composite, gmap_link, `_EventShowMap` →
  embed_gmap), Virtual Events URL → virtual location, attendance-mode
  inference, wpautop description + organizer/cost tail, categories →
  `event_cal` terms with color backfill + default-calendar fallback,
  featured-image reuse (attachment ID, no sideload), event-website URL
  custom field, `Upcoming_Index::rebuild_event()`, `ekti_event_mapping`
  update so Auto-Match/Taxonomy Sync/Run Migration work unchanged.
- **Deliberate divergences from native** (each tied to an existing step):
  `quantity_available` = FULL TEC capacity and `quantity_sold` pre-set
  from TEC `total_sales` (Step 7 model — native stores capacity-minus-
  sold and gives seats back after attendee import); no `organizer_*`
  postmeta (Step 12 removed them; Step 11 Instructors taxonomy owns
  organizers); no custom-field conversion (scan reports the count only);
  WooCommerce attendees NOT imported here — Run Migration owns them via
  the `_ekti_tec_product_{tec}_{product}` bindings this sync writes.
  Tickets Commerce attendees ARE imported as zero-value manual orders
  via the public static `Ticket_Order_Sync::create_manual_order()`
  (native parity; TC has no Woo order to link).
- **Critical-Error hardening:** RSVP rows deduped by email per
  event+instance BEFORE insert (duplicates counted + reported, never
  inserted twice; inserts use `$wpdb`, which cannot throw); dedup marker
  written immediately after `wp_insert_post` (never strands an unmarked
  duplicate draft); per-event try/catch with op-replay rollback (one bad
  event becomes a review item, run continues); chunked resumable runner
  — 25 events/chunk with an 18s time budget, pending queue re-derived
  per call (`run_event_sync()`); optional explicit-ID targeted runs
  (native `event_ids` parity, used by QA for TEC 52802).
- **Audit/undo:** separate `ekti_event_sync_audit` ledger (same rationale
  as Steps 7/8); `replay_audit_ops()` gained 5 op types — `insert_post`
  (hard `wp_delete_post` + residual sweep of tickets / ticket_orders /
  rsvps / upcoming_index rows, parent orders + order_notes keyed by
  checkout_id derived from the seat-scoped order_id prefix),
  `insert_rsvp`, `insert_ticket_order` (per-seat rows + parent order +
  notes, with EventKoi-semantics quantity_sold recount), `insert_field`,
  `insert_field_group`.
- **Native-import adoption:** pre-existing natively-imported events (36
  on dev) whose EventKoi copies already carry ticket-order rows are
  surfaced by the scan; the audited Adopt action flags their TEC
  attendee posts `_eventkoi_imported_from_tec` so Run Migration won't
  double-import attendees EventKoi already brought over. Off by
  default, shares the event-sync ledger so undo reverts it too.
- **Scan (`scan_event_sync()`)**: total/imported/unimported + by-status
  counts, per-provider ticket + attendee counts (Woo/TC/RSVP with
  unique-email math), currency mismatch flags, recurring/all-day/
  virtual/image/custom-field profile, and review items (duplicate-email
  RSVP events — the native-fatal set, no-start-date events, orphan /
  trashed RSVP attendee sources — report only).
- **Admin panel "Event Sync (TEC → EventKoi)"** rendered directly after
  Overview (pipeline step 1): scan stat tiles + review-items table,
  Import images checkbox (default on, native parity), optional targeted
  "TEC IDs" input, Run Sync with chunked progress + per-event console
  lines (created events / tickets / TC attendees / RSVP rows / merged
  dupes / errors), Adopt Native Imports (confirm + counts), Undo Last
  Event Sync. `admin.js` gained `scanEventSync()` / `loopEventSync()` /
  `runEventSync()` / `adoptNativeImports()` / `undoEventSync()` with a
  3-chunk no-progress stall guard. CSS unchanged (existing grid/panel
  classes absorb the panel).

**Status:** ✅ shipped; `php -l` + `node --check` clean; full QA passed
on ott-dev 2026-10-18 (results below). `eventkoi-tickets-importer.zip`
rebuilt from the 1.7.0 tree — per-file identical to the QA build.

**Dev QA results (2026-10-18, importer v1.7.0 / EventKoi v1.4.7.0):**

- **Deploy:** the zip must contain the top-level
  `eventkoi-tickets-importer/` directory — a first attempt with files at
  the archive root installed as a SEPARATE plugin (class-redeclare
  fatal on activation; site stayed healthy because the fatal preceded
  the `active_plugins` write). `opcache_invalidate($file, true)` is
  required after every `wp plugin install --force --activate`.
- **Scan (pre-run):** 661 unimported / 36 native; RSVP 11 rows → 9
  unique emails across 4 importable events with 2 duplicate-email
  review items (52802: 3→2, 52812: 4→3; 52766/52806 clean); 3 orphan
  RSVP sources; all TC data orphaned — the 104 "TC events" are deleted
  post IDs referenced by 281 orphan `tec_tc_attendee` rows (old
  cleanup-cron deletions; report-only, hence tc_attendees=0 in runs);
  68 drafts included; 0 currency mismatch; 0 recurring/virtual.
- **Targeted run TEC 52802** (the deterministic native-fatal event):
  imported cleanly — 2 RSVP rows, 1 merged duplicate. The Critical
  Error is resolved on our side.
- **Full run:** 27 chunks, 626 imported, 35 errors (all "no start date"
  → failure memory, retried on the next full run via the UI's reset
  flag), 9 RSVP rows + 2 merged, done. Totals: 662 events (623 publish
  / 39 draft), 692 tickets, 652 bindings, 626 mapping entries, 7 new
  calendars (19 total), upcoming_index 662. EventKoi SPA verified via
  REST (`/eventkoi/v1/events` returns all 662 well-formed; counts match
  DB); field-by-field spot-checks vs native imports passed (locations
  array w/ gmap + lat/lng, UTC dates, timezone, attendance mode, ticket
  rows with sale windows, description tail); `event_days: []` on
  occurrence-less events confirmed native-parity (line-identical port).
- **Run Migration interop:** batch at attendee 47044 (TEC 43148 → EK
  54078): 30 attendees created / 21 orders + charges, **tickets_created
  0** — every attendee bound to Event Sync tickets via
  `_ekti_tec_product_*`; recount landed quantity_sold on TEC
  total_sales (ticket 721: 1).
- **Undo:** all 2,572 ledger ops replayed in reverse in ~80 s → exact
  baseline restored (36 events / 36 markers / 0 mapping / 40 tickets /
  48 manual orders / 0 rsvps / 102 orders / 204 notes / 36
  upcoming_index / 12 terms).
- **Idempotency:** full re-run on the fixed build reproduced identical
  tallies (626/35/9+2); a subsequent run processed 0 with every tally 0
  and state unchanged (662/662/692/9/652).
- **rollback_import scoping fix (bug found during QA, fixed in-build):**
  the original blanket rollback deleted every `_ekti_tec_product_%`-
  bound ticket — all 652 Event Sync tickets — and wiped every
  `_eventkoi_imported_from_tec` flag plus all WC `_eventkoi_*` meta.
  Fixed with two tracking options (`ekti_migration_created_tickets`,
  `ekti_migration_flagged_posts`) and `_eventkoi_synced`-only WC-meta
  cleanup; also fixed `ek_ticket_order_id` result reporting (stale
  `$wpdb->insert_id`). Re-tested: rollback of the interop batch deleted
  exactly 30 ticket_orders / 21 orders / 21 charges / 21 notes / **0
  tickets** — 692 tickets, 652 bindings, and all Event Sync state
  untouched.
- **Adopt Native Imports:** scan found 14 native events with 48
  unflagged woo attendees; Adopt flagged all 48 (48 audited
  `update_postmeta` ops appended to the event-sync ledger — 2,620 ops
  total; re-scan 0). A migration batch over an adopted attendee (with
  the event mapped) skipped it: "Already imported (attendee-level
  flag)"; adopted flags survive rollback_import (only migration-tracked
  flags are cleared). Natively-imported events are not in
  `ekti_event_mapping`, so unmapped their attendees skip at the mapping
  check instead — either guard prevents double-import.

**Upstream bug report to EventKoi (draft — send via EventKoi support):**

EventKoi v1.4.7.0, `includes/importers/class-tec-importer.php`:

1. **Uncaught `DatabaseQueryException` on duplicate RSVP attendee
   emails (fatal).** `import_native_rsvp_attendees()` (~line 1561)
   inserts one `wp_eventkoi_rsvps` row per TEC RSVP attendee; a
   duplicate email (double submission) violates
   `event_instance_email_uniq (event_id, instance_ts, email)`, and the
   StellarWP wrapper (`runQueryWithErrorChecking`, DB.php:289) throws —
   the `$duplicate++` false-return branch is unreachable.
   `run_import()` has no try/catch → REST 500 "There has been a
   critical error on this website." Repro: any event with two RSVP rows
   sharing an email; the run's first such event kills every attempt.
   Fix: dedupe by email per event+instance before insert (count
   duplicates) or catch the exception and treat it as a duplicate.
2. **`_tec_import_source_id` written after attendee import** (~line
   518) — any mid-event failure strands an unmarked duplicate draft
   event post plus partial ticket/RSVP rows; every retry duplicates.
   Fix: write the marker immediately after `wp_insert_post()`.
3. **No per-event isolation** — one bad event aborts the whole run.
4. **Single synchronous request for all events** (~661 × 0.2–0.5 s ≫
   `max_execution_time`) — chunked/resumable batches recommended.

---

### Step 15 — Ticket data parity rewrite (v1.8.0) ✅

**Problem:** after Step 14 (our Event Sync replacing EventKoi's native TEC
importer) EventKoi ticket data no longer matches TEC. Under the old
pipeline (native importer → Run Migration) it did. Investigated on
ott-dev 2026-10-18. The dev EventKoi side had been reset to empty
(EventKoi 1.4.7.0 tables at AUTO_INCREMENT 1, no builder taxonomies), and
TEC/Woo was intact: 693 live events, 697 live Woo ticket products,
2,306 live attendees (2,088 completed / 215 refunded / 3 failed orders),
692 events on shared (global) stock.

**Root causes (measured on live events):**

1. **Refunded / failed seats imported as sold.** `process_batch()` writes
   every `tribe_wooticket` as `payment_status='complete'` regardless of
   the WC order status → 218 non-paid seats counted in `quantity_sold`
   (122 tickets affected). It also writes a `succeeded` `eventkoi_charges`
   row for refunded orders, inflating EventKoi's Stats (which read the
   charges table).
2. **`quantity_sold` preset from WC `total_sales` cannot hold.** Event
   Sync presets it; EventKoi (and our own batch recount) recounts from
   `ticket_orders` rows on every write, so the value flips. `total_sales`
   ≠ paid-attendee count for 139/697 products (247 sold seats with no
   attendee post; WC also never decrements `total_sales` on refund).
3. **Capacity model diverged from native.** Step 14 stores full capacity
   and no event-capacity deduction. Native stores
   `capacity − total_sales`, then gives back one seat per imported
   attendee. Seats sold in TEC that have no attendee row (the 247) stay
   deducted, so EventKoi's remaining matches TEC's. Same for the per-event
   shared cap (`_tribe_ticket_global_stock_level`).
4. **Attendees imported outside the event import, in a non-native
   shape.** Native imports attendees inside `import_single_event()`
   (tickets → attendees → give-back). We defer to Run Migration, which
   hardcodes USD, generates random tokens (the v1.4.1 doubling root
   cause), omits `'paid'` from the recount IN-list, names fallback tickets
   from `_tribe_deleted_product_name` only, and writes parent
   `eventkoi_orders` + `eventkoi_charges` rows. EventKoi's own WC checkout
   never writes those: it lists WC orders by grouping `ticket_orders` on
   the `wc_{id}` base, and its charges table is documented as Stripe-only.

**Plan (single-file plugin + admin.js, version 1.8.0):**

- **A. Native ticket model in Event Sync.** `insert_imported_ticket_sync()`
  goes back to native exactly (`quantity_available = max(cap − total_sales, 0)`,
  `quantity_sold` left to the recount), records `deducted`. New
  `give_back_imported_capacity_sync()` is a port of native
  `give_back_imported_attendee_capacity()`. Seats given back = rows
  imported for orders that were ever paid (complete /
  partially_refunded / refunded), capped by `deducted` (native counts every
  imported attendee; pending/failed seats never entered `total_sales`).
  Event cap = native `global_level − sold_total` + the same give-back.
- **B. One shared WC attendee writer** `link_woo_attendees()` used by both
  Event Sync (inline, native order) and Run Migration:
  - one `ticket_orders` row per attendee in the exact column set of
    `Ticket_Order_Sync::sync_order_to_local()`'s codes path:
    `order_id = wc_{order}:{ticket}:{token}`, quantity 1, unit/total =
    `_paid_price`, currency = WC order currency, created_at = order date
    (GMT), `charge_id`/`payment_intent_id` empty (non-Stripe parity);
  - `payment_status` from WC order status via EventKoi's own
    `on_order_status_changed()` map (completed/processing → complete,
    refunded → refunded, cancelled, failed, on-hold/pending → pending);
    completed orders with a partial refund → `partially_refunded`;
  - deterministic check-in token per attendee (EventKoi base32 alphabet,
    hash of attendee ID) → re-runs hit the same key (DB-level
    idempotency in addition to the attendee flag);
  - per-attendee name/email (TEC attendee meta, WC billing fallback, as
    native does);
  - checked-in carried over only for check-in-eligible (complete) rows;
  - recount via EventKoi's public `Ticket_Order_Sync::sync_quantity_sold()`;
  - WC order meta (`_eventkoi_event_id`, `_eventkoi_ticket_items` with
    real ticket IDs + per-seat codes, `_eventkoi_master_checkin_code`,
    `_eventkoi_synced = yes`) so EventKoi's status/refund hooks keep the
    rows in sync and never re-run `on_payment_complete()` (which would
    email customers); multi-event orders append ticket_items;
  - **no** parent `eventkoi_orders` / `eventkoi_charges` / notes rows for
    WC orders (native parity, fixes Stats inflation);
  - attendee flag `_eventkoi_imported_from_tec`; every write audited
    (`insert_ticket_order`, new `wc_order_meta` op, `update_postmeta`).
- **C. Event Sync imports Woo attendees inline** (native order), so one
  run produces the finished event. Product bindings still written for
  Ticket Details Sync / Run Migration.
- **D. Run Migration rewired onto the shared writer** (same AJAX/JS
  contract): ticket resolution = binding → native ticket name match →
  create (named from the order line item / deleted-product name). Its
  rollback replays a new `ekti_migration_audit` ledger instead of
  pattern-deletes.
- **E. Undo hardening:** the `insert_post` sweep deletes a WC base's
  parent rows only when no `ticket_orders` rows remain for it (multi-event
  orders); `wc_order_meta` restores prior values.

**Acceptance (ott-dev):** for every live Woo ticket, EventKoi
`quantity_sold` == paid attendees and remaining (`quantity_available −
sold`) == `capacity − paid − unrepresented` clamped at 0, where
unrepresented = `max(total_sales − ever-paid imported rows, 0)` (native
semantics; a refunded seat is released, matching WC's restock); refunded
/ failed rows present with their status and not counted; 0
`eventkoi_charges` rows for WC orders; idempotent re-run (0 new rows);
EventKoi REST orders list shows the imported WC orders; undo restores the
empty baseline.

**Dev QA results (2026-10-18, importer v1.8.0 / EventKoi v1.4.7.0):**

- **Deploy:** zip uploaded via `novamira/create-upload-link`,
  `wp plugin install --force --activate`, opcache invalidated, server md5
  == local build; upload removed afterwards.
- **Targeted run** (44193, 44318, 44515, 43119, 43257, 52802 — refunds,
  multi-event WC order 44624, two-product event, unrepresented seats,
  duplicate-email RSVP): 6 imported / 0 errors, 66 Woo attendees in 41
  orders, 3 refunded kept as `refunded`; every ticket sold == TEC paid,
  remaining == expected (43257: cap 18, ts 7, 4 rows → avail 15 / sold 4);
  0 charges, 0 parent orders, 0 status mismatches vs WC; order 44624's
  `_eventkoi_ticket_items` carries both events' items.
- **Run Migration interop + determinism:** deleted 43257's rows/flags,
  re-linked via `process_batch()` → the 4 recreated `order_id`s are
  byte-identical to Event Sync's (deterministic tokens). Rollback replayed
  `ekti_migration_audit` → removed exactly those 4 rows + flags, restored
  the WC meta to its pre-migration values, 0 tickets touched.
- **Undo (targeted set):** 180 ops replayed → exact empty baseline (0
  posts / rows / flags / WC meta / bindings, default calendar only).
- **Full run:** 11 calls of ≤15-event chunks; 658 imported + 35
  no-start-date errors (= all 693 live events), 688 tickets, 2,306 Woo
  rows (== live attendee count) in 1,594 linked orders, 9 RSVP rows (2
  merged), 8,329 ledger ops. Rows: 2,049 complete / 39
  partially_refunded / 215 refunded / 3 failed.
- **Acceptance:** 688/688 sold == TEC paid attendees (2,088 == 2,088);
  682/682 capacity-limited remaining == expected (6 unlimited); 0 dup
  order_ids, 0 dup tokens, 0 null tickets; 0 eventkoi_orders / charges.
  Re-run: 0 imported, 0 new rows. The single TEC checked-in attendee is on
  a permanently deleted event (orphan, correctly skipped).
- **EventKoi API:** `Tickets::get_all_orders` returns 1,594 orders;
  per-event `get_ticket_orders` (TEC 44515) returns 19 orders (18 complete
  / 1 refunded) with amounts, per-seat codes, master code, and refunded
  orders at 0 check-in eligibility. `/eventkoi/v1/orders` is the Stripe
  `eventkoi_orders` view → empty by design.

---

### Step 16 — One-shot Import Wizard + taxonomy creation (v1.9.0) ✅

**Goal:** one guided run on the Tools page takes a site from "TEC + Woo,
empty EventKoi" to a finished migration. The run stops three times for
the user to review: **events**, **tickets**, and **order matching**. The
Locations and Instructors syncs must work on a fresh EventKoi install,
which means the importer creates the taxonomies themselves (builder
definitions, address fields, Saved Locations designation and slot map),
not just the terms. Today it needs a human to set those up first in
EventKoi Settings > Taxonomies (`scan_taxonomy_sync()` returns
`ekti_no_location_taxonomy`; reset ott-dev has 0 builder taxonomies).

**Current gaps (from code review, 2026-10-18):**

- Ten stacked panels in historical build order, not pipeline order. The
  Calendar Sync, Pre-Import Cleanup, Ticket Details Sync, Event Mapping
  and Run Migration panels are now repair/legacy tools: v1.8 Event Sync
  creates mapping, tickets, sale windows and WC-linked attendees itself.
- Copy is out of date: the Event Sync description still says Woo
  attendees "stay with Run Migration", and its tile is labelled "Woo
  Attendees (deferred)". Overview tiles count Run Migration's view
  ("Attendees to Import", "WC Orders to Link"), which is ~0 after v1.8.
- `import_one_event()` does event → tickets → attendees → give-back in
  one pass per event, so there is nowhere to stop between tickets and
  orders.
- Taxonomy Sync expects the `locations` / `instructors` taxonomies to
  already exist (`get_taxonomy_for_type()`, `get_saved_location_field_map()`).
- Undo is per-ledger (`ekti_event_sync_audit`, `ekti_tax_sync_audit_*`, …).
  Nothing gives an "undo this stage" or "undo the whole wizard" action.

#### Phase 0 — ott-dev discovery ✅ (2026-10-18, EventKoi 1.4.7.0)

Reference: EventKoi doc "Turn a custom taxonomy into reusable Saved
Locations" (eventkoi.com/docs/custom-data/taxonomies/…). In EventKoi's UI
it's a three-step flow under Settings > Taxonomies: turn on "Replace
default location fields with", pick a taxonomy built for venues, then map
its fields to address details. After that, the event editor shows a term
dropdown instead of address fields. Existing events keep their built-in
address until a term is chosen. The doc says this needs EventKoi Pro, but
the classes ship in `eventkoi/eventkoi.php` 1.4.7.0 on dev.

**Current state of ott-dev:**
- `eventkoi_taxonomies`, `eventkoi_location_taxonomy` and
  `eventkoi_location_taxonomy_map` are all **absent**. Neither `locations`
  nor `instructors` is registered.
- 0 EventKoi events and an empty `ekti_event_mapping`: the EventKoi side
  was reset again.
- The **active importer is v1.7.0**, not the v1.8.0 build that was QA'd.
  Deploy the current build before any wizard QA.
- TEC data: 3 venues (Austin 38370, San Antonio - Central 38374, The Bake
  Lab 38379) and 37 published organizers.

**EventKoi API to reuse.** These are public statics, so no option writes
by hand:

| Need | Call | Notes |
|---|---|---|
| Create or update a taxonomy definition | `EventKoi\Core\Taxonomies::save( $def )` | `$def` = slug, singular, plural, hierarchical, public, show_in_menu, name_label, fields[] of {key, label, type, description, options?}. Field types: text, textarea, url, image, gmap, dropdown, checkbox, radio. Keys go through `sanitize_key`, must be unique, and are stored as `eventkoi_field_{key}` term meta. Slug rules (create only): 1–32 chars, not an existing taxonomy, not a reserved word, no rewrite collision. |
| Make it usable in the same request | `Taxonomies::register()` + `Taxonomies::invalidate_rewrites()` | `register()` skips taxonomies that already exist. EventKoi normally calls it from `Post_Types` on `init`. `invalidate_rewrites()` makes the next `init` flush rewrite rules once, so archive pages work. |
| Designate it as Saved Locations | `EventKoi\Core\Location_Taxonomy::set_slug( $slug )` | Refuses a slug that isn't in `Taxonomies::all()`. Upstream quirk: its "different slug → clear map" check runs after `update_option`, so the old map is never cleared. Always call `set_map()` right after. |
| Map address slots to fields | `Location_Taxonomy::set_map( [slot => field_key] )` | Slots: address1, address2, city, state, country, zip, latitude, longitude. Unknown keys are dropped. |
| Write term field values | `Taxonomy_Fields::save_values( $term_id, $slug, $values )` | Our `sync_location_term_fields()` writes the same meta directly and stays as-is. |
| Delete a definition (undo) | `Taxonomies::delete( $slug, $delete_terms )` | |

**Measured slug checks:** `locations` ok; `venues` ok; `instructors` ok;
`location` is **rejected** (TEC's `tribe_venue` rewrite owns it).

**How events pick their address:** an event uses its assigned term when
the designation is active and the event's `location_mode` meta is not
`manual`. If the meta is absent, the event is in taxonomy mode. Events
with no term still show their built-in `locations` meta, which Event Sync
keeps writing as the fallback, as EventKoi intends.

#### Phase 1 — Location taxonomy set-up and venue matching (backend)

This is the step where the user sets up the location taxonomy and then
matches TEC venues to it. The taxonomy doesn't exist yet, so there's
nothing to match against until it is set up.

- **`get_taxonomy_setup_state( $type )`** (read-only) reports one of
  three states:
  1. no definition yet → returns a **proposed definition**;
  2. a definition exists but isn't designated, or has unmapped slots →
     returns the gaps;
  3. ready → returns the slot map and fields.

  For locations it also lists every EventKoi builder taxonomy, so the
  user can designate an existing one instead of creating one.
- **Proposed Locations definition** (every value editable in the UI
  before saving):

  | Setting | Value |
  |---|---|
  | slug | `locations` |
  | singular / plural | Location / Locations |
  | public | on |
  | hierarchical | off |
  | fields (all `text`) | `address_1`, `address_2`, `city`, `state`, `zip`, `country`, `latitude`, `longitude`; optional `gmap` "Map" field |
  | slot map | 1:1 onto those fields → 8/8 slots mapped |

- **Proposed Instructors definition:** slug `instructors`, Instructor /
  Instructors, no fields by default. Contact fields are an open decision.
- **`provision_taxonomy( $type, $def, $slot_map, &$ops )`** does the
  writes. It records the old values of `eventkoi_taxonomies`,
  `eventkoi_location_taxonomy` and `eventkoi_location_taxonomy_map`
  (new `set_option` op; null = absent). Then it runs
  `Taxonomies::save()` → `register()` → `invalidate_rewrites()`, and for
  locations `set_slug()` → `set_map()`. If any step returns a `WP_Error`,
  it stops and shows EventKoi's message.
- **Rules for existing definitions:**
  - Reuse it.
  - Only *append* missing fields, passing the stored fields with their
    `original_key`, so EventKoi's rename/alias logic never fires.
  - Never remove or rename a field.
  - Never re-designate when a different taxonomy is already the Saved
    Locations taxonomy, unless the user explicitly picks that in the UI.
- New ledger `ekti_tax_provision_audit`, separate from the term-sync
  ledgers. Undo restores the three options exactly, deletes definitions
  this run created (`Taxonomies::delete( $slug, true )`, terms included,
  only when the ledger shows we created them), and calls
  `invalidate_rewrites()`.
- **Venue matching** reuses the existing Taxonomy Sync pieces against the
  newly provisioned taxonomy: `get_tec_location_sources()`,
  `auto_map_sources_to_terms()` (exact → containment → fuzzy ≥ 85%),
  create-new terms via `create_taxonomy_term()` (which fills address fields
  from the venue when the designation is active), and
  `sync_location_term_fields()` with fill-empty or overwrite. On a fresh
  install every venue proposes "create new" with its address preview.
- **Sources no longer depend on imported events.** Today
  `get_tec_location_sources()` walks `ekti_event_mapping`, which is empty
  on a fresh install. For the set-up step, enumerate TEC venues and
  organizers directly (event counts from `_EventVenueID` /
  `_EventOrganizerID` across importable events). Terms can then be created
  and matched **before** Event Sync runs. Assignment to events happens
  later, when the events exist.
- `scan_taxonomy_sync()` stops erroring when the taxonomy is missing.
  It returns the setup state with `provision_needed: true`.

#### Phase 2 — Staged event import (backend)

Split `import_one_event()` into three resumable stages while keeping the
Step 15 ordering semantics (tickets → event capacity → attendees →
give-back):

| Stage | Writes | Per-event marker |
|---|---|---|
| `events` | post, dates, locations array, categories → calendars, image, website field, mapping entry, upcoming index, **taxonomy assignment** (if mappings are saved) | `_tec_import_source_id` (existing) |
| `tickets` | `import_event_tickets_sync()`, cost fallback ticket, `tickets_event_capacity`, attendance mode, product bindings | `_ekti_stage_tickets` = summary JSON incl. per-ticket `deducted` |
| `orders` | `import_event_attendees_sync()` (RSVP / TC / Woo via `link_woo_attendees()`), `give_back_imported_capacity_sync()` using the persisted `deducted` | `_ekti_stage_orders` = summary |

- `run_event_sync()` gains a `$stage` argument. The pending queue for
  each stage is "previous stage marker present, own marker absent". It is
  re-derived per chunk as it is today, so every stage stays resumable
  after a PHP death.
- Upcoming-index rebuild runs again after `tickets`, because attendance
  mode changes.
- Every op is tagged `stage`. New `undo_stage( $stage )` replays only
  that stage's ops in reverse, LIFO only: tickets can't be undone while
  orders exist. The existing "Undo Last Event Sync" keeps replaying the
  whole ledger.
- **Dry-run previews**, one per review stop:
  - events: the existing `scan_event_sync()`.
  - tickets: a read-only per-event ticket plan (name, price, currency,
    capacity, `deducted`, sale window, binding). Flags multi-product
    events, price ≠ TEC current price, inverted windows, and the
    unlimited count.
  - orders: `link_woo_attendees( …, $dry_run = true )` over the pending
    events. Shows per-WC-order status mapping, multi-event orders (32 on
    dev), orphan attendees on deleted TEC events (856, report-only),
    unrepresented seats, and the expected `quantity_sold` and remaining
    per ticket.
- **Post-stage verification** (each stop also shows what was written):
  - events: created / errors (no-start-date) / drafts.
  - tickets: count == TEC live products, bindings == tickets.
  - orders: the Step 15 acceptance check run live. `sold == paid
    attendees` per ticket, remaining == expected, 0 duplicate order_ids
    or tokens, 0 `eventkoi_charges` rows for WC. Mismatches block "Finish".

#### Phase 3 — Wizard UI (admin page restructure)

New primary panel **Import Wizard** at the top. Its state is kept in
`ekti_wizard_state` (current step, completed steps, timestamps, last
verification results), so reloading the page resumes where it left off.

1. **Preflight** (auto): EventKoi / TEC / ET+ / Woo active and versions;
   TEC `trash-past-events` ≥ 24 or off, `delete-past-events` off; native
   imports present (offer Adopt); existing ledgers non-empty (warn about a
   partial previous run); log file writable.
2. **Locations & Instructors.** This step can be opened on its own from
   Advanced tools, outside the wizard. It has two sub-steps:
   - **2a. Set up the taxonomy.**
     - **Locations card:** status line (not set up / set up but not
       designated as Saved Locations / N of 8 slots mapped / ready).
       Choice: "Create a new Locations taxonomy" (prefilled, editable
       slug/labels/field list) or "Use an existing EventKoi taxonomy"
       (dropdown).
     - **Slot-mapping grid:** 8 address slots → field dropdowns, matching
       EventKoi's own settings screen.
     - **Save taxonomy** runs `provision_taxonomy()` and re-scans. A link
       to EventKoi Settings > Taxonomies is offered for anyone who prefers
       to set it up there; the card then reads the result.
     - **Instructors card:** the same, without the slot grid.
   - **2b. Match TEC venues and organizers to terms.** Uses the existing
     mapping table: TEC venue → existing term or "➕ Create new", a
     per-slot address preview (TEC value → term value, conflicts flagged),
     the fill-empty/overwrite checkbox, and auto-match badges. **Save &
     create terms** saves the mappings, creates the new terms and writes
     their address fields.
   - Undo buttons for 2a and 2b.
   - Moving to Events requires 2a = ready for Locations, and every venue
     either mapped or explicitly marked "skip".
   - Term **assignment** to events happens in the events stage.
3. **Events — review stop 1:** scan tiles + review items → **Import
   events** (chunked progress + console) → verification summary with
   links to a few sample events in EventKoi → **Approve events** /
   **Undo events**.
4. **Tickets — review stop 2:** ticket plan preview → **Import tickets**
   → verification (ticket totals, flagged rows) → **Approve tickets** /
   **Undo tickets**.
5. **Order matching — review stop 3:** dry-run linkage preview (status
   breakdown, multi-event orders, orphans) → **Link orders & attendees**
   → acceptance report → **Approve** / **Undo orders**.
6. **Finish:** summary report (counts per stage, review items carried
   forward, link to the log), the optional organizer custom-field cleanup
   (Step 12), and **Undo entire import** (stage undos in reverse, then
   taxonomy provisioning).

- Buttons for a step stay disabled until the previous step is approved.
  Each stop's tables reuse the existing renderers in `admin.js`
  (`scanEventSync`, taxonomy table builders).
- **Advanced tools** (collapsed `<details>` below the wizard): Event
  Mapping, Run Migration (for events Event Sync did not create), Ticket
  Details Sync, Pre-Import Cleanup, Adopt Native Imports, Legacy Calendar
  Sync (undo only), Import Log. The standalone Event Sync and Taxonomy
  Sync panels move here as "manual mode".
- Copy fixes: remove "deferred" and "stay with Run Migration". Retarget
  the Overview tiles to the wizard pipeline (TEC events / imported /
  tickets / linked WC orders / review items).
- `admin.js`: new `wizard` module (state load/save, step gating, one
  generic chunk-loop helper replacing the six near-identical
  `loop*()` copies; existing call sites move onto it). CSS for a
  stepper header plus approved, active and locked step states. No build
  step, same jQuery IIFE.

#### Phase 4 — QA on ott-dev (connector required)

1. Deploy the zip (top-level `eventkoi-tickets-importer/` dir), run
   `wp plugin install --force --activate`, call `opcache_invalidate`,
   check the md5 matches.
2. **Fresh-install path** (dev is already in this state as of
   2026-10-18: no taxonomy options, 0 events). Before the end-to-end run,
   test 2a on its own: create → `Location_Taxonomy::is_active()` true and
   8/8 slots; designate an existing taxonomy; undo restores the options to
   absent and removes the definition. Run the wizard end to end through the UI
   in the browser pane (not via reflection), stopping at each review.
   Expect: taxonomies created with 8/8 slots, ~3 location terms and
   ~28 instructor terms with address fields, 658 events plus 35
   no-start-date review items, 688 tickets, 2,306 Woo rows in 1,594
   orders, and the Step 15 acceptance numbers (2,088 sold == paid).
3. **Existing-definition path:** re-run with curated definitions in
   place. Expect 0 definition changes and fields only appended.
4. **Resumability:** kill a chunk mid-stage (MCP 30 s timeout) and
   resume. Expect identical totals.
5. **Undo:** run per-stage LIFO undo, then "Undo entire import". Expect
   the Phase 0 baseline exactly, including the three EventKoi options.
6. **Idempotency:** a second full wizard run creates 0 rows.
7. `php -l`, `node --check`, and an EventKoi REST spot-check of events
   and orders.

#### Out of scope

Status-sync QA on refunded orders (Next action 1), the upstream EventKoi
bug report, `uninstall.php`, and i18n. The new options (`ekti_wizard_state`,
`ekti_tax_provision_audit`) and `_ekti_stage_*` meta are added to the
uninstall list when that step lands.

#### Decisions (2026-10-19, user)

Recommended defaults taken: slug `locations`; terms are assigned during
the events stage; Instructors is name-only; no `gmap` field.

#### Implementation notes (as built)

- **Provisioning:** `provision_taxonomy()` → `Taxonomies::save()` /
  `register()` / `invalidate_rewrites()`, then for locations
  `Location_Taxonomy::set_slug()` + `set_map()`. Ledger
  `ekti_tax_provision_audit` (new ops `restore_option`,
  `provision_taxonomy`). Undo is refused while a term-sync ledger is
  non-empty, and unregisters the taxonomy for the current request.
  `get_taxonomy_setup_state()` reports missing / not_designated /
  unmapped / ready / unsupported.
- **Sources before events:** `get_tec_event_pairs()` (every importable
  TEC event → EventKoi ID or 0) feeds `get_tec_locations( true )` and
  `get_tec_organizer_sources()`. Scan and run skip ek_id 0 for
  assignment, so terms can be created first. Also fixed: the organizer
  fallback ran for every event (`$names` was never filled).
- **Stages:** `import_one_event( …, $full )` is the events stage.
  `import_event_tickets_stage()` / `import_event_orders_stage()` are the
  other two. The full single-pass path still calls all three inline.
  Stage markers: `_ekti_stage_events` / `_ekti_stage_tickets` (the
  ticket summary, including `sold_by_ticket`, persisted for the give-back)
  / `_ekti_stage_orders`. The description cost tail is decided up front
  via `es_has_et_tickets()` + `cost_ticket_spec()`. Give-back is now
  audited (`ticket_qty_add`, plus `update_postmeta` for
  `tickets_event_capacity`), so the orders stage can be undone on its own.
- **Runner / undo:** `run_event_sync( …, 'events' )` and
  `run_event_stage( 'tickets'|'orders' )`. Ops are tagged `stage` in
  `ekti_event_sync_audit`. `undo_event_stage()` is LIFO-guarded,
  time-budgeted, persists every 250 ops, and clears that step's
  approvals.
- **Review data:** `preview_ticket_stage()`, `preview_orders_stage()`
  (chunked dry run of `link_woo_attendees()`), `verify_stage()` (orders
  = the Step 15 acceptance check, live), `preflight()`.
- **Wizard state:** `ekti_wizard_state` (approved / verified per step).
  `wizard_approve()` enforces order, empty queues, and passed order
  verification.
- **UI:** the wizard panel and an Activity console sit on top. The old
  panels are under a collapsed "Advanced tools", except Taxonomy Sync,
  which now lives in wizard step 2b. The stale "Woo Attendees (deferred)"
  and "stay with Run Migration" copy is gone. New `loopChunks()` helper
  for the wizard.
  **Deviation:** the six existing `loop*()` copies were left as they are
  (not refactored onto the helper) to keep the legacy tools unchanged.

#### Dev QA results (2026-10-19, ott-dev, importer v1.9.0 / EventKoi 1.4.7.0)

Driven through the same methods the AJAX handlers call (MCP + reflection).
The browser pane needed a WordPress sign-in, so the click-through UI pass
is still open.

- **Deploy:** zip via `novamira/create-upload-link` +
  `novamira/run-wp-cli plugin install --force --activate` (the `wp`
  binary is not on PHP's PATH). md5 matched; upload removed.
- **Baseline:** 0 EventKoi events/tickets/orders, no taxonomy options.
- **Preflight:** all required checks ok. One warning: TEC
  `trash-past-events` = 12.
- **Provisioning:** Locations created and designated, 8/8 slots,
  `is_active()` true. Instructors created. Re-provision is a no-op
  (created false, 0 fields added). Undo returned all three EventKoi
  options to absent.
- **Term matching before any event exists:** 3 location terms with 23
  address fields (Austin, San Antonio - Central, The Bake Lab).
  32 instructor terms.
- **Events stage:** 658 imported + 35 no-start-date errors (= Step 15).
  656 events got a location term, 595 an instructor term, 658 indexed.
  0 tickets / 0 order rows written. Attendance mode = none until the
  tickets stage. 2,587 ledger ops.
- **Tickets stage:** preview predicted 688 types (38 multi-type events,
  6 RSVP-only, 6 unlimited, 0 inverted windows). Run produced 688/688
  rows and 688 bindings, sold 0. LIFO guard refused an events undo.
  Tickets undo (3,905 ops) restored the exact post-events state, and a
  re-run reproduced 688/688.
- **Orders preview (dry run, 0 writes):** 2,306 Woo rows (2,049 complete
  / 39 partially_refunded / 215 refunded / 3 failed), 9 RSVP rows
  (2 merged), 0 unlinkable. 51 multi-event WC orders and 1,216 orphan
  attendees, both report-only.
- **Orders stage:** 2,306 rows, 2,088 sold, 1,594 linked WC orders.
  688 Woo tickets checked with 0 mismatches (sold == sold rows, rows ==
  TEC attendees, remaining == expected). 0 duplicate order IDs or
  tokens, 0 WooCommerce charges rows. Identical to Step 15.
- **Idempotency:** a second run of every stage made 0 new events, ticket
  rows or order rows (the 35 dateless events retried and failed again).
- **Full undo:** orders (7,033 ops) → tickets (3,905) → events (2,587)
  → term matching → provisioning. Result: 0 events / tickets / rows /
  RSVPs / flags / WC meta / bindings / location+instructor terms; one
  `event_cal` term (default); taxonomy options absent; 0 orphan meta.
  Inert empty options and wizard state were cleared afterwards.
- **Speed on dev:** events ≈ 5/s, tickets ≈ 5/s, orders ≈ 3/s; undo
  ≈ 110–150 ops/s (events), ~2,800 ops per 25 s (orders).

#### UI pass (2026-10-19, browser pane, narrow ~560 px viewport)

Run end to end from a clean baseline by clicking the wizard. Native
`confirm()` dialogs were auto-accepted in-page.

- Preflight → approve. 2a: Locations created via the setup form (8/8
  slots prefilled), Instructors created. 2b: Create Terms & Apply → 3
  location terms (23 address fields) and 32 instructor terms → approve
  (no warnings). Each step unlocked and opened the next.
- Events: preview, then run (693 → 658 imported, 35 rolled back, about
  4.5 min). Results check: 656 with a location term, 595 with an
  instructor term, 658 indexed → approve.
- Tickets: preview 688 types → run (about 2.5 min) → 688 / 688, 688
  bindings → approve.
- Orders: preview 2,306 rows (status split as in backend QA) → run
  (about 4.5 min) → acceptance passed (2,306 / 2,088 sold / 1,594
  orders / 0 mismatches / 0 duplicates / 0 charges) → approve.
  Finish shows Complete.
- Reload mid-run resumes at the right step (state is server-side). 0
  console errors.
- **Fixed during the pass** (all redeployed):
  - unmatched sources now default to "Create new term" (fresh installs
    had Create Terms & Apply disabled);
  - asset versions include file mtime (the browser was serving stale
    JS/CSS);
  - titles are decoded (`&#038;`, and `&amp;` double-escaped in the
    console);
  - spot-check links are spread across the run;
  - copy: "TEC events", confirm text when 0 assignments, events preview
    subtracts dateless events, "Event × order links";
  - narrow-width CSS: setup cards, slot grid, buttons, tables. 0
    horizontal overflow, measured.
- Side effect noticed: Rank Math logs a "deleted term
  /locations/austin/" notice after an undo deletes a location term.
  Notice only, no redirect created.

### Step 17 — Attendee fidelity (v1.10.0) ✅

**Why:** EventKoi has no separate attendee table. Its attendees are the
per-seat `wp_eventkoi_ticket_orders` rows our orders stage writes. The
investigation on ott-dev (2026-10-19, against the v1.9.0 import) found
that the admin Attendees tab works, but three things are wrong or
missing.

**What works (verified):**
- `/tickets/orders` (admin Attendees tab, Sales history, CSV export)
  returns the imported orders with per-seat check-in codes and
  check-in-eligible quantities. Example: event 54634 → 4 orders.
- The admin tab only passes `instance_ts` for **recurring** events, and
  dev has 0 recurring events, so nothing is hidden today.
- QR check-in reads a row's `customer_name` / `customer_email` /
  `checkin_token`. Imported tokens are unique, and rows with ineligible
  statuses (refunded, failed) are not check-in eligible.
- The only TEC check-in is on a deleted event (orphan, correctly
  skipped).

**Finding 1 — storefront inventory ignores imported sales (bug,
oversell risk).**
- Imported rows have `instance_ts = 0`.
- For `date_type = standard`, `standard_type = selected` (644 of 658
  imported events), EventKoi's `resolve_checkout_instance_ts()` resolves
  the event day's start timestamp. `get_public_tickets()` then counts
  only rows tagged with that instance (`get_remote_ticket_sales()`,
  `get_event_committed_quantity()`), so our sold seats count as 0.
- Measured on event 54634 (Spooky Bookstore Cookies): the public payload
  shows `quantity_sold: 0` (actually 4). Remaining stayed at 11 only
  because the shared event cap clamped it. The event cap itself also
  reads 0 committed, so it allows 11 instead of TEC's 7.
- Exposure on dev: **41 upcoming events / 126 sold seats**, 543 events
  with a shared event cap.
- EventKoi's own WC checkout stamps the resolved instance on every row
  and on `_eventkoi_instance_ts`. RSVP rows already use
  `start_timestamp`, and `start_timestamp` == `event_days[0].start_date`
  on all 658 events.

**Finding 2 — per-seat attendee identity is lost.**
- `_tribe_tickets_full_name` / `_tribe_tickets_email` hold the
  **purchaser** for WooCommerce tickets. The actual attendee is in
  Event Tickets Plus' `_tribe_tickets_meta` (`first-name`, `last-name`,
  `email`).
- 2,240 of 2,306 imported attendees have meta names, and 1,163 differ
  from the purchaser. **454 multi-seat orders** list different people per
  seat in TEC, but every EventKoi row carries the purchaser's name.
- EventKoi's own checkout also writes the buyer on every seat, so it has
  no native per-seat identity. QR check-in shows the row's name.

**Finding 3 — TEC registration answers are not carried.**
- `_tribe_tickets_meta` also holds phone, ZIP / postcode, "Have you
  taken a class with us before?", consent, and comments (687).
- EventKoi only surfaces extra data as **checkout fields**: defined in
  code through the `eventkoi_checkout_fields` filter, with values in WC
  order meta `_eventkoi_checkout_fields` (order-level). The CSV export
  adds one column per field.
- No such field is registered on dev; `eventkoi_fields` only holds our
  "Event website" event field.

**Plan:**

- **A. Instance tagging (required, fixes Finding 1).**
  - New `ek_instance_ts_for_event( $ek_id )` mirrors EventKoi's
    resolution for import-time data:
    - recurring → the occurrence matching the TEC attendee's event start
      (none on dev; review item if unmatched);
    - standard + `selected` → that day's `start_date` timestamp. With
      several selected days, match the TEC occurrence date, and fall
      back to the first day plus a review item;
    - `continuous` and anything else → 0.
  - Use it in `link_woo_attendees()` (row `instance_ts` and
    `_eventkoi_instance_ts` WC meta), TC `create_manual_order( …,
    $instance_ts )`, and RSVP (same value).
  - **Repair for existing imports:** a "Re-tag attendee instances" action
    (Advanced tools + an automatic check in the orders verify). It
    updates `instance_ts` on importer-written rows (`wc_` keys flagged
    `_ekti_wc_linked`, plus our manual orders), with a new audited op
    `update_ticket_orders_instance` (ids + old value). It also updates
    `_eventkoi_instance_ts` through the existing `wc_order_meta` op.
  - Orders verify gains a **storefront check**: per upcoming ticket,
    `get_public_tickets()` `quantity_sold` / `remaining` == expected,
    and the event cap remaining == TEC global level − paid.
- **B. Per-seat attendee identity (Finding 2) — decided: B2 (user,
  2026-10-19).**
  - **B1 (recommended):** each seat row gets its own attendee name and
    email from `_tribe_tickets_meta` (fallback: purchaser → billing).
    Staff see the right person at QR check-in.
    Trade-off: EventKoi labels an order with whichever seat row comes
    first, so a multi-seat order may show a seat holder rather than the
    buyer. Mitigation: write the purchaser seat first and keep the
    buyer on the WC order and in the export.
  - **B2:** keep the purchaser on every row (EventKoi parity) and list
    the seat holders in an "Attendees" checkout field (see C). The
    order list stays buyer-centric; the door list shows the buyer.
- **C. Registration answers (Finding 3).**
  - Register EventKoi checkout fields for the TEC attendee-meta keys
    actually present (discovered dynamically: phone, ZIP, "taken a class
    before", comments; consent kept as a checkbox), plus "Attendees"
    under B2.
  - Write `_eventkoi_checkout_fields` on each linked WC order
    (multi-seat values joined per seat), audited via `wc_order_meta`.
  - Field registration must outlive the importer, so the wizard
    installs a tiny **mu-plugin** (`ekti-checkout-fields.php`, field
    list stored in an option). The install is audited and undo removes
    it.
  - Report-only: the count of attendees whose meta email differs from
    the purchaser (reminder/confirmation emails go to the row email).
- **D. Wizard / UI.**
  - The orders preview shows per-seat names and which registration
    fields will be carried.
  - The orders verify adds the storefront and instance checks.
  - The Finish step offers "Install attendee fields".
- **E. QA on ott-dev.**
  - Run the repair on the current import, then confirm event 54634's
    public payload shows sold 4 / remaining 11 / event cap 7, and all
    41 upcoming events match TEC.
  - Run the orders undo + re-run to prove the new writer tags instances
    at import time.
  - Check 3 multi-seat orders: per-seat names (B1) or the Attendees field
    (B2) in the admin tab and the CSV export.
  - Do a QR check-in on a test token, then undo it.
  - Confirm idempotency.


#### As built (v1.10.0)

- `ek_instance_ts_for_event()`: standard + selected → `event_days[0]`
  start; everything else → 0 (recurring: none on the data). Used by
  `link_woo_attendees()` (rows and `_eventkoi_instance_ts`) and TC
  `create_manual_order()`. RSVP already used `start_timestamp`, which
  is equal.
- B2: rows keep the purchaser. `seat_registration()` reads ET+
  `_tribe_tickets_meta` (name/email → "Attendees"; other answers
  → `tec_*` keys, labelled from the product's ET+ field definitions;
  the md5-keyed consent checkbox → `tec_consent`).
  `apply_seat_registrations()` keeps a per-order seat registry
  (`_ekti_seat_registrations`) and rebuilds `_eventkoi_checkout_fields`:
  values shared by every seat collapse to one value, otherwise
  "Name: value; …". Multi-event orders accumulate. Both keys are audited
  in `wc_order_meta`.
- Field registry option `ekti_checkout_fields` (Attendees first),
  persisted at shutdown, frozen during dry runs.
- Repair: `run_attendee_repair()` / `repair_event_attendees()` for events
  without `_ekti_attendee_v = 17`. It re-tags `instance_ts` (new op
  `update_ticket_orders_instance`) and rebuilds seat registrations on
  `_ekti_wc_linked` orders. Ops are tagged `orders`, so "Undo Orders"
  reverts it. New orders-stage runs set the marker themselves.
- mu-plugin `ekti-attendee-fields.php` (install / remove from the wizard;
  "Undo Entire Import" removes it). Its `eventkoi_checkout_fields` filter
  answers only for events with `_tec_import_source_id`, and only in
  wp-admin page loads or on the `/tickets/attendees/export` and
  `/tickets/orders/get` routes, so the storefront checkout never shows
  these fields.
- Orders verify adds: rows off EventKoi's instance; per upcoming event,
  EventKoi's own `get_public_tickets()` sold/remaining vs imported rows
  and the event cap; linked orders without seat registrations (HPOS-aware
  SQL); plugin status. The orders preview shows multi-person orders and
  the fields that will be carried.

#### Dev QA results (2026-10-19, importer v1.10.0)

- **Before (v1.9 import, new checks):** 2,273 rows off-instance, 41/41
  upcoming events wrong on the storefront, 1,594 orders missing details.
  Repair queue: 421 events.
- **Repair through the UI** ("Update Existing Attendees", about 4 min,
  0 errors): 0 off-instance, 41 storefront events correct, 0 missing;
  fields recorded: Attendees, Phone, Zip/Post Code, "Have you taken a
  class with us before?", Consent, Comments. Fields plugin installed
  through the UI.
- **Storefront:** event 54634 public payload is now sold 4 / remaining 7
  (TEC: 11 global level − 4 sold); before it was sold 0.
- **Export / checkout:** EventKoi's attendee CSV gained the 6 columns.
  Example order 46808 (2 seats, purchaser Daniela Daniel on both rows):
  Attendees "Daniela Daniel …; Jason Daniel …", Phone per person,
  shared ZIP/answers collapsed. `get_checkout_fields()` outside the
  allowed contexts (storefront) returns []. 458 orders list more than
  one person.
- **Import-time writer:** orders undo (9,507 ops incl. the repair)
  → exact post-tickets state (9,230 available, 0 rows / seat meta /
  markers / flags). The orders re-run then produced 2,306 rows / 2,088
  sold / 1,594 orders, every attendee check passing with an empty repair
  queue. Orders re-approved.
- **Check-in:** EventKoi `update_ticket_checkin` (`checked_in` = check in
  all; `check_in` = reset) checked in 2/2 seats on wc_46808; a fully
  refunded order (wc_44872) had 0 eligible; reverted to 0 check-ins.
- **Dry run:** the preview for event 54059 reported 7 multi-person orders
  and 5 labelled fields, with 0 writes and an untouched registry.

### Step 18 — EventKoi setup in Preflight (v1.11.0) ✅

**Problem (reported 2026-10-19).** EventKoi's events list showed
"0 tickets" for every imported event (e.g. 54644 "Vintage Halloween
Cookies", 1 sold). The import was correct: the row is a `wc_` order,
USD, `complete`.

- `Events::get_events()` (class-events.php ~2060) counts sold seats only
  for the current checkout gateway and currency:
  `ticket_checkout_method = woocommerce` → `order_id LIKE 'wc\_%'`,
  anything else (default `stripe`) → `NOT LIKE 'wc\_%'`.
- `ticket_checkout_method` was never set on ott-dev, so all 2,088 sold
  WC rows in 328 events were excluded. EventKoi's WooCommerce checkout
  was also off (`WooCommerce_Checkout::is_active()` false).
- Earlier suspicion that attendees went into RSVP was wrong: the 6 RSVP
  events are TEC RSVP-only (free) events, and RSVP mode is correct for
  them (user decision).

**EventKoi onboarding wizard** (`#/dashboard/onboarding`, React): license
key → calendar defaults (`week_starts_on` 0 = Monday … 6 = Sunday,
`working_days`, `time_format` `12`/`24`, plus a timezone / date format
reminder) → Elementor templates (only when Elementor is active) → demo
event "Classical Concert in the Park" + plugin tour. Completion sets
`eventkoi_quick_start_completed = yes` and deletes
`eventkoi_show_quick_start_prompt`.

#### As built (v1.11.0)

- **Preflight** (step 1 is now "Preflight & EventKoi setup") adds rows:
  TEC ticket sales (Woo attendees / orders / products, TC attendees),
  EventKoi checkout method, checkout currency, non-WooCommerce imported
  sales (warn), EventKoi license (status only, the key is never read),
  EventKoi demo event (warn if `eventkoi_demo_event_id` exists).
  `preflight()` also returns `setup` (`ek_setup_proposal()`).
- **Setup form** (JS `renderEkSetupForm`): checkout method (WooCommerce
  pre-selected when TEC has Woo sales, "Leave as …" otherwise offered),
  week start (pre-filled from WP `start_of_week`, converted), working
  days (Mon–Fri), time format (from WP `time_format`), timezone / date
  format read-only with a General Settings link, Elementor note (link to
  EventKoi settings) only when Elementor is active.
- **Saving:** "Approve & Continue" posts the form with the approval;
  "Save Setup" saves it at any time. `apply_ek_setup()` validates, writes
  the 4 keys through `Settings::set()`, marks the quick start done, and
  records each key's prior value (or absence) in `ekti_ek_setup_audit`
  (first write only). `undo_ek_setup()` restores per key, deletes
  `eventkoi_settings` if it did not exist before, and restores the
  quick-start options. "Undo Entire Import" also undoes the setup.
- **Cache flush:** EventKoi clears its events-list cache only on
  `update_option_eventkoi_settings`. Creating or deleting the option does
  not fire it, so the list kept showing 0 after the first save.
  `flush_ek_event_caches()` calls `Hooks::clear_event_query_cache()` and
  `bump_events_cache_version()` after every save and undo.
- **Guard:** `checkout_method_guard()` blocks approving step 1, running
  or approving the tickets / orders stages while TEC has WooCommerce
  sales and EventKoi's method is not WooCommerce. The UI disables those
  buttons and shows the reason.
- **Verify:** Order matching gains "Events list sold counts" —
  `verify_dashboard_counts()` reruns EventKoi's gateway + currency rule
  per event and compares it with all sold rows imported; mismatches fail
  the check.
- **Not included:** demo event, plugin tour, license entry, writing
  EventKoi's Elementor template choice.

#### QA (ott-dev, 2026-10-19, EventKoi v1.4.7.0)

- **Deploy:** zip via `novamira/create-upload-link` + `wp plugin install
  --force --activate`, opcache invalidated, server md5 == local build,
  upload removed.
- **Before:** Preflight warns "checkout method not set"; guard active;
  Order matching fails with "0 / 328 events match"; EventKoi
  `get_events()` page 8 (start date desc, 10 per page) = the user's
  screenshot, all 0 sold.
- **Save:** method `woocommerce`, week start Monday, Mon–Fri, 12-hour.
  `WooCommerce_Checkout::is_active()` true; `get_events()` page 8 now
  2 / 0 / 1 / 0 / 3 / 1 / 4 / 4 / 2 / 1 sold (54644 = 1); guard clear.
- **Undo:** `eventkoi_settings` deleted again (it did not exist before),
  audit removed, list back to 0, guard active. **Save again:** list
  correct with no manual cache clear. **Third save:** no changes.
- **Validation:** empty working days, time format 13 and method
  "paypal" are refused with no writes.
- **Order matching:** passes — 328 / 328 events match, plus the Step 17
  checks (0 off-instance, 41 storefront events correct, 0 missing).
- **UI (built-in browser):** Run Checks → form pre-filled (WooCommerce,
  Monday, Mon–Fri, 12-hour); Save Setup → "no changes"; Undo EventKoi
  Setup → method back to "not set", Preflight warns, Tickets run
  disabled; Save Setup → WooCommerce. EventKoi Events page 8 (start date
  desc) now shows "1 ticket" for Vintage Halloween Cookies and 2 / 0 / 1 /
  3 / 0 / 1 / 4 / 4 / 2 for the rest. No JS errors (only unrelated font
  CORS errors).

---

### Step 19 — Remove pre-wizard import paths (v1.11.1) ✅

The wizard is the supported import path, so the Advanced tools panels
that predate it were removed (user-approved audit, 2026-10-19).

**Removed** (PHP methods, AJAX hooks, markup, JS, unused CSS):
- Run Migration Overview stats (`ekti_get_stats`), Event Mapping
  (`ekti_get_event_mapping`, `ekti_save_mapping`, `ekti_auto_match`) and
  Run Migration (`ekti_run_batch`, `ekti_rollback`; `process_batch`,
  `get_or_create_ticket`, migration state).
- Pre-Import Cleanup (`ekti_scan_cleanup`, `ekti_run_ticket_dedupe`,
  `ekti_run_event_merge`, `ekti_undo_cleanup`).
- Ticket Details Sync (`ekti_scan_ticket_details`,
  `ekti_run_ticket_details_sync`, `ekti_undo_sync`).
- Legacy Calendar Sync (`ekti_scan_calendar_sync`,
  `ekti_save_location_mapping`, `ekti_run_calendar_sync`,
  `ekti_undo_calendar_sync`).
- Single-pass Event Sync runner (`ekti_run_event_sync`, TEC-ID targeting,
  scan tiles and review table). `run_event_sync()` now only runs the
  wizard's events stage (always tags ops `events`), and
  `import_one_event()` lost its inline tickets + orders branch.
- Four methods nothing called (`get_eventkoi_events_with_tickets`,
  `get_eventkoi_tickets_for_event`, `get_product_name_for_tec_event`,
  `get_wc_order_date`). 63 methods, ~2,400 PHP lines.

**Kept:** `link_woo_attendees` (orders stage + attendee repair),
`replay_audit_ops` (every undo; handlers for the old op types stay),
`get_saved_mapping`/`save_mapping`, `scan_event_sync` (events Preview),
`scan_native_adoption` (Preflight), taxonomy sync and organizer field
cleanup (wizard step 2), the import log.

**Advanced tools** now holds "Native Imports & Event Sync Ledger" (Adopt
Native Imports, Undo Last Event Sync) and the import log. Preflight and
the events Preview still point users to Adopt.

**Behaviour change:** "Undo Last Event Sync" now replays only the
untagged ledger ops (adoption and pre-wizard single-pass runs), which is
what Preflight already described. Before, it replayed the whole ledger,
including wizard stages, in one request without un-approving the wizard.
It no longer clears `ekti_event_sync_state` (the events stage's failure
memory).

**Checks:** `php -l` and `node --check` pass. Every AJAX hook has a JS
caller and every JS action has a hook. No method is left without a
caller.

**ott-dev QA (2026-10-19, Novamira + built-in browser).** The user
restored ott-dev to a pre-import backup first, so the planned "imported
state unchanged" check no longer applied.
- **Legacy ledgers:** `ekti_migration_audit`, `ekti_cleanup_audit`,
  `ekti_sync_audit`, `ekti_cal_sync_audit`, `ekti_event_sync_audit` and
  the other importer options are all absent. Nothing is orphaned.
- **Deploy:** zip (sha256 df3bd93d…) uploaded and unpacked over
  1.7.0; 1.7.0 kept at `wp-content/ekti-backup-1.7.0`. Plugin still
  active, `EKTI_VERSION` 1.11.1.
- **Backend (read-only):** 23 `wp_ajax_ekti_*` hooks, none of the
  removed ones. `scan_event_sync()`: 691 TEC events (623 publish / 68
  draft), 695 Woo tickets, 2,299 Woo attendees, 6 RSVP events, 42 review
  items. `scan_native_adoption()`: 0 candidates.
- **UI:** page loads with admin.js 1.11.1 and no JS errors. Wizard shows
  6 steps; Advanced tools holds only "Native Imports & Event Sync Ledger"
  and "Import Log File"; no removed panel IDs in the DOM. Run Checks:
  all required checks pass, 2 warnings (TEC cleaner at 12 months,
  checkout method not set), "Previous Event Sync runs: None". Load Log
  works. No undo or adopt button was clicked and no wizard step was
  approved.
- **Full wizard run (UI, built-in browser; confirm dialogs auto-accepted
  in the page; no undo used):**
  - Preflight → Approve: checkout method saved as WooCommerce.
  - 2a: Locations (8/8 slots prefilled) and Instructors created. 2b:
    Create Terms & Apply → 3 location terms (23 address fields), 32
    instructor terms → approve.
  - Events: preview 656 to import + 35 without a start date → run (about
    6 min) → 656 done, 35 rolled back; 617 publish / 39 draft; 654 with a
    location term, 593 with an instructor term, 656 indexed → approve.
    Server: 2,579 ledger ops, all tagged `events`; 0 ticket and 0 order
    rows written (the trimmed `import_one_event()` stops after the
    event); failure memory holds the 35; `ekti_event_mapping` has 656.
  - Tickets: preview 686 types (38 multi-type events, 6 RSVP-only, 6
    unlimited, 4 with no tickets) → run (about 3.5 min) → 686 / 686 rows,
    686 bindings → approve.
  - Orders: preview 2,299 Woo attendees (2,042 complete / 39 partially
    refunded / 215 refunded / 3 failed), 9 RSVP rows, 0 unlinkable →
    run (about 7 min) → 2,299 rows, 2,081 sold, 1,590 linked orders, 0
    duplicate order IDs or tokens, 0 charges rows, events list 327 / 327
    match, 0 rows off-instance, 41 storefront events checked, 0 orders
    missing attendee details → approve. Finish shows Complete.
  - Ledger after the run: events 2,579 / tickets 3,893 / orders 7,670
    ops, 0 untagged. 2,299 attendees flagged imported.
  - The admin session (Novamira admin access link) was renewed once
    before the orders run. Console: only a 403 from an earlier wrong-slug
    URL and a browser "Transition was skipped" from the login redirect;
    none from admin.js.
- **Not covered:** Adopt Native Imports and Undo Last Event Sync on real
  ledger data (no native imports or untagged ops on this site).

---

## Admin UI ✅

**v1.11.1 layout:** Advanced tools holds only "Native Imports & Event
Sync Ledger" (Adopt Native Imports, Undo Last Event Sync) and the Import
Log. The panels described below were removed in Step 19 and are kept
here as history.

**v1.9.0 layout:** Import Wizard (6 steps, review stops after events,
tickets and orders; taxonomy setup and term matching in step 2) →
Activity console → collapsed **Advanced tools** (Run Migration Overview,
single-pass Event Sync, Event Mapping, Run Migration, Pre-Import Cleanup,
Ticket Details Sync, Legacy Calendar Sync, Import Log). The eight-panel
description below is the pre-1.9 layout; those panels now sit under
Advanced tools.

Server-rendered PHP page (`render_admin_page()`) with eight panels:
1. **Overview** — stats grid (TEC events, attendees, EventKoi events, mapped/
   unmapped events, attendees to import, WC orders to link, already imported)
   loaded via AJAX. Dismissible warning notice when WooCommerce is inactive.
2. **Event Sync (TEC → EventKoi)** (v1.7.0) — Scan Events / Run Event Sync /
   Adopt Native Imports / Undo Last Event Sync buttons, Import images
   checkbox, optional targeted TEC-IDs input, progress bar reuse, scan stat
   tiles, review-items table, and native-import adoption status.
3. **Event Mapping** — Auto-Match button, Load/Save Mapping buttons, a
   `wp-list-table` with TEC event ID/title/attendee count and an EventKoi
   event `<select>` per row. Status badges show match source.
4. **Run Migration** — Dry Run checkbox, Start Import / Resume / Rollback
   buttons, progress bar, live console output with color-coded log entries
   (green=success, red=error, yellow=warn, blue=info). Console shows
   composite keys and WC order IDs per attendee.
5. **Import Log File** — Load/Clear buttons, `<pre>` viewer.
6. **Pre-Import Cleanup** (v1.1.0) — Scan / Run Ticket Dedupe / Run Event
   Merge / Undo Last Cleanup buttons, progress bar reuse, three review
   tables (stale ticket pairs with keep-vs-delete + TEC current price,
   review items, duplicate event groups with canonical badge and member
   stats).
7. **Ticket Details Sync** (v1.2.0) — Scan / Run Sync / Undo Last Sync
   buttons, progress bar reuse, three tables (ticket types to create or
   convert with price/capacity/site-local sale window, field updates with
   current → proposed values, review items).
8. **Calendar Sync (Locations)** (v1.3.0) — Scan Locations / Save Mapping /
   Run Calendar Sync / Undo Last Calendar Sync buttons, progress bar reuse,
   one row per TEC location with source, mapped-event count, auto-match
   badge, and a calendar dropdown (existing calendars + create-new with
   inline name input).

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

- 2026-08-23: **Step 8 — locations only, categories out of scope.**
  ott-dev discovery showed EventKoi's native importer already created
  AND assigned calendars for all 19 TEC event categories (names and
  assignment counts match), so the mapper covers TEC *locations*
  (venues) only. The auto-map-by-name algorithm is generic
  (normalize → exact → containment → fuzzy ≥ 85%) and could serve
  categories too if a site ever needs it. Scope is `ekti_event_mapping`
  events only — unmapped TEC events never get imported, so there is no
  EventKoi event to assign. New calendars copy their display term-meta
  set from a template calendar rather than hard-coding EventKoi
  internals, so the plugin stays resilient to EventKoi schema changes.

- 2026-08-29: **Orphan skip + idempotency + live import (v1.4.0 →
  v1.4.1).** Root-caused mystery event deletions to TEC's
  `tribe_trash_event_cron` (`trash-past-events` raised 12 → 24 months;
  9 events restored). 856 attendees on 139 permanently deleted TEC
  events are skipped by `auto_match_events()` (v1.4.0) instead of
  fuzzy-matching onto a different year's same-titled event. The first
  import attempt exposed a doubling bug: random checkin codes inside
  composite order keys (`wc_{order}:{ticket}:{code}`) broke the
  DB-level duplicate check across re-runs after an MCP 30 s timeout
  (PHP continued server-side) → 4295 ticket_orders. After cleanup,
  v1.4.1 added the attendee-level `_eventkoi_imported_from_tec`
  pre-check at the top of `process_batch()`. The clean re-import
  produced exactly the expected numbers, and a deliberate second full
  run created 0 rows (3277 skips) — idempotency proven empirically.

- 2026-08-29: **v1.4.2 WC order-meta fix + post-import reconciliation.**
  `process_batch()` grouped attendees into `$wc_order_totals` before
  attaching `_ek_event_id`/`_ek_ticket_id`, so `_eventkoi_ticket_items`
  was written with `ticket_id: null` (PHP value-copy of the array;
  1754 "Undefined array key" warnings during the first run flagged it).
  EventKoi consumers skip null ticket IDs defensively and treat the
  ticket_orders table as source of truth, so nothing broke — but the
  meta contributed nothing. Fixed the ordering in code (v1.4.2) and
  reconciled all 1674 orders server-side, rebuilding ticket_items in
  EventKoi's native format (one item per ticket type, real ticket_id,
  per-seat `codes` from `checkin_token`). Verified: 0 orders with null
  ticket_ids, 0 event-meta mismatches, 0 ERROR log lines.

- 2026-08-29: **Old-event deletion prevention — user decision: keep 24 months.**
  Re-verified the deletion mechanism with live evidence: TEC's
  `tribe_trash_event_cron` (daily) trashes events ended >N months ago,
  then WP's `wp_scheduled_delete` purges trash after 30 days
  (`EMPTY_TRASH_DAYS=30`) — the pipeline that consumed the 139 lost
  events. Fingerprint: oldest published TEC event is 2025-08-19,
  exactly the 12-month boundary during the incident. Both importers
  verified innocent (our plugin's only `wp_trash_post` is EventKoi-side
  in the merge panel; rollback touches only EventKoi data; the native
  EventKoi importer has no tribe_events deletion path). TEC's harsher
  `delete-past-events` permanent-delete cron is OFF (option absent,
  `tribe_del_event_cron` not scheduled). Current state: 0 events in
  trash, 0 events older than 24 months → the cron trashes nothing
  today. **User chose no change:** the 10 oldest events (ended
  2025-08-19→2025-08-29) become trash-eligible ~Aug 2027 as the
  24-month window slides — revisit before then. Recommended-but-
  declined option was disabling auto-trash entirely
  (`trash-past-events = 0` clears the cron per Event_Cleaner source).
  Even if events are lost again, v1.4.0 orphan-skip caps the damage at
  skipped attendees (no wrong-year mis-matching).

- 2026-08-29: **Native-importer clone cleanup (dev).** Trashed all 333
  duplicate `eventkoi_event` posts (exact clones sharing a
  `_tec_import_source_id`, all published + calendar-assigned, 0 attached
  ticket data — verified live). Guards re-checked per loser: no
  ticket_orders/tickets rows, not a mapping target, published status;
  loser calendars unioned onto the canonical (0 unions needed — exact
  clones). Reversible via a NEW `ekti_event_dedupe_audit` ledger of 333
  `untrash_post` ops — the same op format `replay_audit_ops()` already
  reverses, so a UI "Undo" button can be added before the production
  cutover replay. Separate ledger by design (same rationale as Step 7)
  so undoing this never replays the verified Step 6 `ekti_cleanup_audit`
  ops. Note: the cleanup call hit the 30 s MCP timeout but completed
  server-side; the resumable design (skip already-trashed + ledger
  repair) verified a clean 333/333 outcome.

- 2026-08-31: **v1.5.0 taxonomy sync + organizer field cleanup (dev).**
  Deployed `eventkoi-tickets-importer` v1.5.0 to ott-dev. The TEC→EventKoi
  event mapping option (`ekti_event_mapping`) was rebuilt from
  `_tec_import_source_id` meta (660 mapped events). Locations sync mapped
  3 sources (Austin, San Antonio – Central, The Bake Lab) to existing
  `locations` taxonomy terms; 658 term assignments applied, 0 errors.
  Instructors sync created 28 new `instructors` terms from TEC organizers
  and applied 633 assignments, 0 errors. The native-import organizer
  meta key (`organizer_name`) was detected via postmeta scan, backed up
  to `ekti_organizer_field_backup`, and 605 event meta rows deleted.
  Spot-check: sample EventKoi event carries both `locations` and
  `instructors` terms and no longer has `organizer_name` meta.

- 2026-10-14: **Step 13 — Saved Locations term field sync (v1.6.0).**
  Fill-empty is the default write policy and overwrite is an explicit
  opt-in checkbox because the dev terms carry manually-curated data that
  diverges from TEC in 11 places (zip, longitude, "Texas" vs "TX",
  two-line address split) — a silent overwrite would destroy deliberate
  curation, so divergences surface as review-only conflicts instead.
  Detection reads EventKoi's options directly
  (`eventkoi_location_taxonomy`, `eventkoi_taxonomies`,
  `eventkoi_location_taxonomy_map`) rather than its classes, consistent
  with the importer's existing no-class-dependency pattern. Legacy
  `location`/`locations` post meta is deliberately NOT cleaned: EventKoi
  preserves it as the manual-mode fallback and `Event::get_locations()`
  prefers term rows, so the scan reports the count only. The field sync
  runs on the first chunk only (JS drops the `sync_fields` flag
  afterwards) so overwrite mode never records duplicate audit ops for
  the same write. Note: the pre-QA ledger still held the v1.5.0 sync's
  658 `set_terms` ops, so each QA undo also reverted those assignments
  (ledger semantics — undo replays everything); the final restore run
  re-applied all 658 assignments and rebuilt an equivalent ledger,
  leaving dev verified-identical to pre-QA.

- 2026-10-18: **Step 14 — Event Sync design decisions.** Porting the
  private native-importer logic into our plugin (with provenance
  comments) rather than patching EventKoi — it's a vendor plugin, so the
  bug gets reported upstream instead (class-tec-importer.php ~line 1561:
  uncaught DatabaseQueryException on duplicate RSVP emails; recommend
  marker-before-attendees + per-event isolation). The runner recomputes
  the pending queue per chunk instead of persisting a queue option, so
  the run is resumable even after a mid-run PHP death — the early marker
  guarantees a retry can never create a duplicate event (the native
  importer's failure mode; a partial event is repairable by deleting its
  EventKoi copy and re-running). Undo hard-deletes created events
  (unlike the merge panel's trash) because a trashed EventKoi copy would
  still carry `_tec_import_source_id` and block re-imports after undo.
  `create_manual_order()`'s quantity_sold overwrite is consistent with
  the Step 7 capacity model because a TEC ticket is either Woo (no
  manual orders created here — Run Migration owns those attendees) or
  TC (total_sales == the manual orders we create); the undo op re-runs
  EventKoi's own recount SQL. Adoption shares the event-sync ledger so a
  single Undo reverses adoption + sync together (both are Step 14
  lifecycle writes).

- 2026-10-18: **Step 14 QA — zip structure lesson.** A plugin zip with
  files at the archive ROOT installs as a separate plugin directory
  (named after the zip) → class-redeclare fatal on activation. Builds
  must wrap everything in the top-level `eventkoi-tickets-importer/`
  directory (including LICENSE, README.md, and assets/) — verified
  against the original distribution zip listing.

- 2026-10-18: **rollback_import scoped to migration-created artifacts.**
  QA exposed that Run Migration's rollback blanket-deleted every ticket
  referenced by a `_ekti_tec_product_%` option — after Step 14 most of
  those bindings belong to Event Sync's own tickets, so a migration
  rollback would have destroyed the entire Event Sync ticket set (652
  tickets on dev) plus every `_eventkoi_imported_from_tec` flag
  (including Adopt's) and all WC `_eventkoi_*` meta (including Event
  Dedupe's repoints). Rollback now deletes only migration-created
  tickets (`ekti_migration_created_tickets`), clears flags only on
  migration-flagged posts (`ekti_migration_flagged_posts`), and cleans
  WC meta only on orders the migration itself synced
  (`_eventkoi_synced`). Stale bindings (ticket no longer exists) are
  still removed either way.

- 2026-10-18: **QA expectation corrections vs the plan.** All TC data
  on dev is orphaned (the 104 "TC events" are permanently deleted posts
  referenced by 281 `tec_tc_attendee` rows — report-only, runs show
  tc_attendees=0); only 2 events have duplicate RSVP emails (plan
  guessed 4); the full run is 626 imported + 35 no-start-date errors,
  not 661/697 (661 was the scan's unimported count, which includes the
  35 unusable events). Location/instructor term assignments from
  v1.5.0 went with the pre-cleanup event population (0 assignments
  today) — re-run Taxonomy Sync after Event Sync to repopulate.

- 2026-10-19: **Step 17 — B2 chosen (user).** Rows keep the WooCommerce
  purchaser because EventKoi's own checkout does, and its order list and
  emails key off the row name. Seat holders and registration answers go
  to EventKoi's only extensibility point for buyer data, the
  `eventkoi_checkout_fields` filter + `_eventkoi_checkout_fields` order
  meta. The filter must outlive the importer and must not alter the
  storefront checkout, hence a context-gated mu-plugin. The instance
  value is computed from event meta rather than EventKoi's private
  `resolve_checkout_instance_ts()`; the storefront check verifies the
  two agree on every upcoming event.

- 2026-10-19: **Step 16 — wizard + taxonomy provisioning.** Provisioning
  goes through EventKoi's public statics (`Taxonomies::save()`,
  `Location_Taxonomy::set_slug()` / `set_map()`) rather than raw option
  writes, so EventKoi's own validation runs (slug collision with TEC's
  `location` rewrite, field-key rules). `set_slug()` never clears a
  previous map (it compares after writing), so `set_map()` is always
  called explicitly. Stage state lives on the event as meta rather than
  a queue option (same resumability rationale as Step 14). Stage undo
  shares the event-sync ledger via a `stage` tag, so "Undo Last Event
  Sync" still reverts everything. The orders stage needs the per-ticket
  `deducted` seats from the tickets stage, so the ticket summary is
  persisted in `_ekti_stage_tickets`. The 1.7.0 build found active on dev
  (STATE said 1.8.0) was replaced by 1.9.0.

- 2026-10-18: **Step 15 — native ticket model + WC-linked attendees.**
  WC attendees stay WC-linked (real price, real WC status, WC meta) rather
  than native's zero-value manual orders: the importer exists to connect
  tickets to WooCommerce orders, and native's manual orders mark refunded
  and failed seats `complete`. Parent `eventkoi_orders` /
  `eventkoi_charges` rows for WC orders were dropped — EventKoi 1.4.7's
  own WC checkout writes none (it groups `ticket_orders` by the `wc_{id}`
  base), and synthetic `succeeded` charges inflated EventKoi Stats. This
  supersedes the Step 5 parent-order/charge design. Capacity give-back
  counts only ever-paid rows (complete / partially_refunded / refunded):
  WC `total_sales` includes refunded sales but never pending/failed ones.
  Run Migration's rollback is now a ledger replay (`ekti_migration_audit`);
  the v1.7.0 tracking options (`ekti_migration_created_tickets`,
  `ekti_migration_flagged_posts`) are no longer written.

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

- 2026-10-19: **Repo cleanup.** Removed the single-pass Event Sync QA
  loop (`run-sync-chunk.py`, `run-sync-loop.sh`, local
  `qa-sync-run.log`), the August screenshots of the pre-wizard panels and
  a committed `__pycache__` file; `.gitignore` now covers `__pycache__/`,
  `*.pyc` and `.DS_Store`. `ott-dev.py` and `fetch_ek_source.py` stay as
  the dev QA tools (note: `ott-dev.py`'s stored app password returned 401
  on 2026-10-19; QA used the Novamira MCP connection instead).

- 2026-10-19: **v1.11.1 removed the pre-wizard Advanced tools** (Step
  19). "Undo Last Event Sync" was narrowed to untagged ledger ops rather
  than removed, because adoption ops are untagged and no wizard undo
  covers them; replaying the whole ledger also undid wizard stages
  without updating wizard state.

---

## Open questions / blockers

- **Orphaned legacy ledgers (v1.11.1):** `ekti_migration_audit`,
  `ekti_cleanup_audit`, `ekti_sync_audit` and `ekti_cal_sync_audit` can
  no longer be undone from the UI. All absent on ott-dev after its
  restore (checked 2026-10-19); check any other site before upgrading
  it, and undo with v1.11.0 first if any are non-empty. `ekti_event_mapping` is still written by
  the events stage; `ekti_migration_state` and `ekti_location_mapping`
  are now inert.

- **Elementor template choice** (Step 18): EventKoi's onboarding writes
  it through `api/class-elementor-templates.php`; the importer only links
  to EventKoi settings. Implement if a target site runs Elementor.

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
