<?php declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        exit;
    }
}

namespace Elementor {

    if (!class_exists('\\Elementor\\Widget_Base')) {
        abstract class Widget_Base {
            public function get_name(): string { return ''; }
            public function get_title(): string { return ''; }
            public function get_icon(): string { return ''; }
            /** @return array<mixed> */ public function get_categories(): array { return array(); }
            /** @return array<mixed> */ public function get_keywords(): array { return array(); }
            /** @param array<mixed> $args */ protected function start_controls_section(string $id, array $args = array()): void {}
            /** @param array<mixed> $args */ protected function add_control(string $id, array $args = array()): void {}
            protected function end_controls_section(): void {}
            /** @param array<mixed> $args */ protected function add_group_control(string $type, array $args = array()): void {}
            /** @return array<mixed> */ protected function get_settings_for_display(): array { return array(); }
        }
    }

    if (!class_exists('\\Elementor\\Controls_Manager')) {
        class Controls_Manager {
            public const TEXT = 'text';
            public const TEXTAREA = 'textarea';
            public const SELECT = 'select';
            public const NUMBER = 'number';
            public const SWITCHER = 'switcher';
            public const COLOR = 'color';
            public const SLIDER = 'slider';
            public const TAB_CONTENT = 'content';
            public const TAB_STYLE = 'style';
            public const TAB_ADVANCED = 'advanced';
        }
    }

    if (!class_exists('\\Elementor\\Group_Control_Typography')) {
        class Group_Control_Typography {
            public static function get_type(): string { return 'typography'; }
        }
    }

    if (!class_exists('\\Elementor\\Group_Control_Box_Shadow')) {
        class Group_Control_Box_Shadow {
            public static function get_type(): string { return 'box_shadow'; }
        }
    }

    if (!class_exists('\\Elementor\\Elements_Manager')) {
        class Elements_Manager {
            /** @param array<mixed> $args */ public function add_category(string $id, array $args = array()): void {}
        }
    }

    if (!class_exists('\\Elementor\\Widgets_Manager')) {
        class Widgets_Manager {
            public function register(object $widget): void {}
        }
    }
}

namespace MHBO\Integrations\Elementor {

    if (!defined('ABSPATH')) {
        exit;
    }

    /**
     * Base Widget Class for MHBO Elementor Widgets.
     *
     * @package MHBO\Integrations\Elementor
     * @since   2.4.8
     */
    abstract class BaseWidget extends \Elementor\Widget_Base
    {
        /**
         * Get widget categories.
         *
         * @return array<int, string>
         */
        public function get_categories(): array
        {
            return array('modern-hotel-booking');
        }

        /**
         * Get widget keywords.
         *
         * @return array<int, string>
         */
        public function get_keywords(): array
        {
            return array('hotel', 'booking', 'room', 'reservation', 'mhbo');
        }

        /**
         * Get style dependencies.
         *
         * @return array<int, string>
         */
        public function get_style_depends(): array
        {
            return array('mhbo-google-fonts', 'mhbo-flatpickr-css', 'mhbo-style');
        }

        /**
         * Get script dependencies.
         *
         * @return array<int, string>
         */
        public function get_script_depends(): array
        {
            return array('mhbo-flatpickr-js', 'mhbo-frontend');
        }
    }
}
