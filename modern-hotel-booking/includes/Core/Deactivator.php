<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deactivator class for plugin deactivation tasks.
 *
 * Handles cleanup of scheduled events, transients, and rewrite rules
 * when the plugin is deactivated.
 *
 * @package MHBO\Core
 * @since   2.2.3
 */
class Deactivator
{
    /**
     * Run deactivation tasks.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        // Clear all scheduled cron events
        self::clear_scheduled_crons();

        // Clear plugin transients
        self::clear_transients();

        // Flush rewrite rules
        flush_rewrite_rules();

        // AI Discovery cleanup - remove llms.txt if exists
        if ( class_exists( '\MHBO\AI\LlmFile' ) ) {
            \MHBO\AI\LlmFile::cleanup();
        }
    }

    /**
     * Clear all scheduled cron events for this plugin.
     *
     * @return void
     */
    private static function clear_scheduled_crons(): void
    {
        $crons = [
            'mhbo_hourly_sync',
            'mhbo_daily_maintenance',
            'mhbo_ical_scheduled_sync',
        ];

        foreach ($crons as $cron_hook) {
            $timestamp = wp_next_scheduled($cron_hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $cron_hook);
            }
            // Clear any remaining scheduled events
            wp_clear_scheduled_hook($cron_hook);
        }
    }

    /**
     * Clear plugin transients (rate limiting, caching, etc.).
     *
     * @return void
     */
    private static function clear_transients(): void
    {
        global $wpdb;

        // Rule 13 rationale: Bulk cleanup of temporary transients during deactivation.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup during deactivation
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_mhbo_%' 
             OR option_name LIKE '_site_transient_mhbo_%'"
        );
    }
}
