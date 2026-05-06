<?php
/**
 * Ability: Check Room Availability
 *
 * Queries the plugin's existing availability system and returns available
 * room types with real-time pricing.
 *
 * @package MHBO\AI\Abilities
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

use MHBO\AI\KnowledgeBase;
use MHBO\Core\Pricing;
use MHBO\Core\HotelTime;
use MHBO\Core\Cache;
use MHBO\Core\I18n;
use MHBO\Core\License;
use MHBO\Core\Money;
use MHBO\Core\Tax;

use function __;
use function _n;
use function absint;
use function add_query_arg;
use function array_filter;
use function array_map;
use function bcmul;
use function ceil;
use function count;
use function defined;
use function esc_url;
use function floor;
use function function_exists;
use function get_option;
use function implode;
use function is_array;
use function json_decode;
use function max;
use function min;
use function preg_replace;
use function round;
use function sanitize_email;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function stripos;
use function strtotime;
use function time;
use function wp_create_nonce;
use function wp_json_encode;
use function wp_register_ability;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class CheckAvailability {

    /**
     * Return the WP Ability / MCP tool definition.
     *
     * @return array<mixed>
     */
    public static function get_definition(): array {
        return [
            'name'        => \__( 'Check Room Availability', 'modern-hotel-booking' ),
            'description' => \__( 'Check real-time room availability for specific dates and guest count. Returns available room types with pricing.', 'modern-hotel-booking' ),
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'check_in'  => [ 'type' => 'string', 'format' => 'date',    'description' => 'Check-in date.' ],
                    'check_out' => [ 'type' => 'string', 'format' => 'date',    'description' => 'Check-out date.' ],
                    'adults'    => [ 'type' => 'integer', 'default' => 2, 'minimum' => 1, 'maximum' => 10 ],
                    'children'  => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0 ],
                    
                    'room_type'  => [ 'type' => 'string',  'description' => 'Optional filter by room type name or ID.' ],
                    'guest_name' => [ 'type' => 'string',  'description' => 'Optional: Guest full name.' ],
                    'guest_email'=> [ 'type' => 'string',  'description' => 'Optional: Guest email.' ],
                    'guest_phone'=> [ 'type' => 'string',  'description' => 'Optional: Guest phone.' ],
                ],
                'required' => [ 'check_in', 'check_out' ],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'available'    => [ 'type' => 'boolean' ],
                    'rooms'        => [ 
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'room_id'         => [ 'type' => 'integer' ],
                                'room_name'       => [ 'type' => 'string' ],
                                'units_remaining' => [ 'type' => 'integer' ],
                                'total_price'     => [ 'type' => 'number' ],
                                'price_formatted' => [ 'type' => 'string' ],
                                'booking_url'     => [ 'type' => 'string' ],
                            ]
                        ]
                    ],
                    'nights_count' => [ 'type' => 'integer' ],
                    'search_url'   => [ 'type' => 'string' ],
                    'message'      => [ 'type' => 'string' ],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback'    => [ self::class, 'execute' ],
            'meta'                => [ 'mcp' => [ 'public' => true ] ],
        ];
    }

    /**
     * Execute the availability check.
     *
     * @param array<mixed> $args
     * @return array<mixed>
     */
    public static function execute( array $args ): array {
        global $wpdb;

        $check_in  = (string) \preg_replace( '/[^0-9\-]/', '', \sanitize_text_field( (string) ( $args['check_in']  ?? '' ) ) );
        $check_out = (string) \preg_replace( '/[^0-9\-]/', '', \sanitize_text_field( (string) ( $args['check_out'] ?? '' ) ) );
        $adults    = \max( 1, (int) ( $args['adults']   ?? 2 ) );
        $children  = \max( 0, (int) ( $args['children'] ?? 0 ) );

$child_ages = [];
        
        $room_type  = \sanitize_text_field( (string) ( $args['room_type']  ?? '' ) );
        $guest_name = \sanitize_text_field( (string) ( $args['guest_name'] ?? '' ) );
        $guest_email= \sanitize_email( (string) ( $args['guest_email']    ?? '' ) );
        $guest_phone= \sanitize_text_field( (string) ( $args['guest_phone'] ?? '' ) );

        // Validate dates.
        $today = (string) HotelTime::today();
        if ( $check_in < $today ) {
            return [ 'available' => false, 'rooms' => [], 'nights_count' => 0, 'message' => \__( 'Check-in date cannot be in the past.', 'modern-hotel-booking' ) ];
        }
        if ( $check_out <= $check_in ) {
            return [ 'available' => false, 'rooms' => [], 'nights_count' => 0, 'message' => \__( 'Check-out must be after check-in.', 'modern-hotel-booking' ) ];
        }

        $check_in_ts  = \strtotime( $check_in );
        $check_out_ts = \strtotime( $check_out );

        if ( ! $check_in_ts || ! $check_out_ts || $check_in_ts < \time() - \DAY_IN_SECONDS ) {
            return [ 'available' => false, 'rooms' => [], 'nights_count' => 0, 'message' => \__( 'Invalid date format.', 'modern-hotel-booking' ) ];
        }

        $nights = (int) ( ( (int) $check_out_ts - (int) $check_in_ts ) / \DAY_IN_SECONDS );

        if ( $nights > 365 ) {
            return [ 'available' => false, 'rooms' => [], 'nights_count' => 0, 'message' => \__( 'Stay cannot exceed 365 nights.', 'modern-hotel-booking' ) ];
        }

// Fetch rooms from DB (mirrors RestApi::get_availability).
        $cache_key = 'rooms_with_types_ai';
        $rooms     = Cache::get_query( (string) $cache_key, Cache::TABLE_ROOMS );

        if ( false === $rooms ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rooms = $wpdb->get_results(
                "SELECT r.id AS room_id, r.room_number, r.status, r.custom_price,
                        t.id AS type_id, t.name AS type_name, t.base_price, t.max_adults, t.max_children, t.amenities, t.image_url
                 FROM {$wpdb->prefix}mhbo_rooms r
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                 ORDER BY t.id, r.id"
            );
            Cache::set_query( $cache_key, $rooms, Cache::TABLE_ROOMS, \HOUR_IN_SECONDS );
        }

        if ( [] === (array) $rooms ) {
            return [ 'available' => false, 'rooms' => [], 'nights_count' => $nights, 'message' => \__( 'No rooms found.', 'modern-hotel-booking' ) ];
        }

        // Bulk prime pricing cache and ensure Pro seasonal/weekend rules are loaded.
        $room_ids = \array_map( fn( $r ) => (int) $r->room_id, (array) $rooms );
        Pricing::prime_room_cache( $room_ids );
        Pricing::ensure_pro_init();

        $currency     = Pricing::get_currency_code();
        $base_url     = KnowledgeBase::get_booking_url();
        $type_inventory = [];
        $available      = [];

        // 1. Group rooms by type and count total physical units.
        foreach ( (array) $rooms as $room ) {
            $type_id = (int) $room->type_id;
            if ( ! isset( $type_inventory[ $type_id ] ) ) {
                $type_inventory[ $type_id ] = [
                    'count'              => 0,
                    'room'               => $room,
                    'available_room_ids' => [], // Only rooms that pass live availability check.
                ];
            }
            if ( true === Pricing::is_room_available( (int) $room->room_id, $check_in, $check_out ) ) {
                $type_inventory[ $type_id ]['count']++;
                $type_inventory[ $type_id ]['available_room_ids'][] = [
                    'id'     => (int) $room->room_id,
                    'number' => (string) ( $room->room_number ?? '' ),
                ];
            }
        }

        // 2. Build the output list for the AI.
        foreach ( $type_inventory as $type_id => $data ) {
            $room       = $data['room'];
            $count      = (int) $data['count'];
            $room_id    = (int) $room->room_id;
            $max_a      = (int) $room->max_adults;
            $max_c      = (int) $room->max_children;
            $max_guests = $max_a + $max_c;

            if ( $count <= 0 ) {
                continue;
            }

            // Optional type filter.
            if ( $room_type && $room_type !== (string) $type_id && \stripos( I18n::decode( (string) $room->type_name ), $room_type ) === false ) {
                continue;
            }

            // --- Multi-Room Suggestion Logic ---
            if ( $adults > $max_a || $children > $max_c ) {
                $multi_room_handled = false;
                
                // Fallback: estimate multi-room cost from single-room price when Pro pricing is unavailable.
                if ( ! $multi_room_handled ) {
                    $min_rooms            = (int) \max(
                        $max_a > 0 ? (int) \ceil( $adults / $max_a ) : 1,
                        $max_c > 0 && $children > 0 ? (int) \ceil( $children / $max_c ) : 1
                    );
                    $avail_rooms_for_type = (array) $data['available_room_ids'];

                    if ( $count >= $min_rooms ) {
                        $single_calc = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, \min( $adults, $max_a ), [], \min( $children, $max_c ), [] );
                        $est_total   = null;
                        $est_fmt     = null;
                        if ( isset( $single_calc['total'] ) && $single_calc['total'] instanceof Money ) {
                            $est_decimal = \bcmul( $single_calc['total']->toDecimal(), (string) $min_rooms, 2 );
                            $est_total   = (float) $est_decimal;
                            $est_fmt     = '~' . Money::fromDecimal( $est_decimal, (string) $currency )->format();
                        }

                        // Build per-room distribution from confirmed available rooms so the AI can
                        // call create_booking_link once per room with the correct guest split.
                        $a_left       = $adults;
                        $c_left       = $children;
                        $distribution = [];
                        for ( $i = 0; $i < $min_rooms; $i++ ) {
                            $slot_a     = \min( $a_left, $max_a );
                            $slot_c     = $c_left > 0 ? \min( $c_left, $max_c ) : 0;
                            $a_left    -= $slot_a;
                            $c_left     = \max( 0, $c_left - $slot_c );
                            $avail_room = $avail_rooms_for_type[ $i ] ?? [ 'id' => $room_id, 'number' => '' ];
                            $distribution[] = [
                                'room_id'     => (int) $avail_room['id'],
                                'room_number' => (string) $avail_room['number'],
                                'adults'      => (int) $slot_a,
                                'children'    => (int) $slot_c,
                            ];
                        }

                        $available[] = [
                            'room_id'         => $room_id,
                            'room_name'       => I18n::decode( (string) $room->type_name ),
                            'room_type'       => 'type_' . (int) $type_id,
                            'is_multi_room'   => true,
                            'num_rooms'       => $min_rooms,
                            'units_remaining' => (int) \floor( $count / $min_rooms ),
                            'price_per_night' => $est_total !== null && $nights > 0 ? \round( $est_total / $nights, 2 ) : null,
                            'total_price'     => $est_total,
                            'price_formatted' => $est_fmt,
                            'currency'        => $currency,
                            'max_adults'      => $max_a,
                            'max_children'    => $max_c,
                            'distribution'    => $distribution,
                        ];
                    }
                }
                continue;
            }

            // --- Single Room Logic ---
            $calc = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $adults, [], $children, $child_ages );
            if ( ! isset( $calc['total'] ) || ! ( $calc['total'] instanceof Money ) ) {
                continue;
            }

            $total_money = $calc['total'];

            $room_amenities = [];
            if ( isset( $room->amenities ) && '' !== (string) $room->amenities ) {
                $a = \json_decode( (string) $room->amenities, true );
                if ( \is_array( $a ) ) {
                    $room_amenities = $a;
                }
            }

            // PRE-FILLED LINK GENERATION (2026 HANDSHAKE)
            $nonce = \wp_create_nonce( 'mhbo_auto_action' );
            if ( \count( $child_ages ) > 0 ) {
                \set_transient( 'mhbo_child_ages_' . (string) $nonce, \wp_json_encode( $child_ages ), \HOUR_IN_SECONDS );
            }

            $room_booking_url = \add_query_arg( [
                'mhbo_auto_book' => 1,
                'mhbo_nonce'     => $nonce,
                'check_in'       => $check_in,
                'check_out'      => $check_out,
                'guests'         => $adults,
                'children'       => $children,
                'room_id'        => $room_id,
                'type_id'        => $type_id,
                'total_price'    => $total_money->toDecimal(),
                'customer_name'  => $guest_name,
                'customer_email' => $guest_email,
                'customer_phone' => $guest_phone,
            ], $base_url );

            $available[] = [
                'room_id'         => $room_id,
                'room_name'       => I18n::decode( (string) $room->type_name ),
                'room_type'       => 'type_' . (int) $type_id,
                'units_remaining' => $count,
                'price_per_night' => (int) $nights > 0 ? \round( (float) $total_money->toDecimal() / (int) $nights, 2 ) : 0.0,
                'total_price'     => (float) $total_money->toDecimal(),
                'price_formatted' => $total_money->format(),
                'currency'        => $currency,
                'max_adults'      => (int) $room->max_adults,
                'max_children'    => (int) $room->max_children,
                'max_guests'      => $max_guests,
                'amenities'       => $room_amenities,
                'thumbnail_url'   => \esc_url( (string) ( $room->image_url ?? '' ) ),
                'booking_url'     => \esc_url( (string) $room_booking_url ),
            ];
        }

        // Capacity guidelines — help the AI explain limits and child policies.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $all_types = $wpdb->get_results( \sprintf( "SELECT t.id, t.name, t.max_adults, t.max_children, t.child_age_free_limit FROM %smhbo_room_types t", $wpdb->prefix ) );
        $capacity_hints = [];
        foreach ( (array) $all_types as $t ) {
            $name = I18n::decode( (string) ($t->name ?? '') );
            $free = (int) $t->child_age_free_limit;
            if ( $free <= 0 ) {
                $free = (int) \get_option( 'mhbo_child_free_age', 0 );
            }
            $policy = ( $free > 0 )
                // translators: %d: age
                ? \sprintf( \__( ' (Children aged %d and under stay free)', 'modern-hotel-booking' ), $free )
                : \__( ' (Babies aged 0 stay free)', 'modern-hotel-booking' );

            $capacity_hints[] = \sprintf(
                // translators: 1: room name, 2: max adults, 3: max children, 4: total capacity, 5: age policy
                \__( '- %1$s: Max Adults %2$d, Max Children %3$d. Total: %4$d%5$s', 'modern-hotel-booking' ),
                $name,
                (int) $t->max_adults,
                (int) $t->max_children,
                ( (int) $t->max_adults + (int) $t->max_children ),
                $policy
            );
        }

        $search_url = KnowledgeBase::get_booking_url();
        $has_multi  = [] !== \array_filter( $available, fn( $r ) => ( $r['is_multi_room'] ?? false ) );
        $message    = [] === $available
            ? \__( 'No rooms available for the selected dates and guest count.', 'modern-hotel-booking' ) . "\n\n" . \__( 'Capacity Guidelines:', 'modern-hotel-booking' ) . "\n" . \implode( "\n", $capacity_hints )
            : \sprintf(
                // translators: %1$d number of rooms, %2$d nights
                \_n( '%1$d room type available for %2$d night.', '%1$d room types available for %2$d nights.', \count( $available ), 'modern-hotel-booking' ),
                \count( $available ),
                $nights
            );

        if ( [] !== $available ) {
            if ( $has_multi ) {
                $message .= ' ' . \__( 'Note: some options require multiple rooms to accommodate your group.', 'modern-hotel-booking' );
                // translators: This is an internal instruction for the AI model to handle multiple room reservations.
                $multi_room_guidance = \__( 'IMPORTANT: For multi-room options, I must create INDIVIDUAL booking cards for each room. I need the guest\'s Full Name, Email, and Phone to prepare these cards. Once they provide these details, I will generate the FIRST card and instruct them to return here for the next one.', 'modern-hotel-booking' );
            }
            $message .= "\n\n" . \__( 'Guests can book directly using the provided room links or view all options here: ', 'modern-hotel-booking' ) . $search_url;
        }

        // Include deposit policy with computed amounts so the AI can quote exact payment terms.
        $deposit_info = null;
        if ( (int) \get_option( 'mhbo_deposits_enabled', 0 ) ) {
            $deposit_type  = (string) \get_option( 'mhbo_deposit_type', 'percentage' );
            $deposit_value = (float)  \get_option( 'mhbo_deposit_value', 20 );
            $non_refund    = (bool)   \get_option( 'mhbo_deposit_non_refundable', 0 );

            // Compute the deposit amount for each available room so the AI can quote it.
            $deposit_examples = [];
            foreach ( $available as $room ) {
                $total = (float) $room['total_price'];
                $dep_amount = match ( $deposit_type ) {
                    'first_night' => (float) $room['price_per_night'],
                    'fixed'       => (float) $deposit_value,
                    default       => \round( (float) $total * (float) $deposit_value / 100, 2 ),
                };
                $deposit_examples[ (string) $room['room_name'] ] = [
                    'deposit_due'  => $dep_amount,
                    'balance_due'  => \round( $total - $dep_amount, 2 ),
                    'currency'     => $currency,
                ];
            }

            $deposit_info = [
                'enabled'          => true,
                'type'             => $deposit_type,
                'value'            => $deposit_value,
                'non_refundable'   => $non_refund,
                'per_room_amounts' => $deposit_examples,
            ];
        }

        return [
            'available'                => [] !== $available,
            'rooms'                    => $available,
            'nights_count'             => $nights,
            'check_in'                 => $check_in,
            'check_out'                => $check_out,
            'adults'                   => $adults,
            'children'                 => $children,
            'message'                  => $message,
            'search_url'               => $search_url,
            'deposit_info'             => $deposit_info,
            'is_pro'                   => false,
            'payment_methods_summary'  => CreateBookingLink::get_payment_summary(),
            'multi_room_guidance'      => $multi_room_guidance ?? '',
            'tax_note'                 => Tax::is_enabled()
                ? \sprintf( '%s %s%%', \get_option( 'mhbo_tax_label', 'VAT' ), \get_option( 'mhbo_tax_rate_accommodation', 0 ) )
                : '',
        ];
    }
    
    /**
     * Register as a WordPress Ability (WP 7.0+).
     */
    public static function register(): void {
        if ( ! \function_exists( 'wp_register_ability' ) ) {
            return;
        }
        \wp_register_ability( 'mhbo/check-availability', self::get_definition() );
    }
}
