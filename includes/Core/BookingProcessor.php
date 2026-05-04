<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Tax;
use MHBO\Core\License;
use MHBO\Core\Cache;

/**
 * BookingProcessor Class
 * 
 * Centralized service for processing and finalising bookings.
 * This class handles validation, availability checks, pricing recalculation,
 * payment verification, and database insertion.
 * 
 * @package MHBO\Core
 * @since 2.3.8
 */
class BookingProcessor
{
    /**
     * Process a booking submission.
     * 
     * @param array $data {
     *     @type int    $room_id         Room ID.
     *     @type string $check_in        Check-in date (Y-m-d).
     *     @type string $check_out       Check-out date (Y-m-d).
     *     @type string $customer_name   Customer name.
     *     @type string $customer_email  Customer email.
     *     @type string $customer_phone  Customer phone.
     *     @type int    $guests          Number of adults.
     *     @type int    $children        Number of children (Pro).
     *     @type array  $child_ages      Ages of children (Pro).
     *     @type array  $extras          Map of extra ID => quantity (Pro).
     *     @type string $payment_method  'arrival', 'stripe', or 'paypal'.
     *     @type string $payment_type    'full' or 'deposit' (Pro).
     *     @type string $stripe_pi       Stripe PaymentIntent ID (Pro).
     *     @type string $paypal_order_id PayPal Order ID (Pro).
     *     @type array  $custom_fields   Map of custom field ID => value.
     *     @type string $admin_notes     Optional admin notes.
     *     @type int    $update_id       Optional booking ID to update (resumption).
     *     @type string $page_url        The URL of the booking page for redirects.
     *     @type string $language        Booking language.
     *     @type string $source          Booking source (public, admin, ai_concierge, ical, airbnb, etc.).
     *     @type string $external_id     Platform UID for iCal/OTA sync.
     *     @type string $parent_token    Parent token for multi-room linking.
     *     @type bool   $bypass_past     Whether to bypass past-date validation (Admin/iCal).
     * }
     * }
     * @param array<string, mixed> $data
     * @return array<string, mixed>|\WP_Error Result array on success, WP_Error on failure.
     */
    /**
     * @param array<string, mixed> $data
     * @param bool $bypass_lock When true the caller already holds all advisory locks
     *                          for this room and is responsible for releasing them.
     *                          MUST only be set via this parameter — never via $data —
     *                          to prevent HTTP clients from bypassing concurrency guards.
     */
    public static function process(array $data, bool $bypass_lock = false): array|\WP_Error
    {
        global $wpdb;

        // 1. Sanitize Inputs
        $room_id        = absint($data['room_id'] ?? 0);
        $type_id        = absint($data['type_id'] ?? 0);
        $check_in       = sanitize_text_field($data['check_in'] ?? '');
        $check_out      = sanitize_text_field($data['check_out'] ?? '');
        $customer_name  = sanitize_text_field($data['customer_name'] ?? '');
        $customer_email = sanitize_email($data['customer_email'] ?? '');
        $customer_phone = sanitize_text_field($data['customer_phone'] ?? '');
        $guests         = max(1, absint($data['guests'] ?? 1));
        $payment_method = sanitize_key($data['payment_method'] ?? 'arrival') ?: 'arrival';
        $language       = sanitize_key($data['language'] ?? I18n::get_current_language());
        $update_id      = absint($data['update_id'] ?? 0);
        $source         = sanitize_text_field($data['source'] ?? 'public');
        $external_id    = sanitize_text_field($data['external_id'] ?? '');
        $parent_token   = sanitize_text_field($data['parent_token'] ?? '');
        $bypass_past    = (bool) ($data['bypass_past'] ?? false);

        // Resolve room_id from type_id if it's 0 (category booking)
        if (0 === $room_id && 0 !== $type_id && '' !== $check_in && '' !== $check_out) {
            $resolved_room = Pricing::find_available_room($type_id, $check_in, $check_out, $guests);
            if ($resolved_room) {
                $room_id = $resolved_room;
            }
        }

        if (0 === $room_id || '' === $check_in || '' === $check_out || '' === $customer_name || '' === $customer_email || '' === $customer_phone) {
            return new \WP_Error('mhbo_missing_fields', I18n::get_label('label_fill_all_fields'));
        }

        // 2. Input Validation
        if (mb_strlen($customer_name) > 100) {
            return new \WP_Error('mhbo_name_too_long', I18n::get_label('label_name_too_long'));
        }
        if (mb_strlen($customer_phone) > 30) {
            return new \WP_Error('mhbo_phone_too_long', I18n::get_label('label_phone_too_long'));
        }

        if (!$bypass_past) {
            $today = wp_date('Y-m-d');
            if ($check_in < $today) {
                return new \WP_Error('mhbo_past_date', I18n::get_label('label_check_in_past'));
            }
        }
        if ($check_out <= $check_in) {
            return new \WP_Error('mhbo_invalid_range', I18n::get_label('label_check_out_after'));
        }

        // 3. Pro Features Check
        $is_pro_active = false;

$children      = 0;
        $child_ages    = [];
        $extras_input  = [];
        $payment_type  = 'full';

// 4. Room & Availability Logic
        if (!$bypass_lock && !Pricing::acquire_booking_lock($room_id, 10)) {
            return new \WP_Error('mhbo_lock_failed', I18n::get_label('label_booking_busy'));
        }

        try {
            $availability = Pricing::is_room_available($room_id, $check_in, $check_out, $update_id);
            if (true !== $availability) {
                $label = is_string($availability) ? $availability : 'label_already_booked';
                return new \WP_Error('mhbo_unavailable', I18n::get_label($label));
            }

            // 4. Pricing Calculation
            $calc = Pricing::calculate_booking_money($room_id, $check_in, $check_out, $guests, $extras_input, $children, $child_ages);
            if (!$calc) {
                return new \WP_Error('mhbo_pricing_failed', I18n::get_label('label_price_calc_error'));
            }

            $total = $calc['total'];

            // 5a. Determine privilege level here so the price-override guard below can use it.
            $privileged_sources = ['admin', 'ical', 'airbnb', 'booking_com'];
            $is_privileged      = in_array($source, $privileged_sources, true);

            // Allow manual price override ONLY for privileged/internal sources (admin, iCal, OTA).
            // 2026 BP: Public bookings MUST always use server-recalculated pricing to prevent
            // URL/POST price tampering.
            if ( $is_privileged && isset( $data['total_price'] ) ) {
                $total = Money::fromDecimal( (string) $data['total_price'], Pricing::get_currency_code() );
            }
            $booking_extras = $calc['extras_breakdown'] ?? [];
            $tax_data = $calc['tax'] ?? null;

$charge_amount = $total;

// 5. Payment Verification
            // Only privileged internal sources may preset status/payment_status.
            $status = $is_privileged ? sanitize_key($data['status'] ?? 'pending') : 'pending';
            $payment_status = $is_privileged ? sanitize_key($data['payment_status'] ?? 'pending') : 'pending';
            $payment_received = ( $is_privileged && (bool) ( $data['payment_received'] ?? false ) ) ? 1 : 0;
            $transaction_id = sanitize_text_field($data['transaction_id'] ?? '');
            $capture_id = sanitize_text_field($data['capture_id'] ?? '');
            $payment_date = isset($data['payment_date']) ? sanitize_text_field($data['payment_date']) : null;
            if ($payment_received && !$payment_date) {
                $payment_date = current_time('mysql');
            }

// 5. Custom Fields & GDPR
            $custom_data = [];
            $custom_fields_defn = get_option('mhbo_custom_fields', []);
            if (is_array($custom_fields_defn) && [] !== $custom_fields_defn) {
                $post_custom = $data['custom_fields'] ?? [];
                foreach ($custom_fields_defn as $defn) {
                    $field_id = $defn['id'] ?? '';
                    if (!$field_id) continue;

                    $val = sanitize_textarea_field((string)($post_custom[$field_id] ?? ''));
                    
                    if ( (bool) ( $defn['required'] ?? false ) && '' === $val ) {
                        $label = I18n::decode(I18n::encode($defn['label'] ?? $field_id));
                        return new \WP_Error('mhbo_field_required', sprintf(I18n::get_label('label_field_required'), $label));
                    }

                    if ('' !== $val) {
                        $custom_data[$field_id] = $val;
                    }
                }
            }

// 6. Database Insertion
            $insert_data = [
                'room_id'                => $room_id,
                'customer_name'          => $customer_name,
                'customer_email'         => $customer_email,
                'customer_phone'         => $customer_phone,
                'check_in'               => $check_in,
                'check_out'              => $check_out,
                'total_price'            => (string) $total->toDecimal(),
                'status'                 => $status,
                'booking_token'          => wp_generate_password(32, false, false),
                'booking_language'       => $language,
                'payment_method'         => $payment_method,
                'payment_received'       => $payment_received,
                'payment_status'         => $payment_status,
                'payment_transaction_id' => $transaction_id ?: null,
                'payment_date'           => $payment_date,
                'payment_amount'         => $payment_received ? (string)$charge_amount->toDecimal() : null,
                'guests'                 => $guests,
                'children'               => $children,
                'children_ages'          => $children > 0 ? wp_json_encode($child_ages) : null,
                'admin_notes'            => sanitize_textarea_field($data['admin_notes'] ?? ''),
                'custom_fields'          => [] !== $custom_data ? wp_json_encode($custom_data) : null,
                'source'                 => $source,
                'external_id'            => $external_id ?: null,
                'ical_uid'               => $source === 'ical' ? $external_id : null,
            ];

            $insert_format = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s'];

if ($update_id > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated via Cache::invalidate_booking() below.
                $wpdb->update("{$wpdb->prefix}mhbo_bookings", $insert_data, ['id' => $update_id], $insert_format, ['%d']);
                $booking_id = $update_id;
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert; cache invalidated via Cache::invalidate_booking() below.
                $wpdb->insert("{$wpdb->prefix}mhbo_bookings", $insert_data, $insert_format);
                $booking_id = $wpdb->insert_id;
            }

            if (!$booking_id) {
                return new \WP_Error('mhbo_insert_failed', I18n::get_label('label_booking_error'));
            }

            // 7. Post-Processing
            Cache::invalidate_booking($booking_id, $room_id);

// Clean up transients
            if (isset($data['stripe_pi'])) {
                delete_transient('mhbo_pi_amount_' . $data['stripe_pi']);
                delete_transient('mhbo_pi_params_' . $data['stripe_pi']);
            }

            // Hooks
            do_action('mhbo_booking_created', $booking_id);
            if ('confirmed' === $status) {
                do_action('mhbo_booking_confirmed', $booking_id);
            }

            // 8. Prepare Success Response
            $success_nonce = wp_create_nonce('mhbo_success_display');
            $token = $insert_data['booking_token'];
            
            $success_url = add_query_arg([
                'mhbo_success'       => 1,
                'mhbo_success_nonce' => $success_nonce,
                'mhbo_status'        => $status,
                'reference'          => $token,
            ], remove_query_arg(['mhbo_auto_book', 'mhbo_nonce', 'mhbo_confirm_booking'], $data['page_url'] ?? home_url('/')));

            return [
                'booking_id'    => $booking_id,
                'status'        => $status,
                'token'         => $token,
                'redirect_url'  => $success_url,
                'message'       => I18n::get_label('label_booking_success'),
            ];

        } catch (\Throwable $e) {
            return new \WP_Error('mhbo_exception', $e->getMessage());
        } finally {
            // 2026 BP: Zero-Leak guarantee — lock released unless caller owns it (bypass_lock).
            if (!$bypass_lock) {
                Pricing::release_booking_lock($room_id);
            }
        }
    }
}
