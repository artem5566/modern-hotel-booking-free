<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Cache Management Utility
 * 
 * Implements Rule 13: Row-level caching with separate version tracking 
 * for each data table to ensure maximum performance and data integrity.
 */
class Cache
{
    /**
     * Runtime static cache for single-request performance.
     * @var array<string, mixed>
     */
    private static array $runtime_cache = [];

	/**
	 * Cache group for all MHBO data.
	 */
	const GROUP = 'mhbo_cache';

	/**
	 * Last changed salt for Rule 13.
	 */
	const LAST_CHANGED_KEY = 'bookings_last_changed';

	/**
	 * Available tables for versioning.
	 */
	const TABLE_BOOKINGS = 'bookings';
	const TABLE_ROOM_TYPES = 'room_types';
	const TABLE_ROOMS = 'rooms';
	const TABLE_PRICING_RULES = 'pricing_rules';
	const TABLE_ICAL_CONNECTIONS = 'ical_connections';
	const TABLE_SETTINGS = 'settings';
	const TABLE_CALENDAR_OVERRIDES = 'calendar_overrides';

	/**
	 * Get the current version of a data table.
	 *
	 * @param string $table The table identifier (use class constants).
	 * @return int The current version number.
	 */
	public static function get_version(string $table): int
	{
        // 2026 BP: Request-level static caching to avoid redundant get_option calls.
        if (isset(self::$runtime_cache['v_' . $table])) {
            return (int) self::$runtime_cache['v_' . $table];
        }

		$version = (int) get_option("mhbo_v_{$table}", 1);
		if ($version < 1) {
			$version = 1;
			update_option("mhbo_v_{$table}", 1);
		}

        self::$runtime_cache['v_' . $table] = $version;
		return $version;
	}

	/**
	 * Increment the version of a data table.
	 * This effectively invalidates all cached queries related to this table.
	 *
	 * @param string $table The table identifier (use class constants).
	 */
	public static function bump(string $table): void
	{
		$version = self::get_version($table) + 1;
		update_option("mhbo_v_{$table}", $version);
		
		// Clear non-persistent local and runtime cache for this process
		wp_cache_delete("mhbo_v_{$table}", self::GROUP);
        unset(self::$runtime_cache['v_' . $table]);
        
        // Ensure all versioned transients are effectively "cleared" by the new version number
        // for any process checking them subsequently.
	}

	/**
	 * Bump all table versions at once.
	 * Useful for troubleshooting or major updates.
	 * 
	 * @return bool Always true on completion.
	 */
	public static function flush_all(): bool
	{
		$tables = [
			self::TABLE_CALENDAR_OVERRIDES,
			self::TABLE_BOOKINGS,
			self::TABLE_ROOM_TYPES,
			self::TABLE_ROOMS,
			self::TABLE_PRICING_RULES,
			self::TABLE_ICAL_CONNECTIONS,
			self::TABLE_SETTINGS,
		];

		foreach ($tables as $table) {
			self::bump($table);
		}

		self::clear_dashboard_transients();

		return true;
	}

	/**
	 * Rule 13: Clear all dashboard and analytics transients.
	 * 
	 * @since 2.3.8
	 */
	public static function clear_dashboard_transients(): void
	{
		$today = wp_date('Y-m-d');
		
		// Dashboard Widget Transients
		delete_transient('mhbo_widget_batch_counts');
		delete_transient('mhbo_widget_today_bookings_' . $today);
		
		// Full Dashboard Stats
		delete_transient('mhbo_dashboard_stats_' . $today);

		// Legacy transients (Cleanup)
		delete_transient('mhbo_widget_total_bookings');
		delete_transient('mhbo_widget_pending_bookings');
		delete_transient('mhbo_dashboard_total_bookings');
		delete_transient('mhbo_dashboard_pending_bookings');
		delete_transient('mhbo_dashboard_earned_revenue_' . $today);
		delete_transient('mhbo_dashboard_future_revenue_' . $today);
	}

	/**
	 * Alias for flush_all() to support legacy calls.
	 * 
	 * @return bool Always true on completion.
	 */
	public static function flush(): bool
	{
		return self::flush_all();
	}

	/**
	 * Get a cached query result.
	 *
	 * @param string $key Unique query key (e.g. sql hash).
	 * @param string $table Table to track version against.
	 * @return mixed Cached data or false on miss.
	 */
	public static function get_query(string $key, string $table): mixed
	{
		$version = self::get_version($table);
		return wp_cache_get("q_{$key}_v{$version}", self::GROUP);
	}

	/**
	 * Cache a query result.
	 *
	 * @param string $key Unique query key.
	 * @param mixed $data Data to cache.
	 * @param string $table Table to track version against.
	 * @param int $expire Expiration in seconds (default 1 hour).
	 * @return bool True on success, false on failure.
	 */
	public static function set_query(string $key, mixed $data, string $table, int $expire = 3600): bool
	{
		$version = self::get_version($table);
		return wp_cache_set("q_{$key}_v{$version}", $data, self::GROUP, $expire);
	}

	/**
	 * Get a cached single row (row-level caching).
	 *
	 * @param string|int $id Unique identifier for the row.
	 * @param string $table Table to track version against.
	 * @return mixed Cached data or false on miss.
	 */
	public static function get_row(string|int $id, string $table): mixed
	{
		$version = self::get_version($table);
		return wp_cache_get("r_{$table}_{$id}_v{$version}", self::GROUP);
	}

	/**
	 * Cache a single row (row-level caching).
	 *
	 * @param string|int $id Unique identifier for the row.
	 * @param mixed $data Data to cache.
	 * @param string $table Table to track version against.
	 * @param int $expire Expiration in seconds (default 1 hour).
	 * @return bool True on success, false on failure.
	 */
	public static function set_row(string|int $id, mixed $data, string $table, int $expire = 3600): bool
	{
		$version = self::get_version($table);
		return wp_cache_set("r_{$table}_{$id}_v{$version}", $data, self::GROUP, $expire);
	}

    /**
     * Get a versioned transient. 
     * RATIONALE: Persistent caching even without object cache (Redis/Memcached).
     *
     * @param string $key   Unique cache key.
     * @param string $table Controlling table version.
     * @return mixed Data or false.
     */
    public static function get_transient_versioned(string $key, string $table): mixed
    {
        $version = self::get_version($table);
        return get_transient("mhbo_{$key}_v{$version}");
    }

    /**
     * Set a versioned transient.
     *
     * @param string $key    Unique cache key.
     * @param mixed  $data   Value to cache.
     * @param string $table  Controlling table version.
     * @param int    $expire Expiration (default 12 hours for persistent cache).
     * @return bool
     */
    public static function set_transient_versioned(string $key, mixed $data, string $table, int $expire = 43200): bool
    {
        $version = self::get_version($table);
        return set_transient("mhbo_{$key}_v{$version}", $data, $expire);
    }

	/**
	 * Invalidate a specific row cache without bumping the whole table.
	 * Not recommended for queries involving multiple rows, but useful for 
	 * precise row updates if the versioning pattern is strictly row-id based.
	 *
	 * NOTE: In Rule 13 implementation, we prefer bumping the entire table 
	 * version to ensure all dependent complex queries (like availability) are cleared.
	 */
	public static function delete_row(string|int $id, string $table): void
	{
		$version = self::get_version($table);
		wp_cache_delete("r_{$table}_{$id}_v{$version}", self::GROUP);
	}

	/**
	 * Rule 13: Invalidate all booking-related caches.
	 * Bumps bookings version and rooms version (as bookings affect room availability).
	 */
	public static function invalidate_booking(int $booking_id = 0, int $room_id = 0): void
	{
		self::bump(self::TABLE_BOOKINGS);
		self::bump(self::TABLE_ROOMS); // Availability changes

		// Clear dashboard/widget transients
		$today = wp_date('Y-m-d');
		delete_transient('mhbo_widget_total_bookings');
		delete_transient('mhbo_widget_pending_bookings');
		delete_transient('mhbo_widget_today_bookings_' . $today);
		delete_transient('mhbo_dashboard_total_bookings');
		delete_transient('mhbo_dashboard_pending_bookings');
		delete_transient('mhbo_dashboard_earned_revenue_' . $today);
		delete_transient('mhbo_dashboard_future_revenue_' . $today);
	}

	/**
	 * Rule 13: Invalidate room-related caches.
	 */
	public static function invalidate_rooms(): void
	{
		self::bump(self::TABLE_ROOMS);
		self::bump(self::TABLE_ROOM_TYPES);
		wp_cache_delete('mhbo_all_rooms', 'mhbo_rooms');
		wp_cache_delete('mhbo_room_types_all', 'mhbo');
	}

	/**
	 * Rule 13: Invalidate pricing-related caches.
	 */
	public static function invalidate_pricing(): void
	{
		self::bump(self::TABLE_PRICING_RULES);
		wp_cache_delete('mhbo_pricing_rules_all', 'mhbo');
	}

	/**
	 * Invalidate calendar overrides cache.
	 */
	public static function invalidate_calendar_overrides(): void
	{
		self::bump(self::TABLE_CALENDAR_OVERRIDES);
	}

	/**
	 * COMPATIBILITY: Legacy calendar cache invalidation.
	 */
	/**
	 * COMPATIBILITY: Legacy pricing version retrieval.
	 */
	public static function get_prices_version(): int
	{
		return self::get_version(self::TABLE_PRICING_RULES);
	}

	/**
	 * COMPATIBILITY: Legacy rooms version retrieval.
	 */
	public static function get_availability_version(): int
	{
		return self::get_version(self::TABLE_ROOMS);
	}

	/**
	 * COMPATIBILITY: Legacy settings version retrieval.
	 */
	public static function get_settings_version(): int
	{
		return self::get_version(self::TABLE_SETTINGS);
	}

	/**
	 * COMPATIBILITY: Legacy availability retrieval used by RestApi.php.
	 * Redirects to Rule 13 versioning for 'bookings' table.
	 */
	public static function get_bookings_last_changed(): int
	{
		return self::get_version(self::TABLE_BOOKINGS);
	}
}
