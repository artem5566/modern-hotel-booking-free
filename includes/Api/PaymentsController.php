<?php declare(strict_types=1);

namespace MHBO\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use MHBO\Database\Queries\Booking_Query;
use MHBO\Core\Pricing;
use MHBO\Core\Money;
use MHBO\Core\I18n;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PaymentsController (WP 7.1 / 2026 Standard)
 *
 * Implements Option B: Server-Side Session Pricing.
 * Prevents client-side price manipulation by calculating prices exclusively server-side
 * and binding temporary payment sessions to the guest's mhbo_session cookie.
 *
 * Booking ID Reconciliation Architecture Note:
 * When creating a PaymentIntent before a booking record exists in the database,
 * the PaymentIntent ID is attached to the form and submitted to /booking/complete.
 * If the user completes payment on Stripe but abandons /booking/complete, or if the
 * Stripe webhook fires before /booking/complete completes, the webhook handler
 * queries by session_token/payment_intent_id to reconcile and ensure no orphan payments.
 */
class PaymentsController extends WP_REST_Controller
{
    public function __construct()
    {
        $this->namespace = 'mhbo/v1';
        $this->rest_base = 'payments';
    }

    public function register_routes(): void
    {
        // 1. Calculate endpoint (Option B: Server-Side Session Pricing)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/calculate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'calculate_session'],
            'permission_callback' => '__return_true', // Rate limiting and nonces verified inside callback
        ]);

        // 2. Intent endpoint (consumes session_token or booking_id)
        
    }

    private function check_https(): ?WP_Error
    {
        if (!is_ssl() && wp_get_environment_type() === 'production') {
            return new WP_Error('rest_forbidden', 'HTTPS is required for payment endpoints.', ['status' => 403]);
        }
        return null;
    }

    private function log_error(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[MHBO Payments] ' . $message);
        }
    }

    private function check_rate_limit(): ?WP_Error
    {
        static $incremented = false;
        
        $client_ip = $this->get_client_ip_secure();
        $rate_key  = 'mhbo_intent_rate_' . md5(sanitize_text_field($client_ip));
        $rate_count = (int) get_transient($rate_key);
        
        if ($rate_count >= 10) {
            return new WP_Error('rate_limited', 'Too many requests. Please try again later.', ['status' => 429]);
        }

        if (!$incremented) {
            set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS * 15);
            $incremented = true;
        }
        
        return null;
    }

    private function get_client_ip_secure(): string
    {
        $cloudflare_ranges = [
            '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '104.16.0.0/13',   '104.24.0.0/14',   '108.162.192.0/18',
            '131.0.72.0/22',   '141.101.64.0/18', '162.158.0.0/15',
            '172.64.0.0/13',   '173.245.48.0/20', '188.114.96.0/20',
            '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
            '2400:cb00::/32',  '2606:4700::/32',  '2803:f800::/32',
            '2405:b500::/32',  '2405:8100::/32',  '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
        
        $raw_remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '127.0.0.1';
        $remote     = filter_var($raw_remote, FILTER_VALIDATE_IP) ? $raw_remote : '127.0.0.1';

        if (wp_get_environment_type() === 'local') {
            $cloudflare_ranges[] = '127.0.0.1/32';
        }

        if (class_exists('\MHBO\Core\Security')) {
            foreach ($cloudflare_ranges as $range) {
                if (\MHBO\Core\Security::ip_in_range($remote, $range)) {
                    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                        $cf_ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
                        if (filter_var($cf_ip, FILTER_VALIDATE_IP)) {
                            return $cf_ip;
                        }
                    }
                    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                        $forwarded = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
                        $ips = explode(',', $forwarded);
                        $first_ip = trim($ips[0]);
                        if (filter_var($first_ip, FILTER_VALIDATE_IP)) {
                            return $first_ip;
                        }
                    }
                    return $remote;
                }
            }
        }
        
        return $remote;
    }

    private function get_idempotency_key(string $gateway, int|string $identifier): string
    {
        $transient_key = 'mhbo_idemp_' . $gateway . '_' . md5((string) $identifier);
        $key = get_transient($transient_key);
        if (empty($key) || !is_string($key)) {
            $key = wp_generate_uuid4();
            set_transient($transient_key, $key, 12 * HOUR_IN_SECONDS);
        }
        return $key;
    }

    /**
     * Calculate session pricing server-side and issue a bound session token.
     */
    public function calculate_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $https_check = $this->check_https();
        if ($https_check) {
            return $https_check;
        }

        // Gap 2: Strict Rate Limiting on calculation endpoint
        $rate_check = $this->check_rate_limit();
        if ($rate_check) {
            return $rate_check;
        }

        // Nonce check
        $nonce = (string) ($request->get_param('nonce') ?: $request->get_param('security') ?: '');
        $nonce_valid = wp_verify_nonce($nonce, 'mhbo_stripe_nonce')
            || wp_verify_nonce($nonce, 'mhbo_paypal_nonce')
            || wp_verify_nonce($nonce, 'mhbo_braintree_nonce')
            || wp_verify_nonce($nonce, 'wp_rest');

        if (!$nonce_valid) {
            return new WP_Error('rest_forbidden', 'Invalid security token.', ['status' => 403]);
        }

        $room_id      = (int) $request->get_param('room_id');
        $type_id      = (int) $request->get_param('type_id');
        $check_in     = sanitize_text_field((string) $request->get_param('check_in'));
        $check_out    = sanitize_text_field((string) $request->get_param('check_out'));
        $guests       = (int) ($request->get_param('guests') ?: 1);
        $children     = (int) ($request->get_param('children') ?: 0);
        $child_ages   = (array) ($request->get_param('child_ages') ?: []);
        $extras       = (array) ($request->get_param('mhbo_extras') ?: []);
        $payment_type = sanitize_text_field((string) ($request->get_param('mhbo_payment_type') ?: 'full'));

        $child_ages   = array_map('absint', $child_ages);
        $extras       = array_map('sanitize_text_field', $extras);

        // Resolve room_id from type_id if category booking
        if (0 === $room_id && 0 !== $type_id) {
            if (class_exists(Pricing::class) && method_exists(Pricing::class, 'find_available_room')) {
                $resolved_room = Pricing::find_available_room($type_id, $check_in, $check_out);
                if ($resolved_room) {
                    $room_id = (int) $resolved_room;
                }
            }
        }

        if (0 === $room_id) {
            return new WP_Error('rest_not_found', 'Room not found or unavailable.', ['status' => 404]);
        }

        // Gap 1: Bind session to mhbo_session cookie early
        $raw_cookie = isset($_COOKIE['mhbo_session']) ? sanitize_text_field(wp_unslash($_COOKIE['mhbo_session'])) : '';
        $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $raw_cookie);
        if ('' === $session_id) {
            $session_id = bin2hex(random_bytes(32));
            if (!headers_sent()) {
                setcookie('mhbo_session', $session_id, [
                    'expires'  => time() + HOUR_IN_SECONDS * 2,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }

        // Check if the current user session already holds a pending booking (e.g. switching payment gateways or re-calculating)
        $existing_pending_id = (int) get_transient('mhbo_session_pending_booking_' . $session_id);

        if (class_exists(Pricing::class) && method_exists(Pricing::class, 'ensure_pro_init')) {
            Pricing::ensure_pro_init();
        }

        // Availability check (exclude the user's current pending booking if re-calculating)
        if (class_exists(Pricing::class) && method_exists(Pricing::class, 'is_room_available')) {
            $availability = Pricing::is_room_available($room_id, $check_in, $check_out, $existing_pending_id);
            if ($availability !== true) {
                return new WP_Error('availability_conflict', 'Selected dates are no longer available.', ['status' => 409]);
            }
        }

        $calc = Pricing::calculate_booking_money($room_id, $check_in, $check_out, $guests, $extras, $children, $child_ages);
        $amount_money = $calc['total'] ?? null;

        // Coupon code handling if provided
        $coupon_code = sanitize_text_field((string) ($request->get_param('mhbo_coupon_code') ?: $request->get_param('mhbo_coupon_applied') ?: ''));
        if ('' !== $coupon_code && $amount_money !== null && class_exists(ProRemoved::class) && (bool)(int)get_option('mhbo_coupons_enabled', 1)) {
            $customer_email = sanitize_email((string) $request->get_param('customer_email'));
            $room_data      = Pricing::get_room_pricing_data($room_id);
            $type_id_coupon = $room_data ? (int)$room_data->type_id : 0;
            $coupon_valid   = ProRemoved::validate($coupon_code, $amount_money, $room_id, $type_id_coupon, $customer_email);
            if (!is_wp_error($coupon_valid)) {
                $discount = ProRemoved::calculate_discount($coupon_valid, $amount_money);
                $currency_code = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
                $recalc = \MHBO\Core\Tax::recalculate_with_coupon($calc, $discount, strtoupper($coupon_code), $currency_code);
                $amount_money = $recalc['total'] ?? $amount_money;
            }
        }

        // Deposit handling
        if ('deposit' === $payment_type && $amount_money !== null) {
            $first_night_end = gmdate('Y-m-d', strtotime($check_in . ' +1 day'));
            $fn_deposit_type = (string) get_option('mhbo_deposit_type', 'percentage');
            $fn_extras_arg   = ('first_night' === $fn_deposit_type) ? [] : $extras;
            $fn_children_arg = ('first_night' === $fn_deposit_type) ? 0 : $children;
            $fn_ages_arg     = ('first_night' === $fn_deposit_type) ? [] : $child_ages;
            $first_night_calc = Pricing::calculate_booking_money($room_id, $check_in, $first_night_end, $guests, $fn_extras_arg, $fn_children_arg, $fn_ages_arg);
            $first_night_price_money = (is_array($first_night_calc) && isset($first_night_calc['total'])) ? $first_night_calc['total'] : Money::fromCents(0, $amount_money->getCurrency());

            $deposit_data = Pricing::calculate_deposit_money($amount_money, $first_night_price_money);
            if (is_array($deposit_data) && isset($deposit_data['deposit_money'])) {
                $amount_money = $deposit_data['deposit_money'];
            }
        }

        if ($amount_money === null || $amount_money->isZero()) {
            return new WP_Error('rest_error', 'Invalid booking calculation.', ['status' => 400]);
        }

        // Cryptographically secure 64-char booking token
        $booking_token = bin2hex(random_bytes(32));
        $total_decimal = (float) $amount_money->toDecimal();
        $currency_code = $amount_money->getCurrency();

        // Customer details if provided during calculation
        $customer_name  = sanitize_text_field((string) ($request->get_param('customer_name') ?: 'Guest'));
        $customer_email = sanitize_email((string) ($request->get_param('customer_email') ?: 'guest@pending.local'));
        $customer_phone = sanitize_text_field((string) ($request->get_param('customer_phone') ?: ''));

        // Insert durable booking in pending_payment status (prevents webhook timing gap)
        global $wpdb;

        $booking_data = [
            'room_id'        => $room_id,
            'customer_name'  => $customer_name,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'check_in'       => $check_in,
            'check_out'      => $check_out,
            'guests'         => $guests,
            'children'       => $children,
            'children_ages'  => [] !== $child_ages ? wp_json_encode($child_ages) : null,
            'booking_extras' => [] !== $extras ? wp_json_encode($extras) : null,
            'total_price'    => $total_decimal,
            'status'         => 'pending_payment',
            'booking_token'  => $booking_token,
            'payment_status' => 'pending',
            'payment_type'   => $payment_type,
            'payment_method' => sanitize_key((string) ($request->get_param('gateway') ?: 'stripe')),
            'created_at'     => current_time('mysql'),
        ];

        if ($existing_pending_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update("{$wpdb->prefix}mhbo_bookings", $booking_data, ['id' => $existing_pending_id]);
            $booking_id = $existing_pending_id;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert("{$wpdb->prefix}mhbo_bookings", $booking_data);
            $booking_id = (int) $wpdb->insert_id;
            if ($booking_id > 0) {
                set_transient('mhbo_session_pending_booking_' . $session_id, $booking_id, HOUR_IN_SECONDS);
            }
        }

        if ($booking_id <= 0) {
            return new WP_Error('rest_error', 'Failed to initialize pending booking.', ['status' => 500]);
        }

        // Lightweight session binding transient (1 hour TTL)
        set_transient('mhbo_booking_session_' . $booking_token, [
            'booking_id' => $booking_id,
            'session_id' => $session_id,
        ], HOUR_IN_SECONDS);

        // Backward compatibility alias for session_token
        set_transient('mhbo_session_' . $booking_token, [
            'price'      => $total_decimal,
            'currency'   => $currency_code,
            'booking_id' => $booking_id,
            'session_id' => $session_id,
        ], HOUR_IN_SECONDS);

        return new WP_REST_Response([
            'success'       => true,
            'booking_id'    => $booking_id,
            'booking_token' => $booking_token,
            'session_token' => $booking_token, // Alias for backward compatibility
            'data'          => [
                'booking_id'    => $booking_id,
                'booking_token' => $booking_token,
                'session_token' => $booking_token,
            ],
        ]);
    }

}
