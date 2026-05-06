<?php
declare(strict_types=1);
/**
 * Chat Widget Template
 *
 * Outputs the server-side widget container div and structured data.
 * The JS in mhbo-chat-widget.js picks up .mhbo-chat-widget containers.
 *
 * @package modern-hotel-booking
 * @since 2.3.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MHBO\Business\Info;
use MHBO\Core\License;

$mhbo_company      = Info::get_company();
$mhbo_hotel_name   = $mhbo_company['company_name'] ?: get_bloginfo( 'name' );
$mhbo_position     = (string) get_option( 'mhbo_ai_widget_position', 'bottom-right' );
$mhbo_welcome      = (string) get_option( 'mhbo_ai_welcome_message', '' );
$mhbo_theme        = (string) get_option( 'mhbo_ai_theme', '' );

$mhbo_theme_class  = $mhbo_theme ? ' mhbo-theme-' . sanitize_html_class( $mhbo_theme ) : '';

$mhbo_booking_page_url = get_permalink( get_option( 'mhbo_booking_page', 0 ) ) ?: home_url( '/' );

// Schema.org CommunicateAction structured data.
$mhbo_schema = [
    '@context' => 'https://schema.org',
    '@type'    => 'CommunicateAction',
    // translators: %s: hotel name
    'name'     => sprintf( __( 'Chat with %s AI Concierge', 'modern-hotel-booking' ), (string) $mhbo_hotel_name ),
    'agent'    => [
        '@type' => 'Hotel',
        'name'  => $mhbo_hotel_name,
    ],
    'description' => __( 'Real-time AI chat assistant for room availability, policies, and booking support.', 'modern-hotel-booking' ),
];
?>

<!-- MHBO AI Concierge Widget -->
<div
    class="mhbo-chat-widget<?php echo $mhbo_theme_class; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via esc_attr() above ?>"
    data-position="<?php echo esc_attr( $mhbo_position ); ?>"
    data-variant="floating"
    <?php if ( $mhbo_welcome ) : ?>data-welcome-message="<?php echo esc_attr( $mhbo_welcome ); ?>"<?php endif; ?>
    role="complementary"
    aria-label="<?php esc_attr_e( 'AI Concierge Chat', 'modern-hotel-booking' ); ?>"
>
    <!-- Populated by mhbo-chat-widget.js -->
    <noscript>
        <p style="display:none;">
            <?php
            printf(
                /* translators: %s: booking page URL */
                esc_html__( 'JavaScript is required for the AI Concierge. You can also %s.', 'modern-hotel-booking' ),
                '<a href="' . esc_url( $mhbo_booking_page_url ) . '">' . esc_html__( 'book directly here', 'modern-hotel-booking' ) . '</a>'
            );
            ?>
        </p>
    </noscript>
</div>

<script type="application/ld+json">
<?php echo wp_json_encode( $mhbo_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with JSON_HEX_TAG is safe in script context ?>
</script>
