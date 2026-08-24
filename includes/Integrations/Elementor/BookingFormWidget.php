<?php declare(strict_types=1);

namespace MHBO\Integrations\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use MHBO\Core\I18n;

/**
 * Booking Form Elementor Widget (Free Edition).
 *
 * @package MHBO\Integrations\Elementor
 * @since   2.4.8
 */
class BookingFormWidget extends BaseWidget
{
    public function get_name(): string
    {
        return 'mhbo_booking_form';
    }

    public function get_title(): string
    {
        return (string) I18n::get_label('label_elementor_booking_form');
    }

    public function get_icon(): string
    {
        return 'eicon-calendar';
    }

    public function get_script_depends(): array
    {
        // Enqueue the calendar JS which handles the Booking form and modal UI
        return ['mhbo-calendar-js'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section(
            'section_content',
            array(
                'label' => 'Form Settings',
            )
        );

        $this->add_control(
            'title',
            array(
                'label'       => 'Widget Header Title',
                'type'        => Controls_Manager::TEXT,
                'default'     => 'Book Your Stay',
                'placeholder' => 'Enter section title',
            )
        );

        $this->add_control(
            'layout',
            array(
                'label'   => 'Form Layout Style',
                'type'    => Controls_Manager::SELECT,
                'default' => 'vertical',
                'options' => array(
                    'vertical'   => 'Vertical Card (Default)',
                    'horizontal' => 'Horizontal Bar',
                    'inline'     => 'Inline Header Search',
                ),
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style',
            array(
                'label' => 'Form Styling',
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'primary_color',
            array(
                'label'     => 'Primary Color',
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}}' => '--mhbo-primary-color: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'button_text_color',
            array(
                'label'     => 'Button Text Color',
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}}' => '--mhbo-button-text-color: {{VALUE}};',
                ),
            )
        );
        
        $this->add_control(
            'border_radius',
            array(
                'label'      => 'Border Radius',
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px', 'em', '%'),
                'selectors'  => array(
                    '{{WRAPPER}}' => '--mhbo-border-radius: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            array(
                'name'     => 'form_typography',
                'label'    => 'Typography',
                'selector' => '{{WRAPPER}} .mhbo-elementor-booking-form-wrapper',
            )
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $title    = (isset($settings['title']) && '' !== (string) $settings['title']) ? sanitize_text_field($settings['title']) : '';
        $layout   = (isset($settings['layout']) && '' !== (string) $settings['layout']) ? sanitize_key($settings['layout']) : 'vertical';

        echo '<div class="mhbo-elementor-booking-form-wrapper mhbo-layout-' . esc_attr($layout) . '">';
        if ('' !== $title) {
            echo '<h3 class="mhbo-widget-title">' . esc_html($title) . '</h3>';
        }

        // Render standard booking search form shortcode with layout attribute
        echo do_shortcode('[mhbo_booking_form layout="' . esc_attr($layout) . '"]');
        echo '</div>';
    }
}
