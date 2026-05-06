<?php declare(strict_types=1);

namespace MHBO\Database\Queries;

/**
 * Booking_Query
 *
 * Centralized query handler for MHBO Bookings to resolve N+1 performance issues
 * and provide a clean data access layer compliant with 2026 standards.
 *
 * @package MHBO\Database\Queries
 * @since 2.3.0
 */
if (!defined('ABSPATH')) {
    exit;
}

class Booking_Query {

    /**
     * Get recent bookings with room details in a single JOIN.
     *
     * @param int $limit Number of bookings to retrieve.
     * @return array<int, object> List of booking objects.
     */
    public static function get_recent(int $limit = 5): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i handles identifier escaping (WP 6.2+).
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT b.*, r.room_number as room_name FROM %i b LEFT JOIN %i r ON b.room_id = r.id ORDER BY b.created_at DESC LIMIT %d',
                $wpdb->prefix . 'mhbo_bookings',
                $wpdb->prefix . 'mhbo_rooms',
                $limit
            )
        );
    }

    /**
     * Get bookings with full details (Room and Room Type) for admin lists.
     *
     * @param string $status Filter by status (optional).
     * @param int $limit Max results.
     * @return array<int, object>
     */
    public static function get_list(string $status = '', int $limit = 100): array {
        global $wpdb;

        $where  = '1=1';
        $params = [];
        if ( $status ) {
            $where   .= ' AND b.status = %s';
            $params[] = $status;
        }

        // No string interpolation: table names use %i (WP 6.2+ identifier placeholder).
        // $where is built solely from string literals and %s placeholders — no user data.
        $sql = 'SELECT b.*, r.room_number, t.name as room_type'
             . ' FROM %i b'
             . ' LEFT JOIN %i r ON b.room_id = r.id'
             . ' LEFT JOIN %i t ON r.type_id = t.id'
             . ' WHERE ' . $where
             . ' ORDER BY b.created_at DESC LIMIT %d';

        $all_params = array_merge(
            [ $wpdb->prefix . 'mhbo_bookings', $wpdb->prefix . 'mhbo_rooms', $wpdb->prefix . 'mhbo_room_types' ],
            $params,
            [ $limit ]
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is a variable because WHERE is dynamic, but is built solely from string literals + %i/%s placeholders; no user data is interpolated. Passed through prepare() before get_results().
        return $wpdb->get_results( $wpdb->prepare( $sql, ...$all_params ) );
    }

    /**
     * Fetch a single booking with room details.
     *
     * @param int $id Booking ID.
     * @return object|null
     */
    public static function get_one(int $id): ?object {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i handles identifier escaping (WP 6.2+).
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT b.*, r.room_number as room_name FROM %i b LEFT JOIN %i r ON b.room_id = r.id WHERE b.id = %d',
                $wpdb->prefix . 'mhbo_bookings',
                $wpdb->prefix . 'mhbo_rooms',
                $id
            )
        );

        return $row ?: null;
    }

}
