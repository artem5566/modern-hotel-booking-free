<?php declare(strict_types=1);

namespace MHBO\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

class BlockBindings
{
    public function init(): void
    {
        add_action('init', [$this, 'register_bindings']);
    }

    public function register_bindings(): void
    {
        if (!function_exists('register_block_bindings_source')) {
            return;
        }

        register_block_bindings_source('mhbo/booking-total', [
            'label' => 'MHBO Booking Total',
            'get_value_callback' => [$this, 'get_booking_total_value'],
        ]);

        // Register attributes for pattern overrides (WP 7.0+)
        add_filter('block_bindings_supported_attributes', [$this, 'supported_attributes'], 10, 1);
    }

    /**
     * @param array<string, mixed> $source_args
     * @param \WP_Block $block_instance
     * @param string $attribute_name
     */
    public function get_booking_total_value($source_args, $block_instance, string $attribute_name): string
    {
        $total = '0.00';

        // Retrieve total from a secure server-side session or transient (e.g., WC()->session if WooCommerce is used)
        if (function_exists('WC')) {
            $wc = \WC();
            if (isset($wc->session)) {
                $total = $wc->session->get('mhbo_booking_total', '0.00');
            }
        } else {
            // Fallback for non-WC environments: read from a validated booking transient using a session ID
            $session_id = isset($_COOKIE['mhbo_session']) ? sanitize_text_field(wp_unslash($_COOKIE['mhbo_session'])) : '';
            if ($session_id) {
                $total = get_transient('mhbo_booking_total_' . $session_id) ?: '0.00';
            }
        }

        // Ensure mathematical structure
        $total = number_format((float) $total, 2, '.', '');
        
        $currency = get_option('mhbo_currency_symbol', '$');
        return esc_html($currency . $total);
    }

    /**
     * @param string[] $supported_attributes
     * @return string[]
     */
    public function supported_attributes(array $supported_attributes): array
    {
        // Support text attributes (e.g., core/paragraph content)
        if (is_array($supported_attributes) && !in_array('mhbo/booking-total', $supported_attributes, true)) {
            $supported_attributes[] = 'mhbo/booking-total';
        }
        return $supported_attributes;
    }
}
