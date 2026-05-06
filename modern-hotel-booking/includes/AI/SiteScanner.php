<?php
declare(strict_types=1);

/**
 * Site Scanner — builds a plain-text knowledge base from the site's content.
 *
 * Scans pages, posts, and plugin data (rooms, hotel options) and caches the
 * result as a transient (24 h) with a permanent option fallback.
 *
 * @package MHBO\AI
 * @since 2.3.8
 */

namespace MHBO\AI;
if ( ! defined( 'ABSPATH' ) ) exit;

use MHBO\Business\Info;
use MHBO\Core\Pricing;
use MHBO\Core\I18n;
use MHBO\Core\Tax;
use WP_Query;
use WP_Post;

class SiteScanner {

    private const TRANSIENT_KEY = 'mhbo_kb_cache';
    private const OPTION_KEY    = 'mhbo_kb_snapshot';
    private const TTL           = DAY_IN_SECONDS;
    private const MAX_PAGE_CHARS = 3000;
    private const MAX_POSTS      = 75;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Retrieve the knowledge-base string, building it if necessary.
     *
     * @param bool $force_refresh Force a fresh scan even if cache is valid.
     * @return string
     */
    public static function get_or_build( bool $force_refresh = false ): string {
        if ( ! $force_refresh ) {
            $cached = get_transient( (string) self::TRANSIENT_KEY );
            if ( is_string( $cached ) && '' !== $cached ) {
                return (string) $cached;
            }
        }

        $kb = (string) self::scan();

        set_transient( (string) self::TRANSIENT_KEY, $kb, (int) self::TTL );
        update_option( (string) self::OPTION_KEY, $kb, false );
        update_option( 'mhbo_kb_snapshot_updated', gmdate( 'Y-m-d H:i:s' ), false );

        return (string) $kb;
    }

    /**
     * Perform a full site scan and return the KB string.
     *
     * @return string
     */
    public static function scan(): string {
        $sections = [];

        // ── Hotel overview ────────────────────────────────────────────────────
        $sections[] = self::build_hotel_overview();

        // ── Rooms from DB ─────────────────────────────────────────────────────
        $sections[] = self::build_rooms_section();

        // ── Extras / add-ons ─────────────────────────────────────────────────
        $sections[] = self::build_extras_section();

        // ── Pricing rules (taxes, seasonal, min-stay) ─────────────────────────
        $sections[] = self::build_pricing_section();

        // ── Payment methods ───────────────────────────────────────────────────
        $sections[] = self::build_payment_section();

        // ── WhatsApp / direct contact ─────────────────────────────────────────
        $sections[] = self::build_contact_section();

        // ── WordPress pages, posts ────────────────────────────────────────────
        $sections[] = self::build_pages_section();

        return implode( "\n\n", array_filter( $sections, fn( $s ) => '' !== $s ) );
    }

    /**
     * Clear the knowledge-base cache (transient only; option is kept as fallback).
     */
    public static function clear_cache(): void {
        delete_transient( self::TRANSIENT_KEY );
    }

    // -------------------------------------------------------------------------
    // Section Builders
    // -------------------------------------------------------------------------

    /**
     * Build the hotel overview section from plugin settings.
     *
     * @return string
     */
    private static function build_hotel_overview(): string {
        $company = Info::get_company();

        $hotel_name    = (string) ( $company['company_name'] ?: get_bloginfo( 'name' ) );
        $address_parts = array_filter( [
            (string) ( $company['address_line_1'] ?? '' ),
            (string) ( $company['address_line_2'] ?? '' ),
            (string) ( $company['city']           ?? '' ),
            (string) ( $company['state']          ?? '' ),
            (string) ( $company['postcode']       ?? '' ),
            (string) ( $company['country']        ?? '' ),
        ], fn( $v ) => '' !== $v );
        $address  = implode( ', ', $address_parts );
        $phone    = (string) ( $company['telephone']  ?? '' );
        $email    = (string) ( $company['email']      ?? '' );
        $website  = (string) ( $company['website'] ?: get_site_url() );

        $checkin_time  = (string) get_option( 'mhbo_checkin_time', '14:00' );
        $checkout_time = (string) get_option( 'mhbo_checkout_time', '11:00' );
        $currency      = Pricing::get_currency_code();

        $amenities_raw = get_option( 'mhbo_amenities_list', [] );
        $amenities     = '';
        if ( is_array( $amenities_raw ) && [] !== $amenities_raw ) {
            $names     = array_column( (array) $amenities_raw, 'name' );
            $amenities = implode( ', ', array_map( 'sanitize_text_field', $names ) );
        }

        $policies = [];
        $cancel   = (string) get_option( 'mhbo_cancellation_policy', '' );
        $pets     = (string) get_option( 'mhbo_pet_policy', '' );
        $smoking  = (string) get_option( 'mhbo_smoking_policy', '' );
        $children = (string) get_option( 'mhbo_children_policy', '' );
        if ( $cancel )   { $policies[] = 'Cancellation: ' . (string) $cancel; }
        if ( $pets )     { $policies[] = 'Pets: ' . (string) $pets; }
        if ( $smoking )  { $policies[] = 'Smoking: ' . (string) $smoking; }
        if ( $children ) { $policies[] = 'Children: ' . (string) $children; }
        
        $local_guide = (string) get_option( 'mhbo_ai_local_guide', '' );
        if ( $local_guide ) {
            $policies[] = '';
            $policies[] = I18n::get_label( 'ai_kb_header_local_guide' );
            $policies[] = (string) $local_guide;
        }

        $lines = [
            I18n::get_label( 'ai_kb_header_hotel_overview' ),
            'Hotel Name: ' . $hotel_name,
        ];
        if ( $address )  { $lines[] = 'Address: ' . $address; }
        if ( $phone )    { $lines[] = 'Phone: ' . $phone; }
        if ( $email )    { $lines[] = 'Email: ' . $email; }
        if ( $website )  { $lines[] = 'Website: ' . $website; }
        $lines[] = 'Check-in Time: ' . $checkin_time;
        $lines[] = 'Check-out Time: ' . $checkout_time;
        $lines[] = 'Currency: ' . $currency;
        if ( $amenities ) { $lines[] = 'Hotel Amenities: ' . $amenities; }
        if ( [] !== (array) $policies ) {
            $lines[] = '';
            $lines[] = I18n::get_label( 'ai_kb_header_policies' );
            foreach ( $policies as $p ) {
                $lines[] = $p;
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Build the rooms section from the plugin's custom DB tables.
     *
     * @return string
     */
    private static function build_rooms_section(): string {
        global $wpdb;

        // Fetch room types and individual rooms.
        $cache_key  = 'mhbo_kb_room_types';
        $room_types = wp_cache_get( $cache_key, 'mhbo' );

        if ( false === $room_types ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $room_types = $wpdb->get_results(
                "SELECT t.id, t.name, t.description, t.base_price, t.max_adults, t.max_children, t.amenities, t.image_url,
                        COUNT(r.id) AS total_rooms
                 FROM {$wpdb->prefix}mhbo_room_types t
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON r.type_id = t.id
                 GROUP BY t.id
                 ORDER BY t.id ASC"
            );
            wp_cache_set( $cache_key, $room_types, 'mhbo', 300 ); // 5min intermediate cache
        }

        if ( [] === (array) $room_types ) {
            return '';
        }

        $output = [];

        foreach ( $room_types as $type ) {
            $name        = I18n::decode( $type->name );
            $description = I18n::decode( $type->description );
            $amenities   = [];
            if ( $type->amenities ) {
                $a = json_decode( $type->amenities, true );
                if ( is_array( $a ) ) {
                    $amenities = $a;
                }
            }

            $block = [
                sprintf( I18n::get_label( 'ai_kb_header_rooms' ), $name ),
                I18n::get_label( 'ai_kb_label_type_id' ) . ': ' . $type->id,
                I18n::get_label( 'ai_kb_label_base_price' ) . ': ' . $type->base_price . ' ' . Pricing::get_currency_code(),
                I18n::get_label( 'ai_kb_label_max_adults' ) . ': ' . $type->max_adults,
                I18n::get_label( 'ai_kb_label_max_children' ) . ': ' . $type->max_children,
                I18n::get_label( 'ai_kb_label_total_rooms' ) . ': ' . $type->total_rooms,
            ];

            if ( $description ) {
                $block[] = 'Description: ' . wp_strip_all_tags( (string) $description );
            }
            if ( [] !== (array) $amenities ) {
                $block[] = 'Room Amenities: ' . implode( ', ', array_map( 'sanitize_text_field', (array) $amenities ) );
            }

            $output[] = implode( "\n", $block );
        }

        return implode( "\n\n", $output );
    }

    /**
     * Build the extras/add-ons section from Pro settings.
     *
     * Reads `mhbo_pro_extras` — the same option used by the booking form.
     * Returns empty string if extras are not configured or Pro is not active.
     *
     * @return string
     */
    private static function build_extras_section(): string {
        $extras = get_option( 'mhbo_pro_extras', [] );
        if ( ! is_array( $extras ) || [] === $extras ) {
            return '';
        }

        $currency = Pricing::get_currency_code();
        $lines    = [ I18n::get_label( 'ai_kb_header_extras' ) ];

        foreach ( $extras as $extra ) {
            if ( '' === (string) ( $extra['name'] ?? '' ) ) {
                continue;
            }

            $name         = sanitize_text_field( I18n::decode( (string) $extra['name'] ) );
            $price        = (float) ( $extra['price'] ?? 0 );
            $pricing_type = (string) ( $extra['pricing_type'] ?? $extra['type'] ?? 'fixed' );
            $desc         = sanitize_text_field( (string) ( $extra['description'] ?? '' ) );

            $type_label = match ( $pricing_type ) {
                'per_night'           => 'per night',
                'per_person'          => 'per person',
                'per_adult'           => 'per adult',
                'per_child'           => 'per child',
                'per_person_per_night'=> 'per person per night',
                default               => 'fixed price',
            };

            $line = "- {$name}: {$price} {$currency} ({$type_label})";
            if ( $desc ) {
                $line .= " — {$desc}";
            }
            $lines[] = $line;
        }

        return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
    }

    /**
     * Build the pricing rules section: taxes, min/max stay, seasonal pricing note.
     *
     * @return string
     */
    private static function build_pricing_section(): string {
        $lines = [ I18n::get_label( 'ai_kb_header_pricing' ) ];

        // Tax configuration.
        if ( Tax::is_enabled() ) {
            $tax_mode        = (string) get_option( 'mhbo_tax_mode', 'disabled' );
            $tax_label       = I18n::decode( (string) get_option( 'mhbo_tax_label', 'VAT' ) );
            $rate_accomm     = (float) get_option( 'mhbo_tax_rate_accommodation', 0 );
            $rate_extras_val = (float) get_option( 'mhbo_tax_rate_extras', 0 );
            $display_mode    = $tax_mode === 'vat' ? 'inclusive (already included in quoted prices)' : 'exclusive (added on top of quoted prices)';

            $lines[] = "Tax: {$tax_label} is {$display_mode}.";
            if ( $rate_accomm > 0 ) {
                $lines[] = "- Accommodation {$tax_label} rate: {$rate_accomm}%";
            }
            if ( $rate_extras_val > 0 ) {
                $lines[] = "- Extras/Add-ons {$tax_label} rate: {$rate_extras_val}%";
            }

            $reg_number = (string) get_option( 'mhbo_tax_registration_number', '' );
            if ( $reg_number ) {
                $lines[] = "- Tax registration number: {$reg_number}";
            }
        } else {
            $lines[] = 'Tax: Not applicable (no tax configured).';
        }

        // Min / max stay.
        $min_stay = (int) get_option( 'mhbo_min_stay', 1 );
        $max_stay = (int) get_option( 'mhbo_max_stay', 0 );
        if ( $min_stay > 1 ) {
            $lines[] = "Minimum stay: {$min_stay} nights.";
        }
        if ( $max_stay > 0 ) {
            $lines[] = "Maximum stay: {$max_stay} nights.";
        }

        // Seasonal / weekend pricing (note only — actual prices are live-computed per tool call).
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rule_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_pricing_rules" );
        if ( $rule_count > 0 ) {
            $lines[] = "Seasonal/Weekend Pricing: {$rule_count} active pricing rule(s) are configured. Prices quoted by the check_availability tool already reflect these rules — always use that tool for real-time accurate pricing.";
        }

        // Deposit (mirror what's in the system prompt, for completeness).
        if ( get_option( 'mhbo_deposits_enabled', 0 ) ) {
            $dep_type  = (string) get_option( 'mhbo_deposit_type', 'percentage' );
            $dep_value = (float)  get_option( 'mhbo_deposit_value', 20 );
            $non_r     = (bool)   get_option( 'mhbo_deposit_non_refundable', 0 );

            $dep_label = match ( $dep_type ) {
                'first_night' => 'first night\'s rate',
                'fixed'       => (float) $dep_value . ' ' . (string) Pricing::get_currency_code() . ' fixed',
                default       => (int) $dep_value . '% of total',
            };
            $refund_note = $non_r ? ' (non-refundable)' : ' (refundable on cancellation per policy)';
            $lines[]     = "Deposit required at booking: {$dep_label}{$refund_note}.";
        }

        return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
    }

    /**
     * Build the payment methods section from gateway settings.
     * Includes online gateways, bank transfer, and Revolut.
     *
     * @return string
     */
    private static function build_payment_section(): string {
        $methods = [];

        if ( (int) get_option( 'mhbo_gateway_stripe_enabled', 0 ) ) {
            $methods[] = 'Credit/Debit Card (Stripe — secure online payment)';
        }
        if ( (int) get_option( 'mhbo_gateway_paypal_enabled', 0 ) ) {
            $methods[] = 'PayPal';
        }
        if ( (int) get_option( 'mhbo_gateway_onsite_enabled', 0 ) ) {
            $methods[] = 'Pay on Arrival (payment collected at the property)';
        }

        // Bank transfer — from Business Info settings.
        $banking = Info::get_banking();
        if ( ( $banking['enabled'] ?? false ) && '' !== (string) ( $banking['bank_name'] ?? '' ) ) {
            $entry = 'Bank Transfer';
            if ( $banking['bank_name'] )  { $entry .= " — Bank: {$banking['bank_name']}"; }
            if ( $banking['account_name'] )    { $entry .= ", Account: {$banking['account_name']}"; }
            if ( $banking['iban'] )       { $entry .= ", IBAN: {$banking['iban']}"; }
            if ( $banking['swift_bic'] )  { $entry .= ", SWIFT/BIC: {$banking['swift_bic']}"; }
            if ( $banking['reference_prefix'] ) { $entry .= ", Reference prefix: {$banking['reference_prefix']}"; }
            if ( $banking['instructions'] ) { $entry .= ". Instructions: {$banking['instructions']}"; }
            $methods[] = $entry;
        }

        // Revolut.
        $revolut = Info::get_revolut();
        if ( ( $revolut['enabled'] ?? false ) && '' !== (string) ( $revolut['revolut_tag'] ?? '' ) ) {
            $entry = "Revolut (tag: {$revolut['revolut_tag']})";
            if ( $revolut['revolut_iban'] ) { $entry .= ", IBAN: {$revolut['revolut_iban']}"; }
            if ( $revolut['revolut_link'] ) { $entry .= ", link: {$revolut['revolut_link']}"; }
            if ( $revolut['qr_code_url'] )  { $entry .= ", QR code available at: {$revolut['qr_code_url']}"; }
            if ( $revolut['instructions'] ) { $entry .= ". Instructions: {$revolut['instructions']}"; }
            $methods[] = $entry;
        }

        if ( [] === $methods ) {
            return '';
        }

        $lines   = [ I18n::get_label( 'ai_kb_header_payment' ) ];
        $lines[] = 'Accepted payment methods:';
        foreach ( $methods as $m ) {
            $lines[] = '- ' . $m;
        }

        return implode( "\n", $lines );
    }

    /**
     * Build the contact / WhatsApp section so the AI can route guests accurately.
     *
     * @return string
     */
    private static function build_contact_section(): string {
        $wa = Info::get_whatsapp();
        if ( ! ( $wa['enabled'] ?? false ) || '' === (string) ( $wa['phone_number'] ?? '' ) ) {
            return '';
        }

        $lines   = [ I18n::get_label( 'ai_kb_header_contact' ) ];
        $lines[] = "WhatsApp: {$wa['phone_number']}";
        if ( '' !== (string) ( $wa['default_msg'] ?? '' ) ) {
            $lines[] = "Default greeting message: {$wa['default_msg']}";
        }
        $lines[] = 'Guests can reach us directly on WhatsApp for urgent requests or booking assistance.';

        return implode( "\n", $lines );
    }

    /**
     * Build the pages/posts section from WP content.
     *
     * @return string
     */
    private static function build_pages_section(): string {
        $query = new WP_Query( [
            'post_type'      => [ 'page', 'post' ],
            'post_status'    => 'publish',
            'posts_per_page' => self::MAX_POSTS,
            'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
            'no_found_rows'  => true,
        ] );

        if ( ! $query->have_posts() ) {
            return '';
        }

        $output = [];

        foreach ( $query->posts as $post ) {
            if ( ! ( $post instanceof WP_Post ) ) {
                continue;
            }
            $title     = (string) get_the_title( $post );
            $permalink = (string) get_permalink( $post );

            // Optimization 2026: Bypass the full 'the_content' filter stack to prevent timeouts/memory bloat.
            // We use strip_shortcodes and wp_strip_all_tags for a lightweight version.
            $content = (string) strip_shortcodes( (string) $post->post_content );
            $content = (string) wp_strip_all_tags( (string) $content );
            
            // Collapse whitespace.
            $content = (string) preg_replace( '/\s+/', ' ', (string) $content );
            $content = trim( (string) $content );

            if ( '' === $content ) {
                continue;
            }

            if ( strlen( $content ) > 50000 ) {
                $content = substr( $content, 0, 50000 ); // Fast safety ceiling before expensive mb_ operations
            }

            if ( mb_strlen( (string) $content ) > (int) self::MAX_PAGE_CHARS ) {
                $content = mb_substr( (string) $content, 0, (int) self::MAX_PAGE_CHARS ) . '…';
            }

            $label       = ( 'page' === $post->post_type ) ? I18n::get_label( 'ai_kb_header_page' ) : I18n::get_label( 'ai_kb_header_post' );
            $header_text = sprintf( $label, $title );

            $output[] = implode( "\n", [
                $header_text,
                'URL: ' . $permalink,
                $content,
            ] );
        }

        wp_reset_postdata();

        return implode( "\n\n", $output );
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Register WordPress hooks.
     * Called from the AI Loader on init.
     */
    public static function register_hooks(): void {
        add_action( 'save_post', [ self::class, 'on_save_post' ], 10, 1 );
        add_action( 'mhbo_room_saved', [ self::class, 'clear_cache' ] );
        add_action( 'mhbo_room_type_saved', [ self::class, 'clear_cache' ] );
    }

    /**
     * Clear KB cache when a relevant post is saved.
     *
     * @param int $post_id
     */
    public static function on_save_post( int $post_id ): void {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        $post_type = get_post_type( $post_id );
        if ( in_array( $post_type, [ 'page', 'post' ], true ) ) {
            self::clear_cache();
        }
    }
}
