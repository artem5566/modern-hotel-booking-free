<?php declare(strict_types=1);

namespace MHBO\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\I18n;

class Calendar
{
    public function init()
    {
        add_shortcode('mhbo_room_calendar', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'load_assets']);
    }

    /**
     * Ensure assets are loaded (late enqueue fallback for widgets/templates).
     * 
     * This method is called during shortcode rendering to ensure assets
     * are loaded even when the shortcode is used in widgets, footers, or
     * other areas not checked by has_shortcode().
     */
    public static function enqueue_assets(): void
    {
        // Register assets if they aren't already (e.g. if Block.php didn't run or we need them early)
        // We register them here to ensure they exist before enqueueing
        if (!wp_style_is('mhbo-calendar-style', 'registered')) {
            wp_register_style(
                'mhbo-calendar-style',
                MHBO_PLUGIN_URL . 'assets/css/mhbo-calendar.css',
                ['mhbo-flatpickr-css', 'mhbo-style'],
                (string) time() // Cache buster for active development
            );
        }
        if (!wp_style_is('mhbo-flatpickr-css', 'registered')) {
            wp_register_style(
                'mhbo-flatpickr-css',
                MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
                [],
                '4.6.13'
            );
        }
        if (!wp_style_is('mhbo-style', 'registered')) {
            wp_register_style(
                'mhbo-style',
                MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css',
                [],
                MHBO_VERSION
            );
        }
        if (!wp_script_is('mhbo-calendar-js', 'registered')) {
            wp_register_script(
                'mhbo-calendar-js',
                MHBO_PLUGIN_URL . 'assets/js/mhbo-calendar.js',
                ['jquery', 'mhbo-flatpickr-js'],
                MHBO_VERSION,
                true
            );
        }
        if (!wp_script_is('mhbo-flatpickr-js', 'registered')) {
            wp_register_script(
                'mhbo-flatpickr-js',
                MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
                [],
                '4.6.13',
                true
            );
        }

        // Enqueue flatpickr first (dependency for calendar)
        if (!wp_style_is('mhbo-flatpickr-css', 'enqueued')) {
            wp_enqueue_style('mhbo-flatpickr-css');
        }
        if (!wp_script_is('mhbo-flatpickr-js', 'enqueued')) {
            wp_enqueue_script('mhbo-flatpickr-js');
        }

        // Enqueue calendar assets
        if (!wp_style_is('mhbo-calendar-style', 'enqueued')) {
            wp_enqueue_style('mhbo-calendar-style');
        }
        if (!wp_script_is('mhbo-calendar-js', 'enqueued')) {
            wp_enqueue_script('mhbo-calendar-js');
        }

        // Enqueue booking modal assets when modal mode is enabled
        if ((int) get_option('mhbo_modal_enabled', 1) === 1) {
            if (!wp_style_is('mhbo-booking-modal-css', 'registered')) {
                wp_register_style(
                    'mhbo-booking-modal-css',
                    MHBO_PLUGIN_URL . 'assets/css/mhbo-booking-modal.css',
                    ['mhbo-style'],
                    MHBO_VERSION
                );
            }
            if (!wp_script_is('mhbo-booking-modal-js', 'registered')) {
                wp_register_script(
                    'mhbo-booking-modal-js',
                    MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-modal.js',
                    [],
                    MHBO_VERSION,
                    true
                );
            }
            if (!wp_style_is('mhbo-booking-modal-css', 'enqueued')) {
                wp_enqueue_style('mhbo-booking-modal-css');
            }
            if (!wp_script_is('mhbo-booking-modal-js', 'enqueued')) {
                wp_enqueue_script('mhbo-booking-modal-js');
                Shortcode::enqueue_for_modal();
                wp_add_inline_script(
                    'mhbo-booking-modal-js',
                    'window.mhboModalI18n = ' . wp_json_encode([
                        'bookNow'      => I18n::get_label('btn_book_now'),
                        'loading'      => I18n::get_label('label_loading'),
                        'close'        => _x('Close', 'action', 'modern-hotel-booking'),
                        'errorLoading'       => __('Could not load booking form. Please try again.', 'modern-hotel-booking'),
                        'confirming'         => __('Confirming your booking', 'modern-hotel-booking'),
                        'errorConfirmation'  => __('Your booking was received but we could not load the confirmation. Please check your email.', 'modern-hotel-booking'),
                    ]) . ';'
                );
            }
        }

        // Inject theme styles if available (must be after enqueueing styles)
        if (class_exists('MHBO\\Frontend\\Shortcode')) {
            Shortcode::inject_theme_styles();
        }

        // Load locale-specific Flatpickr scripts dynamically based on availability
        $current_lang = I18n::get_current_language();
        // Check if a locale file exists for the current language
        $locale_path = MHBO_PLUGIN_DIR . 'assets/js/vendor/flatpickr.' . $current_lang . '.js';

        if (file_exists($locale_path)) {
            if (!wp_script_is('mhbo-flatpickr-' . $current_lang, 'registered')) {
                wp_register_script(
                    'mhbo-flatpickr-' . $current_lang,
                    MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.' . $current_lang . '.js',
                    ['mhbo-flatpickr-js'],
                    '4.6.13',
                    true
                );
            }
            if (!wp_script_is('mhbo-flatpickr-' . $current_lang, 'enqueued')) {
                wp_enqueue_script('mhbo-flatpickr-' . $current_lang);
            }
        } elseif (strlen($current_lang) > 2) {
            // Try 2-letter code if full locale (e.g. pt_BR -> pt) not found
            $short_lang = substr($current_lang, 0, 2);
            $locale_path = MHBO_PLUGIN_DIR . 'assets/js/vendor/flatpickr.' . $short_lang . '.js';
            if (file_exists($locale_path)) {
                if (!wp_script_is('mhbo-flatpickr-' . $short_lang, 'registered')) {
                    wp_register_script(
                        'mhbo-flatpickr-' . $short_lang,
                        MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.' . $short_lang . '.js',
                        ['mhbo-flatpickr-js'],
                        '4.6.13',
                        true
                    );
                }
                if (!wp_script_is('mhbo-flatpickr-' . $short_lang, 'enqueued')) {
                    wp_enqueue_script('mhbo-flatpickr-' . $short_lang);
                }
                // Update current_lang passed to JS to match the loaded locale
                $current_lang = $short_lang;
            }
        }

        // Add localization data for the calendar script (only once)
        if (!wp_script_is('mhbo-calendar-js', 'done')) {
            wp_localize_script('mhbo-calendar-js', 'mhbo_calendar', [
                'rest_url' => get_rest_url(null, 'mhbo/v1/calendar-data'),
                'nonce' => wp_create_nonce('wp_rest'),
                'auto_nonce' => wp_create_nonce('mhbo_auto_action'),
                'settings' => [
                    'currency_symbol' => get_option('mhbo_currency_symbol', '$'),
                    'currency_pos' => get_option('mhbo_currency_position', 'before'),
                    'currency_decimals' => (int) get_option('mhbo_calendar_show_decimals', 0) === 1 ? (int) apply_filters('mhbo_currency_decimals', 2) : 0,
                    'currency_space_before' => 0,
                    'currency_space_after' => 0,
                    'checkin_time' => get_option('mhbo_checkin_time', '14:00'),
                    'checkout_time' => get_option('mhbo_checkout_time', '11:00'),
                    'prevent_turnover' => (int) get_option('mhbo_prevent_same_day_turnover', 0) === 1,
                ],
                'i18n' => [
                    'your_selection' => I18n::get_label('label_your_selection'),
                    'check_in' => I18n::get_label('label_check_in'),
                    'check_out' => I18n::get_label('label_check_out'),
                    'total' => I18n::get_label('label_total'),
                    'select_checkout' => I18n::get_label('label_select_check_out'),
                    'dates_selected' => I18n::get_label('label_dates_selected'),
                    'select_dates_error' => I18n::get_label('label_select_dates_error'),
                    'check_in_from' => I18n::get_label('label_check_in_from'),
                    'check_out_by' => I18n::get_label('label_check_out_by'),
                    'continue_booking' => I18n::get_label('label_continue_booking'),
                    'night' => I18n::get_label('label_night'),
                    'nights' => I18n::get_label('label_nights'),
                    'checkout_only_error' => I18n::get_label('label_checkout_only'),
                    'checkin_only_error' => I18n::get_label('label_checkin_only'),
                    'api_err_min_stay'   => I18n::get_label('api_err_min_stay'),
                    'api_err_max_stay'   => I18n::get_label('api_err_max_stay'),
                    
                ],
                'current_lang' => $current_lang
            ]);
        }
    }

    public function load_assets()
    {
        /** @var \WP_Post $post */
        global $post;
        $has_shortcode = is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'mhbo_room_calendar');
        $has_block = is_a($post, 'WP_Post') && has_block('modern-hotel-booking/room-calendar', $post->post_content);

        if (!$has_shortcode && !$has_block) {
            return;
        }

        self::enqueue_assets();
    }

    private static $calendar_instance_rendered = false;

    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(['room_id' => 0], $atts);
        $room_id = absint($atts['room_id']);

        if (!$room_id) {
            return '<p>' . esc_html(I18n::get_label('label_calendar_no_id')) . '</p>';
        }

        return self::render_unified_view($room_id);
    }

    /**
     * Render the unified calendar view (used by Shortcode, Widget, and Block).
     * 
     * @param int $room_id Room ID to display (0 for aggregated view).
     * @return string HTML content.
     */
    public static function render_unified_view($room_id = 0)
    {
        // Ensure assets are loaded
        self::enqueue_assets();

        ob_start();
        // Flag to control whether pricing should be shown in the calendar UI.
        // Room-specific calendars (room_id > 0) show prices, aggregated views (room_id = 0) do not.
        $show_pricing = ($room_id > 0);
        ?>
        <div class="mhbo-calendar-container mhbo-calendar-wrapper" data-room-id="<?php echo esc_attr((string) $room_id); ?>"
            data-show-price="<?php echo esc_attr($show_pricing ? '1' : '0'); ?>"
            data-modal-mode="<?php echo esc_attr(($room_id > 0 && (int) get_option('mhbo_modal_enabled', 1) === 1) ? '1' : '0'); ?>">
            <div class="mhbo-calendar-guide">
                <?php echo esc_html(I18n::get_label('label_select_check_in')); ?>
            </div>

            <!-- Booking Status Legend -->
            <div class="mhbo-calendar-legend">
                <div class="mhbo-legend-item">
                    <span class="mhbo-legend-color mhbo-legend-confirmed"></span>
                    <span class="mhbo-legend-text"><?php echo esc_html(I18n::get_label('label_legend_confirmed')); ?></span>
                </div>
                <div class="mhbo-legend-item">
                    <span class="mhbo-legend-color mhbo-legend-pending"></span>
                    <span class="mhbo-legend-text"><?php echo esc_html(I18n::get_label('label_legend_pending')); ?></span>
                </div>
            </div>

            <div class="mhbo-calendar-inline"></div>

            <!-- Inline error notification area (hidden until needed) -->
            <div class="mhbo-calendar-errors mhbo-inline-errors" style="display:none !important;"></div>

            <?php
            // Build the action URL for the booking form submission
            $action_url = I18n::decode(get_option('mhbo_booking_page_url'), null, false);
            if ('' === $action_url) {
                $b_page_id = (int) get_option('mhbo_booking_page');
                if ($b_page_id > 0) {
                    $action_url = get_permalink($b_page_id);
                }
            }

            // Fallback: If no booking page is configured, use the home URL to avoid relative path issues
            if ('' === $action_url) {
                $action_url = home_url('/');
            }

            /*
             * Filter the calendar form action URL.
             *
             * @since 1.0.0
             * @param string $action_url The resolved action URL.
             * @param int    $room_id    The current room ID (or 0 for aggregated).
             */
            $action_url = apply_filters('mhbo_calendar_action_url', $action_url, $room_id);

            // Button label depends on context
            $btn_label = ($room_id > 0) ? I18n::get_label('btn_book_now') : I18n::get_label('btn_search_rooms');
            ?>

            <div class="mhbo-selection-box" style="display:none !important;">
                <form action="<?php echo esc_url($action_url); ?>" method="get" class="mhbo-selection-form">
                    <input type="hidden" name="mhbo_nonce" value="<?php echo esc_attr(wp_create_nonce('mhbo_auto_action')); ?>">
                    <input type="hidden" name="room_id" value="<?php echo esc_attr((string) $room_id); ?>">
                    <input type="hidden" name="check_in" class="mhbo-cal-check-in">
                    <input type="hidden" name="check_out" class="mhbo-cal-check-out">
                    <!-- For aggregated view, total price is just an estimate/starting from -->
                    <input type="hidden" name="total_price" class="mhbo-cal-total-price">
                    <input type="hidden" name="mhbo_auto_book" value="1">

                    <div class="mhbo-selection-header">
                        <h3><?php echo esc_html(I18n::get_label('label_your_selection')); ?></h3>
                    </div>

                    <div class="mhbo-selection-details">
                        <div class="mhbo-selection-dates">
                            <div class="mhbo-selection-item">
                                <span class="mhbo-label"><?php echo esc_html(I18n::get_label('label_check_in')); ?></span>
                                <span class="mhbo-value mhbo-display-check-in">-</span>
                                <span
                                    class="mhbo-time-info mhbo-check-in-time"><?php echo esc_html(sprintf(I18n::get_label('label_check_in_from'), esc_html(get_option('mhbo_checkin_time', '14:00')))); ?></span>
                            </div>
                            <div class="mhbo-selection-item">
                                <span class="mhbo-label"><?php echo esc_html(I18n::get_label('label_check_out')); ?></span>
                                <span class="mhbo-value mhbo-display-check-out">-</span>
                                <span
                                    class="mhbo-time-info mhbo-check-out-time"><?php echo esc_html(sprintf(I18n::get_label('label_check_out_by'), esc_html(get_option('mhbo_checkout_time', '11:00')))); ?></span>
                            </div>
                        </div>

                        <?php if ($show_pricing): ?>
                            <div class="mhbo-selection-price">
                                <span class="mhbo-label"><?php echo esc_html(I18n::get_label('label_total_starting_from')); ?></span>
                                <span class="mhbo-price-value mhbo-display-price">-</span>
                            </div>
                        <?php else: ?>
                            <!-- Room Type and Guests selections are deliberately deferred to the subsequent pages -->
                            <input type="hidden" name="type_id" class="mhbo-cal-type-id" value="0">
                            <input type="hidden" name="guests" class="mhbo-cal-guests" value="2">
                            
                        <?php endif; ?>
                    </div>

                    <button type="submit" style="position:relative; z-index:10; width: 100%;"
                        class="mhbo-btn-primary mhbo-booking-btn-submit"><?php echo esc_html($btn_label); ?></button>
                </form>
            </div>

            <?php if (get_option('mhbo_powered_by_link', 0)): ?>
                <div class="mhbo-powered-by" style="text-align: right; margin-top: 10px; font-size: 11px; opacity: 0.7;">
                    <a href="<?php echo esc_url('https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/'); ?>"
                        target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: none;">
                        <?php echo esc_html(I18n::get_label('powered_by')); ?> <strong><?php echo esc_html(I18n::get_label('label_plugin_name')); ?></strong>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
