<?php
/**
 * AI Client — unified gateway to any configured AI provider.
 *
 * Supports:
 *  - WP 7.0+ native wp_ai_client_prompt() builder API (when available).
 *  - WP 6.x standalone wordpress/ai-client SDK (when installed via Composer).
 *  - Direct HTTP fallback using the stored API key from plugin settings.
 *
 * @package MHBO\AI
 * @since 2.3.8
 * @since 2.4.0 Migrated Path 1 to WP 7.0 wp_ai_client_prompt() builder API.
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
     * On WP 7.0+ the recommended path is wp_ai_client_prompt() (the builder API),
     * not a raw client object. This method is preserved for Path 2 (standalone SDK)
     * backward compatibility. Callers should prefer the prompt() method.
     *
     * @return mixed|null
     */
    public static function get_client(): mixed {
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
     * Whether the WP 7.0+ native AI Client is available AND has at least
     * one provider configured that supports text generation.
     *
     * This is a FAST, zero-cost check (no API calls are made).
     * Internally respects wp_supports_ai() kill switch.
     *
     * @since 2.4.0
     * @return bool
     */
    public static function is_native_available(): bool {
        if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
            return false;
        }
        try {
            // @phpstan-ignore function.notFound (WP 7.0 Core function; guarded by function_exists above)
            return \wp_ai_client_prompt( 'test' )->is_supported_for_text_generation();
        } catch ( Throwable ) {
            return false;
        }
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

        // ── Path 1: WP 7.0+ native AI Client (prompt builder API) ─────────
        //    The builder supports text generation only — no tool/function calling.
        //    When tools are present (concierge flow), we skip to Path 2/3 which
        //    have full agentic tool calling support.
        if ( [] === $tools && function_exists( 'wp_ai_client_prompt' ) ) {
            try {
                // Build the last user message as the prompt text.
                $last_message = end( $messages );
                $prompt_text  = is_array( $last_message ) ? (string) ( $last_message['content'] ?? '' ) : '';

                // Build conversation history (everything except the final message).
                $history_slice = count( $messages ) > 1 ? array_slice( $messages, 0, -1 ) : [];

                // @phpstan-ignore function.notFound (WP 7.0 Core function; guarded by function_exists above)
                $builder = \wp_ai_client_prompt( $prompt_text )
                    ->using_system_instruction( $system_prompt )
                    ->using_max_tokens( 2048 )
                    ->using_temperature( 0.7 );

                // Attach conversation history for multi-turn context.
                if ( [] !== $history_slice ) {
                    $builder->with_history( $history_slice );
                }

                // Apply model preference from plugin settings.
                $model = (string) get_option( self::OPT_PREFIX . 'model', '' );
                if ( $model ) {
                    $builder->using_model_preference( $model );
                }

                // Zero-cost feature detection — skip if no provider supports text generation.
                if ( ! $builder->is_supported_for_text_generation() ) {
                    // Fall through to Path 2/3 silently.
                    self::log_error( 'WP 7.0 native AI: text generation not supported (no provider configured).' );
                } else {
                    $text = $builder->generate_text();

                    if ( is_wp_error( $text ) ) {
                        self::log_error( 'wp_ai_client_prompt error: ' . $text->get_error_message() . '. Falling back to standalone/HTTP.' );
                    } else {
                        return self::parse_native_text( (string) $text );
                    }
                }
            } catch ( Throwable $e ) {
                self::log_error( 'wp_ai_client_prompt exception: ' . $e->getMessage() );
                // Fall through to Path 2/3 for resilience.
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
            self::PROVIDER_GEMINI    => self::http_gemini( $api_key, $model ?: 'gemini-3.7-flash', $messages, $system_prompt, $tools ),
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Direct integration used as Path 3 fallback when wp_ai_client_prompt() does not support function calling or on pre-WP 7.0.
            self::PROVIDER_OPENAI    => self::http_openai( $api_key, $model ?: 'gpt-5.4-mini', $messages, $system_prompt, $tools, ( str_contains( $model, 'gpt-5' ) ) ? 'https://api.openai.com/v1/responses' : 'https://api.openai.com/v1/chat/completions' ),
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Direct integration used as Path 3 fallback when wp_ai_client_prompt() does not support function calling or on pre-WP 7.0.
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
     * Parse a plain text response from the WP 7.0 native prompt builder
     * into our standard response array.
     *
     * The builder's generate_text() returns a string — no tool calls,
     * no structured metadata. This is the simplest response path.
     *
     * @since 2.4.0
     * @param string $text
     * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
     */
    private static function parse_native_text( string $text ): array {
        return [
            'content'       => $text,
            'tool_calls'    => [],
            'finish_reason' => 'stop',
            'error'         => null,
        ];
    }

    /**
     * Parse a response from the standalone wordpress/ai-client SDK (Path 2)
     * or legacy native client into our standard format.
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
                return self::http_gemini( $api_key, $model ?: 'gemini-3.7-flash', $messages, $system_prompt, $tools );
 
            case self::PROVIDER_OPENAI:
                // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Direct integration used as Path 3 fallback when wp_ai_client_prompt() does not support function calling or on pre-WP 7.0.
                $endpoint = ( str_contains( $model, 'gpt-5' ) ) ? 'https://api.openai.com/v1/responses' : 'https://api.openai.com/v1/chat/completions';
                return self::http_openai( $api_key, $model ?: 'gpt-5.4-mini', $messages, $system_prompt, $tools, $endpoint );
 
            case self::PROVIDER_OPENROUTER:
                // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Direct integration used as Path 3 fallback when wp_ai_client_prompt() does not support function calling or on pre-WP 7.0.
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
     * v1beta itself is NOT deprecated (verified August 2026).
     *
     * Verified Fallback Chain (August 2026):
     *   gemini-3.7-flash → gemini-3.6-flash → gemini-3.5-flash → gemini-3.5-flash-lite
     *   → gemini-3.1-flash-lite → gemini-3-flash-preview → gemini-3.1-pro-preview
     *
     * EOL models removed: gemini-3.1-flash-lite-preview (shut down 2026-05-25),
     *                      gemini-2.5-flash-lite-preview (shut down 2026-03-31),
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
        // Retired: gemini-1.5-*, gemini-2.0-*, gemini-2.5-flash-lite-preview, gemini-3.1-flash-lite-preview.
        $model = str_replace( '-latest', '', $model ?: 'gemini-3.7-flash' );

        $retired_prefixes = [
            'gemini-1.5',
            'gemini-2.0',
        ];
        $retired_exact = [
            'gemini-3.1-flash-lite-preview', // EOL: 2026-05-25
            'gemini-2.5-flash-lite-preview', // EOL: 2026-03-31
            'gemini-1.5-flash',              // EOL: 2026-03-31
            'gemini-1.5-pro',                // EOL: 2026-03-31
            'gemini-3-pro-preview',          // EOL: 2026-03-09
        ];

        if ( in_array( $model, $retired_exact, true ) ) {
            self::log_error( "Model {$model} is EOL. Auto-migrating to gemini-3.7-flash." );
            $model = 'gemini-3.7-flash';
        } elseif ( [] !== array_filter( $retired_prefixes, fn( $p ) => str_starts_with( $model, $p ) ) ) {
            self::log_error( "Model {$model} uses a retired prefix. Auto-migrating to gemini-3.7-flash." );
            $model = 'gemini-3.7-flash';
        }

        // Build the Gemini-format contents array (OpenAI → Gemini conversion).
        $contents = self::build_gemini_contents( $messages );

        $body = [
            'system_instruction' => [ 'parts' => [ [ 'text' => $system_prompt ] ] ],
            'contents'           => $contents,
            'generationConfig'   => [ 'maxOutputTokens' => 2048 ],
        ];

        if ( [] !== $tools ) {
            // WP 7.1: Sanitize tool schemas to Draft-4 for strict AI clients.
            $sanitized_tools = $tools;
            if ( \function_exists( 'wp_prepare_json_schema_for_client' ) ) {
                $sanitized_tools = array_map( function ( $t ) {
                    $fn = $t['function'] ?? $t;
                    if ( isset( $fn['parameters'] ) ) {
                        $fn['parameters'] = \call_user_func( 'wp_prepare_json_schema_for_client', $fn['parameters'], 'draft-04' );
                    }
                    if ( isset( $t['function'] ) ) {
                        $t['function'] = $fn;
                    } else {
                        $t = $fn;
                    }
                    return $t;
                }, $sanitized_tools );
            }
            $body['tools'] = [ [ 'function_declarations' => array_map( fn( $t ) => $t['function'] ?? $t, $sanitized_tools ) ] ];
        }

        // 2026.4 BP: Cascade: primary model first, then cheaper/resilient fallbacks.
        // The chain is now dynamic, deprioritizing models with recent failures.
        $fallback_chain  = self::get_dynamic_fallback_chain( $model );
        $last_result     = [ 'content' => '', 'tool_calls' => [], 'finish_reason' => 'error', 'error' => 'No models available.' ];
        $cascade_start   = \microtime( true );
        $cascade_budget  = 40.0; // 2026.6 BP: Increased from 26s. Per-model timeout is now 12s, so 3-4 models fit within budget. PHP set_time_limit(180) covers this.

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
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Direct integration used as Path 3 fallback when wp_ai_client_prompt() does not support function calling or on pre-WP 7.0.
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

            // 2026.6 BP: Reduced backoff (max 1s + 0.25s jitter) to maximize models tried within cascade budget.
            // Previous 2s + 0.5s ate too much time, preventing fallback models from being reached.
            $base_backoff = (int) \min( 1, \pow( 2, $idx ) );
            $jitter_ms    = \wp_rand( 0, 250 * 1000 ); // Up to 0.25s jitter
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
     * 2026.5 BP: Dynamic chain – models that failed in the last 5 minutes are deprioritized.
     * Note: This cascade prioritizes "Service Continuity" (ensuring the guest gets a response)
     * over "Absolute Minimum Cost". If the cheapest model is busy, the system will try
     * more available tiers in the ecosystem.
     *
     * August 2026 Verified Active Models:
     *   GA       : gemini-3.7-flash — best value, newest frontier
     *              gemini-3.6-flash — high performance workhorse
     *              gemini-3.5-flash-lite — cheapest stable
     *   PREVIEW  : gemini-3-flash-preview — Computer Use support
     *              gemini-3.1-pro-preview — flagship preview
     *
     * @param string $primary
     * @return list<string>
     */
    private static function get_dynamic_fallback_chain( string $primary ): array {
        // Alias map: allow shorthand admin selections to resolve to real model IDs.
        $blanket_map = [
            'gemini-stable-primary' => 'gemini-3.7-flash',
            'gemini-stable-high'    => 'gemini-3.1-pro-preview',
            'gemini-flash-latest'   => 'gemini-3.7-flash',
            'gemini-pro-latest'     => 'gemini-3.1-pro-preview',
        ];

        if ( isset( $blanket_map[ $primary ] ) ) {
            $primary = $blanket_map[ $primary ];
        }

        // August 2026 Economic Cascade — ordered by cost & capability.
        // GA models tried first (most reliable); preview models are the capability safety net.
        $default_models = [
            'gemini-3.7-flash',              // GA      : August 2026 frontier flagship
            'gemini-3.6-flash',              // GA      : July 2026 workhorse
            'gemini-3.5-flash',              // GA      : High performance legacy
            'gemini-3.5-flash-lite',         // GA      : Ultralight budget
            'gemini-3.1-flash-lite',         // GA      : Stable
            'gemini-3-flash-preview',        // Preview : Computer Use support
            'gemini-3.1-pro-preview',        // Preview : Capability preview
            'gemini-2.5-flash-lite',         // GA      : Safety net (Deprecated)
            'gemini-2.5-flash',              // GA      : Last resort (Deprecated)
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

        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            \error_log( "[MHBO AI] Resilience: Circuit Breaker score expanded to {$score}. Cooling down for {$duration}s." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug telemetry, guarded by WP_DEBUG_LOG
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
        // 2026.6 BP: Added cURL timeout/network phrases. Without these, cURL error 28
        // ("Operation timed out") was classified as permanent, aborting the cascade
        // immediately instead of trying fallback models.
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
            'curl error',
            'timed out',
            'timeout',
            'operation timed out',
            'connection reset',
            'could not resolve',
            'name resolution',
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
            'timeout'        => 12, // 2026.6 BP: Reduced from 22s. Flash models respond in 2-5s; 12s is generous. Allows 3-4 models within cascade budget.
            'connecttimeout' => 5,  // 2026.6 BP: Reduced from 8s. If TCP connect takes >5s, the endpoint is unreachable.
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
            'timeout'        => 12, // 2026.6 BP: Reduced from 22s for consistency with Gemini cascade timing.
            'connecttimeout' => 5,  // 2026.6 BP: Reduced from 8s.
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
        $model = $model ?: 'claude-sonnet-4-6';
        if ( 'claude-3-5-sonnet-20240620' === $model ) {
            self::log_error( "Model claude-3-5-sonnet-20240620 is EOL. Auto-migrating to claude-sonnet-4-6." );
            $model = 'claude-sonnet-4-6';
        }
        // 2026.6 BP: Anthropic deprecated claude-opus-4-7 on June 15, 2026. Current flagship is 4.8.
        if ( 'claude-opus-4-7' === $model ) {
            self::log_error( "Model claude-opus-4-7 is deprecated (June 2026). Auto-migrating to claude-opus-4-8." );
            $model = 'claude-opus-4-8';
        }

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
                $schema = $t['function']['parameters'] ?? $t['parameters'] ?? [ 'type' => 'object', 'properties' => [] ];

                // WP 7.1: Sanitize schema to Draft-4 for strict AI clients.
                if ( \function_exists( 'wp_prepare_json_schema_for_client' ) ) {
                    $schema = \call_user_func( 'wp_prepare_json_schema_for_client', $schema, 'draft-04' );
                }

                return [
                    'name'         => $t['function']['name'] ?? $t['name'] ?? '',
                    'description'  => $t['function']['description'] ?? $t['description'] ?? '',
                    'input_schema' => $schema,
                ];
            }, $tools );
        }

        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Direct integration used as Path 3 fallback when wp_ai_client_prompt() does not support function calling or on pre-WP 7.0.
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout'        => 12, // 2026.6 BP: Reduced from 22s for consistency with cascade timing.
            'connecttimeout' => 5,  // 2026.6 BP: Fast-fail on unreachable endpoint.
            'sslverify'      => true,
            'headers'        => [
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
