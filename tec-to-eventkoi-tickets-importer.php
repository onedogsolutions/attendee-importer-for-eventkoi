<?php
/**
 * Plugin Name: EventKoi Tickets Importer
 * Plugin URI:  https://onedog.solutions
 * Description: Migrates tickets and attendees from The Events Calendar (Event Tickets / Event Tickets Plus) to EventKoi.
 * Version:     1.2.0
 * Author:      One Dog Solutions
 * Author URI:  https://onedog.solutions
 * License:     GPL-2.0-or-later
 * Text Domain: eventkoi-tickets-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EKTI_VERSION', '1.2.0' );
define( 'EKTI_LOG_DIR', WP_CONTENT_DIR . '/uploads' );
define( 'EKTI_LOG_FILE', EKTI_LOG_DIR . '/eventkoi-import.log' );
define( 'EKTI_BATCH_SIZE', 30 );

/**
 * Main plugin class.
 */
final class EventKoi_Tickets_Importer {

    /** @var self */
    private static $instance;

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
            $title    = '';

            if ( $tec_post ) {
                $title = $tec_post->post_title;
            } else {
                // TEC event deleted — use product name from attendees.
                $title = $this->get_product_name_for_tec_event( $tec_id ) ?: '';
            }

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

        $this->log( sprintf(
            'Auto-match: %d total matched (%d via _tec_import_source_id, %d exact title, %d fuzzy). %d unmatched.',
            $matched, $source_matched, $title_matched, $fuzzy_matched, $unmatched
        ) );

        return [
            'total_tec_events'  => count( $tec_event_ids ),
            'matched'           => $matched,
            'source_matched'    => $source_matched,
            'title_matched'     => $title_matched,
            'fuzzy_matched'     => $fuzzy_matched,
            'unmatched'         => $unmatched,
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

            $att['_ek_event_id']  = $ek_event_id;
            $att['_ek_ticket_id'] = $ek_ticket_id;
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

                    // Mark source attendee as imported.
                    update_post_meta( $att['ID'], '_eventkoi_imported_from_tec', true );

                    $results[] = [
                        'attendee_id'        => $att['ID'],
                        'action'             => 'created',
                        'name'               => $att['full_name'],
                        'composite_key'      => $composite_order_id,
                        'ek_ticket_order_id' => $wpdb->insert_id,
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

        // Delete tickets created by the importer (tracked via options).
        $all_options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '_ekti_tec_product_%'"
        );
        $deleted_tickets = 0;
        foreach ( $all_options as $opt ) {
            $wpdb->delete( $tickets_table, [ 'id' => intval( $opt->option_value ) ], [ '%d' ] );
            delete_option( $opt->option_name );
            $deleted_tickets++;
        }

        // Clean up meta on source posts.
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_eventkoi_imported_from_tec'"
        );

        // Clean EventKoi meta from WooCommerce orders.
        if ( $this->wc_is_available() ) {
            $ek_meta_keys = [
                '_eventkoi_event_id',
                '_eventkoi_instance_ts',
                '_eventkoi_event_title',
                '_eventkoi_ticket_items',
                '_eventkoi_master_checkin_code',
                '_eventkoi_synced',
            ];
            // Only clean meta that was set by this importer (where _eventkoi_synced = 'yes'
            // and there's a matching ticket_orders row with wc_ prefix — which we just deleted).
            // Since the ticket_orders rows are already deleted, we clean meta on orders
            // that had the synced flag but no longer have EK ticket orders.
            foreach ( $ek_meta_keys as $meta_key ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE pm FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                     WHERE pm.meta_key = %s
                       AND p.post_type = 'shop_order'",
                    $meta_key
                ) );
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
                    wp_set_object_terms( $op['post'], $op['old_term_ids'], 'event_cal', false );
                    break;
                case 'untrash_post':
                    wp_untrash_post( $op['id'] );
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
            $tec_title = $tec_post ? $tec_post->post_title : '(Deleted) ' . $this->get_product_name_for_tec_event( $tec_id );
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
        $this->log( "Auto-match complete: {$result['matched']} matched, {$result['unmatched']} unmatched." );
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
