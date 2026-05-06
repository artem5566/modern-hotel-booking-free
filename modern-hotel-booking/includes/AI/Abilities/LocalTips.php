<?php
/**
 * Ability: Get Local Tips
 *
 * Provides localized recommendations for dining, attractions, and transit.
 *
 * @package MHBO\AI\Abilities
 * @since   2.4.5
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

use MHBO\Core\I18n;

if ( ! defined( 'ABSPATH' ) ) {
    exit();
}

class LocalTips {

    /**
     * Return the tool definition.
     *
     * @return array<mixed>
     */
    public static function get_definition(): array {
        return [
            'name'        => __( 'Get Local Concierge Tips', 'modern-hotel-booking' ),
            'description' => __( 'Retrieve recommendations for nearby restaurants, attractions, and local travel tips.', 'modern-hotel-booking' ),
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'category' => [ 
                        'type' => 'string', 
                        'enum' => [ 'dining', 'attractions', 'transit', 'general' ],
                        'description' => 'Optional category to filter recommendations.'
                    ],
                ],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'tips'    => [ 'type' => 'string' ],
                    'message' => [ 'type' => 'string' ],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback'    => [ self::class, 'execute' ],
            'meta'                => [ 'mcp' => [ 'public' => true ] ],
        ];
    }

    /**
     * Execute the local tips retrieval.
     *
     * @param array<mixed> $args
     * @return array<mixed>
     */
    public static function execute( array $args ): array {
        $category = sanitize_text_field( (string) ( $args['category'] ?? 'general' ) );
        
        // Retrieve the admin-configured tips.
        $all_tips = (string) get_option( 'mhbo_ai_local_guide', '' );
        
        if ( '' === $all_tips ) {
            return [
                'tips'    => __( 'I don\'t have specific local tips configured yet. Please ask our front desk staff for personalized recommendations!', 'modern-hotel-booking' ),
                'message' => __( 'No local tips configured in settings.', 'modern-hotel-booking' )
            ];
        }

        // In a more advanced version, we could parse by category. 
        // For now, we return the full guide as it's typically a summary.
        return [
            'tips'    => "---\n" . I18n::decode( $all_tips ) . "\n---",
            // translators: %s: recommendation category
            'message' => sprintf( __( 'Showing local recommendations for: %s', 'modern-hotel-booking' ), (string) $category )
        ];
    }

    /**
     * Register as a WordPress Ability.
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        wp_register_ability( 'mhbo/get-local-tips', self::get_definition() );
    }
}
