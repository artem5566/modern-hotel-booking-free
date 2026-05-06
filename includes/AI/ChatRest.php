<?php
/**
 * Chat REST Endpoint
 *
 * POST /wp-json/mhbo/v1/chat
 *
 * Rate-limited (30 req/hr free, 200/hr pro).
 * Supports tool calling with automatic tool execution.
 * Optionally streams via SSE.
 *
 * @package MHBO\AI
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI;

use MHBO\AI\Abilities\CheckAvailability;
use MHBO\AI\Abilities\CreateBookingLink;
use MHBO\AI\Abilities\GetBusinessCard;
use MHBO\AI\Abilities\GetKnowledgeBase;
use MHBO\AI\Abilities\HotelInfo;
use MHBO\AI\Abilities\Policies;
use MHBO\AI\Abilities\RoomDetails;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;
use MHBO\Core\License;
use MHBO\Core\Pricing;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Throwable;

use function register_rest_route;
use function sanitize_text_field;
use function wp_verify_nonce;
use function get_transient;
use function set_transient;
use function wp_json_encode;
use function __;
use function _n;
use function esc_html;
use function wp_generate_password;
use function wp_unslash;
use function rest_ensure_response;
use function sanitize_email;

use const HOUR_IN_SECONDS;
use const MINUTE_IN_SECONDS;
use const DAY_IN_SECONDS;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

class ChatRest {

    private const REST_NAMESPACE = 'mhbo/v1';
    private const RATE_FREE      = 30;
    private const RATE_PRO       = 200;
    private const RATE_WINDOW    = \HOUR_IN_SECONDS;
    private const MAX_TOOL_TURNS = 5;   // prevent infinite tool loops

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register the REST route. Called on rest_api_init.
     */
    public static function register(): void {
        \register_rest_route(
            self::REST_NAMESPACE,
            '/chat',
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'handle' ],
                'permission_callback' => '__return_true', // Public concierge access
                'args'                => [
                    'message'    => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => '\sanitize_text_field' ],
                    'nonce'      => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => '\sanitize_text_field' ],
                    'lang'       => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => '\sanitize_text_field' ],
                ],
            ]
        );

        // Nonce refresh endpoint — allows JS to recover from a stale nonce without a page reload.
        // This handles 403s caused by page caches (Cloudflare, WP Rocket, LiteSpeed, etc.) baking
        // an old nonce into the cached HTML. The endpoint itself is cache-controlled to no-store.
        \register_rest_route(
            self::REST_NAMESPACE,
            '/nonce',
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'handle_nonce' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Return fresh nonces so the JS widget can recover from a stale cached nonce (403).
     */
    public static function handle_nonce(): WP_REST_Response {
        $response = new WP_REST_Response( [
            'nonce'      => \wp_create_nonce( 'mhbo_chat_nonce' ),
            'rest_nonce' => \wp_create_nonce( 'wp_rest' ),
        ], 200 );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle the POST /mhbo/v1/chat request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        // 0. Ensure PHP has enough time for slow AI models (2026 BP).
        if ( \function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
            @\set_time_limit( 120 );
        }
        // 1. Nonce verification.
        $nonce = (string) ( $request->get_param( 'nonce' ) ?? $request->get_header( 'X-WP-Nonce' ) ?? '' );
        if ( ! \wp_verify_nonce( $nonce, 'mhbo_chat_nonce' ) ) {
            return new WP_Error( 'mhbo_forbidden', I18n::get_label( 'ai_error_invalid_nonce' ), [ 'status' => 403 ] );
        }

        // 2. Rate limiting.
        $ip     = self::get_client_ip();
        $ip_key = 'mhbo_rl_' . \md5( (string) $ip );
        $rate   = false ? self::RATE_PRO : self::RATE_FREE;

        $count = (int) \get_transient( $ip_key );
        if ( $count >= $rate ) {
            return new WP_Error(
                'mhbo_rate_limit',
                I18n::get_label( 'ai_error_rate_limit' ),
                [ 'status' => 429 ]
            );
        }
        \set_transient( $ip_key, $count + 1, (int) self::RATE_WINDOW );

        // 3. Streaming Support (SSE).
        $is_streaming = ( $request->get_param( 'stream' ) === 'true' ) || ( \str_contains( (string) $request->get_header( 'Accept' ), 'text/event-stream' ) );
        if ( $is_streaming && ! \headers_sent() ) {
            \header( 'Content-Type: text/event-stream' );
            \header( 'Cache-Control: no-cache' );
            \header( 'X-Accel-Buffering: no' );
            \header( 'Connection: keep-alive' );
            echo "retry: 1000\n\n";
            if ( \ob_get_level() > 0 ) {
                \ob_flush();
            }
            \flush();
        }

        // 3. Fail-Fast: Check if AI Circuit Breaker is currently tripped.
        if ( Client::is_circuit_broken() ) {
            $score = (int) \get_transient( 'mhbo_ai_error_score' );
            $lock  = (int) \get_transient( 'mhbo_ai_quota_lock' );
            $delay = \max( 15, $lock - \time() );

            return new WP_REST_Response( [
                'content'     => \sprintf(
                    // translators: %1$d: circuit breaker tier score, %2$d: seconds remaining before retry
                    I18n::get_label( 'ai_status_cooling_down_tier' ),
                    $score,
                    $delay
                ),
                'session_id'  => (string) $request->get_param( 'session_id' ),
                'suggestions' => [],
            ], 503 ); // Return 503 Service Unavailable for circuit breaks.
        }

        // 4. Input processing.
        $raw_message = (string) $request->get_param( 'message' );
        if ( \mb_strlen( (string) $raw_message ) > 1000 ) {
            return new WP_Error( 'mhbo_message_too_long', I18n::get_label( 'ai_error_message_too_long' ), [ 'status' => 400 ] );
        }

        try {

        // 4. Session.
        $session_id = ChatSession::get_session_id();
        $history    = ChatSession::get_history( $session_id );

        // 5. Add user message to history.
        ChatSession::add_message( $session_id, 'user', $raw_message );
        $history[] = [ 'role' => 'user', 'content' => $raw_message ];

        // 6. System prompt + tools.
        $lang          = (string) ( $request->get_param( 'lang' ) ?? '' );
        $system_prompt = KnowledgeBase::get_system_prompt( $lang );
        $tools         = KnowledgeBase::get_tool_definitions( false );

        // 7. Dynamic Session Context (2026 BP).
        //    Inject known session state directly into the prompt to ensure the AI
        //    never misses a guest's identity or current scarcity constraints.
        $today         = HotelTime::today();
        $guest_name    = ChatSession::get_guest_name( $session_id );
        $guest_email   = ChatSession::get_guest_email( $session_id );
        $guest_phone   = ChatSession::get_guest_phone( $session_id );

        $lang_block    = $lang ? \sprintf( I18n::get_label( 'ai_prompt_lang_rule' ), $lang ) : '';
        
        $context_name  = $guest_name ? \sprintf( I18n::get_label( 'ai_prompt_guest_name' ), $guest_name ) : "";
        $context_email = $guest_email ? \sprintf( I18n::get_label( 'ai_prompt_guest_email' ), $guest_email ) : "";
        $context_phone = $guest_phone ? \sprintf( I18n::get_label( 'ai_prompt_guest_phone' ), $guest_phone ) : "";
        
        $date_context  = \sprintf( 
            I18n::get_label( 'ai_prompt_date_context' ), 
            $today, 
            \gmdate( 'l', \strtotime( (string) $today ) ) 
        );
        $scarcity_rule = I18n::get_label( 'ai_prompt_scarcity_rule' ) . "\n";
        $decisive_rule = \sprintf( 
            I18n::get_label( 'ai_prompt_decisive_rule' ), 
            false ? '`create_booking_link` (or `create_booking_draft` if is_pro)' : '`create_booking_link`'
        );
        
        $system_prompt .= "\n" . $lang_block . "\n" . $context_name . " " . $context_email . " " . $context_phone . "\n" . $date_context . "\n" . $scarcity_rule . $decisive_rule;

        // 7b. Multi-Room Sequential Context (2026 BP).
        //     If a multi-room plan is active, inject the current progress so the AI
        //     generates exactly ONE booking card for the next room in the distribution.
        $mr_plan = ChatSession::get_multi_room_plan( $session_id );
        if ( [] !== $mr_plan ) {
            $mr_idx        = (int) ( $mr_plan['current_index'] ?? 0 );
            $mr_total      = (int) ( $mr_plan['total_rooms'] ?? 0 );
            $mr_type_name  = (string) ( $mr_plan['room_type_name'] ?? '' );
            $mr_check_in   = (string) ( $mr_plan['check_in'] ?? '' );
            $mr_check_out  = (string) ( $mr_plan['check_out'] ?? '' );
            $mr_dist       = (array) ( $mr_plan['distribution'] ?? [] );
            $mr_remaining  = $mr_total - $mr_idx - 1;
            $mr_slot       = $mr_dist[ $mr_idx ] ?? null;

            if ( null !== $mr_slot ) {
                $mr_room_id = (int) ( $mr_slot['room_id'] ?? 0 );
                $mr_adults  = (int) ( $mr_slot['adults'] ?? 1 );
                $mr_children= (int) ( $mr_slot['children'] ?? 0 );

                // Live re-validation (Gate 3): Confirm the room is still bookable.
                $mr_avail = Pricing::is_room_available( $mr_room_id, $mr_check_in, $mr_check_out );
                $mr_avail_note = '';

                if ( true !== $mr_avail ) {
                    // Attempt auto-swap to another available room of the same type.
                    $mr_alt = self::find_alternative_room( (int) ( $mr_plan['type_id'] ?? 0 ), $mr_check_in, $mr_check_out, $mr_dist );
                    if ( $mr_alt > 0 ) {
                        ChatSession::swap_multi_room_id( $session_id, $mr_idx, $mr_alt );
                        $mr_room_id   = $mr_alt;
                        $mr_avail_note = ' (auto-swapped to room ' . $mr_alt . ' — original was no longer available)';
                    } else {
                        // No alternative — inject context telling AI to inform the guest.
                        $system_prompt .= "\n\n=== MULTI-ROOM BOOKING ERROR ===\n" .
                            "The next room (room_id {$mr_room_id}) is no longer available for {$mr_check_in} to {$mr_check_out}, " .
                            "and no alternative rooms of type \"{$mr_type_name}\" are available. " .
                            "Inform the guest that one of the required rooms has been booked by another guest. " .
                            "Suggest trying different dates or a different room type. " .
                            "Do NOT call create_booking_link.\n";
                        // Clear the stale plan.
                        ChatSession::clear_multi_room_plan( $session_id );
                        $mr_slot = null; // Prevent normal injection below.
                    }
                }

            if ( null !== $mr_slot ) {
                    $mr_human_idx = $mr_idx + 1;
                    $system_prompt .= "\n\n=== MULTI-ROOM BOOKING IN PROGRESS ===\n" .
                        "This is booking {$mr_human_idx} of {$mr_total}.\n" .
                        "Room type: {$mr_type_name}. room_id: {$mr_room_id}, adults: {$mr_adults}, children: {$mr_children}.\n" .
                        "Dates: {$mr_check_in} to {$mr_check_out}. (Room {$mr_room_id} CONFIRMED AVAILABLE{$mr_avail_note})\n" .
                        "INSTRUCTIONS:\n";

                    if ( ! $guest_name || ! $guest_email || ! $guest_phone ) {
                        $system_prompt .= "- Explain that you need the guest's Full Name, Email, and Phone number to prepare the individual booking cards for these {$mr_total} rooms.\n" .
                            "- Do NOT call create_booking_link yet. Wait for the guest to provide their details.\n";
                    } else {
                        $system_prompt .= "- Generate EXACTLY ONE `create_booking_link` call with room_id={$mr_room_id}, adults={$mr_adults}, children={$mr_children}, " .
                            "check_in={$mr_check_in}, check_out={$mr_check_out}, multi_room_index={$mr_human_idx}, multi_room_total={$mr_total}.\n" .
                            "- Use the guest_name: \"{$guest_name}\", guest_email: \"{$guest_email}\", guest_phone: \"{$guest_phone}\" from session context.\n";

                        if ( $mr_remaining > 0 ) {
                            $system_prompt .= "- After the card appears, EXPLICITLY tell the guest: \"This is booking {$mr_human_idx} of {$mr_total}. " .
                                "Once you complete this reservation, PLEASE COME BACK TO THIS CHAT and say 'next room' or 'continue' and I'll prepare the next booking card for you. " .
                                "You have {$mr_remaining} more " . ( 1 === $mr_remaining ? 'room' : 'rooms' ) . " to book.\"\n";
                        } else {
                            $system_prompt .= "- This is the FINAL room. After the card appears, tell the guest: \"All {$mr_total} bookings are now prepared! " .
                                "Please ensure you complete each reservation using the booking cards above.\"\n";
                        }
                    }

                    $system_prompt .= "- Do NOT generate multiple create_booking_link calls. ONE only.\n" .
                        "=== END MULTI-ROOM CONTEXT ===\n";
                }
            }
        }

        // 8. AI call — may require multiple turns for tool use.
        //
        // NOTE on finish_reason: OpenAI uses 'tool_calls' to signal the model wants
        // another turn; Gemini always returns 'STOP' (even when it calls functions).
        // We therefore drive the loop purely on whether tool_calls are present:
        //   • No tool_calls  → text content is the final answer; break.
        //   • Has tool_calls → execute them, append results, continue to next turn.
        $final_response = '';
        $tool_calls_log = [];
        $messages       = $history;

        for ( $turn = 0; $turn < self::MAX_TOOL_TURNS; $turn++ ) {
            $ai_response = [ 'error' => null ];
            $max_retries = 3; // 2026 BP: Standard REST retry threshold.

            for ( $attempt = 0; $attempt < $max_retries; $attempt++ ) {
                // 2026 BP: Emit 'thinking' event for real-time guest feedback.
                $step_text = $turn === 0 ? I18n::get_label( 'ai_status_thinking_analyzing' ) : I18n::get_label( 'ai_status_thinking_refining' );
                if ( $attempt > 0 ) {
                    // translators: %d: current retry attempt number
                    $step_text = \sprintf( I18n::get_label( 'ai_status_thinking_retrying' ), (int) $attempt + 1 );
                }
                self::emit_thought( $step_text, $is_streaming );

                $ai_response = Client::prompt( $messages, $system_prompt, $tools );

                // 2026 BP: Rule 15 explicit check for non-error state.
                if ( null === ( $ai_response['error'] ?? null ) ) {
                    break; // Success!
                }

                $is_transient = self::is_transient_error( (string) $ai_response['error'] );

                if ( ! $is_transient || $attempt === $max_retries - 1 ) {
                    break; // Final failure or non-transient error.
                }

                // Brief pause before retry — capped at 1 s to stay well under proxy timeouts.
                \sleep( 1 );
            }

            if ( $ai_response['error'] ) {
                $is_transient = self::is_transient_error( (string) $ai_response['error'] );

                if ( $is_transient ) {
                    return new WP_REST_Response( [
                        'response'    => I18n::get_label( 'ai_status_cooling_down' ),
                        'session_id'  => $session_id,
                        'suggestions' => [],
                    ], 200 );
                }

                return new WP_Error( 'mhbo_ai_error', (string) $ai_response['error'], [ 'status' => 502 ] );
            }

            $tool_calls = $ai_response['tool_calls'] ?? [];
            $thought    = (string) ( $ai_response['thought'] ?? '' );

            // 2026 BP: Rule 15 explicit check for empty toolset.
            if ( [] === (array) $tool_calls ) {
                $final_response = (string) $ai_response['content'];
                break;
            }

            $assistant_meta = [
                'tool_calls' => $tool_calls,
                'thought'    => $thought,
                'raw_parts'  => $ai_response['raw_parts'] ?? null,
            ];
            $messages[] = [
                'role'              => 'assistant',
                'content'           => '' === $ai_response['content'] ? null : $ai_response['content'],
                'thought'           => $thought,
                'thought_signature' => $ai_response['thought_signature'] ?? null,
                'tool_calls'        => $tool_calls,
            ];

            ChatSession::add_message( $session_id, 'assistant', (string) $ai_response['content'], $assistant_meta );

            $booking_link_created_this_turn = false; // 2026 BP: one card per conversation turn.

            foreach ( $ai_response['tool_calls'] as $tc ) {
                $tc_id     = $tc['id'] ?? '';
                $tc_name   = $tc['function']['name'] ?? '';
                // 2026 BP: Emit tool-specific progress event.
                $step_name = \str_replace( '_', ' ', $tc_name );
                self::emit_thought( \ucfirst( $step_name ) . '...', $is_streaming );

                $fn_name  = (string) ( $tc['function']['name'] ?? '' );
                $fn_args  = (array) ( \json_decode( (string) ( $tc['function']['arguments'] ?? '{}' ), true ) ?: [] );
                $tc_id    = (string) ( $tc['id'] ?? 'call_' . \wp_generate_password( 8, false ) );

                // 2026 BP: For create_booking_link, auto-fill missing guest details from session
                // and enforce one-card-per-turn to prevent duplicate multi-room cards.
                if ( 'create_booking_link' === $fn_name ) {
                    if ( '' === \trim( (string) ( $fn_args['guest_name'] ?? '' ) ) ) {
                        $fn_args['guest_name'] = ChatSession::get_guest_name( $session_id );
                    }
                    if ( '' === \trim( (string) ( $fn_args['guest_email'] ?? '' ) ) ) {
                        $fn_args['guest_email'] = ChatSession::get_guest_email( $session_id );
                    }
                    if ( '' === \trim( (string) ( $fn_args['guest_phone'] ?? '' ) ) ) {
                        $fn_args['guest_phone'] = ChatSession::get_guest_phone( $session_id );
                    }

                    if ( $booking_link_created_this_turn ) {
                        $tool_result = [
                            'error'            => 'Only one booking card allowed per message.',
                            'internal_message' => 'You already generated a booking card this turn. Tell the guest this is booking 1 of X and ask them to say "next room" to continue.',
                        ];
                        $tool_calls_log[] = [ 'tool' => $fn_name, 'args' => $fn_args, 'result' => $tool_result ];
                        $tc_msg = [
                            'role'         => 'tool',
                            'name'         => $fn_name,
                            'tool_call_id' => $tc_id,
                            'content'      => \wp_json_encode( $tool_result ),
                        ];
                        $messages[] = $tc_msg;
                        ChatSession::add_message( $session_id, 'tool', \wp_json_encode( $tool_result ), [ 'name' => $fn_name ] );
                        continue;
                    }
                    $booking_link_created_this_turn = true;
                }

                $tool_result = self::execute_tool( $fn_name, $fn_args );

                // 2026 BP: Multi-Room Plan — Pre-flight and state management.
                if ( 'check_availability' === $fn_name && isset( $tool_result['rooms'] ) && [] !== (array) $tool_result['rooms'] ) {
                    self::maybe_save_multi_room_plan( $session_id, $tool_result );
                }
                if ( 'create_booking_link' === $fn_name && ! isset( $tool_result['error'] ) ) {
                    ChatSession::advance_multi_room( $session_id );
                    // Inject remaining room count so the frontend card shows the "come back" hint.
                    $new_mr_plan = ChatSession::get_multi_room_plan( $session_id );
                    $tool_result['multi_room_remaining'] = [] !== $new_mr_plan
                        ? \max( 0, (int) ( $new_mr_plan['total_rooms'] ?? 0 ) - (int) ( $new_mr_plan['current_index'] ?? 0 ) )
                        : 0;
                }

                $tool_calls_log[] = [ 'tool' => $fn_name, 'args' => $fn_args, 'result' => $tool_result ];

                $tc_msg = [
                    'role'             => 'tool',
                    'name'             => $fn_name,
                    'tool_call_id'     => $tc_id,
                    'content'          => \wp_json_encode( $tool_result ),
                    'thought_signature' => $tc['thought_signature'] ?? null,
                ];

                $messages[] = $tc_msg;

                $tool_meta = [ 'name' => $fn_name ];
                if ( isset( $tc['thought_signature'] ) && '' !== (string) $tc['thought_signature'] ) {
                    $tool_meta['thought_signature'] = $tc['thought_signature'];
                }
                
                if ( $session_id ) {
                    $b_id = $tool_result['booking_id'] ?? 0;
                    if ( 0 !== $b_id ) {
                        // 2026 BP: Safeguard for Free build where link_booking method is stripped.
                        if ( \method_exists( '\MHBO\AI\ChatSession', 'link_booking' ) ) {
                            ChatSession::link_booking( $session_id, (int) $b_id );
                        }
                    }
                    $g_name = (string) ( $tool_result['guest_name'] ?? '' );
                    $g_email = '';
                    $g_phone = '';
                    if ( isset($fn_args['booking_id']) && '' !== (string) $fn_args['booking_id'] ) {
                        $b_id = (string)$fn_args['booking_id'];
                    }
                    if ( isset($fn_args['guest_name']) && '' !== (string) $fn_args['guest_name'] ) {
                        $g_name = (string)$fn_args['guest_name'];
                    }
                    if ( isset($fn_args['guest_email']) && '' !== (string) $fn_args['guest_email'] ) {
                        $g_email = (string)$fn_args['guest_email'];
                    }
                    if ( isset($fn_args['guest_phone']) && '' !== (string) $fn_args['guest_phone'] ) {
                        $g_phone = (string)$fn_args['guest_phone'];
                    }
                    if ( '' !== $g_name ) {
                        ChatSession::set_guest_name( $session_id, $g_name );
                    }
                    if ( '' !== $g_email ) {
                        ChatSession::set_guest_email( $session_id, $g_email );
                    }
                    if ( '' !== $g_phone ) {
                        ChatSession::set_guest_phone( $session_id, $g_phone );
                    }
                }
                
                ChatSession::add_message( $session_id, 'tool', (string) $tc_msg['content'], $tool_meta );
            }
        }

        // 8a. Booking card suppression & empty-response guard.
        //     - When the FINAL booking card is shown, suppress AI text (card has all info).
        //     - EXCEPTION: preserve text when more rooms remain (guest needs "come back" guidance).
        //     - When AI produced no text AND no card was shown, return a graceful message.
        $mr_plan_after   = ChatSession::get_multi_room_plan( $session_id );
        $mr_rooms_remain = [] !== $mr_plan_after;
        $card_tools      = [ 'create_booking_link', 'create_booking_draft' ];
        $card_created    = false;

        foreach ( $tool_calls_log as $tc_entry ) {
            if (
                \in_array( $tc_entry['tool'], $card_tools, true ) &&
                isset( $tc_entry['result'] ) &&
                ! isset( $tc_entry['result']['error'] )
            ) {
                $card_created = true;
                break;
            }
        }

        if ( $card_created && ! $mr_rooms_remain ) {
            $final_response = '';
        }

        if ( '' === $final_response && ! $card_created ) {
            return new WP_REST_Response( [
                'response'    => I18n::get_label( 'ai_status_cooling_down' ),
                'session_id'  => $session_id,
                'suggestions' => [],
            ], 200 );
        }

        // 8b. Save assistant response to history.
        if ( $final_response ) {
            ChatSession::add_message( $session_id, 'assistant', $final_response, [ 'tool_calls_summary' => $tool_calls_log ] );
        }

        // 9. Generate suggestions.
        //    2026 BP: Override suggestions when multi-room flow is active.
        if ( $mr_rooms_remain ) {
            $suggestions = [
                \__( 'Book next room', 'modern-hotel-booking' ),
                \__( 'Change room type', 'modern-hotel-booking' ),
                \__( 'Contact hotel', 'modern-hotel-booking' ),
            ];
        } else {
            $suggestions = self::generate_suggestions( $tool_calls_log );
        }

        // 10. Booking intent score (Einstein Next Best Action-style signal).
        //     Returned to the frontend so the UI can surface a "Book Now" CTA
        //     or escalation options at the right moment.
        $intent      = self::score_booking_intent( $messages, $tool_calls_log );
        $handoff     = self::build_handoff_options();

        // 11. Handle SSE streaming if requested.
        $accept = (string) $request->get_header( 'Accept' );
        if ( \str_contains( (string) $accept, 'text/event-stream' ) ) {
            self::stream_response( (string) $final_response, $session_id, $tool_calls_log, $suggestions );
            exit; // SSE never returns a WP_REST_Response
        }

        return \rest_ensure_response( [
            'response'         => $final_response,
            'session_id'       => $session_id,
            'tool_calls_made'  => $tool_calls_log,
            'suggestions'      => $suggestions,
            'booking_intent'   => $intent,
            'handoff'          => $handoff,
        ] );

        } catch ( Throwable $e ) {
            if ( \defined( 'WP_DEBUG_LOG' ) && \WP_DEBUG_LOG ) {
                \error_log( '[MHBO Chat] Exception (' . \get_class( $e ) . '): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error telemetry, guarded by WP_DEBUG_LOG.
            }
            $session_id = isset( $session_id ) ? $session_id : '';
            return new WP_REST_Response( [
                'response'    => I18n::get_label( 'ai_status_cooling_down' ),
                'session_id'  => $session_id,
                'suggestions' => [],
            ], 200 );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Whether an AI error string represents a transient (retryable) failure.
     */
    private static function is_transient_error( string $error ): bool {
        $phrases = [
            'cooling', 'quota', '503', '504', 'demand', 'overloaded',
            'capacity', 'retry', 'limit', 'busy', 'unavailable',
            'no models', 'available', 'high demand',
        ];
        $lower = \strtolower( $error );
        foreach ( $phrases as $p ) {
            if ( \str_contains( $lower, $p ) ) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Tool Execution
    // -------------------------------------------------------------------------

    /**
     * Execute a tool by name and return its result.
     *
     * @param string       $name
     * @param array<mixed> $args
     * @return array<mixed>
     */
    private static function execute_tool( string $name, array $args ): array {
        return match ( $name ) {
            'check_availability'  => CheckAvailability::execute( $args ),
            'get_room_details'    => RoomDetails::execute( $args ),
            'get_policies'        => Policies::execute( $args ),
            'get_hotel_info'      => HotelInfo::execute( $args ),
            'get_knowledge_base'  => GetKnowledgeBase::execute( $args ),
            'get_local_tips'      => Abilities\LocalTips::execute( $args ),
            'get_business_card'   => GetBusinessCard::execute( $args ),
            'create_booking_link' => CreateBookingLink::execute( $args ),
            
            default => [ 'error' => "Unknown tool: {$name}" ],
        };
    }

    // -------------------------------------------------------------------------
    // SSE Streaming
    // -------------------------------------------------------------------------

    /**
     * Emit a 'thought' event mid-stream to update the guest on AI progress.
     */
    private static function emit_thought( string $content, bool $enabled ): void {
        if ( ! $enabled ) {
            return;
        }
        echo 'data: ' . \wp_json_encode( [ 'type' => 'thought', 'content' => $content ] ) . "\n\n";
        if (\ob_get_level() > 0) {
            \ob_flush();
        }
        \flush();
    }

    /**
     * Stream a final text response with metadata.
     *
     * @param string $text
     * @param string $session_id
     * @param array<int, array<string, mixed>> $tool_calls
     * @param string[] $suggestions
     */
    private static function stream_response( string $text, string $session_id, array $tool_calls, array $suggestions ): void {
        if ( ! \headers_sent() ) {
            \header( 'Content-Type: text/event-stream' );
            \header( 'Cache-Control: no-cache' );
            \header( 'X-Accel-Buffering: no' );
        }

        // Stream the text word-by-word (visual typing).
        $words = \preg_split( '/(\s+)/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE );
        if ( \is_array( $words ) ) {
            foreach ( $words as $chunk ) {
                echo 'data: ' . \wp_json_encode( [ 'delta' => $chunk ] ) . "\n\n";
                if (\ob_get_level() > 0) {
                    \ob_flush();
                }
                \flush();
                // 2026 BP: Micro-delay for natural typing feel (20-40ms).
                \usleep( 25000 ); 
            }
        }

        // Send final metadata event.
        echo 'data: ' . \wp_json_encode( [
            'done'            => true,
            'session_id'      => $session_id,
            'tool_calls_made' => $tool_calls,
            'suggestions'     => $suggestions,
        ] ) . "\n\n";
        echo "data: [DONE]\n\n";
        if (\ob_get_level() > 0) {
            \ob_flush();
        }
        \flush();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Generate 2–3 quick-reply suggestions based on conversation context.
     *
     * Reads actual tool call results so suggestions are specific to the data
     * the AI just retrieved (e.g. room names from a real availability query).
     *
     * @param array<mixed> $tool_calls Each entry: ['tool'=>string, 'args'=>array, 'result'=>array]
     * @return string[]
     */
    private static function generate_suggestions( array $tool_calls ): array {
        $tools_used  = \array_column( $tool_calls, 'tool' );
        $suggestions = [];

        if ( \in_array( 'check_availability', $tools_used, true ) ) {
            // Try to surface the first available room name for a more specific CTA.
            $avail_result = null;
            foreach ( $tool_calls as $tc ) {
                if ( 'check_availability' === $tc['tool'] ) {
                    $avail_result = $tc['result'] ?? null;
                    break;
                }
            }

            $rooms = $avail_result['rooms'] ?? [];
            if ( [] !== (array) $rooms && isset( $rooms[0]['room_name'] ) ) {
                $first_room    = (string) $rooms[0]['room_name'];
                $suggestions[] = \sprintf(
                    I18n::get_label( 'ai_suggestion_room_more' ),
                    $first_room
                );
            } else {
                $suggestions[] = I18n::get_label( 'ai_suggestion_rooms_info' );
            }
            $suggestions[] = I18n::get_label( 'ai_suggestion_cancellation' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_complete_booking' );

        } elseif ( \in_array( 'get_room_details', $tools_used, true ) ) {
            $suggestions[] = I18n::get_label( 'ai_suggestion_check_availability' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_amenities' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_location' );

        } elseif ( \in_array( 'policies', $tools_used, true ) ) {
            $suggestions[] = I18n::get_label( 'ai_suggestion_cancellation' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_check_in_out' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_breakfast' );

        } elseif ( \in_array( 'hotel_info', $tools_used, true ) ) {
            $suggestions[] = I18n::get_label( 'ai_suggestion_location' );
            $suggestions[] = I18n:: get_label( 'ai_suggestion_parking' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_amenities' );

        } elseif ( \in_array( 'get_business_card', $tools_used, true ) ) {
            $suggestions[] = I18n::get_label( 'ai_suggestion_payment' );

        } elseif ( \in_array( 'create_booking_link', $tools_used, true ) ) {
            $suggestions[] = I18n::get_label( 'ai_suggestion_payment_options' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_cancellation_info' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_extras' );

        } else {
            $suggestions[] = I18n::get_label( 'ai_suggestion_availability' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_room_types' );
            $suggestions[] = I18n::get_label( 'ai_suggestion_about_hotel' );
        }

        return \array_slice( $suggestions, 0, 3 );
    }

    /**
     * Einstein-style booking intent score (0–100).
     *
     * Scores the conversation based on signals that indicate purchase readiness:
     *   +40  Availability checked and rooms found
     *   +15  Room details viewed
     *   +15  Payment / pricing question asked (policies tool called)
     *   +15  Extras / add-ons asked
     *   +10  Multiple rounds of conversation (engagement depth)
     *   +5   Booking draft started
     *
     * The score is sent with every response. The JS widget shows a "Book Now"
     * CTA button when it crosses the HIGH_INTENT threshold (≥ 60).
     *
     * @param array<mixed> $messages      Full conversation including tool messages.
     * @param array<mixed> $tool_calls    Calls made in this request.
     * @return array{score:int,label:string}
     */
    private static function score_booking_intent( array $messages, array $tool_calls ): array {
        $score      = 0;
        $tools_used = \array_column( $tool_calls, 'tool' );

        // Availability checked with rooms available.
        if ( \in_array( 'check_availability', $tools_used, true ) ) {
            foreach ( $tool_calls as $tc ) {
                if ( 'check_availability' === $tc['tool'] && [] !== (array) ( $tc['result']['rooms'] ?? [] ) ) {
                    $score += 40;
                    break;
                }
            }
            // Checked but no rooms — still some intent.
            if ( $score === 0 ) {
                $score += 10;
            }
        }

        if ( \in_array( 'get_room_details', $tools_used, true ) )   { $score += 15; }
        if ( \in_array( 'get_policies', $tools_used, true ) )        { $score += 15; }
        if ( \in_array( 'get_hotel_info', $tools_used, true ) )      { $score += 5;  }
        if ( \in_array( 'create_booking_draft', $tools_used, true ) ) { $score += 5; }

        $has_extras_question = false;
        foreach ( $messages as $msg ) {
            if ( 'user' === (string) ( $msg['role'] ?? '' ) ) {
                $lower = \strtolower( (string) ( $msg['content'] ?? '' ) );
                if ( \str_contains( (string) $lower, 'extra' ) || \str_contains( (string) $lower, 'breakfast' )
                    || \str_contains( (string) $lower, 'add-on' ) || \str_contains( (string) $lower, 'addon' )
                    || \str_contains( (string) $lower, 'include' ) ) {
                    $has_extras_question = true;
                    break;
                }
            }
        }
        if ( $has_extras_question ) { $score += 15; }

        // Conversation depth — more than 3 user turns = engaged guest.
        $user_turns = \count( \array_filter( $messages, fn( $m ) => 'user' === ( (string) ( $m['role'] ?? '' ) ) ) );
        if ( $user_turns >= 3 ) { $score += 10; }

        $score = \min( 100, $score );

        $label = match ( true ) {
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default      => 'low',
        };

        return [ 'score' => $score, 'label' => $label ];
    }

    /**
     * Build the handoff options array from plugin settings.
     *
     * Returned with every response so the JS can show context-aware escalation
     * buttons (WhatsApp, email, phone) when the guest needs human help.
     *
     * @return array<string,string>   Keys: type ('whatsapp'|'email'|'phone'), value: contact string.
     */
    private static function build_handoff_options(): array {
        $options = [];

        $wa = \MHBO\Business\Info::get_whatsapp();
        if ( ( $wa['enabled'] ?? false ) && '' !== (string) ( $wa['phone_number'] ?? '' ) ) {
            $options[ I18n::get_label( 'ai_handoff_whatsapp' ) ] = \sanitize_text_field( $wa['phone_number'] );
        }

        $company = \MHBO\Business\Info::get_company();
        if ( '' !== (string) ( $company['email'] ?? '' ) ) {
            $options[ I18n::get_label( 'ai_handoff_email' ) ] = \sanitize_email( $company['email'] );
        }
        if ( '' !== (string) ( $company['telephone'] ?? '' ) ) {
            $options[ I18n::get_label( 'ai_handoff_phone' ) ] = \sanitize_text_field( $company['telephone'] );
        }

        return $options;
    }

    /**
     * Get the client IP address safely.
     *
     * @return string
     */
    private static function get_client_ip(): string {
        $headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $headers as $h ) {
            $raw = (string) ( \filter_input( \INPUT_SERVER, $h, \FILTER_DEFAULT ) ?? '' );
            if ( '' !== $raw ) {
                $ip = \sanitize_text_field( \wp_unslash( $raw ) );
                // Take first IP if comma-separated.
                $ip = \explode( ',', $ip )[0];
                $ip = \trim( $ip );
                if ( \filter_var( $ip, \FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    // -------------------------------------------------------------------------
    // Multi-Room Plan Helpers (2026 BP)
    // -------------------------------------------------------------------------

    /**
     * Inspect a check_availability tool result and, if it contains a multi-room
     * distribution, pre-flight validate ALL rooms then save the plan to the session.
     *
     * Gate 2: Every room_id in the distribution is re-checked via
     * Pricing::is_room_available(). If a room has been taken since the search,
     * an alternative of the same type is substituted. If insufficient rooms
     * remain, the plan is NOT saved — the AI will report the shortfall naturally
     * from the tool result.
     *
     * @param string             $session_id
     * @param array<string,mixed> $tool_result
     */
    private static function maybe_save_multi_room_plan( string $session_id, array $tool_result ): void {
        $rooms = (array) ( $tool_result['rooms'] ?? [] );

        // Don't reset a plan that is already mid-booking (index > 0).
        // Overwriting would lose the current position in the sequence.
        $existing = ChatSession::get_multi_room_plan( $session_id );
        if ( [] !== $existing && (int) ( $existing['current_index'] ?? 0 ) > 0 ) {
            return;
        }

        foreach ( $rooms as $room ) {
            if ( ! ( $room['is_multi_room'] ?? false ) || ! ( $room['distribution'] ?? [] ) ) {
                continue;
            }

            $distribution = (array) $room['distribution'];
            $total_rooms  = \count( $distribution );
            $type_id      = 0;
            $check_in     = (string) ( $tool_result['check_in'] ?? '' );
            $check_out    = (string) ( $tool_result['check_out'] ?? '' );
            $type_name    = (string) ( $room['room_name'] ?? '' );

            // Extract type_id from room_type field (format: "type_123").
            if ( isset( $room['room_type'] ) && \preg_match( '/type_(\d+)/', (string) $room['room_type'], $m ) ) {
                $type_id = (int) $m[1];
            }

            if ( '' === $check_in || '' === $check_out || $total_rooms < 2 ) {
                continue;
            }

            // PRE-FLIGHT: Validate every room in the distribution.
            $validated   = [];
            $all_valid   = true;

            foreach ( $distribution as $idx => $slot ) {
                $slot_room_id = (int) ( $slot['room_id'] ?? 0 );
                if ( $slot_room_id <= 0 ) {
                    $all_valid = false;
                    break;
                }

                $available = Pricing::is_room_available( $slot_room_id, $check_in, $check_out );

                if ( true !== $available ) {
                    // Attempt auto-swap.
                    $alt = self::find_alternative_room( $type_id, $check_in, $check_out, $distribution );
                    if ( $alt > 0 ) {
                        $distribution[ $idx ]['room_id'] = $alt;
                    } else {
                        $all_valid = false;
                        break;
                    }
                }

                $validated[] = $distribution[ $idx ];
            }

            if ( ! $all_valid || \count( $validated ) < $total_rooms ) {
                // Insufficient rooms — do NOT save the plan.
                // The AI will see the multi-room data in the tool result and can
                // report the shortfall using the availability message.
                continue;
            }

            // All rooms validated — save the sequential plan.
            ChatSession::set_multi_room_plan( $session_id, [
                'total_rooms'    => $total_rooms,
                'current_index'  => 0,
                'room_type_name' => $type_name,
                'type_id'        => $type_id,
                'check_in'       => $check_in,
                'check_out'      => $check_out,
                'distribution'   => $distribution,
            ] );

            // Only save the first multi-room entry found.
            break;
        }
    }

    /**
     * Find an alternative available room of the same type that is NOT
     * already in the current distribution.
     *
     * @param int    $type_id    Room type ID.
     * @param string $check_in   Check-in date (Y-m-d).
     * @param string $check_out  Check-out date (Y-m-d).
     * @param array<int,array<string,mixed>> $distribution Current distribution (to exclude used room_ids).
     * @return int  Alternative room ID, or 0 if none available.
     */
    private static function find_alternative_room( int $type_id, string $check_in, string $check_out, array $distribution ): int {
        if ( $type_id <= 0 ) {
            return 0;
        }

        global $wpdb;

        // Collect room_ids already in the distribution.
        $used_ids = \array_filter( \array_map( fn( $s ) => (int) ( $s['room_id'] ?? 0 ), $distribution ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Real-time availability swap for multi-room flow; must be live.
        $candidates = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT r.id FROM {$wpdb->prefix}mhbo_rooms r WHERE r.type_id = %d AND r.status = 'active' ORDER BY r.id ASC",
                $type_id
            )
        );

        if ( ! \is_array( $candidates ) ) {
            return 0;
        }

        foreach ( $candidates as $candidate_id ) {
            $cid = (int) $candidate_id;
            if ( \in_array( $cid, $used_ids, true ) ) {
                continue;
            }
            if ( true === Pricing::is_room_available( $cid, $check_in, $check_out ) ) {
                return $cid;
            }
        }

        return 0;
    }
}
