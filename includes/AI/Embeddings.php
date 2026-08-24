<?php
declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_Error;

/**
 * AI Embeddings Wrapper
 *
 * Interfaces with configured LLM API (e.g., OpenAI text-embedding-3-small)
 * to generate semantic vectors for knowledge base and room data.
 *
 * @package MHBO\AI
 * @since 2.4.9.5
 */
class Embeddings {

    /**
     * Generate an embedding vector for a given string of text.
     *
     * @param string $text The text to embed.
     * @return array<float>|WP_Error Array of floats on success, WP_Error on failure.
     */
    public static function generate( string $text ): array|WP_Error {
        // In a full implementation, this calls the active LLM provider (OpenAI, Gemini, etc.)
        // For Phase 2 scaffolding, we simulate an API call or return a WP_Error if unconfigured.
        
        $api_key = get_option( 'mhbo_ai_api_key' );
        if ( ! $api_key ) {
            return new WP_Error( 'missing_api_key', 'AI API key is not configured.' );
        }

        // Dummy embedding generation for scaffolding
        // A real vector would be 1536 floats long for OpenAI.
        $vector = array_fill( 0, 1536, 0.01 );
        
        return $vector;
    }
}
