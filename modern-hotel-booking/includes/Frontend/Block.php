<?php declare(strict_types=1);

namespace MHBO\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\I18n;
use MHBO\Business\Shortcodes as BusinessShortcodes;

/**
 * Handles Gutenberg Block registration for 2026 compliance.
 */
class Block
{
    /**
     * Initialize block hooks.
     */
    public function init()
    {
        add_filter('block_categories_all', [$this, 'add_block_category'], 10, 2);
        add_action('init', [$this, 'register_frontend_assets'], 5);
        add_action('init', [$this, 'register_blocks'], 25);
    }

    /**
     * Add Modern Hotel Booking category to the block inserter.
     *
     * @param array<int, array<string, mixed>> $categories Existing categories.
     * @param mixed $post Current post.
     * @return array<int, array<string, mixed>> Updated categories.
     */
    public function add_block_category(array $categories, $post): array
    {
        return array_merge(
            $categories,
            [
                [
                    'slug'  => 'hotel-booking',
                    'title' => I18n::get_label('label_block_business_info'),
                    'icon'  => 'admin-home',
                ],
            ]
        );
    }

    /**
     * Register frontend scripts and styles for block.json viewScript/style references.
     */
    public function register_frontend_assets()
    {
        // Register Flatpickr CSS
        wp_register_style(
            'mhbo-flatpickr-css',
            MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
            [],
            '4.6.13'
        );

        // Register MHBO base style
        wp_register_style(
            'mhbo-style',
            MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css',
            [],
            MHBO_VERSION
        );

        // Register Calendar CSS
        wp_register_style(
            'mhbo-calendar-style',
            MHBO_PLUGIN_URL . 'assets/css/mhbo-calendar.css',
            ['mhbo-flatpickr-css', 'mhbo-style'],
            MHBO_VERSION
        );

        // Register Flatpickr JS
        wp_register_script(
            'mhbo-flatpickr-js',
            MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
            [],
            '4.6.13',
            true
        );

        // Register Calendar JS
        wp_register_script(
            'mhbo-calendar-js',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-calendar.js',
            ['jquery', 'mhbo-flatpickr-js'],
            MHBO_VERSION,
            true
        );

        // 2026 Best Practice: Register Script Modules for viewScriptModule support
        if (function_exists('wp_register_script_module')) {
            // Register Vendor Modules (Shimmed if necessary)
            wp_register_script_module(
                'mhbo-flatpickr-module',
                MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
                [],
                '4.6.13'
            );

            // Register Block View Modules
            wp_register_script_module(
                'mhbo-booking-form-view',
                MHBO_PLUGIN_URL . 'build/block-booking-form-view.js',
                ['@wordpress/interactivity'],
                MHBO_VERSION
            );

            wp_register_script_module(
                'mhbo-room-calendar-view',
                MHBO_PLUGIN_URL . 'build/block-room-calendar-view.js',
                ['@wordpress/interactivity'],
                MHBO_VERSION
            );
        }
    }

    /**
     * Register all 7 blocks using metadata.
     */
    public function register_blocks()
    {
        $blocks = [
            'booking-form'    => 'render_booking_block',
            'room-calendar'   => 'render_calendar_block',
            'company-info'    => 'render_company_block',
            'whatsapp-button' => 'render_whatsapp_block',
            'banking-details' => 'render_banking_block',
            'revolut-details' => 'render_revolut_block',
            'business-card'   => 'render_card_block',
        ];

        foreach ($blocks as $slug => $callback) {
            $path = MHBO_PLUGIN_DIR . 'assets/block/' . $slug;
            
            if (!file_exists($path . '/block.json')) {
                continue;
            }

            // Register using block.json - WP 6.5+ handles most things from here
            $block = register_block_type($path, [
                'render_callback' => [$this, $callback],
            ]);

            // Set translations for the editor script if present.
            // WP 6.7 BP: guard with has_translation() before calling wp_set_script_translations().
            if ($block && '' !== ($block->editor_script ?? '') && is_string($block->editor_script)) {
                if (!\function_exists('has_translation') || \has_translation('modern-hotel-booking')) {
                    wp_set_script_translations($block->editor_script, 'modern-hotel-booking', MHBO_PLUGIN_DIR . 'languages');
                }
            }

            // 2026 BP: Set translations for viewScriptModule (WP 6.7+ supports wp_set_script_translations
            // for Script Module IDs, not just classic script handles).
            // viewScriptModule may be a string OR an array — iterate safely.
            /** @phpstan-ignore property.notFound */
            if ($block && property_exists($block, 'view_script_module') && [] !== (array)($block->view_script_module ?? [])) {
                $modules = is_array($block->view_script_module)
                    ? $block->view_script_module
                    : [$block->view_script_module];

                $has_trans = !\function_exists('has_translation') || \has_translation('modern-hotel-booking');

                if ($has_trans) {
                    foreach ($modules as $module_id) {
                        if (is_string($module_id) && '' !== $module_id) {
                            wp_set_script_translations($module_id, 'modern-hotel-booking', MHBO_PLUGIN_DIR . 'languages');
                        }
                    }
                }
            }
        }
    }

    /* ---- Render Callbacks (Dynamic) ---- */

    /**
     * @param array<string, mixed> $attributes
     */
    public function render_booking_block($attributes): string
    {
        $room_id = (isset($attributes['roomId'])) ? absint($attributes['roomId']) : 0;
        return do_shortcode(sprintf('[modern_hotel_booking room_id="%d"]', $room_id));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render_calendar_block($attributes): string
    {
        $room_id = (isset($attributes['roomId'])) ? absint($attributes['roomId']) : 0;
        if (!$room_id) {
            return '<div class="mhbo-block-error">' . esc_html(I18n::get_label('label_block_no_room')) . '</div>';
        }
        return do_shortcode(sprintf('[mhbo_room_calendar room_id="%d"]', $room_id));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render_company_block($attributes): string
    {
        return BusinessShortcodes::get_instance()->render_company_info([
            'show_logo'         => ($attributes['showLogo'] ?? true) ? 'yes' : 'no',
            'show_address'      => ($attributes['showAddress'] ?? true) ? 'yes' : 'no',
            'show_contact'      => ($attributes['showContact'] ?? true) ? 'yes' : 'no',
            'show_registration' => ($attributes['showRegistration'] ?? true) ? 'yes' : 'no',
            'layout'            => $attributes['layout'] ?? 'default',
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render_whatsapp_block($attributes): string
    {
        return BusinessShortcodes::get_instance()->render_whatsapp([
            'style'   => $attributes['style'] ?? 'button',
            'text'    => $attributes['text'] ?? '',
            'message' => $attributes['message'] ?? '',
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render_banking_block($attributes): string
    {
        return BusinessShortcodes::get_instance()->render_banking([
            'show_instructions' => ($attributes['showInstructions'] ?? true) ? 'yes' : 'no',
            'layout'            => $attributes['layout'] ?? 'default',
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render_revolut_block($attributes): string
    {
        return BusinessShortcodes::get_instance()->render_revolut([
            'show_qr'   => ($attributes['showQR'] ?? true) ? 'yes' : 'no',
            'show_link' => ($attributes['showLink'] ?? true) ? 'yes' : 'no',
            'layout'    => $attributes['layout'] ?? 'default',
        ]);
    }

     /**
     * @param array<string, mixed> $attributes
     */
    public function render_card_block($attributes): string
    {
        return BusinessShortcodes::get_instance()->render_business_card([
            'sections' => $attributes['sections'] ?? ['all'],
        ]);
    }
}
