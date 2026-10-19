<?php
/**
 * Plugin Name: EventKoi Tickets Importer
 * Plugin URI:  https://onedog.solutions
 * Description: Migrates tickets and attendees from The Events Calendar (Event Tickets / Event Tickets Plus) to EventKoi.
 * Version:     1.11.1
 * Author:      One Dog Solutions
 * Author URI:  https://onedog.solutions
 * License:     GPL-2.0-or-later
 * Text Domain: eventkoi-tickets-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EKTI_VERSION', '1.11.1' );
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

    /** Attendee writer version (Step 17); events below it need the repair. */
    const ATTENDEE_VERSION = '17';

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
        add_action( 'wp_ajax_ekti_scan_taxonomy_sync', [ $this, 'ajax_scan_taxonomy_sync' ] );
        add_action( 'wp_ajax_ekti_save_taxonomy_mapping', [ $this, 'ajax_save_taxonomy_mapping' ] );
        add_action( 'wp_ajax_ekti_run_taxonomy_sync', [ $this, 'ajax_run_taxonomy_sync' ] );
        add_action( 'wp_ajax_ekti_undo_taxonomy_sync', [ $this, 'ajax_undo_taxonomy_sync' ] );
        add_action( 'wp_ajax_ekti_remove_organizer_fields', [ $this, 'ajax_remove_organizer_fields' ] );
        add_action( 'wp_ajax_ekti_scan_event_sync', [ $this, 'ajax_scan_event_sync' ] );
        add_action( 'wp_ajax_ekti_adopt_native_imports', [ $this, 'ajax_adopt_native_imports' ] );
        add_action( 'wp_ajax_ekti_undo_event_sync', [ $this, 'ajax_undo_event_sync' ] );
        add_action( 'wp_ajax_ekti_wizard_state', [ $this, 'ajax_wizard_state' ] );
        add_action( 'wp_ajax_ekti_wizard_approve', [ $this, 'ajax_wizard_approve' ] );
        add_action( 'wp_ajax_ekti_preflight', [ $this, 'ajax_preflight' ] );
        add_action( 'wp_ajax_ekti_ek_setup', [ $this, 'ajax_ek_setup' ] );
        add_action( 'wp_ajax_ekti_provision_taxonomy', [ $this, 'ajax_provision_taxonomy' ] );
        add_action( 'wp_ajax_ekti_undo_taxonomy_provision', [ $this, 'ajax_undo_taxonomy_provision' ] );
        add_action( 'wp_ajax_ekti_run_event_stage', [ $this, 'ajax_run_event_stage' ] );
        add_action( 'wp_ajax_ekti_undo_event_stage', [ $this, 'ajax_undo_event_stage' ] );
        add_action( 'wp_ajax_ekti_preview_tickets', [ $this, 'ajax_preview_tickets' ] );
        add_action( 'wp_ajax_ekti_preview_orders', [ $this, 'ajax_preview_orders' ] );
        add_action( 'wp_ajax_ekti_verify_stage', [ $this, 'ajax_verify_stage' ] );
        add_action( 'wp_ajax_ekti_run_attendee_repair', [ $this, 'ajax_run_attendee_repair' ] );
        add_action( 'wp_ajax_ekti_attendee_fields', [ $this, 'ajax_attendee_fields' ] );
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
        // File mtime in the version busts browser caches on every deploy.
        $ver = function ( $rel ) {
            $path = plugin_dir_path( __FILE__ ) . $rel;
            return EKTI_VERSION . ( file_exists( $path ) ? '.' . filemtime( $path ) : '' );
        };
        wp_enqueue_style( 'ekti-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', [], $ver( 'assets/admin.css' ) );
        wp_enqueue_script( 'ekti-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.js', [ 'jquery' ], $ver( 'assets/admin.js' ), true );
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
     * Get distinct TEC event IDs that have attendees.
     */
    private function get_tec_event_ids_with_attendees() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tribe_wooticket_event'"
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

    /* ------------------------------------------------------------------
     * WOOCOMMERCE HELPERS
     * ------------------------------------------------------------------ */

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

    /* ------------------------------------------------------------------
     * AUDIT LEDGER REPLAY
     *
     * Every import write is recorded as an op in a ledger option; undo
     * replays the ops in reverse.
     * ------------------------------------------------------------------ */

    /**
     * Replay a list of audit ops in reverse. Shared by every undo path.
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
                    // get_post_meta() returns '' for a missing key — restore
                    // "missing" rather than leaving an empty value behind.
                    if ( '' === $op['old'] ) {
                        delete_post_meta( $op['post'], $op['key'] );
                    } else {
                        update_post_meta( $op['post'], $op['key'], $op['old'] );
                    }
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
                    $wpdb->delete( $this->ek_table( 'ticket_orders' ), [ 'event_id' => $post_id ] );
                    $bases = [];
                    foreach ( (array) $order_ids as $order_id ) {
                        $base           = strstr( (string) $order_id, ':', true );
                        $bases[ false === $base ? (string) $order_id : $base ] = true;
                    }
                    foreach ( array_keys( $bases ) as $base ) {
                        // A WC order can span several events — keep its
                        // parent rows while another event still has seats.
                        $still_used = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$this->ek_table( 'ticket_orders' )} WHERE order_id = %s OR order_id LIKE %s",
                            $base,
                            $wpdb->esc_like( $base ) . ':%'
                        ) );
                        if ( $still_used ) {
                            continue;
                        }
                        $order_row_id = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT id FROM {$this->ek_table( 'orders' )} WHERE checkout_id = %s",
                            $base
                        ) );
                        if ( $order_row_id ) {
                            $wpdb->delete( $this->ek_table( 'order_notes' ), [ 'order_id' => $order_row_id ] );
                            $wpdb->delete( $this->ek_table( 'orders' ), [ 'id' => $order_row_id ] );
                        }
                    }
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
                case 'wc_order_meta':
                    // Restore the WC order's EventKoi meta to its pre-link values.
                    $wc_order = $this->get_wc_order( (int) $op['order'] );
                    if ( $wc_order ) {
                        foreach ( (array) $op['old'] as $meta_key => $old_value ) {
                            if ( null === $old_value ) {
                                $wc_order->delete_meta_data( $meta_key );
                            } else {
                                $wc_order->update_meta_data( $meta_key, $old_value );
                            }
                        }
                        $wc_order->save();
                    }
                    break;
                case 'insert_field':
                    $wpdb->delete( $this->ek_table( 'fields' ), [ 'id' => (int) $op['id'] ] );
                    break;
                case 'insert_field_group':
                    $wpdb->delete( $this->ek_table( 'field_groups' ), [ 'id' => (int) $op['id'] ] );
                    break;
                // Step 16 ops.
                case 'restore_option':
                    if ( null === $op['old'] ) {
                        delete_option( $op['name'] );
                    } else {
                        update_option( $op['name'], $op['old'] );
                    }
                    break;
                case 'provision_taxonomy':
                    if ( ! empty( $op['created'] ) && class_exists( '\\EventKoi\\Core\\Taxonomies' ) ) {
                        \EventKoi\Core\Taxonomies::delete( (string) $op['slug'], true );
                        // Also drop it from this request, so a re-create in
                        // the same request is not refused as "slug exists".
                        if ( taxonomy_exists( (string) $op['slug'] ) ) {
                            unregister_taxonomy( (string) $op['slug'] );
                        }
                    }
                    if ( class_exists( '\\EventKoi\\Core\\Taxonomies' ) ) {
                        \EventKoi\Core\Taxonomies::invalidate_rewrites();
                    }
                    break;
                case 'update_ticket_orders_instance':
                    $ids = array_map( 'intval', (array) $op['ids'] );
                    if ( $ids ) {
                        $wpdb->query( $wpdb->prepare(
                            "UPDATE {$this->ek_table( 'ticket_orders' )} SET instance_ts = %d WHERE id IN (" . implode( ',', $ids ) . ')',
                            (int) $op['old']
                        ) );
                    }
                    break;
                case 'ticket_qty_add':
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$this->ek_table( 'tickets' )} SET quantity_available = quantity_available - %d WHERE id = %d",
                        (int) $op['delta'],
                        (int) $op['id']
                    ) );
                    break;
            }
            $counts[ $op['type'] ] = ( $counts[ $op['type'] ] ?? 0 ) + 1;
        }
        return $counts;
    }

    /* ------------------------------------------------------------------
     * TIME + LOCATION HELPERS
     * ------------------------------------------------------------------ */

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
     *
     * With $include_unimported, every importable TEC event counts (ek_id 0
     * when it has no EventKoi copy yet) so venues can be matched to terms
     * before Event Sync runs (Step 16 wizard).
     */
    private function get_tec_locations( $include_unimported = false ) {
        $mapping   = $include_unimported ? $this->get_tec_event_pairs() : $this->get_saved_mapping();
        $locations = [];

        foreach ( $mapping as $tec_id => $ek_id ) {
            $tec_id = (int) $tec_id;
            $ek_id  = (int) $ek_id;
            if ( $ek_id && ! get_post( $ek_id ) ) {
                $ek_id = 0;
            }
            if ( ! $ek_id && ! $include_unimported ) {
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
            if ( '' === trim( $name ) && $ek_id ) {
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

    /* ------------------------------------------------------------------
     * TAXONOMY SYNC (LOCATIONS & INSTRUCTORS)
     *
     * EventKoi added two new taxonomies: Locations and Instructors.
     * This engine maps TEC venues -> Locations and TEC organizers ->
     * Instructors with an idempotent, append-only pattern, audited into a
     * ledger per taxonomy so each can be undone on its own.
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
            return taxonomy_exists( EKTI_INSTRUCTOR_TAXONOMY )
                && is_object_in_taxonomy( 'eventkoi_event', EKTI_INSTRUCTOR_TAXONOMY )
                ? EKTI_INSTRUCTOR_TAXONOMY
                : '';
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
        foreach ( $this->get_tec_locations( true ) as $key => $loc ) {
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
     * in a custom field (detected automatically). Covers every importable
     * TEC event (ek_id 0 = not imported yet) so organizers can be matched
     * to terms before Event Sync runs.
     */
    private function get_tec_organizer_sources() {
        $mapping = $this->get_tec_event_pairs();
        $sources = [];
        foreach ( $mapping as $tec_id => $ek_id ) {
            $tec_id = (int) $tec_id;
            $ek_id  = (int) $ek_id;
            if ( $ek_id && ! get_post( $ek_id ) ) {
                $ek_id = 0;
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
                    $names[] = $name;
                }
            }
            if ( empty( $names ) && $ek_id ) {
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
            if ( in_array( $type, [ 'locations', 'instructors' ], true ) ) {
                // Step 16: report the setup state instead of failing, so the
                // UI can offer to create the taxonomy first.
                return [
                    'provision_needed' => true,
                    'setup'            => $this->get_taxonomy_setup_state( $type ),
                    'sources'          => [],
                    'terms'            => [],
                    'counts'           => [ 'sources' => count( $this->get_tec_taxonomy_sources( $type ) ) ],
                    'saved_locations'  => null,
                ];
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
            // No saved choice and no matching term (e.g. a fresh taxonomy):
            // propose creating a term named after the TEC source.
            if ( ! isset( $saved[ $key ] ) && empty( $selected ) ) {
                $selected = 'new:' . sanitize_text_field( $source['name'] );
            }
            $is_new   = is_string( $selected ) && 0 === strpos( $selected, 'new:' );
            $term_id  = $is_new ? 0 : (int) $selected;
            $pending  = 0;
            $seen     = [];
            foreach ( $source['events'] as $ek_id ) {
                $counts['mapped_events']++;
                // Not imported yet: counted, nothing to assign until Event Sync runs.
                if ( $ek_id <= 0 || isset( $seen[ $ek_id ] ) ) {
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
            'provision_needed' => false,
            'setup'            => $this->get_taxonomy_setup_state( $type ),
            'sources'          => $rows,
            'terms'            => $terms,
            'counts'           => $counts,
            'saved_locations'  => $saved_locations,
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
                if ( $ek_id <= 0 || isset( $seen[ $ek_id . ':' . $term_id ] ) ) {
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
     * STEP 16 — TAXONOMY PROVISIONING
     *
     * Creates the EventKoi builder taxonomies the Taxonomy Sync targets
     * (Locations with address fields + the Saved Locations designation and
     * slot map; Instructors) through EventKoi's own public statics —
     * Taxonomies::save() / register(), Location_Taxonomy::set_slug() /
     * set_map() — so definitions are validated and stored exactly as
     * EventKoi's Settings > Taxonomies screen stores them. Audited into the
     * separate ekti_tax_provision_audit ledger.
     * ------------------------------------------------------------------ */

    /**
     * Every importable TEC event (native status filter) => its EventKoi
     * event ID, or 0 when it has none yet. The saved mapping wins, then the
     * lowest-ID live `_tec_import_source_id` marker.
     */
    private function get_tec_event_pairs() {
        global $wpdb;
        $pairs = [];
        $ids   = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'tribe_events'
               AND post_status IN ('publish','draft','pending','future','private')
             ORDER BY ID ASC"
        );
        foreach ( $ids as $id ) {
            $pairs[ (int) $id ] = 0;
        }
        $markers = $wpdb->get_results(
            "SELECT pm.meta_value AS tec_id, pm.post_id AS ek_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_tec_import_source_id'
               AND p.post_type = 'eventkoi_event'
               AND p.post_status NOT IN ('trash','auto-draft')
             ORDER BY pm.post_id ASC",
            ARRAY_A
        );
        foreach ( $markers as $m ) {
            $tec_id = (int) $m['tec_id'];
            if ( isset( $pairs[ $tec_id ] ) && ! $pairs[ $tec_id ] ) {
                $pairs[ $tec_id ] = (int) $m['ek_id'];
            }
        }
        foreach ( $this->get_saved_mapping() as $tec_id => $ek_id ) {
            if ( (int) $ek_id > 0 ) {
                $pairs[ (int) $tec_id ] = (int) $ek_id;
            }
        }
        return $pairs;
    }

    private function ek_taxonomy_api_available() {
        return class_exists( '\\EventKoi\\Core\\Taxonomies' )
            && class_exists( '\\EventKoi\\Core\\Location_Taxonomy' )
            && class_exists( '\\EventKoi\\Core\\Taxonomy_Fields' );
    }

    /**
     * The definition the importer proposes when the taxonomy is missing.
     * Locations: one text field per Saved Locations address slot.
     * Instructors: name only.
     */
    private function proposed_taxonomy_definition( $type ) {
        if ( 'locations' === $type ) {
            $labels = [
                'address_1' => 'Address line 1',
                'address_2' => 'Address line 2',
                'city'      => 'City',
                'state'     => 'State / Province',
                'zip'       => 'ZIP / Postal code',
                'country'   => 'Country',
                'latitude'  => 'Latitude',
                'longitude' => 'Longitude',
            ];
            $fields = [];
            foreach ( $labels as $key => $label ) {
                $fields[] = [ 'key' => $key, 'label' => $label, 'type' => 'text', 'description' => '' ];
            }
            return [
                'slug'         => EKTI_LOCATION_TAXONOMY,
                'singular'     => 'Location',
                'plural'       => 'Locations',
                'hierarchical' => false,
                'public'       => true,
                'show_in_menu' => true,
                'fields'       => $fields,
            ];
        }
        return [
            'slug'         => EKTI_INSTRUCTOR_TAXONOMY,
            'singular'     => 'Instructor',
            'plural'       => 'Instructors',
            'hierarchical' => false,
            'public'       => true,
            'show_in_menu' => true,
            'fields'       => [],
        ];
    }

    /**
     * Default slot => field-key map for the proposed Locations definition.
     */
    private function proposed_slot_map() {
        return [
            'address1'  => 'address_1',
            'address2'  => 'address_2',
            'city'      => 'city',
            'state'     => 'state',
            'country'   => 'country',
            'zip'       => 'zip',
            'latitude'  => 'latitude',
            'longitude' => 'longitude',
        ];
    }

    /**
     * Where a taxonomy type stands: missing / not designated (locations) /
     * slots unmapped (locations) / ready. Read-only.
     */
    private function get_taxonomy_setup_state( $type ) {
        $api   = $this->ek_taxonomy_api_available();
        $defs  = $api ? \EventKoi\Core\Taxonomies::all() : (array) get_option( 'eventkoi_taxonomies', [] );
        $slug  = 'locations' === $type ? EKTI_LOCATION_TAXONOMY : EKTI_INSTRUCTOR_TAXONOMY;

        $definitions = [];
        foreach ( $defs as $def_slug => $def ) {
            if ( ! is_array( $def ) ) {
                continue;
            }
            $fields = [];
            foreach ( (array) ( $def['fields'] ?? [] ) as $f ) {
                if ( is_array( $f ) && ! empty( $f['key'] ) ) {
                    $fields[] = [
                        'key'   => (string) $f['key'],
                        'label' => (string) ( $f['label'] ?? $f['key'] ),
                        'type'  => (string) ( $f['type'] ?? 'text' ),
                    ];
                }
            }
            $definitions[] = [
                'slug'     => (string) $def_slug,
                'singular' => (string) ( $def['singular'] ?? $def_slug ),
                'plural'   => (string) ( $def['plural'] ?? $def_slug ),
                'fields'   => $fields,
                'terms'    => taxonomy_exists( (string) $def_slug ) ? (int) wp_count_terms( [ 'taxonomy' => (string) $def_slug, 'hide_empty' => false ] ) : 0,
            ];
        }

        $state = [
            'type'          => $type,
            'api'           => $api,
            'status'        => 'missing',
            'target'        => '',
            'designated'    => '',
            'map'           => [],
            'slots'         => $this->location_slots,
            'definitions'   => $definitions,
            'proposed'      => $this->proposed_taxonomy_definition( $type ),
            'proposed_map'  => 'locations' === $type ? $this->proposed_slot_map() : [],
            'conflict'      => '',
            'message'       => '',
        ];

        if ( ! $api ) {
            $state['status']  = 'unsupported';
            $state['message'] = 'EventKoi\'s Taxonomy Builder is not available. Update EventKoi to 1.4.6 or later.';
            return $state;
        }

        if ( 'instructors' === $type ) {
            if ( isset( $defs[ $slug ] ) ) {
                $state['status'] = 'ready';
                $state['target'] = $slug;
            } elseif ( taxonomy_exists( $slug ) ) {
                $state['conflict'] = 'A taxonomy named "' . $slug . '" is registered by another plugin.';
            }
            return $state;
        }

        $designated          = \EventKoi\Core\Location_Taxonomy::get_slug();
        $state['designated'] = $designated;
        $target              = ( '' !== $designated && isset( $defs[ $designated ] ) )
            ? $designated
            : ( isset( $defs[ $slug ] ) ? $slug : '' );
        $state['target']     = $target;

        if ( '' === $target ) {
            if ( taxonomy_exists( $slug ) ) {
                $state['conflict'] = 'A taxonomy named "' . $slug . '" is registered by another plugin.';
            }
            return $state;
        }

        if ( $designated !== $target || ! \EventKoi\Core\Location_Taxonomy::is_active() ) {
            $state['status'] = 'not_designated';
            return $state;
        }

        $field_map    = $this->get_saved_location_field_map();
        $state['map'] = $field_map['slots'];
        $state['status'] = empty( $field_map['unmapped_slots'] ) ? 'ready' : 'unmapped';
        return $state;
    }

    /**
     * Value of an option, or null when the option does not exist.
     */
    private function get_option_or_null( $name ) {
        $missing = '__ekti_missing__';
        $value   = get_option( $name, $missing );
        return $missing === $value ? null : $value;
    }

    /**
     * Create (or extend) a builder taxonomy and, for locations, designate it
     * as Saved Locations with a slot map.
     *
     * @param string $type  locations|instructors.
     * @param array  $input mode (create|existing), slug, singular, plural,
     *                      fields[], existing_slug, map (slot => field key).
     * @return array|WP_Error
     */
    private function provision_taxonomy( $type, $input ) {
        if ( ! in_array( $type, [ 'locations', 'instructors' ], true ) ) {
            return new WP_Error( 'ekti_invalid_type', 'Invalid taxonomy type.' );
        }
        if ( ! $this->ek_taxonomy_api_available() ) {
            return new WP_Error( 'ekti_no_taxonomy_api', 'EventKoi\'s Taxonomy Builder is not available. Update EventKoi to 1.4.6 or later.' );
        }

        $mode = isset( $input['mode'] ) && 'existing' === $input['mode'] ? 'existing' : 'create';
        $ops  = [];
        // Snapshot EventKoi's options first; reverse replay restores them
        // after the definition delete (ops pushed later replay first).
        foreach ( [ 'eventkoi_taxonomies', 'eventkoi_location_taxonomy', 'eventkoi_location_taxonomy_map' ] as $name ) {
            $ops[] = [ 'type' => 'restore_option', 'name' => $name, 'old' => $this->get_option_or_null( $name ) ];
        }

        $created = false;
        $added   = 0;
        if ( 'existing' === $mode ) {
            $target = sanitize_key( (string) ( $input['existing_slug'] ?? '' ) );
            if ( '' === $target || null === \EventKoi\Core\Taxonomies::get( $target ) ) {
                return new WP_Error( 'ekti_unknown_taxonomy', 'Choose an existing EventKoi taxonomy.' );
            }
        } else {
            $proposed = $this->proposed_taxonomy_definition( $type );
            $target   = sanitize_key( (string) ( $input['slug'] ?? $proposed['slug'] ) );
            if ( '' === $target ) {
                $target = $proposed['slug'];
            }
            $requested = [];
            foreach ( (array) ( $input['fields'] ?? $proposed['fields'] ) as $f ) {
                if ( ! is_array( $f ) ) {
                    continue;
                }
                $key = sanitize_key( (string) ( $f['key'] ?? '' ) );
                if ( '' === $key ) {
                    continue;
                }
                $requested[ $key ] = [
                    'key'         => $key,
                    'label'       => sanitize_text_field( (string) ( $f['label'] ?? $key ) ),
                    'type'        => 'text',
                    'description' => '',
                ];
            }

            $existing = \EventKoi\Core\Taxonomies::get( $target );
            if ( null === $existing ) {
                $def = [
                    'slug'         => $target,
                    'singular'     => sanitize_text_field( (string) ( $input['singular'] ?? $proposed['singular'] ) ),
                    'plural'       => sanitize_text_field( (string) ( $input['plural'] ?? $proposed['plural'] ) ),
                    'hierarchical' => false,
                    'public'       => true,
                    'show_in_menu' => true,
                    'fields'       => array_values( $requested ),
                ];
                $created = true;
                $added   = count( $requested );
            } else {
                // Extend only: keep every stored field (original_key = key so
                // EventKoi's rename/alias logic never fires) and append the
                // requested fields the definition does not have yet.
                $def           = $existing;
                $def['fields'] = [];
                $have          = [];
                foreach ( (array) ( $existing['fields'] ?? [] ) as $f ) {
                    $f['original_key'] = $f['key'];
                    $def['fields'][]   = $f;
                    $have[ $f['key'] ] = true;
                }
                foreach ( $requested as $key => $f ) {
                    if ( ! isset( $have[ $key ] ) ) {
                        $def['fields'][] = $f;
                        ++$added;
                    }
                }
            }

            if ( $created || $added ) {
                $saved = \EventKoi\Core\Taxonomies::save( $def );
                if ( is_wp_error( $saved ) ) {
                    return $saved;
                }
            }
            // Live in this request (register() skips already-registered
            // taxonomies), rewrites flushed once on the next init.
            \EventKoi\Core\Taxonomies::register();
            \EventKoi\Core\Taxonomies::invalidate_rewrites();
        }

        $designated = false;
        if ( 'locations' === $type ) {
            $set = \EventKoi\Core\Location_Taxonomy::set_slug( $target );
            if ( is_wp_error( $set ) ) {
                $this->audit_push_provision( $ops, $created, $target );
                return $set;
            }
            // set_slug() never clears a previous taxonomy's map (it compares
            // after writing), so always write the map explicitly.
            $map = [];
            foreach ( (array) ( $input['map'] ?? $this->proposed_slot_map() ) as $slot => $field_key ) {
                $slot = sanitize_key( (string) $slot );
                if ( in_array( $slot, $this->location_slots, true ) ) {
                    $map[ $slot ] = sanitize_key( (string) $field_key );
                }
            }
            \EventKoi\Core\Location_Taxonomy::set_map( $map );
            $designated = true;
        }

        $this->audit_push_provision( $ops, $created, $target );
        $this->log( sprintf(
            'Taxonomy provisioning (%s): %s "%s"%s%s.',
            $type,
            $created ? 'created' : ( 'existing' === $mode ? 'selected' : 'reused' ),
            $target,
            $added && ! $created ? ", {$added} field(s) appended" : '',
            $designated ? ', designated as Saved Locations' : ''
        ) );

        return [
            'slug'    => $target,
            'created' => $created,
            'added'   => $added,
            'state'   => $this->get_taxonomy_setup_state( $type ),
        ];
    }

    private function audit_push_provision( $ops, $created, $slug ) {
        $ops[] = [ 'type' => 'provision_taxonomy', 'slug' => $slug, 'created' => (bool) $created ];
        $audit = get_option( 'ekti_tax_provision_audit', [] );
        update_option( 'ekti_tax_provision_audit', array_merge( is_array( $audit ) ? $audit : [], $ops ), false );
    }

    /**
     * Undo taxonomy provisioning. Refused while a term sync ledger still
     * holds writes into those taxonomies (undo term matching first).
     */
    private function undo_taxonomy_provision() {
        $audit = get_option( 'ekti_tax_provision_audit', [] );
        if ( empty( $audit ) ) {
            return [ 'error' => 'Nothing to undo.' ];
        }
        foreach ( [ 'locations', 'instructors' ] as $type ) {
            if ( ! empty( get_option( "ekti_tax_sync_audit_{$type}", [] ) ) ) {
                return [ 'error' => 'Undo the ' . $type . ' term matching first — its ledger still holds writes into this taxonomy.' ];
            }
        }
        $counts = $this->replay_audit_ops( $audit );
        delete_option( 'ekti_tax_provision_audit' );
        foreach ( [ 'locations', 'instructors' ] as $type ) {
            delete_option( "ekti_taxonomy_mapping_{$type}" );
        }
        $this->log( 'Taxonomy provisioning undo complete. ' . wp_json_encode( $counts ) );
        return [ 'undone' => $counts ];
    }

    /**
     * Assign the mapped Locations / Instructors terms to one freshly
     * imported event (events stage). Uses the saved source → term mappings
     * from the wizard's taxonomy step; unresolved "new:" targets are skipped.
     */
    private function assign_event_taxonomies( $tec_id, $ek_id, &$ops ) {
        $assigned = [];
        foreach ( [ 'locations', 'instructors' ] as $type ) {
            $taxonomy = $this->get_taxonomy_for_type( $type );
            $mapping  = $this->get_saved_taxonomy_mapping( $type );
            if ( ! $taxonomy || empty( $mapping ) ) {
                continue;
            }
            $keys = [];
            if ( 'locations' === $type ) {
                $vid   = (int) get_post_meta( $tec_id, '_EventVenueID', true );
                $venue = $vid ? get_post( $vid ) : null;
                if ( $venue && '' !== trim( $venue->post_title ) ) {
                    $keys[] = $this->normalize_location_name( html_entity_decode( $venue->post_title, ENT_QUOTES ) );
                }
            } else {
                $oids = get_post_meta( $tec_id, '_EventOrganizerID', false );
                foreach ( (array) $oids as $oid ) {
                    foreach ( (array) $oid as $one ) {
                        $org = (int) $one ? get_post( (int) $one ) : null;
                        if ( $org && '' !== trim( $org->post_title ) ) {
                            $keys[] = $this->normalize_location_name( html_entity_decode( $org->post_title, ENT_QUOTES ) );
                        }
                    }
                }
            }
            $term_ids = [];
            foreach ( array_unique( array_filter( $keys ) ) as $key ) {
                $target = $mapping[ $key ] ?? null;
                if ( is_int( $target ) || ( is_numeric( $target ) && (int) $target > 0 ) ) {
                    $term_ids[] = (int) $target;
                }
            }
            if ( empty( $term_ids ) ) {
                continue;
            }
            $current = wp_get_object_terms( $ek_id, $taxonomy, [ 'fields' => 'ids' ] );
            $current = is_wp_error( $current ) ? [] : array_map( 'intval', $current );
            $set     = wp_set_object_terms( $ek_id, $term_ids, $taxonomy, true );
            if ( is_wp_error( $set ) ) {
                continue;
            }
            $ops[] = [ 'type' => 'set_terms', 'taxonomy' => $taxonomy, 'post' => $ek_id, 'old_term_ids' => $current ];
            $assigned[ $type ] = count( $term_ids );
        }
        return $assigned;
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
     *   - WooCommerce attendees are linked to their orders by the orders
     *     stage (link_woo_attendees) through the
     *     _ekti_tec_product_{tec}_{product} bindings written here. Tickets Commerce attendees DO import here as zero-value
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

            // WooCommerce attendees (the orders stage links them — report only).
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
     * so the orders stage will not double-import attendees EventKoi's native
     * importer already brought over. Only events whose EventKoi copy already
     * has ticket-order rows count as native adoptions — this sync's own
     * events have no orders until the orders stage runs.
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

        // Step 16: tickets and attendees are separate stages. The event
        // stage only needs to know whether tickets WILL exist, for the
        // description's cost tail (native appends it only when the event
        // has neither Event Tickets types nor a cost-derived ticket).
        $cost_raw        = get_post_meta( $tec_id, '_EventCost', true );
        $currency_symbol = get_post_meta( $tec_id, '_EventCurrencySymbol', true );
        $currency_pos    = get_post_meta( $tec_id, '_EventCurrencyPosition', true );
        $currency_code   = get_post_meta( $tec_id, '_EventCurrencyCode', true );
        $has_et_tickets  = $this->es_has_et_tickets( $tec_id );
        $will_cost_ticket = ! $has_et_tickets
            && null !== $this->cost_ticket_spec( $cost_raw, $currency_symbol, $currency_code );

        // Placeholder attendance until the tickets stage sets the real mode.
        $attendance_mode = 'none';

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

        if ( ! $will_cost_ticket && ! $has_et_tickets && '' !== (string) $cost_raw ) {
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

        // Keep the saved TEC → EventKoi mapping in step (taxonomy sources read it).
        $old_mapping = $this->get_saved_mapping();
        if ( (int) ( $old_mapping[ $tec_id ] ?? 0 ) !== $new_post_id ) {
            $new_mapping = $old_mapping;
            $new_mapping[ $tec_id ] = $new_post_id;
            $this->save_mapping( $new_mapping );
            $ops[] = [ 'type' => 'update_option', 'name' => 'ekti_event_mapping', 'old' => $old_mapping ];
        }

        // Locations / Instructors terms from the wizard's taxonomy mapping.
        $taxonomies = $this->assign_event_taxonomies( $tec_id, $new_post_id, $ops );

        // Stage marker (lives on the created post — undo deletes it with it).
        update_post_meta( $new_post_id, '_ekti_stage_events', time() );

        $result = [
            'tec_id'           => $tec_id,
            'event_id'         => $new_post_id,
            'title'            => $title,
            'status'           => $status,
            'attendance_mode'  => $attendance_mode,
            'tickets'          => 0,
            'cost_ticket'      => false,
            'tc_attendees'     => 0,
            'rsvp_rows'        => 0,
            'rsvp_merged'      => 0,
            'rsvp_skipped'     => 0,
            'woo_attendees'    => 0,
            'woo_orders'       => 0,
            'woo_unpaid'       => 0,
            'bindings'         => 0,
            'taxonomies'       => $taxonomies,
            'notes'            => $notes,
        ];

        return $result;
    }

    /**
     * Whether a TEC event has any Event Tickets types EventKoi would import
     * (mirrors the `found` flag of import_event_tickets_sync()).
     */
    private function es_has_et_tickets( $tec_id ) {
        if ( ! function_exists( 'eventkoi_is_tickets_feature_enabled' ) || ! eventkoi_is_tickets_feature_enabled() ) {
            return false;
        }
        return ! empty( $this->es_get_et_ticket_posts( 'tribe_rsvp_tickets', '_tribe_rsvp_for_event', $tec_id ) )
            || ! empty( $this->es_get_et_ticket_posts( 'tec_tc_ticket', '_tec_tickets_commerce_event', $tec_id ) )
            || ! empty( $this->es_get_et_ticket_posts( 'product', '_tribe_wooticket_for_event', $tec_id ) );
    }

    /**
     * Write one meta value on an imported event, recording the prior value
     * so a stage undo can restore it.
     */
    private function set_event_meta_audited( $post_id, $key, $value, &$ops ) {
        $old = get_post_meta( $post_id, $key, true );
        update_post_meta( $post_id, $key, $value );
        $ops[] = [ 'type' => 'update_postmeta', 'post' => (int) $post_id, 'key' => $key, 'old' => $old ];
    }

    /**
     * Tickets stage (Step 16) for one imported event: Event Tickets types
     * (or the cost-derived ticket), attendance mode, RSVP / shared capacity,
     * WooCommerce product bindings. The ticket summary (incl. the seats
     * deducted per ticket) is persisted on the event for the orders stage's
     * capacity give-back.
     */
    private function import_event_tickets_stage( $ek_id, $tec_id, &$ops ) {
        $notes      = [];
        $et_tickets = $this->import_event_tickets_sync( $tec_id, $ek_id, $ops, $notes );

        $ticket_id = 0;
        if ( ! $et_tickets['found'] ) {
            $ticket_id = $this->maybe_create_ticket_from_cost_sync(
                $ek_id,
                get_post_meta( $tec_id, '_EventCost', true ),
                get_post_meta( $tec_id, '_EventCurrencySymbol', true ),
                get_post_meta( $tec_id, '_EventCurrencyCode', true ),
                $ops
            );
        }

        $attendance_mode = ( $ticket_id || $et_tickets['imported'] > 0 ) ? 'tickets' : 'none';
        if ( 'rsvp' === $et_tickets['attendance_mode'] ) {
            $attendance_mode = 'rsvp';
        }

        $this->set_event_meta_audited( $ek_id, 'attendance_mode', $attendance_mode, $ops );
        $this->set_event_meta_audited( $ek_id, 'tickets_enabled', 'tickets' === $attendance_mode, $ops );
        if ( 'rsvp' === $attendance_mode ) {
            $this->set_event_meta_audited( $ek_id, 'rsvp_enabled', true, $ops );
            if ( $et_tickets['rsvp_capacity'] > 0 ) {
                $this->set_event_meta_audited( $ek_id, 'rsvp_capacity', $et_tickets['rsvp_capacity'], $ops );
            }
        }
        // Shared (capped/global) Event Tickets stock maps to the per-event total.
        if ( $et_tickets['event_capacity'] > 0 ) {
            $this->set_event_meta_audited( $ek_id, 'tickets_event_capacity', $et_tickets['event_capacity'], $ops );
        }

        foreach ( $et_tickets['skipped'] as $s ) {
            $notes[] = $s;
        }

        $summary                = $et_tickets;
        $summary['cost_ticket'] = (int) $ticket_id;
        $summary['at']          = time();
        $this->set_event_meta_audited( $ek_id, '_ekti_stage_tickets', $summary, $ops );

        // Attendance mode changed since the events stage indexed the event.
        if ( class_exists( '\\EventKoi\\Core\\Upcoming_Index' ) ) {
            \EventKoi\Core\Upcoming_Index::rebuild_event( $ek_id );
        }

        return [
            'attendance_mode' => $attendance_mode,
            'tickets'         => (int) $et_tickets['imported'],
            'cost_ticket'     => (bool) $ticket_id,
            'bindings'        => (int) $et_tickets['bindings'],
            'notes'           => $notes,
        ];
    }

    /**
     * Orders stage (Step 16) for one imported event: RSVP rows, Tickets
     * Commerce manual orders, WC-linked WooCommerce attendees, then the
     * native capacity give-back using the tickets stage's summary.
     *
     * @return array|WP_Error
     */
    private function import_event_orders_stage( $ek_id, $tec_id, &$ops ) {
        $et_tickets = get_post_meta( $ek_id, '_ekti_stage_tickets', true );
        if ( ! is_array( $et_tickets ) ) {
            return new WP_Error( 'no_ticket_stage', 'The tickets stage has not run for this event.' );
        }
        $notes     = [];
        $attendees = $this->import_event_attendees_sync( $tec_id, $ek_id, $et_tickets, $ops, $notes );
        $this->set_event_meta_audited( $ek_id, '_ekti_stage_orders', array_merge( $attendees, [ 'at' => time() ] ), $ops );
        // Written by the Step 17 writer — nothing for the attendee repair to do.
        $this->set_event_meta_audited( $ek_id, '_ekti_attendee_v', self::ATTENDEE_VERSION, $ops );
        return array_merge( $attendees, [ 'notes' => $notes ] );
    }

    /**
     * Import Event Tickets ticket types for one event. Mirrors native
     * TEC_Importer::import_event_tickets() with the Step 14 capacity
     * divergence: quantity_available is the FULL capacity and quantity_sold
     * is pre-set from TEC total_sales (native deducts sold seats from the
     * capacity and gives them back after attendee import).
     *
     * Also writes _ekti_tec_product_{tec}_{product} bindings for WooCommerce
     * products so the orders stage binds its attendee import to the tickets
     * created here.
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
                if ( $inserted['deducted'] > 0 ) {
                    $summary['sold_by_ticket'][ $inserted['id'] ] = $inserted['deducted'];
                }

                // WooCommerce binding so the orders stage attaches to this ticket.
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
            $notes[] = $sold_total . ' previously sold seat(s) deducted from the imported capacity (given back per imported attendee).';
        }

        // Shared (capped/global) stock maps to the per-event total capacity,
        // minus the seats already sold (native parity; given back after the
        // attendee import).
        $use_global = (string) get_post_meta( $tec_id, '_tribe_ticket_use_global_stock', true );
        if ( in_array( $use_global, [ '1', 'yes', 'true' ], true ) ) {
            $stock_level               = max( 0, (int) get_post_meta( $tec_id, '_tribe_ticket_global_stock_level', true ) );
            $summary['event_capacity'] = max( $stock_level - $sold_total, 0 );
        }

        return $summary;
    }

    /**
     * Insert one imported Event Tickets type as an eventkoi_tickets row.
     * Mirrors native TEC_Importer::insert_imported_ticket() exactly:
     * previously sold seats are deducted from the capacity up front and
     * quantity_sold is left to EventKoi's recount. After attendee import,
     * give_back_imported_capacity_sync() returns the seats that are now
     * represented by real ticket_orders rows (Step 15 — the Step 14
     * full-capacity + total_sales preset could not survive a recount).
     *
     * @return array{id:int, deducted:int}
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

        // Seats actually removed from capacity (0 for unlimited tickets).
        $deducted = 0;
        if ( null !== $capacity ) {
            $deducted                   = min( $capacity, max( 0, (int) $sold ) );
            $data['quantity_available'] = max( $capacity - (int) $sold, 0 );
            $formats[]                  = '%d';
        }

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
            return [ 'id' => 0, 'deducted' => 0 ];
        }

        $id     = (int) $wpdb->insert_id;
        $ops[]  = [ 'type' => 'delete_ticket', 'id' => $id ];
        return [ 'id' => $id, 'deducted' => $deducted ];
    }

    /**
     * Give back capacity for seats now represented by imported attendee
     * rows. Port of native give_back_imported_attendee_capacity(): native
     * counts every imported attendee; here only rows whose WC order was
     * ever paid count (complete / partially_refunded / refunded) because
     * pending or failed seats never entered total_sales, so they were
     * never deducted. Both the per-ticket and the shared per-event
     * capacity are adjusted. The ticket rows were created in this same
     * event import, so undo deletes them outright (no extra op needed);
     * the event meta lives on the created post.
     *
     * @param array $et_tickets      import_event_tickets_sync() summary.
     * @param array $paid_by_ticket  eventkoi ticket id => ever-paid rows imported.
     */
    private function give_back_imported_capacity_sync( $new_post_id, $et_tickets, $paid_by_ticket, &$ops ) {
        global $wpdb;

        // Audited (Step 16) so undoing only the orders stage puts the
        // deducted seats back while the tickets themselves stay.
        $total = 0;
        foreach ( $paid_by_ticket as $ticket_id => $count ) {
            $total    += (int) $count;
            $give_back = min( (int) ( $et_tickets['sold_by_ticket'][ $ticket_id ] ?? 0 ), (int) $count );
            if ( $give_back <= 0 ) {
                continue;
            }
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$this->ek_table( 'tickets' )} SET quantity_available = quantity_available + %d WHERE id = %d",
                $give_back,
                (int) $ticket_id
            ) );
            $ops[] = [ 'type' => 'ticket_qty_add', 'id' => (int) $ticket_id, 'delta' => $give_back ];
        }

        if ( $total > 0 && (int) get_post_meta( $new_post_id, 'tickets_event_capacity', true ) > 0 ) {
            $give_back = min( (int) $et_tickets['sold_total'], $total );
            if ( $give_back > 0 ) {
                $current = get_post_meta( $new_post_id, 'tickets_event_capacity', true );
                update_post_meta( $new_post_id, 'tickets_event_capacity', (int) $current + $give_back );
                $ops[] = [ 'type' => 'update_postmeta', 'post' => (int) $new_post_id, 'key' => 'tickets_event_capacity', 'old' => $current ];
            }
        }
    }

    /**
     * Create a single EventKoi ticket from TEC's free-text cost field when
     * the cost is a positive numeric value. Mirrors native
     * TEC_Importer::maybe_create_ticket_from_cost() (audited).
     */
    private function maybe_create_ticket_from_cost_sync( $event_id, $cost_raw, $currency_symbol, $currency_code, &$ops ) {
        $spec = $this->cost_ticket_spec( $cost_raw, $currency_symbol, $currency_code );
        if ( null === $spec ) {
            return false;
        }
        $price             = $spec['price'];
        $checkout_currency = $spec['currency'];

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
     * The ticket native would derive from TEC's free-text cost field —
     * [price, currency] — or null when no ticket would be created.
     */
    private function cost_ticket_spec( $cost_raw, $currency_symbol, $currency_code ) {
        if ( '' === (string) $cost_raw ) {
            return null;
        }
        if ( ! function_exists( 'eventkoi_is_tickets_feature_enabled' ) || ! eventkoi_is_tickets_feature_enabled() ) {
            return null;
        }
        if ( ! preg_match( '/\d+(?:\.\d+)?/', (string) $cost_raw, $m ) ) {
            return null; // Non-numeric: "Free", "Donation", "TBD" — description keeps the text.
        }
        $price = (float) $m[0];
        if ( $price <= 0 ) {
            return null;
        }
        $checkout_currency = $this->es_get_checkout_currency();
        $has_tec_currency  = '' !== (string) $currency_code || '' !== trim( (string) $currency_symbol );
        if ( $has_tec_currency && $this->es_infer_currency_code( $currency_code, $currency_symbol ) !== $checkout_currency ) {
            return null;
        }
        return [ 'price' => $price, 'currency' => $checkout_currency ];
    }

    /**
     * Import attendee history for one event: native EventKoi RSVP rows for
     * RSVP-only events, Tickets Commerce attendees as zero-value manual
     * orders otherwise. Mirrors native TEC_Importer::import_event_attendees()
     * plus the WooCommerce branch (link_woo_attendees(), through the product
     * bindings written by import_event_tickets_sync()).
     */
    private function import_event_attendees_sync( $tec_id, $new_post_id, $et_tickets, &$ops, &$notes ) {
        $summary = [
            'tc_attendees'          => 0,
            'rsvp_rows'             => 0,
            'rsvp_merged'           => 0,
            'rsvp_skipped'          => 0,
            'woo_attendees'         => 0,
            'woo_orders'            => 0,
            'woo_unpaid'            => 0,
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

        $currency       = '' !== $et_tickets['currency'] ? $et_tickets['currency'] : $this->es_get_checkout_currency();
        $ticket_id_map  = $et_tickets['ticket_id_map'];
        $paid_by_ticket = []; // eventkoi ticket id => ever-paid rows (capacity give-back).

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
                $paid_by_ticket[ $ticket_id ] = ( $paid_by_ticket[ $ticket_id ] ?? 0 ) + 1;
            }
        }

        if ( $unmatched > 0 ) {
            $notes[] = $unmatched . ' Tickets Commerce attendee(s) not imported: no matching imported ticket.';
        }
        if ( $unusable > 0 ) {
            $notes[] = $unusable . ' Tickets Commerce attendee(s) not imported: no recorded name or email.';
        }

        // WooCommerce attendees → WC-linked ticket_orders rows (Step 15).
        // Native imports them here too (as zero-value manual orders); we
        // keep the WooCommerce link and the real order status instead.
        $woo_attendees = $this->get_tec_attendees_for_event( $tec_id );
        if ( ! empty( $woo_attendees ) ) {
            $resolver = function ( $product_id ) use ( $ticket_id_map ) {
                return (int) ( $ticket_id_map[ (int) $product_id ] ?? 0 );
            };
            $woo = $this->link_woo_attendees( $new_post_id, $woo_attendees, $resolver, false, $ops );

            $summary['woo_attendees'] = $woo['created'];
            $summary['woo_orders']    = $woo['orders'];
            $summary['woo_unpaid']    = $woo['unpaid'];
            foreach ( $woo['ever_paid_by_ticket'] as $tid => $n ) {
                $paid_by_ticket[ $tid ] = ( $paid_by_ticket[ $tid ] ?? 0 ) + $n;
            }
            foreach ( $woo['notes'] as $n ) {
                $notes[] = $n;
            }
        }

        $this->give_back_imported_capacity_sync( $new_post_id, $et_tickets, $paid_by_ticket, $ops );

        return $summary;
    }

    /* ------------------------------------------------------------------
     * WOOCOMMERCE ATTENDEE WRITER (Step 15)
     *
     * Used by the wizard's orders stage and attendee repair. Writes
     * one wp_eventkoi_ticket_orders row per TEC attendee in the exact shape
     * EventKoi's own WooCommerce checkout produces via
     * Ticket_Order_Sync::sync_order_to_local() (codes path), with the WC
     * order's real status. No eventkoi_orders / eventkoi_charges rows: the
     * native WC flow writes none (EventKoi lists WC orders by grouping
     * ticket_orders on the wc_{id} base; its charges table is Stripe-only).
     * ------------------------------------------------------------------ */

    /**
     * Pivoted TEC WooCommerce attendee rows for one TEC event (published
     * attendee posts only), same columns as get_tec_attendees().
     */
    private function get_tec_attendees_for_event( $tec_id ) {
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
             INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID
               AND ev.meta_key = '_tribe_wooticket_event' AND ev.meta_value = %s
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'tribe_wooticket' AND p.post_status = 'publish'
             GROUP BY p.ID
             ORDER BY p.ID ASC",
            (string) (int) $tec_id
        ), ARRAY_A );
    }

    /**
     * EventKoi payment_status for a WC order — the map EventKoi itself uses
     * in WooCommerce_Checkout::on_order_status_changed(). A completed order
     * carrying a partial refund maps to partially_refunded (EventKoi's
     * sync_refund_to_local() status; still counted as sold). Returns null
     * for statuses EventKoi ignores (checkout-draft, trash, custom).
     */
    private function wc_order_ek_status( $wc_order ) {
        $map = [
            'completed'  => 'complete',
            'processing' => 'complete',
            'refunded'   => 'refunded',
            'cancelled'  => 'cancelled',
            'failed'     => 'failed',
            'on-hold'    => 'pending',
            'pending'    => 'pending',
        ];
        $status = $map[ (string) $wc_order->get_status() ] ?? null;
        if ( 'complete' === $status && (float) $wc_order->get_total_refunded() > 0 ) {
            $status = 'partially_refunded';
        }
        return $status;
    }

    /**
     * Deterministic 12-char check-in token for a TEC attendee (EventKoi's
     * human-friendly base32 alphabet). Determinism makes the composite
     * order_id stable across runs, so a re-run hits the same key instead of
     * inserting a second row (the v1.4.1 doubling root cause).
     */
    private function attendee_checkin_token( $attendee_id, $salt = 0 ) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $hash     = hash( 'sha256', 'ekti-tec-attendee:' . (int) $attendee_id . ( $salt ? ':' . (int) $salt : '' ), true );
        $code     = '';
        for ( $i = 0; $i < 12; $i++ ) {
            $code .= $alphabet[ ord( $hash[ $i ] ) % 32 ];
        }
        return $code;
    }

    /**
     * Link TEC WooCommerce attendees for ONE EventKoi event.
     *
     * @param int      $ek_event_id EventKoi event post ID.
     * @param array    $attendees   Rows from get_tec_attendees*() — all for one TEC event.
     * @param callable $resolve     fn( $product_id, $attendee_row ) => eventkoi ticket id (0 = none).
     * @param bool     $dry_run     Compute only, no writes.
     * @param array    $ops         Audit ops (by reference).
     * @return array {created, existing, orders, unpaid, ever_paid_by_ticket, by_status, notes, results}
     */
    private function link_woo_attendees( $ek_event_id, $attendees, $resolve, $dry_run, &$ops ) {
        global $wpdb;

        $table   = $this->ek_table( 'ticket_orders' );
        $summary = [
            'created'             => 0,
            'existing'            => 0,
            'orders'              => 0,
            'unpaid'              => 0,
            'ever_paid_by_ticket' => [],
            'by_status'           => [],
            'notes'               => [],
            'results'             => [],
            'multi_person_orders' => 0,
            'registration_fields' => [],
        ];
        $ever_paid  = [ 'complete', 'partially_refunded', 'refunded' ];
        // Step 17: tag rows with the instance EventKoi's checkout resolves
        // for this event, so per-instance inventory counts imported sales.
        $instance_ts = $this->ek_instance_ts_for_event( $ek_event_id );
        // A dry run (orders preview) must not persist the field registry.
        $frozen_before         = $this->registry_frozen;
        $this->registry_frozen = (bool) $dry_run;
        $counted    = [ 'complete', 'completed', 'succeeded', 'paid', 'partially_refunded' ];
        $no_ticket  = 0;
        $no_order   = 0;
        $odd_status = 0;

        // Group by WC order so each order is resolved (and its meta written) once.
        $by_order = [];
        foreach ( $attendees as $att ) {
            $by_order[ (int) $att['wc_order_id'] ][] = $att;
        }

        $touched_tickets = [];
        foreach ( $by_order as $wc_order_id => $group ) {
            $wc_order = $wc_order_id ? $this->get_wc_order( $wc_order_id ) : null;
            if ( ! $wc_order ) {
                $no_order += count( $group );
                foreach ( $group as $att ) {
                    $summary['results'][] = [ 'attendee_id' => $att['ID'], 'action' => 'skipped', 'reason' => 'No WooCommerce order #' . $wc_order_id ];
                }
                continue;
            }

            $status = $this->wc_order_ek_status( $wc_order );
            if ( null === $status ) {
                $odd_status += count( $group );
                foreach ( $group as $att ) {
                    $summary['results'][] = [ 'attendee_id' => $att['ID'], 'action' => 'skipped', 'reason' => 'WC order #' . $wc_order_id . ' status "' . $wc_order->get_status() . '" is not mapped by EventKoi' ];
                }
                continue;
            }

            $currency   = strtoupper( (string) $wc_order->get_currency() );
            $currency   = preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD';
            $date       = $wc_order->get_date_created();
            $created_at = $date ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' );
            $bill_name  = trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
            $bill_email = (string) $wc_order->get_billing_email();

            // Seat holders + registration answers (Event Tickets Plus
            // attendee meta). Rows keep the purchaser (EventKoi parity);
            // seat data goes to EventKoi checkout fields on the WC order.
            $seats = [];
            foreach ( $group as $att ) {
                $seats[ (int) $att['ID'] ] = $this->seat_registration( $att, $bill_name, $bill_email );
            }
            $seat_names = array_unique( array_filter( array_map( function ( $seat ) {
                return strtolower( $seat['name'] );
            }, $seats ) ) );
            if ( count( $seat_names ) > 1 ) {
                ++$summary['multi_person_orders'];
            }
            foreach ( $seats as $seat ) {
                foreach ( $seat['labels'] as $fk => $label ) {
                    $summary['registration_fields'][ $fk ] = $label;
                }
            }

            $items     = []; // ticket_id|unit_amount => meta ticket item (with codes).
            $new_rows  = []; // ticket_id => row ids inserted for this order.
            $order_new = 0;

            foreach ( $group as $att ) {
                $ticket_id = (int) call_user_func( $resolve, (int) $att['product_id'], $att );
                if ( ! $ticket_id ) {
                    ++$no_ticket;
                    $summary['results'][] = [ 'attendee_id' => $att['ID'], 'action' => 'error', 'reason' => 'No EventKoi ticket for product #' . $att['product_id'] ];
                    continue;
                }

                $token = $this->attendee_checkin_token( $att['ID'] );
                $key   = 'wc_' . $wc_order_id . ':' . $ticket_id . ':' . $token;
                $cents = (int) round( (float) $att['paid_price'] * 100 );

                $ikey = $ticket_id . '|' . $cents;
                if ( ! isset( $items[ $ikey ] ) ) {
                    $items[ $ikey ] = [
                        'ticket_id'   => $ticket_id,
                        'name'        => (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$this->ek_table( 'tickets' )} WHERE id = %d", $ticket_id ) ),
                        'description' => '',
                        'quantity'    => 0,
                        'unit_amount' => $cents,
                        'codes'       => [],
                    ];
                }
                ++$items[ $ikey ]['quantity'];
                $items[ $ikey ]['codes'][] = $token;

                $summary['by_status'][ $status ] = ( $summary['by_status'][ $status ] ?? 0 ) + 1;
                if ( in_array( $status, $ever_paid, true ) ) {
                    $summary['ever_paid_by_ticket'][ $ticket_id ] = ( $summary['ever_paid_by_ticket'][ $ticket_id ] ?? 0 ) + 1;
                }
                if ( ! in_array( $status, $counted, true ) ) {
                    ++$summary['unpaid'];
                }

                $exists = $dry_run ? null : $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %s LIMIT 1", $key ) );
                if ( $exists ) {
                    ++$summary['existing'];
                    $summary['results'][] = [ 'attendee_id' => $att['ID'], 'action' => 'skipped', 'reason' => 'Already imported', 'composite_key' => $key ];
                    $this->flag_attendee_imported( (int) $att['ID'], $ops );
                    continue;
                }

                $name  = trim( (string) $att['full_name'] ) !== '' ? (string) $att['full_name'] : $bill_name;
                $email = trim( (string) $att['email'] ) !== '' ? (string) $att['email'] : $bill_email;
                $name  = '' !== trim( $name ) ? $name : 'Imported attendee';

                if ( $dry_run ) {
                    ++$summary['created'];
                    ++$order_new;
                    $summary['results'][] = [
                        'attendee_id'   => $att['ID'],
                        'action'        => 'dry_run_create',
                        'name'          => $name,
                        'email'         => $email,
                        'ek_event_id'   => $ek_event_id,
                        'ek_ticket_id'  => $ticket_id,
                        'wc_order_id'   => $wc_order_id,
                        'composite_key' => $key,
                        'price'         => $att['paid_price'],
                        'status'        => $status,
                    ];
                    continue;
                }

                // A token clash with another order's row (astronomically
                // unlikely) gets a salted variant rather than a dup key.
                for ( $salt = 1; $salt < 5 && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE checkin_token = %s LIMIT 1", $token ) ); $salt++ ) {
                    $token = $this->attendee_checkin_token( $att['ID'], $salt );
                    $key   = 'wc_' . $wc_order_id . ':' . $ticket_id . ':' . $token;
                    $items[ $ikey ]['codes'][ count( $items[ $ikey ]['codes'] ) - 1 ] = $token;
                }

                $checked_in = in_array( $status, [ 'complete', 'partially_refunded' ], true ) && '1' === (string) $att['checked_in'];
                $unit_price = (float) $att['paid_price'];

                // Column set of Ticket_Order_Sync::sync_order_to_local() (codes path).
                $inserted = $wpdb->insert( $table, [
                    'event_id'          => (int) $ek_event_id,
                    'instance_ts'       => $instance_ts,
                    'ticket_id'         => $ticket_id,
                    'order_id'          => $key,
                    'customer_name'     => sanitize_text_field( $name ),
                    'customer_email'    => sanitize_email( $email ),
                    'quantity'          => 1,
                    'unit_price'        => $unit_price,
                    'total_amount'      => $unit_price,
                    'currency'          => $currency,
                    'payment_status'    => $status,
                    'payment_intent_id' => '',
                    'charge_id'         => '',
                    'checkin_token'     => $token,
                    'checked_in'        => $checked_in ? 1 : 0,
                    'checked_in_at'     => $checked_in ? $created_at : null,
                    'created_at'        => $created_at,
                    'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
                ] );

                if ( ! $inserted ) {
                    $summary['results'][] = [ 'attendee_id' => $att['ID'], 'action' => 'error', 'reason' => $wpdb->last_error ];
                    $this->log( "DB Error inserting attendee {$att['ID']}: " . $wpdb->last_error, 'ERROR' );
                    continue;
                }

                $row_id                       = (int) $wpdb->insert_id;
                $new_rows[ $ticket_id ][]     = $row_id;
                $touched_tickets[ $ticket_id ] = true;
                ++$summary['created'];
                ++$order_new;
                $this->flag_attendee_imported( (int) $att['ID'], $ops );

                $summary['results'][] = [
                    'attendee_id'        => $att['ID'],
                    'action'             => 'created',
                    'name'               => $name,
                    'composite_key'      => $key,
                    'status'             => $status,
                    'ek_ticket_order_id' => $row_id,
                ];
            }

            foreach ( $new_rows as $ticket_id => $row_ids ) {
                $ops[] = [
                    'type'             => 'insert_ticket_order',
                    'ticket_order_ids' => $row_ids,
                    'order_id'         => 'wc_' . $wc_order_id,
                    'order_row_id'     => 0,
                    'note_ids'         => [],
                    'ticket_id'        => (int) $ticket_id,
                ];
            }

            if ( $order_new > 0 ) {
                ++$summary['orders'];
                if ( ! $dry_run ) {
                    $this->link_wc_order_meta( $wc_order, $ek_event_id, array_values( $items ), $ops, $instance_ts, $seats );
                }
            }
        }

        // EventKoi's own recount (complete/completed/succeeded/paid/partially_refunded).
        if ( ! $dry_run && $touched_tickets ) {
            $recount_items = array_map( function ( $id ) {
                return [ 'ticket_id' => $id ];
            }, array_keys( $touched_tickets ) );
            if ( class_exists( '\\EventKoi\\Core\\Ticket_Order_Sync' ) ) {
                \EventKoi\Core\Ticket_Order_Sync::sync_quantity_sold( (int) $ek_event_id, $recount_items );
            }
        }

        if ( $no_ticket ) {
            $summary['notes'][] = $no_ticket . ' WooCommerce attendee(s) not imported: no EventKoi ticket for their product.';
        }
        if ( $no_order ) {
            $summary['notes'][] = $no_order . ' WooCommerce attendee(s) not imported: WooCommerce order not found.';
        }
        if ( $odd_status ) {
            $summary['notes'][] = $odd_status . ' WooCommerce attendee(s) not imported: order status not mapped by EventKoi.';
        }
        if ( $summary['unpaid'] ) {
            $summary['notes'][] = $summary['unpaid'] . ' WooCommerce attendee(s) imported with a non-sold status (refunded/failed/pending/cancelled) — not counted as sold.';
        }

        $this->registry_frozen = $frozen_before;
        return $summary;
    }


    /* ------------------------------------------------------------------
     * STEP 17 — ATTENDEE FIDELITY
     *
     * Imported ticket_orders rows are EventKoi's attendees. They carry the
     * WooCommerce purchaser (EventKoi's own checkout does the same), the
     * instance EventKoi's checkout resolves for the event (so per-instance
     * storefront inventory counts imported sales), and the Event Tickets
     * Plus seat holders + registration answers as EventKoi checkout field
     * values on the WC order (`_eventkoi_checkout_fields`).
     * ------------------------------------------------------------------ */

    /**
     * The instance timestamp EventKoi's checkout resolves for an event
     * (Tickets::resolve_checkout_instance_ts): a standard "selected" event
     * uses its first event day; continuous events and anything else use 0.
     * Recurring events need a per-occurrence match TEC does not give us
     * (none on the target data) and keep 0.
     */
    private function ek_instance_ts_for_event( $ek_id ) {
        if ( 'standard' !== (string) get_post_meta( $ek_id, 'date_type', true )
            || 'selected' !== (string) get_post_meta( $ek_id, 'standard_type', true ) ) {
            return 0;
        }
        $days = get_post_meta( $ek_id, 'event_days', true );
        if ( ! is_array( $days ) || empty( $days ) ) {
            return 0;
        }
        $first = reset( $days );
        $ts    = is_array( $first ) && ! empty( $first['start_date'] ) ? strtotime( (string) $first['start_date'] ) : 0;
        return $ts ? (int) $ts : 0;
    }

    /**
     * EventKoi checkout-field key for a TEC attendee-meta key. ET+ stores a
     * slug-less field (the consent checkbox) under "_" + md5.
     */
    private function tec_registration_field_key( $tec_key ) {
        $tec_key = (string) $tec_key;
        if ( preg_match( '/^_[0-9a-f]{32}$/', $tec_key ) ) {
            return 'tec_consent';
        }
        return substr( 'tec_' . str_replace( '-', '_', sanitize_key( $tec_key ) ), 0, 48 );
    }

    /**
     * ET+ field definitions for a ticket product: slug => [label, type].
     */
    private function tec_registration_field_defs( $product_id ) {
        static $cache = [];
        $product_id = (int) $product_id;
        if ( isset( $cache[ $product_id ] ) ) {
            return $cache[ $product_id ];
        }
        $defs = [];
        foreach ( (array) get_post_meta( $product_id, '_tribe_tickets_meta', true ) as $def ) {
            if ( ! is_array( $def ) ) {
                continue;
            }
            $slug  = (string) ( $def['slug'] ?? '' );
            $label = html_entity_decode( (string) ( $def['label'] ?? '' ), ENT_QUOTES, 'UTF-8' );
            if ( '' === $slug && 'checkbox' === ( $def['type'] ?? '' ) ) {
                $slug  = '__consent';
                $label = '' !== $label ? $label : 'Consent';
            }
            if ( '' !== $slug ) {
                $defs[ $slug ] = [ 'label' => '' !== $label ? $label : $slug, 'type' => (string) ( $def['type'] ?? 'text' ) ];
            }
        }
        return $cache[ $product_id ] = $defs;
    }

    /**
     * One seat's holder + registration answers from the TEC attendee row.
     * Name/email: ET+ attendee meta, else the TEC purchaser fields, else the
     * WC billing details.
     *
     * @return array{name:string, email:string, fields:array<string,string>}
     */
    private function seat_registration( $att, $bill_name, $bill_email ) {
        $meta = maybe_unserialize( (string) ( $att['ticket_meta'] ?? '' ) );
        $meta = is_array( $meta ) ? $meta : [];
        $decode = function ( $v ) {
            if ( is_array( $v ) ) {
                $v = implode( ', ', array_map( 'strval', $v ) );
            }
            return trim( html_entity_decode( wp_strip_all_tags( (string) $v ), ENT_QUOTES, 'UTF-8' ) );
        };

        $name  = trim( $decode( $meta['first-name'] ?? '' ) . ' ' . $decode( $meta['last-name'] ?? '' ) );
        $email = $decode( $meta['email'] ?? '' );
        if ( '' === $name ) {
            $name = '' !== trim( (string) $att['full_name'] ) ? trim( (string) $att['full_name'] ) : $bill_name;
        }
        if ( '' === $email ) {
            $email = '' !== trim( (string) $att['email'] ) ? trim( (string) $att['email'] ) : $bill_email;
        }

        $defs   = $this->tec_registration_field_defs( (int) $att['product_id'] );
        $fields = [];
        $labels = [];
        foreach ( $meta as $k => $v ) {
            if ( in_array( (string) $k, [ 'first-name', 'last-name', 'email' ], true ) || is_int( $k ) ) {
                continue;
            }
            $value = $decode( $v );
            if ( '' === $value ) {
                continue;
            }
            $key            = $this->tec_registration_field_key( $k );
            $fields[ $key ] = $value;
            $def_key        = preg_match( '/^_[0-9a-f]{32}$/', (string) $k ) ? '__consent' : (string) $k;
            $labels[ $key ] = $defs[ $def_key ]['label'] ?? ucwords( str_replace( [ '-', '_' ], ' ', (string) $k ) );
            if ( ! $this->registry_frozen ) {
                $this->register_checkout_field( $key, $labels[ $key ], $defs[ $def_key ]['type'] ?? 'text' );
            }
        }
        return [ 'name' => $name, 'email' => $email, 'fields' => $fields, 'labels' => $labels ];
    }

    /**
     * Remember a checkout field (persisted once per request at shutdown).
     */
    private function register_checkout_field( $key, $label, $tec_type ) {
        if ( null === $this->field_registry ) {
            $this->field_registry = (array) get_option( 'ekti_checkout_fields', [] );
            add_action( 'shutdown', [ $this, 'persist_checkout_field_registry' ] );
        }
        foreach ( $this->field_registry as $f ) {
            if ( ( $f['key'] ?? '' ) === $key ) {
                return;
            }
        }
        $type = in_array( $tec_type, [ 'email' ], true ) ? 'email' : ( 'telephone' === $tec_type ? 'tel' : ( 'textarea' === $tec_type || 'tec_comments' === $key ? 'textarea' : 'text' ) );
        $this->field_registry[] = [ 'key' => $key, 'label' => sanitize_text_field( $label ), 'type' => $type ];
        $this->field_registry_dirty = true;
    }

    /** @var array|null */
    private $field_registry = null;
    private $registry_frozen = false;
    private $field_registry_dirty = false;

    public function persist_checkout_field_registry() {
        if ( ! $this->field_registry_dirty ) {
            return;
        }
        // "Attendees" first, then fields in discovery order.
        $list = array_values( array_filter( $this->field_registry, function ( $f ) {
            return 'ekti_attendees' !== ( $f['key'] ?? '' );
        } ) );
        array_unshift( $list, [ 'key' => 'ekti_attendees', 'label' => 'Attendees', 'type' => 'textarea' ] );
        update_option( 'ekti_checkout_fields', $list, false );
        $this->field_registry_dirty = false;
    }

    /**
     * Merge seats into the WC order's seat registry and rebuild EventKoi's
     * checkout-field values from every seat on the order (multi-event orders
     * accumulate across events). Caller saves the order.
     */
    private function apply_seat_registrations( $wc_order, $seats, $ours ) {
        $all = $ours ? $wc_order->get_meta( '_ekti_seat_registrations' ) : [];
        $all = is_array( $all ) ? $all : [];
        foreach ( $seats as $attendee_id => $seat ) {
            unset( $seat['labels'] );
            $all[ (int) $attendee_id ] = $seat;
        }
        ksort( $all );
        $wc_order->update_meta_data( '_ekti_seat_registrations', $all );
        $wc_order->update_meta_data( '_eventkoi_checkout_fields', $this->checkout_values_from_seats( $all ) );
    }

    /**
     * EventKoi checkout-field values for an order: "Attendees" lists every
     * seat holder; other fields collapse to one value when all seats agree,
     * else "Name: value; Name: value".
     */
    private function checkout_values_from_seats( $seats ) {
        $values = [];
        $people = [];
        foreach ( $seats as $seat ) {
            $people[] = '' !== $seat['email'] ? $seat['name'] . ' <' . $seat['email'] . '>' : $seat['name'];
        }
        $values['ekti_attendees'] = implode( '; ', array_values( array_unique( array_filter( $people ) ) ) );

        $by_key = [];
        foreach ( $seats as $seat ) {
            foreach ( $seat['fields'] as $k => $v ) {
                $by_key[ $k ][] = [ $seat['name'], $v ];
            }
        }
        foreach ( $by_key as $k => $pairs ) {
            $distinct = array_unique( array_map( function ( $p ) {
                return $p[1];
            }, $pairs ) );
            $values[ $k ] = 1 === count( $distinct )
                ? reset( $distinct )
                : implode( '; ', array_map( function ( $p ) {
                    return $p[0] . ': ' . $p[1];
                }, $pairs ) );
        }
        return $values;
    }

    /**
     * Stamp EventKoi's order meta on a WC order (what attach_order_meta() +
     * on_payment_complete() leave behind) so EventKoi's status/refund hooks
     * keep the imported rows in sync. _eventkoi_synced = 'yes' is mandatory:
     * without it a later status change to completed re-enters
     * on_payment_complete() and emails the customer. Orders spanning several
     * events (one row group per event) append their ticket items; orders
     * EventKoi itself synced (no _ekti_wc_linked marker) are left alone.
     */
    private function link_wc_order_meta( $wc_order, $ek_event_id, $items, &$ops, $instance_ts = 0, $seats = [] ) {
        $keys = [
            '_eventkoi_event_id',
            '_eventkoi_instance_ts',
            '_eventkoi_event_title',
            '_eventkoi_ticket_items',
            '_eventkoi_master_checkin_code',
            '_eventkoi_synced',
            '_ekti_wc_linked',
            '_eventkoi_checkout_fields',
            '_ekti_seat_registrations',
        ];

        $ours = '1' === (string) $wc_order->get_meta( '_ekti_wc_linked' );
        if ( 'yes' === $wc_order->get_meta( '_eventkoi_synced' ) && ! $ours ) {
            return; // A genuine EventKoi checkout order — never rewrite it.
        }

        $old = [];
        foreach ( $keys as $k ) {
            $old[ $k ] = $wc_order->meta_exists( $k ) ? $wc_order->get_meta( $k ) : null;
        }

        $merged = [];
        if ( $ours ) {
            foreach ( (array) $wc_order->get_meta( '_eventkoi_ticket_items' ) as $it ) {
                if ( is_array( $it ) && ! empty( $it['ticket_id'] ) ) {
                    $merged[ (int) $it['ticket_id'] . '|' . (int) ( $it['unit_amount'] ?? 0 ) ] = $it;
                }
            }
        }
        foreach ( $items as $it ) {
            $k = (int) $it['ticket_id'] . '|' . (int) $it['unit_amount'];
            if ( isset( $merged[ $k ] ) ) {
                $codes                   = array_values( array_unique( array_merge( (array) ( $merged[ $k ]['codes'] ?? [] ), $it['codes'] ) ) );
                $merged[ $k ]['codes']    = $codes;
                $merged[ $k ]['quantity'] = count( $codes );
            } else {
                $merged[ $k ] = $it;
            }
        }
        $merged = array_values( $merged );

        $all_codes = [];
        foreach ( $merged as $it ) {
            foreach ( (array) $it['codes'] as $c ) {
                $all_codes[] = $c;
            }
        }
        // One seat reuses its own token (EventKoi's rule); otherwise a
        // deterministic group code derived from the order ID.
        $master = 1 === count( $all_codes ) ? $all_codes[0] : $this->attendee_checkin_token( 0, (int) $wc_order->get_id() );

        // First event wins the order-level pointer (EventKoi's model is one
        // event per order); every row keeps its own event_id.
        if ( ! $ours ) {
            $wc_order->update_meta_data( '_eventkoi_event_id', (int) $ek_event_id );
            $wc_order->update_meta_data( '_eventkoi_instance_ts', (int) $instance_ts );
            $wc_order->update_meta_data( '_eventkoi_event_title', get_the_title( $ek_event_id ) );
        }
        if ( $seats ) {
            $this->apply_seat_registrations( $wc_order, $seats, $ours );
        }
        $wc_order->update_meta_data( '_eventkoi_ticket_items', $merged );
        $wc_order->update_meta_data( '_eventkoi_master_checkin_code', $master );
        $wc_order->update_meta_data( '_eventkoi_synced', 'yes' );
        $wc_order->update_meta_data( '_ekti_wc_linked', '1' );
        $wc_order->save();

        $ops[] = [ 'type' => 'wc_order_meta', 'order' => (int) $wc_order->get_id(), 'old' => $old ];
    }

    /**
     * Flag a TEC attendee as imported (audited, no-op when already set).
     */
    private function flag_attendee_imported( $attendee_id, &$ops ) {
        $old = get_post_meta( $attendee_id, '_eventkoi_imported_from_tec', true );
        if ( '' !== (string) $old && '0' !== (string) $old ) {
            return;
        }
        update_post_meta( $attendee_id, '_eventkoi_imported_from_tec', '1' );
        $ops[] = [ 'type' => 'update_postmeta', 'post' => $attendee_id, 'key' => '_eventkoi_imported_from_tec', 'old' => $old ];
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

        $result = \EventKoi\Core\Ticket_Order_Sync::create_manual_order( (int) $event_id, (int) $ticket_id, $name, (string) $email, 1, $currency, $this->ek_instance_ts_for_event( $event_id ) );
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
     * (reset_stage_failures() clears it when a fresh run starts) or the state is undone — fixing
     * an event in TEC and running the sync again retries exactly the failed
     * set.
     *
     * Runs as the wizard's events stage: ops are tagged 'events', and
     * tickets and orders come from run_event_stage().
     *
     * @param int   $chunk         Events per request (default 20).
     * @param bool  $import_images Whether to reuse the TEC featured image.
     */
    private function run_event_sync( $chunk = 20, $import_images = true ) {
        $chunk         = max( 1, min( 100, (int) $chunk ) );
        $import_images = (bool) $import_images;

        $state  = get_option( 'ekti_event_sync_state', [] );
        $failed = ! is_array( $state ) || ! is_array( $state['failed'] ?? null )
            ? []
            : $state['failed'];

        $pending = $this->get_tec_event_import_ids();
        if ( ! empty( $failed ) ) {
            $failed_ids = array_map( 'absint', array_keys( $failed ) );
            $pending    = array_values( array_diff( $pending, $failed_ids ) );
        }
        $batch = array_slice( $pending, 0, $chunk );

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
            'woo_attendees' => 0,
            'woo_orders'   => 0,
            'woo_unpaid'   => 0,
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
                    'title'  => $this->plain_title( $tec_id ),
                    'status' => 'error',
                    'error'  => $message,
                ];
                $this->log( "Event Sync: TEC event {$tec_id} failed and was rolled back: {$message}", 'ERROR' );
                continue;
            }

            $this->audit_push_event_sync( $this->tag_ops( $event_ops, 'events' ) );

            ++$tally['imported'];
            $tally['tickets']      += $result['tickets'];
            $tally['cost_tickets'] += $result['cost_ticket'] ? 1 : 0;
            $tally['tc_attendees'] += $result['tc_attendees'];
            $tally['rsvp_rows']    += $result['rsvp_rows'];
            $tally['rsvp_merged']  += $result['rsvp_merged'];
            $tally['woo_attendees'] += $result['woo_attendees'];
            $tally['woo_orders']   += $result['woo_orders'];
            $tally['woo_unpaid']   += $result['woo_unpaid'];

            $results[] = [
                'tec_id'          => $result['tec_id'],
                'event_id'        => $result['event_id'],
                'title'           => html_entity_decode( (string) $result['title'], ENT_QUOTES, 'UTF-8' ),
                'status'          => $result['status'],
                'attendance_mode' => $result['attendance_mode'],
                'tickets'         => $result['tickets'],
                'tc_attendees'    => $result['tc_attendees'],
                'rsvp_rows'       => $result['rsvp_rows'],
                'rsvp_merged'     => $result['rsvp_merged'],
                'woo_attendees'   => $result['woo_attendees'],
                'woo_orders'      => $result['woo_orders'],
                'woo_unpaid'      => $result['woo_unpaid'],
                'taxonomies'      => $result['taxonomies'],
                'notes'           => $result['notes'],
            ];
        }

        // Re-derive the queue after the batch — the resumability source of truth.
        $remaining_ids = $this->get_tec_event_import_ids();
        if ( ! empty( $failed ) ) {
            $remaining_ids = array_values( array_diff( $remaining_ids, array_map( 'absint', array_keys( $failed ) ) ) );
        }
        $processed = $tally['imported'] + $tally['skipped'] + $tally['errors'];

        // Persist this run's failure memory.
        update_option( 'ekti_event_sync_state', [ 'failed' => $failed ], false );

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
            'failed_total' => count( $failed ),
            'done'         => 0 === count( $remaining_ids ),
            'results'      => $results,
        ];
    }

    /**
     * Adopt pre-existing native imports: flag the TEC WooCommerce attendee
     * posts behind natively-imported events with _eventkoi_imported_from_tec
     * so the orders stage will not double-import attendees EventKoi's own
     * importer already brought over. Audited (untagged) into the event-sync
     * ledger, so "Undo Last Event Sync" reverts it.
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
     * Undo native-import adoption and single-pass Event Sync runs from
     * earlier versions by replaying the untagged ops of the event-sync
     * ledger in reverse. Events those runs created are hard-deleted together
     * with their residual EventKoi rows; every other write is restored to
     * its pre-run value. Wizard stage ops (tagged) are kept: each stage has
     * its own undo.
     */
    private function undo_event_sync() {
        $audit = get_option( 'ekti_event_sync_audit', [] );
        $audit = is_array( $audit ) ? $audit : [];
        $untagged = [];
        $tagged   = [];
        foreach ( $audit as $op ) {
            if ( empty( $op['stage'] ) ) {
                $untagged[] = $op;
            } else {
                $tagged[] = $op;
            }
        }
        if ( ! $untagged ) {
            return [ 'error' => 'Nothing to undo: the ledger holds only wizard steps (undo those in the wizard).' ];
        }
        $counts = $this->replay_audit_ops( $untagged );
        if ( $tagged ) {
            update_option( 'ekti_event_sync_audit', $tagged, false );
        } else {
            delete_option( 'ekti_event_sync_audit' );
        }
        $this->log( 'Event sync undo complete (untagged ops). ' . wp_json_encode( $counts ) );
        return [ 'undone' => $counts ];
    }

    /**
     * Append audit ops to the event-sync ledger (separate from the taxonomy
     * and setup ledgers so undoing one step never replays another step's
     * writes).
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
     * STEP 16 — STAGED IMPORT (events → tickets → orders) + WIZARD
     *
     * The wizard runs Event Sync in three resumable stages with a review
     * stop after each. Stage progress lives on the EventKoi event as
     * `_ekti_stage_events` / `_ekti_stage_tickets` / `_ekti_stage_orders`
     * meta; each stage's queue is "previous marker present, own marker
     * absent", re-derived per chunk. Stage ops go into the shared
     * ekti_event_sync_audit ledger tagged with `stage`, so a stage can be
     * undone on its own (latest stage first) while "Undo Last Event Sync"
     * still replays everything.
     * ------------------------------------------------------------------ */

    private $stages = [ 'events', 'tickets', 'orders' ];

    /**
     * Post title as plain text (get_the_title() returns HTML entities such
     * as &#038;, which the admin JS would escape a second time).
     */
    private function plain_title( $post_id ) {
        return html_entity_decode( (string) get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
    }

    private function tag_ops( $ops, $stage ) {
        foreach ( $ops as $i => $op ) {
            $ops[ $i ]['stage'] = $stage;
        }
        return $ops;
    }

    /**
     * EventKoi event IDs waiting for the tickets or orders stage.
     */
    private function get_stage_queue( $stage ) {
        global $wpdb;
        $prev = 'tickets' === $stage ? '_ekti_stage_events' : '_ekti_stage_tickets';
        $own  = '_ekti_stage_' . $stage;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
             WHERE p.post_type = 'eventkoi_event'
               AND p.post_status NOT IN ('trash','auto-draft')
               AND b.meta_id IS NULL
             ORDER BY p.ID ASC",
            $prev,
            $own
        ) ) );
    }

    /**
     * EventKoi event IDs that completed a stage.
     */
    private function get_stage_done( $stage ) {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
             WHERE p.post_type = 'eventkoi_event'
               AND p.post_status NOT IN ('trash','auto-draft')
             ORDER BY p.ID ASC",
            '_ekti_stage_' . $stage
        ) ) );
    }

    private function get_stage_failures( $stage ) {
        if ( 'events' === $stage ) {
            $state = get_option( 'ekti_event_sync_state', [] );
            return is_array( $state['failed'] ?? null ) ? $state['failed'] : [];
        }
        $state = get_option( 'ekti_event_stage_state', [] );
        return is_array( $state[ $stage ]['failed'] ?? null ) ? $state[ $stage ]['failed'] : [];
    }

    private function reset_stage_failures( $stage ) {
        if ( 'events' === $stage ) {
            delete_option( 'ekti_event_sync_state' );
            return;
        }
        $state = get_option( 'ekti_event_stage_state', [] );
        $state = is_array( $state ) ? $state : [];
        unset( $state[ $stage ] );
        update_option( 'ekti_event_stage_state', $state, false );
    }

    /**
     * One chunk of the tickets or orders stage. Same contract as
     * run_event_sync(): per-event try/catch with op-replay rollback,
     * failure memory, 18 s time budget, queue re-derived after the chunk.
     */
    private function run_event_stage( $stage, $chunk = 15 ) {
        if ( ! in_array( $stage, [ 'tickets', 'orders' ], true ) ) {
            return [ 'error' => 'Invalid stage.' ];
        }
        $chunk  = max( 1, min( 100, (int) $chunk ) );
        $failed = $this->get_stage_failures( $stage );
        $queue  = array_values( array_diff( $this->get_stage_queue( $stage ), array_map( 'absint', array_keys( $failed ) ) ) );
        $batch  = array_slice( $queue, 0, $chunk );

        $tally = [
            'imported'      => 0,
            'errors'        => 0,
            'tickets'       => 0,
            'cost_tickets'  => 0,
            'bindings'      => 0,
            'tc_attendees'  => 0,
            'rsvp_rows'     => 0,
            'rsvp_merged'   => 0,
            'woo_attendees' => 0,
            'woo_orders'    => 0,
            'woo_unpaid'    => 0,
        ];
        $results    = [];
        $started_at = microtime( true );

        foreach ( $batch as $ek_id ) {
            if ( microtime( true ) - $started_at > 18 ) {
                break;
            }
            $tec_id = (int) get_post_meta( $ek_id, '_tec_import_source_id', true );
            $ops    = [];
            try {
                if ( ! $tec_id ) {
                    $result = new WP_Error( 'no_source', 'Event has no _tec_import_source_id marker.' );
                } elseif ( 'tickets' === $stage ) {
                    $result = $this->import_event_tickets_stage( $ek_id, $tec_id, $ops );
                } else {
                    $result = $this->import_event_orders_stage( $ek_id, $tec_id, $ops );
                }
            } catch ( Throwable $e ) {
                $result = new WP_Error( 'exception', $e->getMessage() );
            }

            if ( is_wp_error( $result ) ) {
                if ( $ops ) {
                    $this->replay_audit_ops( $ops );
                }
                ++$tally['errors'];
                $failed[ $ek_id ] = $result->get_error_message();
                $results[]        = [
                    'event_id' => $ek_id,
                    'tec_id'   => $tec_id,
                    'title'    => $this->plain_title( $ek_id ),
                    'status'   => 'error',
                    'error'    => $result->get_error_message(),
                ];
                $this->log( "Event Sync ({$stage} stage): event {$ek_id} failed and was rolled back: " . $result->get_error_message(), 'ERROR' );
                continue;
            }

            $this->audit_push_event_sync( $this->tag_ops( $ops, $stage ) );
            ++$tally['imported'];
            foreach ( [ 'tickets', 'bindings', 'tc_attendees', 'rsvp_rows', 'rsvp_merged', 'woo_attendees', 'woo_orders', 'woo_unpaid' ] as $k ) {
                $tally[ $k ] += (int) ( $result[ $k ] ?? 0 );
            }
            $tally['cost_tickets'] += ! empty( $result['cost_ticket'] ) ? 1 : 0;
            $results[] = array_merge( $result, [
                'event_id' => $ek_id,
                'tec_id'   => $tec_id,
                'title'    => $this->plain_title( $ek_id ),
                'status'   => 'ok',
            ] );
        }

        $state = get_option( 'ekti_event_stage_state', [] );
        $state = is_array( $state ) ? $state : [];
        $state[ $stage ] = [ 'failed' => $failed ];
        update_option( 'ekti_event_stage_state', $state, false );

        $remaining = array_values( array_diff( $this->get_stage_queue( $stage ), array_map( 'absint', array_keys( $failed ) ) ) );
        $processed = $tally['imported'] + $tally['errors'];
        $this->log( sprintf(
            'Event Sync (%s stage): processed %d (ok %d, errors %d) — %d remaining.',
            $stage, $processed, $tally['imported'], $tally['errors'], count( $remaining )
        ) );

        return [
            'processed'    => $processed,
            'tally'        => $tally,
            'remaining'    => count( $remaining ),
            'total'        => count( $queue ),
            'failed_total' => count( $failed ),
            'done'         => 0 === count( $remaining ),
            'results'      => $results,
        ];
    }

    /**
     * Undo one stage's ops (latest stage first), newest op first, within a
     * time budget. Returns done=false when ops remain — call again.
     */
    private function undo_event_stage( $stage, $budget = 20 ) {
        if ( ! in_array( $stage, $this->stages, true ) ) {
            return [ 'error' => 'Invalid stage.' ];
        }
        $audit = get_option( 'ekti_event_sync_audit', [] );
        $audit = is_array( $audit ) ? $audit : [];
        $later = array_slice( $this->stages, array_search( $stage, $this->stages, true ) + 1 );
        foreach ( $audit as $op ) {
            if ( in_array( $op['stage'] ?? '', $later, true ) ) {
                return [ 'error' => 'Undo the ' . $op['stage'] . ' stage first.' ];
            }
        }

        $idx = [];
        foreach ( $audit as $i => $op ) {
            if ( ( $op['stage'] ?? '' ) === $stage ) {
                $idx[] = $i;
            }
        }

        $counts  = [];
        $undone  = 0;
        $started = microtime( true );
        while ( $idx && microtime( true ) - $started < $budget ) {
            $i = array_pop( $idx );
            foreach ( $this->replay_audit_ops( [ $audit[ $i ] ] ) as $type => $n ) {
                $counts[ $type ] = ( $counts[ $type ] ?? 0 ) + $n;
            }
            unset( $audit[ $i ] );
            ++$undone;
            // Persist periodically so a PHP death mid-undo never replays an
            // op twice (keys are kept until the final reindex).
            if ( 0 === $undone % 250 ) {
                update_option( 'ekti_event_sync_audit', $audit, false );
            }
        }
        update_option( 'ekti_event_sync_audit', array_values( $audit ), false );

        if ( ! $idx ) {
            $this->reset_stage_failures( $stage );
            $this->wizard_unapprove_from( $stage );
        }
        $this->log( sprintf( 'Event Sync (%s stage) undo: %d op(s) replayed, %d remaining. %s', $stage, $undone, count( $idx ), wp_json_encode( $counts ) ) );

        return [
            'undone'    => $counts,
            'ops'       => $undone,
            'remaining' => count( $idx ),
            'done'      => empty( $idx ),
        ];
    }

    /**
     * Read-only plan for the tickets stage: per pending event, the ticket
     * rows the stage would write (native capacity model) plus flags.
     */
    private function preview_ticket_stage() {
        $queue  = $this->get_stage_queue( 'tickets' );
        $events = [];
        $counts = [
            'events'           => count( $queue ),
            'ticketed_events'  => 0,
            'tickets'          => 0,
            'woo'              => 0,
            'tc'               => 0,
            'rsvp_events'      => 0,
            'cost_tickets'     => 0,
            'unlimited'        => 0,
            'multi_product'    => 0,
            'inverted_windows' => 0,
            'unpriced'         => 0,
            'no_tickets'       => 0,
        ];
        $feature           = function_exists( 'eventkoi_is_tickets_feature_enabled' ) && eventkoi_is_tickets_feature_enabled();
        $checkout_currency = $this->es_get_checkout_currency();
        $woo_currency      = $this->es_woo_currency_code();
        $woo_ok            = '' !== $woo_currency && $woo_currency === $checkout_currency;
        $tc_ok             = $this->es_et_currency_code() === $checkout_currency;

        foreach ( $queue as $ek_id ) {
            $tec_id = (int) get_post_meta( $ek_id, '_tec_import_source_id', true );
            $row    = [
                'event_id' => $ek_id,
                'tec_id'   => $tec_id,
                'title'    => $this->plain_title( $ek_id ),
                'mode'     => 'none',
                'tickets'  => [],
                'flags'    => [],
            ];
            $rsvp = $feature ? $this->es_get_et_ticket_posts( 'tribe_rsvp_tickets', '_tribe_rsvp_for_event', $tec_id ) : [];
            $tc   = $feature ? $this->es_get_et_ticket_posts( 'tec_tc_ticket', '_tec_tickets_commerce_event', $tec_id ) : [];
            $woo  = $feature ? $this->es_get_et_ticket_posts( 'product', '_tribe_wooticket_for_event', $tec_id ) : [];

            if ( empty( $rsvp ) && empty( $tc ) && empty( $woo ) ) {
                $spec = $this->cost_ticket_spec(
                    get_post_meta( $tec_id, '_EventCost', true ),
                    get_post_meta( $tec_id, '_EventCurrencySymbol', true ),
                    get_post_meta( $tec_id, '_EventCurrencyCode', true )
                );
                if ( $spec ) {
                    $row['mode']      = 'tickets';
                    $row['tickets'][] = [
                        'name' => 'General admission', 'provider' => 'cost', 'price' => $spec['price'],
                        'capacity' => null, 'sold' => 0, 'available' => null, 'sale_start' => null, 'sale_end' => null,
                    ];
                    ++$counts['cost_tickets'];
                    ++$counts['tickets'];
                    ++$counts['ticketed_events'];
                    ++$counts['unlimited'];
                    $events[] = $row;
                } else {
                    ++$counts['no_tickets'];
                }
                continue;
            }

            if ( empty( $tc ) && empty( $woo ) ) {
                $row['mode'] = 'rsvp';
                ++$counts['rsvp_events'];
                $events[] = $row;
                continue;
            }

            $row['mode'] = 'tickets';
            ++$counts['ticketed_events'];
            if ( count( $woo ) + count( $tc ) > 1 ) {
                ++$counts['multi_product'];
                $row['flags'][] = 'multiple ticket types';
            }
            foreach ( array_merge( $tc, $rsvp, $woo ) as $t ) {
                $is_woo   = 'product' === $t->post_type;
                $price    = (float) get_post_meta( $t->ID, '_price', true );
                $unpriced = $price > 0 && ( $is_woo ? ! $woo_ok : ! $tc_ok );
                $capacity = get_post_meta( $t->ID, '_tribe_ticket_capacity', true );
                $capacity = ( '' === (string) $capacity || (int) $capacity < 0 ) ? null : (int) $capacity;
                $sold     = max( 0, (int) get_post_meta( $t->ID, 'total_sales', true ) );
                $start    = $this->es_et_ticket_datetime( $t->ID, '_ticket_start_date', '_ticket_start_time' );
                $end      = $this->es_et_ticket_datetime( $t->ID, '_ticket_end_date', '_ticket_end_time' );
                if ( null === $capacity ) {
                    ++$counts['unlimited'];
                }
                if ( $unpriced ) {
                    ++$counts['unpriced'];
                    $row['flags'][] = '"' . $t->post_title . '" imports without a price (currency mismatch)';
                }
                if ( $start && $end && strtotime( $end ) <= strtotime( $start ) ) {
                    ++$counts['inverted_windows'];
                    $row['flags'][] = '"' . $t->post_title . '" sale window ends before it starts';
                }
                ++$counts['tickets'];
                if ( $is_woo ) {
                    ++$counts['woo'];
                } elseif ( 'tec_tc_ticket' === $t->post_type ) {
                    ++$counts['tc'];
                }
                $row['tickets'][] = [
                    'name'       => $t->post_title,
                    'provider'   => $is_woo ? 'woo' : ( 'tec_tc_ticket' === $t->post_type ? 'tc' : 'rsvp' ),
                    'price'      => $unpriced ? 0 : $price,
                    'capacity'   => $capacity,
                    'sold'       => $sold,
                    // Native model: seats sold in TEC are deducted now and
                    // given back per imported paid attendee in the orders stage.
                    'available'  => null === $capacity ? null : max( $capacity - $sold, 0 ),
                    'sale_start' => $start ? $this->utc_to_local( $start ) : null,
                    'sale_end'   => $end ? $this->utc_to_local( $end ) : null,
                ];
            }
            $events[] = $row;
        }

        return [ 'counts' => $counts, 'events' => $events ];
    }

    /**
     * Read-only dry run of the orders stage over a slice of the pending
     * events (the JS loops over offsets and aggregates).
     */
    private function preview_orders_stage( $offset = 0, $limit = 40 ) {
        global $wpdb;
        $queue = $this->get_stage_queue( 'orders' );
        $slice = array_slice( $queue, (int) $offset, max( 1, (int) $limit ) );

        $agg = [
            'events'       => 0,
            'woo_rows'     => 0,
            'woo_orders'   => 0,
            'woo_unpaid'   => 0,
            'by_status'    => [],
            'tc_attendees' => 0,
            'rsvp_rows'    => 0,
            'rsvp_merged'  => 0,
            'not_linked'   => 0,
            'multi_person_orders' => 0,
            'fields'       => [],
        ];
        $rows = [];
        foreach ( $slice as $ek_id ) {
            $tec_id     = (int) get_post_meta( $ek_id, '_tec_import_source_id', true );
            $et_tickets = get_post_meta( $ek_id, '_ekti_stage_tickets', true );
            if ( ! $tec_id || ! is_array( $et_tickets ) ) {
                continue;
            }
            ++$agg['events'];
            $row = [
                'event_id'  => $ek_id,
                'tec_id'    => $tec_id,
                'title'     => $this->plain_title( $ek_id ),
                'woo_rows'  => 0,
                'orders'    => 0,
                'by_status' => [],
                'notes'     => [],
                'other'     => '',
            ];
            if ( ! $et_tickets['found'] ) {
                continue;
            }
            if ( 'rsvp' === $et_tickets['attendance_mode'] ) {
                $emails = [];
                foreach ( $this->es_get_et_ticket_posts( 'tribe_rsvp_attendees', '_tribe_rsvp_event', $tec_id ) as $a ) {
                    $e = strtolower( sanitize_email( (string) get_post_meta( $a->ID, '_tribe_rsvp_email', true ) ) );
                    if ( '' !== $e ) {
                        $emails[] = $e;
                    }
                }
                $unique              = count( array_unique( $emails ) );
                $agg['rsvp_rows']   += $unique;
                $agg['rsvp_merged'] += count( $emails ) - $unique;
                $row['other']        = $unique . ' RSVP row(s)' . ( count( $emails ) > $unique ? ', ' . ( count( $emails ) - $unique ) . ' duplicate(s) merged' : '' );
                $rows[]              = $row;
                continue;
            }
            $tc = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID AND ev.meta_key = '_tec_tickets_commerce_event' AND ev.meta_value = %s
                 INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_tec_tickets_commerce_status' AND st.meta_value = 'completed'
                 WHERE p.post_type = 'tec_tc_attendee'",
                (string) $tec_id
            ) );
            $agg['tc_attendees'] += $tc;
            if ( $tc ) {
                $row['other'] = $tc . ' Tickets Commerce attendee(s)';
            }

            $attendees = $this->get_tec_attendees_for_event( $tec_id );
            if ( $attendees ) {
                $map      = $et_tickets['ticket_id_map'];
                $resolver = function ( $product_id ) use ( $map ) {
                    return (int) ( $map[ (int) $product_id ] ?? 0 );
                };
                $ops = [];
                $dry = $this->link_woo_attendees( $ek_id, $attendees, $resolver, true, $ops );
                $row['woo_rows']   = $dry['created'];
                $row['orders']     = $dry['orders'];
                $row['by_status']  = $dry['by_status'];
                $row['notes']      = array_values( array_filter( $dry['notes'], function ( $n ) {
                    return false === strpos( $n, 'non-sold status' );
                } ) );
                $agg['woo_rows']   += $dry['created'];
                $agg['woo_orders'] += $dry['orders'];
                $agg['woo_unpaid'] += $dry['unpaid'];
                $agg['not_linked'] += count( $attendees ) - $dry['created'] - $dry['existing'];
                $agg['multi_person_orders'] += $dry['multi_person_orders'];
                foreach ( $dry['registration_fields'] as $fk => $label ) {
                    $agg['fields'][ $fk ] = $label;
                }
                foreach ( $dry['by_status'] as $s => $n ) {
                    $agg['by_status'][ $s ] = ( $agg['by_status'][ $s ] ?? 0 ) + $n;
                }
            }
            if ( $row['woo_rows'] || $row['other'] || $row['notes'] ) {
                $rows[] = $row;
            }
        }

        $out = [
            'total'  => count( $queue ),
            'offset' => (int) $offset,
            'next'   => (int) $offset + count( $slice ),
            'done'   => (int) $offset + count( $slice ) >= count( $queue ),
            'agg'    => $agg,
            'rows'   => $rows,
        ];

        if ( 0 === (int) $offset ) {
            // Report-only context for the review: attendees on TEC events
            // that no longer exist, and WC orders spanning several events.
            $out['orphan_attendees'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} a
                 INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = a.ID AND ev.meta_key = '_tribe_wooticket_event'
                 LEFT JOIN {$wpdb->posts} e ON e.ID = ev.meta_value AND e.post_type = 'tribe_events'
                  AND e.post_status NOT IN ('trash','auto-draft')
                 WHERE a.post_type = 'tribe_wooticket' AND a.post_status = 'publish' AND e.ID IS NULL"
            );
            $out['multi_event_orders'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM (
                   SELECT o.meta_value FROM {$wpdb->postmeta} o
                   INNER JOIN {$wpdb->posts} a ON a.ID = o.post_id AND a.post_type = 'tribe_wooticket' AND a.post_status = 'publish'
                   INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = o.post_id AND ev.meta_key = '_tribe_wooticket_event'
                   WHERE o.meta_key = '_tribe_wooticket_order'
                   GROUP BY o.meta_value
                   HAVING COUNT(DISTINCT ev.meta_value) > 1
                 ) x"
            );
        }
        return $out;
    }

    /**
     * Post-stage verification shown at each review stop.
     */
    private function verify_stage( $stage ) {
        global $wpdb;
        $done     = $this->get_stage_done( $stage );
        $failures = $this->get_stage_failures( $stage );
        $failed   = [];
        foreach ( $failures as $id => $msg ) {
            $failed[] = [ 'id' => (int) $id, 'title' => $this->plain_title( (int) $id ), 'error' => $msg ];
        }
        $remaining = 'events' === $stage
            ? count( array_diff( $this->get_tec_event_import_ids(), array_map( 'absint', array_keys( $failures ) ) ) )
            : count( array_diff( $this->get_stage_queue( $stage ), array_map( 'absint', array_keys( $failures ) ) ) );

        $out = [
            'stage'     => $stage,
            'done'      => count( $done ),
            'remaining' => $remaining,
            'failed'    => $failed,
            'checks'    => [],
            'mismatches' => [],
            'ok'        => true,
        ];
        $in = $done ? implode( ',', $done ) : '0';

        if ( 'events' === $stage ) {
            $out['by_status'] = [];
            foreach ( $wpdb->get_results( "SELECT post_status, COUNT(*) AS n FROM {$wpdb->posts} WHERE ID IN ({$in}) GROUP BY post_status", ARRAY_A ) as $r ) {
                $out['by_status'][ $r['post_status'] ] = (int) $r['n'];
            }
            foreach ( [ 'locations', 'instructors' ] as $type ) {
                $taxonomy = $this->get_taxonomy_for_type( $type );
                $out['checks'][] = [
                    'label' => 'Events with ' . ( 'locations' === $type ? 'a location' : 'an instructor' ) . ' term',
                    'value' => $taxonomy ? (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr
                         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                         WHERE tt.taxonomy = %s AND tr.object_id IN ({$in})",
                        $taxonomy
                    ) ) : 'taxonomy not set up',
                ];
            }
            $out['checks'][] = [
                'label' => 'Events in EventKoi\'s upcoming index',
                'value' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT event_id) FROM {$this->ek_table( 'upcoming_index' )} WHERE event_id IN ({$in})" ),
            ];
            $out['samples'] = [];
            // Spread the spot-check across the run (recurring classes
            // cluster by ID, so the first five are often one title).
            $picks = [];
            $step  = max( 1, (int) floor( count( $done ) / 5 ) );
            for ( $k = 0; $k < count( $done ) && count( $picks ) < 5; $k += $step ) {
                $picks[] = $done[ $k ];
            }
            foreach ( $picks as $id ) {
                $out['samples'][] = [ 'id' => $id, 'title' => $this->plain_title( $id ), 'url' => get_permalink( $id ) ];
            }
            return $out;
        }

        if ( 'tickets' === $stage ) {
            $expected = 0;
            $actual   = 0;
            $bindings = 0;
            foreach ( $done as $ek_id ) {
                $summary = get_post_meta( $ek_id, '_ekti_stage_tickets', true );
                $want    = is_array( $summary ) ? (int) $summary['imported'] + ( ! empty( $summary['cost_ticket'] ) ? 1 : 0 ) : 0;
                $have    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->ek_table( 'tickets' )} WHERE event_id = %d", $ek_id ) );
                $expected += $want;
                $actual   += $have;
                $bindings += is_array( $summary ) ? (int) $summary['bindings'] : 0;
                if ( $want !== $have && count( $out['mismatches'] ) < 100 ) {
                    $out['mismatches'][] = [ 'id' => $ek_id, 'title' => $this->plain_title( $ek_id ), 'detail' => "expected {$want} ticket(s), found {$have}" ];
                }
            }
            $out['checks'][] = [ 'label' => 'Ticket rows (expected / found)', 'value' => "{$expected} / {$actual}" ];
            $out['checks'][] = [ 'label' => 'WooCommerce product bindings', 'value' => $bindings ];
            $out['checks'][] = [
                'label' => 'Unlimited-capacity tickets',
                'value' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->ek_table( 'tickets' )} WHERE event_id IN ({$in}) AND quantity_available IS NULL" ),
            ];
            $out['ok'] = $expected === $actual;
            return $out;
        }

        // Orders: Step 15 acceptance, live.
        $counted   = "'complete','completed','succeeded','paid','partially_refunded'";
        $ever_paid = "'complete','partially_refunded','refunded'";
        $tickets_checked = 0;
        $sold_total      = 0;
        $rows_total      = 0;
        foreach ( $done as $ek_id ) {
            $summary = get_post_meta( $ek_id, '_ekti_stage_tickets', true );
            $tec_id  = (int) get_post_meta( $ek_id, '_tec_import_source_id', true );
            if ( ! is_array( $summary ) || empty( $summary['ticket_id_map'] ) ) {
                continue;
            }
            foreach ( $summary['ticket_id_map'] as $product_id => $ticket_id ) {
                if ( 'product' !== get_post_type( (int) $product_id ) ) {
                    continue;
                }
                ++$tickets_checked;
                $t = $wpdb->get_row( $wpdb->prepare( "SELECT quantity_available, quantity_sold FROM {$this->ek_table( 'tickets' )} WHERE id = %d", (int) $ticket_id ), ARRAY_A );
                $tec_rows = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID AND ev.meta_key = '_tribe_wooticket_event' AND ev.meta_value = %s
                     INNER JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_tribe_wooticket_product' AND pr.meta_value = %s
                     WHERE p.post_type = 'tribe_wooticket' AND p.post_status = 'publish'",
                    (string) $tec_id,
                    (string) (int) $product_id
                ) );
                $ek_rows = $wpdb->get_row( $wpdb->prepare(
                    "SELECT COUNT(*) AS n,
                            SUM( payment_status IN ({$counted}) ) AS sold,
                            SUM( payment_status IN ({$ever_paid}) ) AS ever_paid
                     FROM {$this->ek_table( 'ticket_orders' )}
                     WHERE ticket_id = %d AND order_id LIKE 'wc\\_%%'",
                    (int) $ticket_id
                ), ARRAY_A );
                $n         = (int) $ek_rows['n'];
                $sold_rows = (int) $ek_rows['sold'];
                $sold_total += (int) ( $t['quantity_sold'] ?? 0 );
                $rows_total += $n;

                $problems = [];
                if ( ! $t ) {
                    $problems[] = 'ticket row missing';
                } else {
                    if ( (int) $t['quantity_sold'] !== $sold_rows ) {
                        $problems[] = "quantity_sold {$t['quantity_sold']} ≠ sold rows {$sold_rows}";
                    }
                    $capacity = get_post_meta( (int) $product_id, '_tribe_ticket_capacity', true );
                    if ( '' !== (string) $capacity && (int) $capacity >= 0 ) {
                        $total_sales = max( 0, (int) get_post_meta( (int) $product_id, 'total_sales', true ) );
                        $deducted    = min( (int) $capacity, $total_sales );
                        $expected    = max( (int) $capacity - $total_sales, 0 ) + min( $deducted, (int) $ek_rows['ever_paid'] );
                        if ( (int) $t['quantity_available'] !== $expected ) {
                            $problems[] = "quantity_available {$t['quantity_available']} ≠ expected {$expected}";
                        }
                    }
                }
                if ( $n !== $tec_rows ) {
                    $problems[] = "{$n} EventKoi row(s) for {$tec_rows} TEC attendee(s)";
                }
                if ( $problems && count( $out['mismatches'] ) < 100 ) {
                    $out['mismatches'][] = [
                        'id'     => $ek_id,
                        'title'  => $this->plain_title( $ek_id ) . ' — ' . $this->plain_title( (int) $product_id ),
                        'detail' => implode( '; ', $problems ),
                    ];
                }
            }
        }
        $dup_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT order_id FROM {$this->ek_table( 'ticket_orders' )} GROUP BY order_id HAVING COUNT(*) > 1) x" );
        $dup_tokens = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT checkin_token FROM {$this->ek_table( 'ticket_orders' )} WHERE checkin_token IS NOT NULL AND checkin_token != '' GROUP BY checkin_token HAVING COUNT(*) > 1) x" );
        $wc_charges = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->ek_table( 'charges' )} WHERE gateway = 'woocommerce' OR charge_id LIKE 'wc\\_charge\\_%'" );
        $status_rows = $wpdb->get_results(
            "SELECT payment_status, COUNT(*) AS n FROM {$this->ek_table( 'ticket_orders' )} WHERE event_id IN ({$in}) GROUP BY payment_status",
            ARRAY_A
        );
        $by_status = [];
        foreach ( $status_rows as $r ) {
            $by_status[ $r['payment_status'] ] = (int) $r['n'];
        }
        $out['by_status'] = $by_status;
        $out['checks'][]  = [ 'label' => 'WooCommerce tickets checked', 'value' => $tickets_checked ];
        $out['checks'][]  = [ 'label' => 'WC-linked attendee rows', 'value' => $rows_total ];
        $out['checks'][]  = [ 'label' => 'Seats counted as sold', 'value' => $sold_total ];
        $out['checks'][]  = [ 'label' => 'Linked WooCommerce orders', 'value' => (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT SUBSTRING_INDEX(order_id, ':', 1)) FROM {$this->ek_table( 'ticket_orders' )} WHERE order_id LIKE 'wc\\_%' AND event_id IN ({$in})"
        ) ];
        $out['checks'][]  = [ 'label' => 'Duplicate order IDs', 'value' => $dup_orders ];
        $out['checks'][]  = [ 'label' => 'Duplicate check-in tokens', 'value' => $dup_tokens ];
        $out['checks'][]  = [ 'label' => 'eventkoi_charges rows for WooCommerce', 'value' => $wc_charges ];
        $dashboard_ok = $this->verify_dashboard_counts( $done, $out );
        $attendees_ok = $this->verify_attendees( $done, $out );
        $out['ok']    = empty( $out['mismatches'] ) && $dashboard_ok && $attendees_ok && 0 === $dup_orders && 0 === $dup_tokens && 0 === $wc_charges;

        $state                   = $this->get_wizard_state();
        $state['verified'][ $stage ] = [ 'ok' => $out['ok'], 'at' => time() ];
        update_option( 'ekti_wizard_state', $state, false );
        return $out;
    }


    /**
     * Step 18: EventKoi's events list counts sold seats only for the
     * current checkout gateway and currency (Events::get_events(),
     * "RSVPs/Tickets" column). Re-run that rule per imported event and
     * compare it with every sold row imported, so a checkout-method
     * mismatch shows up here instead of as "0 tickets" in the dashboard.
     */
    private function verify_dashboard_counts( $done, &$out ) {
        global $wpdb;
        if ( empty( $done ) || ! class_exists( '\\EventKoi\\Core\\Settings' ) ) {
            return true;
        }
        $settings = \EventKoi\Core\Settings::get();
        $method   = $settings['ticket_checkout_method'] ?? 'stripe';
        $currency = ( 'woocommerce' === $method && function_exists( 'get_woocommerce_currency' ) )
            ? strtoupper( get_woocommerce_currency() )
            : strtoupper( (string) ( $settings['currency'] ?? '' ) );
        $gateway  = 'woocommerce' === $method ? "order_id LIKE 'wc\\_%'" : "order_id NOT LIKE 'wc\\_%'";
        $cur_sql  = '' !== $currency ? $wpdb->prepare( ' AND UPPER(currency) = %s', $currency ) : '';
        $counted  = "payment_status IN ('complete','completed','succeeded','paid','partially_refunded')";
        $in       = implode( ',', array_map( 'intval', $done ) );

        $shown = [];
        foreach ( $wpdb->get_results( "SELECT event_id, SUM(quantity) AS n FROM {$this->ek_table( 'ticket_orders' )} WHERE event_id IN ({$in}) AND {$counted} AND {$gateway}{$cur_sql} GROUP BY event_id", ARRAY_A ) as $r ) {
            $shown[ (int) $r['event_id'] ] = (int) $r['n'];
        }
        $sold = [];
        foreach ( $wpdb->get_results( "SELECT event_id, SUM(quantity) AS n FROM {$this->ek_table( 'ticket_orders' )} WHERE event_id IN ({$in}) AND {$counted} GROUP BY event_id", ARRAY_A ) as $r ) {
            $sold[ (int) $r['event_id'] ] = (int) $r['n'];
        }

        $bad = 0;
        foreach ( $sold as $ek_id => $n ) {
            $listed = $shown[ $ek_id ] ?? 0;
            if ( $listed === $n ) {
                continue;
            }
            ++$bad;
            if ( count( $out['mismatches'] ) < 100 ) {
                $out['mismatches'][] = [
                    'id'     => $ek_id,
                    'title'  => $this->plain_title( $ek_id ),
                    'detail' => "EventKoi's events list shows {$listed} sold; {$n} sold seat(s) imported",
                ];
            }
        }
        $out['checks'][] = [
            'label' => 'Events list sold counts (checkout: ' . ( $settings['ticket_checkout_method'] ?? 'not set' ) . ')',
            'value' => ( count( $sold ) - $bad ) . ' / ' . count( $sold ) . ' events match',
        ];
        return 0 === $bad;
    }

    /* Step 17 — repair, field plugin, checks ---------------------------- */

    /**
     * Imported events whose attendee rows predate the Step 17 writer.
     */
    private function get_attendee_repair_queue() {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT o.event_id FROM {$this->ek_table( 'ticket_orders' )} o
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = o.event_id AND m.meta_key = '_tec_import_source_id'
             LEFT JOIN {$wpdb->postmeta} v ON v.post_id = o.event_id AND v.meta_key = '_ekti_attendee_v' AND v.meta_value = %s
             WHERE v.meta_id IS NULL
             ORDER BY o.event_id ASC",
            self::ATTENDEE_VERSION
        ) ) );
    }

    /**
     * Bring one imported event's attendees up to the Step 17 writer:
     * re-tag rows with EventKoi's instance and rebuild the WC orders' seat
     * registrations / checkout-field values. Audited (orders stage).
     */
    private function repair_event_attendees( $ek_id, &$ops ) {
        global $wpdb;
        $table    = $this->ek_table( 'ticket_orders' );
        $instance = $this->ek_instance_ts_for_event( $ek_id );
        $out      = [ 'rows' => 0, 'orders' => 0 ];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, instance_ts FROM {$table} WHERE event_id = %d AND instance_ts != %d",
            $ek_id,
            $instance
        ), ARRAY_A );
        $by_old = [];
        foreach ( $rows as $r ) {
            $by_old[ (int) $r['instance_ts'] ][] = (int) $r['id'];
        }
        foreach ( $by_old as $old => $ids ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET instance_ts = %d WHERE id IN (" . implode( ',', $ids ) . ')',
                $instance
            ) );
            $ops[]       = [ 'type' => 'update_ticket_orders_instance', 'ids' => $ids, 'old' => $old ];
            $out['rows'] += count( $ids );
        }

        $tec_id = (int) get_post_meta( $ek_id, '_tec_import_source_id', true );
        $groups = [];
        foreach ( $this->get_tec_attendees_for_event( $tec_id ) as $att ) {
            $groups[ (int) $att['wc_order_id'] ][] = $att;
        }
        foreach ( $groups as $wc_order_id => $group ) {
            $wc_order = $wc_order_id ? $this->get_wc_order( $wc_order_id ) : null;
            if ( ! $wc_order || '1' !== (string) $wc_order->get_meta( '_ekti_wc_linked' ) ) {
                continue; // Not linked by this importer — never touch it.
            }
            $keys = [ '_eventkoi_instance_ts', '_eventkoi_checkout_fields', '_ekti_seat_registrations' ];
            $old  = [];
            foreach ( $keys as $k ) {
                $old[ $k ] = $wc_order->meta_exists( $k ) ? $wc_order->get_meta( $k ) : null;
            }
            $bill_name  = trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
            $bill_email = (string) $wc_order->get_billing_email();
            $seats      = [];
            foreach ( $group as $att ) {
                $seats[ (int) $att['ID'] ] = $this->seat_registration( $att, $bill_name, $bill_email );
            }
            if ( (int) $wc_order->get_meta( '_eventkoi_event_id' ) === (int) $ek_id ) {
                $wc_order->update_meta_data( '_eventkoi_instance_ts', $instance );
            }
            $this->apply_seat_registrations( $wc_order, $seats, true );
            $wc_order->save();
            $ops[] = [ 'type' => 'wc_order_meta', 'order' => (int) $wc_order_id, 'old' => $old ];
            ++$out['orders'];
        }

        $this->set_event_meta_audited( $ek_id, '_ekti_attendee_v', self::ATTENDEE_VERSION, $ops );
        return $out;
    }

    /**
     * One time-budgeted chunk of the attendee repair.
     */
    private function run_attendee_repair( $budget = 18 ) {
        $queue   = $this->get_attendee_repair_queue();
        $total   = count( $queue );
        $done    = 0;
        $rows    = 0;
        $orders  = 0;
        $started = microtime( true );
        foreach ( $queue as $ek_id ) {
            if ( microtime( true ) - $started > $budget ) {
                break;
            }
            $ops = [];
            try {
                $r = $this->repair_event_attendees( $ek_id, $ops );
            } catch ( Throwable $e ) {
                if ( $ops ) {
                    $this->replay_audit_ops( $ops );
                }
                $this->log( "Attendee repair: event {$ek_id} failed and was rolled back: " . $e->getMessage(), 'ERROR' );
                return [ 'error' => 'Event #' . $ek_id . ': ' . $e->getMessage() ];
            }
            $this->audit_push_event_sync( $this->tag_ops( $ops, 'orders' ) );
            ++$done;
            $rows   += $r['rows'];
            $orders += $r['orders'];
        }
        $remaining = count( $this->get_attendee_repair_queue() );
        $this->log( "Attendee repair: {$done} event(s), {$rows} row(s) re-tagged, {$orders} order(s) updated — {$remaining} remaining." );
        return [ 'processed' => $done, 'rows' => $rows, 'orders' => $orders, 'remaining' => $remaining, 'total' => $total, 'done' => 0 === $remaining ];
    }

    private function attendee_fields_plugin_path() {
        return WPMU_PLUGIN_DIR . '/ekti-attendee-fields.php';
    }

    /**
     * Install the tiny must-use plugin that registers the imported attendee
     * fields with EventKoi (`eventkoi_checkout_fields`). Registration must
     * outlive this importer, and must never reach the storefront checkout,
     * so the filter only answers for imported events, in wp-admin page loads
     * and EventKoi's attendee-export / order-detail REST routes.
     */
    private function install_attendee_fields_plugin() {
        $fields = get_option( 'ekti_checkout_fields', [] );
        if ( empty( $fields ) ) {
            return new WP_Error( 'ekti_no_fields', 'No attendee fields recorded yet. Run the order matching step (or the attendee repair) first.' );
        }
        if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
            return new WP_Error( 'ekti_no_mu_dir', 'Could not create ' . WPMU_PLUGIN_DIR . '.' );
        }
        $code = <<<'PHP'
<?php
/**
 * Plugin Name: EventKoi — Imported TEC Attendee Fields
 * Description: Shows the attendee details imported from The Events Calendar (seat holders, phone, ZIP, …) as EventKoi checkout fields in the admin attendee views and CSV export. Installed by EventKoi Tickets Importer; safe to delete (the stored values stay on the orders).
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'eventkoi_checkout_fields',
	static function ( $fields, $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id || '' === (string) get_post_meta( $event_id, '_tec_import_source_id', true ) ) {
			return $fields;
		}
		// Never on the storefront checkout: admin screens and EventKoi's
		// attendee export / order detail routes only.
		$route   = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? untrailingslashit( (string) $GLOBALS['wp']->query_vars['rest_route'] ) : '';
		$allowed = in_array( $route, array( '/eventkoi/v1/tickets/attendees/export', '/eventkoi/v1/tickets/orders/get' ), true );
		if ( ! $allowed && ! ( is_admin() && ! wp_doing_ajax() ) ) {
			return $fields;
		}
		foreach ( (array) get_option( 'ekti_checkout_fields', array() ) as $field ) {
			if ( empty( $field['key'] ) ) {
				continue;
			}
			$fields[] = array(
				'key'      => (string) $field['key'],
				'label'    => (string) ( $field['label'] ?? $field['key'] ),
				'type'     => (string) ( $field['type'] ?? 'text' ),
				'required' => false,
			);
		}
		return $fields;
	},
	10,
	2
);
PHP;
        if ( false === file_put_contents( $this->attendee_fields_plugin_path(), $code, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            return new WP_Error( 'ekti_write_failed', 'Could not write ' . $this->attendee_fields_plugin_path() . '.' );
        }
        $this->log( 'Attendee fields plugin installed (' . count( $fields ) . ' field(s)).' );
        return [ 'installed' => true, 'fields' => count( $fields ) ];
    }

    private function remove_attendee_fields_plugin() {
        $path = $this->attendee_fields_plugin_path();
        if ( file_exists( $path ) && false !== strpos( (string) file_get_contents( $path ), 'Installed by EventKoi Tickets Importer' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            wp_delete_file( $path );
            $this->log( 'Attendee fields plugin removed.' );
            return [ 'removed' => true ];
        }
        return [ 'removed' => false ];
    }

    private function attendee_fields_status() {
        $fields = (array) get_option( 'ekti_checkout_fields', [] );
        return [
            'installed' => file_exists( $this->attendee_fields_plugin_path() ),
            'fields'    => array_map( function ( $f ) {
                return (string) ( $f['label'] ?? $f['key'] );
            }, $fields ),
            'repair'    => count( $this->get_attendee_repair_queue() ),
        ];
    }

    /**
     * Storefront + attendee checks for the orders verification: rows carry
     * EventKoi's instance, EventKoi's public ticket payload counts imported
     * sales on upcoming events, and linked orders carry seat registrations.
     */
    private function verify_attendees( $done, &$out ) {
        global $wpdb;
        $table      = $this->ek_table( 'ticket_orders' );
        $counted    = "'complete','completed','succeeded','paid','partially_refunded'";
        $bad_rows   = 0;
        $upcoming   = 0;
        $store_bad  = 0;
        $now        = time();
        foreach ( $done as $ek_id ) {
            $instance = $this->ek_instance_ts_for_event( $ek_id );
            $bad      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND instance_ts != %d", $ek_id, $instance ) );
            $bad_rows += $bad;
            if ( $bad && count( $out['mismatches'] ) < 100 ) {
                $out['mismatches'][] = [ 'id' => $ek_id, 'title' => $this->plain_title( $ek_id ), 'detail' => "{$bad} attendee row(s) not tagged with EventKoi's instance {$instance}" ];
            }

            if ( (int) get_post_meta( $ek_id, 'start_timestamp', true ) <= $now || ! class_exists( '\\EventKoi\\API\\Tickets' ) ) {
                continue;
            }
            $sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND payment_status IN ({$counted})", $ek_id ) );
            if ( ! $sold ) {
                continue;
            }
            ++$upcoming;
            $req = new WP_REST_Request( 'GET', '/eventkoi/v1/events/' . $ek_id . '/tickets/public' );
            $req->set_param( 'event_id', $ek_id );
            $res = \EventKoi\API\Tickets::get_public_tickets( $req );
            if ( is_wp_error( $res ) ) {
                continue;
            }
            $data     = $res->get_data();
            $cap      = (int) get_post_meta( $ek_id, 'tickets_event_capacity', true );
            $problems = [];
            foreach ( (array) ( $data['tickets'] ?? [] ) as $t ) {
                $rows_sold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d AND payment_status IN ({$counted})", (int) $t['id'] ) );
                if ( (int) $t['quantity_sold'] !== $rows_sold ) {
                    $problems[] = "storefront sold {$t['quantity_sold']} ≠ {$rows_sold} for ticket #{$t['id']}";
                }
                if ( null !== $t['quantity_available'] ) {
                    $expected = max( (int) $t['quantity_available'] - $rows_sold, 0 );
                    if ( $cap > 0 ) {
                        $expected = min( $expected, max( $cap - $sold, 0 ) );
                    }
                    if ( null !== $t['remaining'] && (int) $t['remaining'] !== $expected ) {
                        $problems[] = "storefront remaining {$t['remaining']} ≠ {$expected} for ticket #{$t['id']}";
                    }
                }
            }
            if ( $problems ) {
                ++$store_bad;
                if ( count( $out['mismatches'] ) < 100 ) {
                    $out['mismatches'][] = [ 'id' => $ek_id, 'title' => $this->plain_title( $ek_id ), 'detail' => implode( '; ', $problems ) ];
                }
            }
        }
        $in       = $done ? implode( ',', array_map( 'intval', $done ) ) : '0';
        $linked   = $wpdb->get_col( "SELECT DISTINCT CAST(SUBSTRING(SUBSTRING_INDEX(order_id, ':', 1), 4) AS UNSIGNED) FROM {$table} WHERE order_id LIKE 'wc\\_%' AND event_id IN ({$in})" );
        $missing  = 0;
        $ids      = array_filter( array_map( 'intval', $linked ) );
        if ( $ids ) {
            // HPOS stores order meta in wc_orders_meta; legacy in postmeta.
            $hpos = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) )
                && class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            $mt   = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
            $col  = $hpos ? 'order_id' : 'post_id';
            $pk   = $hpos ? 'id' : 'meta_id';
            $list = implode( ',', $ids );
            $missing = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT l.{$col}) FROM {$mt} l
                 LEFT JOIN {$mt} r ON r.{$col} = l.{$col} AND r.meta_key = '_ekti_seat_registrations'
                 WHERE l.meta_key = '_ekti_wc_linked' AND l.meta_value = '1'
                   AND l.{$col} IN ({$list}) AND r.{$pk} IS NULL"
            );
        }
        $out['checks'][] = [ 'label' => 'Attendee rows not on EventKoi\'s instance', 'value' => $bad_rows ];
        $out['checks'][] = [ 'label' => 'Upcoming events checked on the storefront', 'value' => $upcoming . ( $store_bad ? " ({$store_bad} wrong)" : '' ) ];
        $out['checks'][] = [ 'label' => 'Linked orders missing attendee details', 'value' => $missing ];
        $fs = $this->attendee_fields_status();
        $out['checks'][] = [ 'label' => 'Attendee fields plugin', 'value' => ( $fs['installed'] ? 'installed' : 'not installed' ) . ' — ' . implode( ', ', $fs['fields'] ) ];
        return 0 === $bad_rows && 0 === $store_bad && 0 === $missing;
    }

    /**
     * Environment checks for the wizard's first step.
     */
    private function preflight() {
        $checks = [];
        $add    = function ( $label, $status, $detail = '' ) use ( &$checks ) {
            $checks[] = [ 'label' => $label, 'status' => $status, 'detail' => $detail ];
        };
        $ver = function ( $file ) {
            $path = WP_PLUGIN_DIR . '/' . $file;
            if ( ! file_exists( $path ) || ! function_exists( 'get_plugin_data' ) ) {
                return '';
            }
            $data = get_plugin_data( $path, false, false );
            return isset( $data['Version'] ) ? 'v' . $data['Version'] : '';
        };
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $ek_active = class_exists( '\\EventKoi\\Core\\Ticket_Order_Sync' );
        $add( 'EventKoi', $ek_active ? 'ok' : 'fail', $ek_active ? $ver( 'eventkoi/eventkoi.php' ) : 'EventKoi is not active.' );
        $tickets_on = function_exists( 'eventkoi_is_tickets_feature_enabled' ) && eventkoi_is_tickets_feature_enabled();
        $add( 'EventKoi tickets feature', $tickets_on ? 'ok' : 'fail', $tickets_on ? 'Enabled' : 'Turn on Tickets in EventKoi settings — no ticket rows can be imported without it.' );
        $tax_api = $this->ek_taxonomy_api_available();
        $add( 'EventKoi Taxonomy Builder', $tax_api ? 'ok' : 'warn', $tax_api ? 'Available (Locations / Instructors can be created here)' : 'Not available — the Locations and Instructors step will be skipped.' );
        $tec = class_exists( 'Tribe__Events__Main' );
        $add( 'The Events Calendar', $tec ? 'ok' : 'fail', $tec ? $ver( 'the-events-calendar/the-events-calendar.php' ) : 'The Events Calendar is not active.' );
        $et = class_exists( 'Tribe__Tickets__Main' );
        $add( 'Event Tickets', $et ? 'ok' : 'warn', $et ? $ver( 'event-tickets/event-tickets.php' ) : 'Event Tickets is not active (ticket data is still read from the database).' );
        $wc = $this->wc_is_available();
        $add( 'WooCommerce', $wc ? 'ok' : 'fail', $wc ? $ver( 'woocommerce/woocommerce.php' ) : 'WooCommerce is not active — WooCommerce attendees cannot be linked to their orders.' );

        $opts   = get_option( 'tribe_events_calendar_options', [] );
        $opts   = is_array( $opts ) ? $opts : [];
        $trash  = isset( $opts['trash-past-events'] ) ? (int) $opts['trash-past-events'] : 0;
        $delete = isset( $opts['delete-past-events'] ) ? (int) $opts['delete-past-events'] : 0;
        if ( $delete > 0 ) {
            $add( 'TEC event cleaner', 'fail', "\"Permanently delete events older than\" is set to {$delete} month(s). Turn it off before importing — it hard-deletes source events." );
        } elseif ( $trash > 0 && $trash < 24 ) {
            $add( 'TEC event cleaner', 'warn', "\"Move to trash events older than\" is {$trash} month(s). Raise it to 24 or turn it off so old events are not lost mid-migration." );
        } else {
            $add( 'TEC event cleaner', 'ok', $trash ? "Trash after {$trash} months; permanent delete off" : 'Off' );
        }

        $adopt = $this->scan_native_adoption();
        $add(
            'Native EventKoi imports',
            $adopt['candidate_events'] ? 'warn' : 'ok',
            $adopt['candidate_events']
                ? $adopt['candidate_events'] . ' natively imported event(s) carry attendee history. Run "Adopt Native Imports" under Advanced tools before importing orders.'
                : $adopt['marked_events'] . ' EventKoi event(s) already linked to TEC'
        );

        $audit = get_option( 'ekti_event_sync_audit', [] );
        $untagged = 0;
        foreach ( (array) $audit as $op ) {
            if ( empty( $op['stage'] ) ) {
                ++$untagged;
            }
        }
        $add(
            'Previous Event Sync runs',
            $untagged ? 'warn' : 'ok',
            $untagged ? $untagged . ' ledger op(s) from a single-pass Event Sync exist. Those events are skipped by the wizard; "Undo Last Event Sync" under Advanced tools removes them.' : 'None'
        );

        $log_dir = dirname( EKTI_LOG_FILE );
        $add( 'Import log', is_writable( $log_dir ) ? 'ok' : 'warn', is_writable( $log_dir ) ? EKTI_LOG_FILE : 'Upload directory is not writable — the log file will not be written.' );

        // Step 18: ticket sales source, checkout method and EventKoi setup.
        $setup = $ek_active ? $this->ek_setup_proposal() : null;
        if ( $setup ) {
            foreach ( $this->ek_setup_checks( $setup ) as $c ) {
                $add( $c['label'], $c['status'], $c['detail'] );
            }
        }

        $fail = count( array_filter( $checks, function ( $c ) {
            return 'fail' === $c['status'];
        } ) );
        return [ 'checks' => $checks, 'ok' => 0 === $fail, 'setup' => $setup ];
    }

    /* Step 18 — EventKoi setup ------------------------------------------
     *
     * EventKoi's onboarding wizard minus its sample data: the ticket
     * checkout method (set to WooCommerce when TEC sold through
     * WooCommerce), calendar defaults (week start, working days, time
     * format) and the quick-start flag. The demo event and plugin tour are
     * skipped; the license is reported, never read or written. Every
     * setting written is recorded per key in ekti_ek_setup_audit so
     * "Undo EventKoi Setup" restores exactly what was there.
     * ------------------------------------------------------------------ */

    /** EventKoi settings keys the setup step writes. */
    private $ek_setup_keys = [ 'ticket_checkout_method', 'week_starts_on', 'working_days', 'time_format' ];

    /**
     * Where TEC's ticket sales came from (published attendee posts).
     */
    private function tec_sales_profile() {
        static $profile = null;
        if ( null !== $profile ) {
            return $profile;
        }
        global $wpdb;
        $profile = [
            'woo_products'  => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_tribe_wooticket_for_event'" ),
            'woo_attendees' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_tribe_wooticket_order' AND o.meta_value != ''
                 WHERE p.post_type = 'tribe_wooticket' AND p.post_status = 'publish'"
            ),
            'woo_orders'    => (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT o.meta_value) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_tribe_wooticket_order' AND o.meta_value != ''
                 WHERE p.post_type = 'tribe_wooticket' AND p.post_status = 'publish'"
            ),
            'tc_attendees'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tec_tc_attendee' AND post_status = 'publish'" ),
        ];
        $profile['woo_detected'] = $profile['woo_attendees'] > 0 && $this->wc_is_available();
        return $profile;
    }

    /** EventKoi's stored ticket checkout method ('' when never chosen). */
    private function ek_checkout_method() {
        $settings = class_exists( '\\EventKoi\\Core\\Settings' ) ? \EventKoi\Core\Settings::get() : [];
        return (string) ( $settings['ticket_checkout_method'] ?? '' );
    }

    /**
     * Blocks the tickets / orders stages while TEC sold through
     * WooCommerce but EventKoi's checkout is something else: EventKoi's
     * event list and checkout would then ignore the imported orders.
     */
    private function checkout_method_guard() {
        if ( $this->tec_sales_profile()['woo_detected'] && 'woocommerce' !== $this->ek_checkout_method() ) {
            return 'EventKoi\'s ticket checkout method is not WooCommerce, but TEC sold its tickets through WooCommerce. Save the EventKoi setup in step 1 first.';
        }
        return '';
    }

    /**
     * Current values plus what the setup form proposes. Unset calendar
     * defaults are pre-filled from the WordPress settings TEC also uses.
     */
    private function ek_setup_proposal() {
        $raw      = get_option( 'eventkoi_settings', [] );
        $raw      = is_array( $raw ) ? $raw : [];
        $profile  = $this->tec_sales_profile();
        $current  = $this->ek_checkout_method();

        // WordPress: 0 = Sunday … 6 = Saturday. EventKoi: 0 = Monday … 6 = Sunday.
        $wp_week = ( (int) get_option( 'start_of_week', 1 ) + 6 ) % 7;
        $wp_time = preg_match( '/[aAgh]/', (string) get_option( 'time_format', 'g:i a' ) ) ? '12' : '24';

        $demo_id   = (int) get_option( 'eventkoi_demo_event_id', 0 );
        $demo_post = $demo_id ? get_post( $demo_id ) : null;

        return [
            'sales'          => $profile,
            'method'         => [
                'current'  => $current,
                'proposed' => $profile['woo_detected'] ? 'woocommerce' : $current,
            ],
            'week_starts_on' => [
                'current'  => isset( $raw['week_starts_on'] ) ? (int) $raw['week_starts_on'] : null,
                'proposed' => isset( $raw['week_starts_on'] ) ? (int) $raw['week_starts_on'] : $wp_week,
            ],
            'working_days'   => [
                'current'  => isset( $raw['working_days'] ) && is_array( $raw['working_days'] ) ? array_map( 'intval', $raw['working_days'] ) : null,
                'proposed' => isset( $raw['working_days'] ) && is_array( $raw['working_days'] ) ? array_map( 'intval', $raw['working_days'] ) : [ 0, 1, 2, 3, 4 ],
            ],
            'time_format'    => [
                'current'  => isset( $raw['time_format'] ) ? (string) $raw['time_format'] : null,
                'proposed' => isset( $raw['time_format'] ) && in_array( (string) $raw['time_format'], [ '12', '24' ], true ) ? (string) $raw['time_format'] : $wp_time,
            ],
            'timezone'       => wp_timezone_string(),
            'date_format'    => (string) get_option( 'date_format' ),
            'general_url'    => admin_url( 'options-general.php' ),
            'ek_settings_url' => admin_url( 'admin.php?page=eventkoi#/settings' ),
            'elementor'      => defined( 'ELEMENTOR_VERSION' ),
            'currency'       => [
                'eventkoi' => strtoupper( (string) ( \EventKoi\Core\Settings::get()['currency'] ?? '' ) ),
                'woo'      => $this->es_woo_currency_code(),
            ],
            'license'        => [
                'status'  => (string) get_option( 'eventkoi_license_status', '' ),
                'expires' => (string) get_option( 'eventkoi_license_expires', '' ),
            ],
            'demo_event'     => $demo_post && 'eventkoi_event' === $demo_post->post_type && 'trash' !== $demo_post->post_status
                ? [ 'id' => $demo_id, 'title' => $this->plain_title( $demo_id ), 'status' => $demo_post->post_status ]
                : null,
            'quick_start_done' => 'yes' === get_option( 'eventkoi_quick_start_completed', '' ),
            'applied'        => ! empty( get_option( 'ekti_ek_setup_audit', [] ) ),
        ];
    }

    /**
     * Preflight rows for the setup proposal.
     */
    private function ek_setup_checks( $setup ) {
        global $wpdb;
        $rows   = [];
        $s      = $setup['sales'];
        $labels = [ 'woocommerce' => 'WooCommerce', 'stripe' => 'Stripe', 'surecart' => 'SureCart', '' => 'not set' ];
        $name   = function ( $m ) use ( $labels ) {
            return $labels[ $m ] ?? $m;
        };

        $rows[] = [
            'label'  => 'TEC ticket sales',
            'status' => 'ok',
            'detail' => sprintf(
                'WooCommerce: %s attendee(s) in %s order(s) across %s ticket product(s). Tickets Commerce: %s attendee(s).',
                number_format_i18n( $s['woo_attendees'] ),
                number_format_i18n( $s['woo_orders'] ),
                number_format_i18n( $s['woo_products'] ),
                number_format_i18n( $s['tc_attendees'] )
            ),
        ];

        $current = $setup['method']['current'];
        if ( ! $s['woo_detected'] ) {
            $rows[] = [ 'label' => 'EventKoi checkout method', 'status' => 'ok', 'detail' => ucfirst( $name( $current ) ) . ' — no WooCommerce ticket sales found in TEC, so it is left as is.' ];
        } elseif ( 'woocommerce' === $current ) {
            $rows[] = [ 'label' => 'EventKoi checkout method', 'status' => 'ok', 'detail' => 'WooCommerce (matches TEC)' ];
        } else {
            $rows[] = [
                'label'  => 'EventKoi checkout method',
                'status' => 'warn',
                'detail' => 'Currently ' . $name( $current ) . '. TEC sold its tickets through WooCommerce, so approving this step sets it to WooCommerce. Until then EventKoi\'s event list shows 0 sold for imported orders.',
            ];
        }

        if ( $s['woo_detected'] ) {
            $ek_cur  = $setup['currency']['eventkoi'];
            $woo_cur = $setup['currency']['woo'];
            $rows[]  = [
                'label'  => 'Checkout currency',
                'status' => '' === $woo_cur ? 'warn' : 'ok',
                'detail' => '' === $woo_cur
                    ? 'WooCommerce has no valid currency set.'
                    : $woo_cur . ( $ek_cur && $ek_cur !== $woo_cur ? ' (EventKoi currently shows ' . $ek_cur . '; it follows WooCommerce once checkout is WooCommerce)' : '' ),
            ];

            $non_wc = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->ek_table( 'ticket_orders' )} t
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = t.event_id AND m.meta_key = '_tec_import_source_id'
                 WHERE t.order_id NOT LIKE 'wc\\_%'
                   AND t.payment_status IN ('complete','completed','succeeded','paid','partially_refunded')"
            );
            if ( $non_wc > 0 ) {
                $rows[] = [
                    'label'  => 'Non-WooCommerce sales',
                    'status' => 'warn',
                    'detail' => $non_wc . ' imported sold row(s) are not WooCommerce orders (Tickets Commerce / manual). EventKoi\'s event list does not count them while checkout is WooCommerce.',
                ];
            }
        }

        $lic    = $setup['license'];
        $rows[] = [
            'label'  => 'EventKoi license',
            'status' => 'valid' === $lic['status'] ? 'ok' : 'warn',
            'detail' => 'valid' === $lic['status']
                ? 'Active' . ( $lic['expires'] ? ' (' . $lic['expires'] . ')' : '' )
                : 'Not active. Add the license key in EventKoi → Settings (the importer does not handle it).',
        ];

        $rows[] = [
            'label'  => 'EventKoi demo event',
            'status' => $setup['demo_event'] ? 'warn' : 'ok',
            'detail' => $setup['demo_event']
                ? 'EventKoi\'s onboarding demo event "' . $setup['demo_event']['title'] . '" (#' . $setup['demo_event']['id'] . ') exists. Trash it in EventKoi if it is not wanted.'
                : 'None (the importer never creates it)',
        ];

        return $rows;
    }

    /**
     * Validate the setup form and write it into EventKoi's settings,
     * recording each key's prior value (or absence) for undo.
     *
     * @return array|WP_Error
     */
    private function apply_ek_setup( $input ) {
        if ( ! class_exists( '\\EventKoi\\Core\\Settings' ) ) {
            return new WP_Error( 'no_eventkoi', 'EventKoi is not active.' );
        }

        $values = [];

        $method = isset( $input['ticket_checkout_method'] ) ? sanitize_key( (string) $input['ticket_checkout_method'] ) : '';
        if ( 'woocommerce' === $method ) {
            if ( ! $this->wc_is_available() ) {
                return new WP_Error( 'no_wc', 'WooCommerce is not active, so it cannot be EventKoi\'s checkout.' );
            }
            $values['ticket_checkout_method'] = 'woocommerce';
        } elseif ( '' !== $method && 'keep' !== $method ) {
            return new WP_Error( 'bad_method', 'Unsupported checkout method.' );
        }

        if ( isset( $input['week_starts_on'] ) ) {
            $week = (int) $input['week_starts_on'];
            if ( $week < 0 || $week > 6 ) {
                return new WP_Error( 'bad_week', 'Week start must be a day of the week.' );
            }
            $values['week_starts_on'] = $week;
        }

        if ( isset( $input['working_days'] ) ) {
            $days = array_values( array_unique( array_filter( array_map( 'intval', (array) $input['working_days'] ), function ( $d ) {
                return $d >= 0 && $d <= 6;
            } ) ) );
            sort( $days );
            if ( empty( $days ) ) {
                return new WP_Error( 'bad_days', 'Pick at least one working day.' );
            }
            $values['working_days'] = $days;
        }

        if ( isset( $input['time_format'] ) ) {
            $tf = (string) $input['time_format'];
            if ( ! in_array( $tf, [ '12', '24' ], true ) ) {
                return new WP_Error( 'bad_time', 'Time format must be 12 or 24.' );
            }
            $values['time_format'] = $tf;
        }

        $raw     = get_option( 'eventkoi_settings', null );
        $existed = is_array( $raw );
        $raw     = $existed ? $raw : [];

        $audit = get_option( 'ekti_ek_setup_audit', [] );
        $audit = is_array( $audit ) ? $audit : [];
        if ( ! isset( $audit['option_existed'] ) ) {
            $audit['option_existed'] = $existed;
        }
        $audit['keys'] = is_array( $audit['keys'] ?? null ) ? $audit['keys'] : [];

        $changed = [];
        foreach ( $values as $key => $value ) {
            // Only the first write of a key records the original value.
            if ( ! array_key_exists( $key, $audit['keys'] ) ) {
                $audit['keys'][ $key ] = array_key_exists( $key, $raw ) ? [ 'had' => true, 'old' => $raw[ $key ] ] : [ 'had' => false ];
            }
            // Loose: EventKoi's sanitizer stores numbers as strings.
            if ( ! array_key_exists( $key, $raw ) || $raw[ $key ] != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons
                $changed[] = $key;
            }
            $raw[ $key ] = $value;
        }

        // Mark EventKoi's quick start done so it stops offering the demo event.
        foreach ( [ 'eventkoi_quick_start_completed', 'eventkoi_show_quick_start_prompt' ] as $opt ) {
            if ( ! isset( $audit['options'][ $opt ] ) ) {
                $old                       = get_option( $opt, null );
                $audit['options'][ $opt ] = null === $old ? [ 'had' => false ] : [ 'had' => true, 'old' => $old ];
            }
        }

        update_option( 'ekti_ek_setup_audit', $audit, false );
        // Settings::set() runs EventKoi's own sanitizer and filter; the
        // update_option_eventkoi_settings hooks clear its display caches.
        \EventKoi\Core\Settings::set( $raw );
        update_option( 'eventkoi_quick_start_completed', 'yes' );
        delete_option( 'eventkoi_show_quick_start_prompt' );
        $this->flush_ek_event_caches();

        $this->log( 'EventKoi setup saved: ' . wp_json_encode( $values ) . ( $changed ? ' (changed: ' . implode( ', ', $changed ) . ')' : ' (no changes)' ) );
        return [ 'saved' => $values, 'changed' => $changed ];
    }

    /**
     * Clear EventKoi's event-list caches. Its own clear only hooks
     * update_option_eventkoi_settings, which does not fire when the option
     * is first created (add_option) or deleted, so the events list would
     * keep serving sold counts computed under the old checkout method.
     */
    private function flush_ek_event_caches() {
        foreach ( [ 'clear_event_query_cache', 'bump_events_cache_version' ] as $method ) {
            if ( is_callable( [ '\\EventKoi\\Core\\Hooks', $method ] ) ) {
                call_user_func( [ '\\EventKoi\\Core\\Hooks', $method ] );
            }
        }
    }

    /**
     * Restore every EventKoi setting the setup step wrote.
     */
    private function undo_ek_setup() {
        $audit = get_option( 'ekti_ek_setup_audit', [] );
        if ( empty( $audit ) || ! is_array( $audit ) ) {
            return [ 'error' => 'Nothing to undo.' ];
        }

        $raw = get_option( 'eventkoi_settings', [] );
        $raw = is_array( $raw ) ? $raw : [];
        foreach ( (array) ( $audit['keys'] ?? [] ) as $key => $prior ) {
            if ( ! empty( $prior['had'] ) ) {
                $raw[ $key ] = $prior['old'];
            } else {
                unset( $raw[ $key ] );
            }
        }
        if ( empty( $audit['option_existed'] ) && empty( $raw ) ) {
            delete_option( 'eventkoi_settings' );
        } elseif ( class_exists( '\\EventKoi\\Core\\Settings' ) ) {
            \EventKoi\Core\Settings::set( $raw );
        } else {
            update_option( 'eventkoi_settings', $raw );
        }

        foreach ( (array) ( $audit['options'] ?? [] ) as $opt => $prior ) {
            if ( ! empty( $prior['had'] ) ) {
                update_option( $opt, $prior['old'] );
            } else {
                delete_option( $opt );
            }
        }

        $this->flush_ek_event_caches();
        delete_option( 'ekti_ek_setup_audit' );
        $this->log( 'EventKoi setup undone: restored ' . implode( ', ', array_keys( (array) ( $audit['keys'] ?? [] ) ) ) . '.' );
        return [ 'restored' => array_keys( (array) ( $audit['keys'] ?? [] ) ) ];
    }

    /* Wizard state ----------------------------------------------------- */

    private $wizard_steps = [ 'preflight', 'taxonomies', 'events', 'tickets', 'orders' ];

    private function get_wizard_state() {
        $state = get_option( 'ekti_wizard_state', [] );
        $state = is_array( $state ) ? $state : [];
        $state['approved'] = is_array( $state['approved'] ?? null ) ? $state['approved'] : [];
        $state['verified'] = is_array( $state['verified'] ?? null ) ? $state['verified'] : [];
        return $state;
    }

    /**
     * Clear the approval of a step and every step after it.
     */
    private function wizard_unapprove_from( $step ) {
        $state = $this->get_wizard_state();
        $pos   = array_search( $step, $this->wizard_steps, true );
        if ( false === $pos ) {
            return;
        }
        foreach ( array_slice( $this->wizard_steps, $pos ) as $s ) {
            unset( $state['approved'][ $s ], $state['verified'][ $s ] );
        }
        update_option( 'ekti_wizard_state', $state, false );
    }

    /**
     * Live wizard summary: approvals plus each stage's progress.
     */
    private function wizard_summary() {
        $state  = $this->get_wizard_state();
        $audit  = get_option( 'ekti_event_sync_audit', [] );
        $ledger = [ 'events' => 0, 'tickets' => 0, 'orders' => 0, 'untagged' => 0 ];
        foreach ( (array) $audit as $op ) {
            $s = $op['stage'] ?? '';
            if ( isset( $ledger[ $s ] ) ) {
                ++$ledger[ $s ];
            } else {
                ++$ledger['untagged'];
            }
        }
        $stages = [];
        foreach ( $this->stages as $stage ) {
            $failures = $this->get_stage_failures( $stage );
            $queue    = 'events' === $stage ? $this->get_tec_event_import_ids() : $this->get_stage_queue( $stage );
            $stages[ $stage ] = [
                'done'      => count( $this->get_stage_done( $stage ) ),
                'remaining' => count( array_diff( $queue, array_map( 'absint', array_keys( $failures ) ) ) ),
                'failed'    => count( $failures ),
                'ledger'    => $ledger[ $stage ],
            ];
        }
        return [
            'approved'     => $state['approved'],
            'verified'     => $state['verified'],
            'stages'       => $stages,
            'untagged_ops' => $ledger['untagged'],
            'attendees'    => $this->attendee_fields_status(),
            'taxonomies'   => [
                'locations'   => $this->get_taxonomy_setup_state( 'locations' ),
                'instructors' => $this->get_taxonomy_setup_state( 'instructors' ),
                'mapped'      => [
                    'locations'   => count( $this->get_saved_taxonomy_mapping( 'locations' ) ),
                    'instructors' => count( $this->get_saved_taxonomy_mapping( 'instructors' ) ),
                ],
                'provisioned' => ! empty( get_option( 'ekti_tax_provision_audit', [] ) ),
            ],
            'ek_setup'     => [
                'applied' => ! empty( get_option( 'ekti_ek_setup_audit', [] ) ),
                'method'  => class_exists( '\\EventKoi\\Core\\Settings' ) ? $this->ek_checkout_method() : '',
                'guard'   => class_exists( '\\EventKoi\\Core\\Settings' ) ? $this->checkout_method_guard() : '',
            ],
        ];
    }

    private function wizard_approve( $step ) {
        $pos = array_search( $step, $this->wizard_steps, true );
        if ( false === $pos ) {
            return [ 'error' => 'Unknown step.' ];
        }
        $state = $this->get_wizard_state();
        foreach ( array_slice( $this->wizard_steps, 0, $pos ) as $prev ) {
            if ( empty( $state['approved'][ $prev ] ) ) {
                return [ 'error' => 'Approve the ' . $prev . ' step first.' ];
            }
        }
        if ( in_array( $step, [ 'preflight', 'tickets', 'orders' ], true ) ) {
            $guard = $this->checkout_method_guard();
            if ( $guard ) {
                return [ 'error' => $guard ];
            }
        }
        if ( in_array( $step, $this->stages, true ) ) {
            $failures  = $this->get_stage_failures( $step );
            $queue     = 'events' === $step ? $this->get_tec_event_import_ids() : $this->get_stage_queue( $step );
            $remaining = count( array_diff( $queue, array_map( 'absint', array_keys( $failures ) ) ) );
            if ( $remaining > 0 ) {
                return [ 'error' => $remaining . ' event(s) still pending in this stage — finish the run first.' ];
            }
        }
        if ( 'orders' === $step && empty( $state['verified']['orders']['ok'] ) ) {
            return [ 'error' => 'Order verification has not passed. Run the check and resolve the mismatches first.' ];
        }
        $state['approved'][ $step ] = time();
        update_option( 'ekti_wizard_state', $state, false );
        $this->log( "Import wizard: step \"{$step}\" approved." );
        return [ 'approved' => $state['approved'] ];
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

    /* Step 16 — wizard ---------------------------------------------- */

    public function ajax_wizard_state() {
        $this->check_ajax();
        wp_send_json_success( $this->wizard_summary() );
    }

    public function ajax_wizard_approve() {
        $this->check_ajax();
        $step = isset( $_POST['step'] ) ? sanitize_key( $_POST['step'] ) : '';
        // Approving preflight saves the EventKoi setup form first.
        if ( 'preflight' === $step && isset( $_POST['setup'] ) ) {
            $input = json_decode( wp_unslash( $_POST['setup'] ), true );
            $saved = $this->apply_ek_setup( is_array( $input ) ? $input : [] );
            if ( is_wp_error( $saved ) ) {
                wp_send_json_error( $saved->get_error_message() );
            }
        }
        $result = $this->wizard_approve( $step );
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $this->wizard_summary() );
    }

    public function ajax_preflight() {
        $this->check_ajax();
        wp_send_json_success( $this->preflight() );
    }

    public function ajax_ek_setup() {
        $this->check_ajax();
        $op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : 'save';
        if ( 'undo' === $op ) {
            $result = $this->undo_ek_setup();
            if ( isset( $result['error'] ) ) {
                wp_send_json_error( $result['error'] );
            }
            wp_send_json_success( $result );
        }
        $input = isset( $_POST['setup'] ) ? json_decode( wp_unslash( $_POST['setup'] ), true ) : [];
        $saved = $this->apply_ek_setup( is_array( $input ) ? $input : [] );
        if ( is_wp_error( $saved ) ) {
            wp_send_json_error( $saved->get_error_message() );
        }
        wp_send_json_success( $saved );
    }

    public function ajax_provision_taxonomy() {
        $this->check_ajax();
        $type  = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
        $input = isset( $_POST['config'] ) ? json_decode( wp_unslash( $_POST['config'] ), true ) : [];
        $result = $this->provision_taxonomy( $type, is_array( $input ) ? $input : [] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public function ajax_undo_taxonomy_provision() {
        $this->check_ajax();
        $result = $this->undo_taxonomy_provision();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        $this->wizard_unapprove_from( 'taxonomies' );
        wp_send_json_success( $result );
    }

    public function ajax_run_event_stage() {
        $this->check_ajax();
        $stage = isset( $_POST['stage'] ) ? sanitize_key( $_POST['stage'] ) : '';
        if ( ! in_array( $stage, $this->stages, true ) ) {
            wp_send_json_error( 'Invalid stage.' );
        }
        $state = $this->get_wizard_state();
        $prev  = $this->wizard_steps[ array_search( $stage, $this->wizard_steps, true ) - 1 ];
        if ( empty( $state['approved'][ $prev ] ) ) {
            wp_send_json_error( 'Approve the ' . $prev . ' step first.' );
        }
        if ( 'events' !== $stage ) {
            $guard = $this->checkout_method_guard();
            if ( $guard ) {
                wp_send_json_error( $guard );
            }
        }
        if ( ! empty( $_POST['reset'] ) && 'true' === $_POST['reset'] ) {
            $this->reset_stage_failures( $stage );
        }
        if ( 'events' === $stage ) {
            $images = ! isset( $_POST['import_images'] ) || ! in_array( $_POST['import_images'], [ '0', 'false', '' ], true );
            wp_send_json_success( $this->run_event_sync( 20, $images ) );
        }
        $result = $this->run_event_stage( $stage, 'orders' === $stage ? 10 : 20 );
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_undo_event_stage() {
        $this->check_ajax();
        $stage  = isset( $_POST['stage'] ) ? sanitize_key( $_POST['stage'] ) : '';
        $result = $this->undo_event_stage( $stage );
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_preview_tickets() {
        $this->check_ajax();
        wp_send_json_success( $this->preview_ticket_stage() );
    }

    public function ajax_preview_orders() {
        $this->check_ajax();
        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        wp_send_json_success( $this->preview_orders_stage( $offset, 40 ) );
    }

    public function ajax_verify_stage() {
        $this->check_ajax();
        $stage = isset( $_POST['stage'] ) ? sanitize_key( $_POST['stage'] ) : '';
        if ( ! in_array( $stage, $this->stages, true ) ) {
            wp_send_json_error( 'Invalid stage.' );
        }
        wp_send_json_success( $this->verify_stage( $stage ) );
    }

    public function ajax_run_attendee_repair() {
        $this->check_ajax();
        $result = $this->run_attendee_repair();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_attendee_fields() {
        $this->check_ajax();
        $op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : 'status';
        if ( 'install' === $op ) {
            $result = $this->install_attendee_fields_plugin();
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
        } elseif ( 'remove' === $op ) {
            $this->remove_attendee_fields_plugin();
        }
        wp_send_json_success( $this->attendee_fields_status() );
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
            <p class="description">Migrate events, tickets and attendees from The Events Calendar to EventKoi.</p>

            <!-- Import Wizard -->
            <div class="ekti-panel ekti-wizard" id="ekti-wizard">
                <h2>Import Wizard</h2>
                <p>Runs the whole migration in order and stops for your review after events, tickets and order matching. Every step is audited and can be undone, latest step first.</p>
                <ol class="ekti-stepper" id="ekti-stepper">
                    <li data-step="preflight"><span class="ekti-step-num">1</span> Preflight &amp; setup</li>
                    <li data-step="taxonomies"><span class="ekti-step-num">2</span> Locations &amp; Instructors</li>
                    <li data-step="events"><span class="ekti-step-num">3</span> Events</li>
                    <li data-step="tickets"><span class="ekti-step-num">4</span> Tickets</li>
                    <li data-step="orders"><span class="ekti-step-num">5</span> Order matching</li>
                    <li data-step="finish"><span class="ekti-step-num">6</span> Finish</li>
                </ol>

                <!-- Step 1: Preflight -->
                <section class="ekti-step" data-step="preflight">
                    <h3 class="ekti-step-title">1. Preflight &amp; EventKoi setup <span class="ekti-step-badge"></span></h3>
                    <div class="ekti-step-body">
                        <p class="description">Checks the plugins, TEC's event cleaner, any earlier imports and where TEC's ticket sales came from. Approving saves the EventKoi setup below (checkout method and calendar defaults), the parts of EventKoi's onboarding wizard an import needs. Its demo event and plugin tour are skipped.</p>
                        <div class="ekti-actions">
                            <button class="button button-primary" id="ekti-wz-preflight-run">Run Checks</button>
                            <button class="button" id="ekti-wz-preflight-approve" disabled>Approve &amp; Continue</button>
                        </div>
                        <div id="ekti-wz-preflight-status" class="ekti-status"></div>
                        <table class="wp-list-table widefat fixed striped" id="ekti-wz-preflight-table" style="display:none;">
                            <thead><tr><th style="width:28%;">Check</th><th style="width:10%;">Result</th><th>Detail</th></tr></thead>
                            <tbody></tbody>
                        </table>

                        <div id="ekti-wz-setup" class="ekti-wz-setup" style="display:none;">
                            <h4>EventKoi setup</h4>
                            <table class="form-table" role="presentation"><tbody id="ekti-wz-setup-rows"></tbody></table>
                            <div class="ekti-actions">
                                <button class="button" id="ekti-wz-setup-save">Save Setup</button>
                                <button class="button button-secondary" id="ekti-wz-setup-undo" style="color:#a00;" disabled>Undo EventKoi Setup</button>
                            </div>
                            <div id="ekti-wz-setup-status" class="ekti-status"></div>
                        </div>
                    </div>
                </section>

                <!-- Step 2: Locations & Instructors -->
                <section class="ekti-step" data-step="taxonomies">
                    <h3 class="ekti-step-title">2. Locations &amp; Instructors <span class="ekti-step-badge"></span></h3>
                    <div class="ekti-step-body">
                        <p class="description">Set up EventKoi's Locations taxonomy as <strong>Saved Locations</strong> (and an Instructors taxonomy), then match each TEC venue and organizer to a term. Terms are created with the venue's address. The events step assigns them to every imported event. This step can be reopened at any time.</p>

                        <h4>2a. Set up the taxonomies</h4>
                        <div class="ekti-setup-cards">
                            <div class="ekti-setup-card" id="ekti-setup-locations" data-type="locations"><p><span class="ekti-spinner"></span> Loading&hellip;</p></div>
                            <div class="ekti-setup-card" id="ekti-setup-instructors" data-type="instructors"><p><span class="ekti-spinner"></span> Loading&hellip;</p></div>
                        </div>
                        <div class="ekti-actions">
                            <button class="button button-secondary" id="ekti-wz-provision-undo" style="color:#a00;">Undo Taxonomy Setup</button>
                        </div>

                        <h4 style="margin-top:1.5em;">2b. Match TEC venues and organizers to terms</h4>
                        <p class="description">Pick an existing term or "Create new term" for each source, then <strong>Create terms &amp; apply</strong>. Leave a source on "None" to skip it.</p>
                            <!-- Locations -->
                            <div class="ekti-tax-section" id="ekti-tax-locations-section" style="margin-top:1.5em;">
                                <h3>Locations</h3>
                                <p class="description">Map TEC venues to EventKoi Locations taxonomy terms. With EventKoi's Saved Locations active, each TEC venue's address is copied onto the mapped term, and events resolve their address from the assigned term.</p>
                                <div class="ekti-actions">
                                    <button class="button button-primary" id="ekti-tax-locations-scan">Scan Locations</button>
                                    <button class="button" id="ekti-tax-locations-save" disabled>Save Mapping</button>
                                    <button class="button" id="ekti-tax-locations-run" disabled>Create Terms &amp; Apply</button>
                                    <button class="button button-secondary" id="ekti-tax-locations-undo" style="color:#a00;">Undo Location Matching</button>
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
                                    <button class="button" id="ekti-tax-instructors-run" disabled>Create Terms &amp; Apply</button>
                                    <button class="button button-secondary" id="ekti-tax-instructors-undo" style="color:#a00;">Undo Instructor Matching</button>
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
                        <div class="ekti-actions" style="margin-top:1.5em;">
                            <button class="button button-primary" id="ekti-wz-taxonomies-approve">Approve &amp; Continue</button>
                        </div>
                        <div id="ekti-wz-taxonomies-status" class="ekti-status"></div>
                    </div>
                </section>

                <!-- Step 3: Events -->
                <section class="ekti-step" data-step="events">
                    <h3 class="ekti-step-title">3. Events <span class="ekti-step-badge"></span></h3>
                    <div class="ekti-step-body">
                        <p class="description">Imports TEC events into EventKoi (dates, venues, categories, images, Locations and Instructors terms). Tickets and attendees come in the next two steps.</p>
                        <div class="ekti-actions">
                            <button class="button" id="ekti-wz-events-preview">Preview</button>
                            <button class="button button-primary" id="ekti-wz-events-run" disabled>Import Events</button>
                            <label style="margin-left:6px;"><input type="checkbox" id="ekti-wz-events-images" checked /> Import images</label>
                        </div>
                        <div id="ekti-wz-events-status" class="ekti-status"></div>
                        <div class="ekti-wz-progress" id="ekti-wz-events-progress" style="display:none;"><div class="ekti-progress-bar"><div class="ekti-progress-fill" style="width:0%"></div></div><div class="ekti-progress-text">0%</div></div>
                        <div class="ekti-wz-preview" id="ekti-wz-events-preview-out"></div>
                        <div class="ekti-wz-verify" id="ekti-wz-events-verify"></div>
                        <div class="ekti-actions ekti-review-actions">
                            <button class="button" id="ekti-wz-events-check">Check Results</button>
                            <button class="button button-primary" id="ekti-wz-events-approve" disabled>Approve Events</button>
                            <button class="button button-secondary" id="ekti-wz-events-undo" style="color:#a00;">Undo Events</button>
                        </div>
                    </div>
                </section>

                <!-- Step 4: Tickets -->
                <section class="ekti-step" data-step="tickets">
                    <h3 class="ekti-step-title">4. Tickets <span class="ekti-step-badge"></span></h3>
                    <div class="ekti-step-body">
                        <p class="description">Creates EventKoi ticket types from TEC tickets (price, capacity, sale window), RSVP and shared capacity, and WooCommerce product links. Seats already sold in TEC are held back until their attendees are imported in the next step.</p>
                        <div class="ekti-actions">
                            <button class="button" id="ekti-wz-tickets-preview">Preview</button>
                            <button class="button button-primary" id="ekti-wz-tickets-run" disabled>Import Tickets</button>
                        </div>
                        <div id="ekti-wz-tickets-status" class="ekti-status"></div>
                        <div class="ekti-wz-progress" id="ekti-wz-tickets-progress" style="display:none;"><div class="ekti-progress-bar"><div class="ekti-progress-fill" style="width:0%"></div></div><div class="ekti-progress-text">0%</div></div>
                        <div class="ekti-wz-preview" id="ekti-wz-tickets-preview-out"></div>
                        <div class="ekti-wz-verify" id="ekti-wz-tickets-verify"></div>
                        <div class="ekti-actions ekti-review-actions">
                            <button class="button" id="ekti-wz-tickets-check">Check Results</button>
                            <button class="button button-primary" id="ekti-wz-tickets-approve" disabled>Approve Tickets</button>
                            <button class="button button-secondary" id="ekti-wz-tickets-undo" style="color:#a00;">Undo Tickets</button>
                        </div>
                    </div>
                </section>

                <!-- Step 5: Order matching -->
                <section class="ekti-step" data-step="orders">
                    <h3 class="ekti-step-title">5. Order matching <span class="ekti-step-badge"></span></h3>
                    <div class="ekti-step-body">
                        <p class="description">Links every TEC attendee to its WooCommerce order with the order's real status (refunded and failed seats are kept but not counted as sold), imports RSVP and Tickets Commerce attendees, then releases the held seats. Customers are not emailed.</p>
                        <div class="ekti-actions">
                            <button class="button" id="ekti-wz-orders-preview">Preview</button>
                            <button class="button button-primary" id="ekti-wz-orders-run" disabled>Link Orders &amp; Attendees</button>
                        </div>
                        <div id="ekti-wz-orders-status" class="ekti-status"></div>
                        <div class="ekti-wz-progress" id="ekti-wz-orders-progress" style="display:none;"><div class="ekti-progress-bar"><div class="ekti-progress-fill" style="width:0%"></div></div><div class="ekti-progress-text">0%</div></div>
                        <div class="ekti-wz-preview" id="ekti-wz-orders-preview-out"></div>
                        <div class="ekti-wz-verify" id="ekti-wz-orders-verify"></div>
                        <div class="ekti-setup-card" id="ekti-wz-attendees" style="margin:10px 0;">
                            <h5>Attendee details</h5>
                            <p class="description">Each seat keeps the WooCommerce purchaser (as EventKoi's own checkout does). The people on each order and their registration answers from TEC (phone, ZIP, &hellip;) are stored on the order as EventKoi checkout fields. They appear in the attendee CSV export and the order details once the attendee fields plugin is installed. The plugin never adds fields to the storefront checkout.</p>
                            <div id="ekti-wz-attendees-status" class="ekti-status"></div>
                            <div class="ekti-actions">
                                <button class="button" id="ekti-wz-attendees-repair">Update Existing Attendees</button>
                                <button class="button" id="ekti-wz-attendees-install">Install Attendee Fields</button>
                                <button class="button button-secondary" id="ekti-wz-attendees-remove" style="color:#a00;">Remove Attendee Fields</button>
                            </div>
                        </div>
                        <div class="ekti-actions ekti-review-actions">
                            <button class="button" id="ekti-wz-orders-check">Check Results</button>
                            <button class="button button-primary" id="ekti-wz-orders-approve" disabled>Approve Orders</button>
                            <button class="button button-secondary" id="ekti-wz-orders-undo" style="color:#a00;">Undo Orders</button>
                        </div>
                    </div>
                </section>

                <!-- Step 6: Finish -->
                <section class="ekti-step" data-step="finish">
                    <h3 class="ekti-step-title">6. Finish <span class="ekti-step-badge"></span></h3>
                    <div class="ekti-step-body">
                        <div id="ekti-wz-finish-summary"></div>
                        <p class="description">Optional: remove the Organizer custom field left by EventKoi's native importer (Step 2 &rarr; Instructors &rarr; Custom Field Cleanup). The full run is recorded in the import log under Advanced tools.</p>
                        <div class="ekti-actions">
                            <button class="button button-secondary" id="ekti-wz-undo-all" style="color:#a00;">Undo Entire Import</button>
                        </div>
                        <div id="ekti-wz-finish-status" class="ekti-status"></div>
                    </div>
                </section>
            </div>

            <!-- Activity Panel -->
            <div class="ekti-panel" id="ekti-activity-panel">
                <h2>Activity</h2>
                <div id="ekti-console-wrap">
                    <div id="ekti-console" class="ekti-console"></div>
                </div>
            </div>

            <details class="ekti-advanced" id="ekti-advanced">
                <summary>Advanced tools</summary>
                <p class="description">Native-import adoption, the Event Sync undo ledger and the import log.</p>

                <!-- Native imports & Event Sync ledger -->
                <div class="ekti-panel" id="ekti-event-sync-panel">
                    <h2>Native Imports &amp; Event Sync Ledger</h2>
                    <p><strong>Adopt Native Imports</strong> flags the TEC WooCommerce attendees behind events EventKoi's own importer already brought over, so the wizard's order matching step does not import them twice. <strong>Undo Last Event Sync</strong> reverts adoption and any single-pass Event Sync runs from earlier versions (ledger entries the wizard's per-step undo does not cover).</p>
                    <div class="ekti-actions">
                        <button class="button" id="ekti-event-sync-adopt">Adopt Native Imports</button>
                        <button class="button button-secondary" id="ekti-event-sync-undo" style="color:#a00;">Undo Last Event Sync</button>
                    </div>
                    <div id="ekti-event-sync-status" class="ekti-status"></div>
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
            </details>
        </div>
        <?php
    }
}

// Boot.
EventKoi_Tickets_Importer::instance();
