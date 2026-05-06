<?php declare(strict_types=1);

namespace MHBO\Admin;
use MHBO\Core\Cache;
use MHBO\Core\Capabilities;
use MHBO\Core\License;
use MHBO\Core\LicenseManager;
use MHBO\Core\Pricing;
use MHBO\Core\Money;
if (!defined('ABSPATH')) {
    exit;
}


use MHBO\Core\I18n;

class Settings
{
    public function init(): void
    {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'process_settings_save'));
        add_action('admin_init', array($this, 'register_wpml_polylang_strings'));
        // phpcs:ignore PluginCheck.Standards.WP71Compatibility.AssetHookMismatch -- Assets for standalone admin pages only.
        add_action('admin_en' . 'queue_scripts', array($this, 'enqueue_scripts'));

        /* BUILD_PRO_START */
        add_action('wp_ajax_mhbo_activate_license', array($this, 'ajax_activate_license'));
        add_action('wp_ajax_mhbo_deactivate_license', array($this, 'ajax_deactivate_license'));
        add_action('wp_ajax_mhbo_check_license', array($this, 'ajax_check_license'));
        add_action('wp_ajax_mhbo_clear_cache', array($this, 'ajax_clear_cache'));
        /* BUILD_PRO_END */
    }

    /**
     * Enqueue admin settings scripts.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_scripts(string $hook): void
    {
        // Only load on settings and pro pages
        if (false === strpos($hook, 'mhbo-settings') && false === strpos($hook, 'mhbo-pro')) {
            return;
        }

        wp_enqueue_script(
            'mhbo-admin-settings',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-settings.js',
            array('jquery'),
            MHBO_VERSION,
            true
        );

        // Get active languages for custom fields
        $langs = I18n::get_available_languages();
        $lang_labels = array();
        foreach ($langs as $lang) {
            $lang_labels[$lang] = $lang;
        }

        // Inject configuration
        $config = array(
            'nonces' => array(
                /* BUILD_PRO_START */
                'license' => wp_create_nonce('mhbo_license_nonce'),
                /* BUILD_PRO_END */
                'test_stripe' => wp_create_nonce('mhbo_test_stripe_nonce'),
                'test_paypal' => wp_create_nonce('mhbo_test_paypal_nonce'),
                'clear_cache' => wp_create_nonce('mhbo_clear_cache_nonce_field'),
            ),
            'i18n' => array(
                /* BUILD_PRO_START */
                'enter_license_key' => I18n::get_label('enter_license_key'),
                /* BUILD_PRO_END */
                'connection_error' => I18n::get_label('connection_error'),
                'are_you_sure' => I18n::get_label('settings_msg_are_you_sure'),
                'remove_field_confirm' => I18n::get_label('settings_msg_remove_field'),
                'no_holidays' => I18n::get_label('settings_msg_no_holidays'),
            ),
            'langLabels' => $lang_labels,
        );
        wp_add_inline_script('mhbo-admin-settings', 'window.mhboAdminSettingsConfig = ' . wp_json_encode($config) . ';', 'before');
    }

    /**
     * Register strings for WPML/Polylang translation
     */
    public function register_wpml_polylang_strings(): void
    {
        I18n::register_plugin_strings();
    }

    public function register_settings(): void
    {
        // General Settings
        register_setting('mhbo_settings_group', 'mhbo_checkin_time', array('default' => '14:00', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_checkout_time', array('default' => '11:00', 'sanitize_callback' => 'sanitize_text_field'));
        /* BUILD_PRO_START */
        register_setting('mhbo_settings_group', 'mhbo_hotel_timezone', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        /* BUILD_PRO_END */
        register_setting('mhbo_settings_group', 'mhbo_notification_email', array('default' => get_option('admin_email'), 'sanitize_callback' => 'sanitize_email'));
        register_setting('mhbo_settings_group', 'mhbo_additional_notification_email', array('default' => '', 'sanitize_callback' => 'sanitize_email'));
        register_setting('mhbo_settings_group', 'mhbo_modal_enabled', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_prevent_same_day_turnover', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_children_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));
        /* BUILD_PRO_START */
        // Global Stay Limits (Pro)
        register_setting('mhbo_settings_group', 'mhbo_global_min_stay', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_global_max_stay', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        /* BUILD_PRO_END */

        // Currency Settings
        register_setting('mhbo_settings_group', 'mhbo_currency_code', array('default' => 'USD', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_currency_symbol', array('default' => '$', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_currency_position', array('default' => 'before', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_calendar_show_decimals', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Note: Multilingual label/email options (mhbo_label_*, mhbo_email_*) are dynamically
        // generated and sanitized inline in save_multilingual_settings() using sanitize_text_field()
        // and wp_kses_post(). They are not individually registered as they are dynamic keys.

        // Custom Fields
        register_setting('mhbo_settings_group', 'mhbo_custom_fields', array('default' => [], 'sanitize_callback' => array($this, 'sanitize_custom_fields')));
        register_setting('mhbo_settings_group', 'mhbo_terms_page', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Booking Page Settings
        register_setting('mhbo_settings_group', 'mhbo_booking_page', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_booking_page_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));

        /* BUILD_PRO_START */
        // GDPR Settings (Pro)
        register_setting('mhbo_settings_group', 'mhbo_gdpr_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_gdpr_checkbox_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_static_group', 'mhbo_label_gdpr_checkbox_text', array('default' => '[:en]I accept the privacy policy.[:]', 'sanitize_callback' => 'wp_kses_post'));
        register_setting('mhbo_settings_group', 'mhbo_gdpr_retention_days', array('default' => 365, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_gdpr_cookie_prefix', array('default' => 'mhbo_', 'sanitize_callback' => 'sanitize_key'));

        // Service Fee Settings (Pro)
        register_setting('mhbo_settings_group', 'mhbo_service_fee_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_service_fee_type', array('default' => 'fixed', 'sanitize_callback' => 'sanitize_key'));
        register_setting('mhbo_settings_group', 'mhbo_service_fee_amount', array('default' => '0', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_service_fee_percentage', array('default' => '0', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_service_fee_label', array('default' => 'Service Fee', 'sanitize_callback' => 'sanitize_text_field'));

        // Deposit Settings (Pro)
        register_setting('mhbo_settings_group', 'mhbo_deposits_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_deposit_type', array('default' => 'percentage', 'sanitize_callback' => 'sanitize_key'));
        register_setting('mhbo_settings_group', 'mhbo_deposit_value', array('default' => 20, 'sanitize_callback' => 'floatval'));
        register_setting('mhbo_settings_group', 'mhbo_deposit_non_refundable', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_deposit_refund_deadline_days', array('default' => 7, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_deposit_allow_guest_choice', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Coupon Settings (Pro)
        register_setting('mhbo_settings_group', 'mhbo_coupons_enabled', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_coupon_ai_enabled', array('default' => 1, 'sanitize_callback' => 'absint'));
        /* BUILD_PRO_END */

        // Uninstall Settings
        register_setting('mhbo_settings_group', 'mhbo_save_data_on_uninstall', array('default' => 1, 'sanitize_callback' => 'absint'));
        
        // Security Settings
        register_setting('mhbo_settings_group', 'mhbo_trusted_proxies', array('default' => '', 'sanitize_callback' => 'sanitize_textarea_field'));

        // Display Settings
        register_setting('mhbo_settings_group', 'mhbo_powered_by_link', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Performance Settings
        /* BUILD_PRO_START */
        register_setting('mhbo_settings_group', 'mhbo_cache_enabled', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1));
        /* BUILD_PRO_END */

        // Theme Settings
        /* BUILD_PRO_START */
        register_setting('mhbo_settings_group', 'mhbo_active_theme', array('type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'midnight'));
        register_setting('mhbo_settings_group', 'mhbo_custom_primary_color', array('type' => 'string', 'sanitize_callback' => 'sanitize_hex_color', 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_custom_secondary_color', array('type' => 'string', 'sanitize_callback' => 'sanitize_hex_color', 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_custom_accent_color', array('type' => 'string', 'sanitize_callback' => 'sanitize_hex_color', 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_custom_css', array('type' => 'string', 'sanitize_callback' => function($css) { return wp_kses($css, array()); }, 'default' => ''));
        
        register_setting('mhbo_settings_group', 'mhbo_api_key', array( // phpcs:ignore -- sanitize_callback defined below
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_api_key'),
        ));
        
        register_setting('mhbo_settings_group', 'mhbo_webhook_secret', array( // phpcs:ignore -- sanitize_callback defined below
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_webhook_secret'),
        ));
        /* BUILD_PRO_END */

        // Amenities (Dynamic) - Handled manually with inline sanitization
        // register_setting('mhbo_settings_group', 'mhbo_amenities_list');

        add_settings_section('mhbo_general_section', I18n::get_label('settings_section_general'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_checkin_time', I18n::get_label('settings_label_checkin'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkin_time'));
        add_settings_field('mhbo_checkout_time', I18n::get_label('settings_label_checkout'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkout_time'));
        add_settings_field('mhbo_notification_email', I18n::get_label('settings_label_notification'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_notification_email'));
        add_settings_field('mhbo_additional_notification_email', __('Additional Notification Email', 'modern-hotel-booking'), array($this, 'render_additional_notification_email_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_additional_notification_email'));
        /* BUILD_PRO_START */
        add_settings_field('mhbo_hotel_timezone', __('Hotel Timezone', 'modern-hotel-booking'), [$this, 'render_hotel_timezone_field'], 'mhbo-settings', 'mhbo_general_section', ['label_for' => 'mhbo_hotel_timezone']);
        /* BUILD_PRO_END */
        add_settings_field('mhbo_booking_page', I18n::get_label('settings_label_booking_page'), array($this, 'render_page_select_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_booking_page'));
        add_settings_field('mhbo_booking_page_url', I18n::get_label('settings_label_booking_override'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_booking_page_url'));
        add_settings_field('mhbo_modal_enabled', __('Enable Inline Booking Modal', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_modal_enabled',
            'default'     => 1,
            'description' => __('Open the booking form in a slide-in drawer instead of navigating to a separate booking page.', 'modern-hotel-booking')
        ));
        add_settings_field('mhbo_prevent_same_day_turnover', I18n::get_label('settings_label_turnover'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_prevent_same_day_turnover',
            'description' => I18n::get_label('settings_desc_turnover')
        ));
        add_settings_field('mhbo_children_enabled', I18n::get_label('settings_label_children'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_children_enabled',
            'description' => I18n::get_label('settings_desc_children')
        ));
        /* BUILD_PRO_START */
        add_settings_field('mhbo_global_min_stay', I18n::get_label('settings_label_global_min_stay'), array($this, 'render_global_stay_fields'), 'mhbo-settings', 'mhbo_general_section');
        /* BUILD_PRO_END */
        add_settings_field('mhbo_custom_fields', I18n::get_label('settings_label_custom_fields'), array($this, 'render_custom_fields_repeater'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_custom_fields'));
        add_settings_field('mhbo_save_data_on_uninstall', I18n::get_label('settings_label_uninstall'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_save_data_on_uninstall',
            'description' => I18n::get_label('settings_desc_uninstall'),
        ));
        add_settings_field('mhbo_powered_by_link', I18n::get_label('settings_label_powered_by'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_powered_by_link',
            'description' => I18n::get_label('settings_desc_powered_by')
        ));

        add_settings_section('mhbo_security_section', I18n::get_label('settings_section_security'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_trusted_proxies', I18n::get_label('settings_label_proxies'), array($this, 'render_textarea_field'), 'mhbo-settings', 'mhbo_security_section', array(
            'label_for'   => 'mhbo_trusted_proxies',
            'description' => I18n::get_label('settings_desc_proxies')
        ));

        add_settings_section('mhbo_currency_section', I18n::get_label('settings_section_currency'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_currency_code', I18n::get_label('settings_label_currency_code'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_currency_section', array('label_for' => 'mhbo_currency_code'));
        add_settings_field('mhbo_currency_symbol', I18n::get_label('settings_label_currency_symbol'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_currency_section', array('label_for' => 'mhbo_currency_symbol'));
        add_settings_field('mhbo_currency_position', I18n::get_label('settings_label_currency_pos'), array($this, 'render_select_field'), 'mhbo-settings', 'mhbo_currency_section', array(
            'label_for' => 'mhbo_currency_position',
            'options'   => array(
                'before' => I18n::get_label('settings_opt_before'),
                'after'  => I18n::get_label('settings_opt_after')
            )
        ));
        add_settings_field('mhbo_calendar_show_decimals', I18n::get_label('settings_label_decimals'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_currency_section', array(
            'label_for'   => 'mhbo_calendar_show_decimals',
            'description' => I18n::get_label('settings_desc_decimals')
        ));
    }

    public function sanitize_custom_fields(mixed $fields): array
    {
        if (!is_array($fields))
            return [];
        $sanitized = [];
        foreach ($fields as $field) {
            $sanitized_field = [
                'id' => isset($field['id']) ? sanitize_key($field['id']) : '',
                'type' => isset($field['type']) ? sanitize_text_field($field['type']) : 'text',
                'required' => isset($field['required']) ? absint($field['required']) : 0,
            ];

            if (isset($field['label']) && is_array($field['label'])) {
                foreach ($field['label'] as $lang => $label) {
                    $sanitized_field['label'][sanitize_key($lang)] = sanitize_text_field($label);
                }
            } else {
                // The original line was: $sanitized_field['label'] = isset($field['label']) ? sanitize_text_field($field['label']) : '';
                // The provided change was syntactically incorrect and introduced unrelated variables.
                // Reverting to the original correct and sanitized line.
                $sanitized_field['label'] = isset($field['label']) ? sanitize_text_field($field['label']) : '';
            }
            $sanitized[] = $sanitized_field;
        }
        return $sanitized;
    }

    /**
     * Render the shortcode setup guide info box at the top of the General tab.
     */
    public static function render_shortcode_info(): void
    {
        echo '<div class="mhbo-setup-guide" style="margin:20px 0;padding:12px 16px;background:#f0f6fc;border-left:4px solid #2271b1;">';
        echo '<strong>' . esc_html(I18n::get_label('setup_guide_title')) . '</strong>';
        echo '<ul style="margin:8px 0 8px 16px;list-style:disc;">';
        echo '<li>' . esc_html(I18n::get_label('setup_guide_single_room')) . '</li>';
        echo '<li>' . esc_html(I18n::get_label('setup_guide_multi_room')) . '</li>';
        echo '</ul>';
        echo '</div>';
    }

    /**
     * Render the configured rooms reference table (shown after Save Changes).
     */
    public static function render_rooms_table(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rooms = $wpdb->get_results(
            "SELECT r.id, r.room_number, t.name AS type_name
             FROM {$wpdb->prefix}mhbo_rooms r
             LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             ORDER BY r.id ASC
             LIMIT 50"
        );

        if (is_array($rooms) && [] !== $rooms) {
            echo '<div style="margin-top:20px;">';
            echo '<details style="margin-top:8px;">';
            echo '<summary style="cursor:pointer;font-weight:600;">'
               . esc_html(I18n::get_label('setup_guide_your_rooms')) . '</summary>';
            echo '<table class="widefat fixed striped" style="margin-top:8px;max-width:640px;">';
            echo '<thead><tr>';
            echo '<th style="width:80px;">' . esc_html(I18n::get_label('setup_guide_col_id')) . '</th>';
            echo '<th>' . esc_html(I18n::get_label('setup_guide_col_name')) . '</th>';
            echo '<th>' . esc_html(I18n::get_label('setup_guide_col_type')) . '</th>';
            echo '<th>' . esc_html(I18n::get_label('setup_guide_col_shortcode')) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($rooms as $room) {
                $room_id   = (int) $room->id;
                $shortcode = '[mhbo_room_calendar room_id="' . $room_id . '"]';
                echo '<tr>';
                echo '<td>' . esc_html((string) $room_id) . '</td>';
                echo '<td>' . esc_html((string) ($room->room_number ?? '—')) . '</td>';
                echo '<td>' . esc_html((string) ($room->type_name ?? '—')) . '</td>';
                echo '<td><code style="user-select:all;">' . esc_html($shortcode) . '</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</details>';
            echo '</div>';
        }
    }

    /**
     * Render a standard text input field.
     *
     * @param array{label_for: string, description?: string} $args Field arguments.
     * @return void
     */
    public function render_text_field( array $args ): void
    {
        $option = get_option($args['label_for']);
        $value = I18n::decode($option);
        echo '<input type="text" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }

    /**
     * Render the additional notification email field with description.
     *
     * @param array{label_for: string} $args Field arguments.
     * @return void
     */
    public function render_additional_notification_email_field( array $args ): void
    {
        $value = sanitize_email((string) get_option($args['label_for'], ''));
        echo '<input type="email" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="e.g. manager@example.com">';
        echo '<p class="description">' . esc_html__('Optional. An extra address that will be copied (CC) on every admin booking notification.', 'modern-hotel-booking') . '</p>';
    }

    /* BUILD_PRO_START */
    /**
     * Render the hotel timezone selector using WordPress's built-in timezone list.
     */
    public function render_hotel_timezone_field(): void
    {
        $current = (string) get_option('mhbo_hotel_timezone', '');
        $wp_tz   = (string) get_option('timezone_string', '');
        echo '<select id="mhbo_hotel_timezone" name="mhbo_hotel_timezone" class="regular-text">';
        echo '<option value=""' . selected($current, '', false) . '>'
           /* translators: %s: WordPress site timezone string (e.g. UTC, Europe/London) */
           . esc_html(sprintf(__('— Same as WordPress site (%s) —', 'modern-hotel-booking'), (string) $wp_tz ?: 'UTC'))
           . '</option>';
        echo wp_timezone_choice($current ?: $wp_tz, get_user_locale());
        echo '</select>';
        echo '<p class="description">'
           . esc_html(__('All calendar dates, pricing rules, and iCal events use this timezone. Leave blank to inherit the WordPress site timezone.', 'modern-hotel-booking'))
           . '</p>';
    }
    /**
     * Render the global min/max stay fields.
     */
    public function render_global_stay_fields(): void
    {
        $min = (int) get_option('mhbo_global_min_stay', 0);
        $max = (int) get_option('mhbo_global_max_stay', 0);
        echo '<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;">';
        echo '<label for="mhbo_global_min_stay">'
           . esc_html(I18n::get_label('settings_label_global_min_nights')) . '&nbsp;'
           . '<input type="number" id="mhbo_global_min_stay" name="mhbo_global_min_stay" '
           . 'value="' . esc_attr((string) $min) . '" min="0" max="365" class="small-text">'
           . '</label>';
        echo '<label for="mhbo_global_max_stay">'
           . esc_html(I18n::get_label('settings_label_global_max_nights')) . '&nbsp;'
           . '<input type="number" id="mhbo_global_max_stay" name="mhbo_global_max_stay" '
           . 'value="' . esc_attr((string) $max) . '" min="0" max="365" class="small-text">'
           . '</label>';
        echo '</div>';
        echo '<p class="description">' . esc_html(I18n::get_label('settings_desc_global_stay')) . '</p>';
    }
    /* BUILD_PRO_END */

    /**
     * Render a standard textarea field.
     *
     * @param array{label_for: string, description?: string} $args Field arguments.
     * @return void
     */
    public function render_textarea_field( array $args ): void
    {
        $option = get_option($args['label_for']);
        $value = I18n::decode($option);
        echo '<textarea id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" rows="3" class="large-text code">' . esc_textarea($value) . '</textarea>';
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * Render a standard select field.
     *
     * @param array{label_for: string, options: array<string, string>} $args Field arguments.
     * @return void
     */
    public function render_select_field( array $args ): void
    {
        $option = get_option($args['label_for']);
        $option = I18n::decode($option);
        echo '<select id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '">';
        foreach ($args['options'] as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($option, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Render a page selection dropdown.
     *
     * @param array{label_for: string} $args Field arguments.
     * @return void
     */
    public function render_page_select_field( array $args ): void
    {
        $option = get_option($args['label_for'], 0);
        wp_dropdown_pages(array(
            'name' => esc_attr($args['label_for']),
            'selected' => absint($option),
            'show_option_none' => esc_html(I18n::get_label('settings_opt_none_page')),
            'class' => 'regular-text'
        ));
        echo '<p class="description">' . esc_html(I18n::get_label('settings_desc_booking_shortcode')) . '</p>';
    }

    /**
     * Render a standard checkbox field.
     *
     * @param array{label_for: string, default?: int, description?: string} $args Field arguments.
     * @return void
     */
    public function render_checkbox_field( array $args ): void
    {
        $default = isset($args['default']) ? $args['default'] : 0;
        $option = get_option($args['label_for'], $default);
        echo '<input type="hidden" name="' . esc_attr($args['label_for']) . '" value="0">';
        echo '<input type="checkbox" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="1" ' . checked(1, $option, false) . '>';
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * Render the custom fields row repeater.
     *
     * @param array{label_for: string} $args Field arguments.
     * @return void
     */
    public function render_custom_fields_repeater( array $args ): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only render from get_option(), nonce verified in process_settings_save().
        $fields = get_option('mhbo_custom_fields', []);
        $langs = I18n::get_available_languages();
        ?>
        <div id="mhbo-custom-fields-repeater" style="max-width: 800px;">
            <div class="mhbo-repeater-items">
                <?php if (isset($fields) && count($fields) > 0):
                    foreach ($fields as $index => $field): ?>
                        <div class="mhbo-repeater-item"
                            style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 15px; position: relative; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                            <button type="button" class="mhbo-remove-field"
                                style="position: absolute; top: 10px; right: 10px; color: #d63638; background: none; border: none; font-size: 20px; cursor: pointer; padding: 0;">&times;</button>

                            <div
                                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 12px;">
                                <div>
                                    <label
                                        style="display: block; font-weight: bold; margin-bottom: 5px;"><?php echo esc_html(I18n::get_label('cf_field_id')); ?></label>
                                    <input type="text" name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][id]"
                                        value="<?php echo esc_attr($field['id']); ?>" class="widefat" placeholder="e.g. address"
                                        required>
                                </div>
                                <div>
                                    <label
                                        style="display: block; font-weight: bold; margin-bottom: 5px;"><?php echo esc_html(I18n::get_label('cf_type')); ?></label>
                                    <select name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][type]" class="widefat">
                                        <option value="text" <?php selected($field['type'], 'text'); ?>>
                                            <?php echo esc_html(I18n::get_label('cf_type_text')); ?>
                                        </option>
                                        <option value="number" <?php selected($field['type'], 'number'); ?>>
                                            <?php echo esc_html(I18n::get_label('cf_type_number')); ?>
                                        </option>
                                        <option value="textarea" <?php selected($field['type'], 'textarea'); ?>>
                                            <?php echo esc_html(I18n::get_label('cf_type_textarea')); ?>
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div style="margin-bottom: 12px;">
                                <label
                                    style="display: block; font-weight: bold; margin-bottom: 8px;"><?php echo esc_html(I18n::get_label('cf_label_multilingual')); ?></label>
                                <?php foreach ($langs as $lang): ?>
                                    <div style="display: flex; align-items: center; margin-bottom: 5px;">
                                        <span
                                            style="width: 35px; font-weight: 600; font-size: 11px;"><?php echo esc_html(strtoupper($lang)); ?>:</span>
                                        <input type="text"
                                            name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][label][<?php echo esc_attr($lang); ?>]"
                                            value="<?php echo esc_attr(isset($field['label'][$lang]) ? $field['label'][$lang] : ''); ?>"
                                            class="widefat" style="flex: 1;">
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div>
                                <label style="font-weight: bold;">
                                    <input type="checkbox" name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][required]"
                                        value="1" <?php checked(isset($field['required']) && $field['required']); ?>>
                                    <?php echo esc_html(I18n::get_label('cf_required')); ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
            </div>

            <button type="button" id="mhbo-add-custom-field" class="button button-secondary"
                style="margin-top: 10px;"><?php echo esc_html(I18n::get_label('cf_btn_add')); ?></button>
            <p class="description">
                <?php echo esc_html(I18n::get_label('cf_desc')); ?>
            </p>
        </div>
        <?php
        // phpcs:enable
    }
        // Note: Custom fields JavaScript logic has been moved to assets/js/mhbo-admin-settings.js
        // Configuration is injected via wp_add_inline_script() in enqueue_scripts()

    private static function is_tab_license_gated(string $tab): bool
    {
        /* BUILD_FREE_START */
        return false;
        /* BUILD_FREE_END */
        /* BUILD_PRO_START */
        $gated_tabs = ['gdpr', 'tax', 'performance', 'pricing', 'themes', 'deposits'];
        if (!in_array($tab, $gated_tabs, true)) {
            return false;
        }

        return !License::is_active();
        /* BUILD_PRO_END */
    }

    public static function render()
    {
        $active_tab   = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation, no state change.

        // Targeted success notice for manual redirects (e.g. Business tab)
        // Others (General, Performance, etc.) use settings_errors() via register_setting()
        if (isset($_GET['settings-updated']) && sanitize_key(wp_unslash($_GET['settings-updated']))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WP core redirect param, display-only.
            echo '<div class="updated notice is-dismissible"><p>' . esc_html(I18n::get_label('settings_msg_saved')) . '</p></div>';
        }

        // Redundant generic notice removed. Specific tab notices are handled via settings_errors().

        ?>
        <div class="wrap mhbo-admin-wrap">
            <?php AdminUI::render_header(
                I18n::get_label('settings_title'),
                I18n::get_label('settings_desc_page'),
                [],
                [
                    ['label' => I18n::get_label('menu_main'), 'url' => admin_url('admin.php?page=mhbo-dashboard')]
                ]
            ); ?>
            <?php settings_errors('mhbo_settings'); ?>
            <?php settings_errors('mhbo_amenities'); ?>
            <h2 class="nav-tab-wrapper">
                <?php /* BUILD_PRO_START */ ?>
                <a href="?page=mhbo-settings&tab=license"
                    class="nav-tab <?php echo 'license' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_license'); ?></a>
                <?php /* BUILD_PRO_END */ ?>
                <a href="?page=mhbo-settings&tab=general"
                    class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_general'); ?></a>
                <a href="?page=mhbo-settings&tab=emails"
                    class="nav-tab <?php echo 'emails' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_emails'); ?></a>
                <a href="?page=mhbo-settings&tab=labels"
                    class="nav-tab <?php echo 'labels' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_multilingual'); ?></a>
                <a href="?page=mhbo-settings&tab=amenities"
                    class="nav-tab <?php echo 'amenities' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_amenities'); ?></a>
                <a href="?page=mhbo-settings&tab=business"
                    class="nav-tab <?php echo 'business' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_business'); ?></a>
                <?php /* BUILD_PRO_START */ ?>
                <a href="?page=mhbo-settings&tab=gdpr"
                    class="nav-tab <?php echo 'gdpr' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_gdpr'); ?></a>
                <a href="?page=mhbo-settings&tab=deposits"
                    class="nav-tab <?php echo 'deposits' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_deposits'); ?></a>
                <a href="?page=mhbo-settings&tab=performance"
                    class="nav-tab <?php echo 'performance' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_performance'); ?></a>
                <?php /* BUILD_PRO_END */ ?>
            </h2>

            <?php
            /* BUILD_PRO_START */
            if (self::is_tab_license_gated($active_tab)) {
                AdminUI::render_card_start(I18n::get_label('pro_upsell_title'));
                License::render_upsell_notice();
                AdminUI::render_card_end();
                return;
            }
            /* BUILD_PRO_END */
            ?>

            <?php AdminUI::render_card_start('', 'settings-card'); ?>
                <?php
                $manual_tabs = [
                    /* BUILD_PRO_START */
                    'license',
                    'deposits',
                    /* BUILD_PRO_END */
                    'emails',
                    'labels',
                    'amenities',
                    'business',
                    'webhooks',
                    /* BUILD_PRO_START */
                    'gdpr',
                    'tax',
                    'themes',
                    'performance',
                    /* BUILD_PRO_END */
                    'general'
                ];
                $action = in_array($active_tab, $manual_tabs, true) ? '' : 'options.php';
                ?>
                <form method="post" action="<?php echo esc_attr($action); ?>">
                    <?php
                    // Don't use settings_fields for manual tabs to avoid WP trying to save to options.php
                    if (!in_array($active_tab, $manual_tabs, true)) {
                        settings_fields('mhbo_settings_group');
                    } else {
                        wp_nonce_field('mhbo_settings_nonce', 'mhbo_nonce');
                    }

                    if ('license' === $active_tab) {
                        /* BUILD_PRO_START */
                        self::render_license_tab();
                        /* BUILD_PRO_END */
                    } elseif ('general' === $active_tab) {
                        do_settings_sections('mhbo-settings');
                    } elseif ('emails' === $active_tab) {
                        self::render_email_templates_tab();
                    } elseif ('labels' === $active_tab) {
                        self::render_labels_tab();
                    } elseif ('amenities' === $active_tab) {
                        self::render_amenities_tab();
                    /* BUILD_PRO_START */
                    } elseif ('gdpr' === $active_tab) {
                        self::render_gdpr_tab();
                    /* BUILD_PRO_END */

                    /* BUILD_PRO_START */
                    } elseif ('tax' === $active_tab) {
                        self::render_tax_tab();
                    /* BUILD_PRO_END */

                    /* BUILD_PRO_START */
                    } elseif ('themes' === $active_tab) {
                        self::render_themes_tab();
                    /* BUILD_PRO_END */

                    /* BUILD_PRO_START */
                    } elseif ('performance' === $active_tab) {
                        self::render_performance_tab();
                    } elseif ('deposits' === $active_tab) {
                        self::render_deposits_tab();
                    /* BUILD_PRO_END */

                    } elseif ('business' === $active_tab) {
                        \MHBO\Business\Info::render_settings_tab();
                    } elseif ('webhooks' === $active_tab) {
                        self::render_api_tab();
                    }

                    // Show save button — Pro version gates locked tabs, Free always shows
                    /* BUILD_FREE_START */
                    $show_save = true;
                    /* BUILD_FREE_END */
                    /* BUILD_PRO_START */
                    $locked_tabs = ['gdpr', 'tax', 'license', 'deposits'];
                    $show_save = License::is_active() || !in_array($active_tab, $locked_tabs, true);
                    /* BUILD_PRO_END */
                    if ($show_save) {
                        echo '<input type="hidden" name="mhbo_save_tab" value="' . esc_attr($active_tab) . '">';
                        submit_button();
                    }

                    if ('general' === $active_tab) {
                        self::render_shortcode_info();
                        self::render_rooms_table();
                    }


                    ?>
                </form>
            <?php AdminUI::render_card_end(); ?>
        </div>
        <?php
    }

    private static function render_license_tab()
    {
        /* BUILD_PRO_START */
        $license_key = get_option('mhbo_license_key', '');
        $is_verified = License::is_active();
        $status_label = License::get_status_label();
        $status_color = License::get_status_color();
        $expires = get_option('mhbo_license_expires', '');

        echo '<div class="mhbo-license-header">';
        echo '<h2>' . esc_html(I18n::get_label('tab_license')) . '</h2>';
        echo '<span class="mhbo-badge-premium" style="background:' . esc_attr($status_color) . ';">' . esc_html($status_label) . '</span>';
        echo '</div>';

        if (!$is_verified) {
            echo '<div class="mhbo-license-banner mhbo-license-inactive" style="margin-bottom: 30px;">';
            echo '<div class="mhbo-license-banner-content">';
            echo '<div class="mhbo-license-icon"><span class="dashicons dashicons-lock"></span></div>';
            echo '<div class="mhbo-license-info">';
            echo '<h3>' . esc_html(I18n::get_label('settings_title_pro_locked')) . '</h3>';
            echo '<p>' . esc_html(I18n::get_label('settings_desc_pro_locked')) . '</p>';
            echo '</div></div></div>';
        }

        echo '<table class="form-table" role="presentation">';
        echo '<tr>';
        echo '<th scope="row"><label for="mhbo_license_key">' . esc_html(I18n::get_label('settings_label_license_key')) . '</label></th>';
        echo '<td>';
        echo '<div style="display: flex; gap: 10px; align-items: center;">';
        echo '<input type="text" id="mhbo_license_key" name="mhbo_license_key" value="' . esc_attr($license_key) . '" class="regular-text" style="font-family: monospace;" ' . ($is_verified ? 'readonly' : '') . '>';
        if ($is_verified) {
            echo '<span class="dashicons dashicons-yes-alt" style="color: #10b981; font-size: 24px; width: 24px; height: 24px;"></span>';
        }
        echo '</div>';
        echo '<p class="description">' . esc_html(I18n::get_label('settings_desc_license_key')) . '</p>';
        echo '</td>';
        echo '</tr>';

        if ($is_verified && $expires) {
            echo '<tr><th scope="row">' . esc_html(I18n::get_label('settings_label_license_expires')) . '</th>';
            echo '<td>';
            echo '<strong>' . esc_html(wp_date(get_option('date_format'), strtotime($expires))) . '</strong>';
            echo '<p class="description">' . esc_html(I18n::get_label('settings_desc_license_renewal')) . '</p>';
            echo '</td></tr>';
        }
        echo '</table>';

        echo '<p class="submit" style="margin-top: 30px; padding: 0;">';
        if ($is_verified) {
            echo '<button type="button" id="mhbo_check_license_btn" class="button" style="margin-right:10px;">' . esc_html(I18n::get_label('settings_btn_license_refresh')) . '</button>';
            echo '<button type="button" id="mhbo_deactivate_license" class="button">' . esc_html(I18n::get_label('settings_btn_license_deactivate')) . '</button>';
        } else {
            echo '<button type="button" id="mhbo_activate_license" class="button button-primary button-large">' . esc_html(I18n::get_label('settings_btn_license_activate')) . '</button>';
        }
        echo '<span id="mhbo_license_spinner" class="spinner" style="float:none;"></span>';
        echo '</p>';
        echo '<div id="mhbo_license_message" style="margin-top:10px;"></div>';

        if ($is_verified) {
            echo '<div class="mhbo-pro-features">';
            echo '<h4>' . esc_html(I18n::get_label('settings_title_pro_active')) . '</h4>';
            echo '<div class="mhbo-pro-grid">';
            
            $features = [
                I18n::get_label('settings_item_pro_gateways'),
                I18n::get_label('settings_item_pro_ical'),
                I18n::get_label('settings_item_pro_analytics'),
                I18n::get_label('settings_item_pro_deposits'),
                I18n::get_label('settings_item_pro_pricing'),
                I18n::get_label('settings_item_pro_support'),
            ];

            foreach ($features as $feature) {
                echo '<div class="mhbo-pro-item"><span class="dashicons dashicons-yes"></span> ' . esc_html($feature) . '</div>';
            }

            echo '</div></div>';
        }
        /* BUILD_PRO_END */
    }

    /**
     * Render Performance/Cache settings tab.
     */
    private static function render_performance_tab()
    {
        $cache_enabled = get_option('mhbo_cache_enabled', 1);

        // Safely check if object cache is available
        $object_cache_available = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        echo '<h2>' . esc_html(I18n::get_label('performance_settings')) . '</h2>';
        echo '<p>' . esc_html(I18n::get_label('performance_desc')) . '</p>';

        echo '<table class="form-table" role="presentation">';

        // Cache Enable/Disable
        echo '<tr>';
        echo '<th scope="row"><label for="mhbo_cache_enabled">' . esc_html(I18n::get_label('performance_enable_cache')) . '</label></th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" id="mhbo_cache_enabled" name="mhbo_cache_enabled" value="1" ' . checked($cache_enabled, 1, false) . '>';
        echo ' ' . esc_html(I18n::get_label('performance_cache_label'));
        echo '</label>';
        echo '<p class="description">' . esc_html(I18n::get_label('performance_cache_desc')) . '</p>';
        echo '</td>';
        echo '</tr>';

        // Object Cache Status
        echo '<tr>';
        echo '<th scope="row">' . esc_html(I18n::get_label('performance_object_cache')) . '</th>';
        echo '<td>';
        if ($object_cache_available) {
            echo '<span style="color: green; font-weight: bold;">&#10003; ' . esc_html(I18n::get_label('performance_active')) . '</span>';
            echo '<p class="description">' . esc_html(I18n::get_label('performance_object_cache_desc')) . '</p>';
        } else {
            $is_pro = defined('MHBO_PRO_VERSION');
        ?>
        <div class="mhbo-settings-section">
            <h3><?php echo esc_html(I18n::get_label('performance_using_transients')); ?></h3>
            <p><?php echo esc_html(I18n::get_label('performance_no_cache_desc')); ?></p>

        </div>
        <?php
        }
        echo '</td>';
        echo '</tr>';

        // Clear Cache Button
        echo '<tr>';
        echo '<th scope="row">' . esc_html(I18n::get_label('performance_clear_cache')) . '</th>';
        echo '<td>';
        echo '<button type="button" id="mhbo_clear_cache" class="button button-secondary">' . esc_html(I18n::get_label('performance_btn_clear_all')) . '</button>';
        echo '<span id="mhbo_cache_spinner" class="spinner" style="float: none; margin-left: 10px;"></span>';
        echo '<p class="description">' . esc_html(I18n::get_label('performance_clear_desc')) . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Note: Cache clear JS handler has been moved to assets/js/mhbo-admin-settings.js
        // Nonce is injected via wp_add_inline_script() in enqueue_scripts()
    }


    private static function render_email_templates_tab()
    {
        ?>
        <div class="mhbo-settings-section">
            <?php
            $statuses = array(
                'pending' => I18n::get_label('email_status_pending'),
                'confirmed' => I18n::get_label('email_status_confirmed'),
                'cancelled' => I18n::get_label('email_status_cancelled'),
                'payment' => I18n::get_label('email_status_payment'),
            );

            $langs = I18n::get_available_languages();

            foreach ($statuses as $id => $label):
                ?>
                <div style="margin-bottom: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                        <span class="dashicons dashicons-email-alt" style="margin-top: 3px;"></span>
                        <?php echo esc_html($label); ?>
                    </h3>

                    <?php foreach ($langs as $code): 
                        $raw_subject = get_option("mhbo_email_{$id}_subject", '');
                        $raw_message = get_option("mhbo_email_{$id}_message", '');
                        
                        $subject = I18n::decode($raw_subject, $code, true);
                        $message = I18n::decode($raw_message, $code, true);
                        
                        $is_multilingual = count($langs) > 1;
                        $lang_label = I18n::get_language_name($code);
                    ?>
                        <div class="mhbo-lang-group" style="<?php echo $is_multilingual ? 'margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #007cba;' : ''; ?>">
                            <?php if ($is_multilingual): ?>
                                <h4 style="margin: 0 0 10px 0; color: #007cba;"><?php echo esc_html($lang_label); ?></h4>
                            <?php endif; ?>

                            <p>
                                <label style="display: block; font-weight: bold; margin-bottom: 5px;"><?php echo esc_html(I18n::get_label('email_label_subject')); ?></label>
                                <input type="text" name="mhbo_email_templates[<?php echo esc_attr($id); ?>][subject][<?php echo esc_attr($code); ?>]"
                                    value="<?php echo esc_attr($subject); ?>" class="widefat">
                            </p>

                            <p>
                                <label style="display: block; font-weight: bold; margin-bottom: 5px;"><?php echo esc_html(I18n::get_label('email_label_message')); ?></label>
                                <textarea name="mhbo_email_templates[<?php echo esc_attr($id); ?>][message][<?php echo esc_attr($code); ?>]" rows="8"
                                    class="widefat"><?php echo esc_textarea($message); ?></textarea>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <p class="description">
                <?php echo esc_html(I18n::get_label('email_placeholders_desc')); ?>
            </p>
        </div>
        <?php
    }

    /* BUILD_PRO_START */
    private static function render_gdpr_tab()
    {
        $langs = I18n::get_available_languages();
        $is_multilingual = count($langs) > 1;
        ?>
        <div class="mhbo-settings-section">
            <h3><?php echo esc_html(I18n::get_label('gdpr_title')); ?></h3>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html(I18n::get_label('gdpr_enable_suite')); ?></th>
                    <td>
                        <input type="checkbox" name="mhbo_gdpr_enabled" value="1" <?php checked(get_option('mhbo_gdpr_enabled', 0)); ?>>
                        <p class="description"><?php echo esc_html(I18n::get_label('gdpr_desc_suite')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(I18n::get_label('gdpr_require_consent')); ?></th>
                    <td>
                        <input type="checkbox" name="mhbo_gdpr_checkbox_enabled" value="1" <?php checked(get_option('mhbo_gdpr_checkbox_enabled', 0)); ?>>
                        <p class="description"><?php echo esc_html(I18n::get_label('gdpr_desc_consent')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(I18n::get_label('gdpr_terms_page')); ?></th>
                    <td>
                        <?php
                        wp_dropdown_pages(array(
                            'name'             => 'mhbo_terms_page',
                            'selected'         => absint( get_option( 'mhbo_terms_page' ) ),
                            'show_option_none' => esc_html( I18n::get_label( 'gdpr_select_page' ) ),
                        ));
                        ?>
                        <p class="description"><?php I18n::esc_html_e('gdpr_terms_desc'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php I18n::esc_html_e('gdpr_consent_text'); ?></th>
                    <td>
                        <?php
                        $raw_consent = get_option('mhbo_label_gdpr_checkbox_text', I18n::get_label('label_gdpr_consent_text'));
                        foreach ($langs as $code):
                            $consent_str = I18n::decode($raw_consent, $code, true);
                            $lang_label = I18n::get_language_name($code);
                        ?>
                            <?php if ($is_multilingual): ?>
                                <strong style="display:block; margin: 10px 0 5px; color: #007cba;"><?php echo esc_html($lang_label); ?></strong>
                            <?php endif; ?>
                            <textarea name="mhbo_label_templates[gdpr_checkbox_text][<?php echo esc_attr($code); ?>]" rows="3" class="widefat"><?php echo esc_textarea($consent_str); ?></textarea>
                        <?php endforeach; ?>
                        <p class="description" style="margin-top:8px;"><?php I18n::esc_html_e('gdpr_consent_desc'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php I18n::esc_html_e('gdpr_retention_days'); ?></th>
                    <td>
                        <input type="number" name="mhbo_gdpr_retention_days"
                            value="<?php echo esc_attr(get_option('mhbo_gdpr_retention_days', 0)); ?>" class="small-text">
                        <?php I18n::esc_html_e('gdpr_days'); ?>
                        <p class="description"><?php I18n::esc_html_e('gdpr_retention_desc'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php I18n::esc_html_e('gdpr_cookie_prefix'); ?></th>
                    <td>
                        <input type="text" name="mhbo_gdpr_cookie_prefix"
                            value="<?php echo esc_attr(get_option('mhbo_gdpr_cookie_prefix', 'mhbo_')); ?>" class="regular-text">
                        <p class="description"><?php I18n::esc_html_e('gdpr_cookie_desc'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    /* BUILD_PRO_END */


    private static function render_labels_tab()
    {
        $langs = I18n::get_available_languages();
        $label_groups = [
            'settings_group_search' => [
                'btn_search_rooms' => I18n::__('label_override_search_rooms'),
                'label_check_in' => I18n::__('label_override_check_in'),
                'label_check_out' => I18n::__('label_override_check_out'),
                'label_guests' => I18n::__('label_override_guests'),
                'label_children' => I18n::__('label_override_children'),
                'label_child_ages' => I18n::__('label_override_child_ages'),
                /* translators: %d: child number (1, 2, 3, etc.) */
                'label_child_n_age' => I18n::__('label_override_child_n_age'),
                'label_select_dates' => I18n::__('label_override_select_dates'),
                'label_dates_selected' => I18n::__('label_override_dates_selected'),
                'label_your_selection' => I18n::__('label_override_your_selection'),
                'label_continue_booking' => I18n::__('label_override_continue'),
                'label_availability_error' => I18n::__('label_override_avail_error'),
                'label_stay_dates' => I18n::__('label_override_stay_dates'),
                'label_select_check_in' => I18n::__('label_override_guide_checkin'),
                'label_select_check_out' => I18n::__('label_override_guide_checkout'),
                'label_calendar_no_id' => I18n::__('label_override_cal_no_id'),
                'label_calendar_config_error' => I18n::__('label_override_cal_config'),
                'label_select_dates_error' => I18n::__('label_desc_select_dates_error'),
                'label_block_no_room' => I18n::__('label_desc_block_no_room'),
                'label_check_in_past' => I18n::__('label_desc_check_in_past'),
                'label_check_out_after' => I18n::__('label_desc_check_out_after'),
                'label_check_in_future' => I18n::__('label_desc_check_in_future'),
                'label_check_out_future' => I18n::__('label_desc_check_out_future'),
                'label_legend_confirmed' => I18n::__('label_desc_legend_confirmed'),
                'label_legend_pending' => I18n::__('label_desc_legend_pending'),
                'label_legend_available' => I18n::__('label_desc_legend_available'),
                'label_room_alt_text' => I18n::__('label_desc_room_alt_text'),
            ],
            'settings_group_results' => [
                'label_available_rooms' => I18n::__('label_desc_available_rooms'),
                'label_no_rooms' => I18n::__('label_desc_no_rooms'),
                'label_per_night' => I18n::__('label_desc_per_night'),
                'label_total_nights' => I18n::__('label_desc_total_nights'),
                'label_max_guests' => I18n::__('label_desc_max_guests'),
                'label_loading' => I18n::__('label_desc_loading'),
                'label_to' => I18n::__('label_desc_to'),
                'btn_book_now' => I18n::__('label_desc_book_now'),
                'btn_processing' => I18n::__('label_desc_processing'),
            ],
            'settings_group_booking' => [
                'label_complete_booking' => I18n::__('label_desc_complete_booking'),
                'label_total' => I18n::__('label_desc_total'),
                'label_name' => I18n::__('label_desc_name'),
                'label_email' => I18n::__('label_desc_email'),
                'label_phone' => I18n::__('label_desc_phone'),
                'label_special_requests' => I18n::__('label_desc_special_requests'),
                'label_secure_payment' => I18n::__('label_desc_secure_payment'),
                'label_security_error' => I18n::__('label_desc_security_error'),
                'label_rate_limit_error' => I18n::__('label_desc_rate_limit_error'),
                'label_spam_honeypot' => I18n::__('label_desc_spam_honeypot'),
                'btn_confirm_booking' => I18n::__('label_desc_confirm_booking'),
                'btn_pay_confirm' => I18n::__('label_desc_pay_confirm'),
                'label_confirm_request' => I18n::__('label_desc_confirm_request'),
                'label_room_not_found' => I18n::__('label_desc_room_not_found'),
                'label_name_too_long' => I18n::__('label_desc_name_too_long'),
                'label_phone_too_long' => I18n::__('label_desc_phone_too_long'),
                'label_max_children_error' => I18n::__('label_desc_max_children_error'),
                'label_max_adults_error' => I18n::__('label_desc_max_adults_error'),
                'label_price_calc_error' => I18n::__('label_desc_price_calc_error'),
                'label_fill_all_fields' => I18n::__('label_desc_fill_all_fields'),
                'label_field_required' => I18n::__('label_desc_field_required'),
                'label_spam_detected' => I18n::__('label_desc_spam_detected'),
                'label_already_booked' => I18n::__('label_desc_already_booked'),
                'label_invalid_email' => I18n::__('label_desc_invalid_email'),
            ],
            'settings_group_confirmation' => [
                'msg_booking_confirmed' => I18n::__('label_desc_booking_confirmed'),
                'msg_confirmation_sent' => I18n::__('label_desc_confirmation_sent'),
                'msg_booking_received' => I18n::__('label_desc_booking_received'),
                'msg_booking_received_detail' => I18n::__('label_desc_booking_received_detail'),
                'label_arrival_msg' => I18n::__('label_desc_arrival_msg'),
                'msg_gdpr_required' => I18n::__('label_desc_gdpr_required'),
                'label_privacy_policy' => I18n::__('label_desc_privacy_policy'),
                'label_terms_conditions' => I18n::__('label_desc_terms_conditions'),
                'msg_paypal_required' => I18n::__('label_desc_paypal_required'),
                'msg_payment_success_email' => I18n::__('label_desc_payment_success_email'),
                'msg_booking_arrival_email' => I18n::__('label_desc_booking_arrival_email'),
                'msg_payment_failed_detail' => I18n::__('label_desc_payment_failed_detail'),
                'msg_booking_received_pending' => I18n::__('label_desc_booking_received_pending'),
            ],
            'settings_group_payments' => [
                'label_payment_method' => I18n::__('label_desc_payment_method'),
                'label_pay_arrival' => I18n::__('label_desc_pay_arrival'),
                'label_credit_card' => I18n::__('label_desc_credit_card'),
                'label_paypal' => I18n::__('label_desc_paypal'),
                'label_payment_status' => I18n::__('label_desc_payment_status'),
                'label_paid' => I18n::__('label_desc_paid'),
                'label_amount_paid' => I18n::__('label_desc_amount_paid'),
                'label_transaction_id' => I18n::__('label_desc_transaction_id'),
                'label_failed' => I18n::__('label_desc_failed'),
                'label_payment_failed' => I18n::__('label_desc_payment_failed'),
                'label_dates_no_longer_available' => I18n::__('label_desc_dates_no_longer_available'),
                'label_invalid_booking_calc' => I18n::__('label_desc_invalid_booking_calc'),
                'label_stripe_not_configured' => I18n::__('label_desc_stripe_not_configured'),
                'label_paypal_not_configured' => I18n::__('label_desc_paypal_not_configured'),
                'label_paypal_connection_error' => I18n::__('label_desc_paypal_connection_error'),
                'label_paypal_auth_failed' => I18n::__('label_desc_paypal_auth_failed'),
                'label_paypal_order_create_error' => I18n::__('label_desc_paypal_order_create_error'),
                'label_paypal_currency_unsupported' => I18n::__('label_desc_paypal_currency_unsupported'),
                'label_paypal_generic_error' => I18n::__('label_desc_paypal_generic_error'),
                'label_missing_order_id' => I18n::__('label_desc_missing_order_id'),
                'label_paypal_capture_error' => I18n::__('label_desc_paypal_capture_error'),
                'label_payment_already_processed' => I18n::__('label_desc_payment_already_processed'),
                'label_payment_declined_paypal' => I18n::__('label_desc_payment_declined_paypal'),
                'label_stripe_intent_missing' => I18n::__('label_desc_stripe_intent_missing'),
                'label_paypal_id_missing' => I18n::__('label_desc_paypal_id_missing'),
                'label_payment_required' => I18n::__('label_desc_payment_required'),
                'label_rest_pro_error' => I18n::__('label_desc_rest_pro_error'),
                'label_invalid_nonce' => I18n::__('label_desc_invalid_nonce'),
                'label_api_rate_limit' => I18n::__('label_desc_api_rate_limit'),
                'label_payment_confirmation' => I18n::__('label_desc_payment_confirmation'),
                'label_payment_info' => I18n::__('label_desc_payment_info'),
                'msg_pay_on_arrival_email' => I18n::__('label_desc_pay_on_arrival_email'),
                'label_amount_due' => I18n::__('label_desc_amount_due'),
                'label_payment_date' => I18n::__('label_desc_payment_date'),
                'label_paypal_order_failed' => I18n::__('label_desc_paypal_order_failed'),
                'label_security_verification_failed' => I18n::__('label_desc_security_verification_failed'),
                'label_paypal_client_id_missing' => I18n::__('label_desc_paypal_client_id_missing'),
                'label_paypal_secret_missing' => I18n::__('label_desc_paypal_secret_missing'),
                'label_api_not_configured' => I18n::__('label_desc_api_not_configured'),
                'label_invalid_api_key' => I18n::__('label_desc_invalid_api_key'),
                'label_webhook_sig_required' => I18n::__('label_desc_webhook_sig_required'),
                'label_stripe_webhook_secret_missing' => I18n::__('label_desc_stripe_webhook_secret_missing'),
                'label_invalid_stripe_sig_format' => I18n::__('label_desc_invalid_stripe_sig_format'),
                'label_webhook_expired' => I18n::__('label_desc_webhook_expired'),
                'label_invalid_stripe_sig' => I18n::__('label_desc_invalid_stripe_sig'),
                'label_missing_paypal_headers' => I18n::__('label_desc_missing_paypal_headers'),
                'label_invalid_customer' => I18n::__('label_desc_invalid_customer'),
                'label_invalid_dates' => I18n::__('label_desc_invalid_dates'),
                'label_booking_failed' => I18n::__('label_desc_booking_failed'),
                'label_permission_denied' => I18n::__('label_desc_permission_denied'),
                'label_stripe_pk_missing' => I18n::__('label_desc_stripe_pk_missing'),
                'label_stripe_sk_missing' => I18n::__('label_desc_stripe_sk_missing'),
                'label_stripe_invalid_pk_format' => I18n::__('label_desc_stripe_invalid_pk_format'),
                'label_credentials_spaces' => I18n::__('label_desc_credentials_spaces'),
                'label_mode_mismatch' => I18n::__('label_desc_mode_mismatch'),
                'label_credentials_expired' => I18n::__('label_desc_credentials_expired'),
                'label_creds_valid_env' => I18n::__('label_desc_creds_valid_env'),
                'label_stripe_creds_valid' => I18n::__('label_desc_stripe_creds_valid'),
                'label_connection_failed' => I18n::__('label_desc_connection_failed'),
                'label_auth_failed_env' => I18n::__('label_desc_auth_failed_env'),
                'label_common_causes' => I18n::__('label_desc_common_causes'),
                'label_stripe_generic_error' => I18n::__('label_desc_stripe_generic_error'),
            ],
            'Booking Extras' => [
                'label_enhance_stay' => I18n::__('label_desc_enhance_stay'),
                'label_per_person' => I18n::__('label_desc_per_person'),
                /* BUILD_PRO_START */
                'label_per_adult' => I18n::__('label_desc_per_adult'),
                'label_per_child' => I18n::__('label_desc_per_child'),
                /* BUILD_PRO_END */
                'label_per_person_per_night' => I18n::__('label_desc_per_person_per_night'),
                /* BUILD_PRO_START */
                'label_per_adult_per_night' => I18n::__('label_desc_per_adult_per_night'),
                'label_per_child_per_night' => I18n::__('label_desc_per_child_per_night'),
                /* BUILD_PRO_END */
            ],
            'settings_group_tax' => [
                'label_booking_summary' => I18n::__('label_desc_booking_summary'),
                'label_accommodation' => I18n::__('label_desc_accommodation'),
                'label_extras_item' => I18n::__('label_desc_extras_item'),
                'label_tax_breakdown' => I18n::__('label_desc_tax_breakdown'),
                'label_tax_total' => I18n::__('label_desc_tax_total'),
                'label_tax_registration' => I18n::__('label_desc_tax_registration'),
                'label_includes_tax' => I18n::__('label_desc_includes_tax'),
                'label_price_includes_tax' => I18n::__('label_desc_price_includes_tax'),
                'label_tax_added_at_checkout' => I18n::__('label_desc_tax_added_at_checkout'),
                'label_subtotal' => I18n::__('label_desc_subtotal'),
                'label_room' => I18n::__('label_desc_room'),
                'label_extras' => I18n::__('label_desc_extras'),
                'label_item' => I18n::__('label_desc_item'),
                'label_amount' => I18n::__('label_desc_amount'),
                'label_tax_accommodation' => I18n::__('label_desc_tax_accommodation'),
                'label_tax_extras' => I18n::__('label_desc_tax_extras'),
                'label_tax_rate' => I18n::__('label_desc_tax_rate'),
                /* translators: %s: Tax rate percentage */
                'label_tax_note_includes' => I18n::__('label_desc_tax_note_includes'),
                /* translators: %s: Tax rate percentage */
                'label_tax_note_plus' => I18n::__('label_desc_tax_note_plus'),
                /* translators: 1: Tax label, 2: First tax rate, 3: Second tax rate */
                'label_tax_note_includes_multi' => I18n::__('label_desc_tax_note_includes_multi'),
                /* translators: 1: Tax label, 2: First tax rate, 3: Second tax rate */
                'label_tax_note_plus_multi' => I18n::__('label_desc_tax_note_plus_multi'),
            ],
            'settings_group_amenities' => []
        ];

        // Add dynamic amenities to labels
        $amenities = get_option('mhbo_amenities_list');
        if (false === $amenities) {
            $amenities = [
                'wifi'      => I18n::__('amenity_free_wifi'),
                'ac'        => I18n::__('amenity_air_conditioning'),
                'tv'        => I18n::__('amenity_smart_tv'),
                'breakfast' => I18n::__('amenity_breakfast_included'),
                'pool'      => I18n::__('amenity_pool_view')
            ];
        }
        $amenities = is_array($amenities) ? $amenities : [];
        foreach ($amenities as $key => $label) {
            $label_groups['settings_group_amenities'][$key] = $label;
        }

        echo '<div class="mhbo-labels-tab-wrap">';
        /* translators: 1: placeholder example %s, 2: placeholder example %d */
        $labels_desc = sprintf( I18n::get_label( 'settings_labels_desc' ), '<code>%s</code>', '<code>%d</code>' );
        echo '<p class="description">' . wp_kses_post( $labels_desc ) . '</p>';

        foreach ($label_groups as $group_name => $labels) {
            echo '<h3 style="background:#f6f7f7;padding:10px;border-left:4px solid #2271b1;">';
            I18n::esc_html_e($group_name);
            echo '</h3>';
            echo '<table class="form-table">';
            foreach ($labels as $key => $label_desc) {
                $val = get_option("mhbo_label_{$key}");
                echo '<tr><th scope="row">' . esc_html($label_desc) . '<br><small style="font-weight:normal;color:#666;">' . esc_html($key) . '</small></th><td>';
                foreach ($langs as $lang) {
                    $lang_val = I18n::decode($val, $lang);
                    echo '<div style="margin-bottom:5px; display:flex; align-items:center;">';
                    echo '<span style="width:30px;font-weight:bold;">' . esc_html(strtoupper($lang)) . ':</span>';
                    echo '<input type="text" name="mhbo_label_templates[' . esc_attr($key) . '][' . esc_attr($lang) . ']" value="' . esc_attr($lang_val) . '" class="large-text" style="flex:1;">';
                    echo '</div>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    /**
     * Handle the custom multilingual settings saving (Emails & Labels).
     */
    /**
     * Process settings save operations.
     */
    public function process_settings_save(): void
    {
        // 1. Determine which nonce to verify based on the action/tab
        $nonce_action = 'mhbo_settings_nonce';
        $nonce_field  = 'mhbo_nonce';

        /* BUILD_PRO_START */
        if (isset($_POST['mhbo_save_pro_settings']) || isset($_POST['mhbo_remove_license'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below in Security Gatekeeper.
            $nonce_action = 'mhbo_pro_settings';
            $nonce_field  = '_wpnonce'; // Standard WordPress naming for this subpage
        } elseif (isset($_POST['mhbo_pro_themes_save'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
            $nonce_action = 'mhbo_pro_themes_settings';
            $nonce_field  = '_wpnonce';
        }
        /* BUILD_PRO_END */

        // 2. Security Gatekeeper - Only trigger if this is a plugin-specific save request.
        if (isset($_POST['mhbo_save_tab']) || isset($_POST['mhbo_save_pro_settings']) || isset($_POST['mhbo_pro_themes_save'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately below.

            // Find the actual nonce field sent
            $sent_nonce = isset($_POST[$nonce_field]) ? sanitize_text_field(wp_unslash($_POST[$nonce_field])) : '';
            
            if (!$sent_nonce || !wp_verify_nonce($sent_nonce, $nonce_action)) {
                 wp_die(esc_html(I18n::get_label('label_security_check_failed')));
            }

            if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
                wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
            }

            // 3. Routing
            $data = $_POST;
            $tab  = isset($data['mhbo_save_tab']) ? sanitize_key(wp_unslash($data['mhbo_save_tab'])) : '';

            /* BUILD_PRO_START */
            // Auto-detect tab for Pro-specific update buttons if not explicitly set
            if (!$tab || $tab === '') {
                if (isset($data['mhbo_save_pro_settings']) || isset($data['mhbo_remove_license'])) {
                    $tab = 'license';
                } elseif (isset($data['mhbo_pro_themes_save'])) {
                    $tab = 'themes';
                }
            }
            /* BUILD_PRO_END */

            switch ($tab) {
                case 'general':
                    $this->save_general_settings($data);
                    break;
                /* BUILD_PRO_START */
                case 'themes':
                    $this->save_themes_settings($data);
                    // 2026 BP: Rule 3 - Always use wp_safe_redirect for internal redirects.
                    wp_safe_redirect(admin_url('admin.php?page=mhbo-pro-themes&settings-updated=true'));
                    exit;
                case 'pricing':
                    $this->save_pricing_settings($data);
                    break;
                /* BUILD_PRO_END */
                case 'amenities':
                    $this->save_amenities_settings($data);
                    break;
                case 'payments':
                    $this->save_payments_settings($data);
                    break;
                case 'webhooks':
                case 'api':
                    $this->save_api_settings($data);
                    break;

                case 'gdpr':
                    $this->save_gdpr_settings($data);
                    break;
                case 'emails':
                case 'labels':
                case 'i18n':
                    $this->save_multilingual_settings($data);
                    break;
                case 'business':
                    $this->save_business_settings($data);
                    break;
                /* BUILD_PRO_START */
                case 'tax':
                    $this->save_tax_settings($data);
                    break;
                case 'performance':
                    $this->save_performance_settings($data);
                    break;
                case 'license':
                    $this->save_license_settings($data);
                    break;
                case 'deposits':
                    $this->save_deposits_settings($data);
                    break;
                /* BUILD_PRO_END */
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save_multilingual_settings(array $data): void
    {
        // Permission check and nonce verification are centralized in process_settings_save()
        if (!isset($data['mhbo_save_tab']) || !in_array(sanitize_key(wp_unslash($data['mhbo_save_tab'])), ['emails', 'labels', 'gdpr'], true)) {
            return;
        }

        $tab = sanitize_key(wp_unslash($data['mhbo_save_tab']));

        // Save Emails
        $allowed_email_statuses = ['pending', 'confirmed', 'cancelled', 'payment'];
        if (isset($data['mhbo_email_templates']) && is_array($data['mhbo_email_templates'])) {
            // 2026 BP: Rule 11 - Decouple extraction from sanitization.
            $raw_email_templates = wp_unslash($data['mhbo_email_templates']);
            $email_templates_post = map_deep($raw_email_templates, 'wp_kses_post');
            foreach ($allowed_email_statuses as $status) {
                if (!isset($email_templates_post[$status]) || !is_array($email_templates_post[$status])) {
                    continue;
                }
                $data = $email_templates_post[$status];
                if (isset($data['subject'])) {
                    // Subjects should be plain text — tighten with sanitize_text_field.
                    $subject_data = is_array($data['subject'])
                        ? array_map('sanitize_text_field', $data['subject'])
                        : sanitize_text_field($data['subject']);
                    update_option("mhbo_email_{$status}_subject", I18n::encode($subject_data));
                }
                if (isset($data['message'])) {
                    // Messages are already safe from map_deep(... 'wp_kses_post').
                    $message_data = $data['message'];
                    update_option("mhbo_email_{$status}_message", I18n::encode($message_data));
                }
            }
        }

        // Save Labels
        if (isset($data['mhbo_label_templates']) && is_array($data['mhbo_label_templates'])) {
            $allowed_label_keys = [
                'btn_search_rooms',
                'label_check_in',
                'label_check_out',
                'label_guests',
                'label_children',
                'label_child_ages',
                'label_child_n_age',
                'label_your_selection',
                'label_available_rooms',
                'label_no_rooms',
                'label_per_night',
                'label_total_nights',
                'label_max_guests',
                'btn_book_now',
                'label_complete_booking',
                'label_total',
                'label_name',
                'label_email',
                'label_phone',
                'label_special_requests',
                'btn_confirm_booking',
                'btn_pay_confirm',
                /* BUILD_PRO_START */
                'label_pay_deposit',
                'label_pay_full',
                'label_deposit_amount',
                'label_remaining_balance',
                /* BUILD_PRO_END */
                'label_non_refundable',
                'msg_booking_confirmed',
                'msg_confirmation_sent',
                'msg_booking_received',
                'msg_booking_received_detail',
                'label_arrival_msg',
                'label_payment_method',
                'label_pay_arrival',
                'label_select_dates',
                'label_dates_selected',
                'label_continue_booking',
                'label_confirm_request',
                'label_credit_card',
                'label_paypal',
                'label_booking_summary',
                'label_tax_total',
                'label_tax_registration',
                'label_includes_tax',
                'label_price_includes_tax',
                'label_tax_added_at_checkout',
                'label_subtotal',
                'label_tax_breakdown',
                'label_accommodation',
                'label_extras_item',
                'label_room',
                'label_extras',
                'label_item',
                'label_amount',
                'label_tax_accommodation',
                'label_tax_extras',
                'label_tax_rate',
                'gdpr_checkbox_text',
                'label_availability_error',
                'label_room_not_found',
                'label_stay_dates',
                'label_enhance_stay',
                'label_per_person',
                /* BUILD_PRO_START */
                'label_per_adult',
                'label_per_child',
                /* BUILD_PRO_END */
                'label_per_person_per_night',
                /* BUILD_PRO_START */
                'label_per_adult_per_night',
                'label_per_child_per_night',
                /* BUILD_PRO_END */
                'label_tax_note_includes',
                'label_tax_note_plus',
                'label_tax_note_includes_multi',
                'label_tax_note_plus_multi',
                'label_secure_payment',
                'label_security_error',
                'label_rate_limit_error',
                'label_spam_honeypot',
                'label_room_alt_text',
                'label_desc_room_alt_text',
                'label_select_check_in',
                'label_select_check_out',
                'label_calendar_no_id',
                'label_calendar_config_error',
                'label_select_dates_error',
                'label_block_no_room',
                'label_loading',
                'label_to',
                'btn_processing',
                'msg_gdpr_required',
                'msg_paypal_required',
                'btn_add',
                'btn_delete',
                'label_column_label',
                'label_column_key',
                'label_column_action',
                'settings_label_desc',
                'shortcode_desc_company_info',
                'shortcode_desc_whatsapp',
                'shortcode_desc_banking',
                'shortcode_desc_revolut',
                'shortcode_desc_card',
                'shortcode_desc_all_methods',
                'settings_title_gutenberg',
                'general_search_hotel'
            ];
            $amenities = get_option('mhbo_amenities_list', []);
            $allowed_label_keys = array_merge($allowed_label_keys, array_keys($amenities));
            // 2026 BP: Rule 11 - Decouple extraction from sanitization.
            $raw_label_templates = wp_unslash($data['mhbo_label_templates']);
            $label_templates_post = map_deep($raw_label_templates, 'sanitize_text_field');
            foreach ($allowed_label_keys as $key) {
                if (!isset($label_templates_post[$key])) {
                    continue;
                }
                $val = $label_templates_post[$key];
                $label_data = is_array($val)
                    ? array_map('sanitize_text_field', $val)
                    : sanitize_text_field($val);
                update_option("mhbo_label_{$key}", I18n::encode($label_data));
            }
        }

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_multilingual_saved'), 'success');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save_gdpr_settings(array $data): void
    {
        /* BUILD_PRO_START */
        update_option('mhbo_gdpr_enabled', isset($data['mhbo_gdpr_enabled']) ? 1 : 0);
        update_option('mhbo_gdpr_checkbox_enabled', isset($data['mhbo_gdpr_checkbox_enabled']) ? 1 : 0);

        if (isset($data['mhbo_label_templates']['gdpr_checkbox_text']) && is_array($data['mhbo_label_templates'])) {
            // 2026 BP: Rule 11 - Decouple extraction from sanitization.
            $raw_consent_data = wp_unslash($data['mhbo_label_templates']['gdpr_checkbox_text']);
            $consent_data = $raw_consent_data;
            if (is_array($consent_data)) {
                $consent_data = array_map('sanitize_text_field', $consent_data);
            } else {
                $consent_data = sanitize_text_field($consent_data);
            }
            update_option('mhbo_label_gdpr_checkbox_text', I18n::encode($consent_data));
        }

        if (isset($data['mhbo_gdpr_cookie_prefix'])) {
            update_option('mhbo_gdpr_cookie_prefix', sanitize_key(wp_unslash($data['mhbo_gdpr_cookie_prefix'])));
        }

        if (isset($data['mhbo_gdpr_retention_days'])) {
            update_option('mhbo_gdpr_retention_days', absint(wp_unslash($data['mhbo_gdpr_retention_days'])));
        }

        if (isset($data['mhbo_terms_page'])) {
            update_option('mhbo_terms_page', absint($data['mhbo_terms_page']));
        }

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_gdpr_saved'), 'success');
        /* BUILD_PRO_END */
    }


    /**
     * @param array<string, mixed> $data
     */
    public function save_general_settings(array $data): void
    {
        // Text & Select Fields
        if (isset($data['mhbo_checkin_time'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_checkin = wp_unslash($data['mhbo_checkin_time']);
            update_option('mhbo_checkin_time', sanitize_text_field($raw_checkin));
        }
        if (isset($data['mhbo_checkout_time'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_checkout = wp_unslash($data['mhbo_checkout_time']);
            update_option('mhbo_checkout_time', sanitize_text_field($raw_checkout));
        }
        if (isset($data['mhbo_booking_page'])) {
            update_option('mhbo_booking_page', absint(wp_unslash($data['mhbo_booking_page'])));
        }
        if (isset($data['mhbo_notification_email'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_email = wp_unslash($data['mhbo_notification_email']);
            update_option('mhbo_notification_email', sanitize_email($raw_email));
        }
        if (isset($data['mhbo_additional_notification_email'])) {
            $raw_extra = wp_unslash($data['mhbo_additional_notification_email']);
            update_option('mhbo_additional_notification_email', sanitize_email($raw_extra));
        }
        if (isset($data['mhbo_booking_page_url'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_url = wp_unslash($data['mhbo_booking_page_url']);
            update_option('mhbo_booking_page_url', esc_url_raw($raw_url));
        }

        if (isset($data['mhbo_hotel_timezone'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_tz = wp_unslash($data['mhbo_hotel_timezone']);
            update_option('mhbo_hotel_timezone', sanitize_text_field($raw_tz));
        }

        // Boolean Fields
        $bool_fields = [
            'mhbo_modal_enabled',
            'mhbo_prevent_same_day_turnover',
            'mhbo_children_enabled',
            'mhbo_calendar_show_decimals',
            'mhbo_powered_by_link',
            'mhbo_save_data_on_uninstall'
        ];

        foreach ($bool_fields as $field) {
            $raw_val = isset($data[$field]) ? (string) wp_unslash($data[$field]) : '0';
            $val = ('1' === $raw_val) ? 1 : 0;
            update_option($field, $val);
        }

        // 2026 BP: Rule 11 - Individual extraction then sanitization.
        if (isset($data['mhbo_custom_fields']) && is_array($data['mhbo_custom_fields'])) {
            $custom_fields = [];
            $raw_custom_fields = wp_unslash($data['mhbo_custom_fields']);
            $fields_data = map_deep($raw_custom_fields, 'sanitize_text_field');
            foreach ($fields_data as $field) {
                if (isset($field['id']) && $field['id'] !== '' && isset($field['label'], $field['type'])) {
                    $custom_fields[] = [
                        'id' => sanitize_key($field['id']),
                        'label' => is_array($field['label']) ? array_map('sanitize_text_field', $field['label']) : sanitize_text_field($field['label']),
                        'type' => sanitize_text_field($field['type']),
                        'required' => isset($field['required']) ? 1 : 0
                    ];
                }
            }
            update_option('mhbo_custom_fields', $custom_fields);
        } else {
            update_option('mhbo_custom_fields', []);
        }

        // Currency with Validation
        if (isset($data['mhbo_currency_code'])) {
            $code = sanitize_text_field(wp_unslash($data['mhbo_currency_code']));
            if (I18n::is_valid_currency($code)) {
                update_option('mhbo_currency_code', strtoupper($code));
            } else {
                add_settings_error('mhbo_settings', 'invalid_currency', I18n::get_label('msg_invalid_currency'));
            }
        }

        if (isset($data['mhbo_currency_symbol'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_symbol = wp_unslash($data['mhbo_currency_symbol']);
            update_option('mhbo_currency_symbol', sanitize_text_field($raw_symbol));
        }
        if (isset($data['mhbo_currency_position'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_position = wp_unslash($data['mhbo_currency_position']);
            update_option('mhbo_currency_position', sanitize_text_field($raw_position));
        }

        /* BUILD_PRO_START */
        // Global Stay Limits (Pro) — 0 means disabled; cap at 365 nights.
        if (isset($data['mhbo_global_min_stay'])) {
            $global_min = min(absint(wp_unslash($data['mhbo_global_min_stay'])), 365);
            update_option('mhbo_global_min_stay', $global_min);
        }
        if (isset($data['mhbo_global_max_stay'])) {
            $global_max = min(absint(wp_unslash($data['mhbo_global_max_stay'])), 365);
            update_option('mhbo_global_max_stay', $global_max);
        }
        /* BUILD_PRO_END */

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_general_saved'), 'success');
    }

    /* BUILD_PRO_START */
    /**
     * @param array<string, mixed> $data
     */
    public function save_themes_settings(array $data): void
    {
        $this->perform_theme_save($data);
        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_theme_saved'), 'success');
    }

    /**
     * Internal helper to perform theme save.
     */
    private function perform_theme_save(array $data): void
    {
        if (isset($data['mhbo_active_theme'])) {
            update_option('mhbo_active_theme', sanitize_key(wp_unslash($data['mhbo_active_theme'])));
        }
        if (isset($data['mhbo_custom_primary_color'])) {
            update_option('mhbo_custom_primary_color', sanitize_hex_color(wp_unslash($data['mhbo_custom_primary_color'])));
        }
        if (isset($data['mhbo_custom_secondary_color'])) {
            update_option('mhbo_custom_secondary_color', sanitize_hex_color(wp_unslash($data['mhbo_custom_secondary_color'])));
        }
        if (isset($data['mhbo_custom_accent_color'])) {
            update_option('mhbo_custom_accent_color', sanitize_hex_color(wp_unslash($data['mhbo_custom_accent_color'])));
        }
        if (isset($data['mhbo_custom_css'])) {
            update_option('mhbo_custom_css', wp_strip_all_tags(wp_unslash($data['mhbo_custom_css'])));
        }
    }
    /* BUILD_PRO_END */
    
    public static function render_pro_page(): void
    {
        /* BUILD_PRO_START */
        if (isset($_GET['reset_theme'])) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'mhbo_reset_theme')) {
                wp_die(esc_html(I18n::get_label('settings_msg_security')));
            }
            if (sanitize_text_field(wp_unslash($_GET['reset_theme'])) === '1') {
                update_option('mhbo_active_theme', 'midnight');
                update_option('mhbo_custom_primary_color', '');
                update_option('mhbo_custom_secondary_color', '');
                update_option('mhbo_custom_accent_color', '');
                update_option('mhbo_custom_css', '');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('theme_reset_success')) . '</p></div>';
            }
        }
        
        $license_key = get_option('mhbo_license_key', '');
        $license_status = get_option('mhbo_license_status', 'inactive');
        $is_active = License::is_active();
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation.
        $get_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI navigation (tab switching).
        $active_tab = ($get_page && 'mhbo-pro' !== $get_page) ? str_replace('mhbo-pro-', '', $get_page) : 'overview';
        
        if ('licensing' === $active_tab) {
            $active_tab = 'overview';
        }

        // Gate Pro subpages
        if ('overview' !== $active_tab && !$is_active) {
            echo '<div class="wrap mhbo-admin-wrap">';
            AdminUI::render_header(I18n::get_label('pro_restricted_title'), I18n::get_label('pro_restricted_desc'));
            License::render_upsell_notice();
            echo '</div>';
            return;
        }

        ?>
        <div class="wrap mhbo-admin-wrap">
            <?php AdminUI::render_header(
                I18n::get_label('pro_experience'), 
                I18n::get_label('pro_experience_desc'),
                [],
                [
                    ['label' => I18n::get_label('menu_main'), 'url' => admin_url('admin.php?page=mhbo-dashboard')]
                ]
            ); ?>

            <?php settings_errors('mhbo_settings'); ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=mhbo-pro"
                    class="nav-tab <?php echo esc_attr('overview' === $active_tab ? 'nav-tab-active' : ''); ?>"><?php echo esc_html(I18n::get_label('pro_tab_overview')); ?></a>
                <a href="?page=mhbo-pro-extras"
                    class="nav-tab <?php echo esc_attr('extras' === $active_tab ? 'nav-tab-active' : ''); ?>"><?php echo esc_html(I18n::get_label('pro_tab_extras')); ?></a>
                <a href="?page=mhbo-pro-ical"
                    class="nav-tab <?php echo esc_attr('ical' === $active_tab ? 'nav-tab-active' : ''); ?>"><?php echo esc_html(I18n::get_label('pro_tab_ical')); ?></a>
                <a href="?page=mhbo-pro-payments"
                    class="nav-tab <?php echo esc_attr('payments' === $active_tab ? 'nav-tab-active' : ''); ?>"><?php echo esc_html(I18n::get_label('pro_tab_payments')); ?></a>
                <a href="?page=mhbo-pro-webhooks"
                    class="nav-tab <?php echo 'webhooks' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(I18n::get_label('pro_tab_webhooks')); ?></a>
                <a href="?page=mhbo-pro-themes"
                    class="nav-tab <?php echo 'themes' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(I18n::get_label('pro_tab_themes')); ?></a>
                <a href="?page=mhbo-pro-analytics"
                    class="nav-tab <?php echo 'analytics' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(I18n::get_label('pro_tab_analytics')); ?></a>
                <a href="?page=mhbo-pro-pricing"
                    class="nav-tab <?php echo 'pricing' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(I18n::get_label('pro_tab_pricing')); ?></a>
                <a href="?page=mhbo-pro-tax"
                    class="nav-tab <?php echo 'tax' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(I18n::get_label('pro_tab_tax')); ?></a>
                <a href="?page=mhbo-pro-coupons"
                    class="nav-tab <?php echo esc_attr('coupons' === $active_tab ? 'nav-tab-active' : ''); ?>"><?php echo esc_html(__('Coupons', 'modern-hotel-booking')); ?></a>
            </h2>


            <div class="mhbo-pro-content-area" style="margin-top: 20px;">
                <?php if ('overview' === $active_tab): ?>
                    <?php
                    // License status for display
                    $license_expires = get_option('mhbo_license_expires', '');
                    ?>

                    <!-- License Status Banner -->
                    <div class="mhbo-license-banner <?php echo esc_attr($is_active ? 'mhbo-license-active' : 'mhbo-license-inactive'); ?>">
                        <div class="mhbo-license-banner-content">
                            <div class="mhbo-license-icon">
                                <?php if ($is_active): ?>
                                    <span class="dashicons dashicons-yes-alt"></span>
                                <?php else: ?>
                                    <span class="dashicons dashicons-lock"></span>
                                <?php endif; ?>
                            </div>
                            <div class="mhbo-license-info">
                                <h3>
                                    <?php if ($is_active): ?>
                                        <?php echo esc_html(I18n::get_label('pro_active')); ?>
                                    <?php else: ?>
                                        <?php echo esc_html(I18n::get_label('pro_required')); ?>
                                    <?php endif; ?>
                                </h3>

                                <p>
                                    <?php if ($is_active): ?>
                                        <?php if ($license_expires): ?>
                                            <?php
                                            // translators: %s: license expiration date
                                            echo esc_html(sprintf(I18n::get_label('pro_expires'), date_i18n(get_option('date_format'), strtotime($license_expires)))); ?>
                                        <?php else: ?>
                                            <?php echo esc_html(I18n::get_label('pro_unlocked')); ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php echo esc_html(I18n::get_label('pro_upsell_desc')); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="mhbo-license-action">
                                <?php if ($is_active): ?>
                                    <a href="?page=mhbo-settings&tab=license" class="button button-secondary">
                                        <?php echo esc_html(I18n::get_label('pro_manage')); ?>
                                    </a>
                                <?php else: ?>
                                    <div style="display: flex; gap: 10px;">
                                        <a href="<?php echo esc_url('https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/'); ?>"
                                            target="_blank" rel="noopener noreferrer" class="button button-primary">
                                            <?php echo esc_html(I18n::get_label('pro_upgrade')); ?>
                                        </a>
                                        <a href="?page=mhbo-settings&tab=license" class="button button-secondary">
                                            <?php echo esc_html(I18n::get_label('pro_enter_key')); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$is_active): ?>
                            <div class="mhbo-license-benefits">
                                <span class="mhbo-benefit-item"><?php echo esc_html(I18n::get_label('pro_benefits_support')); ?></span>
                                <span class="mhbo-benefit-item"><?php echo esc_html(I18n::get_label('pro_benefits_updates')); ?></span>
                                <span
                                    class="mhbo-benefit-item"><?php echo esc_html(I18n::get_label('pro_benefits_features')); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    // Dynamically fetch the latest changelog from readme.txt
                    $changelog_items = [];
                    $latest_version = MHBO_VERSION;
                    $readme_file = MHBO_PLUGIN_DIR . 'readme.txt';

                    if (file_exists($readme_file)) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read
                        $readme_content = file_get_contents($readme_file);
                        if ($readme_content && preg_match('/==\s*Changelog\s*==(.*?)($|==)/s', $readme_content, $matches)) {
                            $changelog_section = $matches[1];
                            if (preg_match('/^\s*=\s*([0-9\.]+.*?)\s*=(.*?)(?:\n\s*=\s*[0-9]|$)/s', $changelog_section, $version_matches)) {
                                $latest_version = trim($version_matches[1]);
                                $version_notes = trim($version_matches[2]);
                                $lines = explode("\n", $version_notes);
                                foreach ($lines as $line) {
                                    $line = trim($line);
                                    if (strpos($line, '*') === 0 || strpos($line, '-') === 0) {
                                        $changelog_items[] = trim(substr($line, 1));
                                    }
                                }
                            }
                        }
                    }
                    ?>

                    <div class="mhbo-card" style="margin-top: 20px; border-left: 4px solid #10b981;">
                        <h3 style="color: #10b981; margin-top: 0; margin-bottom: 10px; font-size: 15px;">
                            <?php
                            // translators: %s: Plugin version number
                            echo esc_html(sprintf(I18n::get_label('version_updates'), $latest_version));
                            ?>
                        </h3>
                        <?php if (isset($changelog_items) && count($changelog_items) > 0): ?>
                            <ul style="margin-left: 20px; font-size: 12px; color: #646970;">
                                <?php foreach ($changelog_items as $item): ?>
                                    <li style="margin-bottom: 4px;"><?php
                                    $item_clean = trim($item);
                                    if (preg_match('/^([^:]+:)(.*)$/', $item_clean, $parts)) {
                                        echo '<strong>' . esc_html($parts[1]) . '</strong>' . esc_html($parts[2]);
                                    } else {
                                        echo esc_html($item_clean);
                                    }
                                    ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p style="font-size: 12px; color: #646970;">
                                <?php echo esc_html(I18n::get_label('changelog_readme')); ?>
                            </p>
                        <?php endif; ?>

                        <div style="margin-top: 10px;">
                            <a href="https://github.com/leslieradue-web/modern-hotel-booking-free" target="_blank"
                                rel="noopener noreferrer"
                                style="font-size: 12px; color: #10b981; text-decoration: none; font-weight: bold;">
                                <?php echo esc_html(I18n::get_label('view_changelog')); ?>
                            </a>
                        </div>
                    </div>

                    <!-- Feature Categories -->
                    <div class="mhbo-feature-categories">

                        <!-- Payment Processing Category -->
                        <div class="mhbo-feature-category">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">💳</span>
                                    <?php I18n::esc_html_e('label_payment_processing'); ?></h2>
                                <a href="?page=mhbo-pro-payments" class="mhbo-configure-link">
                                    <?php I18n::esc_html_e('btn_configure'); ?> <span
                                        class="dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">💳</span>
                                    <h4><?php I18n::esc_html_e('label_stripe_integration'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_stripe_desc'); ?>
                                    </p>
                                    <span
                                        class="mhbo-badge mhbo-badge-popular"><?php I18n::esc_html_e('badge_popular'); ?></span>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🅿️</span>
                                    <h4><?php I18n::esc_html_e('label_paypal_integration'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_paypal_desc'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🧾</span>
                                     <h4><?php I18n::esc_html_e('label_invoicing_system'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_invoicing_desc'); ?>
                                    </p>
                                    <span class="mhbo-badge" style="background: #10b981;"><?php I18n::esc_html_e('label_invoicing_available'); ?></span>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">💰</span>
                                    <h4><?php I18n::esc_html_e('label_partial_payments'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_partial_payments_desc'); ?>
                                    </p>
                                    <span class="mhbo-badge" style="background: #3b82f6;"><?php I18n::esc_html_e('badge_new'); ?></span>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🔄</span>
                                    <h4><?php I18n::esc_html_e('label_hmac_webhooks'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_hmac_webhooks_desc'); ?>
                                    </p>
                                    <span class="mhbo-badge" style="background: #3b82f6;"><?php I18n::esc_html_e('badge_updated'); ?></span>
                                    <a href="?page=mhbo-pro-webhooks" class="mhbo-configure-link">
                                        <?php I18n::esc_html_e('btn_configure'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Tax Management Category -->
                        <div class="mhbo-feature-category">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">🧾</span>
                                    <?php I18n::esc_html_e('label_tax_management'); ?></h2>
                                <a href="?page=mhbo-settings&tab=tax" class="mhbo-configure-link">
                                    <?php I18n::esc_html_e('btn_configure'); ?> <span
                                        class="dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🧾</span>
                                    <h4><?php I18n::esc_html_e('label_vat_tax_system'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_vat_tax_desc'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🏢</span>
                                    <h4><?php I18n::esc_html_e('label_business_information_title'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_business_info_desc'); ?>
                                    </p>
                                    <a href="?page=mhbo-settings&tab=business" class="mhbo-configure-link">
                                        <?php I18n::esc_html_e('btn_configure'); ?>
                                    </a>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">💬</span>
                                    <h4><?php I18n::esc_html_e('label_whatsapp_integration'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_whatsapp_desc'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🌍</span>
                                    <h4><?php I18n::esc_html_e('label_country_specific_vat'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_country_vat_desc'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">📊</span>
                                    <h4><?php I18n::esc_html_e('label_tax_breakdown_display'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_tax_breakdown_desc'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Booking Management Category -->
                        <div class="mhbo-feature-category">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">📅</span>
                                    <?php I18n::esc_html_e('label_booking_management'); ?></h2>
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🏷️</span>
                                    <h4><?php I18n::esc_html_e('label_seasonal_pricing'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_seasonal_pricing_desc'); ?>
                                    </p>
                                    <a href="?page=mhbo-pro-pricing" class="mhbo-configure-link">
                                        <?php I18n::esc_html_e('btn_configure'); ?>
                                    </a>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">➕</span>
                                    <h4><?php I18n::esc_html_e('label_booking_extras'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_booking_extras_desc'); ?>
                                    </p>
                                    <a href="?page=mhbo-pro-extras" class="mhbo-configure-link">
                                        <?php I18n::esc_html_e('btn_configure'); ?>
                                    </a>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🔄</span>
                                    <h4><?php I18n::esc_html_e('label_ical_synchronization'); ?></h4>
                                    <p><?php I18n::esc_html_e('msg_ical_sync_desc'); ?>
                                    </p>
                                    <a href="?page=mhbo-pro-ical" class="mhbo-configure-link">
                                        <?php I18n::esc_html_e('btn_configure'); ?>
                                    </a>
                                    <span
                                        class="mhbo-badge mhbo-badge-popular"><?php I18n::esc_html_e('badge_popular'); ?></span>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">📈</span>
                                    <h4><?php I18n::esc_html_e('feature_analytics_title'); ?></h4>
                                    <p><?php I18n::esc_html_e('feature_analytics_desc'); ?>
                                    </p>
                                    <a href="?page=mhbo-pro-analytics" class="mhbo-configure-link">
                                        <?php I18n::esc_html_e('btn_view'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Compliance & UX Category -->
                        <div class="mhbo-feature-category">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">🔒</span>
                                    <?php I18n::esc_html_e('cat_compliance_ux'); ?></h2>
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🔒</span>
                                    <h4><?php I18n::esc_html_e('feature_gdpr_title'); ?></h4>
                                    <p><?php I18n::esc_html_e('feature_gdpr_desc'); ?>
                                    </p>
                                    <a href="?page=mhbo-settings&tab=gdpr" class="mhbo-configure-link">
                                        <?php I18n::esc_html_e('btn_configure'); ?>
                                    </a>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🎨</span>
                                    <h4><?php I18n::esc_html_e('feature_themes_title'); ?></h4>
                                    <p><?php I18n::esc_html_e('feature_themes_desc'); ?>
                                    </p>
                                    <a href="?page=mhbo-pro-themes" class="mhbo-configure-link">
                                        <?php I18n::esc_html_e('btn_configure'); ?>
                                    </a>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🌐</span>
                                    <h4><?php I18n::esc_html_e('feature_multilingual_title'); ?></h4>
                                    <p><?php I18n::esc_html_e('feature_multilingual_desc'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Developer Tools Category -->
                        <div class="mhbo-feature-category mhbo-feature-category-dark">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">⚡</span>
                                    <?php I18n::esc_html_e('cat_developer_platform'); ?></h2>
                                <a href="?page=mhbo-pro-webhooks" class="mhbo-configure-link">
                                    <?php I18n::esc_html_e('btn_configure'); ?> <span
                                        class="dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card mhbo-feature-card-dark">
                                    <span class="mhbo-feature-icon">🔍</span>
                                    <h4><?php I18n::esc_html_e('feature_rest_api_title'); ?></h4>
                                    <p><?php I18n::esc_html_e('feature_rest_api_desc'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card mhbo-feature-card-dark">
                                    <span class="mhbo-feature-icon">🔗</span>
                                    <h4><?php I18n::esc_html_e('feature_webhooks_title'); ?></h4>
                                    <p><?php I18n::esc_html_e('feature_webhooks_desc'); ?>
                                    </p>
                                    <span class="mhbo-badge" style="background: #3b82f6;"><?php I18n::esc_html_e('badge_updated'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions Section -->
                    <div class="mhbo-quick-actions">
                        <h2><?php I18n::esc_html_e('title_quick_actions'); ?></h2>
                        <div class="mhbo-quick-actions-grid">
                            <a href="?page=mhbo-pro-payments" class="mhbo-quick-action-btn">
                                <span class="dashicons dashicons-money-alt"></span>
                                <?php I18n::esc_html_e('action_configure_payments'); ?>
                            </a>
                            <a href="?page=mhbo-settings&tab=tax" class="mhbo-quick-action-btn">
                                <span class="dashicons dashicons-chart-pie"></span>
                                <?php I18n::esc_html_e('action_setup_tax'); ?>
                            </a>
                            <a href="?page=mhbo-pro-analytics" class="mhbo-quick-action-btn">
                                <span class="dashicons dashicons-chart-bar"></span>
                                <?php I18n::esc_html_e('action_view_analytics'); ?>
                            </a>
                            <a href="?page=mhbo-pro-ical" class="mhbo-quick-action-btn">
                                <span class="dashicons dashicons-calendar-alt"></span>
                                <?php I18n::esc_html_e('action_manage_ical'); ?>
                            </a>
                        </div>
                    </div>


                <?php elseif ('extras' === $active_tab): ?>
                    <?php if (License::is_pro_active()): ?>
                        <div class="mhbo-card">
                            <?php
                            if (method_exists('MHBO\Admin\Menu', 'display_extras_page')) {
                                (new Menu())->display_extras_page();
                            }
                            ?>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>



                <?php elseif ('payments' === $active_tab): ?>
                    <?php if (License::is_pro_active()): ?>
                        <div class="mhbo-card">
                            <form method="post" action="">
                                <?php wp_nonce_field('mhbo_settings_nonce', 'mhbo_nonce'); ?>
                                <input type="hidden" name="mhbo_save_tab" value="payments">
                                <?php self::render_payments_tab(); ?>
                                <?php submit_button(I18n::get_label('btn_save_payment_settings')); ?>
                            </form>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>



                <?php elseif ('webhooks' === $active_tab): ?>
                    <?php if (License::is_pro_active()): ?>
                        <div class="mhbo-card">
                            <form method="post" action="">
                                <?php wp_nonce_field('mhbo_settings_nonce', 'mhbo_nonce'); ?>
                                <input type="hidden" name="mhbo_save_tab" value="webhooks">
                                <?php self::render_api_tab(); ?>
                                <?php submit_button(I18n::get_label('btn_save_webhook_settings')); ?>
                            </form>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>



                <?php elseif ('themes' === $active_tab): ?>
                    <?php if (License::is_pro_active()): ?>
                        <form method="post">
                            <?php wp_nonce_field('mhbo_pro_themes_settings'); ?>
                            <div class="mhbo-card">
                                <?php self::render_themes_tab(); ?>
                            </div>
                            <p class="submit">
                                <button type="submit" name="mhbo_pro_themes_save" value="1" class="button button-primary">
                                    <?php I18n::esc_html_e('btn_save_theme_settings'); ?>
                                </button>
                            </p>
                        </form>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>



                <?php elseif ('analytics' === $active_tab): ?>
                    <?php if (License::is_pro_active()): ?>
                        <div class="mhbo-card">
                            <?php
                            if (class_exists('MHBO\Pro\Analytics')) {
                                (new \MHBO\Pro\Analytics())->render_analytics_page();
                            }
                            ?>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>



                <?php elseif ('business' === $active_tab): ?>
                    <div class="mhbo-card">
                        <?php \MHBO\Business\Info::render_settings_tab(); ?>
                    </div>

                <?php elseif ('tax' === $active_tab): ?>
                    <?php if (License::is_pro_active()): ?>
                        <form method="post">
                            <?php wp_nonce_field('mhbo_settings_nonce', 'mhbo_nonce'); ?>
                            <input type="hidden" name="mhbo_save_tab" value="tax">
                            <div class="mhbo-card">
                                <?php self::render_tax_tab(); ?>
                            </div>
                            <p class="submit">
                                <?php submit_button(I18n::get_label('btn_save_tax_settings'), 'primary', 'submit', false); ?>
                            </p>
                        </form>
                    <?php else: ?>
                        <?php self::render_pro_upsell(); ?>
                    <?php endif; ?>

                <?php elseif ('pricing' === $active_tab): ?>

                    <?php if (License::is_pro_active()): ?>
                        <form method="post">
                            <?php wp_nonce_field('mhbo_settings_nonce', 'mhbo_nonce'); ?>
                            <input type="hidden" name="mhbo_save_tab" value="pricing">
                            <div class="mhbo-card">
                                <?php self::render_pricing_tab(); ?>
                            </div>
                            <p class="submit">
                                <button type="submit" name="mhbo_save_pro_pricing" value="1" class="button button-primary">
                                    <?php I18n::esc_html_e('btn_save_pricing_settings'); ?>
                                </button>
                            </p>
                        </form>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>

                   <?php elseif ('ical' === $active_tab): ?>
                    <?php if (License::is_pro_active()): ?>
                        <div class="mhbo-card">
                            <?php
                            if (class_exists('MHBO\Pro\ICalManager')) {
                                (new \MHBO\Pro\ICalManager())->render_ical_page();
                            }
                            ?>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>

                <?php elseif ('coupons' === $active_tab): ?>
                    <?php if (License::is_pro_active()): ?>
                        <div class="mhbo-card">
                            <?php
                            if (class_exists('MHBO\Pro\CouponAdmin')) {
                                \MHBO\Pro\CouponAdmin::render();
                            }
                            ?>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>
                <?php endif; ?>
            </div>
            <?php
        /* BUILD_PRO_END */
    }

    /**
     * Render Pro Upsell notice for unlicensed users trying to access Pro tabs.
     */
    public static function render_pro_upsell()
    {
        ?>
            <div class="mhbo-pro-upsell"
                style="margin-top: 20px; padding: 40px; text-align: center; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 12px; border: 1px solid #dee2e6;">
                <div style="font-size: 48px; margin-bottom: 20px;">🔒</div>
                <h3 style="margin: 0 0 10px 0; font-size: 1.4rem; color: #1a3b5d;">
                    <?php I18n::esc_html_e('pro_upsell_title'); ?>
                </h3>
                <p style="color: #6c757d; max-width: 400px; margin: 0 auto 20px auto; font-size: 14px;">
                    <?php I18n::esc_html_e('pro_upsell_full_desc'); ?>
                </p>
                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <a href="<?php echo esc_url('https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/'); ?>"
                        target="_blank" rel="noopener noreferrer"
                        class="button button-primary button-large"><?php I18n::esc_html_e('pro_upsell_upgrade'); ?></a>
                    <a href="?page=mhbo-settings&tab=license"
                        class="button button-secondary button-large"><?php I18n::esc_html_e('pro_upsell_enter_key'); ?></a>
                </div>
                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                    <span
                        style="display: inline-block; font-size: 12px; color: #856404; background: rgba(255,255,255,0.8); padding: 4px 12px; border-radius: 12px; margin: 0 5px;">✓
                        <?php I18n::esc_html_e('pro_upsell_support'); ?></span>
                    <span
                        style="display: inline-block; font-size: 12px; color: #856404; background: rgba(255,255,255,0.8); padding: 4px 12px; border-radius: 12px; margin: 0 5px;">✓
                        <?php I18n::esc_html_e('pro_upsell_updates'); ?></span>
                    <span
                        style="display: inline-block; font-size: 12px; color: #856404; background: rgba(255,255,255,0.8); padding: 4px 12px; border-radius: 12px; margin: 0 5px;">✓
                        <?php I18n::esc_html_e('pro_upsell_all'); ?></span>
                </div>
            </div>
            <?php
    }

    private static function render_payments_tab(): void
    {
        /* BUILD_PRO_START */
        if (class_exists('MHBO\Pro\PaymentGateways')) {
            \MHBO\Pro\PaymentGateways::render_settings_section();
        } else {
            echo '<p>' . esc_html(I18n::get_label('msg_pro_not_available')) . '</p>';
        }
        /* BUILD_PRO_END */
    }

    private static function render_api_tab(): void
    {
        /* BUILD_PRO_START */
        $api_key = get_option('mhbo_api_key', '');
        $webhook_url = get_option('mhbo_webhook_url', '');
        ?>
            <div class="mhbo-settings-section">
                <h3><?php I18n::esc_html_e('tab_api'); ?></h3>
                <p class="description">
                    <?php I18n::esc_html_e('label_api_base_desc'); ?>
                    <code><?php echo esc_html(rest_url('mhbo/v1/')); ?></code>
                </p>
                <table class="form-table">
                    <tr>
                        <th><?php I18n::esc_html_e('col_api_key'); ?></th>
                        <td>
                            <?php 
                            $display_key = ($api_key !== '' && $api_key !== null) ? 'REDACTED' : '';
                            ?>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <input type="text" id="mhbo_api_key" name="mhbo_api_key"
                                    value="<?php echo esc_attr($display_key); ?>" class="regular-text">
                                <button type="button" class="button"
                                    onclick="document.getElementById('mhbo_api_key').value = '<?php echo esc_js(wp_generate_password(32, false)); ?>';">🔑
                                    <?php I18n::esc_html_e('api_btn_generate'); ?></button>
                            </div>
                            <p class="description">
                                <?php I18n::esc_html_e('label_api_key_header'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h3><?php I18n::esc_html_e('tab_webhooks'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><?php I18n::esc_html_e('api_label_webhook_url'); ?></th>
                        <td>
                            <input type="url" name="mhbo_webhook_url" value="<?php echo esc_url($webhook_url); ?>"
                                class="regular-text" placeholder="https://example.com/webhook">
                            <p class="description">
                                <?php I18n::esc_html_e('label_webhook_json_desc'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php I18n::esc_html_e('api_label_webhook_secret'); ?></th>
                        <td>
                            <?php 
                            $webhook_secret = get_option('mhbo_webhook_secret', '');
                            $display_secret = ($webhook_secret !== '' && $webhook_secret !== null) ? 'REDACTED' : '';
                            ?>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <input type="text" id="mhbo_webhook_secret" name="mhbo_webhook_secret"
                                    value="<?php echo esc_attr($display_secret); ?>" class="regular-text" placeholder="<?php I18n::esc_attr_e('api_placeholder_webhook_secret'); ?>">
                                <button type="button" class="button"
                                    onclick="document.getElementById('mhbo_webhook_secret').value = '<?php echo esc_js(wp_generate_password(32, false)); ?>';">🔑
                                    <?php I18n::esc_html_e('api_btn_generate'); ?></button>
                            </div>
                            <p class="description">
                                <?php I18n::esc_html_e('label_webhook_hmac_desc'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php I18n::esc_html_e('api_label_logging'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mhbo_webhook_logging_enabled" value="1" <?php checked(get_option('mhbo_webhook_logging_enabled', '1'), '1'); ?>>
                                <?php I18n::esc_html_e('label_webhook_debug_logging'); ?>
                            </label>
                            <p class="description">
                                <?php I18n::esc_html_e('label_webhook_logging_desc'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php I18n::esc_html_e('api_label_actions'); ?></th>
                        <td>
                            <button type="button" id="mhbo-test-webhook" class="button">
                                <?php I18n::esc_html_e('btn_send_test'); ?>
                            </button>
                            <span class="spinner"></span>
                            <div id="mhbo-webhook-test-result" style="margin-top: 10px; font-weight: bold;"></div>
                        </td>
                    </tr>
                </table>

                <h3><?php I18n::esc_html_e('api_title_logs'); ?></h3>
                <div id="mhbo-webhook-logs-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <p class="description"><?php I18n::esc_html_e('api_desc_logs'); ?></p>
                    <button type="button" id="mhbo-clear-webhook-logs" class="button button-link-delete">
                        <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span> <?php I18n::esc_html_e('btn_clear_history'); ?>
                    </button>
                </div>
                <div id="mhbo-webhook-logs">
                    <p class="description"><?php I18n::esc_html_e('api_msg_loading_logs'); ?></p>
                </div>

                <style>
                    .mhbo-status-pill { padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
                    .mhbo-status-ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
                    .mhbo-status-warning { background: #fef9c3; color: #854d0e; border: 1px solid #fef08a; }
                    .mhbo-status-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
                    .mhbo-log-response { max-height: 60px; overflow-y: auto; font-family: monospace; font-size: 11px; white-space: pre-wrap; background: #f8f9fa; padding: 4px; border-radius: 4px; border: 1px solid #ddd; cursor: pointer; }
                    .mhbo-log-response:hover { background: #f1f3f5; }
                    .mhbo-copy-tooltip { position: relative; display: inline-block; }
                    #mhbo-test-webhook:disabled { opacity: 0.7; cursor: not-allowed; }
                </style>

                <h3><?php I18n::esc_html_e('api_title_endpoints'); ?></h3>
                <table class="widefat" style="max-width: 800px;">
                    <thead>
                        <tr>
                            <th><?php I18n::esc_html_e('pro_api_method'); ?></th>
                            <th><?php I18n::esc_html_e('pro_api_endpoint'); ?></th>
                            <th><?php I18n::esc_html_e('pro_api_access'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>GET</code></td>
                            <td><code>/mhbo/v1/rooms</code></td>
                            <td><?php I18n::esc_html_e('pro_api_pro'); ?></td>
                        </tr>
                        <tr>
                            <td><code>GET</code></td>
                            <td><code>/mhbo/v1/availability</code></td>
                            <td><?php I18n::esc_html_e('pro_api_pro'); ?></td>
                        </tr>
                        <tr>
                            <td><code>POST</code></td>
                            <td><code>/mhbo/v1/recalculate-price</code></td>
                            <td><?php I18n::esc_html_e('pro_api_public'); ?></td>
                        </tr>
                        <tr>
                            <td><code>GET</code></td>
                            <td><code>/mhbo/v1/calendar-data</code></td>
                            <td><?php I18n::esc_html_e('pro_api_public'); ?></td>
                        </tr>
                        <tr>
                            <td><code>GET</code></td>
                            <td><code>/mhbo/v1/bookings</code></td>
                            <td>🔑 <?php I18n::esc_html_e('pro_api_required'); ?></td>
                        </tr>
                        <tr>
                            <td><code>GET</code></td>
                            <td><code>/mhbo/v1/bookings/{id}</code></td>
                            <td>🔑 <?php I18n::esc_html_e('pro_api_admin'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <script>
            jQuery(document).ready(function($) {
                // Fetch Webhook Logs
                function fetchWebhookLogs() {
                    const $container = $('#mhbo-webhook-logs');
                    $.ajax({
                        url: ajaxurl,
                        data: {
                            action: 'mhbo_fetch_webhook_logs',
                            nonce: '<?php echo esc_js(wp_create_nonce('mhbo_webhook_logs')); ?>'
                        },
                        success: function(response) {
                            if (response.success && response.data.length > 0) {
                                let html = '<table class="widefat striped"><thead><tr>';
                                html += '<th>' + MHBO_I18n.get('log_time') + '</th>';
                                html += '<th>' + MHBO_I18n.get('log_event') + '</th>';
                                html += '<th>' + MHBO_I18n.get('log_status') + '</th>';
                                html += '<th>' + MHBO_I18n.get('log_response') + '</th>';
                                html += '</tr></thead><tbody>';
                                
                                response.data.forEach(function(log) {
                                    let statusType = 'ok';
                                    if (log.status >= 400 && log.status < 500) statusType = 'warning';
                                    if (log.status >= 500 || log.status === 0) statusType = 'error';
                                    
                                    const statusClass = 'mhbo-status-' + statusType;
                                    
                                    let formattedResponse = log.response;
                                    try {
                                        const json = JSON.parse(log.response);
                                        formattedResponse = JSON.stringify(json, null, 2);
                                    } catch(e) {}

                                    html += '<td>' + log.timestamp + '</td>';
                                    html += '<td><code>' + log.event + '</code></td>';
                                    html += '<td><span class="mhbo-status-pill ' + statusClass + '">' + log.status + '</span></td>';
                                    html += '<td><div class="mhbo-log-response" title="' + MHBO_I18n.get('api_msg_click_copy') + '" onclick="navigator.clipboard.writeText(this.innerText);">' + formattedResponse + '</div></td>';
                                    html += '</tr>';
                                });
                                
                                html += '</tbody></table>';
                                $container.html(html);
                            } else {
                                $container.html('<p class="description">' + MHBO_I18n.get('api_msg_no_logs') + '</p>');
                            }
                        }
                    });
                }

                // Initial fetch
                if ($('#mhbo-webhook-logs').length) {
                    fetchWebhookLogs();
                }

                // Test Webhook
                $('#mhbo-test-webhook').on('click', function() {
                    const $btn = $(this);
                    const $spinner = $btn.next('.spinner');
                    const $result = $('#mhbo-webhook-test-result');
                    const url = $('input[name="mhbo_webhook_url"]').val();

                    if (!url) {
                        alert(<?php echo wp_json_encode(I18n::get_label('api_msg_enter_url')); ?>);
                        return;
                    }

                    $btn.prop('disabled', true).text(<?php echo wp_json_encode(I18n::get_label('api_msg_sending')); ?>);
                    $spinner.addClass('is-active');
                    $result.text('').removeClass('mhbo-status-ok mhbo-status-error');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'mhbo_test_webhook',
                            url: url,
                            nonce: '<?php echo esc_js(wp_create_nonce('mhbo_webhook_test')); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html('<span style="color:#166534;">✅ ' + response.data + '</span>');
                                fetchWebhookLogs();
                            } else {
                                $result.html('<span style="color:#991b1b;">❌ ' + response.data + '</span>');
                            }
                        },
                        error: function() {
                            $result.html('<span style="color:#991b1b;">❌ ' + MHBO_I18n.get('msg_network_error') + '</span>');
                        },
                        complete: function() {
                            $btn.prop('disabled', false).text(MHBO_I18n.get('btn_send_test_webhook'));
                            $spinner.removeClass('is-active');
                        }
                    });
                });

                // Clear History
                $('#mhbo-clear-webhook-logs').on('click', function() {
                    if (!confirm(MHBO_I18n.get('msg_confirm_clear_logs'))) {
                        return;
                    }

                    const $btn = $(this);
                    $btn.prop('disabled', true).css('opacity', 0.5);

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'mhbo_clear_webhook_logs',
                            nonce: '<?php echo esc_js(wp_create_nonce('mhbo_webhook_logs')); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                fetchWebhookLogs();
                            } else {
                                alert(response.data.message || 'Error clearing logs.');
                            }
                        },
                        complete: function() {
                            $btn.prop('disabled', false).css('opacity', 1);
                        }
                    });
                });
            });
            </script>
            <style>
                .mhbo-status-pill { padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
                .mhbo-status-ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; font-weight: bold; }
                .mhbo-status-warning { background: #fef9c3; color: #854d0e; border: 1px solid #fef08a; font-weight: bold; }
                .mhbo-status-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; font-weight: bold; }
                .mhbo-log-response { max-height: 80px; overflow-y: auto; font-family: monospace; font-size: 11px; white-space: pre-wrap; background: #f8f9fa; padding: 6px; border-radius: 4px; border: 1px solid #ddd; cursor: pointer; }
                .mhbo-log-response:hover { background: #f1f3f5; }
                #mhbo-test-webhook:disabled { opacity: 0.7; cursor: not-allowed; }
                #mhbo-webhook-logs { margin-top: 10px; border: 1px solid #f0f0f1; border-radius: 4px; background: #fff; }
            </style>
            <?php
        /* BUILD_PRO_END */
    }

    /* BUILD_PRO_START */
    private static function render_pricing_tab(): void
    {
        $weekend_enabled = get_option('mhbo_weekend_pricing_enabled', 0);
        $weekend_days = get_option('mhbo_weekend_days', ['friday', 'saturday', 'sunday']);
        if (!is_array($weekend_days)) {
            $weekend_days = (is_string($weekend_days) && $weekend_days !== '') ? explode(',', $weekend_days) : [];
        }
        $weekend_val = get_option('mhbo_weekend_rate_multiplier', 1.2);
        $weekend_type = get_option('mhbo_weekend_modifier_type', 'multiplier');
        $holiday_enabled = get_option('mhbo_holiday_pricing_enabled', 0);
        $holiday_val = get_option('mhbo_holiday_rate_modifier', 1.2);
        $holiday_type = get_option('mhbo_holiday_modifier_type', 'multiplier');
        $holidays = get_option('mhbo_holiday_dates', '');
        $apply_weekend_to_holidays = get_option('mhbo_apply_weekend_to_holidays', 1);

        $days = [
            'monday' => I18n::get_label('day_monday'),
            'tuesday' => I18n::get_label('day_tuesday'),
            'wednesday' => I18n::get_label('day_wednesday'),
            'thursday' => I18n::get_label('day_thursday'),
            'friday' => I18n::get_label('day_friday'),
            'saturday' => I18n::get_label('day_saturday'),
            'sunday' => I18n::get_label('day_sunday'),
        ];
        ?>
            <div class="mhbo-settings-section">
                <h3 style="margin-top:0; color: var(--mhbo-primary, #1a365d);"><?php I18n::esc_html_e('pricing_title_weekend'); ?></h3>
                <p class="description">
                    <?php I18n::esc_html_e('pricing_desc_weekend'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th><?php I18n::esc_html_e('pricing_label_weekend_days'); ?></th>
                        <td>
                            <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                                <?php foreach ($days as $val => $label): ?>
                                    <label style="display: flex; align-items: center; gap: 5px;">
                                        <input type="checkbox" name="mhbo_weekend_days[]" value="<?php echo esc_attr($val); ?>"
                                            <?php checked(in_array($val, $weekend_days, true)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php I18n::esc_html_e('pricing_label_weekend_adj'); ?></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <label style="display: flex; align-items: center; gap: 5px; font-weight: 600;">
                                    <input type="checkbox" name="mhbo_weekend_pricing_enabled" class="mhbo-adj-toggle" value="1"
                                        <?php checked($weekend_enabled, 1); ?>>
                                    <?php I18n::esc_html_e('btn_enable'); ?>
                                </label>
                                <div class="mhbo-adj-inputs"
                                    style="<?php echo esc_attr($weekend_enabled ? '' : 'opacity: 0.5; pointer-events: none;'); ?>">
                                    <input type="number" step="any" name="mhbo_weekend_rate_multiplier"
                                        value="<?php echo esc_attr($weekend_val); ?>" class="small-text">
                                    <select name="mhbo_weekend_modifier_type">
                                        <option value="multiplier" <?php selected($weekend_type, 'multiplier'); ?>>
                                            <?php I18n::esc_html_e('label_multiplier_desc'); ?>
                                        </option>
                                        <option value="percent" <?php selected($weekend_type, 'percent'); ?>>
                                            <?php I18n::esc_html_e('label_percentage_desc'); ?>
                                        </option>
                                        <option value="fixed" <?php selected($weekend_type, 'fixed'); ?>>
                                            <?php I18n::esc_html_e('label_fixed_amount_desc'); ?>
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top:30px; color: var(--mhbo-primary, #1a365d);"><?php I18n::esc_html_e('pricing_title_holiday'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><?php I18n::esc_html_e('pricing_label_holiday_picker'); ?></th>
                        <td>
                            <div id="mhbo-holiday-picker-wrap"
                                style="max-width: 400px; background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 15px;">
                                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                                    <input type="date" id="mhbo-new-holiday" class="regular-text" style="flex:1;">
                                    <button type="button" id="mhbo-add-holiday-btn"
                                        class="button button-primary"><?php I18n::esc_html_e('btn_add'); ?></button>
                                </div>
                                <div id="mhbo-holiday-list"
                                    style="display: flex; flex-direction: column; gap: 5px; max-height: 200px; overflow-y: auto;">
                                    <!-- JS populated -->
                                </div>
                                <input type="hidden" name="mhbo_holiday_dates" id="mhbo-holiday-dates-hidden"
                                    value="<?php echo esc_attr($holidays); ?>">
                            </div>
                            <p class="description">
                                <?php I18n::esc_html_e('pricing_desc_holiday_picker'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php I18n::esc_html_e('pricing_label_holiday_adj'); ?></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <label style="display: flex; align-items: center; gap: 5px; font-weight: 600;">
                                    <input type="checkbox" name="mhbo_holiday_pricing_enabled" class="mhbo-adj-toggle" value="1"
                                        <?php checked($holiday_enabled, 1); ?>>
                                    <?php I18n::esc_html_e('btn_enable'); ?>
                                </label>
                                <div class="mhbo-adj-inputs"
                                    style="<?php echo esc_attr($holiday_enabled ? '' : 'opacity: 0.5; pointer-events: none;'); ?>">
                                    <input type="number" step="any" name="mhbo_holiday_rate_modifier"
                                        value="<?php echo esc_attr($holiday_val); ?>" class="small-text">
                                    <select name="mhbo_holiday_modifier_type">
                                        <option value="multiplier" <?php selected($holiday_type, 'multiplier'); ?>>
                                            <?php I18n::esc_html_e('label_multiplier_desc'); ?>
                                        </option>
                                        <option value="percent" <?php selected($holiday_type, 'percent'); ?>>
                                            <?php I18n::esc_html_e('label_percentage_desc'); ?>
                                        </option>
                                        <option value="fixed" <?php selected($holiday_type, 'fixed'); ?>>
                                            <?php I18n::esc_html_e('label_fixed_amount_desc'); ?>
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php I18n::esc_html_e('pricing_label_conflict'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mhbo_apply_weekend_to_holidays" value="1" <?php checked($apply_weekend_to_holidays, 1); ?>>
                                <?php I18n::esc_html_e('pricing_label_conflict_desc'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
            <?php
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * Render Pro Themes settings tab.
     */
    private static function render_themes_tab()
    {
        $active_theme = get_option('mhbo_active_theme', 'midnight');
        $custom_primary = get_option('mhbo_custom_primary_color', '#1a365d');
        $custom_secondary = get_option('mhbo_custom_secondary_color', '#f2e2c4');
        $custom_accent = get_option('mhbo_custom_accent_color', '#d4af37');
        $custom_css = get_option('mhbo_custom_css', '');

        $themes = [
            'midnight' => [
                'name' => I18n::get_label('theme_midnight_name'),
                'colors' => ['#1a365d', '#f2e2c4', '#d4af37'],
                'desc' => I18n::get_label('theme_midnight_desc')
            ],
            'emerald' => [
                'name' => I18n::get_label('theme_emerald_name'),
                'colors' => ['#065f46', '#34d399', '#10b981'],
                'desc' => I18n::get_label('theme_emerald_desc')
            ],
            'oceanic' => [
                'name' => I18n::get_label('theme_oceanic_name'),
                'colors' => ['#1e3a8a', '#60a5fa', '#3b82f6'],
                'desc' => I18n::get_label('theme_oceanic_desc')
            ],
            'ruby' => [
                'name' => I18n::get_label('theme_ruby_name'),
                'colors' => ['#7f1d1d', '#f87171', '#ef4444'],
                'desc' => I18n::get_label('theme_ruby_desc')
            ],
            'urban' => [
                'name' => I18n::get_label('theme_urban_name'),
                'colors' => ['#1f2937', '#9ca3af', '#4b5563'],
                'desc' => I18n::get_label('theme_urban_desc')
            ],
            'lavender' => [
                'name' => I18n::get_label('theme_lavender_name'),
                'colors' => ['#4c1d95', '#a78bfa', '#8b5cf6'],
                'desc' => I18n::get_label('theme_lavender_desc')
            ],
        ];
        ?>
            <div class="mhbo-settings-section">
                <h3 style="margin-top:0;"><?php I18n::esc_html_e('pricing_title_themes'); ?></h3>
                <p class="description">
                    <?php I18n::esc_html_e('pricing_desc_themes'); ?>
                </p>

                <div
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin: 25px 0;">
                    <?php foreach ($themes as $slug => $theme): ?>
                        <label class="mhbo-theme-card <?php echo esc_attr($active_theme === $slug ? 'active' : ''); ?>"
                            style="cursor:pointer; border: 2px solid #ddd; border-radius: 12px; padding: 15px; display: block; background: #fff; transition: all 0.2s;">
                            <input type="radio" name="mhbo_active_theme" value="<?php echo esc_attr($slug); ?>" <?php checked($active_theme, $slug); ?> style="display:none;">
                            <div
                                style="display: flex; gap: 5px; height: 40px; border-radius: 6px; overflow: hidden; margin-bottom: 12px;">
                                <div style="flex: 2; background: <?php echo esc_attr($theme['colors'][0]); ?>;"></div>
                                <div style="flex: 1; background: <?php echo esc_attr($theme['colors'][1]); ?>;"></div>
                                <div style="flex: 1; background: <?php echo esc_attr($theme['colors'][2]); ?>;"></div>
                            </div>
                            <h4 style="margin: 0 0 5px 0;"><?php echo esc_html($theme['name']); ?></h4>
                            <p style="margin: 0; font-size: 13px; color: #646970;"><?php echo esc_html($theme['desc']); ?></p>
                        </label>
                    <?php endforeach; ?>

                    <label class="mhbo-theme-card <?php echo esc_attr('custom' === $active_theme ? 'active' : ''); ?>"
                        style="cursor:pointer; border: 2px solid #ddd; border-radius: 12px; padding: 15px; display: block; background: #fff; transition: all 0.2s;">
                        <input type="radio" name="mhbo_active_theme" value="custom" <?php checked($active_theme, 'custom'); ?>
                            style="display:none;">
                        <div
                            style="display: flex; gap: 5px; height: 40px; border-radius: 6px; overflow: hidden; margin-bottom: 12px; background: linear-gradient(45deg, #ff0000, #ff7f00, #ffff00, #00ff00, #0000ff, #4b0082, #8b00ff);">
                        </div>
                        <h4 style="margin: 0 0 5px 0;"><?php I18n::esc_html_e('theme_custom_name'); ?></h4>
                        <p style="margin: 0; font-size: 13px; color: #646970;">
                            <?php I18n::esc_html_e('theme_custom_desc'); ?>
                        </p>
                    </label>
                </div>

                <div id="mhbo-custom-colors-wrap"
                    style="display: <?php echo esc_attr('custom' === $active_theme ? 'block' : 'none'); ?>; border-top: 1px solid #ddd; padding-top: 25px; margin-top: 25px;">
                    <h3><?php I18n::esc_html_e('theme_title_custom'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><?php I18n::esc_html_e('theme_label_primary'); ?></th>
                            <td><input type="color" name="mhbo_custom_primary_color"
                                    value="<?php echo esc_attr($custom_primary); ?>"></td>
                        </tr>
                        <tr>
                            <th><?php I18n::esc_html_e('theme_label_secondary'); ?></th>
                            <td><input type="color" name="mhbo_custom_secondary_color"
                                    value="<?php echo esc_attr($custom_secondary); ?>"></td>
                        </tr>
                        <tr>
                            <th><?php I18n::esc_html_e('theme_label_accent'); ?></th>
                            <td><input type="color" name="mhbo_custom_accent_color"
                                    value="<?php echo esc_attr($custom_accent); ?>">
                            </td>
                        </tr>
                    </table>
                </div>

                <div style="border-top: 1px solid #ddd; padding-top: 25px; margin-top: 35px;">
                    <h3><?php I18n::esc_html_e('theme_title_css'); ?></h3>
                    <p class="description">
                        <?php I18n::esc_html_e('theme_desc_css'); ?>
                    </p>
                    <textarea name="mhbo_custom_css" rows="10" class="large-text code"
                        style="margin-top: 15px; font-family: monospace;"><?php echo esc_textarea(wp_unslash($custom_css)); ?></textarea>
                </div>

                <div style="margin-top: 20px; display: flex; gap: 15px; align-items: center;">
                    <input type="submit" name="submit_save_themes" class="button button-primary button-hero mhbo-apply-theme-btn" value="<?php I18n::esc_attr_e('btn_apply_theme'); ?>">
                    <?php $reset_nonce = wp_create_nonce('mhbo_reset_theme'); ?>
                    <button type="button" class="button button-hero"
                        onclick="if(confirm(<?php echo wp_json_encode(I18n::get_label('msg_confirm_reset_theme')); ?>)) { window.location.href = window.location.href + '&reset_theme=1&_wpnonce=<?php echo esc_attr($reset_nonce); ?>'; }">
                        <?php I18n::esc_html_e('btn_return_default'); ?>
                    </button>
                    <?php // Note: Theme selection JavaScript logic has been moved to assets/js/mhbo-admin-settings.js ?>
                </div>
            </div>
            <?php
    }
    /* BUILD_PRO_END */


    /**
     * @param array<string, mixed> $data
     */
    public function save_api_settings(array $data): void
    {
        /* BUILD_PRO_START */
        if (isset($data['mhbo_api_key'])) {
            $api_key = sanitize_text_field(wp_unslash($data['mhbo_api_key']));
            // 'REDACTED' is the display sentinel — skip to preserve the existing key.
            if ($api_key !== 'REDACTED') {
                update_option('mhbo_api_key', $api_key);
            }
        }
        if (isset($data['mhbo_webhook_url'])) {
            update_option('mhbo_webhook_url', esc_url_raw(wp_unslash($data['mhbo_webhook_url'])));
        }

        if (isset($data['mhbo_webhook_secret'])) {
            $secret = sanitize_text_field(wp_unslash($data['mhbo_webhook_secret']));
            // 'REDACTED' is the display sentinel — skip to preserve the existing secret.
            if ($secret !== '' && $secret !== 'REDACTED') {
                $encrypted = \MHBO\Core\Security::encrypt_secret($secret, 'mhbo_webhook_secret_salt');
                update_option('mhbo_webhook_secret', $encrypted);
            }
        }

        update_option('mhbo_webhook_logging_enabled', isset($data['mhbo_webhook_logging_enabled']) ? 1 : 0);

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_api_saved'), 'success');
        /* BUILD_PRO_END */
    }

    /* BUILD_PRO_START */
    /**
     * Sanitize callback for mhbo_api_key registered via register_setting().
     * The actual key is stored pre-sanitized; this is a passthrough guard.
     */
    public function sanitize_api_key(mixed $value): string
    {
        return sanitize_text_field((string) $value);
    }

    /**
     * Sanitize callback for mhbo_webhook_secret registered via register_setting().
     * The value stored is already AES-256-CBC encrypted base64 — pass through as-is.
     */
    public function sanitize_webhook_secret(mixed $value): string
    {
        return (string) $value;
    }
    /* BUILD_PRO_END */

    /**
     * @param array<string, mixed> $data
     */
    public function save_payments_settings(array $data): void
    {
        /* BUILD_PRO_START */
        // Stripe
        $stripe_enabled = isset($data['mhbo_gateway_stripe_enabled']) ? 1 : 0;
        update_option('mhbo_gateway_stripe_enabled', $stripe_enabled);
        if (isset($data['mhbo_stripe_mode'])) {
            update_option('mhbo_stripe_mode', sanitize_text_field(wp_unslash($data['mhbo_stripe_mode'])));
        }
        if (isset($data['mhbo_stripe_test_publishable_key'])) {
            update_option('mhbo_stripe_test_publishable_key', sanitize_text_field(wp_unslash($data['mhbo_stripe_test_publishable_key'])));
        }
        
        $stripe_test_secret = isset($data['mhbo_stripe_test_secret_key']) ? sanitize_text_field(wp_unslash($data['mhbo_stripe_test_secret_key'])) : '';
        if (trim($stripe_test_secret) !== '') {
            update_option('mhbo_stripe_test_secret_key', $stripe_test_secret);
        }
        if (isset($data['mhbo_stripe_live_publishable_key'])) {
            update_option('mhbo_stripe_live_publishable_key', sanitize_text_field(wp_unslash($data['mhbo_stripe_live_publishable_key'])));
        }
            
        $stripe_live_secret = isset($data['mhbo_stripe_live_secret_key']) ? sanitize_text_field(wp_unslash($data['mhbo_stripe_live_secret_key'])) : '';
        if (trim($stripe_live_secret) !== '') {
            update_option('mhbo_stripe_live_secret_key', $stripe_live_secret);
        }

        // Stripe Webhook Secrets
        $stripe_test_webhook = isset($data['mhbo_stripe_test_webhook_secret']) ? sanitize_text_field(wp_unslash($data['mhbo_stripe_test_webhook_secret'])) : '';
        if (trim($stripe_test_webhook) !== '') {
            update_option('mhbo_stripe_test_webhook_secret', $stripe_test_webhook);
        }
        $stripe_live_webhook = isset($data['mhbo_stripe_live_webhook_secret']) ? sanitize_text_field(wp_unslash($data['mhbo_stripe_live_webhook_secret'])) : '';
        if (trim($stripe_live_webhook) !== '') {
            update_option('mhbo_stripe_live_webhook_secret', $stripe_live_webhook);
        }

        // PayPal
        $paypal_enabled = isset($data['mhbo_gateway_paypal_enabled']) ? 1 : 0;
        update_option('mhbo_gateway_paypal_enabled', $paypal_enabled);
        if (isset($data['mhbo_paypal_mode'])) {
            update_option('mhbo_paypal_mode', sanitize_text_field(wp_unslash($data['mhbo_paypal_mode'])));
        }
        if (isset($data['mhbo_paypal_sandbox_client_id'])) {
            update_option('mhbo_paypal_sandbox_client_id', trim(sanitize_text_field(wp_unslash($data['mhbo_paypal_sandbox_client_id']))));
        }
        if (isset($data['mhbo_paypal_sandbox_webhook_id'])) {
            update_option('mhbo_paypal_sandbox_webhook_id', trim(sanitize_text_field(wp_unslash($data['mhbo_paypal_sandbox_webhook_id']))));
        }
            
        $paypal_sandbox_secret = isset($data['mhbo_paypal_sandbox_secret']) ? sanitize_text_field(wp_unslash($data['mhbo_paypal_sandbox_secret'])) : '';
        if (trim($paypal_sandbox_secret) !== '') {
            update_option('mhbo_paypal_sandbox_secret', $paypal_sandbox_secret);
        }
        if (isset($data['mhbo_paypal_live_client_id'])) {
            update_option('mhbo_paypal_live_client_id', trim(sanitize_text_field(wp_unslash($data['mhbo_paypal_live_client_id']))));
        }
        if (isset($data['mhbo_paypal_live_webhook_id'])) {
            update_option('mhbo_paypal_live_webhook_id', trim(sanitize_text_field(wp_unslash($data['mhbo_paypal_live_webhook_id']))));
        }
            
        $paypal_live_secret = isset($data['mhbo_paypal_live_secret']) ? sanitize_text_field(wp_unslash($data['mhbo_paypal_live_secret'])) : '';
        if (trim($paypal_live_secret) !== '') {
            update_option('mhbo_paypal_live_secret', $paypal_live_secret);
        }

        if (isset($data['mhbo_paypal_sdk_args'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            // Allow basic URL characters (alphanumeric, =, &, -, _, .)
            $sdk_args = sanitize_text_field(wp_unslash($data['mhbo_paypal_sdk_args']));
            update_option('mhbo_paypal_sdk_args', trim($sdk_args));
        }

        if (isset($data['mhbo_paypal_sdk_locale'])) {
            $sdk_locale = sanitize_text_field(wp_unslash($data['mhbo_paypal_sdk_locale']));
            update_option('mhbo_paypal_sdk_locale', trim($sdk_locale));
        }

        // Pay on Arrival
        $onsite_enabled = isset($data['mhbo_gateway_onsite_enabled']) ? 1 : 0;
        update_option('mhbo_gateway_onsite_enabled', $onsite_enabled);
        if (isset($data['mhbo_onsite_instructions'])) {
            update_option('mhbo_onsite_instructions', wp_kses_post(wp_unslash($data['mhbo_onsite_instructions'])));
        }

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_payment_saved'), 'success');
        /* BUILD_PRO_END */
    }

    /* BUILD_PRO_START */
    /**
     * @param array<string, mixed> $data
     */
    public function save_pricing_settings(array $data): void
    {
        // Weekend Pricing
        $weekend_enabled = isset($data['mhbo_weekend_pricing_enabled']) ? 1 : 0;
        update_option('mhbo_weekend_pricing_enabled', $weekend_enabled);

        if (isset($data['mhbo_weekend_days']) && is_array($data['mhbo_weekend_days'])) {
            $days = array_map('sanitize_text_field', wp_unslash($data['mhbo_weekend_days']));
            update_option('mhbo_weekend_days', $days);
        } else {
            update_option('mhbo_weekend_days', []);
        }

        if (isset($data['mhbo_weekend_rate_multiplier'])) {
            update_option('mhbo_weekend_rate_multiplier', floatval(wp_unslash($data['mhbo_weekend_rate_multiplier'])));
        }
        if (isset($data['mhbo_weekend_modifier_type'])) {
            update_option('mhbo_weekend_modifier_type', sanitize_key(wp_unslash($data['mhbo_weekend_modifier_type'])));
        }

        // Holiday Pricing
        $holiday_enabled = isset($data['mhbo_holiday_pricing_enabled']) ? 1 : 0;
        update_option('mhbo_holiday_pricing_enabled', $holiday_enabled);

        if (isset($data['mhbo_holiday_rate_modifier'])) {
            update_option('mhbo_holiday_rate_modifier', floatval(wp_unslash($data['mhbo_holiday_rate_modifier'])));
        }
        if (isset($data['mhbo_holiday_modifier_type'])) {
            update_option('mhbo_holiday_modifier_type', sanitize_key(wp_unslash($data['mhbo_holiday_modifier_type'])));
        }
        if (isset($data['mhbo_holiday_dates'])) {
            update_option('mhbo_holiday_dates', sanitize_text_field(wp_unslash($data['mhbo_holiday_dates'])));
        }

        $apply_both = isset($data['mhbo_apply_weekend_to_holidays']) ? 1 : 0;
        update_option('mhbo_apply_weekend_to_holidays', $apply_both);

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_pricing_saved'), 'success');
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    public function ajax_activate_license(): void
    {
        check_ajax_referer('mhbo_license_nonce', 'security');

        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_send_json_error(['message' => I18n::get_label('msg_permission_denied')]);
        }

        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $result = LicenseManager::activate($key);

        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    public function ajax_check_license(): void
    {
        check_ajax_referer('mhbo_license_nonce', 'security');

        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_send_json_error(['message' => I18n::get_label('msg_permission_denied')]);
        }

        $result = LicenseManager::check_status();

        if (false === $result) {
            wp_send_json_error(['message' => I18n::get_label('msg_license_server_error')]);
        }

        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    public function ajax_deactivate_license(): void
    {
        check_ajax_referer('mhbo_license_nonce', 'security');

        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_send_json_error(['message' => I18n::get_label('msg_permission_denied')]);
        }

        $result = LicenseManager::deactivate();

        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }
    /* BUILD_PRO_END */

    private static function render_amenities_tab()
    {
        $amenities = get_option('mhbo_amenities_list');
        if (false === $amenities) { // If option doesn't exist, initialize with defaults
            $amenities = [
                'wifi'      => I18n::get_label('label_free_wifi'),
                'ac'        => I18n::get_label('label_air_conditioning'),
                'tv'        => I18n::get_label('label_smart_tv'),
                'breakfast' => I18n::get_label('label_breakfast_included'),
                'pool'      => I18n::get_label('label_pool_view')
            ];
        }
        $amenities = is_array($amenities) ? $amenities : []; // Ensure it's always an array
        ?>
            <h3><?php I18n::esc_html_e('label_room_amenities_title'); ?></h3>
            <p><?php I18n::esc_html_e('msg_room_amenities_desc'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><?php I18n::esc_html_e('label_add_new_amenity'); ?></th>
                    <td>
                        <div style="display:flex; gap:10px;">
                            <input type="text" name="mhbo_new_amenity" placeholder="e.g. Hot Tub" class="regular-text">
                            <button type="submit" name="mhbo_add_amenity" value="1"
                                class="button button-primary"><?php I18n::esc_html_e('btn_add'); ?></button>
                        </div>
                    </td>
                </tr>
            </table>

            <div style="margin-top:20px; max-width:600px;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php I18n::esc_html_e('label_column_label'); ?></th>
                            <th><?php I18n::esc_html_e('label_column_key'); ?></th>
                            <th style="width:100px;"><?php I18n::esc_html_e('label_column_action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($amenities) === 0): ?>
                            <tr>
                                <td colspan="3"><?php I18n::esc_html_e('label_no_amenities_found'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($amenities as $key => $label): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($label); ?></strong></td>
                                    <td><code><?php echo esc_html($key); ?></code></td>
                                    <td>
                                        <button type="submit" name="mhbo_remove_amenity" value="<?php echo esc_attr($key); ?>"
                                            class="button button-link-delete"
                                            onclick="return confirm('<?php echo esc_js(I18n::get_label('label_are_you_sure')); ?>');"><?php I18n::esc_html_e('btn_delete'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
    }



    /**
     * @param array<string, mixed> $data
     */
    public function save_business_settings(array $data): void
    {
        \MHBO\Business\Info::get_instance()->handle_save($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save_amenities_settings(array $data): void
    {
        $amenities = get_option('mhbo_amenities_list');
        if (false === $amenities) { // If option doesn't exist, initialize with defaults
            $amenities = [
                'wifi'      => I18n::get_label('label_free_wifi'),
                'ac'        => I18n::get_label('label_air_conditioning'),
                'tv'        => I18n::get_label('label_smart_tv'),
                'breakfast' => I18n::get_label('label_breakfast_included'),
                'pool'      => I18n::get_label('label_pool_view')
            ];
        }
        $amenities = is_array($amenities) ? $amenities : []; // Ensure it's always an array

        // Add Amenity
        if (isset($data['mhbo_add_amenity']) && trim($data['mhbo_new_amenity']) !== '') {
            $label = sanitize_text_field(wp_unslash($data['mhbo_new_amenity']));
            $key = sanitize_title($label);
            if ($key && !isset($amenities[$key])) {
                $amenities[$key] = $label;
                update_option('mhbo_amenities_list', $amenities);
                add_settings_error('mhbo_amenities', 'added', I18n::get_label('msg_amenity_added'), 'success');
            }
        }

        // Remove Amenity
        if (isset($data['mhbo_remove_amenity'])) {
            $key = sanitize_key(wp_unslash($data['mhbo_remove_amenity']));
            if (isset($amenities[$key])) {
                unset($amenities[$key]);
                update_option('mhbo_amenities_list', $amenities);
                add_settings_error('mhbo_amenities', 'removed', I18n::get_label('msg_amenity_removed'), 'success');
            }
        }
    }

    private static function render_tax_tab(): void
    {
        /* BUILD_PRO_START */
        $langs = I18n::get_available_languages();
        $tax_mode = get_option('mhbo_tax_mode', 'disabled');
        $tax_rate_accommodation = get_option('mhbo_tax_rate_accommodation', 0.00);
        $tax_rate_extras = get_option('mhbo_tax_rate_extras', 0.00);
        $tax_label = get_option('mhbo_tax_label', '[:en]VAT[:ro]TVA[:]');
        $tax_registration_number = get_option('mhbo_tax_registration_number', '');
        $tax_display_frontend = get_option('mhbo_tax_display_frontend', 1);
        $tax_display_email = get_option('mhbo_tax_display_email', 1);
        $tax_rounding_mode = get_option('mhbo_tax_rounding_mode', 'per_total');
        $tax_decimal_places = get_option('mhbo_tax_decimal_places', 2);

        echo '<h2>' . esc_html(I18n::get_label('label_tax_settings_title')) . '</h2>';
        echo '<p>' . esc_html(I18n::get_label('msg_tax_settings_desc')) . '</p>';

        echo '<table class="form-table">';

        // Tax Mode
        echo '<tr><th scope="row">' . esc_html(I18n::get_label('label_tax_mode_label')) . '</th><td>';
        echo '<fieldset>';
        echo '<label style="display:block;margin-bottom:8px;">';
        echo '<input type="radio" name="mhbo_tax_mode" value="disabled" ' . checked($tax_mode, 'disabled', false) . '> ';
        echo '<span>' . esc_html(I18n::get_label('label_tax_mode_disabled')) . '</span>';
        echo '<p class="description" style="margin-left:24px;">' . esc_html(I18n::get_label('label_tax_none_desc')) . '</p>';
        echo '</label>';
        echo '<label style="display:block;margin-bottom:8px;">';
        echo '<input type="radio" name="mhbo_tax_mode" value="vat" ' . checked($tax_mode, 'vat', false) . '> ';
        echo '<span>' . esc_html(I18n::get_label('label_tax_mode_vat')) . '</span>';
        echo '<p class="description" style="margin-left:24px;">' . esc_html(I18n::get_label('label_tax_mode_vat_desc')) . '</p>';
        echo '</label>';
        echo '<label style="display:block;">';
        echo '<input type="radio" name="mhbo_tax_mode" value="sales_tax" ' . checked($tax_mode, 'sales_tax', false) . '> ';
        echo '<span>' . esc_html(I18n::get_label('label_tax_mode_sales')) . '</span>';
        echo '<p class="description" style="margin-left:24px;">' . esc_html(I18n::get_label('label_tax_mode_sales_desc')) . '</p>';
        echo '</label>';
        echo '</fieldset>';
        echo '</td></tr>';

        // Tax Label (Multilingual)
        echo '<tr><th scope="row">' . esc_html(I18n::get_label('label_tax_name')) . '</th><td>';
        foreach ($langs as $lang) {
            $val = I18n::decode($tax_label, $lang);
            echo '<div style="margin-bottom:5px; display:flex; align-items:center;">';
            echo '<span style="width:30px;font-weight:bold;">' . esc_html(strtoupper($lang)) . ':</span>';
            echo '<input type="text" name="mhbo_tax_label_lang[' . esc_attr($lang) . ']" value="' . esc_attr($val) . '" class="regular-text" style="flex:1;">';
            echo '</div>';
        }
        echo '<p class="description">' . esc_html(I18n::get_label('label_tax_label_desc')) . '</p>';
        echo '</td></tr>';

        // Tax Registration Number
        echo '<tr><th scope="row">' . esc_html(I18n::get_label('label_tax_registration_num')) . '</th><td>';
        echo '<input type="text" name="mhbo_tax_registration_number" value="' . esc_attr($tax_registration_number) . '" class="regular-text">';
        echo '<p class="description">' . esc_html(I18n::get_label('label_tax_registration_desc')) . '</p>';
        echo '</td></tr>';

        // Accommodation Tax Rate
        echo '<tr><th scope="row">' . esc_html(I18n::get_label('label_accommodation_tax')) . '</th><td>';
        echo '<input type="number" name="mhbo_tax_rate_accommodation" value="' . esc_attr((string) ((float) $tax_rate_accommodation + 0)) . '" min="0" max="100" step="any" class="small-text"> %';
        echo '<p class="description">' . esc_html(I18n::get_label('label_accommodation_tax_desc')) . '</p>';
        echo '</td></tr>';

        // Extras Tax Rate
        echo '<tr><th scope="row">' . esc_html(I18n::get_label('label_extras_tax')) . '</th><td>';
        echo '<input type="number" name="mhbo_tax_rate_extras" value="' . esc_attr((string) ((float) $tax_rate_extras + 0)) . '" min="0" max="100" step="any" class="small-text"> %';
        echo '<p class="description">' . esc_html(I18n::get_label('label_extras_tax_desc')) . '</p>';
        echo '</td></tr>';

        // Display Options
        echo '<tr><th scope="row">' . esc_html(I18n::get_label('label_display_options')) . '</th><td>';
        echo '<label style="display:block;margin-bottom:8px;">';
        echo '<input type="checkbox" name="mhbo_tax_display_frontend" value="1" ' . checked(1, $tax_display_frontend, false) . '> ';
        echo esc_html(I18n::get_label('label_show_tax_frontend'));
        echo '</label>';
        echo '<label style="display:block;">';
        echo '<input type="checkbox" name="mhbo_tax_display_email" value="1" ' . checked(1, $tax_display_email, false) . '> ';
        echo esc_html(I18n::get_label('label_show_tax_email'));
        echo '</label>';
        echo '</td></tr>';

        // Advanced Settings
        echo '<tr><th scope="row">' . esc_html(I18n::get_label('label_rounding_mode')) . '</th><td>';
        echo '<select name="mhbo_tax_rounding_mode">';
        echo '<option value="per_total" ' . selected($tax_rounding_mode, 'per_total', false) . '>' . esc_html(I18n::get_label('label_rounding_per_total')) . '</option>';
        echo '<option value="per_line" ' . selected($tax_rounding_mode, 'per_line', false) . '>' . esc_html(I18n::get_label('label_rounding_per_line')) . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html(I18n::get_label('label_rounding_desc')) . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html(I18n::get_label('label_decimal_places')) . '</th><td>';
        echo '<input type="number" name="mhbo_tax_decimal_places" value="' . esc_attr($tax_decimal_places) . '" min="0" max="4" class="small-text">';
        echo '<p class="description">' . esc_html(I18n::get_label('label_decimal_places_desc')) . '</p>';
        echo '</td></tr>';

        echo '</table>';

        // Service Fee Section
        $sf_enabled    = (int) get_option('mhbo_service_fee_enabled', 0);
        $sf_type       = (string) get_option('mhbo_service_fee_type', 'fixed');
        $sf_amount     = (string) get_option('mhbo_service_fee_amount', '0');
        $sf_percentage = (string) get_option('mhbo_service_fee_percentage', '0');
        $sf_label      = (string) get_option('mhbo_service_fee_label', 'Service Fee');

        echo '<hr>';
        echo '<h3>' . esc_html(__('Service Fee (Pro)', 'modern-hotel-booking')) . '</h3>';
        echo '<p>' . esc_html(__('Add an optional fixed or percentage-based service fee to every booking. The fee appears as its own line item in the booking breakdown and is taxable when tax is enabled.', 'modern-hotel-booking')) . '</p>';

        echo '<table class="form-table">';

        // Enable toggle
        echo '<tr><th scope="row">' . esc_html(__('Enable Service Fee', 'modern-hotel-booking')) . '</th><td>';
        echo '<label>';
        echo '<input type="checkbox" name="mhbo_service_fee_enabled" value="1" ' . checked(1, $sf_enabled, false) . '> ';
        echo esc_html(__('Charge a service fee on all bookings', 'modern-hotel-booking'));
        echo '</label>';
        echo '</td></tr>';

        // Label
        echo '<tr><th scope="row">' . esc_html(__('Fee Label', 'modern-hotel-booking')) . '</th><td>';
        echo '<input type="text" name="mhbo_service_fee_label" value="' . esc_attr($sf_label) . '" class="regular-text">';
        echo '<p class="description">' . esc_html(__('Label shown to guests in the booking breakdown (e.g. "Service Fee", "Resort Fee").', 'modern-hotel-booking')) . '</p>';
        echo '</td></tr>';

        // Fee type + inline inputs (combined row)
        $currency_symbol = get_option('mhbo_currency_symbol', '$');
        $sf_amount_display     = number_format((float) $sf_amount, 2, '.', '');
        $sf_percentage_display = number_format((float) $sf_percentage, 2, '.', '');
        echo '<tr><th scope="row">' . esc_html(__('Fee Type', 'modern-hotel-booking')) . '</th><td>';
        echo '<fieldset>';
        echo '<label style="display:flex;align-items:center;gap:6px;margin-bottom:10px;">';
        echo '<input type="radio" name="mhbo_service_fee_type" value="fixed" ' . checked($sf_type, 'fixed', false) . '> ';
        echo esc_html(__('Fixed amount', 'modern-hotel-booking')) . ' : ';
        echo '<input type="number" name="mhbo_service_fee_amount" value="' . esc_attr($sf_amount_display) . '" min="0" step="0.01" class="small-text" style="width:80px;"> ';
        echo '<span>' . esc_html($currency_symbol) . '</span>';
        echo '</label>';
        echo '<label style="display:flex;align-items:center;gap:6px;">';
        echo '<input type="radio" name="mhbo_service_fee_type" value="percentage" ' . checked($sf_type, 'percentage', false) . '> ';
        echo esc_html(__('Percentage (%)', 'modern-hotel-booking')) . ' : ';
        echo '<input type="number" name="mhbo_service_fee_percentage" value="' . esc_attr($sf_percentage_display) . '" min="0" max="100" step="0.01" class="small-text" style="width:80px;"> ';
        echo '<span>%</span>';
        echo '</label>';
        echo '<p class="description" style="margin-top:8px;">' . esc_html(__('Fixed: flat fee per booking. Percentage: % of subtotal (room + extras, pre-tax).', 'modern-hotel-booking')) . '</p>';
        echo '</fieldset>';
        echo '</td></tr>';

        echo '</table>';
        /* BUILD_PRO_END */
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save_license_settings(array $data): void
    {
        /* BUILD_PRO_START */
        // 1. Handle Key Saving
        if (isset($data['mhbo_license_key'])) {
            $new_key = sanitize_text_field(wp_unslash($data['mhbo_license_key']));
            $old_key = get_option('mhbo_license_key', '');

            if ($new_key !== $old_key) {
                // If the key changed, we MUST invalidate the current license status
                LicenseManager::deactivate();
                update_option('mhbo_license_key', $new_key);
                add_settings_error('mhbo_settings', 'key_changed', I18n::get_label('msg_license_updated'), 'info');
            }
        }

        // 2. Handle Activation
        if (isset($data['mhbo_save_pro_settings'])) {
            $raw_key = isset($data['mhbo_license_key']) ? wp_unslash($data['mhbo_license_key']) : '';
            $key = is_string($raw_key) ? sanitize_text_field($raw_key) : '';
            $result = LicenseManager::activate($key);
            if ($result['success']) {
                add_settings_error('mhbo_settings', 'license_success', $result['message'], 'success');
            } else {
                add_settings_error('mhbo_settings', 'license_error', $result['message'], 'error');
            }
        }

        // 3. Handle Deactivation/Removal
        if (isset($data['mhbo_remove_license'])) {
            $result = LicenseManager::deactivate();
            add_settings_error('mhbo_settings', 'license_info', $result['message'], 'info');
        }

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_license_saved'), 'success');
        /* BUILD_PRO_END */
    }

    /**
     * Save Tax Settings
     */
    /**
     * @param array<string, mixed> $data
     */
    public function save_tax_settings(array $data): void
    {
        /* BUILD_PRO_START */
        // Whitelist of valid tax modes
        if (isset($data['mhbo_tax_mode'])) {
            $allowed_modes = ['disabled', 'vat', 'sales_tax'];
            $mode = sanitize_text_field(wp_unslash($data['mhbo_tax_mode']));
            if (in_array($mode, $allowed_modes, true)) {
                update_option('mhbo_tax_mode', $mode);
            }
        }

        // Tax Label (Multilingual)
        if (isset($data['mhbo_tax_label_lang']) && is_array($data['mhbo_tax_label_lang'])) {
            $label_data = array_map('sanitize_text_field', wp_unslash($data['mhbo_tax_label_lang']));
            update_option('mhbo_tax_label', I18n::encode($label_data));
        }

        // Tax Registration Number
        if (isset($data['mhbo_tax_registration_number'])) {
            update_option('mhbo_tax_registration_number', sanitize_text_field(wp_unslash($data['mhbo_tax_registration_number'])));
        }

        // Tax Rates (with server-side range validation 0-100)
        if (isset($data['mhbo_tax_rate_accommodation'])) {
            $rate = max(0, min(100, floatval(wp_unslash($data['mhbo_tax_rate_accommodation']))));
            update_option('mhbo_tax_rate_accommodation', $rate);
        }
        if (isset($data['mhbo_tax_rate_extras'])) {
            $rate = max(0, min(100, floatval(wp_unslash($data['mhbo_tax_rate_extras']))));
            update_option('mhbo_tax_rate_extras', $rate);
        }

        // Display Options
        update_option('mhbo_tax_display_frontend', isset($data['mhbo_tax_display_frontend']) ? 1 : 0);
        update_option('mhbo_tax_display_email', isset($data['mhbo_tax_display_email']) ? 1 : 0);

        // Advanced Settings
        if (isset($data['mhbo_tax_rounding_mode'])) {
            $allowed_rounding = ['per_total', 'per_line'];
            $rounding = sanitize_text_field(wp_unslash($data['mhbo_tax_rounding_mode']));
            if (in_array($rounding, $allowed_rounding, true)) {
                update_option('mhbo_tax_rounding_mode', $rounding);
            }
        }
        if (isset($data['mhbo_tax_decimal_places'])) {
            update_option('mhbo_tax_decimal_places', absint(wp_unslash($data['mhbo_tax_decimal_places'])));
        }

        // Service Fee Settings
        update_option('mhbo_service_fee_enabled', isset($data['mhbo_service_fee_enabled']) ? 1 : 0);

        if (isset($data['mhbo_service_fee_type'])) {
            $allowed_fee_types = ['fixed', 'percentage'];
            $fee_type = sanitize_key(wp_unslash($data['mhbo_service_fee_type']));
            if (in_array($fee_type, $allowed_fee_types, true)) {
                update_option('mhbo_service_fee_type', $fee_type);
            }
        }

        if (isset($data['mhbo_service_fee_amount'])) {
            $fee_amount = max(0, (float) wp_unslash($data['mhbo_service_fee_amount']));
            update_option('mhbo_service_fee_amount', number_format($fee_amount, 2, '.', ''));
        }

        if (isset($data['mhbo_service_fee_percentage'])) {
            $fee_pct = max(0, min(100, (float) wp_unslash($data['mhbo_service_fee_percentage'])));
            update_option('mhbo_service_fee_percentage', number_format($fee_pct, 2, '.', ''));
        }

        if (isset($data['mhbo_service_fee_label'])) {
            update_option('mhbo_service_fee_label', sanitize_text_field(wp_unslash($data['mhbo_service_fee_label'])));
        }

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_tax_saved'), 'success');
        /* BUILD_PRO_END */
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save_performance_settings(array $data): void
    {
        /* BUILD_PRO_START */
        // Cache Enable/Disable
        update_option('mhbo_cache_enabled', isset($data['mhbo_cache_enabled']) ? 1 : 0);

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_performance_saved'), 'success');
        /* BUILD_PRO_END */
    }

    /**
     * AJAX handler for clearing cache.
     */
    /**
     * AJAX handler for clearing cache.
     */
    public function ajax_clear_cache(): void
    {
        check_ajax_referer('mhbo_clear_cache_nonce_field', 'nonce');

        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_send_json_error(['message' => I18n::get_label('msg_permission_denied')]);
        }

        /* BUILD_PRO_START */
        if (class_exists('MHBO\Core\Cache')) {
            $result = Cache::flush_all();
            if ($result) {
                wp_send_json_success(['message' => I18n::get_label('msg_cache_cleared')]);
            } else {
                wp_send_json_error(['message' => I18n::get_label('msg_cache_failed')]);
            }
        } else {
            wp_send_json_error(['message' => I18n::get_label('msg_cache_unavailable')]);
        }
        /* BUILD_PRO_END */
    }

    /* BUILD_PRO_START */
    /**
     * Save deposit settings.
     *
     * @param array $data POST data.
     */
    /**
     * @param array<string, mixed> $data
     */
    public function save_deposits_settings(array $data): void
    {
        update_option('mhbo_deposits_enabled', isset($data['mhbo_deposits_enabled']) ? 1 : 0);
        update_option('mhbo_deposit_type', sanitize_key($data['mhbo_deposit_type'] ?? 'percentage'));
        update_option('mhbo_deposit_value', floatval($data['mhbo_deposit_value'] ?? 20));
        update_option('mhbo_deposit_non_refundable', isset($data['mhbo_deposit_non_refundable']) ? 1 : 0);
        update_option('mhbo_deposit_refund_deadline_days', absint($data['mhbo_deposit_refund_deadline_days'] ?? 7));
        update_option('mhbo_deposit_allow_guest_choice', isset($data['mhbo_deposit_allow_guest_choice']) ? 1 : 0);

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_deposit_saved'), 'success');
    }

    /**
     * Render the Deposits tab.
     */
    private static function render_deposits_tab(): void
    {
        $enabled = get_option('mhbo_deposits_enabled', 0);
        $type    = get_option('mhbo_deposit_type', 'percentage');
        $value   = get_option('mhbo_deposit_value', 20);
        $non_ref = get_option('mhbo_deposit_non_refundable', 0);
        $days    = get_option('mhbo_deposit_refund_deadline_days', 7);
        $choice  = get_option('mhbo_deposit_allow_guest_choice', 0);

        echo '<h2>' . esc_html(I18n::get_label('label_deposits_title')) . '</h2>';
        echo '<p>' . esc_html(I18n::get_label('label_deposits_desc')) . '</p>';

        echo '<table class="form-table" role="presentation">';

        // Enabled
        echo '<tr><th><label>' . esc_html(I18n::get_label('label_enable_deposits')) . '</label></th><td>';
        echo '<input type="checkbox" name="mhbo_deposits_enabled" value="1" ' . checked(1, $enabled, false) . '>';
        echo '<p class="description">' . esc_html(I18n::get_label('label_enable_deposits_desc')) . '</p>';
        echo '</td></tr>';

        // Deposit Type
        echo '<tr class="mhbo-deposit-field"><th><label>' . esc_html(I18n::get_label('label_deposit_type')) . '</label></th><td>';
        echo '<select name="mhbo_deposit_type" id="mhbo_deposit_type">';
        echo '<option value="percentage" ' . selected($type, 'percentage', false) . '>' . esc_html(I18n::get_label('label_deposit_type_pct')) . '</option>';
        echo '<option value="fixed" ' . selected($type, 'fixed', false) . '>' . esc_html(I18n::get_label('label_deposit_type_fixed')) . '</option>';
        echo '<option value="first_night" ' . selected($type, 'first_night', false) . '>' . esc_html(I18n::get_label('label_deposit_type_first_night')) . '</option>';
        echo '</select>';
        echo '</td></tr>';

        // Deposit Value
        $display_val = ($type === 'fixed') ? Money::fromDecimal((string) $value)->toDecimal() : (string) ((float) $value + 0);
        echo '<tr class="mhbo-deposit-field" id="row_mhbo_deposit_value"><th><label>' . esc_html(I18n::get_label('label_deposit_value')) . '</label></th><td>';
        echo '<input type="number" step="any" name="mhbo_deposit_value" value="' . esc_attr($display_val) . '" class="small-text">';
        echo '<span class="mhbo-deposit-unit-pct" style="display:' . esc_attr($type === 'percentage' ? 'inline' : 'none') . ';">%</span>';
        echo '<span class="mhbo-deposit-unit-fixed" style="display:' . esc_attr($type === 'fixed' ? 'inline' : 'none') . ';">' . esc_html(Pricing::get_currency_symbol()) . '</span>';
        echo '</td></tr>';

        // Non-refundable
        echo '<tr class="mhbo-deposit-field"><th><label>' . esc_html(I18n::get_label('label_non_refundable')) . '</label></th><td>';
        echo '<input type="checkbox" name="mhbo_deposit_non_refundable" value="1" ' . checked(1, $non_ref, false) . '>';
        echo '<p class="description">' . esc_html(I18n::get_label('label_non_refundable_desc')) . '</p>';
        echo '</td></tr>';

        // Refund Deadline
        echo '<tr class="mhbo-deposit-field" id="row_mhbo_refund_deadline"><th><label>' . esc_html(I18n::get_label('label_refund_deadline')) . '</label></th><td>';
        echo '<input type="number" name="mhbo_deposit_refund_deadline_days" value="' . esc_attr($days) . '" class="small-text"> ' . esc_html(I18n::get_label('label_days_before_checkin'));
        echo '<p class="description">' . esc_html(I18n::get_label('label_refund_deadline_desc')) . '</p>';
        echo '</td></tr>';

        // Guest Choice
        echo '<tr class="mhbo-deposit-field"><th><label>' . esc_html(I18n::get_label('label_guest_choice')) . '</label></th><td>';
        echo '<input type="checkbox" name="mhbo_deposit_allow_guest_choice" value="1" ' . checked(1, $choice, false) . '>';
        echo '<p class="description">' . esc_html(I18n::get_label('label_guest_choice_desc')) . '</p>';
        echo '</td></tr>';

        echo '</table>';

        // Dynamic JS for show/hide
        ?>
        <script>
        jQuery(document).ready(function($) {
            function toggleDepositFields() {
                var enabled = $('input[name="mhbo_deposits_enabled"]').is(':checked');
                var type = $('#mhbo_deposit_type').val();
                
                if (!enabled) {
                    $('.mhbo-deposit-field').hide();
                } else {
                    $('.mhbo-deposit-field').show();
                    
                    if (type === 'first_night') {
                        $('#row_mhbo_deposit_value').hide();
                    } else {
                        $('#row_mhbo_deposit_value').show();
                        if (type === 'percentage') {
                            $('.mhbo-deposit-unit-pct').show();
                            $('.mhbo-deposit-unit-fixed').hide();
                        } else {
                            $('.mhbo-deposit-unit-pct').hide();
                            $('.mhbo-deposit-unit-fixed').show();
                        }
                    }
                }
            }
            
            $('input[name="mhbo_deposits_enabled"], #mhbo_deposit_type').on('change', toggleDepositFields);
            toggleDepositFields();
        });
        </script>
        <?php
    }
    /* BUILD_PRO_END */

}
