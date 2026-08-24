<?php declare(strict_types=1);

namespace MHBO\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\I18n;
use MHBO\Integrations\Elementor\BookingFormWidget;
use MHBO\Integrations\Elementor\RoomGridWidget;
use MHBO\Integrations\Elementor\CalendarWidget;

/**
 * Elementor Integration Manager.
 *
 * @package MHBO\Integrations
 * @since   2.4.8
 */
class Elementor
{
    /**
     * Initialize Elementor hooks.
     */
    public function init(): void
    {

        add_action('elementor/elements/categories_registered', array($this, 'register_categories'));
        add_action('elementor/widgets/register', array($this, 'register_widgets'));
        
        // Elementor asset registration hooks
        add_action('elementor/frontend/after_register_styles', array($this, 'register_styles'));
        add_action('elementor/frontend/after_register_scripts', array($this, 'register_scripts'));
    }

    /**
     * Register custom widget category in Elementor panel.
     *
     * @param \Elementor\Elements_Manager $elements_manager
     */
    public function register_categories(\Elementor\Elements_Manager $elements_manager): void
    {
        $elements_manager->add_category(
            'modern-hotel-booking',
            array(
                'title' => (string) I18n::get_label('label_hotel_booking'),
                'icon'  => 'eicon-hotel',
            )
        );
    }

    /**
     * Register MHBO widgets with Elementor.
     *
     * @param \Elementor\Widgets_Manager $widgets_manager
     */
    public function register_widgets(\Elementor\Widgets_Manager $widgets_manager): void
    {

        // Free Widgets
        $widgets_manager->register(new BookingFormWidget());
        $widgets_manager->register(new RoomGridWidget());
        $widgets_manager->register(new CalendarWidget());
        // $widgets_manager->register(new AiConciergeWidget()); // Removed per user request

        // Pro Widgets will be injected here during build if enabled.
    }

    /**
     * Register Elementor specific styles.
     */
    public function register_styles(): void
    {
        wp_register_style('mhbo-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap', [], MHBO_VERSION);
        wp_register_style('mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', ['mhbo-google-fonts'], MHBO_VERSION);
        wp_register_style('mhbo-flatpickr-css', MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css', [], '4.6.13');
        wp_register_style('mhbo-calendar-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-calendar.css', [], MHBO_VERSION);
    }

    /**
     * Register Elementor specific scripts.
     */
    public function register_scripts(): void
    {
        wp_register_script('mhbo-flatpickr-js', MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js', [], '4.6.13', true);
        wp_register_script('mhbo-frontend', MHBO_PLUGIN_URL . 'assets/js/mhbo-frontend.js', ['jquery', 'mhbo-flatpickr-js'], MHBO_VERSION, true);
        wp_register_script('mhbo-booking-form', MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-form.js', ['jquery', 'mhbo-frontend'], MHBO_VERSION, true);
        wp_register_script('mhbo-calendar-js', MHBO_PLUGIN_URL . 'assets/js/mhbo-calendar.js', ['jquery', 'mhbo-flatpickr-js'], MHBO_VERSION, true);
        
        // Ensure localization logic is hooked early enough for these scripts
        if (class_exists('\\MHBO\\Frontend\\Shortcode')) {
            add_action('wp_enqueue_scripts', array('\\MHBO\\Frontend\\Shortcode', 'localize_frontend_assets'), 20);
        }
    }
}
