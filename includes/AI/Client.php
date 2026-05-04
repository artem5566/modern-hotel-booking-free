<?php
/**
 * AI Client — unified gateway to any configured AI provider.
 *
 * Supports:
 *  - WP 7.0+ native wp_ai_client() (when available).
 *  - WP 6.9 standalone wordpress/ai-client SDK (when installed via Composer).
 *  - Direct HTTP fallback using the stored API key from plugin settings.
 *
 * @package MHBO\AI
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Throwable;

class Client {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** Plugin-specific option prefix for AI settings. */
    private const OPT_PREFIX = 'mhbo_ai_';

    /** Provider identifiers. */
    public const PROVIDER_GEMINI    = 'gemini';
    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_OPENROUTER = 'openrouter';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OLLAMA    = 'ollama';
    public const PROVIDER_CUSTOM    = 'custom';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return a configured AI client object.
     *
     * Prefers the WP 7.0 native client, falls back to the standalone SDK,
     * then returns null (callers must use the prompt() method which handles all paths).
     *
     * @return mixed|null
     */
    public static function get_client(): mixed {
        // WP 7.0+ native AI Client.
        if ( function_exists( 'wp_ai_client' ) ) {
            return call_user_func( 'wp_ai_client' );
        }

        // Optimization 2026: Use dynamic class name to silence IDE "Undefined type" for optional SDKs.
        if ( class_exists( '\WordPress\AI\Client' ) ) {
            $provider        = self::get_configured_provider();
            $api_key         = self::get_fallback_api_key();
            $wp_client_class = '\WordPress\AI\Client';
            if ( $provider && $api_key ) {
                return new $wp_client_class( (string) $provider, [ 'api_key' => $api_key ] );
            }
        }

        return null;
    }

    /**
     * Send a prompt to the configured AI provider.
     *
     * @param array<array{role:string,content:string}>  $messages       Conversation history.
     * @param string                                    $system_prompt  System/context prompt.
     * @param array<mixed>                              $tools          Tool definitions (for agentic flow).
     *
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    public static function prompt( array $messages, string $system_prompt, array $tools = [] ): array {
        $empty = [ 'content' => '', 'tool_calls' => [], 'finish_reason' => '', 'error' => null ];

        // Global Quota Protection (2026.4 BP)
        if ( self::is_circuit_broken() ) {
            $lock      = (int) get_transient( 'mhbo_ai_quota_lock' );
            $remaining = max( 1, $lock - time() );
            return array_merge( $empty, [
                'error' => sprintf(
                    // translators: %1$d: seconds remaining before the AI concierge becomes available again
                    \MHBO\Core\I18n::get_label( 'ai_error_cooling_down_seconds' ),
                    (int) $remaining
                ),
            ] );
        }

        // ── Path 1: WP 7.0 native AI Client ──────────────────────────────────
        if ( function_exists( 'wp_ai_client' ) ) {
            try {
                $client   = call_user_func( 'wp_ai_client' );
                $payload  = self::build_native_payload( $messages, $system_prompt, $tools );
                $response = $client->chat( $payload );
                return self::parse_native_response( $response );
            } catch ( Throwable $e ) {
                self::log_error( 'wp_ai_client error: ' . $e->getMessage() );
                return array_merge( $empty, [ 'error' => $e->getMessage() ] );
            }
        }

        // ── Path 2: Standalone wordpress/ai-client SDK ────────────────────────
        if ( class_exists( '\WordPress\AI\Client' ) ) {
            try {
                $c = self::get_client();
                if ( $c ) {
                    $result = $c->chat( $messages, [ 'system' => $system_prompt, 'tools' => $tools ] );
                    return self::parse_sdk_response( $result );
                }
            } catch ( Throwable $e ) {
                self::log_error( 'AI SDK error: ' . $e->getMessage() );
                // Fall through to HTTP path.
            }
        }

        // ── Path 3: Direct HTTP call ──────────────────────────────────────────
        $result = self::http_prompt( $messages, $system_prompt, $tools );

        // ── Fallback Path (Pro / Resilience) ─────────────────────────────────
        if ( null !== ( $result['error'] ?? null ) ) {
            $fallback_provider = self::get_configured_fallback_provider();
            if ( $fallback_provider ) {
                self::log_error( "Primary AI failed (" . ( self::get_configured_provider() ?: 'native' ) . "): " . (string) $result['error'] . ". Attempting fallback to {$fallback_provider}..." );
                return self::fallback_prompt( $messages, $system_prompt, $tools );
            }
        }

        return $result;
    }

    /**
     * Return the configured AI provider slug.
     *
     * @return string|false
     */
    public static function get_configured_provider(): string|false {
        $provider = (string) get_option( self::OPT_PREFIX . 'provider', '' );
        $allowed  = [ self::PROVIDER_GEMINI, self::PROVIDER_OPENAI, self::PROVIDER_OPENROUTER, self::PROVIDER_ANTHROPIC, self::PROVIDER_OLLAMA, self::PROVIDER_CUSTOM ];
        return in_array( $provider, $allowed, true ) ? $provider : false;
    }

    /**
     * Read the API key from plugin settings (never exposed to the frontend).
     *
     * @return string
     */
    public static function get_fallback_api_key(): string {
        return (string) get_option( self::OPT_PREFIX . 'api_key', '' );
    }

    /**
     * Return the fallback AI provider slug.
     *
     * @return string|false
     */
    public static function get_configured_fallback_provider(): string|false {
        $provider = (string) get_option( self::OPT_PREFIX . 'fallback_provider', '' );
        $allowed  = [ self::PROVIDER_GEMINI, self::PROVIDER_OPENAI, self::PROVIDER_OPENROUTER, self::PROVIDER_ANTHROPIC, self::PROVIDER_OLLAMA, self::PROVIDER_CUSTOM ];
        return in_array( $provider, $allowed, true ) ? $provider : false;
    }

    /**
     * Read the fallback API key from plugin settings.
     *
     * @return string
     */
    public static function get_fallback_configured_api_key(): string {
        return (string) get_option( self::OPT_PREFIX . 'fallback_api_key', '' );
    }

    /**
     * Secondary fallback prompt — used when the primary connection fails.
     *
     * @param array<mixed> $messages
     * @param string       $system_prompt
     * @param array<mixed> $tools
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    public static function fallback_prompt( array $messages, string $system_prompt, array $tools = [] ): array {
        $provider = self::get_configured_fallback_provider();
        $api_key  = self::get_fallback_configured_api_key();
        $model    = (string) get_option( self::OPT_PREFIX . 'fallback_model', '' );

        if ( ! $provider || ! $api_key ) {
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => \MHBO\Core\I18n::get_label( 'ai_error_fallback_not_configured' ) ];
        }

        return match ( $provider ) {
            self::PROVIDER_GEMINI    => self::http_gemini( $api_key, $model ?: 'gemini-3.1-flash-lite-preview', $messages, $system_prompt, $tools ),
            self::PROVIDER_OPENAI    => self::http_openai( $api_key, $model ?: 'gpt-5.4-mini', $messages, $system_prompt, $tools, ( str_contains( $model, 'gpt-5' ) ) ? 'https://api.openai.com/v1/responses' : 'https://api.openai.com/v1/chat/completions' ),
            self::PROVIDER_OPENROUTER => self::http_openai( $api_key, $model, $messages, $system_prompt, $tools, 'https://openrouter.ai/api/v1/chat/completions' ),
            self::PROVIDER_ANTHROPIC => self::http_anthropic( $api_key, $model ?: 'claude-sonnet-4-6', $messages, $system_prompt, $tools ),
            self::PROVIDER_OLLAMA    => self::http_openai( '', $model ?: 'llama3', $messages, $system_prompt, $tools, (string) get_option( self::OPT_PREFIX . 'fallback_custom_url', 'http://localhost:11434/v1/chat/completions' ) ),
            self::PROVIDER_CUSTOM    => self::http_openai( $api_key, $model, $messages, $system_prompt, $tools, (string) get_option( self::OPT_PREFIX . 'fallback_custom_url', '' ) ),
            default                  => [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => \MHBO\Core\I18n::get_label( 'ai_error_unknown_fallback' ) ],
        };
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a payload for the WP 7.0 native AI client.
     *
     * @param array<mixed> $messages
     * @param string       $system_prompt
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    private static function build_native_payload( array $messages, string $system_prompt, array $tools ): array {
        $payload = [
            'messages' => array_merge(
                [ [ 'role' => 'system', 'content' => $system_prompt ] ],
                $messages
            ),
        ];
        if ( [] !== $tools ) {
            $payload['tools'] = $tools;
        }
        $model = (string) get_option( self::OPT_PREFIX . 'model', '' );
        if ( $model ) {
            $payload['model'] = $model;
        }
        return $payload;
    }

    /**
     * Parse a response from the WP 7.0 native AI client into our standard format.
     *
     * @param mixed $response
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    private static function parse_native_response( mixed $response ): array {
        if ( is_wp_error( $response ) ) {
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $response->get_error_message() ];
        }
        if ( is_array( $response ) ) {
            return [
                'content'       => (string) ( $response['content'] ?? $response['message']['content'] ?? '' ),
                'tool_calls'    => (array)  ( $response['tool_calls'] ?? [] ),
                'finish_reason' => (string) ( $response['finish_reason'] ?? 'stop' ),
                'error'         => null,
            ];
        }
        return [ 'content' => (string) $response, 'tool_calls' => [], 'finish_reason' => 'stop', 'error' => null ];
    }

    /**
     * Parse a response from the standalone wordpress/ai-client SDK.
     *
     * @param mixed $result
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    private static function parse_sdk_response( mixed $result ): array {
        if ( is_wp_error( $result ) ) {
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $result->get_error_message() ];
        }
        return self::parse_native_response( $result );
    }

    /**
     * Direct HTTP call handler — determines provider and routes to specific implementations.
     *
     * @param array<mixed> $messages
     * @param string       $system_prompt
     * @param array<mixed> $tools
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    private static function http_prompt( array $messages, string $system_prompt, array $tools ): array {
        $provider = self::get_configured_provider();
        $api_key  = self::get_fallback_api_key();
        $model    = (string) get_option( self::OPT_PREFIX . 'model', '' );
 
        if ( ! $provider || ! $api_key ) {
            return [
                'content'       => '',
                'tool_calls'    => [],
                'finish_reason' => 'error',
                'error'         => \MHBO\Core\I18n::get_label( 'ai_error_provider_not_configured' ),
            ];
        }
 
        switch ( $provider ) {
            case self::PROVIDER_GEMINI:
                return self::http_gemini( $api_key, $model ?: 'gemini-3.1-flash-lite-preview', $messages, $system_prompt, $tools );
 
            case self::PROVIDER_OPENAI:
                $endpoint = ( str_contains( $model, 'gpt-5' ) ) ? 'https://api.openai.com/v1/responses' : 'https://api.openai.com/v1/chat/completions';
                return self::http_openai( $api_key, $model ?: 'gpt-5.4-mini', $messages, $system_prompt, $tools, $endpoint );
 
            case self::PROVIDER_OPENROUTER:
                return self::http_openai( $api_key, $model, $messages, $system_prompt, $tools, 'https://openrouter.ai/api/v1/chat/completions' );
 
            case self::PROVIDER_ANTHROPIC:
                return self::http_anthropic( $api_key, $model ?: 'claude-sonnet-4-6', $messages, $system_prompt, $tools );
 
            case self::PROVIDER_OLLAMA:
                // Ollama is local-only by design; localhost is intentional.
                $base_url = (string) get_option( self::OPT_PREFIX . 'custom_url', 'http://localhost:11434/v1/chat/completions' );
                return self::http_openai( '', $model ?: 'llama3', $messages, $system_prompt, $tools, $base_url );
 
            case self::PROVIDER_CUSTOM:
                $custom_url = (string) get_option( self::OPT_PREFIX . 'custom_url', '' );
                if ( ! $custom_url || ! wp_http_validate_url( $custom_url ) ) {
                    return [
                        'content'       => '',
                        'tool_calls'    => [],
                        'finish_reason' => 'error',
                        'error'         => \MHBO\Core\I18n::get_label( 'ai_error_bad_custom_url' ),
                    ];
                }
                return self::http_openai( $api_key, $model, $messages, $system_prompt, $tools, $custom_url );
 
            default:
                return [
                    'content'       => '',
                    'tool_calls'    => [],
                    'finish_reason' => 'error',
                    // translators: %s: name of the AI provider
                    'error'         => sprintf( \MHBO\Core\I18n::get_label( 'ai_error_unknown_provider' ), esc_html( $provider ) ),
                ];
        }
    }

    /**
     * HTTP call to Google Gemini API — with automatic model fallback on overload.
     *
     * Primary model is tried first; on transient capacity errors (503 / "high demand")
     * we cascade through cheaper, more available models before giving up.
     *
     * API Version: v1beta is intentionally used (not v1 stable).
     * Reason: v1beta is the ONLY endpoint that supports system_instruction,
     * tools/function_declarations, and all Gemini 3.x preview models. Google's
     * stable v1 endpoint lags behind and does not expose these agentic features.
     * v1beta itself is NOT deprecated (April 2026 verified).
     *
     * Verified Fallback Chain (April 2026):
     *   gemini-3.1-flash-lite-preview → gemini-3.1-flash-preview → gemini-2.5-flash
     *   → gemini-2.5-pro → gemini-3.1-pro-preview (last resort, most expensive)
     *
     * EOL models removed: gemini-2.5-flash-lite-preview (shut down 2026-03-31),
     *                      gemini-1.5-flash (shut down 2026-03-31).
     *
     * @param string       $api_key
     * @param string       $model
     * @param array<mixed> $messages
     * @param string       $system_prompt
     * @param array<mixed> $tools
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    private static function http_gemini( string $api_key, string $model, array $messages, string $system_prompt, array $tools ): array {
        // Normalize retired model names to stable 2026 equivalents.
        // Retired as of March 31, 2026: gemini-1.5-*, gemini-2.0-*, gemini-2.5-flash-lite-preview.
        $model = str_replace( '-latest', '', $model ?: 'gemini-2.5-flash' );

        $retired_prefixes = [
            'gemini-1.5',
            'gemini-2.0',
        ];
        $retired_exact = [
            'gemini-2.5-flash-lite-preview', // EOL: 2026-03-31
            'gemini-1.5-flash',              // EOL: 2026-03-31
            'gemini-1.5-pro',                // EOL: 2026-03-31
        ];

        if ( in_array( $model, $retired_exact, true ) ) {
            self::log_error( "Model {$model} is EOL. Auto-migrating to gemini-2.5-flash." );
            $model = 'gemini-2.5-flash';
        } elseif ( [] !== array_filter( $retired_prefixes, fn( $p ) => str_starts_with( $model, $p ) ) ) {
            self::log_error( "Model {$model} uses a retired prefix. Auto-migrating to gemini-2.5-flash." );
            $model = 'gemini-2.5-flash';
        }

        // Build the Gemini-format contents array (OpenAI → Gemini conversion).
        $contents = self::build_gemini_contents( $messages );

        $body = [
            'system_instruction' => [ 'parts' => [ [ 'text' => $system_prompt ] ] ],
            'contents'           => $contents,
            'generationConfig'   => [ 'maxOutputTokens' => 2048 ],
        ];

        if ( [] !== $tools ) {
            $body['tools'] = [ [ 'function_declarations' => array_map( fn( $t ) => $t['function'] ?? $t, $tools ) ] ];
        }

        // 2026.4 BP: Cascade: primary model first, then cheaper/resilient fallbacks.
        // The chain is now dynamic, deprioritizing models with recent failures.
        $fallback_chain  = self::get_dynamic_fallback_chain( $model );
        $last_result     = [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => 'No models available.' ];
        $cascade_start   = \microtime( true );
        $cascade_budget  = 26.0; // Hard wall: entire cascade must finish within 26 s to stay under typical 30 s proxy timeouts.

        foreach ( $fallback_chain as $idx => $try_model ) {
            // Abort if we are already close to the proxy timeout wall.
            if ( ( \microtime( true ) - $cascade_start ) >= $cascade_budget ) {
                self::log_error( "Gemini cascade wall-clock budget ({$cascade_budget}s) exceeded — aborting." );
                break;
            }

            // Short inter-model pause — only on 2nd+ attempt to avoid hammering the API.
            // Kept to 250ms max so total request time stays well under server proxy timeouts.
            if ( $idx > 0 ) {
                \usleep( 250000 ); // 250ms
            }

            // Always use v1beta — it supports system_instruction, tools/function_declarations,
            // and generationConfig across all model generations. The v1 stable endpoint uses
            // a stricter camelCase-only JSON schema that breaks these fields.
            $url         = "https://generativelanguage.googleapis.com/v1beta/models/{$try_model}:generateContent?key=" . rawurlencode( $api_key );
            $last_result = self::http_gemini_request( $url, $body, $try_model );

            if ( null === $last_result['error'] ) {
                return $last_result; // Success — done.
            }

            // Record failure for this specific model to deprioritise it in the next request.
            self::record_model_failure( $try_model );

            if ( ! self::is_transient_gemini_error( (string) $last_result['error'] ) ) {
                // Defensive 404 Resilience: If a model alias is not found, skip to next in chain immediately.
                if ( \str_contains( \strtolower( (string) $last_result['error'] ), '404' ) || \str_contains( \strtolower( (string) $last_result['error'] ), 'not found' ) ) {
                    self::log_error( "Gemini model {$try_model} not found — skipping to next in chain." );
                    continue;
                }
                // Permanent error (bad key, invalid request…) — stop cascading immediately.
                return $last_result; 
            }

            // Exponential backoff with Jitter for transient errors (capped at 3s total per step for sync safety).
            // 2026 BP: Randomizing the sleep (jitter) prevents 'Thundering Herd' synchronized retries.
            $base_backoff = (int) \min( 2, \pow( 2, $idx ) );
            $jitter_ms    = \wp_rand( 0, 500 * 1000 ); // Up to 0.5s jitter
            self::log_error( "Gemini model {$try_model} error (transient) — marking as busy and moving to next in chain (" . ( $base_backoff + ( $jitter_ms / 1000000 ) ) . "s)." );
            
            if ( $base_backoff > 0 ) {
                \usleep( ( $base_backoff * 1000000 ) + $jitter_ms );
            }
        }

        // Entire cascade failed. Increment the global score and trip the main circuit breaker.
        self::increment_failure_score();
        return $last_result;
    }

    /**
     * Return the model fallback chain starting from the requested model.
     *
     * 2026.4 BP: Dynamic chain – models that failed in the last 5 minutes are deprioritized.
     * Note: This cascade prioritizes "Service Continuity" (ensuring the guest gets a response)
     * over "Absolute Minimum Cost". If the cheapest model is busy, the system will try
     * more available tiers in the ecosystem.
     *
     * April 2026 Verified Active Models (v1beta endpoint):
     *   PREVIEW  : gemini-3.1-flash-lite-preview ($0.25/M), gemini-3-flash-preview ($0.50/M), gemini-3.1-pro-preview ($2-4/M)
     *   STABLE GA: gemini-2.5-flash-lite ($0.10/M) — cheapest stable fallback
     *              gemini-2.5-flash ($0.30/M) — balanced stable fallback
     * EOL (DO NOT USE): gemini-3.1-flash-preview (404), gemini-2.5-flash-lite-preview (retired), gemini-1.5-*, gemini-3-pro-preview (v1)
     *
     * @param string $primary
     * @return list<string>
     */
    private static function get_dynamic_fallback_chain( string $primary ): array {
        // Alias map: allow shorthand admin selections to resolve to real model IDs.
        $blanket_map = [
            'gemini-stable-primary' => 'gemini-3-flash-preview',
            'gemini-stable-high'    => 'gemini-3.1-pro-preview',
            'gemini-flash-latest'   => 'gemini-3.1-flash-lite-preview',
            'gemini-pro-latest'     => 'gemini-3.1-pro-preview',
        ];

        if ( isset( $blanket_map[ $primary ] ) ) {
            $primary = $blanket_map[ $primary ];
        }

        // April 2026 Economic Cascade — ordered by cost (cheapest first), all verified active.
        // Source: https://ai.google.dev/gemini-api/docs/pricing (April 2026)
        // Preview models tried first (better capability); stable GA models are the safety net
        // when all previews are simultaneously at capacity (503).
        $default_models = [
            'gemini-3.1-flash-lite-preview', // Preview : $0.25–0.50/M input  — cheapest preview
            'gemini-3-flash-preview',        // Preview : $0.50/M input        — balanced preview
            'gemini-3.1-pro-preview',        // Preview : $2–4/M input         — most capable preview
            'gemini-2.5-flash-lite',         // GA/Stable: $0.10–0.30/M input — stable safety net
            'gemini-2.5-flash',              // GA/Stable: $0.30–1.00/M input — stable last resort
        ];

        // Separation: Reliable vs Unreliable (recently failed in last 5 min)
        $reliable   = [];
        $unreliable = [];

        foreach ( $default_models as $m ) {
            if ( get_transient( 'mhbo_ai_model_fail_' . $m ) ) {
                $unreliable[] = $m;
            } else {
                $reliable[] = $m;
            }
        }

        // Put primary first if it's reliable; otherwise it goes back with the unreliable.
        $chain = [];
        if ( ! in_array( $primary, $unreliable, true ) ) {
            $chain[] = $primary;
        }

        foreach ( $reliable as $m ) {
            if ( $m !== $primary ) {
                $chain[] = $m;
            }
        }

        // Always append unreliable ones at the very end as a last resort.
        foreach ( $unreliable as $m ) {
            if ( ! in_array( $m, $chain, true ) ) {
                $chain[] = $m;
            }
        }

        return $chain;
    }

    /**
     * Mark a specific model as unreliable for 5 minutes.
     *
     * @param string $model
     */
    private static function record_model_failure( string $model ): void {
        set_transient( 'mhbo_ai_model_fail_' . $model, 1, 300 );
    }

    /**
     * 2026.4 BP: Increment system-wide failure score and apply tiered locking.
     */
    private static function increment_failure_score(): void {
        $score = (int) get_transient( 'mhbo_ai_error_score' ) + 1;
        
        // 2026.4 BP: Reset score if it was cold (transient naturally expires in 15m)
        set_transient( 'mhbo_ai_error_score', $score, 900 );

        // Tiered Lock Duration:
        // Score 1: 15s (minor hiccup)
        // Score 2: 60s (active outage)
        // Score 3+: 15m (hard block/circuit trip)
        $duration = match ( true ) {
            $score >= 3 => 900,
            $score >= 2 => 60,
            default     => 15,
        };

        $new_lock = \time() + $duration;
        \set_transient( 'mhbo_ai_quota_lock', $new_lock, $duration + 60 );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \error_log( "[MHBO AI] Resilience: Circuit Breaker score expanded to {$score}. Cooling down for {$duration}s." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug telemetry, guarded by WP_DEBUG
        }
    }

    /**
     * Check if the AI Circuit Breaker is currently tripped.
     *
     * @return bool
     */
    public static function is_circuit_broken(): bool {
        $lock = get_transient( 'mhbo_ai_quota_lock' );
        if ( ! $lock ) {
            return false;
        }

        return time() < (int) $lock;
    }

    /**
     * Detect transient Gemini capacity / rate-limit errors that warrant a retry/fallback.
     *
     * @param string $error_message
     * @return bool
     */
    private static function is_transient_gemini_error( string $error_message ): bool {
        $transient_phrases = [
            'high demand',
            'temporarily unavailable',
            'overloaded',
            'resource exhausted',
            'quota exceeded',
            'rate limit',
            'request limit',
            'capacity',
            '502',
            '503',
            '504',
            '429',
        ];
        $lower = \strtolower( $error_message );
        foreach ( $transient_phrases as $phrase ) {
            if ( \str_contains( $lower, $phrase ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Execute a single HTTP request to the Gemini generateContent endpoint.
     *
     * @param string       $url      Full endpoint URL (model already embedded).
     * @param array<mixed> $body     JSON-serialisable request body.
     * @param string       $model    Model name (for error logging only).
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    private static function http_gemini_request( string $url, array $body, string $model ): array {
        $response = wp_remote_post( $url, [
            'timeout'        => 22, // Must stay under server proxy timeout (typically 30s).
            'connecttimeout' => 8,
            'sslverify'      => true,
            'headers'        => [ 'Content-Type' => 'application/json' ],
            'body'           => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();
            self::log_error( "Gemini HTTP error [{$model}]: {$err}" );
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $err ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $data      = json_decode( wp_remote_retrieve_body( $response ), true );
        $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

        if ( ! is_array( $data ) ) {
            // translators: %d: HTTP status code
            $err = sprintf( \MHBO\Core\I18n::get_label( 'ai_error_bad_json' ), (int) $http_code );
            self::log_error( $err );
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $err ];
        }

        if ( isset( $data['error'] ) ) {
            $err = $data['error']['message'] ?? \MHBO\Core\I18n::get_label( 'ai_error_unknown_gemini' );
            self::log_error( "Gemini API error [{$model}] HTTP {$http_code}: {$err}" );
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $err, 'retry_after' => $retry_after ];
        }

        $candidate  = $data['candidates'][0] ?? [];
        $parts      = $candidate['content']['parts'] ?? [];

        $text           = '';
        $thought        = '';
        $text_signature = '';
        $tool_calls     = [];

        foreach ( $parts as $part ) {
            if ( isset( $part['text'] ) ) {
                $text .= (string) $part['text'];
                $text_signature = $part['thought_signature'] ?? $part['thoughtSignature'] ?? $text_signature;
            }

            // FIX: Capture native 'thought' blocks (Reasoning).
            if ( isset( $part['thought'] ) ) {
                $thought .= (string) $part['thought'];
            }

            if ( isset( $part['functionCall'] ) ) {
                $raw_args = $part['functionCall']['args'] ?? [];
                $tc_entry = [
                    'id'       => 'call_' . wp_generate_password( 8, false ),
                    'type'     => 'function',
                    'function' => [
                        'name'      => $part['functionCall']['name'] ?? '',
                        'arguments' => ( is_array( $raw_args ) || is_object( $raw_args ) ) ? wp_json_encode( (object) $raw_args ) : '{}',
                    ],
                ];

                // FIX: Capture thought_signature/thoughtSignature from Part level.
                $signature = $part['thought_signature'] ?? $part['thoughtSignature'] ?? '';
                if ( $signature ) {
                    $tc_entry['thought_signature'] = $signature;
                }

                $tool_calls[] = $tc_entry;
            }
        }

        return [
            'content'           => $text,
            'thought'           => (string) ( ( $candidate['content']['thought'] ?? '' ) ?: $thought ),
            'thought_signature' => $text_signature,
            'tool_calls'        => $tool_calls,
            'raw_parts'         => $parts,
            'finish_reason'     => (string) ( $candidate['finishReason'] ?? 'stop' ),
            'error'             => null,
            'retry_after'       => $retry_after,
        ];
    }

    /**
     * Convert OpenAI-style message array to Gemini `contents` format.
     *
     * @param array<mixed> $messages
     * @return array<mixed>
     */
    private static function build_gemini_contents( array $messages ): array {
        // Build a tool_call_id → function_name map for functionResponse lookup.
        $tc_name_map = [];
        foreach ( $messages as $msg ) {
            if ( 'assistant' === ( $msg['role'] ?? '' ) && [] !== ( $msg['tool_calls'] ?? [] ) ) {
                foreach ( (array) $msg['tool_calls'] as $tc ) {
                    $tc_id   = $tc['id'] ?? '';
                    $tc_name = $tc['function']['name'] ?? '';
                    if ( '' !== $tc_id && '' !== $tc_name ) {
                        $tc_name_map[ $tc_id ] = $tc_name;
                    }
                }
            }
        }

        $contents             = [];
        $pending_fn_responses = []; // buffer consecutive tool results into one user turn

        foreach ( $messages as $i => $msg ) {
            $role = $msg['role'] ?? 'user';

            // Flush buffered functionResponses before any non-tool message.
            if ( 'tool' !== $role && [] !== $pending_fn_responses ) {
                $contents[]           = [ 'role' => 'user', 'parts' => $pending_fn_responses ];
                $pending_fn_responses = [];
            }

            if ( 'model' === $role || 'assistant' === $role ) {
                $parts = [];
                if ( '' !== (string) ( $msg['thought'] ?? '' ) ) {
                    $parts[] = [ 'thought' => (string) $msg['thought'] ];
                }
                if ( '' !== (string) ( $msg['content'] ?? '' ) ) {
                    $part = [ 'text' => (string) $msg['content'] ];
                    if ( '' !== (string) ( $msg['thought_signature'] ?? '' ) && [] === ( $msg['tool_calls'] ?? [] ) ) {
                        $part['thought_signature'] = $msg['thought_signature'];
                        $part['thoughtSignature']  = $msg['thought_signature'];
                    }
                    $parts[] = $part;
                }
                foreach ( (array) ( $msg['tool_calls'] ?? [] ) as $tc ) {
                    $decoded = json_decode( $tc['function']['arguments'] ?? '{}' );
                    if ( $decoded instanceof \stdClass ) {
                        $args = $decoded;
                    } elseif ( is_array( $decoded ) && [] !== $decoded ) {
                        $args = (object) $decoded;
                    } else {
                        $args = new \stdClass();
                    }

                    $part = [
                        'functionCall' => [
                            'name' => $tc['function']['name'] ?? '',
                            'args' => $args,
                        ],
                    ];

                    // FIX: thought_signature belongs to the Part, not the functionCall.
                    if ( '' !== (string) ( $tc['thought_signature'] ?? '' ) ) {
                        // Send both to be safe, as API vs Documentation differ on case.
                        $part['thought_signature'] = $tc['thought_signature'];
                        $part['thoughtSignature']  = $tc['thought_signature'];
                    }

                    $parts[] = $part;
                }
                if ( [] !== $parts ) {
                    $contents[] = [ 'role' => 'model', 'parts' => $parts ];
                }
                continue;
            }

            if ( 'tool' === $role ) {
                $tc_name = (string) ( $msg['name'] ?? '' );
                $tc_id   = (string) ( $msg['tool_call_id'] ?? '' );

                // 2026 BP: Look-back logic for robustness. If name is missing (old sessions),
                // search preceding assistant messages for the matching call ID.
                if ( '' === $tc_name && '' !== $tc_id ) {
                    for ( $j = $i - 1; $j >= 0; $j-- ) {
                        $prev = $messages[ $j ];
                        if ( ( 'assistant' === ( $prev['role'] ?? '' ) || 'model' === ( $prev['role'] ?? '' ) ) && [] !== ( $prev['tool_calls'] ?? [] ) ) {
                            foreach ( $prev['tool_calls'] as $tc ) {
                                if ( ( $tc['id'] ?? '' ) === $tc_id ) {
                                    $tc_name = (string) ( $tc['function']['name'] ?? '' );
                                    break 2;
                                }
                            }
                        }
                    }
                }

                // If still vacant (very old/corrupt), fallback to a generic marker 
                // but Gemini MIGHT still 400. Re-storage fix in ChatRest handles new turns.
                if ( '' === $tc_name ) {
                    $tc_name = 'unknown_tool_call';
                }

                $pending_fn_responses[] = [
                    'functionResponse' => [
                        'name'     => $tc_name,
                        'response' => (array)  json_decode( (string) $msg['content'], true ),
                        'id'       => $tc_id ?: 'call_gen_' . wp_generate_password( 8, false ),
                    ],
                ];
                continue;
            }

            // Regular user (or system) message.
            $contents[] = [ 'role' => 'user', 'parts' => [ [ 'text' => (string) ( $msg['content'] ?? '' ) ] ] ];
        }

        // Flush any remaining buffered functionResponses.
        if ( [] !== $pending_fn_responses ) {
            $contents[] = [ 'role' => 'user', 'parts' => $pending_fn_responses ];
        }

        return $contents;
    }

    /**
     * HTTP call to an OpenAI-compatible API (OpenAI, Ollama, custom).
     *
     * @param string       $api_key
     * @param string       $model
     * @param array<mixed> $messages
     * @param string       $system_prompt
     * @param array<mixed> $tools
     * @param string       $endpoint
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    private static function http_openai( string $api_key, string $model, array $messages, string $system_prompt, array $tools, string $endpoint ): array {
        $is_responses_api = str_contains( $endpoint, '/v1/responses' );

        if ( $is_responses_api ) {
            // Modern 2026 'Responses' payload
            $input_text = "Context: " . $system_prompt . "\n\n";
            foreach ( $messages as $msg ) {
                $role = $msg['role'] ?? 'user';
                $val  = $msg['content'] ?? '';
                $input_text .= ucfirst( (string) $role ) . ": " . (string) $val . "\n";
            }

            $body = [
                'model' => $model,
                'input' => $input_text,
                'store' => true,
            ];
        } else {
            $body = [
                'model'    => $model,
                'messages' => array_merge(
                    [ [ 'role' => 'system', 'content' => $system_prompt ] ],
                    $messages
                ),
            ];

            if ( [] !== $tools ) {
                $body['tools'] = $tools;
            }

// 2026 BP: Standardize reasoning content for multi-turn persistence.
            foreach ( $body['messages'] as &$bm ) {
                if ( 'assistant' === ( $bm['role'] ?? '' ) && '' !== (string) ( $bm['thought'] ?? '' ) ) {
                    $bm['reasoning_content'] = $bm['thought'];
                }
            }
        }

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $api_key ) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        // OpenRouter-specific rankings/dashboard attribution (2026 BP).
        if ( str_contains( $endpoint, 'openrouter.ai' ) ) {
            $headers['HTTP-Referer'] = home_url();
            $headers['X-Title']      = 'Modern Hotel Booking (' . get_bloginfo( 'name' ) . ')';
        }

        $args = [
            'timeout'        => 22, // Must stay under server proxy timeout (typically 30s).
            'connecttimeout' => 8,
            'sslverify'      => true,
            'headers'        => $headers,
            'body'           => wp_json_encode( $body ),
        ];

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            self::log_error( 'OpenAI-compatible HTTP error: ' . $response->get_error_message() );
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $response->get_error_message() ];
        }

        return self::parse_openai_response( $response, $endpoint, $api_key, $model, $messages, $system_prompt, $tools );
    }

    /**
     * Parse the response from an OpenAI-compatible endpoint.
     *
     * @param array<string, mixed>|\WP_Error $response
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    private static function parse_openai_response( $response, string $endpoint, string $api_key, string $model, array $messages, string $system_prompt, array $tools ): array {
        if ( is_wp_error( $response ) ) {
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $response->get_error_message() ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $data      = json_decode( wp_remote_retrieve_body( $response ), true );
        $is_responses_api = str_contains( $endpoint, '/v1/responses' );

        if ( ! is_array( $data ) ) {
            // translators: %d: HTTP status code
            $err = sprintf( \MHBO\Core\I18n::get_label( 'ai_error_bad_json' ), (int) $http_code );
            self::log_error( $err );
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $err ];
        }

        if ( isset( $data['error'] ) ) {
            $err = $data['error']['message'] ?? \MHBO\Core\I18n::get_label( 'ai_error_unknown_gemini' );

            if ( $is_responses_api && ( 403 === $http_code || str_contains( strtolower( $err ), 'missing scopes' ) ) ) {
                self::log_error( "OpenAI Scope Error (api.responses.write missing). Retrying via standard Chat Completions endpoint..." );
                $chat_endpoint = str_replace( '/v1/responses', '/v1/chat/completions', $endpoint );
                return self::http_openai( $api_key, $model, $messages, $system_prompt, $tools, $chat_endpoint );
            }

            self::log_error( 'OpenAI API error: ' . $err );
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $err ];
        }

        $text       = '';
        $thought    = '';
        $tool_calls = [];

        if ( isset( $data['output'] ) && is_array( $data['output'] ) ) {
            foreach ( $data['output'] as $part ) {
                $type = $part['type'] ?? '';
                
                if ( 'message' === $type && isset( $part['content'] ) && is_array( $part['content'] ) ) {
                    foreach ( $part['content'] as $c ) {
                        if ( 'output_text' === ( $c['type'] ?? '' ) ) {
                            $text .= (string) ( $c['text'] ?? '' );
                        }
                    }
                }
                
                // Reasoning/Thought content (similar to Gemini's thought parts)
                if ( 'reasoning' === $type ) {
                    $thought .= $part['text'] ?? $part['content'] ?? $part['reasoning_content'] ?? '';
                }

                // Tool calls (if present in modern format)
                if ( 'tool_calls' === $type && [] !== ( $part['tool_calls'] ?? [] ) ) {
                    $tool_calls = array_merge( $tool_calls, (array) $part['tool_calls'] );
                }
            }
        }
        // Handle Legacy v1/chat/completions output
        elseif ( isset( $data['choices'][0]['message'] ) ) {
            $msg        = $data['choices'][0]['message'];
            $text       = (string) ( $msg['content'] ?? '' );
            $thought    = (string) ( $msg['reasoning_content'] ?? '' ); // Capture GPT-5.4 reasoning
            $tool_calls = $msg['tool_calls'] ?? [];
        }

        $finish_reason = (string) ( $data['choices'][0]['finish_reason'] ?? $data['status'] ?? 'stop' );

        $usage = $data['usage'] ?? [];
        
        // OpenRouter / DeepSeek / Reasoning models often put reasoning tokens in usage.
        if ( isset( $usage['reasoning_tokens'] ) && ! isset( $usage['reasoning'] ) ) {
            $usage['reasoning'] = $usage['reasoning_tokens'];
        }

        return [
            'content'       => $text,
            'thought'       => $thought, // Return separately
            'tool_calls'    => $tool_calls,
            'usage'         => $usage,
            'finish_reason' => $finish_reason,
            'error'         => null,
        ];
    }

    /**
     * HTTP call to Anthropic Claude API.
     *
     * @param string       $api_key
     * @param string       $model
     * @param array<mixed> $messages
     * @param string       $system_prompt
     * @param array<mixed> $tools
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    private static function http_anthropic( string $api_key, string $model, array $messages, string $system_prompt, array $tools ): array {
        $body = [
            'model'      => $model,
            'max_tokens' => 4096, // 2026 BP: increased for reasoning models
            'system'     => $system_prompt,
            'messages'   => array_map( function( $m ) {
                $out = [ 'role' => $m['role'], 'content' => $m['content'] ];
                // 2026 BP: Reconstruct thinking blocks for multi-turn tool persistence.
                if ( 'assistant' === $m['role'] && '' !== (string) ( $m['thought'] ?? '' ) ) {
                    $out['content'] = [
                        [ 'type' => 'thinking', 'thinking' => $m['thought'], 'signature' => $m['thought_signature'] ?? '' ],
                        [ 'type' => 'text', 'text' => $m['content'] ?: '' ]
                    ];
                }
                return $out;
            }, $messages ),
        ];

if ( [] !== $tools ) {
            $body['tools'] = array_map( function ( $t ) {
                return [
                    'name'         => $t['function']['name'] ?? $t['name'] ?? '',
                    'description'  => $t['function']['description'] ?? $t['description'] ?? '',
                    'input_schema' => $t['function']['parameters'] ?? $t['parameters'] ?? [ 'type' => 'object', 'properties' => [] ],
                ];
            }, $tools );
        }

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout'   => 22, // Must stay under server proxy timeout (typically 30s).
            'sslverify' => true,
            'headers'   => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            self::log_error( 'Anthropic HTTP error: ' . $response->get_error_message() );
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $response->get_error_message() ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $data      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $data ) ) {
            $err = "Anthropic returned non-JSON response (HTTP {$http_code})";
            self::log_error( $err );
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $err ];
        }

        if ( isset( $data['error'] ) ) {
            $err = $data['error']['message'] ?? 'Unknown Anthropic error';
            self::log_error( 'Anthropic API error: ' . $err );
            return [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => $err ];
        }

        $text      = '';
        $thought   = '';
        $signature = '';
        $tool_calls = [];

        foreach ( $data['content'] ?? [] as $block ) {
            if ( 'text' === ( $block['type'] ?? '' ) ) {
                $text .= $block['text'];
            }
            if ( 'thinking' === ( $block['type'] ?? '' ) ) {
                $thought  .= $block['thinking'] ?? '';
                $signature = $block['signature'] ?? '';
            }
            if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
                $tool_calls[] = [
                    'id'       => $block['id'] ?? 'call_' . wp_generate_password( 8, false ),
                    'type'     => 'function',
                    'function' => [
                        'name'      => $block['name'],
                        'arguments' => wp_json_encode( $block['input'] ?? [] ),
                    ],
                ];
            }
        }

        return [
            'content'           => $text,
            'thought'           => $thought,
            'thought_signature' => $signature,
            'tool_calls'        => $tool_calls,
            'finish_reason'     => (string) ( $data['stop_reason'] ?? 'stop' ),
            'error'             => null,
        ];
    }

    /**
     * Log an error message (only when WP_DEBUG_LOG is active — keys are never logged).
     *
     * @param string $message
     */
    private static function log_error( string $message ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[MHBO AI] ' . $message );
        }
    }
}
