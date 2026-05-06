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
/* BUILD_PRO_START */
use MHBO\Pro\PaymentGateways;
/* BUILD_PRO_END */

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
        /* BUILD_PRO_START */
        $is_pro_active = License::is_pro_active();
        /* BUILD_PRO_END */

        $children      = 0;
        $child_ages    = [];
        $extras_input  = [];
        $payment_type  = 'full';

        /* BUILD_PRO_START */
        if ($is_pro_active) {
            $children      = absint($data['children'] ?? 0);
            $child_ages    = is_array($data['child_ages'] ?? []) ? array_map('absint', $data['child_ages']) : [];
            $extras_input  = is_array($data['extras'] ?? []) ? array_map('sanitize_text_field', $data['extras']) : [];
            $payment_type  = sanitize_key($data['payment_type'] ?? 'full');

            // Max children validation
            $room_obj = Pricing::get_room_pricing_data($room_id);
            if ($room_obj) {
                if ($children > (int)$room_obj->max_children) {
                    return new \WP_Error('mhbo_max_children', sprintf(I18n::get_label('label_max_children_error'), $room_obj->max_children));
                }
                if ($guests > (int)$room_obj->max_adults) {
                    return new \WP_Error('mhbo_max_adults', sprintf(I18n::get_label('label_max_adults_error'), $room_obj->max_adults));
                }
            }

            // Stay Restrictions
            if (class_exists('MHBO\Pro\AdminCalendar')) {
                $dt_in  = new \DateTime($check_in);
                $dt_out = new \DateTime($check_out);
                $nights = (int) $dt_in->diff($dt_out)->format('%a');
                
                $min_stay = \MHBO\Pro\AdminCalendar::resolve_min_stay($room_id, $check_in);
                $max_stay = \MHBO\Pro\AdminCalendar::resolve_max_stay($room_id, $check_in);

                if (null !== $min_stay && $nights < $min_stay) {
                    return new \WP_Error('mhbo_min_stay', sprintf(I18n::get_label('api_err_min_stay'), $min_stay));
                }
                if (null !== $max_stay && $nights > $max_stay) {
                    return new \WP_Error('mhbo_max_stay', sprintf(I18n::get_label('api_err_max_stay'), $max_stay));
                }
            }
        }
        /* BUILD_PRO_END */

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

            /* BUILD_PRO_START */
            // Coupon application — server-side validation only; client-submitted discount_amount is never trusted.
            $coupon_code     = '';
            $coupon_discount = Money::fromCents(0, $total->getCurrency());
            if ($is_pro_active && class_exists(\MHBO\Pro\CouponManager::class) && !$is_privileged) {
                $raw_coupon_code = sanitize_text_field($data['mhbo_coupon_code'] ?? $data['mhbo_coupon_applied'] ?? '');
                if ('' !== $raw_coupon_code && (bool)(int)get_option('mhbo_coupons_enabled', 1)) {
                    $room_obj_for_coupon  = Pricing::get_room_pricing_data($room_id);
                    $room_type_id_coupon  = $room_obj_for_coupon ? (int)$room_obj_for_coupon->type_id : 0;
                    $coupon_result        = \MHBO\Pro\CouponManager::validate(
                        $raw_coupon_code,
                        $total,
                        $room_id,
                        $room_type_id_coupon,
                        $customer_email
                    );
                    if (is_wp_error($coupon_result)) {
                        return $coupon_result;
                    }
                    $coupon_code     = strtoupper($raw_coupon_code);
                    $coupon_discount = \MHBO\Pro\CouponManager::calculate_discount($coupon_result, $total);
                    // Apply coupon to room first; recalculate service fee on discounted base.
                    $bp_recalc = Tax::recalculate_with_coupon($calc, $coupon_discount, $coupon_code, $total->getCurrency());
                    $tax_data           = $bp_recalc['tax'];
                    $calc['service_fee'] = $bp_recalc['service_fee'];
                    $total              = $bp_recalc['total'];
                }
            }
            /* BUILD_PRO_END */

            $charge_amount = $total;

            /* BUILD_PRO_START */
            $deposit_data = null;
            if ($is_pro_active && get_option('mhbo_deposits_enabled', 0)) {
                $currency = $total->getCurrency();
                $fn_type = (string) get_option('mhbo_deposit_type', 'percentage');
                $fn_end = gmdate('Y-m-d', strtotime($check_in . ' +1 day'));
                $fn_extras = ('first_night' === $fn_type) ? [] : $extras_input;
                $fn_children = ('first_night' === $fn_type) ? 0 : $children;
                $fn_ages = ('first_night' === $fn_type) ? [] : $child_ages;
                
                $fn_calc = Pricing::calculate_booking_money($room_id, $check_in, $fn_end, $guests, $fn_extras, $fn_children, $fn_ages);
                $fn_money = (is_array($fn_calc) && isset($fn_calc['total'])) ? $fn_calc['total'] : Money::fromCents(0, $currency);
                
                $deposit_data = Pricing::calculate_deposit_money($total, $fn_money);
                if ('deposit' === $payment_type && $deposit_data) {
                    $charge_amount = $deposit_data['deposit_money'];
                } else {
                    $payment_type = 'full';
                }
            }
            /* BUILD_PRO_END */

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

            /* BUILD_PRO_START */
            if ($is_pro_active && 'arrival' !== $payment_method && class_exists(PaymentGateways::class)) {
                $gateway = new PaymentGateways();
                
                if ('stripe' === $payment_method) {
                    $stripe_pi = sanitize_text_field($data['stripe_pi'] ?? '');
                    if ('' === $stripe_pi) {
                        return new \WP_Error('mhbo_stripe_missing', I18n::get_label('label_stripe_intent_missing'));
                    }

                    if ($gateway->verify_stripe_payment_intent($stripe_pi, $charge_amount)) {
                        $status = 'confirmed';
                        $payment_status = 'completed';
                        $payment_received = 1;
                        $transaction_id = $stripe_pi;
                        $payment_date = current_time('mysql');
                    } else {
                        return new \WP_Error('mhbo_payment_failed', I18n::get_label('label_payment_failed'));
                    }
                } elseif ('paypal' === $payment_method) {
                    $paypal_id = sanitize_text_field($data['paypal_order_id'] ?? '');
                    if ('' === $paypal_id) {
                        return new \WP_Error('mhbo_paypal_missing', I18n::get_label('label_paypal_id_missing'));
                    }

                    if ($gateway->verify_paypal_order($paypal_id, $charge_amount)) {
                        $status = 'confirmed';
                        $payment_status = 'completed';
                        $payment_received = 1;
                        $transaction_id = $paypal_id;
                        $capture_id = sanitize_text_field($data['paypal_capture_id'] ?? '');
                        $payment_date = current_time('mysql');
                    } else {
                        return new \WP_Error('mhbo_payment_failed', I18n::get_label('label_payment_failed'));
                    }
                }
            }
            /* BUILD_PRO_END */

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

            /* BUILD_PRO_START */
            if ($is_pro_active && get_option('mhbo_gdpr_enabled', 0) && get_option('mhbo_gdpr_checkbox_enabled', 0)) {
                if ( ! (bool) ( $data['consent'] ?? false ) ) {
                    return new \WP_Error('mhbo_gdpr_required', I18n::get_label('msg_gdpr_required'));
                }
            }
            /* BUILD_PRO_END */

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

            /* BUILD_PRO_START */
            if ($is_pro_active) {
                if ('' !== $coupon_code) {
                    $insert_data['coupon_code']     = $coupon_code;
                    $insert_data['coupon_discount'] = $coupon_discount->toDecimal();
                    $insert_data['discount_amount'] = $coupon_discount->toDecimal();
                    array_push($insert_format, '%s', '%s', '%s');
                }

                $insert_data['booking_extras'] = [] !== $booking_extras ? wp_json_encode($booking_extras) : null;
                $insert_format[] = '%s';

                // Service fee amount (raw pre-tax gross, stored for audit / display on bookings without tax_breakdown JSON)
                $service_fee_money = isset($calc['service_fee']) ? $calc['service_fee'] : Money::fromCents(0, Pricing::get_currency_code());
                if ($service_fee_money->isPositive()) {
                    $insert_data['service_fee_amount'] = $service_fee_money->toDecimal();
                    $insert_format[] = '%s';
                }

                $insert_data['payment_type']      = $payment_type;
                $insert_data['payment_capture_id'] = $capture_id ?: null;
                $insert_format[] = '%s';
                $insert_format[] = '%s';

                if ($tax_data) {
                    $insert_data['tax_enabled'] = 1;
                    $insert_data['tax_mode']    = sanitize_key($tax_data['mode'] ?? 'disabled');
                    $insert_data['total_tax']   = (string)($tax_data['totals']['total_tax'] ?? '0.00');
                    $insert_data['subtotal_net'] = (string)($tax_data['totals']['subtotal_net'] ?? $total->toDecimal());
                    $insert_data['tax_breakdown'] = wp_json_encode($tax_data);
                    
                    array_push($insert_format, '%d', '%s', '%s', '%s', '%s');
                }

                if ($deposit_data && 'deposit' === $payment_type) {
                    $insert_data['deposit_amount']    = $deposit_data['deposit_money']->toDecimal();
                    $insert_data['remaining_balance'] = $deposit_data['remaining_money']->toDecimal();
                    $insert_data['balance_status']    = 'pending';
                    array_push($insert_format, '%s', '%s', '%s');
                }

                if ( '' !== $parent_token || (bool) ($data['is_multi_room'] ?? false) ) {
                    $insert_data['is_multi_room']      = 1;
                    $insert_data['multi_room_parent'] = $parent_token ?: $insert_data['booking_token'];
                    array_push($insert_format, '%d', '%s');
                }
            }
            /* BUILD_PRO_END */

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

            /* BUILD_PRO_START */
            // Increment coupon uses_count atomically after confirmed DB insert.
            if ($is_pro_active && '' !== $coupon_code && class_exists(\MHBO\Pro\CouponManager::class)) {
                $coupon_row_for_inc = \MHBO\Pro\CouponManager::get_by_code($coupon_code);
                if ($coupon_row_for_inc) {
                    \MHBO\Pro\CouponManager::increment_uses((int)$coupon_row_for_inc->id);
                }
            }
            /* BUILD_PRO_END */

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
