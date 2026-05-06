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

        /* ---- iCal Export (public endpoint) ---- *//* ---- REST API ---- */
        add_action('rest_api_init', function () {
            $api = new RestApi();
            $api->register_routes();

            if ((int) get_option('mhbo_modal_enabled', 1) === 1) {
                $modal_api = new ModalApi();
                $modal_api->register_routes();
            }
        });

        /* ---- Webhook System (Pro-only) ---- */

/* ---- GDPR / Privacy ---- */
        $privacy = new Privacy();
        $privacy->init();

        /* ---- Block Editor support ---- */
        $block = new Block();
        $block->init();

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
