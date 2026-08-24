<?php

/**
 * Ability: Get Price Breakdown
 *
 * Provides an itemized pricing breakdown for a stay including base nightly rates,
 * seasonal adjustments, extra guest fees, taxes, deposits, and promo discounts.
 *
 * @package MHBO\AI\Abilities
 * @since   2.6.0
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MHBO\Core\Pricing;
use MHBO\Core\HotelTime;
use MHBO\Core\Money;
use MHBO\Core\License;

use function __;
use function absint;
use function sanitize_text_field;
use function strtotime;
use function count;
use function is_array;
use function sprintf;
use function gmdate;
use function get_option;

class GetPriceBreakdown {

    /**
     * Return the WP Ability / MCP tool definition.
     *
     * @return array<mixed>
     */
    public static function get_definition(): array {
        $properties = [
            'check_in'  => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-in date (YYYY-MM-DD).' ],
            'check_out' => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-out date (YYYY-MM-DD).' ],
            'room_id'   => [ 'type' => 'integer', 'description' => 'Optional room ID or room type ID.' ],
            'adults'    => [ 'type' => 'integer', 'default' => 2, 'minimum' => 1, 'description' => 'Number of adults.' ],
            'children'  => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Number of children.' ],
        ];

return [
            'label'        => __( 'Get Price Breakdown', 'modern-hotel-booking' ),
            'description'  => 'Get an itemized price breakdown for a stay including nightly rates, taxes, deposits, and discounts.',
            'category'     => 'booking-management',
            'input_schema' => [
                'type'       => 'object',
                'properties' => $properties,
                'required'   => [ 'check_in', 'check_out' ],
            ],
            'output_schema' => [
                'type' => 'object',
            ],
            'permission_callback' => '__return_true',
            'execute_callback'    => [ self::class, 'execute' ],
            'meta'                => [
                'mcp'          => [ 'public' => true ],
                'show_in_rest' => true,
            ],
        ];
    }

    /**
     * Execute price breakdown calculation using modern Money-based engine.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function execute( array $args ): array {
        $check_in_raw  = sanitize_text_field( (string) ( $args['check_in'] ?? '' ) );
        $check_out_raw = sanitize_text_field( (string) ( $args['check_out'] ?? '' ) );

        if ( '' === $check_in_raw || '' === $check_out_raw ) {
            return [
                'error'   => true,
                'message' => 'Both check_in and check_out dates are required.',
            ];
        }

        $t_in  = strtotime( $check_in_raw );
        $t_out = strtotime( $check_out_raw );

        if ( ! $t_in || ! $t_out ) {
            return [
                'error'   => true,
                'message' => 'Invalid date format provided. Use YYYY-MM-DD.',
            ];
        }

        $today_ymd = HotelTime::today();
        $today     = strtotime( $today_ymd );
        if ( $t_in < $today ) {
            return [
                'error'   => true,
                'message' => 'Check-in date cannot be in the past.',
            ];
        }

        if ( $t_in >= $t_out ) {
            return [
                'error'   => true,
                'message' => 'Check-out date must be after check-in date.',
            ];
        }

        $adults   = absint( $args['adults'] ?? 2 );
        $children = absint( $args['children'] ?? 0 );

        $room_id = 0;
        if ( isset( $args['room_id'] ) ) {
            $room_id = absint( $args['room_id'] );
        } elseif ( isset( $args['type_id'] ) ) {
            $type_id = absint( $args['type_id'] );
            $found   = Pricing::find_available_room( $type_id, $check_in_raw, $check_out_raw, $adults + $children );
            $room_id = ( false !== $found ) ? $found : $type_id;
        }
        if ( $room_id <= 0 ) {
            $room_id = 1;
        }

        $child_ages = [];
        $promo_code = '';

$nights_count = (int) ( ( $t_out - $t_in ) / DAY_IN_SECONDS );

        // 2026 BP: Use calculate_booking_money for precise financial calculation
        $calc = Pricing::calculate_booking_money( $room_id, $check_in_raw, $check_out_raw, $adults, [], $children, $child_ages );
        if ( ! is_array( $calc ) ) {
            return [
                'error'   => true,
                'message' => 'Unable to calculate price for the specified room and dates.',
            ];
        }

        $currency = (string) get_option( 'mhbo_currency_code', 'RON' );

        $daily_breakdown = [];
        if ( isset( $calc['daily_prices'] ) && is_array( $calc['daily_prices'] ) ) {
            $t_start = HotelTime::midnight( $check_in_raw );
            foreach ( $calc['daily_prices'] as $i => $m ) {
                $day_date                     = HotelTime::date( 'Y-m-d', $t_start + $i * DAY_IN_SECONDS );
                $daily_breakdown[ $day_date ] = $m instanceof Money ? (float) $m->toDecimal() : (float) $m;
            }
        }

        $subtotal = $calc['subtotal'] instanceof Money ? (float) $calc['subtotal']->toDecimal() : 0.0;
        $total    = $calc['total'] instanceof Money ? (float) $calc['total']->toDecimal() : 0.0;

        $itemized = [
            'room_id'       => $room_id,
            'check_in'      => $check_in_raw,
            'check_out'     => $check_out_raw,
            'nights_count'  => $calc['nights'] ?? $nights_count,
            'adults'        => $adults,
            'children'      => $children,
            'currency'      => $currency,
            'pricing'       => [
                'subtotal'       => $subtotal,
                'tax_total'      => isset( $calc['tax']['tax_amount'] ) ? (float) $calc['tax']['tax_amount'] : 0.0,
                'total_price'    => $total,
            ],
            'nightly_rates' => $daily_breakdown,
        ];

        return $itemized;
    }

    /**
     * WP 7.0 native ability registration.
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        call_user_func( 'wp_register_ability', 'mhbo/get-price-breakdown', self::get_definition() );
    }
}
