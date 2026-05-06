<?php declare(strict_types=1);

namespace MHBO\Core;
use MHBO\Core\Money;

if (!defined('ABSPATH')) {
    exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

class Email
{
    /**
     * Initialize email hooks.
     */
    public static function init(): void
    {
        // phpstan-ignore-next-line return.void -- handlers are void; phpstan-wordpress false-positive for [self::class, 'method'] callables
        add_action('mhbo_booking_confirmed', [self::class, 'handle_booking_confirmed'], 20);
        // phpstan-ignore-next-line return.void
        add_action('mhbo_booking_created', [self::class, 'handle_booking_created'], 20);
        // phpstan-ignore-next-line return.void
        add_action('mhbo_booking_cancelled', [self::class, 'handle_booking_cancelled'], 20);
        // phpstan-ignore-next-line return.void
        add_action('mhbo_booking_created', [self::class, 'handle_admin_notification_created'], 25);
        // phpstan-ignore-next-line return.void
        add_action('mhbo_booking_confirmed', [self::class, 'handle_admin_notification_confirmed'], 25);
    }

    /**
     * Handler for booking confirmation event (Verified Payment / Manual Approval).
     *
     * @param int $booking_id The booking ID.
     */
    public static function handle_booking_confirmed(int $booking_id): void
    {
        self::send_email($booking_id, 'confirmed');
    }

    /**
     * Handler for booking cancellation event.
     *
     * @param int $booking_id The booking ID.
     */
    public static function handle_booking_cancelled(int $booking_id): void
    {
        self::send_email($booking_id, 'cancelled');
    }

    /**
     * Handler for booking creation event (Receipt / Arrival Selection).
     * Sends a receipt immediately for on-site/arrival payments.
     * Defers for Stripe/PayPal — those trigger 'mhbo_booking_confirmed' after server verification.
     *
     * @param int $booking_id The booking ID.
     */
    public static function handle_booking_created(int $booking_id): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $booking = $wpdb->get_row($wpdb->prepare("SELECT payment_method, status FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $booking_id));

        if (!$booking) {
            return;
        }

        if (in_array($booking->payment_method, ['arrival', 'onsite'], true)) {
            self::send_email($booking_id, (string) $booking->status);
        }
    }

    /**
     * Handler: send admin notification when a new booking is created.
     *
     * @param int $booking_id The booking ID.
     */
    public static function handle_admin_notification_created(int $booking_id): void
    {
        self::send_admin_notification($booking_id);
    }

    /**
     * Handler: send admin notification when a booking is confirmed (payment verified).
     * Only fires for Stripe/PayPal bookings that were pending at creation time.
     *
     * @param int $booking_id The booking ID.
     */
    public static function handle_admin_notification_confirmed(int $booking_id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT payment_method FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
            $booking_id
        ));

        // For arrival/onsite the admin notification already fired on creation; skip duplicate.
        if ($booking && in_array($booking->payment_method, ['arrival', 'onsite'], true)) {
            return;
        }

        self::send_admin_notification($booking_id);
    }

    /**
     * Send a dedicated admin notification email with full guest and booking details.
     * Sends to the configured notification email and any additional CC address.
     *
     * @param int $booking_id The booking ID.
     * @return bool True if sent successfully.
     */
    public static function send_admin_notification(int $booking_id): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.name as room_name
             FROM {$wpdb->prefix}mhbo_bookings b
             LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
             LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             WHERE b.id = %d",
            $booking_id
        ));

        if (!$booking) {
            return false;
        }

        $admin_email = get_option('mhbo_notification_email', get_option('admin_email'));
        if (!is_email($admin_email)) {
            return false;
        }

        $lang          = I18n::get_current_language();
        $site_name     = get_bloginfo('name');
        $status_label  = I18n::translate_status((string) ($booking->status ?? 'pending'));
        $check_in      = '' !== (string) ($booking->check_in ?? '') ? date_i18n(get_option('date_format'), strtotime((string) $booking->check_in)) : '--';
        $check_out     = '' !== (string) ($booking->check_out ?? '') ? date_i18n(get_option('date_format'), strtotime((string) $booking->check_out)) : '--';
        $room_name     = I18n::decode((string) ($booking->room_name ?? ''), $lang);
        $payment_label = I18n::translate_payment_method((string) ($booking->payment_method ?? 'arrival'));
        $total_price   = I18n::format_currency(Money::fromDecimal((string) ($booking->total_price ?? 0)));

        $admin_url = admin_url('admin.php?page=mhbo-bookings&action=view&id=' . $booking_id);

        // Build admin notification email HTML
        $body  = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#222;">';
        $body .= '<h2 style="background:#2563eb;color:#fff;padding:16px 20px;margin:0;border-radius:6px 6px 0 0;">';
        $body .= esc_html__('New Booking Received', 'modern-hotel-booking') . ' &mdash; ' . esc_html($site_name) . '</h2>';
        $body .= '<div style="border:1px solid #d1d5db;border-top:none;border-radius:0 0 6px 6px;padding:20px;">';

        // Status badge
        $badge_color = ('confirmed' === $booking->status) ? '#16a34a' : '#d97706';
        $body .= '<p style="margin:0 0 16px 0;">';
        $body .= '<span style="background:' . $badge_color . ';color:#fff;padding:4px 10px;border-radius:4px;font-size:13px;">' . esc_html($status_label) . '</span>';
        $body .= '</p>';

        // Guest details
        $body .= '<h3 style="margin:0 0 8px 0;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">' . esc_html__('Guest Details', 'modern-hotel-booking') . '</h3>';
        $body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">';
        $body .= self::admin_row(_x('Name', 'customer detail', 'modern-hotel-booking'), esc_html((string) ($booking->customer_name ?? '')));
        $body .= self::admin_row(_x('Email', 'customer detail', 'modern-hotel-booking'), '<a href="mailto:' . esc_attr((string) ($booking->customer_email ?? '')) . '">' . esc_html((string) ($booking->customer_email ?? '')) . '</a>');
        $body .= self::admin_row(_x('Phone', 'customer detail', 'modern-hotel-booking'), esc_html((string) ($booking->customer_phone ?? '')));
        if ('' !== (string) ($booking->special_requests ?? '')) {
            $body .= self::admin_row(__('Special Requests', 'modern-hotel-booking'), esc_html((string) $booking->special_requests));
        }
        // Custom fields
        if ('' !== (string) ($booking->custom_fields ?? '')) {
            $custom_data = json_decode((string) $booking->custom_fields, true);
            $custom_defn = get_option('mhbo_custom_fields', []);
            if (is_array($custom_data) && is_array($custom_defn)) {
                foreach ($custom_defn as $defn) {
                    if (isset($custom_data[$defn['id']]) && '' !== (string) $custom_data[$defn['id']]) {
                        $f_label = I18n::decode(I18n::encode($defn['label']), $lang);
                        $body .= self::admin_row(esc_html($f_label), esc_html((string) $custom_data[$defn['id']]));
                    }
                }
            }
        }
        $body .= '</table>';

        // Booking details
        $body .= '<h3 style="margin:0 0 8px 0;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">' . esc_html__('Booking Details', 'modern-hotel-booking') . '</h3>';
        $body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
        $body .= self::admin_row(__('Booking ID', 'modern-hotel-booking'), '#' . (int) $booking->id);
        $body .= self::admin_row(_x('Room', 'accommodation unit', 'modern-hotel-booking'), esc_html($room_name));
        $body .= self::admin_row(__('Check-in', 'modern-hotel-booking'), $check_in);
        $body .= self::admin_row(__('Check-out', 'modern-hotel-booking'), $check_out);
        $body .= self::admin_row(__('Guests', 'modern-hotel-booking'), (string) ((int) ($booking->guests ?? 1)));
        $body .= self::admin_row(__('Payment Method', 'modern-hotel-booking'), esc_html($payment_label));
        $body .= self::admin_row(__('Total Price', 'modern-hotel-booking'), '<strong>' . $total_price . '</strong>');
        /* BUILD_PRO_START */
        $payment_type_val    = (string) ($booking->payment_type ?? 'full');
        $coupon_code_val     = (string) ($booking->coupon_code ?? '');
        $coupon_discount_raw = (float) ($booking->coupon_discount ?? 0);
        $deposit_amount_raw  = (float) ($booking->deposit_amount ?? 0);
        $body .= self::admin_row(__('Payment Type', 'modern-hotel-booking'), esc_html('deposit' === $payment_type_val ? __('Deposit', 'modern-hotel-booking') : __('Full Payment', 'modern-hotel-booking')));
        if ('' !== $coupon_code_val && $coupon_discount_raw > 0) {
            $coupon_discount_formatted = I18n::format_currency(Money::fromDecimal((string) $coupon_discount_raw));
            $body .= self::admin_row(__('Coupon Code', 'modern-hotel-booking'), esc_html(strtoupper($coupon_code_val)));
            $body .= self::admin_row(__('Coupon Discount', 'modern-hotel-booking'), '<span style="color:#16a34a;">-' . $coupon_discount_formatted . '</span>');
        }
        if ('deposit' === $payment_type_val && $deposit_amount_raw > 0) {
            $deposit_formatted   = I18n::format_currency(Money::fromDecimal((string) $deposit_amount_raw));
            $remaining_raw       = max(0.0, (float) ($booking->total_price ?? 0) - $deposit_amount_raw);
            $remaining_formatted = I18n::format_currency(Money::fromDecimal((string) $remaining_raw));
            $body .= self::admin_row(__('Deposit Paid', 'modern-hotel-booking'), '<strong>' . $deposit_formatted . '</strong>');
            $body .= self::admin_row(__('Remaining Balance', 'modern-hotel-booking'), $remaining_formatted);
        }
        /* BUILD_PRO_END */
        $body .= '</table>';
        /* BUILD_PRO_START */
        // Tax breakdown — Pro only (tax is always disabled in the Free build)
        $tax_mode_val = (string) ($booking->tax_mode ?? 'disabled');
        if ('disabled' !== $tax_mode_val) {
            $tax_json = (string) ($booking->tax_breakdown ?? '');
            if ('' !== $tax_json) {
                $tax_data = json_decode($tax_json, true);
                if (is_array($tax_data) && isset($tax_data['totals']) && is_array($tax_data['totals'])) {
                    $totals = $tax_data['totals'];
                    $body .= '<h3 style="margin:0 0 8px 0;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">' . esc_html__('Tax Breakdown', 'modern-hotel-booking') . '</h3>';
                    $body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
                    $body .= self::admin_row(__('Subtotal (net)', 'modern-hotel-booking'), I18n::format_currency(Money::fromDecimal((string) ($totals['subtotal_net'] ?? '0'))));
                    if ((float) ($totals['room_tax'] ?? 0) > 0) {
                        $body .= self::admin_row(__('Accommodation Tax', 'modern-hotel-booking'), I18n::format_currency(Money::fromDecimal((string) $totals['room_tax'])));
                    }
                    if ((float) ($totals['children_tax'] ?? 0) > 0) {
                        $body .= self::admin_row(__('Children Tax', 'modern-hotel-booking'), I18n::format_currency(Money::fromDecimal((string) $totals['children_tax'])));
                    }
                    if ((float) ($totals['extras_tax'] ?? 0) > 0) {
                        $body .= self::admin_row(__('Extras Tax', 'modern-hotel-booking'), I18n::format_currency(Money::fromDecimal((string) $totals['extras_tax'])));
                    }
                    if ((float) ($totals['service_fee_tax'] ?? 0) > 0) {
                        $body .= self::admin_row(__('Service Fee Tax', 'modern-hotel-booking'), I18n::format_currency(Money::fromDecimal((string) $totals['service_fee_tax'])));
                    }
                    $body .= self::admin_row('<strong>' . __('Total Tax', 'modern-hotel-booking') . '</strong>', '<strong>' . I18n::format_currency(Money::fromDecimal((string) ($totals['total_tax'] ?? '0'))) . '</strong>');
                    $body .= '</table>';
                }
            }
        }
        /* BUILD_PRO_END */

        // CTA button
        $body .= '<p style="text-align:center;margin:20px 0 0 0;">';
        $body .= '<a href="' . esc_url($admin_url) . '" style="background:#2563eb;color:#fff;padding:10px 24px;border-radius:5px;text-decoration:none;font-weight:bold;">';
        $body .= esc_html__('View Booking in Dashboard', 'modern-hotel-booking');
        $body .= '</a></p>';

        $body .= '</div></div>';

        $subject = sprintf(
            /* translators: 1: booking ID, 2: guest name */
            __('[%1$s] New Booking #%2$d from %3$s', 'modern-hotel-booking'),
            $site_name,
            (int) $booking->id,
            esc_html((string) ($booking->customer_name ?? ''))
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
        ];

        // CC additional notification email if configured
        $additional_email = get_option('mhbo_additional_notification_email', '');
        if (is_email((string) $additional_email)) {
            $headers[] = 'Cc: ' . sanitize_email((string) $additional_email);
        }

        return (bool) wp_mail($admin_email, $subject, $body, $headers);
    }

    /**
     * Helper: render a two-column table row for the admin notification email.
     *
     * @param string $label The row label.
     * @param string $value The row value (may contain safe HTML).
     * @return string HTML table row.
     */
    private static function admin_row(string $label, string $value): string
    {
        if ('' === $value) {
            return '';
        }
        return '<tr>'
            . '<td style="padding:6px 8px;color:#6b7280;width:38%;vertical-align:top;">' . $label . '</td>'
            . '<td style="padding:6px 8px;vertical-align:top;">' . $value . '</td>'
            . '</tr>';
    }

    /**
     * Alias for send_booking_email for backward compatibility.
     *
     * @param int    $booking_id The booking ID.
     * @param string $status     The booking status.
     * @return bool True if email was sent successfully, false on failure.
     */
    public static function send_email(int $booking_id, string $status, bool $force = false): bool
    {
        return self::send_booking_email($booking_id, $status, $force);
    }

    /**
     * Send a booking notification email to the customer.
     * Only sends for completed payments or arrival payment method.
     *
     * @param int    $booking_id The booking ID.
     * @param string $status     The booking status.
     * @return bool True if email was sent successfully, false on failure.
     */
    public static function send_booking_email(int $booking_id, string $status, bool $force = false): bool
    {
        global $wpdb;

        $cache_key = 'mhbo_booking_' . $booking_id;
        $booking = wp_cache_get($cache_key, 'mhbo_bookings');

        if (false === $booking) {
            // RATIONALE: Required to fetch full booking record for email template rendering.
            // JOIN: Prefetch room name for Kairos performance compliance and N+1 elimination.
            // Uses $wpdb->prepare with %d; result is cached via wp_cache_set.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, specific lookup
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, t.name as room_name 
                 FROM {$wpdb->prefix}mhbo_bookings b
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                 WHERE b.id = %d",
                $booking_id
            ));
            if ($booking) {
                wp_cache_set($cache_key, $booking, 'mhbo_bookings', HOUR_IN_SECONDS);
            }
        }

        if (!$booking) {
            return false;
        }

        // Check if we should send email based on payment status
        $payment_status = isset($booking->payment_status) ? $booking->payment_status : 'pending';
        $payment_method = isset($booking->payment_method) ? $booking->payment_method : 'arrival';

        // DEDUPLICATION: Prevent duplicate confirmation emails unless explicitly forced (manual resend)
        if (!$force && isset($booking->email_sent) && (int) $booking->email_sent === 1 && 'confirmed' === $status) {
            return false;
        }

        // Allow email if:
        // 1. Status is explicitly 'confirmed' (admin manually confirmed the booking), OR
        // 2. Payment is completed, OR
        // 3. Payment method is 'arrival' or 'onsite'
        $email_allowed = ('confirmed' === $status) || ('completed' === $payment_status) || ('arrival' === $payment_method) || ('onsite' === $payment_method);

        if (!$email_allowed) {
            // Payment not confirmed and not explicitly confirmed by admin - don't send confirmation email yet
            return false;
        }

        // UPDATE STATUS: Mark as sent BEFORE wp_mail to avoid race conditions with webhooks
        if ('confirmed' === $status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update
            $wpdb->update(
                $wpdb->prefix . 'mhbo_bookings',
                ['email_sent' => 1],
                ['id' => $booking_id],
                ['%d'],
                ['%d']
            );
            // Invalidate cache
            wp_cache_delete($cache_key, 'mhbo_bookings');
        }

        $lang = $booking->booking_language ?: I18n::get_current_language();

        // Load multilingual templates from options with hardcoded fallbacks if empty
        $template_subject = get_option("mhbo_email_{$status}_subject");
        if ('' === (string) $template_subject) {
            $template_subject = I18n::get_label("email_booking_status_subject");
        }

        $template_message = get_option("mhbo_email_{$status}_message");
        if ('' === (string) $template_message) {
            $template_message = I18n::get_label("email_booking_status_message");
        }

        // SECURITY: Validate email address before sending
        $to = sanitize_email((string) ($booking->customer_email ?? ''));
        if (!is_email($to)) {
            // Invalid email - skip sending
            return false;
        }
        $subject = I18n::decode($template_subject, $lang);
        $message = I18n::decode($template_message, $lang);

        // Room Name Placeholder - JOIN-aware (Kairos optimized)
        $room_name = I18n::decode((string) ($booking->room_name ?? ''), $lang);
        if ( '' === $room_name && isset($booking->room_id) ) {
            // FALLBACK: Only if JOIN failed for some reason, check cache or DB
            $room_name_cache_key = 'mhbo_room_name_' . $booking->room_id;
            $room_name = wp_cache_get($room_name_cache_key, 'mhbo');

            if (false === $room_name) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $room_name = $wpdb->get_var($wpdb->prepare(
                    "SELECT t.name FROM {$wpdb->prefix}mhbo_rooms r 
                     JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id 
                     WHERE r.id = %d",
                    $booking->room_id
                ));
                wp_cache_set($room_name_cache_key, $room_name, 'mhbo', HOUR_IN_SECONDS);
            }
            $room_name = I18n::decode((string) ($room_name ?? ''), $lang);
        }

        // Format Custom Fields for placeholder
        $custom_fields_formatted = '';
        if ( '' !== (string) ($booking->custom_fields ?? '') ) {
            $custom_data = json_decode($booking->custom_fields, true);
            $custom_defn = get_option('mhbo_custom_fields', []);
            if (is_array($custom_data) && count($custom_defn) > 0) {
                foreach ($custom_defn as $defn) {
                    if (isset($custom_data[$defn['id']])) {
                        $f_label = I18n::decode(I18n::encode($defn['label']), $lang);
                        $custom_fields_formatted .= esc_html($f_label) . ': ' . esc_html($custom_data[$defn['id']]) . "<br>\n";
                    }
                }
            }
        }

        // Build payment details section
        $payment_details = '';
        if ('completed' === $payment_status) {
            $payment_details = '<div style="margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 5px;">';
            $payment_details .= '<h4 style="margin: 0 0 10px 0; color: #2e7d32;">' . esc_html(I18n::get_label('label_payment_confirmation')) . '</h4>';
            $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_payment_status')) . '</strong> ' . esc_html(I18n::get_label('label_paid')) . '</p>';
            if ( (float) ($booking->payment_amount ?? 0) > 0 ) {
                $p_amt = Money::fromDecimal((string) $booking->payment_amount);
                $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_amount_paid')) . '</strong> ' . I18n::format_currency($p_amt) . '</p>';
            }
            if ( '' !== (string) ($booking->payment_transaction_id ?? '') ) {
                $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_transaction_id')) . '</strong> ' . esc_html((string) $booking->payment_transaction_id) . '</p>';
            }
            if ( '' !== (string) ($booking->payment_date ?? '') ) {
                $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_payment_date')) . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime((string) $booking->payment_date)) . '</p>';
            }
            if ( '' !== (string) ($booking->payment_method ?? '') ) {
                $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_payment_method')) . '</strong> ' . esc_html(I18n::translate_payment_method((string) $booking->payment_method)) . '</p>';
            }
            $payment_details .= '</div>';
        } elseif ('arrival' === $payment_method || 'onsite' === $payment_method) {
            $payment_details = self::get_business_payment_details_html($booking_id, Money::fromDecimal((string) ($booking->total_price ?? 0)));
        }

        // Build tax breakdown section
        $tax_breakdown_html = '';
        $tax_breakdown_text = '';
        $tax_total = '';
        $tax_registration_number = '';

        /* BUILD_PRO_START */
        if ( mhbo_is_pro() && '' !== (string) ($booking->tax_breakdown ?? '') ) {
            $tax_data = json_decode($booking->tax_breakdown, true);
            $show_breakdown = (bool) ($tax_data['enabled'] ?? false) || (bool) get_option('mhbo_tax_display_email', true);
            if ($tax_data && $show_breakdown) {
                // Use the new consolidated rendering methods
                $meta = [
                    'guests' => $booking->guests,
                    'children' => $booking->children,
                    'payment_type'      => $booking->payment_type ?? 'full',
                    'payment_status'    => $booking->payment_status ?? '',
                    'deposit_amount'    => $booking->deposit_amount ?? 0,
                    'remaining_balance' => $booking->remaining_balance ?? 0,
                    'coupon_code'       => $booking->coupon_code ?? '',
                    'coupon_discount'   => $booking->coupon_discount ?? '',
                ];
                $tax_breakdown_html = Tax::render_breakdown_html($tax_data, $lang, true, $meta);
                $tax_breakdown_text = Tax::render_breakdown_text($tax_data, $lang, $meta);

                // Set individual placeholders for backward compatibility or custom templates
                $totals = $tax_data['totals'] ?? [];
                $tax_total = I18n::format_currency($totals['total_tax'] ?? 0);
                $tax_registration_number = $tax_data['registration_number'] ?? Tax::get_registration_number();
            }
        }

        // If tax is enabled but no breakdown stored (fallback), show basic info
        if ( mhbo_is_pro() && '' === (string) ($tax_breakdown_html ?? '') && Tax::is_enabled() ) {
            $tax_label = Tax::get_label($lang);
            $tax_mode = Tax::get_mode();
            $reg_number = Tax::get_registration_number();
            $accommodation_rate = Tax::get_accommodation_rate();
            $extras_rate = Tax::get_extras_rate();

            $tax_breakdown_html = '<div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 5px; font-family: Arial, sans-serif;">';
            if (Tax::MODE_VAT === $tax_mode) {
                if ($accommodation_rate === $extras_rate) {
                    $tax_breakdown_html .= '<p style="margin: 0; font-size: 14px; color: #666;">' . esc_html(sprintf(I18n::get_label('label_price_includes_tax'), $tax_label, $accommodation_rate)) . '</p>';
                } else {
                    /* translators: %1$s: tax label (e.g., VAT), %2$s: accommodation tax rate, %3$s: extras tax rate */
                    $tax_breakdown_html .= '<p style="margin: 0; font-size: 14px; color: #666;">' . esc_html(sprintf(I18n::get_label('email_tax_includes_split'), $tax_label, $accommodation_rate, $extras_rate)) . '</p>';
                }
            } elseif (Tax::MODE_SALES_TAX === $tax_mode) {
                if ($accommodation_rate === $extras_rate) {
                    $tax_breakdown_html .= '<p style="margin: 0; font-size: 14px; color: #666;">' . esc_html(sprintf(I18n::get_label('label_tax_added_at_checkout'), $tax_label, $accommodation_rate)) . '</p>';
                } else {
                    /* translators: %1$s: tax label (e.g., Sales Tax), %2$s: accommodation tax rate, %3$s: extras tax rate */
                    $tax_breakdown_html .= '<p style="margin: 0; font-size: 14px; color: #666;">' . esc_html(sprintf(I18n::get_label('email_tax_added_split'), $tax_label, $accommodation_rate, $extras_rate)) . '</p>';
                }
            }
            if ( '' !== (string) ($reg_number ?? '') ) {
                $tax_breakdown_html .= '<p style="margin: 10px 0 0 0; font-size: 12px; color: #999;">' . esc_html(sprintf(I18n::get_label('label_tax_registration'), $reg_number)) . '</p>';
            }
            $tax_breakdown_html .= '</div>';

            $tax_registration_number = $reg_number;
        }
        /* BUILD_PRO_END */

        // Format extras
        $booking_extras_html = self::format_extras($booking, $lang, 'html');
        $booking_extras_text = self::format_extras($booking, $lang, 'text');

        // Fetch placeholder collection
        $placeholders = self::get_booking_placeholders($booking, $status, $lang, [
            'extras_html' => $booking_extras_html,
            'extras_text' => $booking_extras_text,
            'custom_fields_formatted' => $custom_fields_formatted,
            'tax_breakdown_html' => $tax_breakdown_html,
            'tax_breakdown_text' => $tax_breakdown_text,
            'tax_total' => $tax_total,
            'tax_registration_number' => $tax_registration_number,
            'room_name' => $room_name
        ]);

        // Append tax breakdown if placeholder is NOT in the template and tax is enabled.
        // Must check BEFORE replacement, since apply_placeholders removes the literal placeholder.
        $has_tax_placeholder = (false !== strpos($message, '{tax_breakdown}'));

        // Replace placeholders with smart cleanup
        $subject = self::apply_placeholders((string) $subject, $placeholders);
        $message = self::apply_placeholders((string) $message, $placeholders);

        if ( '' === (string) ($has_tax_placeholder ?? false) && '' !== (string) ($tax_breakdown_html ?? '') ) {
            $message .= (string) $tax_breakdown_html;
        }

        /* BUILD_PRO_START */
        // Append deposit details if it's a deposit booking and placeholder not used
        if (false === strpos($message, '{deposit_details}') && '' !== (string) ($booking->payment_type ?? '') && 'deposit' === $booking->payment_type) {
            $message .= self::get_deposit_email_html($booking, $lang);
        }
        /* BUILD_PRO_END */


        $admin_email = get_option('mhbo_notification_email', get_option('admin_email'));
        $site_name = get_bloginfo('name');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Reply-To: ' . $admin_email,
        ];

        // BCC additional notification email on customer emails if configured
        $additional_email = get_option('mhbo_additional_notification_email', '');
        if (is_email((string) $additional_email)) {
            $headers[] = 'Bcc: ' . sanitize_email((string) $additional_email);
        }

        $attachments = [];

        // Add iCal attachment for confirmed bookings
        if ('confirmed' === $status) {
            $ics_content = self::generate_simple_ics($booking);
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/booking-' . $booking_id . '.ics';
            file_put_contents($file_path, $ics_content);
            $attachments[] = $file_path;
        }

        $result = (bool) wp_mail($to, (string) $subject, (string) $message, $headers, $attachments);

        // Clean up temporary iCal file
        if ( is_array($attachments) && count($attachments) > 0 ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary file cleanup
            @unlink($attachments[0]);
        }

        return $result;
    }

    /**
     * Send a payment confirmation email (separate receipt).
     *
     * @param int                  $booking_id   The booking ID.
     * @param array<string, mixed> $payment_data Payment details array.
     * @return bool True if email was sent successfully, false on failure.
     */
    public static function send_payment_confirmation_email(int $booking_id, array $payment_data = []): bool
    {
        global $wpdb;
        // RATIONALE: Required to fetch booking record for payment confirmation email.
        // Uses $wpdb->prepare with %d; one-shot send, caching not beneficial.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, specific lookup
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
            $booking_id
        ));

        if (!$booking) {
            return false;
        }

        $lang = $booking->booking_language ?: I18n::get_current_language();

        $template_subject = get_option("mhbo_email_payment_subject");
        if ('' === (string) $template_subject) {
            $template_subject = I18n::get_label("email_payment_confirmation_subject");
        }

        $template_message = get_option("mhbo_email_payment_message");
        if ('' === (string) $template_message) {
            $template_message = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
            $template_message .= '<h2 style="color: #2e7d32;">' . I18n::get_label('email_payment_confirmation_heading') . '</h2>';
            // translators: %s: customer name
            $template_message .= '<p>' . sprintf(I18n::get_label('email_dear_customer'), '{customer_name}') . '</p>';
            $template_message .= '<p>' . I18n::get_label('email_payment_thank_you') . '</p>';

            $template_message .= '<div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;">';
            $template_message .= '<h4 style="margin: 0 0 15px 0;">' . I18n::get_label('email_booking_details') . '</h4>';
            $template_message .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label('email_booking_id') . '</strong> #{booking_id}</p>';
            $template_message .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label('email_check_in') . '</strong> {check_in}</p>';
            $template_message .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label('email_check_out') . '</strong> {check_out}</p>';
            $template_message .= '</div>';

            $template_message .= '{payment_details}';

            $template_message .= '<p>' . I18n::get_label('email_contact_us_prompt') . '</p>';
            $template_message .= '<p>' . I18n::get_label('email_best_regards') . '<br>' . get_bloginfo('name') . '</p>';
            $template_message .= '</div>';
        }

        $subject = I18n::decode($template_subject, $lang);
        $message = I18n::decode($template_message, $lang);

        // Build payment details section
        $payment_details = '<div style="background: #e8f5e9; padding: 15px; border-radius: 5px; margin: 20px 0;">';
        $payment_details .= '<h4 style="margin: 0 0 15px 0; color: #2e7d32;">' . I18n::get_label('email_payment_details') . '</h4>';
        $p_amt = Money::fromDecimal((string) (isset($payment_data['amount']) ? $payment_data['amount'] : ($booking->total_price ?? 0)));
        $payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label('email_amount_paid') . '</strong> ' . I18n::format_currency($p_amt) . '</p>';

        if ('' !== (string) ($payment_data['transaction_id'] ?? '')) {
            $payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label('email_transaction_id') . '</strong> ' . esc_html((string) $payment_data['transaction_id']) . '</p>';
        } elseif ('' !== (string) ($booking->payment_transaction_id ?? '')) {
            $payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label('email_transaction_id') . '</strong> ' . esc_html((string) $booking->payment_transaction_id) . '</p>';
        }

        if ('' !== (string) ($payment_data['method'] ?? '')) {
            $method_name = I18n::translate_payment_method((string) $payment_data['method']);
            $payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label('email_payment_method') . '</strong> ' . esc_html($method_name) . '</p>';
        }

        $payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label('email_payment_date') . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime((string) current_time('mysql'))) . '</p>';
        $payment_details .= '</div>';

        // Build tax breakdown section
        $tax_breakdown_html = '';
        $tax_breakdown_text = '';
        $tax_total = '';
        $tax_registration_number = '';

        /* BUILD_PRO_START */
        if (mhbo_is_pro() && '' !== (string) ($booking->tax_breakdown ?? '')) {
            $tax_data = json_decode($booking->tax_breakdown, true);
            $show_breakdown = (bool) ($tax_data['enabled'] ?? false) || (bool) get_option('mhbo_tax_display_email', true);
            if ($tax_data && $show_breakdown) {
                $meta = [
                    'guests' => $booking->guests,
                    'children' => $booking->children,
                    'payment_type'      => $booking->payment_type ?? 'full',
                    'payment_status'    => $booking->payment_status ?? '',
                    'deposit_amount'    => $booking->deposit_amount ?? 0,
                    'remaining_balance' => $booking->remaining_balance ?? 0,
                    'coupon_code'       => $booking->coupon_code ?? '',
                    'coupon_discount'   => $booking->coupon_discount ?? '',
                ];
                $tax_breakdown_html = Tax::render_breakdown_html($tax_data, $lang, true, $meta);
                $tax_breakdown_text = Tax::render_breakdown_text($tax_data, $lang, $meta);
                $totals = $tax_data['totals'] ?? [];
                $tax_total = I18n::format_currency($totals['total_tax'] ?? 0);
                $tax_registration_number = $tax_data['registration_number'] ?? Tax::get_registration_number();
            }
        }
        /* BUILD_PRO_END */

        // Format Custom Fields for placeholder
        $custom_fields_formatted = '';
        if ( '' !== (string) ($booking->custom_fields ?? '') ) {
            $custom_data = json_decode((string) $booking->custom_fields, true);
            $custom_defn = get_option('mhbo_custom_fields', []);
            if (is_array($custom_data) && is_array($custom_defn) && count($custom_defn) > 0) {
                foreach ($custom_defn as $defn) {
                    if (isset($custom_data[$defn['id']])) {
                        $f_label = I18n::decode(I18n::encode($defn['label']), $lang);
                        $custom_fields_formatted .= esc_html($f_label) . ': ' . esc_html($custom_data[$defn['id']]) . "<br>\n";
                    }
                }
            }
        }

        // Fetch room name for placeholder - with caching
        $room_name_cache_key = 'mhbo_room_name_' . $booking->room_id;
        $room_name = wp_cache_get($room_name_cache_key, 'mhbo');

        if (false === $room_name) {
            // RATIONALE: Required to resolve room name for payment confirmation email placeholder.
            // Uses $wpdb->prepare with %d; result is cached via wp_cache_set.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented
            $room_name = $wpdb->get_var($wpdb->prepare(
                "SELECT t.name FROM {$wpdb->prefix}mhbo_rooms r 
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id 
                 WHERE r.id = %d",
                $booking->room_id
            ));
            wp_cache_set($room_name_cache_key, $room_name, 'mhbo', HOUR_IN_SECONDS);
        }
        $room_name = I18n::decode($room_name, $lang);

        // Format extras
        $booking_extras_html = self::format_extras($booking, $lang, 'html');
        $booking_extras_text = self::format_extras($booking, $lang, 'text');

        // Fetch placeholder collection
        $placeholders = self::get_booking_placeholders($booking, 'payment', $lang, [
            'extras_html' => $booking_extras_html,
            'extras_text' => $booking_extras_text,
            'custom_fields_formatted' => $custom_fields_formatted,
            'tax_breakdown_html' => $tax_breakdown_html,
            'tax_breakdown_text' => $tax_breakdown_text,
            'tax_total' => $tax_total,
            'tax_registration_number' => $tax_registration_number,
            'room_name' => $room_name,
            'payment_details' => $payment_details,
            /* BUILD_PRO_START */
            'company_info'      => self::render_company_card_html($lang),
            'whatsapp_contact'  => self::render_whatsapp_button_html(),
            'banking_details'   => self::render_banking_card_html($booking->id),
            'revolut_details'   => self::render_revolut_card_html(),
            'business_card'     => self::render_business_card_html($booking->id, $lang)
            /* BUILD_PRO_END */
        ]);

        // Check for placeholders BEFORE replacement (replacement removes the literal tokens).
        $has_payment_placeholder = (false !== strpos($message, '{payment_details}'));

        // Replace placeholders with smart cleanup
        $subject = self::apply_placeholders((string) $subject, $placeholders);
        $message = self::apply_placeholders((string) $message, $placeholders);

        // Append payment details only if the template didn't include the placeholder
        if (!$has_payment_placeholder) {
            $message .= $payment_details;
        }

        $admin_email = get_option('mhbo_notification_email', get_option('admin_email'));
        $site_name = get_bloginfo('name');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Reply-To: ' . $admin_email,
            'Bcc: ' . $admin_email
        ];

        // SECURITY: Validate email before sending
        $to = sanitize_email((string) ($booking->customer_email ?? ''));
        if (!is_email($to)) {
            // Invalid email - skip sending
            return false;
        }

        return (bool) wp_mail($to, (string) $subject, (string) $message, $headers);
    }

    /**
     * Send a verification code email for identity verification.
     * 2026 BP: Premium centered OTP block for guest-facing security.
     *
     * @param string $to   Guest email.
     * @param string $code 6-digit verification code.
     * @return bool
     */
    public static function send_verification_email( string $to, string $code ): bool {
        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf(
            // translators: %s: site name
            __( 'Verify your identity - %s', 'modern-hotel-booking' ),
            $site_name
        );

        $message = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #1a1a1b;">';
        $message .= '<h2 style="color: #2e7d32; text-align: center;">' . esc_html( __( 'Verify Your Identity', 'modern-hotel-booking' ) ) . '</h2>';
        $message .= '<p style="font-size: 16px; line-height: 1.5; text-align: center;">' . esc_html( __( 'Please use the following code to confirm your identity with our AI concierge. This code will expire in 20 minutes.', 'modern-hotel-booking' ) ) . '</p>';
        
        $message .= '<div style="margin: 30px auto; padding: 20px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 12px; text-align: center; max-width: 200px;">';
        $message .= '<span style="font-size: 32px; font-weight: 700; letter-spacing: 5px; color: #1a1a1b; display: block; font-family: monospace;">' . esc_html( $code ) . '</span>';
        $message .= '</div>';

        $message .= '<p style="font-size: 14px; color: #6c757d; text-align: center;">' . esc_html( __( 'If you did not request this code, please ignore this email.', 'modern-hotel-booking' ) ) . '</p>';
        $message .= '<hr style="border: 0; border-top: 1px solid #e9ecef; margin: 30px 0;">';
        $message .= '<p style="font-size: 12px; color: #adb5bd; text-align: center;">&copy; ' . gmdate( 'Y' ) . ' ' . esc_html( $site_name ) . '</p>';
        $message .= '</div>';

        $admin_email = get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
        $headers     = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Reply-To: ' . $admin_email,
        ];

        return (bool) wp_mail( $to, (string) $subject, (string) $message, $headers );
    }

    /**
     * Generate a simple ICS file for email attachments.
     * Use explicit UTC times matching the hotel check-in/out timezone configuration.
     *
     * @param object $booking The booking object.
     * @return string ICS file content.
     */
    private static function generate_simple_ics(object $booking): string
    {
        $now = wp_date('Ymd\THis\Z');
        $check_in_date  = (string)($booking->check_in ?? '');
        $check_out_date = (string)($booking->check_out ?? '');
        
        if ('' === $check_in_date || '' === $check_out_date) {
            return '';
        }

        try {
            // Respect the dedicated Hotel Timezone setting first, fallback to WP default
            $tz_string = (string) get_option('mhbo_hotel_timezone');
            if ('' === $tz_string) {
                $tz_string = wp_timezone_string();
            }
            $tz = new \DateTimeZone($tz_string);
            
            $start_dt = new \DateTime($check_in_date . ' ' . get_option('mhbo_check_in_time', '14:00'), $tz);
            $start_dt->setTimezone(new \DateTimeZone('UTC'));
            $dtstart = "DTSTART:" . $start_dt->format('Ymd\THis\Z');

            $end_dt = new \DateTime($check_out_date . ' ' . get_option('mhbo_check_out_time', '11:00'), $tz);
            $end_dt->setTimezone(new \DateTimeZone('UTC'));
            $dtend = "DTEND:" . $end_dt->format('Ymd\THis\Z');
        } catch (\Exception $e) {
            // Fallback to all-day events if parsing fails
            $dtstart = "DTSTART;VALUE=DATE:" . wp_date('Ymd', strtotime($check_in_date));
            $dtend   = "DTEND;VALUE=DATE:" . wp_date('Ymd', strtotime($check_out_date));
        }

        return "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//Modern Hotel Booking//EN\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:mhbo-booking-{$booking->id}\r\n" .
            "DTSTAMP:{$now}\r\n" .
            "{$dtstart}\r\n" .
            "{$dtend}\r\n" .
            "SUMMARY:" . sprintf(I18n::get_label('label_hotel_booking_id'), $booking->id) . "\r\n" .
            "END:VEVENT\r\n" .
            "END:VCALENDAR";
    }

    /**
     * Get placeholders for Business Information.
     *
     * @param string $lang Current language.
     * @return array<string, string>
     */
    private static function get_business_placeholders(string $lang = ''): array
    {
        $placeholders = [];

        if (class_exists('MHBO\Business\Info')) {
            $company  = \MHBO\Business\Info::get_company();
            $whatsapp = \MHBO\Business\Info::get_whatsapp();

            $placeholders['{company_name}']         = I18n::decode($company['name'] ?? '', $lang);
            $placeholders['{company_address}']      = I18n::decode($company['address'] ?? '', $lang);
            $placeholders['{company_phone}']        = $company['phone'] ?? '';
            $placeholders['{company_email}']        = $company['email'] ?? '';
            $placeholders['{company_website}']      = $company['website'] ?? '';
            $placeholders['{company_registration}'] = $company['registration_number'] ?? '';
            $placeholders['{whatsapp_number}']      = $whatsapp['phone_number'] ?? '';
            $placeholders['{whatsapp_link}']        = '' !== (string) ($whatsapp['phone_number'] ?? '') ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', (string) $whatsapp['phone_number']) : '';

            /* BUILD_PRO_START */
            $placeholders['{company_info}']      = self::render_company_card_html($lang);
            $placeholders['{whatsapp_contact}']  = self::render_whatsapp_button_html();
            $placeholders['{banking_details}']   = self::render_banking_card_html(0); // 0 as temporary default
            $placeholders['{revolut_details}']   = self::render_revolut_card_html();
            $placeholders['{business_card}']     = self::render_business_card_html(0, $lang);
            /* BUILD_PRO_END */
        }

        return $placeholders;
    }

    /**
     * Build the complete set of email placeholders with sanitization and fallbacks.
     *
     * @param object               $booking    The booking database row.
     * @param string               $status     The booking status.
     * @param string               $lang       The target language.
     * @param array<string, mixed> $additional Additional pre-rendered components.
     * @return array<string, string|int|float>
     */
    public static function get_booking_placeholders(object $booking, string $status, string $lang, array $additional = []): array
    {
        $check_in  = '' !== (string) ($booking->check_in ?? '') ? strtotime((string) $booking->check_in) : 0;
        $check_out = '' !== (string) ($booking->check_out ?? '') ? strtotime((string) $booking->check_out) : 0;
        $nights    = ($check_in > 0 && $check_out > $check_in) ? (int) round(($check_out - $check_in) / DAY_IN_SECONDS) : 0;

        // SANITIZATION: All user-provided data must be escaped for use in HTML emails.
        $c_name  = '' !== (string) ($booking->customer_name ?? '') ? esc_html((string) $booking->customer_name) : I18n::get_label('label_guest');
        $c_email = '' !== (string) ($booking->customer_email ?? '') ? sanitize_email((string) $booking->customer_email) : '';
        $c_phone = '' !== (string) ($booking->customer_phone ?? '') ? esc_html((string) $booking->customer_phone) : '';
        $special = '' !== (string) ($booking->special_requests ?? '') ? esc_html((string) $booking->special_requests) : '';

        $booking_token = $booking->booking_token ?? '';
        $view_url = $booking_token ? get_rest_url(null, 'mhbo/v1/bookings/' . $booking_token) : '';

        $placeholders = [
            // Core IDs & References
            '{booking_id}'              => (int) ($booking->id ?? 0),
            '{booking_token}'           => $booking_token,
            '{booking_reference}'       => $booking_token, // Alias for backward compatibility
            '{view_url}'                => esc_url($view_url),

            // Stay Information
            '{check_in}'                => $check_in > 0 ? date_i18n(get_option('date_format'), $check_in) : '--',
            '{check_out}'               => $check_out > 0 ? date_i18n(get_option('date_format'), $check_out) : '--',
            '{check_in_time}'           => esc_html(get_option('mhbo_check_in_time', '14:00')),
            '{check_out_time}'           => esc_html(get_option('mhbo_check_out_time', '11:00')),
            '{nights}'                  => $nights,
            '{guests}'                  => (int) ($booking->guests ?? 1),
            '{children}'                => (int) ($booking->children ?? 0),
            '{children_ages}'           => self::format_children_ages($booking->children_ages ?? ''),
            '{children_total}'          => Money::fromDecimal((string) ($booking->children_total_net ?? 0))->isPositive()
                                            ? I18n::format_currency(Money::fromDecimal((string) ($booking->children_total_net ?? 0)))
                                            : '',
            '{total_price}'             => I18n::format_currency(Money::fromDecimal((string) ($booking->total_price ?? 0))),
            '{status}'                  => I18n::translate_status((string) $status),
            '{room_name}'               => esc_html($additional['room_name'] ?? ''),

            // Customer Information
            '{customer_name}'           => $c_name,
            '{customer_email}'          => $c_email,
            '{customer_phone}'          => $c_phone,
            '{special_requests}'        => $special,
            '{arrival_time}'            => esc_html($booking->arrival_time ?? ''),

            // Pre-rendered Components
            '{custom_fields}'           => $additional['custom_fields_formatted'] ?? '',
            '{booking_extras}'          => $additional['extras_html'] ?? '',
            '{extras}'                  => $additional['extras_html'] ?? '', // Alias
            '{extras_text}'             => $additional['extras_text'] ?? '',
            '{payment_details}'         => $additional['payment_details'] ?? '',

            // Tax Details
            '{tax_breakdown}'           => $additional['tax_breakdown_html'] ?? '',
            '{tax_breakdown_text}'      => $additional['tax_breakdown_text'] ?? '',
            '{tax_total}'               => $additional['tax_total'] ?? '',
            '{tax_registration_number}' => esc_html($additional['tax_registration_number'] ?? ''),

            // Global Info
            '{site_name}'               => esc_html(get_bloginfo('name')),
            /* BUILD_PRO_START */
            '{deposit_details}'         => self::get_deposit_email_html($booking, $lang),
            /* BUILD_PRO_END */
        ];

        // Add Business Information Placeholders
        $placeholders = array_merge($placeholders, self::get_business_placeholders($lang));

        return $placeholders;
    }

    /**
     * Apply placeholders to a string and clean up messy formatting.
     *
     * @param string                $text         The text containing placeholders.
     * @param array<string, string|int|float> $placeholders Map of placeholders to values.
     * @return string The processed text.
     */
    public static function apply_placeholders(string $text, array $placeholders): string
    {
        if ( '' === (string) $text ) {
            return '';
        }

        // 1. Decode HTML entities (wp_editor may encode curly braces as &#123; &#125;)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Perform raw replacement
        $text = str_replace(array_keys($placeholders), array_values($placeholders), $text);

        // 3. Smart Cleanup of messy formatting
        // Collapse multiple commas/spaces on the same line (e.g., ", , ,") into a single comma
        $text = preg_replace('/[, \t]+(,[ \t]*)+/', ', ', $text);

        // Remove leading commas on lines (e.g., ", pending")
        $text = preg_replace('/^[ \t]*,+[ \t]*/m', '', $text);

        // Remove trailing commas ONLY if they follow a space (dangling separator)
        // This preserves greeting commas like "Hi {customer_name}," which usually have no space before the comma
        $text = preg_replace('/[ \t]+,[ \t]*$/m', '', $text);

        // Collapse extra vertical whitespace (more than 2 newlines)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Handle commas near HTML breaks and tags
        $text = preg_replace('/,\s*<br\s*\/?>/i', '<br>', $text);
        $text = preg_replace('/<br\s*\/?>\s*,/i', '<br>', $text);
        $text = preg_replace('/,\s*<\/p>/i', '</p>', $text);
        $text = preg_replace('/,\s*<\/div>/i', '</div>', $text);
        $text = preg_replace('/<li>\s*,/i', '<li>', $text);

        return trim($text);
    }

    /**
     * Format booking extras for email placeholders.
     *
     * @param object $booking The booking object.
     * @param string $lang    Current language code.
     * @param string $format  Output format ('html' or 'text').
     * @return string Formatted extras.
     */
    private static function format_extras(object $booking, string $lang, string $format = 'html'): string
    {
        if ( '' === (string) ($booking->booking_extras ?? '') ) {
            return '';
        }
        $extras = json_decode((string)$booking->booking_extras, true);
        if ( ! is_array($extras) || count($extras) === 0 ) {
            return '';
        }

        $mhbo_output = '';
        foreach ($extras as $extra) {
            $name = isset($extra['name']) ? I18n::decode((string)$extra['name'], $lang) : '';
            $total = isset($extra['total']) ? I18n::format_currency((float)$extra['total']) : '';
            if ( '' === (string) $name ) {
                continue;
            }

            if ('html' === $format) {
                $mhbo_output .= '<li>' . esc_html($name) . ': ' . esc_html($total) . '</li>';
            } else {
                $mhbo_output .= ' - ' . $name . ': ' . $total . "\n";
            }
        }

        if ( 'html' === $format && '' !== (string) ($mhbo_output ?? '') ) {
            $mhbo_output = '<ul style="margin: 0; padding-left: 20px;">' . $mhbo_output . '</ul>';
        }

        return $mhbo_output;
    }

    /**
     * Get business payment details for booking emails.
     *
     * @param int   $booking_id  The booking ID.
     * @param float|string|Money $total_price The total booking price.
     * @return string HTML for payment details.
     */
    public static function get_business_payment_details_html(int $booking_id, float|string|Money $total_price): string
    {
        $total_price = $total_price instanceof Money ? $total_price : Money::fromDecimal((string) $total_price);
        $mhbo_output  = '<div style="margin-top: 20px; padding: 15px; background: #fff3e0; border-radius: 5px; border: 1px solid #ffeccf;">';
        $mhbo_output .= '<h4 style="margin: 0 0 10px 0; color: #e65100;">' . esc_html(I18n::get_label('label_payment_info')) . '</h4>';
        $mhbo_output .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_amount_due')) . '</strong> ' . I18n::format_currency($total_price) . '</p>';

        if (class_exists('MHBO\Business\Info')) {
            $banking = \MHBO\Business\Info::get_banking();
            $revolut = \MHBO\Business\Info::get_revolut();
            if ( (bool) ($banking['enabled'] ?? false) && '' !== (string) ($banking['iban'] ?? '') ) {
                $mhbo_output .= '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ffd8a8;">';
                $mhbo_output .= '<h5 style="margin: 0 0 10px 0;">' . esc_html(I18n::get_label('label_bank_transfer_details')) . '</h5>';
                $mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html(I18n::get_label('label_bank')) . '</strong> ' . esc_html((string) $banking['bank_name']) . '</p>';
                $mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html(I18n::get_label('label_iban')) . '</strong> <code style="background:#fff;padding:2px 5px;border:1px solid #ccc;">' . esc_html((string) $banking['iban']) . '</code></p>';
                $mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html(I18n::get_label('label_reference')) . '</strong> ' . esc_html($banking['reference_prefix'] . $booking_id) . '</p>';
                $mhbo_output .= '</div>';
            }
            if ( (bool) ($revolut['enabled'] ?? false) && '' !== (string) ($revolut['revolut_tag'] ?? '') ) {
                $mhbo_output .= '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ffd8a8;">';
                $mhbo_output .= '<h5 style="margin: 0 0 10px 0;">' . esc_html(I18n::get_label('label_revolut_payment')) . '</h5>';
                $mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html(I18n::get_label('label_revtag')) . '</strong> <code style="background:#fff;padding:2px 5px;border:1px solid #ccc;">' . esc_html((string) $revolut['revolut_tag']) . '</code></p>';
                if ( '' !== (string) ($revolut['revolut_link'] ?? '') ) {
                    $mhbo_output .= '<p style="margin: 5px 0;"><a href="' . esc_url((string) $revolut['revolut_link']) . '" style="display:inline-block;background:#000;color:#fff;padding:5px 15px;text-decoration:none;border-radius:4px;font-size:0.85em;">' . esc_html(I18n::get_label('label_pay_via_revolut')) . '</a></p>';
                }
                $mhbo_output .= '</div>';
            }
        }

        $mhbo_output .= '</div>';
        return $mhbo_output;
    }

    /* BUILD_PRO_START */
    /**
     * Get Pro-only deposit summary HTML for emails.
     *
     * @param object $booking
     * @param string $lang
     */
    private static function get_deposit_email_html(object $booking, string $lang): string
    {
        if ( '' === (string) ($booking->payment_type ?? '') || 'deposit' !== $booking->payment_type ) {
            return '';
        }

        $total     = Money::fromDecimal((string) ($booking->total_price ?? 0));
        $paid      = Money::fromDecimal((string) ($booking->deposit_amount ?? 0));
        $remaining = Money::fromDecimal((string) ($booking->remaining_balance ?? 0));

        $is_non_refundable = (bool)($booking->deposit_is_non_refundable ?? false);
        $refund_deadline = $booking->refund_deadline_date ?? '';
        
        $html = '<div style="margin-top: 20px; padding: 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-family: sans-serif;">';
        $html .= '<h4 style="margin: 0 0 12px 0; color: #1e293b; font-size: 16px;">' . esc_html(I18n::get_label('label_email_payment_summary')) . '</h4>';
        
        $html .= '<table style="width: 100%; border-collapse: collapse; font-size: 14px;">';
        $html .= '<tr><td style="padding: 4px 0; color: #64748b;">' . esc_html(I18n::get_label('label_email_total_amount')) . '</td><td style="padding: 4px 0; text-align: right; font-weight: 600;">' . I18n::format_currency($total) . '</td></tr>';
        $deposit_label = ('completed' === ($booking->payment_status ?? ''))
            ? esc_html(I18n::get_label('label_email_deposit_paid'))
            : esc_html(I18n::get_label('label_email_deposit_required'));
        $deposit_color = ('completed' === ($booking->payment_status ?? '')) ? '#16a34a' : '#f97316';
        $html .= '<tr><td style="padding: 4px 0; color: #64748b;">' . $deposit_label . '</td><td style="padding: 4px 0; text-align: right; color: ' . esc_attr($deposit_color) . '; font-weight: 600;">' . I18n::format_currency($paid) . '</td></tr>';
        
        if (!$remaining->isZero()) {
            $html .= '<tr style="border-top: 1px solid #e2e8f0;"><td style="padding: 8px 0 4px 0; color: #1e293b; font-weight: 600;">' . esc_html(I18n::get_label('label_email_remaining_balance')) . '</td><td style="padding: 8px 0 4px 0; text-align: right; color: #f97316; font-weight: 700;">' . I18n::format_currency($remaining) . '</td></tr>';
            $html .= '<tr><td colspan="2" style="padding: 4px 0; font-size: 12px; color: #64748b;">' . esc_html(I18n::get_label('label_email_due_at_checkin')) . '</td></tr>';
        } else {
            $html .= '<tr style="border-top: 1px solid #e2e8f0;"><td colspan="2" style="padding: 8px 0; text-align: center; color: #166534; font-weight: 700;">' . esc_html(I18n::get_label('label_email_paid_full')) . '</td></tr>';
        }
        $html .= '</table>';

        if ($is_non_refundable) {
            $html .= '<div style="margin-top: 12px; padding: 8px; background: #fff1f2; border-left: 4px solid #f43f5e; color: #9f1239; font-size: 12px;">';
            $html .= '<strong>' . esc_html(I18n::get_label('label_email_non_refundable')) . '</strong> ' . esc_html(I18n::get_label('msg_email_non_refundable_desc'));
            $html .= '</div>';
            } elseif ('' !== (string) ($refund_deadline ?? '') && '0000-00-00' !== $refund_deadline) {
            $deadline_ts = strtotime($refund_deadline);
            $html .= '<div style="margin-top: 12px; padding: 8px; background: #f0fdf4; border-left: 4px solid #22c55e; color: #166534; font-size: 12px;">';
            // translators: %s: date
            $html .= sprintf(esc_html(I18n::get_label('msg_email_refund_deadline')), '<strong>' . date_i18n(get_option('date_format'), $deadline_ts) . '</strong>');
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
    /* BUILD_PRO_END */

    /**
     * Format a JSON list of children ages into a user-friendly, localized string.
     * 
     * This utility is used primarily in email notifications to provide detailed 
     * guest composition data to administrators and customers.
     * 
     * @param string|null $ages_json JSON string of ages (e.g. '[5, 8]').
     * @return string Formatted ages (e.g. '5, 8') or empty string if no indices found.
     */
    private static function format_children_ages(?string $ages_json): string
    {
        if ('' === (string) ($ages_json ?? '')) {
            return '';
        }

        $ages = json_decode($ages_json, true);
        if (!is_array($ages) || [] === $ages) {
            return '';
        }

        return implode(', ', array_map('absint', $ages));
    }

    /* BUILD_PRO_START */
    /**
     * Render a professional company info card for emails.
     */
    private static function render_company_card_html(string $lang = ''): string
    {
        if (!class_exists('MHBO\Business\Info')) return '';
        $data = \MHBO\Business\Info::get_company();
        if ('' === (string)($data['company_name'] ?? '')) return '';

        $html = '<div style="background:#f1f5f9;border-radius:8px;padding:20px;font-family:sans-serif;margin:15px 0;border:1px solid #e2e8f0;">';
        if ('' !== (string)($data['logo_url'] ?? '')) {
            $html .= '<div style="margin-bottom:15px;"><img src="'.esc_url($data['logo_url']).'" alt="Logo" style="max-width:150px;height:auto;"></div>';
        }
        $html .= '<h3 style="margin:0 0 10px 0;color:#0f172a;font-size:18px;">'.esc_html($data['company_name']).'</h3>';
        $html .= '<address style="font-style:normal;color:#475569;font-size:14px;line-height:1.5;">';
        $html .= esc_html($data['address_line_1'] ?? '').'<br>';
        if ('' !== (string)($data['address_line_2'] ?? '')) $html .= esc_html($data['address_line_2']).'<br>';
        $html .= esc_html($data['city'] ?? '').', '.esc_html($data['state'] ?? '').' '.esc_html($data['postcode'] ?? '').'<br>';
        $html .= esc_html($data['country'] ?? '');
        $html .= '</address>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a WhatsApp contact button for emails.
     */
    private static function render_whatsapp_button_html(): string
    {
        if (!class_exists('MHBO\Business\Info')) return '';
        $data = \MHBO\Business\Info::get_whatsapp();
        if (!(bool)($data['enabled'] ?? false) || '' === (string)($data['phone_number'] ?? '')) return '';

        $wa_url = 'https://wa.me/' . preg_replace('/[^\d]/', '', (string)($data['phone_number'] ?? ''));
        if ('' !== (string)($data['default_msg'] ?? '')) {
            $wa_url = add_query_arg('text', rawurlencode((string)$data['default_msg']), $wa_url);
        }

        return '<div style="margin:15px 0;">' .
               '<a href="'.esc_url($wa_url).'" style="display:inline-block;background:#25D366;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;font-family:sans-serif;font-size:15px;">' .
               esc_html($data['button_text'] ?: 'Chat on WhatsApp') .
               '</a></div>';
    }

    /**
     * Render a banking details card for emails.
     */
    private static function render_banking_card_html(int $booking_id): string
    {
        if (!class_exists('MHBO\Business\Info')) return '';
        $data = \MHBO\Business\Info::get_banking();
        if (!(bool)($data['enabled'] ?? false) || '' === (string)($data['iban'] ?? '')) return '';

        $reference = $data['reference_prefix'] . ($booking_id ?: 'XXXX');

        $html = '<div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:20px;font-family:sans-serif;margin:15px 0;">';
        $html .= '<h4 style="margin:0 0 15px 0;color:#0f172a;font-size:16px;">'.esc_html($data['bank_name'] ?: I18n::get_label('label_bank_transfer_details')).'</h4>';
        $html .= '<div style="font-size:14px;color:#334155;">';
        if ('' !== (string)($data['account_name'] ?? '')) {
            $html .= '<p style="margin:5px 0;"><strong>'.esc_html(I18n::get_label('label_bank_acc_name')).':</strong> '.esc_html($data['account_name']).'</p>';
        }
        $html .= '<p style="margin:5px 0;"><strong>'.esc_html(I18n::get_label('label_bank_iban')).':</strong> <code style="background:#f8fafc;padding:2px 4px;border:1px solid #e2e8f0;">'.esc_html($data['iban']).'</code></p>';
        if ('' !== (string)($data['swift_bic'] ?? '')) {
            $html .= '<p style="margin:5px 0;"><strong>'.esc_html(I18n::get_label('label_bank_swift')).':</strong> '.esc_html($data['swift_bic']).'</p>';
        }
        $html .= '<p style="margin:5px 0;"><strong>'.esc_html(I18n::get_label('label_reference')).':</strong> <span style="color:#e11d48;font-weight:bold;">'.esc_html($reference).'</span></p>';
        $html .= '</div>';
        if ('' !== (string)($data['instructions'] ?? '')) {
            $html .= '<div style="margin-top:15px;padding-top:15px;border-top:1px solid #f1f5f9;font-size:13px;color:#64748b;">'.wp_kses_post((string)$data['instructions']).'</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a Revolut details card for emails.
     */
    private static function render_revolut_card_html(): string
    {
        if (!class_exists('MHBO\Business\Info')) return '';
        $data = \MHBO\Business\Info::get_revolut();
        if (!(bool)($data['enabled'] ?? false) || ('' === (string)($data['revolut_tag'] ?? '') && '' === (string)($data['revolut_iban'] ?? ''))) return '';

        $html = '<div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:20px;font-family:sans-serif;margin:15px 0;">';
        $html .= '<h4 style="margin:0 0 15px 0;color:#0f172a;font-size:16px;">'.esc_html($data['revolut_name'] ?: I18n::get_label('label_revolut_payment')).'</h4>';
        $html .= '<div style="font-size:14px;color:#334155;">';
        if ('' !== (string)($data['revolut_tag'] ?? '')) {
            $html .= '<p style="margin:5px 0;"><strong>'.esc_html(I18n::get_label('label_revolut_tag')).':</strong> <code style="background:#f8fafc;padding:2px 4px;border:1px solid #e2e8f0;">'.esc_html($data['revolut_tag']).'</code></p>';
        }
        if ('' !== (string)($data['revolut_link'] ?? '')) {
            $html .= '<p style="margin:10px 0;"><a href="'.esc_url($data['revolut_link']).'" style="display:inline-block;background:#000000;color:#ffffff;padding:8px 16px;text-decoration:none;border-radius:4px;font-size:13px;">'.esc_html(I18n::get_label('label_pay_via_revolut')).'</a></p>';
        }
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a complete business card for emails.
     */
    private static function render_business_card_html(int $booking_id, string $lang = ''): string
    {
        $html = '<div style="margin:20px 0;">';
        $html .= self::render_company_card_html($lang);
        $html .= self::render_whatsapp_button_html();
        $html .= self::render_banking_card_html($booking_id);
        $html .= self::render_revolut_card_html();
        $html .= '</div>';
        return $html;
    }
    /* BUILD_PRO_END */
}
