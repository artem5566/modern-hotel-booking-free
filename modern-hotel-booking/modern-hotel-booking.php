<?php declare(strict_types=1);
/**
 * Plugin Name:       Hotel Booking — Modern Vacation Rental System with AI Concierge & Stripe
 * Plugin URI:        https://github.com/leslieradue-web/modern-hotel-booking-free
 * Description:       Hotel booking system for vacation rentals, B&Bs and cabins. Includes iCal sync for Airbnb, Stripe payments and a built-in AI Concierge.
 * Version:           2.3.8
 * Requires at least: 6.6
 * Tested up to:      7.0
 * Requires PHP:      8.0
 * Author:            StartMySuccess
 * Author URI:        https://startmysuccess.com/modern-hotel-booking-wordpress-plugin/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       modern-hotel-booking
 * Domain Path:       /languages
 */

declare(strict_types=1);

// Prevent direct file access.
if (!defined('ABSPATH')) {
    exit;
}

// PHP version check — must be at least 8.0.
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
            esc_html__('Modern Hotel Booking Error', 'modern-hotel-booking'),
            sprintf(
                // translators: %s: current PHP version number
                esc_html__('This plugin requires PHP 8.0 or higher. You are running PHP %s. Please upgrade your PHP version.', 'modern-hotel-booking'),
                esc_html(PHP_VERSION)
            )
        );
    });
    return;
}

define('MHBO_VERSION', '2.3.8');
define( 'MHBO_IS_PRO', false );
define('MHBO_PLUGIN_FILE', __FILE__);
define('MHBO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MHBO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MHBO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4-style autoloader for MHBO\ namespace.
 */
spl_autoload_register(function ($class) {
    $prefix = 'MHBO\\';
    $base_dir = MHBO_PLUGIN_DIR . 'includes/';

    // Remove leading backslash if present
    $class = ltrim($class, '\\');

    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    $file = str_replace('//', '/', $file);

    if (file_exists($file)) {
        require_once $file;
    }
});

// Rule 13: Register capabilities as early as possible.
\MHBO\Core\Capabilities::register();

/**
 * Activation hook.
 */
function mhbo_activate(): void
{
    $activator_file = MHBO_PLUGIN_DIR . 'includes/Core/Activator.php';
    if (file_exists($activator_file)) {
        require_once $activator_file;
        if (class_exists('MHBO\Core\Activator')) {
            \MHBO\Core\Activator::activate();
        }
    }
}

/**
 * Deactivation hook.
 */
function mhbo_deactivate(): void
{
    $deactivator_file = MHBO_PLUGIN_DIR . 'includes/Core/Deactivator.php';
    if (file_exists($deactivator_file)) {
        require_once $deactivator_file;
        if (class_exists('MHBO\Core\Deactivator')) {
            \MHBO\Core\Deactivator::deactivate();
        }
    }
}

register_activation_hook(__FILE__, 'mhbo_activate');
register_deactivation_hook(__FILE__, 'mhbo_deactivate');

/**
 * Boot the plugin.
 */
function mhbo_run(): void
{
    // Guard against double-execution within the same request.
    // WP 6.9 can trigger init callbacks more than once in edge cases,
    // causing fatal PUC slug conflicts and duplicate block registration.
    static $did_run = false;
    if ($did_run) {
        return;
    }
    $did_run = true;

    try {
        if (!class_exists('MHBO\Core\Plugin')) {
            return;
        }

        // Auto-upgrade DB schema when version changes.
        $stored = get_option('mhbo_db_version');
        if (MHBO_VERSION !== $stored) {
            if (class_exists('MHBO\Core\Activator')) {
                if (false !== $stored) {
                    \MHBO\Core\Activator::migrate(is_string($stored) ? $stored : (string) $stored, MHBO_VERSION);
                } else {
                    \MHBO\Core\Activator::activate();
                }
            }
            update_option('mhbo_db_version', MHBO_VERSION);
        }

        // Rule 13: Initialize table versions for caching
        foreach (['bookings', 'rooms', 'room_types', 'pricing_rules', 'ical_connections', 'settings'] as $table) {
            if (false === get_option("mhbo_v_{$table}")) {
                add_option("mhbo_v_{$table}", 1);
            }
        }

        // Instantiate the main plugin class.
        $plugin = new \MHBO\Core\Plugin();
        $plugin->run();
    } catch (Throwable $e) {
        if (\MHBO\Core\Capabilities::current_user_can(\MHBO\Core\Capabilities::MANAGE_SETTINGS)) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error">';
                echo '<p><strong>' . esc_html__('Modern Hotel Booking Error', 'modern-hotel-booking') . '</strong></p>';
                echo '<p>' . esc_html__('An error occurred while loading the plugin. Please check the debug log for details.', 'modern-hotel-booking') . '</p>';
                echo '</div>';
            });
        }
    }
}

add_action('init', 'mhbo_run', 20);

/**
 * Boot the AI Concierge + MCP Server subsystem.
 * Runs on plugins_loaded (priority 1) to register hooks early.
 */
add_action('plugins_loaded', static function (): void {
    if (class_exists('MHBO\AI\Loader')) {
        \MHBO\AI\Loader::init();
    }
}, 1);

/**
 * mhbo_is_pro() — global helper for template/shortcode use.
 * Wraps false.
 */
if (!function_exists('mhbo_is_pro')) {
    function mhbo_is_pro(): bool
    {
        return class_exists('MHBO\Core\License') && false;
    }
}

/**
 * On activation: create chat sessions table (Pro) and register AI guest role.
 */
add_action('mhbo_activated', static function (): void {
    if (mhbo_is_pro() && class_exists('MHBO\AI\ChatSession')) {
        \MHBO\AI\ChatSession::create_table();
    }
    if (class_exists('MHBO\AI\Loader')) {
        \MHBO\AI\Loader::maybe_register_ai_guest_role();
    }
});

/**
 * On deactivation: remove AI guest role.
 */
add_action('mhbo_deactivated', static function (): void {
    remove_role('mhbo_ai_guest');
});
