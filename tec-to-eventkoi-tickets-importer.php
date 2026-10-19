<?php
/**
 * Plugin Name: EventKoi Tickets Importer
 * Plugin URI:  https://onedog.solutions
 * Description: Migrates tickets and attendees from The Events Calendar (Event Tickets / Event Tickets Plus) to EventKoi.
 * Version:     1.7.0
 * Author:      One Dog Solutions
 * Author URI:  https://onedog.solutions
 * License:     GPL-2.0-or-later
 * Text Domain: eventkoi-tickets-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EKTI_VERSION', '1.7.0' );
define( 'EKTI_LOG_DIR', WP_CONTENT_DIR . '/uploads' );
define( 'EKTI_LOG_FILE', EKTI_LOG_DIR . '/eventkoi-import.log' );
define( 'EKTI_BATCH_SIZE', 30 );
define( 'EKTI_LOCATION_TAXONOMY', 'locations' );
define( 'EKTI_INSTRUCTOR_TAXONOMY', 'instructors' );

/**
 * Main plugin class.
 */
final class EventKoi_Tickets_Importer {

    /** @var self */
    private static $instance;

    /**
     * Location slots EventKoi's Saved Locations feature can map to term
     * fields (mirrors EventKoi's Location_Taxonomy::SLOTS).
     *
     * @var string[]
     */
    private $location_slots = [
        'address1',
        'address2',
        'city',
        'state',
        'country',
        'zip',
        'latitude',
        'longitude',
    ];

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_ekti_get_stats', [ $this, 'ajax_get_stats' ] );
        add_action( 'wp_ajax_ekti_get_event_mapping', [ $this, 'ajax_get_event_mapping' ] );
        add_action( 'wp_ajax_ekti_save_mapping', [ $this, 'ajax_save_mapping' ] );
        add_action( 'wp_ajax_ekti_auto_match', [ $this, 'ajax_auto_match' ] );
        add_action( 'wp_ajax_ekti_run_batch', [ $this, 'ajax_run_batch' ] );
        add_action( 'wp_ajax_ekti_rollback', [ $this, 'ajax_rollback' ] );
        add_action( 'wp_ajax_ekti_scan_cleanup', [ $this, 'ajax_scan_cleanup' ] );
        add_action( 'wp_ajax_ekti_run_ticket_dedupe', [ $this, 'ajax_run_ticket_dedupe' ] );
        add_action( 'wp_ajax_ekti_run_event_merge', [ $this, 'ajax_run_event_merge' ] );
        add_action( 'wp_ajax_ekti_undo_cleanup', [ $this, 'ajax_undo_cleanup' ] );
        add_action( 'wp_ajax_ekti_scan_ticket_details', [ $this, 'ajax_scan_ticket_details' ] );
        add_action( 'wp_ajax_ekti_run_ticket_details_sync', [ $this, 'ajax_run_ticket_details_sync' ] );
        add_action( 'wp_ajax_ekti_undo_sync', [ $this, 'ajax_undo_sync' ] );
        add_action( 'wp_ajax_ekti_scan_calendar_sync', [ $this, 'ajax_scan_calendar_sync' ] );
        add_action( 'wp_ajax_ekti_save_location_mapping', [ $this, 'ajax_save_location_mapping' ] );
        add_action( 'wp_ajax_ekti_run_calendar_sync', [ $this, 'ajax_run_calendar_sync' ] );
        add_action( 'wp_ajax_ekti_undo_calendar_sync', [ $this, 'ajax_undo_calendar_sync' ] );
        add_action( 'wp_ajax_ekti_scan_taxonomy_sync', [ $this, 'ajax_scan_taxonomy_sync' ] );
        add_action( 'wp_ajax_ekti_save_taxonomy_mapping', [ $this, 'ajax_save_taxonomy_mapping' ] );
        add_action( 'wp_ajax_ekti_run_taxonomy_sync', [ $this, 'ajax_run_taxonomy_sync' ] );
        add_action( 'wp_ajax_ekti_undo_taxonomy_sync', [ $this, 'ajax_undo_taxonomy_sync' ] );
        add_action( 'wp_ajax_ekti_remove_organizer_fields', [ $this, 'ajax_remove_organizer_fields' ] );
        add_action( 'wp_ajax_ekti_scan_event_sync', [ $this, 'ajax_scan_event_sync' ] );
        add_action( 'wp_ajax_ekti_run_event_sync', [ $this, 'ajax_run_event_sync' ] );
        add_action( 'wp_ajax_ekti_adopt_native_imports', [ $this, 'ajax_adopt_native_imports' ] );
        add_action( 'wp_ajax_ekti_undo_event_sync', [ $this, 'ajax_undo_event_sync' ] );
        add_action( 'wp_ajax_ekti_get_log', [ $this, 'ajax_get_log' ] );
        add_action( 'wp_ajax_ekti_clear_log', [ $this, 'ajax_clear_log' ] );
    }

    /* ------------------------------------------------------------------
     * LOGGING
     * ------------------------------------------------------------------ */

    private function log( $message, $level = 'INFO' ) {
        if ( ! is_dir( EKTI_LOG_DIR ) ) {
            wp_mkdir_p( EKTI_LOG_DIR );
        }
        $ts = current_time( 'Y-m-d H:i:s' );
        $line = sprintf( "[%s] [%s] %s\n", $ts, $level, $message );
        file_put_contents( EKTI_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
    }

    /* ------------------------------------------------------------------
     * ADMIN MENU
     * ------------------------------------------------------------------ */

    public function register_admin_menu() {
        add_management_page(
            'EventKoi Ticket Importer',
            'EventKoi Ticket Importer',
            'manage_options',
            'eventkoi-ticket-importer',
            [ $this, 'render_admin_page' ]
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'tools_page_eventkoi-ticket-importer' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'ekti-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', [], EKTI_VERSION );
        wp_enqueue_script( 'ekti-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.js', [ 'jquery' ], EKTI_VERSION, true );
        wp_localize_script( 'ekti-admin', 'EKTI', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'ekti_nonce' ),
            'batchSize' => EKTI_BATCH_SIZE,
        ] );
    }

    /* ------------------------------------------------------------------
     * DATABASE HELPERS
     * ------------------------------------------------------------------ */

    private function ek_table( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'eventkoi_' . $name;
    }

    /**
     * Get all TEC attendee records (tribe_wooticket CPT).
     */
    private function get_tec_attendees( $offset = 0, $limit = 50 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title,
                    MAX(CASE WHEN pm.meta_key='_tribe_tickets_full_name' THEN pm.meta_value END) AS full_name,
                    MAX(CASE WHEN pm.meta_key='_tribe_tickets_email' THEN pm.meta_value END) AS email,
                    MAX(CASE WHEN pm.meta_key='_tribe_wooticket_event' THEN pm.meta_value END) AS tec_event_id,
                    MAX(CASE WHEN pm.meta_key='_tribe_wooticket_product' THEN pm.meta_value END) AS product_id,
                    MAX(CASE WHEN pm.meta_key='_tribe_wooticket_order' THEN pm.meta_value END) AS wc_order_id,
                    MAX(CASE WHEN pm.meta_key='_paid_price' THEN pm.meta_value END) AS paid_price,
                    MAX(CASE WHEN pm.meta_key='_tribe_wooticket_checkedin' THEN pm.meta_value END) AS checked_in,
                    MAX(CASE WHEN pm.meta_key='_tribe_deleted_product_name' THEN pm.meta_value END) AS product_name,
                    MAX(CASE WHEN pm.meta_key='_tribe_wooticket_security_code' THEN pm.meta_value END) AS security_code,
                    MAX(CASE WHEN pm.meta_key='_tribe_tickets_meta' THEN pm.meta_value END) AS ticket_meta,
                    MAX(CASE WHEN pm.meta_key='order_item_id' THEN pm.meta_value END) AS order_item_id
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'tribe_wooticket' AND p.post_status = 'publish'
             GROUP BY p.ID
             ORDER BY p.ID ASC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ), ARRAY_A );
    }

    /**
     * Count total TEC attendees.
     */
    private function count_tec_attendees() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tribe_wooticket' AND post_status = 'publish'"
        );
    }

    /**
     * Get distinct TEC event IDs that have attendees.
     */
    private function get_tec_event_ids_with_attendees() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tribe_wooticket_event'"
        );
    }

    /**
     * Get all EventKoi events (published, expired, draft — excluding trash).
     */
    private function get_eventkoi_events() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT ID, post_title, post_status FROM {$wpdb->posts}
             WHERE post_type = 'eventkoi_event'
               AND post_status NOT IN ('trash', 'auto-draft')
             ORDER BY post_status = 'publish' DESC, post_title ASC",
            ARRAY_A
        );
    }

    /**
     * Get EventKoi events that have tickets in wp_eventkoi_tickets.
     */
    private function get_eventkoi_events_with_tickets() {
        global $wpdb;
        $table = $this->ek_table( 'tickets' );
        return $wpdb->get_results(
            "SELECT DISTINCT t.event_id, p.post_title
             FROM {$table} t
             JOIN {$wpdb->posts} p ON t.event_id = p.ID
             WHERE p.post_type = 'eventkoi_event' AND p.post_status = 'publish'
             ORDER BY p.post_title ASC",
            ARRAY_A
        );
    }

    /**
     * Get existing tickets for an EventKoi event.
     */
    private function get_eventkoi_tickets_for_event( $event_id ) {
        global $wpdb;
        $table = $this->ek_table( 'tickets' );
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d", $event_id ),
            ARRAY_A
        );
    }

    /* ------------------------------------------------------------------
     * EVENT MAPPING (stored as WP option)
     * ------------------------------------------------------------------ */

    private function get_saved_mapping() {
        return get_option( 'ekti_event_mapping', [] );
    }

    private function save_mapping( $mapping ) {
        update_option( 'ekti_event_mapping', $mapping );
    }

    /**
     * Build a reverse lookup: TEC event ID → EventKoi event ID using _tec_import_source_id.
     * This is the authoritative mapping left by EventKoi's native event importer.
     *
     * Handles duplicate imports (same TEC event imported multiple times) by preferring:
     *   1. Published events over expired/draft.
     *   2. Lower post ID (first import) when statuses are equal.
     *
     * Also includes expired/draft EventKoi events so attendees of past classes
     * can still be migrated if the user chooses to map them.
     */
    private function get_tec_import_source_mapping() {
        global $wpdb;

        // Fetch ALL EventKoi events with _tec_import_source_id (any status).
        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS tec_event_id, p.ID AS eventkoi_id, p.post_status, p.post_date
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_tec_import_source_id'
               AND p.post_type = 'eventkoi_event'
             ORDER BY p.ID ASC",
            ARRAY_A
        );

        // Status priority: publish > eventkoi_expired > draft > anything else.
        $status_priority = [
            'publish'          => 0,
            'eventkoi_expired' => 1,
            'draft'            => 2,
        ];

        $source_map   = [];
        $candidates   = []; // tec_id => array of candidates
        $duplicates   = 0;

        foreach ( $rows as $row ) {
            $tec_id = $row['tec_event_id'];
            $candidates[ $tec_id ][] = $row;
        }

        foreach ( $candidates as $tec_id => $cands ) {
            if ( count( $cands ) > 1 ) {
                $duplicates++;
                // Sort: prefer publish, then lowest ID.
                usort( $cands, function ( $a, $b ) use ( $status_priority ) {
                    $pa = isset( $status_priority[ $a['post_status'] ] ) ? $status_priority[ $a['post_status'] ] : 99;
                    $pb = isset( $status_priority[ $b['post_status'] ] ) ? $status_priority[ $b['post_status'] ] : 99;
                    if ( $pa !== $pb ) {
                        return $pa - $pb;
                    }
                    return intval( $a['eventkoi_id'] ) - intval( $b['eventkoi_id'] );
                } );
            }
            $winner = $cands[0];
            $source_map[ $tec_id ] = (int) $winner['eventkoi_id'];
        }

        if ( $duplicates > 0 ) {
            $this->log( "Source mapping: {$duplicates} duplicate TEC imports detected; resolved by preferring published events." );
        }

        return $source_map;
    }

    /**
     * Attempt auto-matching TEC events to EventKoi events.
     * Strategy:
     *   1. Primary: _tec_import_source_id (authoritative from EventKoi's importer).
     *   2. Secondary: Exact title match.
     *   3. Fallback: Fuzzy title match (≥85% similarity).
     *
     * Orphan attendees (whose TEC event was permanently deleted) are skipped
     * to prevent misassigning them to a different year's same-titled event.
     */
    private function auto_match_events() {
        $tec_event_ids = $this->get_tec_event_ids_with_attendees();
        $ek_events     = $this->get_eventkoi_events();
        $mapping       = $this->get_saved_mapping();
        $source_map    = $this->get_tec_import_source_mapping();

        $this->log( 'Auto-match: ' . count( $source_map ) . ' EventKoi events have _tec_import_source_id.' );

        $ek_titles = [];
        foreach ( $ek_events as $ek ) {
            $key = sanitize_title( $ek['post_title'] );
            $ek_titles[ $key ] = $ek['ID'];
        }

        $matched          = 0;
        $unmatched        = 0;
        $source_matched   = 0;
        $title_matched    = 0;
        $fuzzy_matched    = 0;
        $orphaned         = 0;

        foreach ( $tec_event_ids as $tec_id ) {
            // Strategy 1: Authoritative _tec_import_source_id mapping.
            if ( isset( $source_map[ $tec_id ] ) ) {
                $mapping[ $tec_id ] = $source_map[ $tec_id ];
                $matched++;
                $source_matched++;
                continue;
            }

            // Strategy 2 & 3: Title-based matching (for events not in EventKoi's import).
            $tec_post = get_post( $tec_id );

            // Skip orphan attendees from deleted TEC events — no reliable way
            // to determine which year's instance they belonged to.  Title
            // matching would misassign them to a different year's event.
            if ( ! $tec_post ) {
                $orphaned++;
                continue;
            }

            $title = $tec_post->post_title;

            if ( empty( $title ) ) {
                $unmatched++;
                continue;
            }

            // Strategy 2: Exact title match.
            $key = sanitize_title( $title );
            if ( isset( $ek_titles[ $key ] ) ) {
                $mapping[ $tec_id ] = $ek_titles[ $key ];
                $matched++;
                $title_matched++;
                continue;
            }

            // Strategy 3: Fuzzy title match (≥85%).
            $fuzzy = $this->fuzzy_match_title( $title, array_column( $ek_events, 'post_title' ) );
            if ( $fuzzy ) {
                $fuzzy_key = sanitize_title( $fuzzy );
                if ( isset( $ek_titles[ $fuzzy_key ] ) ) {
                    $mapping[ $tec_id ] = $ek_titles[ $fuzzy_key ];
                    $matched++;
                    $fuzzy_matched++;
                    continue;
                }
            }

            $unmatched++;
        }

        $this->save_mapping( $mapping );

        if ( $orphaned > 0 ) {
            $this->log( "Auto-match: {$orphaned} orphan TEC events (permanently deleted) skipped to prevent cross-year mismatches." );
        }

        $this->log( sprintf(
            'Auto-match: %d total matched (%d via _tec_import_source_id, %d exact title, %d fuzzy). %d unmatched. %d orphaned (deleted TEC events skipped).',
            $matched, $source_matched, $title_matched, $fuzzy_matched, $unmatched, $orphaned
        ) );

        return [
            'total_tec_events'  => count( $tec_event_ids ),
            'matched'           => $matched,
            'source_matched'    => $source_matched,
            'title_matched'     => $title_matched,
            'fuzzy_matched'     => $fuzzy_matched,
            'unmatched'         => $unmatched,
            'orphaned'          => $orphaned,
            'mapping'           => $mapping,
        ];
    }

    /**
     * Get the product name associated with a TEC event (from attendee meta).
     */
    private function get_product_name_for_tec_event( $tec_event_id ) {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.meta_value
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id
             WHERE pm.meta_key = '_tribe_deleted_product_name'
               AND pm2.meta_key = '_tribe_wooticket_event' AND pm2.meta_value = %s
             LIMIT 1",
            $tec_event_id
        ) );
        return $name;
    }

    /**
     * Simple fuzzy title match using similar_text.
     */
    private function fuzzy_match_title( $title, $candidates ) {
        if ( empty( $title ) ) {
            return null;
        }
        $best_score = 0;
        $best_match = null;
        foreach ( $candidates as $candidate ) {
            similar_text( strtolower( $title ), strtolower( $candidate ), $percent );
            if ( $percent > $best_score && $percent >= 85 ) {
                $best_score = $percent;
                $best_match = $candidate;
            }
        }
        return $best_match;
    }

    /* ------------------------------------------------------------------
     * MIGRATION ENGINE
     * ------------------------------------------------------------------ */

    private function get_migration_state() {
        $state = get_option( 'ekti_migration_state', [] );
        $defaults = [
            'offset'           => 0,
            'processed'        => 0,
            'tickets_created'  => 0,
            'attendees_created'=> 0,
            'orders_created'   => 0,
            'charges_created'  => 0,
            'skipped'          => 0,
            'errors'           => 0,
            'completed'        => false,
            'dry_run'          => false,
            'started_at'       => null,
        ];
        return wp_parse_args( $state, $defaults );
    }

    private function update_migration_state( $state ) {
        update_option( 'ekti_migration_state', $state );
    }

    private function reset_migration_state( $dry_run = false ) {
        $state = [
            'offset'           => 0,
            'processed'        => 0,
            'tickets_created'  => 0,
            'attendees_created'=> 0,
            'orders_created'   => 0,
            'charges_created'  => 0,
            'skipped'          => 0,
            'errors'           => 0,
            'completed'        => false,
            'dry_run'          => $dry_run,
            'started_at'       => current_time( 'mysql' ),
        ];
        $this->update_migration_state( $state );
        return $state;
    }

    /**
     * Process a batch of attendees.
     *
     * Groups attendees by WC order so we can create a single parent
     * wp_eventkoi_orders row per order, plus one wp_eventkoi_charges row,
     * then one wp_eventkoi_ticket_orders row per attendee with the
     * EventKoi-native composite key format.
     */
    private function process_batch( $dry_run = false ) {
        global $wpdb;

        $state     = $this->get_migration_state();
        $mapping   = $this->get_saved_mapping();
        $attendees = $this->get_tec_attendees( $state['offset'], EKTI_BATCH_SIZE );

        if ( empty( $attendees ) ) {
            $state['completed'] = true;
            $this->update_migration_state( $state );
            $this->log( 'Migration completed. No more attendees to process.' );
            return [
                'done'    => true,
                'state'   => $state,
                'results' => [],
            ];
        }

        $results          = [];
        $ticket_cache     = []; // tec_event_id_product_id => eventkoi_ticket_id
        $parent_order_ids = []; // wc_order_id => eventkoi_orders.id
        $wc_order_totals  = []; // wc_order_id => { total, qty, event_id, title, attendees, ticket_id }
        $flagged_ids      = []; // Attendee posts this batch flagged as imported.

        // --- Phase 1: resolve tickets and group by WC order ----------------
        $resolved = []; // attendees that passed mapping + ticket resolution

        foreach ( $attendees as $att ) {
            $tec_event_id = $att['tec_event_id'];
            $product_id   = $att['product_id'];
            $wc_order_id  = $att['wc_order_id'];

            // Check mapping.
            if ( ! isset( $mapping[ $tec_event_id ] ) ) {
                $state['skipped']++;
                $results[] = [
                    'attendee_id' => $att['ID'],
                    'action'      => 'skipped',
                    'reason'      => "TEC event {$tec_event_id} not mapped to EventKoi event",
                ];
                continue;
            }

            // Idempotency: skip attendees already imported from a previous run.
            if ( get_post_meta( $att['ID'], '_eventkoi_imported_from_tec', true ) ) {
                $state['skipped']++;
                $results[] = [
                    'attendee_id' => $att['ID'],
                    'action'      => 'skipped',
                    'reason'      => 'Already imported (attendee-level flag)',
                ];
                $state['processed']++;
                continue;
            }

            $ek_event_id = (int) $mapping[ $tec_event_id ];

            // Get or create EventKoi ticket type for this product.
            $cache_key = $tec_event_id . '_' . $product_id;
            if ( isset( $ticket_cache[ $cache_key ] ) ) {
                $ek_ticket_id = $ticket_cache[ $cache_key ];
            } else {
                $ek_ticket_id = $this->get_or_create_ticket( $tec_event_id, $ek_event_id, $product_id, $att, $dry_run );
                if ( $ek_ticket_id ) {
                    $ticket_cache[ $cache_key ] = $ek_ticket_id;
                }
            }

            if ( ! $ek_ticket_id && ! $dry_run ) {
                $state['errors']++;
                $results[] = [
                    'attendee_id' => $att['ID'],
                    'action'      => 'error',
                    'reason'      => "Failed to get/create ticket for TEC event {$tec_event_id}",
                ];
                continue;
            }

            // Attach resolved EventKoi IDs before grouping so the WC order
            // meta ticket_items carry real ticket IDs.
            $att['_ek_event_id']  = $ek_event_id;
            $att['_ek_ticket_id'] = $ek_ticket_id;

            // Track per-WC-order totals for parent order creation.
            if ( $wc_order_id ) {
                if ( ! isset( $wc_order_totals[ $wc_order_id ] ) ) {
                    $ek_event_title = get_the_title( $ek_event_id );
                    $wc_order_totals[ $wc_order_id ] = [
                        'total'     => 0,
                        'qty'       => 0,
                        'ek_event_id'    => $ek_event_id,
                        'ek_event_title' => $ek_event_title,
                        'ek_ticket_id'   => $ek_ticket_id,
                        'attendees'      => [],
                        'ticket_items'   => [],
                    ];
                }
                $wc_order_totals[ $wc_order_id ]['total'] += floatval( $att['paid_price'] );
                $wc_order_totals[ $wc_order_id ]['qty']++;
                $wc_order_totals[ $wc_order_id ]['attendees'][] = $att;
            }

            $resolved[] = $att;
        }

        // --- Phase 2: create parent orders and charges per WC order --------
        foreach ( $wc_order_totals as $wc_order_id => $group ) {
            if ( ! $wc_order_id ) {
                continue;
            }

            $parent_id = $this->create_parent_order(
                $wc_order_id,
                $group['ek_event_id'],
                $group['attendees'],
                $group['ek_ticket_id'],
                $dry_run
            );

            if ( $parent_id ) {
                $parent_order_ids[ $wc_order_id ] = $parent_id;
                $state['orders_created']++;

                if ( ! $dry_run ) {
                    $this->create_charge_row(
                        $wc_order_id,
                        $parent_id,
                        $group['total'],
                        $group['qty'],
                        $dry_run
                    );
                    $state['charges_created']++;
                }

                // Build ticket_items array for WC order meta.
                $ticket_items_for_meta = [];
                foreach ( $group['attendees'] as $a ) {
                    $ticket_items_for_meta[] = [
                        'ticket_id'   => $a['_ek_ticket_id'],
                        'name'        => $a['product_name'] ?: 'General Admission',
                        'description' => '',
                        'quantity'    => 1,
                        'unit_amount' => (int) round( floatval( $a['paid_price'] ) * 100 ),
                        'codes'       => [], // codes set below per attendee
                    ];
                }
                $group['ticket_items'] = $ticket_items_for_meta;

                // Generate master check-in code and set WC order meta.
                $master_code = $this->generate_checkin_code();
                $this->set_wc_order_meta(
                    $wc_order_id,
                    $group['ek_event_id'],
                    $group['ek_event_title'],
                    $ticket_items_for_meta,
                    $master_code,
                    $dry_run
                );
            }
        }

        // --- Phase 3: insert ticket_orders rows per attendee ---------------
        $ticket_orders_table = $this->ek_table( 'ticket_orders' );

        foreach ( $resolved as $att ) {
            $ek_event_id  = $att['_ek_event_id'];
            $ek_ticket_id = $att['_ek_ticket_id'];
            $wc_order_id  = $att['wc_order_id'];

            // Generate check-in code.
            $checkin_code = $this->generate_checkin_code();

            // Build composite order_id matching EventKoi's format.
            if ( $wc_order_id && $ek_ticket_id ) {
                $composite_order_id = 'wc_' . $wc_order_id . ':' . $ek_ticket_id . ':' . $checkin_code;
            } elseif ( $wc_order_id ) {
                $composite_order_id = 'wc_' . $wc_order_id . ':0:' . $checkin_code;
            } else {
                $composite_order_id = 'tec_import_' . $att['ID'];
            }

            // Determine charge_id.
            $charge_id = $wc_order_id ? 'wc_charge_' . $wc_order_id : null;

            // Determine check-in status.
            $checked_in = ! empty( $att['checked_in'] ) && $att['checked_in'] === '1' ? 1 : 0;

            // Get WooCommerce order date for created_at.
            $order_date = $this->get_wc_order_date( $wc_order_id );

            // Check if already imported (by composite key or legacy key).
            if ( ! $dry_run ) {
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$ticket_orders_table} WHERE order_id = %s LIMIT 1",
                    $composite_order_id
                ) );
                if ( $existing ) {
                    $state['skipped']++;
                    $results[] = [
                        'attendee_id' => $att['ID'],
                        'action'      => 'skipped',
                        'reason'      => 'Already imported',
                    ];
                    $state['processed']++;
                    continue;
                }
                // Also check legacy key for backward compat with v1.0.0 imports.
                $legacy_key = 'tec_import_' . $att['ID'];
                $existing_legacy = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$ticket_orders_table} WHERE order_id = %s LIMIT 1",
                    $legacy_key
                ) );
                if ( $existing_legacy ) {
                    $state['skipped']++;
                    $results[] = [
                        'attendee_id' => $att['ID'],
                        'action'      => 'skipped',
                        'reason'      => 'Already imported (v1.0)',
                    ];
                    $state['processed']++;
                    continue;
                }
            }

            if ( $dry_run ) {
                $state['attendees_created']++;
                $results[] = [
                    'attendee_id'      => $att['ID'],
                    'action'           => 'dry_run_create',
                    'name'             => $att['full_name'],
                    'email'            => $att['email'],
                    'ek_event_id'      => $ek_event_id,
                    'ek_ticket_id'     => $ek_ticket_id ?: 'would_create',
                    'wc_order_id'      => $wc_order_id,
                    'composite_key'    => $composite_order_id,
                    'checkin_code'     => $checkin_code,
                    'price'            => $att['paid_price'],
                    'checked_in'       => $checked_in,
                ];
            } else {
                $unit_price = floatval( $att['paid_price'] );
                $inserted = $wpdb->insert( $ticket_orders_table, [
                    'event_id'         => $ek_event_id,
                    'ticket_id'        => $ek_ticket_id ?: 0,
                    'order_id'         => $composite_order_id,
                    'customer_name'    => sanitize_text_field( $att['full_name'] ),
                    'customer_email'   => sanitize_email( $att['email'] ),
                    'quantity'         => 1,
                    'unit_price'       => $unit_price,
                    'total_amount'     => $unit_price,
                    'currency'         => 'USD',
                    'payment_status'   => 'complete',
                    'payment_intent_id'=> null,
                    'charge_id'        => $charge_id,
                    'refund_amount'    => 0,
                    'checked_in'       => $checked_in,
                    'checked_in_at'    => $checked_in ? current_time( 'mysql' ) : null,
                    'checkin_token'    => $checkin_code,
                    'status'           => 'active',
                    'created_at'       => $order_date ?: current_time( 'mysql' ),
                    'updated_at'       => current_time( 'mysql' ),
                    'instance_ts'      => 0,
                ], [
                    '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%f', '%s', '%s',
                    '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%d',
                ] );

                if ( $inserted ) {
                    $ticket_order_id  = (int) $wpdb->insert_id;
                    $state['attendees_created']++;

                    // Update ticket quantity_sold via recount (accurate).
                    if ( $ek_ticket_id ) {
                        $sold = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COALESCE(SUM(quantity), 0) FROM {$ticket_orders_table}
                             WHERE ticket_id = %d AND event_id = %d
                               AND payment_status IN ('complete', 'completed', 'succeeded', 'partially_refunded')",
                            $ek_ticket_id, $ek_event_id
                        ) );
                        $wpdb->update(
                            $this->ek_table( 'tickets' ),
                            [ 'quantity_sold' => $sold ],
                            [ 'id' => $ek_ticket_id, 'event_id' => $ek_event_id ],
                            [ '%d' ],
                            [ '%d', '%d' ]
                        );
                    }

                    // Mark source attendee as imported, remembering it so the
                    // migration rollback clears only flags the migration set —
                    // Adopt Native Imports (Step 14) writes the same flag for
                    // attendees that must keep it.
                    update_post_meta( $att['ID'], '_eventkoi_imported_from_tec', true );
                    $flagged_ids[] = (int) $att['ID'];

                    $results[] = [
                        'attendee_id'        => $att['ID'],
                        'action'             => 'created',
                        'name'               => $att['full_name'],
                        'composite_key'      => $composite_order_id,
                        'ek_ticket_order_id' => $ticket_order_id,
                    ];
                } else {
                    $state['errors']++;
                    $this->log( "DB Error inserting attendee {$att['ID']}: " . $wpdb->last_error, 'ERROR' );
                    $results[] = [
                        'attendee_id' => $att['ID'],
                        'action'      => 'error',
                        'reason'      => $wpdb->last_error,
                    ];
                }
            }

            $state['processed']++;
        }

        $state['offset'] += count( $attendees );
        $this->update_migration_state( $state );

        if ( $flagged_ids && ! $dry_run ) {
            $flagged = get_option( 'ekti_migration_flagged_posts', [] );
            if ( ! is_array( $flagged ) ) {
                $flagged = [];
            }
            update_option( 'ekti_migration_flagged_posts', array_values( array_unique( array_merge( $flagged, $flagged_ids ) ) ), false );
        }

        return [
            'done'    => count( $attendees ) < EKTI_BATCH_SIZE,
            'state'   => $state,
            'results' => $results,
        ];
    }

    /**
     * Get or create an EventKoi ticket type for a TEC event/product combo.
     */
    private function get_or_create_ticket( $tec_event_id, $ek_event_id, $product_id, $att, $dry_run = false ) {
        global $wpdb;
        $tickets_table = $this->ek_table( 'tickets' );

        // Check if a ticket already created for this TEC event + product combo.
        $meta_key = '_ekti_tec_product_' . $tec_event_id . '_' . $product_id;
        $existing_id = get_option( $meta_key );

        if ( $existing_id ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$tickets_table} WHERE id = %d",
                $existing_id
            ) );
            if ( $exists ) {
                return (int) $exists;
            }
        }

        // Check if an existing ticket matches by event_id and price.
        $price = floatval( $att['paid_price'] );
        $ticket_name = $att['product_name'] ?: 'General Admission';

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$tickets_table} WHERE event_id = %d AND name = %s LIMIT 1",
            $ek_event_id,
            $ticket_name
        ), ARRAY_A );

        if ( $existing ) {
            update_option( $meta_key, $existing['id'] );
            return (int) $existing['id'];
        }

        if ( $dry_run ) {
            return null;
        }

        // Count existing attendees for this TEC event/product to set quantity_sold.
        $attendee_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm1 ON (p.ID = pm1.post_id AND pm1.meta_key = '_tribe_wooticket_event')
             JOIN {$wpdb->postmeta} pm2 ON (p.ID = pm2.post_id AND pm2.meta_key = '_tribe_wooticket_product')
             WHERE p.post_type = 'tribe_wooticket' AND p.post_status = 'publish'
               AND pm1.meta_value = %s AND pm2.meta_value = %s",
            $tec_event_id,
            $product_id
        ) );

        // Sale window + capacity from TEC source data (Step 7 rules).
        $defaults      = $this->ticket_defaults( $tec_event_id, $ek_event_id, $product_id );
        $qty_available = $defaults['quantity_available'];

        $inserted = $wpdb->insert( $tickets_table, [
            'event_id'           => $ek_event_id,
            'name'               => sanitize_text_field( $ticket_name ),
            'description'        => '',
            'price'              => $price,
            'currency'           => 'USD',
            'quantity_available' => $qty_available,
            'max_per_order'      => null,
            'quantity_sold'      => $attendee_count,
            'sale_start'         => $defaults['sale_start'],
            'sale_end'           => $defaults['sale_end'],
            'terms_conditions'   => null,
            'status'             => 'active',
            'sort_order'         => 0,
            'created_at'         => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%f', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );

        if ( $inserted ) {
            $ticket_id = $wpdb->insert_id;
            update_option( $meta_key, $ticket_id );
            // Remember tickets the migration created so its rollback deletes
            // only those — Event Sync (Step 14) writes the same binding
            // options for tickets that must survive a migration rollback.
            $created = get_option( 'ekti_migration_created_tickets', [] );
            if ( ! is_array( $created ) ) {
                $created = [];
            }
            $created[] = (int) $ticket_id;
            update_option( 'ekti_migration_created_tickets', array_values( array_unique( $created ) ), false );
            $this->log( "Created ticket type: {$ticket_name} (ID: {$ticket_id}) for EventKoi event {$ek_event_id}" );
            return $ticket_id;
        }

        $this->log( "Failed to create ticket: " . $wpdb->last_error, 'ERROR' );
        return null;
    }

    /**
     * Check if WooCommerce is available and active.
     */
    private function wc_is_available() {
        return function_exists( 'wc_get_order' ) && class_exists( 'WooCommerce' );
    }

    /**
     * Get WC order object (safe wrapper).
     */
    private function get_wc_order( $order_id ) {
        if ( ! $order_id || ! $this->wc_is_available() ) {
            return null;
        }
        return wc_get_order( $order_id );
    }

    /**
     * Get WC order date.
     */
    private function get_wc_order_date( $order_id ) {
        $order = $this->get_wc_order( $order_id );
        if ( $order ) {
            return $order->get_date_created()->date( 'Y-m-d H:i:s' );
        }
        return null;
    }

    /**
     * Generate a 12-character check-in code using EventKoi's human-friendly
     * alphabet (no ambiguous characters: 0, O, 1, I).
     */
    private function generate_checkin_code() {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $length   = 12;
        $code     = '';
        $bytes    = random_bytes( $length );
        for ( $i = 0; $i < $length; $i++ ) {
            $code .= $alphabet[ ord( $bytes[ $i ] ) % strlen( $alphabet ) ];
        }
        return $code;
    }

    /**
     * Create (or skip if exists) a parent row in wp_eventkoi_orders.
     *
     * Returns the row ID of the parent order, or null on failure.
     */
    private function create_parent_order( $wc_order_id, $ek_event_id, $attendees_group, $ek_ticket_id, $dry_run = false ) {
        global $wpdb;

        $checkout_id = 'wc_' . $wc_order_id;
        $orders_table = $this->ek_table( 'orders' );

        // Check if already exists.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$orders_table} WHERE checkout_id = %s LIMIT 1",
            $checkout_id
        ) );
        if ( $existing ) {
            return (int) $existing;
        }

        if ( $dry_run ) {
            return null;
        }

        // Calculate totals from the attendees group.
        $total_amount = 0;
        $total_qty    = 0;
        foreach ( $attendees_group as $att ) {
            $total_amount += floatval( $att['paid_price'] );
            $total_qty++;
        }

        // Get billing info from WC order.
        $billing_name  = '';
        $billing_email = '';
        $wc_order      = $this->get_wc_order( $wc_order_id );
        if ( $wc_order ) {
            $billing_name  = trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
            $billing_email = $wc_order->get_billing_email();
        }

        // Fallback to first attendee's info.
        if ( empty( $billing_name ) && ! empty( $attendees_group ) ) {
            $billing_name = $attendees_group[0]['full_name'];
        }
        if ( empty( $billing_email ) && ! empty( $attendees_group ) ) {
            $billing_email = $attendees_group[0]['email'];
        }

        $now = time();
        $inserted = $wpdb->insert( $orders_table, [
            'checkout_id'    => $checkout_id,
            'payment_id'     => null,
            'charge_id'      => 'wc_charge_' . $wc_order_id,
            'customer_id'    => null,
            'ticket_id'      => $ek_ticket_id ?: 0,
            'quantity'       => $total_qty,
            'subtotal'       => $total_amount,
            'total'          => $total_amount,
            'item_price'     => ! empty( $attendees_group ) ? floatval( $attendees_group[0]['paid_price'] ) : 0,
            'currency'       => 'usd',
            'payment_status' => 'complete',
            'status'         => 'complete',
            'created'        => $now,
            'expires'        => $now,
            'last_updated'   => $now,
            'live'           => 1,
            'billing_type'   => null,
            'billing_name'   => sanitize_text_field( $billing_name ),
            'billing_email'  => sanitize_email( $billing_email ),
            'billing_phone'  => null,
            'billing_address'=> null,
            'billing_data'   => null,
            'ip_address'     => null,
            'gateway'        => 'woocommerce',
            'is_archived'    => 0,
        ], [
            '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%s', '%s',
            '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%d',
        ] );

        if ( ! $inserted ) {
            $this->log( "DB Error creating parent order for WC #{$wc_order_id}: " . $wpdb->last_error, 'ERROR' );
            return null;
        }

        $order_row_id = $wpdb->insert_id;

        // Add order note.
        $notes_table = $this->ek_table( 'order_notes' );
        $wpdb->insert( $notes_table, [
            'order_id'   => $order_row_id,
            'note_key'   => 'order_completed',
            'note_value' => null,
            'type'       => 'system',
            'created'    => $now,
        ] );

        $this->log( "Created parent order: {$checkout_id} (row #{$order_row_id}) for event {$ek_event_id}" );
        return $order_row_id;
    }

    /**
     * Create (or skip if exists) a charge row in wp_eventkoi_charges.
     */
    private function create_charge_row( $wc_order_id, $ek_parent_order_id, $total_amount, $total_qty, $dry_run = false ) {
        global $wpdb;

        $charge_id     = 'wc_charge_' . $wc_order_id;
        $charges_table = $this->ek_table( 'charges' );

        // Check if already exists.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$charges_table} WHERE charge_id = %s LIMIT 1",
            $charge_id
        ) );
        if ( $existing ) {
            return (int) $existing;
        }

        if ( $dry_run ) {
            return null;
        }

        $now = time();
        $inserted = $wpdb->insert( $charges_table, [
            'order_id'        => $ek_parent_order_id ?: 0,
            'checkout_id'     => 'wc_' . $wc_order_id,
            'payment_id'      => 'wc_order_' . $wc_order_id,
            'charge_id'       => $charge_id,
            'amount'          => $total_amount,
            'amount_captured' => $total_amount,
            'amount_refunded' => 0,
            'fees'            => 0,
            'net'             => $total_amount,
            'currency'        => 'USD',
            'quantity'        => $total_qty,
            'status'          => 'succeeded',
            'created'         => $now,
            'live'            => 1,
            'gateway'         => 'woocommerce',
        ], [
            '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%s',
            '%d', '%s', '%d', '%d', '%s',
        ] );

        if ( ! $inserted ) {
            $this->log( "DB Error creating charge for WC #{$wc_order_id}: " . $wpdb->last_error, 'ERROR' );
            return null;
        }

        $this->log( "Created charge: {$charge_id} (amount: {$total_amount}) for WC order #{$wc_order_id}" );
        return $wpdb->insert_id;
    }

    /**
     * Set EventKoi meta on the WooCommerce order so EventKoi's admin can
     * link back to it, handle status changes, and process refunds.
     */
    private function set_wc_order_meta( $wc_order_id, $ek_event_id, $ek_event_title, $ticket_items, $master_checkin_code, $dry_run = false ) {
        if ( $dry_run || ! $this->wc_is_available() ) {
            return;
        }

        $wc_order = wc_get_order( $wc_order_id );
        if ( ! $wc_order ) {
            return;
        }

        // Idempotent: skip if already synced.
        if ( 'yes' === $wc_order->get_meta( '_eventkoi_synced' ) ) {
            return;
        }

        $wc_order->update_meta_data( '_eventkoi_event_id', $ek_event_id );
        $wc_order->update_meta_data( '_eventkoi_instance_ts', 0 );
        $wc_order->update_meta_data( '_eventkoi_event_title', $ek_event_title );
        $wc_order->update_meta_data( '_eventkoi_ticket_items', $ticket_items );
        $wc_order->update_meta_data( '_eventkoi_master_checkin_code', $master_checkin_code );
        $wc_order->update_meta_data( '_eventkoi_synced', 'yes' );
        $wc_order->save();
    }

    /* ------------------------------------------------------------------
     * ROLLBACK
     * ------------------------------------------------------------------ */

    private function rollback_import() {
        global $wpdb;
        $ticket_orders_table = $this->ek_table( 'ticket_orders' );
        $tickets_table       = $this->ek_table( 'tickets' );
        $orders_table        = $this->ek_table( 'orders' );
        $charges_table       = $this->ek_table( 'charges' );
        $notes_table         = $this->ek_table( 'order_notes' );

        // Collect parent order IDs before deleting (for notes cleanup).
        $parent_order_ids = $wpdb->get_col(
            "SELECT id FROM {$orders_table} WHERE gateway = 'woocommerce' AND checkout_id LIKE 'wc_%'"
        );

        // Delete order notes for importer-created parent orders.
        $deleted_notes = 0;
        if ( ! empty( $parent_order_ids ) ) {
            $ids_in = implode( ',', array_map( 'intval', $parent_order_ids ) );
            $deleted_notes = $wpdb->query(
                "DELETE FROM {$notes_table} WHERE order_id IN ({$ids_in})"
            );
        }

        // Delete charges created by the importer.
        $deleted_charges = $wpdb->query(
            "DELETE FROM {$charges_table} WHERE gateway = 'woocommerce' AND charge_id LIKE 'wc_charge_%'"
        );

        // Delete parent orders created by the importer.
        $deleted_parent_orders = $wpdb->query(
            "DELETE FROM {$orders_table} WHERE gateway = 'woocommerce' AND checkout_id LIKE 'wc_%'"
        );

        // Delete imported ticket orders (new composite keys + legacy keys).
        $deleted_orders = $wpdb->query(
            "DELETE FROM {$ticket_orders_table} WHERE order_id LIKE 'wc_%' OR order_id LIKE 'tec_import_%'"
        );

        // Delete tickets created by THIS importer only. The _ekti_tec_product_*
        // bindings are also written by Event Sync (Step 14) for tickets Event
        // Sync itself created; those tickets and bindings survive a migration
        // rollback. A binding is removed when its ticket was migration-created
        // or no longer exists (stale).
        $migration_tickets = get_option( 'ekti_migration_created_tickets', [] );
        if ( ! is_array( $migration_tickets ) ) {
            $migration_tickets = [];
        }
        $migration_tickets = array_map( 'intval', $migration_tickets );
        $all_options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '_ekti_tec_product_%'"
        );
        $deleted_tickets = 0;
        foreach ( $all_options as $opt ) {
            $ticket_id           = intval( $opt->option_value );
            $is_migration_ticket = in_array( $ticket_id, $migration_tickets, true );
            if ( $is_migration_ticket ) {
                $wpdb->delete( $tickets_table, [ 'id' => $ticket_id ], [ '%d' ] );
                $deleted_tickets++;
            }
            $ticket_exists = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$tickets_table} WHERE id = %d",
                $ticket_id
            ) );
            if ( $is_migration_ticket || ! $ticket_exists ) {
                delete_option( $opt->option_name );
            }
        }
        delete_option( 'ekti_migration_created_tickets' );

        // Clear only the import flags the migration itself set — Adopt Native
        // Imports (Step 14) writes the same flag on other attendee posts and
        // those must not be reset by a migration rollback.
        $flagged_posts = get_option( 'ekti_migration_flagged_posts', [] );
        if ( ! is_array( $flagged_posts ) ) {
            $flagged_posts = [];
        }
        if ( $flagged_posts ) {
            $flagged_in = implode( ',', array_map( 'intval', $flagged_posts ) );
            $wpdb->query(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_eventkoi_imported_from_tec' AND post_id IN ({$flagged_in})"
            );
        }
        delete_option( 'ekti_migration_flagged_posts' );

        // Clean EventKoi meta from WooCommerce orders the migration stamped
        // (_eventkoi_synced). Event Dedupe repoints _eventkoi_event_id on
        // other orders; those writes must survive a migration rollback.
        if ( $this->wc_is_available() ) {
            $ek_meta_keys = [
                '_eventkoi_event_id',
                '_eventkoi_instance_ts',
                '_eventkoi_event_title',
                '_eventkoi_ticket_items',
                '_eventkoi_master_checkin_code',
                '_eventkoi_synced',
            ];
            $synced_posts = $wpdb->get_col(
                "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_eventkoi_synced' AND p.post_type = 'shop_order'"
            );
            if ( $synced_posts ) {
                $synced_in = implode( ',', array_map( 'intval', $synced_posts ) );
                foreach ( $ek_meta_keys as $meta_key ) {
                    $wpdb->query(
                        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '{$meta_key}' AND post_id IN ({$synced_in})"
                    );
                }
            }
        }

        // Reset state.
        delete_option( 'ekti_migration_state' );

        $this->log( sprintf(
            'Rollback complete. Deleted: %d ticket orders, %d parent orders, %d charges, %d notes, %d ticket types.',
            $deleted_orders, $deleted_parent_orders, $deleted_charges, $deleted_notes, $deleted_tickets
        ) );

        return [
            'deleted_orders'        => $deleted_orders,
            'deleted_parent_orders' => $deleted_parent_orders,
            'deleted_charges'       => $deleted_charges,
            'deleted_notes'         => $deleted_notes,
            'deleted_tickets'       => $deleted_tickets,
        ];
    }

    /* ------------------------------------------------------------------
     * PRE-IMPORT CLEANUP (Step 6)
     *
     * Two-phase cleanup to run before the ticket import:
     *   Phase 1 — delete stale duplicate ticket types (same-name pairs left
     *             behind by repeated EventKoi event imports).
     *   Phase 2 — merge duplicate eventkoi_event posts that share the same
     *             _tec_import_source_id into one canonical event.
     * Every destructive write is recorded in the ekti_cleanup_audit ledger
     * so the whole cleanup can be undone.
     * ------------------------------------------------------------------ */

    /**
     * Bulk-resolve the current TEC ticket product price per TEC event.
     *
     * Uses the WooCommerce product attached to the event's attendees
     * (_tribe_wooticket_product => _price), falling back to a single
     * distinct _paid_price among attendees. Returns tec_id => float|null.
     */
    private function get_tec_price_map() {
        global $wpdb;

        $prod_rows = $wpdb->get_results(
            "SELECT pm1.meta_value AS tec_id, pm2.meta_value AS product_id
             FROM {$wpdb->postmeta} pm1
             JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
             WHERE pm1.meta_key = '_tribe_wooticket_event'
               AND pm2.meta_key = '_tribe_wooticket_product'",
            ARRAY_A
        );
        $products_of = [];
        $product_ids = [];
        foreach ( $prod_rows as $r ) {
            $products_of[ $r['tec_id'] ][ $r['product_id'] ] = true;
            $product_ids[ $r['product_id'] ] = true;
        }

        $price_of_product = [];
        if ( $product_ids ) {
            $in   = implode( ',', array_map( 'intval', array_keys( $product_ids ) ) );
            $rows = $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_price' AND post_id IN ({$in})",
                ARRAY_A
            );
            foreach ( $rows as $r ) {
                $price_of_product[ (int) $r['post_id'] ] = floatval( $r['meta_value'] );
            }
        }

        $paid_rows = $wpdb->get_results(
            "SELECT pm1.meta_value AS tec_id, pm3.meta_value AS price
             FROM {$wpdb->postmeta} pm1
             JOIN {$wpdb->postmeta} pm3 ON pm1.post_id = pm3.post_id
             WHERE pm1.meta_key = '_tribe_wooticket_event'
               AND pm3.meta_key = '_paid_price' AND pm3.meta_value != ''",
            ARRAY_A
        );
        $paid_of = [];
        foreach ( $paid_rows as $r ) {
            $paid_of[ $r['tec_id'] ][ $r['price'] ] = true;
        }

        $map = [];
        foreach ( $products_of as $tec_id => $products ) {
            $map[ $tec_id ] = null;
            foreach ( array_keys( $products ) as $pid ) {
                if ( isset( $price_of_product[ (int) $pid ] ) ) {
                    $map[ $tec_id ] = $price_of_product[ (int) $pid ];
                    break;
                }
            }
            if ( null === $map[ $tec_id ] && isset( $paid_of[ $tec_id ] ) && 1 === count( $paid_of[ $tec_id ] ) ) {
                $map[ $tec_id ] = floatval( array_key_first( $paid_of[ $tec_id ] ) );
            }
        }
        foreach ( $paid_of as $tec_id => $prices ) {
            if ( ! isset( $map[ $tec_id ] ) && 1 === count( $prices ) ) {
                $map[ $tec_id ] = floatval( array_key_first( $prices ) );
            }
        }
        return $map;
    }

    /**
     * Scan for pre-import cleanup work.
     *
     * Returns:
     *  - stale_pairs: same-name two-ticket events whose stale copy can be deleted.
     *  - review:      items needing manual review (sales present, price anomalies).
     *  - dup_groups:  duplicate eventkoi_event groups by _tec_import_source_id,
     *                 sorted so the first member is the canonical event.
     */
    private function scan_cleanup() {
        global $wpdb;

        $stale_pairs = [];
        $review      = [];
        $dup_groups  = [];

        $events = $wpdb->get_results(
            "SELECT ID, post_title, post_status FROM {$wpdb->posts}
             WHERE post_type = 'eventkoi_event'
               AND post_status NOT IN ('trash', 'auto-draft')",
            ARRAY_A
        );
        if ( empty( $events ) ) {
            return [ 'stale_pairs' => $stale_pairs, 'review' => $review, 'dup_groups' => $dup_groups ];
        }
        $event_ids = array_map( 'intval', array_column( $events, 'ID' ) );
        $in        = implode( ',', $event_ids );

        // Tickets per event.
        $tickets  = $wpdb->get_results(
            "SELECT id, event_id, name, price, quantity_sold, created_at
             FROM {$this->ek_table( 'tickets' )}
             WHERE event_id IN ({$in})
             ORDER BY event_id ASC, id ASC",
            ARRAY_A
        );
        $by_event = [];
        foreach ( $tickets as $t ) {
            $by_event[ (int) $t['event_id'] ][] = $t;
        }

        // Attendee counts per event and per ticket.
        $att_event  = [];
        $att_ticket = [];
        $rows       = $wpdb->get_results(
            "SELECT event_id, ticket_id, COUNT(*) c
             FROM {$this->ek_table( 'ticket_orders' )}
             WHERE event_id IN ({$in})
             GROUP BY event_id, ticket_id",
            ARRAY_A
        );
        foreach ( $rows as $r ) {
            $att_event[ (int) $r['event_id'] ]   = ( $att_event[ (int) $r['event_id'] ] ?? 0 ) + (int) $r['c'];
            $att_ticket[ (int) $r['ticket_id'] ] = (int) $r['c'];
        }

        // TEC source + current price per event.
        $tec_of = [];
        $rows   = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_tec_import_source_id' AND post_id IN ({$in})",
            ARRAY_A
        );
        foreach ( $rows as $r ) {
            $tec_of[ (int) $r['post_id'] ] = $r['meta_value'];
        }
        $price_map = $this->get_tec_price_map();

        foreach ( $events as $e ) {
            $id        = (int) $e['ID'];
            $tks       = $by_event[ $id ] ?? [];
            $tec_id    = $tec_of[ $id ] ?? null;
            $tec_price = ( null !== $tec_id ) ? ( $price_map[ $tec_id ] ?? null ) : null;

            if ( 2 === count( $tks ) && 0 === strcasecmp( trim( $tks[0]['name'] ), trim( $tks[1]['name'] ) ) ) {
                $has_sales = ( (int) $tks[0]['quantity_sold'] > 0 || (int) $tks[1]['quantity_sold'] > 0
                    || ( $att_ticket[ (int) $tks[0]['id'] ] ?? 0 ) > 0
                    || ( $att_ticket[ (int) $tks[1]['id'] ] ?? 0 ) > 0 );
                if ( $has_sales ) {
                    $review[] = [
                        'event_id' => $id,
                        'title'    => $e['post_title'],
                        'reason'   => 'Same-name ticket pair has sales or attendee rows; needs manual review.',
                    ];
                    continue;
                }

                $keep_idx = null;
                $reason   = '';
                if ( null !== $tec_price ) {
                    $m0 = abs( floatval( $tks[0]['price'] ) - $tec_price ) < 0.001;
                    $m1 = abs( floatval( $tks[1]['price'] ) - $tec_price ) < 0.001;
                    if ( $m0 && ! $m1 ) {
                        $keep_idx = 0;
                        $reason   = 'price matches current TEC product';
                    } elseif ( $m1 && ! $m0 ) {
                        $keep_idx = 1;
                        $reason   = 'price matches current TEC product';
                    }
                }
                if ( null === $keep_idx ) {
                    // Fallback: keep the newer ticket (later created_at, then higher ID).
                    $cmp      = strcmp( $tks[0]['created_at'], $tks[1]['created_at'] );
                    $keep_idx = ( $cmp < 0 || ( 0 === $cmp && (int) $tks[0]['id'] < (int) $tks[1]['id'] ) ) ? 1 : 0;
                    $reason  .= ( $reason ? '; ' : '' ) . 'fallback: newer ticket kept';
                }
                $del_idx       = 1 - $keep_idx;
                $stale_pairs[] = [
                    'event_id'     => $id,
                    'title'        => $e['post_title'],
                    'tec_price'    => $tec_price,
                    'keep_id'      => (int) $tks[ $keep_idx ]['id'],
                    'keep_price'   => floatval( $tks[ $keep_idx ]['price'] ),
                    'delete_id'    => (int) $tks[ $del_idx ]['id'],
                    'delete_price' => floatval( $tks[ $del_idx ]['price'] ),
                    'reason'       => $reason,
                ];
            } elseif ( 1 === count( $tks ) && null !== $tec_price && abs( floatval( $tks[0]['price'] ) - $tec_price ) >= 0.001 ) {
                $review[] = [
                    'event_id' => $id,
                    'title'    => $e['post_title'],
                    'reason'   => sprintf( 'Single ticket price %s differs from current TEC price %s.', $tks[0]['price'], $tec_price ),
                ];
            }
        }

        // Duplicate event groups by _tec_import_source_id.
        $rows   = $wpdb->get_results(
            "SELECT pm.meta_value AS tec_id, p.ID, p.post_title, p.post_status
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_tec_import_source_id'
               AND p.post_type = 'eventkoi_event'
               AND p.post_status NOT IN ('trash', 'auto-draft')
             ORDER BY p.ID ASC",
            ARRAY_A
        );
        $groups = [];
        foreach ( $rows as $r ) {
            $groups[ $r['tec_id'] ][] = $r;
        }

        $status_priority = [ 'publish' => 0, 'eventkoi_expired' => 1, 'draft' => 2 ];

        $member_ids = [];
        foreach ( $groups as $g ) {
            if ( count( $g ) > 1 ) {
                foreach ( $g as $m ) {
                    $member_ids[] = (int) $m['ID'];
                }
            }
        }
        $start_ts = [];
        $cals     = [];
        if ( $member_ids ) {
            $min  = implode( ',', $member_ids );
            $rows = $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = 'start_timestamp' AND post_id IN ({$min})",
                ARRAY_A
            );
            foreach ( $rows as $r ) {
                $start_ts[ (int) $r['post_id'] ] = $r['meta_value'];
            }
            $rows = $wpdb->get_results(
                "SELECT tr.object_id, t.name FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = 'event_cal' AND tr.object_id IN ({$min})",
                ARRAY_A
            );
            foreach ( $rows as $r ) {
                $cals[ (int) $r['object_id'] ][] = $r['name'];
            }
        }

        foreach ( $groups as $tec_id => $g ) {
            if ( count( $g ) < 2 ) {
                continue;
            }
            usort( $g, function ( $a, $b ) use ( $status_priority ) {
                $pa = $status_priority[ $a['post_status'] ] ?? 99;
                $pb = $status_priority[ $b['post_status'] ] ?? 99;
                if ( $pa !== $pb ) {
                    return $pa - $pb;
                }
                return (int) $a['ID'] - (int) $b['ID'];
            } );
            $ts_vals = array_unique( array_map( function ( $m ) use ( $start_ts ) {
                return $start_ts[ (int) $m['ID'] ] ?? null;
            }, $g ) );
            $members = [];
            foreach ( $g as $i => $m ) {
                $mid       = (int) $m['ID'];
                $members[] = [
                    'id'        => $mid,
                    'title'     => $m['post_title'],
                    'status'    => $m['post_status'],
                    'canonical' => ( 0 === $i ),
                    'start_ts'  => $start_ts[ $mid ] ?? null,
                    'tickets'   => count( $by_event[ $mid ] ?? [] ),
                    'attendees' => $att_event[ $mid ] ?? 0,
                    'cals'      => $cals[ $mid ] ?? [],
                ];
            }
            $dup_groups[] = [
                'tec_id'  => $tec_id,
                'flagged' => count( $ts_vals ) > 1,
                'members' => $members,
            ];
        }

        return [ 'stale_pairs' => $stale_pairs, 'review' => $review, 'dup_groups' => $dup_groups ];
    }

    /**
     * Append audit ops to the cleanup ledger.
     */
    private function audit_push( $ops ) {
        if ( empty( $ops ) ) {
            return;
        }
        $audit = get_option( 'ekti_cleanup_audit', [] );
        if ( ! is_array( $audit ) ) {
            $audit = [];
        }
        update_option( 'ekti_cleanup_audit', array_merge( $audit, $ops ), false );
    }

    /**
     * Phase 1: delete the first chunk of stale duplicate ticket types.
     *
     * The stale list shrinks as items are processed, so callers always pass
     * offset 0 and repeat until done.
     */
    private function run_ticket_dedupe( $chunk = 50 ) {
        global $wpdb;
        $scan  = $this->scan_cleanup();
        $pairs = array_slice( $scan['stale_pairs'], 0, $chunk );

        $results = [];
        $deleted = 0;
        $ops     = [];

        foreach ( $pairs as $p ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$this->ek_table( 'tickets' )} WHERE id = %d",
                $p['delete_id']
            ), ARRAY_A );
            if ( ! $row ) {
                $results[] = [ 'event_id' => $p['event_id'], 'action' => 'skipped', 'reason' => 'ticket already removed' ];
                continue;
            }
            $orders = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->ek_table( 'ticket_orders' )} WHERE ticket_id = %d",
                $p['delete_id']
            ) );
            if ( (int) $row['quantity_sold'] > 0 || $orders > 0 ) {
                $results[] = [ 'event_id' => $p['event_id'], 'action' => 'flagged', 'reason' => 'ticket has sales or orders' ];
                continue;
            }
            $wpdb->delete( $this->ek_table( 'tickets' ), [ 'id' => $row['id'] ] );
            $ops[] = [ 'type' => 'insert_ticket', 'row' => $row ];
            $deleted++;
            $results[] = [ 'event_id' => $p['event_id'], 'action' => 'deleted', 'ticket_id' => (int) $row['id'], 'keep_id' => $p['keep_id'] ];
            $this->log( "Cleanup P1: deleted stale ticket #{$row['id']} ({$row['price']}) on event {$p['event_id']}; kept #{$p['keep_id']} ({$p['keep_price']})." );
        }

        $this->audit_push( $ops );

        return [ 'processed' => count( $pairs ), 'deleted' => $deleted, 'done' => count( $pairs ) < $chunk, 'results' => $results ];
    }

    /**
     * Phase 2: merge the first chunk of duplicate event groups.
     */
    private function run_event_merge( $chunk = 25 ) {
        global $wpdb;
        $scan   = $this->scan_cleanup();
        $groups = array_slice( $scan['dup_groups'], 0, $chunk );

        $results = [];
        $merged  = 0;

        foreach ( $groups as $g ) {
            if ( $g['flagged'] ) {
                $results[] = [ 'tec_id' => $g['tec_id'], 'action' => 'flagged', 'reason' => 'divergent start timestamps' ];
                continue;
            }

            $canonical = null;
            $losers    = [];
            foreach ( $g['members'] as $m ) {
                if ( $m['canonical'] ) {
                    $canonical = $m['id'];
                } else {
                    $losers[] = $m['id'];
                }
            }

            $ops = [];
            $ok  = true;
            $wpdb->query( 'START TRANSACTION' );

            foreach ( $losers as $loser ) {
                $ok = $this->merge_loser_into( $canonical, $loser, $ops );
                if ( ! $ok ) {
                    break;
                }
            }

            if ( ! $ok ) {
                $wpdb->query( 'ROLLBACK' );
                $this->log( 'Cleanup P2: merge of TEC source ' . $g['tec_id'] . ' failed; rolled back. ' . $wpdb->last_error, 'ERROR' );
                $results[] = [ 'tec_id' => $g['tec_id'], 'action' => 'error', 'reason' => $wpdb->last_error ];
                continue;
            }

            $wpdb->query( 'COMMIT' );
            $this->audit_push( $ops );
            $merged++;
            $results[] = [ 'tec_id' => $g['tec_id'], 'action' => 'merged', 'canonical' => $canonical, 'losers' => $losers ];
            $this->log( 'Cleanup P2: merged TEC source ' . $g['tec_id'] . ' into canonical event ' . $canonical . ' (trashed: ' . implode( ',', $losers ) . ').' );
        }

        return [ 'processed' => count( $groups ), 'merged' => $merged, 'done' => count( $groups ) < $chunk, 'results' => $results ];
    }

    /**
     * Move all ticket data from $loser into $canonical and trash $loser.
     * Collects reversible audit ops into $ops. Returns false on DB error.
     */
    private function merge_loser_into( $canonical, $loser, &$ops ) {
        global $wpdb;
        $tickets_table       = $this->ek_table( 'tickets' );
        $ticket_orders_table = $this->ek_table( 'ticket_orders' );

        $canon_tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE event_id = %d",
            $canonical
        ), ARRAY_A );
        $loser_tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE event_id = %d",
            $loser
        ), ARRAY_A );

        $touched = []; // Canonical ticket IDs needing a quantity_sold recount.

        foreach ( $loser_tickets as $lt ) {
            $match = null;
            foreach ( $canon_tickets as $ct ) {
                if ( 0 === strcasecmp( trim( $ct['name'] ), trim( $lt['name'] ) )
                    && abs( floatval( $ct['price'] ) - floatval( $lt['price'] ) ) < 0.001 ) {
                    $match = $ct;
                    break;
                }
            }

            if ( $match ) {
                // Repoint attendee rows, then drop the duplicate ticket type.
                $moved = $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM {$ticket_orders_table} WHERE ticket_id = %d",
                    $lt['id']
                ) );
                if ( $moved ) {
                    if ( false === $wpdb->query( $wpdb->prepare(
                        "UPDATE {$ticket_orders_table} SET ticket_id = %d WHERE ticket_id = %d",
                        $match['id'], $lt['id']
                    ) ) ) {
                        return false;
                    }
                    $ops[] = [ 'type' => 'update_ticket_orders_ticket', 'ids' => array_map( 'intval', $moved ), 'old_ticket_id' => (int) $lt['id'] ];
                }
                if ( false === $wpdb->delete( $tickets_table, [ 'id' => $lt['id'] ] ) ) {
                    return false;
                }
                $ops[] = [ 'type' => 'insert_ticket', 'row' => $lt ];

                // Adopt quantity_available when the canonical ticket lacks one.
                if ( null === $match['quantity_available'] && null !== $lt['quantity_available'] ) {
                    if ( false === $wpdb->update( $tickets_table, [ 'quantity_available' => $lt['quantity_available'] ], [ 'id' => $match['id'] ] ) ) {
                        return false;
                    }
                    $ops[] = [ 'type' => 'update_ticket_fields', 'id' => (int) $match['id'], 'old' => [ 'quantity_available' => $match['quantity_available'] ] ];
                }
                $touched[ (int) $match['id'] ] = true;

                // Repoint importer product options that referenced the deleted ticket.
                $opt_names = $wpdb->get_col( $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value = %s",
                    '_ekti_tec_product_%',
                    $lt['id']
                ) );
                foreach ( $opt_names as $name ) {
                    $old = get_option( $name );
                    update_option( $name, (int) $match['id'] );
                    $ops[] = [ 'type' => 'update_option', 'name' => $name, 'old' => $old ];
                }
            } else {
                // No matching type on canonical: move the ticket over intact.
                if ( false === $wpdb->update( $tickets_table, [ 'event_id' => $canonical ], [ 'id' => $lt['id'] ] ) ) {
                    return false;
                }
                $ops[] = [ 'type' => 'update_ticket_event', 'id' => (int) $lt['id'], 'old_event_id' => $loser ];
                $touched[ (int) $lt['id'] ] = true;
            }
        }

        // Repoint any remaining attendee rows.
        $remaining = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$ticket_orders_table} WHERE event_id = %d",
            $loser
        ) );
        if ( $remaining ) {
            if ( false === $wpdb->query( $wpdb->prepare(
                "UPDATE {$ticket_orders_table} SET event_id = %d WHERE event_id = %d",
                $canonical, $loser
            ) ) ) {
                return false;
            }
            $ops[] = [ 'type' => 'update_ticket_orders_event', 'ids' => array_map( 'intval', $remaining ), 'old_event_id' => $loser ];
        }

        // Recount quantity_sold on touched tickets.
        foreach ( array_keys( $touched ) as $tid ) {
            $cur  = $wpdb->get_row( $wpdb->prepare(
                "SELECT quantity_sold FROM {$tickets_table} WHERE id = %d",
                $tid
            ), ARRAY_A );
            $sold = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(quantity), 0) FROM {$ticket_orders_table}
                 WHERE ticket_id = %d
                   AND payment_status IN ('complete', 'completed', 'succeeded', 'partially_refunded')",
                $tid
            ) );
            if ( (int) $cur['quantity_sold'] !== $sold ) {
                if ( false === $wpdb->update( $tickets_table, [ 'quantity_sold' => $sold ], [ 'id' => $tid ] ) ) {
                    return false;
                }
                $ops[] = [ 'type' => 'update_ticket_fields', 'id' => $tid, 'old' => [ 'quantity_sold' => $cur['quantity_sold'] ] ];
            }
        }

        // Union calendars onto canonical (append-only).
        $loser_cals = wp_get_object_terms( $loser, 'event_cal', [ 'fields' => 'ids' ] );
        $canon_cals = wp_get_object_terms( $canonical, 'event_cal', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $loser_cals ) && ! is_wp_error( $canon_cals ) ) {
            $add = array_diff( array_map( 'intval', $loser_cals ), array_map( 'intval', $canon_cals ) );
            if ( $add ) {
                wp_set_object_terms( $canonical, array_values( $add ), 'event_cal', true );
                $ops[] = [ 'type' => 'set_terms', 'post' => $canonical, 'old_term_ids' => array_map( 'intval', $canon_cals ) ];
            }
        }

        // Repoint WC order meta.
        $order_posts = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_eventkoi_event_id' AND meta_value = %s",
            $loser
        ) );
        foreach ( $order_posts as $post_id ) {
            update_post_meta( (int) $post_id, '_eventkoi_event_id', $canonical );
            $ops[] = [ 'type' => 'update_postmeta', 'post' => (int) $post_id, 'key' => '_eventkoi_event_id', 'old' => $loser ];
        }

        // Repoint saved mapping entries that target the loser.
        $old_mapping = $this->get_saved_mapping();
        $new_mapping = $old_mapping;
        foreach ( $new_mapping as $tec_id => $ek_id ) {
            if ( (int) $ek_id === $loser ) {
                $new_mapping[ $tec_id ] = $canonical;
            }
        }
        if ( $new_mapping !== $old_mapping ) {
            $this->save_mapping( $new_mapping );
            $ops[] = [ 'type' => 'update_option', 'name' => 'ekti_event_mapping', 'old' => $old_mapping ];
        }

        // Trash the loser (recoverable; never hard-delete).
        if ( ! wp_trash_post( $loser ) ) {
            return false;
        }
        $ops[] = [ 'type' => 'untrash_post', 'id' => $loser ];

        return true;
    }

    /**
     * Undo the last cleanup by replaying the audit ledger in reverse.
     */
    private function undo_cleanup() {
        $audit = get_option( 'ekti_cleanup_audit', [] );
        if ( empty( $audit ) ) {
            return [ 'error' => 'Nothing to undo.' ];
        }
        $counts = $this->replay_audit_ops( $audit );
        delete_option( 'ekti_cleanup_audit' );
        $this->log( 'Cleanup undo complete. ' . wp_json_encode( $counts ) );
        return [ 'undone' => $counts ];
    }

    /**
     * Undo the last ticket details sync (separate ledger from cleanup so
     * undoing a sync never replays the verified pre-import cleanup).
     */
    private function undo_sync() {
        $audit = get_option( 'ekti_sync_audit', [] );
        if ( empty( $audit ) ) {
            return [ 'error' => 'Nothing to undo.' ];
        }
        $counts = $this->replay_audit_ops( $audit );
        delete_option( 'ekti_sync_audit' );
        $this->log( 'Ticket details sync undo complete. ' . wp_json_encode( $counts ) );
        return [ 'undone' => $counts ];
    }

    /**
     * Replay a list of audit ops in reverse. Shared by cleanup and sync undo.
     */
    private function replay_audit_ops( $audit ) {
        global $wpdb;
        $counts = [];
        foreach ( array_reverse( $audit ) as $op ) {
            switch ( $op['type'] ) {
                case 'insert_ticket':
                    $wpdb->insert( $this->ek_table( 'tickets' ), $op['row'] );
                    break;
                case 'delete_ticket':
                    $wpdb->delete( $this->ek_table( 'tickets' ), [ 'id' => $op['id'] ] );
                    break;
                case 'update_ticket_event':
                    $wpdb->update( $this->ek_table( 'tickets' ), [ 'event_id' => $op['old_event_id'] ], [ 'id' => $op['id'] ] );
                    break;
                case 'update_ticket_fields':
                    $wpdb->update( $this->ek_table( 'tickets' ), $op['old'], [ 'id' => $op['id'] ] );
                    break;
                case 'update_ticket_orders_ticket':
                    foreach ( $op['ids'] as $oid ) {
                        $wpdb->update( $this->ek_table( 'ticket_orders' ), [ 'ticket_id' => $op['old_ticket_id'] ], [ 'id' => $oid ] );
                    }
                    break;
                case 'update_ticket_orders_event':
                    foreach ( $op['ids'] as $oid ) {
                        $wpdb->update( $this->ek_table( 'ticket_orders' ), [ 'event_id' => $op['old_event_id'] ], [ 'id' => $oid ] );
                    }
                    break;
                case 'update_option':
                    update_option( $op['name'], $op['old'] );
                    break;
                case 'add_option':
                    delete_option( $op['name'] );
                    break;
                case 'update_postmeta':
                    update_post_meta( $op['post'], $op['key'], $op['old'] );
                    break;
                case 'set_terms':
                    $term_taxonomy = ! empty( $op['taxonomy'] ) ? $op['taxonomy'] : 'event_cal';
                    wp_set_object_terms( $op['post'], $op['old_term_ids'], $term_taxonomy, false );
                    break;
                case 'untrash_post':
                    wp_untrash_post( $op['id'] );
                    break;
                case 'create_term':
                    $term_taxonomy = ! empty( $op['taxonomy'] ) ? $op['taxonomy'] : 'event_cal';
                    wp_delete_term( $op['term_id'], $term_taxonomy );
                    break;
                case 'set_termmeta':
                    if ( null === $op['old'] ) {
                        delete_term_meta( (int) $op['term_id'], $op['key'] );
                    } else {
                        update_term_meta( (int) $op['term_id'], $op['key'], $op['old'] );
                    }
                    break;
                // Event Sync (Step 14) ops — rows this sync created are removed.
                case 'insert_post':
                    $post_id = (int) $op['id'];
                    // Hard delete: this sync created the post (undo must not
                    // leave a trashed copy behind to re-match against).
                    wp_delete_post( $post_id, true );
                    // Residual sweep AFTER the delete (so any EventKoi hooks
                    // firing during deletion cannot re-add rows afterwards):
                    // EventKoi data keyed to the event that does not cascade
                    // with the post. Parent orders are keyed by checkout_id,
                    // the base of the seat-scoped ticket_orders.order_id.
                    $order_ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT DISTINCT order_id FROM {$this->ek_table( 'ticket_orders' )} WHERE event_id = %d",
                        $post_id
                    ) );
                    foreach ( (array) $order_ids as $order_id ) {
                        $base = strstr( (string) $order_id, ':', true );
                        $base = false === $base ? (string) $order_id : $base;
                        $order_row_id = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT id FROM {$this->ek_table( 'orders' )} WHERE checkout_id = %s",
                            $base
                        ) );
                        if ( $order_row_id ) {
                            $wpdb->delete( $this->ek_table( 'order_notes' ), [ 'order_id' => $order_row_id ] );
                            $wpdb->delete( $this->ek_table( 'orders' ), [ 'id' => $order_row_id ] );
                        }
                    }
                    $wpdb->delete( $this->ek_table( 'ticket_orders' ), [ 'event_id' => $post_id ] );
                    $wpdb->delete( $this->ek_table( 'rsvps' ), [ 'event_id' => $post_id ] );
                    $wpdb->delete( $this->ek_table( 'tickets' ), [ 'event_id' => $post_id ] );
                    $wpdb->delete( $this->ek_table( 'upcoming_index' ), [ 'event_id' => $post_id ] );
                    break;
                case 'insert_rsvp':
                    $wpdb->delete( $this->ek_table( 'rsvps' ), [ 'id' => (int) $op['id'] ] );
                    break;
                case 'insert_ticket_order':
                    foreach ( (array) $op['note_ids'] as $note_id ) {
                        $wpdb->delete( $this->ek_table( 'order_notes' ), [ 'id' => (int) $note_id ] );
                    }
                    if ( ! empty( $op['order_row_id'] ) ) {
                        $wpdb->delete( $this->ek_table( 'orders' ), [ 'id' => (int) $op['order_row_id'] ] );
                    }
                    foreach ( (array) $op['ticket_order_ids'] as $row_id ) {
                        $wpdb->delete( $this->ek_table( 'ticket_orders' ), [ 'id' => (int) $row_id ] );
                    }
                    // create_manual_order overwrites quantity_sold with the
                    // complete-order recount; run the same recount now that
                    // this order's rows are gone (harmless when the ticket row
                    // itself is deleted next — reverse replay order).
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$this->ek_table( 'tickets' )} t
                         SET t.quantity_sold = (
                             SELECT COALESCE( SUM( o.quantity ), 0 )
                             FROM {$this->ek_table( 'ticket_orders' )} o
                             WHERE o.ticket_id = t.id
                               AND o.event_id = t.event_id
                               AND o.payment_status IN ( 'complete', 'completed', 'succeeded', 'paid', 'partially_refunded' )
                         )
                         WHERE t.id = %d",
                        (int) $op['ticket_id']
                    ) );
                    break;
                case 'insert_field':
                    $wpdb->delete( $this->ek_table( 'fields' ), [ 'id' => (int) $op['id'] ] );
                    break;
                case 'insert_field_group':
                    $wpdb->delete( $this->ek_table( 'field_groups' ), [ 'id' => (int) $op['id'] ] );
                    break;
            }
            $counts[ $op['type'] ] = ( $counts[ $op['type'] ] ?? 0 ) + 1;
        }
        return $counts;
    }

    /**
     * Append audit ops to the sync ledger.
     */
    private function audit_push_sync( $ops ) {
        if ( empty( $ops ) ) {
            return;
        }
        $audit = get_option( 'ekti_sync_audit', [] );
        if ( ! is_array( $audit ) ) {
            $audit = [];
        }
        update_option( 'ekti_sync_audit', array_merge( $audit, $ops ), false );
    }

    /* ------------------------------------------------------------------
     * STEP 7 — TICKET DETAILS SYNC
     *
     * Backfills sale_start (event publish time), sale_end (6 AM on the day
     * before the event) and quantity_available (TEC ticket capacity) on
     * EventKoi ticket rows, and reconciles multi-product TEC events into
     * distinct EventKoi ticket types. EventKoi stores the sale window as
     * UTC 'Y-m-d H:i:s' strings (it parses them with strtotime( $v . ' UTC' )).
     * ------------------------------------------------------------------ */

    /**
     * Bulk-resolve TEC event => WC ticket products.
     *
     * Products are discovered via the product-side backlink
     * (_tribe_wooticket_for_event) first, then attendee rows
     * (_tribe_wooticket_product). Returns tec_id => list of
     * [ 'id', 'name', 'price', 'capacity' ] sorted by product ID.
     */
    private function get_tec_product_map() {
        global $wpdb;

        $pairs = [];
        $rows  = $wpdb->get_results(
            "SELECT pm.meta_value AS tec_id, pm.post_id AS product_id
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_tribe_wooticket_for_event'
               AND p.post_type = 'product'
               AND p.post_status NOT IN ('trash', 'auto-draft')",
            ARRAY_A
        );
        foreach ( $rows as $r ) {
            $pairs[ (int) $r['tec_id'] ][ (int) $r['product_id'] ] = true;
        }
        $rows = $wpdb->get_results(
            "SELECT DISTINCT pm1.meta_value AS tec_id, pm2.meta_value AS product_id
             FROM {$wpdb->postmeta} pm1
             JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
             WHERE pm1.meta_key = '_tribe_wooticket_event'
               AND pm2.meta_key = '_tribe_wooticket_product'",
            ARRAY_A
        );
        foreach ( $rows as $r ) {
            $pairs[ (int) $r['tec_id'] ][ (int) $r['product_id'] ] = true;
        }

        $product_ids = [];
        foreach ( $pairs as $prods ) {
            foreach ( array_keys( $prods ) as $pid ) {
                $product_ids[ $pid ] = true;
            }
        }

        $titles = [];
        $meta   = [];
        if ( $product_ids ) {
            $in   = implode( ',', array_keys( $product_ids ) );
            $rows = $wpdb->get_results(
                "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$in})",
                ARRAY_A
            );
            foreach ( $rows as $r ) {
                $titles[ (int) $r['ID'] ] = $r['post_title'];
            }
            $rows = $wpdb->get_results(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key IN ('_price', '_tribe_ticket_capacity') AND post_id IN ({$in})",
                ARRAY_A
            );
            foreach ( $rows as $r ) {
                $meta[ (int) $r['post_id'] ][ $r['meta_key'] ] = $r['meta_value'];
            }
        }

        $map = [];
        foreach ( $pairs as $tec_id => $prods ) {
            $list = [];
            foreach ( array_keys( $prods ) as $pid ) {
                $m      = $meta[ $pid ] ?? [];
                $list[] = [
                    'id'       => $pid,
                    'name'     => $titles[ $pid ] ?? '',
                    'price'    => isset( $m['_price'] ) ? floatval( $m['_price'] ) : null,
                    'capacity' => $m['_tribe_ticket_capacity'] ?? '',
                ];
            }
            usort( $list, function ( $a, $b ) {
                return $a['id'] - $b['id'];
            } );
            $map[ $tec_id ] = $list;
        }
        ksort( $map );
        return $map;
    }

    /**
     * Convert a site-local datetime string to a UTC 'Y-m-d H:i:s' string.
     */
    private function local_to_utc( $local ) {
        $local = trim( (string) $local );
        if ( '' === $local ) {
            return null;
        }
        try {
            $dt = new DateTime( $local, wp_timezone() );
        } catch ( Exception $e ) {
            return null;
        }
        $dt->setTimezone( new DateTimeZone( 'UTC' ) );
        return $dt->format( 'Y-m-d H:i:s' );
    }

    /**
     * Convert a UTC 'Y-m-d H:i:s' string to site-local (for display only).
     */
    private function utc_to_local( $utc ) {
        if ( ! $utc ) {
            return null;
        }
        try {
            $dt = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
            $dt->setTimezone( wp_timezone() );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return $utc;
        }
    }

    /**
     * Compute the proposed sale window for an event (UTC strings).
     *
     * sale_start = event publish time (TEC post_date, EventKoi fallback).
     * sale_end   = 6 AM site time on the day before the event start date.
     * An inverted window is reported for review and skipped.
     */
    private function compute_sale_window( $tec_post_date, $ek_post_date, $start_ts ) {
        $sale_start = $this->local_to_utc( $tec_post_date ?: $ek_post_date );
        $sale_end   = null;
        if ( $start_ts ) {
            try {
                $dt  = new DateTime( '@' . (int) $start_ts );
                $dt->setTimezone( wp_timezone() );
                $end = new DateTime( $dt->format( 'Y-m-d' ) . ' 06:00:00', wp_timezone() );
                $end->modify( '-1 day' );
                $end->setTimezone( new DateTimeZone( 'UTC' ) );
                $sale_end = $end->format( 'Y-m-d H:i:s' );
            } catch ( Exception $e ) {
                $sale_end = null;
            }
        }
        $review = null;
        if ( $sale_start && $sale_end && strcmp( $sale_end, $sale_start ) <= 0 ) {
            $review     = 'Computed sale end (' . $sale_end . ' UTC) not after sale start (' . $sale_start . ' UTC); window skipped.';
            $sale_start = null;
            $sale_end   = null;
        }
        return [ 'sale_start' => $sale_start, 'sale_end' => $sale_end, 'review' => $review ];
    }

    /**
     * Proposed field defaults for a freshly created ticket type.
     */
    private function ticket_defaults( $tec_event_id, $ek_event_id, $product_id ) {
        $cap    = get_post_meta( $product_id, '_tribe_ticket_capacity', true );
        $window = $this->compute_sale_window_for_event( $tec_event_id, $ek_event_id );
        return [
            'quantity_available' => ( '' !== $cap && is_numeric( $cap ) && (int) $cap >= 0 ) ? (int) $cap : null,
            'sale_start'         => $window['sale_start'],
            'sale_end'           => $window['sale_end'],
        ];
    }

    /**
     * Resolve the sale window for a TEC/EventKoi event pair from live data.
     */
    private function compute_sale_window_for_event( $tec_event_id, $ek_event_id ) {
        $tec_post_date = null;
        $tec           = get_post( $tec_event_id );
        if ( $tec && 'trash' !== $tec->post_status ) {
            $tec_post_date = $tec->post_date;
        }
        $ek_post_date = null;
        $start_ts     = (int) get_post_meta( $ek_event_id, 'start_timestamp', true );
        $ek           = get_post( $ek_event_id );
        if ( $ek ) {
            $ek_post_date = $ek->post_date;
        }
        if ( ! $start_ts && $tec ) {
            $esd = get_post_meta( $tec_event_id, '_EventStartDate', true );
            $utc = $this->local_to_utc( $esd );
            if ( $utc ) {
                $start_ts = (int) strtotime( $utc . ' UTC' );
            }
        }
        return $this->compute_sale_window( $tec_post_date, $ek_post_date, $start_ts );
    }

    /**
     * Queue per-ticket field backfill items (NULL-fill only) onto a scan.
     */
    private function queue_field_updates( &$items, &$counts, $t, $window, $cap ) {
        $fields    = [];
        $conflicts = [];
        $proposed  = [
            'quantity_available' => $cap,
            'sale_start'         => $window['sale_start'],
            'sale_end'           => $window['sale_end'],
        ];
        foreach ( $proposed as $field => $new ) {
            if ( null === $new ) {
                continue;
            }
            $cur = $t[ $field ];
            if ( null === $cur || '' === $cur ) {
                $fields[ $field ] = $new;
            } elseif ( (string) $cur !== (string) $new ) {
                $conflicts[] = $field . ' is ' . $cur . ', proposed ' . $new;
            }
        }
        if ( $conflicts ) {
            $items[] = [ 'type' => 'review', 'reason' => 'Ticket #' . $t['id'] . ' non-NULL conflict: ' . implode( '; ', $conflicts ) ];
            $counts['reviews']++;
        }
        if ( ! $fields ) {
            return;
        }
        $display = [];
        foreach ( $fields as $field => $new ) {
            $cur_l   = ( 'sale_start' === $field || 'sale_end' === $field ) ? $this->utc_to_local( $t[ $field ] ) : $t[ $field ];
            $new_l   = ( 'sale_start' === $field || 'sale_end' === $field ) ? $this->utc_to_local( $new ) : $new;
            $display[] = $field . ': ' . ( null === $cur_l || '' === $cur_l ? 'NULL' : $cur_l ) . ' → ' . $new_l;
        }
        $items[] = [
            'type'           => 'update',
            'ticket_id'      => (int) $t['id'],
            'ticket_display' => html_entity_decode( $t['name'] ),
            'fields'         => $fields,
            'changes_display'=> implode( '; ', $display ),
        ];
        $counts['updates']++;
    }

    /**
     * Scan mapped events for ticket details sync work (read-only).
     *
     * Returns per-event items (create / convert / bind / update / review)
     * plus aggregate counts. Events are keyed off the canonical
     * _tec_import_source_id resolver so Auto-Match and sync agree.
     */
    private function scan_ticket_details() {
        global $wpdb;

        $counts = [ 'creates' => 0, 'converts' => 0, 'binds' => 0, 'updates' => 0, 'reviews' => 0, 'pending_events' => 0 ];
        $empty  = [ 'events' => [], 'counts' => $counts ];

        $source_map = $this->get_tec_import_source_mapping();
        if ( empty( $source_map ) ) {
            return $empty;
        }
        ksort( $source_map );

        $tec_ids = array_map( 'intval', array_keys( $source_map ) );
        $ek_ids  = array_values( array_unique( array_map( 'intval', $source_map ) ) );
        $in_tec  = implode( ',', $tec_ids );
        $in_ek   = implode( ',', $ek_ids );

        // Titles + publish dates on both sides.
        $ek_posts = [];
        foreach ( $wpdb->get_results( "SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE ID IN ({$in_ek})", ARRAY_A ) as $r ) {
            $ek_posts[ (int) $r['ID'] ] = $r;
        }
        $tec_dates = [];
        foreach ( $wpdb->get_results( "SELECT ID, post_date FROM {$wpdb->posts} WHERE ID IN ({$in_tec})", ARRAY_A ) as $r ) {
            $tec_dates[ (int) $r['ID'] ] = $r['post_date'];
        }

        // Event start timestamps (EK meta, TEC _EventStartDate fallback).
        $start_ts = [];
        foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'start_timestamp' AND post_id IN ({$in_ek})", ARRAY_A ) as $r ) {
            $start_ts[ (int) $r['post_id'] ] = (int) $r['meta_value'];
        }
        $tec_start_dates = [];
        foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_EventStartDate' AND post_id IN ({$in_tec})", ARRAY_A ) as $r ) {
            $tec_start_dates[ (int) $r['post_id'] ] = $r['meta_value'];
        }

        // Event-level TEC capacity fallback for product-less events.
        $event_caps = [];
        foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tribe_ticket_capacity' AND post_id IN ({$in_tec})", ARRAY_A ) as $r ) {
            $event_caps[ (int) $r['post_id'] ] = $r['meta_value'];
        }

        // Existing tickets per EventKoi event.
        $tickets      = [];
        $all_ticket_ids = [];
        foreach ( $wpdb->get_results( "SELECT id, event_id, name, price, quantity_available, quantity_sold, sale_start, sale_end FROM {$this->ek_table( 'tickets' )} WHERE event_id IN ({$in_ek}) ORDER BY event_id ASC, id ASC", ARRAY_A ) as $t ) {
            $tickets[ (int) $t['event_id'] ][] = $t;
            $all_ticket_ids[] = (int) $t['id'];
        }
        $orders_count = [];
        if ( $all_ticket_ids ) {
            $in_t = implode( ',', $all_ticket_ids );
            foreach ( $wpdb->get_results( "SELECT ticket_id, COUNT(*) c FROM {$this->ek_table( 'ticket_orders' )} WHERE ticket_id IN ({$in_t}) GROUP BY ticket_id", ARRAY_A ) as $r ) {
                $orders_count[ (int) $r['ticket_id'] ] = (int) $r['c'];
            }
        }

        $product_map = $this->get_tec_product_map();
        $capacity_of = function ( $prod ) {
            $c = $prod['capacity'];
            return ( '' !== $c && is_numeric( $c ) && (int) $c >= 0 ) ? (int) $c : null;
        };

        $events = [];
        foreach ( $source_map as $tec_key => $ek_key ) {
            $tec_id = (int) $tec_key;
            $ek_id  = (int) $ek_key;
            $tks    = $tickets[ $ek_id ] ?? [];
            $prods  = $product_map[ $tec_id ] ?? [];

            $ts = $start_ts[ $ek_id ] ?? 0;
            if ( ! $ts && isset( $tec_start_dates[ $tec_id ] ) ) {
                $utc = $this->local_to_utc( $tec_start_dates[ $tec_id ] );
                if ( $utc ) {
                    $ts = (int) strtotime( $utc . ' UTC' );
                }
            }
            $window = $this->compute_sale_window( $tec_dates[ $tec_id ] ?? null, $ek_posts[ $ek_id ]['post_date'] ?? null, $ts );

            $items = [];
            if ( $window['review'] ) {
                $items[] = [ 'type' => 'review', 'reason' => $window['review'] ];
                $counts['reviews']++;
            }

            if ( $prods ) {
                $assigned = [];
                foreach ( $prods as $prod ) {
                    $target  = null;
                    $convert = false;

                    // 1) Existing ticket with the same name.
                    foreach ( $tks as $t ) {
                        if ( ! isset( $assigned[ (int) $t['id'] ] ) && 0 === strcasecmp( trim( $t['name'] ), trim( $prod['name'] ) ) ) {
                            $target = $t;
                            break;
                        }
                    }
                    // 2) Single product + single ticket: bind as-is (no rename).
                    if ( ! $target && 1 === count( $prods ) && 1 === count( $tks ) ) {
                        $target = $tks[0];
                    }
                    // 3) Multi-product: convert an unsold price-matching placeholder.
                    if ( ! $target && count( $prods ) > 1 ) {
                        foreach ( $tks as $t ) {
                            if ( isset( $assigned[ (int) $t['id'] ] ) ) {
                                continue;
                            }
                            if ( null !== $prod['price']
                                && abs( floatval( $t['price'] ) - $prod['price'] ) < 0.001
                                && 0 === (int) $t['quantity_sold']
                                && 0 === ( $orders_count[ (int) $t['id'] ] ?? 0 ) ) {
                                $target  = $t;
                                $convert = true;
                                break;
                            }
                        }
                    }
                    // 4) Create the missing ticket type.
                    if ( ! $target ) {
                        $items[] = [
                            'type'         => 'create',
                            'product_id'   => $prod['id'],
                            'name'         => $prod['name'],
                            'name_display' => html_entity_decode( $prod['name'] ),
                            'price'        => $prod['price'],
                            'capacity'     => $capacity_of( $prod ),
                            'sale_start'   => $window['sale_start'],
                            'sale_end'     => $window['sale_end'],
                            'sale_start_l' => $this->utc_to_local( $window['sale_start'] ),
                            'sale_end_l'   => $this->utc_to_local( $window['sale_end'] ),
                        ];
                        $counts['creates']++;
                        $items[] = [ 'type' => 'bind', 'product_id' => $prod['id'], 'ticket_id' => null, 'mode' => 'new' ];
                        $counts['binds']++;
                        continue;
                    }

                    if ( $convert ) {
                        $items[] = [
                            'type'         => 'convert',
                            'ticket_id'    => (int) $target['id'],
                            'product_id'   => $prod['id'],
                            'name'         => $prod['name'],
                            'from_display' => html_entity_decode( $target['name'] ),
                            'to_display'   => html_entity_decode( $prod['name'] ),
                        ];
                        $counts['converts']++;
                    }
                    $assigned[ (int) $target['id'] ] = true;

                    // Bind the product => ticket option for the attendee import.
                    $opt = '_ekti_tec_product_' . $tec_id . '_' . $prod['id'];
                    $cur = get_option( $opt );
                    if ( (int) $cur !== (int) $target['id'] ) {
                        $items[] = [ 'type' => 'bind', 'product_id' => $prod['id'], 'ticket_id' => (int) $target['id'], 'mode' => $cur ? 'rebind' : 'new' ];
                        $counts['binds']++;
                    }

                    $this->queue_field_updates( $items, $counts, $target, $window, $capacity_of( $prod ) );
                }

                // Leftover placeholders on multi-product events.
                if ( count( $prods ) > 1 ) {
                    foreach ( $tks as $t ) {
                        if ( ! isset( $assigned[ (int) $t['id'] ] ) ) {
                            $items[] = [ 'type' => 'review', 'reason' => 'Ticket #' . $t['id'] . ' "' . html_entity_decode( $t['name'] ) . '" left over after reconciliation; review manually.' ];
                            $counts['reviews']++;
                        }
                    }
                }
            } else {
                // No WC product: capacity falls back to event-level meta.
                $cap = isset( $event_caps[ $tec_id ] ) && is_numeric( $event_caps[ $tec_id ] ) && (int) $event_caps[ $tec_id ] >= 0 ? (int) $event_caps[ $tec_id ] : null;
                foreach ( $tks as $t ) {
                    $this->queue_field_updates( $items, $counts, $t, $window, $cap );
                }
                if ( ! $tks ) {
                    $items[] = [ 'type' => 'review', 'reason' => 'No EventKoi tickets and no WC products for this event.' ];
                    $counts['reviews']++;
                }
            }

            $actionable = false;
            foreach ( $items as $it ) {
                if ( in_array( $it['type'], [ 'create', 'convert', 'bind', 'update' ], true ) ) {
                    $actionable = true;
                    break;
                }
            }
            if ( $items ) {
                if ( $actionable ) {
                    $counts['pending_events']++;
                }
                $events[] = [
                    'tec_id'     => $tec_id,
                    'ek_id'      => $ek_id,
                    'title'      => html_entity_decode( $ek_posts[ $ek_id ]['post_title'] ?? ( '#' . $ek_id ) ),
                    'items'      => $items,
                    'actionable' => $actionable,
                ];
            }
        }

        return [ 'events' => $events, 'counts' => $counts ];
    }

    /**
     * Apply a field update to one ticket row, recording a reversible op.
     */
    private function apply_ticket_update( $ticket_id, $fields, &$ops ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->ek_table( 'tickets' )} WHERE id = %d",
            $ticket_id
        ), ARRAY_A );
        if ( ! $row ) {
            return false;
        }
        $changed = [];
        $old     = [];
        foreach ( $fields as $key => $value ) {
            if ( (string) $row[ $key ] !== (string) $value ) {
                $changed[ $key ] = $value;
                $old[ $key ]     = $row[ $key ];
            }
        }
        if ( ! $changed ) {
            return true;
        }
        $changed['updated_at'] = current_time( 'mysql' );
        if ( false === $wpdb->update( $this->ek_table( 'tickets' ), $changed, [ 'id' => $ticket_id ] ) ) {
            return false;
        }
        $ops[] = [ 'type' => 'update_ticket_fields', 'id' => (int) $ticket_id, 'old' => $old ];
        return true;
    }

    /**
     * Run the first chunk of pending ticket details sync work.
     *
     * The pending list shrinks as events are processed, so callers always
     * repeat until done. Every write is recorded in the ekti_sync_audit
     * ledger so the sync can be undone.
     */
    private function run_ticket_details_sync( $chunk = 50 ) {
        global $wpdb;
        $scan    = $this->scan_ticket_details();
        $pending = array_values( array_filter( $scan['events'], function ( $e ) {
            return $e['actionable'];
        } ) );
        $batch   = array_slice( $pending, 0, $chunk );

        $results = [];
        $tally   = [ 'created' => 0, 'converted' => 0, 'bound' => 0, 'updated' => 0 ];

        foreach ( $batch as $e ) {
            $event_ops   = [];
            $ok          = true;
            $last_create = null;
            $sort_base   = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(MAX(sort_order), 0) FROM {$this->ek_table( 'tickets' )} WHERE event_id = %d",
                $e['ek_id']
            ) );
            $event_tally = [ 'created' => 0, 'converted' => 0, 'bound' => 0, 'updated' => 0 ];

            $wpdb->query( 'START TRANSACTION' );
            foreach ( $e['items'] as $item ) {
                if ( ! $ok ) {
                    break;
                }
                switch ( $item['type'] ) {
                    case 'create':
                        $now = current_time( 'mysql' );
                        $sort_base++;
                        $ok = (bool) $wpdb->insert( $this->ek_table( 'tickets' ), [
                            'event_id'         => $e['ek_id'],
                            'name'             => sanitize_text_field( $item['name'] ),
                            'description'      => '',
                            'price'            => $item['price'],
                            'currency'         => 'USD',
                            'quantity_available' => $item['capacity'],
                            'max_per_order'    => null,
                            'quantity_sold'    => 0,
                            'sale_start'       => $item['sale_start'],
                            'sale_end'         => $item['sale_end'],
                            'terms_conditions' => null,
                            'status'           => 'active',
                            'sort_order'       => $sort_base,
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ], [ '%d', '%s', '%s', '%f', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );
                        if ( $ok ) {
                            $last_create = (int) $wpdb->insert_id;
                            $event_ops[] = [ 'type' => 'delete_ticket', 'id' => $last_create ];
                            $event_tally['created']++;
                            $this->log( "Sync: created ticket type \"{$item['name']}\" (#{$last_create}) on EventKoi event {$e['ek_id']}." );
                        }
                        break;
                    case 'convert':
                        // Re-verify hard guards at execution time.
                        $orders = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$this->ek_table( 'ticket_orders' )} WHERE ticket_id = %d",
                            $item['ticket_id']
                        ) );
                        $cur = $wpdb->get_row( $wpdb->prepare(
                            "SELECT quantity_sold FROM {$this->ek_table( 'tickets' )} WHERE id = %d",
                            $item['ticket_id']
                        ), ARRAY_A );
                        if ( ! $cur || (int) $cur['quantity_sold'] > 0 || $orders > 0 ) {
                            $ok = false;
                            break;
                        }
                        $ok = $this->apply_ticket_update( $item['ticket_id'], [ 'name' => $item['name'] ], $event_ops );
                        if ( $ok ) {
                            $event_tally['converted']++;
                            $this->log( "Sync: converted ticket #{$item['ticket_id']} to \"{$item['name']}\" on event {$e['ek_id']}." );
                        }
                        break;
                    case 'bind':
                        $tid = $item['ticket_id'] ?? $last_create;
                        if ( ! $tid ) {
                            $ok = false;
                            break;
                        }
                        $opt = '_ekti_tec_product_' . $e['tec_id'] . '_' . $item['product_id'];
                        $old = get_option( $opt );
                        update_option( $opt, (int) $tid );
                        $event_ops[] = $old ? [ 'type' => 'update_option', 'name' => $opt, 'old' => $old ] : [ 'type' => 'add_option', 'name' => $opt ];
                        $event_tally['bound']++;
                        break;
                    case 'update':
                        $ok = $this->apply_ticket_update( $item['ticket_id'], $item['fields'], $event_ops );
                        if ( $ok ) {
                            $event_tally['updated']++;
                        }
                        break;
                }
            }

            if ( ! $ok ) {
                $wpdb->query( 'ROLLBACK' );
                $this->log( 'Sync: event ' . $e['ek_id'] . ' failed; rolled back. ' . $wpdb->last_error, 'ERROR' );
                $results[] = [ 'tec_id' => $e['tec_id'], 'ek_id' => $e['ek_id'], 'action' => 'error', 'reason' => $wpdb->last_error ?: 'guard failed' ];
                continue;
            }

            $wpdb->query( 'COMMIT' );
            $this->audit_push_sync( $event_ops );
            foreach ( $event_tally as $k => $v ) {
                $tally[ $k ] += $v;
            }
            $results[] = array_merge( [ 'tec_id' => $e['tec_id'], 'ek_id' => $e['ek_id'], 'action' => 'synced', 'title' => $e['title'] ], $event_tally );
        }

        return [
            'processed' => count( $batch ),
            'tally'     => $tally,
            'done'      => count( $pending ) <= count( $batch ),
            'results'   => $results,
        ];
    }

    /* ------------------------------------------------------------------
     * STEP 8 — CALENDAR SYNC (LOCATIONS)
     *
     * Maps The Events Calendar locations (tribe_venue posts referenced
     * via the _EventVenueID event meta, falling back to the location
     * name already imported into EventKoi's `location` post meta) onto
     * EventKoi calendars (event_cal taxonomy terms), then union-appends
     * the mapped calendar onto each mapped EventKoi event. Append-only
     * (existing calendars are never removed) and idempotent.
     * ------------------------------------------------------------------ */

    /**
     * Normalize a location/calendar name into a comparison key.
     *
     * Decodes entities ("San Antonio &#8211; Central"), unifies en/em
     * dashes with hyphens, lowercases, and collapses everything else
     * into single hyphens so keys are sanitize_key()-compatible.
     */
    private function normalize_location_name( $name ) {
        $name = html_entity_decode( (string) $name, ENT_QUOTES );
        $name = str_replace( [ "\u{2013}", "\u{2014}" ], '-', $name );
        $name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
        $name = preg_replace( '/[^a-z0-9]+/', '-', $name );
        return trim( $name, '-' );
    }

    /**
     * Collect distinct locations across the saved event mapping.
     *
     * Primary source: TEC venue post title via _EventVenueID meta.
     * Fallback: the location name left on the EventKoi event by
     * EventKoi's native importer (`location` meta array, `name` key).
     * Returns key => [ name, venue_id, events (tec_id => ek_id) ].
     */
    private function get_tec_locations() {
        $mapping   = $this->get_saved_mapping();
        $locations = [];

        foreach ( $mapping as $tec_id => $ek_id ) {
            $tec_id = (int) $tec_id;
            $ek_id  = (int) $ek_id;
            if ( ! $ek_id || ! get_post( $ek_id ) ) {
                continue;
            }

            $name     = '';
            $venue_id = 0;
            $vid      = (int) get_post_meta( $tec_id, '_EventVenueID', true );
            if ( $vid ) {
                $venue = get_post( $vid );
                if ( $venue && '' !== trim( $venue->post_title ) ) {
                    $name     = html_entity_decode( $venue->post_title, ENT_QUOTES );
                    $venue_id = $vid;
                }
            }
            if ( '' === trim( $name ) ) {
                $loc = get_post_meta( $ek_id, 'location', true );
                if ( is_array( $loc ) && ! empty( $loc['name'] ) ) {
                    $name = $loc['name'];
                }
            }
            if ( '' === trim( $name ) ) {
                continue;
            }

            $key = $this->normalize_location_name( $name );
            if ( '' === $key ) {
                continue;
            }
            if ( ! isset( $locations[ $key ] ) ) {
                $locations[ $key ] = [ 'name' => $name, 'venue_id' => $venue_id, 'events' => [] ];
            }
            if ( $venue_id && ! $locations[ $key ]['venue_id'] ) {
                $locations[ $key ]['venue_id'] = $venue_id;
            }
            $locations[ $key ]['events'][ $tec_id ] = $ek_id;
        }

        // Attach TEC venue address data for the Saved Locations sync.
        foreach ( $locations as $key => $loc ) {
            if ( $loc['venue_id'] ) {
                $locations[ $key ]['venue_data'] = $this->get_venue_address_data( $loc['venue_id'] );
            }
        }

        ksort( $locations );
        return $locations;
    }

    /**
     * All EventKoi calendars (event_cal terms), sorted by name.
     */
    private function get_eventkoi_calendars() {
        $terms = get_terms( [ 'taxonomy' => 'event_cal', 'hide_empty' => false ] );
        if ( is_wp_error( $terms ) ) {
            return [];
        }
        $cals = [];
        foreach ( $terms as $t ) {
            $cals[] = [
                'term_id' => (int) $t->term_id,
                'name'    => $t->name,
                'slug'    => $t->slug,
                'count'   => (int) $t->count,
            ];
        }
        usort( $cals, function ( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );
        return $cals;
    }

    /**
     * Auto-match locations to calendars by name content.
     *
     * Priority: exact normalized-name match, then containment (prefer
     * the longest shared name), then similar_text() >= 85% (same
     * threshold as event fuzzy matching).
     */
    private function auto_map_locations( $locations, $calendars ) {
        $by_norm = [];
        foreach ( $calendars as $cal ) {
            $nk = $this->normalize_location_name( $cal['name'] );
            if ( '' !== $nk && ! isset( $by_norm[ $nk ] ) ) {
                $by_norm[ $nk ] = $cal;
            }
        }

        $suggestions = [];
        foreach ( $locations as $key => $loc ) {
            $best = [ 'term_id' => null, 'term_name' => null, 'match_source' => 'none' ];

            if ( isset( $by_norm[ $key ] ) ) {
                $best = [
                    'term_id'      => $by_norm[ $key ]['term_id'],
                    'term_name'    => $by_norm[ $key ]['name'],
                    'match_source' => 'exact',
                ];
            } else {
                // Containment: prefer the longest calendar name shared with the location.
                $contain = null;
                foreach ( $calendars as $cal ) {
                    $norm = $this->normalize_location_name( $cal['name'] );
                    if ( '' === $norm ) {
                        continue;
                    }
                    if ( false !== strpos( $key, $norm ) || false !== strpos( $norm, $key ) ) {
                        if ( null === $contain
                            || strlen( $norm ) > strlen( $this->normalize_location_name( $contain['name'] ) ) ) {
                            $contain = $cal;
                        }
                    }
                }
                if ( $contain ) {
                    $best = [
                        'term_id'      => $contain['term_id'],
                        'term_name'    => $contain['name'],
                        'match_source' => 'fuzzy',
                    ];
                } else {
                    // Fuzzy similarity.
                    $fuzzy_best = null;
                    $fuzzy_pct  = 0;
                    foreach ( $calendars as $cal ) {
                        similar_text( $key, $this->normalize_location_name( $cal['name'] ), $pct );
                        if ( $pct > $fuzzy_pct ) {
                            $fuzzy_pct  = $pct;
                            $fuzzy_best = $cal;
                        }
                    }
                    if ( $fuzzy_best && $fuzzy_pct >= 85.0 ) {
                        $best = [
                            'term_id'      => $fuzzy_best['term_id'],
                            'term_name'    => $fuzzy_best['name'],
                            'match_source' => 'fuzzy',
                        ];
                    }
                }
            }

            $suggestions[ $key ] = $best;
        }
        return $suggestions;
    }

    /**
     * Location mapping persistence (option: location key => target).
     * Target is an int term_id, or "new:<name>" for create-on-run.
     */
    private function get_saved_location_mapping() {
        $mapping = get_option( 'ekti_location_mapping', [] );
        return is_array( $mapping ) ? $mapping : [];
    }

    private function save_location_mapping( $mapping ) {
        update_option( 'ekti_location_mapping', $mapping, false );
    }

    /**
     * Sanitize a raw location mapping payload from the admin UI.
     */
    private function sanitize_location_mapping( $mapping ) {
        $clean = [];
        if ( ! is_array( $mapping ) ) {
            return $clean;
        }
        foreach ( $mapping as $key => $value ) {
            $key = sanitize_key( $key );
            if ( '' === $key || '' === $value || null === $value ) {
                continue;
            }
            if ( is_string( $value ) && 0 === strpos( $value, 'new:' ) ) {
                $name = sanitize_text_field( substr( $value, 4 ) );
                if ( '' !== $name ) {
                    $clean[ $key ] = 'new:' . $name;
                }
            } else {
                $tid = (int) $value;
                if ( $tid > 0 ) {
                    $clean[ $key ] = $tid;
                }
            }
        }
        return $clean;
    }

    /**
     * First existing event_cal term carrying EventKoi's display term
     * meta — used as the template when creating new calendars.
     */
    private function get_calendar_template_id() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT tm.term_id FROM {$wpdb->termmeta} tm
             JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
             WHERE tt.taxonomy = 'event_cal' AND tm.meta_key = 'display'
             ORDER BY tm.term_id ASC LIMIT 1"
        );
    }

    /**
     * Create an EventKoi calendar, reusing an existing term when the
     * normalized name already exists. Audits a create_term op (undone
     * by deleting the term).
     */
    private function create_eventkoi_calendar( $name, &$ops ) {
        $name = sanitize_text_field( $name );
        if ( '' === $name ) {
            return new WP_Error( 'ekti_empty_calendar_name', 'Calendar name is empty.' );
        }

        $norm = $this->normalize_location_name( $name );
        foreach ( $this->get_eventkoi_calendars() as $cal ) {
            if ( $this->normalize_location_name( $cal['name'] ) === $norm ) {
                return $cal['term_id'];
            }
        }

        $created = wp_insert_term( $name, 'event_cal' );
        if ( is_wp_error( $created ) ) {
            return $created;
        }
        $term_id = (int) $created['term_id'];

        // Copy EventKoi's display settings from a template calendar.
        $template_id = $this->get_calendar_template_id();
        if ( $template_id ) {
            $meta = get_term_meta( $template_id );
            foreach ( $meta as $meta_key => $values ) {
                if ( is_array( $values ) && isset( $values[0] ) ) {
                    add_term_meta( $term_id, $meta_key, $values[0] );
                }
            }
        }

        $ops[] = [ 'type' => 'create_term', 'term_id' => $term_id, 'name' => $name ];
        $this->log( "Calendar sync: created EventKoi calendar \"{$name}\" (#{$term_id})." );
        return $term_id;
    }

    /**
     * Read-only scan: locations, calendars, auto-map suggestions, and
     * per-location pending assignment counts (serves as the preview).
     */
    private function scan_calendar_sync() {
        $locations   = $this->get_tec_locations();
        $calendars   = $this->get_eventkoi_calendars();
        $suggestions = $this->auto_map_locations( $locations, $calendars );
        $saved       = $this->get_saved_location_mapping();

        $rows   = [];
        $counts = [
            'locations'          => count( $locations ),
            'mapped_events'      => 0,
            'pending'            => 0,
            'new_calendars'      => 0,
            'unmapped_locations' => 0,
        ];

        foreach ( $locations as $key => $loc ) {
            $sugg = isset( $suggestions[ $key ] )
                ? $suggestions[ $key ]
                : [ 'term_id' => null, 'term_name' => null, 'match_source' => 'none' ];

            // Saved mapping wins over the auto-map suggestion.
            $selected = isset( $saved[ $key ] ) ? $saved[ $key ] : $sugg['term_id'];

            $is_new  = is_string( $selected ) && 0 === strpos( $selected, 'new:' );
            $term_id = $is_new ? 0 : (int) $selected;

            $pending = 0;
            $seen    = [];
            foreach ( $loc['events'] as $ek_id ) {
                $counts['mapped_events']++;
                if ( isset( $seen[ $ek_id ] ) ) {
                    continue;
                }
                $seen[ $ek_id ] = true;
                if ( $is_new ) {
                    $pending++;
                    continue;
                }
                if ( $term_id <= 0 ) {
                    continue;
                }
                $current = wp_get_object_terms( $ek_id, 'event_cal', [ 'fields' => 'ids' ] );
                if ( is_wp_error( $current ) || ! in_array( $term_id, array_map( 'intval', $current ), true ) ) {
                    $pending++;
                }
            }

            if ( $is_new ) {
                $counts['new_calendars']++;
            } elseif ( $term_id <= 0 ) {
                $counts['unmapped_locations']++;
            }
            $counts['pending'] += $pending;

            $rows[] = [
                'key'                 => $key,
                'name'                => $loc['name'],
                'venue_id'            => $loc['venue_id'],
                'source'              => $loc['venue_id'] ? 'venue' : 'eventkoi',
                'event_count'         => count( $loc['events'] ),
                'match_source'        => $sugg['match_source'],
                'suggested_term_id'   => $sugg['term_id'],
                'suggested_term_name' => $sugg['term_name'],
                'selected'            => ( '' === $selected || null === $selected ) ? '' : (string) $selected,
                'pending'             => $pending,
            ];
        }

        return [
            'locations' => $rows,
            'calendars' => $calendars,
            'counts'    => $counts,
        ];
    }

    /**
     * Apply the location mapping: create any "new:" calendars, then
     * union-append each target calendar onto the mapped EventKoi
     * events. Chunked, idempotent, audited to ekti_cal_sync_audit.
     */
    private function run_calendar_sync( $mapping, $chunk = 100 ) {
        $ops = [];

        // Resolve create-new targets up front so the whole run is
        // audited even if later chunks fail.
        foreach ( $mapping as $key => $target ) {
            if ( is_string( $target ) && 0 === strpos( $target, 'new:' ) ) {
                $term_id = $this->create_eventkoi_calendar( substr( $target, 4 ), $ops );
                if ( is_wp_error( $term_id ) ) {
                    if ( $ops ) {
                        $this->audit_push_cal_sync( $ops );
                    }
                    return [ 'error' => $term_id->get_error_message() ];
                }
                $mapping[ $key ] = $term_id;
            }
        }
        if ( $ops ) {
            $this->audit_push_cal_sync( $ops );
        }
        // Persist the resolved targets so reruns and undo agree.
        $this->save_location_mapping( $mapping );

        $locations = $this->get_tec_locations();
        $pending   = [];
        $seen      = [];
        foreach ( $mapping as $key => $term_id ) {
            $term_id = (int) $term_id;
            if ( $term_id <= 0 || ! isset( $locations[ $key ] ) ) {
                continue;
            }
            foreach ( $locations[ $key ]['events'] as $tec_id => $ek_id ) {
                if ( isset( $seen[ $ek_id . ':' . $term_id ] ) ) {
                    continue;
                }
                $seen[ $ek_id . ':' . $term_id ] = true;
                $current = wp_get_object_terms( $ek_id, 'event_cal', [ 'fields' => 'ids' ] );
                if ( is_wp_error( $current ) ) {
                    continue;
                }
                $current = array_map( 'intval', $current );
                if ( in_array( $term_id, $current, true ) ) {
                    continue;
                }
                $pending[] = [
                    'tec_id'  => $tec_id,
                    'ek_id'   => $ek_id,
                    'term_id' => $term_id,
                    'current' => $current,
                ];
            }
        }

        $batch   = array_slice( $pending, 0, $chunk );
        $results = [];
        $applied = 0;
        $errors  = 0;
        $batch_ops = [];

        foreach ( $batch as $p ) {
            $set = wp_set_object_terms( $p['ek_id'], [ $p['term_id'] ], 'event_cal', true );
            if ( is_wp_error( $set ) ) {
                $errors++;
                $results[] = [ 'action' => 'error', 'ek_id' => $p['ek_id'], 'reason' => $set->get_error_message() ];
                continue;
            }
            $batch_ops[] = [ 'type' => 'set_terms', 'post' => $p['ek_id'], 'old_term_ids' => $p['current'] ];
            $applied++;
            $results[] = [ 'action' => 'assigned', 'ek_id' => $p['ek_id'], 'tec_id' => $p['tec_id'], 'term_id' => $p['term_id'] ];
        }

        if ( $batch_ops ) {
            $this->audit_push_cal_sync( $batch_ops );
        }
        if ( $applied || $errors ) {
            $this->log( sprintf(
                'Calendar sync chunk: %d assigned, %d errors (%d still pending).',
                $applied, $errors, count( $pending ) - count( $batch )
            ) );
        }

        return [
            'processed' => count( $batch ),
            'applied'   => $applied,
            'errors'    => $errors,
            'done'      => count( $pending ) <= count( $batch ),
            'results'   => $results,
        ];
    }

    /**
     * Append audit ops to the calendar sync ledger (separate from the
     * cleanup and ticket-sync ledgers so undos never cross-contaminate).
     */
    private function audit_push_cal_sync( $ops ) {
        if ( empty( $ops ) ) {
            return;
        }
        $audit = get_option( 'ekti_cal_sync_audit', [] );
        if ( ! is_array( $audit ) ) {
            $audit = [];
        }
        update_option( 'ekti_cal_sync_audit', array_merge( $audit, $ops ), false );
    }

    /**
     * Undo the last calendar sync by replaying the ledger in reverse.
     */
    private function undo_calendar_sync() {
        $audit = get_option( 'ekti_cal_sync_audit', [] );
        if ( empty( $audit ) ) {
            return [ 'error' => 'Nothing to undo.' ];
        }
        $counts = $this->replay_audit_ops( $audit );
        delete_option( 'ekti_cal_sync_audit' );
        $this->log( 'Calendar sync undo complete. ' . wp_json_encode( $counts ) );
        return [ 'undone' => $counts ];
    }

    /* ------------------------------------------------------------------
     * TAXONOMY SYNC (LOCATIONS & INSTRUCTORS)
     *
     * EventKoi added two new taxonomies: Locations and Instructors.
     * This engine maps TEC venues -> Locations and TEC organizers ->
     * Instructors, using the same idempotent, append-only, audited pattern
     * as Calendar Sync but keeping its own ledgers so the legacy calendar
     * sync remains independently undoable.
     * ------------------------------------------------------------------ */

    /**
     * Resolve a sync type to its registered taxonomy slug. The locations
     * type follows EventKoi's Saved Locations designation.
     */
    private function get_taxonomy_for_type( $type ) {
        if ( 'locations' === $type ) {
            return $this->get_location_taxonomy_slug();
        }
        if ( 'instructors' === $type ) {
            return EKTI_INSTRUCTOR_TAXONOMY;
        }
        return '';
    }

    /**
     * Taxonomy slug for the locations sync type: EventKoi's Saved
     * Locations designation when it points at a usable taxonomy, else
     * the stock `locations` taxonomy when it is registered.
     */
    private function get_location_taxonomy_slug() {
        $slug = sanitize_key( (string) get_option( 'eventkoi_location_taxonomy', '' ) );
        if ( '' !== $slug
            && taxonomy_exists( $slug )
            && is_object_in_taxonomy( 'eventkoi_event', $slug ) ) {
            return $slug;
        }
        if ( taxonomy_exists( EKTI_LOCATION_TAXONOMY )
            && is_object_in_taxonomy( 'eventkoi_event', EKTI_LOCATION_TAXONOMY ) ) {
            return EKTI_LOCATION_TAXONOMY;
        }
        return '';
    }

    /**
     * EventKoi Saved Locations designation state: whether one is active,
     * the slot → term-field-key map, and the builder field definitions.
     * Read straight from EventKoi's options rather than its classes so
     * the importer keeps working regardless of EventKoi internals.
     */
    private function get_saved_location_field_map() {
        $inactive = [
            'active'         => false,
            'taxonomy'       => '',
            'slots'          => [],
            'unmapped_slots' => [],
            'field_defs'     => [],
        ];
        $slug     = sanitize_key( (string) get_option( 'eventkoi_location_taxonomy', '' ) );
        if ( '' === $slug
            || ! taxonomy_exists( $slug )
            || ! is_object_in_taxonomy( 'eventkoi_event', $slug ) ) {
            return $inactive;
        }

        $definitions = get_option( 'eventkoi_taxonomies', [] );
        $definition  = is_array( $definitions ) && isset( $definitions[ $slug ] ) && is_array( $definitions[ $slug ] )
            ? $definitions[ $slug ]
            : [];

        $field_defs = [];
        if ( ! empty( $definition['fields'] ) && is_array( $definition['fields'] ) ) {
            foreach ( $definition['fields'] as $field ) {
                if ( ! is_array( $field ) || empty( $field['key'] ) ) {
                    continue;
                }
                $key = sanitize_key( (string) $field['key'] );
                if ( '' === $key ) {
                    continue;
                }
                $field_defs[ $key ] = [
                    'label' => isset( $field['label'] ) ? (string) $field['label'] : $key,
                    'type'  => isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text',
                ];
            }
        }

        $map   = get_option( 'eventkoi_location_taxonomy_map', [] );
        $map   = is_array( $map ) ? $map : [];
        $slots = [];
        foreach ( $this->location_slots as $slot ) {
            $field_key = isset( $map[ $slot ] ) ? sanitize_key( (string) $map[ $slot ] ) : '';
            if ( '' !== $field_key && isset( $field_defs[ $field_key ] ) ) {
                $slots[ $slot ] = $field_key;
            }
        }

        return [
            'active'         => true,
            'taxonomy'       => $slug,
            'slots'          => $slots,
            'unmapped_slots' => array_values( array_diff( $this->location_slots, array_keys( $slots ) ) ),
            'field_defs'     => $field_defs,
        ];
    }

    /**
     * Address payload for a TEC venue post, keyed by location slot.
     */
    private function get_venue_address_data( $venue_id ) {
        $venue_id = (int) $venue_id;
        if ( $venue_id <= 0 || ! get_post( $venue_id ) ) {
            return [];
        }
        $state = get_post_meta( $venue_id, '_VenueStateProvince', true );
        if ( '' === trim( (string) $state ) ) {
            $state = get_post_meta( $venue_id, '_VenueState', true );
        }
        list( $line1, $line2 ) = $this->split_venue_address( (string) get_post_meta( $venue_id, '_VenueAddress', true ) );
        return [
            'address1'  => $line1,
            'address2'  => $line2,
            'city'      => (string) get_post_meta( $venue_id, '_VenueCity', true ),
            'state'     => (string) $state,
            'country'   => (string) get_post_meta( $venue_id, '_VenueCountry', true ),
            'zip'       => (string) get_post_meta( $venue_id, '_VenueZip', true ),
            'latitude'  => (string) get_post_meta( $venue_id, '_VenueLat', true ),
            'longitude' => (string) get_post_meta( $venue_id, '_VenueLng', true ),
        ];
    }

    /**
     * Split a single TEC address line into [line1, line2] when it carries
     * a trailing unit segment ("9070 Research Blvd., Ste 203"), mirroring
     * the two-line layout of the address fields. Anything else stays on
     * line 1.
     */
    private function split_venue_address( $address ) {
        $address = trim( (string) $address );
        if ( '' === $address || false === strpos( $address, ',' ) ) {
            return [ $address, '' ];
        }
        $last_comma = strrpos( $address, ',' );
        $line1      = trim( substr( $address, 0, $last_comma ) );
        $line2      = trim( substr( $address, $last_comma + 1 ) );
        if ( '' === $line1 || '' === $line2 ) {
            return [ $address, '' ];
        }
        if ( ! preg_match( '/^(ste|suite|unit|apt|bldg|fl|#)\s*\S+/i', $line2 ) ) {
            return [ $address, '' ];
        }
        return [ $line1, $line2 ];
    }

    /**
     * Sanitize a taxonomy field value the same way EventKoi's
     * Taxonomy_Fields::sanitize() does, so importer writes match what
     * the EventKoi editor would have stored.
     */
    private function sanitize_taxonomy_field_value( $type, $value ) {
        switch ( $type ) {
            case 'image':
                return absint( $value );
            case 'url':
                return esc_url_raw( (string) $value );
            case 'textarea':
                return sanitize_textarea_field( (string) $value );
            default:
                return sanitize_text_field( (string) $value );
        }
    }

    /**
     * Copy TEC venue address data onto a location term's builder fields.
     *
     * Fill-empty by default: a slot that already holds a value is left
     * alone (and counted as a conflict when it differs from the TEC
     * value) unless $overwrite is set. Empty TEC values never clear a
     * filled slot. Each write is audited as a set_termmeta op so the sync
     * undo restores the exact prior state.
     *
     * @return array{written:int, conflicts:int}
     */
    private function sync_location_term_fields( $term_id, $venue_data, $field_map, &$ops, $overwrite = false ) {
        $term_id   = (int) $term_id;
        $written   = 0;
        $conflicts = 0;
        if ( $term_id <= 0 || empty( $field_map['slots'] ) || ! is_array( $venue_data ) ) {
            return [ 'written' => $written, 'conflicts' => $conflicts ];
        }
        foreach ( $field_map['slots'] as $slot => $field_key ) {
            if ( ! array_key_exists( $slot, $venue_data ) ) {
                continue;
            }
            $type = isset( $field_map['field_defs'][ $field_key ]['type'] )
                ? $field_map['field_defs'][ $field_key ]['type']
                : 'text';
            $proposed = $this->sanitize_taxonomy_field_value( $type, $venue_data[ $slot ] );
            if ( '' === $proposed ) {
                continue; // Never write — or clear — with an empty TEC value.
            }
            $meta_key = 'eventkoi_field_' . $field_key;
            $current  = get_term_meta( $term_id, $meta_key, true );
            $current  = ( null === $current || ! is_string( $current ) ) ? '' : $current;
            if ( $current === $proposed ) {
                continue; // Already in sync.
            }
            if ( '' !== $current ) {
                $conflicts++;
                if ( ! $overwrite ) {
                    continue;
                }
            }
            update_term_meta( $term_id, $meta_key, $proposed );
            $ops[]    = [
                'type'    => 'set_termmeta',
                'term_id' => $term_id,
                'key'     => $meta_key,
                'old'     => '' === $current ? null : $current,
            ];
            $written++;
        }
        return [ 'written' => $written, 'conflicts' => $conflicts ];
    }

    /**
     * Read-only analysis of the address-field writes a location sync
     * would make for one source: slots that would be filled (current
     * empty, TEC value present) and conflicts (both present, values
     * differ). $term_id 0 analyzes a create-new target.
     */
    private function scan_location_term_fields( $term_id, $venue_data, $saved_locations ) {
        $result = [
            'term_id'   => (int) $term_id,
            'pending'   => 0,
            'conflicts' => 0,
            'slots'     => [],
        ];
        $term_id = (int) $term_id;
        foreach ( $saved_locations['slots'] as $slot => $field_key ) {
            if ( ! array_key_exists( $slot, $venue_data ) ) {
                continue;
            }
            $type     = isset( $saved_locations['field_defs'][ $field_key ]['type'] )
                ? $saved_locations['field_defs'][ $field_key ]['type']
                : 'text';
            $label    = isset( $saved_locations['field_defs'][ $field_key ]['label'] )
                ? $saved_locations['field_defs'][ $field_key ]['label']
                : $field_key;
            $proposed = $this->sanitize_taxonomy_field_value( $type, $venue_data[ $slot ] );
            if ( '' === $proposed ) {
                continue;
            }
            $current = $term_id > 0
                ? (string) get_term_meta( $term_id, 'eventkoi_field_' . $field_key, true )
                : '';
            if ( $current === $proposed ) {
                continue;
            }
            $result['slots'][] = [
                'slot'     => $slot,
                'label'    => $label,
                'current'  => $current,
                'proposed' => $proposed,
            ];
            if ( '' === $current ) {
                $result['pending']++;
            } else {
                $result['conflicts']++;
            }
        }
        return $result;
    }

    /**
     * All terms for a given taxonomy, sorted by name.
     */
    private function get_taxonomy_terms( $taxonomy ) {
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
        if ( is_wp_error( $terms ) ) {
            return [];
        }
        $out = [];
        foreach ( $terms as $t ) {
            $out[] = [
                'term_id' => (int) $t->term_id,
                'name'    => $t->name,
                'slug'    => $t->slug,
                'count'   => (int) $t->count,
            ];
        }
        usort( $out, function ( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );
        return $out;
    }

    /**
     * Find a template term in the taxonomy that carries EventKoi display
     * meta, if any. New terms copy that meta set.
     */
    private function get_taxonomy_template_id( $taxonomy ) {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT tm.term_id FROM {$wpdb->termmeta} tm
                 JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
                 WHERE tt.taxonomy = %s AND tm.meta_key = 'display'
                 ORDER BY tm.term_id ASC LIMIT 1",
                $taxonomy
            )
        );
    }

    /**
     * Create a term in the target taxonomy, reusing an existing term when
     * the normalized name already exists. Audits a create_term op. When
     * venue address data is supplied and the taxonomy is the designated
     * Saved Locations taxonomy, the new term's address fields are
     * populated from it.
     */
    private function create_taxonomy_term( $taxonomy, $name, &$ops, $venue_data = null ) {
        $name = sanitize_text_field( $name );
        if ( '' === $name ) {
            return new WP_Error( 'ekti_empty_term_name', 'Term name is empty.' );
        }
        $norm = $this->normalize_location_name( $name );
        foreach ( $this->get_taxonomy_terms( $taxonomy ) as $term ) {
            if ( $this->normalize_location_name( $term['name'] ) === $norm ) {
                return $term['term_id'];
            }
        }
        $created = wp_insert_term( $name, $taxonomy );
        if ( is_wp_error( $created ) ) {
            return $created;
        }
        $term_id = (int) $created['term_id'];
        $template_id = $this->get_taxonomy_template_id( $taxonomy );
        if ( $template_id ) {
            $meta = get_term_meta( $template_id );
            foreach ( $meta as $meta_key => $values ) {
                // Builder field values are term-specific: a new location
                // term must never inherit another location's address.
                if ( 0 === strpos( $meta_key, 'eventkoi_field_' ) ) {
                    continue;
                }
                if ( is_array( $values ) && isset( $values[0] ) ) {
                    add_term_meta( $term_id, $meta_key, $values[0] );
                }
            }
        }
        // create_term goes in before any set_termmeta ops so the reverse
        // replay restores the fields before the term is deleted.
        $ops[] = [ 'type' => 'create_term', 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'name' => $name ];
        $this->log( "Taxonomy sync ({$taxonomy}): created term \"{$name}\" (#{$term_id})." );
        if ( is_array( $venue_data ) && ! empty( $venue_data ) ) {
            $field_map = $this->get_saved_location_field_map();
            if ( $field_map['active'] && $field_map['taxonomy'] === $taxonomy ) {
                $fr = $this->sync_location_term_fields( $term_id, $venue_data, $field_map, $ops, true );
                if ( $fr['written'] ) {
                    $this->log( sprintf(
                        'Taxonomy sync (locations): populated "%s" (#%d) with %d address field(s) from the TEC venue.',
                        $name, $term_id, $fr['written']
                    ) );
                }
            }
        }
        return $term_id;
    }

    /**
     * Auto-match source names to existing terms by exact/containment/fuzzy
     * name content. Returns suggestions keyed by source key.
     */
    private function auto_map_sources_to_terms( $sources, $terms ) {
        $by_norm = [];
        foreach ( $terms as $term ) {
            $nk = $this->normalize_location_name( $term['name'] );
            if ( '' !== $nk && ! isset( $by_norm[ $nk ] ) ) {
                $by_norm[ $nk ] = $term;
            }
        }
        $suggestions = [];
        foreach ( $sources as $key => $source ) {
            $best = [ 'term_id' => null, 'term_name' => null, 'match_source' => 'none' ];
            if ( isset( $by_norm[ $key ] ) ) {
                $best = [
                    'term_id'      => $by_norm[ $key ]['term_id'],
                    'term_name'    => $by_norm[ $key ]['name'],
                    'match_source' => 'exact',
                ];
            } else {
                $contain = null;
                foreach ( $terms as $term ) {
                    $norm = $this->normalize_location_name( $term['name'] );
                    if ( '' === $norm ) {
                        continue;
                    }
                    if ( false !== strpos( $key, $norm ) || false !== strpos( $norm, $key ) ) {
                        if ( null === $contain
                            || strlen( $norm ) > strlen( $this->normalize_location_name( $contain['name'] ) ) ) {
                            $contain = $term;
                        }
                    }
                }
                if ( $contain ) {
                    $best = [
                        'term_id'      => $contain['term_id'],
                        'term_name'    => $contain['name'],
                        'match_source' => 'fuzzy',
                    ];
                } else {
                    $fuzzy_best = null;
                    $fuzzy_pct  = 0;
                    foreach ( $terms as $term ) {
                        similar_text( $key, $this->normalize_location_name( $term['name'] ), $pct );
                        if ( $pct > $fuzzy_pct ) {
                            $fuzzy_pct  = $pct;
                            $fuzzy_best = $term;
                        }
                    }
                    if ( $fuzzy_best && $fuzzy_pct >= 85.0 ) {
                        $best = [
                            'term_id'      => $fuzzy_best['term_id'],
                            'term_name'    => $fuzzy_best['name'],
                            'match_source' => 'fuzzy',
                        ];
                    }
                }
            }
            $suggestions[ $key ] = $best;
        }
        return $suggestions;
    }

    /**
     * Persist / load / sanitize a taxonomy mapping (source key => term_id
     * or "new:<name>").
     */
    private function get_saved_taxonomy_mapping( $type ) {
        $mapping = get_option( "ekti_taxonomy_mapping_{$type}", [] );
        return is_array( $mapping ) ? $mapping : [];
    }

    private function save_taxonomy_mapping( $type, $mapping ) {
        update_option( "ekti_taxonomy_mapping_{$type}", $mapping, false );
    }

    private function sanitize_taxonomy_mapping( $mapping ) {
        $clean = [];
        if ( ! is_array( $mapping ) ) {
            return $clean;
        }
        foreach ( $mapping as $key => $value ) {
            $key = sanitize_key( $key );
            if ( '' === $key || '' === $value || null === $value ) {
                continue;
            }
            if ( is_string( $value ) && 0 === strpos( $value, 'new:' ) ) {
                $name = sanitize_text_field( substr( $value, 4 ) );
                if ( '' !== $name ) {
                    $clean[ $key ] = 'new:' . $name;
                }
            } else {
                $tid = (int) $value;
                if ( $tid > 0 ) {
                    $clean[ $key ] = $tid;
                }
            }
        }
        return $clean;
    }

    /**
     * Append ops to a per-type taxonomy sync audit ledger.
     */
    private function audit_push_tax_sync( $type, $ops ) {
        if ( empty( $ops ) ) {
            return;
        }
        $option = "ekti_tax_sync_audit_{$type}";
        $audit  = get_option( $option, [] );
        if ( ! is_array( $audit ) ) {
            $audit = [];
        }
        update_option( $option, array_merge( $audit, $ops ), false );
    }

    /**
     * Source collector dispatcher.
     */
    private function get_tec_taxonomy_sources( $type ) {
        if ( 'instructors' === $type ) {
            return $this->get_tec_organizer_sources();
        }
        return $this->get_tec_location_sources();
    }

    private function get_tec_location_sources() {
        $sources = [];
        foreach ( $this->get_tec_locations() as $key => $loc ) {
            $sources[ $key ] = [
                'name'       => $loc['name'],
                'source_id'  => $loc['venue_id'],
                'events'     => $loc['events'],
                'venue_data' => isset( $loc['venue_data'] ) ? $loc['venue_data'] : [],
            ];
        }
        return $sources;
    }

    /**
     * Collect distinct TEC organizers across the saved event mapping.
     *
     * Primary source: TEC organizer post title via _EventOrganizerID meta.
     * Fallback: the organizer value stored by EventKoi's native importer
     * in a custom field (detected automatically).
     */
    private function get_tec_organizer_sources() {
        $mapping = $this->get_saved_mapping();
        $sources = [];
        foreach ( $mapping as $tec_id => $ek_id ) {
            $tec_id = (int) $tec_id;
            $ek_id  = (int) $ek_id;
            if ( ! $ek_id || ! get_post( $ek_id ) ) {
                continue;
            }
            $names = [];
            $oids  = get_post_meta( $tec_id, '_EventOrganizerID', true );
            if ( ! is_array( $oids ) ) {
                $oids = $oids ? [ $oids ] : [];
            }
            foreach ( $oids as $oid ) {
                $oid = (int) $oid;
                if ( ! $oid ) {
                    continue;
                }
                $org = get_post( $oid );
                if ( $org && '' !== trim( $org->post_title ) ) {
                    $name = html_entity_decode( $org->post_title, ENT_QUOTES );
                    $key  = $this->normalize_location_name( $name );
                    if ( '' === $key ) {
                        continue;
                    }
                    if ( ! isset( $sources[ $key ] ) ) {
                        $sources[ $key ] = [ 'name' => $name, 'source_id' => $oid, 'events' => [] ];
                    }
                    $sources[ $key ]['events'][ $tec_id ] = $ek_id;
                }
            }
            if ( empty( $names ) ) {
                $fallback = $this->get_eventkoi_organizer_fallback( $ek_id );
                if ( $fallback ) {
                    $name = $fallback;
                    $key  = $this->normalize_location_name( $name );
                    if ( '' !== $key ) {
                        if ( ! isset( $sources[ $key ] ) ) {
                            $sources[ $key ] = [ 'name' => $name, 'source_id' => 0, 'events' => [] ];
                        }
                        $sources[ $key ]['events'][ $tec_id ] = $ek_id;
                    }
                }
            }
        }
        ksort( $sources );
        return $sources;
    }

    /**
     * Read the organizer value that EventKoi's native importer stored as a
     * custom field, if any.
     */
    private function get_eventkoi_organizer_fallback( $ek_id ) {
        $detected = $this->detect_organizer_field();
        if ( is_wp_error( $detected ) || empty( $detected['meta_key'] ) ) {
            return '';
        }
        $value = get_post_meta( $ek_id, $detected['meta_key'], true );
        if ( is_array( $value ) && isset( $value['name'] ) ) {
            $value = $value['name'];
        }
        return is_string( $value ) ? trim( $value ) : '';
    }

    /**
     * Detect the custom field that holds the imported organizer.
     * Prefers ACF (field group title/label match) and falls back to a
     * postmeta scan using known TEC organizer names.
     */
    private function detect_organizer_field() {
        $cache_key = 'ekti_organizer_field_detected';
        $cached    = wp_cache_get( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }
        $result = $this->detect_organizer_field_acf();
        if ( is_wp_error( $result ) ) {
            $result = $this->detect_organizer_field_meta();
        }
        wp_cache_set( $cache_key, $result );
        return $result;
    }

    private function detect_organizer_field_acf() {
        if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
            return new WP_Error( 'ekti_no_acf', 'ACF not active.' );
        }
        $groups   = acf_get_field_groups( [ 'post_type' => 'eventkoi_event' ] );
        $best     = null;
        $best_score = 0;
        foreach ( $groups as $group ) {
            $fields = acf_get_fields( $group['key'] );
            if ( empty( $fields ) || ! is_array( $fields ) ) {
                continue;
            }
            foreach ( $fields as $field ) {
                $label = strtolower( (string) ( $field['label'] ?? '' ) );
                $name  = strtolower( (string) ( $field['name'] ?? '' ) );
                $score = 0;
                if ( 'organizer' === $label || 'organizer' === $name || 'instructor' === $label || 'instructor' === $name ) {
                    $score = 100;
                } elseif ( false !== strpos( $label, 'organizer' ) || false !== strpos( $name, 'organizer' )
                    || false !== strpos( $label, 'instructor' ) || false !== strpos( $name, 'instructor' ) ) {
                    $score = 50;
                }
                if ( $score > $best_score ) {
                    $best_score = $score;
                    $best       = [
                        'source'      => 'acf',
                        'meta_key'    => $field['name'],
                        'field_key'   => $field['key'],
                        'group_id'    => $group['ID'] ?? 0,
                        'group_title' => $group['title'] ?? '',
                    ];
                }
            }
        }
        if ( $best ) {
            return $best;
        }
        return new WP_Error( 'ekti_no_acf_organizer', 'No ACF organizer field found.' );
    }

    private function detect_organizer_field_meta() {
        global $wpdb;
        $tec_ids = $this->get_tec_event_ids_with_attendees();
        $names   = [];
        foreach ( $tec_ids as $tec_id ) {
            $oids = get_post_meta( (int) $tec_id, '_EventOrganizerID', true );
            if ( ! is_array( $oids ) ) {
                $oids = $oids ? [ $oids ] : [];
            }
            foreach ( $oids as $oid ) {
                $oid = (int) $oid;
                if ( ! $oid ) {
                    continue;
                }
                $org = get_post( $oid );
                if ( $org && '' !== trim( $org->post_title ) ) {
                    $names[] = $org->post_title;
                }
            }
        }
        $names = array_unique( array_filter( $names ) );
        if ( empty( $names ) ) {
            return new WP_Error( 'ekti_no_organizer_names', 'No TEC organizer names found to match.' );
        }
        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
        $row          = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT pm.meta_key, COUNT(DISTINCT pm.post_id) AS event_count
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = 'eventkoi_event'
                   AND pm.meta_value IN ({$placeholders})
                 GROUP BY pm.meta_key
                 ORDER BY event_count DESC
                 LIMIT 1",
                ...$names
            ),
            ARRAY_A
        );
        if ( empty( $row ) ) {
            return new WP_Error( 'ekti_no_meta_match', 'No eventkoi_event meta key matches organizer names.' );
        }
        return [
            'source'      => 'meta',
            'meta_key'    => $row['meta_key'],
            'field_key'   => '',
            'group_id'    => 0,
            'group_title' => '',
        ];
    }

    /**
     * Read-only preview of what the organizer custom field cleanup will do.
     */
    private function scan_organizer_field_cleanup() {
        $detected = $this->detect_organizer_field();
        if ( is_wp_error( $detected ) ) {
            return [ 'detected' => false, 'reason' => $detected->get_error_message() ];
        }
        global $wpdb;
        $event_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value != '' AND meta_value IS NOT NULL",
                $detected['meta_key']
            )
        );
        return [
            'detected'    => true,
            'source'      => $detected['source'],
            'meta_key'    => $detected['meta_key'],
            'group_id'    => $detected['group_id'],
            'group_title' => $detected['group_title'],
            'event_count' => $event_count,
        ];
    }

    /**
     * Back up then remove the organizer custom field group and its per-event
     * meta values.
     */
    private function remove_organizer_custom_field_group() {
        $detected = $this->detect_organizer_field();
        if ( is_wp_error( $detected ) ) {
            return $detected;
        }
        $backup = $this->backup_organizer_field_group( $detected );
        global $wpdb;
        $meta_keys = [ $detected['meta_key'] ];
        // ACF stores a companion field-key reference under the same key
        // prefixed with an underscore (e.g. "organizer" + "_organizer").
        if ( 'acf' === $detected['source'] ) {
            if ( 0 !== strpos( $detected['meta_key'], '_' ) ) {
                $meta_keys[] = '_' . $detected['meta_key'];
            } else {
                $meta_keys[] = substr( $detected['meta_key'], 1 );
            }
        }
        $deleted_meta = 0;
        foreach ( array_unique( $meta_keys ) as $mk ) {
            $deleted_meta += $wpdb->delete(
                $wpdb->postmeta,
                [ 'meta_key' => $mk ],
                [ '%s' ]
            );
        }
        if ( 'acf' === $detected['source'] && ! empty( $detected['group_id'] ) ) {
            $children = get_posts( [
                'post_type'      => 'acf-field',
                'post_parent'    => $detected['group_id'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ] );
            foreach ( $children as $child_id ) {
                wp_delete_post( $child_id, true );
            }
            wp_delete_post( $detected['group_id'], true );
        }
        update_option( 'ekti_organizer_field_backup', $backup, false );
        $this->log( "Organizer custom field group removed: {$detected['group_title']} (meta_key: {$detected['meta_key']}, {$deleted_meta} event meta rows deleted)." );
        return [
            'removed'      => true,
            'meta_key'     => $detected['meta_key'],
            'group_title'  => $detected['group_title'],
            'deleted_meta' => $deleted_meta,
            'backup_saved' => true,
        ];
    }

    /**
     * Serialize the field group and field definitions into an option so the
     * cleanup can be reconstructed manually if anything goes wrong.
     */
    private function backup_organizer_field_group( $detected ) {
        $backup = [
            'detected'  => $detected,
            'timestamp' => current_time( 'mysql' ),
        ];
        if ( 'acf' === $detected['source']
            && function_exists( 'acf_get_field_group' )
            && ! empty( $detected['group_id'] ) ) {
            $group  = acf_get_field_group( $detected['group_id'] );
            $fields = function_exists( 'acf_get_fields' ) ? acf_get_fields( $group['key'] ) : [];
            $backup['acf_group']  = $group;
            $backup['acf_fields'] = $fields;
        } elseif ( ! empty( $detected['group_id'] ) ) {
            $backup['posts'] = [];
            $group           = get_post( $detected['group_id'] );
            if ( $group ) {
                $backup['posts'][] = $group->to_array();
                $children = get_posts( [
                    'post_type'      => 'acf-field',
                    'post_parent'    => $detected['group_id'],
                    'posts_per_page' => -1,
                ] );
                foreach ( $children as $child ) {
                    $backup['posts'][] = $child->to_array();
                }
            }
        }
        return $backup;
    }

    /**
     * Read-only scan: sources, terms, auto-map suggestions, and pending
     * assignment counts for a taxonomy sync type. The locations type also
     * reports EventKoi's Saved Locations designation state and, per
     * source, the address-field writes a run would make.
     */
    private function scan_taxonomy_sync( $type ) {
        $taxonomy = $this->get_taxonomy_for_type( $type );
        if ( ! $taxonomy ) {
            if ( 'locations' === $type ) {
                return new WP_Error(
                    'ekti_no_location_taxonomy',
                    'No Locations taxonomy is available. Create one in EventKoi (Settings > Taxonomies) or register a "locations" taxonomy first.'
                );
            }
            return new WP_Error( 'ekti_invalid_type', 'Invalid taxonomy sync type.' );
        }
        $sources     = $this->get_tec_taxonomy_sources( $type );
        $terms       = $this->get_taxonomy_terms( $taxonomy );
        $suggestions = $this->auto_map_sources_to_terms( $sources, $terms );
        $saved       = $this->get_saved_taxonomy_mapping( $type );

        // Saved Locations designation state (locations sync only).
        $saved_locations = null;
        if ( 'locations' === $type ) {
            $field_map       = $this->get_saved_location_field_map();
            $saved_locations = [
                'active'         => $field_map['active'],
                'taxonomy'       => $field_map['taxonomy'],
                'slots'          => $field_map['slots'],
                'field_defs'     => $field_map['field_defs'],
                'unmapped_slots' => $field_map['unmapped_slots'],
                'warning'        => '',
            ];
            if ( ! $field_map['active'] ) {
                $saved_locations['warning'] = 'EventKoi\'s Saved Locations designation is not active. Enable "Replace default location fields with a custom taxonomy" in EventKoi Settings > Taxonomies to copy TEC venue addresses onto terms.';
            } elseif ( empty( $field_map['slots'] ) ) {
                $saved_locations['warning'] = 'Saved Locations is active, but no taxonomy fields are mapped to address details. Map them in EventKoi Settings > Taxonomies.';
            }
            global $wpdb;
            $saved_locations['legacy_meta_events'] = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = 'eventkoi_event' AND pm.meta_key = 'location' AND pm.meta_value != ''"
            );
        }

        $rows   = [];
        $counts = [
            'sources'          => count( $sources ),
            'mapped_events'    => 0,
            'pending'          => 0,
            'new_terms'        => 0,
            'unmapped_sources' => 0,
        ];
        if ( $saved_locations ) {
            $counts['field_updates']   = 0;
            $counts['field_conflicts'] = 0;
        }
        foreach ( $sources as $key => $source ) {
            $sugg = isset( $suggestions[ $key ] ) ? $suggestions[ $key ] : [ 'term_id' => null, 'term_name' => null, 'match_source' => 'none' ];
            $selected = isset( $saved[ $key ] ) ? $saved[ $key ] : $sugg['term_id'];
            $is_new   = is_string( $selected ) && 0 === strpos( $selected, 'new:' );
            $term_id  = $is_new ? 0 : (int) $selected;
            $pending  = 0;
            $seen     = [];
            foreach ( $source['events'] as $ek_id ) {
                $counts['mapped_events']++;
                if ( isset( $seen[ $ek_id ] ) ) {
                    continue;
                }
                $seen[ $ek_id ] = true;
                if ( $is_new ) {
                    $pending++;
                    continue;
                }
                if ( $term_id <= 0 ) {
                    continue;
                }
                $current = wp_get_object_terms( $ek_id, $taxonomy, [ 'fields' => 'ids' ] );
                if ( is_wp_error( $current ) || ! in_array( $term_id, array_map( 'intval', $current ), true ) ) {
                    $pending++;
                }
            }
            if ( $is_new ) {
                $counts['new_terms']++;
            } elseif ( $term_id <= 0 ) {
                $counts['unmapped_sources']++;
            }
            $counts['pending'] += $pending;

            // Address-field analysis for the resolved target term.
            $fields = null;
            if ( $saved_locations
                && $saved_locations['active']
                && ! empty( $source['venue_data'] )
                && ( $is_new || $term_id > 0 ) ) {
                $fields             = $this->scan_location_term_fields( $is_new ? 0 : $term_id, $source['venue_data'], $saved_locations );
                $fields['is_new']   = $is_new;
                $counts['field_updates']   += $fields['pending'];
                $counts['field_conflicts'] += $fields['conflicts'];
            }

            $rows[] = [
                'key'                 => $key,
                'name'                => $source['name'],
                'source_id'           => $source['source_id'],
                'source'              => $source['source_id']
                    ? ( 'instructors' === $type ? 'tec_organizer' : 'tec_venue' )
                    : 'eventkoi_meta',
                'event_count'         => count( $source['events'] ),
                'match_source'        => $sugg['match_source'],
                'suggested_term_id'   => $sugg['term_id'],
                'suggested_term_name' => $sugg['term_name'],
                'selected'            => ( '' === $selected || null === $selected ) ? '' : (string) $selected,
                'pending'             => $pending,
                'fields'              => $fields,
            ];
        }
        return [
            'sources'         => $rows,
            'terms'           => $terms,
            'counts'          => $counts,
            'saved_locations' => $saved_locations,
        ];
    }

    /**
     * Apply a taxonomy mapping: create any "new:" terms (populated with
     * TEC venue address data for locations), persist the mapping, sync
     * address fields onto mapped terms (locations, first chunk only),
     * then union-append terms to mapped EventKoi events.
     */
    private function run_taxonomy_sync( $type, $mapping, $chunk = 100, $overwrite = false, $sync_fields = false ) {
        $taxonomy = $this->get_taxonomy_for_type( $type );
        if ( ! $taxonomy ) {
            return [ 'error' => 'Invalid taxonomy sync type.' ];
        }
        $sources        = $this->get_tec_taxonomy_sources( $type );
        $results        = [];
        $fields_written = 0;
        $ops = [];
        foreach ( $mapping as $key => $target ) {
            if ( is_string( $target ) && 0 === strpos( $target, 'new:' ) ) {
                $venue_data = ( 'locations' === $type && isset( $sources[ $key ]['venue_data'] ) )
                    ? $sources[ $key ]['venue_data']
                    : null;
                $ops_before = count( $ops );
                $term_id = $this->create_taxonomy_term( $taxonomy, substr( $target, 4 ), $ops, $venue_data );
                if ( is_wp_error( $term_id ) ) {
                    if ( $ops ) {
                        $this->audit_push_tax_sync( $type, $ops );
                    }
                    return [ 'error' => $term_id->get_error_message() ];
                }
                $mapping[ $key ] = $term_id;
                // Surface address fields written onto the new term at
                // creation time (ops added by create_taxonomy_term).
                $created_fields = 0;
                for ( $i = $ops_before; $i < count( $ops ); $i++ ) {
                    if ( isset( $ops[ $i ]['type'] ) && 'set_termmeta' === $ops[ $i ]['type'] ) {
                        $created_fields++;
                    }
                }
                if ( $created_fields ) {
                    $fields_written += $created_fields;
                    $results[] = [ 'action' => 'fields', 'term_id' => (int) $term_id, 'written' => $created_fields ];
                }
            }
        }
        if ( $ops ) {
            $this->audit_push_tax_sync( $type, $ops );
        }
        $this->save_taxonomy_mapping( $type, $mapping );
        // Saved Locations term field sync. JS sends sync_fields on the
        // first chunk only, so overwrite mode never records duplicate
        // audit ops for the same write.
        if ( 'locations' === $type && $sync_fields ) {
            $field_map = $this->get_saved_location_field_map();
            if ( $field_map['active'] && $field_map['taxonomy'] === $taxonomy && ! empty( $field_map['slots'] ) ) {
                $field_ops  = [];
                $done_terms = [];
                $conflicts  = 0;
                foreach ( $sources as $key => $source ) {
                    if ( empty( $source['venue_data'] ) ) {
                        continue;
                    }
                    $term_id = isset( $mapping[ $key ] ) ? (int) $mapping[ $key ] : 0;
                    if ( $term_id <= 0 || isset( $done_terms[ $term_id ] ) ) {
                        continue;
                    }
                    $done_terms[ $term_id ] = true;
                    $fr = $this->sync_location_term_fields( $term_id, $source['venue_data'], $field_map, $field_ops, $overwrite );
                    $fields_written += $fr['written'];
                    $conflicts      += $fr['conflicts'];
                    if ( $fr['written'] ) {
                        $results[] = [ 'action' => 'fields', 'term_id' => $term_id, 'written' => $fr['written'] ];
                    }
                }
                if ( $field_ops ) {
                    $this->audit_push_tax_sync( $type, $field_ops );
                }
                $this->log( sprintf(
                    'Taxonomy sync (%s): %d term field(s) written, %d conflict(s) %s, across %d term(s).',
                    $type,
                    $fields_written,
                    $conflicts,
                    $overwrite ? 'overwritten' : 'skipped (fill-empty mode)',
                    count( $done_terms )
                ) );
            } else {
                $results[] = [ 'action' => 'fields_skipped', 'reason' => 'saved_locations_inactive' ];
            }
        }
        $pending = [];
        $seen    = [];
        foreach ( $mapping as $key => $term_id ) {
            $term_id = (int) $term_id;
            if ( $term_id <= 0 || ! isset( $sources[ $key ] ) ) {
                continue;
            }
            foreach ( $sources[ $key ]['events'] as $tec_id => $ek_id ) {
                if ( isset( $seen[ $ek_id . ':' . $term_id ] ) ) {
                    continue;
                }
                $seen[ $ek_id . ':' . $term_id ] = true;
                $current = wp_get_object_terms( $ek_id, $taxonomy, [ 'fields' => 'ids' ] );
                if ( is_wp_error( $current ) ) {
                    continue;
                }
                $current = array_map( 'intval', $current );
                if ( in_array( $term_id, $current, true ) ) {
                    continue;
                }
                $pending[] = [
                    'tec_id'  => $tec_id,
                    'ek_id'   => $ek_id,
                    'term_id' => $term_id,
                    'current' => $current,
                ];
            }
        }
        $batch     = array_slice( $pending, 0, $chunk );
        $applied   = 0;
        $errors    = 0;
        $batch_ops = [];
        foreach ( $batch as $p ) {
            $set = wp_set_object_terms( $p['ek_id'], [ $p['term_id'] ], $taxonomy, true );
            if ( is_wp_error( $set ) ) {
                $errors++;
                $results[] = [ 'action' => 'error', 'ek_id' => $p['ek_id'], 'reason' => $set->get_error_message() ];
                continue;
            }
            $batch_ops[] = [ 'type' => 'set_terms', 'taxonomy' => $taxonomy, 'post' => $p['ek_id'], 'old_term_ids' => $p['current'] ];
            $applied++;
            $results[] = [ 'action' => 'assigned', 'ek_id' => $p['ek_id'], 'tec_id' => $p['tec_id'], 'term_id' => $p['term_id'] ];
        }
        if ( $batch_ops ) {
            $this->audit_push_tax_sync( $type, $batch_ops );
        }
        if ( $applied || $errors ) {
            $this->log( sprintf(
                'Taxonomy sync (%s): %d assigned, %d errors (%d still pending).',
                $type, $applied, $errors, count( $pending ) - count( $batch )
            ) );
        }
        return [
            'processed'      => count( $batch ),
            'applied'        => $applied,
            'errors'         => $errors,
            'fields_written' => $fields_written,
            'done'           => count( $pending ) <= count( $batch ),
            'results'        => $results,
        ];
    }

    /**
     * Undo the last taxonomy sync for a type.
     */
    private function undo_taxonomy_sync( $type ) {
        $option = "ekti_tax_sync_audit_{$type}";
        $audit  = get_option( $option, [] );
        if ( empty( $audit ) ) {
            return [ 'error' => 'Nothing to undo.' ];
        }
        $counts = $this->replay_audit_ops( $audit );
        delete_option( $option );
        $this->log( "Taxonomy sync ({$type}) undo complete. " . wp_json_encode( $counts ) );
        return [ 'undone' => $counts ];
    }

    /* ------------------------------------------------------------------
     * STEP 14 — EVENT SYNC (TEC EVENTS → EVENTKOI EVENTS)
     *
     * Imports actual TEC events into EventKoi, mirroring EventKoi's native
     * importer (eventkoi/includes/core/class-tec-importer.php, v1.4.7.0) so
     * both importers produce identical events and agree on the
     * _tec_import_source_id dedup marker. Ported helpers carry provenance
     * comments; deliberate divergences from native are marked inline:
     *
     *   - Ticket capacity: quantity_available is the FULL TEC capacity and
     *     quantity_sold is pre-set from TEC total_sales (Step 7 model),
     *     instead of native's sold-deducted capacity + give-back.
     *   - No organizer_* postmeta or structured record fields (Step 12
     *     removed those; Taxonomy Sync owns organizers).
     *   - No custom-field conversion (scan reports the count only).
     *   - WooCommerce attendees are NOT imported here — Run Migration owns
     *     them via the _ekti_tec_product_{tec}_{product} bindings this sync
     *     writes. Tickets Commerce attendees DO import here as zero-value
     *     manual orders (native parity; TC has no Woo order to link).
     *   - Hardening for the native importer's Critical Error: RSVP rows are
     *     deduped by email per event+instance (EventKoi's StellarWP wrapper
     *     throws DatabaseQueryException on the duplicate instead of letting
     *     the insert return false), the dedup marker is written immediately
     *     after wp_insert_post (native writes it last, leaving unmarked
     *     duplicate drafts behind on any failure), each event runs inside
     *     its own try/catch with per-event rollback, and the runner is
     *     chunked with a time budget instead of one unchunked request.
     * ------------------------------------------------------------------ */

    /**
     * TEC event IDs eligible for import: publish/draft/pending/future/private
     * posts (native status filter; excludes trash/auto-draft), ordered by ID,
     * minus events that already carry an EventKoi _tec_import_source_id
     * marker on a non-trashed EventKoi event.
     */
    private function get_tec_event_import_ids() {
        global $wpdb;

        $imported = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_tec_import_source_id'
               AND p.post_status != 'trash'"
        );
        $imported = array_map( 'intval', array_filter( $imported, 'is_numeric' ) );

        $sql = "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'tribe_events'
               AND post_status IN ('publish','draft','pending','future','private')";
        if ( $imported ) {
            $sql .= ' AND ID NOT IN (' . implode( ',', array_map( 'intval', $imported ) ) . ')';
        }
        $sql .= ' ORDER BY ID ASC';

        return array_map( 'intval', $wpdb->get_col( $sql ) );
    }

    /**
     * Whether a TEC event is marked all-day. Ported from native
     * TEC_Importer::is_all_day(): modern TEC stores '1', legacy 'yes'.
     */
    private function es_is_all_day( $tec_id ) {
        $raw = get_post_meta( $tec_id, '_EventAllDay', true );
        return in_array( $raw, [ 'yes', '1', 1, true ], true );
    }

    /**
     * Convert a TEC wall-clock datetime to ISO 8601 Zulu. Ported from
     * native TEC_Importer::to_iso_date(). Falls back to the raw string.
     */
    private function es_to_iso_date( $date, $timezone = '' ) {
        if ( empty( $date ) ) {
            return '';
        }
        try {
            $tz = ! empty( $timezone ) ? new DateTimeZone( $timezone ) : wp_timezone();
            $dt = new DateTime( $date, $tz );
            $dt->setTimezone( new DateTimeZone( 'UTC' ) );
            return $dt->format( 'Y-m-d\TH:i:s\Z' );
        } catch ( Exception $e ) {
            return $date;
        }
    }

    /**
     * Format a UTC MySQL datetime as ISO 8601 Zulu. Ported from native
     * TEC_Importer::utc_to_iso().
     */
    private function es_utc_to_iso( $datetime ) {
        if ( empty( $datetime ) ) {
            return '';
        }
        try {
            $dt = new DateTime( (string) $datetime, new DateTimeZone( 'UTC' ) );
            return $dt->format( 'Y-m-d\TH:i:s\Z' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    /**
     * Whether a TEC date range spans more than one calendar day, compared in
     * the source wall-clock timezone. Ported from native
     * TEC_Importer::spans_multiple_days().
     */
    private function es_spans_multiple_days( $start_date, $end_date ) {
        if ( empty( $start_date ) || empty( $end_date ) ) {
            return false;
        }
        try {
            $start = new DateTime( $start_date );
            $end   = new DateTime( $end_date );
            if ( $end > $start && '00:00:00' === $end->format( 'H:i:s' ) ) {
                $end->modify( '-1 second' );
            }
            return $start->format( 'Y-m-d' ) !== $end->format( 'Y-m-d' );
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Every materialized TEC occurrence date as event_days rows (TEC 6.x
     * wp_tec_occurrences). Ported from native
     * TEC_Importer::get_tec_occurrence_days().
     */
    private function es_get_tec_occurrence_days( $tec_id, $all_day ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tec_occurrences';
        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT start_date_utc, end_date_utc FROM `{$table}` WHERE post_id = %d ORDER BY start_date_utc ASC",
            $tec_id
        ) );
        if ( empty( $rows ) ) {
            return [];
        }

        $days = [];
        foreach ( $rows as $row ) {
            $start = $this->es_utc_to_iso( $row->start_date_utc );
            if ( '' === $start ) {
                continue;
            }
            $end     = $this->es_utc_to_iso( $row->end_date_utc );
            $days[] = [
                'start_date' => $start,
                'end_date'   => '' !== $end ? $end : $start,
                'all_day'    => (bool) $all_day,
            ];
        }
        return $days;
    }

    /**
     * Convert TEC Pro recurrence rules to EventKoi recurrence_rules. Ported
     * from native TEC_Importer::convert_recurrence_rules().
     */
    private function es_convert_recurrence_rules( $tec_rules, $start_date, $end_date, $timezone ) {
        $ek_rules  = [];
        $freq_map = [
            'Daily'   => 'day',
            'Weekly'  => 'week',
            'Monthly' => 'month',
            'Yearly'  => 'year',
        ];

        foreach ( (array) $tec_rules as $rule ) {
            if ( empty( $rule['type'] ) ) {
                continue;
            }

            $rule_type = $rule['type'];
            if ( 'Custom' === $rule_type ) {
                $rule_type = $rule['custom']['type'] ?? '';
            }

            $frequency = $freq_map[ $rule_type ] ?? '';
            if ( empty( $frequency ) ) {
                continue;
            }

            $start_iso = $this->es_to_iso_date( $start_date, $timezone );
            $end_iso   = ! empty( $end_date ) ? $this->es_to_iso_date( $end_date, $timezone ) : '';
            $interval  = ! empty( $rule['custom']['interval'] ) ? (int) $rule['custom']['interval'] : 1;

            $ek_rule = [
                'start_date'        => $start_iso,
                'end_date'          => $end_iso,
                'frequency'         => $frequency,
                'every'             => $interval,
                'all_day'           => false,
                'working_days_only' => false,
                'weekdays'          => [],
                'months'            => [],
                'month_day_rule'    => 'day-of-month',
                'month_day_value'   => 1,
                'ends'              => 'never',
                'ends_after'        => 30,
                'ends_on'           => '',
            ];

            try {
                $start_dt                   = new DateTime( $start_iso );
                $ek_rule['months']          = [ (int) $start_dt->format( 'n' ) - 1 ];
                $ek_rule['month_day_value'] = (int) $start_dt->format( 'j' );
            } catch ( Exception $e ) {
                unset( $e );
            }

            if ( 'week' === $frequency && ! empty( $rule['custom']['week']['day'] ) ) {
                $ek_days = [];
                foreach ( (array) $rule['custom']['week']['day'] as $d ) {
                    $d        = (int) $d;
                    $ek_days[] = ( 7 === $d ) ? 0 : $d; // TEC 1=Mon..7=Sun → EK 0=Sun..6=Sat.
                }
                $ek_rule['weekdays'] = $ek_days;
            }

            if ( ! empty( $rule['end-type'] ) ) {
                if ( 'After' === $rule['end-type'] && ! empty( $rule['end-count'] ) ) {
                    $ek_rule['ends']       = 'after';
                    $ek_rule['ends_after'] = (int) $rule['end-count'];
                } elseif ( 'On' === $rule['end-type'] && ! empty( $rule['end'] ) ) {
                    $ek_rule['ends']    = 'on';
                    $ek_rule['ends_on'] = $this->es_to_iso_date( $rule['end'], $timezone );
                }
            }

            $ek_rules[] = $ek_rule;
        }

        return $ek_rules;
    }

    /**
     * Build the EventKoi locations array from the TEC venue. Ported from
     * native TEC_Importer::build_locations().
     */
    private function es_build_locations( $tec_id, $show_map = false ) {
        $venue_id = get_post_meta( $tec_id, '_EventVenueID', true );
        if ( empty( $venue_id ) ) {
            return [];
        }
        $venue = get_post( $venue_id );
        if ( ! $venue ) {
            return [];
        }

        $address = (string) get_post_meta( $venue_id, '_VenueAddress', true );
        $city    = (string) get_post_meta( $venue_id, '_VenueCity', true );
        $state   = (string) get_post_meta( $venue_id, '_VenueState', true );
        $zip     = (string) get_post_meta( $venue_id, '_VenueZip', true );
        $country = (string) get_post_meta( $venue_id, '_VenueCountry', true );
        $lat     = (string) get_post_meta( $venue_id, '_VenueLat', true );
        $lng     = (string) get_post_meta( $venue_id, '_VenueLng', true );

        $address3 = implode( ', ', array_filter( [ $city, $state, $zip, $country ] ) );

        $gmap_link = '';
        if ( '' !== $lat && '' !== $lng ) {
            $gmap_link = 'https://www.google.com/maps?q=' . rawurlencode( $lat . ',' . $lng );
        } else {
            $full = trim( $address . ', ' . $address3, ', ' );
            if ( '' !== $full ) {
                $gmap_link = 'https://www.google.com/maps?q=' . rawurlencode( $full );
            }
        }

        return [
            [
                'id'          => wp_generate_uuid4(),
                'type'        => 'physical',
                'name'        => $venue->post_title,
                'address1'    => $address,
                'address2'    => '',
                'address3'    => $address3,
                'city'        => $city,
                'state'       => $state,
                'country'     => $country,
                'zip'         => $zip,
                'embed_gmap'  => (bool) $show_map,
                'gmap_link'   => $gmap_link,
                'virtual_url' => '',
                'latitude'    => $lat,
                'longitude'   => $lng,
            ],
        ];
    }

    /**
     * Build a virtual-location entry. Ported from native
     * TEC_Importer::build_virtual_location().
     */
    private function es_build_virtual_location( $url ) {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        return [
            'id'          => wp_generate_uuid4(),
            'type'        => 'virtual',
            'name'        => '' !== $host ? $host : 'Event website',
            'address1'    => '',
            'address2'    => '',
            'address3'    => '',
            'city'        => '',
            'state'       => '',
            'country'     => '',
            'zip'         => '',
            'embed_gmap'  => false,
            'gmap_link'   => '',
            'virtual_url' => esc_url_raw( $url ),
            'latitude'    => '',
            'longitude'   => '',
        ];
    }

    /**
     * Canonical attendance type from the locations array. Ported from
     * native TEC_Importer::infer_type_from_locations().
     */
    private function es_infer_type_from_locations( $locations ) {
        $has_physical = false;
        $has_virtual  = false;
        foreach ( (array) $locations as $loc ) {
            $type = sanitize_key( (string) ( $loc['type'] ?? 'physical' ) );
            if ( in_array( $type, [ 'physical', 'inperson' ], true ) ) {
                $has_physical = true;
            } elseif ( in_array( $type, [ 'virtual', 'online' ], true ) ) {
                $has_virtual = true;
            }
        }
        if ( $has_physical && $has_virtual ) {
            return 'mixed';
        }
        return $has_physical ? 'inperson' : 'online';
    }

    /**
     * Virtual Events meeting URL for a TEC event (distinct from _EventURL).
     * Ported from native TEC_Importer::get_tec_virtual_url().
     */
    private function es_get_tec_virtual_url( $tec_id ) {
        if ( ! get_post_meta( $tec_id, '_tribe_events_is_virtual', true ) ) {
            return '';
        }
        return esc_url_raw( (string) get_post_meta( $tec_id, '_tribe_events_virtual_url', true ) );
    }

    /**
     * Structured organizer data from a TEC event. Ported from native
     * TEC_Importer::get_organizer_data(). (Used only for the description
     * tail — no organizer_* postmeta divergence.)
     */
    private function es_get_organizer_data( $tec_id ) {
        $organizer_id = get_post_meta( $tec_id, '_EventOrganizerID', true );
        if ( empty( $organizer_id ) ) {
            return null;
        }
        $organizer = get_post( $organizer_id );
        if ( ! $organizer ) {
            return null;
        }
        return [
            'name'    => (string) $organizer->post_title,
            'email'   => (string) get_post_meta( $organizer_id, '_OrganizerEmail', true ),
            'phone'   => (string) get_post_meta( $organizer_id, '_OrganizerPhone', true ),
            'website' => (string) get_post_meta( $organizer_id, '_OrganizerWebsite', true ),
        ];
    }

    /**
     * Whether imported events should display their timezone. Ported from
     * native TEC_Importer::tec_shows_timezone().
     */
    private function es_tec_shows_timezone() {
        return function_exists( 'tribe_get_option' ) && (bool) tribe_get_option( 'tribe_events_timezones_show_zone', false );
    }

    /**
     * Format a TEC cost value for the description tail. Ported from native
     * TEC_Importer::format_cost_display().
     */
    private function es_format_cost_display( $cost_raw, $currency_symbol, $currency_pos ) {
        if ( '' === $currency_symbol ) {
            return (string) $cost_raw;
        }
        return ( 'suffix' === $currency_pos ) ? $cost_raw . $currency_symbol : $currency_symbol . $cost_raw;
    }

    /**
     * Map a currency symbol to an ISO 4217 code. Ported from native
     * TEC_Importer::infer_currency_code().
     */
    private function es_infer_currency_code( $code_meta, $symbol ) {
        if ( '' !== (string) $code_meta ) {
            return strtoupper( substr( sanitize_text_field( (string) $code_meta ), 0, 3 ) );
        }
        $map = [
            '$'   => 'USD',
            '£'   => 'GBP',
            '€'   => 'EUR',
            '¥'   => 'JPY',
            '₹'   => 'INR',
            '₩'   => 'KRW',
            'C$'  => 'CAD',
            'A$'  => 'AUD',
            'kr'  => 'SEK',
            'CHF' => 'CHF',
            'R$'  => 'BRL',
        ];
        $trim = trim( (string) $symbol );
        return $map[ $trim ] ?? 'USD';
    }

    /**
     * The currency EventKoi checkout charges in. Ported from native
     * TEC_Importer::get_checkout_currency().
     */
    private function es_get_checkout_currency() {
        if ( class_exists( '\EventKoi\Core\WooCommerce_Checkout' )
            && \EventKoi\Core\WooCommerce_Checkout::is_active()
            && function_exists( 'get_woocommerce_currency' ) ) {
            return strtoupper( get_woocommerce_currency() );
        }
        $settings = class_exists( '\EventKoi\Core\Settings' ) ? \EventKoi\Core\Settings::get() : [];
        $currency = strtoupper( sanitize_text_field( (string) ( $settings['currency'] ?? 'USD' ) ) );
        return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD';
    }

    /**
     * The currency Tickets Commerce charges in. Ported from native
     * TEC_Importer::et_currency_code().
     */
    private function es_et_currency_code() {
        $code = function_exists( 'tribe_get_option' ) ? (string) tribe_get_option( 'tickets-commerce-currency-code', 'USD' ) : 'USD';
        $code = strtoupper( sanitize_text_field( $code ) );
        return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : 'USD';
    }

    /**
     * The currency a WooCommerce ticket is priced in. Ported from native
     * TEC_Importer::woo_currency_code().
     */
    private function es_woo_currency_code() {
        if ( ! function_exists( 'get_woocommerce_currency' ) ) {
            return '';
        }
        $code = strtoupper( sanitize_text_field( (string) get_woocommerce_currency() ) );
        return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
    }

    /**
     * Event Tickets ticket posts linked to a TEC event. Ported from native
     * TEC_Importer::get_et_ticket_posts().
     */
    private function es_get_et_ticket_posts( $post_type, $meta_key, $tec_id ) {
        $posts = get_posts( [
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
            'meta_key'       => $meta_key,
            'meta_value'     => $tec_id,
        ] );
        return is_array( $posts ) ? $posts : [];
    }

    /**
     * Read an Event Tickets sale-window boundary as a UTC MySQL datetime.
     * Ported from native TEC_Importer::et_ticket_datetime().
     */
    private function es_et_ticket_datetime( $ticket_id, $date_key, $time_key ) {
        $date = trim( (string) get_post_meta( $ticket_id, $date_key, true ) );
        if ( '' === $date ) {
            return null;
        }
        $time = trim( (string) get_post_meta( $ticket_id, $time_key, true ) );
        if ( '' !== $time && false === strpos( $date, ':' ) ) {
            $date .= ' ' . $time;
        }
        try {
            $dt = new DateTime( $date, wp_timezone() );
            $dt->setTimezone( new DateTimeZone( 'UTC' ) );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return null;
        }
    }

    /**
     * Read a TEC category's primary color (Category Colors term meta).
     * Ported from native TEC_Importer::get_tec_category_color().
     */
    private function es_get_tec_category_color( $term_id ) {
        $raw = trim( (string) get_term_meta( $term_id, 'tec-events-cat-colors-primary', true ) );
        if ( '' === $raw ) {
            return '';
        }
        if ( '#' !== $raw[0] && preg_match( '/^[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $raw ) ) {
            $raw = '#' . $raw;
        }
        $hex = sanitize_hex_color( $raw );
        return is_string( $hex ) ? $hex : '';
    }

    /**
     * Base32 alphabet shared with EventKoi check-in tokens (no ambiguous
     * 0/O/1/I). Ported from native TEC_Importer::rsvp_base32_encode().
     */
    private function es_rsvp_base32_encode( $bytes ) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $buffer   = 0;
        $bits     = 0;
        $output   = '';

        $length = strlen( $bytes );
        for ( $i = 0; $i < $length; $i++ ) {
            $buffer = ( $buffer << 8 ) | ord( $bytes[ $i ] );
            $bits  += 8;
            while ( $bits >= 5 ) {
                $index    = ( $buffer >> ( $bits - 5 ) ) & 31;
                $output  .= $alphabet[ $index ];
                $bits    -= 5;
            }
        }
        if ( $bits > 0 ) {
            $index   = ( $buffer << ( 5 - $bits ) ) & 31;
            $output .= $alphabet[ $index ];
        }
        return $output;
    }

    /**
     * Generate a unique RSVP check-in token. Ported from native
     * TEC_Importer::generate_rsvp_checkin_token(), using $wpdb for the
     * uniqueness probe (the StellarWP wrapper throws on any SQL error).
     */
    private function es_generate_rsvp_checkin_token() {
        global $wpdb;
        $max_attempts = 5;
        for ( $i = 0; $i < $max_attempts; $i++ ) {
            $token = substr( $this->es_rsvp_base32_encode( random_bytes( 8 ) ), 0, 12 );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$this->ek_table( 'rsvps' )} WHERE checkin_token = %s LIMIT 1",
                $token
            ) );
            if ( null === $exists ) {
                return $token;
            }
        }
        return substr( $this->es_rsvp_base32_encode( random_bytes( 8 ) ), 0, 12 );
    }

    /**
     * Read-only Event Sync scan: eligibility counts, per-provider ticket and
     * attendee volumes, currency state, custom-field count (report-only),
     * adoption status for natively-imported events, and review items
     * (duplicate-email RSVP events, orphan/trashed RSVP sources, events
     * without a start date).
     */
    private function scan_event_sync() {
        global $wpdb;

        $imported_markers = $wpdb->get_results(
            "SELECT pm.post_id AS ek_id, pm.meta_value AS tec_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_tec_import_source_id'
               AND p.post_status != 'trash'
               AND p.post_type = 'eventkoi_event'",
            ARRAY_A
        );
        $imported_tec_ids = [];
        foreach ( $imported_markers as $m ) {
            $imported_tec_ids[ (int) $m['tec_id'] ] = (int) $m['ek_id'];
        }

        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_status FROM {$wpdb->posts}
             WHERE post_type = 'tribe_events'
               AND post_status IN ('publish','draft','pending','future','private')",
            ARRAY_A
        );

        $unimported = [];
        $titles     = [];
        $by_status  = [ 'publish' => 0, 'draft' => 0, 'pending' => 0, 'future' => 0, 'private' => 0 ];
        foreach ( $posts as $p ) {
            $titles[ (int) $p['ID'] ] = $p['post_title'];
            if ( isset( $imported_tec_ids[ (int) $p['ID'] ] ) ) {
                continue;
            }
            $unimported[] = (int) $p['ID'];
            if ( isset( $by_status[ $p['post_status'] ] ) ) {
                $by_status[ $p['post_status'] ]++;
            }
        }

        $counts = [
            'tec_total'          => count( $posts ),
            'imported'           => count( $imported_tec_ids ),
            'unimported'         => count( $unimported ),
            'by_status'          => $by_status,
            'woo_ticket_events'  => 0,
            'woo_tickets'        => 0,
            'tc_ticket_events'   => 0,
            'tc_tickets'         => 0,
            'rsvp_ticket_events' => 0,
            'rsvp_tickets'       => 0,
            'rsvp_capacity'      => 0,
            'woo_attendees'      => 0,
            'tc_attendees'       => 0,
            'rsvp_attendees'     => 0,
            'rsvp_unique_emails' => 0,
            'rsvp_merged'        => 0,
            'recurring'          => 0,
            'all_day'            => 0,
            'virtual'            => 0,
            'with_images'        => 0,
            'custom_field_events'=> 0,
        ];

        $review = [];
        $in     = $unimported ? implode( ',', $unimported ) : '0';

        if ( $unimported ) {
            // Ticket posts per provider.
            $ticket_rows = $wpdb->get_results(
                "SELECT p.post_type, pm.meta_value AS tec_id, p.ID AS ticket_id
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                  AND pm.meta_key IN ('_tribe_rsvp_for_event','_tec_tickets_commerce_event','_tribe_wooticket_for_event')
                 WHERE p.post_type IN ('tribe_rsvp_tickets','tec_tc_ticket','product')
                   AND pm.meta_value IN ({$in})",
                ARRAY_A
            );
            $ticket_events = [ 'tribe_rsvp_tickets' => [], 'tec_tc_ticket' => [], 'product' => [] ];
            foreach ( $ticket_rows as $t ) {
                $ticket_events[ $t['post_type'] ][ (int) $t['tec_id'] ] = true;
            }
            $counts['rsvp_ticket_events'] = count( $ticket_events['tribe_rsvp_tickets'] );
            $counts['rsvp_tickets']       = count( array_filter( $ticket_rows, function ( $t ) {
                return 'tribe_rsvp_tickets' === $t['post_type'];
            } ) );
            $counts['tc_ticket_events']   = count( $ticket_events['tec_tc_ticket'] );
            $counts['tc_tickets']         = count( array_filter( $ticket_rows, function ( $t ) {
                return 'tec_tc_ticket' === $t['post_type'];
            } ) );
            $counts['woo_ticket_events']  = count( $ticket_events['product'] );
            $counts['woo_tickets']        = count( array_filter( $ticket_rows, function ( $t ) {
                return 'product' === $t['post_type'];
            } ) );

            // RSVP capacity (RSVP-only events use it as EventKoi rsvp_capacity).
            $rsvp_ticket_ids = array_map( 'intval', array_filter( array_map( function ( $t ) {
                return 'tribe_rsvp_tickets' === $t['post_type'] ? (int) $t['ticket_id'] : 0;
            }, $ticket_rows ) ) );
            if ( $rsvp_ticket_ids ) {
                $in_r  = implode( ',', $rsvp_ticket_ids );
                $caps  = $wpdb->get_col(
                    "SELECT meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = '_tribe_ticket_capacity' AND post_id IN ({$in_r})"
                );
                foreach ( $caps as $c ) {
                    $counts['rsvp_capacity'] += max( 0, (int) $c );
                }
            }

            // WooCommerce attendees (Run Migration imports them — report only).
            $counts['woo_attendees'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                  AND pm.meta_key = '_tribe_wooticket_event'
                 WHERE p.post_type = 'tribe_wooticket'
                   AND p.post_status = 'publish'
                   AND pm.meta_value IN ({$in})"
            );

            // Completed Tickets Commerce attendees (imported by this sync).
            $counts['tc_attendees'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                  AND pm.meta_key = '_tec_tickets_commerce_event'
                 INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID
                  AND st.meta_key = '_tec_tickets_commerce_status'
                 WHERE p.post_type = 'tec_tc_attendee'
                   AND st.meta_value = 'completed'
                   AND pm.meta_value IN ({$in})"
            );

            // RSVP attendee rows + duplicate-email math per event.
            $rsvp_rows = $wpdb->get_results(
                "SELECT pm.meta_value AS tec_id, a.ID AS attendee_id, e.meta_value AS email
                 FROM {$wpdb->posts} a
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = a.ID
                  AND pm.meta_key = '_tribe_rsvp_event'
                 LEFT JOIN {$wpdb->postmeta} e ON e.post_id = a.ID
                  AND e.meta_key = '_tribe_rsvp_email'
                 WHERE a.post_type = 'tribe_rsvp_attendees'
                   AND pm.meta_value IN ({$in})",
                ARRAY_A
            );
            $by_event = [];
            foreach ( $rsvp_rows as $r ) {
                $by_event[ (int) $r['tec_id'] ][] = strtolower( trim( (string) $r['email'] ) );
            }
            foreach ( $by_event as $tec_id => $emails ) {
                $unique  = count( array_unique( array_filter( $emails ) ) );
                $rows    = count( $emails );
                $counts['rsvp_attendees']     += $rows;
                $counts['rsvp_unique_emails'] += $unique;
                $counts['rsvp_merged']        += $rows - $unique;
                if ( $unique < $rows ) {
                    $review[] = [
                        'tec_id' => $tec_id,
                        'title'  => $titles[ $tec_id ] ?? ( '#' . $tec_id ),
                        'reason' => 'Duplicate RSVP emails (native importer fatals here)',
                        'detail' => $rows . ' RSVP rows / ' . $unique . ' unique emails — ' . ( $rows - $unique ) . ' duplicate row(s) will be merged during import',
                    ];
                }
            }

            // Events without a start date (cannot be imported).
            $no_start = $wpdb->get_col(
                "SELECT p.ID FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                  AND pm.meta_key = '_EventStartDate'
                 WHERE p.ID IN ({$in})
                   AND (pm.meta_value IS NULL OR pm.meta_value = '')"
            );
            foreach ( (array) $no_start as $tec_id ) {
                $review[] = [
                    'tec_id' => (int) $tec_id,
                    'title'  => $titles[ (int) $tec_id ] ?? ( '#' . $tec_id ),
                    'reason' => 'No start date',
                    'detail' => 'The event has no _EventStartDate; it will be reported as an error during the run.',
                ];
            }

            // Shape profile: recurring / all-day / virtual / featured images.
            $counts['recurring'] = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = '_EventRecurrence'
                   AND pm.meta_value != ''
                   AND pm.meta_value NOT LIKE '%\"rules\";a:0%'
                   AND pm.post_id IN ({$in})"
            );
            $counts['all_day'] = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = '_EventAllDay'
                   AND pm.meta_value IN ('yes','1')
                   AND pm.post_id IN ({$in})"
            );
            $counts['virtual'] = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = '_tribe_events_is_virtual'
                   AND pm.meta_value NOT IN ('','0')
                   AND pm.post_id IN ({$in})"
            );
            $counts['with_images'] = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = '_thumbnail_id'
                   AND pm.meta_value != ''
                   AND pm.post_id IN ({$in})"
            );

            // Custom-field count (report-only — this sync does not convert them).
            $public_meta = $wpdb->get_results(
                "SELECT DISTINCT pm.post_id, pm.meta_key FROM {$wpdb->postmeta} pm
                 WHERE pm.post_id IN ({$in})
                   AND pm.meta_key NOT LIKE '\\_%'
                   AND pm.meta_key NOT IN (
                     'EventStartDate','EventEndDate','EventAllDay','EventStartTime','EventEndTime',
                     'EventTimezone','EventTimezoneAbbr','EventURL','EventCost',
                     'EventCurrencySymbol','EventCurrencyPosition','EventCurrencyCode',
                     'EventShowMap','EventShowMapLink','EventVenueID','EventOrganizerID',
                     'EventOrigin','EventHideFromUpcoming'
                   )
                   AND pm.meta_value != ''",
                ARRAY_A
            );
            $ignored_prefixes = [ 'rank_math_', 'aioseo_', 'seopress_', 'wpseo_', 'jetpack_', 'monsterinsights_', 'footnotes', 'litespeed_', 'stackable_' ];
            $cf_events = [];
            foreach ( (array) $public_meta as $pm ) {
                $skip = false;
                foreach ( $ignored_prefixes as $pref ) {
                    if ( '' !== $pref && 0 === strpos( $pm['meta_key'], $pref ) ) {
                        $skip = true;
                        break;
                    }
                }
                if ( ! $skip ) {
                    $cf_events[ (int) $pm['post_id'] ] = true;
                }
            }
            $counts['custom_field_events'] = count( $cf_events );
        }

        // Orphan / trashed RSVP attendee sources (never importable — report only).
        $orphan_rows = $wpdb->get_results(
            "SELECT DISTINCT pm.meta_value AS tec_id, e.post_status AS status, COUNT(a.ID) AS attendees
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} a ON a.ID = pm.post_id AND a.post_type = 'tribe_rsvp_attendees'
             LEFT JOIN {$wpdb->posts} e ON e.ID = pm.meta_value AND e.post_type = 'tribe_events'
             WHERE pm.meta_key = '_tribe_rsvp_event'
               AND (e.ID IS NULL OR e.post_status = 'trash')
             GROUP BY pm.meta_value, e.post_status",
            ARRAY_A
        );
        foreach ( (array) $orphan_rows as $o ) {
            $is_trashed = ! empty( $o['status'] ) && 'trash' === $o['status'];
            $review[]   = [
                'tec_id' => (int) $o['tec_id'],
                'title'  => $titles[ (int) $o['tec_id'] ] ?? ( '#' . $o['tec_id'] ),
                'reason' => $is_trashed ? 'RSVP source event is in Trash' : 'RSVP source event was deleted',
                'detail' => (int) $o['attendees'] . ' RSVP attendee row(s) reference this TEC event; they are outside the import scope (not imported, report only).',
            ];
        }

        // Orphan / trashed Tickets Commerce attendee sources (same report-only
        // treatment). On this dataset all TC parent posts were permanently
        // deleted by the old event-cleanup crons, so TC counts surface as 0
        // and this review block is the operator's explanation why. Aggregated
        // into one item per status — the per-event detail would be 50+ rows
        // of the same finding.
        $tc_orphan_rows = $wpdb->get_results(
            "SELECT e.post_status AS status, COUNT(DISTINCT pm.meta_value) AS events, COUNT(a.ID) AS attendees
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} a ON a.ID = pm.post_id AND a.post_type = 'tec_tc_attendee'
             LEFT JOIN {$wpdb->posts} e ON e.ID = pm.meta_value AND e.post_type = 'tribe_events'
             WHERE pm.meta_key = '_tec_tickets_commerce_event'
               AND (e.ID IS NULL OR e.post_status = 'trash')
             GROUP BY e.post_status",
            ARRAY_A
        );
        foreach ( (array) $tc_orphan_rows as $o ) {
            $is_trashed = ! empty( $o['status'] ) && 'trash' === $o['status'];
            $review[]   = [
                'tec_id' => 0,
                'title'  => (int) $o['events'] . ' Tickets Commerce source event(s)',
                'reason' => $is_trashed ? 'Tickets Commerce source events are in Trash' : 'Tickets Commerce source events were deleted',
                'detail' => (int) $o['attendees'] . ' Tickets Commerce attendee row(s) reference ' . (int) $o['events'] . ' TEC event(s) with no live post; they are outside the import scope (not imported, report only).',
            ];
        }

        // Currency state (native zeroed prices on mismatch — this sync keeps parity).
        $et_currency      = $this->es_et_currency_code();
        $checkout_currency = $this->es_get_checkout_currency();
        $woo_currency     = $this->es_woo_currency_code();
        $currency = [
            'et'       => $et_currency,
            'checkout' => $checkout_currency,
            'woo'      => $woo_currency,
            'tc_mismatch' => $et_currency !== $checkout_currency,
            'woo_mismatch' => '' !== $woo_currency && $woo_currency !== $checkout_currency,
        ];

        // Native-import adoption status.
        $adoption = $this->scan_native_adoption();

        return [
            'counts'   => $counts,
            'currency' => $currency,
            'review'   => $review,
            'adoption' => $adoption,
        ];
    }

    /**
     * Which natively-imported events still have unflagged TEC WooCommerce
     * attendees. "Adopt" flags those attendee posts _eventkoi_imported_from_tec
     * so Run Migration will not double-import attendees EventKoi's native
     * importer already brought over. Only events whose EventKoi copy already
     * has ticket-order rows count as native adoptions — this sync's own
     * events have no orders until Run Migration runs.
     */
    private function scan_native_adoption() {
        global $wpdb;

        $markers = $wpdb->get_results(
            "SELECT pm.post_id AS ek_id, pm.meta_value AS tec_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_tec_import_source_id'
               AND p.post_status != 'trash'
               AND p.post_type = 'eventkoi_event'",
            ARRAY_A
        );

        $candidate_events = 0;
        $unflagged        = 0;
        $events           = [];
        foreach ( (array) $markers as $m ) {
            $ek_id  = (int) $m['ek_id'];
            $tec_id = (int) $m['tec_id'];

            $order_rows = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->ek_table( 'ticket_orders' )} WHERE event_id = %d",
                $ek_id
            ) );
            if ( 0 === $order_rows ) {
                continue; // No EventKoi attendee history — nothing was natively imported.
            }

            $unflagged_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT a.ID FROM {$wpdb->posts} a
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = a.ID
                  AND pm.meta_key = '_tribe_wooticket_event' AND pm.meta_value = %s
                 LEFT JOIN {$wpdb->postmeta} f ON f.post_id = a.ID
                  AND f.meta_key = '_eventkoi_imported_from_tec'
                 WHERE a.post_type = 'tribe_wooticket'
                   AND a.post_status = 'publish'
                   AND (f.meta_id IS NULL OR f.meta_value = '' OR f.meta_value = '0')",
                (string) $tec_id
            ), ARRAY_A );
            if ( empty( $unflagged_rows ) ) {
                continue;
            }

            $candidate_events++;
            $unflagged   += count( $unflagged_rows );
            $events[]    = [
                'tec_id'    => $tec_id,
                'ek_id'     => $ek_id,
                'title'     => get_the_title( $ek_id ),
                'attendees' => count( $unflagged_rows ),
                'orders'    => $order_rows,
            ];
        }

        return [
            'marked_events' => count( (array) $markers ),
            'candidate_events' => $candidate_events,
            'unflagged_attendees' => $unflagged,
            'events' => $events,
        ];
    }

    /**
     * Import one TEC event into EventKoi. Mirrors native
     * TEC_Importer::import_single_event() with the divergences documented in
     * the Step 14 header. All writes are recorded in $ops for undo; on a
     * mid-event failure the caller replays the event's ops to roll it back.
     *
     * @param int   $tec_id        TEC event post ID.
     * @param bool  $import_images Whether to reuse the TEC featured image.
     * @param array $ops           Audit ledger (appended by reference).
     * @return array|WP_Error Result summary, or WP_Error.
     */
    private function import_one_event( $tec_id, $import_images, &$ops ) {
        global $wpdb;
        $tec_id = (int) $tec_id;

        // Re-check the dedup marker at execution time (runner resumability guard).
        $existing = get_posts( [
            'post_type'      => 'eventkoi_event',
            'post_status'    => 'any',
            'meta_key'       => '_tec_import_source_id',
            'meta_value'     => $tec_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] );
        if ( ! empty( $existing ) ) {
            return new WP_Error( 'already_imported', 'Already imported (marker present).' );
        }

        $post = get_post( $tec_id );
        if ( ! $post || 'tribe_events' !== $post->post_type ) {
            return new WP_Error( 'invalid_event', 'TEC event not found.' );
        }

        $notes      = [];
        $title      = $post->post_title;
        $status     = $post->post_status;
        $start_date = get_post_meta( $tec_id, '_EventStartDate', true );
        $end_date   = get_post_meta( $tec_id, '_EventEndDate', true );
        $timezone   = get_post_meta( $tec_id, '_EventTimezone', true );
        $all_day    = $this->es_is_all_day( $tec_id );

        if ( empty( $start_date ) ) {
            return new WP_Error( 'no_start_date', 'Event has no start date.' );
        }

        // All-day events use an inclusive last day (native parity).
        if ( $all_day && ! empty( $end_date ) ) {
            $end_date = substr( (string) $end_date, 0, 10 ) . ' 23:59:59';
        }

        $start_iso = $this->es_to_iso_date( $start_date, $timezone );
        $end_iso   = ! empty( $end_date ) ? $this->es_to_iso_date( $end_date, $timezone ) : '';

        $is_multi_day  = $this->es_spans_multiple_days( $start_date, $end_date );
        $standard_type = $is_multi_day ? 'continuous' : 'selected';

        $event_days = [];
        if ( ! $is_multi_day ) {
            $event_days[] = [
                'start_date' => $start_iso,
                'end_date'   => '' !== $end_iso ? $end_iso : $start_iso,
                'all_day'    => $all_day,
            ];
        } elseif ( $all_day ) {
            $standard_type = 'continuous';
            $event_days[]  = [
                'start_date' => $start_iso,
                'end_date'   => '' !== $end_iso ? $end_iso : $start_iso,
                'all_day'    => true,
            ];
        }

        // Locations from venue; a Virtual Events URL becomes a virtual location.
        $show_map  = '1' === get_post_meta( $tec_id, '_EventShowMap', true );
        $locations = $this->es_build_locations( $tec_id, $show_map );
        $event_url   = (string) get_post_meta( $tec_id, '_EventURL', true );
        $virtual_url = $this->es_get_tec_virtual_url( $tec_id );
        if ( '' !== $virtual_url ) {
            $locations[] = $this->es_build_virtual_location( $virtual_url );
        }
        $event_type = ! empty( $locations ) ? $this->es_infer_type_from_locations( $locations ) : 'inperson';

        // Recurrence (TEC Pro).
        $date_type        = 'standard';
        $recurrence_rules = [];
        $recurrence_meta  = get_post_meta( $tec_id, '_EventRecurrence', true );
        if ( ! empty( $recurrence_meta ) && is_array( $recurrence_meta ) && ! empty( $recurrence_meta['rules'] ) ) {
            $converted = $this->es_convert_recurrence_rules( $recurrence_meta['rules'], $start_date, $end_date, $timezone );
            if ( ! empty( $converted ) ) {
                $date_type        = 'recurring';
                $recurrence_rules = $converted;
                $event_days       = [];
            }
        }
        if ( 'standard' === $date_type ) {
            $occurrence_days = $this->es_get_tec_occurrence_days( $tec_id, $all_day );
            if ( count( $occurrence_days ) > 1 ) {
                $standard_type = 'selected';
                $event_days    = $occurrence_days;
            }
        }

        $new_post_id = wp_insert_post( [
            'post_type'   => 'eventkoi_event',
            'post_status' => $status,
            'post_title'  => $title,
            'post_name'   => '' !== $post->post_name ? $post->post_name : sanitize_title_with_dashes( $title, '', 'save' ),
            'post_author' => get_current_user_id(),
        ], true );
        if ( is_wp_error( $new_post_id ) ) {
            return $new_post_id;
        }
        $new_post_id = (int) $new_post_id;
        $ops[]       = [ 'type' => 'insert_post', 'id' => $new_post_id ];

        // Critical-Error hardening: write the dedup marker immediately so a
        // failure later in this event can never leave an unmarked duplicate
        // draft behind (native writes it last — its three failed runs each
        // stranded a second copy of the event).
        update_post_meta( $new_post_id, '_tec_import_source_id', $tec_id );

        // Ticket types (RSVP / Tickets Commerce / WooCommerce).
        $et_tickets = $this->import_event_tickets_sync( $tec_id, $new_post_id, $ops, $notes );

        $cost_raw        = get_post_meta( $tec_id, '_EventCost', true );
        $currency_symbol = get_post_meta( $tec_id, '_EventCurrencySymbol', true );
        $currency_pos    = get_post_meta( $tec_id, '_EventCurrencyPosition', true );
        $currency_code   = get_post_meta( $tec_id, '_EventCurrencyCode', true );

        $ticket_id = 0;
        if ( ! $et_tickets['found'] ) {
            $ticket_id = $this->maybe_create_ticket_from_cost_sync( $new_post_id, $cost_raw, $currency_symbol, $currency_code, $ops );
        }

        $attendance_mode = ( $ticket_id || $et_tickets['imported'] > 0 ) ? 'tickets' : 'none';
        if ( 'rsvp' === $et_tickets['attendance_mode'] ) {
            $attendance_mode = 'rsvp';
        }

        // Description: TEC body through wpautop, plus organizer/cost tail.
        $description    = wpautop( (string) $post->post_content );
        $organizer_data = $this->es_get_organizer_data( $tec_id );
        $tail           = [];

        if ( $organizer_data && ! empty( $organizer_data['name'] ) ) {
            $lines = [ '<strong>Organizer:</strong> ' . esc_html( $organizer_data['name'] ) ];
            if ( ! empty( $organizer_data['email'] ) ) {
                $lines[] = 'Email: <a href="mailto:' . esc_attr( $organizer_data['email'] ) . '">' . esc_html( $organizer_data['email'] ) . '</a>';
            }
            if ( ! empty( $organizer_data['phone'] ) ) {
                $lines[] = 'Phone: ' . esc_html( $organizer_data['phone'] );
            }
            if ( ! empty( $organizer_data['website'] ) ) {
                $lines[] = 'Website: <a href="' . esc_url( $organizer_data['website'] ) . '">' . esc_html( $organizer_data['website'] ) . '</a>';
            }
            $tail[] = '<p>' . implode( '<br>', $lines ) . '</p>';
        }

        if ( ! $ticket_id && ! $et_tickets['found'] && '' !== (string) $cost_raw ) {
            $cost_display = $this->es_format_cost_display( $cost_raw, $currency_symbol, $currency_pos );
            $tail[]       = '<p><strong>Cost:</strong> ' . esc_html( $cost_display ) . '</p>';
        }

        if ( ! empty( $tail ) ) {
            $description .= "\n" . implode( "\n", $tail );
        }

        update_post_meta( $new_post_id, 'description', wp_kses_post( $description ) );
        update_post_meta( $new_post_id, 'date_type', $date_type );
        update_post_meta( $new_post_id, 'start_date', $start_iso );
        update_post_meta( $new_post_id, 'start_timestamp', strtotime( $start_iso ) );
        update_post_meta( $new_post_id, 'event_days', $event_days );
        update_post_meta( $new_post_id, 'locations', $locations );
        update_post_meta( $new_post_id, 'type', $event_type );
        update_post_meta( $new_post_id, 'standard_type', $standard_type );
        update_post_meta( $new_post_id, 'embed_gmap', $show_map );

        // Mirror the first virtual URL into legacy top-level meta.
        $virtual_url_legacy = '';
        foreach ( $locations as $loc ) {
            if ( in_array( ( $loc['type'] ?? '' ), [ 'virtual', 'online' ], true ) && ! empty( $loc['virtual_url'] ) ) {
                $virtual_url_legacy = (string) $loc['virtual_url'];
                break;
            }
        }
        update_post_meta( $new_post_id, 'virtual_url', $virtual_url_legacy );
        update_post_meta( $new_post_id, 'recurrence_rules', $recurrence_rules );
        update_post_meta( $new_post_id, 'attendance_mode', $attendance_mode );
        update_post_meta( $new_post_id, 'tickets_enabled', 'tickets' === $attendance_mode );
        update_post_meta( $new_post_id, 'timezone_display', $this->es_tec_shows_timezone() );

        if ( 'rsvp' === $attendance_mode ) {
            update_post_meta( $new_post_id, 'rsvp_enabled', true );
            if ( $et_tickets['rsvp_capacity'] > 0 ) {
                update_post_meta( $new_post_id, 'rsvp_capacity', $et_tickets['rsvp_capacity'] );
            }
        }

        // Shared (capped/global) Event Tickets stock maps to the per-event total.
        if ( $et_tickets['event_capacity'] > 0 ) {
            update_post_meta( $new_post_id, 'tickets_event_capacity', $et_tickets['event_capacity'] );
        }

        // Attendee history: RSVP rows for RSVP-only events, Tickets Commerce
        // attendees as manual orders otherwise. WooCommerce attendees are
        // deliberately left for Run Migration (see Step 14 header).
        $attendees = $this->import_event_attendees_sync( $tec_id, $new_post_id, $et_tickets, $ops, $notes );

        if ( ! empty( $end_iso ) ) {
            update_post_meta( $new_post_id, 'end_date', $end_iso );
            update_post_meta( $new_post_id, 'end_timestamp', strtotime( $end_iso ) );
        }
        if ( ! empty( $timezone ) ) {
            update_post_meta( $new_post_id, 'timezone', $timezone );
        }

        // Legacy top-level location fields kept in sync with the primary entry.
        if ( ! empty( $locations[0] ) ) {
            $primary = $locations[0];
            update_post_meta( $new_post_id, 'location', $primary );
            update_post_meta( $new_post_id, 'address1', (string) ( $primary['address1'] ?? '' ) );
            update_post_meta( $new_post_id, 'address2', (string) ( $primary['address2'] ?? '' ) );
            update_post_meta( $new_post_id, 'address3', (string) ( $primary['address3'] ?? '' ) );
            update_post_meta( $new_post_id, 'latitude', (string) ( $primary['latitude'] ?? '' ) );
            update_post_meta( $new_post_id, 'longitude', (string) ( $primary['longitude'] ?? '' ) );
            update_post_meta( $new_post_id, 'gmap_link', (string) ( $primary['gmap_link'] ?? '' ) );
        }

        // TEC categories → EventKoi calendars (with color backfill).
        $this->map_categories_sync( $tec_id, $new_post_id, $ops );

        // Featured image: reuse the TEC attachment (no sideload).
        if ( $import_images ) {
            $thumbnail_id = get_post_thumbnail_id( $tec_id );
            if ( $thumbnail_id ) {
                set_post_thumbnail( $new_post_id, $thumbnail_id );
                $image_url = wp_get_attachment_url( $thumbnail_id );
                if ( $image_url ) {
                    update_post_meta( $new_post_id, 'image', $image_url );
                    update_post_meta( $new_post_id, 'image_id', $thumbnail_id );
                }
            }
        }

        // Preserve TEC's Event Website link as a URL custom field.
        if ( '' !== $event_url ) {
            $this->import_event_website_field_sync( $new_post_id, $event_url, $ops );
        }

        // Rebuild the admin upcoming index (wp_insert_post fired save_post
        // before any date meta existed — without this the event never appears
        // in EventKoi's All/Upcoming lists).
        if ( class_exists( '\\EventKoi\\Core\\Upcoming_Index' ) ) {
            \EventKoi\Core\Upcoming_Index::rebuild_event( $new_post_id );
        }

        // Keep the saved mapping in step so Auto-Match / Run Migration agree.
        $old_mapping = $this->get_saved_mapping();
        if ( (int) ( $old_mapping[ $tec_id ] ?? 0 ) !== $new_post_id ) {
            $new_mapping = $old_mapping;
            $new_mapping[ $tec_id ] = $new_post_id;
            $this->save_mapping( $new_mapping );
            $ops[] = [ 'type' => 'update_option', 'name' => 'ekti_event_mapping', 'old' => $old_mapping ];
        }

        return [
            'tec_id'           => $tec_id,
            'event_id'         => $new_post_id,
            'title'            => $title,
            'status'           => $status,
            'attendance_mode'  => $attendance_mode,
            'tickets'          => $et_tickets['imported'],
            'cost_ticket'      => (bool) $ticket_id,
            'tc_attendees'     => $attendees['tc_attendees'],
            'rsvp_rows'        => $attendees['rsvp_rows'],
            'rsvp_merged'      => $attendees['rsvp_merged'],
            'rsvp_skipped'     => $attendees['rsvp_skipped'],
            'woo_attendees_skipped' => $attendees['woo_attendees_skipped'],
            'bindings'         => $et_tickets['bindings'],
            'notes'            => $notes,
        ];
    }

    /**
     * Import Event Tickets ticket types for one event. Mirrors native
     * TEC_Importer::import_event_tickets() with the Step 14 capacity
     * divergence: quantity_available is the FULL capacity and quantity_sold
     * is pre-set from TEC total_sales (native deducts sold seats from the
     * capacity and gives them back after attendee import).
     *
     * Also writes _ekti_tec_product_{tec}_{product} bindings for WooCommerce
     * products so Run Migration binds its attendee import to the tickets
     * created here (Step 7 pattern).
     *
     * @return array Native-shaped summary plus 'bindings' count.
     */
    private function import_event_tickets_sync( $tec_id, $new_post_id, &$ops, &$notes ) {
        $summary = [
            'found'           => false,
            'attendance_mode' => '',
            'imported'        => 0,
            'skipped'         => [],
            'rsvp_capacity'   => 0,
            'event_capacity'  => 0,
            'currency'        => '',
            'ticket_id_map'   => [],
            'sold_by_ticket'  => [],
            'sold_total'      => 0,
            'bindings'        => 0,
        ];

        if ( ! function_exists( 'eventkoi_is_tickets_feature_enabled' ) || ! eventkoi_is_tickets_feature_enabled() ) {
            return $summary;
        }

        $rsvp_tickets = $this->es_get_et_ticket_posts( 'tribe_rsvp_tickets', '_tribe_rsvp_for_event', $tec_id );
        $paid_tickets = $this->es_get_et_ticket_posts( 'tec_tc_ticket', '_tec_tickets_commerce_event', $tec_id );
        $woo_tickets  = $this->es_get_et_ticket_posts( 'product', '_tribe_wooticket_for_event', $tec_id );

        if ( empty( $rsvp_tickets ) && empty( $paid_tickets ) && empty( $woo_tickets ) ) {
            return $summary;
        }

        $summary['found'] = true;

        // RSVP-only events use EventKoi's native RSVP mode; no ticket rows.
        if ( empty( $paid_tickets ) && empty( $woo_tickets ) ) {
            $summary['attendance_mode'] = 'rsvp';
            foreach ( $rsvp_tickets as $rsvp_ticket ) {
                $summary['rsvp_capacity'] += max( 0, (int) get_post_meta( $rsvp_ticket->ID, '_tribe_ticket_capacity', true ) );
            }
            return $summary;
        }

        $summary['attendance_mode'] = 'tickets';

        $checkout_currency   = $this->es_get_checkout_currency();
        $summary['currency'] = $checkout_currency;
        $tc_currency_ok      = $this->es_et_currency_code() === $checkout_currency;
        $woo_currency        = $this->es_woo_currency_code();
        $woo_currency_ok     = '' !== $woo_currency && $woo_currency === $checkout_currency;
        $unpriced_paid       = 0;
        $unpriced_woo        = 0;
        $sold_total          = 0;
        $sort_order          = 0;

        foreach ( array_merge( $paid_tickets, $rsvp_tickets, $woo_tickets ) as $et_ticket ) {
            $is_woo = 'product' === $et_ticket->post_type;
            $price  = (float) get_post_meta( $et_ticket->ID, '_price', true );

            if ( $price > 0 ) {
                if ( $is_woo && ! $woo_currency_ok ) {
                    $price = 0;
                    ++$unpriced_woo;
                } elseif ( ! $is_woo && ! $tc_currency_ok ) {
                    $price = 0;
                    ++$unpriced_paid;
                }
            }

            $sold = max( 0, (int) get_post_meta( $et_ticket->ID, 'total_sales', true ) );

            $inserted = $this->insert_imported_ticket_sync( $new_post_id, $et_ticket, $price, $checkout_currency, $sold, $sort_order, $ops );

            if ( $inserted['id'] ) {
                ++$summary['imported'];
                ++$sort_order;
                $sold_total += $sold;

                $summary['ticket_id_map'][ (int) $et_ticket->ID ] = $inserted['id'];

                // WooCommerce binding so Run Migration attaches to this ticket.
                if ( $is_woo ) {
                    $opt = '_ekti_tec_product_' . $tec_id . '_' . $et_ticket->ID;
                    $old = get_option( $opt );
                    update_option( $opt, (int) $inserted['id'] );
                    $ops[] = $old
                        ? [ 'type' => 'update_option', 'name' => $opt, 'old' => $old ]
                        : [ 'type' => 'add_option', 'name' => $opt ];
                    ++$summary['bindings'];
                }
            }
        }

        $summary['sold_total'] = $sold_total;

        if ( $unpriced_paid > 0 ) {
            $summary['skipped'][] = $unpriced_paid . ' paid ticket(s) imported without pricing: Event Tickets charges ' . $this->es_et_currency_code() . ' while checkout is ' . $checkout_currency . '.';
        }
        if ( $unpriced_woo > 0 ) {
            $summary['skipped'][] = $unpriced_woo . ' WooCommerce ticket(s) imported without pricing: WooCommerce charges ' . $woo_currency . ' while checkout is ' . $checkout_currency . '.';
        }
        if ( $sold_total > 0 ) {
            $notes[] = 'quantity_sold pre-set to TEC total_sales for ' . $sold_total . ' previously sold seat(s) (Step 7 capacity model).';
        }

        // Shared (capped/global) stock maps to the per-event total capacity.
        // Unlike native, no sold deduction: quantity_sold already covers it.
        $use_global = (string) get_post_meta( $tec_id, '_tribe_ticket_use_global_stock', true );
        if ( in_array( $use_global, [ '1', 'yes', 'true' ], true ) ) {
            $summary['event_capacity'] = max( 0, (int) get_post_meta( $tec_id, '_tribe_ticket_global_stock_level', true ) );
        }

        return $summary;
    }

    /**
     * Insert one imported Event Tickets type as an eventkoi_tickets row.
     * Mirrors native TEC_Importer::insert_imported_ticket() with the Step 14
     * divergence: full capacity + pre-set quantity_sold (native stores
     * capacity-minus-sold and leaves quantity_sold at 0 for the give-back).
     */
    private function insert_imported_ticket_sync( $event_id, $et_ticket, $price, $currency, $sold, $sort_order, &$ops ) {
        global $wpdb;

        $capacity = get_post_meta( $et_ticket->ID, '_tribe_ticket_capacity', true );
        $capacity = ( '' === (string) $capacity || (int) $capacity < 0 ) ? null : (int) $capacity;

        $data = [
            'event_id'    => (int) $event_id,
            'name'        => sanitize_text_field( $et_ticket->post_title ),
            'description' => sanitize_textarea_field( $et_ticket->post_excerpt ),
            'price'       => $price,
            'currency'    => $currency,
            'status'      => 'active',
            'sort_order'  => (int) $sort_order,
        ];
        $formats = [ '%d', '%s', '%s', '%f', '%s', '%s', '%d' ];

        if ( null !== $capacity ) {
            $data['quantity_available'] = $capacity; // Full capacity (divergence).
            $formats[]                   = '%d';
        }

        // Pre-set sold count so availability math is correct immediately.
        $data['quantity_sold'] = max( 0, (int) $sold );
        $formats[]             = '%d';

        $sale_start = $this->es_et_ticket_datetime( $et_ticket->ID, '_ticket_start_date', '_ticket_start_time' );
        if ( null !== $sale_start ) {
            $data['sale_start'] = $sale_start;
            $formats[]          = '%s';
        }

        $sale_end = $this->es_et_ticket_datetime( $et_ticket->ID, '_ticket_end_date', '_ticket_end_time' );
        if ( null !== $sale_end ) {
            $data['sale_end'] = $sale_end;
            $formats[]        = '%s';
        }

        $inserted = $wpdb->insert( $this->ek_table( 'tickets' ), $data, $formats );
        if ( ! $inserted ) {
            return [ 'id' => 0 ];
        }

        $id     = (int) $wpdb->insert_id;
        $ops[]  = [ 'type' => 'delete_ticket', 'id' => $id ];
        return [ 'id' => $id ];
    }

    /**
     * Create a single EventKoi ticket from TEC's free-text cost field when
     * the cost is a positive numeric value. Mirrors native
     * TEC_Importer::maybe_create_ticket_from_cost() (audited).
     */
    private function maybe_create_ticket_from_cost_sync( $event_id, $cost_raw, $currency_symbol, $currency_code, &$ops ) {
        if ( '' === (string) $cost_raw ) {
            return false;
        }
        if ( ! function_exists( 'eventkoi_is_tickets_feature_enabled' ) || ! eventkoi_is_tickets_feature_enabled() ) {
            return false;
        }
        if ( ! preg_match( '/\d+(?:\.\d+)?/', (string) $cost_raw, $m ) ) {
            return false; // Non-numeric: "Free", "Donation", "TBD" — description keeps the text.
        }
        $price = (float) $m[0];
        if ( $price <= 0 ) {
            return false;
        }

        $checkout_currency = $this->es_get_checkout_currency();
        $has_tec_currency  = '' !== (string) $currency_code || '' !== trim( (string) $currency_symbol );
        if ( $has_tec_currency && $this->es_infer_currency_code( $currency_code, $currency_symbol ) !== $checkout_currency ) {
            return false;
        }

        global $wpdb;
        $ok = $wpdb->insert( $this->ek_table( 'tickets' ), [
            'event_id'    => (int) $event_id,
            'name'        => 'General admission',
            'description' => '',
            'price'       => $price,
            'currency'    => $checkout_currency,
            'status'      => 'active',
            'sort_order'  => 0,
        ], [ '%d', '%s', '%s', '%f', '%s', '%s', '%d' ] );

        if ( ! $ok ) {
            return false;
        }
        $ops[] = [ 'type' => 'delete_ticket', 'id' => (int) $wpdb->insert_id ];
        return (int) $wpdb->insert_id;
    }

    /**
     * Import attendee history for one event: native EventKoi RSVP rows for
     * RSVP-only events, Tickets Commerce attendees as zero-value manual
     * orders otherwise. Mirrors native TEC_Importer::import_event_attendees()
     * minus the WooCommerce branch (Run Migration owns those attendees via
     * the product bindings written by import_event_tickets_sync()).
     */
    private function import_event_attendees_sync( $tec_id, $new_post_id, $et_tickets, &$ops, &$notes ) {
        $summary = [
            'tc_attendees'          => 0,
            'rsvp_rows'             => 0,
            'rsvp_merged'           => 0,
            'rsvp_skipped'          => 0,
            'woo_attendees_skipped' => 0,
        ];

        if ( ! $et_tickets['found'] ) {
            return $summary;
        }

        if ( 'rsvp' === $et_tickets['attendance_mode'] ) {
            $rsvp = $this->import_native_rsvp_attendees_sync( $tec_id, $new_post_id, $ops );
            $summary['rsvp_rows']    = $rsvp['imported'];
            $summary['rsvp_merged']  = $rsvp['merged'];
            $summary['rsvp_skipped'] = $rsvp['skipped'];
            foreach ( $rsvp['notes'] as $n ) {
                $notes[] = $n;
            }
            return $summary;
        }

        $currency      = '' !== $et_tickets['currency'] ? $et_tickets['currency'] : $this->es_get_checkout_currency();
        $ticket_id_map = $et_tickets['ticket_id_map'];

        // Tickets Commerce attendees → zero-value manual orders.
        $unmatched = 0;
        $unusable  = 0;
        $tc_attendees = $this->es_get_et_ticket_posts( 'tec_tc_attendee', '_tec_tickets_commerce_event', $tec_id );
        foreach ( $tc_attendees as $attendee ) {
            if ( 'completed' !== (string) get_post_meta( $attendee->ID, '_tec_tickets_commerce_status', true ) ) {
                continue; // Pending/refunded/etc.; no completed sale to reflect.
            }

            $et_ticket_id = (int) get_post_meta( $attendee->ID, '_tec_tickets_commerce_ticket', true );
            $ticket_id    = $ticket_id_map[ $et_ticket_id ] ?? 0;
            if ( ! $ticket_id ) {
                ++$unmatched;
                continue;
            }

            $name = (string) get_post_meta( $attendee->ID, '_tec_tickets_commerce_full_name', true );
            if ( '' === trim( $name ) ) {
                $name = (string) get_post_meta( $attendee->ID, '_tec_tickets_commerce_purchaser_name', true );
            }
            $email = (string) get_post_meta( $attendee->ID, '_tec_tickets_commerce_email', true );
            if ( '' === trim( $email ) ) {
                $email = (string) get_post_meta( $attendee->ID, '_tec_tickets_commerce_purchaser_email', true );
            }

            if ( '' === trim( $name ) && '' === trim( $email ) ) {
                ++$unusable;
                continue;
            }

            if ( $this->create_manual_attendee_sync( $new_post_id, $ticket_id, $name, $email, $currency, $ops ) ) {
                ++$summary['tc_attendees'];
            }
        }

        if ( $unmatched > 0 ) {
            $notes[] = $unmatched . ' Tickets Commerce attendee(s) not imported: no matching imported ticket.';
        }
        if ( $unusable > 0 ) {
            $notes[] = $unusable . ' Tickets Commerce attendee(s) not imported: no recorded name or email.';
        }

        // WooCommerce attendees are deliberately left for Run Migration.
        $woo_attendees = $this->es_get_et_ticket_posts( 'tribe_wooticket', '_tribe_wooticket_event', $tec_id );
        if ( ! empty( $woo_attendees ) ) {
            $summary['woo_attendees_skipped'] = count( $woo_attendees );
            $notes[] = count( $woo_attendees ) . ' WooCommerce attendee(s) left for Run Migration (product bindings written).';
        }

        return $summary;
    }

    /**
     * Import RSVP attendees for an RSVP-only event as native eventkoi_rsvps
     * rows. Mirrors native TEC_Importer::import_native_rsvp_attendees() with
     * the Critical-Error fix: rows are deduped by email per event+instance
     * BEFORE insert (native inserts blindly and relies on a false return the
     * StellarWP wrapper can never produce — a duplicate email instead throws
     * an uncaught DatabaseQueryException that kills the whole run). Inserts
     * go through $wpdb, which cannot throw, and the table's unique key stays
     * as a second guard.
     */
    private function import_native_rsvp_attendees_sync( $tec_id, $new_post_id, &$ops ) {
        global $wpdb;

        $summary = [ 'imported' => 0, 'merged' => 0, 'skipped' => 0, 'notes' => [] ];

        $rsvp_attendees = $this->es_get_et_ticket_posts( 'tribe_rsvp_attendees', '_tribe_rsvp_event', $tec_id );
        if ( empty( $rsvp_attendees ) ) {
            return $summary;
        }

        $instance_ts = absint( get_post_meta( $new_post_id, 'start_timestamp', true ) );
        $now         = gmdate( 'Y-m-d H:i:s' );
        $no_email    = 0;
        $seen        = []; // Lowercased email => true, per event+instance.

        foreach ( $rsvp_attendees as $attendee ) {
            $email = sanitize_email( (string) get_post_meta( $attendee->ID, '_tribe_rsvp_email', true ) );

            // email is NOT NULL and part of the table's unique key.
            if ( '' === $email ) {
                ++$no_email;
                continue;
            }

            $key = strtolower( $email );
            if ( isset( $seen[ $key ] ) ) {
                ++$summary['merged'];
                continue; // Deduplicate instead of tripping event_instance_email_uniq.
            }
            $seen[ $key ] = true;

            $going         = 'no' !== (string) get_post_meta( $attendee->ID, '_tribe_rsvp_status', true );
            $status        = $going ? 'going' : 'not_going';
            $checkin_token = $going ? $this->es_generate_rsvp_checkin_token() : null;

            $inserted = $wpdb->insert( $this->ek_table( 'rsvps' ), [
                'event_id'         => (int) $new_post_id,
                'instance_ts'      => $instance_ts,
                'user_id'          => null,
                'name'             => sanitize_text_field( (string) get_post_meta( $attendee->ID, '_tribe_rsvp_full_name', true ) ),
                'email'            => $email,
                'status'           => $status,
                'guests'           => 0,
                'meta'             => null,
                'checkin_token'    => $checkin_token,
                'checked_in'       => null,
                'checked_in_count' => null,
                'checkin_status'   => 'none',
                'created'          => $now,
                'updated'          => $now,
            ] );

            if ( $inserted ) {
                ++$summary['imported'];
                $ops[] = [ 'type' => 'insert_rsvp', 'id' => (int) $wpdb->insert_id ];
            } else {
                ++$summary['merged']; // Unique key guard tripped after all.
            }
        }

        $summary['skipped'] = $no_email;
        if ( $no_email > 0 ) {
            $summary['notes'][] = $no_email . ' RSVP attendee(s) not imported: no recorded email.';
        }
        if ( $summary['merged'] > 0 ) {
            $summary['notes'][] = $summary['merged'] . ' duplicate RSVP row(s) merged (same email for this event+instance).';
        }

        return $summary;
    }

    /**
     * Create one zero-value manual attendee order via EventKoi's own path
     * (Ticket_Order_Sync::create_manual_order — public static, the same path
     * the native importer and the manual add-attendee action use), recording
     * every row it writes for the undo ledger.
     */
    private function create_manual_attendee_sync( $event_id, $ticket_id, $name, $email, $currency, &$ops ) {
        global $wpdb;

        $name = '' !== trim( (string) $name ) ? $name : 'Imported attendee';

        $result = \EventKoi\Core\Ticket_Order_Sync::create_manual_order( (int) $event_id, (int) $ticket_id, $name, (string) $email, 1, $currency, 0 );
        if ( empty( $result['rows'] ) ) {
            return false;
        }

        $base_id = (string) $result['order_id'];
        $like    = $wpdb->esc_like( $base_id ) . '%';

        $ticket_order_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$this->ek_table( 'ticket_orders' )} WHERE order_id LIKE %s",
            $like
        ) ) );
        $order_row_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->ek_table( 'orders' )} WHERE checkout_id = %s",
            $base_id
        ) );
        $note_ids = [];
        if ( $order_row_id ) {
            $note_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$this->ek_table( 'order_notes' )} WHERE order_id = %d",
                $order_row_id
            ) ) );
        }

        $ops[] = [
            'type'              => 'insert_ticket_order',
            'ticket_order_ids'  => $ticket_order_ids,
            'order_id'          => $base_id,
            'order_row_id'      => $order_row_id,
            'note_ids'          => $note_ids,
            'ticket_id'         => (int) $ticket_id,
        ];

        return true;
    }

    /**
     * Map TEC categories to EventKoi calendars, creating matching terms with
     * color backfill and a default-calendar fallback. Mirrors native
     * TEC_Importer::map_categories(); every term/meta write is audited.
     */
    private function map_categories_sync( $tec_id, $new_post_id, &$ops ) {
        $tec_cats = wp_get_post_terms( $tec_id, 'tribe_events_cat', [ 'fields' => 'all' ] );

        if ( is_wp_error( $tec_cats ) || empty( $tec_cats ) ) {
            $default_cal = function_exists( 'eventkoi_resolve_calendar_id' )
                ? eventkoi_resolve_calendar_id( (int) get_option( 'eventkoi_default_event_cal', 0 ) )
                : 0;
            if ( $default_cal ) {
                wp_set_post_terms( $new_post_id, [ (int) $default_cal ], 'event_cal' );
            }
            return;
        }

        $cal_ids = [];
        foreach ( $tec_cats as $cat ) {
            $color   = $this->es_get_tec_category_color( $cat->term_id );
            $existing = get_term_by( 'slug', $cat->slug, 'event_cal' );

            if ( $existing ) {
                $cal_ids[] = (int) $existing->term_id;

                // Backfill color only when the calendar has none yet (never overwrite).
                if ( '' !== $color && '' === (string) get_term_meta( $existing->term_id, 'color', true ) ) {
                    $old = get_term_meta( $existing->term_id, 'color', true );
                    update_term_meta( $existing->term_id, 'color', $color );
                    $ops[] = [ 'type' => 'set_termmeta', 'term_id' => (int) $existing->term_id, 'key' => 'color', 'old' => $old ];
                }
            } else {
                $new_term = wp_insert_term( $cat->name, 'event_cal', [ 'slug' => $cat->slug ] );
                if ( ! is_wp_error( $new_term ) ) {
                    $cal_ids[] = (int) $new_term['term_id'];
                    $ops[]    = [ 'type' => 'create_term', 'term_id' => (int) $new_term['term_id'], 'taxonomy' => 'event_cal' ];

                    if ( '' !== $color ) {
                        update_term_meta( $new_term['term_id'], 'color', $color );
                        $ops[] = [ 'type' => 'set_termmeta', 'term_id' => (int) $new_term['term_id'], 'key' => 'color', 'old' => '' ];
                    }
                }
            }
        }

        if ( ! empty( $cal_ids ) ) {
            wp_set_post_terms( $new_post_id, $cal_ids, 'event_cal' );
        }
    }

    /**
     * Preserve TEC's Event Website link as an "Event website" URL custom
     * field. Mirrors native TEC_Importer::import_event_website_field();
     * field/group definitions created here are audited.
     */
    private function import_event_website_field_sync( $new_post_id, $url, &$ops ) {
        global $wpdb;

        $url = esc_url_raw( (string) $url );
        if ( '' === $url || ! class_exists( '\EventKoi\Core\Fields' ) ) {
            return;
        }

        $group_id  = $this->get_or_create_import_group_sync( $ops );
        $field_key = 'event_website';
        $field     = \EventKoi\Core\Fields::get_field_by_key( $field_key );

        // A same-named field of another type belongs to the user; use a namespaced key.
        if ( $field && 'url' !== ( $field['type'] ?? '' ) ) {
            $field_key = 'tec_event_website';
            $field     = \EventKoi\Core\Fields::get_field_by_key( $field_key );
            if ( $field && 'url' !== ( $field['type'] ?? '' ) ) {
                return;
            }
        }

        if ( ! $field ) {
            $result = \EventKoi\Core\Fields::create_field( [
                'field_key' => $field_key,
                'name'      => 'Event website',
                'type'      => 'url',
                'groups'    => $group_id ? [ $group_id ] : [],
            ] );
            if ( ! $result || is_wp_error( $result ) ) {
                return;
            }
            $new_id = (int) ( is_object( $result ) ? ( $result->id ?? 0 ) : ( $result['id'] ?? 0 ) );
            if ( $new_id ) {
                $ops[] = [ 'type' => 'insert_field', 'id' => $new_id ];
            }
            $field_key = (string) ( is_object( $result ) ? ( $result->field_key ?? $field_key ) : ( $result['field_key'] ?? $field_key ) );
        }

        update_post_meta( $new_post_id, 'event_field_' . $field_key, $url );
        $this->append_imported_custom_field_keys_sync( $new_post_id, [ $field_key ], $group_id );
    }

    /**
     * Find or create the "Imported Fields" custom-field group. Mirrors native
     * TEC_Importer::get_or_create_import_group() (audited).
     */
    private function get_or_create_import_group_sync( &$ops ) {
        static $cached_id = null;
        if ( null !== $cached_id ) {
            return $cached_id;
        }
        if ( ! class_exists( '\EventKoi\Core\Field_Groups' ) ) {
            $cached_id = 0;
            return 0;
        }

        $group = \EventKoi\Core\Field_Groups::get_field_group_by_key( 'tec-import' );
        if ( $group ) {
            $cached_id = absint( is_object( $group ) ? ( $group->id ?? 0 ) : ( $group['id'] ?? 0 ) );
            return $cached_id;
        }

        $group = \EventKoi\Core\Field_Groups::create_group( [
            'group_key' => 'tec-import',
            'name'      => 'Imported Fields',
        ] );

        $cached_id = ( is_array( $group ) || is_object( $group ) ) && ! is_wp_error( $group )
            ? absint( is_object( $group ) ? ( $group->id ?? 0 ) : ( $group['id'] ?? 0 ) )
            : 0;

        if ( $cached_id ) {
            $ops[] = [ 'type' => 'insert_field_group', 'id' => $cached_id ];
        }

        return $cached_id;
    }

    /**
     * Track imported custom-field keys on an event. Mirrors native
     * TEC_Importer::append_imported_custom_field_keys().
     */
    private function append_imported_custom_field_keys_sync( $event_id, array $field_keys, $group_id = 0 ) {
        $existing_keys = get_post_meta( $event_id, 'eventkoi_custom_fields_keys', true );
        $existing_keys = is_array( $existing_keys ) ? $existing_keys : [];
        $field_keys    = array_filter( array_map( 'sanitize_key', $field_keys ) );
        update_post_meta( $event_id, 'eventkoi_custom_fields_keys', array_values( array_unique( array_merge( $existing_keys, $field_keys ) ) ) );

        if ( ! $group_id ) {
            return;
        }

        $layout = get_post_meta( $event_id, 'custom_fields_layout', true );
        $layout = is_array( $layout ) ? $layout : [];
        foreach ( $layout as $item ) {
            if ( is_array( $item ) && 'group' === ( $item['type'] ?? '' ) && absint( $item['id'] ?? 0 ) === absint( $group_id ) ) {
                return;
            }
        }

        $layout[] = [ 'type' => 'group', 'id' => absint( $group_id ) ];
        update_post_meta( $event_id, 'custom_fields_layout', $layout );
    }

    /**
     * Chunked, resumable Event Sync runner. Each call re-derives the pending
     * queue (TEC events without an EventKoi _tec_import_source_id marker),
     * imports up to $chunk events, and guards the loop with an 18-second
     * budget so one request can never approach max_execution_time — the
     * native importer runs everything in a single unchunked request and dies
     * on timeout long before a few hundred events finish. Every event runs
     * inside its own try/catch: a failure rolls just that event back through
     * the audit ledger, becomes a review item, and the run continues.
     *
     * Failed events never receive a marker, so they would otherwise reappear
     * in every subsequent chunk's pending queue and the run could never
     * finish (the last chunks would re-fail the same events forever). The
     * run state (ekti_event_sync_state) therefore remembers this run's
     * failures and excludes them from the queue until the next run starts
     * (the AJAX handler clears it on reset) or the state is undone — fixing
     * an event in TEC and running the sync again retries exactly the failed
     * set.
     *
     * @param int   $chunk         Events per request (default 25).
     * @param bool  $import_images Whether to reuse the TEC featured image.
     * @param array $ids           Optional explicit TEC event IDs to import
     *                             (native run_import parity; used for
     *                             targeted/QA runs; bypasses the failed-list
     *                             so a targeted retry always attempts).
     */
    private function run_event_sync( $chunk = 25, $import_images = true, $ids = [] ) {
        $chunk         = max( 1, min( 100, (int) $chunk ) );
        $import_images = (bool) $import_images;
        $targeted      = ! empty( $ids );

        $state  = get_option( 'ekti_event_sync_state', [] );
        $failed = $targeted || ! is_array( $state ) || ! is_array( $state['failed'] ?? null )
            ? []
            : $state['failed'];

        $pending = $this->get_tec_event_import_ids();
        if ( ! empty( $failed ) ) {
            $failed_ids = array_map( 'absint', array_keys( $failed ) );
            $pending    = array_values( array_diff( $pending, $failed_ids ) );
        }
        if ( $targeted ) {
            $batch = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
        } else {
            $batch = array_slice( $pending, 0, $chunk );
        }

        $results = [];
        $tally   = [
            'imported'     => 0,
            'skipped'      => 0,
            'errors'       => 0,
            'tickets'      => 0,
            'cost_tickets' => 0,
            'tc_attendees' => 0,
            'rsvp_rows'    => 0,
            'rsvp_merged'  => 0,
        ];

        $started_at = microtime( true );
        foreach ( $batch as $tec_id ) {
            // Stop before starting an event we might not finish in budget.
            if ( microtime( true ) - $started_at > 18 ) {
                break;
            }

            $event_ops = [];
            try {
                $result = $this->import_one_event( $tec_id, $import_images, $event_ops );
            } catch ( Throwable $e ) {
                $result = new WP_Error( 'exception', $e->getMessage() );
            }

            if ( is_wp_error( $result ) ) {
                // Roll this event's partial writes back to its pre-import state.
                if ( $event_ops ) {
                    $this->replay_audit_ops( $event_ops );
                }

                if ( 'already_imported' === $result->get_error_code() ) {
                    ++$tally['skipped'];
                    continue; // Marker appeared after the batch was derived.
                }

                ++$tally['errors'];
                $message   = $result->get_error_message();
                $failed[ $tec_id ] = $message;
                $results[] = [
                    'tec_id' => $tec_id,
                    'title'  => (string) get_the_title( $tec_id ),
                    'status' => 'error',
                    'error'  => $message,
                ];
                $this->log( "Event Sync: TEC event {$tec_id} failed and was rolled back: {$message}", 'ERROR' );
                continue;
            }

            $this->audit_push_event_sync( $event_ops );

            ++$tally['imported'];
            $tally['tickets']      += $result['tickets'];
            $tally['cost_tickets'] += $result['cost_ticket'] ? 1 : 0;
            $tally['tc_attendees'] += $result['tc_attendees'];
            $tally['rsvp_rows']    += $result['rsvp_rows'];
            $tally['rsvp_merged']  += $result['rsvp_merged'];

            $results[] = [
                'tec_id'          => $result['tec_id'],
                'event_id'        => $result['event_id'],
                'title'           => $result['title'],
                'status'          => $result['status'],
                'attendance_mode' => $result['attendance_mode'],
                'tickets'         => $result['tickets'],
                'tc_attendees'    => $result['tc_attendees'],
                'rsvp_rows'       => $result['rsvp_rows'],
                'rsvp_merged'     => $result['rsvp_merged'],
            ];
        }

        // Re-derive the queue after the batch — the resumability source of truth.
        $remaining_ids = $this->get_tec_event_import_ids();
        if ( ! $targeted && ! empty( $failed ) ) {
            $remaining_ids = array_values( array_diff( $remaining_ids, array_map( 'absint', array_keys( $failed ) ) ) );
        }
        $processed = $tally['imported'] + $tally['skipped'] + $tally['errors'];

        // Persist this run's failure memory (full runs only).
        if ( ! $targeted ) {
            update_option( 'ekti_event_sync_state', [ 'failed' => $failed ], false );
        }

        $this->log( sprintf(
            'Event Sync: processed %d (imported %d, errors %d, skipped %d) — %d remaining.',
            $processed,
            $tally['imported'],
            $tally['errors'],
            $tally['skipped'],
            count( $remaining_ids )
        ) );

        return [
            'processed'    => $processed,
            'tally'        => $tally,
            'remaining'    => count( $remaining_ids ),
            'total'        => count( $pending ),
            'failed_total' => $targeted ? 0 : count( $failed ),
            'done'         => ( $targeted && count( $batch ) === $processed ) || ( ! $targeted && 0 === count( $remaining_ids ) ),
            'results'      => $results,
        ];
    }

    /**
     * Adopt pre-existing native imports: flag the TEC WooCommerce attendee
     * posts behind natively-imported events with _eventkoi_imported_from_tec
     * so Run Migration will not double-import attendees EventKoi's own
     * importer already brought over. Audited into the event-sync ledger.
     */
    private function adopt_native_imports() {
        global $wpdb;

        $scan = $this->scan_native_adoption();
        if ( empty( $scan['events'] ) ) {
            return [ 'error' => 'No native imports to adopt: no natively-imported events with unflagged WooCommerce attendees were found.' ];
        }

        $ops     = [];
        $flagged = 0;
        foreach ( $scan['events'] as $ev ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT a.ID FROM {$wpdb->posts} a
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = a.ID
                  AND pm.meta_key = '_tribe_wooticket_event' AND pm.meta_value = %s
                 LEFT JOIN {$wpdb->postmeta} f ON f.post_id = a.ID
                  AND f.meta_key = '_eventkoi_imported_from_tec'
                 WHERE a.post_type = 'tribe_wooticket'
                   AND a.post_status = 'publish'
                   AND (f.meta_id IS NULL OR f.meta_value = '' OR f.meta_value = '0')",
                (string) $ev['tec_id']
            ), ARRAY_A );

            foreach ( (array) $rows as $r ) {
                $post_id = (int) $r['ID'];
                $old     = get_post_meta( $post_id, '_eventkoi_imported_from_tec', true );
                update_post_meta( $post_id, '_eventkoi_imported_from_tec', '1' );
                $ops[]   = [ 'type' => 'update_postmeta', 'post' => $post_id, 'key' => '_eventkoi_imported_from_tec', 'old' => $old ];
                ++$flagged;
            }
        }

        $this->audit_push_event_sync( $ops );
        $this->log( sprintf( 'Event Sync: adopted %d natively-imported attendee(s) across %d event(s).', $flagged, count( $scan['events'] ) ) );

        return [ 'flagged' => $flagged, 'events' => count( $scan['events'] ) ];
    }

    /**
     * Undo the last Event Sync run (and/or native-import adoption) by
     * replaying the event-sync ledger in reverse. Events this sync created
     * are hard-deleted together with their residual EventKoi rows; every
     * other write is restored to its pre-run value.
     */
    private function undo_event_sync() {
        $audit = get_option( 'ekti_event_sync_audit', [] );
        if ( empty( $audit ) || ! is_array( $audit ) ) {
            return [ 'error' => 'Nothing to undo.' ];
        }
        $counts = $this->replay_audit_ops( $audit );
        delete_option( 'ekti_event_sync_audit' );
        delete_option( 'ekti_event_sync_state' ); // Failure memory is stale post-undo.
        $this->log( 'Event sync undo complete. ' . wp_json_encode( $counts ) );
        return [ 'undone' => $counts ];
    }

    /**
     * Append audit ops to the event-sync ledger (separate from
     * ekti_sync_audit so undoing one step never replays another step's
     * verified writes).
     */
    private function audit_push_event_sync( $ops ) {
        if ( empty( $ops ) ) {
            return;
        }
        $audit = get_option( 'ekti_event_sync_audit', [] );
        if ( ! is_array( $audit ) ) {
            $audit = [];
        }
        update_option( 'ekti_event_sync_audit', array_merge( $audit, $ops ), false );
    }

    /* ------------------------------------------------------------------
     * AJAX HANDLERS
     * ------------------------------------------------------------------ */

    private function check_ajax() {
        check_ajax_referer( 'ekti_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
    }

    public function ajax_get_stats() {
        $this->check_ajax();

        $tec_attendee_count = $this->count_tec_attendees();
        $tec_event_ids      = $this->get_tec_event_ids_with_attendees();
        $ek_events          = $this->get_eventkoi_events();
        $mapping            = $this->get_saved_mapping();
        $state              = $this->get_migration_state();

        // Count matched/unmatched.
        $mapped_count = 0;
        foreach ( $tec_event_ids as $tid ) {
            if ( isset( $mapping[ $tid ] ) ) {
                $mapped_count++;
            }
        }

        // Count attendees that would be imported (mapped events only).
        global $wpdb;
        $mapped_attendees = 0;
        foreach ( $mapping as $tec_id => $ek_id ) {
            $cnt = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tribe_wooticket_event' AND meta_value = %s",
                $tec_id
            ) );
            $mapped_attendees += $cnt;
        }

        // Count already imported.
        $already_imported = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_eventkoi_imported_from_tec'"
        );

        // Count distinct WC orders that would be linked.
        $wc_order_count = 0;
        if ( ! empty( $mapping ) ) {
            $mapped_tec_ids = array_keys( $mapping );
            $placeholders   = implode( ',', array_fill( 0, count( $mapped_tec_ids ), '%s' ) );
            $wc_order_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->postmeta}
                 WHERE meta_key = '_tribe_wooticket_order'
                   AND meta_value != ''
                   AND post_id IN (
                       SELECT post_id FROM {$wpdb->postmeta}
                       WHERE meta_key = '_tribe_wooticket_event'
                         AND meta_value IN ({$placeholders})
                   )",
                ...$mapped_tec_ids
            ) );
        }

        // WooCommerce availability.
        $wc_available = $this->wc_is_available();

        wp_send_json_success( [
            'tec_attendee_count'  => $tec_attendee_count,
            'tec_event_count'     => count( $tec_event_ids ),
            'ek_event_count'      => count( $ek_events ),
            'mapped_event_count'  => $mapped_count,
            'unmapped_event_count'=> count( $tec_event_ids ) - $mapped_count,
            'mapped_attendees'    => $mapped_attendees,
            'already_imported'    => $already_imported,
            'wc_order_count'      => $wc_order_count,
            'wc_available'        => $wc_available,
            'migration_state'     => $state,
        ] );
    }

    public function ajax_get_event_mapping() {
        $this->check_ajax();

        $tec_event_ids = $this->get_tec_event_ids_with_attendees();
        $ek_events     = $this->get_eventkoi_events();
        $mapping       = $this->get_saved_mapping();
        $source_map    = $this->get_tec_import_source_mapping();
        global $wpdb;

        $rows = [];
        foreach ( $tec_event_ids as $tec_id ) {
            $tec_post = get_post( $tec_id );
            $is_orphan = ! $tec_post;
            $tec_title = $tec_post
                ? $tec_post->post_title
                : '(Deleted — orphan attendees)';
            $attendee_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tribe_wooticket_event' AND meta_value = %s",
                $tec_id
            ) );

            $match_source = 'none';
            if ( isset( $mapping[ $tec_id ] ) ) {
                if ( isset( $source_map[ $tec_id ] ) && (int) $source_map[ $tec_id ] === (int) $mapping[ $tec_id ] ) {
                    $match_source = 'source';
                } else {
                    $match_source = 'manual';
                }
            }

            $rows[] = [
                'tec_id'         => $tec_id,
                'tec_title'      => $tec_title,
                'attendee_count' => $attendee_count,
                'ek_event_id'    => isset( $mapping[ $tec_id ] ) ? $mapping[ $tec_id ] : null,
                'match_source'   => $match_source,
                'is_orphan'      => $is_orphan,
            ];
        }

        wp_send_json_success( [
            'rows'       => $rows,
            'ek_events'  => $ek_events,
        ] );
    }

    public function ajax_save_mapping() {
        $this->check_ajax();
        $mapping = isset( $_POST['mapping'] ) ? json_decode( stripslashes( $_POST['mapping'] ), true ) : [];
        if ( ! is_array( $mapping ) ) {
            wp_send_json_error( 'Invalid mapping data' );
        }
        // Sanitize.
        $clean = [];
        foreach ( $mapping as $tec_id => $ek_id ) {
            if ( $ek_id === '' || $ek_id === null || $ek_id === '0' ) {
                continue;
            }
            $clean[ intval( $tec_id ) ] = intval( $ek_id );
        }
        $this->save_mapping( $clean );
        $this->log( 'Event mapping saved. ' . count( $clean ) . ' events mapped.' );
        wp_send_json_success( [ 'saved' => count( $clean ) ] );
    }

    public function ajax_auto_match() {
        $this->check_ajax();
        $result = $this->auto_match_events();
        $this->log( "Auto-match complete: {$result['matched']} matched, {$result['unmatched']} unmatched, {$result['orphaned']} orphaned (deleted TEC events skipped)." );
        wp_send_json_success( $result );
    }

    public function ajax_run_batch() {
        $this->check_ajax();

        $dry_run = ! empty( $_POST['dry_run'] ) && $_POST['dry_run'] === 'true';
        $reset   = ! empty( $_POST['reset'] ) && $_POST['reset'] === 'true';

        if ( $reset ) {
            $this->reset_migration_state( $dry_run );
            $this->log( 'Migration ' . ( $dry_run ? 'dry run' : 'run' ) . ' started.' );
        }

        $result = $this->process_batch( $dry_run );

        wp_send_json_success( $result );
    }

    public function ajax_rollback() {
        $this->check_ajax();
        $result = $this->rollback_import();
        wp_send_json_success( $result );
    }

    public function ajax_scan_cleanup() {
        $this->check_ajax();
        wp_send_json_success( $this->scan_cleanup() );
    }

    public function ajax_run_ticket_dedupe() {
        $this->check_ajax();
        wp_send_json_success( $this->run_ticket_dedupe() );
    }

    public function ajax_run_event_merge() {
        $this->check_ajax();
        wp_send_json_success( $this->run_event_merge() );
    }

    public function ajax_undo_cleanup() {
        $this->check_ajax();
        $result = $this->undo_cleanup();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_scan_ticket_details() {
        $this->check_ajax();
        wp_send_json_success( $this->scan_ticket_details() );
    }

    public function ajax_run_ticket_details_sync() {
        $this->check_ajax();
        wp_send_json_success( $this->run_ticket_details_sync() );
    }

    public function ajax_undo_sync() {
        $this->check_ajax();
        $result = $this->undo_sync();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_scan_calendar_sync() {
        $this->check_ajax();
        wp_send_json_success( $this->scan_calendar_sync() );
    }

    public function ajax_save_location_mapping() {
        $this->check_ajax();
        $mapping = isset( $_POST['mapping'] ) ? json_decode( stripslashes( $_POST['mapping'] ), true ) : [];
        $clean   = $this->sanitize_location_mapping( $mapping );
        $this->save_location_mapping( $clean );
        $this->log( 'Location mapping saved. ' . count( $clean ) . ' locations.' );
        wp_send_json_success( [ 'saved' => count( $clean ) ] );
    }

    public function ajax_run_calendar_sync() {
        $this->check_ajax();
        // A mapping payload in the request wins; otherwise use the saved one.
        if ( isset( $_POST['mapping'] ) ) {
            $clean = $this->sanitize_location_mapping( json_decode( stripslashes( $_POST['mapping'] ), true ) );
            $this->save_location_mapping( $clean );
        } else {
            $clean = $this->get_saved_location_mapping();
        }
        if ( empty( $clean ) ) {
            wp_send_json_error( 'No location mapping saved. Scan locations and save a mapping first.' );
        }
        $result = $this->run_calendar_sync( $clean );
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_undo_calendar_sync() {
        $this->check_ajax();
        $result = $this->undo_calendar_sync();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_scan_taxonomy_sync() {
        $this->check_ajax();
        $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
        $result = $this->scan_taxonomy_sync( $type );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public function ajax_save_taxonomy_mapping() {
        $this->check_ajax();
        $type    = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
        $mapping = isset( $_POST['mapping'] ) ? json_decode( stripslashes( $_POST['mapping'] ), true ) : [];
        $clean   = $this->sanitize_taxonomy_mapping( $mapping );
        $this->save_taxonomy_mapping( $type, $clean );
        $this->log( 'Taxonomy mapping saved. ' . count( $clean ) . " {$type} sources mapped." );
        wp_send_json_success( [ 'saved' => count( $clean ) ] );
    }

    public function ajax_run_taxonomy_sync() {
        $this->check_ajax();
        $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
        if ( isset( $_POST['mapping'] ) ) {
            $clean = $this->sanitize_taxonomy_mapping( json_decode( stripslashes( $_POST['mapping'] ), true ) );
            $this->save_taxonomy_mapping( $type, $clean );
        } else {
            $clean = $this->get_saved_taxonomy_mapping( $type );
        }
        if ( empty( $clean ) ) {
            wp_send_json_error( 'No taxonomy mapping saved. Scan and save a mapping first.' );
        }
        $overwrite   = 'locations' === $type
            && isset( $_POST['overwrite'] )
            && 'true' === $_POST['overwrite'];
        $sync_fields = 'locations' === $type
            && isset( $_POST['sync_fields'] )
            && 'true' === $_POST['sync_fields'];
        $result = $this->run_taxonomy_sync( $type, $clean, 100, $overwrite, $sync_fields );
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_undo_taxonomy_sync() {
        $this->check_ajax();
        $type   = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
        $result = $this->undo_taxonomy_sync( $type );
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_remove_organizer_fields() {
        $this->check_ajax();
        $preview = empty( $_POST['confirm'] ) || 'true' !== $_POST['confirm'];
        if ( $preview ) {
            wp_send_json_success( $this->scan_organizer_field_cleanup() );
        }
        if ( ! isset( $_POST['confirm'] ) || 'true' !== $_POST['confirm'] ) {
            wp_send_json_error( 'Confirmation required.' );
        }
        $result = $this->remove_organizer_custom_field_group();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public function ajax_scan_event_sync() {
        $this->check_ajax();
        wp_send_json_success( $this->scan_event_sync() );
    }

    public function ajax_run_event_sync() {
        $this->check_ajax();
        $chunk         = isset( $_POST['chunk'] ) ? max( 1, absint( $_POST['chunk'] ) ) : 25;
        $import_images = ! isset( $_POST['import_images'] ) || ! in_array( $_POST['import_images'], [ '0', 'false', '' ], true );
        $ids           = isset( $_POST['ids'] ) && '' !== trim( (string) $_POST['ids'] )
            ? array_filter( array_map( 'absint', explode( ',', (string) $_POST['ids'] ) ) )
            : [];
        // First chunk of a fresh run: forget the previous run's failure memory
        // so fixed events retry (same pattern as ajax_run_batch's reset flag).
        if ( ! empty( $_POST['reset'] ) && 'true' === $_POST['reset'] ) {
            delete_option( 'ekti_event_sync_state' );
        }
        wp_send_json_success( $this->run_event_sync( $chunk, $import_images, $ids ) );
    }

    public function ajax_adopt_native_imports() {
        $this->check_ajax();
        $result = $this->adopt_native_imports();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_undo_event_sync() {
        $this->check_ajax();
        $result = $this->undo_event_sync();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_get_log() {
        $this->check_ajax();
        $lines = 50;
        if ( isset( $_POST['lines'] ) ) {
            $lines = intval( $_POST['lines'] );
        }

        if ( ! file_exists( EKTI_LOG_FILE ) ) {
            wp_send_json_success( [ 'log' => '' ] );
        }

        $content = file_get_contents( EKTI_LOG_FILE );
        $all_lines = explode( "\n", trim( $content ) );
        $tail = array_slice( $all_lines, -$lines );

        wp_send_json_success( [ 'log' => implode( "\n", $tail ) ] );
    }

    public function ajax_clear_log() {
        $this->check_ajax();
        if ( file_exists( EKTI_LOG_FILE ) ) {
            unlink( EKTI_LOG_FILE );
        }
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
     * ADMIN PAGE RENDER
     * ------------------------------------------------------------------ */

    public function render_admin_page() {
        ?>
        <div class="wrap" id="ekti-app">
            <h1>EventKoi Ticket Importer</h1>
            <p class="description">Migrate tickets &amp; attendees from The Events Calendar to EventKoi.</p>

            <!-- Stats Panel -->
            <div class="ekti-panel" id="ekti-stats-panel">
                <h2>Overview</h2>
                <div class="ekti-stats-grid" id="ekti-stats-grid">
                    <div class="ekti-stat"><span class="ekti-stat-value" id="stat-tec-events">—</span><span class="ekti-stat-label">TEC Events with Attendees</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="stat-tec-attendees">—</span><span class="ekti-stat-label">Total Attendees</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="stat-ek-events">—</span><span class="ekti-stat-label">EventKoi Events</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="stat-mapped">—</span><span class="ekti-stat-label">Mapped Events</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="stat-unmapped">—</span><span class="ekti-stat-label">Unmapped Events</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="stat-mapped-attendees">—</span><span class="ekti-stat-label">Attendees to Import</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="stat-wc-orders">—</span><span class="ekti-stat-label">WC Orders to Link</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="stat-already-imported">—</span><span class="ekti-stat-label">Already Imported</span></div>
                </div>
                <div id="ekti-wc-warning" style="display:none;" class="notice notice-warning"><p>WooCommerce is not active. Order linking requires WooCommerce to be installed and active.</p></div>
                <button class="button" id="ekti-refresh-stats">Refresh Stats</button>
            </div>

            <!-- Event Sync Panel -->
            <div class="ekti-panel" id="ekti-event-sync-panel">
                <h2>Event Sync (TEC &rarr; EventKoi)</h2>
                <p>Import TEC events into EventKoi &mdash; the native importer's pipeline with the duplicate-RSVP Critical Error fixed, per-event error isolation, and a chunked resumable runner. Tickets Commerce and RSVP attendees import here; WooCommerce attendees stay with Run Migration via product bindings.</p>
                <div class="ekti-actions">
                    <button class="button button-primary" id="ekti-event-sync-scan">Scan Events</button>
                    <button class="button" id="ekti-event-sync-run" disabled>Run Event Sync</button>
                    <button class="button" id="ekti-event-sync-adopt" disabled>Adopt Native Imports</button>
                    <button class="button button-secondary" id="ekti-event-sync-undo" style="color:#a00;">Undo Last Event Sync</button>
                </div>
                <div class="ekti-migration-options">
                    <label>
                        <input type="checkbox" id="ekti-event-sync-images" checked />
                        <strong>Import images</strong> &mdash; reuse TEC featured images on imported events.
                    </label>
                    <label style="margin-left:1.5em;">
                        TEC IDs (optional)
                        <input type="text" id="ekti-event-sync-ids" placeholder="e.g. 52802, 52766" size="18" />
                    </label>
                </div>
                <div id="ekti-event-sync-status" class="ekti-status"></div>
                <div id="ekti-event-sync-progress-wrap" style="display:none;">
                    <div class="ekti-progress-bar">
                        <div class="ekti-progress-fill" id="ekti-event-sync-progress-fill" style="width:0%"></div>
                    </div>
                    <div class="ekti-progress-text" id="ekti-event-sync-progress-text">0%</div>
                </div>
                <div class="ekti-stats-grid" id="ekti-event-sync-stats" style="display:none;">
                    <div class="ekti-stat"><span class="ekti-stat-value" id="ekti-es-total">&mdash;</span><span class="ekti-stat-label">TEC Events</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="ekti-es-imported">&mdash;</span><span class="ekti-stat-label">Already Imported</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="ekti-es-unimported">&mdash;</span><span class="ekti-stat-label">To Import</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="ekti-es-tickets">&mdash;</span><span class="ekti-stat-label">Ticket Types</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="ekti-es-tc">&mdash;</span><span class="ekti-stat-label">TC Attendees</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="ekti-es-rsvp">&mdash;</span><span class="ekti-stat-label">RSVP Rows</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="ekti-es-woo">&mdash;</span><span class="ekti-stat-label">Woo Attendees (deferred)</span></div>
                    <div class="ekti-stat"><span class="ekti-stat-value" id="ekti-es-images">&mdash;</span><span class="ekti-stat-label">With Images</span></div>
                </div>
                <div id="ekti-es-review-wrap" style="display:none;">
                    <h3>Review Items</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>TEC Event ID</th><th>Title</th><th>Reason</th><th>Detail</th></tr>
                        </thead>
                        <tbody id="ekti-es-review-tbody"></tbody>
                    </table>
                </div>
                <div id="ekti-es-adoption-wrap" style="display:none;">
                    <h3>Native Import Adoption</h3>
                    <p class="description" id="ekti-es-adoption-text"></p>
                </div>
            </div>

            <!-- Pre-Import Cleanup Panel -->
            <div class="ekti-panel" id="ekti-cleanup-panel">
                <h2>Pre-Import Cleanup</h2>
                <p>Detect and remove stale duplicate ticket types and duplicate EventKoi events (same TEC source) before running the ticket import. All changes are audited and undoable.</p>
                <div class="ekti-actions">
                    <button class="button button-primary" id="ekti-cleanup-scan">Scan for Cleanup</button>
                    <button class="button" id="ekti-cleanup-dedupe" disabled>Run Ticket Dedupe</button>
                    <button class="button" id="ekti-cleanup-merge" disabled>Run Event Merge</button>
                    <button class="button button-secondary" id="ekti-cleanup-undo" style="color:#a00;">Undo Last Cleanup</button>
                </div>
                <div id="ekti-cleanup-status" class="ekti-status"></div>
                <div id="ekti-cleanup-progress-wrap" style="display:none;">
                    <div class="ekti-progress-bar">
                        <div class="ekti-progress-fill" id="ekti-cleanup-progress-fill" style="width:0%"></div>
                    </div>
                    <div class="ekti-progress-text" id="ekti-cleanup-progress-text">0%</div>
                </div>
                <div id="ekti-stale-table-wrap" style="display:none;">
                    <h3>Stale Duplicate Tickets</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>Event ID</th><th>Title</th><th>Keep</th><th>Delete</th><th>TEC Price</th><th>Reason</th></tr>
                        </thead>
                        <tbody id="ekti-stale-tbody"></tbody>
                    </table>
                </div>
                <div id="ekti-review-table-wrap" style="display:none;">
                    <h3>Manual Review Required</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>Event ID</th><th>Title</th><th>Reason</th></tr>
                        </thead>
                        <tbody id="ekti-review-tbody"></tbody>
                    </table>
                </div>
                <div id="ekti-dups-table-wrap" style="display:none;">
                    <h3>Duplicate Event Groups</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>TEC Source</th><th>Role</th><th>Event ID</th><th>Status</th><th>Tickets</th><th>Attendees</th><th>Calendars</th></tr>
                        </thead>
                        <tbody id="ekti-dups-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Ticket Details Sync Panel -->
            <div class="ekti-panel" id="ekti-sync-panel">
                <h2>Ticket Details Sync</h2>
                <p>Backfills ticket sale windows (on sale at event publish time, off sale 6 AM the day before the event) and quantity available (TEC capacity), and reconciles multi-product TEC events into distinct ticket types. All changes are audited and undoable.</p>
                <div class="ekti-actions">
                    <button class="button button-primary" id="ekti-sync-scan">Scan Ticket Details</button>
                    <button class="button" id="ekti-sync-run" disabled>Run Sync</button>
                    <button class="button button-secondary" id="ekti-sync-undo" style="color:#a00;">Undo Last Sync</button>
                </div>
                <div id="ekti-sync-status" class="ekti-status"></div>
                <div id="ekti-sync-progress-wrap" style="display:none;">
                    <div class="ekti-progress-bar">
                        <div class="ekti-progress-fill" id="ekti-sync-progress-fill" style="width:0%"></div>
                    </div>
                    <div class="ekti-progress-text" id="ekti-sync-progress-text">0%</div>
                </div>
                <div id="ekti-sync-types-wrap" style="display:none;">
                    <h3>Ticket Types to Create / Convert</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>Event ID</th><th>Title</th><th>Action</th><th>Name</th><th>Price</th><th>Capacity</th><th>Sale Window (site time)</th></tr>
                        </thead>
                        <tbody id="ekti-sync-types-tbody"></tbody>
                    </table>
                </div>
                <div id="ekti-sync-updates-wrap" style="display:none;">
                    <h3>Field Updates</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>Event ID</th><th>Title</th><th>Ticket</th><th>Changes</th></tr>
                        </thead>
                        <tbody id="ekti-sync-updates-tbody"></tbody>
                    </table>
                </div>
                <div id="ekti-sync-review-wrap" style="display:none;">
                    <h3>Manual Review Required</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>Event ID</th><th>Title</th><th>Reason</th></tr>
                        </thead>
                        <tbody id="ekti-sync-review-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Calendar Sync Panel -->
            <div class="ekti-panel" id="ekti-calsync-panel">
                <h2>Legacy: Calendar Sync (Locations)</h2>
                <p><strong>Deprecated:</strong> EventKoi now has a dedicated Locations taxonomy. Use the new <strong>Taxonomy Sync</strong> panel below for Locations. This legacy panel remains available only to undo previous calendar assignments.</p>
                <div class="ekti-actions">
                    <button class="button button-primary" id="ekti-calsync-scan">Scan Locations</button>
                    <button class="button" id="ekti-calsync-save" disabled>Save Mapping</button>
                    <button class="button" id="ekti-calsync-run" disabled>Run Calendar Sync</button>
                    <button class="button button-secondary" id="ekti-calsync-undo" style="color:#a00;">Undo Last Calendar Sync</button>
                </div>
                <div id="ekti-calsync-status" class="ekti-status"></div>
                <div id="ekti-calsync-progress-wrap" style="display:none;">
                    <div class="ekti-progress-bar">
                        <div class="ekti-progress-fill" id="ekti-calsync-progress-fill" style="width:0%"></div>
                    </div>
                    <div class="ekti-progress-text" id="ekti-calsync-progress-text">0%</div>
                </div>
                <div id="ekti-calsync-table-wrap" style="display:none;">
                    <h3>Location &rarr; Calendar Mapping</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>TEC Location</th><th>Source</th><th>Mapped Events</th><th>Auto-Match</th><th>EventKoi Calendar</th><th>Pending</th></tr>
                        </thead>
                        <tbody id="ekti-calsync-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Taxonomy Sync Panel -->
            <div class="ekti-panel" id="ekti-taxonomy-panel">
                <h2>Taxonomy Sync</h2>
                <p>Map The Events Calendar locations and organizers to EventKoi's new <strong>Locations</strong> and <strong>Instructors</strong> taxonomies. Existing term assignments are never removed. All changes are audited and undoable.</p>

                <!-- Locations -->
                <div class="ekti-tax-section" id="ekti-tax-locations-section" style="margin-top:1.5em;">
                    <h3>Locations</h3>
                    <p class="description">Map TEC venues to EventKoi Locations taxonomy terms. With EventKoi's Saved Locations active, each TEC venue's address is copied onto the mapped term, and events resolve their address from the assigned term.</p>
                    <div class="ekti-actions">
                        <button class="button button-primary" id="ekti-tax-locations-scan">Scan Locations</button>
                        <button class="button" id="ekti-tax-locations-save" disabled>Save Mapping</button>
                        <button class="button" id="ekti-tax-locations-run" disabled>Run Location Sync</button>
                        <button class="button button-secondary" id="ekti-tax-locations-undo" style="color:#a00;">Undo Last Location Sync</button>
                        <label style="margin-left:6px;"><input type="checkbox" id="ekti-tax-locations-overwrite" /> <strong>Overwrite</strong> non-empty address fields with TEC venue data</label>
                    </div>
                    <div id="ekti-tax-locations-status" class="ekti-status"></div>
                    <div id="ekti-tax-locations-designation" style="display:none;"></div>
                    <div id="ekti-tax-locations-progress-wrap" style="display:none;">
                        <div class="ekti-progress-bar">
                            <div class="ekti-progress-fill" id="ekti-tax-locations-progress-fill" style="width:0%"></div>
                        </div>
                        <div class="ekti-progress-text" id="ekti-tax-locations-progress-text">0%</div>
                    </div>
                    <div id="ekti-tax-locations-table-wrap" style="display:none;">
                        <h4>TEC Location &rarr; EventKoi Location</h4>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr><th>TEC Source</th><th>Source</th><th>Mapped Events</th><th>Auto-Match</th><th>EventKoi Location</th><th>Address Data</th><th>Pending</th></tr>
                            </thead>
                            <tbody id="ekti-tax-locations-tbody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Instructors -->
                <div class="ekti-tax-section" id="ekti-tax-instructors-section" style="margin-top:2.5em;">
                    <h3>Instructors</h3>
                    <p class="description">Map TEC organizers to EventKoi Instructors taxonomy terms.</p>
                    <div class="ekti-actions">
                        <button class="button button-primary" id="ekti-tax-instructors-scan">Scan Instructors</button>
                        <button class="button" id="ekti-tax-instructors-save" disabled>Save Mapping</button>
                        <button class="button" id="ekti-tax-instructors-run" disabled>Run Instructor Sync</button>
                        <button class="button button-secondary" id="ekti-tax-instructors-undo" style="color:#a00;">Undo Last Instructor Sync</button>
                    </div>
                    <div id="ekti-tax-instructors-status" class="ekti-status"></div>
                    <div id="ekti-tax-instructors-progress-wrap" style="display:none;">
                        <div class="ekti-progress-bar">
                            <div class="ekti-progress-fill" id="ekti-tax-instructors-progress-fill" style="width:0%"></div>
                        </div>
                        <div class="ekti-progress-text" id="ekti-tax-instructors-progress-text">0%</div>
                    </div>
                    <div id="ekti-tax-instructors-table-wrap" style="display:none;">
                        <h4>TEC Organizer &rarr; EventKoi Instructor</h4>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr><th>TEC Source</th><th>Source</th><th>Mapped Events</th><th>Auto-Match</th><th>EventKoi Instructor</th><th>Pending</th></tr>
                            </thead>
                            <tbody id="ekti-tax-instructors-tbody"></tbody>
                        </table>
                    </div>
                    <div id="ekti-tax-instructors-cleanup" style="margin-top:1.5em; padding-top:1em; border-top:1px solid #c3c4c7;">
                        <h4>Custom Field Cleanup</h4>
                        <p class="description">After instructors are synced, remove the native-import Organizer custom field group.</p>
                        <div class="ekti-actions">
                            <button class="button" id="ekti-tax-instructors-scan-fields">Scan Organizer Fields</button>
                            <button class="button button-secondary" id="ekti-tax-instructors-remove-fields" disabled style="color:#a00;">Remove Organizer Custom Fields</button>
                        </div>
                        <div id="ekti-tax-instructors-cleanup-status" class="ekti-status"></div>
                    </div>
                </div>
            </div>

            <!-- Event Mapping Panel -->
            <div class="ekti-panel" id="ekti-mapping-panel">
                <h2>Event Mapping</h2>
                <p>Match TEC events to EventKoi events. Only mapped events will have their attendees imported.</p>
                <div class="ekti-actions">
                    <button class="button button-primary" id="ekti-auto-match">Auto-Match by Title</button>
                    <button class="button" id="ekti-load-mapping">Load Mapping Table</button>
                    <button class="button button-secondary" id="ekti-save-mapping">Save Mapping</button>
                </div>
                <div id="ekti-mapping-status" class="ekti-status"></div>
                <div id="ekti-mapping-table-wrap" style="display:none;">
                    <table class="wp-list-table widefat fixed striped" id="ekti-mapping-table">
                        <thead>
                            <tr>
                                <th>TEC Event ID</th>
                                <th>TEC Event Title</th>
                                <th>Attendees</th>
                                <th>EventKoi Event</th>
                            </tr>
                        </thead>
                        <tbody id="ekti-mapping-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Migration Panel -->
            <div class="ekti-panel" id="ekti-migration-panel">
                <h2>Run Migration</h2>
                <div class="ekti-migration-options">
                    <label>
                        <input type="checkbox" id="ekti-dry-run" checked />
                        <strong>Dry Run Mode</strong> — Preview changes without writing to the database.
                    </label>
                </div>
                <div class="ekti-actions">
                    <button class="button button-primary" id="ekti-start-migration">Start Import</button>
                    <button class="button" id="ekti-resume-migration">Resume</button>
                    <button class="button button-secondary" id="ekti-rollback" style="color:#a00;">Rollback / Cleanup</button>
                </div>
                <div id="ekti-migration-status" class="ekti-status"></div>

                <!-- Progress -->
                <div id="ekti-progress-wrap" style="display:none;">
                    <div class="ekti-progress-bar">
                        <div class="ekti-progress-fill" id="ekti-progress-fill" style="width:0%"></div>
                    </div>
                    <div class="ekti-progress-text" id="ekti-progress-text">0%</div>
                </div>

                <!-- Console -->
                <div id="ekti-console-wrap">
                    <h3>Console Log</h3>
                    <div id="ekti-console" class="ekti-console"></div>
                </div>
            </div>

            <!-- Log File Panel -->
            <div class="ekti-panel" id="ekti-log-panel">
                <h2>Import Log File</h2>
                <div class="ekti-actions">
                    <button class="button" id="ekti-load-log">Load Log</button>
                    <button class="button" id="ekti-clear-log">Clear Log</button>
                </div>
                <pre id="ekti-log-content" class="ekti-log-content"></pre>
            </div>
        </div>
        <?php
    }
}

// Boot.
EventKoi_Tickets_Importer::instance();
