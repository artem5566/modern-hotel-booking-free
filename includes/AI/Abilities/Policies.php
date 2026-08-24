<?php
/**
 * Ability: Get Hotel Policies
 *
 * Returns hotel policies (cancellation, check-in/out, pets, smoking, etc.)
 *
 * @package MHBO\AI\Abilities
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Policies {

    private const POLICY_KEYS = [
        'cancellation',
        'checkin',
        'checkout',
        'pets',
        'smoking',
        'children',
        'payment',
    ];

    /**
     * Return the WP Ability / MCP tool definition.
     *
     * @return array<mixed>
     */
    public static function get_definition(): array {
        return [
            'label'       => __( 'Get Hotel Policies', 'modern-hotel-booking' ),
            'description' => __( 'Retrieve hotel policies including cancellation, check-in/out, pets, smoking, and payment terms.', 'modern-hotel-booking' ),
            'category'    => 'hotel-information',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'type' => [
                        'type'    => 'string',
                        'enum'    => array_merge( self::POLICY_KEYS, [ 'all' ] ),
                        'default' => 'all',
                        'description' => 'Which policy to retrieve.',
                    ],
                ],
                'required' => [],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'policies' => [ 'type' => 'object' ],
                ],
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
     * Execute the ability.
     *
     * @param array<mixed> $args
     * @return array<mixed>
     */
    public static function execute( array $args = [] ): array {
        $type = sanitize_text_field( (string) ( $args['type'] ?? 'all' ) );

        // Map policy key → option name.
        $option_map = [
            'cancellation' => 'mhbo_cancellation_policy',
            'checkin'      => 'mhbo_checkin_policy',
            'checkout'     => 'mhbo_checkout_policy',
            'pets'         => 'mhbo_pet_policy',
            'smoking'      => 'mhbo_smoking_policy',
            'children'     => 'mhbo_children_policy',
            'payment'      => 'mhbo_payment_policy',
        ];

        // Fallback: synthesize checkin/checkout from times if no explicit policy.
        $checkin_time  = (string) get_option( 'mhbo_checkin_time', '14:00' );
        $checkout_time = (string) get_option( 'mhbo_checkout_time', '11:00' );

        $all_policies = [];
        foreach ( $option_map as $key => $option ) {
            $value = (string) get_option( $option, '' );
            if ( '' === $value ) {
                $value = match ( (string) $key ) {
                    // translators: %s: check-in time
                    'checkin'  => sprintf( __( 'Check-in is from %s.', 'modern-hotel-booking' ), (string) $checkin_time ),
                    // translators: %s: check-out time
                    'checkout' => sprintf( __( 'Check-out is by %s.', 'modern-hotel-booking' ), (string) $checkout_time ),
                    default    => __( 'Please contact the hotel for details.', 'modern-hotel-booking' ),
                };
            }
            $all_policies[ $key ] = $value;
        }

        if ( 'all' === $type || ! in_array( $type, self::POLICY_KEYS, true ) ) {
            return [ 'policies' => $all_policies ];
        }

        return [ 'policies' => [ $type => $all_policies[ $type ] ?? '' ] ];
    }

    /**
     * Register as a WordPress Ability (WP 7.0+).
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        call_user_func( 'wp_register_ability', 'mhbo/get-policies', self::get_definition() );
    }
}
