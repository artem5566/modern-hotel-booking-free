<?php declare(strict_types=1);

namespace MHBO\Integrations\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Box_Shadow;
use MHBO\Core\I18n;

/**
 * Room Grid Showcase Elementor Widget (Free Edition).
 *
 * @package MHBO\Integrations\Elementor
 * @since   2.4.8
 */
class RoomGridWidget extends BaseWidget
{
    public function get_name(): string
    {
        return 'mhbo_room_grid';
    }

    public function get_title(): string
    {
        return (string) I18n::get_label('label_elementor_room_grid');
    }

    public function get_icon(): string
    {
        return 'eicon-posts-grid';
    }

    protected function register_controls(): void
    {
        $this->start_controls_section(
            'section_content',
            array(
                'label' => 'Display Settings',
            )
        );

        $this->add_control(
            'columns',
            array(
                'label'   => 'Grid Columns',
                'type'    => Controls_Manager::SELECT,
                'default' => '3',
                'options' => array(
                    '1' => '1 Column',
                    '2' => '2 Columns',
                    '3' => '3 Columns',
                    '4' => '4 Columns',
                ),
            )
        );

        $this->add_control(
            'limit',
            array(
                'label'   => 'Max Rooms to Show',
                'type'    => Controls_Manager::NUMBER,
                'default' => 6,
                'min'     => 1,
                'max'     => 50,
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style',
            array(
                'label' => 'Grid Styling',
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'grid_gap',
            array(
                'label'      => 'Grid Gap',
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px', 'em', '%'),
                'selectors'  => array(
                    '{{WRAPPER}}' => '--mhbo-grid-gap: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_control(
            'card_background',
            array(
                'label'     => 'Card Background',
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}}' => '--mhbo-card-bg: {{VALUE}};',
                ),
            )
        );
        
        $this->add_control(
            'badge_color',
            array(
                'label'     => 'Badge Color',
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}}' => '--mhbo-badge-color: {{VALUE}};',
                ),
            )
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            array(
                'name'     => 'card_box_shadow',
                'label'    => 'Card Shadow',
                'selector' => '{{WRAPPER}} .mhbo-elementor-room-grid-wrapper .mhbo-room-card',
            )
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $columns  = (isset($settings['columns']) && '' !== (string) $settings['columns']) ? absint($settings['columns']) : 3;
        $limit    = (isset($settings['limit']) && '' !== (string) $settings['limit']) ? absint($settings['limit']) : 6;

        echo '<div class="mhbo-elementor-room-grid-wrapper">';
        echo do_shortcode(sprintf('[mhbo_rooms columns="%d" limit="%d"]', $columns, $limit));
        echo '</div>';
    }
}
