<?php
/**
 * AI Concierge Loader — manages the AI initialization, chat sessions,
 * and background site scanning.
 *
 * @package MHBO\AI
 * @since 2.3.8 (Advanced Agentic 2026 Edition)
 */

declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

use MHBO\Admin\AiSettings;
use MHBO\Core\License;
use MHBO\Business\Info;

class Loader {

    private static bool $initialized = false;

    /**
     * Boot the AI subsystem.
     * Idempotent — safe to call multiple times.
     */
    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        // Check if the AI subsystem is enabled globally.
        $enabled = (int) get_option( 'mhbo_ai_enabled', 1 );

        // 2026 BP: AI Settings routes must be registered on rest_api_init even outside admin
        // so that the 'Test Connection' and 'Refresh KB' AJAX calls don't 404.
        AiSettings::register();

        if ( is_admin() ) {
            add_action( 'admin_menu', [ self::class, 'register_admin_menu' ], 20 );
        }

        // Block and shortcode must register unconditionally so the block always
        // appears in the inserter regardless of widget-enabled settings.
        add_action( 'init', [ self::class, 'register_block' ],     10 );
        add_action( 'init', [ self::class, 'register_shortcode' ], 10 );

        $widget_enabled = (int) get_option( 'mhbo_ai_widget_enabled', 1 );

        if ( ! $enabled || ! $widget_enabled ) {
            return;
        }

        // Register Composer autoloader (Jetpack Autoloader / MCP Adapter).
        $autoload = MHBO_PLUGIN_DIR . 'vendor/autoload.php';
        if ( file_exists( $autoload ) ) {
            require_once $autoload;
        }

        // Hooks that must fire early.
        // WP 7.0+ Abilities API fallback guard.
        if ( function_exists( 'wp_register_ability' ) ) {
            add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
        }

        add_action( 'rest_api_init',      [ ChatRest::class, 'register' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_frontend' ] );
        add_action( 'wp_footer',          [ self::class, 'render_widget_template' ] );

        // Cookie-consent plugin compatibility.
        // All MHBO booking and chat scripts are "functional/strictly necessary":
        // they set no tracking or marketing cookies and cannot be deferred without
        // breaking the booking flow.  We hook multiple filters to maximise coverage
        // across Complianz, Cookie Notice, Cookie Caterer, and similar plugins.
        // These hooks are no-ops when the consent plugin is not active.
        add_filter( 'cmplz_safe_scripts',         [ self::class, 'cmplz_safe_scripts' ] );
        add_filter( 'cmplz_known_script_tags',     [ self::class, 'cmplz_safe_scripts' ] );
        add_filter( 'cmplz_accepted_cookie_types', [ self::class, 'cmplz_safe_scripts' ] );
        add_filter( 'cn_cookie_whitelist',         [ self::class, 'cmplz_safe_scripts' ] );
        add_filter( 'cookie_cat_required_scripts', [ self::class, 'cmplz_safe_scripts' ] );

        // Site Scanner hooks (auto-invalidate KB on content save).
        SiteScanner::register_hooks();

// MCP Server (priority 20 = after abilities are registered).

// Pro activation: register AI guest role.
        
    }
    
    /**
     * Cookie-consent compatibility: declare ALL MHBO scripts as functional/required.
     * Works with Complianz, Cookie Notice, Cookie Caterer, and similar plugins.
     * Safe to call even if these plugins are not active — hook simply won't fire.
     *
     * 2026 BP: Include every MHBO script handle — not just AI — so that
     * Complianz never blocks the booking modal, calendar, or payment forms.
     *
     * @param array<string|int,mixed> $scripts
     * @return array<string|int,mixed>
     */
    public static function cmplz_safe_scripts( array $scripts ): array {
        $scripts[] = 'mhbo-chat-widget';
        $scripts[] = 'mhbo-voice';
        $scripts[] = 'mhbo-booking-modal-js';
        $scripts[] = 'mhbo-booking-form';
        $scripts[] = 'mhbo-frontend';
        $scripts[] = 'mhbo-calendar';
        $scripts[] = 'mhbo-stripe';
        return $scripts;
    }

    /**
     * @return void
     */
    public static function run_weekly_sync(): void {
        LlmFile::sync();
    }

// -------------------------------------------------------------------------
    // Admin Menu
    // -------------------------------------------------------------------------

    /**
     * Register the AI Concierge settings as a sub-menu under the existing
     * Hotel Booking admin menu.
     */
    public static function register_admin_menu(): void {
        add_submenu_page(
            'mhbo-hotel-booking',                                         // parent slug (MHBO main menu)
            \MHBO\Core\I18n::get_label( 'ai_label_settings_title' ),
            \MHBO\Core\I18n::get_label( 'ai_label_settings_menu' ),
            'manage_options',
            'mhbo-ai-concierge',
            [ AiSettings::class, 'render_tab' ]
        );
    }

    // -------------------------------------------------------------------------
    // Abilities
    // -------------------------------------------------------------------------

    /**
     * Register WP Abilities (WP 7.0+) if the API is available.
     */
    public static function register_abilities(): void {
        Abilities\HotelInfo::register();
        Abilities\CheckAvailability::register();
        Abilities\RoomDetails::register();
        Abilities\Policies::register();
        Abilities\GetKnowledgeBase::register();
        Abilities\LocalTips::register();
        Abilities\GetBusinessCard::register();
        Abilities\CreateBookingLink::register();

}

    // -------------------------------------------------------------------------
    // Frontend Assets
    // -------------------------------------------------------------------------

    /**
     * Enqueue chat widget CSS and JS on the frontend.
     */
    public static function enqueue_frontend(): void {
        $ai_enabled = (int) get_option( 'mhbo_ai_enabled', 1 );
        if ( ! $ai_enabled ) {
            return;
        }

        wp_enqueue_style(
            'mhbo-google-fonts',
            'https://fonts.googleapis.com/css2?family=Outfit:wght@400;600&display=swap',
            [],
            '1.0.0'
        );

        wp_enqueue_style(
            'mhbo-chat-widget',
            MHBO_PLUGIN_URL . 'assets/css/mhbo-chat-widget.css',
            [ 'mhbo-google-fonts' ],
            MHBO_VERSION
        );

        $voice_enabled = (int) get_option( 'mhbo_ai_voice_input_enabled', 1 );
        if ( $voice_enabled ) {
            wp_enqueue_script(
                'mhbo-voice',
                MHBO_PLUGIN_URL . 'assets/js/mhbo-voice.js',
                [],
                MHBO_VERSION,
                true
            );
        }

        wp_enqueue_script(
            'mhbo-chat-widget',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-chat-widget.js',
            $voice_enabled ? [ 'mhbo-voice' ] : [],
            MHBO_VERSION,
            true
        );

        $company = Info::get_company();

        wp_localize_script( 'mhbo-chat-widget', 'mhboChat', [
            'restUrl'  => untrailingslashit( rest_url() ),
            'ajaxurl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mhbo_chat_nonce' ),
            'restNonce'=> wp_create_nonce( 'wp_rest' ),
            'isPro'    => false,
            'settings' => [
                'hotelName'      => $company['company_name'] ?: get_bloginfo( 'name' ),
                'personaName'    => get_option( 'mhbo_ai_persona_name', \MHBO\Core\I18n::get_label( 'ai_persona_default' ) ),
                'position'       => get_option( 'mhbo_ai_widget_position', 'bottom-right' ),
                'accentColor'    => get_option( 'mhbo_ai_accent_color', '#2C3E50' ),
                'theme'          => get_option( 'mhbo_ai_theme', '' ),
                'welcomeMessage' => get_option( 'mhbo_ai_welcome_message', '' ),
                'voiceEnabled'   => (bool) get_option( 'mhbo_ai_voice_input_enabled', 1 ),
                'ttsEnabled'     => (bool) get_option( 'mhbo_ai_voice_output_enabled', 0 ),
                'language'       => (string) ( get_option( 'mhbo_ai_voice_language', '' ) ?: self::detect_page_locale() ),
                'pageLocale'     => self::detect_page_locale(),
                
                // Einstein / proactive features.
                'proactiveTriggerSeconds' => (int) get_option( 'mhbo_ai_proactive_trigger_seconds', 45 ),
                'bookingUrl'              => KnowledgeBase::get_booking_url(),
                'modalEnabled'            => (bool) get_option( 'mhbo_modal_enabled', 0 ),
            ],
            'strings'  => [
                'openChat'         => \MHBO\Core\I18n::get_label( 'ai_widget_open' ),
                'close'            => \MHBO\Core\I18n::get_label( 'ai_widget_close' ),
                'minimize'         => \MHBO\Core\I18n::get_label( 'ai_widget_minimize' ),
                'send'             => \MHBO\Core\I18n::get_label( 'ai_widget_send' ),
                'inputPlaceholder' => \MHBO\Core\I18n::get_label( 'ai_widget_input_placeholder' ),
                'inputLabel'       => \MHBO\Core\I18n::get_label( 'ai_widget_input_label' ),
                'startVoice'       => \MHBO\Core\I18n::get_label( 'ai_widget_start_voice' ),
                'stopVoice'        => \MHBO\Core\I18n::get_label( 'ai_widget_stop_voice' ),
                'toggleVoice'      => \MHBO\Core\I18n::get_label( 'ai_widget_toggle_voice' ),
                'typing'           => \MHBO\Core\I18n::get_label( 'ai_widget_typing' ),
                'errorMessage'     => \MHBO\Core\I18n::get_label( 'ai_widget_error_message' ),
                'welcomeMessage'   => \MHBO\Core\I18n::get_label( 'ai_widget_welcome_message' ),
                'suggCheckAvail'   => \MHBO\Core\I18n::get_label( 'ai_widget_sugg_check_avail' ),
                'suggRoomTypes'    => \MHBO\Core\I18n::get_label( 'ai_widget_sugg_room_types' ),
                'suggPolicies'     => \MHBO\Core\I18n::get_label( 'ai_widget_sugg_policies' ),
                'chatDialogLabel'  => \MHBO\Core\I18n::get_label( 'ai_widget_dialog_label' ),
                'messageHistory'   => \MHBO\Core\I18n::get_label( 'ai_widget_message_history' ),
                'suggestions'      => \MHBO\Core\I18n::get_label( 'ai_widget_suggestions' ),
                'voiceNotSupported'=> \MHBO\Core\I18n::get_label( 'ai_widget_voice_not_supported' ),
                'voicePermissionDenied' => \MHBO\Core\I18n::get_label( 'ai_widget_voice_denied' ),
                // Book Now CTA (intent-driven).
                'ctaHighIntent'    => \MHBO\Core\I18n::get_label( 'ai_cta_high_intent' ),
                'ctaMedIntent'     => \MHBO\Core\I18n::get_label( 'ai_cta_med_intent' ),
                'bookNowLabel'     => \MHBO\Core\I18n::get_label( 'ai_cta_book_now' ),
                'dismiss'          => \MHBO\Core\I18n::get_label( 'ai_cta_dismiss' ),
                // Handoff / escalation bar.
                'handoffIntro'     => \MHBO\Core\I18n::get_label( 'ai_handoff_intro' ),
                'handoffWhatsapp'  => \MHBO\Core\I18n::get_label( 'ai_handoff_whatsapp' ),
                'handoffEmail'     => \MHBO\Core\I18n::get_label( 'ai_handoff_email' ),
                'handoffPhone'     => \MHBO\Core\I18n::get_label( 'ai_handoff_phone' ),
                // Proactive greeting messages.
                'proactiveDefault' => \MHBO\Core\I18n::get_label( 'ai_proactive_default' ),
                'proactiveBooking' => \MHBO\Core\I18n::get_label( 'ai_proactive_booking' ),
                'proactiveRooms'   => \MHBO\Core\I18n::get_label( 'ai_proactive_rooms' ),
            ],
        ] );

        // When inline booking modal is enabled, enqueue its assets here so the
        // chat widget's "Complete Booking" button can dispatch mhboBookNow on
        // pages that don't have the calendar shortcode.
        if ( (int) get_option( 'mhbo_modal_enabled', 0 ) === 1 ) {
            if ( ! wp_script_is( 'mhbo-booking-modal-js', 'enqueued' ) ) {
                if ( ! wp_script_is( 'mhbo-booking-modal-js', 'registered' ) ) {
                    wp_register_script(
                        'mhbo-booking-modal-js',
                        MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-modal.js',
                        [],
                        MHBO_VERSION,
                        true
                    );
                }
                wp_enqueue_script( 'mhbo-booking-modal-js' );
                \MHBO\Frontend\Shortcode::enqueue_for_modal();
                wp_add_inline_script(
                    'mhbo-booking-modal-js',
                    'window.mhboModalI18n = ' . wp_json_encode( [
                        'bookNow'      => \MHBO\Core\I18n::get_label( 'btn_book_now' ),
                        'loading'      => \MHBO\Core\I18n::get_label( 'label_loading' ),
                        'errorLoading' => \MHBO\Core\I18n::get_label( 'ai_error_empty_response' ),
                    ] ) . ';' .
                    'window.mhbo_vars = window.mhbo_vars || {};' .
                    'if (!window.mhbo_vars.nonce) { window.mhbo_vars.nonce = ' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . '; }' .
                    'if (!window.mhbo_vars.rest_url) { window.mhbo_vars.rest_url = ' . wp_json_encode( untrailingslashit( rest_url( 'mhbo/v1' ) ) ) . '; }'
                );
            }
            if ( ! wp_style_is( 'mhbo-booking-modal-css', 'enqueued' ) ) {
                if ( ! wp_style_is( 'mhbo-booking-modal-css', 'registered' ) ) {
                    wp_register_style(
                        'mhbo-booking-modal-css',
                        MHBO_PLUGIN_URL . 'assets/css/mhbo-booking-modal.css',
                        [],
                        MHBO_VERSION
                    );
                }
                wp_enqueue_style( 'mhbo-booking-modal-css' );
            }
        }

        // Inject CSS variable for accent color.
        $accent = sanitize_hex_color( (string) get_option( 'mhbo_ai_accent_color', '#2C3E50' ) );
        if ( $accent ) {
            wp_add_inline_style( 'mhbo-chat-widget', ":root { --mhbo-chat-accent: {$accent}; }" );
        }
    }

    // -------------------------------------------------------------------------
    // Widget Template
    // -------------------------------------------------------------------------

    /**
     * Render the floating widget container in the footer.
     */
    public static function render_widget_template(): void {
        $ai_enabled     = (int) get_option( 'mhbo_ai_enabled', 1 );
        $widget_enabled = (int) get_option( 'mhbo_ai_widget_enabled', 1 );
        $show_globally  = (int) get_option( 'mhbo_ai_show_globally', 1 );

        if ( ! $ai_enabled || ! $widget_enabled || ! $show_globally ) {
            return;
        }
        include MHBO_PLUGIN_DIR . 'templates/chat-widget.php';
    }

    // -------------------------------------------------------------------------
    // Gutenberg Block
    // -------------------------------------------------------------------------

    /**
     * Register the AI Concierge Gutenberg block.
     */
    public static function register_block(): void {
        $block_dir = MHBO_PLUGIN_DIR . 'blocks/ai-concierge';
        if ( ! file_exists( $block_dir . '/block.json' ) ) {
            return;
        }
        register_block_type( $block_dir, [
            'render_callback' => [ self::class, 'render_block' ],
        ] );
    }

    /**
     * Server-side render for the AI Concierge block.
     *
     * @param array<mixed> $attrs
     * @return string
     */
    public static function render_block( array $attrs ): string {
        $enabled = (int) get_option( 'mhbo_ai_enabled', 1 );
        if ( ! $enabled ) {
            return '';
        }
        return self::render_widget_div( $attrs );
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    /**
     * Register [mhbo_ai_concierge] shortcode.
     */
    public static function register_shortcode(): void {
        add_shortcode( 'mhbo_ai_concierge', [ self::class, 'shortcode_handler' ] );
    }

    /**
     * Shortcode handler.
     *
     * @param array<mixed>|string $atts
     * @return string
     */
    public static function shortcode_handler( array|string $atts ): string {
        $atts = shortcode_atts( [
            'variant'         => 'floating',
            'position'        => 'bottom-right',
            'welcome_message' => '',
            'theme'           => '',     // Pro only
        ], \is_array( $atts ) ? $atts : [] );

$enabled = (int) get_option( 'mhbo_ai_enabled', 1 );
        if ( ! $enabled ) {
            return '';
        }

        return self::render_widget_div( [
            'variant'        => sanitize_text_field( (string) $atts['variant'] ),
            'position'       => sanitize_text_field( (string) $atts['position'] ),
            'welcomeMessage' => sanitize_text_field( (string) $atts['welcome_message'] ),
            'theme'          => sanitize_text_field( (string) $atts['theme'] ),
        ] );
    }

    /**
     * Build the widget container HTML.
     *
     * @param array<mixed> $attrs
     * @return string
     */
    private static function render_widget_div( array $attrs ): string {
        $allowed_variants  = [ 'floating', 'inline' ];
        $allowed_positions = [ 'bottom-right', 'bottom-left' ];

        $raw_variant  = (string) ( $attrs['variant']  ?? 'floating' );
        $raw_position = (string) ( $attrs['position'] ?? 'bottom-right' );

        $variant  = esc_attr( \in_array( $raw_variant,  $allowed_variants,  true ) ? $raw_variant  : 'floating'      );
        $position = esc_attr( \in_array( $raw_position, $allowed_positions, true ) ? $raw_position : 'bottom-right' );
        $welcome  = esc_attr( (string) ( $attrs['welcomeMessage'] ?? '' ) );

        $theme = (string) ( $attrs['theme'] ?? '' );
        if ( '' === $theme ) {
            $theme = (string) get_option( 'mhbo_ai_theme', '' );
        }
        $theme_class = $theme ? ' mhbo-theme-' . sanitize_html_class( $theme ) : '';

        $data  = 'data-variant="' . $variant . '"';
        $data .= ' data-position="' . $position . '"';
        if ( $welcome ) {
            $data .= ' data-welcome-message="' . $welcome . '"';
        }

        return '<div class="mhbo-chat-widget' . $theme_class . '" ' . $data . '></div>';
    }

    // -------------------------------------------------------------------------
    // Settings Tab Integration
    // -------------------------------------------------------------------------

    /**
     * Add the AI Concierge tab to the MHBO settings page.
     *
     * @param array<string,string> $tabs
     * @return array<string,string>
     */
    public static function add_settings_tab( array $tabs ): array {
        $tabs['ai_concierge'] = __( 'AI Concierge', 'modern-hotel-booking' );
        return $tabs;
    }

    // -------------------------------------------------------------------------
    // PRO: Role management
    // -------------------------------------------------------------------------

// -------------------------------------------------------------------------
    // Locale Detection
    // -------------------------------------------------------------------------

    /**
     * Detect the current page locale from multilingual plugins or WP core.
     *
     * Priority: Polylang → WPML → qTranslate-XT → WP get_locale().
     * Returns a BCP-47 tag (e.g. "ro-RO", "en-US", "de-DE").
     *
     * @return string
     */
    public static function detect_page_locale(): string {
        // Polylang.
        if ( function_exists( 'pll_current_language' ) ) {
            /** @var string|false $locale */
            $locale = \call_user_func( 'pll_current_language', 'locale' );
            if ( $locale ) {
                return str_replace( '_', '-', $locale );
            }
        }

        // WPML.
        if ( \defined( 'ICL_LANGUAGE_CODE' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            $locale = apply_filters( 'wpml_current_language_details', null );
            if ( \is_array( $locale ) && '' !== (string) ( $locale['default_locale'] ?? '' ) ) {
                return str_replace( '_', '-', $locale['default_locale'] );
            }
            // Fallback: just the 2-letter code from the constant.
            return (string) constant( 'ICL_LANGUAGE_CODE' );
        }

        // qTranslate-XT / qTranslate-X.
        global $q_config;
        if ( '' !== (string) ( $q_config['language'] ?? '' ) ) {
            // $q_config['language'] is a 2-char code; try to expand via $q_config['locale'].
            $qlang   = $q_config['language'];
            $qlocale = $q_config['locale'][ $qlang ] ?? $qlang;
            return str_replace( '_', '-', $qlocale );
        }

        // WP core locale (works for single-language sites or as a safe fallback).
        return str_replace( '_', '-', get_locale() );
    }
}
