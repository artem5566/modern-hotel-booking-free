<?php declare(strict_types=1);

namespace MHBO\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\I18n;

class BookingWidget extends \WP_Widget
{
    /**
     * Widget ID base - used for both parent constructor and is_active_widget() check.
     */
    private const ID_BASE = 'mhbo_booking_widget';

    public function __construct()
    {
        parent::__construct(
            self::ID_BASE,
            I18n::get_label('widget_title'),
            array('description' => I18n::get_label('widget_desc'))
        );

        // Enqueue assets when widget is displayed
        add_action('wp_enqueue_scripts', [$this, 'enqueue_widget_assets']);
    }

    /**
     * Enqueue widget assets on all pages where widget might appear.
     * 
     * We load assets globally if the widget is active (in any sidebar)
     * to ensure the datepicker works on pages without the main shortcode.
     */
    public function enqueue_widget_assets(): void
    {
        // Only load if this widget is active in any sidebar
        if (!is_active_widget(false, false, self::ID_BASE, true)) {
            return;
        }

        // Delegate to Calendar to load unified assets
        if (class_exists('MHBO\Frontend\Calendar')) {
            Calendar::enqueue_assets();
        }
    }

    private static $widget_rendered = false;

    public function widget($args, $instance)
    {
        // Only allow one widget instance per page if calendar is singular
        // Actually, unified calendar allows multiple instances if IDs are different?
        // But Calendar::render_unified_view checks $calendar_instance_rendered.
        // So if main shortcode rendered, widget won't render calendar.
        // This might be desired or not.
        // If widget is sidebar and shortcode is content, we might want both?
        // But flatpickr IDs might conflict if not careful.
        // The unified view uses class-based initialization: $('.mhbo-calendar-container').each(...)
        // So multiple instances SHOULD be fine if HTML IDs are unique or not used.
        // The HTML uses classes, not IDs for elements (except guide? no).
        // Let's rely on Calendar::render_unified_view's protection for now.

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress core provides HTML for widget wrappers, still using wp_kses_post for compliance
        echo wp_kses_post($args['before_widget']);
        if (isset($instance['title']) && '' !== $instance['title']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress core provides HTML for widget wrappers
            echo wp_kses_post($args['before_title']) . esc_html(apply_filters('widget_title', $instance['title'])) . wp_kses_post($args['after_title']);
        }

        // Render the unified calendar view (aggregated)
        if (class_exists('MHBO\Frontend\Calendar')) {
            // Reset the static flag to allow widget to render even if shortcode rendered?
            // No, Calendar::render_unified_view enforces singleton per page for now.
            // If we want widget + main content, we'd need to remove that check in Calendar.php.
            // For now, let's assume one calendar per page is safer.
            echo '<div class="mhbo-widget-context">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Calendar::render_unified_view returns pre-escaped HTML from internal components
            echo wp_kses_post(Calendar::render_unified_view(0));
            echo '</div>';
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress core provides HTML for widget wrappers
        echo wp_kses_post($args['after_widget']);
    }

    public function form($instance)
    {
        $title = (isset($instance['title']) && '' !== $instance['title']) ? $instance['title'] : I18n::get_label('widget_default_title');
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php echo esc_html(I18n::get_label('widget_field_title')); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance)
    {
        $instance = array();
        $instance['title'] = (isset($new_instance['title']) && '' !== $new_instance['title']) ? wp_strip_all_tags($new_instance['title']) : '';
        return $instance;
    }
}
