<?php
/**
 * Ability: Get Room Details
 *
 * Returns detailed information about a specific room type.
 *
 * @package MHBO\AI\Abilities
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

use MHBO\AI\KnowledgeBase;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RoomDetails {

    /**
     * Return the WP Ability / MCP tool definition.
     *
     * @return array<mixed>
     */
    public static function get_definition(): array {
        return [
            'name'        => __( 'Get Room Details', 'modern-hotel-booking' ),
            'description' => __( 'Get detailed information about a room type including amenities, capacity, and pricing.', 'modern-hotel-booking' ),
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'room_id' => [ 'type' => 'string', 'description' => 'Room type ID (integer) or name.' ],
                ],
                'required' => [ 'room_id' ],
            ],
            'output_schema' => [
                'type' => 'object',
            ],
            'permission_callback' => '__return_true',
            'execute_callback'    => [ self::class, 'execute' ],
            'meta'                => [ 'mcp' => [ 'public' => true ] ],
        ];
    }

    /**
     * Execute the ability.
     *
     * @param array<mixed> $args
     * @return array<mixed>|array{error:string}
     */
    public static function execute( array $args ): array {
        global $wpdb;

        $raw_id = sanitize_text_field( (string) ( $args['room_id'] ?? '' ) );

        if ( '' === $raw_id ) {
            return [ 'error' => __( 'room_id is required.', 'modern-hotel-booking' ) ];
        }

        // Try numeric ID first, then name search.
        if ( is_numeric( $raw_id ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $type = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}mhbo_room_types WHERE id = %d",
                    (int) $raw_id
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $type = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}mhbo_room_types WHERE name LIKE %s LIMIT 1",
                    '%' . $wpdb->esc_like( $raw_id ) . '%'
                )
            );
        }

        if ( ! $type ) {
            return [ 'error' => __( 'Room type not found.', 'modern-hotel-booking' ) ];
        }

        $type_id = (int) $type->id;
        $currency = Pricing::get_currency_code();

        // Fetch one representative room to get a real-time daily price.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $room = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, custom_price FROM {$wpdb->prefix}mhbo_rooms WHERE type_id = %d LIMIT 1",
                $type_id
            )
        );

        // Ensure Pro seasonal/weekend rules are initialised before price lookup.
        Pricing::ensure_pro_init();

        // Use the Money-native daily price engine (respects seasonal rules, custom prices).
        // Falls back to base_price if no representative room exists.
        if ( $room ) {
            Pricing::prime_room_cache( [ (int) $room->id ] );
            $price_money = Pricing::calculate_daily_price_money( (int) $room->id, HotelTime::today() );
        } else {
            $price_money = Money::fromDecimal( (string) ( $type->base_price ?? '0' ), $currency );
        }

        $display_price = (float) $price_money->toDecimal();

        $amenities = [];
        if ( isset( $type->amenities ) && $type->amenities ) {
            $a = maybe_unserialize( $type->amenities );
            if ( is_array( $a ) ) {
                $amenities = $a;
            }
        }

        return [
            'room_id'          => $type_id,
            'name'             => I18n::decode( (string) ( $type->name ?? '' ) ),
            'description'      => wp_strip_all_tags( I18n::decode( (string) ( $type->description ?? '' ) ) ),
            'type'             => 'room_type',
            'capacity'         => [
                'adults'   => (int) ( $type->max_adults   ?? 0 ),
                'children' => (int) ( $type->max_children ?? 0 ),
            ],
            'price_per_night'  => $display_price,
            'currency'         => $currency,
            'price_formatted'  => $price_money->format(),
            'amenities'        => $amenities,
            'thumbnail_url'    => esc_url( (string) ( $type->image_url ?? '' ) ),
            'booking_url'      => KnowledgeBase::get_booking_url(),
            'total_rooms'      => (int) ( $type->total_rooms ?? 0 ),
        ];
    }

    /**
     * Register as a WordPress Ability (WP 7.0+).
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        wp_register_ability( 'mhbo/get-room-details', self::get_definition() );
    }
}
