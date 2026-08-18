# Build State — EventKoi Tickets Importer

**Single source of truth for build progress AND the step-by-step implementation
plan.** Every build session MUST update this file as its final action (see "Update
protocol" below). Read this first before starting any step. This file inlines the
condensed version of each build step so progress and plan travel together in one
top-level document.

- **Branch:** `main`
- **Plugin version:** 1.0.0
- **Last updated:** 2026-08-18
- **Overall status:** ✅ Phase 1 complete (4/4) — bootstrap, database layer,
  event mapping + auto-match, migration engine with rollback, admin UI with
  jQuery. Duplicate TEC import handling added. Plugin is feature-complete for
  the initial release.

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

Status legend: ⬜ Not started · 🟡 In progress · ✅ Done · ⚠️ Blocked

## Next action

**1.0.0 is feature-complete on `main`.** Remaining before wider release:

- **Live QA** on a WordPress install with real TEC + EventKoi data — confirm
  the full pipeline (stats → auto-match → mapping review → dry run → live
  import → rollback) works end-to-end.
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
  processed count, tickets/attendees created, skipped, errors, completion
  flag, dry-run flag, and start time.
- **Batch processor:** `process_batch($dry_run)` reads `EKTI_BATCH_SIZE` (30)
  attendees from the current offset, checks event mapping, gets or creates
  an EventKoi ticket type per TEC event + product combo (via
  `get_or_create_ticket()`), checks for already-imported attendees (via
  `tec_import_` prefixed order IDs), and inserts into
  `wp_eventkoi_ticket_orders` with full field mapping (customer name/email,
  quantity 1, unit price, payment status `paid`, WC order reference as
  `payment_intent_id`, check-in status/token, WC order date as `created_at`).
  Updates `quantity_sold` on the ticket type. Marks source attendees with
  `_eventkoi_imported_from_tec` post meta.
- **Ticket creation:** `get_or_create_ticket()` — checks for a previously
  created ticket (via `_ekti_tec_product_{tec_event_id}_{product_id}` option),
  falls back to matching existing EventKoi tickets by event + name, and if
  neither matches, inserts a new row into `wp_eventkoi_tickets` with
  `quantity_sold` pre-set to the existing attendee count for that TEC
  event/product combo.
- **Dry run:** same code path but skips DB writes; results array contains
  `dry_run_create` actions with the would-be data.
- **Rollback:** `rollback_import()` — deletes all `tec_import_*` rows from
  `wp_eventkoi_ticket_orders`, deletes ticket types tracked via
  `_ekti_tec_product_*` options, cleans `_eventkoi_imported_from_tec` meta,
  resets migration state.
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

## Admin UI ✅

Server-rendered PHP page (`render_admin_page()`) with four panels:
1. **Overview** — stats grid (TEC events, attendees, EventKoi events, mapped/
   unmapped events, attendees to import, already imported) loaded via AJAX.
2. **Event Mapping** — Auto-Match button, Load/Save Mapping buttons, a
   `wp-list-table` with TEC event ID/title/attendee count and an EventKoi
   event `<select>` per row. Status badges show match source.
3. **Run Migration** — Dry Run checkbox, Start Import / Resume / Rollback
   buttons, progress bar, live console output with color-coded log entries
   (green=success, red=error, yellow=warn, blue=info).
4. **Import Log File** — Load/Clear buttons, `<pre>` viewer.

`admin.js` (318 lines, jQuery IIFE): AJAX helper, console appender, stats
loader, mapping loader/saver, auto-match runner, recursive batch runner with
progress tracking, rollback with confirmation.

`admin.css` (187 lines): panel cards, stats grid, progress bar, dark console
with syntax coloring, mapping table overflow scroll, CSS spinner.

---

## Decisions & deviations log

- 2026-08-18: Initial STATE.md created retroactively to document the completed
  1.0.0 build. All four steps were implemented across commits `c303f8a` →
  `f63942a`.

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
- **WooCommerce dependency:** `get_wc_order_date()` calls `wc_get_order()`
  without checking if WooCommerce is active. If WC is deactivated, this will
  fatal. Should be guarded with `function_exists('wc_get_order')`.
- **No i18n:** all strings are hard-coded in English. A `.pot` file and
  `load_plugin_textdomain()` call would be needed for translation.
- **Log file in uploads:** the log file lives at
  `wp-content/uploads/eventkoi-import.log` which is web-accessible. Consider
  adding an `.htaccess` deny or moving to a non-public path.

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
