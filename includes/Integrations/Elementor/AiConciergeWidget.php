<?php declare(strict_types=1);

namespace MHBO\Integrations\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use MHBO\Core\I18n;

/**
 * AI Concierge Assistant Elementor Widget (Free Edition).
 *
 * @package MHBO\Integrations\Elementor
 * @since   2.4.8
 */
class AiConciergeWidget extends BaseWidget
{
    public function get_name(): string
    {
        return 'mhbo_ai_concierge';
    }

    public function get_title(): string
    {
        return (string) I18n::get_label('label_elementor_ai_concierge');
    }

    public function get_icon(): string
    {
        return 'eicon-comments';
    }

    protected function register_controls(): void
    {
        $this->start_controls_section(
            'section_content',
            array(
                'label' => 'Chatbot Settings',
            )
        );

        $this->add_control(
            'display_mode',
            array(
                'label'   => 'Display Mode',
                'type'    => Controls_Manager::SELECT,
                'default' => 'embedded',
                'options' => array(
                    'embedded' => 'Embedded Inline Card',
                    'floating' => 'Floating Bottom-Right Bubble',
                ),
            )
        );

        $this->add_control(
            'welcome_message',
            array(
                'label'       => 'Welcome Message Override',
                'type'        => Controls_Manager::TEXTAREA,
                'default'     => '',
                'placeholder' => 'Hello! How can I help you book a room?',
                'dynamic'     => array(
                    'active' => true,
                ),
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style',
            array(
                'label' => 'Chatbot Styling',
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'primary_color',
            array(
                'label'     => 'Primary Color',
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}}' => '--mhbo-ai-primary: {{VALUE}};',
                ),
            )
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings        = $this->get_settings_for_display();
        $display_mode    = (isset($settings['display_mode']) && '' !== (string) $settings['display_mode']) ? sanitize_key($settings['display_mode']) : 'embedded';
        $welcome_message = (isset($settings['welcome_message']) && '' !== (string) $settings['welcome_message']) ? sanitize_textarea_field($settings['welcome_message']) : '';

        echo '<div class="mhbo-elementor-ai-concierge-wrapper mhbo-mode-' . esc_attr($display_mode) . '">';
        $shortcode = '[mhbo_ai_concierge mode="' . esc_attr($display_mode) . '"';
        if ('' !== $welcome_message) {
            $shortcode .= ' welcome_message="' . esc_attr($welcome_message) . '"';
        }
        $shortcode .= ']';
        echo do_shortcode($shortcode);
        echo '</div>';
    }
}
