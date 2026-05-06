<?php
/**
 * Ability: Get Business Card
 *
 * Returns structured contact, payment, and business information
 * from the hotel's admin-configured settings. Wraps existing
 * Business\Info API methods into a single AI-consumable tool response.
 *
 * Available in both Free and Pro tiers (no BUILD_PRO markers).
 *
 * @package MHBO\AI\Abilities
 * @since   2.6.0
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

use MHBO\Business\Info;
use MHBO\Core\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GetBusinessCard {

    /**
     * Return the WP Ability / MCP tool definition.
     *
     * @return array<mixed>
     */
    public static function get_definition(): array {
        return [
            'name'         => __( 'Get Business Card', 'modern-hotel-booking' ),
            'description'  => __( 'Get hotel contact details, payment methods, banking info, Revolut, WhatsApp, and deposit policy. Use when guests ask about paying, contacting the hotel, or need business details.', 'modern-hotel-booking' ),
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'sections' => [
                        'type'        => 'string',
                        'enum'        => [ 'all', 'company', 'payments', 'banking', 'revolut', 'whatsapp', 'deposit' ],
                        'default'     => 'all',
                        'description' => 'Which section(s) to retrieve. Use "all" for the full card, or a specific section to reduce response size.',
                    ],
                ],
                'required'   => [],
            ],
        ];
    }

    /**
     * Execute the tool and return structured business data.
     *
     * @param array<mixed> $args
     * @return array<mixed>
     */
    public static function execute( array $args ): array {
        $section = sanitize_text_field( (string) ( $args['sections'] ?? 'all' ) );
        $result  = [];

        // ── Company ─────────────────────────────────────────────────
        if ( 'all' === $section || 'company' === $section ) {
            $company = Info::get_company();
            $result['company'] = [
                'name'      => $company['company_name'] ?: get_bloginfo( 'name' ),
                'address'   => self::format_address( $company ),
                'phone'     => $company['telephone'],
                'email'     => $company['email'],
                'website'   => $company['website'] ?: home_url(),
                'logo_url'  => $company['logo_url'] ? esc_url( $company['logo_url'] ) : '',
                'tax_id'    => $company['tax_id'],
            ];
        }

        // ── WhatsApp ────────────────────────────────────────────────
        if ( 'all' === $section || 'whatsapp' === $section ) {
            $wa = Info::get_whatsapp();
            if ( isset( $wa['enabled'] ) && $wa['enabled'] && isset( $wa['phone_number'] ) && '' !== $wa['phone_number'] ) {
                $phone_clean = preg_replace( '/[^0-9+]/', '', (string) $wa['phone_number'] );
                $msg         = rawurlencode( (string) $wa['default_msg'] );
                $result['whatsapp'] = [
                    'enabled'      => true,
                    'phone'        => $wa['phone_number'],
                    'default_msg'  => $wa['default_msg'],
                    'chat_url'     => "https://wa.me/{$phone_clean}" . ( $msg ? "?text={$msg}" : '' ),
                ];
            } else {
                $result['whatsapp'] = [ 'enabled' => false ];
            }
        }

        // ── Online Payment Gateways ─────────────────────────────────
        if ( 'all' === $section || 'payments' === $section ) {
            $gateways = [];
            if ( (int) get_option( 'mhbo_gateway_stripe_enabled', 0 ) ) {
                $gateways[] = 'Credit/Debit Card (Stripe)';
            }
            if ( (int) get_option( 'mhbo_gateway_paypal_enabled', 0 ) ) {
                $gateways[] = 'PayPal';
            }
            if ( (int) get_option( 'mhbo_gateway_onsite_enabled', 0 ) ) {
                $gateways[] = 'Pay on Arrival';
            }
            $result['online_gateways'] = $gateways;
        }

        // ── Banking ─────────────────────────────────────────────────
        if ( 'all' === $section || 'banking' === $section ) {
            $banking = Info::get_banking();
            if ( isset( $banking['enabled'] ) && $banking['enabled'] && isset( $banking['bank_name'] ) && '' !== $banking['bank_name'] ) {
                $result['banking'] = [
                    'enabled'          => true,
                    'bank_name'        => $banking['bank_name'],
                    'account_name'     => $banking['account_name'],
                    'account_number'   => $banking['account_number'],
                    'iban'             => $banking['iban'],
                    'swift_bic'        => $banking['swift_bic'],
                    'sort_code'        => $banking['sort_code'],
                    'reference_prefix' => $banking['reference_prefix'],
                    'instructions'     => $banking['instructions'],
                ];
            } else {
                $result['banking'] = [ 'enabled' => false ];
            }
        }

        // ── Revolut ─────────────────────────────────────────────────
        if ( 'all' === $section || 'revolut' === $section ) {
            $revolut = Info::get_revolut();
            if ( isset( $revolut['enabled'] ) && $revolut['enabled'] && isset( $revolut['revolut_tag'] ) && '' !== $revolut['revolut_tag'] ) {
                $result['revolut'] = [
                    'enabled'      => true,
                    'name'         => $revolut['revolut_name'],
                    'tag'          => $revolut['revolut_tag'],
                    'iban'         => $revolut['revolut_iban'],
                    'payment_link' => $revolut['revolut_link'] ? esc_url( $revolut['revolut_link'] ) : '',
                    'qr_code_url'  => $revolut['qr_code_url'] ? esc_url( $revolut['qr_code_url'] ) : '',
                    'instructions' => $revolut['instructions'],
                ];
            } else {
                $result['revolut'] = [ 'enabled' => false ];
            }
        }

        // ── Deposit Policy ──────────────────────────────────────────
        if ( 'all' === $section || 'deposit' === $section ) {
            if ( get_option( 'mhbo_deposits_enabled', 0 ) ) {
                $dep_type  = (string) get_option( 'mhbo_deposit_type', 'percentage' );
                $dep_value = (float)  get_option( 'mhbo_deposit_value', 20 );
                $non_r     = (bool)   get_option( 'mhbo_deposit_non_refundable', 0 );

                $label = match ( $dep_type ) {
                    'first_night' => __( 'First night\'s rate', 'modern-hotel-booking' ),
                    'fixed'       => sprintf( '%s %s', number_format( $dep_value, 2 ), Pricing::get_currency_code() ),
                    default       => sprintf( '%d%% of total', (int) $dep_value ),
                };

                $result['deposit'] = [
                    'required'       => true,
                    'type'           => $dep_type,
                    'value'          => $dep_value,
                    'label'          => $label,
                    'non_refundable' => $non_r,
                ];
            } else {
                $result['deposit'] = [ 'required' => false ];
            }
        }

        // ── Meta ────────────────────────────────────────────────────
        $result['currency'] = Pricing::get_currency_code();
        $result['check_in_time']  = (string) get_option( 'mhbo_check_in_time', '14:00' );
        $result['check_out_time'] = (string) get_option( 'mhbo_check_out_time', '11:00' );

        return $result;
    }

    /**
     * Format a company address into a single readable string.
     *
     * @param array<string, mixed> $company
     * @return string
     */
    private static function format_address( array $company ): string {
        $parts = array_filter( [
            $company['address_line_1'] ?? '',
            $company['address_line_2'] ?? '',
            $company['city'] ?? '',
            $company['state'] ?? '',
            $company['postcode'] ?? '',
            $company['country'] ?? '',
        ], fn( $val ) => '' !== $val );
        return implode( ', ', $parts );
    }

    /**
     * Register as a WordPress Ability (WP 7.0+).
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        wp_register_ability( 'mhbo/get-business-card', self::get_definition() );
    }
}
