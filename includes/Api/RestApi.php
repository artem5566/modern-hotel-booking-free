<?php declare(strict_types=1);

/**
 * REST API endpoints for Modern Hotel Booking.
 *
 * Namespace: mhbo/v1
 * Endpoints:
 *   GET  /rooms         — list room types
 *   GET  /availability  — check availability for date range
 *   POST /bookings      — create a booking (API key required)
 *   GET  /bookings/{id} — get booking details (API key required)
 *
 * @package MHBO\Api
 * @since   2.0.1
 */

namespace MHBO\Api;
if (!defined('ABSPATH')) {
    exit;
}


use MHBO\Core\Cache;
use MHBO\Core\I18n;
use MHBO\Core\Pricing;
use MHBO\Core\Capabilities;
use MHBO\Core\License;
use MHBO\Core\Security;
use MHBO\Core\Money;
use MHBO\Core\Tax;

/**
 * REST API endpoints for Modern Hotel Booking.
 *
 * @package MHBO\Api
 * @since   2.0.1
 */
class RestApi
{
    /**
     * Register REST routes.
     */
    public function register_routes(): void
    {
        $namespace = 'mhbo/v1';

        register_rest_route($namespace, '/rooms', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_rooms'),
            'permission_callback' => function ($request) {
                /* BUILD_PRO_START */
                // Combine Pro check and Rate limiting
                $pro = $this->check_pro_access();
                if (is_wp_error($pro))
                    return $pro;
                /* BUILD_PRO_END */
                return $this->check_read_access($request);
            },
        ));

        register_rest_route($namespace, '/availability', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_availability'),
            'permission_callback' => function ($request) {
                return $this->check_read_access($request);
            },
            'args' => array(
                'check_in' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'check_out' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        /* BUILD_PRO_START */
        register_rest_route($namespace, '/bookings', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_booking'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => array(
                'room_id' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && intval($value) > 0;
                    },
                    'sanitize_callback' => 'absint',
                ),
                'check_in' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'check_out' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'customer_name' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_string($value) && mb_strlen(trim($value)) > 0 && mb_strlen($value) <= 100;
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'customer_email' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_email($value);
                    },
                    'sanitize_callback' => 'sanitize_email',
                ),
                'customer_phone' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return '' === $value || ( is_string($value) && mb_strlen($value) <= 30 );
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'language' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_key',
                ),
                'payment_type' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => 'full'
                ),
            ),
        ));
        /* BUILD_PRO_END */

        register_rest_route($namespace, '/bookings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_bookings'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => array(
                'status' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return '' === $value || in_array($value, ['pending', 'confirmed', 'cancelled', 'completed', 'deposit_paid', 'refunded'], true);
                    },
                    'sanitize_callback' => 'sanitize_key',
                ),
                'per_page' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value >= 1 && (int) $value <= 100;
                    },
                    'sanitize_callback' => 'absint',
                    'default' => 20,
                ),
                'page' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value >= 1;
                    },
                    'sanitize_callback' => 'absint',
                    'default' => 1,
                ),
            ),
        ));

        register_rest_route($namespace, '/bookings/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_booking'),
            'permission_callback' => function ($request) {
                // Allow administrators OR valid API Key holders
                if (Capabilities::current_user_can(Capabilities::MANAGE_LHBO)) {
                    return true;
                }
                return $this->check_api_key($request);
            },
        ));

        register_rest_route($namespace, '/bookings/(?P<reference>[a-zA-Z0-9]{24,64})', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_booking'),
            'permission_callback' => '__return_true', // Authorization handled inside get_booking via verify_booking_access
        ));

        register_rest_route($namespace, '/calendar-data', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_calendar_data'),
            'permission_callback' => array($this, 'check_read_access'),
            'args' => array(
                'room_id' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ),
                'year' => array(
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ),
                'month' => array(
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        register_rest_route($namespace, '/recalculate-price', array(
            'methods' => 'POST',
            'callback' => array($this, 'recalculate_price'),
            'permission_callback' => array($this, 'check_read_access'),
            'args' => array(
                'room_id' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && intval($value) > 0;
                    },
                    'sanitize_callback' => 'absint'
                ),
                'check_in' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'check_out' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'guests' => array(
                    'required' => false,
                    'sanitize_callback' => 'absint',
                    'default' => 1
                ),
                'children' => array(
                    'required' => false,
                    'sanitize_callback' => 'absint',
                    'default' => 0
                ),
                'child_ages' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return is_array($value);
                    },
                    'sanitize_callback' => function ($value) {
                        return is_array($value) ? array_map('absint', $value) : array();
                    },
                    'default' => array()
                ),
                'extras' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return is_array($value);
                    },
                    'sanitize_callback' => function ($value) {
                        if (!is_array($value))
                            return array();
                        $sanitized = array();
                        foreach ($value as $k => $v) {
                            $sanitized[sanitize_key($k)] = sanitize_text_field($v);
                        }
                        return $sanitized;
                    },
                    'default' => array()
                ),
                'coupon_code' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => ''
                ),
            ),
        ));

        /* BUILD_PRO_START */
        // Payment webhook endpoint for Stripe/PayPal webhooks
        // SECURITY: Permission callback verifies webhook signature internally
        register_rest_route($namespace, '/payment-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_payment_webhook'),
            'permission_callback' => array($this, 'verify_webhook_permission'),
        ));
        /* BUILD_PRO_END */

        /* BUILD_PRO_START */
        // Tax settings endpoint for frontend
        register_rest_route($namespace, '/tax-settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_tax_settings'),
            'permission_callback' => array($this, 'check_read_access'),
        ));
        /* BUILD_PRO_END */

        // 2026 BP: Modernized public booking completion endpoint.
        // Replaces legacy admin-ajax.php logic to ensure output isolation and performance.
        register_rest_route($namespace, '/booking/complete', array(
            'methods'  => 'POST',
            'callback' => array($this, 'handle_public_booking_submission'),
            'permission_callback' => function ($request) {
                return $this->check_read_access($request);
            },
            'args' => array(
                'room_id' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && intval($value) > 0;
                    },
                    'sanitize_callback' => 'absint',
                ),
                'check_in' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'check_out' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'customer_name' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_string($value) && mb_strlen(trim($value)) > 0 && mb_strlen($value) <= 100;
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'customer_email' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_email($value);
                    },
                    'sanitize_callback' => 'sanitize_email',
                ),
                'customer_phone' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_string($value) && mb_strlen($value) <= 30;
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'guests' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value >= 1;
                    },
                    'sanitize_callback' => 'absint',
                    'default' => 1,
                ),
                'payment_method' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return in_array($value, ['arrival', 'stripe', 'paypal'], true);
                    },
                    'sanitize_callback' => 'sanitize_key',
                    'default' => 'arrival',
                ),
            ),
        ));

    }

    /**
     * Validate a date string (Y-m-d).
     *
     * @param string $value Date string.
     * @return bool
     */
    public function validate_date($value)
    {
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }

    /**
     * General check if REST API is allowed (Pro only).
     *
     * @return bool|\WP_Error
     */
    public function check_pro_access()
    {
        /* BUILD_PRO_START */
        if (!License::is_active()) {
            return new \WP_Error(
                'rest_pro_required',
                I18n::get_label('msg_pro_required'),
                array('status' => 403)
            );
        }
        return true;
        /* BUILD_PRO_END */
    }

    /**
     * Check for read access - Verify nonce (CSRF protection) and rate limit.
     * Use for non-authenticated public endpoints that need protection.
     *
     * @param \WP_REST_Request|null $request Request object.
     * @return bool|\WP_Error
     */
    public function check_read_access($request = null)
    {
        // Require nonce for protection against CSRF
        if ($request !== null) {
            $nonce = $request->get_header('X-WP-Nonce');
            if ('' === (string) ($nonce ?? '') || !wp_verify_nonce((string) $nonce, 'wp_rest')) {
                return new \WP_Error(
                    'mhbo_unauthorized',
                    esc_html(I18n::get_label('label_invalid_nonce')),
                    array('status' => 403)
                );
            }
        }

        // Apply rate limiting for public endpoints
        $rate_limit = $this->check_rate_limit();
        if (is_wp_error($rate_limit)) {
            return $rate_limit;
        }

        return true;
    }

    /**
     * Check rate limit for API requests.
     * Limits to 60 requests per minute per IP address.
     *
     * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
     */
    private function check_rate_limit()
    {
        $ip = Security::get_client_ip();
        
        // 2026 BP: Rule 11 - Individual extraction/sanitization from superglobals.
        // Rule 11: Individually extract and sanitize to avoid contamination.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized/unslashed on next line
        $raw_api_key = isset($_SERVER['HTTP_X_MHBO_API_KEY']) ? wp_unslash($_SERVER['HTTP_X_MHBO_API_KEY']) : '';
        $api_key = sanitize_text_field((string) $raw_api_key);
        
        // Use API Key for bucket if available, otherwise fallback to IP
        $identifier = ($api_key !== '' && $api_key !== null) ? 'key_' . md5($api_key) : 'ip_' . md5($ip);
        
        $transient_key = 'mhbo_api_rate_' . $identifier;
        $request_count = get_transient($transient_key);
        
        // Higher limit for API Key holders
        $default_limit = ($api_key !== '' && $api_key !== null) ? 300 : 60;
        $limit = apply_filters('mhbo_api_rate_limit', $default_limit, $api_key); 
        $window = apply_filters('mhbo_api_rate_window', 60); 

        if (false === $request_count) {
            set_transient($transient_key, 1, $window);
            return true;
        }

        if ($request_count >= $limit) {
            return new \WP_Error(
                'mhbo_rate_limit_exceeded',
                esc_html(I18n::get_label('label_api_rate_limit')),
                array('status' => 429)
            );
        }

        set_transient($transient_key, (int) $request_count + 1, $window);
        return true;
    }

    /* BUILD_PRO_START */
    /**
     * Check API key for sensitive endpoints.
     *
     * @param \WP_REST_Request $request Request object.
     * @return bool|\WP_Error True if valid, WP_Error otherwise.
     */
    public function check_api_key($request)
    {
        if (!License::is_pro_active()) {
            return new \WP_Error(
                'rest_pro_required',
                I18n::get_label('msg_pro_required'),
                array('status' => 403)
            );
        }

        // Header-only: query-param api_key is rejected to prevent server-log leakage.
        $api_key = (string) ($request->get_header('X-MHBO-API-KEY') ?: '');

        if ('' === $api_key) {
            return new \WP_Error(
                'rest_unauthorized',
                I18n::get_label('msg_missing_api_key'),
                array('status' => 401)
            );
        }

        // Apply Rate Limiting
        $rate_limit = $this->check_rate_limit();
        if (is_wp_error($rate_limit)) {
            return $rate_limit;
        }

        $stored_key = get_option('mhbo_api_key', '');
        
        // Decrypt the stored key for comparison
        // Note: Using the specific salt for API keys
        $decrypted_key = Security::decrypt_secret((string) $stored_key, 'mhbo_api_key_salt');

        if ('' === (string) ($decrypted_key ?? '') || !hash_equals((string) $decrypted_key, (string) $api_key)) {
            return new \WP_Error(
                'rest_forbidden',
                I18n::get_label('msg_invalid_api_key'),
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Get a list of bookings (Pro only).
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_bookings($request): \WP_REST_Response
    {
        global $wpdb;
        
        // Rule 11: Extract and sanitize all inputs at start
        $per_page = absint($request->get_param('per_page') ?: 20);
        $page     = absint($request->get_param('page') ?: 1);
        $status_raw = sanitize_key($request->get_param('status') ?: '');
        $allowed_statuses = ['pending', 'confirmed', 'cancelled', 'completed', 'deposit_paid', 'refunded'];
        $status = in_array($status_raw, $allowed_statuses, true) ? $status_raw : '';
        
        $offset   = ($page - 1) * $per_page;
        
        // 2026 BP: Branched literals are the only 100% compliant way in strict PCP environments.
        // We avoid all intermediate variables or dynamic string building for the query template.
        
        if ( $status !== '' && $status !== null ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $bookings = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d", sanitize_text_field($status), $per_page, $offset)
            );
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings WHERE status = %s", sanitize_text_field($status))
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $bookings = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE 1=1 ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset)
            );
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings WHERE 1=1");
        }
        
        $response_data = [];
        foreach ($bookings as $booking) {
            $response_data[] = $this->prepare_booking_for_response($booking, $request);
        }
        
        $response = new \WP_REST_Response($response_data, 200);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) ceil($total / $per_page));
        
        return $response;
    }
    /* BUILD_PRO_END */

    /**
     * Verify webhook permission - check for valid webhook signature.
     * SECURITY: This prevents unauthorized webhook submissions.
     *
     * @param \WP_REST_Request $request Request object.
     * @return bool|\WP_Error
     */
    public function verify_webhook_permission($request)
    {
        $headers = $request->get_headers();
        $payload = $request->get_body();

        /* BUILD_PRO_START */
        // Check for Stripe signature
        $stripe_signature = isset($headers['stripe_signature']) ? $headers['stripe_signature'][0] : null;
        if ($stripe_signature) {
            return $this->verify_stripe_signature($payload, $stripe_signature);
        }

        // Check for PayPal authentication headers
        $paypal_auth = isset($headers['paypal_auth_algo']) ? $headers['paypal_auth_algo'][0] : null;
        if ($paypal_auth) {
            return $this->verify_paypal_signature($request);
        }
        /* BUILD_PRO_END */

        // SECURITY: Reject webhooks without proper signatures
        return new \WP_Error(
            'mhbo_webhook_unauthorized',
            esc_html(I18n::get_label('label_webhook_sig_required')),
            array('status' => 401)
        );
    }

    /* BUILD_PRO_START */
    /**
     * Verify Stripe webhook signature.
     * SECURITY: Implements proper HMAC signature verification.
     *
     * @param string $payload Raw request body.
     * @param string $signature Stripe signature header.
     * @return bool|\WP_Error
     */
    private function verify_stripe_signature($payload, $signature)
    {
        $mode = get_option('mhbo_stripe_mode', 'test');
        $webhook_secret = get_option("mhbo_stripe_{$mode}_webhook_secret", '');

        if ('' === (string) ($webhook_secret ?? '')) {
            // SECURITY: Reject if no webhook secret configured
            return new \WP_Error(
                'mhbo_webhook_not_configured',
                esc_html(I18n::get_label('label_stripe_webhook_secret_missing')),
                array('status' => 500)
            );
        }

        // Parse Stripe signature header
        // Format: t=1234567890,v1=abc123def456...
        $sig_elements = [];
        foreach (explode(',', $signature) as $item) {
            $parts = explode('=', $item, 2);
            if (count($parts) === 2) {
                $sig_elements[$parts[0]] = $parts[1];
            }
        }

        if (!isset($sig_elements['t']) || !isset($sig_elements['v1'])) {
            return new \WP_Error(
                'mhbo_invalid_signature_format',
                esc_html(I18n::get_label('label_invalid_stripe_sig_format')),
                array('status' => 400)
            );
        }

        $timestamp = $sig_elements['t'];
        $expected_signature = $sig_elements['v1'];

        // SECURITY: Verify timestamp to prevent replay attacks (5 minute tolerance)
        $current_time = time();
        $tolerance = 300; // 5 minutes
        if (abs($current_time - (int) $timestamp) > $tolerance) {
            return new \WP_Error(
                'mhbo_webhook_expired',
                esc_html(I18n::get_label('label_webhook_expired')),
                array('status' => 400)
            );
        }

        // Compute expected signature
        $signed_payload = $timestamp . '.' . $payload;
        $computed_signature = hash_hmac('sha256', $signed_payload, $webhook_secret);

        // SECURITY: Use hash_equals to prevent timing attacks
        if (!hash_equals($expected_signature, $computed_signature)) {
            return new \WP_Error(
                'mhbo_invalid_signature',
                esc_html(I18n::get_label('label_invalid_stripe_sig')),
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Verify PayPal webhook signature.
     * SECURITY: Validates PayPal webhook authentication headers.
     *
     * @param \WP_REST_Request $request Request object.
     * @return bool|\WP_Error
     */
    private function verify_paypal_signature($request)
    {
        $mode = get_option('mhbo_paypal_mode', 'sandbox');
        $client_id = get_option("mhbo_paypal_{$mode}_client_id", '');
        $gateway = new \MHBO\Pro\PaymentGateways();
        $client_secret = $gateway->get_decrypted_secret(get_option("mhbo_paypal_{$mode}_secret", ''));

        if ('' === (string) ($client_id ?? '') || '' === (string) ($client_secret ?? '')) {
            return new \WP_Error(
                'mhbo_paypal_not_configured',
                esc_html(I18n::get_label('label_paypal_not_configured')),
                array('status' => 500)
            );
        }

        $headers = $request->get_headers();
        $payload = $request->get_body();
        $api_base = ('live' === $mode) ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        // 1. Get Access Token (Cached)
        $access_token = \MHBO\Pro\PaymentGateways::get_paypal_access_token($mode, $client_id, $client_secret);


        if ('' === (string) ($access_token ?? '')) {
            return false;
        }


        // 2. Call PayPal to verify signature
        $webhook_id = get_option("mhbo_paypal_{$mode}_webhook_id", '');
        if ('' === (string) ($webhook_id ?? '')) {
            return new \WP_Error(
                'mhbo_paypal_webhook_id_missing',
                I18n::get_label('api_err_paypal_webhook_id_missing'),
                array('status' => 500)
            );
        }

        $verification_response = wp_safe_remote_post($api_base . '/v1/notifications/verify-webhook-signature', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'auth_algo' => $headers['paypal_auth_algo'][0] ?? '',
                'cert_url' => $headers['paypal_cert_url'][0] ?? '',
                'transmission_id' => $headers['paypal_transmission_id'][0] ?? '',
                'transmission_sig' => $headers['paypal_transmission_sig'][0] ?? '',
                'transmission_time' => $headers['paypal_transmission_time'][0] ?? '',
                'webhook_id' => $webhook_id,
                'webhook_event' => json_decode($payload, true),
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($verification_response)) {
            return false;
        }

        $verification_body = json_decode(wp_remote_retrieve_body($verification_response), true);

        return (isset($verification_body['verification_status']) && $verification_body['verification_status'] === 'SUCCESS');
    }
    /* BUILD_PRO_END */

    /**
     * GET /rooms — List all room types.
     *
     * @return \WP_REST_Response
     */
    public function get_rooms()
    {
        global $wpdb;
        $cache_key = 'all_types';
        $room_types = Cache::get_query($cache_key, Cache::TABLE_ROOM_TYPES);

        if (false === $room_types) {
            // RATIONALE: Required to list room types for public rooms REST endpoint.
            // Read-only; result is cached via Cache::set_query with versioned salt.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented via Cache class
            $room_types = $wpdb->get_results(
                "SELECT id, name, description, base_price, max_adults, max_children, total_rooms, amenities, image_url
                 FROM {$wpdb->prefix}mhbo_room_types ORDER BY id ASC"
            );
            Cache::set_query($cache_key, $room_types, Cache::TABLE_ROOM_TYPES, HOUR_IN_SECONDS);
        }

        $data = array();
        foreach ($room_types as $type) {
            $data[] = array(
                'id' => (int) $type->id,
                'name' => I18n::decode($type->name),
                'description' => I18n::decode($type->description),
                'base_price' => (float) $type->base_price,
                'max_adults' => (int) $type->max_adults,
                'max_children' => (int) $type->max_children,
                'total_rooms' => (int) $type->total_rooms,
                'amenities' => $type->amenities ? json_decode($type->amenities, true) : array(),
                'image_url' => esc_url($type->image_url),
            );
        }

        return rest_ensure_response($data);
    }

    /**
     * GET /availability — Check room availability.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_availability($request)
    {
        global $wpdb;

        // Rule 11: Extract and sanitize all inputs at start
        $check_in  = sanitize_text_field($request->get_param('check_in'));
        $check_out = sanitize_text_field($request->get_param('check_out'));


        if ($check_in >= $check_out) {
            return new \WP_Error(
                'mhbo_invalid_dates',
                esc_html(I18n::get_label('label_check_out_after')),
                array('status' => 400)
            );
        }

        // Get all rooms - cache this as room configuration rarely changes
        $rooms_cache_key = 'rooms_with_types';
        $rooms = Cache::get_query($rooms_cache_key, Cache::TABLE_ROOMS);

        if (false === $rooms) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- RATIONALE: Room Type lookup uses a custom table. Result is cached above using a unique key to prevent redundant schema-level JOINS under high REST traffic.
            $rooms = $wpdb->get_results(
                "SELECT r.id AS room_id, r.room_number, r.status, r.custom_price,
                        t.id AS type_id, t.name AS type_name, t.base_price, t.max_adults, t.max_children
                 FROM {$wpdb->prefix}mhbo_rooms r
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                 ORDER BY t.id, r.id"
            );
            Cache::set_query($rooms_cache_key, $rooms, Cache::TABLE_ROOMS, HOUR_IN_SECONDS);
        }

        // 2026 BP: Bulk pre-fetch all room metadata and policies in a single query 
        // to eliminate the N+1 problem during availability calculations.
        $room_ids = array_map(function($r) { return (int) $r->room_id; }, $rooms);
        Pricing::prime_room_cache($room_ids);

        $available = array();

        foreach ($rooms as $room) {
            // Use the centralized availability check (Consolidated SQL)
            $availability_status = Pricing::is_room_available((int) $room->room_id, $check_in, $check_out);

            if (true === $availability_status) {
                // Calculate total price for the stay using Money precision
                $currency = Pricing::get_currency_code();
                $total_money = Money::fromCents(0, $currency);
                $period = new \DatePeriod(
                    new \DateTime($check_in),
                    new \DateInterval('P1D'),
                    new \DateTime($check_out)
                );
                foreach ($period as $date) {
                    $total_money = $total_money->add(Pricing::calculate_daily_price_money($room->room_id, $date->format('Y-m-d')));
                }

                $available[] = array(
                    'room_id' => (int) $room->room_id,
                    'room_number' => $room->room_number,
                    'type_id' => (int) $room->type_id,
                    'type_name' => I18n::decode($room->type_name),
                    'max_adults' => (int) $room->max_adults,
                    'max_children' => (int) $room->max_children,
                    'total_price' => (float) $total_money->toDecimal(),
                    'price_formatted' => $total_money->format(),
                );
            }
        }

        return rest_ensure_response(array(
            'check_in' => $check_in,
            'check_out' => $check_out,
            'available' => $available,
            'count' => count($available),
        ));
    }

    /* BUILD_PRO_START */
    /**
     * POST /bookings — Create a new booking via API.
     * SECURITY: Requires API key authentication.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function create_booking($request)
    {
        // All sanitization is handled by BookingProcessor::process(). Idempotency check only.
        $request_hash    = '';
        $idempotency_key = $request->get_header('Idempotency-Key') ?: $request->get_header('X-Idempotency-Key');
        if ($idempotency_key) {
            $idempotency_key = sanitize_text_field(wp_unslash($idempotency_key));
            $request_hash = hash('sha256', wp_json_encode($request->get_params()));
            $cached_response = $this->get_idempotency_result($idempotency_key, $request_hash);
            if ($cached_response) {
                $response = rest_ensure_response($cached_response['body']);
                $response->set_status($cached_response['status']);
                return $response;
            }
        }

        // 2026 BP: Delegate to the centralized BookingProcessor to ensure absolute
        // architectural consistency between public, API, AI, and Admin channels.
        $params = $request->get_params();
        $params['source'] = 'api'; // Ensure attribution
        
        $result = \MHBO\Core\BookingProcessor::process($params);

        if (is_wp_error($result)) {
            $status = 400;
            switch ($result->get_error_code()) {
                case 'mhbo_lock_failed':
                    $status = 409;
                    break;
                case 'mhbo_unauthorized':
                    $status = 403;
                    break;
                case 'mhbo_error':
                    $status = 500;
                    break;
            }
            return new \WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => $status));
        }

        // Map response to the standard Pro API format for backward compatibility
        $response_data = array(
            'booking_id'    => $result['booking_id'],
            'booking_token' => $result['token'],
            'message'       => $result['message'],
        );

        $response = rest_ensure_response($response_data);

        if ($idempotency_key) {
            $this->save_idempotency_result($idempotency_key, $request_hash, $response->get_status(), $response->get_data());
        }

        return $response;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * GET /bookings/{id} — Get a single booking.
     * SECURITY: Requires API key and verifies booking access.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_booking($request)
    {
        global $wpdb;

        // Rule 11: Extract and sanitize all inputs at start
        $id        = absint($request->get_param('id'));
        $reference = sanitize_text_field($request->get_param('reference'));
        $booking   = null;

        if ($id > 0) {
            $cache_key = 'mhbo_booking_' . $id;
            $booking = wp_cache_get($cache_key, 'mhbo_bookings');

            if (false === $booking) {
                // RATIONALE: Required to fetch a single booking by ID for API response.
                // Uses $wpdb->prepare with %d; result is cached via wp_cache_set.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $booking = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
                    $id
                ));
                if ($booking) {
                    wp_cache_set($cache_key, $booking, 'mhbo_bookings', HOUR_IN_SECONDS);
                }
            }
        } elseif ($reference !== '' && $reference !== null) {
            // SECURITY: Support fetching by high-entropy reference token.
            $reference = sanitize_text_field($reference);
            // RATIONALE: Required to fetch booking by unique token for public-facing lookup.
            // Uses $wpdb->prepare with %s placeholder; single-use display.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE booking_token = %s",
                $reference
            ));
        }

        if (!$booking) {
            return new \WP_Error(
                'mhbo_not_found',
                esc_html(I18n::get_label('label_room_not_found')),
                array('status' => 404)
            );
        }

        // SECURITY: Verify access to this booking
        // Option 1: User is logged in and has manage_options capability (admin)
        // Option 2: API key is associated with this booking (via booking_reference)
        // Option 3: Request includes the booking's customer email for verification
        $has_access = $this->verify_booking_access($request, $booking);

        if (is_wp_error($has_access)) {
            return $has_access;
        }

        return rest_ensure_response($this->prepare_booking_for_response($booking, $request, $has_access));
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * Verify access to a booking.
     * SECURITY: Prevents unauthorized access to booking PII.
     *
     * @param \WP_REST_Request $request Request object.
     * @param object $booking Booking object.
     * @return bool|string|\WP_Error True for admin, 'owner' for verified owner, WP_Error for denied.
     */
    private function verify_booking_access($request, $booking)
    {
        // Admin users have full access
        if (Capabilities::current_user_can(Capabilities::MANAGE_LHBO)) {
            return true;
        }

        // Check for booking reference in request (for token-based verification)
        $booking_reference = $request->get_param('reference');
        if ($booking_reference !== '' && $booking_reference !== null) {
            // SECURITY: If the request came via the reference route or includes a valid token, grant 'owner' access.
            if (hash_equals($booking->booking_token, $booking_reference)) {
                return 'owner';
            }

            // BACKWARD COMPATIBILITY: Support the legacy HMAC-style reference for old confirmation links.
            $legacy_expected = hash('sha256', $booking->id . $booking->customer_email . wp_salt('auth'));
            if (hash_equals($legacy_expected, $booking_reference)) {
                return 'owner';
            }
        }

        // SECURITY: Removed weak email-only verification to prevent IDOR.
        // Access now requires either admin privileges or a valid high-entropy reference hash.

        // SECURITY: Deny access by default
        return new \WP_Error(
            'mhbo_access_denied',
            I18n::get_label('api_err_no_permission'),
            array('status' => 403)
        );
    }
    /* BUILD_PRO_END */

    /**
     * GET /calendar-data — Get availability data for calendar display.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_calendar_data($request)
    {
        global $wpdb;
        
        // Rule 11: Extract and sanitize all inputs at start
        $raw_room_id = $request->get_param('room_id');
        $room_id     = ($raw_room_id !== null && '' !== $raw_room_id) ? absint($raw_room_id) : -1;
        $year        = absint($request->get_param('year') ?: wp_date('Y'));
        $month       = absint($request->get_param('month') ?: wp_date('m'));

        // Validate room_id - If 0, we serve aggregated data for the search page
        if (0 === $room_id) {
            $start_str = sprintf('%04d-%02d-01', $year, $month);
            $dt_start  = new \DateTime($start_str);
            $dt_end    = clone $dt_start;
            $dt_end->modify('last day of this month');
            return $this->get_aggregated_calendar_data($start_str, $dt_end->format('Y-m-d'));
        }

        if ($room_id < 0) {
            return new \WP_Error('mhbo_missing_room_id', I18n::get_label('api_err_room_id_required'), array('status' => 400));
        }

        // Fetch bookings with status to differentiate pending vs confirmed
        // Cache with versioning for Rule 13 compliance
        $bookings_cache_key = 'calendar_bookings_' . $room_id;
        $bookings = Cache::get_query($bookings_cache_key, Cache::TABLE_BOOKINGS);

        if (false === $bookings) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, versioned caching implemented above
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT check_in, check_out, status FROM {$wpdb->prefix}mhbo_bookings 
                 WHERE room_id = %d 
                 AND status != 'cancelled'",
                $room_id
            ));
            Cache::set_query($bookings_cache_key, $bookings, Cache::TABLE_BOOKINGS, 300); // 5 min cache for real-time accuracy
        }

        // Get room status to mark as unbookable if maintenance/hidden
        $room_status = Cache::get_row($room_id, Cache::TABLE_ROOMS);

        if (false === $room_status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, row caching implemented above
            $room_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d",
                $room_id
            )) ?: 'available';
            Cache::set_row($room_id, $room_status, Cache::TABLE_ROOMS, HOUR_IN_SECONDS);
        }

        // Map each date to its booking status (pending or confirmed)
        $booked_dates = [];
        $check_ins = [];
        $check_outs = [];
        $checkout_booking_status = [];

        foreach ($bookings as $b) {
            $check_ins[] = $b->check_in;
            $check_outs[] = $b->check_out;
            $checkout_booking_status[$b->check_out] = $b->status;
            try {
                // Determine if this booking blocks specific dates
                $period = new \DatePeriod(
                    new \DateTime($b->check_in),
                    new \DateInterval('P1D'),
                    new \DateTime($b->check_out)
                );
                foreach ($period as $date) {
                    $date_str = $date->format('Y-m-d');
                    // Store the booking status for this date
                    $booked_dates[$date_str] = $b->status;
                }
            } catch (\Exception $e) {
                // Skip invalid dates
            }
        }

        // Hoist options that are loop-invariant out of the per-date iteration.
        $prevent_turnover = (int) get_option('mhbo_prevent_same_day_turnover', 0) === 1;
        $show_decimals    = (int) get_option('mhbo_calendar_show_decimals', 0) === 1;

        /* BUILD_PRO_START */
        // Pre-fetch ALL calendar overrides for the full 12-month window in two queries
        // (room-scope + type-scope), replacing the previous N+1 pattern of one DB call per
        // date for each of resolve_availability / resolve_min_stay / resolve_max_stay.
        // With 365 dates × 3 resolve functions × up to 2 queries each = ~2190 DB hits before;
        // now exactly 2–3 queries total regardless of window size.
        $overrides_batch      = []; // date → room-level stdClass row
        $type_overrides_batch = []; // date → type-level stdClass row
        $batch_global_min_stay = 0;
        $batch_global_max_stay = 0;

        if (class_exists('MHBO\Pro\AdminCalendar')) {
            // Compute the inclusive date range for the batch queries.
            $batch_start = sprintf('%04d-%02d-01', $year, $month);
            $batch_end   = (new \DateTime($batch_start))->modify('+12 months')->modify('-1 day')->format('Y-m-d');

            // 1 query: all room-level overrides for the window.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- versioned cache invalidated on save via Cache::bump(TABLE_CALENDAR_OVERRIDES); batching replaces per-date N+1
            $room_ov_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT date, availability, min_stay, max_stay
                 FROM {$wpdb->prefix}mhbo_calendar_overrides
                 WHERE scope = 'room' AND room_id = %d AND date BETWEEN %s AND %s",
                $room_id, $batch_start, $batch_end
            ));
            foreach ($room_ov_rows as $_ov) {
                $overrides_batch[$_ov->date] = $_ov;
            }

            // Resolve type_id (cached per-request).
            $cache_key_bt = 'mhbo_room_type_id_' . $room_id;
            $cached_bt    = wp_cache_get($cache_key_bt, 'mhbo');
            if (false === $cached_bt) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $cached_bt = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT type_id FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d", $room_id
                ));
                wp_cache_set($cache_key_bt, $cached_bt, 'mhbo', 3600);
            }
            $batch_type_id = (int) $cached_bt;

            // 1 query: all type-level overrides for the window.
            if ($batch_type_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batch query; see above
                $type_ov_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT date, availability, min_stay, max_stay
                     FROM {$wpdb->prefix}mhbo_calendar_overrides
                     WHERE scope = 'type' AND type_id = %d AND date BETWEEN %s AND %s",
                    $batch_type_id, $batch_start, $batch_end
                ));
                foreach ($type_ov_rows as $_ov) {
                    $type_overrides_batch[$_ov->date] = $_ov;
                }
            }

            // Global fallbacks (WP options, each cached after first read).
            $batch_global_min_stay = (int) get_option('mhbo_global_min_stay', 0);
            $batch_global_max_stay = (int) get_option('mhbo_global_max_stay', 0);
        }
        /* BUILD_PRO_END */

        // Generate data for 12 months (1 year) starting from the requested month
        $data = [];
        try {
            $start_date = new \DateTime("$year-$month-01");
            $end_date = clone $start_date;
            $end_date->modify('+12 months');

            $period = new \DatePeriod($start_date, new \DateInterval('P1D'), $end_date);
            foreach ($period as $dt) {
                $date_str = $dt->format('Y-m-d');
                $price_money = Pricing::calculate_daily_price_money($room_id, $date_str);
                $price = (float) $price_money->toDecimal();

                // Determine availability status using centralized logic
                // For calendar, we check if the room can be booked STARTING on this date
                // However, the calendar usually shows "booked" if any part of the day is occupied.
                // To match user expectations: a date is "booked" if it's already reserved.
                $is_booked         = isset($booked_dates[$date_str]);
                $is_manual_block   = false;

                /* BUILD_PRO_START */
                // Calendar overrides: blocked dates (availability=0) count as booked for the frontend.
                // Resolved from pre-fetched batch maps — O(1), no DB query per date.
                if (!$is_booked) {
                    $_ov_r = $overrides_batch[$date_str] ?? null;
                    $_ov_t = $type_overrides_batch[$date_str] ?? null;
                    // Room-level availability beats type-level.
                    $_av_resolved = null;
                    if ($_ov_r && null !== $_ov_r->availability) {
                        $_av_resolved = (int) $_ov_r->availability;
                    } elseif ($_ov_t && null !== $_ov_t->availability) {
                        $_av_resolved = (int) $_ov_t->availability;
                    }
                    if ($_av_resolved === 0) {
                        $is_booked       = true;
                        $is_manual_block = true; // Distinguish admin block from a real booking.
                    }
                }
                /* BUILD_PRO_END */

                // Check "Prevent Same-day Turnover" for check-in availability
                // If the date is a check-out date of an existing booking, it's only available if turnover is allowed.
                $is_check_out_day = in_array($date_str, $check_outs, true);

                // Also check if the NEXT day is a check-in day
                $next_day_str = gmdate('Y-m-d', strtotime($date_str . ' +1 day'));
                $next_day_is_check_in = in_array($next_day_str, $check_ins, true);

                $can_check_in = true;
                if ($is_booked) {
                    $can_check_in = false;
                } elseif ($is_check_out_day && $prevent_turnover) {
                    $can_check_in = false;
                } elseif ($next_day_is_check_in && $prevent_turnover) {
                    // Prevent checking in on the day immediately preceding an existing booking.
                    $can_check_in = false;
                }

                // Room is unbookable if status is not 'available' OR if price is 0 (unbooked)
                $is_unbookable = ('available' !== $room_status) || (!$is_booked && $price <= 0);

                // Determine final response 'status' to force frontend disable if turnover is prevented
                // If we cannot check in because of turnover prevention, force status to 'booked' so JS disables it.
                $final_status = 'available';
                if ($is_booked || (!$can_check_in && $is_check_out_day)) {
                    $final_status = 'booked';
                } elseif ($is_unbookable) {
                    $final_status = 'unbookable';
                }

                $b_status = $is_booked ? $booked_dates[$date_str] : null;
                // Provide booking status for checkout dates so the UI renders half-day colors
                if (!$is_booked && $is_check_out_day && isset($checkout_booking_status[$date_str])) {
                     $b_status = $checkout_booking_status[$date_str];
                }

                /* BUILD_PRO_START */
                // Resolve min/max stay from batch maps: room override > type override > global setting.
                // Priority cascade mirrors AdminCalendar::resolve_min_stay / resolve_max_stay exactly.
                $_ov_r = $overrides_batch[$date_str] ?? null;
                $_ov_t = $type_overrides_batch[$date_str] ?? null;

                $min_stay_override = null;
                if ($_ov_r && null !== $_ov_r->min_stay) {
                    $min_stay_override = (int) $_ov_r->min_stay;
                } elseif ($_ov_t && null !== $_ov_t->min_stay) {
                    $min_stay_override = (int) $_ov_t->min_stay;
                } elseif ($batch_global_min_stay > 0) {
                    $min_stay_override = $batch_global_min_stay;
                }

                $max_stay_override = null;
                if ($_ov_r && null !== $_ov_r->max_stay) {
                    $max_stay_override = (int) $_ov_r->max_stay;
                } elseif ($_ov_t && null !== $_ov_t->max_stay) {
                    $max_stay_override = (int) $_ov_t->max_stay;
                } elseif ($batch_global_max_stay > 0) {
                    $max_stay_override = $batch_global_max_stay;
                }
                /* BUILD_PRO_END */

                // Determine the reason a date is blocked (null = available).
                $reason = null;
                if ($is_manual_block) {
                    $reason = 'manual';
                } elseif ($is_booked) {
                    $reason = 'booked';
                } elseif ('available' !== $room_status) {
                    $reason = 'maintenance';
                }

                $data[] = [
                    'date' => $date_str,
                    'status' => $final_status,
                    'booking_status' => $b_status,
                    'is_checkin' => in_array($date_str, $check_ins, true),
                    'is_checkout' => $is_check_out_day,
                    'can_check_in' => $can_check_in,
                    'price' => $price,
                    'price_formatted' => $price_money->format(false, $show_decimals ? null : 0),
                    'reason' => $reason,
                    /* BUILD_PRO_START */
                    'min_stay' => $min_stay_override,
                    'max_stay' => $max_stay_override,
                    /* BUILD_PRO_END */
                ];
            }
        } catch (\Exception $e) {
            return new \WP_Error('mhbo_calendar_error', I18n::get_label('api_err_calendar_gen'), array('status' => 500));
        }

        $response = rest_ensure_response($data);
        /* BUILD_PRO_START */
        $hotel_tz = (string) get_option('mhbo_hotel_timezone', get_option('timezone_string', 'UTC'));
        $response->header('X-MHBO-Hotel-Timezone', $hotel_tz ?: 'UTC');
        /* BUILD_PRO_END */
        return $response;
    }

    /**
     * Get aggregated calendar data for all rooms.
     */
    private function get_aggregated_calendar_data($start_str, $end_str)
    {
        global $wpdb;

        // Get all rooms with caching using Rule 13 patterns
        $cache_key = 'mhbo_available_rooms_ids';
        $rooms = Cache::get_query($cache_key, Cache::TABLE_ROOMS);

        if (false === $rooms) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, caching implemented via Cache::set_query
            $rooms = $wpdb->get_results($wpdb->prepare("SELECT id FROM %i WHERE status = 'available'", $wpdb->prefix . 'mhbo_rooms'));
            Cache::set_query($cache_key, $rooms, Cache::TABLE_ROOMS, 3600);
        }

        if (count($rooms) === 0) {
            return rest_ensure_response([]);
        }

        $room_ids = array_column($rooms, 'id');

        // Same-day Turnover Setting
        $prevent_turnover = (bool) get_option('mhbo_prevent_same_day_turnover', false);

        // 2026 Best Practice (Rule 13): Use Cache class with versioned keys.
        $cache_key = 'mhbo_avail_agg_' . md5(implode(',', $room_ids) . $start_str . $end_str . (int)$prevent_turnover);
        $bookings = Cache::get_query($cache_key, Cache::TABLE_BOOKINGS);

        if (false === $bookings) {
            $room_placeholders_string = implode(',', array_fill(0, count($room_ids), '%d'));
            $params = array_merge($room_ids, [$end_str, $start_str]);

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            /**
             * RATIONALE FOR PHPCS DISABLE (RULE 13):
             * 1. DirectQuery: Required for custom table 'mhbo_bookings'.
             * 2. NoCaching: FALSE. Caching is handled via the $last_changed salt wrap-around.
             * 3. PreparedSQL: Fragmented preparation for code readability has been reviewed for security.
             */
            if ($prevent_turnover) {
                $bookings = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT room_id, check_in, check_out, status FROM {$wpdb->prefix}mhbo_bookings WHERE room_id IN ($room_placeholders_string) AND status != 'cancelled' AND (check_in <= DATE(%s) AND check_out >= DATE(%s))",
                        ...$params
                    )
                );
            } else {
                $bookings = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT room_id, check_in, check_out, status FROM {$wpdb->prefix}mhbo_bookings WHERE room_id IN ($room_placeholders_string) AND status != 'cancelled' AND (check_in < DATE(%s) AND check_out > DATE(%s))",
                        ...$params
                    )
                );
            }
            // phpcs:enable

            // Store in cache with 1 hour TTL (Rule 13 versioned)
            Cache::set_query($cache_key, $bookings, Cache::TABLE_BOOKINGS, 3600);
        }

        // Organize bookings by room
        $room_bookings = [];
        foreach ($room_ids as $rid) {
            $room_bookings[$rid] = [];
        }
        foreach ($bookings as $b) {
            $room_bookings[$b->room_id][] = [
                'check_in' => $b->check_in,
                'check_out' => $b->check_out,
                'status' => $b->status
            ];
        }

        /* BUILD_PRO_START */
        $global_min_stay = (int) get_option('mhbo_global_min_stay', 0);
        $global_max_stay = (int) get_option('mhbo_global_max_stay', 0);
        /* BUILD_PRO_END */

        $data = [];
        $start_date = new \DateTime($start_str);
        $end_date = new \DateTime($end_str);
        $period = new \DatePeriod($start_date, new \DateInterval('P1D'), $end_date);

        foreach ($period as $dt) {
            $date_str = $dt->format('Y-m-d');

            $total_rooms = count($room_ids);
            $rooms_free_pm = 0; // Can check in?
            $rooms_free_am = 0; // Can check out?
            $rooms_booked_pm = 0; // Actual stay occupancy
            $min_price = null;
            $has_pending_pm = false;
            $has_pending_am = false;

            foreach ($room_ids as $rid) {
                // Check status for this room on this date
                $is_occupied_pm = false; // Night stay
                $is_blocked_pm = false; // Turnover block
                $is_occupied_am = false; // Morning checkout day

                foreach ($room_bookings[$rid] as $b) {
                    // Stay Occupancy (Night of date_str)
                    if ($date_str >= $b['check_in'] && $date_str < $b['check_out']) {
                        $is_occupied_pm = true; 
                        if ($b['status'] === 'pending') $has_pending_pm = true;
                    }
                    
                    // Prevent same-day turnover block (Afternoon of checkout)
                    if ($prevent_turnover && $date_str === (string) $b['check_out']) {
                        $is_blocked_pm = true;
                        if ($b['status'] === 'pending') $has_pending_pm = true;
                    }

                    // Dead day block (Day before Check-In)
                    if ($prevent_turnover) {
                        $next_day = new \DateTime($date_str);
                        $next_day->modify('+1 day');
                        $next_day_str = $next_day->format('Y-m-d');
                        if ($next_day_str === (string) $b['check_in']) {
                            // Block check-in on the day before
                            $is_blocked_pm = true;
                        }
                    }

                    // AM Occupancy (Morning of checkout)
                    if ($date_str > $b['check_in'] && $date_str <= $b['check_out']) {
                        $is_occupied_am = true; 
                        if ($b['status'] === 'pending') $has_pending_am = true;
                    }
                }

                // A room is NOT free PM if it's either occupied by a stay OR blocked by turnover
                if (!$is_occupied_pm && !$is_blocked_pm) {
                    $rooms_free_pm++;
                }
                
                // Track actual night occupancy separately for status visualization
                if ($is_occupied_pm) {
                    $rooms_booked_pm++;
                }

                if (!$is_occupied_am) {
                    $rooms_free_am++;
                }

                // Calculate price (lowest available)
                if (!$is_occupied_pm) {
                    $price_money = Pricing::calculate_daily_price_money((int) $rid, $date_str);
                    $price = (float) $price_money->toDecimal();
                    if ($min_price === null || $price < $min_price) {
                        $min_price = $price;
                    }
                }
            }

            // Aggregated Status
            $status = 'available';
            $is_checkin = false; // White/Red (Free AM, Booked PM) -> Not selectable for check-in
            $is_checkout = false; // Red/White (Booked AM, Free PM) -> Selectable for check-in
            $booking_status = null;

            // If ALL rooms are occupied for a night stay
            if ($rooms_booked_pm === $total_rooms) {
                $status = 'booked';
                $booking_status = $has_pending_pm ? 'pending' : 'confirmed';

                // If some rooms are free AM (transition day), style as White/Red
                if ($rooms_free_am > 0) {
                    $is_checkin = true;
                }
            } elseif ($rooms_free_pm === 0) {
                // All rooms are either booked or turnover-blocked.
                // We show status 'booked' to match frontend disabling logic.
                $status = 'booked';
                $booking_status = $has_pending_pm ? 'pending' : 'confirmed';
                
                if ($rooms_free_am === 0) {
                    $is_checkout = true; // Visual: Red/White
                }
            } else {
                // At least one room is free PM.
                // If ALL rooms are occupied AM, style as Red/White
                if ($rooms_free_am === 0) {
                    $is_checkout = true; // Visual: Red/White
                    $booking_status = $has_pending_am ? 'pending' : 'confirmed';
                }
            }

            $show_decimals = (int) get_option('mhbo_calendar_show_decimals', 0) === 1;
            $data[] = [
                'date' => $date_str,
                'status' => $status,
                'booking_status' => $booking_status,
                'price' => $min_price !== null ? $min_price : 0,
                'price_formatted' => $min_price !== null ? I18n::format_currency($min_price, $show_decimals ? null : 0) : '-',
                'is_checkin' => $is_checkin,
                'is_checkout' => $is_checkout,
                'can_checkin' => $rooms_free_pm > 0,
                'can_checkout' => $rooms_free_am > 0,
                /* BUILD_PRO_START */
                'min_stay' => $global_min_stay > 0 ? $global_min_stay : null,
                'max_stay' => $global_max_stay > 0 ? $global_max_stay : null,
                /* BUILD_PRO_END */
            ];
        }

        return rest_ensure_response($data);
    }

    /**
     * POST /recalculate-price — Calculate total price with children and extras.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function recalculate_price($request)
    {
        // Rule 11: Extract and sanitize all inputs at start
        $room_id         = absint($request->get_param('room_id'));
        $check_in        = sanitize_text_field($request->get_param('check_in'));
        $check_out       = sanitize_text_field($request->get_param('check_out'));
        $guests          = absint($request->get_param('guests') ?: 1);
        $children        = absint($request->get_param('children') ?: 0);
        $child_ages_raw  = $request->get_param('child_ages') ?: $request->get_param('children_ages');
        $child_ages      = is_array($child_ages_raw) ? array_map('absint', $child_ages_raw) : array();
        // Rule 11: sanitize extras map at input boundary (key → sanitize_key, value → sanitize_text_field)
        $extras_raw      = is_array($request->get_param('extras')) ? $request->get_param('extras') : array();
        $extras          = array();
        foreach ($extras_raw as $k => $v) {
            $extras[ sanitize_key((string) $k) ] = sanitize_text_field((string) $v);
        }
        $payment_type    = sanitize_text_field($request->get_param('mhbo_payment_type') ?: 'full');
        $payment_method  = sanitize_text_field($request->get_param('mhbo_payment_method') ?: '');

        // Terminology Normalization: Map 'onsite' (Pro) to 'arrival' (Core)
        if ('onsite' === $payment_method) {
            $payment_method = 'arrival';
        }

        /* BUILD_PRO_START */
        $request_hash    = '';
        $idempotency_key = $request->get_header('Idempotency-Key') ?: $request->get_header('X-Idempotency-Key');
        if ($idempotency_key) {
            $idempotency_key = sanitize_text_field(wp_unslash($idempotency_key));
            $request_hash = hash('sha256', wp_json_encode($request->get_params()));
            $cached_response = $this->get_idempotency_result($idempotency_key, $request_hash);
            if ($cached_response) {
                $response = rest_ensure_response($cached_response['body']);
                $response->set_status($cached_response['status']);
                return $response;
            }
        }
        /* BUILD_PRO_END */

        /* BUILD_PRO_START */
        // Validation: Stay Restrictions (Minimum/Maximum Stay)
        if (class_exists('MHBO\Pro\AdminCalendar')) {
            $min_stay_rule = \MHBO\Pro\AdminCalendar::resolve_min_stay($room_id, $check_in);
            $max_stay_rule = \MHBO\Pro\AdminCalendar::resolve_max_stay($room_id, $check_in);
            
            $dt_in  = new \DateTime($check_in);
            $dt_out = new \DateTime($check_out);
            $nights = (int) $dt_in->diff($dt_out)->format('%a');

            if (null !== $min_stay_rule && $nights < $min_stay_rule) {
                return new \WP_Error(
                    'mhbo_min_stay',
                    esc_html(sprintf(
                        // translators: %1$d: minimum number of nights required
                        I18n::get_label('api_err_min_stay'),
                        (int) $min_stay_rule
                    )),
                    array('status' => 400)
                );
            }

            if (null !== $max_stay_rule && $nights > $max_stay_rule) {
                return new \WP_Error(
                    'mhbo_max_stay',
                    esc_html(sprintf(
                        // translators: %1$d: maximum number of nights allowed
                        I18n::get_label('api_err_max_stay'),
                        (int) $max_stay_rule
                    )),
                    array('status' => 400)
                );
            }
        }
        /* BUILD_PRO_END */

        $calc = Pricing::calculate_booking_money($room_id, $check_in, $check_out, (int) $guests, $extras, (int) $children, $child_ages);

        if ($calc && get_option('mhbo_deposits_enabled', 0) && (isset($calc['nights']) ? (int) $calc['nights'] : 0) > 1) {
            // 2026 BP: For 'first_night' deposit type, use room-rate-only calc (no extras, no children)
            // to match the industry standard meaning of "first night's rate" (accommodation only).
            // For percentage/fixed types, first_night_money is not used by calculate_deposit_money.
            // Uses calculate_booking_money (not daily_prices[0]) to ensure tax is applied in all modes.
            $fn_deposit_type_api = (string) get_option('mhbo_deposit_type', 'percentage');
            $first_night_end     = gmdate('Y-m-d', strtotime($check_in . ' +1 day'));
            $fn_extras_api       = ('first_night' === $fn_deposit_type_api) ? [] : $extras;
            $fn_children_api     = ('first_night' === $fn_deposit_type_api) ? 0 : (int) $children;
            $fn_ages_api         = ('first_night' === $fn_deposit_type_api) ? [] : $child_ages;
            $first_night_calc    = Pricing::calculate_booking_money($room_id, $check_in, $first_night_end, (int) $guests, $fn_extras_api, $fn_children_api, $fn_ages_api);
            $first_night_money   = (is_array($first_night_calc) && isset($first_night_calc['total']))
                ? $first_night_calc['total']
                : Money::fromCents(0, $calc['total']->getCurrency());
            $deposit_data = Pricing::calculate_deposit_money($calc['total'], $first_night_money);
            if ($deposit_data) {
                // Return high-precision objects and legacy floats for compatibility
                $calc['deposit_money'] = $deposit_data['deposit_money'];
                $calc['remaining_money'] = $deposit_data['remaining_money'];
                $calc['deposit_amount'] = $deposit_data['deposit_money']->toDecimal();
                $calc['remaining_balance'] = (float)$deposit_data['remaining_money']->toDecimal();
            }
        }

        $coupon_applied            = '';
        $coupon_discount_formatted = '';
        /* BUILD_PRO_START */
        // Apply coupon to recalculated totals so the display always matches the discounted price.
        // Mirrors the logic in ajax_create_paypal_order / create_stripe_intent.
        $coupon_code_param = sanitize_text_field((string)($request->get_param('coupon_code') ?: ''));
        if (
            '' !== $coupon_code_param
            && $calc
            && class_exists(\MHBO\Pro\CouponManager::class)
            && (bool)(int)get_option('mhbo_coupons_enabled', 1)
        ) {
            $room_data_for_coupon = Pricing::get_room_pricing_data($room_id);
            $type_id_for_coupon   = $room_data_for_coupon ? (int)$room_data_for_coupon->type_id : 0;
            $coupon_valid_rc      = \MHBO\Pro\CouponManager::validate(
                $coupon_code_param,
                $calc['total'],
                $room_id,
                $type_id_for_coupon,
                ''
            );
            if (!is_wp_error($coupon_valid_rc)) {
                $coupon_discount_rc        = \MHBO\Pro\CouponManager::calculate_discount($coupon_valid_rc, $calc['total']);
                $currency_code_rc          = strtoupper((string)get_option('mhbo_currency_code', 'USD'));
                $recalc_rc                 = Tax::recalculate_with_coupon($calc, $coupon_discount_rc, strtoupper($coupon_code_param), $currency_code_rc);
                $calc['tax']               = $recalc_rc['tax'];
                $calc['service_fee']       = $recalc_rc['service_fee'];
                $calc['total']             = $recalc_rc['total'];
                $coupon_applied            = strtoupper($coupon_code_param);
                $coupon_discount_formatted = $coupon_discount_rc->format();

                // Re-calculate deposit on the discounted total so the displayed deposit amount is also correct.
                if (get_option('mhbo_deposits_enabled', 0) && (isset($calc['nights']) ? (int)$calc['nights'] : 0) > 1) {
                    $fn_type_rc   = (string)get_option('mhbo_deposit_type', 'percentage');
                    $fn_end_rc    = gmdate('Y-m-d', strtotime($check_in . ' +1 day'));
                    $fn_extras_rc = ('first_night' === $fn_type_rc) ? [] : $extras;
                    $fn_ch_rc     = ('first_night' === $fn_type_rc) ? 0 : (int)$children;
                    $fn_ages_rc   = ('first_night' === $fn_type_rc) ? [] : $child_ages;
                    $fn_calc_rc   = Pricing::calculate_booking_money($room_id, $check_in, $fn_end_rc, (int)$guests, $fn_extras_rc, $fn_ch_rc, $fn_ages_rc);
                    $fn_money_rc  = (is_array($fn_calc_rc) && isset($fn_calc_rc['total']))
                        ? $fn_calc_rc['total']
                        : Money::fromCents(0, $calc['total']->getCurrency());
                    $dep_rc = Pricing::calculate_deposit_money($calc['total'], $fn_money_rc);
                    if ($dep_rc) {
                        $calc['deposit_money']     = $dep_rc['deposit_money'];
                        $calc['remaining_money']   = $dep_rc['remaining_money'];
                        $calc['deposit_amount']    = $dep_rc['deposit_money']->toDecimal();
                        $calc['remaining_balance'] = (float)$dep_rc['remaining_money']->toDecimal();
                    }
                }
            }
        }
        /* BUILD_PRO_END */

        if (!$calc) {
            return new \WP_Error(
                'mhbo_calculation_failed',
                I18n::get_label('api_err_price_calc'),
                array('status' => 400)
            );
        }

        $tax_data = $calc['tax'] ?? array(
            'enabled' => false,
            'mode' => 'disabled',
            'totals' => array(
                'subtotal_net' => $calc['total'],
                'total_tax' => Money::fromCents(0, Pricing::get_currency_code()),
                'total_gross' => $calc['total']
            )
        );

        // Extract typed local vars so the static analyser can narrow Money vs scalar — $calc is mixed[].
        $deposit_money_obj   = (isset($calc['deposit_money'])   && $calc['deposit_money']   instanceof Money) ? $calc['deposit_money']   : null;
        $remaining_money_obj = (isset($calc['remaining_money']) && $calc['remaining_money'] instanceof Money) ? $calc['remaining_money'] : null;

        // Include deposit info for HTML rendering if present
        if (null !== $deposit_money_obj) {
            $tax_data['deposit_amount']    = (float)$deposit_money_obj->toDecimal();
            $tax_data['remaining_balance'] = null !== $remaining_money_obj ? (float)$remaining_money_obj->toDecimal() : 0.0;
        }

        // payable_now reflects what the guest actually pays at checkout — only switch to
        // deposit amount when the guest has explicitly selected the deposit payment option.
        $payable_now = $calc['total'];
        if ('deposit' === $payment_type && null !== $deposit_money_obj) {
            $payable_now = $deposit_money_obj;
        }

        $children_money = $calc['children_total'] ?? Money::fromCents(0, Pricing::get_currency_code());
        $response = rest_ensure_response(array(
            'success' => true,
            'total' => (float) $calc['total']->toDecimal(),
            'total_formatted' => $calc['total']->format(),
            'payable_now' => (float) $payable_now->toDecimal(),
            'payable_now_formatted' => $payable_now->format(),
            'room_total' => (float) $calc['room_total']->toDecimal(),
            'children_total' => (float) $children_money->toDecimal(),
            'children_total_formatted' => $children_money->isPositive() ? $children_money->format() : '',
            'extras_total' => (float) $calc['extras_total']->toDecimal(),
            'deposit_amount' => (float) (null !== $deposit_money_obj ? $deposit_money_obj->toDecimal() : 0),
            'deposit_amount_formatted' => null !== $deposit_money_obj ? $deposit_money_obj->format() : '',
            'remaining_balance' => (float) (null !== $remaining_money_obj ? $remaining_money_obj->toDecimal() : 0),
            'remaining_balance_formatted' => null !== $remaining_money_obj ? $remaining_money_obj->format() : '',
            'extras_breakdown' => array_reduce($calc['extras_breakdown'] ?? array(), function($carry, $item) {
                $carry[(string)$item['id']] = array(
                    'value'               => (float) $item['total']->toDecimal(),
                    'formatted'           => $item['total']->format(),
                    'unit_price_value'    => (float) $item['price']->toDecimal(),
                    'unit_price_formatted'=> $item['price']->format(),
                    'multiplier'          => (int) ($item['multiplier'] ?? 1),
                    'pricing_type'        => $item['pricing_type'] ?? 'fixed',
                    'name'                => $item['name'],
                    'quantity'            => $item['quantity'],
                    'compulsory'          => ( $item['compulsory'] ?? false ) ? 1 : 0,
                );
                return $carry;
            }, array()),
            'breakdown' => $calc,
            'tax' => $tax_data,
            'tax_breakdown_html' => get_option('mhbo_tax_display_frontend', false) ? Tax::render_breakdown_html($tax_data, null, false, ['payment_type' => $payment_type], false) : '',
            'coupon_applied'            => $coupon_applied,
            'coupon_discount_formatted' => $coupon_discount_formatted,
        ));

        /* BUILD_PRO_START */
        if ($idempotency_key) {
            $this->save_idempotency_result($idempotency_key, $request_hash, $response->get_status(), $response->get_data());
        }
        /* BUILD_PRO_END */

        return $response;
    }

    /**
     * GET /tax-settings — Get current tax settings for frontend.
     *
     * @return \WP_REST_Response
     */
    public function get_tax_settings()
    {
        $tax = Tax::get_settings();

        return rest_ensure_response(array(
            'enabled' => $tax['enabled'],
            'mode' => $tax['mode'],
            'label' => $tax['label'],
            'accommodation_rate' => (float) $tax['accommodation_rate'],
            'extras_rate' => (float) $tax['extras_rate'],
            'registration_number' => $tax['registration_number'],
            'display_frontend' => (bool) $tax['display_frontend'],
            'prices_include_tax' => (bool) $tax['prices_include_tax'],
        ));
    }

    /* BUILD_PRO_START */
    /**
     * POST /payment-webhook - Handle Stripe/PayPal webhook events.
     * SECURITY: Signature verification is performed in verify_webhook_permission().
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_payment_webhook($request)
    {
        global $wpdb;

        // Get raw body for processing
        $payload = $request->get_body();
        $headers = $request->get_headers();

        // Determine webhook source (Stripe or PayPal)
        $stripe_signature = isset($headers['stripe_signature']) ? $headers['stripe_signature'][0] : null;
        $paypal_auth = isset($headers['paypal_auth_algo']) ? $headers['paypal_auth_algo'][0] : null;

        // Handle Stripe webhook
        if ($stripe_signature) {
            $event = json_decode($payload, true);
            return $this->process_stripe_event($event);
        }

        // Handle PayPal webhook
        if ($paypal_auth) {
            return $this->handle_paypal_webhook($payload, $headers);
        }

        // SECURITY: This should never be reached due to permission_callback verification
        return new \WP_Error(
            'mhbo_invalid_webhook',
            I18n::get_label('api_err_invalid_webhook'),
            array('status' => 400)
        );
    }
    /* BUILD_PRO_END */



    /* BUILD_PRO_START */
    /**
     * Process Stripe webhook event.
     *
     * @param array $event Stripe event data.
     * @return \WP_REST_Response
     */
    private function process_stripe_event($event)
    {
        if (!isset($event['type'])) {
            return rest_ensure_response(array('status' => 'ignored', 'reason' => I18n::get_label('api_err_no_event_type')));
        }

        global $wpdb;

        switch ($event['type']) {
            case 'payment_intent.succeeded':
                $payment_intent = $event['data']['object'] ?? null;
                if ($payment_intent && isset($payment_intent['id'])) {
                    $cache_key = 'mhbo_booking_tx_' . md5($payment_intent['id']);
                    $booking = wp_cache_get($cache_key, 'mhbo_bookings');

                    if (false === $booking) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                        $booking = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE payment_transaction_id = %s",
                            $payment_intent['id']
                        ));
                    }

                    $currency = strtoupper((string) ($payment_intent['currency'] ?? get_option('mhbo_currency_code', 'USD')));
                    $amount_cents = (int) ($payment_intent['amount'] ?? 0);
                    $money = Money::fromCents($amount_cents, $currency);

                    if ($booking) {
                        wp_cache_set($cache_key, $booking, 'mhbo_bookings', 5 * MINUTE_IN_SECONDS);
                        \MHBO\Pro\PaymentGateways::update_payment_status(
                            (int) $booking->id,
                            'completed',
                            $payment_intent['id'],
                            $money
                        );
                    } else {
                        // Booking not found - attempt to create from metadata (asynchronous creation via webhook)
                        $metadata = $payment_intent['metadata'] ?? array();
                        if ($metadata !== null && count($metadata) > 0 && isset($metadata['mhbo_source']) && $metadata['mhbo_source'] === 'frontend_booking') {
                            \MHBO\Pro\PaymentGateways::create_booking_from_metadata(
                                $metadata,
                                $payment_intent['id'],
                                'stripe',
                                $money
                            );
                        }
                    }
                }
                break;

            case 'payment_intent.payment_failed':
                $payment_intent = $event['data']['object'] ?? null;
                if ($payment_intent && isset($payment_intent['id'])) {
                    $cache_key = 'mhbo_booking_tx_' . md5($payment_intent['id']);
                    $booking = wp_cache_get($cache_key, 'mhbo_bookings');

                    if (false === $booking) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                        $booking = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE payment_transaction_id = %s",
                            $payment_intent['id']
                        ));
                        if ($booking) {
                            wp_cache_set($cache_key, $booking, 'mhbo_bookings', 5 * MINUTE_IN_SECONDS);
                        }
                    }

                    if ($booking) {
                        $error_message = isset($payment_intent['last_payment_error']['message'])
                            ? $payment_intent['last_payment_error']['message']
                            : I18n::get_label('api_err_payment_failed');
                        \MHBO\Pro\PaymentGateways::update_payment_status(
                            $booking->id,
                            'failed',
                            $payment_intent['id'],
                            null,
                            $error_message
                        );
                    }
                }
                break;

            case 'charge.refunded':
                $charge = $event['data']['object'] ?? null;
                if ($charge && isset($charge['payment_intent'])) {
                    $cache_key = 'mhbo_booking_tx_' . md5($charge['payment_intent']);
                    $booking = wp_cache_get($cache_key, 'mhbo_bookings');

                    if (false === $booking) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                        $booking = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE payment_transaction_id = %s",
                            $charge['payment_intent']
                        ));
                        if ($booking) {
                            wp_cache_set($cache_key, $booking, 'mhbo_bookings', 5 * MINUTE_IN_SECONDS);
                        }
                    }
                    if ($booking) {
                        \MHBO\Pro\PaymentGateways::update_payment_status(
                            $booking->id,
                            'refunded',
                            $charge['payment_intent']
                        );
                    }
                }
                break;
        }

        // Fire action for extensibility
        do_action('mhbo_stripe_webhook_received', $event);

        return rest_ensure_response(array('status' => 'received', 'event_type' => $event['type']));
    }

    /* BUILD_PRO_END */


    /* BUILD_PRO_START */
    /**
     * Handle PayPal webhook with header verification.
     *
     * @param string $payload Raw request body.
     * @param array $headers Request headers.
     * @return \WP_REST_Response|\WP_Error
     */
    private function handle_paypal_webhook($payload, $headers)
    {
        $event = json_decode($payload, true);
        return $this->process_paypal_event($event);
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * Process PayPal webhook event.
     *
     * @param array $event PayPal event data.
     * @return \WP_REST_Response
     */
    private function process_paypal_event($event)
    {
        if (!isset($event['event_type'])) {
            return rest_ensure_response(array('status' => 'ignored', 'reason' => I18n::get_label('api_err_no_event_type')));
        }

        global $wpdb;

        switch ($event['event_type']) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                $resource = $event['resource'] ?? null;
                if ($resource) {
                    $order_id = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? null;

                    if ($order_id) {
                        $cache_key = 'mhbo_booking_tx_' . md5((string)$order_id);
                        $booking = wp_cache_get($cache_key, 'mhbo_bookings');

                        if (false === $booking) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                            $booking = $wpdb->get_row($wpdb->prepare(
                                "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE payment_transaction_id = %s",
                                $order_id
                            ));
                            if ($booking) {
                                wp_cache_set($cache_key, $booking, 'mhbo_bookings', 5 * MINUTE_IN_SECONDS);
                            }
                        }

                        $amount_val = $resource['amount']['value'] ?? '0';
                        $currency   = $resource['amount']['currency_code'] ?? get_option('mhbo_currency_code', 'USD');
                        $money      = Money::fromDecimal((string) $amount_val, (string) $currency);

                        if ($booking) {
                            \MHBO\Pro\PaymentGateways::update_payment_status(
                                $booking->id,
                                'completed',
                                $order_id,
                                $money
                            );
                        } else {
                            // Asynchronous fallback: Create booking from metadata if it doesn't exist yet
                            $custom_id = $resource['custom_id'] ?? ($resource['custom'] ?? null);
                            if ($custom_id) {
                                $metadata = json_decode($custom_id, true);
                                if ($metadata && isset($metadata['mhbo_source']) && $metadata['mhbo_source'] === 'frontend_booking') {
                                    \MHBO\Pro\PaymentGateways::create_booking_from_metadata(
                                        $metadata,
                                        $order_id,
                                        'paypal',
                                        $money
                                    );
                                }
                            }
                        }
                    }
                }
                break;

            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.REFUNDED':
                $resource = $event['resource'] ?? null;
                if ($resource) {
                    $order_id = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? null;

                    if ($order_id) {
                        $cache_key = 'mhbo_booking_tx_' . md5((string)$order_id);
                        $booking = wp_cache_get($cache_key, 'mhbo_bookings');

                        if (false === $booking) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                            $booking = $wpdb->get_row($wpdb->prepare(
                                "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE payment_transaction_id = %s",
                                $order_id
                            ));
                            if ($booking) {
                                wp_cache_set($cache_key, $booking, 'mhbo_bookings', 5 * MINUTE_IN_SECONDS);
                            }
                        }

                        if ($booking) {
                            $status = ($event['event_type'] === 'PAYMENT.CAPTURE.REFUNDED') ? 'refunded' : 'failed';
                            \MHBO\Pro\PaymentGateways::update_payment_status(
                                $booking->id,
                                $status,
                                $order_id
                            );
                        }
                    }
                }
                break;

            case 'CHECKOUT.ORDER.APPROVED':
                // Order approved but not yet captured - mark as processing
                $resource = $event['resource'] ?? null;
                if ($resource && isset($resource['id'])) {
                    $cache_key = 'mhbo_booking_tx_' . md5($resource['id']);
                    $booking = wp_cache_get($cache_key, 'mhbo_bookings');

                    if (false === $booking) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                        $booking = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE payment_transaction_id = %s",
                            $resource['id']
                        ));
                        if ($booking) {
                            wp_cache_set($cache_key, $booking, 'mhbo_bookings', 5 * MINUTE_IN_SECONDS);
                        }
                    }
                    if ($booking) {
                        \MHBO\Pro\PaymentGateways::update_payment_status(
                            $booking->id,
                            'processing',
                            $resource['id']
                        );
                    }
                }
                break;
        }

        // Fire action for extensibility
        do_action('mhbo_paypal_webhook_received', $event);

        return rest_ensure_response(array('status' => 'received', 'event_type' => $event['event_type']));
    }
    /* BUILD_PRO_END */
    /**
     * Prepare a booking object for REST response.
     *
     * @param object           $booking Booking row.
     * @param \WP_REST_Request $request Request object.
     * @param mixed            $access  Access level (true, error, or 'owner').
     * @return array<string, mixed>
     */
    private function prepare_booking_for_response(object $booking, \WP_REST_Request $request, mixed $access = true): array
    {
        // Return limited data for non-admin access
        $is_admin = Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS);
        
        // Treat API key holders as admins for data visibility
        $is_api_client = ($request->get_header('X-MHBO-API-KEY') !== '' && $request->get_header('X-MHBO-API-KEY') !== null);

        $response_data = array(
            'id' => (int) $booking->id,
            'room_id' => (int) $booking->room_id,
            'check_in' => $booking->check_in,
            'check_out' => $booking->check_out,
            'total_price' => (float) $booking->total_price,
            'status' => $booking->status,
            'booking_language' => $booking->booking_language,
            'source' => $booking->source,
            'created_at' => $booking->created_at,
            'payment_type'               => $booking->payment_type ?? 'full',
            'deposit_amount'             => (float)($booking->deposit_amount ?? 0),
            'remaining_balance'          => (float)($booking->remaining_balance ?? 0),
            'balance_status'             => $booking->balance_status ?? 'collected',
            'deposit_is_non_refundable'  => (bool)($booking->deposit_is_non_refundable ?? 0),
            'refund_deadline_date'       => $booking->refund_deadline_date ?? null,
        );

        // Include PII only for admin access, API clients, or verified owners
        if ($is_admin || $is_api_client || 'owner' === $access) {
            $response_data['customer_name'] = $booking->customer_name;
            $response_data['customer_email'] = $booking->customer_email;
            $response_data['customer_phone'] = $booking->customer_phone;
            
            $breakdown = $booking->tax_breakdown ? json_decode($booking->tax_breakdown, true) : null;
            if (isset($breakdown['extras']) && is_array($breakdown['extras'])) {
                foreach ($breakdown['extras'] as &$extra) {
                    if (isset($extra['name'])) {
                        $extra['name'] = I18n::decode($extra['name'], $booking->booking_language);
                    }
                }
            }

            $response_data['tax'] = array(
                'enabled' => (bool) ($booking->tax_enabled ?? 0),
                'mode' => $booking->tax_mode ?? 'disabled',
                'subtotal_net' => (float) ($booking->subtotal_net ?? $booking->total_price),
                'total_tax' => (float) ($booking->total_tax ?? 0),
                'total_gross' => (float) ($booking->total_gross ?? $booking->total_price),
                'breakdown' => $breakdown,
            );
        }

        return $response_data;
    }

    /* BUILD_PRO_START */
    /**
     * Retrieve a cached idempotency response.
     * Rule 13: Direct database lookup for reliable API execution.
     *
     * @param string $key          The Idempotency-Key.
     * @param string $request_hash SHA256 of the request payload.
     * @return array{status: int, body: array<string, mixed>}|null The stored response or null.
     */
    private function get_idempotency_result(string $key, string $request_hash): ?array
    {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT response_code, response_body, request_hash FROM {$wpdb->prefix}mhbo_idempotency WHERE idempotency_key = %s",
            $key
        ));

        if (!$row) {
            return null;
        }

        // Verify request payload hasn't changed for the same key
        if (!hash_equals($row->request_hash, $request_hash)) {
            return array('status' => 400, 'body' => array('code' => 'idempotency_conflict', 'message' => I18n::get_label('api_err_idempotency_conflict')));
        }

        return array(
            'status' => (int) $row->response_code,
            'body'   => json_decode($row->response_body, true),
        );
    }

    /**
     * Save an idempotency response.
     *
     * @param string               $key          The Idempotency-Key.
     * @param string               $request_hash SHA256 of the request payload.
     * @param int                  $code         HTTP response code.
     * @param array<string, mixed> $body         Response body.
     */
    private function save_idempotency_result(string $key, string $request_hash, int $code, array $body): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhbo_idempotency';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->replace(
            $table,
            array(
                'idempotency_key' => $key,
                'request_hash'    => $request_hash,
                'response_code'   => $code,
                'response_body'   => wp_json_encode($body),
                'created_at'      => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%s', '%s')
        );
    }

    /**
     * Clean up expired idempotency keys (older than 24 hours).
     */
    public function cleanup_expired_idempotency_keys(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mhbo_idempotency WHERE created_at < %s",
            gmdate('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
    }
    /* BUILD_PRO_END */

    /**
     * Handle public booking submission from the frontend.
     * 2026 Modernized Flow: Replaces legacy AJAX with clean REST API.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_public_booking_submission(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        // 1. Prepare data for processor
        $params = $request->get_params();
        
        // 2. Delegate to central processor
        $result = \MHBO\Core\BookingProcessor::process($params);

        if (is_wp_error($result)) {
            // Map common error codes to HTTP statuses
            $status = 400;
            switch ($result->get_error_code()) {
                case 'mhbo_lock_failed':
                    $status = 409;
                    break;
                case 'mhbo_unauthorized':
                    $status = 403;
                    break;
            }
            return new \WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => $status));
        }

        return rest_ensure_response($result);
    }
}
