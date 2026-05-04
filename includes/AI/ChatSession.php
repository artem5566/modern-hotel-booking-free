<?php
/**
 * Chat Session — stores conversation history per visitor session.
 *
 * FREE:  WP transient (2-hour TTL, last 10 turns).
 * PRO:   Custom DB table with permanent storage and guest-email linking.
 *
 * @package MHBO\AI
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI;

use MHBO\Core\License;

use function sanitize_text_field;
use function wp_unslash;
use function get_transient;
use function set_transient;
use function delete_transient;
use function dbDelta;
use function wp_json_encode;
use function defined;
use function random_bytes;

use const HOUR_IN_SECONDS;
use const MINUTE_IN_SECONDS;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class ChatSession {

    private const COOKIE_NAME   = 'mhbo_chat_session';
    private const FREE_TTL      = 2 * \HOUR_IN_SECONDS;
    private const FREE_MAX_TURN = 10;       // max message pairs stored free
    private const COOKIE_TTL    = 8 * \HOUR_IN_SECONDS;

    // -------------------------------------------------------------------------
    // Session ID
    // -------------------------------------------------------------------------

    /**
     * Return the current session ID, creating one if needed.
     *
     * @return string
     */
    public static function get_session_id(): string {
        // Check existing cookie first.
        // Use filter_input() to avoid static-analysis false positives on $_COOKIE.
        $raw      = (string) ( \filter_input( \INPUT_COOKIE, self::COOKIE_NAME, \FILTER_DEFAULT ) ?? '' );
        $existing = '' !== $raw ? \sanitize_text_field( \wp_unslash( (string) $raw ) ) : '';

        if ( $existing && \preg_match( '/^[a-f0-9]{64}$/', (string) $existing ) ) {
            return (string) $existing;
        }

        // Generate a cryptographically secure session ID (random_bytes — PHP 7.0+ built-in).
        $session_id = \bin2hex( \random_bytes( 32 ) );

        if ( ! \headers_sent() ) {
            \setcookie(
                (string) self::COOKIE_NAME,
                (string) $session_id,
                [
                    'expires'  => \time() + (int) self::COOKIE_TTL,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => \is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        return (string) $session_id;
    }

    // -------------------------------------------------------------------------
    // History
    // -------------------------------------------------------------------------

    /**
     * Get conversation history for a session.
     *
     * @param string $session_id
     * @return array<array{role:string,content:string}>
     */
    public static function get_history( string $session_id ): array {
        
        $transient_key = self::transient_key( $session_id );
        $history       = \get_transient( $transient_key );

        return \is_array( $history ) ? $history : [];
    }

    /**
     * Append a message to the session history.
     *
     * @param string $session_id
     * @param string $role    'user' | 'assistant' | 'tool'
     * @param string $content
     * @param array<mixed> $meta Optional metadata (tool_calls, raw_parts, thought_signature).
     */
    public static function add_message( string $session_id, string $role, string $content, array $meta = [] ): void {

// Free path: transient.
        $transient_key = self::transient_key( $session_id );
        $history       = \get_transient( $transient_key );
        if ( ! \is_array( $history ) ) {
            $history = [];
        }

        $msg = [
            'role'    => (string) $role,
            'content' => (string) $content,
        ];
        if ( [] !== (array) $meta ) {
            $msg = \array_merge( $msg, (array) $meta );
        }
        $history[] = $msg;

        // Enforce free-tier limit (keep last N pairs of user+assistant).
        $limit = (int) self::FREE_MAX_TURN * 2;
        if ( \count( $history ) > $limit ) {
            $history = \array_slice( $history, - $limit );
        }
        \set_transient( $transient_key, $history, (int) self::FREE_TTL );
    }

    /**
     * Store the guest's name in the session for proactive personalization.
     *
     * @param string $session_id
     * @param string $name
     */
    public static function set_guest_name( string $session_id, string $name ): void {
        \set_transient( 'mhbo_chat_name_' . \substr( $session_id, 0, 32 ), $name, (int) self::FREE_TTL );
    }

    /**
     * Store the guest's email in the session.
     *
     * @param string $session_id
     * @param string $email
     */
    public static function set_guest_email( string $session_id, string $email ): void {
        \set_transient( 'mhbo_chat_email_' . \substr( $session_id, 0, 32 ), $email, (int) self::FREE_TTL );
    }

    /**
     * Store the guest's phone in the session.
     *
     * @param string $session_id
     * @param string $phone
     */
    public static function set_guest_phone( string $session_id, string $phone ): void {
        \set_transient( 'mhbo_chat_phone_' . \substr( $session_id, 0, 32 ), $phone, (int) self::FREE_TTL );
    }

    // -------------------------------------------------------------------------
    // Multi-Room Plan State (2026 BP: Sequential One-Card-Per-Turn)
    // -------------------------------------------------------------------------

    /**
     * Store a multi-room booking plan in the session.
     *
     * Called after CheckAvailability returns is_multi_room=true AND the
     * pre-flight availability check confirms all rooms are available.
     *
     * @param string              $session_id
     * @param array<string,mixed> $plan {
     *     @type int      $total_rooms    Total rooms required (e.g. 3).
     *     @type int      $current_index  0-based index of the next room to book.
     *     @type string   $room_type_name Display name of the room type.
     *     @type int      $type_id        Room type ID.
     *     @type string   $check_in       Check-in date (Y-m-d).
     *     @type string   $check_out      Check-out date (Y-m-d).
     *     @type array[]  $distribution   Per-room split: [{room_id, adults, children}].
     * }
     */
    public static function set_multi_room_plan( string $session_id, array $plan ): void {
        $key = 'mhbo_chat_mrp_' . \substr( $session_id, 0, 32 );
        \set_transient( $key, $plan, (int) self::FREE_TTL );
    }

    /**
     * Retrieve the active multi-room plan for this session.
     *
     * @param string $session_id
     * @return array<string,mixed> The plan array, or empty array if none active.
     */
    public static function get_multi_room_plan( string $session_id ): array {
        $key  = 'mhbo_chat_mrp_' . \substr( $session_id, 0, 32 );
        $plan = \get_transient( $key );
        return \is_array( $plan ) ? $plan : [];
    }

    /**
     * Advance the multi-room plan to the next room.
     *
     * Increments current_index. If all rooms are booked, clears the plan.
     *
     * @param string $session_id
     */
    public static function advance_multi_room( string $session_id ): void {
        $plan = self::get_multi_room_plan( $session_id );
        if ( [] === $plan ) {
            return;
        }

        $plan['current_index'] = ( (int) ( $plan['current_index'] ?? 0 ) ) + 1;

        if ( $plan['current_index'] >= (int) ( $plan['total_rooms'] ?? 0 ) ) {
            self::clear_multi_room_plan( $session_id );
            return;
        }

        self::set_multi_room_plan( $session_id, $plan );
    }

    /**
     * Update a specific room in the distribution (e.g. after an availability swap).
     *
     * @param string $session_id
     * @param int    $index       0-based index in the distribution array.
     * @param int    $new_room_id Replacement room ID.
     */
    public static function swap_multi_room_id( string $session_id, int $index, int $new_room_id ): void {
        $plan = self::get_multi_room_plan( $session_id );
        if ( [] === $plan || ! isset( $plan['distribution'][ $index ] ) ) {
            return;
        }

        $plan['distribution'][ $index ]['room_id'] = $new_room_id;
        self::set_multi_room_plan( $session_id, $plan );
    }

    /**
     * Clear the multi-room plan (booking complete or abandoned).
     *
     * @param string $session_id
     */
    public static function clear_multi_room_plan( string $session_id ): void {
        \delete_transient( 'mhbo_chat_mrp_' . \substr( $session_id, 0, 32 ) );
    }

    /**
     * Set a verification code for the current session.
     * 2026 BP: Uses transients for temporary, session-linked high-entropy codes.
     *
     * @param string $session_id
     * @param string $code  6-digit numeric code.
     * @param string $email The email address being verified.
     */
    public static function set_verification_code( string $session_id, string $code, string $email ): void {
        $key = 'mhbo_chat_otp_' . \substr( $session_id, 0, 32 );
        \set_transient( $key, [
            'code'  => (string) $code,
            'email' => (string) $email,
        ], 20 * \MINUTE_IN_SECONDS );
    }

    /**
     * Verify a code for the current session.
     * Mandatory brute-force protection: Lockout after 10 failed attempts.
     *
     * @param string $session_id
     * @param string $code
     * @return array{success:bool,message:string}
     */
    public static function verify_code( string $session_id, string $code ): array {
        $short_id   = \substr( $session_id, 0, 32 );
        $key        = 'mhbo_chat_otp_' . $short_id;
        $fail_key   = 'mhbo_chat_otp_failed_' . $short_id;
        $stored     = \get_transient( $key );
        $attempts   = (int) \get_transient( $fail_key );

        if ( $attempts >= 10 ) {
            return [
                'success' => false,
                'message' => \MHBO\Core\I18n::get_label( 'ai_error_otp_lockout' ),
            ];
        }

        if ( ! \is_array( $stored ) || '' === (string) ( $stored['code'] ?? '' ) ) {
            return [
                'success' => false,
                'message' => \MHBO\Core\I18n::get_label( 'ai_error_otp_expired' ),
            ];
        }

        if ( (string) $code !== (string) $stored['code'] ) {
            \set_transient( $fail_key, $attempts + 1, 1 * \HOUR_IN_SECONDS );
            return [
                'success' => false,
                'message' => \sprintf(
                    \MHBO\Core\I18n::get_label( 'ai_error_otp_invalid' ),
                    10 - ( $attempts + 1 )
                ),
            ];
        }

        // Success: Mark as verified for this specific email.
        $verified_key = 'mhbo_chat_verified_' . $short_id . '_' . \md5( \strtolower( (string) $stored['email'] ) );
        \set_transient( $verified_key, true, 4 * \HOUR_IN_SECONDS );
        
        // Clear the OTP and failed count.
        \delete_transient( $key );
        \delete_transient( $fail_key );

        return [
            'success' => true,
            'message' => \MHBO\Core\I18n::get_label( 'ai_msg_otp_verified' ),
        ];
    }

    /**
     * Check if a specific email has been verified in the current session.
     *
     * @param string $session_id
     * @param string $email
     * @return bool
     */
    public static function is_email_verified( string $session_id, string $email ): bool {
        $short_id     = \substr( $session_id, 0, 32 );
        $verified_key = 'mhbo_chat_verified_' . $short_id . '_' . \md5( \strtolower( (string) $email ) );
        return (bool) \get_transient( $verified_key );
    }

    /**
     * Retrieve the guest's name if known for this session.
     *
     * @param string $session_id
     * @return string
     */
    public static function get_guest_name( string $session_id ): string {
        $name = \get_transient( 'mhbo_chat_name_' . \substr( $session_id, 0, 32 ) );
        return \is_string( $name ) ? $name : '';
    }

    /**
     * Retrieve the guest's email if known for this session.
     *
     * @param string $session_id
     * @return string
     */
    public static function get_guest_email( string $session_id ): string {
        $email = \get_transient( 'mhbo_chat_email_' . \substr( $session_id, 0, 32 ) );
        return \is_string( $email ) ? $email : '';
    }

    /**
     * Retrieve the guest's phone if known for this session.
     *
     * @param string $session_id
     * @return string
     */
    public static function get_guest_phone( string $session_id ): string {
        $phone = \get_transient( 'mhbo_chat_phone_' . \substr( $session_id, 0, 32 ) );
        return \is_string( $phone ) ? $phone : '';
    }

    /**
     * Clear a session's history.
     *
     * @param string $session_id
     */
    public static function clear_session( string $session_id ): void {
        \delete_transient( self::transient_key( $session_id ) );

}

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param string $session_id
     * @return string
     */
    private static function transient_key( string $session_id ): string {
        return 'mhbo_chat_' . \substr( $session_id, 0, 32 );
    }

    // -------------------------------------------------------------------------
    // PRO: DB storage
    // -------------------------------------------------------------------------

}
