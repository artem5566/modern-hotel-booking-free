<?php declare(strict_types=1);

namespace MHBO\Api;

use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Frontend\Shortcode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST endpoints for the inline booking modal.
 *
 * GET /mhbo/v1/modal/booking-form    — returns form HTML for a specific room + dates.
 * GET /mhbo/v1/modal/confirmation    — returns confirmation panel HTML for a completed booking.
 */
class ModalApi
{
    public function register_routes(): void
    {
        register_rest_route('mhbo/v1', '/modal/booking-form', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_booking_form'],
            'permission_callback' => '__return_true',
            'args'                => [
                'room_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'check_in' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'check_out' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'guests' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 2,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'children' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 0,
                    'minimum'           => 0,
                    'sanitize_callback' => 'absint',
                ],
                'total_price' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '0',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'page_url' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'customer_name' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'customer_email' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_email',
                ],
                'customer_phone' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('mhbo/v1', '/modal/confirmation', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_confirmation'],
            'permission_callback' => '__return_true',
            'args'                => [
                'booking_token' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'status' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'confirmed',
                    'enum'              => ['confirmed', 'pending', 'failed'],
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    /**
     * Return the booking form HTML for injection into the modal.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_booking_form(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        // Validate date format
        $check_in  = $request->get_param('check_in');
        $check_out = $request->get_param('check_out');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_in) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_out)) {
            return new \WP_Error('mhbo_invalid_dates', __('Invalid date format. Use YYYY-MM-DD.', 'modern-hotel-booking'), ['status' => 400]);
        }

        if ($check_out <= $check_in) {
            return new \WP_Error('mhbo_invalid_dates', __('Check-out must be after check-in.', 'modern-hotel-booking'), ['status' => 400]);
        }

        $room_id        = (int) $request->get_param('room_id');
        $guests         = max(1, (int) $request->get_param('guests'));
        $children       = max(0, (int) $request->get_param('children'));
        $page_url       = (string) $request->get_param('page_url');
        $customer_name  = (string) $request->get_param('customer_name');
        $customer_email = (string) $request->get_param('customer_email');
        $customer_phone = (string) $request->get_param('customer_phone');

        $raw_price   = (float) $request->get_param('total_price');
        $currency    = Pricing::get_currency_code();
        $total_money = Money::fromDecimal((string) $raw_price, $currency);

        $shortcode = new Shortcode();
        $html = $shortcode->render_booking_form_html([
            'room_id'         => $room_id,
            'type_id'         => 0,
            'check_in'        => $check_in,
            'check_out'       => $check_out,
            'guests'          => $guests,
            'children'        => $children,
            'total_price'     => $total_money,
            'page_url'        => $page_url,
            'customer_name'   => $customer_name,
            'customer_email'  => $customer_email,
            'customer_phone'  => $customer_phone,
        ]);

        if ('' === $html) {
            return new \WP_Error('mhbo_render_failed', __('Unable to render booking form.', 'modern-hotel-booking'), ['status' => 500]);
        }

        return new \WP_REST_Response([
            'html'  => $html,
            'nonce' => wp_create_nonce('mhbo_confirm_action'),
        ], 200);
    }

    /**
     * Return the confirmation panel HTML for a completed booking.
     * The booking_token is the security credential — no additional nonce needed.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_confirmation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $token  = (string) $request->get_param('booking_token');
        $status = (string) $request->get_param('status');

        if ('' === $token) {
            return new \WP_Error('mhbo_missing_token', __('Booking token is required.', 'modern-hotel-booking'), ['status' => 400]);
        }

        $shortcode = new Shortcode();
        $html      = $shortcode->render_confirmation_html($token, $status);

        if ('' === $html) {
            return new \WP_Error('mhbo_not_found', __('Booking not found.', 'modern-hotel-booking'), ['status' => 404]);
        }

        return new \WP_REST_Response(['html' => $html], 200);
    }
}
