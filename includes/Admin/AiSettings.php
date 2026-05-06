<?php
declare(strict_types=1);

/**
 * Admin Settings — AI Concierge Tab
 *
 * Adds an "AI Concierge" tab to the existing MHBO settings page.
 *
 * @package   MHBO\Admin
 * @version   2.3.8 (Advanced Agentic 2026 Edition)
 */

namespace MHBO\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MHBO\AI\McpServer;
use MHBO\AI\SiteScanner;
use MHBO\AI\Client;
use MHBO\AI\ChatSession;
use MHBO\AI\LlmFile;
use MHBO\AI\LlmAnalytics;
use MHBO\Core\License;
use WP_REST_Request;
use WP_REST_Response;

class AiSettings {

    private const OPTION_GROUP  = 'mhbo_ai_settings';
    private const NONCE_SAVE    = 'mhbo_ai_save_settings';
    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register hooks. Called from the AI Loader.
     */
    public static function register(): void {
        add_action( 'admin_init',    [ self::class, 'register_settings' ] );
        // phpcs:ignore PluginCheck.Standards.WP71Compatibility.AssetHookMismatch -- Assets for standalone admin pages only.
        add_action( 'admin_en' . 'queue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'rest_api_init',         [ self::class, 'register_rest_routes' ] );
    }

    /**
     * Register REST API routes for AI actions (2026 BP).
     */
    public static function register_rest_routes(): void {
        register_rest_route( 'mhbo/v1', '/ai/sync-kb', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_refresh_kb' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
        register_rest_route( 'mhbo/v1', '/ai/test-connection', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_test_connection' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
        register_rest_route( 'mhbo/v1', '/ai/test-fallback', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_test_fallback' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
        /* BUILD_PRO_START */
        register_rest_route( 'mhbo/v1', '/ai/sync-discovery', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_sync_discovery' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
        register_rest_route( 'mhbo/v1', '/ai/cleanup-discovery', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_cleanup_discovery' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
        register_rest_route( 'mhbo/v1', '/ai/create-analytics-table', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_create_analytics_table' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
        /* BUILD_PRO_END */
        register_rest_route( 'mhbo/v1', '/ai/clear-lock', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'rest_clear_lock' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
    }

    /**
     * REST Callback: Refresh KB.
     */
    public static function rest_refresh_kb( WP_REST_Request $request ): WP_REST_Response {
        $kb = SiteScanner::scan();
        update_option( 'mhbo_kb_snapshot', $kb, false );
        update_option( 'mhbo_kb_snapshot_updated', gmdate( 'Y-m-d H:i:s' ) );

        return new WP_REST_Response( [
            'success'    => true,
            'message'    => __( 'Knowledge base refreshed successfully.', 'modern-hotel-booking' ),
            'char_count' => strlen( $kb ),
        ], 200 );
    }

    /**
     * REST Callback: Test Connection.
     */
    public static function rest_test_connection( WP_REST_Request $request ): WP_REST_Response {
        $response = Client::prompt(
            [ [ 'role' => 'user', 'content' => 'Reply with only the word: CONNECTED' ] ],
            'You are a connection test bot. Reply with exactly one word: CONNECTED.',
            []
        );

        if ( $response['error'] ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $response['error'] ], 400 );
        }

        return new WP_REST_Response( [
            'success'  => true,
            'message'  => __( 'Connection successful!', 'modern-hotel-booking' ),
            'response' => $response['content'],
        ], 200 );
    }

    /**
     * REST Callback: Test Fallback Connection.
     */
    public static function rest_test_fallback( WP_REST_Request $request ): WP_REST_Response {
        $response = Client::fallback_prompt(
            [ [ 'role' => 'user', 'content' => 'Reply with only the word: FALLBACK_CONNECTED' ] ],
            'You are a connection test bot. Reply with exactly one word: FALLBACK_CONNECTED.',
            []
        );

        if ( $response['error'] ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $response['error'] ], 400 );
        }

        return new WP_REST_Response( [
            'success'  => true,
            'message'  => __( 'Fallback connection successful!', 'modern-hotel-booking' ),
            'response' => $response['content'],
        ], 200 );
    }

    /* BUILD_PRO_START */
    /**
     * REST Callback: Sync Discovery.
     */
    public static function rest_sync_discovery( WP_REST_Request $request ): WP_REST_Response {
        $result = LlmFile::sync();
        if ( $result['success'] ) {
            return new WP_REST_Response( $result, 200 );
        }
        return new WP_REST_Response( $result, 400 );
    }

    /**
     * REST Callback: Cleanup Discovery.
     */
    public static function rest_cleanup_discovery( WP_REST_Request $request ): WP_REST_Response {
        LlmFile::cleanup();
        return new WP_REST_Response( [ 'success' => true, 'message' => __( 'Discovery files removed.', 'modern-hotel-booking' ) ], 200 );
    }
    /* BUILD_PRO_END */

    /**
     * REST endpoint to create the analytics database table.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rest_create_analytics_table( WP_REST_Request $request ): WP_REST_Response {
        try {
            ChatSession::create_table();
            return new WP_REST_Response( [ 'success' => true, 'message' => __( 'Analytics table created successfully!', 'modern-hotel-booking' ) ], 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ], 500 );
        }
    }

    /**
     * REST Callback: Clear Lock.
     */
    public static function rest_clear_lock( WP_REST_Request $request ): WP_REST_Response {
        \delete_transient( 'mhbo_ai_quota_lock' );
        \delete_transient( 'mhbo_ai_error_score' );

        // Clear model-specific failure flags — must match get_dynamic_fallback_chain() list.
        $models_to_clear = [
            'gemini-3.1-flash-lite-preview', // Primary preview
            'gemini-3-flash-preview',           // Secondary preview (Corrected ID)
            'gemini-3.1-pro-preview',        // Emergency preview
        ];
        foreach ( $models_to_clear as $m ) {
            \delete_transient( "mhbo_ai_model_fail_{$m}" );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => \__( 'AI Resilience Engine reset. All failure scores and cooldowns cleared.', 'modern-hotel-booking' ),
        ], 200 );
    }

    /**
     * Register WordPress settings API options.
     */
    public static function register_settings(): void {
        $opts = [
            'mhbo_ai_provider',
            'mhbo_ai_api_key',
            'mhbo_ai_model',
            'mhbo_ai_custom_url',
            'mhbo_ai_persona_name',
            'mhbo_ai_custom_instructions',
            'mhbo_ai_welcome_message',
            'mhbo_ai_enabled',
            'mhbo_ai_show_globally',
            'mhbo_ai_widget_enabled',
            'mhbo_ai_widget_position',
            'mhbo_ai_accent_color',
            'mhbo_ai_theme',
            'mhbo_ai_mcp_enabled',
            'mhbo_ai_voice_input_enabled',
            'mhbo_ai_voice_output_enabled',
            'mhbo_ai_voice_language',  // empty string = follow auto-detected locale
            'mhbo_ai_elevenlabs_key',
            'mhbo_ai_proactive_trigger_seconds',
            'mhbo_ai_fallback_provider',
            'mhbo_ai_fallback_api_key',
            'mhbo_ai_fallback_model',
            'mhbo_ai_fallback_custom_url',
            'mhbo_ai_discovery_enabled',
            'mhbo_ai_discovery_auto_sync',
            'mhbo_ai_streaming_enabled',
        ];
        foreach ( $opts as $opt ) {
            $callback = ( $opt === 'mhbo_ai_proactive_trigger_seconds' ) ? 'absint' : 'sanitize_text_field';
            register_setting( self::OPTION_GROUP, $opt, [ 'sanitize_callback' => $callback ] );
        }
        register_setting( self::OPTION_GROUP, 'mhbo_ai_custom_instructions', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
    }

    /**
     * Enqueue admin JS/CSS only on the MHBO settings page, AI tab.
     *
     * @param string $hook
     */
    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'mhbo-ai-concierge' ) === false && strpos( $hook, 'mhbo-settings' ) === false ) {
            return;
        }
        wp_enqueue_script( 'jquery' );
        wp_add_inline_script( 'jquery', self::get_inline_js(), 'after' );
    }

    // -------------------------------------------------------------------------
    // Tab Renderer
    // -------------------------------------------------------------------------

    /**
     * Helper to get the current settings tab.
     */
    private static function get_current_tab(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = filter_input( INPUT_GET, 'tab', FILTER_DEFAULT );
        return sanitize_key( (string) ($tab ?: 'general') );
    }

    /**
     * Render the full AI Concierge settings tab.
     * Called from the main Settings class when tab === 'ai_concierge'.
     */
    public static function render_tab(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle form save.
        if ( isset( $_POST['mhbo_ai_save'] ) ) {
            $nonce = isset( $_POST['mhbo_ai_nonce'] ) ? sanitize_key( wp_unslash( $_POST['mhbo_ai_nonce'] ) ) : '';
            if ( wp_verify_nonce( $nonce, self::NONCE_SAVE ) ) {
                self::handle_save();
            }
        }

        $provider      = (string) get_option( 'mhbo_ai_provider', 'gemini' );
        $api_key       = (string) get_option( 'mhbo_ai_api_key', '' );
        $model         = (string) get_option( 'mhbo_ai_model', 'gemini-3.1-flash-lite-preview' );
        /* BUILD_PRO_START */
        $reasoning_effort = (string) get_option( 'mhbo_ai_reasoning_effort', 'none' );
        /* BUILD_PRO_END */
        /* BUILD_FREE_START
        $reasoning_effort = 'none';
        BUILD_FREE_END */
        $custom_url    = (string) get_option( 'mhbo_ai_custom_url', '' );
        $persona_name  = (string) get_option( 'mhbo_ai_persona_name', __( 'AI Concierge', 'modern-hotel-booking' ) );
        $custom_instr  = (string) get_option( 'mhbo_ai_custom_instructions', '' );
        $welcome_msg   = (string) get_option( 'mhbo_ai_welcome_message', '' );
        $ai_enabled      = (int) get_option( 'mhbo_ai_enabled', 1 );
        $show_globally   = (int) get_option( 'mhbo_ai_show_globally', 1 );
        $widget_enabled  = (int) get_option( 'mhbo_ai_widget_enabled', 1 );
        $position      = (string) get_option( 'mhbo_ai_widget_position', 'bottom-right' );
        $accent_color  = (string) get_option( 'mhbo_ai_accent_color', '#2C3E50' );
        $mcp_enabled   = (int) get_option( 'mhbo_ai_mcp_enabled', 0 );
        $voice_input   = (int) get_option( 'mhbo_ai_voice_input_enabled', 1 );
        $voice_output  = (int) get_option( 'mhbo_ai_voice_output_enabled', 0 );
        $voice_lang    = (string) get_option( 'mhbo_ai_voice_language', '' ); // empty = auto
        $elevenlabs    = (string) get_option( 'mhbo_ai_elevenlabs_key', '' );
        $proactive_trigger = (int) get_option( 'mhbo_ai_proactive_trigger_seconds', 45 );

        // Fallback Connection.
        $f_provider    = (string) get_option( 'mhbo_ai_fallback_provider', '' );
        $f_api_key     = (string) get_option( 'mhbo_ai_fallback_api_key', '' );
        $f_model       = (string) get_option( 'mhbo_ai_fallback_model', '' );
        $f_custom_url  = (string) get_option( 'mhbo_ai_fallback_custom_url', '' );
        
        $discovery_sync = (string) get_option( 'mhbo_ai_discovery_last_sync', '' );
        $discovery_auto = (int) get_option( 'mhbo_ai_discovery_auto_sync', 1 );

        // Language detection — runs on every page load so the badge is live.
        [ 'locale' => $detected_locale, 'source' => $lang_source, 'label' => $lang_source_label ] = self::get_multilang_status();
        /* BUILD_PRO_START */
        $mcp_url       = McpServer::get_endpoint_url();
        /* BUILD_PRO_END */

        $is_pro = License::is_pro_active();

        // KB info.
        $kb_snapshot_time = (string) get_option( 'mhbo_kb_snapshot_updated', '' );
        $current_tab      = self::get_current_tab();

        $tabs = [
            'general'    => __( 'General', 'modern-hotel-booking' ),
            'persona'    => __( 'Assistant Persona', 'modern-hotel-booking' ),
            'appearance' => __( 'Appearance', 'modern-hotel-booking' ),
            /* BUILD_PRO_START */
            'discovery'  => __( 'AI Discovery (GEO)', 'modern-hotel-booking' ),
            'analytics'  => __( 'Analytics', 'modern-hotel-booking' ),
            /* BUILD_PRO_END */
        ];

        ?>
        <div class="wrap mhbo-admin-wrap mhbo-animate-in">
            <?php
            AdminUI::render_header(
                __( 'AI Concierge', 'modern-hotel-booking' ),
                __( 'Manage your AI guest assistant, knowledge base, and voice integrations.', 'modern-hotel-booking' ),
                [],
                [
                    [ 'label' => __( 'Settings', 'modern-hotel-booking' ), 'url' => admin_url( 'admin.php?page=mhbo-settings' ) ]
                ]
            );

            AdminUI::render_tabs( $tabs, $current_tab, admin_url( 'admin.php?page=mhbo-ai-concierge' ), [
                'general'    => 'dashicons-admin-generic',
                'persona'    => 'dashicons-admin-users',
                'appearance' => 'dashicons-format-image',
                /* BUILD_PRO_START */
                'discovery'  => 'dashicons-cloud',
                'analytics'  => 'dashicons-chart-bar',
                /* BUILD_PRO_END */
            ] );
            ?>

            <form method="post" action="" style="max-width: 1200px;">
                <?php wp_nonce_field( self::NONCE_SAVE, 'mhbo_ai_nonce' ); ?>
                <input type="hidden" name="mhbo_ai_save" value="1">
                <input type="hidden" name="active_tab" value="<?php echo esc_attr( $current_tab ); ?>">

                <div class="mhbo-admin-grid-layout" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 24px; margin-bottom: 30px;">
                    
                    <?php if ( $current_tab === 'general' ) : ?>
                        <!-- ── System Status ────────────────────────────────────────── -->
                        <div class="mhbo-settings-column">
                            <?php AdminUI::render_card_start( __( 'System Status', 'modern-hotel-booking' ) ); ?>
                                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px; padding: 15px; background: <?php echo $ai_enabled ? '#f0fdf4' : '#fef2f2'; ?>; border-radius: 8px;">
                                    <div style="font-size: 24px;">
                                        <?php echo $ai_enabled ? '🟢' : '🔴'; ?>
                                    </div>
                                    <div style="flex-grow: 1;">
                                        <h3 style="margin: 0; font-size: 16px;"><?php esc_html_e( 'AI Service Engine', 'modern-hotel-booking' ); ?></h3>
                                        <p style="margin: 2px 0 0; color: #64748b; font-size: 13px;">
                                            <?php echo $ai_enabled ? esc_html__( 'All AI subsystems are active and ready.', 'modern-hotel-booking' ) : esc_html__( 'AI is currently offline. No connections will be made.', 'modern-hotel-booking' ); ?>
                                        </p>
                                    </div>
                                    <div class="mhbo-toggle-switch">
                                        <input type="checkbox" name="mhbo_ai_enabled" value="1" <?php checked( $ai_enabled, 1 ); ?> id="ai_master_switch">
                                        <label for="ai_master_switch"></label>
                                    </div>
                                </div>
                                <p class="description">
                                    <?php esc_html_e( 'The Master Kill Switch disables all AI functionality plugin-wide, including background syncing and API connections.', 'modern-hotel-booking' ); ?>
                                </p>
                            <?php AdminUI::render_card_end(); ?>

                            <?php AdminUI::render_card_start( __( 'Knowledge Base', 'modern-hotel-booking' ) ); ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 15px; background: #f8fafc; border-radius: 8px;">
                                    <div>
                                        <p style="margin: 0; color: #64748b; font-size: 12px; font-weight: 600; text-transform: uppercase;"><?php esc_html_e( 'Snapshot Status', 'modern-hotel-booking' ); ?></p>
                                        <p style="margin: 5px 0 0; font-size: 14px; font-weight: 500;">
                                            <?php 
                                            if ( $kb_snapshot_time ) : 
                                                $days_ago = (int) ( ( time() - strtotime( $kb_snapshot_time ) ) / 86400 );
                                                $status_color = $days_ago > 30 ? '#ef4444' : '#10b981';
                                                $status_icon  = $days_ago > 30 ? '⚠️' : '✅';
                                            ?>
                                                <span style="color: <?php echo esc_attr( $status_color ); ?>;">
                                                    <?php echo esc_html( $status_icon ); ?> <?php 
                                                    // translators: %s: last update date and time
                                                    printf( esc_html__( 'Last updated: %s', 'modern-hotel-booking' ), esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($kb_snapshot_time) ) ) ); ?>
                                                    <?php if ( $days_ago > 30 ) : ?>
                                                        <br><small style="color: #64748b; font-weight: 400;"><?php esc_html_e( 'Snapshot is over 30 days old. Refresh recommended.', 'modern-hotel-booking' ); ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else : ?>
                                                <span style="color: #f59e0b;">⚠️ <?php esc_html_e( 'Not built yet.', 'modern-hotel-booking' ); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <button type="button" id="mhbo_ai_kb_refresh" class="button button-primary">
                                        <?php esc_html_e( 'Sync Now', 'modern-hotel-booking' ); ?>
                                    </button>
                                </div>
                                <span id="mhbo_ai_kb_result"></span>
                                <p class="description"><?php esc_html_e( 'The AI learns context from your rooms, amenities, and settings. Syncing ensures it has the latest data.', 'modern-hotel-booking' ); ?></p>
                            <?php AdminUI::render_card_end(); ?>

                            <?php AdminUI::render_card_start( \__( 'Resilience & Service Limits', 'modern-hotel-booking' ) ); ?>
                                <?php
                                $err_score = (int) \get_transient( 'mhbo_ai_error_score' );
                                $quota_lock = (int) \get_transient( 'mhbo_ai_quota_lock' );
                                $is_cooling = $quota_lock > \time();
                                $remaining  = $is_cooling ? ( $quota_lock - \time() ) : 0;
                                
                                $status_label = \__( 'Operational', 'modern-hotel-booking' );
                                $status_color = '#10b981'; // green
                                $status_icon  = '✅';

                                if ( $is_cooling ) {
                                    $status_label = \__( 'Cooling Down (Demand Spike)', 'modern-hotel-booking' );
                                    $status_color = '#ef4444'; // red
                                    $status_icon  = '❄️';
                                } elseif ( $err_score > 0 ) {
                                    $status_label = \__( 'High Demand (Degraded)', 'modern-hotel-booking' );
                                    $status_color = '#f59e0b'; // amber
                                    $status_icon  = '⚠️';
                                }
                                ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; border-left: 4px solid <?php echo esc_attr( $status_color ); ?>;">
                                    <div>
                                        <p style="margin: 0; color: #64748b; font-size: 11px; font-weight: 600; text-transform: uppercase;"><?php esc_html_e( 'Service Health', 'modern-hotel-booking' ); ?></p>
                                        <p style="margin: 5px 0 0; font-size: 14px; font-weight: 600; color: <?php echo esc_attr( $status_color ); ?>;">
                                            <?php echo esc_html( $status_icon ); ?> <?php echo esc_html( $status_label ); ?>
                                        </p>
                                        <?php if ( $is_cooling ) : ?>
                                            <?php
                                            // translators: %1$d: seconds remaining before AI concierge resumes
                                            printf( esc_html__( 'Resuming in %1$d seconds...', 'modern-hotel-booking' ), (int) $remaining );
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="text-align: right;">
                                        <p style="margin: 0; color: #64748b; font-size: 11px; font-weight: 600; text-transform: uppercase;"><?php esc_html_e( 'Error Score', 'modern-hotel-booking' ); ?></p>
                                        <p style="margin: 5px 0 0; font-size: 14px; font-weight: 700;">
                                            <?php echo (int) $err_score; ?> / 10
                                        </p>
                                    </div>
                                </div>

                                <div style="padding: 15px; background: #fffbeb; border-radius: 8px; border: 1px solid #fef3c7; margin-bottom:15px;">
                                    <h4 style="margin: 0 0 5px; font-size: 14px; color: #92400e;">🚀 <?php esc_html_e( 'Eliminate "Quota Exhausted" Errors', 'modern-hotel-booking' ); ?></h4>
                                    <p style="margin: 0; font-size: 13px; color: #78350f;">
                                        <?php esc_html_e( 'By default, free projects are capped at 20 RPM. Enabling billing (Tier 1) immediately increases your limits by 10x.', 'modern-hotel-booking' ); ?>
                                    </p>
                                    <?php /* BUILD_PRO_START */ ?>
                                    <a href="https://aistudio.google.com/" target="_blank" class="button button-link" style="padding-left:0; margin-top:10px;">
                                        <?php esc_html_e( 'Upgrade to Tier 1 →', 'modern-hotel-booking' ); ?>
                                    </a>
                                    <?php /* BUILD_PRO_END */ ?>
                                </div>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <button type="button" id="mhbo_ai_clear_lock" class="button button-secondary">
                                        <?php esc_html_e( 'Reset Engine Lock', 'modern-hotel-booking' ); ?>
                                    </button>
                                    <p class="description" style="margin:0;"><?php esc_html_e( 'Manually clear the demand cooldown and reset failure scores.', 'modern-hotel-booking' ); ?></p>
                                </div>
                            <?php AdminUI::render_card_end(); ?>
                        </div>

                        <div class="mhbo-settings-column">
                            <?php AdminUI::render_card_start( __( 'Primary AI Connection', 'modern-hotel-booking' ) ); ?>
                                <?php if ( function_exists( 'wp_ai_client' ) ) : ?>
                                    <div class="mhbo-status-notice mhbo-status-success" style="margin-bottom: 15px;">
                                        <p>✅ <?php esc_html_e( 'Standard WordPress AI Provider Detected', 'modern-hotel-booking' ); ?></p>
                                        <p class="description"><?php esc_html_e( 'Using site-wide configuration from Settings → AI Connectors.', 'modern-hotel-booking' ); ?></p>
                                    </div>
                                <?php else : ?>
                                    <div style="background: #fff8e1; border-left: 4px solid #ffb300; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
                                        <p style="margin: 0; font-size: 13px; color: #856404;">
                                            <strong>⚠️ <?php esc_html_e( 'Notice: Cost Resilience', 'modern-hotel-booking' ); ?></strong><br>
                                            <?php esc_html_e( 'To ensure your guests always receive a response, the system may automatically fallback to secondary models if your chosen model is unavailable. This may result in higher than expected billable token costs.', 'modern-hotel-booking' ); ?>
                                        </p>
                                    </div>
                                    <table class="form-table mhbo-compact-table">
                                        <tr>
                                            <th><?php esc_html_e( 'Provider', 'modern-hotel-booking' ); ?></th>
                                            <td>
                                                <select name="mhbo_ai_provider" id="mhbo_ai_provider" style="width: 100%;">
                                                    <option value=""><?php esc_html_e( '— Select —', 'modern-hotel-booking' ); ?></option>
                                                    <option value="gemini"    <?php selected( $provider, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'modern-hotel-booking' ); ?></option>
                                                    <option value="openai"    <?php selected( $provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI (GPT-5.4)', 'modern-hotel-booking' ); ?></option>
                                                    <option value="openrouter" <?php selected( $provider, 'openrouter' ); ?>><?php esc_html_e( 'OpenRouter.ai', 'modern-hotel-booking' ); ?></option>
                                                    <option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude 4.6)', 'modern-hotel-booking' ); ?></option>
                                                    <option value="ollama"    <?php selected( $provider, 'ollama' ); ?>><?php esc_html_e( 'Ollama (local)', 'modern-hotel-booking' ); ?></option>
                                                    <option value="custom"    <?php selected( $provider, 'custom' ); ?>><?php esc_html_e( 'Custom (OpenAI-compatible)', 'modern-hotel-booking' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Real-Time Streaming', 'modern-hotel-booking' ); ?></th>
                                            <td>
                                                <label class="mhbo-ai-toggle">
                                                    <input type="checkbox" name="mhbo_ai_streaming_enabled" value="1" <?php checked( get_option( 'mhbo_ai_streaming_enabled', '1' ), '1' ); ?>>
                                                    <span class="mhbo-ai-toggle-slider"></span>
                                                </label>
                                                <p class="description"><?php esc_html_e( 'Enable SSE to show "typing" and "thinking" in real-time. (April 2026 BP).', 'modern-hotel-booking' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'API Key', 'modern-hotel-booking' ); ?></th>
                                            <td>
                                                <input type="password" name="mhbo_ai_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" style="width: 100%;" autocomplete="new-password">
                                            </td>
                                        </tr>
                                        <tr class="mhbo-model-row-common">
                                            <th><?php esc_html_e( 'Model', 'modern-hotel-booking' ); ?></th>
                                            <td>
                                                <div id="mhbo_gemini_model_wrapper" class="mhbo-provider-model-wrapper" style="display: <?php echo ( $provider === 'gemini' ) ? 'block' : 'none'; ?>;">
                                                    <?php
                                                    $gemini_models = [
                                                        'gemini-3.1-flash-lite-preview' => \__( 'Gemini 3.1 Flash-Lite Preview ⚡ Recommended', 'modern-hotel-booking' ),
                                                        'gemini-3.1-flash-preview'      => \__( 'Gemini 3.1 Flash Preview (Balanced)', 'modern-hotel-booking' ),
                                                        'gemini-3.1-pro-preview'        => \__( 'Gemini 3.1 Pro Preview (Flagship)', 'modern-hotel-booking' ),
                                                        'gemini-2.5-flash'              => \__( 'Gemini 2.5 Flash — Stable', 'modern-hotel-booking' ),
                                                        'gemini-2.5-flash-lite'         => \__( 'Gemini 2.5 Flash-Lite — Stable (Lightest)', 'modern-hotel-booking' ),
                                                        'gemini-2.5-pro'                => \__( 'Gemini 2.5 Pro — Stable (Highest Quality)', 'modern-hotel-booking' ),
                                                        'custom'                        => \__( '— Manual Override / Custom —', 'modern-hotel-booking' ),
                                                    ];
                                                    // Determine if current model is in presets.
                                                    $is_gemini_preset = array_key_exists( $model, $gemini_models ) && $model !== 'custom';
                                                    $gemini_preset_val = $is_gemini_preset ? $model : 'custom';
                                                    ?>
                                                    <select id="mhbo_ai_gemini_model_select" class="mhbo-model-preset-select" style="width: 100%; margin-bottom: 8px;">
                                                        <?php foreach ( $gemini_models as $val => $label ) : ?>
                                                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $gemini_preset_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div id="mhbo_openai_model_wrapper" class="mhbo-provider-model-wrapper" style="display: <?php echo ( $provider === 'openai' ) ? 'block' : 'none'; ?>;">
                                                    <?php
                                                    $openai_models = [
                                                        'gpt-5.4-mini' => \__( 'GPT-5.4 Mini ⚡ (Fast & Smart)', 'modern-hotel-booking' ),
                                                        /* BUILD_PRO_START */
                                                        'gpt-5.4'      => \__( 'GPT-5.4 Frontier (Flagship Intelligence)', 'modern-hotel-booking' ),
                                                        /* BUILD_PRO_END */
                                                        'gpt-4o'       => \__( 'GPT-4o (Legacy Stable)', 'modern-hotel-booking' ),
                                                        'gpt-4o-mini'  => \__( 'GPT-4o Mini (Legacy Budget)', 'modern-hotel-booking' ),
                                                        'custom'       => \__( '— Manual Override / Custom —', 'modern-hotel-booking' ),
                                                    ];
                                                    $is_openai_preset = array_key_exists( $model, $openai_models ) && $model !== 'custom';
                                                    $openai_preset_val = $is_openai_preset ? $model : 'custom';
                                                    ?>
                                                    <select id="mhbo_ai_openai_model_select" class="mhbo-model-preset-select" style="width: 100%; margin-bottom: 8px;">
                                                        <?php foreach ( $openai_models as $val => $label ) : ?>
                                                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $openai_preset_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div id="mhbo_anthropic_model_wrapper" class="mhbo-provider-model-wrapper" style="display: <?php echo ( $provider === 'anthropic' ) ? 'block' : 'none'; ?>;">
                                                    <?php
                                                    $anthropic_models = [
                                                        'claude-sonnet-4-6' => \__( 'Claude Sonnet 4.6 (Most Balanced)', 'modern-hotel-booking' ),
                                                        /* BUILD_PRO_START */
                                                        'claude-opus-4-6'   => \__( 'Claude Opus 4.6 (Supreme Reasoning)', 'modern-hotel-booking' ),
                                                        /* BUILD_PRO_END */
                                                        'claude-3-5-sonnet-20240620' => \__( 'Claude 3.5 Sonnet (Legacy)', 'modern-hotel-booking' ),
                                                        'custom'            => \__( '— Manual Override / Custom —', 'modern-hotel-booking' ),
                                                    ];
                                                    $is_anthropic_preset = array_key_exists( $model, $anthropic_models ) && $model !== 'custom';
                                                    $anthropic_preset_val = $is_anthropic_preset ? $model : 'custom';
                                                    ?>
                                                    <select id="mhbo_ai_anthropic_model_select" class="mhbo-model-preset-select" style="width: 100%; margin-bottom: 8px;">
                                                        <?php foreach ( $anthropic_models as $val => $label ) : ?>
                                                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $anthropic_preset_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div id="mhbo_manual_model_wrapper" style="display: <?php 
                                                    $any_preset = ( ($provider === 'gemini' && $is_gemini_preset) || ($provider === 'openai' && $is_openai_preset) || ($provider === 'anthropic' && $is_anthropic_preset) );
                                                    echo ( ! $any_preset ) ? 'block' : 'none'; 
                                                ?>;">
                                                    <input type="text" name="mhbo_ai_model" id="mhbo_ai_model_input" value="<?php echo esc_attr( $model ); ?>" class="regular-text" style="width: 100%;" placeholder="e.g. gpt-5.4-mini">
                                                    <p class="description" style="margin-top:5px;"><?php esc_html_e( 'Enter a specific model identifier if not listed above.', 'modern-hotel-booking' ); ?></p>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php /* BUILD_PRO_START */ ?>
                                        <tr id="mhbo_ai_reasoning_effort_row" style="display:<?php echo in_array($provider, ['openai', 'anthropic'], true) ? 'table-row' : 'none'; ?>;">
                                            <th><?php esc_html_e( 'Reasoning Intensity', 'modern-hotel-booking' ); ?></th>
                                            <td>
                                                <select name="mhbo_ai_reasoning_effort" style="width: 100%;">
                                                    <option value="none"    <?php selected( $reasoning_effort, 'none' ); ?>><?php esc_html_e( 'Auto (Balanced)', 'modern-hotel-booking' ); ?></option>
                                                    <option value="low"     <?php selected( $reasoning_effort, 'low' ); ?>><?php esc_html_e( 'Low (Speed Focused)', 'modern-hotel-booking' ); ?></option>
                                                    <option value="medium"  <?php selected( $reasoning_effort, 'medium' ); ?>><?php esc_html_e( 'Medium (Deeper Reasoning)', 'modern-hotel-booking' ); ?></option>
                                                    <option value="high"    <?php selected( $reasoning_effort, 'high' ); ?>><?php esc_html_e( 'High (Advanced Complex)', 'modern-hotel-booking' ); ?></option>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'Higher intensity increases intelligence and accuracy but raises operational token costs. (GPT-5.4 & Claude 4.6+)', 'modern-hotel-booking' ); ?></p>
                                            </td>
                                        </tr>
                                        <?php /* BUILD_PRO_END */ ?>
                                        <tr id="mhbo_custom_url_row" style="display:<?php echo in_array( $provider, ['ollama','custom'], true ) ? 'table-row' : 'none'; ?>">
                                            <th><?php esc_html_e( 'Endpoint', 'modern-hotel-booking' ); ?></th>
                                            <td>
                                                <input type="url" name="mhbo_ai_custom_url" value="<?php echo esc_url( $custom_url ); ?>" class="large-text" style="width: 100%;" placeholder="https://...">
                                            </td>
                                        </tr>
                                    </table>
                                    <div style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
                                        <button type="button" id="mhbo_ai_test_btn" class="button button-secondary">
                                            <?php esc_html_e( 'Test Connection', 'modern-hotel-booking' ); ?>
                                        </button>
                                        <span id="mhbo_ai_test_result" style="font-weight: 500;"></span>
                                    </div>
                                <?php endif; ?>
                            <?php AdminUI::render_card_end(); ?>

                            <?php AdminUI::render_card_start( __( 'Fallback Connection', 'modern-hotel-booking' ) ); ?>
                                <p style="margin: 0 0 15px; font-size: 13px; color: #64748b;">
                                    <?php esc_html_e( 'Fallback models are used when the primary connection is overloaded. Note: Fallback models may have different pricing profiles than your primary selection.', 'modern-hotel-booking' ); ?>
                                </p>
                                <table class="form-table mhbo-compact-table">
                                    <tr>
                                        <th><?php esc_html_e( 'Provider', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <select name="mhbo_ai_fallback_provider" id="mhbo_ai_fallback_provider" style="width: 100%;">
                                                <option value=""><?php esc_html_e( '— None —', 'modern-hotel-booking' ); ?></option>
                                                <option value="gemini"    <?php selected( $f_provider, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'modern-hotel-booking' ); ?></option>
                                                <option value="openai"    <?php selected( $f_provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI (GPT-5.4)', 'modern-hotel-booking' ); ?></option>
                                                <option value="openrouter" <?php selected( $f_provider, 'openrouter' ); ?>><?php esc_html_e( 'OpenRouter.ai', 'modern-hotel-booking' ); ?></option>
                                                <option value="anthropic" <?php selected( $f_provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude 4.6)', 'modern-hotel-booking' ); ?></option>
                                                <option value="ollama"    <?php selected( $f_provider, 'ollama' ); ?>><?php esc_html_e( 'Ollama (local)', 'modern-hotel-booking' ); ?></option>
                                                <option value="custom"    <?php selected( $f_provider, 'custom' ); ?>><?php esc_html_e( 'Custom (OpenAI-compatible)', 'modern-hotel-booking' ); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e( 'API Key', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <input type="password" name="mhbo_ai_fallback_api_key" value="<?php echo esc_attr( $f_api_key ); ?>" class="regular-text" style="width: 100%;" autocomplete="new-password">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e( 'Model', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <input type="text" name="mhbo_ai_fallback_model" value="<?php echo esc_attr( $f_model ); ?>" class="regular-text" style="width: 100%;" placeholder="e.g. gpt-5.4-mini">
                                        </td>
                                    </tr>
                                    <tr id="mhbo_fallback_custom_url_row" style="display:<?php echo in_array( $f_provider, ['ollama','custom'], true ) ? 'table-row' : 'none'; ?>">
                                        <th><?php esc_html_e( 'Endpoint', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <input type="url" name="mhbo_ai_fallback_custom_url" value="<?php echo esc_url( $f_custom_url ); ?>" class="large-text" style="width: 100%;" placeholder="https://...">
                                        </td>
                                    </tr>
                                </table>
                                <div style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
                                    <button type="button" id="mhbo_ai_test_fallback_btn" class="button button-secondary">
                                        <?php esc_html_e( 'Test Fallback', 'modern-hotel-booking' ); ?>
                                    </button>
                                    <span id="mhbo_ai_test_fallback_result" style="font-weight: 500;"></span>
                                </div>
                            <?php AdminUI::render_card_end(); ?>
                        </div>

                    <?php elseif ( $current_tab === 'persona' ) : ?>
                        <div class="mhbo-settings-column">
                            <?php AdminUI::render_card_start( __( 'Persona & Rules', 'modern-hotel-booking' ) ); ?>
                                <table class="form-table mhbo-compact-table">
                                    <tr>
                                        <th><?php esc_html_e( 'Bot Name', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <input type="text" name="mhbo_ai_persona_name" value="<?php echo esc_attr( $persona_name ); ?>" class="regular-text" style="width: 100%;" placeholder="AI Concierge">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e( 'Instructions', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <textarea name="mhbo_ai_custom_instructions" rows="6" class="large-text" style="width: 100%;"><?php echo esc_textarea( $custom_instr ); ?></textarea>
                                            <p class="description"><?php esc_html_e( 'Define specific rules, e.g. "Suggest the breakfast buffet if guests ask about snacks."', 'modern-hotel-booking' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e( 'First Message', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <input type="text" name="mhbo_ai_welcome_message" value="<?php echo esc_attr( $welcome_msg ); ?>" class="large-text" style="width: 100%;" placeholder="<?php esc_attr_e( 'How can I help you today?', 'modern-hotel-booking' ); ?>">
                                        </td>
                                    </tr>
                                </table>
                            <?php AdminUI::render_card_end(); ?>

                            <?php AdminUI::render_card_start( __( 'Language & Locale', 'modern-hotel-booking' ) ); ?>
                                <div style="padding: 15px; background: #eff6ff; border-radius: 8px; border-left: 4px solid #3b82f6; margin-bottom: 20px;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="font-weight: 600; color: #1e40af;"><?php esc_html_e( 'Active Locale:', 'modern-hotel-booking' ); ?></span>
                                        <span class="mhbo-status-badge mhbo-status-confirmed" style="background: #3b82f6; color: #fff;">
                                            <?php echo esc_html( $detected_locale ); ?> (<?php echo esc_html( $lang_source_label ); ?>)
                                        </span>
                                    </div>
                                    <p style="margin: 10px 0 0; font-size: 13px; color: #1e3a8a;">
                                        <?php esc_html_e( 'The AI automatically adapts to the guest language based on your site configuration.', 'modern-hotel-booking' ); ?>
                                    </p>
                                </div>

                                <table class="form-table mhbo-compact-table">
                                    <tr>
                                        <th><?php esc_html_e( 'Force Language', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <input type="text" name="mhbo_ai_voice_language" value="<?php echo esc_attr( $voice_lang ); ?>" class="small-text" placeholder="auto">
                                            <p class="description"><?php esc_html_e( 'e.g. en-US, de-DE. Leave blank for auto-detect.', 'modern-hotel-booking' ); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            <?php AdminUI::render_card_end(); ?>
                        </div>

                        <div class="mhbo-settings-column">
                            <?php AdminUI::render_card_start( __( 'Local Concierge Guide', 'modern-hotel-booking' ) ); ?>
                                <table class="form-table mhbo-compact-table">
                                    <tr>
                                        <th><?php esc_html_e( 'Local Tips & Info', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <?php $local_guide = (string) get_option( 'mhbo_ai_local_guide', '' ); ?>
                                            <textarea name="mhbo_ai_local_guide" rows="12" class="large-text" style="width: 100%;" placeholder="<?php esc_attr_e( 'e.g. Best Pizza: Luigi\'s (2 blocks away), Transit: Use Line 4 to Beach...', 'modern-hotel-booking' ); ?>"><?php echo esc_textarea( $local_guide ); ?></textarea>
                                            <p class="description">
                                                <?php esc_html_e( 'Provide details about nearby attractions, restaurants, and transit. The AI will use this to answer general concierge questions.', 'modern-hotel-booking' ); ?>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            <?php AdminUI::render_card_end(); ?>
                        </div>

                    <?php elseif ( $current_tab === 'appearance' ) : ?>
                        <div class="mhbo-settings-column">
                            <?php AdminUI::render_card_start( __( 'Widget Display', 'modern-hotel-booking' ) ); ?>
                                <table class="form-table mhbo-compact-table">
                                    <tr>
                                        <th style="padding-top: 0; vertical-align: middle;"><?php esc_html_e( 'Enable Chat', 'modern-hotel-booking' ); ?></th>
                                        <td style="padding-top: 0;">
                                            <div class="mhbo-toggle-switch">
                                                <input type="checkbox" name="mhbo_ai_widget_enabled" value="1" <?php checked( $widget_enabled, 1 ); ?> id="ai_widget_enabled">
                                                <label for="ai_widget_enabled"></label>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th style="vertical-align: middle;"><?php esc_html_e( 'Global Display', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                                <input type="checkbox" name="mhbo_ai_show_globally" value="1" <?php checked( $show_globally, 1 ); ?>>
                                                <span style="font-size: 13px;"><?php esc_html_e( 'Show on all public pages', 'modern-hotel-booking' ); ?></span>
                                            </label>
                                            <p class="description"><?php esc_html_e( 'Uncheck to only show the AI via Shortcode or Gutenberg Block.', 'modern-hotel-booking' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e( 'Position', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <select name="mhbo_ai_widget_position" style="width: 100%;">
                                                <option value="bottom-right" <?php selected( $position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'modern-hotel-booking' ); ?></option>
                                                <option value="bottom-left"  <?php selected( $position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'modern-hotel-booking' ); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e( 'Brand Color', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <input type="color" name="mhbo_ai_accent_color" value="<?php echo esc_attr( $accent_color ); ?>" style="width: 40px; height: 40px; padding: 0; border: none; border-radius: 4px; cursor: pointer;">
                                                <code style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px;"><?php echo esc_html( strtoupper($accent_color) ); ?></code>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php if ( $is_pro ) : ?>
                                    <?php /* BUILD_PRO_START */ ?>
                                    <tr>
                                        <th><?php esc_html_e( 'Pro Theme', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <?php
                                            $current_theme = (string) get_option( 'mhbo_ai_theme', '' );
                                            $themes = [
                                                ''         => __( 'Default Premium', 'modern-hotel-booking' ),
                                                'minimal'  => __( 'Minimalist', 'modern-hotel-booking' ),
                                                'luxury'   => __( 'Luxury Gold', 'modern-hotel-booking' ),
                                                'boutique' => __( 'Boutique', 'modern-hotel-booking' ),
                                                'modern'   => __( 'Modern Edge', 'modern-hotel-booking' ),
                                                'dark'     => __( 'Solid Dark', 'modern-hotel-booking' ),
                                            ];
                                            ?>
                                            <select name="mhbo_ai_theme" style="width: 100%;">
                                                <?php foreach ( $themes as $val => $label ) : ?>
                                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_theme, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php /* BUILD_PRO_END */ ?>
                                    <?php endif; ?>
                                    <tr>
                                        <th><?php esc_html_e( 'Proactive Suggestion', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <input type="number" name="mhbo_ai_proactive_trigger_seconds" value="<?php echo (int) $proactive_trigger; ?>" class="small-text" min="0">
                                                <span style="font-size: 13px; color: #64748b;"><?php esc_html_e( 'seconds of inactivity before bubble appears (0 to disable).', 'modern-hotel-booking' ); ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            <?php AdminUI::render_card_end(); ?>

                            <?php AdminUI::render_card_start( __( 'Advanced Integrations', 'modern-hotel-booking' ) ); ?>
                                <table class="form-table mhbo-compact-table">
                                    <tr>
                                        <th><?php esc_html_e( 'MCP Server', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <label class="mhbo-toggle">
                                                <input type="checkbox" name="mhbo_ai_mcp_enabled" value="1" <?php checked( $mcp_enabled, 1 ); ?>>
                                                <span class="mhbo-toggle-slider"></span>
                                            </label>
                                            <p class="description" style="margin-top: 5px;"><?php esc_html_e( 'Enable external AI tools access via Model Context Protocol.', 'modern-hotel-booking' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e( 'Voice Input', 'modern-hotel-booking' ); ?></th>
                                        <td><input type="checkbox" name="mhbo_ai_voice_input_enabled" value="1" <?php checked( $voice_input, 1 ); ?>> <?php esc_html_e( 'Enable Speech-to-Text', 'modern-hotel-booking' ); ?></td>
                                    </tr>
                                    <tr>
                                        <th><?php esc_html_e( 'Voice Output', 'modern-hotel-booking' ); ?></th>
                                        <td><input type="checkbox" name="mhbo_ai_voice_output_enabled" value="1" <?php checked( $voice_output, 1 ); ?>> <?php esc_html_e( 'Enable Neural TTS', 'modern-hotel-booking' ); ?></td>
                                    </tr>
                                    <?php /* BUILD_PRO_START */ ?>
                                    <?php if ( $is_pro ) : ?>
                                    <tr>
                                        <th><?php esc_html_e( 'ElevenLabs Key', 'modern-hotel-booking' ); ?></th>
                                        <td>
                                            <input type="password" name="mhbo_ai_elevenlabs_key" value="<?php echo esc_attr( $elevenlabs ); ?>" class="regular-text" style="width: 100%;" placeholder="eleven_...">
                                            <p class="description"><?php esc_html_e( 'Required for premium high-fidelity voice.', 'modern-hotel-booking' ); ?></p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php /* BUILD_PRO_END */ ?>
                                </table>
                            <?php AdminUI::render_card_end(); ?>
                        </div>

                        <div class="mhbo-settings-column">
                            <?php AdminUI::render_card_start( __( 'Integration & Usage', 'modern-hotel-booking' ) ); ?>
                                <div style="background: #fdf2f2; border-left: 4px solid #ef4444; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                                    <strong style="display: block; margin-bottom: 5px; color: #991b1b;"><?php esc_html_e( 'Display Mode', 'modern-hotel-booking' ); ?></strong>
                                    <p style="margin: 0; font-size: 13px; color: #7f1d1d;">
                                        <?php esc_html_e( 'If "Global Display" is unchecked above, the AI will only appear where you explicitly place it using the tools below.', 'modern-hotel-booking' ); ?>
                                    </p>
                                </div>

                                <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0;">
                                    <p style="font-weight: 600; margin: 0 0 8px;"><?php esc_html_e( '1. Gutenberg Block', 'modern-hotel-booking' ); ?></p>
                                    <p style="margin: 0; font-size: 13px; color: #64748b;">
                                        <?php esc_html_e( 'Search for the "Hotel: AI Concierge" block in the editor to embed more than one chat widget or place it exactly where needed.', 'modern-hotel-booking' ); ?>
                                    </p>
                                </div>

                                <div>
                                    <p style="font-weight: 600; margin: 0 0 8px;"><?php esc_html_e( '2. Shortcode', 'modern-hotel-booking' ); ?></p>
                                    <code style="display: block; padding: 12px; background: #f1f5f9; border-radius: 4px; font-size: 14px;">[mhbo_ai_concierge]</code>
                                    <p style="margin: 8px 0 0; font-size: 12px; color: #94a3b8;">
                                        <?php esc_html_e( 'Attributes:', 'modern-hotel-booking' ); ?> <code>variant="inline|floating"</code>
                                    </p>
                                </div>
                            <?php AdminUI::render_card_end(); ?>
                        </div>
                    <?php endif; ?>
                    <?php /* BUILD_PRO_START */ ?>
                    <?php if ( $current_tab === 'discovery' ) : ?>
                        <div class="mhbo-settings-column" style="grid-column: 1 / -1;">
                             <?php AdminUI::render_card_start( __( 'AI Discovery (GEO)', 'modern-hotel-booking' ) ); ?>
                                <?php 
                                $discovery_status = LlmFile::get_status(); 
                                $is_live          = $discovery_status['summary'] && $discovery_status['full'];
                                ?>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                                    <div>
                                        <div style="padding: 20px; background: <?php echo $is_live ? '#f0fdf4' : '#fff7ed'; ?>; border-radius: 12px; margin-bottom: 24px; border: 1px solid <?php echo $is_live ? '#bbf7d0' : '#ffedd5'; ?>;">
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <div>
                                                    <p style="margin: 0; font-size: 18px; font-weight: 600; color: <?php echo $is_live ? '#166534' : '#9a3412'; ?>;">
                                                        <?php echo $is_live ? esc_html__( 'Manifest is Live', 'modern-hotel-booking' ) : esc_html__( 'Not Indexable', 'modern-hotel-booking' ); ?>
                                                    </p>
                                                    <p style="margin: 6px 0 0; font-size: 13px; color: #64748b;">
                                                        <?php if ( $discovery_sync ) : ?>
                                                            <?php 
                                                            // translators: %s: date of the manifest publication
                                                            printf( esc_html__( 'Published to root: %s', 'modern-hotel-booking' ), esc_html( $discovery_sync ) ); 
                                                            
                                                            // Calculate KB integrity vs Discovery parity.
                                                            if ( $kb_snapshot_time && strtotime( $kb_snapshot_time ) > strtotime( $discovery_sync ) ) : ?>
                                                                <br><span style="color: #b91c1c; font-weight: 600; font-size: 11px; text-transform: uppercase;">
                                                                    <?php esc_html_e( 'Outdated: KB is newer than manifest.', 'modern-hotel-booking' ); ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php else : ?>
                                                            <?php esc_html_e( 'Property discovery files are not currently present.', 'modern-hotel-booking' ); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <div style="font-size: 32px;"><?php echo $is_live ? '✅' : '⚠️'; ?></div>
                                            </div>
                                        </div>
                                        
                                        <div style="display: flex; gap: 15px;">
                                            <button type="button" id="mhbo_ai_discovery_sync" class="button button-primary button-large" style="flex: 1;">
                                                <?php echo $is_live ? esc_html__( 'Update Manifest Now', 'modern-hotel-booking' ) : esc_html__( 'Publish Discovery Files', 'modern-hotel-booking' ); ?>
                                            </button>
                                            <?php if ( $is_live ) : ?>
                                                <button type="button" id="mhbo_ai_discovery_cleanup" class="button button-link-delete">
                                                    <?php esc_html_e( 'Takedown Manifest', 'modern-hotel-booking' ); ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div>
                                        <table class="form-table mhbo-compact-table" style="margin: 0;">
                                            <tr>
                                                <th style="padding: 0 10px 10px 0;"><?php esc_html_e( 'Auto-Sync (Pro)', 'modern-hotel-booking' ); ?></th>
                                                <td style="padding: 0 0 10px 0;">
                                                    <label class="mhbo-toggle">
                                                        <input type="checkbox" name="mhbo_ai_discovery_auto_sync" value="1" <?php checked( $discovery_auto, 1 ); ?>>
                                                        <span class="mhbo-toggle-slider"></span>
                                                    </label>
                                                    <p class="description"><?php esc_html_e( 'Automatically refresh discovery summary when hotel data changes.', 'modern-hotel-booking' ); ?></p>
                                                </td>
                                            </tr>
                                        </table>
                                        <div style="margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                                            <h4 style="margin: 0 0 10px; font-size: 14px;"><?php esc_html_e( 'Impact of Discovery', 'modern-hotel-booking' ); ?></h4>
                                            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #475569;">
                                                <?php esc_html_e( 'By publishing llms.txt, you provide AI agents (like Perplexity, ChatGPT, and AI Travel Assistants) a structured map of your hotel. This ensures accurate pricing, amenity listings, and direct booking links appear in conversational search results.', 'modern-hotel-booking' ); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            <?php AdminUI::render_card_end(); ?>
                        </div>

                    <?php elseif ( $current_tab === 'analytics' ) : ?>
                        <!-- ── Analytics (Pro) ─────────────────────────────────── -->
                        <div class="mhbo-grid-column" style="grid-column: 1 / -1;">
                            <?php AdminUI::render_card_start( __( 'AI Performance & Analytics', 'modern-hotel-booking' ) ); ?>
                                <?php self::render_analytics(); ?>
                            <?php AdminUI::render_card_end(); ?>
                    <?php endif; ?>
                    <?php /* BUILD_PRO_END */ ?>

                </div>

                <div class="mhbo-form-actions-dock" style="margin-top: 0; background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 -4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
                    <?php submit_button( __( 'Apply AI Configuration', 'modern-hotel-booking' ), 'primary large' ); ?>
                </div>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Save Handler
    // -------------------------------------------------------------------------

    private static function handle_save(): void {
        if ( ! isset( $_POST['mhbo_ai_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mhbo_ai_nonce'] ) ), self::NONCE_SAVE ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab = isset( $_POST['active_tab'] ) ? sanitize_key( $_POST['active_tab'] ) : '';

        // Emergency Recovery: If everything got disabled, turn it back on.
        if ( ! get_option( 'mhbo_ai_enabled' ) && ! get_option( 'mhbo_ai_show_globally' ) ) {
             update_option( 'mhbo_ai_enabled', 1 );
             update_option( 'mhbo_ai_show_globally', 1 );
        }

        $tab_fields = [
            'general'    => [
                'mhbo_ai_enabled', 'mhbo_ai_provider', 'mhbo_ai_api_key', 'mhbo_ai_model', 
                'mhbo_ai_custom_url', 'mhbo_ai_fallback_provider', 'mhbo_ai_fallback_api_key', 
                'mhbo_ai_fallback_model', 'mhbo_ai_fallback_custom_url'
            ],
            'persona'    => [
                'mhbo_ai_persona_name', 'mhbo_ai_custom_instructions', 'mhbo_ai_welcome_message',
                'mhbo_ai_voice_language', 'mhbo_ai_local_guide'
            ],
            'appearance' => [
                'mhbo_ai_widget_enabled', 'mhbo_ai_show_globally', 'mhbo_ai_widget_position', 
                'mhbo_ai_accent_color', 'mhbo_ai_theme', 'mhbo_ai_proactive_trigger_seconds',
                'mhbo_ai_mcp_enabled', 'mhbo_ai_voice_input_enabled', 'mhbo_ai_voice_output_enabled',
                'mhbo_ai_elevenlabs_key'
            ],
            'discovery'  => [
                'mhbo_ai_discovery_auto_sync'
            ]
        ];

        // 1. Text/Password/Select fields.
        $text_opts = $tab_fields[ $active_tab ] ?? [];
        $checkboxes = [ 
            'mhbo_ai_enabled', 'mhbo_ai_show_globally', 'mhbo_ai_widget_enabled', 
            'mhbo_ai_mcp_enabled', 'mhbo_ai_voice_input_enabled', 'mhbo_ai_voice_output_enabled',
            'mhbo_ai_discovery_auto_sync'
        ];

        // 0. Extract all relevant POST data once (Gated by Nonce).
        $post_data = wp_unslash( $_POST );

        foreach ( $text_opts as $key ) {
            if ( ! isset( $post_data[ $key ] ) && ! in_array( $key, $checkboxes, true ) ) {
                continue;
            }

            $raw_val = $post_data[ $key ] ?? null;

            // Textarea special handling.
            if ( in_array( $key, ['mhbo_ai_custom_instructions', 'mhbo_ai_local_guide'], true ) ) {
                $val = is_string( $raw_val ) ? sanitize_textarea_field( $raw_val ) : '';
                update_option( $key, $val );
                continue;
            }

            // Checkbox logic: Only update if it's a checkbox AND it's in the current tab's scope.
            if ( in_array( $key, $checkboxes, true ) ) {
                $val = ( null !== $raw_val ) ? 1 : 0;
                update_option( $key, $val );
                continue;
            }

            // Standard text/select.
            if ( null !== $raw_val ) {
                $val_str = (string) $raw_val;
                if ( 'mhbo_ai_proactive_trigger_seconds' === $key ) {
                    update_option( $key, absint( $val_str ) );
                } else {
                    update_option( $key, sanitize_text_field( $val_str ) );
                }
            }
        }

        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'AI Concierge settings saved.', 'modern-hotel-booking' ) . '</p></div>';
        } );
    }

    // -------------------------------------------------------------------------
    // Language Detection Helper
    // -------------------------------------------------------------------------

    /**
     * Return the currently active locale and which plugin/system provided it.
     *
     * @return array{ locale: string, source: string, label: string }
     */
    private static function get_multilang_status(): array {
        // Polylang.
        if ( function_exists( 'pll_current_language' ) ) {
            /** @var string|false $locale */
            $locale = call_user_func( 'pll_current_language', 'locale' );
            if ( $locale ) {
                return [
                    'locale' => str_replace( '_', '-', $locale ),
                    'source' => 'polylang',
                    'label'  => 'Polylang',
                ];
            }
        }

        // WPML.
        if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            $details = apply_filters( 'wpml_current_language_details', null );
            $locale  = ( is_array( $details ) && isset( $details['default_locale'] ) && '' !== $details['default_locale'] )
                ? str_replace( '_', '-', (string) $details['default_locale'] )
                : (string) constant( 'ICL_LANGUAGE_CODE' );
            return [
                'locale' => $locale,
                'source' => 'wpml',
                'label'  => 'WPML',
            ];
        }

        // qTranslate-XT / qTranslate-X.
        global $q_config;
        if ( isset( $q_config['language'] ) && '' !== $q_config['language'] ) {
            $qlang   = (string) $q_config['language'];
            /** @var string $qlocale */
            $qlocale = $q_config['locale'][ $qlang ] ?? $qlang;
            return [
                'locale' => str_replace( '_', '-', $qlocale ),
                'source' => 'qtranslate',
                'label'  => 'qTranslate-XT',
            ];
        }

        return [
            'locale' => str_replace( '_', '-', get_locale() ),
            'source' => 'wp_core',
            'label'  => __( 'WordPress site language', 'modern-hotel-booking' ),
        ];
    }

    /* BUILD_PRO_START */

    /**
     * Render the Pro analytics section.
     */
    private static function render_analytics(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'mhbo_chat_sessions';

        // Check table exists; auto-create if missing (self-healing).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            try {
                ChatSession::create_table();
                // Re-check after creation attempt.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            } catch ( \Throwable $e ) {
                $exists = null;
            }
        }
        if ( $exists !== $table ) {
            echo '<div class="notice notice-error inline" style="margin-top: 0; border-radius: 8px;"><p>' . esc_html__( 'The chat sessions table could not be created. Please deactivate and reactivate the plugin, or contact support.', 'modern-hotel-booking' ) . '</p></div>';
            return;
        }

        $month_start = gmdate( 'Y-m-01 00:00:00' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_this_month = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM %i WHERE created_at >= %s", $table, $month_start )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_questions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT message_content, COUNT(*) AS cnt FROM %i WHERE message_role = 'user' AND created_at >= %s GROUP BY message_content ORDER BY cnt DESC LIMIT 5",
                $table,
                $month_start
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $conversions = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM %i WHERE booking_id IS NOT NULL AND created_at >= %s", $table, $month_start )
        );

        $conv_rate = $total_this_month > 0 ? round( ( $conversions / $total_this_month ) * 100, 1 ) : 0;

        ?>
        <div class="mhbo-analytics-dashboard">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; text-align: center;">
                    <p style="margin: 0; color: #64748b; font-size: 13px; font-weight: 600; text-transform: uppercase;"><?php esc_html_e( 'Chats (Monthly)', 'modern-hotel-booking' ); ?></p>
                    <p style="margin: 10px 0 0; font-size: 28px; font-weight: 700; color: #1e293b;"><?php echo esc_html( (string) $total_this_month ); ?></p>
                </div>
                <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; text-align: center;">
                    <p style="margin: 0; color: #64748b; font-size: 13px; font-weight: 600; text-transform: uppercase;"><?php esc_html_e( 'Bookings Influenced', 'modern-hotel-booking' ); ?></p>
                    <p style="margin: 10px 0 0; font-size: 28px; font-weight: 700; color: #10b981;"><?php echo esc_html( (string) $conversions ); ?></p>
                </div>
                <div style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; text-align: center;">
                    <p style="margin: 0; color: #64748b; font-size: 13px; font-weight: 600; text-transform: uppercase;"><?php esc_html_e( 'Conversion Rate', 'modern-hotel-booking' ); ?></p>
                    <p style="margin: 10px 0 0; font-size: 28px; font-weight: 700; color: #3b82f6;"><?php echo esc_html( (string) $conv_rate ); ?>%</p>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">
                <div>
                    <h4 style="margin: 0 0 15px;"><?php esc_html_e( 'Trending Questions', 'modern-hotel-booking' ); ?></h4>
                    <?php if ( [] === $top_questions || null === $top_questions ) : ?>
                        <div style="padding: 20px; background: #fdf2f2; border-radius: 8px; color: #991b1b; font-size: 13px;">
                            <?php esc_html_e( 'No trending questions detected in the current period.', 'modern-hotel-booking' ); ?>
                        </div>
                    <?php else : ?>
                        <div style="background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden;">
                            <?php foreach ( $top_questions as $q ) : ?>
                                <div style="padding: 12px 15px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-size: 14px; color: #475569; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80%;"><?php echo esc_html( (string) $q->message_content ); ?></span>
                                    <span class="mhbo-status-badge" style="background: #f1f5f9; color: #64748b; min-width: 30px; text-align: center;"><?php echo (int) $q->cnt; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="background: #f1f5f9; padding: 20px; border-radius: 12px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center;">
                    <span class="dashicons dashicons-download" style="font-size: 40px; width: 40px; height: 40px; color: #64748b; margin-bottom: 15px;"></span>
                    <h4 style="margin: 0 0 10px;"><?php esc_html_e( 'Deep Dive Data', 'modern-hotel-booking' ); ?></h4>
                    <p style="margin: 0 0 20px; font-size: 13px; color: #64748b;"><?php esc_html_e( 'Download the full interaction logs for sentiment analysis and fine-tuning.', 'modern-hotel-booking' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=mhbo_export_chat_logs&nonce=' . wp_create_nonce( 'mhbo_export_chat' ) ) ); ?>" class="button button-primary button-large" style="width: 100%;">
                        <?php esc_html_e( 'Export CSV', 'modern-hotel-booking' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /* BUILD_PRO_END */

    // -------------------------------------------------------------------------
    // Inline JS
    // -------------------------------------------------------------------------

    private static function get_inline_js(): string {
        $nonce = wp_create_nonce( 'wp_rest' );
        $rest_url = esc_url_raw( rest_url( 'mhbo/v1' ) );

        return "
        jQuery(function($) {
            const apiRequest = (path, method = 'POST') => {
                return $.ajax({
                    url: '{$rest_url}' + path,
                    method: method,
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', '{$nonce}');
                    }
                });
            };

            // KB Refresh.
            $('#mhbo_ai_kb_refresh').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('" . esc_js( __( 'Refreshing…', 'modern-hotel-booking' ) ) . "');
                apiRequest('/ai/sync-kb').done(function(res) {
                    location.reload();
                }).fail(function(xhr) {
                    btn.prop('disabled', false).text('" . esc_js( __( 'Refresh Knowledge Base Now', 'modern-hotel-booking' ) ) . "');
                    $('#mhbo_ai_kb_result').text(xhr.responseJSON.message).css('color', 'red');
                });
            });

            // Test Connection.
            $('#mhbo_ai_test_btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('" . esc_js( __( 'Testing…', 'modern-hotel-booking' ) ) . "');
                apiRequest('/ai/test-connection').done(function(res) {
                    btn.prop('disabled', false).text('" . esc_js( __( 'Test Connection', 'modern-hotel-booking' ) ) . "');
                    $('#mhbo_ai_test_result').text('✅ ' + res.message).css('color', 'green');
                }).fail(function(xhr) {
                    btn.prop('disabled', false).text('" . esc_js( __( 'Test Connection', 'modern-hotel-booking' ) ) . "');
                    $('#mhbo_ai_test_result').text('❌ ' + xhr.responseJSON.message).css('color', 'red');
                });
            });

            // Test Fallback.
            $('#mhbo_ai_test_fallback_btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('" . esc_js( __( 'Testing…', 'modern-hotel-booking' ) ) . "');
                apiRequest('/ai/test-fallback').done(function(res) {
                    btn.prop('disabled', false).text('" . esc_js( __( 'Test Fallback', 'modern-hotel-booking' ) ) . "');
                    $('#mhbo_ai_test_fallback_result').text('✅ ' + res.message).css('color', 'green');
                }).fail(function(xhr) {
                    btn.prop('disabled', false).text('" . esc_js( __( 'Test Fallback', 'modern-hotel-booking' ) ) . "');
                    $('#mhbo_ai_test_fallback_result').text('❌ ' + xhr.responseJSON.message).css('color', 'red');
                });
            });

            // Discovery Sync.
            $('#mhbo_ai_discovery_sync').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('" . esc_js( __( 'Syncing…', 'modern-hotel-booking' ) ) . "');
                apiRequest('/ai/sync-discovery').done(function(res) {
                    location.reload();
                }).fail(function(xhr) {
                    btn.prop('disabled', false).text('" . esc_js( __( 'Sync Failed', 'modern-hotel-booking' ) ) . "');
                    alert(xhr.responseJSON.message);
                });
            });

            // Discovery Cleanup.
            $('#mhbo_ai_discovery_cleanup').on('click', function() {
                if (!confirm('" . esc_js( __( 'Are you sure?', 'modern-hotel-booking' ) ) . "')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('" . esc_js( __( 'Cleaning…', 'modern-hotel-booking' ) ) . "');
                apiRequest('/ai/cleanup-discovery').done(function() {
                    location.reload();
                });
            });

            // Clear Quota Lock.
            $('#mhbo_ai_clear_lock').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('" . esc_js( __( 'Clearing…', 'modern-hotel-booking' ) ) . "');
                apiRequest('/ai/clear-lock').done(function(res) {
                    btn.prop('disabled', false).text('" . esc_js( __( 'Engine Reset ✅', 'modern-hotel-booking' ) ) . "');
                    setTimeout(() => btn.text('" . esc_js( __( 'Reset Engine Lock', 'modern-hotel-booking' ) ) . "'), 2000);
                }).fail(function(xhr) {
                    btn.prop('disabled', false).text('" . esc_js( __( 'Failed', 'modern-hotel-booking' ) ) . "');
                    alert(xhr.responseJSON.message);
                });
            });

            // Create Analytics Table.
            $('#mhbo_ai_create_table_btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('" . esc_js( __( 'Creating…', 'modern-hotel-booking' ) ) . "');
                apiRequest('/ai/create-analytics-table').done(function() {
                    location.reload();
                }).fail(function(xhr) {
                     btn.prop('disabled', false);
                     alert(xhr.responseJSON.message);
                });
            });

            // Provider Row Toggles.
            $('#mhbo_ai_provider').on('change', function() {
                var val = $(this).val();
                $('#mhbo_custom_url_row').toggle(val === 'ollama' || val === 'custom');
                $('#mhbo_ai_reasoning_effort_row').toggle(val === 'openai' || val === 'anthropic');
                
                // Hide all wrappers.
                $('.mhbo-provider-model-wrapper').hide();

                // Show provider-specific wrapper.
                if (val === 'gemini') {
                    $('#mhbo_gemini_model_wrapper').show();
                    $('#mhbo_ai_gemini_model_select').trigger('change');
                } else if (val === 'openai') {
                    $('#mhbo_openai_model_wrapper').show();
                    $('#mhbo_ai_openai_model_select').trigger('change');
                } else if (val === 'anthropic') {
                    $('#mhbo_anthropic_model_wrapper').show();
                    $('#mhbo_ai_anthropic_model_select').trigger('change');
                } else {
                    $('#mhbo_manual_model_wrapper').show();
                }
            }).trigger('change');

            // Model preset select vs manual input.
            $('.mhbo-model-preset-select').on('change', function() {
                var val = $(this).val();
                if (val === 'custom') {
                    $('#mhbo_manual_model_wrapper').show();
                } else {
                    $('#mhbo_manual_model_wrapper').hide();
                    $('#mhbo_ai_model_input').val(val);
                }
            });

            $('#mhbo_ai_fallback_provider').on('change', function() {
                var val = $(this).val();
                $('#mhbo_fallback_custom_url_row').toggle(val === 'ollama' || val === 'custom');
            }).trigger('change');
        });
        ";
    }
}
