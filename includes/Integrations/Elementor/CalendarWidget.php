<?php declare(strict_types=1);

namespace MHBO\Integrations\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;
use MHBO\Core\I18n;

/**
 * Availability Calendar Elementor Widget (Free Edition).
 *
 * @package MHBO\Integrations\Elementor
 * @since   2.4.8
 */
class CalendarWidget extends BaseWidget
{
    public function get_name(): string
    {
        return 'mhbo_calendar';
    }

    public function get_title(): string
    {
        return (string) I18n::get_label('label_elementor_calendar');
    }

    public function get_icon(): string
    {
        return 'eicon-calendar';
    }

    public function get_script_depends(): array
    {
        return ['mhbo-calendar-js'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section(
            'section_content',
            array(
                'label' => 'Calendar Settings',
            )
        );

        $this->add_control(
            'room_id',
            array(
                'label'       => 'Filter by Specific Room ID',
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => 'Leave blank for all rooms',
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style',
            array(
                'label' => 'Calendar Styling',
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'calendar_theme',
            array(
                'label'   => 'Theme',
                'type'    => Controls_Manager::SELECT,
                'default' => 'light',
                'options' => array(
                    'light' => 'Light',
                    'dark'  => 'Dark',
                ),
                'selectors' => array(
                    '{{WRAPPER}}' => '--mhbo-calendar-theme: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'available_color',
            array(
                'label'     => 'Available Color',
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}}' => '--mhbo-cal-available: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'unavailable_color',
            array(
                'label'     => 'Unavailable Color',
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}}' => '--mhbo-cal-unavailable: {{VALUE}};',
                ),
            )
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $room_id  = (isset($settings['room_id']) && '' !== (string) $settings['room_id']) ? absint($settings['room_id']) : 0;

        echo '<div class="mhbo-elementor-calendar-wrapper">';
        if ($room_id > 0) {
            echo do_shortcode(sprintf('[mhbo_room_calendar room_id="%d"]', $room_id));
        } else {
            echo do_shortcode('[mhbo_room_calendar]');
        }
        echo '</div>';
    }
}
