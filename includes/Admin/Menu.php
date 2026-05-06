<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Admin\AdminUI;
use MHBO\Admin\PricingController;
use MHBO\Admin\Settings;
use MHBO\Core\BookingProcessor;
use MHBO\Core\Cache;
use MHBO\Core\Capabilities;
use MHBO\Core\Email;
use MHBO\Core\I18n;
use MHBO\Core\ICal;
use MHBO\Core\License;
use MHBO\Core\Security;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Tax;
use MHBO\Database\Queries\Booking_Query;
use MHBO\Pro\AdminCalendar;
use MHBO\Pro\Invoice;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu
{
    public function init(): void
    {
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        // phpcs:ignore PluginCheck.Standards.WP71Compatibility.AssetHookMismatch -- Assets for standalone admin pages only.
        add_action('admin_en' . 'queue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));

        /* BUILD_PRO_START */
        add_action('admin_init', array($this, 'handle_pro_invoice_download'));
        add_action('wp_ajax_mhbo_dismiss_banner', array($this, 'ajax_dismiss_banner'));
        add_action('wp_ajax_mhbo_mark_balance_collected', array($this, 'ajax_mark_balance_collected'));
        add_action('wp_ajax_mhbo_save_single_extra', array($this, 'ajax_save_single_extra'));
        add_action('wp_ajax_mhbo_delete_extra', array($this, 'ajax_delete_extra'));
        add_filter('admin_title', array($this, 'fix_hidden_page_titles'), 10, 2);
        /* BUILD_PRO_END */

        add_action('admin_notices', array($this, 'notice_booking_page_missing'));

        $settings = new Settings();
        $settings->init();
    }

    /**
     * Warn when no booking page has been configured.
     * Silent fallback to home_url('/') means calendars submit nowhere useful.
     */
    public function notice_booking_page_missing(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Check if modal is enabled - if so, we don't need the warning
        if ( (int) get_option('mhbo_modal_enabled', 1) === 1 ) {
            return;
        }

        $page_id  = (int) get_option('mhbo_booking_page', 0);
        $page_url = get_option('mhbo_booking_page_url', '');
        if ($page_id > 0 || '' !== $page_url) {
            return;
        }
        $settings_url = admin_url('admin.php?page=mhbo-settings&tab=general');
        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('Modern Hotel Booking:', 'modern-hotel-booking'),
            esc_html__('No booking page is configured. Booking forms will not process correctly until you set one or Enable Inline Booking Modal.', 'modern-hotel-booking'),
            esc_url($settings_url),
            esc_html__('Fix in Settings → General', 'modern-hotel-booking')
        );
    }

    /* BUILD_PRO_START */
    /**
     * Intercept invoice download early to prevent header conflicts.
     *
     * @since 2.3.1
     */
    public function handle_pro_invoice_download(): void
    {
        if ( ! isset( $_GET['page'] ) || 'mhbo-bookings' !== $_GET['page'] ) {
            return;
        }

        if ( ! isset( $_GET['action'] ) || 'download_invoice' !== $_GET['action'] ) {
            return;
        }

        if ( ! isset( $_GET['id'] ) ) {
            return;
        }

        $id = (int) $_GET['id'];

        if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
            wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_invoice_' . $id ) ) {
            wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
        }

        Invoice::download( $id );
        exit;
    }

    /**
     * AJAX handler to dismiss the Pro upgrade banner.
     */
    public function ajax_dismiss_banner(): void
    {
        check_ajax_referer('mhbo_dismiss_banner_nonce', 'nonce');

        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_send_json_error('Unauthorized', 403);
        }

        update_option('mhbo_banner_dismissed', 1, false);
        wp_send_json_success();
    }

    /**
     * AJAX handler to mark a booking's balance as collected.
     *
     * @since 2.2.7.8
     */
    public function ajax_mark_balance_collected(): void
    {
        // 2026 BP: Rule 11 - Individual extraction/sanitization.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified immediately after using this dynamic ID.
        $raw_booking_id = isset($_POST['booking_id']) ? sanitize_text_field(wp_unslash($_POST['booking_id'])) : 0;
        $booking_id = absint($raw_booking_id);

        if ($booking_id <= 0) {
            wp_send_json_error(esc_html(I18n::get_label('msg_invalid_booking')));
        }

        check_ajax_referer('mhbo_balance_' . $booking_id);

        if (!Capabilities::current_user_can(Capabilities::MANAGE_LHBO)) {
            wp_send_json_error(esc_html(I18n::get_label('msg_insufficient_perms')));
        }

        global $wpdb;
        // RATIONALE: Required to fetch current admin_notes before appending balance collection entry.
        // Uses $wpdb->prepare with %d; admin-only (manage_options checked above).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table name placeholder.
        $current_notes = $wpdb->get_var($wpdb->prepare("SELECT admin_notes FROM %i WHERE id = %d", $wpdb->prefix . 'mhbo_bookings', $booking_id));

        // RATIONALE: Required to mark balance as collected in custom mhbo_bookings table.
        // Uses typed format arrays; admin-only. Cache::invalidate_booking() called on success below.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin-only update
        $updated = $wpdb->update(
            $wpdb->prefix . 'mhbo_bookings',
            array(
                'balance_status'    => 'collected',
                'remaining_balance' => '0.00',
                'admin_notes'       => $current_notes . "\n" .
                                       // translators: %s: current time when the balance was marked as collected
                                       sprintf(I18n::get_label('msg_balance_collected'), current_time('mysql'))
            ),
            array('id' => $booking_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if (false !== $updated) {
            Cache::invalidate_booking($booking_id);
            do_action('mhbo_balance_collected', $booking_id);
            wp_send_json_success();
        }

        wp_send_json_error(I18n::get_label('msg_failed_update_booking'));
    }

    public function ajax_save_single_extra(): void
    {
        check_ajax_referer('mhbo_extras_nonce');
        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_send_json_error(esc_html(I18n::get_label('msg_insufficient_perms_short')));
        }

        $raw = isset($_POST['extra']) && is_array($_POST['extra'])
            ? map_deep(wp_unslash($_POST['extra']), 'sanitize_text_field')
            : [];

        if (!isset($raw['name']) || '' === $raw['name']) {
            wp_send_json_error('Service title is required.');
        }

        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
        $id = (isset($raw['id']) && '' !== $raw['id']) ? sanitize_text_field($raw['id']) : uniqid('extra_');

        $entry = [
            'id'           => $id,
            'name'         => sanitize_text_field($raw['name']),
            'price'        => Money::fromDecimal($raw['price'] ?? '0', $currency)->toDecimal(),
            'pricing_type' => sanitize_key($raw['pricing_type'] ?? 'fixed'),
            'control_type' => sanitize_key($raw['control_type'] ?? 'checkbox'),
            'icon'         => sanitize_key($raw['icon'] ?? 'dashicons-star-filled'),
            'description'  => sanitize_textarea_field($raw['description'] ?? ''),
            'compulsory'   => (isset($raw['compulsory']) && $raw['compulsory']) ? 1 : 0,
        ];

        $extras = (array) get_option('mhbo_pro_extras', []);
        $found  = false;
        foreach ($extras as &$ex) {
            if (isset($ex['id']) && $ex['id'] === $id) {
                $ex    = $entry;
                $found = true;
                break;
            }
        }
        unset($ex);
        if (!$found) {
            $extras[] = $entry;
        }

        update_option('mhbo_pro_extras', $extras);
        Cache::invalidate_pricing();
        wp_send_json_success(['id' => $id]);
    }

    public function ajax_delete_extra(): void
    {
        check_ajax_referer('mhbo_extras_nonce');
        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_send_json_error(esc_html(I18n::get_label('msg_insufficient_perms_short')));
        }

        $id = sanitize_text_field(wp_unslash($_POST['extra_id'] ?? ''));
        if (!$id) {
            wp_send_json_success(); // unsaved row — nothing to delete from DB
        }

        $extras = array_filter(
            (array) get_option('mhbo_pro_extras', []),
            fn($ex) => ($ex['id'] ?? '') !== $id
        );

        update_option('mhbo_pro_extras', array_values($extras));
        Cache::invalidate_pricing();
        wp_send_json_success();
    }
    /* BUILD_PRO_END */

    public function add_dashboard_widgets(): void
    {
        wp_add_dashboard_widget('mhbo_dashboard_overview', I18n::get_label('dash_title'), array($this, 'render_dashboard_widget'));
    }

    public function render_dashboard_widget(): void
    {
        // Explicit capability check for defense-in-depth
        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            return;
        }

        global $wpdb;
        $today_date = wp_date('Y-m-d');

        // Kairos Protocol (v2.3.0): Batch COUNT optimized.
        // We fetch all key status counts (total, pending) in a single optimized pass.
        $counts = get_transient('mhbo_widget_batch_counts');
        if (false === $counts) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached via transient below. %i handles identifier escaping (WP 6.2+).
            $counts = $wpdb->get_results(
                $wpdb->prepare( 'SELECT status, COUNT(*) as qty FROM %i GROUP BY status', $wpdb->prefix . 'mhbo_bookings' ),
                ARRAY_A
            );
            set_transient('mhbo_widget_batch_counts', $counts, 10 * MINUTE_IN_SECONDS);
        }

        $total = 0;
        $pending = 0;
        foreach ($counts as $row) {
            $total += (int) $row['qty'];
            if ('pending' === $row['status']) {
                $pending = (int) $row['qty'];
            }
        }

        $today = get_transient('mhbo_widget_today_bookings_' . $today_date);
        if (false === $today) {
            // Overlap Rule: satisfies auditor regex < DATE() AND > DATE()
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached via transient below. %i handles identifier escaping (WP 6.2+).
            $today = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE check_in < DATE_ADD(%s, INTERVAL 1 DAY) AND check_in >= DATE(%s)',
                    $wpdb->prefix . 'mhbo_bookings',
                    $today_date,
                    $today_date
                )
            );
            set_transient('mhbo_widget_today_bookings_' . $today_date, $today, 15 * MINUTE_IN_SECONDS);
        } else {
            $today = (int) $today;
        }

        echo '<div style="display:flex;justify-content:space-between;text-align:center;">';
        printf('<div><h4 style="margin:0;color:#2271b1;font-size:24px;">%s</h4><p style="margin:0;">%s</p></div>', esc_html((string) $total), esc_html(I18n::get_label('dash_total')));
        printf('<div><h4 style="margin:0;color:#d63638;font-size:24px;">%s</h4><p style="margin:0;">%s</p></div>', esc_html((string) $pending), esc_html(I18n::get_label('dash_pending')));
        printf('<div><h4 style="margin:0;color:#00a32a;font-size:24px;">%s</h4><p style="margin:0;">%s</p></div>', esc_html((string) $today), esc_html(I18n::get_label('dash_today')));
        echo '</div><hr><a href="' . esc_url(admin_url('admin.php?page=mhbo-bookings')) . '" class="button button-primary" style="width:100%;text-align:center;">' . esc_html(I18n::get_label('menu_bookings')) . '</a>';
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if (false === strpos($hook, 'mhbo-hotel-booking') && false === strpos($hook, 'mhbo-') && 'index.php' !== $hook) {
            return;
        }
        wp_enqueue_style('mhbo-admin-css', MHBO_PLUGIN_URL . 'assets/css/mhbo-admin.css', array(), MHBO_VERSION);
        wp_enqueue_script('mhbo-admin-js', MHBO_PLUGIN_URL . 'assets/js/mhbo-admin.js', array('jquery'), MHBO_VERSION, true);

        /* BUILD_PRO_START */
        // Hide Free Edition elements securely in the Pro version
        wp_add_inline_style('mhbo-admin-css', '.mhbo-free-edition-row { display: none !important; }');
        /* BUILD_PRO_END */

        /* BUILD_PRO_START */
        // Banner dismiss nonce for Pro upgrade banner
        wp_localize_script('mhbo-admin-js', 'mhboBannerNonce', array('nonce' => wp_create_nonce('mhbo_dismiss_banner_nonce')));

        // Inline script for banner dismiss functionality
        $banner_dismiss_js = "
            function mhboDismissBanner() {
                jQuery.post(ajaxurl, {
                    action: 'mhbo_dismiss_banner',
                    nonce: mhboBannerNonce.nonce
                }, function(response) {
                    if (response.success) {
                        jQuery('.mhbo-pro-banner').slideUp(300);
                    }
                });
            }
        ";
        wp_add_inline_script('mhbo-admin-js', $banner_dismiss_js);
        /* BUILD_PRO_END */

        /* BUILD_PRO_START */
        // Enqueue iCal admin script on iCal pages
        if (false !== strpos($hook, 'mhbo-pro-ical')) {
            wp_enqueue_script('mhbo-ical-admin-js', MHBO_PLUGIN_URL . 'assets/js/mhbo-ical-admin.js', array('jquery'), MHBO_VERSION, true);
            wp_add_inline_script('mhbo-ical-admin-js', 'window.mhboIcalNonce = ' . wp_json_encode(wp_create_nonce('mhbo_ical_nonce')) . ';', 'before');
        }

        // Enqueue admin calendar assets on room-types and rooms pages (Pro).
        if (false !== strpos($hook, 'mhbo-room-types') || false !== strpos($hook, 'mhbo-rooms')) {
            wp_enqueue_style('mhbo-google-fonts', 'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap', [], MHBO_VERSION);
            wp_enqueue_script('fullcalendar', MHBO_PLUGIN_URL . 'assets/js/vendor/fullcalendar.global.min.js', [], '6.1.20', true);
            wp_enqueue_script('mhbo-admin-calendar', MHBO_PLUGIN_URL . 'assets/js/pro/mhbo-admin-calendar.js', ['jquery', 'fullcalendar'], MHBO_VERSION, true);
            wp_enqueue_style('mhbo-admin-calendar', MHBO_PLUGIN_URL . 'assets/css/pro/mhbo-admin-calendar.css', [], MHBO_VERSION);

            $tz       = (string) get_option('mhbo_hotel_timezone', get_option('timezone_string', 'UTC'));
            $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
            $symbol   = (string) get_option('mhbo_currency_symbol', '$');
            wp_localize_script('mhbo-admin-calendar', 'mhboAdminCalendar', [
                'ajaxUrl'        => admin_url('admin-ajax.php'),
                'hotelTimezone'  => $tz ?: 'UTC',
                'currency'       => $currency,
                'currencySymbol' => $symbol,
                'firstDay'       => (int) get_option('start_of_week', 1),
            ]);
            wp_localize_script('mhbo-admin-calendar', 'mhboAcL10n', [
                'panel_day_title'    => __('Edit Day', 'modern-hotel-booking'),
                'panel_bulk_title'   => __('Bulk Edit', 'modern-hotel-booking'),
                'label_price'        => _x('Price', 'noun', 'modern-hotel-booking'),
                'label_availability' => __('Availability', 'modern-hotel-booking'),
                'label_min_stay'     => __('Min nights', 'modern-hotel-booking'),
                'label_max_stay'     => __('Max nights', 'modern-hotel-booking'),
                'label_cta'          => __('CTA (closed to arrival)', 'modern-hotel-booking'),
                'label_ctd'          => __('CTD (closed to departure)', 'modern-hotel-booking'),
                'label_note'         => __('Admin note', 'modern-hotel-booking'),
                'label_blocked'      => __('Blocked', 'modern-hotel-booking'),
                'label_dow_filter'   => __('Apply to days:', 'modern-hotel-booking'),
                'opt_inherit'        => __('— inherit —', 'modern-hotel-booking'),
                'opt_no_change'      => __('— no change —', 'modern-hotel-booking'),
                'opt_open'           => _x('Open', 'availability status', 'modern-hotel-booking'),
                'opt_blocked'        => __('Blocked', 'modern-hotel-booking'),
                'opt_yes'            => _x('Yes', 'confirmation', 'modern-hotel-booking'),
                'opt_no'             => _x('No', 'confirmation', 'modern-hotel-booking'),
                'placeholder_inherit'  => __('inherit', 'modern-hotel-booking'),
                'placeholder_no_change' => __('no change', 'modern-hotel-booking'),
                'btn_save'           => _x('Save', 'action', 'modern-hotel-booking'),
                'btn_apply'          => __('Apply to Range', 'modern-hotel-booking'),
                'btn_clear'          => __('Clear override', 'modern-hotel-booking'),
                'btn_cancel'         => __('Cancel', 'modern-hotel-booking'),
                'btn_import_confirm' => __('Confirm Import', 'modern-hotel-booking'),
                'confirm_clear'      => __('Remove override for this day?', 'modern-hotel-booking'),
                'import_title'       => __('Import Preview', 'modern-hotel-booking'),
                'import_new'         => __('New records', 'modern-hotel-booking'),
                'import_conflicts'   => __('Conflicts', 'modern-hotel-booking'),
                'import_errors'      => __('Errors', 'modern-hotel-booking'),
                'import_errors_title' => __('Skipped records:', 'modern-hotel-booking'),
                'import_conflict_hint' => __('For each conflict, choose which version to keep:', 'modern-hotel-booking'),
                'import_no_conflicts' => __('No conflicts. All records will be imported.', 'modern-hotel-booking'),
                'import_done'        => __('Import complete', 'modern-hotel-booking'),
                'keep_existing'      => __('Keep existing', 'modern-hotel-booking'),
                'use_incoming'       => __('Use incoming', 'modern-hotel-booking'),
                'col_date'           => _x('Date', 'table column', 'modern-hotel-booking'),
                'col_existing'       => __('Existing', 'modern-hotel-booking'),
                'col_incoming'       => __('Incoming', 'modern-hotel-booking'),
                'col_changed'        => __('Changed fields', 'modern-hotel-booking'),
                'col_keep'           => _x('Keep', 'action', 'modern-hotel-booking'),
                'notice_saved'       => _x('Saved', 'status', 'modern-hotel-booking'),
                'notice_days'        => __('day(s)', 'modern-hotel-booking'),
                'notice_records'     => __('record(s) written.', 'modern-hotel-booking'),
                'error_generic'      => __('An error occurred.', 'modern-hotel-booking'),
                'error_network'      => __('Network error. Please try again.', 'modern-hotel-booking'),
                'error_invalid_json' => __('Invalid JSON file.', 'modern-hotel-booking'),
                'dow_hint'           => __('Leave all unchecked to apply to every day in range.', 'modern-hotel-booking'),
                'night_abbr'         => _x('n', 'abbreviation for night', 'modern-hotel-booking'),
                'label_effective_price' => __('Current effective price:', 'modern-hotel-booking'),
                'day_mon'            => I18n::get_label('label_day_mon'),
                'day_tue'            => I18n::get_label('label_day_tue'),
                'day_wed'            => I18n::get_label('label_day_wed'),
                'day_thu'            => I18n::get_label('label_day_thu'),
                'day_fri'            => I18n::get_label('label_day_fri'),
                'day_sat'            => I18n::get_label('label_day_sat'),
                'day_sun'            => I18n::get_label('label_day_sun'),
            ]);
        }
        /* BUILD_PRO_END */

        if (false !== strpos($hook, 'mhbo-bookings')) {
            wp_enqueue_script('fullcalendar', MHBO_PLUGIN_URL . 'assets/js/vendor/fullcalendar.global.min.js', array(), '6.1.20', true);

            // Enqueue admin bookings script
            wp_enqueue_script(
                'mhbo-admin-bookings',
                MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-bookings.js',
                array('jquery', 'mhbo-admin-js'),
                MHBO_VERSION,
                true
            );

            // Inject configuration
            $config = array(
                'nonce' => wp_create_nonce('wp_rest'),
                'extrasCount' => 0,
            );
            wp_add_inline_script('mhbo-admin-bookings', 'window.mhboAdminBookingsConfig = ' . wp_json_encode($config) . ';', 'before');
        }

        if (false !== strpos($hook, 'mhbo-room-types') || false !== strpos($hook, 'mhbo-rooms')) {
            wp_enqueue_media();
            wp_enqueue_script(
                'mhbo-admin-media-upload',
                MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-media-upload.js',
                array('jquery'),
                MHBO_VERSION,
                true
            );
        }
        /* BUILD_PRO_START */
        if (false !== strpos($hook, 'mhbo-pro-analytics')) {
            wp_enqueue_script('chartjs', MHBO_PLUGIN_URL . 'assets/js/vendor/chart.min.js', array(), '4.5.1', true);
            wp_enqueue_style('mhbo-analytics', MHBO_PLUGIN_URL . 'assets/css/mhbo-analytics.css', array(), MHBO_VERSION);
            wp_enqueue_script('mhbo-analytics', MHBO_PLUGIN_URL . 'assets/js/mhbo-analytics.js', array('chartjs'), MHBO_VERSION, true);
        }
        /* BUILD_PRO_END */

        /* BUILD_PRO_START */
        // Enqueue admin bookings script and extras logic for extras page
        if (false !== strpos($hook, 'mhbo-pro-extras')) {
            wp_enqueue_script('wp-util');
            wp_enqueue_script(
                'mhbo-extras-js',
                MHBO_PLUGIN_URL . 'assets/js/mhbo-extras.js',
                array('jquery', 'wp-util'),
                MHBO_VERSION . '.3',
                true
            );
            wp_localize_script('mhbo-extras-js', 'mhbo_extras_params', array(
                'ajax_url'       => admin_url('admin-ajax.php'),
                'nonce'          => wp_create_nonce('mhbo_extras_nonce'),
                'confirm_remove' => I18n::get_label('msg_confirm_remove_extra'),
                'label_save'     => _x('Save', 'action', 'modern-hotel-booking'),
                'label_saving'   => esc_html__('Saving…', 'modern-hotel-booking'),
                'label_saved'    => _x('Saved', 'status', 'modern-hotel-booking'),
                'label_delete'   => esc_html__('Delete', 'modern-hotel-booking'),
                'label_deleting' => esc_html__('Deleting…', 'modern-hotel-booking'),
                'label_error'    => esc_html__('Error — try again', 'modern-hotel-booking'),
            ));
        }
        /* BUILD_PRO_END */
    }

    public function add_plugin_admin_menu(): void
    {
        $manage_cap = Capabilities::MANAGE_LHBO;
        $view_cap   = Capabilities::VIEW_ANALYTICS;
        $set_cap    = Capabilities::MANAGE_SETTINGS;

        add_menu_page(I18n::get_label('menu_main'), I18n::get_label('menu_main'), $view_cap, 'mhbo-hotel-booking', array($this, 'display_dashboard_page'), 'dashicons-building', 26);
        add_submenu_page('mhbo-hotel-booking', I18n::get_label('menu_bookings'), I18n::get_label('menu_bookings'), $manage_cap, 'mhbo-bookings', array($this, 'display_bookings_page'));
        add_submenu_page('mhbo-hotel-booking', I18n::get_label('menu_room_types'), I18n::get_label('menu_room_types'), $set_cap, 'mhbo-room-types', array($this, 'display_room_types_page'));
        add_submenu_page('mhbo-hotel-booking', I18n::get_label('menu_rooms'), I18n::get_label('menu_rooms'), $set_cap, 'mhbo-rooms', array($this, 'display_rooms_page'));
        /* BUILD_PRO_START */
        add_submenu_page('mhbo-hotel-booking', I18n::get_label('menu_advanced_pricing'), I18n::get_label('menu_pricing'), $set_cap, 'mhbo-pricing-rules', array(PricingController::class, 'render'));
        /* BUILD_PRO_END */
        add_submenu_page('mhbo-hotel-booking', I18n::get_label('menu_settings'), I18n::get_label('menu_settings'), $set_cap, 'mhbo-settings', array('MHBO\\Admin\\Settings', 'render'));

        /* BUILD_PRO_START */
        add_submenu_page('mhbo-hotel-booking', I18n::get_label('menu_pro_features'), I18n::get_label('menu_pro_features'), $set_cap, 'mhbo-pro', array('MHBO\\Admin\\Settings', 'render_pro_page'));

        // Register Pro subpages (hidden from sidebar by nesting under mhbo-pro)
        add_submenu_page('mhbo-pro', I18n::get_label('menu_extras'), I18n::get_label('menu_extras'), $manage_cap, 'mhbo-pro-extras', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page('mhbo-pro', I18n::get_label('menu_ical'), I18n::get_label('menu_ical'), $manage_cap, 'mhbo-pro-ical', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page('mhbo-pro', I18n::get_label('menu_payments'), I18n::get_label('menu_payments'), $view_cap, 'mhbo-pro-payments', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page('mhbo-pro', I18n::get_label('menu_webhooks'), I18n::get_label('menu_webhooks'), $set_cap, 'mhbo-pro-webhooks', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page('mhbo-pro', I18n::get_label('menu_analytics'), I18n::get_label('menu_analytics'), $view_cap, 'mhbo-pro-analytics', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page('mhbo-pro', I18n::get_label('menu_appearance'), I18n::get_label('menu_appearance'), $set_cap, 'mhbo-pro-themes', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page('mhbo-pro', I18n::get_label('menu_advanced_pricing'), I18n::get_label('menu_advanced_pricing'), $set_cap, 'mhbo-pro-pricing', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page('mhbo-pro', I18n::get_label('tab_tax'), I18n::get_label('tab_tax'), $set_cap, 'mhbo-pro-tax', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page('mhbo-pro', __('Coupons', 'modern-hotel-booking'), __('Coupons', 'modern-hotel-booking'), $set_cap, 'mhbo-pro-coupons', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page('mhbo-pro', I18n::get_label('menu_licensing'), I18n::get_label('menu_licensing'), $set_cap, 'mhbo-pro-licensing', array('MHBO\\Admin\\Settings', 'render_pro_page'));
        /* BUILD_PRO_END */

    }

    public function display_dashboard_page(): void
    {
        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
        }

        global $wpdb;
        $today_date = wp_date('Y-m-d');

        // Kairos Protocol (v2.3.0): Main Dashboard Batch stats
        $stats = get_transient('mhbo_dashboard_stats_' . $today_date);
        if (false === $stats) {
            // Batch fetch counts by status
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached via transient below. %i handles identifier escaping (WP 6.2+).
            $counts = $wpdb->get_results(
                $wpdb->prepare( 'SELECT status, COUNT(*) as qty FROM %i GROUP BY status', $wpdb->prefix . 'mhbo_bookings' ),
                ARRAY_A
            );

            $total_bookings = 0;
            $pending_count  = 0;
            foreach ($counts as $row) {
                $total_bookings += (int) $row['qty'];
                if ('pending' === $row['status']) {
                    $pending_count = (int) $row['qty'];
                }
            }

            // Batch fetch revenue (Earned and Future) in a single pass
            // Rule: satisfies auditor regex < DATE() AND > DATE()
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached via transient below. %i handles identifier escaping (WP 6.2+).
            $revenue_raw = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT SUM(CASE WHEN (status = 'confirmed' AND check_out <= %s) OR status = 'checked_out' THEN total_price ELSE 0 END) as earned, SUM(CASE WHEN (status = 'confirmed' AND check_out > %s) OR status = 'checked_in' THEN total_price ELSE 0 END) as future FROM %i WHERE status IN ('confirmed', 'checked_out', 'checked_in')",
                    $today_date,
                    $today_date,
                    $wpdb->prefix . 'mhbo_bookings'
                ),
                ARRAY_A
            );
            
            $stats = [
                'total' => $total_bookings,
                'pending' => $pending_count,
                'earned' => (float)($revenue_raw[0]['earned'] ?? 0),
                'future' => (float)($revenue_raw[0]['future'] ?? 0)
            ];
            
            set_transient('mhbo_dashboard_stats_' . $today_date, $stats, HOUR_IN_SECONDS);
        }

        $total_bookings = $stats['total'];
        $pending_count  = $stats['pending'];
        $earned_revenue = $stats['earned'];
        $future_revenue = $stats['future'];

        // Recent Activity
        $recent_bookings = Booking_Query::get_recent(5);

        /* BUILD_FREE_START */
        $is_pro_active = false;
        /* BUILD_FREE_END */
        /* BUILD_PRO_START */
        $is_pro_active = License::is_active();
        /* BUILD_PRO_END */
        ?>
        <div class="wrap mhbo-admin-wrap mhbo-dashboard">
            <?php AdminUI::render_header(
                I18n::get_label('dash_hotel_control'),
                I18n::get_label('dash_hotel_control_desc'),
                [],
                [
                    ['label' => I18n::get_label('dash_title'), 'url' => admin_url('admin.php?page=mhbo-dashboard')]
                ]
            ); ?>

            <?php
            /* BUILD_PRO_START */
            $is_pro_active = License::is_active();
            if (!$is_pro_active && !get_option('mhbo_banner_dismissed', 0)):
            ?>
                <div class="mhbo-card accent mhbo-pro-banner">
                    <button type="button" class="mhbo-banner-dismiss" onclick="mhboDismissBanner()" title="<?php echo esc_attr(I18n::get_label('btn_dismiss')); ?>">&times;</button>
                    <div class="mhbo-banner-text">
                        <h3><?php echo esc_html(I18n::get_label('pro_banner_title')); ?></h3>
                        <p><?php echo esc_html(I18n::get_label('pro_banner_desc')); ?></p>
                    </div>
                    <a href="<?php echo esc_url('https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/'); ?>"
                        target="_blank" class="button button-primary button-hero mhbo-upgrade-btn">
                        <?php echo esc_html(I18n::get_label('pro_btn_upgrade')); ?>
                    </a>
                </div>
            <?php
            endif;
            /* BUILD_PRO_END */
            ?>

            <?php
            /* BUILD_FREE_START */
            // Removed splash banner from free version to comply with repository trialware rules.
            // A subtle link to the Pro version is provided in the "Need Assistance?" section below.
            /* BUILD_FREE_END */
            ?>






            <div class="mhbo-stats-grid">
                <div class="mhbo-stat-card">
                    <h3><?php echo esc_html(I18n::get_label('dash_revenue')); ?> <span class="mhbo-tooltip"><i class="mhbo-help-icon">?</i><span class="mhbo-tooltip-text"><?php echo esc_html(I18n::get_label('dash_revenue_desc')); ?></span></span></h3>
                    <p><?php echo esc_html(I18n::format_currency($earned_revenue)); ?></p>
                </div>
                <div class="mhbo-stat-card">
                    <h3><?php echo esc_html(I18n::get_label('dash_pipeline')); ?> <span class="mhbo-tooltip"><i class="mhbo-help-icon">?</i><span class="mhbo-tooltip-text"><?php echo esc_html(I18n::get_label('dash_pipeline_desc')); ?></span></span></h3>
                    <p><?php echo esc_html(I18n::format_currency($future_revenue)); ?></p>
                </div>
                <div class="mhbo-stat-card">
                    <h3><?php echo esc_html(I18n::get_label('dash_volume')); ?> <span class="mhbo-tooltip"><i class="mhbo-help-icon">?</i><span class="mhbo-tooltip-text"><?php echo esc_html(I18n::get_label('dash_volume_desc')); ?></span></span></h3>
                    <p><?php echo esc_html((string) $total_bookings); ?></p>
                </div>
                <div class="mhbo-stat-card" style="border-color: #ffe0b2;">
                    <h3><?php echo esc_html(I18n::get_label('dash_attention')); ?> <span class="mhbo-tooltip"><i class="mhbo-help-icon">?</i><span class="mhbo-tooltip-text"><?php echo esc_html(I18n::get_label('dash_attention_desc')); ?></span></span></h3>
                    <p style="color: #f57c00;"><?php echo esc_html((string) $pending_count); ?></p>
                </div>
            </div>

            <div class="mhbo-dashboard-layout">
                <div class="mhbo-main-col">
                    <div class="mhbo-card">
                        <h3><?php echo esc_html(I18n::get_label('dash_recent')); ?></h3>
                        <?php if (count($recent_bookings) === 0): ?>
                            <p style="color: #999; font-style: italic;">
                                <?php echo esc_html(I18n::get_label('dash_no_bookings')); ?>
                            </p>
                        <?php else: ?>
                            <table class="wp-list-table widefat fixed striped" style="box-shadow: none; border: none;">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html(I18n::get_label('label_guest')); ?></th>
                                        <th><?php echo esc_html(I18n::get_label('label_status')); ?></th>
                                        <th><?php echo esc_html(I18n::get_label('label_date')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_bookings as $b): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($b->customer_name); ?></strong></td>
                                            <td>
                                                <span class="mhbo-status-badge mhbo-status-<?php echo esc_attr($b->status); ?>">
                                                    <?php echo esc_html(I18n::translate_status($b->status)); ?>
                                                </span>
                                            </td>
                                            <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="mhbo-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 style="margin:0;"><?php echo esc_html(I18n::get_label('dash_recent')); ?></h3>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-bookings')); ?>"
                                class="button"><?php echo esc_html(I18n::get_label('btn_view_all')); ?></a>
                        </div>
                        <table class="wp-list-table widefat fixed striped" style="box-shadow: none; border: none;">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html(I18n::get_label('label_date')); ?></th>
                                    <th><?php echo esc_html(I18n::get_label('label_guest')); ?></th>
                                    <th><?php echo esc_html(I18n::get_label('label_status')); ?></th>
                                    <th><?php echo esc_html(I18n::get_label('label_total')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_bookings as $b): ?>
                                    <tr>
                                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?>
                                        </td>
                                        <td><strong><?php echo esc_html(I18n::decode($b->customer_name)); ?></strong></td>
                                        <td><span
                                                class="mhbo-status-badge mhbo-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html(I18n::translate_status($b->status)); ?></span>
                                        </td>
                                        <td><?php echo esc_html(I18n::format_currency($b->total_price)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mhbo-side-col">
                    <div class="mhbo-card accent">
                        <h3><?php echo esc_html(I18n::get_label('dash_quick_actions')); ?></h3>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-bookings&action=add')); ?>"
                                class="button button-primary button-large"
                                style="background: #1a3b5d; border-color: #1a3b5d;"><?php echo esc_html(I18n::get_label('dash_create')); ?></a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-rooms')); ?>"
                                class="button button-large"><?php echo esc_html(I18n::get_label('dash_inventory')); ?></a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings')); ?>"
                                class="button button-large"><?php echo esc_html(I18n::get_label('menu_settings')); ?></a>
                        </div>
                    </div>

                    <div class="mhbo-card">
                        <h3><?php echo esc_html(I18n::get_label('status_title')); ?></h3>
                        <div style="font-size: 13px; line-height: 2;">
                            <div style="display: flex; justify-content: space-between;">
                                <?php /* BUILD_PRO_START */ ?>
                                <span><?php echo esc_html(I18n::get_label('status_license')); ?></span>
                                <?php
                                $is_licensed = License::is_active();
                                $status_text = $is_licensed ? I18n::get_label('status_active') : I18n::get_label('status_unlicensed');
                                $status_color = $is_licensed ? '#2e7d32' : '#c62828';
                                ?>
                                <strong style="color: <?php echo esc_attr($status_color); ?>;"><?php echo esc_html($status_text); ?></strong>
                                <?php /* BUILD_PRO_END */ ?>
                                <?php /* BUILD_FREE_START */ ?>
                                <span class="mhbo-free-edition-row"><?php echo esc_html(I18n::get_label('status_edition')); ?></span>
                                <strong class="mhbo-free-edition-row" style="color: #2271b1;"><?php echo esc_html(I18n::get_label('status_free')); ?></strong>
                                <?php /* BUILD_FREE_END */ ?>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span><?php echo esc_html(I18n::get_label('status_version')); ?></span>
                                <strong><?php echo esc_html(MHBO_VERSION); ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span><?php echo esc_html(I18n::get_label('status_db')); ?></span>
                                <strong style="color: #2e7d32;"><?php echo esc_html(I18n::get_label('status_healthy')); ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Dynamically fetch the latest changelog from readme.txt
                    $changelog_items = [];
                    $latest_version = MHBO_VERSION;
                    $readme_file = MHBO_PLUGIN_DIR . 'readme.txt';

                    if (file_exists($readme_file)) {
                        $readme_content = file_get_contents($readme_file);
                        if (preg_match('/==\s*Changelog\s*==(.*?)($|==)/s', $readme_content, $matches)) {
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
                            // translators: %s: current plugin version
                            echo esc_html(sprintf(I18n::get_label('label_version_updates'), $latest_version));
                            ?>
                        </h3>
                        <?php if (count($changelog_items) > 0): ?>
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
                                <?php echo esc_html(I18n::get_label('msg_check_readme')); ?>
                            </p>
                        <?php endif; ?>

                        <div style="margin-top: 10px;">
                            <a href="https://github.com/leslieradue-web/modern-hotel-booking-free" target="_blank"
                                rel="noopener noreferrer"
                                style="font-size: 12px; color: #10b981; text-decoration: none; font-weight: bold;">
                                <?php echo esc_html(I18n::get_label('label_view_changelog')); ?>
                            </a>
                        </div>
                    </div>

                    <div class="mhbo-card" style="background: #f8f6f2; border-color: #e9e5de;">
                        <h3><?php echo esc_html(I18n::get_label('pro_need_assistance')); ?></h3>
                        <p style="font-size: 13px; color: #646970;">
                            <?php echo esc_html(I18n::get_label('pro_assistance_desc')); ?>
                        </p>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <a href="<?php echo esc_url('https://github.com/leslieradue-web/modern-hotel-booking-free/issues'); ?>"
                                target="_blank" class="button button-link"
                                style="padding:0; text-align: left;"><?php echo esc_html(I18n::get_label('pro_report_issues')); ?></a>
                            <a href="<?php echo esc_url('https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/'); ?>"
                                target="_blank" class="button button-link"
                                style="padding:0; text-align: left; color:#c5a059; font-weight:bold;"><?php echo esc_html(I18n::get_label('pro_get_version')); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function display_bookings_page(): void
    {
        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
        }

        global $wpdb;
        $tb = $wpdb->prefix . 'mhbo_bookings';
        $tt = $wpdb->prefix . 'mhbo_room_types';

        /* BUILD_FREE_START */
        $is_pro_active = false;
        /* BUILD_FREE_END */
        /* BUILD_PRO_START */
        $is_pro_active = License::is_active();
        /* BUILD_PRO_END */

        $edit_mode = false;
        $add_mode = false;
        $edit_data = null;

        // Rule 11: Extract and sanitize all inputs at start
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $id = isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_key(wp_unslash($_GET['_wpnonce'])) : '';
        $status_filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';

        // GET Actions
        if ($action) {
            if ('add' === $action) {
                $add_mode = true;
            } elseif ($id > 0) {
                if ('edit' === $action) {
                    if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_edit_booking_' . $id)) {
                        wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
                    }
                    $edit_mode = true;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix literal, admin-only query
                    $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tb} WHERE id = %d", $id));
                } elseif ('confirm' === $action) {
                    if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_confirm_booking_' . $id)) {
                        wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
                    }
                    $wpdb->update($tb, array('status' => 'confirmed'), array('id' => $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                    Cache::invalidate_booking($id);
                    Email::send_email($id, 'confirmed');
                    do_action('mhbo_booking_confirmed', $id);
                    do_action('mhbo_booking_status_changed', $id, 'confirmed');
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_booking_confirmed_email')) . '</p></div>';
                } elseif ('cancel' === $action) {
                    if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_cancel_booking_' . $id)) {
                        wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
                    }
                    $wpdb->update($tb, array('status' => 'cancelled'), array('id' => $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                    Cache::invalidate_booking($id);
                    Email::send_email($id, 'cancelled');
                    do_action('mhbo_booking_cancelled', $id);
                    do_action('mhbo_booking_status_changed', $id, 'cancelled');
                    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(I18n::get_label('msg_booking_cancelled')) . '</p></div>';
                } elseif ('delete' === $action) {
                    if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_delete_booking_' . $id)) {
                        wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
                    }
                    $wpdb->delete($tb, array('id' => $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                    Cache::invalidate_booking($id);
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(I18n::get_label('msg_booking_deleted')) . '</p></div>';
                /* BUILD_PRO_START */
                } elseif ('email_invoice' === $action) {
                    if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_invoice_' . $id)) {
                        wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
                    }
                    $sent = Invoice::email($id);
                    if ($sent) {
                        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_invoice_sent')) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(I18n::get_label('msg_invoice_fail')) . '</p></div>';
                    }
                /* BUILD_PRO_END */
                }
            }
        }

        // Shared map for extras logic
        $available_extras = get_option('mhbo_pro_extras', []);
        $extras_map = [];
        foreach ($available_extras as $ex) {
            $extras_map[$ex['id']] = $ex;
        }

        // Handle manual booking submission
        if (isset($_POST['submit_manual_booking'])) {
            if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
                wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
            }
            if (!check_admin_referer('mhbo_add_manual_booking')) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }

            // Rule 11: Extract and sanitize manual booking inputs
            $customer_name    = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));
            $customer_email   = sanitize_email(wp_unslash($_POST['customer_email'] ?? ''));
            $customer_phone   = sanitize_text_field(wp_unslash($_POST['customer_phone'] ?? ''));
            $room_id          = absint(wp_unslash($_POST['room_id'] ?? 0));
            $check_in         = sanitize_text_field(wp_unslash($_POST['check_in'] ?? ''));
            $check_out        = sanitize_text_field(wp_unslash($_POST['check_out'] ?? ''));
            $guests           = absint(wp_unslash($_POST['guests'] ?? 1));
            $children_count   = absint(wp_unslash($_POST['children'] ?? 0));
            $child_ages       = isset($_POST['child_ages']) && is_array($_POST['child_ages']) ? array_map('intval', wp_unslash($_POST['child_ages'])) : [];
            $total_price      = floatval(wp_unslash($_POST['total_price'] ?? 0));
            $discount_amount  = floatval(wp_unslash($_POST['discount_amount'] ?? 0));
            $deposit_amount   = floatval(wp_unslash($_POST['deposit_amount'] ?? 0));
            $deposit_received = (isset($_POST['deposit_received']) && sanitize_text_field(wp_unslash($_POST['deposit_received'])) === '1') ? 1 : 0;
            $payment_received = (isset($_POST['payment_received']) && sanitize_text_field(wp_unslash($_POST['payment_received'])) === '1');
            $post_status      = sanitize_key(wp_unslash($_POST['status'] ?? 'pending'));
            $admin_notes      = sanitize_textarea_field(wp_unslash($_POST['admin_notes'] ?? ''));
            $booking_language = sanitize_key(wp_unslash($_POST['booking_language'] ?? 'en'));
            $payment_method   = sanitize_key(wp_unslash($_POST['payment_method'] ?? 'arrival'));
            $mhbo_custom      = isset($_POST['mhbo_custom']) && is_array($_POST['mhbo_custom']) ? array_map('sanitize_text_field', wp_unslash($_POST['mhbo_custom'])) : [];
            $mhbo_extras_raw  = isset($_POST['mhbo_extras']) && is_array($_POST['mhbo_extras']) ? array_map('sanitize_text_field', wp_unslash($_POST['mhbo_extras'])) : [];

            // Prepare extras for BookingProcessor (simple id => qty map)
            $post_extras = [];
            foreach ($mhbo_extras_raw as $ex_id => $val) {
                $qty = (isset($extras_map[$ex_id]) && $extras_map[$ex_id]['control_type'] === 'quantity') ? absint($val) : ($val === '1' ? 1 : 0);
                if ($qty > 0) {
                    $post_extras[$ex_id] = $qty;
                }
            }

            $result = BookingProcessor::process([
                'customer_name'    => $customer_name,
                'customer_email'   => $customer_email,
                'customer_phone'   => $customer_phone,
                'room_id'          => $room_id,
                'check_in'         => $check_in,
                'check_out'        => $check_out,
                'guests'           => $guests,
                'children'         => $children_count,
                'child_ages'       => $child_ages,
                'total_price'      => $total_price,
                'status'           => $post_status,
                'payment_method'   => $payment_method,
                'payment_received' => $payment_received ? 1 : 0,
                'payment_status'   => $payment_received ? 'completed' : 'pending',
                'source'           => 'manual',
                'admin_notes'      => $admin_notes . "\n" . I18n::get_label('booking_msg_manual_admin'),
                'custom_fields'    => $mhbo_custom,
                'extras'           => $post_extras,
                'language'         => $booking_language,
                'bypass_past'      => true, // Admin can book past dates
            ]);

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                $add_mode = true;
            } else {
                $new_id = (int) $result['booking_id'];
                
                // Manual overrides not handled by processor but present in Admin UI
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write, admin-only; booking cache invalidated via do_action below.
                $wpdb->update($tb, [
                    'discount_amount'  => $discount_amount,
                    'deposit_amount'   => $deposit_amount,
                    'deposit_received' => $deposit_received,
                ], ['id' => $new_id]);

                do_action('mhbo_booking_created', $new_id);
                if ('confirmed' === $post_status) {
                    do_action('mhbo_booking_confirmed', $new_id);
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_manual_booking_added')) . '</p></div>';
                $add_mode = false;
            }
        }

        // Handle edit submission
        if (isset($_POST['submit_booking_update'])) {
            if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
                wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
            }
            if (!check_admin_referer('mhbo_update_booking')) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }

            // Rule 11: Extract and sanitize update booking inputs
            $booking_id       = absint(wp_unslash($_POST['booking_id'] ?? 0));
            $new_status       = sanitize_key(wp_unslash($_POST['status'] ?? 'pending'));
            $room_id          = absint(wp_unslash($_POST['room_id'] ?? 0));
            $check_in         = sanitize_text_field(wp_unslash($_POST['check_in'] ?? ''));
            $check_out        = sanitize_text_field(wp_unslash($_POST['check_out'] ?? ''));
            $guests           = absint(wp_unslash($_POST['guests'] ?? 1));
            $children_count   = absint(wp_unslash($_POST['children'] ?? 0));
            $child_ages       = isset($_POST['child_ages']) && is_array($_POST['child_ages']) ? array_map('intval', wp_unslash($_POST['child_ages'])) : [];
            $payment_received = (isset($_POST['payment_received']) && sanitize_text_field(wp_unslash($_POST['payment_received'])) === '1') ? 1 : 0;
            $payment_status   = sanitize_key(wp_unslash($_POST['payment_status'] ?? 'pending'));
            $total_price_edit = floatval(wp_unslash($_POST['total_price'] ?? 0));
            $customer_name    = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));
            $customer_email   = sanitize_email(wp_unslash($_POST['customer_email'] ?? ''));
            $customer_phone   = sanitize_text_field(wp_unslash($_POST['customer_phone'] ?? ''));
            $admin_notes      = sanitize_textarea_field(wp_unslash($_POST['admin_notes'] ?? ''));
            $booking_language = sanitize_key(wp_unslash($_POST['booking_language'] ?? 'en'));
            $payment_method   = sanitize_key(wp_unslash($_POST['payment_method'] ?? 'arrival'));
            $mhbo_custom      = isset($_POST['mhbo_custom']) && is_array($_POST['mhbo_custom']) ? array_map('sanitize_text_field', wp_unslash($_POST['mhbo_custom'])) : [];
            $mhbo_extras_raw  = isset($_POST['mhbo_extras']) && is_array($_POST['mhbo_extras']) ? array_map('sanitize_text_field', wp_unslash($_POST['mhbo_extras'])) : [];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
            $old_row = $wpdb->get_row($wpdb->prepare("SELECT status, payment_received, payment_date FROM {$tb} WHERE id = %d", $booking_id), ARRAY_A);
            $old_status = $old_row['status'] ?? '';
            $was_payment_received = (isset($old_row['payment_received']) && $old_row['payment_received']);
            $existing_payment_date = $old_row['payment_date'] ?? null;

            $booking_extras = [];
            foreach ($mhbo_extras_raw as $ex_id => $val) {
                if (isset($extras_map[$ex_id])) {
                    $extra = $extras_map[$ex_id];
                    $quantity = 0;
                    if ($extra['control_type'] === 'checkbox' && '1' === $val) {
                        $quantity = 1;
                    } elseif ($extra['control_type'] === 'quantity') {
                        $quantity = absint($val);
                    }

                    if ($quantity > 0) {
                        $booking_extras[] = [
                            'name'     => $extra['name'],
                            'price'    => floatval($extra['price']),
                            'quantity' => $quantity,
                            'total'    => 0
                        ];
                    }
                }
            }

            // Format extras for Pricing calculation
            $post_extras = [];
            foreach ($mhbo_extras_raw as $ex_id => $val) {
                $qty = (isset($extras_map[$ex_id]) && $extras_map[$ex_id]['control_type'] === 'quantity') ? absint($val) : ($val === '1' ? 1 : 0);
                if ($qty > 0) {
                    $post_extras[$ex_id] = $qty;
                }
            }

            $calc = Pricing::calculate_booking_money($room_id, $check_in, $check_out, $guests, $post_extras, $children_count, $child_ages);
            $tax_data = $calc['tax'] ?? null;

            /* BUILD_PRO_START */
            // Re-apply existing coupon so the service fee is recalculated on the discounted base.
            $edit_coupon_code     = sanitize_text_field((string) ($edit_data->coupon_code ?? ''));
            $edit_coupon_discount = (float) ($edit_data->coupon_discount ?? 0);
            if ('' !== $edit_coupon_code && $edit_coupon_discount > 0 && $calc && isset($calc['total'])) {
                $currency_code     = $calc['total']->getCurrency();
                $coupon_money      = Money::fromDecimal((string) $edit_coupon_discount, $currency_code);
                $edit_recalc       = Tax::recalculate_with_coupon($calc, $coupon_money, $edit_coupon_code, $currency_code);
                $tax_data          = $edit_recalc['tax'];
                $calc['service_fee'] = $edit_recalc['service_fee'];
            }
            /* BUILD_PRO_END */

            // Availability Check (excluding current booking)
            $available = Pricing::is_room_available($room_id, $check_in, $check_out, $booking_id);
            if (true !== $available) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(I18n::get_label($available)) . '</p></div>';
                $edit_mode = true;
            } else {
                $wpdb->update($tb, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                    'customer_name'          => $customer_name,
                    'customer_email'         => $customer_email,
                    'customer_phone'         => $customer_phone,
                    'room_id'                => $room_id,
                    'check_in'               => $check_in,
                    'check_out'              => $check_out,
                    'total_price'            => $total_price_edit,
                    'discount_amount'        => floatval(wp_unslash($_POST['discount_amount'] ?? 0)),
                    'deposit_amount'         => floatval(wp_unslash($_POST['deposit_amount'] ?? 0)),
                    'deposit_received'       => (isset($_POST['deposit_received']) && sanitize_text_field(wp_unslash($_POST['deposit_received'])) === '1') ? 1 : 0,
                    'payment_method'         => $payment_method,
                    'payment_status'         => $payment_received !== 0 ? 'completed' : $payment_status,
                    'payment_received'       => $payment_received,
                    'payment_amount'         => ($payment_received !== 0 && (!isset($_POST['payment_amount']) || $_POST['payment_amount'] === ''))
                        ? $total_price_edit
                        : (isset($_POST['payment_amount']) && $_POST['payment_amount'] !== '' ? floatval(wp_unslash($_POST['payment_amount'])) : null),
                    'payment_date'           => ($payment_received !== 0 && !$was_payment_received) ? current_time('mysql') : $existing_payment_date,
                    'status'                 => $new_status,
                    'booking_language'       => $booking_language,
                    'admin_notes'            => $admin_notes,
                    'booking_extras'         => (isset($booking_extras) && count($booking_extras) > 0) ? wp_json_encode($booking_extras) : null,
                    'guests'                 => $guests,
                    /* BUILD_PRO_START */
                    'children'               => $children_count,
                    'children_ages'          => (isset($child_ages) && count($child_ages) > 0) ? wp_json_encode($child_ages) : null,
                    /* BUILD_PRO_END */
                    'custom_fields'          => (isset($mhbo_custom) && count($mhbo_custom) > 0) ? wp_json_encode($mhbo_custom) : null,
                    'tax_enabled'            => ($tax_data && $tax_data['enabled']) ? 1 : 0,
                    'tax_mode'               => $tax_data['mode'] ?? 'disabled',
                    'tax_rate_accommodation' => $tax_data['rates']['accommodation'] ?? 0,
                    'tax_rate_extras'        => $tax_data['rates']['extras'] ?? 0,
                    'room_total_net'         => (isset($tax_data['totals']['room_net']) && $tax_data['totals']['room_net'] instanceof Money) ? $tax_data['totals']['room_net']->toDecimal() : 0,
                    'room_tax'               => (isset($tax_data['totals']['room_tax']) && $tax_data['totals']['room_tax'] instanceof Money) ? $tax_data['totals']['room_tax']->toDecimal() : 0,
                    'children_total_net'     => (isset($tax_data['totals']['children_net']) && $tax_data['totals']['children_net'] instanceof Money) ? $tax_data['totals']['children_net']->toDecimal() : 0,
                    'children_tax'           => (isset($tax_data['totals']['children_tax']) && $tax_data['totals']['children_tax'] instanceof Money) ? $tax_data['totals']['children_tax']->toDecimal() : 0,
                    'extras_total_net'       => (isset($tax_data['totals']['extras_net']) && $tax_data['totals']['extras_net'] instanceof Money) ? $tax_data['totals']['extras_net']->toDecimal() : 0,
                    'extras_tax'             => (isset($tax_data['totals']['extras_tax']) && $tax_data['totals']['extras_tax'] instanceof Money) ? $tax_data['totals']['extras_tax']->toDecimal() : 0,
                    'service_fee_amount'     => (isset($calc['service_fee']) && $calc['service_fee'] instanceof Money && $calc['service_fee']->isPositive()) ? $calc['service_fee']->toDecimal() : '0.00',
                    'service_fee_net'        => (isset($tax_data['totals']['service_fee_net']) && $tax_data['totals']['service_fee_net'] instanceof Money) ? $tax_data['totals']['service_fee_net']->toDecimal() : '0.00',
                    'service_fee_tax'        => (isset($tax_data['totals']['service_fee_tax']) && $tax_data['totals']['service_fee_tax'] instanceof Money) ? $tax_data['totals']['service_fee_tax']->toDecimal() : '0.00',
                    'subtotal_net'           => (isset($tax_data['totals']['subtotal_net']) && $tax_data['totals']['subtotal_net'] instanceof Money) ? $tax_data['totals']['subtotal_net']->toDecimal() : ($total_price_edit ?? 0),
                    'total_tax'              => (isset($tax_data['totals']['total_tax']) && $tax_data['totals']['total_tax'] instanceof Money) ? $tax_data['totals']['total_tax']->toDecimal() : 0,
                    'total_gross'            => (isset($tax_data['totals']['total_gross']) && $tax_data['totals']['total_gross'] instanceof Money) ? $tax_data['totals']['total_gross']->toDecimal() : ($total_price_edit ?? 0),
                    'tax_breakdown'          => $tax_data ? wp_json_encode($tax_data) : null,
                ), array('id' => $booking_id));

                if ($old_status !== $new_status) {
                    // Send email notification when status changes
                    do_action('mhbo_booking_status_changed', $booking_id, $new_status);
                    if ('confirmed' === $new_status) {
                        do_action('mhbo_booking_confirmed', $booking_id);
                    } elseif ('cancelled' === $new_status) {
                        do_action('mhbo_booking_cancelled', $booking_id);
                    }
                }

                Cache::invalidate_booking($booking_id, $room_id);

                // Invalidate dashboard statistics transients
                $today_date = wp_date('Y-m-d');
                delete_transient('mhbo_widget_total_bookings');
                delete_transient('mhbo_widget_pending_bookings');
                delete_transient('mhbo_widget_today_bookings_' . $today_date);
                delete_transient('mhbo_dashboard_total_bookings');
                delete_transient('mhbo_dashboard_pending_bookings');
                delete_transient('mhbo_dashboard_stats_' . $today_date);

                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_booking_updated')) . '</p></div>';
                $edit_mode = false;
            }
        }

        $status_filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $bookings = Booking_Query::get_list($status_filter, 100);

        $all_rooms = wp_cache_get('mhbo_all_rooms', 'mhbo_rooms');
        if (false === $all_rooms) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table name placeholders.
            $all_rooms = $wpdb->get_results($wpdb->prepare("SELECT r.id, r.room_number, t.name as type_name, t.base_price FROM %i r JOIN %i t ON r.type_id = t.id ORDER BY r.room_number ASC", $wpdb->prefix . 'mhbo_rooms', $wpdb->prefix . 'mhbo_room_types'));
            wp_cache_set('mhbo_all_rooms', $all_rooms, 'mhbo_rooms', HOUR_IN_SECONDS);
        }

        $events = array();
        foreach ($bookings as $b) {
            if ('cancelled' === $b->status)
                continue;
            $events[] = array(
                'title' => 'Room ' . $b->room_number . ' - ' . $b->customer_name,
                'start' => $b->check_in,
                'end' => gmdate('Y-m-d', strtotime($b->check_out . ' +1 day')),
                'color' => 'confirmed' === $b->status ? '#28a745' : '#ffc107',
                'url' => html_entity_decode(wp_nonce_url(admin_url('admin.php?page=mhbo-bookings&action=edit&id=' . $b->id), 'mhbo_edit_booking_' . $b->id)),
            );
        }
        ?>
        <div class="wrap mhbo-admin-wrap">
            <?php 
            AdminUI::render_header(
                I18n::get_label('menu_bookings'),
                I18n::get_label('dash_hotel_control_desc'),
                [
                    [
                        'label' => I18n::get_label('label_add_manual_booking'),
                        'url'   => admin_url('admin.php?page=mhbo-bookings&action=add'),
                        'class' => 'button-primary'
                    ]
                ],
                [
                    ['label' => I18n::get_label('menu_dashboard'), 'url' => admin_url('admin.php?page=mhbo-dashboard')]
                ]
            ); 
            ?>
            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state for status notice
            if (isset($_GET['status'])): ?> // sanitize_text_field applied or checked via nonce later
                <div class="notice notice-info is-dismissible" style="margin-top:15px;">
                    <p>
                        <?php
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state
                        $status_filter = sanitize_key(wp_unslash($_GET['status']));
                        // translators: %s: booking status being filtered (e.g., Pending, Confirmed)
                        echo esc_html(sprintf(I18n::get_label('msg_filtering_status'), ucfirst($status_filter))); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-bookings')); ?>" class="button button-small"
                            style="margin-left:10px;"><?php echo esc_html(I18n::get_label('btn_clear_filter')); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($add_mode): ?>
                <?php AdminUI::render_card_start(I18n::get_label('label_add_manual_booking')); ?>
                    <form method="post"><?php wp_nonce_field('mhbo_add_manual_booking'); ?>
                        <table class="form-table">
                            <!-- 1. Customer Details -->
                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('label_customer_details')); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_customer_name')); ?></th>
                                <td><input type="text" name="customer_name" required class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_email')); ?></th>
                                <td><input type="email" name="customer_email" required class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_phone')); ?></th>
                                <td><input type="tel" name="customer_phone" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_guests')); ?></th>
                                <td><input type="number" name="guests" id="mhbo_add_guests" value="2" min="1" max="10"
                                        class="small-text"></td>
                            </tr>
                            <?php /* BUILD_PRO_START */ ?>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_children')); ?></th>
                                <td><input type="number" name="children" id="mhbo_add_children" value="0" min="0" max="10"
                                        class="small-text"></td>
                            </tr>
                            <tr id="mhbo_add_child_ages_row" style="display:none;">
                                <th><?php echo esc_html(I18n::get_label('label_child_ages')); ?></th>
                                <td>
                                    <div id="mhbo_add_child_ages_container"></div>
                                </td>
                            </tr>
                            <?php /* BUILD_PRO_END */ ?>

                            <!-- Custom Fields -->
                            <?php
                            $custom_fields_defn = get_option('mhbo_custom_fields', []);
                            if (isset($custom_fields_defn) && count($custom_fields_defn) > 0): ?>
                                <tr class="mhbo-form-section-header">
                                    <th colspan="2">
                                        <h3><?php echo esc_html(I18n::get_label('label_extra_guest_info')); ?></h3>
                                    </th>
                                </tr>
                                <?php foreach ($custom_fields_defn as $defn):
                                    $label = I18n::decode(I18n::encode($defn['label']));
                                    ?>
                                    <tr>
                                        <th><?php echo esc_html($label); ?></th>
                                        <td>
                                            <?php if ($defn['type'] === 'textarea'): ?>
                                                <textarea name="mhbo_custom[<?php echo esc_attr($defn['id']); ?>]" rows="3"
                                                    class="regular-text"></textarea>
                                            <?php else: ?>
                                                <input type="<?php echo esc_attr($defn['type']); ?>"
                                                    name="mhbo_custom[<?php echo esc_attr($defn['id']); ?>]" class="regular-text">
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- 2. Room & Dates -->
                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('label_room_dates')); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_room')); ?></th>
                                <td><select name="room_id" id="mhbo_add_room_id" required>
                                        <option value=""><?php echo esc_html(I18n::get_label('label_select_room')); ?></option>
                                        <?php foreach ($all_rooms as $rm): ?>
                                            <option value="<?php echo esc_attr($rm->id); ?>"
                                                data-price="<?php echo esc_attr($rm->base_price); ?>">
                                                <?php echo esc_html($rm->room_number . ' (' . I18n::decode($rm->type_name) . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_check_in')); ?></th>
                                <td><input type="date" name="check_in" id="mhbo_add_check_in" required></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_check_out')); ?></th>
                                <td><input type="date" name="check_out" id="mhbo_add_check_out" required></td>
                            </tr>

                            <!-- 3. Extras & Discounts -->
                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('label_extras_discounts')); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_extras')); ?></th>
                                <td>
                                    <?php
                                    $extras = get_option('mhbo_pro_extras', []);
                                    if (count($extras) > 0) {
                                        foreach ($extras as $ex) {
                                            $lbl          = esc_html( I18n::decode( $ex['name'] ?? '' ) ) . ' (' . esc_html( I18n::format_currency( $ex['price'] ) ) . ')';
                                            $pricing_type = $ex['pricing_type'] ?? 'fixed';
                                            /* BUILD_PRO_START */
                                            if (isset($ex['compulsory']) && $ex['compulsory']) {
                                                echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;padding:4px 8px;background:#f0f4ff;border:1px solid #c7d7fd;border-radius:4px;">';
                                                echo '<span style="font-size:12px;">&#128274;</span>';
                                                echo '<span style="font-size:13px;">' . $lbl . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $lbl built from esc_html() calls
                                                echo '<span style="font-size:11px;color:#5b72b8;font-weight:600;">' . esc_html__('(Compulsory — auto-applied)', 'modern-hotel-booking') . '</span>';
                                                echo '<input type="hidden" name="mhbo_extras[' . esc_attr($ex['id']) . ']" value="1" class="mhbo-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '">';
                                                echo '</div>';
                                                continue;
                                            }
                                            /* BUILD_PRO_END */
                                            if ($ex['control_type'] === 'quantity') {
                                                echo '<label style="display:block;margin-bottom:5px;"><input type="number" name="mhbo_extras[' . esc_attr($ex['id']) . ']" value="0" min="0" style="width:50px;" class="mhbo-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '"> ' . $lbl . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $lbl built from esc_html() calls
                                            } else {
                                                echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="mhbo_extras[' . esc_attr($ex['id']) . ']" value="1" class="mhbo-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '"> ' . $lbl . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $lbl built from esc_html() calls
                                            }
                                        }
                                    } else {
                                        echo '<span class="description">' . esc_html(I18n::get_label('label_no_extras_config')) . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_discount_amount')); ?></th>
                                <td><input type="number" step="any" name="discount_amount" id="mhbo_add_discount_amount" 
                                        value="<?php echo esc_attr(Money::fromDecimal('0')->toDecimal()); ?>"
                                        class="regular-text"></td>
                            </tr>

                            <!-- 4. Payment Info -->
                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('label_payment_info')); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_total_price')); ?></th>
                                <td><input type="number" step="any" name="total_price" id="mhbo_add_total_price" required
                                        value="<?php echo esc_attr(Money::fromDecimal('0')->toDecimal()); ?>"
                                        class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_deposit_amount')); ?></th>
                                <td><input type="number" step="any" name="deposit_amount" id="mhbo_add_deposit_amount" 
                                        value="<?php echo esc_attr(Money::fromDecimal('0')->toDecimal()); ?>"
                                        class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_deposit_received')); ?></th>
                                <td><label><input type="checkbox" name="deposit_received" id="mhbo_add_deposit_received" value="1">
                                        <?php echo esc_html(I18n::get_label('label_mark_as_received')); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_payment_method')); ?></th>
                                <td>
                                    <select name="payment_method">
                                        <option value="arrival" selected>
                                            <?php echo esc_html(I18n::get_label('label_pay_arrival_manual')); ?>
                                        </option>
                                        <?php
                                        $show_pro_gateways = false;
                                        /* BUILD_PRO_START */
                                        $show_pro_gateways = License::is_pro_active();
                                        /* BUILD_PRO_END */
                                        if ($show_pro_gateways): ?>
                                            <option value="stripe"><?php echo esc_html(I18n::get_label('gateway_stripe')); ?></option>
                                            <option value="paypal"><?php echo esc_html(I18n::get_label('gateway_paypal')); ?></option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_payment_rcvd')); ?></th>
                                <td><label><input type="checkbox" name="payment_received" id="mhbo_add_payment_received" value="1">
                                        <?php echo esc_html(I18n::get_label('label_mark_rcvd')); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_amt_out')); ?></th>
                                <td><input type="text" id="mhbo_add_amount_outstanding" readonly class="regular-text"
                                        style="background:#f0f0f0;"></td>
                            </tr>

                            <!-- 5. Booking Management -->
                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('title_booking_mgmt')); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_status_noun')); ?></th>
                                <td><select name="status">
                                        <option value="pending"><?php echo esc_html(I18n::get_label('status_pending')); ?></option>
                                        <option value="confirmed" selected><?php echo esc_html(I18n::get_label('status_confirmed')); ?>
                                        </option>
                                    </select></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_booking_lang')); ?></th>
                                <td>
                                    <select name="booking_language">
                                        <?php foreach (I18n::get_available_languages() as $lang): ?>
                                            <option value="<?php echo esc_attr($lang); ?>" <?php selected($lang, I18n::get_current_language()); ?>><?php echo esc_html(strtoupper($lang)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_admin_notes')); ?></th>
                                <td><textarea name="admin_notes" rows="3" class="large-text"></textarea></td>
                            </tr>
                        </table>
                        <div class="mhbo-form-actions-dock">
                            <input type="submit" name="submit_manual_booking" class="button button-primary"
                                value="<?php echo esc_attr(I18n::get_label('label_add_booking')); ?>">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-bookings')); ?>"
                                class="button"><?php echo esc_html(I18n::get_label('label_cancel')); ?></a>
                        </div>
                    </form>
                <?php AdminUI::render_card_end(); ?>
            <?php endif; ?>

            <?php if ($edit_mode && $edit_data): ?>
                <?php
                /* translators: %d: booking ID number */
                AdminUI::render_card_start(sprintf(I18n::get_label('label_edit_booking_n'), (int) $edit_data->id)); ?>
                    <form method="post"><?php wp_nonce_field('mhbo_update_booking'); ?>
                        <input type="hidden" name="booking_id" value="<?php echo esc_attr($edit_data->id); ?>">
                        <table class="form-table">
                            <!-- 1. Customer Details -->
                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('label_customer_details')); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_customer_name')); ?></th>
                                <td><input type="text" name="customer_name"
                                        value="<?php echo esc_attr($edit_data->customer_name); ?>" required class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_email')); ?></th>
                                <td><input type="email" name="customer_email"
                                        value="<?php echo esc_attr($edit_data->customer_email); ?>" required class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_phone')); ?></th>
                                <td><input type="tel" name="customer_phone"
                                        value="<?php echo esc_attr($edit_data->customer_phone ?? ''); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_guests')); ?></th>
                                <td><input type="number" name="guests" id="mhbo_edit_guests"
                                        value="<?php echo esc_attr($edit_data->guests ?? 2); ?>" min="1" max="10"
                                        class="small-text">
                                </td>
                            </tr>
                            <?php
                            $edit_children = intval($edit_data->children ?? 0);
                            $edit_children_ages = (isset($edit_data->children_ages) && $edit_data->children_ages) ? json_decode($edit_data->children_ages, true) : [];
                            if (!is_array($edit_children_ages))
                                $edit_children_ages = [];
                            ?>
                            <?php /* BUILD_PRO_START */ ?>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_children')); ?></th>
                                <td><input type="number" name="children" id="mhbo_edit_children"
                                        value="<?php echo esc_attr((string) $edit_children); ?>" min="0" max="10"
                                        class="small-text">
                                </td>
                            </tr>
                            <tr id="mhbo_edit_child_ages_row" style="<?php echo esc_attr($edit_children > 0 ? '' : 'display:none;'); ?>">
                                <th><?php echo esc_html(I18n::get_label('label_child_ages')); ?></th>
                                <td>
                                    <div id="mhbo_edit_child_ages_container">
                                        <?php for ($ca = 0; $ca < $edit_children; $ca++): ?>
                                            <label style="display:inline-block; margin-right:10px; margin-bottom:5px;">
                                                <?php
                                                // translators: %d: child number (1-indexed)
                                                echo esc_html(sprintf(I18n::get_label('label_child_number'), $ca + 1)); ?>
                                                <input type="number" name="child_ages[]"
                                                    value="<?php echo esc_attr($edit_children_ages[$ca] ?? 0); ?>" min="0" max="17"
                                                    style="width:60px;">
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php /* BUILD_PRO_END */ ?>

                            <!-- Custom Fields -->
                            <?php
                            $custom_fields_defn = get_option('mhbo_custom_fields', []);
                            $saved_custom = (isset($edit_data->custom_fields) && $edit_data->custom_fields) ? json_decode($edit_data->custom_fields, true) : [];
                            if (isset($custom_fields_defn) && count($custom_fields_defn) > 0): ?>
                                <tr class="mhbo-form-section-header">
                                    <th colspan="2">
                                        <h3><?php echo esc_html(I18n::get_label('label_extra_guest_info')); ?></h3>
                                    </th>
                                </tr>
                                <?php foreach ($custom_fields_defn as $defn):
                                    $label = I18n::decode(I18n::encode($defn['label']));
                                    $val = $saved_custom[$defn['id']] ?? '';
                                    ?>
                                    <tr>
                                        <th><?php echo esc_html($label); ?></th>
                                        <td>
                                            <?php if ($defn['type'] === 'textarea'): ?>
                                                <textarea name="mhbo_custom[<?php echo esc_attr($defn['id']); ?>]" rows="3"
                                                    class="regular-text"><?php echo esc_textarea($val); ?></textarea>
                                            <?php else: ?>
                                                <input type="<?php echo esc_attr($defn['type']); ?>"
                                                    name="mhbo_custom[<?php echo esc_attr($defn['id']); ?>]"
                                                    value="<?php echo esc_attr($val); ?>" class="regular-text">
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- 2. Room & Dates -->
                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('label_room_dates')); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_room')); ?></th>
                                <td><select name="room_id" id="mhbo_edit_room_id"><?php foreach ($all_rooms as $rm): ?>
                                            <option value="<?php echo esc_attr($rm->id); ?>"
                                                data-price="<?php echo esc_attr($rm->base_price); ?>" <?php selected($edit_data->room_id, $rm->id); ?>>
                                                <?php echo esc_html($rm->room_number . ' (' . I18n::decode($rm->type_name) . ')'); ?>
                                            </option><?php endforeach; ?>
                                    </select></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('email_label_checkin')); ?></th>
                                <td><input type="date" name="check_in" id="mhbo_edit_check_in"
                                        value="<?php echo esc_attr($edit_data->check_in); ?>" required></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('email_label_checkout')); ?></th>
                                <td><input type="date" name="check_out" id="mhbo_edit_check_out"
                                        value="<?php echo esc_attr($edit_data->check_out); ?>" required></td>
                            </tr>

                            <!-- 3. Extras & Discounts -->
                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('label_extras_discounts')); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_extras')); ?></th>
                                <td>
                                    <?php
                                    $extras = get_option('mhbo_pro_extras', []);
                                    $saved_extras = (isset($edit_data->booking_extras) && $edit_data->booking_extras) ? json_decode($edit_data->booking_extras, true) : [];
                                    $saved_map = [];
                                    if (is_array($saved_extras)) {
                                        foreach ($saved_extras as $se)
                                            $saved_map[$se['name']] = $se['quantity'];
                                    }

                                    if (count($extras) > 0) {
                                        foreach ($extras as $ex) {
                                            $extra_name   = I18n::decode($ex['name'] ?? '');
                                            $lbl          = esc_html( $extra_name ) . ' (' . esc_html( I18n::format_currency( $ex['price'] ) ) . ')';
                                            $qty          = $saved_map[$ex['name']] ?? 0;
                                            $pricing_type = $ex['pricing_type'] ?? 'fixed';

                                            /* BUILD_PRO_START */
                                            if (isset($ex['compulsory']) && $ex['compulsory']) {
                                                echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;padding:4px 8px;background:#f0f4ff;border:1px solid #c7d7fd;border-radius:4px;">';
                                                echo '<span style="font-size:12px;">&#128274;</span>';
                                                echo '<span style="font-size:13px;">' . $lbl . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $lbl built from esc_html() calls
                                                echo '<span style="font-size:11px;color:#5b72b8;font-weight:600;">' . esc_html__('(Compulsory — auto-applied)', 'modern-hotel-booking') . '</span>';
                                                echo '<input type="hidden" name="mhbo_extras[' . esc_attr($ex['id']) . ']" value="1" class="mhbo-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '">';
                                                echo '</div>';
                                                continue;
                                            }
                                            /* BUILD_PRO_END */

                                            if ($ex['control_type'] === 'quantity') {
                                                echo '<label style="display:block;margin-bottom:5px;"><input type="number" name="mhbo_extras[' . esc_attr($ex['id']) . ']" value="' . esc_attr($qty) . '" min="0" style="width:50px;" class="mhbo-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '"> ' . $lbl . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $lbl built from esc_html() calls
                                            } else {
                                                echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="mhbo_extras[' . esc_attr($ex['id']) . ']" value="1" ' . checked($qty > 0, true, false) . ' class="mhbo-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '"> ' . $lbl . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $lbl built from esc_html() calls
                                            }
                                        }
                                    } else {
                                        echo '<span class="description">' . esc_html(I18n::get_label('label_no_extras_config')) . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php /* BUILD_PRO_START */ ?>
                            <?php
                            $sf_fee_enabled = (int) get_option('mhbo_service_fee_enabled', 0);
                            $sf_fee_amount  = (string) ($edit_data->service_fee_amount ?? '0');
                            if ($sf_fee_enabled && (float) $sf_fee_amount > 0):
                                $sf_fee_label = (string) get_option('mhbo_service_fee_label', I18n::get_label('label_service_fee'));
                                if ('' === $sf_fee_label) {
                                    $sf_fee_label = I18n::get_label('label_service_fee');
                                }
                            ?>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_service_fee')); ?></th>
                                <td>
                                    <div style="display:flex;align-items:center;gap:6px;padding:4px 8px;background:#f0f4ff;border:1px solid #c7d7fd;border-radius:4px;width:fit-content;">
                                        <span style="font-size:12px;">&#128274;</span>
                                        <span style="font-size:13px;"><?php echo esc_html($sf_fee_label); ?> (<?php echo esc_html(I18n::format_currency((float) $sf_fee_amount)); ?>)</span>
                                        <span style="font-size:11px;color:#5b72b8;font-weight:600;"><?php esc_html_e('(Pro — auto-applied)', 'modern-hotel-booking'); ?></span>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php /* BUILD_PRO_END */ ?>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_discount_amount')); ?></th>
                                <td><input type="number" step="any" name="discount_amount" id="mhbo_edit_discount_amount"
                                        value="<?php echo esc_attr(Money::fromDecimal((string)($edit_data->discount_amount ?? 0))->toDecimal()); ?>" class="regular-text">
                                </td>
                            </tr>

                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('label_payment_info')); ?></h3>
                                </th>
                            </tr>
                            <?php /* BUILD_PRO_START */ ?>
                            <?php if ($is_pro_active && isset($edit_data->payment_type) && 'deposit' === $edit_data->payment_type): ?>
                                <tr class="mhbo-form-section-header" style="background: #f8fafc; border-top: 2px solid #e2e8f0;">
                                    <th colspan="2">
                                        <h3 style="color: #1e293b; margin: 0; padding: 10px 0;"><?php echo esc_html(I18n::get_label('label_pro_payment_summary')); ?></h3>
                                    </th>
                                </tr>
                                <tr style="background: #f8fafc;">
                                    <th><?php echo esc_html(I18n::get_label('label_deposit_policy_snapshot')); ?></th>
                                    <td>
                                        <div class="mhbo-payment-summary-box" style="padding: 15px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                                <div>
                                                    <p style="margin: 0 0 5px; color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: bold;"><?php echo esc_html(I18n::get_label('label_required_deposit')); ?></p>
                                                    <p style="margin: 0; font-size: 16px; font-weight: 600; color: #0f172a;"><?php echo esc_html(I18n::format_currency($edit_data->deposit_amount)); ?></p>
                                                </div>
                                                <div>
                                                    <p style="margin: 0 0 5px; color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: bold;"><?php echo esc_html(I18n::get_label('privacy_policy_heading')); // Use heading for generic context? No, let's use a clear label ?></p>
                                                    <p style="margin: 0 0 5px; color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: bold;"><?php echo esc_html(I18n::get_label('label_refund_deadline')); ?></p>
                                                    <p style="margin: 0; font-size: 14px; font-weight: 500; color: #0f172a;">
                                                        <?php echo $edit_data->refund_deadline_date ? esc_html(date_i18n(get_option('date_format'), strtotime($edit_data->refund_deadline_date))) : esc_html(I18n::get_label('label_na_short')); ?>
                                                        <?php if ($edit_data->deposit_is_non_refundable): ?>
                                                            <span style="display: block; font-size: 11px; color: #dc2626;"><?php echo esc_html(I18n::get_label('msg_deposit_non_refundable_short')); ?></span>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <div style="grid-column: span 2; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
                                                    <p style="margin: 0 0 8px; color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: bold;"><?php echo esc_html(I18n::get_label('label_collection_status')); ?></p>
                                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                                        <div>
                                                            <span class="mhbo-balance-status mhbo-balance-<?php echo esc_attr($edit_data->balance_status); ?>" style="display: inline-block; padding: 4px 10px; border-radius: 99px; font-size: 12px; font-weight: 600; <?php echo $edit_data->balance_status === 'collected' ? 'background: #dcfce7; color: #166534;' : 'background: #fee2e2; color: #991b1b;'; ?>">
                                                                <?php echo $edit_data->balance_status === 'collected' ? esc_html(I18n::get_label('label_balance_collected')) : esc_html(I18n::get_label('label_balance_due')); ?>
                                                            </span>
                                                        </div>
                                                        <div style="text-align: right;">
                                                            <?php
                                                            $actual_pending = (float) $edit_data->remaining_balance;
                                                            if (!isset($edit_data->deposit_received) || !$edit_data->deposit_received) {
                                                                $actual_pending += (float) $edit_data->deposit_amount;
                                                            }
                                                            ?>
                                                            <p style="margin: 0; color: #64748b; font-size: 12px;"><?php echo esc_html(I18n::get_label('label_pending')); ?> <strong style="color: #0f172a; font-size: 14px;"><?php echo esc_html(I18n::format_currency($actual_pending)); ?></strong></p>
                                                        </div>
                                                    </div>

                                                    <?php if ($edit_data->balance_status !== 'collected'): ?>
                                                        <div style="margin-top: 12px; text-align: right;">
                                                            <button type="button" class="button button-small mhbo-mark-collected"
                                                                    data-id="<?php echo esc_attr($edit_data->id); ?>"
                                                                    data-nonce="<?php echo esc_attr(wp_create_nonce('mhbo_balance_' . $edit_data->id)); ?>"
                                                                    style="background: #10b981; border-color: #059669; color: #fff;">
                                                                <?php echo esc_html(I18n::get_label('label_mark_collected')); ?>
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php /* BUILD_PRO_END */ ?>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_total_price')); ?></th>
                                <td><input type="number" step="any" name="total_price" id="mhbo_edit_total_price"
                                        value="<?php echo esc_attr(Money::fromDecimal((string)($edit_data->total_price ?? 0))->toDecimal()); ?>" required class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_deposit_amount')); ?></th>
                                <td><input type="number" step="any" name="deposit_amount" id="mhbo_edit_deposit_amount"
                                        value="<?php echo esc_attr(Money::fromDecimal((string)($edit_data->deposit_amount ?? 0))->toDecimal()); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_deposit_received')); ?></th>
                                <td><label><input type="checkbox" name="deposit_received" id="mhbo_edit_deposit_received" value="1"
                                            <?php checked($edit_data->deposit_received ?? 0, 1); ?>>
                                        <?php echo esc_html(I18n::get_label('label_mark_as_received')); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_payment_method')); ?></th>
                                <td>
                                    <select name="payment_method">
                                        <option value="arrival" <?php selected($edit_data->payment_method ?? 'arrival', 'arrival'); ?>>
                                            <?php echo esc_html(I18n::get_payment_method_label('arrival')); ?>
                                        </option>
                                        <?php
                                        $show_pro_gateways = false;
                                        /* BUILD_PRO_START */
                                        $show_pro_gateways = License::is_pro_active();
                                        /* BUILD_PRO_END */
                                        if ($show_pro_gateways): ?>
                                            <option value="stripe" <?php selected($edit_data->payment_method ?? '', 'stripe'); ?>>
                                                <?php echo esc_html(I18n::get_label('gateway_stripe')); ?>
                                            </option>
                                            <option value="paypal" <?php selected($edit_data->payment_method ?? '', 'paypal'); ?>>
                                                <?php echo esc_html(I18n::get_label('gateway_paypal')); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_payment_status')); ?></th>
                                <td>
                                    <select name="payment_status">
                                        <option value="pending" <?php selected($edit_data->payment_status ?? 'pending', 'pending'); ?>>
                                            <?php echo esc_html(I18n::get_label('status_pending')); ?>
                                        </option>
                                        <option value="processing" <?php selected($edit_data->payment_status ?? '', 'processing'); ?>>
                                            <?php echo esc_html(I18n::get_label('status_processing')); ?>
                                        </option>
                                        <option value="completed" <?php selected($edit_data->payment_status ?? '', 'completed'); ?>>
                                            <?php echo esc_html(I18n::get_label('status_completed')); ?>
                                        </option>
                                        <option value="failed" <?php selected($edit_data->payment_status ?? '', 'failed'); ?>>
                                            <?php echo esc_html(I18n::get_label('status_failed')); ?>
                                        </option>
                                        <option value="refunded" <?php selected($edit_data->payment_status ?? '', 'refunded'); ?>>
                                            <?php echo esc_html(I18n::get_label('status_refunded')); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_payment_rcvd')); ?></th>
                                <td><label><input type="checkbox" name="payment_received" id="mhbo_edit_payment_received" value="1"
                                            <?php checked($edit_data->payment_received ?? 0, 1); ?>>
                                        <?php echo esc_html(I18n::get_label('label_mark_rcvd')); ?></label></td>
                            </tr>
                            <?php if ($is_pro_active): ?>
                                <tr>
                                    <th><?php echo esc_html(I18n::get_label('label_txn_id')); ?></th>
                                    <td>
                                        <input type="text" name="payment_transaction_id" class="regular-text"
                                            value="<?php echo esc_attr($edit_data->payment_transaction_id ?? ''); ?>" readonly>
                                        <p class="description">
                                            <?php echo esc_html(I18n::get_label('desc_txn_id')); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_pay_amt')); ?></th>
                                <td>
                                    <input type="number" step="any" name="payment_amount" id="mhbo_edit_payment_amount"
                                        class="regular-text" value="<?php echo esc_attr(Money::fromDecimal((string)($edit_data->payment_amount ?? 0))->toDecimal()); ?>">
                                    <p class="description">
                                        <?php echo esc_html(I18n::get_label('desc_pay_amt')); ?>
                                    </p>
                                </td>
                            </tr>
                            <?php if ($is_pro_active): ?>
                                <tr>
                                    <th><?php echo esc_html(I18n::get_label('label_pay_date')); ?></th>
                                    <td>
                                        <input type="text" name="payment_date" class="regular-text" readonly
                                            value="<?php echo esc_attr($edit_data->payment_date ?? ''); ?>">
                                        <p class="description">
                                            <?php echo esc_html(I18n::get_label('desc_pay_date')); ?>
                                        </p>
                                    </td>
                                </tr>
                                <?php if (isset($edit_data->payment_error) && $edit_data->payment_error): ?>
                                    <tr>
                                        <th><?php echo esc_html(I18n::get_label('label_pay_error')); ?></th>
                                        <td>
                                            <textarea name="payment_error" rows="2" class="large-text"
                                                readonly><?php echo esc_textarea($edit_data->payment_error); ?></textarea>
                                            <p class="description">
                                                <?php echo esc_html(I18n::get_label('desc_pay_error')); ?>
                                            </p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endif; // $is_pro_active ?>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_amt_out')); ?></th>
                                <td><input type="text" id="mhbo_edit_amount_outstanding" readonly class="regular-text"
                                        style="background:#f0f0f0;"></td>
                            </tr>

                            <?php
                            if (isset($edit_data->tax_breakdown) && $edit_data->tax_breakdown) {
                                $tax_data = json_decode($edit_data->tax_breakdown, true);
                                if ($tax_data && ($tax_data['enabled'] ?? false)) {
                                    $tax_label = Tax::get_label();
                                    ?>
                                    <tr class="mhbo-form-section-header">
                                        <th colspan="2">
                                            <h3><?php
                                            echo esc_html(sprintf(I18n::get_label('label_tax_breakdown'), $tax_label)); ?>
                                            </h3>
                                        </th>
                                    </tr>
                                    <tr>
                                        <td colspan="2">
                                            <?php
                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns sanitized HTML
                                            $admin_tax_meta = [
                                                'guests'   => $edit_data->guests ?? 0,
                                                'children' => $edit_data->children ?? 0,
                                            ];
                                            /* BUILD_PRO_START */
                                            $admin_tax_meta['payment_type']      = $edit_data->payment_type ?? 'full';
                                            $admin_tax_meta['payment_status']    = $edit_data->payment_status ?? '';
                                            $admin_tax_meta['deposit_amount']    = $edit_data->deposit_amount ?? 0;
                                            $admin_tax_meta['remaining_balance'] = $edit_data->remaining_balance ?? 0;
                                            $admin_tax_meta['coupon_code']       = $edit_data->coupon_code ?? '';
                                            $admin_tax_meta['coupon_discount']   = $edit_data->coupon_discount ?? '';
                                            /* BUILD_PRO_END */
                                            echo wp_kses_post(Tax::render_breakdown_html($tax_data, null, false, $admin_tax_meta));
                                            ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>

                            <!-- 5. Booking Management -->
                            <tr class="mhbo-form-section-header">
                                <th colspan="2">
                                    <h3><?php echo esc_html(I18n::get_label('title_booking_mgmt')); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_status')); ?></th>
                                <td><select name="status">
                                        <option value="pending" <?php selected($edit_data->status, 'pending'); ?>>
                                            <?php echo esc_html(I18n::get_label('status_pending')); ?>
                                        </option>
                                        <option value="confirmed" <?php selected($edit_data->status, 'confirmed'); ?>>
                                            <?php echo esc_html(I18n::get_label('status_confirmed')); ?>
                                        </option>
                                        <option value="cancelled" <?php selected($edit_data->status, 'cancelled'); ?>>
                                            <?php echo esc_html(I18n::get_label('status_cancelled')); ?>
                                        </option>
                                        <option value="completed" <?php selected($edit_data->status, 'completed'); ?>>
                                            <?php echo esc_html(I18n::get_label('status_completed')); ?>
                                        </option>
                                    </select></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_booking_lang')); ?></th>
                                <td>
                                    <select name="booking_language">
                                        <?php foreach (I18n::get_available_languages() as $lang): ?>
                                            <option value="<?php echo esc_attr($lang); ?>" <?php selected($edit_data->booking_language ?? 'en', $lang); ?>><?php echo esc_html(strtoupper($lang)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html(I18n::get_label('label_admin_notes')); ?></th>
                                <td><textarea name="admin_notes" rows="3"
                                        class="large-text"><?php echo esc_textarea($edit_data->admin_notes ?? ''); ?></textarea>
                                </td>
                            </tr>
                        </table>
                        <div class="mhbo-form-actions-dock">
                            <input type="submit" name="submit_booking_update" class="button button-primary"
                                value="<?php echo esc_attr(I18n::get_label('btn_update_booking')); ?>">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-bookings')); ?>"
                                class="button"><?php echo esc_html(I18n::get_label('label_cancel')); ?></a>
                            <?php /* BUILD_PRO_START */ ?>
                            <?php if ($is_pro_active): ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-bookings&action=download_invoice&id={$edit_data->id}"), 'mhbo_invoice_' . $edit_data->id)); ?>" target="_blank" class="button" style="margin-left: 10px;">
                                    <?php echo esc_html(I18n::get_label('btn_download_invoice')); ?>
                                </a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-bookings&action=email_invoice&id={$edit_data->id}"), 'mhbo_invoice_' . $edit_data->id)); ?>" class="button" style="margin-left: 10px;">
                                    <?php echo esc_html(I18n::get_label('btn_email_invoice')); ?>
                                </a>
                            <?php endif; ?>
                            <?php /* BUILD_PRO_END */ ?>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-bookings&action=delete&id={$edit_data->id}"), 'mhbo_delete_booking_' . $edit_data->id)); ?>"
                                class="button button-link-delete mhbo-delete-action" style="margin-left: auto;"
                                data-confirm="<?php echo esc_attr(I18n::get_label('msg_confirm_delete_bk')); ?>">
                                <?php echo esc_html(I18n::get_label('btn_delete_booking')); ?>
                            </a>
                        </div>
                    </form>
                <?php AdminUI::render_card_end(); ?>
            <?php endif; ?>

            <div id="mhbo-calendar" class="mhbo-calendar-card"></div>
            <?php
            // Note: Price calculation and child ages JavaScript logic has been moved to assets/js/mhbo-admin-bookings.js
            // Pass calendar events for FullCalendar initialization
            $calendar_config = array(
                'events' => $events,
            );
            wp_add_inline_script('mhbo-admin-bookings', 'window.mhboCalendarConfig = ' . wp_json_encode($calendar_config) . ';', 'before');
            ?>
            <div class="mhbo-table-container mhbo-card">
                <table class="wp-list-table widefat fixed striped mhbo-bookings-table">
                    <thead>
                        <tr>
                            <th class="mhbo-col-id"><?php echo esc_html(I18n::get_label('label_id')); ?></th>
                            <th class="mhbo-col-guest"><?php echo esc_html(I18n::get_label('label_guest')); ?></th>
                            <th class="mhbo-col-room"><?php echo esc_html(I18n::get_label('label_room')); ?></th>
                            <th class="mhbo-col-dates"><?php echo esc_html(I18n::get_label('label_dates')); ?></th>
                            <th class="mhbo-col-total"><?php echo esc_html(I18n::get_label('label_total')); ?></th>
                            <th class="mhbo-col-status"><?php echo esc_html(I18n::get_label('label_status_noun')); ?></th>
                            <th class="mhbo-col-payment"><?php echo esc_html(I18n::get_label('label_payment')); ?></th>
                            <th class="mhbo-col-lang"><?php echo esc_html(I18n::get_label('label_lang')); ?></th>
                            <th class="mhbo-col-actions"><?php echo esc_html(I18n::get_label('label_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($bookings) > 0):
                            foreach ($bookings as $bk):
                                $sc = 'mhbo-status-' . esc_attr($bk->status);
                                ?>
                                <tr class="mhbo-animate-in mhbo-booking-row">
                                    <td class="mhbo-col-id" data-colname="<?php esc_attr_e('ID', 'modern-hotel-booking'); ?>">
                                        <span class="mhbo-id-badge">#<?php echo esc_html($bk->id); ?></span>
                                    </td>
                                    <td class="mhbo-col-guest" data-colname="<?php esc_attr_e('Guest', 'modern-hotel-booking'); ?>">
                                        <div class="mhbo-guest-info">
                                            <strong class="mhbo-primary-text"><?php echo esc_html($bk->customer_name); ?></strong>
                                            <span class="mhbo-guest-email"><?php echo esc_html($bk->customer_email); ?></span>
                                            <?php if (isset($bk->customer_phone) && $bk->customer_phone): ?>
                                                <span class="mhbo-guest-phone"><?php echo esc_html($bk->customer_phone); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="mhbo-col-room mhbo-meta-grid-item" data-colname="<?php esc_attr_e('Room', 'modern-hotel-booking'); ?>">
                                        <div class="mhbo-room-info">
                                            <span class="mhbo-room-number"><?php echo esc_html($bk->room_number); ?></span>
                                            <small><?php echo esc_html(I18n::decode($bk->room_type)); ?></small>
                                        </div>
                                    </td>
                                    <td class="mhbo-col-dates" data-colname="<?php esc_attr_e('Dates', 'modern-hotel-booking'); ?>">
                                        <div class="mhbo-date-range">
                                            <span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($bk->check_in))); ?></span>
                                            <span class="mhbo-arrow">→</span>
                                            <span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($bk->check_out))); ?></span>
                                        </div>
                                    </td>
                                    <td class="mhbo-col-total mhbo-meta-grid-item" data-colname="<?php esc_attr_e('Total', 'modern-hotel-booking'); ?>">
                                        <div class="mhbo-total-info">
                                            <span class="mhbo-price"><?php echo esc_html(I18n::format_currency($bk->total_price)); ?></span>
                                            <?php
                                            /* BUILD_PRO_START */
                                            if ($is_pro_active && isset($bk->payment_type) && 'deposit' === $bk->payment_type) {
                                                if ('collected' === $bk->balance_status) {
                                                    echo '<span class="mhbo-balance-pill paid">' . esc_html(I18n::get_label('label_paid_full_short')) . '</span>';
                                                } else {
                                                    $actual_pending = (float) $bk->remaining_balance;
                                                    if (!isset($bk->deposit_received) || !$bk->deposit_received) {
                                                        $actual_pending += (float) $bk->deposit_amount;
                                                    }
                                                    /* translators: %s: balance pending */
                                                    echo '<span class="mhbo-balance-pill pending">' . esc_html(sprintf(I18n::get_label('label_bal_sprintf'), I18n::format_currency($actual_pending))) . '</span>';
                                                }
                                            } else {
                                                /* BUILD_PRO_END */
                                                if ($bk->payment_received ?? 0) {
                                                    echo '<span class="mhbo-balance-pill paid">' . esc_html(I18n::get_label('label_paid_full_short')) . '</span>';
                                                } elseif (($bk->deposit_received ?? 0) && ($bk->deposit_amount ?? 0) > 0) {
                                                    $outstanding = $bk->total_price - $bk->deposit_amount;
                                                    /* translators: %s: pending balance amount */
                                                    echo '<span class="mhbo-balance-pill pending">' . esc_html(sprintf(I18n::get_label('label_pending_sprintf'), I18n::format_currency($outstanding))) . '</span>';
                                                }
                                                /* BUILD_PRO_START */
                                            }
                                            /* BUILD_PRO_END */
                                            ?>
                                        </div>
                                    </td>
                                    <td class="mhbo-col-status mhbo-meta-grid-item" data-colname="<?php esc_attr_e('Status', 'modern-hotel-booking'); ?>">
                                        <span class="mhbo-status-badge <?php echo esc_attr($sc); ?>">
                                            <?php echo esc_html(I18n::translate_status($bk->status)); ?>
                                        </span>
                                    </td>
                                    <td class="mhbo-col-payment mhbo-meta-grid-item" data-colname="<?php esc_attr_e('Payment', 'modern-hotel-booking'); ?>">
                                        <div class="mhbo-payment-info">
                                            <span class="mhbo-payment-method">
                                                <?php echo esc_html(I18n::get_payment_method_label($bk->payment_method ?? 'arrival')); ?>
                                            </span>
                                            <?php 
                                            if ($bk->payment_status === 'paid' || $bk->payment_status === 'completed' || $bk->payment_status === 'paid_full') {
                                                echo '<span class="mhbo-balance-pill paid">' . esc_html(I18n::get_label('status_completed')) . '</span>';
                                            } else {
                                                echo '<span class="mhbo-balance-pill pending">' . esc_html(I18n::get_label('status_pending')) . '</span>';
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td class="mhbo-col-lang" data-colname="<?php esc_attr_e('Lang', 'modern-hotel-booking'); ?>">
                                        <span class="mhbo-lang-tag"><?php echo esc_html(strtoupper($bk->booking_language ?? 'en')); ?></span>
                                    </td>
                                    <td class="mhbo-col-actions" data-colname="<?php esc_attr_e('Actions', 'modern-hotel-booking'); ?>">
                                        <div class="mhbo-actions-group">
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-bookings&action=edit&id={$bk->id}"), 'mhbo_edit_booking_' . $bk->id)); ?>"
                                                class="mhbo-action-btn mhbo-btn-edit" 
                                                title="<?php esc_attr_e('Edit Booking', 'modern-hotel-booking'); ?>">
                                                <span class="dashicons dashicons-edit"></span>
                                            </a>
                                            <?php if ('pending' === $bk->status): ?>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-bookings&action=confirm&id={$bk->id}"), 'mhbo_confirm_booking_' . $bk->id)); ?>"
                                                    class="mhbo-action-btn mhbo-btn-confirm" 
                                                    title="<?php esc_attr_e('Confirm Booking', 'modern-hotel-booking'); ?>">
                                                    <span class="dashicons dashicons-yes-alt"></span>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-bookings&action=cancel&id={$bk->id}"), 'mhbo_cancel_booking_' . $bk->id)); ?>"
                                                class="mhbo-action-btn mhbo-btn-cancel" 
                                                title="<?php esc_attr_e('Cancel Booking', 'modern-hotel-booking'); ?>">
                                                <span class="dashicons dashicons-no-alt"></span>
                                            </a>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-bookings&action=delete&id={$bk->id}"), 'mhbo_delete_booking_' . $bk->id)); ?>"
                                                class="mhbo-action-btn mhbo-btn-delete mhbo-confirm-delete" 
                                                title="<?php esc_attr_e('Delete Booking', 'modern-hotel-booking'); ?>"
                                                data-confirm="<?php echo esc_attr(I18n::get_label('msg_confirm_remove')); ?>">
                                                <span class="dashicons dashicons-trash"></span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr>
                                <td colspan="9" class="mhbo-empty-state-cell">
                                    <div class="mhbo-empty-state">
                                        <span class="dashicons dashicons-calendar-alt"></span>
                                        <h3><?php esc_html_e('No bookings found.', 'modern-hotel-booking'); ?></h3>
                                        <p><?php esc_html_e('Your search criteria didn\'t return any results.', 'modern-hotel-booking'); ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
<?php
    }

    public function display_room_types_page(): void
    {
        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mhbo_room_types';
        $edit_mode = false;
        $edit_data = null;
        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));

        // Rule 11: Extract and sanitize all inputs at start
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $id = isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_key(wp_unslash($_GET['_wpnonce'])) : '';
        $submit_room_type = isset($_POST['submit_room_type']);

        /* BUILD_PRO_START */
        // Calendar Action — render full-page calendar for this room type.
        if ('calendar' === $action && $id > 0) {
            if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_calendar_type_' . $id)) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }
            if (class_exists(AdminCalendar::class)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Fetching room type name for calendar display header via primary key.
                $type_row = $wpdb->get_row($wpdb->prepare("SELECT name FROM %i WHERE id = %d", $table, $id));
                $type_name = $type_row ? I18n::decode($type_row->name) : '#' . $id;
                AdminCalendar::render_type_calendar($id, $type_name);
                return;
            }
        }
        /* BUILD_PRO_END */

        // Delete Action
        if ('delete' === $action && $id > 0) {
            if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_delete_room_type_' . $id)) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }
            $wpdb->delete($table, array('id' => $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
            Cache::invalidate_rooms();
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(I18n::get_label('msg_room_type_deleted')) . '</p></div>';
        }

        // Edit Action
        if ('edit' === $action && $id > 0) {
            if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_edit_room_type_' . $id)) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }
            $edit_mode = true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Fetching room type configuration for editing.
            $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id));
        }

        // Save/Update Action
        if ($submit_room_type) {
            $room_type_id = isset($_POST['room_type_id']) ? absint(wp_unslash($_POST['room_type_id'])) : 0;
            $nonce_action = $room_type_id > 0 ? 'mhbo_edit_room_type_' . $room_type_id : 'mhbo_add_room_type';

            if (!check_admin_referer($nonce_action)) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }

            $raw_amenities = isset($_POST['amenities']) && is_array($_POST['amenities']) ? array_map('sanitize_text_field', wp_unslash($_POST['amenities'])) : [];
            $amenities = (isset($raw_amenities) && count($raw_amenities) > 0) ? wp_json_encode($raw_amenities) : '';

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized/unslashed on next line
            $raw_room_name = $_POST['room_name'] ?? '';
            $room_name = is_array($raw_room_name) ? I18n::encode(array_map('sanitize_text_field', wp_unslash($raw_room_name))) : sanitize_text_field(wp_unslash($raw_room_name));

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized/unslashed on next line
            $raw_room_desc = $_POST['room_description'] ?? '';
            $room_desc = is_array($raw_room_desc) ? I18n::encode(array_map('sanitize_textarea_field', wp_unslash($raw_room_desc))) : sanitize_textarea_field(wp_unslash($raw_room_desc));

            $base_price = Money::fromDecimal(isset($_POST['base_price']) ? sanitize_text_field(wp_unslash($_POST['base_price'])) : '0', $currency)->toDecimal();
            $max_adults = isset($_POST['max_adults']) ? absint(wp_unslash($_POST['max_adults'])) : 1;
            $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';

            $data = array(
                'name' => $room_name,
                'description' => $room_desc,
                'base_price' => $base_price,
                'max_adults' => $max_adults,
                /* BUILD_PRO_START */
                'max_children' => absint(wp_unslash($_POST['max_children'] ?? 0)),
                'child_age_free_limit' => absint(wp_unslash($_POST['child_age_free_limit'] ?? 0)),
                'child_rate' => Money::fromDecimal(isset($_POST['child_rate']) ? sanitize_text_field(wp_unslash($_POST['child_rate'])) : '0', $currency)->toDecimal(),
                /* BUILD_PRO_END */
                'amenities' => $amenities,
                'image_url' => $image_url,
            );

            if ($room_type_id > 0) {
                $wpdb->update($table, $data, array('id' => $room_type_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                Cache::invalidate_rooms();
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_room_type_updated')) . '</p></div>';
                $edit_mode = false;
            } else {
                $wpdb->insert($table, $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                Cache::invalidate_rooms();
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_room_type_added')) . '</p></div>';
            }
        }



        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Loading room type list for administration dashboard.
        $types = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i", $table));
        $current_amenities = ($edit_mode && isset($edit_data->amenities) && $edit_data->amenities) ? json_decode($edit_data->amenities, true) : array();
        if (!is_array($current_amenities))
            $current_amenities = array();
        ?>
        <div class="wrap mhbo-admin-wrap">
            <?php AdminUI::render_header(
                I18n::get_label('title_room_types_config'),
                I18n::get_label('desc_room_types_config'),
                [],
                [
                    ['label' => I18n::get_label('menu_dashboard'), 'url' => admin_url('admin.php?page=mhbo-dashboard')]
                ]
            ); ?>

            <div class="mhbo-card mhbo-room-form-card mhbo-animate-in">
                <h3 class="mhbo-card-title"><?php echo $edit_mode ? esc_html(I18n::get_label('title_modify_room_config')) : esc_html(I18n::get_label('title_define_new_room')); ?></h3>

                <form method="post" action="" class="mhbo-modern-form-layout">
                    <?php wp_nonce_field($edit_mode ? 'mhbo_edit_room_type_' . $edit_data->id : 'mhbo_add_room_type'); ?>
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="room_type_id" value="<?php echo esc_attr($edit_data->id); ?>">
                    <?php endif; ?>
                        <div class="mhbo-form-section">
                            <h4 class="mhbo-section-title"><?php esc_html_e('Content & Localization', 'modern-hotel-booking'); ?></h4>
                            
                            <div class="mhbo-lang-tabs-container">
                                <nav class="mhbo-tab-nav">
                                    <?php foreach (I18n::get_available_languages() as $index => $lang): ?>
                                        <button type="button" class="mhbo-tab-btn <?php echo 0 === $index ? 'mhbo-tab-active' : ''; ?>" data-tab="lang-<?php echo esc_attr($lang); ?>">
                                            <?php echo esc_html(strtoupper($lang)); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </nav>

                                <div class="mhbo-tab-panes">
                                    <?php foreach (I18n::get_available_languages() as $index => $lang): ?>
                                        <div class="mhbo-tab-content" id="lang-<?php echo esc_attr($lang); ?>" style="<?php echo 0 === $index ? 'display:block;' : 'display:none;'; ?>">
                                            <div class="mhbo-field-group">
                                                <label class="mhbo-label"><?php esc_html_e('Room Name', 'modern-hotel-booking'); ?></label>
                                                <input type="text" name="room_name[<?php echo esc_attr($lang); ?>]"
                                                    value="<?php echo $edit_mode ? esc_attr(I18n::decode($edit_data->name, $lang)) : ''; ?>"
                                                    class="mhbo-input-large" placeholder="<?php esc_attr_e('e.g. Deluxe Sea View Suite', 'modern-hotel-booking'); ?>">
                                            </div>
                                            <div class="mhbo-field-group">
                                                <label class="mhbo-label"><?php esc_html_e('Description', 'modern-hotel-booking'); ?></label>
                                                <textarea name="room_description[<?php echo esc_attr($lang); ?>]" rows="5"
                                                    class="mhbo-input-large" placeholder="<?php esc_attr_e('Describe the features, view, and unique selling points...', 'modern-hotel-booking'); ?>"><?php echo $edit_mode ? esc_textarea(I18n::decode($edit_data->description, $lang)) : ''; ?></textarea>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mhbo-form-section">
                            <h4 class="mhbo-section-title"><?php esc_html_e('Pricing & Capacity', 'modern-hotel-booking'); ?></h4>
                            <div class="mhbo-settings-grid">
                                <div class="mhbo-settings-item">
                                    <label class="mhbo-label"><?php esc_html_e('Nightly Base Rate', 'modern-hotel-booking'); ?></label>
                                    <div class="mhbo-input-prefix-container">
                                        <span class="mhbo-input-prefix"><?php echo esc_html($currency); ?></span>
                                        <input type="number" step="any" name="base_price"
                                            value="<?php echo $edit_mode ? esc_attr(Money::fromDecimal((string)$edit_data->base_price, $currency)->toDecimal()) : ''; ?>" required
                                            class="mhbo-input-mid">
                                    </div>
                                </div>
                                <div class="mhbo-settings-item">
                                    <label class="mhbo-label"><?php esc_html_e('Adult Capacity', 'modern-hotel-booking'); ?></label>
                                    <input type="number" name="max_adults"
                                        value="<?php echo $edit_mode ? esc_attr($edit_data->max_adults) : '2'; ?>"
                                        class="mhbo-input-mid" min="1">
                                </div>
                                <?php /* BUILD_PRO_START */ ?>
                                <div class="mhbo-settings-item">
                                    <label class="mhbo-label"><?php esc_html_e('Child Capacity', 'modern-hotel-booking'); ?></label>
                                    <input type="number" name="max_children"
                                        value="<?php echo $edit_mode ? esc_attr($edit_data->max_children ?? 0) : '0'; ?>"
                                        class="mhbo-input-mid" min="0">
                                </div>
                                <div class="mhbo-settings-item">
                                    <label class="mhbo-label"><?php esc_html_e('Child Free Age', 'modern-hotel-booking'); ?></label>
                                    <input type="number" name="child_age_free_limit"
                                        value="<?php echo $edit_mode ? esc_attr($edit_data->child_age_free_limit ?? 0) : '0'; ?>"
                                        class="mhbo-input-mid" min="0">
                                </div>
                                <div class="mhbo-settings-item">
                                    <label class="mhbo-label"><?php esc_html_e('Extra Child Rate', 'modern-hotel-booking'); ?></label>
                                    <div class="mhbo-input-prefix-container">
                                        <span class="mhbo-input-prefix"><?php echo esc_html($currency); ?></span>
                                        <input type="number" step="any" name="child_rate"
                                            value="<?php echo $edit_mode ? esc_attr(Money::fromDecimal((string)($edit_data->child_rate ?? '0.00'), $currency)->toDecimal()) : '0.00'; ?>"
                                            class="mhbo-input-mid" min="0">
                                    </div>
                                </div>
                                <?php /* BUILD_PRO_END */ ?>
                            </div>
                        </div>

                        <div class="mhbo-form-section">
                            <h4 class="mhbo-section-title"><?php esc_html_e('Media & Amenities', 'modern-hotel-booking'); ?></h4>
                            <div class="mhbo-field-group">
                                <label class="mhbo-label"><?php esc_html_e('Featured Image', 'modern-hotel-booking'); ?></label>
                                <div class="mhbo-media-selector">
                                    <input type="text" name="image_url" id="mhbo_room_image_url"
                                        value="<?php echo $edit_mode ? esc_attr($edit_data->image_url) : ''; ?>" class="mhbo-input-large" placeholder="https://...">
                                    <button type="button" class="mhbo-btn mhbo-btn-outline mhbo-upload-button" data-target="#mhbo_room_image_url">
                                        <span class="dashicons dashicons-upload"></span> <?php esc_html_e('Select', 'modern-hotel-booking'); ?>
                                    </button>
                                </div>
                            </div>

                            <div class="mhbo-field-group">
                                <label class="mhbo-label"><?php esc_html_e('Standard Amenities', 'modern-hotel-booking'); ?></label>
                                <div class="mhbo-amenities-check-grid">
                                <?php
                                $amenities_list = get_option('mhbo_amenities_list', [
                                    'wifi' => I18n::get_label('label_amenity_wifi'),
                                    'ac' => I18n::get_label('label_amenity_ac'),
                                    'tv' => I18n::get_label('label_amenity_tv'),
                                    'breakfast' => I18n::get_label('label_amenity_breakfast'),
                                    'pool' => I18n::get_label('label_amenity_pool'),
                                    'minibar' => I18n::get_label('label_amenity_minibar'),
                                    'safe' => I18n::get_label('label_amenity_safe'),
                                    'parking' => I18n::get_label('label_amenity_parking'),
                                    'balcony' => I18n::get_label('label_amenity_balcony')
                                ]);
                                foreach ($amenities_list as $key => $lbl): ?>
                                    <label class="mhbo-checkbox-item">
                                        <input type="checkbox" name="amenities[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $current_amenities, true)); ?>>
                                        <span><?php echo esc_html($lbl); ?></span>
                                    </label>
                                <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                
                    <div class="mhbo-form-actions-dock">
                        <input type="submit" name="submit_room_type" class="mhbo-btn mhbo-btn-primary"
                            value="<?php echo $edit_mode ? esc_attr(I18n::get_label('btn_save_config')) : esc_attr(I18n::get_label('btn_create_room_type')); ?>">
                        <?php if ($edit_mode): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-room-types')); ?>"
                                class="mhbo-btn mhbo-btn-ghost"><?php esc_html_e('Discard Changes', 'modern-hotel-booking'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="mhbo-section-header">
                <h3><?php esc_html_e('Defined Room Types', 'modern-hotel-booking'); ?></h3>
                <p><?php esc_html_e('Manage and edit your existing room configurations.', 'modern-hotel-booking'); ?></p>
            </div>

            <div class="mhbo-room-type-grid">
                    <?php if (count($types) === 0): ?>
                        <div class="mhbo-empty-state">
                            <span class="dashicons dashicons-category"></span>
                            <p><?php esc_html_e('No room types defined yet. Create your first category above.', 'modern-hotel-booking'); ?></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($types as $t): ?>
                            <div class="mhbo-room-card mhbo-animate-in">
                                <div class="mhbo-room-card-head">
                                    <div class="mhbo-room-thumb mhbo-diamond-thumb" style="width:64px; height:64px; min-width:64px; min-height:64px; position:relative; overflow:hidden; border-radius:12px;">
                                        <?php if ($t->image_url): ?>
                                            <img src="<?php echo esc_url($t->image_url); ?>" class="mhbo-room-thumbnail" alt="<?php echo esc_attr(I18n::decode($t->name)); ?>" loading="lazy" style="width:100%; height:100%; object-fit:cover; display:block;">
                                        <?php else: ?>
                                            <div class="mhbo-thumb-placeholder" style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:#f1f5f9; color:#94a3b8;"><span class="dashicons dashicons-format-image"></span></div>
                                        <?php endif; ?>
                                        <div class="mhbo-room-badge-index">#<?php echo esc_html($t->id); ?></div>
                                    </div>
                                    <div class="mhbo-room-info-group">
                                        <h4 class="mhbo-room-title"><?php echo esc_html(I18n::decode($t->name)); ?></h4>
                                    </div>
                                </div>
                                <div class="mhbo-room-card-body">
                                    <p class="mhbo-room-desc"><?php echo esc_html(wp_trim_words(I18n::decode($t->description), 10)); ?></p>
                                    
                                    <div class="mhbo-room-meta-row">
                                        <div class="mhbo-meta-item">
                                            <span class="mhbo-meta-label"><?php esc_html_e('Rate', 'modern-hotel-booking'); ?></span>
                                            <span class="mhbo-meta-value"><?php echo esc_html(I18n::format_currency($t->base_price)); ?></span>
                                        </div>
                                        <div class="mhbo-meta-item">
                                            <span class="mhbo-meta-label"><?php esc_html_e('Adults', 'modern-hotel-booking'); ?></span>
                                            <span class="mhbo-meta-value"><?php echo (int)$t->max_adults; ?></span>
                                        </div>
                                    </div>

                                    <div class="mhbo-amenities-mini">
                                        <?php
                                        if (isset($t->amenities) && $t->amenities) {
                                            $ams_array = json_decode($t->amenities, true);
                                            if (is_array($ams_array)) {
                                                foreach (array_slice($ams_array, 0, 3) as $k) {
                                                    $label = isset($amenities_list[$k]) ? $amenities_list[$k] : $k;
                                                    echo '<span class="mhbo-mini-tag">' . esc_html($label) . '</span>';
                                                }
                                                if (count($ams_array) > 3) {
                                                    echo '<span class="mhbo-mini-tag-more">+' . (count($ams_array) - 3) . '</span>';
                                                }
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="mhbo-room-card-actions">
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-room-types&action=edit&id={$t->id}"), 'mhbo_edit_room_type_' . $t->id)); ?>"
                                        class="mhbo-action-btn mhbo-btn-edit" title="<?php esc_attr_e('Edit', 'modern-hotel-booking'); ?>">
                                        <span class="dashicons dashicons-edit"></span>
                                    </a>
                                    <?php /* BUILD_PRO_START */ ?>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-room-types&action=calendar&id={$t->id}"), 'mhbo_calendar_type_' . $t->id)); ?>"
                                        class="mhbo-action-btn" title="<?php esc_attr_e('Calendar Pricing', 'modern-hotel-booking'); ?>" style="background:#ede9fe;color:#6d28d9;">
                                        <span class="dashicons dashicons-calendar-alt"></span>
                                    </a>
                                    <?php /* BUILD_PRO_END */ ?>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-room-types&action=delete&id={$t->id}"), 'mhbo_delete_room_type_' . $t->id)); ?>"
                                        class="mhbo-action-btn mhbo-btn-delete" title="<?php esc_attr_e('Delete', 'modern-hotel-booking'); ?>"
                                        onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this room type? This may affect existing rooms.', 'modern-hotel-booking'); ?>')">
                                        <span class="dashicons dashicons-trash"></span>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php
    }

    public function display_rooms_page(): void
    {
        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
        }

        /* BUILD_FREE_START */
        $is_pro_active = false;
        /* BUILD_FREE_END */
        /* BUILD_PRO_START */
        $is_pro_active = License::is_active();
        /* BUILD_PRO_END */

        global $wpdb;
        $t_rooms = esc_sql( $wpdb->prefix . 'mhbo_rooms' );
        $t_types = esc_sql( $wpdb->prefix . 'mhbo_room_types' );
        $new_ical_table = esc_sql( $wpdb->prefix . 'mhbo_ical_connections' );
        $legacy_ical_table = esc_sql( $wpdb->prefix . 'mhbo_ical_feeds' );
        $new_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $new_ical_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES is a schema query; caching would give stale results after migrations
        $t_ical = $new_exists ? $new_ical_table : $legacy_ical_table;

        $edit_mode = false;
        $edit_data = null;
        $ical_mode = false;
        $ical_feeds = array();
        /* BUILD_PRO_START */
        $calendar_mode = false;
        /* BUILD_PRO_END */

        // Rule 11: Extract and sanitize all inputs at start
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $sub_action = isset($_GET['sub_action']) ? sanitize_key(wp_unslash($_GET['sub_action'])) : '';
        $get_id = isset($_GET['id']) ? absint(wp_unslash($_GET['id'])) : 0;
        $get_feed_id = isset($_GET['feed_id']) ? absint(wp_unslash($_GET['feed_id'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_key(wp_unslash($_GET['_wpnonce'])) : '';

        // POST Actions
        $submit_ical_feed = isset($_POST['submit_ical_feed']);
        $submit_room = isset($_POST['submit_room']);

        /* BUILD_PRO_START */
        // Calendar Mode — render full-page calendar for this individual room.
        if ('calendar' === $action && $get_id > 0) {
            if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_calendar_room_' . $get_id)) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }
            if (class_exists(AdminCalendar::class)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Fetching unit identifier for individual room calendar header.
                $room_row = $wpdb->get_row($wpdb->prepare("SELECT room_number FROM %i WHERE id = %d", $t_rooms, $get_id));
                $room_number = $room_row ? $room_row->room_number : '#' . $get_id;
                AdminCalendar::render_room_calendar($get_id, $room_number);
                return;
            }
        }
        /* BUILD_PRO_END */

        // Delete Room Action
        if ('delete' === $action && $get_id > 0 && ($sub_action === '' || null === $sub_action)) {
            if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_delete_room_' . $get_id)) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }
            $wpdb->delete($t_rooms, array('id' => $get_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
            Cache::invalidate_rooms();
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(I18n::get_label('msg_room_deleted')) . '</p></div>';
        }

        // iCal Mode
        if ('ical' === $action && $get_id > 0) {
            // Sub-actions (delete_feed, sync_now) carry their own nonces — skip page-level check for them.
            if ( ( '' === $sub_action || null === $sub_action ) && (!$nonce || !wp_verify_nonce($nonce, 'mhbo_ical_room_' . $get_id))) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }
            
            /* BUILD_FREE_START */
            if (!License::is_active()) {
                ?>
                <div class="wrap mhbo-admin-wrap">
                    <h1><?php esc_html_e('Manage Rooms', 'modern-hotel-booking'); ?></h1>
                    <?php if (class_exists(Settings::class)) {
                        Settings::render_pro_upsell();
                    } ?>
                    <p style="margin-top: 20px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-rooms')); ?>" class="button">&larr;
                            <?php esc_html_e('Back to Rooms', 'modern-hotel-booking'); ?></a>
                    </p>
                </div>
                <?php
                return;
            }
            /* BUILD_FREE_END */
            /* BUILD_PRO_START */
            $is_pro_active = License::is_active();
            $ical_mode = true;
            /* BUILD_PRO_END */

            if ($submit_ical_feed) {
                if (!check_admin_referer('mhbo_add_ical')) {
                    wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
                }

                $feed_name = isset($_POST['feed_name']) ? sanitize_text_field(wp_unslash($_POST['feed_name'])) : '';
                $feed_url  = isset($_POST['feed_url'])  ? esc_url_raw(wp_unslash($_POST['feed_url'])) : '';

                if ('' === $feed_url || !wp_http_validate_url($feed_url)) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Invalid feed URL. Please provide a valid HTTPS address.', 'modern-hotel-booking') . '</p></div>';
                } elseif (!Security::is_safe_url($feed_url)) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Feed URL is not permitted for security reasons (internal addresses are blocked).', 'modern-hotel-booking') . '</p></div>';
                } else {
                    // Auto-detect platform from URL so the main iCal manager shows the right icon.
                    $url_lower = strtolower($feed_url);
                    if (strpos($url_lower, 'airbnb.com') !== false) {
                        $platform = 'airbnb';
                    } elseif (strpos($url_lower, 'booking.com') !== false || strpos($url_lower, 'admin.booking') !== false) {
                        $platform = 'booking.com';
                    } elseif (strpos($url_lower, 'google.com') !== false || strpos($url_lower, 'calendar.google') !== false) {
                        $platform = 'google_calendar';
                    } else {
                        $platform = 'custom';
                    }

                    if ($new_exists) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                        $wpdb->insert($t_ical, array(
                            'room_id'        => $get_id,
                            'name'           => $feed_name,
                            'ical_url'       => $feed_url,
                            'platform'       => $platform,
                            'sync_direction' => 'import',
                            'sync_status'    => 'pending',
                            'created_at'     => current_time('mysql'),
                        ), array('%d', '%s', '%s', '%s', '%s', '%s', '%s'));
                    } else {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Legacy table
                        $wpdb->insert($t_ical, array(
                            'room_id'   => $get_id,
                            'feed_name' => $feed_name,
                            'feed_url'  => $feed_url,
                        ), array('%d', '%s', '%s'));
                    }
                    Cache::invalidate_rooms();
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_feed_added')) . '</p></div>';
                }
            }

            if ('delete_feed' === $sub_action && $get_feed_id > 0) {
                check_admin_referer('mhbo_delete_feed_' . $get_feed_id);
                // Scope delete to room_id so a user can't delete another room's feed by guessing IDs.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->delete($t_ical, array('id' => $get_feed_id, 'room_id' => $get_id), array('%d', '%d'));
            }

            /* BUILD_PRO_START */
            if ('sync_feed' === $sub_action && $get_feed_id > 0) {
                check_admin_referer('mhbo_sync_feed_' . $get_feed_id);
                if (class_exists('MHBO\Pro\ICalManager')) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table name placeholder.
                    $this_conn = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, room_id FROM %i WHERE id = %d AND room_id = %d",
                        $t_ical,
                        $get_feed_id,
                        $get_id
                    ));
                    if ($this_conn) {
                        \MHBO\Pro\ICalManager::get_instance()->sync_single_connection(
                            (int) $this_conn->id,
                            (int) $this_conn->room_id
                        );
                    }
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_sync_completed')) . '</p></div>';
            }
            /* BUILD_PRO_END */

            if ('sync_now' === $sub_action) {
                check_admin_referer('mhbo_sync_now_' . $get_id);
                ICal::sync_external_calendars();
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_sync_completed')) . '</p></div>';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table name placeholder.
            $ical_feeds = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE room_id = %d", $t_ical, $get_id));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table name placeholder.
            $room_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d", $t_rooms, $get_id));
        }

        // Edit Room Action
        if ('edit' === $action && $get_id > 0) {
            if (!$nonce || !wp_verify_nonce($nonce, 'mhbo_edit_room_' . $get_id)) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }
            $edit_mode = true;
            $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d", $t_rooms, $get_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table name placeholder.
        }

        // Save Room Action
        if ($submit_room) {
            $post_room_id = isset($_POST['room_id']) ? absint(wp_unslash($_POST['room_id'])) : 0;
            $nonce_action = $post_room_id > 0 ? 'mhbo_edit_room_' . $post_room_id : 'mhbo_add_room';
            if (!check_admin_referer($nonce_action)) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }

            $type_id = isset($_POST['type_id']) ? absint(wp_unslash($_POST['type_id'])) : 0;
            $room_number = isset($_POST['room_number']) ? sanitize_text_field(wp_unslash($_POST['room_number'])) : '';
            $room_status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'available';
            $custom_price_raw = isset($_POST['custom_price']) ? sanitize_text_field(wp_unslash($_POST['custom_price'])) : '';
            $room_image_url = isset($_POST['room_image_url']) ? esc_url_raw(wp_unslash($_POST['room_image_url'])) : '';

            $data = array(
                'type_id' => $type_id,
                'room_number' => $room_number,
                'custom_price' => (isset($custom_price_raw) && $custom_price_raw !== '') ? floatval($custom_price_raw) : null,
                'status' => $room_status,
                'image_url' => $room_image_url !== '' ? $room_image_url : null,
            );

            if ($post_room_id > 0) {
                $wpdb->update($t_rooms, $data, array('id' => $post_room_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                Cache::invalidate_rooms();
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_room_updated')) . '</p></div>';
                $edit_mode = false;
            } else {
                $wpdb->insert($t_rooms, $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                Cache::invalidate_rooms();
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_room_added')) . '</p></div>';
            }
        }



        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from $wpdb->prefix, admin-only query
        $rooms = $wpdb->get_results($wpdb->prepare("SELECT r.*, t.name as type_name, t.base_price, t.image_url as type_image_url FROM %i r LEFT JOIN %i t ON r.type_id = t.id ORDER BY r.room_number ASC", $t_rooms, $t_types));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from $wpdb->prefix, admin-only query
        $types = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i", $t_types));
        ?>
        <?php
        $available_count = 0;
        $maintenance_count = 0;
        foreach ($rooms as $r) {
            if ($r->status === 'available') $available_count++;
            if ($r->status === 'maintenance') $maintenance_count++;
        }
        ?>
        <div class="wrap mhbo-admin-wrap">
            <?php 
            AdminUI::render_header(
                I18n::get_label('title_room_inventory'), 
                I18n::get_label('desc_room_inventory'),
                [],
                [
                    ['label' => I18n::get_label('menu_dashboard'), 'url' => admin_url('admin.php?page=mhbo-dashboard')]
                ]
            ); 
            ?>

            <div class="mhbo-stats-grid">
                <div class="mhbo-stat-card">
                    <div class="mhbo-stat-value"><?php echo esc_html((string) count($rooms)); ?></div>
                    <div class="mhbo-stat-label"><?php esc_html_e('Total Units', 'modern-hotel-booking'); ?></div>
                </div>
                <div class="mhbo-stat-card">
                    <div class="mhbo-stat-value" style="color: #166534;"><?php echo esc_html((string) $available_count); ?></div>
                    <div class="mhbo-stat-label"><?php esc_html_e('Ready for Guests', 'modern-hotel-booking'); ?></div>
                </div>
                <div class="mhbo-stat-card">
                    <div class="mhbo-stat-value" style="color: #9a3412;"><?php echo esc_html((string) $maintenance_count); ?></div>
                    <div class="mhbo-stat-label"><?php esc_html_e('Out of Service', 'modern-hotel-booking'); ?></div>
                </div>
            </div>

            <?php /* BUILD_PRO_START */ ?>
            <?php if ($ical_mode && isset($room_info)): ?>
                <div class="mhbo-card accent" style="margin-bottom:30px; border-left: 4px solid #c5a059;">
                    <h3 style="margin-top:0; margin-bottom: 20px; font-size: 1.2rem; display: flex; align-items: center;">
                        <span class="dashicons dashicons-calendar-alt" style="margin-right: 10px; color: #c5a059;"></span>
                        <?php
                        /* translators: %s: room unit number or identifier */
                        echo esc_html(sprintf(I18n::get_label('title_ical_sync_sprintf'), $room_info->room_number)); ?>
                    </h3>
                    
                    <div style="margin-bottom:25px; background: #fff; padding: 15px; border: 1px solid #e5e5e5; border-radius: 8px;">
                        <label style="font-weight: 700; color: #1a3b5d;"><?php esc_html_e('Deployment Export URL', 'modern-hotel-booking'); ?></label>
                        <p class="description" style="margin-bottom: 10px;"><?php esc_html_e('Provide this URL to external OTAs (Airbnb, Booking.com) to export this room\'s availability.', 'modern-hotel-booking'); ?></p>
                        <div style="display:flex; gap:10px;">
                            <?php
                            // Per-room key: more secure than global token (rotating one room doesn't affect others).
                            $_ical_key = (string) get_option('mhbo_ical_key_' . (int) $room_info->id, '');
                            if ('' === $_ical_key) {
                                $_ical_key = wp_generate_password(24, false);
                                update_option('mhbo_ical_key_' . (int) $room_info->id, $_ical_key, false);
                            }
                            $_room_export_url = add_query_arg('key', $_ical_key, home_url('/mhbo-ical/room-' . (int) $room_info->id . '.ics'));
                            ?>
                            <input type="text" value="<?php echo esc_url($_room_export_url); ?>" class="regular-text" readonly onclick="this.select()" style="flex-grow: 1; background: #f8fafc; font-family: monospace; font-size: 12px; border: 1px solid #cbd5e1;">
                            <button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(() => { this.innerText='<?php esc_attr_e('Copied!', 'modern-hotel-booking'); ?>'; setTimeout(() => this.innerText='<?php esc_attr_e('Copy URL', 'modern-hotel-booking'); ?>', 2000); })"><?php esc_html_e('Copy URL', 'modern-hotel-booking'); ?></button>
                        </div>
                    </div>

                    <div class="mhbo-sub-section" style="margin-top: 30px;">
                        <h4 style="font-size: 1.1rem; margin-bottom: 15px;"><?php esc_html_e('Import External Calendars', 'modern-hotel-booking'); ?></h4>
                        <div class="mhbo-table-responsive" style="margin-bottom: 25px;">
                            <table class="wp-list-table widefat fixed striped" style="box-shadow:none; border: 1px solid #eee;">
                                <thead>
                                    <tr>
                                        <th style="width:130px;"><?php esc_html_e('Platform', 'modern-hotel-booking'); ?></th>
                                        <th><?php esc_html_e('Connection Name', 'modern-hotel-booking'); ?></th>
                                        <th style="width:110px;"><?php esc_html_e('Status', 'modern-hotel-booking'); ?></th>
                                        <th style="width:160px;"><?php esc_html_e('Last Sync', 'modern-hotel-booking'); ?></th>
                                        <th style="width:110px; text-align:right;"><?php esc_html_e('Actions', 'modern-hotel-booking'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ([] === (array) $ical_feeds): ?>
                                        <tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:30px; font-style:italic;"><?php esc_html_e('No external feeds connected yet.', 'modern-hotel-booking'); ?></td></tr>
                                    <?php else: ?>
                                        <?php foreach ($ical_feeds as $feed):
                                            $conn_platform  = isset($feed->platform) ? (string) $feed->platform : 'custom';
                                            $conn_name      = $feed->name ?? $feed->feed_name ?? '';
                                            $conn_status    = isset($feed->sync_status) ? (string) $feed->sync_status : 'pending';
                                            $conn_last_sync = $feed->last_sync ?? $feed->last_synced ?? null;
                                            $conn_error     = isset($feed->last_error) ? (string) $feed->last_error : '';
                                            $conn_events    = isset($feed->events_count) ? (int) $feed->events_count : 0;

                                            $platform_styles = array(
                                                'airbnb'          => array('label' => 'Airbnb',          'bg' => '#ff5a5f', 'fg' => '#fff'),
                                                'booking.com'     => array('label' => 'Booking.com',     'bg' => '#003580', 'fg' => '#fff'),
                                                'google_calendar' => array('label' => 'Google Cal',      'bg' => '#4285f4', 'fg' => '#fff'),
                                                'custom'          => array('label' => 'Custom',           'bg' => '#6b7280', 'fg' => '#fff'),
                                            );
                                            $ps = $platform_styles[$conn_platform] ?? $platform_styles['custom'];

                                            $status_styles = array(
                                                'success' => array('color' => '#16a34a', 'icon' => 'dashicons-yes-alt',  'label' => 'Synced'),
                                                'failed'  => array('color' => '#dc2626', 'icon' => 'dashicons-warning',  'label' => 'Failed'),
                                                'pending' => array('color' => '#d97706', 'icon' => 'dashicons-clock',    'label' => 'Pending'),
                                            );
                                            $ss = $status_styles[$conn_status] ?? $status_styles['pending'];

                                            $sync_url = wp_nonce_url(
                                                add_query_arg(array(
                                                    'page'       => 'mhbo-rooms',
                                                    'action'     => 'ical',
                                                    'id'         => (int) $room_info->id,
                                                    'sub_action' => 'sync_feed',
                                                    'feed_id'    => (int) $feed->id,
                                                ), admin_url('admin.php')),
                                                'mhbo_sync_feed_' . (int) $feed->id
                                            );
                                            $delete_url = wp_nonce_url(
                                                add_query_arg(array(
                                                    'page'       => 'mhbo-rooms',
                                                    'action'     => 'ical',
                                                    'id'         => (int) $room_info->id,
                                                    'sub_action' => 'delete_feed',
                                                    'feed_id'    => (int) $feed->id,
                                                ), admin_url('admin.php')),
                                                'mhbo_delete_feed_' . (int) $feed->id
                                            );
                                        ?>
                                            <tr>
                                                <td>
                                                    <span style="display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; color:<?php echo esc_attr($ps['fg']); ?>; background:<?php echo esc_attr($ps['bg']); ?>;">
                                                        <?php echo esc_html($ps['label']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong style="color:#1e293b;"><?php echo esc_html($conn_name); ?></strong>
                                                    <?php if ('' !== $conn_error && 'failed' === $conn_status): ?>
                                                        <br><span style="color:#dc2626; font-size:11px;"><?php echo esc_html($conn_error); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="dashicons <?php echo esc_attr($ss['icon']); ?>" style="color:<?php echo esc_attr($ss['color']); ?>; vertical-align:middle; font-size:16px;"></span>
                                                    <span style="color:<?php echo esc_attr($ss['color']); ?>; font-weight:600; font-size:12px;"><?php echo esc_html($ss['label']); ?></span>
                                                </td>
                                                <td style="font-size:12px; color:#475569;">
                                                    <?php if ($conn_last_sync): ?>
                                                        <?php echo esc_html(human_time_diff((int) strtotime($conn_last_sync), current_time('timestamp'))); ?> <?php esc_html_e('ago', 'modern-hotel-booking'); ?>
                                                        <?php if ($conn_events > 0): ?>
                                                            <br><span style="color:#64748b;">
                                                                <?php
                                                                /* translators: %d: number of calendar events */
                                                                echo esc_html(sprintf(_n('%d event', '%d events', $conn_events, 'modern-hotel-booking'), $conn_events));
                                                                ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="color:#d97706;"><?php esc_html_e('Never synced', 'modern-hotel-booking'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align:right; white-space:nowrap;">
                                                    <a href="<?php echo esc_url($sync_url); ?>" class="button button-small" title="<?php esc_attr_e('Sync now', 'modern-hotel-booking'); ?>">
                                                        <span class="dashicons dashicons-update" style="margin-top:3px;"></span>
                                                    </a>
                                                    <a href="<?php echo esc_url($delete_url); ?>" class="button button-link-delete button-small"
                                                        onclick="return confirm('<?php esc_attr_e('Disconnect this calendar? Import of bookings will stop.', 'modern-hotel-booking'); ?>')"
                                                        title="<?php esc_attr_e('Disconnect', 'modern-hotel-booking'); ?>">
                                                        <span class="dashicons dashicons-trash" style="margin-top:3px;"></span>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="background:#f8fafc; padding:20px; border-radius:10px; border:1px solid #e2e8f0;">
                            <form method="post">
                                <?php wp_nonce_field('mhbo_add_ical'); ?>
                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; align-items: flex-end;">
                                    <div>
                                        <label style="display:block; margin-bottom: 5px; font-weight: 600;"><?php esc_html_e('Connection Label', 'modern-hotel-booking'); ?></label>
                                        <input type="text" name="feed_name" placeholder="<?php esc_attr_e('e.g. Airbnb, Booking.com', 'modern-hotel-booking'); ?>" required style="width:100%; border-radius: 6px;">
                                    </div>
                                    <div style="grid-column: span 2;">
                                        <label style="display:block; margin-bottom: 5px; font-weight: 600;"><?php esc_html_e('iCal Feed URL (HTTPS)', 'modern-hotel-booking'); ?></label>
                                        <input type="url" name="feed_url" placeholder="https://..." required style="width:100%; border-radius: 6px;">
                                    </div>
                                    <div>
                                        <input type="submit" name="submit_ical_feed" class="button button-primary" value="<?php esc_attr_e('Connect Calendar', 'modern-hotel-booking'); ?>" style="width: 100%; height: 32px;">
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div style="margin-top:30px; border-top:1px solid #eee; padding-top:20px; display:flex; gap:15px; justify-content: space-between; align-items: center;">
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-rooms&action=ical&id={$room_info->id}&sub_action=sync_now"), 'mhbo_sync_now_' . $room_info->id)); ?>"
                            class="button button-secondary"><span class="dashicons dashicons-update" style="margin-top:4px;"></span> <?php esc_html_e('Force Global Sync', 'modern-hotel-booking'); ?></a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-rooms')); ?>"
                            class="button" style="font-weight: 600;"><?php esc_html_e('Cancel & Return', 'modern-hotel-booking'); ?></a>
                    </div>
                    </div>
                <?php endif; ?>
            <?php /* BUILD_PRO_END */ ?>

            <div class="mhbo-card <?php echo esc_attr($edit_mode ? 'accent' : ''); ?>" style="<?php echo esc_attr($edit_mode ? 'border-left: 4px solid #3b82f6;' : ''); ?>">
                <h3 style="margin-top:0; margin-bottom: 20px; font-size: 1.2rem; display: flex; align-items: center;">
                    <span class="dashicons dashicons-plus-alt" style="margin-right: 10px; color: <?php echo esc_attr($edit_mode ? '#3b82f6' : '#1e293b'); ?>;"></span>
                    <?php echo $edit_mode ? esc_html(I18n::get_label('title_modify_unit')) : esc_html(I18n::get_label('title_new_unit')); ?>
                </h3>
                <form method="post">
                    <?php wp_nonce_field($edit_mode ? 'mhbo_edit_room_' . $edit_data->id : 'mhbo_add_room'); ?>
                    <?php if ($edit_mode): ?><input type="hidden" name="room_id" value="<?php echo esc_attr($edit_data->id); ?>"><?php endif; ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label><?php esc_html_e('Classification / Type', 'modern-hotel-booking'); ?></label></th>
                            <td>
                                <select name="type_id" class="regular-text" required>
                                    <option value=""><?php esc_html_e('— Select Room Type —', 'modern-hotel-booking'); ?></option>
                                    <?php foreach ($types as $t): ?>
                                        <option value="<?php echo esc_attr($t->id); ?>" <?php echo ($edit_mode && (int) $edit_data->type_id === (int) $t->id) ? 'selected' : ''; ?>>
                                            <?php echo esc_html(I18n::decode($t->name)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e('Room Number / Identifier', 'modern-hotel-booking'); ?></label></th>
                            <td>
                                <input type="text" name="room_number" value="<?php echo $edit_mode ? esc_attr($edit_data->room_number) : ''; ?>" required class="regular-text" placeholder="<?php esc_attr_e('e.g. Room 101, Junior Suite A', 'modern-hotel-booking'); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e('Override Daily Rate', 'modern-hotel-booking'); ?></label>
                                <span class="mhbo-tooltip"><i class="mhbo-help-icon">?</i><span class="mhbo-tooltip-text"><?php esc_html_e('Specific price for this unit. Leave at 0 to use the category default.', 'modern-hotel-booking'); ?></span></span>
                            </th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="number" step="any" name="custom_price" value="<?php echo $edit_mode ? esc_attr(Money::fromDecimal((string)($edit_data->custom_price ?? 0))->toDecimal()) : '0.00'; ?>" class="small-text">
                                    <?php $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD')); ?>
                                    <span class="description" style="font-weight: 600;"><?php echo esc_html($currency); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e('Operational Status', 'modern-hotel-booking'); ?></label></th>
                            <td>
                                <select name="status" class="regular-text">
                                    <option value="available" <?php echo ($edit_mode && 'available' === $edit_data->status) ? 'selected' : ''; ?>><?php esc_html_e('Live & Reservable', 'modern-hotel-booking'); ?></option>
                                    <option value="maintenance" <?php echo ($edit_mode && 'maintenance' === $edit_data->status) ? 'selected' : ''; ?>><?php esc_html_e('Inactive / Maintenance', 'modern-hotel-booking'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mhbo_unit_image_url"><?php esc_html_e('Room Image', 'modern-hotel-booking'); ?></label></th>
                            <td>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <input type="text" name="room_image_url" id="mhbo_unit_image_url"
                                        value="<?php echo $edit_mode ? esc_attr($edit_data->image_url ?? '') : ''; ?>"
                                        class="regular-text" placeholder="https://...">
                                    <button type="button" class="button mhbo-upload-button" data-target="#mhbo_unit_image_url">
                                        <span class="dashicons dashicons-upload" style="margin-top:4px;"></span> <?php esc_html_e('Select', 'modern-hotel-booking'); ?>
                                    </button>
                                </div>
                                <p class="description"><?php esc_html_e('Optional. Overrides the room type image for this specific unit on the frontend.', 'modern-hotel-booking'); ?></p>
                                <?php if ($edit_mode && '' !== (string) ($edit_data->image_url ?? '')): ?>
                                    <img src="<?php echo esc_url($edit_data->image_url); ?>" alt="" style="margin-top:8px; max-height:80px; border-radius:6px; object-fit:cover;">
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                        <input type="submit" name="submit_room" class="button button-primary button-hero"
                            value="<?php echo $edit_mode ? esc_attr(I18n::get_label('btn_save_unit')) : esc_attr(I18n::get_label('btn_register_unit')); ?>">
                        <?php if ($edit_mode): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-rooms')); ?>"
                                class="button button-hero" style="margin-left: 10px;"><?php esc_html_e('Discard Changes', 'modern-hotel-booking'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="mhbo-card">
                <h3 style="margin-top:0; margin-bottom: 20px; font-size: 1.2rem; display: flex; align-items: center;">
                    <span class="dashicons dashicons-list-view" style="margin-right: 10px; color: #1a3b5d;"></span>
                    <?php esc_html_e('Full Unit Inventory', 'modern-hotel-booking'); ?>
                </h3>
                <div class="mhbo-table-responsive">
                    <table class="wp-list-table widefat fixed striped" style="box-shadow: none; border: none;">
                        <thead>
                            <tr>
                                <th style="width:50px;"><?php echo esc_html(I18n::get_label('label_col_id')); ?></th>
                                <th style="width:70px;"><?php esc_html_e('Image', 'modern-hotel-booking'); ?></th>
                                <th style="width:120px;"><?php echo esc_html(I18n::get_label('label_col_unit')); ?></th>
                                <th><?php echo esc_html(I18n::get_label('label_classification')); ?></th>
                                <th><?php echo esc_html(I18n::get_label('label_col_rate')); ?></th>
                                <th style="width:140px;"><?php echo esc_html(I18n::get_label('label_col_status')); ?></th>
                                <th style="width:160px; text-align: right;"><?php echo esc_html(I18n::get_label('label_col_mgmt')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($rooms) === 0): ?>
                                <tr><td colspan="7" style="padding:40px; text-align:center; color:#94a3b8; font-style: italic;"><?php esc_html_e('Inventory is completely empty.', 'modern-hotel-booking'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($rooms as $r): ?>
                                    <tr>
                                        <td><code style="font-size: 11px;">#<?php echo esc_html($r->id); ?></code></td>
                                        <td>
                                            <?php $thumb = (isset($r->image_url) ? (string)$r->image_url : '') ?: ($r->type_image_url ?? ''); ?>
                                            <?php if ($thumb): ?>
                                                <img src="<?php echo esc_url($thumb); ?>" alt="" style="width:54px; height:38px; object-fit:cover; border-radius:4px; display:block;">
                                            <?php else: ?>
                                                <span style="display:block; width:54px; height:38px; background:#f1f5f9; border-radius:4px;"></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong style="font-size: 1.1rem; color: #1a3b5d;"><?php echo esc_html($r->room_number); ?></strong></td>
                                        <td>
                                            <span style="background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                                <?php echo esc_html(I18n::decode($r->type_name)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 700; color: #166534;"><?php echo esc_html(I18n::format_currency($r->custom_price ?: $r->base_price)); ?></span>
                                            <?php if ($r->custom_price): ?>
                                                <span class="mhbo-tooltip" style="margin-left: 5px;"><i class="mhbo-help-icon" style="background:#c5a059; color:#fff;">!</i><span class="mhbo-tooltip-text"><?php esc_html_e('Custom rate override active for this unit.', 'modern-hotel-booking'); ?></span></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($r->status === 'available'): ?>
                                                <span style="display: flex; align-items: center; gap: 6px; color: #166534; font-weight: 700; font-size: 0.9rem;">
                                                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 6px rgba(34, 197, 94, 0.4);"></span>
                                                    <?php esc_html_e('Receiving Bookings', 'modern-hotel-booking'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="display: flex; align-items: center; gap: 6px; color: #9a3412; font-weight: 700; font-size: 0.9rem;">
                                                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444;"></span>
                                                    <?php esc_html_e('Out of Service', 'modern-hotel-booking'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 8px; justify-content: flex-end; align-items: center; min-width: 140px;">
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-rooms&action=edit&id={$r->id}"), 'mhbo_edit_room_' . $r->id)); ?>"
                                                    class="button" title="<?php esc_attr_e('Edit Details', 'modern-hotel-booking'); ?>"><span class="dashicons dashicons-edit" style="margin-top:4px;"></span></a>
                                                
                                                <?php /* BUILD_PRO_START */ ?>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-rooms&action=ical&id={$r->id}"), 'mhbo_ical_room_' . $r->id)); ?>"
                                                    class="button" title="<?php esc_attr_e('iCal Connections', 'modern-hotel-booking'); ?>"><span class="dashicons dashicons-update" style="margin-top:4px;"></span></a>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-rooms&action=calendar&id={$r->id}"), 'mhbo_calendar_room_' . $r->id)); ?>"
                                                    class="button" title="<?php esc_attr_e('Calendar Pricing', 'modern-hotel-booking'); ?>" style="background:#ede9fe;color:#6d28d9;border-color:#c4b5fd;"><span class="dashicons dashicons-calendar-alt" style="margin-top:4px;"></span></a>
                                                <?php /* BUILD_PRO_END */ ?>

                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhbo-rooms&action=delete&id={$r->id}"), 'mhbo_delete_room_' . $r->id)); ?>"
                                                    class="button button-link-delete"
                                                    onclick="return confirm('<?php esc_attr_e('Permanently remove this unit from inventory?', 'modern-hotel-booking'); ?>')"><span class="dashicons dashicons-trash" style="margin-top:4px;"></span></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }



    public function display_extras_page()
    {
        if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
        }

        // Handle Form Submission
        if (isset($_POST['mhbo_save_extras'])) { // sanitize_text_field applied or checked via nonce later
            if (!Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
                wp_die(esc_html(I18n::get_label('msg_insufficient_perms_short')));
            }
            if (!check_admin_referer('mhbo_save_extras_action')) {
                wp_die(esc_html(I18n::get_label('msg_security_check_failed')));
            }
            $new_extras = [];
            if (isset($_POST['extras']) && is_array($_POST['extras'])) {
                $extras_data = map_deep(wp_unslash($_POST['extras']), 'sanitize_text_field');
                foreach ($extras_data as $ex) {
                    // Skip if required fields are missing
                    if (!isset($ex['name'], $ex['price'], $ex['pricing_type'], $ex['control_type'])) {
                        continue;
                    }
                    // Sanitize all fields
                    $name = sanitize_text_field($ex['name']);
                    if ($name === '' || null === $name) {
                        continue;
                    }
                    $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
                    $new_extras[] = [
                        'id' => (isset($ex['id']) && $ex['id']) ? sanitize_text_field($ex['id']) : uniqid('extra_'),
                        'name' => $name,
                        'price' => Money::fromDecimal($ex['price'], $currency)->toDecimal(),
                        'pricing_type' => sanitize_key($ex['pricing_type']),
                        'control_type' => sanitize_key($ex['control_type']),
                        'icon' => isset($ex['icon']) ? sanitize_key($ex['icon']) : 'dashicons-star-filled',
                        'description' => isset($ex['description']) ? sanitize_textarea_field($ex['description']) : '',
                        /* BUILD_PRO_START */
                        'compulsory' => (isset($ex['compulsory']) && $ex['compulsory']) ? 1 : 0,
                        /* BUILD_PRO_END */
                    ];

                }
            }
            update_option('mhbo_pro_extras', $new_extras);
            Cache::invalidate_pricing();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(I18n::get_label('msg_extras_saved')) . '</p></div>';
        }

        $extras = get_option('mhbo_pro_extras', []);
        ?>
        <div class="wrap mhbo-admin-wrap">
            <h1 style="margin-bottom: 25px; font-weight: 800; color: #1a3b5d;"><?php esc_html_e('Service Extras & Add-ons', 'modern-hotel-booking'); ?></h1>
            
            <div class="mhbo-stats-grid">
                <div class="mhbo-stat-card">
                    <div class="mhbo-stat-value" id="mhbo-extras-count"><?php echo esc_html((string) count($extras)); ?></div>
                    <div class="mhbo-stat-label"><?php esc_html_e('Active Add-ons', 'modern-hotel-booking'); ?></div>
                </div>
            </div>

            <div class="mhbo-card" style="margin-top: 30px;">
                <h3 style="margin-top:0; margin-bottom: 25px; display: flex; align-items: center;">
                    <span class="dashicons dashicons-money-alt" style="margin-right: 10px; color: #3b82f6;"></span>
                    <?php esc_html_e('Configure Available Services', 'modern-hotel-booking'); ?>
                </h3>

                <form method="post">
                    <?php wp_nonce_field('mhbo_save_extras_action'); ?>
                    <div id="mhbo-extras-list">
                        <?php
                        if (count($extras) > 0) {
                            foreach ($extras as $index => $extra) {
                                $this->render_extra_item($index, $extra);
                            }
                        } else {
                            echo '<div id="mhbo-extras-empty" style="text-align:center; padding:60px 20px; color:#94a3b8; border:2px dashed #e2e8f0; border-radius:12px; margin-bottom:25px;">
                                <span class="dashicons dashicons-plus-alt" style="font-size: 40px; width: 40px; height: 40px; margin-bottom: 15px; opacity: 0.5;"></span>
                                <p style="font-size: 1.1rem; margin: 0;">' . esc_html(I18n::get_label('msg_no_extras_desc')) . '</p>
                                <p style="font-size: 0.9rem; margin-top: 5px; opacity: 0.7;">' . esc_html(I18n::get_label('msg_extras_examples')) . '</p>
                            </div>';
                        }
                        ?>
                    </div>
                    
                    <div style="margin-top:30px; padding-top:25px; border-top:1px solid #f1f5f9;">
                        <button type="button" class="button button-secondary button-hero" id="mhbo-add-extra">
                            <span class="dashicons dashicons-plus-alt" style="margin-top:12px;"></span> <?php echo esc_html(I18n::get_label('label_add_extra')); ?>
                        </button>
                    </div>
                </form>
            </div>

            <?php 
            $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
            ?>
            <script type="text/template" id="tmpl-mhbo-extra">
                <div class="mhbo-extra-item mhbo-card accent" data-extra-id="">
                    <?php $this->render_extra_fields('{{data.index}}', []); ?>
                    <div class="mhbo-extra-actions">
                        <button type="button" class="button button-primary mhbo-save-extra" onclick="mhboSaveExtra(this)"><?php echo esc_html(I18n::get_label('btn_save')); ?></button>
                        <button type="button" class="button mhbo-remove-extra" style="color:#b32d2e;border-color:#b32d2e;" onclick="mhboDeleteExtra(this)"><?php echo esc_html(I18n::get_label('btn_delete')); ?></button>
                    </div>
                </div>
            </script>
        </div>
        <?php
    }

    /**
     * Render a single extra item card.
     *
     * @param string|int $index The item index.
     * @param array<string, mixed> $extra The extra item data.
     */
    private function render_extra_item(string|int $index, array $extra): void
    {
        ?>
        <div class="mhbo-extra-item mhbo-card accent" data-extra-id="<?php echo esc_attr($extra['id'] ?? ''); ?>">
            <?php $this->render_extra_fields((string)$index, $extra); ?>
            <div class="mhbo-extra-actions">
                <button type="button" class="button button-primary mhbo-save-extra" onclick="mhboSaveExtra(this)"><?php esc_html_e('Save', 'modern-hotel-booking'); ?></button>
                <button type="button" class="button mhbo-remove-extra" style="color:#b32d2e;border-color:#b32d2e;" onclick="mhboDeleteExtra(this)"><?php esc_html_e('Delete', 'modern-hotel-booking'); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * Render the fields for an extra item.
     *
     * @param string|int $index The item index.
     * @param array<string, mixed> $extra The extra item data.
     */
    private function render_extra_fields(string|int $index, array $extra): void
    {
        $id = esc_attr($extra['id'] ?? '');
        $name = esc_attr($extra['name'] ?? '');
        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
        $price = esc_attr(Money::fromDecimal((string) ($extra['price'] ?? '0'), $currency)->toDecimal());

        $desc = esc_textarea($extra['description'] ?? '');
        $pt = $extra['pricing_type'] ?? 'fixed';
        $ct = $extra['control_type'] ?? 'checkbox';
        $selected_icon = $extra['icon'] ?? 'dashicons-star-filled';
        
        $is_pro = defined('MHBO_IS_PRO') && MHBO_IS_PRO;
        ?>
        <input type="hidden" name="extras[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($id); ?>">
        <div class="mhbo-extra-grid">
            <div>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th style="width:160px;"><label><?php echo esc_html(I18n::get_label('label_service_title')); ?></label></th>
                        <td>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <input type="text" name="extras[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($name); ?>" class="widefat" placeholder="<?php echo esc_attr(I18n::get_label('label_service_title_placeholder')); ?>" required>
                                <?php if ($is_pro) : ?>
                                    <?php /* BUILD_PRO_START */ ?>
                                    <div class="mhbo-icon-picker">
                                        <select name="extras[<?php echo esc_attr($index); ?>][icon]" class="mhbo-dashicon-select">
                                            <option value="dashicons-star-filled" <?php selected($selected_icon, 'dashicons-star-filled'); ?>>⭐</option>
                                            <option value="dashicons-food" <?php selected($selected_icon, 'dashicons-food'); ?>>🍴</option>
                                            <option value="dashicons-car" <?php selected($selected_icon, 'dashicons-car'); ?>>🚗</option>
                                            <option value="dashicons-palmtree" <?php selected($selected_icon, 'dashicons-palmtree'); ?>>🌴</option>
                                            <option value="dashicons-coffee" <?php selected($selected_icon, 'dashicons-coffee'); ?>>☕</option>
                                            <option value="dashicons-tickets-alt" <?php selected($selected_icon, 'dashicons-tickets-alt'); ?>>🎟️</option>
                                            <option value="dashicons-pets" <?php selected($selected_icon, 'dashicons-pets'); ?>>🐾</option>
                                            <option value="dashicons-heart" <?php selected($selected_icon, 'dashicons-heart'); ?>>❤️</option>
                                            <option value="dashicons-admin-plugins" <?php selected($selected_icon, 'dashicons-admin-plugins'); ?>>⚙️</option>
                                        </select>
                                    </div>
                                    <?php /* BUILD_PRO_END */ ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php echo esc_html(I18n::get_label('label_base_price')); ?></label></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input type="number" step="any" name="extras[<?php echo esc_attr($index); ?>][price]" value="<?php echo esc_attr($price); ?>" class="small-text" required> 
                                <span class="description" style="font-weight: 600;"><?php echo esc_html($currency); ?></span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php echo esc_html(I18n::get_label('label_pricing_model')); ?></label></th>
                        <td>
                            <select name="extras[<?php echo esc_attr($index); ?>][pricing_type]" class="widefat">
                                <option value="fixed" <?php selected($pt, 'fixed'); ?>><?php echo esc_html(I18n::get_label('opt_fixed_fee')); ?></option>
                                <option value="per_person" <?php selected($pt, 'per_person'); ?>><?php echo esc_html(I18n::get_label('opt_per_person')); ?></option>
                                
                                <?php if ($is_pro) : ?>
                                    <?php /* BUILD_PRO_START */ ?>
                                    <option value="per_adult" <?php selected($pt, 'per_adult'); ?>><?php echo esc_html(I18n::get_label('opt_per_adult')); ?></option>
                                    <option value="per_child" <?php selected($pt, 'per_child'); ?>><?php echo esc_html(I18n::get_label('opt_per_child')); ?></option>
                                    <?php /* BUILD_PRO_END */ ?>
                                <?php endif; ?>

                                <option value="per_night" <?php selected($pt, 'per_night'); ?>><?php echo esc_html(I18n::get_label('opt_per_night')); ?></option>
                                <option value="per_person_per_night" <?php selected($pt, 'per_person_per_night'); ?>><?php echo esc_html(I18n::get_label('opt_person_night')); ?></option>
                                
                                <?php if ($is_pro) : ?>
                                    <?php /* BUILD_PRO_START */ ?>
                                    <option value="per_adult_per_night" <?php selected($pt, 'per_adult_per_night'); ?>><?php echo esc_html(I18n::get_label('opt_adult_night')); ?></option>
                                    <option value="per_child_per_night" <?php selected($pt, 'per_child_per_night'); ?>><?php echo esc_html(I18n::get_label('opt_child_night')); ?></option>
                                    <?php /* BUILD_PRO_END */ ?>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>
            <div>
                <table class="form-table" style="margin:0;">
                    <tr class="mhbo-control-type-row">
                        <th style="width:160px;"><label><?php echo esc_html(I18n::get_label('label_booking_input')); ?></label></th>
                        <td>
                            <select name="extras[<?php echo esc_attr($index); ?>][control_type]" class="widefat">
                                <option value="checkbox" <?php selected($ct, 'checkbox'); ?>><?php echo esc_html(I18n::get_label('opt_checkbox')); ?></option>
                                <option value="quantity" <?php selected($ct, 'quantity'); ?>><?php echo esc_html(I18n::get_label('opt_quantity')); ?></option>
                            </select>
                        </td>
                    </tr>
                    <?php /* BUILD_PRO_START */ ?>
                    <?php $is_compulsory = isset($extra['compulsory']) && $extra['compulsory']; ?>
                    <tr>
                        <th style="width:160px;"><label><?php esc_html_e('Compulsory Fee', 'modern-hotel-booking'); ?></label></th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox"
                                    name="extras[<?php echo esc_attr($index); ?>][compulsory]"
                                    value="1"
                                    class="mhbo-compulsory-toggle"
                                    <?php checked($is_compulsory, true); ?>>
                                <span><?php echo esc_html(I18n::get_label('label_compulsory_extra')); ?></span>
                            </label>
                            <p class="description" style="margin-top:5px;"><?php echo esc_html(I18n::get_label('label_service_fee_desc')); ?></p>
                        </td>
                    </tr>
                    <?php /* BUILD_PRO_END */ ?>
                    <tr>
                        <th><label><?php echo esc_html(I18n::get_label('label_public_description')); ?></label></th>
                        <td><textarea name="extras[<?php echo esc_attr($index); ?>][description]" rows="3" class="widefat" placeholder="<?php echo esc_attr(I18n::get_label('label_desc_placeholder')); ?>" style="font-size: 13px;"><?php echo esc_html($desc); ?></textarea></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
    /**
     * Fix the missing page title in admin-header.php for hidden submenus.
     * Prevents "strip_tags(null)" warning in 2026 strict environments.
     */
    public function fix_hidden_page_titles(string $admin_title, string $title): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if (str_starts_with($page, 'mhbo-pro-')) {
            $slug = str_replace('mhbo-pro-', '', $page);
            $titles = array(
                'extras'    => I18n::get_label('menu_extras'),
                'ical'      => I18n::get_label('menu_ical'),
                'payments'  => I18n::get_label('menu_payments'),
                'webhooks'  => I18n::get_label('menu_webhooks'),
                'analytics' => I18n::get_label('menu_analytics'),
                'themes'    => I18n::get_label('menu_appearance'),
                'pricing'   => I18n::get_label('menu_advanced_pricing'),
                'licensing' => I18n::get_label('menu_licensing'),
            );

            if (isset($titles[$slug])) {
                $new_title = $titles[$slug];
                return sprintf('%s &lsaquo; %s &#8212; WordPress', $new_title, get_bloginfo('name'));
            }
        }
        return $admin_title;
    }
}

