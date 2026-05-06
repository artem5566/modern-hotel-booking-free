<?php declare(strict_types=1);

namespace MHBO\Core;
use MHBO\Core\ICal;
use MHBO\Core\License;
use MHBO\Core\Pricing;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Admin\Menu;
use MHBO\Admin\Settings;
use MHBO\Frontend\Shortcode;
use MHBO\Frontend\Calendar;
use MHBO\Frontend\BookingWidget;
use MHBO\Frontend\Block;
use MHBO\Api\RestApi;
use MHBO\Api\ModalApi;
use MHBO\Core\Privacy;


class Plugin
{

    public function __construct()
    {
        // Translations are auto-loaded by WordPress for plugins hosted on WordPress.org since WP 4.6.
    }

    /**
     * Wire up all hooks and load subsystems.
     */
    public function run(): void
    {
        // Initialize I18n filters
        I18n::init();
        Email::init();

        /* ---- iCal Export (public endpoint) ---- */
        /* BUILD_PRO_START */
        add_action('init', function () {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- External API endpoint without nonce
            if (
                'ical_export' === filter_input(INPUT_GET, 'mhbo_action')
                && filter_input(INPUT_GET, 'room_id')
                && !class_exists('MHBO\\Pro\\ICalManager') // Pro Manager handles this at template_redirect; fallback here for edge cases
            ) {
                if (!License::is_active()) {
                    wp_die(esc_html(I18n::get_label('label_pro_required_ical')), '', array('response' => 403));
                }
                $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
                $valid_token = get_option('mhbo_ical_token');
                if ($token === '' || !is_string($valid_token) || $valid_token === '' || !hash_equals((string)$valid_token, $token)) {
                    wp_die(esc_html(I18n::get_label('msg_security_check_token')), '', array('response' => 403));
                }
                $room_id = filter_input(INPUT_GET, 'room_id', FILTER_SANITIZE_NUMBER_INT);
                if ($room_id) {
                    ICal::generate_ics(absint($room_id), $token);
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
        });

        /* BUILD_PRO_START */
        // Note: The specialized Pro scheduler handles intervals (5m, 15m, hourly, etc.)
        // in ICalManager::maybe_schedule_sync().
        /* BUILD_PRO_END */

        /* BUILD_PRO_END */

        /* ---- REST API ---- */
        add_action('rest_api_init', function () {
            $api = new RestApi();
            $api->register_routes();

            if ((int) get_option('mhbo_modal_enabled', 1) === 1) {
                $modal_api = new ModalApi();
                $modal_api->register_routes();
            }
        });

        /* ---- Webhook System (Pro-only) ---- */
        /* BUILD_PRO_START */
        if (class_exists('MHBO\Core\Webhook')) {
            $webhook = new Webhook();
            $webhook->init();
        }

        if (is_admin() && class_exists('MHBO\Admin\WebhookController')) {
            (new \MHBO\Admin\WebhookController())->init();
        }
        /* BUILD_PRO_END */

        /* ---- GDPR / Privacy ---- */
        $privacy = new Privacy();
        $privacy->init();

        /* ---- Block Editor support ---- */
        $block = new Block();
        $block->init();

        /* BUILD_PRO_START */
        if (class_exists('MHBO\Core\LicenseValidator')) {
            $license_validator = new LicenseValidator();
            $license_validator->init();
        }

        // Load Pro Features if in Pro build
        if (License::is_pro_active()) {
            $this->load_pro();
        }
        /* BUILD_PRO_END */

        if (is_admin()) {
            $this->load_admin();
        }

        // 2026 BP: Always initialize frontend modules that handle AJAX/Form-processing requests.
        // is_admin() returns true for AJAX, so we must load these even in admin context.
        $this->load_frontend();

        // Always register AJAX handlers (needed because is_admin() is true for AJAX)
        $calendar = new Calendar();
        $calendar->init();

        // Register Widget (Must be global to show in Admin > Widgets)
        add_action('widgets_init', function () {
            register_widget(BookingWidget::class);
        });

        /* ---- Business Info Module ---- */
        $this->load_business();

        add_filter('plugin_action_links_' . MHBO_PLUGIN_BASENAME, function ($links) {
            $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=mhbo-settings')) . '">' . esc_html(I18n::get_label('label_settings')) . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        });

        $this->schedule_cron();
    }

    private function load_admin(): void
    {
        $menu = new Menu();
        $menu->init();
    }

    private function load_frontend(): void
    {
        $shortcode = new Shortcode();
        $shortcode->init();
    }

    /* BUILD_PRO_START */
    private function load_pro(): void
    {
        if (class_exists('MHBO\Pro\SeasonalRates')) {
            $seasonal_rates = new \MHBO\Pro\SeasonalRates();
            $seasonal_rates->init();
        }
        if (class_exists('MHBO\Pro\ICalManager')) {
            $ical_manager = new \MHBO\Pro\ICalManager();
            $ical_manager->init();
        }
        if (class_exists('MHBO\Pro\BookingExtras')) {
            $extras = new \MHBO\Pro\BookingExtras();
            $extras->init();
        }
        if (class_exists('MHBO\Pro\PaymentGateways')) {
            $gateways = new \MHBO\Pro\PaymentGateways();
            $gateways->init();
        }
        if (class_exists('MHBO\Pro\Analytics') && is_admin()) {
            $analytics = new \MHBO\Pro\Analytics();
            $analytics->init();
        }
        if (class_exists('MHBO\Pro\GDPR')) {
            $gdpr = new \MHBO\Pro\GDPR();
            $gdpr->init();
        }
        if (class_exists('MHBO\Pro\Update\Handler')) {
            $update_handler = new \MHBO\Pro\Update\Handler();
            $update_handler->init();
        }

        if (class_exists(\MHBO\Pro\AdminCalendar::class)) {
            $admin_calendar = new \MHBO\Pro\AdminCalendar();
            $admin_calendar->init();
        }

        // Professional Deposit Selection UI (hooked at priority 11)
        add_action('mhbo_booking_form_after_inputs', [Pricing::class, 'render_deposit_selection_html'], 11, 2);

        if (class_exists(\MHBO\Pro\CouponManager::class)) {
            \MHBO\Pro\CouponManager::init();
        }
        if (class_exists(\MHBO\Pro\CouponAdmin::class) && is_admin()) {
            \MHBO\Pro\CouponAdmin::init();
        }
    }
    /* BUILD_PRO_END */

    /**
     * Load Business Information module.
     */
    private function load_business(): void
    {
        \MHBO\Business\Info::get_instance();
        \MHBO\Business\Shortcodes::get_instance();
    }

    /**
     * Schedule daily maintenance cron job.
     */
    private function schedule_cron(): void
    {
        if (!wp_next_scheduled('mhbo_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'mhbo_daily_maintenance');
        }
    }
}
