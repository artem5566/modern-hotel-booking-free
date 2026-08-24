<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Modern_Hotel_Booking
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('ABSPATH')) {
    exit;
}

// Check if we should save data on uninstall (default to 1 for safety)
$mhbo_option_val = get_option('mhbo_save_data_on_uninstall', 1);
$mhbo_save_data = is_numeric($mhbo_option_val) ? (int) $mhbo_option_val : 1;

if (0 !== $mhbo_save_data) {
    $mhbo_cron_hooks = array(
        'mhbo_hourly_sync',
        'mhbo_daily_maintenance',
        'mhbo_ical_scheduled_sync',
        'mhbo_ical_scheduled_sync_single',
        'check_updates-modern-hotel-booking',
        
    );

    foreach ($mhbo_cron_hooks as $mhbo_hook) {
        wp_clear_scheduled_hook($mhbo_hook);
    }
    return;  // Let script complete normally for WordPress
}

/** @var \wpdb $wpdb */
global $wpdb;

// Drop custom tables
$mhbo_tables = array(
    $wpdb->prefix . 'mhbo_room_types',
    $wpdb->prefix . 'mhbo_rooms',
    $wpdb->prefix . 'mhbo_bookings',
    $wpdb->prefix . 'mhbo_ical_feeds',
    $wpdb->prefix . 'mhbo_ical_connections',
    $wpdb->prefix . 'mhbo_pricing_rules',
    $wpdb->prefix . 'mhbo_calendar_overrides',
    $wpdb->prefix . 'mhbo_idempotency',
    
);

foreach ($mhbo_tables as $mhbo_table) {
    $wpdb->query("DROP TABLE IF EXISTS {$mhbo_table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
}

// Delete ALL mhbo_ options (comprehensive cleanup)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Cleanup query, hardcoded strings
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mhbo_%'"
);

$mhbo_cron_hooks = array(
    'mhbo_hourly_sync',
    'mhbo_daily_maintenance',
    'mhbo_ical_scheduled_sync',
    'mhbo_ical_scheduled_sync_single',
    'check_updates-modern-hotel-booking',
    
);

foreach ($mhbo_cron_hooks as $mhbo_hook) {
    wp_clear_scheduled_hook($mhbo_hook);
}

// Clear any remaining single events for iCal retries
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Cleanup query, hardcoded strings
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mhbo_%' OR option_name LIKE '_site_transient_mhbo_%'"
);

// Clean up roles and capabilities
global $wp_roles;
if (isset($wp_roles)) {
    $mhbo_capabilities = array(
        'mhbo_manage_bookings',
        'mhbo_view_analytics',
        'mhbo_manage_settings',
        'mhbo_create_booking'
    );

    foreach ($wp_roles->roles as $mhbo_role_name => $mhbo_role_info) {
        $mhbo_role = get_role($mhbo_role_name);
        if ($mhbo_role) {
            foreach ($mhbo_capabilities as $mhbo_cap) {
                if ($mhbo_role->has_cap($mhbo_cap)) {
                    $mhbo_role->remove_cap($mhbo_cap);
                }
            }
        }
    }
}

// Remove custom Pro AI role completely
if (get_role('mhbo_ai_guest')) {
    remove_role('mhbo_ai_guest');
}
