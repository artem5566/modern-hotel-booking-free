<?php
/**
 * Ability: Get Hotel Information
 *
 * Returns hotel name, contact details, amenities, and check-in/out times.
 *
 * @package MHBO\AI\Abilities
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

use MHBO\Business\Info;
use MHBO\Core\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HotelInfo {

    /**
     * Return the WP Ability / MCP tool definition.
     *
     * @return array<mixed>
     */
    public static function get_definition(): array {
        return [
            'name'               => __( 'Get Hotel Information', 'modern-hotel-booking' ),
            'description'        => __( 'Returns the hotel name, description, location, contact details, amenities, and basic policies.', 'modern-hotel-booking' ),
            'input_schema'       => [ 'type' => 'object', 'properties' => (object) [], 'required' => [] ],
            'output_schema'      => [
                'type'       => 'object',
                'properties' => [
                    'hotel_name'      => [ 'type' => 'string' ],
                    'description'     => [ 'type' => 'string' ],
                    'address'         => [ 'type' => 'string' ],
                    'phone'           => [ 'type' => 'string' ],
                    'email'           => [ 'type' => 'string' ],
                    'checkin_time'    => [ 'type' => 'string' ],
                    'checkout_time'   => [ 'type' => 'string' ],
                    'currency'        => [ 'type' => 'string' ],
                    'amenities'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'policies_summary'=> [ 'type' => 'string' ],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback'    => [ self::class, 'execute' ],
            'meta'               => [ 'mcp' => [ 'public' => true ] ],
        ];
    }

    /**
     * Execute the ability.
     *
     * @param array<mixed> $args  Unused.
     * @return array<mixed>
     */
    public static function execute( array $args = [] ): array {
        $company = Info::get_company();

        $address_parts = array_filter( [
            (string) ( $company['address_line_1'] ?? '' ),
            (string) ( $company['address_line_2'] ?? '' ),
            (string) ( $company['city']           ?? '' ),
            (string) ( $company['state']          ?? '' ),
            (string) ( $company['postcode']       ?? '' ),
            (string) ( $company['country']        ?? '' ),
        ], fn( $val ) => '' !== $val );

        $amenities_raw = get_option( 'mhbo_amenities_list', [] );
        $amenities     = [];
        if ( is_array( $amenities_raw ) ) {
            foreach ( $amenities_raw as $item ) {
                if ( isset( $item['name'] ) && '' !== $item['name'] ) {
                    $amenities[] = sanitize_text_field( (string) $item['name'] );
                }
            }
        }

        $policies_parts = array_filter( [
            get_option( 'mhbo_cancellation_policy', '' ) ? 'Cancellation: ' . (string) get_option( 'mhbo_cancellation_policy' ) : '',
            get_option( 'mhbo_pet_policy', '' )          ? 'Pets: ' . (string) get_option( 'mhbo_pet_policy' ) : '',
            get_option( 'mhbo_smoking_policy', '' )      ? 'Smoking: ' . (string) get_option( 'mhbo_smoking_policy' ) : '',
        ], fn( $val ) => '' !== $val );

        return [
            'hotel_name'       => (string) ( $company['company_name'] ?: get_bloginfo( 'name' ) ),
            'description'      => (string) get_bloginfo( 'description' ),
            'address'          => implode( ', ', $address_parts ),
            'phone'            => (string) ( $company['telephone'] ?? '' ),
            'email'            => (string) ( $company['email'] ?? '' ),
            'checkin_time'     => (string) get_option( 'mhbo_checkin_time', '14:00' ),
            'checkout_time'    => (string) get_option( 'mhbo_checkout_time', '11:00' ),
            'currency'         => Pricing::get_currency_code(),
            'amenities'        => $amenities,
            'policies_summary' => implode( ' | ', $policies_parts ),
        ];
    }

    /**
     * Register as a WordPress Ability (WP 7.0+).
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        $def = self::get_definition();
        wp_register_ability( 'mhbo/get-hotel-info', $def );
    }
}
