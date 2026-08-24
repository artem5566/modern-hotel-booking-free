<?php
declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI Vector Cache
 *
 * Handles saving and retrieving semantic vectors to/from the mhbo_vector_cache table.
 *
 * @package MHBO\AI
 * @since 2.4.9.5
 */
class VectorCache {

    /**
     * Store an embedding vector for a given object.
     *
     * @param string $object_type E.g., 'post', 'room'.
     * @param int $object_id The ID of the object.
     * @param string $content_hash MD5 hash of the content embedded.
     * @param array<float> $vector The embedding vector.
     * @return bool True on success, false on failure.
     */
    public static function store( string $object_type, int $object_id, string $content_hash, array $vector ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'mhbo_vector_cache';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->replace(
            $table,
            [
                'object_type'      => $object_type,
                'object_id'        => $object_id,
                'content_hash'     => $content_hash,
                'embedding_vector' => wp_json_encode( $vector ),
            ],
            [ '%s', '%d', '%s', '%s' ]
        );

        return false !== $result;
    }
    
    /**
     * Hooks to trigger vector generation automatically on post save.
     */
    public static function register_hooks(): void {
        add_action( 'save_post', [ self::class, 'handle_post_save' ], 10, 3 );
    }

    /**
     * Schedule a background job to generate vector when a knowledge base post is saved.
     * 
     * @param int $post_id
     * @param \WP_Post $post
     * @param bool $update
     */
    public static function handle_post_save( int $post_id, \WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( 'mhbo_kb' !== $post->post_type ) {
            return;
        }

        // Schedule Action Scheduler job instead of blocking the save process
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            \as_enqueue_async_action( 'mhbo_generate_vector', [ $post_id, 'post' ] );
        }
    }
}
