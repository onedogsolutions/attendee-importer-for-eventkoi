<?php
/**
 * Plugin Name: EventKoi Tickets Importer
 * Plugin URI:  https://onedog.solutions
 * Description: Migrates tickets and attendees from The Events Calendar (Event Tickets / Event Tickets Plus) to EventKoi.
 * Version:     1.0.0
 * Author:      One Dog Solutions
 * Author URI:  https://onedog.solutions
 * License:     GPL-2.0-or-later
 * Text Domain: eventkoi-tickets-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EKTI_VERSION', '1.0.0' );
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
     * Get all published EventKoi events.
     */
    private function get_eventkoi_events() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'eventkoi_event' AND post_status = 'publish' ORDER BY post_title ASC",
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
     * Attempt auto-matching TEC events to EventKoi events by title similarity.
     */
    private function auto_match_events() {
        $tec_event_ids = $this->get_tec_event_ids_with_attendees();
        $ek_events     = $this->get_eventkoi_events();
        $mapping       = $this->get_saved_mapping();

        $ek_titles = [];
        foreach ( $ek_events as $ek ) {
            $key = sanitize_title( $ek['post_title'] );
            $ek_titles[ $key ] = $ek['ID'];
        }

        $matched   = 0;
        $unmatched = 0;

        foreach ( $tec_event_ids as $tec_id ) {
            $tec_post = get_post( $tec_id );
            if ( ! $tec_post ) {
                // TEC event deleted — try to match by product name from attendees.
                $product_name = $this->get_product_name_for_tec_event( $tec_id );
                if ( $product_name ) {
                    $key = sanitize_title( $product_name );
                    if ( isset( $ek_titles[ $key ] ) ) {
                        $mapping[ $tec_id ] = $ek_titles[ $key ];
                        $matched++;
                        continue;
                    }
                }
                // Fuzzy match.
                $fuzzy = $this->fuzzy_match_title( $product_name ?: '', array_column( $ek_events, 'post_title' ) );
                if ( $fuzzy ) {
                    $key = sanitize_title( $fuzzy );
                    if ( isset( $ek_titles[ $key ] ) ) {
                        $mapping[ $tec_id ] = $ek_titles[ $key ];
                        $matched++;
                        continue;
                    }
                }
                $unmatched++;
                continue;
            }

            $tec_title = $tec_post->post_title;
            $key       = sanitize_title( $tec_title );

            if ( isset( $ek_titles[ $key ] ) ) {
                $mapping[ $tec_id ] = $ek_titles[ $key ];
                $matched++;
            } else {
                $fuzzy = $this->fuzzy_match_title( $tec_title, array_column( $ek_events, 'post_title' ) );
                if ( $fuzzy ) {
                    $key = sanitize_title( $fuzzy );
                    if ( isset( $ek_titles[ $key ] ) ) {
                        $mapping[ $tec_id ] = $ek_titles[ $key ];
                        $matched++;
                        continue;
                    }
                }
                $unmatched++;
            }
        }

        $this->save_mapping( $mapping );

        return [
            'total_tec_events' => count( $tec_event_ids ),
            'matched'          => $matched,
            'unmatched'        => $unmatched,
            'mapping'          => $mapping,
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
     */
    private function process_batch( $dry_run = false ) {
        global $wpdb;

        $state    = $this->get_migration_state();
        $mapping  = $this->get_saved_mapping();
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

        $results         = [];
        $ticket_cache    = []; // tec_event_id => eventkoi_ticket_id
        $product_cache   = []; // product_id + tec_event_id => eventkoi_ticket_id

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
            if ( isset( $product_cache[ $cache_key ] ) ) {
                $ek_ticket_id = $product_cache[ $cache_key ];
            } elseif ( isset( $ticket_cache[ $tec_event_id ] ) ) {
                $ek_ticket_id = $ticket_cache[ $tec_event_id ];
            } else {
                $ek_ticket_id = $this->get_or_create_ticket( $tec_event_id, $ek_event_id, $product_id, $att, $dry_run );
                if ( $ek_ticket_id ) {
                    $ticket_cache[ $tec_event_id ]    = $ek_ticket_id;
                    $product_cache[ $cache_key ]       = $ek_ticket_id;
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

            // Check if attendee already imported.
            if ( ! $dry_run ) {
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}eventkoi_ticket_orders WHERE order_id = %s",
                    'tec_import_' . $att['ID']
                ) );
                if ( $existing ) {
                    $state['skipped']++;
                    $results[] = [
                        'attendee_id' => $att['ID'],
                        'action'      => 'skipped',
                        'reason'      => 'Already imported',
                    ];
                    continue;
                }
            }

            // Parse ticket meta for custom fields.
            $ticket_meta = [];
            if ( ! empty( $att['ticket_meta'] ) ) {
                $ticket_meta = maybe_unserialize( $att['ticket_meta'] );
                if ( ! is_array( $ticket_meta ) ) {
                    $ticket_meta = [];
                }
            }

            // Determine check-in status.
            $checked_in = ! empty( $att['checked_in'] ) && $att['checked_in'] === '1' ? 1 : 0;

            // Get WooCommerce order date for created_at.
            $order_date = $this->get_wc_order_date( $wc_order_id );

            if ( $dry_run ) {
                $state['attendees_created']++;
                $results[] = [
                    'attendee_id'   => $att['ID'],
                    'action'        => 'dry_run_create',
                    'name'          => $att['full_name'],
                    'email'         => $att['email'],
                    'ek_event_id'   => $ek_event_id,
                    'ek_ticket_id'  => $ek_ticket_id ?: 'would_create',
                    'wc_order_id'   => $wc_order_id,
                    'price'         => $att['paid_price'],
                    'checked_in'    => $checked_in,
                ];
            } else {
                // Insert into wp_eventkoi_ticket_orders.
                $ticket_orders_table = $this->ek_table( 'ticket_orders' );
                $unit_price = floatval( $att['paid_price'] );
                $inserted = $wpdb->insert( $ticket_orders_table, [
                    'event_id'         => $ek_event_id,
                    'ticket_id'        => $ek_ticket_id,
                    'order_id'         => 'tec_import_' . $att['ID'],
                    'customer_name'    => sanitize_text_field( $att['full_name'] ),
                    'customer_email'   => sanitize_email( $att['email'] ),
                    'quantity'         => 1,
                    'unit_price'       => $unit_price,
                    'total_amount'     => $unit_price,
                    'currency'         => 'USD',
                    'payment_status'   => 'paid',
                    'payment_intent_id'=> $wc_order_id ? 'wc_order_' . $wc_order_id : null,
                    'charge_id'        => null,
                    'refund_amount'    => 0,
                    'checked_in'       => $checked_in,
                    'checked_in_at'    => $checked_in ? current_time( 'mysql' ) : null,
                    'checkin_token'    => $att['security_code'] ?: null,
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

                    // Update ticket quantity_sold.
                    if ( $ek_ticket_id ) {
                        $wpdb->query( $wpdb->prepare(
                            "UPDATE {$this->ek_table('tickets')} SET quantity_sold = quantity_sold + 1 WHERE id = %d",
                            $ek_ticket_id
                        ) );
                    }

                    // Mark source attendee as imported.
                    update_post_meta( $att['ID'], '_eventkoi_imported_from_tec', true );

                    $results[] = [
                        'attendee_id'   => $att['ID'],
                        'action'        => 'created',
                        'name'          => $att['full_name'],
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

        // Get stock from WooCommerce product if available.
        $stock = get_post_meta( $product_id, '_stock', true );
        $qty_available = $stock !== '' ? intval( $stock ) : null;

        $inserted = $wpdb->insert( $tickets_table, [
            'event_id'           => $ek_event_id,
            'name'               => sanitize_text_field( $ticket_name ),
            'description'        => '',
            'price'              => $price,
            'currency'           => 'USD',
            'quantity_available' => $qty_available,
            'max_per_order'      => null,
            'quantity_sold'      => $attendee_count,
            'sale_start'         => null,
            'sale_end'           => null,
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
     * Get WC order date.
     */
    private function get_wc_order_date( $order_id ) {
        if ( ! $order_id ) {
            return null;
        }
        $order = wc_get_order( $order_id );
        if ( $order ) {
            return $order->get_date_created()->date( 'Y-m-d H:i:s' );
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * ROLLBACK
     * ------------------------------------------------------------------ */

    private function rollback_import() {
        global $wpdb;
        $ticket_orders_table = $this->ek_table( 'ticket_orders' );
        $tickets_table       = $this->ek_table( 'tickets' );

        // Delete imported ticket orders.
        $deleted_orders = $wpdb->query(
            "DELETE FROM {$ticket_orders_table} WHERE order_id LIKE 'tec_import_%'"
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

        // Reset state.
        delete_option( 'ekti_migration_state' );

        $this->log( "Rollback complete. Deleted {$deleted_orders} ticket orders and {$deleted_tickets} ticket types." );

        return [
            'deleted_orders'  => $deleted_orders,
            'deleted_tickets' => $deleted_tickets,
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

        wp_send_json_success( [
            'tec_attendee_count'  => $tec_attendee_count,
            'tec_event_count'     => count( $tec_event_ids ),
            'ek_event_count'      => count( $ek_events ),
            'mapped_event_count'  => $mapped_count,
            'unmapped_event_count'=> count( $tec_event_ids ) - $mapped_count,
            'mapped_attendees'    => $mapped_attendees,
            'already_imported'    => $already_imported,
            'migration_state'     => $state,
        ] );
    }

    public function ajax_get_event_mapping() {
        $this->check_ajax();

        $tec_event_ids = $this->get_tec_event_ids_with_attendees();
        $ek_events     = $this->get_eventkoi_events();
        $mapping       = $this->get_saved_mapping();
        global $wpdb;

        $rows = [];
        foreach ( $tec_event_ids as $tec_id ) {
            $tec_post = get_post( $tec_id );
            $tec_title = $tec_post ? $tec_post->post_title : '(Deleted) ' . $this->get_product_name_for_tec_event( $tec_id );
            $attendee_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tribe_wooticket_event' AND meta_value = %s",
                $tec_id
            ) );

            $rows[] = [
                'tec_id'         => $tec_id,
                'tec_title'      => $tec_title,
                'attendee_count' => $attendee_count,
                'ek_event_id'    => isset( $mapping[ $tec_id ] ) ? $mapping[ $tec_id ] : null,
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
                    <div class="ekti-stat"><span class="ekti-stat-value" id="stat-already-imported">—</span><span class="ekti-stat-label">Already Imported</span></div>
                </div>
                <button class="button" id="ekti-refresh-stats">Refresh Stats</button>
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
