<?php declare(strict_types=1);

namespace MHBO\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Tax;
use MHBO\Core\License;
use MHBO\Core\Security;
use MHBO\Core\Cache;

class Shortcode
{

    /**
     * Whether the current process_booking() call is running in AJAX mode.
     * When true, booking_fail() stores errors instead of echoing and
     * booking_done() stores the redirect URL instead of calling wp_safe_redirect().
     */
    private bool $ajax_mode = false;

    /** Stores the error message when $ajax_mode is true. */
    private ?string $ajax_error = null;

    /** Stores the success redirect URL when $ajax_mode is true. */
    private ?string $ajax_redirect = null;

    /**
     * Initialize shortcodes and actions.
     *
     * @return void
     */
    public function init(): void
    {
        // Primary shortcode
        add_shortcode('mhbo_booking_form', [$this, 'render_shortcode']);
        // Backward compatibility
        add_shortcode('modern_hotel_booking', [$this, 'render_shortcode']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Premium Typography: Standard Google Fonts (Inter) for 2026 aesthetics via enqueue_assets
        add_filter('wp_resource_hints', function($urls, $relation_type) {
            if ('preconnect' === $relation_type) {
                $urls[] = 'https://fonts.googleapis.com';
                $urls[] = [
                    'href' => 'https://fonts.gstatic.com',
                    'crossorigin',
                ];
            }
            return $urls;
        }, 10, 2);

        // AJAX booking submission handler (nopriv: guests can book without being logged in).
        add_action('wp_ajax_mhbo_process_booking', [$this, 'handle_ajax_booking']);
        add_action('wp_ajax_nopriv_mhbo_process_booking', [$this, 'handle_ajax_booking']);

        // SECURITY: Handle booking submissions immediately.
        // CRITICAL FIX: This code runs inside init priority 20 (via Plugin::run()).
        // Using add_action('init', ..., 10) here would NEVER fire because priority 10
        // has already passed. We must call these directly.
        $this->handle_form_submissions();
        
    }

/**
     * Entry point for standard POST form submissions.
     * 2026 BP: Always active to support both direct POST and AJAX registration context.
     * All actions are strictly guarded by mhbo_confirm_action nonces.
     */
    public function handle_form_submissions(): void
    {
        // Only act on POST requests that include our form fields
        $post = $_POST ?? [];
        if ([] === $post) {
            return;
        }

        // 1. Security Check First: Verify nonce if any POST data exists.
        if (!isset($_POST['mhbo_confirm_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mhbo_confirm_nonce'])), 'mhbo_confirm_action')) {
            if (isset($_POST['mhbo_confirm_booking'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- inside nonce-failed branch; no data processed
                // Nonce failed
            }
            return;
        }

        // 2. Intent Check: Ensure it's our specific form action.
        if (!isset($_POST['mhbo_confirm_booking'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
            return;
        }

        // Proceed with booking

        // SECURITY: Capture output to prevent "headers already sent" during redirect.
        // We use a specific flag to detect REAL validation errors vs benign warnings/notices.
        ob_start();
        $this->process_booking();
        $output = ob_get_clean();

        if ('' !== $output) {
            // Captured output
        }

        // If we have output AND it contains a specific MHBO error class, then it's a real failure.
        // This makes the process resilient against PHP 8.2+ Deprecation warnings in the buffer.
        $has_real_error = str_contains($output, 'mhbo-error');

        // Log unexpected output (PHP errors, warnings) that aren't MHBO validation errors.
        // This prevents silent failures where process_booking() encounters a PHP error but doesn't redirect.
        // Unexpected output that is not an MHBO error indicates a silent PHP/DB failure.

        if ($has_real_error) {
            // Store error in transient to show after redirect (valid for 1 minute)
            $user_id = get_current_user_id();
        $client_ip = Security::get_client_ip();
            $key = 'mhbo_err_' . ($user_id ? $user_id : md5((string)$client_ip));
            set_transient($key, $output, 60);
            
            // Redirect back to same page but with parameters to restore the booking form state.
            // This prevents the "Redirect Loop" by ensuring the shortcode re-enters the 'Booking Form' stage (Stage 3).
            $referer = wp_get_referer();
            $redirect_url = $referer ? $referer : $this->get_booking_page_url();

            // Resolve room_id from type_id if it's missing (0) before redirecting back on error
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified at the start of handle_form_submissions.
            $room_id = isset($_POST['mhbo_room_id']) ? absint(wp_unslash($_POST['mhbo_room_id'])) : (isset($_POST['room_id']) ? absint(wp_unslash($_POST['room_id'])) : 0);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $type_id = isset($_POST['mhbo_type_id']) ? absint(wp_unslash($_POST['mhbo_type_id'])) : (isset($_POST['type_id']) ? absint(wp_unslash($_POST['type_id'])) : 0);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $check_in = isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $check_out = isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $guests = isset($_POST['guests']) ? absint(wp_unslash($_POST['guests'])) : 1;

            if (0 === $room_id && $type_id > 0 && '' !== $check_in && '' !== $check_out) {
                $resolved = Pricing::find_available_room($type_id, $check_in, $check_out, $guests);
                if ($resolved) {
                    $room_id = $resolved;
                }
            }

            $args = array(
                'room_id'        => $room_id,
                'type_id'        => isset($_POST['mhbo_type_id']) ? absint(wp_unslash($_POST['mhbo_type_id'])) : (isset($_POST['type_id']) ? absint(wp_unslash($_POST['type_id'])) : 0),
                'check_in'       => isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '',
                'check_out'      => isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '',
                'guests'         => isset($_POST['guests']) ? absint(wp_unslash($_POST['guests'])) : 1,
                'total_price'    => isset($_POST['total_price']) ? (float) sanitize_text_field(wp_unslash($_POST['total_price'])) : 0.0,
                'customer_name'  => isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '',
                'customer_email' => isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '',
                'customer_phone' => isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '',
                'admin_notes'    => isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '',
            );

            // Add payment type if present (Stripe/PayPal flows)
            if (isset($_POST['mhbo_payment_type'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified earlier in handle_form_submissions()
                $args['mhbo_payment_type'] = sanitize_key(wp_unslash($_POST['mhbo_payment_type']));
            }

            // CLEAN REDIRECT: Remove existing parameters to avoid stacking and potential loop triggers
            // This is the "Neural Damper" - we MUST NOT redirect back with mhbo_auto_book enabled on failure.
            $redirect_url = $this->remove_mhbo_query_args($redirect_url);
            
            // Add error flag to suppress auto-submit on the next load
            $args['mhbo_error'] = 1;
            $redirect_url = add_query_arg($args, $redirect_url);
            
            // SECURITY: Ensure we don't carry over the auto-book flag into the next session
            $redirect_url = remove_query_arg(['mhbo_auto_book', 'mhbo_nonce'], $redirect_url);
            
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * AJAX booking handler — intercepts form submission via fetch() in the browser.
     *
     * Reuses process_booking() in AJAX mode so all validation logic is executed
     * identically to the standard POST-Redirect-GET path.
     *
     * Responds with:
     *   {"success":false,"data":{"message":"..."}}   — validation failure (inline error display)
     *   {"success":true,"data":{"redirect_url":"..."}} — booking accepted; JS redirects
     *
     * Handle the AJAX confirmation flow.
     * 2026 BP: Registered globally in Plugin.php because admin-ajax.php is an admin context.
     */
    public function handle_ajax_booking(): void
    {
        // 1. Nonce verification — reuse the existing form nonce.
        $nonce = isset($_POST['mhbo_confirm_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['mhbo_confirm_nonce']))
            : '';
        if (!wp_verify_nonce($nonce, 'mhbo_confirm_action')) {
            wp_send_json_error(['message' => I18n::get_label('api_err_nonce')]);
        }

        // 2. Intent check — must be our booking form (same guard as handle_form_submissions).
        if (!isset($_POST['mhbo_confirm_booking'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
            wp_send_json_error(['message' => I18n::get_label('api_err_generic')]);
        }

        // 3. Run process_booking() in AJAX mode (no echo, no wp_safe_redirect).
        $this->ajax_mode     = true;
        $this->ajax_error    = null;
        $this->ajax_redirect = null;

        $this->process_booking();

        $this->ajax_mode = false;

        // 4. Return the result as JSON.
        if ($this->ajax_redirect !== null) {
            wp_send_json_success(['redirect_url' => $this->ajax_redirect]);
        } else {
            $message = $this->ajax_error ?? I18n::get_label('api_err_generic');
            wp_send_json_error(['message' => $message]);
        }
    }

    /**
     * Dual-mode error output helper.
     *
     * In normal mode: echoes the HTML error div (existing behaviour, captured by ob_start).
     * In AJAX mode: stores the plain-text message and returns immediately so process_booking()
     * can return cleanly without needing a redirect.
     *
     * @param string $html_error_div  Full '<div class="mhbo-error">…</div>' string.
     */
    private function booking_fail(string $html_error_div): void
    {
        if ($this->ajax_mode) {
            $this->ajax_error = wp_strip_all_tags($html_error_div);
            return;
        }
        echo wp_kses_post($html_error_div); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- content already escaped at call sites
    }

    /**
     * Dual-mode success redirect helper.
     *
     * In normal mode: calls wp_safe_redirect() and exits (existing behaviour).
     * In AJAX mode: stores the URL and returns so the AJAX handler can send JSON.
     *
     * @param string $url Fully-qualified redirect URL.
     */
    private function booking_done(string $url): void
    {
        if ($this->ajax_mode) {
            $this->ajax_redirect = $url;
            return;
        }
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Ensure assets are loaded (late enqueue fallback for widgets/templates).
     * 
     * This method is called during shortcode rendering to ensure assets
     * are loaded even when the shortcode is used in widgets, footers, or
     * other areas not checked by has_shortcode().
     */
    private function ensure_assets_loaded(): void
    {
        // Enqueue main styles
        if (!wp_style_is('mhbo-style', 'enqueued')) {
            wp_enqueue_style('mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', [], MHBO_VERSION);
        }

        // Enqueue flatpickr first since frontend script depends on it
        if (!wp_style_is('mhbo-flatpickr-css', 'enqueued')) {
            wp_enqueue_style(
                'mhbo-flatpickr-css',
                MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
                [],
                '4.6.13'
            );
        }
        if (!wp_script_is('mhbo-flatpickr-js', 'enqueued')) {
            wp_enqueue_script(
                'mhbo-flatpickr-js',
                MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
                [],
                '4.6.13',
                true
            );
        }

        // Enqueue frontend script (depends on both jQuery and flatpickr)
        if (!wp_script_is('mhbo-frontend', 'enqueued')) {
            wp_enqueue_script('mhbo-frontend', MHBO_PLUGIN_URL . 'assets/js/mhbo-frontend.js', ['jquery', 'mhbo-flatpickr-js'], MHBO_VERSION, true);
        }

        // Enqueue calendar assets via centralized handler
        if (class_exists('MHBO\Frontend\Calendar')) {
            Calendar::enqueue_assets();
        }

        // Inject theme styles (must be after enqueuing styles)
        self::inject_theme_styles();

        // Enqueue booking form script
        if (!wp_script_is('mhbo-booking-form', 'enqueued')) {
            wp_enqueue_script('mhbo-booking-form', MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-form.js', ['jquery', 'mhbo-frontend'], MHBO_VERSION, true);
        }

// Add localization data (only once)
        if (!wp_script_is('mhbo-frontend', 'done')) {
            $localized_data = array(
                'pay_confirm' => I18n::get_label('btn_pay_confirm'),
                'confirm' => I18n::get_label('btn_confirm_booking'),
                'processing' => I18n::get_label('btn_processing'),
                'loading' => I18n::get_label('label_loading'),
                'to' => I18n::get_label('label_to'),
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => get_rest_url(null, 'mhbo/v1'),
                'nonce' => wp_create_nonce('wp_rest'),
                'label_child_n_age' => I18n::get_label('label_child_n_age'),
                'currency_symbol' => get_option('mhbo_currency_symbol', '$'),
                'currency_pos' => get_option('mhbo_currency_position', 'before'),
                'msg_gdpr_required' => I18n::get_label('msg_gdpr_required'),
                'msg_paypal_required' => I18n::get_label('msg_paypal_required'),
                'tax_enabled' => Tax::is_enabled(),
                'tax_mode' => Tax::get_mode(),
                'tax_label' => Tax::get_label(),
                'tax_rate_accommodation' => Tax::get_accommodation_rate(),
                'tax_rate_extras' => Tax::get_extras_rate(),
                'checkin_time' => get_option('mhbo_checkin_time', '14:00'),
                'checkout_time' => get_option('mhbo_checkout_time', '11:00'),
                'auto_nonce' => wp_create_nonce('mhbo_auto_action'),
                'label_setup_failed' => I18n::get_label('label_setup_failed'),
                'label_payment_already_confirmed' => I18n::get_label('label_payment_already_confirmed'),
                'label_finalizing' => I18n::get_label('label_finalizing'),
                'label_gateway_not_ready' => I18n::get_label('label_gateway_not_ready'),
                'label_payment_success_form_fail' => I18n::get_label('label_payment_success_form_fail'),
                'label_payment_cancelled' => I18n::get_label('label_payment_cancelled'),
                'label_redirecting' => I18n::get_label('label_redirecting'),
                'label_loading_payment' => I18n::get_label('label_loading_payment'),
                'label_payment_capture_failed' => I18n::get_label('label_payment_capture_failed'),
                'label_generic_error' => I18n::get_label('api_err_generic'),
                'label_network_error' => I18n::get_label('api_err_network'),
            );

            $localized_data = apply_filters('mhbo_frontend_localized_data', $localized_data);
            wp_add_inline_script('mhbo-frontend', 'var mhbo_vars = ' . wp_json_encode($localized_data) . ';');
        }
    }

    /**
     * Enqueue frontend assets for the booking form.
     *
     * @return void
     */
    public function enqueue_assets(): void
    {
        /** @var \WP_Post $post */
        global $post;
        $has_shortcode = is_a($post, 'WP_Post') && (
            has_shortcode($post->post_content, 'mhbo_booking_form') ||
            has_shortcode($post->post_content, 'modern_hotel_booking')
        );
        $has_block = is_a($post, 'WP_Post') && has_block('modern-hotel-booking/booking-form', $post->post_content);
        $is_booking_page = is_a($post, 'WP_Post') && ((int) get_option('mhbo_booking_page') === $post->ID);

        // If not on booking page, no shortcode, and no block, don't enqueue
        if (!$has_shortcode && !$has_block && !$is_booking_page) {
            return;
        }

        wp_enqueue_style('mhbo-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap', [], MHBO_VERSION);
        wp_enqueue_style('mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', ['mhbo-google-fonts'], MHBO_VERSION);

        // Enqueue flatpickr first since frontend script depends on it
        wp_enqueue_style(
            'mhbo-flatpickr-css',
            MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
            [],
            '4.6.13'
        );
        wp_enqueue_script(
            'mhbo-flatpickr-js',
            MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
            [],
            '4.6.13',
            true
        );

        // Enqueue frontend script (depends on both jQuery and flatpickr)
        wp_enqueue_script('mhbo-frontend', MHBO_PLUGIN_URL . 'assets/js/mhbo-frontend.js', ['jquery', 'mhbo-flatpickr-js'], MHBO_VERSION, true);

        // Enqueue calendar assets for the new search form
        wp_enqueue_style('mhbo-calendar-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-calendar.css', [], (string) time());
        wp_enqueue_script('mhbo-calendar-js', MHBO_PLUGIN_URL . 'assets/js/mhbo-calendar.js', ['jquery', 'mhbo-flatpickr-js'], MHBO_VERSION, true);

        // Ensure calendar script is localized if enqueued here
        if (class_exists('MHBO\Frontend\Calendar')) {
            Calendar::enqueue_assets();
        }

        // Inject theme styles (must be after enqueuing styles)
        self::inject_theme_styles();

        // Booking form interactions
        wp_enqueue_script('mhbo-booking-form', MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-form.js', ['jquery', 'mhbo-frontend'], MHBO_VERSION, true);

// Localize script for JS strings
        $localized_data = array(
            'pay_confirm' => I18n::get_label('btn_pay_confirm'),
            'confirm' => I18n::get_label('btn_confirm_booking'),
            'processing' => I18n::get_label('btn_processing'),
            'loading' => I18n::get_label('label_loading'),
            'to' => I18n::get_label('label_to'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => get_rest_url(null, 'mhbo/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'prevent_turnover' => (int) get_option('mhbo_prevent_same_day_turnover', 0) === 1,
            'label_child_n_age' => I18n::get_label('label_child_n_age'),
            'currency_symbol' => get_option('mhbo_currency_symbol', '$'),
            'currency_pos' => get_option('mhbo_currency_position', 'before'),
            'msg_gdpr_required' => I18n::get_label('msg_gdpr_required'),
            'msg_paypal_required' => I18n::get_label('msg_paypal_required'),
            // Tax settings for frontend
            'tax_enabled' => Tax::is_enabled(),
            'tax_mode' => Tax::get_mode(),
            'tax_label' => Tax::get_label(),
            'tax_rate_accommodation' => Tax::get_accommodation_rate(),
            'tax_rate_extras' => Tax::get_extras_rate(),
            'checkin_time' => get_option('mhbo_checkin_time', '14:00'),
            'checkout_time' => get_option('mhbo_checkout_time', '11:00'),
            'auto_nonce' => wp_create_nonce('mhbo_auto_action'),
            'nonce_confirm' => wp_create_nonce('mhbo_confirm_action'),
            'label_setup_failed' => I18n::get_label('label_setup_failed'),
            'label_payment_already_confirmed' => I18n::get_label('label_payment_already_confirmed'),
            'label_finalizing' => I18n::get_label('label_finalizing'),
            'label_gateway_not_ready' => I18n::get_label('label_gateway_not_ready'),
            'label_payment_success_form_fail' => I18n::get_label('label_payment_success_form_fail'),
            'label_payment_cancelled' => I18n::get_label('label_payment_cancelled'),
            'label_redirecting' => I18n::get_label('label_redirecting'),
            'label_loading_payment' => I18n::get_label('label_loading_payment'),
            'label_payment_capture_failed' => I18n::get_label('label_payment_capture_failed'),
            'label_generic_error' => I18n::get_label('api_err_generic'),
            'label_network_error' => I18n::get_label('api_err_network'),
        );

        $localized_data = apply_filters('mhbo_frontend_localized_data', $localized_data);

        // SECURITY: Use wp_json_encode for proper escaping and WordPress consistency
        wp_add_inline_script('mhbo-frontend', 'var mhbo_vars = ' . wp_json_encode($localized_data) . ';');
    }

    /**
     * Enqueue booking form assets on pages that don't have the shortcode
     * (e.g. calendar-only pages using the inline modal).
     * Skips if assets are already loaded via the normal shortcode path.
     */
    public static function enqueue_for_modal(): void
    {
        if (wp_script_is('mhbo-booking-form', 'enqueued')) {
            return;
        }

        // Main plugin stylesheet — required for the booking form UI.
        if (!wp_style_is('mhbo-style', 'enqueued')) {
            if (!wp_style_is('mhbo-style', 'registered')) {
                wp_register_style('mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', [], MHBO_VERSION);
            }
            wp_enqueue_style('mhbo-style');
        }

        if (!wp_script_is('mhbo-flatpickr-js', 'enqueued')) {
            if (!wp_script_is('mhbo-flatpickr-js', 'registered')) {
                wp_register_script('mhbo-flatpickr-js', MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js', [], '4.6.13', true);
                wp_register_style('mhbo-flatpickr-css', MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css', [], '4.6.13');
            }
            wp_enqueue_script('mhbo-flatpickr-js');
            wp_enqueue_style('mhbo-flatpickr-css');
        }

        wp_enqueue_script(
            'mhbo-frontend',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-frontend.js',
            ['jquery', 'mhbo-flatpickr-js'],
            MHBO_VERSION,
            true
        );
        wp_enqueue_script(
            'mhbo-booking-form',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-form.js',
            ['jquery', 'mhbo-frontend'],
            MHBO_VERSION,
            true
        );

$localized_data = [
            'pay_confirm'                        => I18n::get_label('btn_pay_confirm'),
            'confirm'                            => I18n::get_label('btn_confirm_booking'),
            'processing'                         => I18n::get_label('btn_processing'),
            'loading'                            => I18n::get_label('label_loading'),
            'to'                                 => I18n::get_label('label_to'),
            'ajax_url'                           => admin_url('admin-ajax.php'),
            'rest_url'                           => get_rest_url(null, 'mhbo/v1'),
            'nonce'                              => wp_create_nonce('wp_rest'),
            'prevent_turnover'                   => (int) get_option('mhbo_prevent_same_day_turnover', 0) === 1,
            'label_child_n_age'                  => I18n::get_label('label_child_n_age'),
            'currency_symbol'                    => get_option('mhbo_currency_symbol', '$'),
            'currency_pos'                       => get_option('mhbo_currency_position', 'before'),
            'msg_gdpr_required'                  => I18n::get_label('msg_gdpr_required'),
            'msg_paypal_required'                => I18n::get_label('msg_paypal_required'),
            'tax_enabled'                        => Tax::is_enabled(),
            'tax_mode'                           => Tax::get_mode(),
            'tax_label'                          => Tax::get_label(),
            'tax_rate_accommodation'             => Tax::get_accommodation_rate(),
            'tax_rate_extras'                    => Tax::get_extras_rate(),
            'checkin_time'                       => get_option('mhbo_checkin_time', '14:00'),
            'checkout_time'                      => get_option('mhbo_checkout_time', '11:00'),
            'auto_nonce'                         => wp_create_nonce('mhbo_auto_action'),
            'nonce_confirm'                      => wp_create_nonce('mhbo_confirm_action'),
            'label_setup_failed'                 => I18n::get_label('label_setup_failed'),
            'label_payment_already_confirmed'    => I18n::get_label('label_payment_already_confirmed'),
            'label_finalizing'                   => I18n::get_label('label_finalizing'),
            'label_gateway_not_ready'            => I18n::get_label('label_gateway_not_ready'),
            'label_payment_success_form_fail'    => I18n::get_label('label_payment_success_form_fail'),
            'label_payment_cancelled'            => I18n::get_label('label_payment_cancelled'),
            'label_redirecting'                  => I18n::get_label('label_redirecting'),
            'label_loading_payment'              => I18n::get_label('label_loading_payment'),
            'label_payment_capture_failed'       => I18n::get_label('label_payment_capture_failed'),
            'label_generic_error'                => I18n::get_label('api_err_generic'),
            'label_network_error'                => I18n::get_label('api_err_network'),
        ];

        $localized_data = apply_filters('mhbo_frontend_localized_data', $localized_data);
        wp_add_inline_script('mhbo-frontend', 'var mhbo_vars = ' . wp_json_encode($localized_data) . ';');
    }

    /**
     * Render the booking form HTML as a string for the inline modal.
     * Wraps render_booking_form() output with the modal context wrapper.
     *
     * @param array<string, mixed> $params Booking parameters.
     * @return string HTML string.
     */
    public function render_booking_form_html(array $params): string
    {
        ob_start();
        echo '<div class="mhbo-wrapper mhbo-booking-form-wrapper mhbo-modal-form" data-instance-id="modal" data-modal-context="1">';
        $this->render_booking_form($params);
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Render the booking confirmation panel as a string for the inline modal.
     * Looks up the booking by its unguessable token — no nonce required.
     *
     * @param string $token  The booking_token (reference) value.
     * @param string $status mhbo_status: 'confirmed', 'pending', or 'failed'.
     * @return string HTML string, or empty string if token is invalid.
     */
    public function render_confirmation_html(string $token, string $status): string
    {
        if ('' === $token) {
            return '';
        }

        global $wpdb;

        $cache_key = 'mhbo_booking_ref_' . md5($token);
        $booking   = wp_cache_get($cache_key, 'mhbo_bookings');

        if (false === $booking) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE booking_token = %s",
                $token
            ));
            if ($booking) {
                wp_cache_set($cache_key, $booking, 'mhbo_bookings', 300);
            }
        }

        if (null === $booking) {
            return '';
        }

        $msg_title    = I18n::get_label('msg_booking_confirmed');
        $msg_detail   = I18n::get_label('msg_confirmation_sent');
        $status_class = 'mhbo-status-confirmed';
        $icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';

        if ('pending' === $status) {
            $msg_title    = I18n::get_label('msg_booking_confirmed_received');
            $msg_detail   = I18n::get_label('msg_booking_received_pending');
            $status_class = 'mhbo-status-pending';
            $icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
        } elseif ('failed' === $status) {
            $msg_title    = I18n::get_label('label_failed');
            $msg_detail   = I18n::get_label('label_payment_capture_failed');
            $status_class = 'mhbo-status-failed';
            $icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
        }

        if ('' !== (string) ($booking->customer_email ?? '')) {
            $email_label = ('pending' === $status) ? 'msg_pending_sent_to' : 'msg_confirmation_sent_to';
            $msg_detail  = sprintf(
                // translators: %s: Customer email address
                I18n::get_label($email_label),
                '<strong>' . esc_html($booking->customer_email) . '</strong>'
            );
        }

        if ('arrival' === $booking->payment_method && 'pending' !== $status) {
            $msg_detail = I18n::get_label('msg_booking_received_detail');
        }

        $booking_id     = (int) $booking->id;
        $check_in_time  = get_option('mhbo_checkin_time', '14:00');
        $check_out_time = get_option('mhbo_checkout_time', '11:00');
        $nights         = 0;

        try {
            $start  = new \DateTime($booking->check_in);
            $end    = new \DateTime($booking->check_out);
            $nights = $start->diff($end)->days;
        } catch (\Exception) {
            // Fallback to 0
        }

        $nights_label = ($nights === 1)
            ? I18n::get_label('label_nights_count_single')
            : sprintf(I18n::get_label('label_nights_count'), $nights);

        ob_start();
        ?>
        <div class="mhbo-success-message <?php echo esc_attr($status_class); ?>">
            <div class="mhbo-success-icon"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is hardcoded ?></div>
            <h3><?php echo esc_html($msg_title); ?></h3>
            <p><?php echo wp_kses_post($msg_detail); ?></p>
            <p class="mhbo-reservation-id"><strong><?php echo esc_html(I18n::get_label('label_reservation')); ?>:</strong> <?php echo esc_html((string) $booking_id); ?></p>

            <div class="mhbo-stay-details">
                <div class="mhbo-stay-row">
                    <div class="mhbo-stay-col">
                        <strong><?php echo esc_html(I18n::get_label('label_check_in')); ?></strong>
                        <div class="mhbo-stay-value">
                            <span class="mhbo-stay-date"><?php echo esc_html(I18n::format_date($booking->check_in)); ?></span>
                            <span class="mhbo-stay-label"><?php echo esc_html(sprintf(I18n::get_label('label_check_in_from'), '')); ?></span>
                            <span class="mhbo-stay-time"><?php echo esc_html($check_in_time); ?></span>
                        </div>
                    </div>
                    <div class="mhbo-stay-col">
                        <strong><?php echo esc_html(I18n::get_label('label_check_out')); ?></strong>
                        <div class="mhbo-stay-value">
                            <span class="mhbo-stay-date"><?php echo esc_html(I18n::format_date($booking->check_out)); ?></span>
                            <span class="mhbo-stay-label"><?php echo esc_html(sprintf(I18n::get_label('label_check_out_by'), '')); ?></span>
                            <span class="mhbo-stay-time"><?php echo esc_html($check_out_time); ?></span>
                        </div>
                    </div>
                    <div class="mhbo-stay-col">
                        <strong><?php echo esc_html(I18n::get_label('label_nights')); ?></strong>
                        <div class="mhbo-stay-value"><?php echo esc_html((string) $nights_label); ?></div>
                    </div>
                </div>
            </div>

            <?php if ('completed' === $booking->payment_status) : ?>
                <div class="mhbo-transaction-details">
                    <p><strong><?php echo esc_html(I18n::get_label('label_payment_status')); ?>:</strong> <?php echo esc_html(I18n::get_label('label_paid')); ?></p>
                    <?php if ((float) ($booking->payment_amount ?? 0) > 0) : ?>
                        <p><strong><?php echo esc_html(I18n::get_label('label_amount_paid')); ?>:</strong> <?php echo esc_html(I18n::format_currency((float) $booking->payment_amount)); ?></p>
                    <?php endif; ?>
                    <?php if ('' !== (string) ($booking->payment_transaction_id ?? '')) : ?>
                        <p><strong><?php echo esc_html(I18n::get_label('label_transaction_id')); ?>:</strong> <?php echo esc_html($booking->payment_transaction_id); ?></p>
                    <?php endif; ?>
                </div>
            <?php elseif ('pending' === $booking->payment_status && 'arrival' === $booking->payment_method) : ?>
                <div class="mhbo-transaction-details">
                    <p><strong><?php echo esc_html(I18n::get_label('label_payment_status')); ?>:</strong> <?php echo esc_html(I18n::get_label('label_pay_arrival')); ?></p>
                    <?php
                    $arrival_currency = Pricing::get_currency_code();
                    $arrival_total    = Money::fromDecimal((string) ($booking->total_price ?? 0), $arrival_currency);
                    $arrival_children = Money::fromDecimal((string) ($booking->children_total_net ?? 0), $arrival_currency);
                    if ($arrival_total->isPositive()) :
                        if ($arrival_children->isPositive()) :
                            echo '<p style="margin:4px 0;"><span>' . esc_html(I18n::get_label('label_children')) . ':</span> ' . esc_html($arrival_children->format()) . '</p>';
                        endif;
                        echo '<p style="margin:4px 0;"><strong>' . esc_html(I18n::get_label('label_total')) . ':</strong> ' . esc_html($arrival_total->format()) . '</p>';
                    endif;
                    ?>
                </div>
            <?php elseif ('failed' === $booking->payment_status) : ?>
                <div class="mhbo-transaction-details">
                    <p><strong><?php echo esc_html(I18n::get_label('label_payment_status')); ?>:</strong> <?php echo esc_html(I18n::get_label('label_failed')); ?></p>
                </div>
            <?php endif; ?>

</div>
        <?php
        return (string) ob_get_clean();
    }

    private static int $instance_count = 0;

    /**
     * Render the booking form shortcode.
     *
     * @param array<string, mixed> $atts    Shortcode attributes.
     * @param string|null          $content Shortcode content.
     * @return string The rendered HTML.
     */
    public function render_shortcode(array $atts = [], ?string $content = null): string
    {
        self::$instance_count++;

        // Late enqueue fallback for widgets/templates
        $this->ensure_assets_loaded();

        $atts = shortcode_atts(array(
            'room_id'       => 0,
            'show_calendar' => 'no',
        ), $atts, 'modern_hotel_booking');

        $show_calendar = (strtolower((string)$atts['show_calendar']) === 'yes' || (bool)$atts['show_calendar']);

        ob_start();
        echo '<div class="mhbo-wrapper mhbo-booking-form-wrapper" data-instance-id="' . esc_attr((string) self::$instance_count) . '">';
        
        // Show success message if redirected (nonce-secured)
        $nonce_val = filter_input(INPUT_GET, 'mhbo_success_nonce');
        $success_nonce = $nonce_val ? sanitize_key(wp_unslash($nonce_val)) : '';

        $is_success = isset($_GET['mhbo_success']);
        $nonce_valid = false;
        if ($is_success) {
            $nonce_valid = wp_verify_nonce($success_nonce, 'mhbo_success_display');
        }

        if ($show_calendar && (int)$atts['room_id'] > 0 && !$is_success) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is safely constructed and escaped within the Calendar component.
            echo Calendar::render_unified_view((int)$atts['room_id']);
        }

        if ($is_success && $nonce_valid) {
            $mhbo_status = isset($_GET['mhbo_status']) ? sanitize_key(wp_unslash($_GET['mhbo_status'])) : '';
            $booking_id  = isset($_GET['booking_id']) ? absint(wp_unslash($_GET['booking_id'])) : 0;
            $reference   = isset($_GET['reference']) ? sanitize_text_field(wp_unslash($_GET['reference'])) : '';
            
            $msg_title = I18n::get_label('msg_booking_confirmed');
            $msg_detail = I18n::get_label('msg_confirmation_sent');
            $status_class = 'mhbo-status-confirmed';
            $icon_html = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
            
            if ('pending' === $mhbo_status) {
                $msg_title = I18n::get_label('msg_booking_confirmed_received');
                $msg_detail = I18n::get_label('msg_booking_received_pending');
                $status_class = 'mhbo-status-pending';
                $icon_html = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
            } elseif ('failed' === $mhbo_status) {
                $msg_title = I18n::get_label('label_failed');
                $msg_detail = I18n::get_label('label_payment_capture_failed');
                $status_class = 'mhbo-status-failed';
                $icon_html = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
            }
            
            $message_class = 'mhbo-success-message ' . $status_class;
            
            echo '<div class="' . esc_attr($message_class) . '">';
            echo '<div class="mhbo-success-icon">' . $icon_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is hardcoded and safe
            echo '<h3>' . esc_html($msg_title) . '</h3>';
            
            // SECURITY: Only look up via the unguessable booking_token (reference). Never by
            // numeric booking_id — that path is IDOR-exploitable since the nonce is not
            // bound to a specific booking and booking IDs are sequential integers.
            if ('' !== $reference) {
                global $wpdb;
                $cache_key = 'mhbo_booking_ref_' . md5($reference);
                $booking = wp_cache_get($cache_key, 'mhbo_bookings');

                if (false === $booking) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Retrieving booking details for success page display from custom table.
                    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE booking_token = %s", $reference));

                    if ($booking) {
                        wp_cache_set($cache_key, $booking, 'mhbo_bookings', 300);
                    }
                }

                if (null !== $booking) {
                    $booking_id = (int) $booking->id;
                }
                
                if (null !== $booking) {
                    // Update confirmation message with email if available
                    if ($booking->customer_email) {
                        $email_label = ('pending' === $mhbo_status)
                            ? 'msg_pending_sent_to'
                            : 'msg_confirmation_sent_to';
                        $msg_detail = sprintf(
                            // translators: %s: Customer email address
                            I18n::get_label($email_label),
                            '<strong>' . esc_html($booking->customer_email) . '</strong>'
                        );
                    }

                    if ('arrival' === $booking->payment_method && 'pending' !== $mhbo_status) {
                        $msg_detail = I18n::get_label('msg_booking_received_detail');
                    }
                    
                    echo '<p>' . wp_kses_post($msg_detail) . '</p>';
                    echo '<p class="mhbo-reservation-id"><strong>' . esc_html(I18n::get_label('label_reservation')) . ':</strong> ' . esc_html((string)$booking_id) . '</p>';

                    // Stay Details
                    $check_in_time = get_option('mhbo_checkin_time', '14:00');
                    $check_out_time = get_option('mhbo_checkout_time', '11:00');
                    
                    $nights = 0;
                    try {
                        $start = new \DateTime($booking->check_in);
                        $end = new \DateTime($booking->check_out);
                        $nights = $start->diff($end)->days;
                    } catch (\Exception) {
                        // Fallback
                    }

                    $nights_label = ($nights === 1) ? I18n::get_label('label_nights_count_single') : sprintf(I18n::get_label('label_nights_count'), $nights);

                    echo '<div class="mhbo-stay-details">';
                    echo '<div class="mhbo-stay-row">';
                    // Check-in
                    echo '<div class="mhbo-stay-col">';
                    echo '<strong>' . esc_html(I18n::get_label('label_check_in')) . '</strong>';
                    echo '<div class="mhbo-stay-value">';
                    echo '<span class="mhbo-stay-date">' . esc_html(I18n::format_date($booking->check_in)) . '</span> ';
                    echo '<span class="mhbo-stay-label">' . esc_html(sprintf(I18n::get_label('label_check_in_from'), '')) . '</span> ';
                    echo '<span class="mhbo-stay-time">' . esc_html($check_in_time) . '</span>';
                    echo '</div></div>';

                    // Check-out
                    echo '<div class="mhbo-stay-col">';
                    echo '<strong>' . esc_html(I18n::get_label('label_check_out')) . '</strong>';
                    echo '<div class="mhbo-stay-value">';
                    echo '<span class="mhbo-stay-date">' . esc_html(I18n::format_date($booking->check_out)) . '</span> ';
                    echo '<span class="mhbo-stay-label">' . esc_html(sprintf(I18n::get_label('label_check_out_by'), '')) . '</span> ';
                    echo '<span class="mhbo-stay-time">' . esc_html($check_out_time) . '</span>';
                    echo '</div></div>';

                    // Duration
                    echo '<div class="mhbo-stay-col">';
                    echo '<strong>' . esc_html(I18n::get_label('label_nights')) . '</strong>';
                    echo '<div class="mhbo-stay-value">' . esc_html((string)$nights_label) . '</div>';
                    echo '</div>';
                    echo '</div>'; // .mhbo-stay-row
                    echo '</div>'; // .mhbo-stay-details

                    // Show payment summary
                    if ('completed' === $booking->payment_status) {
                        echo '<div class="mhbo-transaction-details">';
                        echo '<p><strong>' . esc_html(I18n::get_label('label_payment_status')) . ':</strong> ' . esc_html(I18n::get_label('label_paid')) . '</p>';
                        if ((float) ($booking->payment_amount ?? 0) > 0) {
                            echo '<p><strong>' . esc_html(I18n::get_label('label_amount_paid')) . ':</strong> ' . esc_html(I18n::format_currency((float) $booking->payment_amount)) . '</p>';
                        }
                        if ('' !== (string) ($booking->payment_transaction_id ?? '')) {
                            echo '<p><strong>' . esc_html(I18n::get_label('label_transaction_id')) . ':</strong> ' . esc_html($booking->payment_transaction_id) . '</p>';
                        }
                        echo '</div>';
                    } elseif ('pending' === $booking->payment_status && 'arrival' === $booking->payment_method) {
                        echo '<div class="mhbo-transaction-details">';
                        echo '<p><strong>' . esc_html(I18n::get_label('label_payment_status')) . ':</strong> ' . esc_html(I18n::get_label('label_pay_arrival')) . '</p>';
                        // Show itemised cost breakdown so the confirmed total (incl. children) is always visible.
                        $arrival_currency = Pricing::get_currency_code();
                        $arrival_total    = Money::fromDecimal((string) ($booking->total_price ?? 0), $arrival_currency);
                        $arrival_children = Money::fromDecimal((string) ($booking->children_total_net ?? 0), $arrival_currency);
                        if ($arrival_total->isPositive()) {
                            if ($arrival_children->isPositive()) {
                                echo '<p style="margin:4px 0;"><span>' . esc_html(I18n::get_label('label_children')) . ':</span> ' . esc_html($arrival_children->format()) . '</p>';
                            }
                            echo '<p style="margin:4px 0;"><strong>' . esc_html(I18n::get_label('label_total')) . ':</strong> ' . esc_html($arrival_total->format()) . '</p>';
                        }
                        echo '</div>';
                    } elseif ('failed' === $booking->payment_status) {
                        echo '<div class="mhbo-transaction-details">';
                        echo '<p><strong>' . esc_html(I18n::get_label('label_payment_status')) . ':</strong> ' . esc_html(I18n::get_label('label_failed')) . '</p>';
                        echo '</div>';
                    }
                    
                    // Show tax breakdown using shared renderer
                    
                }
            } else {
                // Fallback for when booking ID is missing but success flag is present
                echo '<p>' . esc_html($msg_detail) . '</p>';
            }

            echo '</div>';
        } else {
            // Show any errors captured during handle_form_submissions redirect
            $user_id = get_current_user_id();
        $client_ip = Security::get_client_ip();
            $key = 'mhbo_err_' . ($user_id ? $user_id : md5((string)$client_ip));
            $error = get_transient($key);
            if ('' !== $error) {
                echo wp_kses_post((string)$error);  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content already escaped in process_booking branches
                delete_transient($key);
                // Add a small JS script to scroll to the error
                echo '<script>window.addEventListener("DOMContentLoaded", function() { const err = document.querySelector(".mhbo-error, .mhbo-message.mhbo-error"); if(err) err.scrollIntoView({behavior: "smooth", block: "center"}); });</script>';
            }

            // handle_booking_process handles the DISPLAY branches (search/form/etc)
            $this->handle_booking_process($atts);
        }
        
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Orchestrate the booking flow based on request parameters.
     *
     * @param array<string, mixed> $atts Shortcode attributes.
     * @return void
     */
    private function handle_booking_process(array $atts = []): void
    {
        // 0. Show error from transient if exists (redirected from handle_form_submissions)
        $user_id = get_current_user_id();
        $client_ip = Security::get_client_ip();
        $key = 'mhbo_err_' . ($user_id ? $user_id : md5((string)$client_ip));
        $error_msg = get_transient($key);
        if ('' !== $error_msg) {
            delete_transient($key);
            echo wp_kses_post((string)$error_msg);
        }

        $room_id_attr = isset($atts['room_id']) ? absint($atts['room_id']) : 0;

        // 1. Process 'Book Now' from search results (POST) - Redirect to clean GET URL with real Room ID
        if (isset($_POST['mhbo_book_room'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
            $nonce = isset($_POST['mhbo_book_now_nonce']) ? sanitize_key(wp_unslash($_POST['mhbo_book_now_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'mhbo_book_now_action')) {
                echo '<div class="mhbo-message mhbo-error">' . esc_html(I18n::get_label('label_security_error')) . '</div>';
                return;
            }

            // Extract values and redirect to Stage 3 (GET) to keep URL clean and unique
            $redirect_args = array(
                'mhbo_auto_book' => 1,
                'mhbo_nonce'     => wp_create_nonce('mhbo_auto_action'),
                'room_id'        => isset($_POST['room_id']) ? absint(wp_unslash($_POST['room_id'])) : 0,
                'type_id'        => isset($_POST['type_id']) ? absint(wp_unslash($_POST['type_id'])) : 0,
                'check_in'       => isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '',
                'check_out'      => isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '',
                'guests'         => isset($_POST['guests']) ? absint(wp_unslash($_POST['guests'])) : 1,
                'children'       => isset($_POST['children']) ? absint(wp_unslash($_POST['children'])) : 0,
                'total_price'    => isset($_POST['total_price']) ? (float) sanitize_text_field(wp_unslash($_POST['total_price'])) : 0.0,
            );

            $redirect_url = add_query_arg($redirect_args, $this->get_booking_page_url());

wp_safe_redirect($redirect_url);
            exit;
        }

        // 2. Process Manual Search Form (POST) - Priority 2
        if (isset($_POST['mhbo_search'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
            $nonce = isset($_POST['mhbo_search_nonce']) ? sanitize_key(wp_unslash($_POST['mhbo_search_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'mhbo_search_action')) {
                echo '<div class="mhbo-message mhbo-error">' . esc_html(I18n::get_label('label_security_error')) . '</div>';
                return;
            }
            
            // Extract and sanitize for explicit passing (satisfies WPCS NonceVerification)
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_booking_process.
            $check_in = isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $check_out = isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $guests = isset($_POST['guests']) ? absint(wp_unslash($_POST['guests'])) : 2;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $room_id_filter = isset($_POST['room_id_filter']) ? intval(wp_unslash($_POST['room_id_filter'])) : $room_id_attr;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $children_search = isset($_POST['children']) ? absint(wp_unslash($_POST['children'])) : 0;

            $this->render_search_results($room_id_filter, $check_in, $check_out, $guests, 0, $children_search);
            return;
        }

        // 3. Check for Automatic Booking/Search (GET) - Priority 3
        // We favor nonced links for auto-book (Priority), but allow deep-linking for search.
        $is_auto_book = isset($_GET['mhbo_auto_book']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below for auto-book.
        $is_auto_search = isset($_GET['mhbo_auto_search']) || (isset($_GET['check_in']) && isset($_GET['check_out'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search parameters.

        if ($is_auto_book || $is_auto_search) {
            // Extraction with strict sanitization (2026/WP Repo Compliance)
            $room_id = isset($_GET['room_id']) ? absint(wp_unslash($_GET['room_id'])) : $room_id_attr;
            $type_id = isset($_GET['type_id']) ? absint(wp_unslash($_GET['type_id'])) : 0;
            $check_in = isset($_GET['check_in']) ? sanitize_text_field(wp_unslash($_GET['check_in'])) : '';
            $check_out = isset($_GET['check_out']) ? sanitize_text_field(wp_unslash($_GET['check_out'])) : '';
            $guests   = isset($_GET['guests']) ? absint(wp_unslash($_GET['guests'])) : 2;
            $children = isset($_GET['children']) ? absint(wp_unslash($_GET['children'])) : 0;
            $exclude_id = isset($_GET['exclude_id']) ? absint(wp_unslash($_GET['exclude_id'])) : 0;

            // For auto-book, we REQUIRE a nonce for security (Priority)
            if ($is_auto_book) {
                $nonce = isset($_GET['mhbo_nonce']) ? sanitize_key(wp_unslash($_GET['mhbo_nonce'])) : '';
                if (wp_verify_nonce($nonce, 'mhbo_auto_action')) {
                    if ($room_id === 0 && $type_id > 0 && '' !== $check_in && '' !== $check_out) {
                        // Resolve room_id from type_id and redirect to clean URL
                        $resolved_room_id = Pricing::find_available_room($type_id, $check_in, $check_out, $guests);
                        if ($resolved_room_id > 0) {
                            $redirect_url = add_query_arg(
                                [
                                    'mhbo_auto_book' => 1,
                                    'mhbo_nonce'     => $nonce,
                                    'room_id'        => $resolved_room_id,
                                    'type_id'        => $type_id,
                                    'check_in'       => $check_in,
                                    'check_out'      => $check_out,
                                    'guests'         => $guests,
                                    'children'       => $children,
                                    'total_price'    => isset($_GET['total_price']) ? (float) sanitize_text_field(wp_unslash($_GET['total_price'])) : 0.0,
                                ],
                                $this->get_booking_page_url()
                            );
                            wp_safe_redirect($redirect_url);
                            exit;
                        }
                    }

                    if ($room_id > 0) {
                        $currency_code = Pricing::get_currency_code();

                        // 2026 BP: Recover child_ages BEFORE price recalculation so
                        // the displayed price accounts for children charges.
                        $recovered_child_ages = [];

// 2026 BP: ALWAYS recalculate price server-side for the form display.
                        // Never trust the total_price from the URL — it could be tampered.
                        // Pass children + child_ages so children supplements are included.
                        // Extras are not pre-selected from the URL — compulsory extras auto-inject
                        // inside calculate_booking_money.
                        $calc_preview = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, max( 1, $guests ), [], $children, $recovered_child_ages );
                        $total = ( is_array( $calc_preview ) && isset( $calc_preview['total'] ) )
                            ? $calc_preview['total']
                            : Money::fromCents( 0, $currency_code );
                        $this->render_booking_form(array(
                            'room_id'        => $room_id,
                            'type_id'        => $type_id,
                            'check_in'       => $check_in,
                            'check_out'      => $check_out,
                            'guests'         => max(1, $guests),
                            'children'       => $children,
                            'exclude_id'     => $exclude_id,
                            
                            'total_price'    => $total,
                            'customer_name'  => isset($_GET['customer_name']) ? sanitize_text_field(wp_unslash($_GET['customer_name'])) : '',
                            'customer_email' => isset($_GET['customer_email']) ? sanitize_email(wp_unslash($_GET['customer_email'])) : '',
                            'customer_phone' => isset($_GET['customer_phone']) ? sanitize_text_field(wp_unslash($_GET['customer_phone'])) : '',
                            'admin_notes'    => isset($_GET['admin_notes']) ? sanitize_textarea_field(wp_unslash($_GET['admin_notes'])) : '',
                        ));
                        return;
                    } else {
                        // Suppress error if we are about to show search results (Better UX)
                        if (!$is_auto_search) {
                            echo '<div class="mhbo-error mhbo-message mhbo-error">' . esc_html(I18n::get_label('label_no_room_available_auto')) . '</div>';
                        }
                    }
                }
            }

            // For search, we allow deep-linking without nonce if dates are valid
            if ($this->validate_date($check_in) && $this->validate_date($check_out)) {
                $this->render_search_results($room_id, $check_in, $check_out, $guests, $type_id, $children);
                return;
            }
        }

        // 4. Default: Render empty search form or unified view
        $this->render_search_form($room_id_attr);
    }

    /**
     * Render the search form (unified view).
     *
     * @param int $room_id Optional room ID to focus on.
     * @return void
     */
    private function render_search_form(int $room_id = 0): void
    {
        // Unified Calendar View replacing the old search form
        // Delegates to the centralized Calendar renderer
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Calendar::render_unified_view returns pre-escaped HTML from internal components
        echo wp_kses_post(Calendar::render_unified_view($room_id));
    }

    private function render_search_results( int $room_id_filter = 0, string $check_in = '', string $check_out = '', int $guests = 1, int $type_id_filter = 0, int $children = 0 ): void
    {
        global $wpdb;

        // Ensure we have minimal valid data (Already verified if from POST, or sanitized if from GET)
        if ('' === $check_in || '' === $check_out) {
            $this->render_search_form($room_id_filter);
            return;
        }

        // Date Validation
        $today = wp_date('Y-m-d');
        if ($check_in < $today) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_check_in_past')) . '</div>';
            $this->render_search_form();
            return;
        }
        if ($check_out <= $check_in) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_check_out_after')) . '</div>';
            $this->render_search_form();
            return;
        }

        // $room_id_filter is now passed as an argument, no longer fall back to POST here

        $query_args = [];
        $sql = "SELECT r.id, r.type_id, r.room_number, r.status, r.custom_price,
                       r.image_url as room_image_url, t.image_url as type_image_url,
                       t.name as type_name, t.base_price, t.max_adults, t.max_children,
                       t.description as description, t.amenities as amenities,
                       t.description as type_description, t.amenities as type_amenities
                FROM {$wpdb->prefix}mhbo_rooms r
                JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                WHERE r.status = 'available'";

        if ( 0 !== $room_id_filter ) {
            $sql .= " AND r.id = %d";
            $query_args[] = $room_id_filter;
        }

        if ( 0 !== $type_id_filter ) {
            $sql .= " AND r.type_id = %d";
            $query_args[] = $type_id_filter;
        }

        // Same-day Turnover Setting
        $prevent_same_day = (int) get_option('mhbo_prevent_same_day_turnover', 0) === 1;

        // Industry-standard overlap logic (dynamically settings-aware)
        // Refactored to avoid dynamic condition interpolation for scanner compliance
        if ($prevent_same_day) {
            $sql .= " AND r.id NOT IN ( 
                        SELECT room_id FROM {$wpdb->prefix}mhbo_bookings 
                        WHERE (check_in <= DATE(%s) AND check_out >= DATE(%s))
                        AND status != 'cancelled' 
                    )";
        } else {
            $sql .= " AND r.id NOT IN ( 
                        SELECT room_id FROM {$wpdb->prefix}mhbo_bookings 
                        WHERE (check_in < DATE(%s) AND check_out > DATE(%s))
                        AND status != 'cancelled' 
                    )";
        }

        // Remove SQL GROUP BY to prevent ONLY_FULL_GROUP_BY mode failures in MySQL 5.7+
        // $sql .= " GROUP BY r.type_id";
        
        $query_args[] = $check_out; 
        $query_args[] = $check_in;

        // Implement manual caching for search results
        $cache_key = 'mhbo_available_rooms_v3_' . md5($sql . wp_json_encode($query_args));
        $available_rooms = wp_cache_get($cache_key, 'mhbo_bookings');

        if (false === $available_rooms) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: High-performance room availability search across custom relational tables. Query is safely assembled using $wpdb->prepare placeholders for all variables.
            $all_rooms = $wpdb->get_results($wpdb->prepare($sql, ...$query_args));
            
            $available_rooms = [];
            $seen_types = [];
            if ( null !== $all_rooms && [] !== $all_rooms ) {
                foreach ($all_rooms as $room) {
                    if (!isset($seen_types[$room->type_id])) {
                        $available_rooms[] = $room;
                        $seen_types[$room->type_id] = true;
                    }
                }
            }
            
            wp_cache_set($cache_key, $available_rooms, 'mhbo_bookings', 300); // Cache for 5 minutes
        }

echo '<h3>' . esc_html(sprintf(I18n::get_label('label_available_rooms'), $check_in, $check_out)) . '</h3>';
        if ([] === $available_rooms) {
            echo '<p>' . esc_html(I18n::get_label('label_no_rooms')) . '</p>';
            $this->render_search_form();
            return;
        }

        echo '<div class="mhbo-rooms-grid">';
        foreach ($available_rooms as $room) {
            $start_date = new \DateTime($check_in);
            $end_date = new \DateTime($check_out);
            $interval = new \DateInterval('P1D');
            $period = new \DatePeriod($start_date, $interval, $end_date);

            $currency = Pricing::get_currency_code();
            $total = Money::fromCents(0, $currency);
            foreach ($period as $dt) {
                $date_str = $dt->format('Y-m-d');
                $total = $total->add(Pricing::calculate_daily_price_money((int) $room->id, $date_str));
            }

            $days = iterator_count($period);
            // $price is just for display "per night", maybe average?
            $avg_price = $days > 0 ? (float)$total->toDecimal() / $days : (float)($room->custom_price ?: $room->base_price);

            $amenities = $room->amenities ? json_decode($room->amenities) : [];

            // Room-specific image takes priority; falls back to room type image.
            $resolved_img = ('' !== (string)($room->room_image_url ?? '')) ? $room->room_image_url : ($room->type_image_url ?? '');
            $img_style = '';
            if ('' !== (string)$resolved_img) {
                $img_style = 'background:url(' . esc_url($resolved_img) . ') center/cover;';
            } else {
                $img_style = 'background: linear-gradient(135deg, var(--mhbo-primary) 0%, var(--mhbo-accent) 100%); opacity: 0.8;';
            }

            echo '<div class="mhbo-room-card">';
            echo '<div class="mhbo-room-image" style="height:200px; ' . esc_attr((string)$img_style) . '"></div>';
            echo '<div class="mhbo-room-content">';
            echo '<h4 class="mhbo-room-title">' . esc_html(I18n::decode($room->type_name)) . '</h4>';

            $desc = I18n::decode($room->description ?: ($room->type_description ?? ''));
            if ('' !== $desc) {
                echo '<p class="mhbo-room-description" style="font-size:0.9rem; color:#666; margin-bottom:15px;">' . esc_html(wp_trim_words((string)$desc, 20)) . '</p>';
            }

            echo '<div class="mhbo-room-price">' . esc_html(I18n::format_currency($avg_price)) . ' <span>' . esc_html(I18n::get_label('label_per_night')) . '</span></div>';

            $amenities_raw = $room->amenities ?: ($room->type_amenities ?? '[]');
            $amenities = $amenities_raw ? json_decode((string)$amenities_raw) : [];

            if ([] !== $amenities) {
                echo '<div class="mhbo-amenities" style="margin-bottom:10px; font-size:0.85rem; color:#666;">';
                foreach ($amenities as $am) {
                    echo '<span style="display:inline-block; background:#eee; padding:2px 8px; border-radius:12px; margin-right:5px; margin-bottom:5px;">' . esc_html(ucfirst((string)I18n::decode($am))) . '</span>';
                }
                echo '</div>';
            }

            echo '<div class="mhbo-room-details">';
            echo wp_kses_post(sprintf(I18n::get_label('label_total_nights'), $days, '<strong>' . esc_html(I18n::format_currency($total)) . '</strong>'));
            echo '<p>' . esc_html(sprintf(I18n::get_label('label_max_guests'), $room->max_adults)) . '</p>';
            echo '</div>';

            // Fix: If no specific room_id was requested (category search), use the available $room->id found by the query.
            // This prevents "Room 1" from being hardcoded into all results if the user is on the Room 1 page.
            $assigned_room_id = ($room_id_filter > 0 && (int)$room->id === (int)$room_id_filter) ? $room_id_filter : $room->id;

            echo '<form method="post" action="' . esc_url($this->get_booking_page_url()) . '">';
            wp_nonce_field('mhbo_book_now_action', 'mhbo_book_now_nonce');
            echo '<input type="hidden" name="check_in" value="' . esc_attr($check_in) . '"><input type="hidden" name="check_out" value="' . esc_attr($check_out) . '"><input type="hidden" name="room_id" value="' . esc_attr((string) $assigned_room_id) . '"><input type="hidden" name="type_id" value="' . esc_attr((string) $room->type_id) . '"><input type="hidden" name="guests" value="' . esc_attr((string) max(1, $guests)) . '"><input type="hidden" name="children" value="' . esc_attr((string) max(0, $children)) . '"><input type="hidden" name="total_price" value="' . esc_attr((string)$total->toDecimal()) . '">';
            echo '<button type="submit" name="mhbo_book_room" class="mhbo-btn">' . esc_html(I18n::get_label('btn_book_now')) . '</button>';
            echo '</form></div></div>';
        }
        echo '</div>';
    }

    /**
     * Render the final booking details and customer info form.
     *
     * @param array<string, mixed> $params Booking parameters (room_id, dates, etc.).
     * @return void
     */
    private function render_booking_form(array $params = []): void
    {
        global $wpdb;

        // Read booking data exclusively from params (verified in handle_booking_process)
        $room_id    = isset($params['room_id']) ? intval($params['room_id']) : 0;
        $type_id    = isset($params['type_id']) ? intval($params['type_id']) : 0;
        $check_in   = isset($params['check_in']) ? sanitize_text_field($params['check_in']) : '';
        $check_out  = isset($params['check_out']) ? sanitize_text_field($params['check_out']) : '';
        $guests     = isset($params['guests']) ? intval($params['guests']) : 2;
        $currency_code = Pricing::get_currency_code();
        $total_hint = (isset($params['total_price']) && $params['total_price'] instanceof Money) ? $params['total_price'] : Money::fromDecimal((string)($params['total_price'] ?? '0'), $currency_code);
        
        // Customer Details (for re-population)
        $customer_name  = isset($params['customer_name']) ? sanitize_text_field($params['customer_name']) : '';
        $customer_email = isset($params['customer_email']) ? sanitize_email($params['customer_email']) : '';
        $customer_phone = isset($params['customer_phone']) ? sanitize_text_field($params['customer_phone']) : '';
        $admin_notes    = isset($params['admin_notes']) ? sanitize_textarea_field($params['admin_notes']) : '';

        // Resolve room_id from type_id if it's 0 (category booking)
        if (0 === $room_id && 0 !== $type_id) {
            $resolved_room = Pricing::find_available_room($type_id, $check_in, $check_out, $guests);
            if (0 !== $resolved_room) {
                $room_id = $resolved_room;
            }
        }

        $exclude_id = isset($params['exclude_id']) ? intval($params['exclude_id']) : 0;
        $update_id  = isset($params['mhbo_update_id']) ? intval($params['mhbo_update_id']) : 0;

        // Check availability before rendering booking form.
        $available = Pricing::is_room_available((int) $room_id, $check_in, $check_out, $exclude_id);

        if (true !== $available) {
            echo '<div class="mhbo-error mhbo-availability-error">' .
                esc_html(I18n::get_label($available)) .
                '</div>';
            $this->render_search_form();
            return;
        }

$cache_key = 'mhbo_room_details_' . md5((string)$room_id);
        $room = wp_cache_get($cache_key, 'mhbo_bookings');
        if (false === $room) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
            $room = $wpdb->get_row($wpdb->prepare("SELECT t.id as type_id, t.image_url as type_image_url, r.image_url as room_image_url, t.name as type_name, t.base_price, t.max_adults, t.max_children, t.child_rate, t.child_age_free_limit, r.room_number, r.custom_price FROM {$wpdb->prefix}mhbo_rooms r JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id WHERE r.id = %d", $room_id));
            if (null !== $room) {
                wp_cache_set($cache_key, $room, 'mhbo_bookings', 300);
            }
        }

        $room_type_id = $room ? $room->type_id : $type_id;

        // Validate room exists before rendering form
        if (null === $room) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_room_not_found')) . '</div>';
            $this->render_search_form();
            return;
        }

        $resolved_room_img = ($room && $room->room_image_url) ? $room->room_image_url : (($room && $room->type_image_url) ? $room->type_image_url : '');
        $image_url = $resolved_room_img ? esc_url($resolved_room_img) : '';
        $room_name = $room ? I18n::decode($room->type_name) . ' (' . $room->room_number . ')' : I18n::get_label('label_room');
        $total = $total_hint; // Set total to Money object from hint

        // Always recalculate on render to ensure we have the full $calc breakdown for display
        $calc_guests     = $guests;
        $calc_children   = isset($params['children']) ? intval($params['children']) : 0;
        $calc_child_ages = isset($params['child_ages']) ? array_map('absint', (array) $params['child_ages']) : array();
        $calc_extras     = isset($params['extras']) ? array_map('sanitize_text_field', (array) $params['extras']) : array();

        $calc = Pricing::calculate_booking_money($room_id, $check_in, $check_out, $calc_guests, $calc_extras, $calc_children, $calc_child_ages);
        $total = ( false !== $calc ) ? $calc['total'] : Money::fromCents(0, Pricing::get_currency_code());

        $is_pro_active = false;

$deposit_data = null;
        if (MHBO_IS_PRO && get_option('mhbo_deposits_enabled', 0)) {
            $currency = $total->getCurrency();
            // 2026 BP: For 'first_night' deposit type, use room-rate-only calc (no extras, no children)
            // to match the industry standard meaning of "first night's rate" (accommodation only).
            $fn_deposit_type   = (string) get_option('mhbo_deposit_type', 'percentage');
            $fn_end            = gmdate('Y-m-d', strtotime($check_in . ' +1 day'));
            $fn_extras_arg     = ('first_night' === $fn_deposit_type) ? [] : $calc_extras;
            $fn_children_arg   = ('first_night' === $fn_deposit_type) ? 0 : $calc_children;
            $fn_ages_arg       = ('first_night' === $fn_deposit_type) ? [] : $calc_child_ages;
            $fn_calc           = Pricing::calculate_booking_money($room_id, $check_in, $fn_end, $calc_guests, $fn_extras_arg, $fn_children_arg, $fn_ages_arg);
            $first_night_money = (is_array($fn_calc) && isset($fn_calc['total'])) ? $fn_calc['total'] : Money::fromCents(0, $currency);
            // Store in $calc so render_deposit_selection_html uses the correct first-night amount.
            if (is_array($calc)) {
                $calc['first_night_total'] = $first_night_money;
            }
            $deposit_data = Pricing::calculate_deposit_money($total, $first_night_money);
        }

        ?>
        <div class="mhbo-booking-wrapper">
                <div class="mhbo-booking-summary">
                    <h3 class="mhbo-summary-title"><?php echo esc_html(I18n::get_label('label_booking_summary')); ?></h3>
                    
                    <div class="mhbo-summary-content" style="display:flex; align-items:center; gap:16px; margin-bottom:24px;">
                        <?php if ('' !== $image_url): ?>
                            <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr(I18n::get_label('label_room_alt_text')); ?>" style="width:100px; height:70px; object-fit:cover; border-radius:8px; flex-shrink:0;">
                        <?php endif; ?>
                        <div>
                            <strong style="color:var(--mhbo-primary); font-size:1.15rem; display:block; margin-bottom:4px;"><?php echo esc_html($room_name); ?></strong>
                            <span style="font-size: 0.9rem; color: var(--mhbo-text-light); text-transform:uppercase; font-weight:600; letter-spacing:0.5px;"><?php echo esc_html(I18n::get_label('label_room_number')); ?> <?php echo esc_html($room->room_number); ?></span>
                        </div>
                    </div>

                    <?php
                    $ci_date  = \DateTime::createFromFormat('Y-m-d', $check_in);
                    $co_date  = \DateTime::createFromFormat('Y-m-d', $check_out);
                    $nights   = ($ci_date && $co_date) ? max(1, $ci_date->diff($co_date)->days) : 1;
                    $ci_time  = get_option('mhbo_check_in_time', '14:00');
                    $co_time  = get_option('mhbo_check_out_time', '11:00');
                    ?>
                    
                    <div class="mhbo-booking-dates" style="border:1px solid var(--mhbo-border); border-radius:12px; padding:16px; display:flex; justify-content:space-between; margin-bottom:32px; background:var(--mhbo-bg, #fff);">
                        <div style="flex:1;">
                            <div style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:var(--mhbo-text-light); letter-spacing:0.5px;">CHECK IN</div>
                            <div style="font-size:1.05rem; font-weight:800; color:var(--mhbo-primary); margin:4px 0;"><?php echo esc_html($ci_date ? $ci_date->format('M j, Y') : $check_in); ?></div>
                            <div style="font-size:0.9rem; font-weight:700; color:var(--mhbo-accent);"><span style="font-size:0.65rem; color:var(--mhbo-text-light); text-transform:uppercase;">FROM</span> <?php echo esc_html($ci_time); ?></div>
                        </div>
                        <div style="flex:1.2;">
                            <div style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:var(--mhbo-text-light); letter-spacing:0.5px;">CHECK-OUT</div>
                            <div style="font-size:1.05rem; font-weight:800; color:var(--mhbo-primary); margin:4px 0;"><?php echo esc_html($co_date ? $co_date->format('M j, Y') : $check_out); ?></div>
                            <div style="font-size:0.9rem; font-weight:700; color:var(--mhbo-accent);"><span style="font-size:0.65rem; color:var(--mhbo-text-light); text-transform:uppercase;">BY</span> <?php echo esc_html($co_time); ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:var(--mhbo-text-light); letter-spacing:0.5px;">NIGHTS</div>
                            <div style="font-size:1.2rem; font-weight:900; color:var(--mhbo-primary); margin:4px 0 0; line-height:1;"><?php echo esc_html((string)(int)$nights); ?></div>
                            <div style="font-size:0.9rem; font-weight:800; color:var(--mhbo-primary);">Nights</div>
                        </div>
                    </div>

                    <div class="mhbo-summary-grid">
                        <div class="mhbo-summary-header">
                            <span><?php echo esc_html(_x('Item', 'cart item', 'modern-hotel-booking')); ?></span>
                            <span><?php echo esc_html(__('Amount', 'modern-hotel-booking')); ?></span>
                        </div>
                        
                        <div class="mhbo-summary-row">
                            <span><?php echo esc_html(_x('Room', 'accommodation unit', 'modern-hotel-booking')); ?></span>
                            <span><?php echo esc_html(I18n::format_currency($total)); ?></span>
                        </div>
                        
                        <?php
                        $children_total_init = $calc ? $calc['children_total'] : Money::fromCents(0, Pricing::get_currency_code());
                        ?>
                        <div class="mhbo-summary-row mhbo-children-cost-row" style="<?php echo esc_attr($children_total_init->isPositive() ? '' : 'display:none;'); ?>">
                            <span><?php echo esc_html(I18n::get_label('label_children')); ?></span>
                            <span class="mhbo-children-total-display"><?php echo esc_html($children_total_init->isPositive() ? $children_total_init->format() : ''); ?></span>
                        </div>
                        
                        <div class="mhbo-summary-divider"></div>
                        
                        <div class="mhbo-summary-total">
                            <span><?php echo esc_html(I18n::get_label('label_total')); ?></span>
                            <span class="mhbo-display-total"
                                data-base-total="<?php echo esc_attr((string)$total->toDecimal()); ?>"
                                data-currency-symbol="<?php echo esc_attr(get_option('mhbo_currency_symbol', '$')); ?>"
                                data-currency-pos="<?php echo esc_attr(get_option('mhbo_currency_position', 'before')); ?>">
                                <?php echo esc_html(I18n::format_currency($total)); ?>
                            </span>
                        </div>
                    </div>
                </div>
            <h2 class="mhbo-form-heading"><?php echo esc_html(I18n::get_label('label_complete_booking')); ?></h2>
            <form method="post" class="mhbo-booking-form" id="mhbo-booking-form">
                <?php wp_nonce_field('mhbo_confirm_action', 'mhbo_confirm_nonce'); ?>
                <?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is rigorously verified in the process_payment flow.
                    $url_token = isset($_GET['booking_token']) ? sanitize_text_field(wp_unslash($_GET['booking_token'])) : '';
                    $final_token = $url_token ?: ($booking->booking_token ?? '');
                ?>
                <input type="hidden" name="mhbo_booking_token" value="<?php echo esc_attr($final_token); ?>">
                <!-- Hidden field so JS form.submit() includes the booking action -->
                <input type="hidden" name="mhbo_confirm_booking" value="1">
                <input type="hidden" name="mhbo_room_id" value="<?php echo esc_attr((string) $room_id); ?>">
                <input type="hidden" name="mhbo_exclude_id" value="<?php echo esc_attr((string) $exclude_id); ?>">
                <input type="hidden" name="mhbo_update_id" value="<?php echo esc_attr((string) $update_id); ?>">
                <input type="hidden" name="mhbo_type_id" value="<?php echo esc_attr((string) ($room_type_id ?? 0)); ?>">
                <input type="hidden" name="check_in" value="<?php echo esc_attr($check_in); ?>">
                <input type="hidden" name="check_out" value="<?php echo esc_attr($check_out); ?>">
                <input type="hidden" name="total_price" value="<?php echo esc_attr($total->toDecimal()); ?>">
                <input type="hidden" name="booking_language" value="<?php echo esc_attr(I18n::get_current_language()); ?>">
                <input type="hidden" name="mhbo_page_url" value="<?php echo esc_attr( get_permalink() ?: ( isset( $_SERVER['REQUEST_URI'] ) ? home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : home_url( '/' ) ) ); ?>">

                <div class="mhbo-form-group"><label><?php echo esc_html(I18n::get_label('label_name')); ?> <span
                            class="required">*</span></label><input type="text" name="customer_name" value="<?php echo esc_attr($customer_name); ?>" required></div>
                <div class="mhbo-form-group"><label><?php echo esc_html(I18n::get_label('label_email')); ?> <span
                            class="required">*</span></label><input type="email" name="customer_email" value="<?php echo esc_attr($customer_email); ?>" required></div>
                <div class="mhbo-form-group">
                    <label><?php echo esc_html(I18n::get_label('label_guests')); ?> <span class="required">*</span></label>
                    <select name="guests" class="mhbo-booking-guests" required>
                        <?php
                        // Determine max guests (capacity)
                        $max_capacity = isset($room->max_adults) ? intval($room->max_adults) : 2;
                        if ($max_capacity < 1)
                            $max_capacity = 2;

                        // Use pre-validated $guests parameter
                        $selected_guests = $guests;
                        if ($selected_guests > $max_capacity)
                            $selected_guests = $max_capacity;

                        for ($i = 1; $i <= $max_capacity; $i++) {
                            echo '<option value="' . esc_attr((string) $i) . '" ' . selected($selected_guests, $i, false) . '>' . esc_html((string) $i) . '</option>';
                        }
                        ?>
                    </select>
                </div>

<div class="mhbo-form-group">
                    <label><?php echo esc_html(I18n::get_label('label_phone')); ?> <span class="required">*</span></label><input
                        type="tel" name="customer_phone" value="<?php echo esc_attr($customer_phone); ?>" required>
                </div>
                <div class="mhbo-form-group">
                    <label><?php echo esc_html(I18n::get_label('label_special_requests')); ?></label>
                    <textarea name="admin_notes" rows="3" style="width:100%"><?php echo esc_textarea($admin_notes); ?></textarea>
                </div>

                <?php
                // Note: Honeypot removed for compliance. Security is handled via nonces.
                ?>

<?php
                // Render Custom Fields
                $custom_fields = get_option('mhbo_custom_fields', []);
                if ([] !== $custom_fields) {
                    foreach ($custom_fields as $field) {
                        $label = isset($field['label']) ? I18n::decode(I18n::encode($field['label'])) : $field['id'];
                        $required = (isset($field['required']) && (bool)$field['required']) ? 'required' : '';
                        $required_mark = $required ? ' <span class="required">*</span>' : '';

                        echo '<div class="mhbo-form-group mhbo-custom-field-group">';
                        echo '<label>' . esc_html($label) . wp_kses_post($required_mark) . '</label>';

                        if ($field['type'] === 'textarea') {
                            echo '<textarea name="mhbo_custom[' . esc_attr($field['id']) . ']" rows="3" style="width:100%" ' . esc_attr($required) . '></textarea>';
                        } else {
                            $input_type = ($field['type'] === 'number') ? 'number' : 'text';
                            echo '<input type="' . esc_attr($input_type) . '" name="mhbo_custom[' . esc_attr($field['id']) . ']" ' . esc_attr($required) . '>';
                        }
                        echo '</div>';
                    }
                }
                ?>

<?php do_action('mhbo_booking_form_after_inputs', $total, $calc); ?>

                <!-- Inline error notification area for payment/booking errors -->
                <div class="mhbo-booking-errors mhbo-inline-errors" style="display:none;"></div>

                <div class="mhbo-tax-breakdown-container" style="margin: 20px 0; padding: 15px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <?php
                    // Display the dynamic pricing and tax breakdown from the server calculation
                    $show_breakdown = !Tax::is_enabled() || get_option('mhbo_tax_display_frontend', 1);
                    if ($show_breakdown) {
                        $currency = $total->getCurrency();
                        $tax_data = isset($calc['tax']) ? $calc['tax'] : [
                            'enabled' => false,
                            'totals' => [
                                'subtotal_net' => isset($calc['total']) ? $calc['total'] : Money::fromCents(0, $currency),
                                'total_tax' => Money::fromCents(0, $currency),
                                'total_gross' => isset($calc['total']) ? $calc['total'] : Money::fromCents(0, $currency)
                            ]
                        ];

echo wp_kses_post(Tax::render_breakdown_html($tax_data, null, false, array(), false));
                    }
                    ?>
                </div>
                <?php
                // VAT notes removed from booking page per user request.
                ?>

<div class="mhbo-submit-container">
                    <button type="submit" name="mhbo_confirm_booking" class="mhbo-btn mhbo-submit-btn">
                        <?php echo esc_html(I18n::get_label('btn_confirm_booking')); ?>
                    </button>
                    <div class="mhbo-secure-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <span><?php echo esc_html(I18n::get_label('label_secure_payment')); ?></span>
                    </div>
                </div>

                <?php
                // Note: Booking form JavaScript logic has been moved to assets/js/mhbo-booking-form.js
                // The mhbo_vars configuration is injected via wp_add_inline_script() in enqueue_assets()
                ?>

            </form>
        </div>
        <?php
    }

    /**
     * Handle the server-side booking creation process.
     *
     * @return void
     */
    private function process_booking(): void
    {

// SECURITY: Rate limiting for booking submissions (5 per minute per IP)
        $ip = Security::get_client_ip();
        $rate_key = 'mhbo_booking_rate_' . md5((string)$ip);
        $count = get_transient($rate_key);
        if (false !== $count && $count >= 5) {
            $this->booking_fail('<div class="mhbo-error">' . esc_html(I18n::get_label('label_rate_limit_error')) . '</div>');
            return;
        }
        set_transient($rate_key, (int) $count + 1, 60);

        // Map POST data to BookingProcessor format.
        // Nonce is verified by the calling methods (handle_booking_form_submission / handle_ajax_booking).
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
        $data = [
            'room_id'           => isset( $_POST['mhbo_room_id'] ) ? absint( wp_unslash( $_POST['mhbo_room_id'] ) ) : ( isset( $_POST['room_id'] ) ? absint( wp_unslash( $_POST['room_id'] ) ) : 0 ),
            'type_id'           => isset( $_POST['mhbo_type_id'] ) ? absint( wp_unslash( $_POST['mhbo_type_id'] ) ) : 0,
            'check_in'          => isset( $_POST['check_in'] ) ? sanitize_text_field( wp_unslash( $_POST['check_in'] ) ) : '',
            'check_out'         => isset( $_POST['check_out'] ) ? sanitize_text_field( wp_unslash( $_POST['check_out'] ) ) : '',
            'customer_name'     => isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '',
            'customer_email'    => isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '',
            'customer_phone'    => isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '',
            'guests'            => isset( $_POST['guests'] ) ? absint( wp_unslash( $_POST['guests'] ) ) : 1,
            'children'          => isset( $_POST['children'] ) ? absint( wp_unslash( $_POST['children'] ) ) : 0,
            'child_ages'        => isset( $_POST['child_ages'] ) && is_array( $_POST['child_ages'] ) ? array_map( 'absint', wp_unslash( $_POST['child_ages'] ) ) : [],
            'extras'            => isset( $_POST['mhbo_extras'] ) && is_array( $_POST['mhbo_extras'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_extras'] ) ) : [],
            'payment_method'    => isset( $_POST['mhbo_payment_method'] ) ? sanitize_key( wp_unslash( $_POST['mhbo_payment_method'] ) ) : 'arrival',
            'payment_type'      => isset( $_POST['mhbo_payment_type'] ) ? sanitize_key( wp_unslash( $_POST['mhbo_payment_type'] ) ) : 'full',
            'stripe_pi'         => isset( $_POST['mhbo_stripe_payment_intent'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_stripe_payment_intent'] ) ) : ( isset( $_GET['payment_intent'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_intent'] ) ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            'paypal_order_id'   => isset( $_POST['mhbo_paypal_order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_paypal_order_id'] ) ) : '',
            'paypal_capture_id' => isset( $_POST['mhbo_paypal_capture_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_paypal_capture_id'] ) ) : '',
            'custom_fields'     => isset( $_POST['mhbo_custom'] ) && is_array( $_POST['mhbo_custom'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_custom'] ) ) : [],
            'admin_notes'       => isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '',
            'update_id'         => isset( $_POST['mhbo_update_id'] ) ? absint( wp_unslash( $_POST['mhbo_update_id'] ) ) : 0,
            'page_url'          => $this->get_booking_page_url(),
            'language'          => isset( $_POST['booking_language'] ) ? sanitize_key( wp_unslash( $_POST['booking_language'] ) ) : I18n::get_current_language(),
            'consent'           => isset( $_POST['mhbo_consent'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['mhbo_consent'] ) ) : false,
            
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

        // Delegate to the centralized BookingProcessor
        $result = \MHBO\Core\BookingProcessor::process($data);

        if (is_wp_error($result)) {
            $this->booking_fail('<div class="mhbo-error">' . $result->get_error_message() . '</div>');
            return;
        }

        // Handle success
        $this->booking_done($result['redirect_url']);
    }

    /**
     * Inject theme styles based on Pro settings.
     * Note: Theme styles are applied to all users to ensure CSS variables are defined.
     *
     * @return void
     */
    public static function inject_theme_styles(): void
    {
        $active_theme = get_option('mhbo_active_theme', 'midnight');
        $primary = '';
        $secondary = '';
        $accent = '';

        $presets = [
            'midnight' => ['#1a365d', '#f2e2c4', '#d4af37'],
            'emerald' => ['#065f46', '#34d399', '#10b981'],
            'oceanic' => ['#1e3a8a', '#60a5fa', '#3b82f6'],
            'ruby' => ['#7f1d1d', '#f87171', '#ef4444'],
            'urban' => ['#1f2937', '#9ca3af', '#4b5563'],
            'lavender' => ['#4c1d95', '#a78bfa', '#8b5cf6'],
        ];

if (isset($presets[$active_theme])) {
                $primary = $presets[$active_theme][0];
                $secondary = $presets[$active_theme][1];
                $accent = $presets[$active_theme][2];
            }

        if ('' !== $primary) {
            // SECURITY: Validate hex colors before CSS interpolation
            $primary = sanitize_hex_color($primary) ?: '#1a365d';
            $secondary = sanitize_hex_color($secondary) ?: '#f2e2c4';
            $accent = sanitize_hex_color($accent) ?: '#d4af37';
            
            // SECURITY: Using printf for clean CSS variable construction with pre-sanitized values
            $custom_css = sprintf(
                ':root, .mhbo-calendar-wrapper, .mhbo-booking-form-wrapper, .mhbo-deposit-options-wrapper, .mhbo-success-message {
                    --mhbo-primary: %s !important;
                    --mhbo-secondary: %s !important;
                    --mhbo-accent: %s !important;
                    --mhbo-border: color-mix(in srgb, %s, transparent 85%%) !important;
                    --mhbo-glass: color-mix(in srgb, %s, white 90%%) !important;
                }',
                $primary,
                $secondary,
                $accent,
                $primary,
                $primary
            );
            $handles = ['mhbo-style', 'mhbo-calendar-style', 'mhbo-deposit-checkout'];
            foreach ($handles as $handle) {
                wp_add_inline_style($handle, $custom_css);
            }
        }

wp_add_inline_style('mhbo-frontend', '
            .mhbo-child-age-group { 
                display: flex; 
                align-items: center; 
                justify-content: space-between; 
                background: rgba(0,0,0,0.03); 
                padding: 8px 12px; 
                border-radius: 6px; 
                margin-bottom: 8px !important;
            }
            .mhbo-child-age-group label { margin: 0 !important; font-weight: normal !important; }
            .mhbo_faded { opacity: 0.5; transition: opacity 0.3s; pointer-events: none; }
        ');
    }

    /**
     * Get the booking page URL (Instance wrapper for static helper).
     *
     * @since 2.4.1
     * @return string
     */
    public function get_booking_page_url(): string
    {
        return self::get_checkout_url();
    }

    /**
     * Remove MHBO specific query arguments from a URL to prevent redirect loops.
     *
     * @since 2.4.1
     * @param string $url The URL to clean.
     * @return string The cleaned URL.
     */
    public function remove_mhbo_query_args(string $url): string
    {
        return remove_query_arg([
            'mhbo_auto_book',
            'mhbo_nonce',
            'mhbo_confirm_booking',
            'mhbo_confirm_nonce',
            'mhbo_payment_return',
            'payment_intent',
            'payment_intent_client_secret',
            'mhbo_booking',
            'token',
            'mhbo_success',
            'mhbo_success_nonce',
            'mhbo_status',
            'reference',
            'mhbo_error'
        ], $url);
    }

    /**
     * Validate a date string (Y-m-d).
     *
     * @since 2.4.1
     * @param mixed $value Date string to validate.
     * @return bool True if valid Y-m-d, false otherwise.
     */
    public function validate_date($value): bool
    {
        if (!is_string($value) || '' === $value) {
            return false;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }

    /**
     * Get the URL of the designated booking page or fallback to current.
     * 
     * @since 2.4.1 Public Static for AI engine access.
     * @return string The resolved booking page URL.
     */
    public static function get_checkout_url(): string
    {
        $booking_page_id = (int) get_option('mhbo_booking_page');
        $booking_page_url = get_option('mhbo_booking_page_url');
        
        // Correct truthy check: get_option returns false on failure, string/null otherwise.
        if (is_string($booking_page_url) && '' !== $booking_page_url) {
            return esc_url_raw($booking_page_url);
        }

        if ($booking_page_id > 0) {
            $permalink = get_permalink($booking_page_id);
            if (false !== $permalink) {
                return esc_url_raw($permalink);
            }
        }

        // Fallback to home if no specific booking page is configured
        return esc_url_raw(home_url('/'));
    }
}
