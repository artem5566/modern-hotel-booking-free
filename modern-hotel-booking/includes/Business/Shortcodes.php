<?php
/**
 * Business Shortcodes
 *
 * All shortcodes for company info, WhatsApp, banking, Revolut, and combined views.
 * Namespace-aligned with PSR-4 autoloader.
 *
 * @package ModernHotelBooking
 * @since   2.1.0
 */

declare(strict_types=1);

namespace MHBO\Business;

if (!defined('ABSPATH')) exit;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shortcodes {

    /** @var self|null */
    private static ?self $instance = null;

    /**
     * Allowed SVG tags and attributes for wp_kses().
     *
     * @var array
     */
    const ALLOWED_SVG = array(
        'svg'  => array(
            'class'       => true,
            'viewbox'     => true,
            'width'       => true,
            'height'      => true,
            'fill'        => true,
            'aria-hidden' => true,
        ),
        'path' => array(
            'd'    => true,
            'fill' => true,
        ),
    );

    /** @var string Copy icon SVG */
    const COPY_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" class="mhbo-icon-copy"><path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>';

    /** @return self */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'mhbo_company_info',    array( $this, 'render_company_info' ) );
        add_shortcode( 'mhbo_whatsapp',        array( $this, 'render_whatsapp' ) );
        add_shortcode( 'mhbo_banking_details', array( $this, 'render_banking' ) );
        add_shortcode( 'mhbo_revolut_details', array( $this, 'render_revolut' ) );
        add_shortcode( 'mhbo_business_card',    array( $this, 'render_business_card' ) );
        add_shortcode( 'mhbo_payment_methods', array( $this, 'render_payment_methods' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
    }

    public function enqueue_frontend_assets(): void {
        wp_enqueue_style(
            'mhbo-business-front',
            MHBO_PLUGIN_URL . 'assets/css/mhbo-business-front.css',
            array(),
            '2.3.0'
        );

        wp_enqueue_script(
            'mhbo-business-front',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-business-front.js',
            array(),
            '2.3.0',
            true
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       SHORTCODE RENDERS
       ═══════════════════════════════════════════════════════════════ */

    /**
     * [mhbo_company_info]
     *
     * @param array|string $atts {
     *     @type string $show_logo        'yes'|'no'
     *     @type string $show_address     'yes'|'no'
     *     @type string $show_contact     'yes'|'no'
     *     @type string $show_registration 'yes'|'no'
     *     @type string $layout           'vertical'|'horizontal'
     * }
     * @return string HTML
     */
    public function render_company_info( $atts ): string {
        $atts = shortcode_atts( array(
            'show_logo'         => 'yes',
            'show_address'      => 'yes',
            'show_contact'      => 'yes',
            'show_registration' => 'no',
            'layout'            => 'vertical',
        ), $atts, 'mhbo_company_info' );

        $data = Info::get_company();
        if ( '' === (string) ($data['company_name'] ?? '') && '' === (string) ($data['logo_url'] ?? '') ) {
            return '';
        }

        ob_start();
        ?>
        <div class="mhbo-company-info mhbo-card-premium mhbo-layout-<?php echo esc_attr( $atts['layout'] ); ?>">
            <?php if ( 'yes' === $atts['show_logo'] && '' !== (string) ($data['logo_url'] ?? '') ) : ?>
                <div class="mhbo-logo">
                    <img src="<?php echo esc_url( $data['logo_url'] ); ?>" alt="<?php echo esc_attr( $data['company_name'] ); ?>" />
                </div>
            <?php endif; ?>

            <div class="mhbo-details">
                <h3 class="mhbo-company-name"><?php echo esc_html( $data['company_name'] ); ?></h3>

                <?php if ( 'yes' === $atts['show_address'] ) : ?>
                    <address class="mhbo-address">
                        <?php echo esc_html( $data['address_line_1'] ?? '' ); ?><br>
                        <?php if ( '' !== (string) ($data['address_line_2'] ?? '') ) : ?>
                            <?php echo esc_html( $data['address_line_2'] ); ?><br>
                        <?php endif; ?>
                        <?php echo esc_html( $data['city'] ); ?>, <?php echo esc_html( $data['state'] ); ?> <?php echo esc_html( $data['postcode'] ); ?><br>
                        <?php echo esc_html( $data['country'] ); ?>
                    </address>
                <?php endif; ?>

                <div class="mhbo-business-meta">
                    <?php if ( 'yes' === $atts['show_contact'] ) : ?>
                        <div class="mhbo-contact">
                            <?php if ( '' !== (string) ($data['telephone'] ?? '') ) : ?>
                                <p><span class="mhbo-meta-label"><?php esc_html_e( 'Tel:', 'modern-hotel-booking' ); ?></span> <a href="tel:<?php echo esc_attr( $data['telephone'] ); ?>"><?php echo esc_html( $data['telephone'] ); ?></a></p>
                            <?php endif; ?>
                            <?php if ( '' !== (string) ($data['email'] ?? '') ) : ?>
                                <p><span class="mhbo-meta-label"><?php esc_html_e( 'Email:', 'modern-hotel-booking' ); ?></span> <a href="mailto:<?php echo esc_attr( $data['email'] ); ?>"><?php echo esc_html( $data['email'] ); ?></a></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( 'yes' === $atts['show_registration'] ) : ?>
                        <div class="mhbo-legal">
                            <?php if ( '' !== (string) ($data['tax_id'] ?? '') ) : ?>
                                <p><span class="mhbo-meta-label"><?php esc_html_e( 'Tax ID:', 'modern-hotel-booking' ); ?></span> <?php echo esc_html( $data['tax_id'] ); ?></p>
                            <?php endif; ?>
                            <?php if ( '' !== (string) ($data['registration_no'] ?? '') ) : ?>
                                <p><span class="mhbo-meta-label"><?php esc_html_e( 'Reg:', 'modern-hotel-booking' ); ?></span> <?php echo esc_html( $data['registration_no'] ); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * [mhbo_whatsapp]
     *
     * @param array|string $atts {
     *     @type string $style   'button'|'link'|'floating'
     *     @type string $text    Button text
     *     @type string $message Pre-filled message
     * }
     * @return string HTML
     */
    public function render_whatsapp( $atts ): string {
        $data = Info::get_whatsapp();
        if ( ! (bool) ($data['enabled'] ?? false) || '' === (string) ($data['phone_number'] ?? '') ) {
            return '';
        }

        $atts = shortcode_atts( array(
            'style'   => $data['display_style'],
            'text'    => $data['button_text'],
            'message' => $data['default_msg'],
        ), $atts, 'mhbo_whatsapp' );

        $wa_url = 'https://wa.me/' . preg_replace( '/[^\d]/', '', (string) ($data['phone_number'] ?? '') );
        if ( '' !== (string) ($atts['message'] ?? '') ) {
            $wa_url = add_query_arg( 'text', rawurlencode( (string) $atts['message'] ), $wa_url );
        }

        ob_start();
        $class = 'mhbo-whatsapp-link mhbo-wa-' . $atts['style'];
        if ( 'floating' === $atts['style'] ) {
            $class .= ' mhbo-wa-pos-' . $data['position'];
        }

        $svg = '<svg viewBox="0 0 448 512" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.7 17.8 69.4 27.2 106.2 27.2 122.4 0 222-99.6 222-222 0-59.3-23-115.1-65-157.1zM223.9 446.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3 18.7-68.1-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 54 81.2 54 130.5 0 101.7-82.8 184.5-184.6 184.5zm101.3-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-5.5-2.8-23.2-8.5-44.2-27.1-16.4-14.6-27.4-32.6-30.6-38.1-3.2-5.6-.3-8.6 2.4-11.3 2.5-2.4 5.5-6.5 8.3-9.7 2.8-3.3 3.7-5.6 5.6-9.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 13.2 5.8 23.5 9.2 31.6 11.8 13.3 4.2 25.4 3.6 35 2.2 10.7-1.5 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>';
        ?>
        <a href="<?php echo esc_url( $wa_url ); ?>" class="<?php echo esc_attr( $class ); ?>" target="_blank" rel="noopener">
            <?php echo wp_kses( $svg, self::ALLOWED_SVG ); ?>
            <span><?php echo esc_html( $atts['text'] ); ?></span>
        </a>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * [mhbo_banking_details]
     * 
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_banking( $atts ): string {
        $data = Info::get_banking();
        if ( ! (bool) ($data['enabled'] ?? false) || '' === (string) ($data['iban'] ?? '') ) {
            return '';
        }

        $atts = shortcode_atts( array(
            'show_instructions' => 'yes',
            'booking_id'        => 0,
            'layout'            => 'card',
        ), $atts, 'mhbo_banking_details' );

        $reference = $data['reference_prefix'] . ( $atts['booking_id'] ?: 'XXXX' );

        ob_start();
        ?>
        <div class="mhbo-banking-details mhbo-card-premium mhbo-style-<?php echo esc_attr( $atts['layout'] ); ?>">
            <div class="mhbo-card-header">
                <div class="mhbo-icon-wrapper"><?php echo wp_kses( '<svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M11.5 1L2 6v2h19V6m-5 4v7h3v-7m-5 0v7h3v-7m-5 0v7h3v-7M2 19v2h19v-2H2z"/></svg>', self::ALLOWED_SVG ); ?></div>
                <h4><?php echo esc_html( $data['bank_name'] ?: __( 'Bank Transfer Details', 'modern-hotel-booking' ) ); ?></h4>
            </div>
            <div class="mhbo-grid">
                <?php if ( '' !== (string) ($data['account_name'] ?? '') ) : ?>
                    <div class="mhbo-field">
                        <strong><?php esc_html_e( 'Account Holder', 'modern-hotel-booking' ); ?></strong>
                        <span><?php echo esc_html( $data['account_name'] ); ?></span>
                    </div>
                <?php endif; ?>
                <div class="mhbo-field">
                    <strong><?php esc_html_e( 'IBAN', 'modern-hotel-booking' ); ?></strong>
                    <div class="mhbo-copy-wrapper">
                        <code class="mhbo-copyable"><?php echo esc_html( $data['iban'] ); ?></code>
                        <button class="mhbo-copy-btn" data-copy="<?php echo esc_attr( $data['iban'] ); ?>" title="<?php esc_attr_e( 'Copy IBAN', 'modern-hotel-booking' ); ?>">
                            <?php echo wp_kses( self::COPY_ICON, self::ALLOWED_SVG ); ?>
                        </button>
                    </div>
                </div>
                <?php if ( '' !== (string) ($data['swift_bic'] ?? '') ) : ?>
                    <div class="mhbo-field">
                        <strong><?php esc_html_e( 'SWIFT/BIC', 'modern-hotel-booking' ); ?></strong>
                        <div class="mhbo-copy-wrapper">
                            <code><?php echo esc_html( $data['swift_bic'] ); ?></code>
                            <button class="mhbo-copy-btn" data-copy="<?php echo esc_attr( $data['swift_bic'] ); ?>" title="<?php esc_attr_e( 'Copy SWIFT/BIC', 'modern-hotel-booking' ); ?>">
                                <?php echo wp_kses( self::COPY_ICON, self::ALLOWED_SVG ); ?>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="mhbo-field">
                    <strong><?php esc_html_e( 'Reference', 'modern-hotel-booking' ); ?></strong>
                    <div class="mhbo-copy-wrapper">
                        <code class="mhbo-ref"><?php echo esc_html( $reference ); ?></code>
                        <button class="mhbo-copy-btn" data-copy="<?php echo esc_attr( $reference ); ?>" title="<?php esc_attr_e( 'Copy Reference', 'modern-hotel-booking' ); ?>">
                            <?php echo wp_kses( self::COPY_ICON, self::ALLOWED_SVG ); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php if ( 'yes' === $atts['show_instructions'] && '' !== (string) ($data['instructions'] ?? '') ) : ?>
                <div class="mhbo-instructions"><?php echo wp_kses_post( (string) $data['instructions'] ); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * [mhbo_revolut_details]
     * 
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_revolut( $atts ): string {
        $data = Info::get_revolut();
        if ( ! (bool) ($data['enabled'] ?? false) || ( '' === (string) ($data['revolut_tag'] ?? '') && '' === (string) ($data['revolut_iban'] ?? '') ) ) {
            return '';
        }

        $atts = shortcode_atts( array(
            'show_qr'   => 'yes',
            'show_link' => 'yes',
            'layout'    => 'card',
        ), $atts, 'mhbo_revolut_details' );

        ob_start();
        ?>
        <div class="mhbo-revolut-details mhbo-card-premium mhbo-style-<?php echo esc_attr( $atts['layout'] ); ?>">
            <div class="mhbo-rev-header">
                <span class="mhbo-rev-badge"><?php echo wp_kses( '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm1-13h-2v5H6v2h5v5h2v-5h5v-2h-5z"/></svg>', self::ALLOWED_SVG ); ?></span>
                <h4><?php echo esc_html( $data['revolut_name'] ?: __( 'Revolut Payment', 'modern-hotel-booking' ) ); ?></h4>
            </div>
            <div class="mhbo-flex">
                <div class="mhbo-rev-info">
                    <?php if ( '' !== (string) ($data['revolut_tag'] ?? '') ) : ?>
                        <div class="mhbo-field">
                            <strong><?php esc_html_e( 'Revtag', 'modern-hotel-booking' ); ?></strong>
                            <div class="mhbo-copy-wrapper">
                                <code><?php echo esc_html( $data['revolut_tag'] ); ?></code>
                                <button class="mhbo-copy-btn" data-copy="<?php echo esc_attr( $data['revolut_tag'] ); ?>" title="<?php esc_attr_e( 'Copy Revtag', 'modern-hotel-booking' ); ?>">
                                    <?php echo wp_kses( self::COPY_ICON, self::ALLOWED_SVG ); ?>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ( '' !== (string) ($data['revolut_iban'] ?? '') ) : ?>
                        <div class="mhbo-field">
                            <strong><?php esc_html_e( 'IBAN', 'modern-hotel-booking' ); ?></strong>
                            <div class="mhbo-copy-wrapper">
                                <code class="mhbo-copyable"><?php echo esc_html( $data['revolut_iban'] ); ?></code>
                                <button class="mhbo-copy-btn" data-copy="<?php echo esc_attr( $data['revolut_iban'] ); ?>" title="<?php esc_attr_e( 'Copy IBAN', 'modern-hotel-booking' ); ?>">
                                    <?php echo wp_kses( self::COPY_ICON, self::ALLOWED_SVG ); ?>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ( 'yes' === $atts['show_link'] && $data['revolut_link'] ) : ?>
                        <a href="<?php echo esc_url( $data['revolut_link'] ); ?>" class="mhbo-btn-premium mhbo-rev-btn" target="_blank">
                            <span><?php esc_html_e( 'Pay via Revolut.me', 'modern-hotel-booking' ); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
                <?php if ( 'yes' === $atts['show_qr'] && $data['qr_code_url'] ) : ?>
                    <div class="mhbo-rev-qr">
                        <img src="<?php echo esc_url( $data['qr_code_url'] ); ?>" alt="Revolut QR" />
                    </div>
                <?php endif; ?>
            </div>
            <?php if ( '' !== (string) ($data['instructions'] ?? '') ) : ?>
                <div class="mhbo-instructions"><?php echo wp_kses_post( (string) $data['instructions'] ); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * [mhbo_business_card]
     * 
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_business_card( $atts ): string {
        $atts = shortcode_atts( array(
            'sections' => 'company,whatsapp,banking,revolut',
        ), $atts, 'mhbo_business_card' );

        $sections = explode( ',', $atts['sections'] );
        $output   = '<div class="mhbo-business-card">';

        foreach ( $sections as $section ) {
            $section = trim( (string) $section );
            switch ( $section ) {
                case 'company':
                    $output .= $this->render_company_info( array( 'layout' => 'horizontal' ) );
                    break;
                case 'whatsapp':
                    $output .= $this->render_whatsapp( array( 'style' => 'button' ) );
                    break;
                case 'banking':
                    $output .= $this->render_banking( array( 'layout' => 'inline' ) );
                    break;
                case 'revolut':
                    $output .= $this->render_revolut( array( 'layout' => 'inline', 'show_qr' => 'no' ) );
                    break;
            }
        }

        $output .= '</div>';
        return $output;
    }

    /**
     * [mhbo_payment_methods]
     * 
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function render_payment_methods( $atts ): string {
        $atts = shortcode_atts( array(
            'booking_id' => 0,
        ), $atts, 'mhbo_payment_methods' );

        $output = '';
        $bank   = $this->render_banking( array( 'booking_id' => $atts['booking_id'] ) );
        $rev    = $this->render_revolut( array() );

        if ( (bool) $bank || (bool) $rev ) {
            $output .= '<div class="mhbo-payment-methods-grid">';
            if ( $bank ) {
                $output .= '<div class="mhbo-pm-col">' . $bank . '</div>';
            }
            if ( $rev ) {
                $output .= '<div class="mhbo-pm-col">' . $rev . '</div>';
            }
            $output .= '</div>';
        }

        return $output;
    }
}
