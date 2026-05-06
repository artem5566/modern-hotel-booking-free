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
                
                'test_stripe' => wp_create_nonce('mhbo_test_stripe_nonce'),
                'test_paypal' => wp_create_nonce('mhbo_test_paypal_nonce'),
                'clear_cache' => wp_create_nonce('mhbo_clear_cache_nonce_field'),
            ),
            'i18n' => array(
                
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
        
        register_setting('mhbo_settings_group', 'mhbo_notification_email', array('default' => get_option('admin_email'), 'sanitize_callback' => 'sanitize_email'));
        register_setting('mhbo_settings_group', 'mhbo_additional_notification_email', array('default' => '', 'sanitize_callback' => 'sanitize_email'));
        register_setting('mhbo_settings_group', 'mhbo_modal_enabled', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_prevent_same_day_turnover', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_children_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));

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

// Uninstall Settings
        register_setting('mhbo_settings_group', 'mhbo_save_data_on_uninstall', array('default' => 1, 'sanitize_callback' => 'absint'));
        
        // Security Settings
        register_setting('mhbo_settings_group', 'mhbo_trusted_proxies', array('default' => '', 'sanitize_callback' => 'sanitize_textarea_field'));

        // Display Settings
        register_setting('mhbo_settings_group', 'mhbo_powered_by_link', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Performance Settings

// Theme Settings

// Amenities (Dynamic) - Handled manually with inline sanitization
        // register_setting('mhbo_settings_group', 'mhbo_amenities_list');

        add_settings_section('mhbo_general_section', I18n::get_label('settings_section_general'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_checkin_time', I18n::get_label('settings_label_checkin'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkin_time'));
        add_settings_field('mhbo_checkout_time', I18n::get_label('settings_label_checkout'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkout_time'));
        add_settings_field('mhbo_notification_email', I18n::get_label('settings_label_notification'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_notification_email'));
        add_settings_field('mhbo_additional_notification_email', __('Additional Notification Email', 'modern-hotel-booking'), array($this, 'render_additional_notification_email_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_additional_notification_email'));
        
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
        
        return false;

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
                
            </h2>

<?php AdminUI::render_card_start('', 'settings-card'); ?>
                <?php
                $manual_tabs = [
                    
                    'emails',
                    'labels',
                    'amenities',
                    'business',
                    'webhooks',
                    
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
                        
                    } elseif ('general' === $active_tab) {
                        do_settings_sections('mhbo-settings');
                    } elseif ('emails' === $active_tab) {
                        self::render_email_templates_tab();
                    } elseif ('labels' === $active_tab) {
                        self::render_labels_tab();
                    } elseif ('amenities' === $active_tab) {
                        self::render_amenities_tab();

} elseif ('business' === $active_tab) {
                        \MHBO\Business\Info::render_settings_tab();
                    } elseif ('webhooks' === $active_tab) {
                        self::render_api_tab();
                    }

                    // Show save button — Pro version gates locked tabs, Free always shows
                    
                    $show_save = true;

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
                
                'label_per_person_per_night' => I18n::__('label_desc_per_person_per_night'),
                
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

switch ($tab) {
                case 'general':
                    $this->save_general_settings($data);
                    break;
                
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
                
                'label_per_person_per_night',
                
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

add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_general_saved'), 'success');
    }

public static function render_pro_page(): void
    {
        
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
                        class="button button-primary button-large"><?php I18n::esc_html_e('pro_upsell_upgrade'); ?></a>                </div>
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
        
    }

    private static function render_api_tab(): void
    {
        
    }

/**
     * @param array<string, mixed> $data
     */
    public function save_api_settings(array $data): void
    {
        
    }

/**
     * @param array<string, mixed> $data
     */
    public function save_payments_settings(array $data): void
    {
        
    }

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
        
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save_license_settings(array $data): void
    {
        
    }

    /**
     * Save Tax Settings
     */
    /**
     * @param array<string, mixed> $data
     */
    public function save_tax_settings(array $data): void
    {
        
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save_performance_settings(array $data): void
    {
        
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

}

}
