<?php
/**
 * Ability: Get Knowledge Base
 *
 * Returns the full site knowledge base text, optionally filtered by query.
 *
 * @package MHBO\AI\Abilities
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

use MHBO\AI\SiteScanner;
use MHBO\Business\Info;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GetKnowledgeBase {

    /**
     * Return the WP Ability / MCP tool definition.
     *
     * @return array<mixed>
     */
    public static function get_definition(): array {
        return [
            'label'       => __( 'Get Knowledge Base', 'modern-hotel-booking' ),
            'description' => __( 'Returns the hotel knowledge base. Optionally filter by a search query to retrieve relevant sections.', 'modern-hotel-booking' ),
            'category'    => 'hotel-information',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'query' => [ 'type' => 'string', 'description' => 'Optional search query to filter KB sections.' ],
                ],
                'required' => [],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'knowledge_base' => [ 'type' => 'string' ],
                    'last_updated'   => [ 'type' => 'string' ],
                    'hotel_name'     => [ 'type' => 'string' ],
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
        $query = sanitize_text_field( (string) ( $args['query'] ?? '' ) );
        $kb    = SiteScanner::get_or_build();

        // Simple section filtering by query.
        if ( '' !== $query ) {
            $kb = self::filter_by_query( $kb, $query );
        }

        $company    = Info::get_company();
        $hotel_name = (string) ( $company['company_name'] ?: get_bloginfo( 'name' ) );

        // Last-updated is when the snapshot option was last touched.
        $last_updated = (string) get_option( 'mhbo_kb_snapshot_updated', '' );
        if ( '' === $last_updated ) {
            $last_updated = (string) gmdate( 'Y-m-d H:i:s' );
        }

        return [
            'knowledge_base' => $kb,
            'last_updated'   => $last_updated,
            'hotel_name'     => $hotel_name,
        ];
    }

    /**
     * Filter KB text by returning sections that contain the query string.
     *
     * Splits by === headers and returns matching sections.
     *
     * @param string $kb
     * @param string $query
     * @return string
     */
    private static function filter_by_query( string $kb, string $query ): string {
        // Split into sections (delimited by lines starting with ===).
        $sections = preg_split( '/(?=^=== )/m', $kb );
        if ( ! is_array( $sections ) ) {
            return $kb;
        }

        $lower_query = mb_strtolower( $query );
        $matched     = [];

        foreach ( $sections as $section ) {
            if ( str_contains( mb_strtolower( $section ), $lower_query ) ) {
                $matched[] = trim( $section );
            }
        }

        return [] === $matched ? $kb : implode( "\n\n", $matched );
    }

    /**
     * Register as a WordPress Ability (WP 7.0+).
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        call_user_func( 'wp_register_ability', 'mhbo/get-knowledge-base', self::get_definition() );
    }
}
