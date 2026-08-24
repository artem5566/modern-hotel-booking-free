<?php

/**
 * Knowledge Base — assembles the AI system prompt and tool definitions.
 *
 * @package MHBO\AI
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MHBO\Business\Info;
use MHBO\Core\I18n;
use MHBO\Core\License;
use MHBO\Core\Money;
use MHBO\Core\Pricing;

use function __;
use function esc_url;
use function get_bloginfo;
use function get_option;
use function get_permalink;
use function home_url;
use function sprintf;
use function gmdate;
use function number_format;
use function defined;

if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

class KnowledgeBase {

    private const OPT_PERSONA_NAME    = 'mhbo_ai_persona_name';
    private const OPT_CUSTOM_INSTR    = 'mhbo_ai_custom_instructions';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

/**
     * Build and return the complete system prompt for the AI concierge.
     *
     * @param string $lang BCP-47 language tag of the current page (e.g. "ro-RO", "en-US").
     *                     Empty string means no language override.
     * @return string
     */
    public static function get_system_prompt( string $lang = '' ): string {
        $company      = Info::get_company();
        $hotel_name   = $company['company_name'] ?: get_bloginfo( 'name' );
        $persona_name = (string) get_option( self::OPT_PERSONA_NAME, __( 'AI Concierge', 'modern-hotel-booking' ) );
        $custom_instr = (string) get_option( self::OPT_CUSTOM_INSTR, '' );
        $kb_text      = SiteScanner::get_or_build();

        $custom_block = $custom_instr ? "\n{$custom_instr}" : '';
        $lang_block   = $lang ? "- Language: This page is served in language tag \"{$lang}\". Always reply in that language. If the guest writes in a different language, match their language instead." : '';

        $scarcity_rule = "- SCARCITY URGENCY: If inventory for a requested date is low (less than 3 units), EXPLICITLY highlight this urgency in your response using bold or ALL CAPS (e.g., \"ONLY 2 ROOMS LEFT!\").\n";
        $scarcity_rule .= "- PERSUASION: Use professional, high-hospitality language.\n";

        $rules_text = self::get_instruction_rules( false );

        return "You are {$persona_name}, the AI concierge for {$hotel_name}.{$custom_block}\n" .
               "\n" .
               "=== HOTEL KNOWLEDGE BASE ===\n" .
               "{$kb_text}\n" .
               "\n" .
               "=== INSTRUCTIONS ===\n" .
               "- Always be helpful, warm, and professional.\n" .
               "{$lang_block}\n" .
               "{$scarcity_rule}\n" .
               "{$rules_text}";
    }

    /**
     * Build the instructions block for the AI concierge.
     * Separated for clarity and maintenance of the 2026 Agentic Rulebook.
     * 
     * @return string
     */
    /**
     * Build the instructions block for the AI concierge.
     *
     * @param bool $include_pro Whether to include Pro-only protocol rules in the output.
     *                          Must be true only when false is already confirmed.
     * @return string
     */
    private static function get_instruction_rules( bool $include_pro = false ): string {
        $today        = \gmdate( 'Y-m-d' );
        $booking_url  = self::get_booking_url();
        $booking_line = $booking_url ? "- BOOKING LINKS: Use the PRE-FILLED booking_url returned by tools (e.g. check_availability). Never provide a naked link like {$booking_url} if a specific tool result is available." : '';
        $deposit_line = self::get_deposit_prompt_line();

        $stay_limits_line = '';

// ── Rule 2: Booking Flow — Pro-only protocol appended via PHP logic, not string literals. ──
        $pro_protocol_block = '';

// ── Rule 3: Atomic multi-room (Pro only). ──
        $atomic_multiroom_block = '';

// ── Rule 6: Loyalty & Identity Handshake (Pro only). ──
        $loyalty_block = '';

// ── Rule 9: Pro personalization confirmation. ──
        $personalization_pro = '';

return "=== CONCIERGE RULEBOOK (2026) ===\n" .
               "1. AGENTIC FINALIZATION & PROACTIVE MANDATE: You are a booking agent, not just a link provider. If a guest confirms dates and guest count, immediately call `check_availability` to start the booking flow. IF YOU HAVE ALL REQUIRED PARAMETERS FOR A TOOL (like Name, Email, Phone for a booking), YOU MUST CALL THE TOOL IMMEDIATELY. DO NOT ASK FOR PERMISSION TO EXECUTE A TOOL IF YOU HAVE THE DATA. PROCEED WITHOUT HESITATION.\n" .
               "\n" .
               "2. BOOKING FLOW PROTOCOL:\n" .
               "   TIER DETECTION: Check the `is_pro` field in the check_availability response.\n" .
               "   NEURAL DAMPER: NEVER mention technical issues, API errors, 404s, 503s, or retries to the guest. If a tool fails but a retry succeeds, remain in character and only report the outcome.\n" .
               "   BUTTON POLICY: When generating a booking link via 'create_booking_link', NEVER output the raw URL in your text response. A premium styled button will be rendered automatically by the UI from the tool's result. Just confirm that you've prepared the booking details for them.\n" .
               "   DECISIVENESS: Once you have collected the guest's name, email, and phone (if required), trigger the 'create_booking_link' tool IMMEDIATELY. Do not ask for permission again or wait for further confirmation if the intent is clear.\n" .
               "\n" .
               "   FREE PROTOCOL:\n" .
               "   a. After availability results, collect guest Name, Email, and Phone conversationally.\n" .
               "   b. SINGLE ROOM: Call `create_booking_link` once with the chosen room_id, adults, and children.\n" .
               "   b-MULTI-ROOM: If check_availability returns a result where is_multi_room is true and a distribution array is present:\n" .
               "     1. Explain to the guest: 'Your group of [N] guests requires [X] rooms of type [name] (max [cap] guests each). I will need your Full Name, Email, and Phone to prepare the individual booking cards for you.'\n" .
               "     2. Generate ONLY ONE create_booking_link call for the FIRST entry in the distribution (room_id, adults, children from distribution[0]). Include multi_room_index=1 and multi_room_total=[X] in the call.\n" .
               "     3. After the card appears, tell the guest: 'This is booking 1 of [X]. Once you complete this reservation, PLEASE COME BACK TO THIS CHAT and say \"next room\" or \"continue\" so I can prepare the next booking card for you. I have [X-1] more rooms to book for your group.'\n" .
               "     4. For subsequent rooms (when the system injects MULTI-ROOM BOOKING IN PROGRESS context), generate ONE create_booking_link for the specified room only.\n" .
               "     5. After the final room card, tell the guest: 'All [X] bookings are now prepared! Please ensure you have completed every reservation using the cards above.'\n" .
               "     6. NEVER generate multiple create_booking_link calls in the same turn for multi-room bookings.\n" .
               "\n" .
               "   c. Present the pre-filled booking card with the full price summary.\n" .
               "   d. Present deposit amount and payment method details from the link response.\n" .
               "   e. If bank transfer or Revolut is available, present payment details with reference info.\n" .
               "   f. For booking changes or cancellations, call `get_business_card` and provide contact information.\n" .
               $pro_protocol_block .
               "\n" .
               $atomic_multiroom_block .
               "\n" .
               "4. PAYMENT PRESENTATION RULES:\n" .
               "   - When generating a booking link/draft, rely on the summary card logic. DO NOT call `get_business_card` or list manual banking/contact/phone details in your text unless the guest specifically asks for \"manual transfer info\" or \"alternative payment methods\".\n" .
               "   - ONLY use `get_business_card` when guests ask specifically about contact info, company registration, or manual banking details.\n" .
               "   - NEVER invent banking details — only present what the tool returns.\n" .
               "   - When banking shows a reference_prefix, tell the guest: \"Use reference [PREFIX]-[BOOKING_ID].\"\n" .
               "   - If Revolut has a qr_code_url, mention: \"You can scan our QR code for instant payment.\"\n" .
               "   - If WhatsApp is enabled, always mention it as an instant support channel.\n" .
               "\n" .
               "5. FINANCIAL TRANSPARENCY:\n" .
               "   - Always mention deposit requirements BEFORE the guest commits to booking.\n" .
               "   - Always clarify if tax is inclusive or exclusive in the quoted price.\n" .
               "   - Always present the full price breakdown: base price, deposit, and tax.\n" .
               "   - If extras are available (breakfast, spa, transport), suggest ONE relevant extra naturally.\n" .
               $loyalty_block .
               "\n" .
               "7. SENTIMENT & ESCALATION:\n" .
               "   - Detect frustration, confusion, or reports of technical errors. \n" .
               "   - Pivot immediately to empathy: Apologize sincerely and call `get_business_card` to provide the hotel's direct contact details for high-priority human assistance.\n" .
               "\n" .
               "8. INVENTORY TRANSPARENCY:\n" .
               "   - If a room type has low stock (< 5 units), inform the guest: \"We only have X rooms of this type left for your dates.\"\n" .
               "\n" .
               "9. PERSONALIZATION:\n" .
               "   - Always address the guest by their first name once they have provided it.\n" .
               $personalization_pro .
               "\n" .
               "10. FORMATTING, LINKS & TTS:\n" .
               "   - PROHIBITED: Never use markdown asterisks (*) for lists or bolding. Use simple dashes (-) and plain text.\n" .
               "   - SAFE LINKS: Never wrap URLs in bold, italics, brackets, or HTML tags. Place the URL on its own line with no other text to trigger high-visibility buttons for payments and resumptions.\n" .
               "   - TTS (Reading): Never read aloud raw URLs. Say \"the secure booking link\" instead.\n" .
               "   - CURRENCY: Always use words like \"dollars\" or \"euros\". \n" .
               "   - DATES: Today is {$today}. Never ask the user to format dates like \"YYYY-MM-DD\".\n" .
               "\n" .
               "{$stay_limits_line}" .
               "{$booking_line}\n" .
               "{$deposit_line}";
    }

    /**
     * Resolve the configured booking page URL (mirrors Calendar.php logic).
     *
     * Checks mhbo_booking_page_url override first, then falls back to
     * get_permalink( mhbo_booking_page ), then home_url('/').
     *
     * @return string
     */
    public static function get_booking_url(): string {
        $override = I18n::decode( (string) get_option( 'mhbo_booking_page_url', '' ), null, false );
        if ( '' !== $override ) {
            return esc_url_raw( $override );
        }

        $page_id = (int) get_option( 'mhbo_booking_page', 0 );
        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
            if ( $url ) {
                return esc_url_raw( $url );
            }
        }

        return home_url( '/' );
    }

    /**
     * Build a deposit policy line for the system prompt (empty if deposits disabled).
     *
     * @return string
     */
    private static function get_deposit_prompt_line(): string {
        if ( ! get_option( 'mhbo_deposits_enabled', 0 ) ) {
            return '';
        }

        $type  = (string) get_option( 'mhbo_deposit_type', 'percentage' );
        $value = (float)  get_option( 'mhbo_deposit_value', 20 );
        $non_r = (bool)   get_option( 'mhbo_deposit_non_refundable', 0 );
        $refund_note = $non_r ? ' (non-refundable)' : '';

        return match ( (string) $type ) {
            // Standard first night rate logic.
            'first_night' => \sprintf(
                // translators: %1$s: optional refund policy note e.g. " (non-refundable)"
                \__( '- A deposit of your first night\'s rate is required at booking time%1$s.', 'modern-hotel-booking' ),
                (string) $refund_note
            ),

            // Fixed amount logic.
            'fixed' => \sprintf(
                // translators: %1$s: deposit amount, %2$s: currency symbol, %3$s: optional refund policy note
                \__( '- A deposit of %1$s %2$s is required at booking time%3$s.', 'modern-hotel-booking' ),
                \number_format( (float) $value, 2 ),
                (string) \get_option( 'mhbo_currency_symbol', '$' ),
                (string) $refund_note
            ),

            // Percentage is the standard default for MHBO.
            'percentage' => \sprintf(
                // translators: %1$d: deposit percentage, %2$s: optional refund policy note
                \__( '- A deposit of %1$d%% of the total is required at booking time%2$s.', 'modern-hotel-booking' ),
                (int) $value,
                (string) $refund_note
            ),

            default => \sprintf(
                // translators: %1$d: deposit percentage, %2$s: optional refund policy note
                \__( '- A deposit of %1$d%% of the total is required at booking time%2$s.', 'modern-hotel-booking' ),
                (int) $value,
                (string) $refund_note
            ),
        } . ' ' . \__( 'Inform guests when discussing pricing.', 'modern-hotel-booking' );
    }

    /**
     * Return tool definitions for the AI provider.
     *
     * @param bool $include_pro Whether to include Pro tools.
     * @return array<mixed>
     */
    public static function get_tool_definitions( bool $include_pro = false ): array {
        $tools = [
            self::def_check_availability(),
            self::def_get_room_details(),
            self::def_get_policies(),
            self::def_get_hotel_info(),
            self::def_get_local_tips(),
            self::def_get_price_breakdown(),
            self::def_recommend_rooms(),
            self::def_create_booking_link(),
            self::def_get_business_card(),
        ];

return $tools;
    }

    // -------------------------------------------------------------------------
    // Tool Definition Helpers (OpenAI function-calling format)
    // -------------------------------------------------------------------------

    /** @return array<mixed> */
    private static function def_check_availability(): array {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'check_availability',
                'description' => 'Check real-time room availability for specific dates and guest count. Returns available room types with pricing and pre-filled booking URLs for manual completion.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'check_in'  => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-in date.' ],
                        'check_out' => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-out date.' ],
                        'adults'    => [ 'type' => 'integer', 'default' => 2, 'minimum' => 1, 'maximum' => 10, 'description' => 'Number of adults.' ],
                        'children'  => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Number of children.' ],
                        'room_type' => [ 'type' => 'string', 'description' => 'Optional filter by room type name or ID.' ],
                    ],
                    'required' => [ 'check_in', 'check_out' ],
                ],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function def_get_room_details(): array {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_room_details',
                'description' => 'Get detailed information about a specific room type including amenities, capacity, and pricing.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'room_id' => [ 'type' => 'string', 'description' => 'Room type ID (integer) or name slug.' ],
                    ],
                    'required' => [ 'room_id' ],
                ],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function def_get_policies(): array {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_policies',
                'description' => 'Retrieve hotel policies including cancellation, check-in/out, pets, smoking, and payment.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'type' => [
                            'type'        => 'string',
                            'enum'        => [ 'cancellation', 'checkin', 'checkout', 'pets', 'smoking', 'children', 'payment', 'all' ],
                            'default'     => 'all',
                            'description' => 'The type of policy to retrieve.',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function def_get_hotel_info(): array {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_hotel_info',
                'description' => 'Get general hotel information including name, address, contact details, and amenities.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => (object) [],
                    'required'   => [],
                ],
            ],
        ];
    }

/** @return array<mixed> */
    private static function def_get_local_tips(): array {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_local_tips',
                'description' => 'Retrieve recommendations for nearby restaurants, attractions, and local travel tips.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'category' => [ 
                            'type' => 'string', 
                            'enum' => [ 'dining', 'attractions', 'transit', 'general' ] 
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function def_create_booking_link(): array {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'create_booking_link',
                'description' => 'Generate a pre-filled booking link for a guest with full pricing, deposit, and payment details. Use this when the guest has confirmed their room choice, dates, and guest count. Does not create a booking — the guest completes the reservation by clicking the link.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'room_id'          => [ 'type' => 'integer', 'description' => 'Room ID from the availability check.' ],
                        'type_id'          => [ 'type' => 'integer', 'description' => 'Room type ID from the availability check.' ],
                        'check_in'         => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-in date.' ],
                        'check_out'        => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-out date.' ],
                        'adults'           => [ 'type' => 'integer', 'minimum' => 1, 'default' => 2, 'description' => 'Number of adults.' ],
                        'children'         => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Number of children.' ],
                        'guest_name'       => [ 'type' => 'string', 'description' => 'Full name of the primary guest.' ],
                        'guest_email'      => [ 'type' => 'string', 'format' => 'email', 'description' => 'Guest email address.' ],
                        'guest_phone'      => [ 'type' => 'string', 'description' => 'Guest phone number.' ],
                        'multi_room_index' => [ 'type' => 'integer', 'description' => 'Sequential room number (1-based) in a multi-room group. REQUIRED for multi-room bookings.' ],
                        'multi_room_total' => [ 'type' => 'integer', 'description' => 'Total rooms required in the multi-room group. REQUIRED for multi-room bookings.' ],
                    ],
                    'required' => [ 'room_id', 'check_in', 'check_out', 'adults', 'guest_name', 'guest_email', 'guest_phone' ],
                ],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function def_get_business_card(): array {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_business_card',
                'description' => 'Get hotel contact details, payment methods, banking info, Revolut, WhatsApp, and deposit policy. Use when guests ask about paying, contacting the hotel, or need business details.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'sections' => [
                            'type'        => 'string',
                            'enum'        => [ 'all', 'company', 'payments', 'banking', 'revolut', 'whatsapp', 'deposit' ],
                            'default'     => 'all',
                            'description' => 'Which section to retrieve. Use "all" for the full card.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function def_get_price_breakdown(): array {
        $properties = [
            'check_in'  => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-in date (YYYY-MM-DD).' ],
            'check_out' => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-out date (YYYY-MM-DD).' ],
            'room_id'   => [ 'type' => 'integer', 'description' => 'Optional specific room ID.' ],
            'type_id'   => [ 'type' => 'integer', 'description' => 'Optional room type ID.' ],
            'adults'    => [ 'type' => 'integer', 'default' => 2, 'minimum' => 1, 'description' => 'Number of adults.' ],
            'children'  => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Number of children.' ],
        ];

return [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_price_breakdown',
                'description' => 'Get an itemized price breakdown for a stay including base rates, taxes, deposits, and discounts.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => $properties,
                    'required'   => [ 'check_in', 'check_out' ],
                ],
            ],
        ];
    }

    /** @return array<mixed> */
    private static function def_recommend_rooms(): array {
        $properties = [
            'check_in'            => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-in date (YYYY-MM-DD).' ],
            'check_out'           => [ 'type' => 'string', 'format' => 'date', 'description' => 'Check-out date (YYYY-MM-DD).' ],
            'adults'              => [ 'type' => 'integer', 'default' => 2, 'minimum' => 1, 'description' => 'Number of adults.' ],
            'children'            => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Number of children.' ],
            'max_budget'          => [ 'type' => 'number', 'description' => 'Maximum total stay budget.' ],
            'preferred_amenities' => [
                'type'        => 'array',
                'items'       => [ 'type' => 'string' ],
                'description' => 'Desired amenities (e.g. WiFi, Sea View, Balcony, AC, Pool, Breakfast).',
            ],
        ];

return [
            'type'     => 'function',
            'function' => [
                'name'        => 'recommend_rooms',
                'description' => 'Recommend and rank available room options based on budget caps, capacity fit, and guest amenity preferences.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => $properties,
                    'required'   => [ 'check_in', 'check_out' ],
                ],
            ],
        ];
    }

}
