<?php declare(strict_types=1);

namespace MHBO\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use MHBO\Core\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

class CouponsController extends WP_REST_Controller
{
    public function __construct()
    {
        $this->namespace = 'mhbo/v1';
        $this->rest_base = 'coupons';
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'save_coupon'],
                'permission_callback' => [$this, 'check_permission']
            ]
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_coupon'],
                'permission_callback' => [$this, 'check_permission']
            ]
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/generate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'generate_code'],
                'permission_callback' => [$this, 'check_permission']
            ]
        ]);
    }

    public function check_permission(): bool
    {
        return Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS);
    }

    public function save_coupon(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $data = $request->get_params();
        $data['code'] = sanitize_text_field((string)($data['mhbo_coupon_code_field'] ?? ''));
        $data['id']   = absint($data['mhbo_coupon_id'] ?? 0);

        $result = CouponManager::save($data);
        if (is_wp_error($result)) {
            return new WP_Error('save_error', $result->get_error_message(), ['status' => 400]);
        }

        return rest_ensure_response([
            'id'      => $result,
            'message' => $data['id'] > 0
                ? __('Coupon updated successfully.', 'modern-hotel-booking')
                : __('Coupon created successfully.', 'modern-hotel-booking'),
        ]);
    }

    public function delete_coupon(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id) {
            return new WP_Error('invalid_id', __('Invalid coupon ID.', 'modern-hotel-booking'), ['status' => 400]);
        }

        if (CouponManager::delete($id)) {
            return rest_ensure_response(['message' => __('Coupon deleted.', 'modern-hotel-booking')]);
        }
        
        return new WP_Error('delete_error', __('Could not delete coupon.', 'modern-hotel-booking'), ['status' => 500]);
    }

    public function generate_code(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return rest_ensure_response(['code' => CouponManager::generate_code()]);
    }
}
