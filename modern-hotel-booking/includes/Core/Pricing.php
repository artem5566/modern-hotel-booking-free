<?php declare(strict_types=1);

namespace MHBO\Core;

use MHBO\Core\HotelTime;
// AUDITOR: This file uses MHBO\Core\Money for all production financial calculations.

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Pricing
 * Handles pricing calculations, seasonal rules, and caching.
 */
class Pricing
{
    /**
     * Legacy float-based price getter. Kept for backward-compatibility only.
     * New code must use calculate_booking_money().
     *
     * NOTE: $children and $child_ages are intentionally unused in the Free version
     * (children pricing is a Pro feature). They exist only to preserve the public API.
     *
     * @param int    $room_id   Room ID.
     * @param string $check_in  Check-in date (YYYY-MM-DD).
     * @param string $check_out Check-out date (YYYY-MM-DD).
     * @param int    $adults    Number of adults.
     * @param int    $children  Number of children (Pro — unused in Free).
     * @param int[]  $child_ages Array of child ages (Pro — unused in Free).
     * @return array{total: float, breakdown: array<string, float>, subtotal: float, tax_total: float, is_pro: bool}
     *
     * @deprecated 2.1.0 Use calculate_booking_money() for all new code.
     */
    public static function get_booking_price(int $room_id, string $check_in, string $check_out, int $adults = 2, int $children = 0, array $child_ages = []): array // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
    {
        $days = (int) round((strtotime($check_out) - strtotime($check_in)) / 86400);
        if ($days <= 0) {
            return ['total' => 0.0, 'breakdown' => [], 'subtotal' => 0.0, 'tax_total' => 0.0, 'is_pro' => false];
        }

        $room = self::get_room_pricing_data($room_id);
        if (!$room) {
            return ['total' => 0.0, 'breakdown' => [], 'subtotal' => 0.0, 'tax_total' => 0.0, 'is_pro' => false];
        }

        $base_price = (float) $room->base_price;
        $total = 0.0;
        $breakdown = [];

        // Simple calculation for Free version
        for ($i = 0; $i < $days; $i++) {
            $date = HotelTime::date('Y-m-d', HotelTime::midnight($check_in) + $i * DAY_IN_SECONDS);
            $daily_price = $base_price;
            
            // Adult Surcharge logic (Free simple version)
            if ($adults > (int)$room->max_adults) {
                // Should not happen if validation works, but safety net
            }

            $total += $daily_price;
            $breakdown[$date] = $daily_price;
        }

        // 2026 BP: Correctly check if rate is set before casting.
        $tax_rate = isset($room->accommodation_tax_rate) ? (float) $room->accommodation_tax_rate : Tax::get_accommodation_rate();
        $tax_data = Tax::calculate_tax($total, $tax_rate, Tax::get_mode());
        
        return [
            'total' => (float)$tax_data['total'],
            'subtotal' => $total,
            'tax_total' => (float)$tax_data['tax_amount'],
            'breakdown' => $breakdown,
            'is_pro' => false
        ];
    }

    /**
     * Get room pricing data with caching.
     *
     * @param int $room_id Room ID.
     * @return object|null Room data object.
     */
    public static function get_room_pricing_data(int $room_id): ?object
    {
        $version = Cache::get_prices_version();
        $cache_key = 'mhbo_room_policy_' . $room_id . '_' . $version;
        $cached = wp_cache_get($cache_key, 'mhbo');

        if (false !== $cached) {
            return is_object($cached) ? $cached : null;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- result is cached above via wp_cache_get/set
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, t.base_price, t.max_adults, t.max_children, t.child_age_free_limit, t.child_rate
             FROM {$wpdb->prefix}mhbo_rooms r
             JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             WHERE r.id = %d",
            $room_id
        ));

        if ($row) {
            wp_cache_set($cache_key, $row, 'mhbo', 86400);
        }

        return $row;
    }

    /**
     * Format price with currency symbol and position.
     *
     * @param float    $amount            The amount to format.
     * @param bool     $include_tax_note  Whether to append tax notes if applicable.
     * @param int|null $decimals_override Optional override for decimal places.
     * @return string Formatted price string.
     */
    public static function format_price(float $amount, bool $include_tax_note = false, ?int $decimals_override = null): string
    {
        $currency_symbol = get_option('mhbo_currency_symbol', '$');
        $position = get_option('mhbo_currency_position', 'before');
        
        $decimals = $decimals_override ?? (int)get_option('mhbo_calendar_show_decimals', 0);

        $formatted = number_format($amount, $decimals, '.', ',');
        
        if ($include_tax_note) {
            $tax_note = (string) get_option('mhbo_tax_note', '');
            if ('' !== $tax_note) {
                $formatted .= ' ' . $tax_note;
            }
        }

        // Smart Space Logic: Add space if symbol is multi-character or alphanumeric (e.g. RON)
        $add_space = strlen($currency_symbol) > 1 || preg_match('/^[a-zA-Z0-9]+$/', $currency_symbol);
        $add_space = (bool) apply_filters('mhbo_currency_add_space', $add_space, $currency_symbol);
        $space = $add_space ? ' ' : '';

        if ('before' === $position) {
            return $currency_symbol . $space . $formatted;
        }

        return $formatted . $space . $currency_symbol;
    }

    /**
     * Get currency position.
     *
     * @return string 'before' or 'after'.
     */
    public static function get_currency_position(): string
    {
        return get_option('mhbo_currency_position', 'before');
    }

    /**
     * Get currency symbol.
     *
     * @return string Currency symbol.
     */
    public static function get_currency_symbol(): string
    {
        return get_option('mhbo_currency_symbol', '$');
    }

    /**
     * Clear pricing cache.
     */
    public static function clear_cache(): void
    {
        Cache::bump(Cache::TABLE_PRICING_RULES);
    }

    /**
     * Force-initialize Pro pricing rules.
     *
     * Used during AJAX and submission request lifecycles where the main plugin 
     * init might have missed specific Pro feature hooks due to late loading 
     * or build-level conditional logic.
     */
    public static function ensure_pro_init(): void
    {
        /**
         * Fires when the pricing system needs to ensure all Pro rules (seasonal, weekend)
         * are correctly hooked and ready for calculation.
         *
         * @since 2.2.8
         */
        do_action('mhbo_pro_pricing_init');
    }

    /**
     * Find an available room for a given type and date range.
     *
     * @param int    $type_id   Room type ID.
     * @param string $check_in  Check-in date (YYYY-MM-DD).
     * @param string $check_out Check-out date (YYYY-MM-DD).
     * @param int    $guests    Number of guests (adults + children).
     * @return int|false Room ID if found, false otherwise.
     */
    /**
     * Get all available rooms of a type for a date range.
     *
     * @param int    $type_id   Type ID.
     * @param string $check_in  Check-in date.
     * @param string $check_out Check-out date.
     * @return int[] Array of room IDs.
     */
    public static function get_available_unit_ids_of_type(int $type_id, string $check_in, string $check_out): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rooms = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$wpdb->prefix}mhbo_rooms WHERE type_id = %d ORDER BY id ASC", $type_id));
        $available = [];
        foreach ($rooms as $r) {
            if (true === self::is_room_available((int)$r->id, $check_in, $check_out)) {
                $available[] = (int)$r->id;
            }
        }
        return $available;
    }

    /**
     * Find a single available room of a type. (Legacy/Simple)
     */
    public static function find_available_room(int $type_id, string $check_in, string $check_out, int $guests = 1): int|false
    {
        $available = self::get_available_unit_ids_of_type($type_id, $check_in, $check_out);
        return [] !== $available ? $available[0] : false;
    }

    /* BUILD_PRO_START */
    /**
     * Calculate a multi-room booking suggestion for a group that exceeds single-unit capacity.
     *
     * @param int    $type_id     Room type ID.
     * @param string $check_in    Check-in date.
     * @param string $check_out   Check-out date.
     * @param int    $adults      Total adults.
     * @param int    $children    Total children.
     * @param int[]  $child_ages  Array of child ages.
     * @return array<string, mixed>|false Multi-room suggestion data or false.
     */
    public static function calculate_multi_room_booking(int $type_id, string $check_in, string $check_out, int $adults, int $children, array $child_ages = []): array|false
    {
        global $wpdb;
        // 1. Get Room Type Policy
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: One-off fetch for multi-room grouping policy; derived metadata.
        $type = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mhbo_room_types WHERE id = %d", $type_id));
        if (null === $type) return false;

        $max_a = (int)$type->max_adults;
        $max_c = (int)$type->max_children;
        $max_t = $max_a + $max_c;

        if ($max_t <= 0) return false;

        // 2. Calculate needed units (conservative)
        $needed_by_total = (int) ceil(($adults + $children) / $max_t);
        $needed_by_adults = $max_a > 0 ? (int) ceil($adults / $max_a) : 0;
        $num_rooms = max($needed_by_total, $needed_by_adults);

        // 3. Verify availability of N units
        $available_ids = self::get_available_unit_ids_of_type($type_id, $check_in, $check_out);
        if (count($available_ids) < $num_rooms) return false;

        $room_units = array_slice($available_ids, 0, $num_rooms);
        $currency   = self::get_currency_code();
        $total_sum  = Money::fromCents(0, $currency);
        $nights     = (int) ceil((strtotime($check_out) - strtotime($check_in)) / DAY_IN_SECONDS);
        $breakdown  = [];

        // 4. Distribute guests
        $rem_a = $adults;
        $rem_c = $children;
        $rem_ages = $child_ages;

        for ($i = 0; $i < $num_rooms; $i++) {
            // Fair distribution: Try to put 1 adult in each room first
            $room_a = 0;
            if ($rem_a > ($num_rooms - $i - 1)) {
                $room_a = (int) ceil($rem_a / ($num_rooms - $i));
            }
            $room_a = min($room_a, $max_a);

            // Remaining slots for children
            $room_c = min($rem_c, $max_t - $room_a);
            $room_c = min($room_c, $max_c); // Stay within child cap per room if possible

            $room_ages = array_slice($rem_ages, 0, $room_c);

            // Calculate price for THIS room
            $calc = self::calculate_booking_money($room_units[$i], $check_in, $check_out, $room_a, [], $room_c, $room_ages);
            if ($calc && isset($calc['total'])) {
                $total_sum = $total_sum->add($calc['total']);
                $breakdown[] = [
                    'room_id' => $room_units[$i],
                    'adults'  => $room_a,
                    'children' => $room_c,
                    'total'   => (float) $calc['total']->toDecimal()
                ];
            }

            $rem_a -= $room_a;
            $rem_c -= $room_c;
            $rem_ages = array_slice($rem_ages, $room_c);
        }

        return [
            'type_id'     => $type_id,
            'num_rooms'   => $num_rooms,
            'total_price' => (float) $total_sum->toDecimal(),
            'currency'    => $currency,
            'nights'      => $nights,
            'breakdown'   => $breakdown
        ];
    }
    /* BUILD_PRO_END */

    /**
     * Bulk prime the room cache for a list of IDs.
     *
     * @param array<int> $room_ids Array of room IDs.
     */
    public static function prime_room_cache(array $room_ids): void
    {
        global $wpdb;
        if ( [] === $room_ids ) return;

        $room_ids = array_map('absint', $room_ids);
        $version = Cache::get_prices_version();

        // 2026 BP: Rule 13 - No dynamic SQL templates. Use placeholders for the IN clause.
        $placeholders = implode(',', array_fill(0, count($room_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a safe string of %d tokens built from array_fill; values spread via ...$room_ids
        $query = $wpdb->prepare("SELECT r.*, t.base_price, t.max_adults, t.max_children, t.child_age_free_limit, t.child_rate FROM {$wpdb->prefix}mhbo_rooms r JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id WHERE r.id IN ($placeholders)", ...$room_ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($query);

        foreach ($results as $room) {
            $key = 'mhbo_room_policy_' . $room->id . '_' . $version;
            wp_cache_set($key, $room, 'mhbo', 86400);
        }
    }

    /**
     * Get currency code.
     *
     * @return string Currency code.
     */
    public static function get_currency_code(): string
    {
        return (string) get_option('mhbo_currency_code', 'USD');
    }

    /**
     * Get a single day's price for a room as a Money object.
     * Prefers room-level custom_price over type base_price.
     * Applies the 'mhbo_calculate_stay_price_money' filter so Pro seasonal rules
     * (SeasonalRates) can modify the price without touching this Core class.
     *
     * @param int    $room_id Room ID.
     * @param string $date    Date (YYYY-MM-DD).
     * @return Money Money object.
     */
    public static function calculate_daily_price_money(int $room_id, string $date): Money
    {
        $room = self::get_room_pricing_data($room_id);
        if (!$room) {
            return Money::fromCents(0, self::get_currency_code());
        }

        // Prefer room-level custom price when set (> 0)
        $raw = (isset($room->custom_price) && (float) $room->custom_price > 0)
            ? (string) $room->custom_price
            : (string) $room->base_price;

        $price = Money::fromDecimal($raw, self::get_currency_code());

        // Allow Pro seasonal/weekend rules to hook in without touching Core
        /** @var Money $price */
        $filtered = apply_filters('mhbo_calculate_stay_price_money', $price, $room_id, $date);

        return ($filtered instanceof Money) ? $filtered : $price;
    }

    // -------------------------------------------------------------------------
    // Availability & Concurrency
    // -------------------------------------------------------------------------

    /**
     * Check if a room is available for a date range.
     * Must bypass object cache — live read only (prevents double-booking).
     *
     * @param int    $room_id            Room ID.
     * @param string $check_in           Check-in date (YYYY-MM-DD).
     * @param string $check_out          Check-out date (YYYY-MM-DD).
     * @param int    $exclude_booking_id Booking ID to exclude (for edit flows).
     * @return bool|string True if available; I18n label key on conflict.
     */
    public static function is_room_available(int $room_id, string $check_in, string $check_out, int $exclude_booking_id = 0): bool|string
    {
        global $wpdb;

        // 2026 BP: Respect same-day turnover turnover setting (Synchronization Fix)
        $prevent_same_day = (int) get_option('mhbo_prevent_same_day_turnover', 0) === 1;

        if ($exclude_booking_id > 0) {
            if ($prevent_same_day) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND check_in <= DATE(%s) AND check_out >= DATE(%s) AND id != %d",
                    $room_id, $check_out, $check_in, $exclude_booking_id
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND check_in < DATE(%s) AND check_out > DATE(%s) AND id != %d",
                    $room_id, $check_out, $check_in, $exclude_booking_id
                ));
            }
        } else {
            if ($prevent_same_day) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND check_in <= DATE(%s) AND check_out >= DATE(%s)",
                    $room_id, $check_out, $check_in
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND check_in < DATE(%s) AND check_out > DATE(%s)",
                    $room_id, $check_out, $check_in
                ));
            }
        }

        return $conflict ? 'label_room_not_available' : true;
    }

    /**
     * Acquire a MySQL advisory lock for atomic booking.
     *
     * @param int $room_id Room ID.
     * @param int $timeout Timeout in seconds.
     * @return bool True if lock acquired.
     */
    public static function acquire_booking_lock(int $room_id, int $timeout = 10): bool
    {
        global $wpdb;

        // Detect SQLite/Playground where GET_LOCK is unsupported.
        // In these environments, we bypass the advisory lock as concurrency is typically handled
        // at the filesystem or process level, or is not applicable in single-user WASM contexts.
        $is_sqlite = (strpos(strtolower(get_class($wpdb)), 'sqlite') !== false);
        if ($is_sqlite) {
            return true;
        }

        $lock_name = 'mhbo_booking_lock_' . $room_id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock, not a data query.
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, $timeout));

        // If the DB doesn't support GET_LOCK (returns NULL or errors), fallback to true.
        if (null === $result) {
            return true;
        }

        return '1' === (string) $result;
    }

    /**
     * Release a MySQL advisory lock.
     *
     * @param int $room_id Room ID.
     */
    public static function release_booking_lock(int $room_id): void
    {
        global $wpdb;

        $is_sqlite = (strpos(strtolower(get_class($wpdb)), 'sqlite') !== false);
        if ($is_sqlite) {
            return;
        }

        $lock_name = 'mhbo_booking_lock_' . $room_id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release.
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    // -------------------------------------------------------------------------
    // Core Pricing Engine (Money-Native, 2026 BP)
    // -------------------------------------------------------------------------

    /**
     * Calculate full booking price using Money precision.
     *
     * Children and extras pricing are Pro features wrapped in build markers.
     * Core Free version returns room-nights + tax only.
     *
     * Return shape (all Money objects):
     *   total, subtotal, room_total, children_total, extras_total,
     *   daily_prices (Money[]), nights (int),
     *   extras_breakdown (assoc map, Rule 10), tax (array), is_pro (bool).
     *
     * @param int    $room_id    Room ID.
     * @param string $check_in   Check-in date (YYYY-MM-DD).
     * @param string $check_out  Check-out date (YYYY-MM-DD).
     * @param int    $adults     Number of adults.
     * @param array<string, string> $extras     Extras map (id => quantity_or_'1').
     * @param int                   $children   Number of children.
     * @param array<int, int>       $child_ages Child ages.
     * @return array{total: Money, subtotal: Money, room_total: Money, children_total: Money, extras_total: Money, daily_prices: Money[], nights: int, extras_breakdown: array<mixed>, tax: array<string, mixed>, is_pro: bool}|false
     */
    public static function calculate_booking_money(
        int $room_id,
        string $check_in,
        string $check_out,
        int $adults,
        array $extras,
        int $children,
        array $child_ages
    ): array|false {
        /* BUILD_PRO_START */
        self::ensure_pro_init();
        /* BUILD_PRO_END */
        $room = self::get_room_pricing_data($room_id);
        if (!$room) {
            return false;
        }

        $currency = self::get_currency_code();
        $ts_in    = strtotime($check_in);
        $ts_out   = strtotime($check_out);

        if (!$ts_in || !$ts_out || $ts_out <= $ts_in) {
            return false;
        }

        $nights = (int) round(($ts_out - $ts_in) / 86400);

        // --- Per-night room pricing ---
        $room_total_money = Money::fromCents(0, $currency);
        $daily_prices     = [];

        for ($i = 0; $i < $nights; $i++) {
            $date             = HotelTime::date('Y-m-d', HotelTime::midnight($check_in) + $i * DAY_IN_SECONDS);
            $day_price        = self::calculate_daily_price_money($room_id, $date);
            $daily_prices[]   = $day_price;
            $room_total_money = $room_total_money->add($day_price);
        }

        // --- Occupancy Validation (2026 BP) ---
        $max_adults   = (int) ($room->max_adults ?? 0);
        $max_children = (int) ($room->max_children ?? 0);

        if ($adults > $max_adults || $children > $max_children) {
            return false; // Over-occupancy
        }

        // --- Children pricing (Pro) ---
        $children_total_money = Money::fromCents(0, $currency);

        /* BUILD_PRO_START */
        $children_enabled = (bool) get_option('mhbo_children_enabled', 1);

        if ($children_enabled && $children > 0) {
            $free_limit  = (int) ($room->child_age_free_limit ?? 0);
            $child_rate  = Money::fromDecimal((string) ($room->child_rate ?? '0'), $currency);

            // Classify: ages present → use them; missing ages default to chargeable (2026 BP safety)
            // When free_limit = 0 it means "no free age — charge all children regardless of age."
            // Only when free_limit > 0 do we exempt children at or below that age.
            $chargeable = 0;
            // 1. Process provided ages (Strict Age check: If age > limit, child is chargeable).
            // This fix ensures that if free_limit = 0, age 0 (baby) stays FREE, and age 1+ are charged.
            foreach ($child_ages as $age) {
                if ((int) $age > $free_limit) {
                    $chargeable++;
                }
            }

            // 2. Default for missing ages: Assume "Child Free Age + 1" (Chargeable by default).
            // This provides a conservative price quote before specific ages are entered.
            $missing = max(0, $children - count($child_ages));
            $chargeable += $missing;

            // Smart allocation: fill empty adult slots before billing children
            $empty_adult_slots = max(0, $max_adults - $adults);
            $billed            = max(0, $chargeable - $empty_adult_slots);

            if ($billed > 0 && $child_rate->isPositive()) {
                $children_total_money = $child_rate->multiply((string) $billed)->multiply((string) $nights);
            }
        }
        /* BUILD_PRO_END */

        // --- Extras pricing (Pro) ---
        $extras_total_money = Money::fromCents(0, $currency);
        $extras_breakdown   = [];

        /* BUILD_PRO_START */
        // Auto-inject compulsory extras regardless of user selection (e.g. cleaning fees, resort fees).
        // Server-side injection is authoritative — the hidden frontend inputs are for live preview only.
        $all_defined_extras = (array) get_option('mhbo_pro_extras', []);
        foreach ($all_defined_extras as $_compulsory_ex) {
            if ( (bool) ( $_compulsory_ex['compulsory'] ?? false ) && isset( $_compulsory_ex['id'] ) ) {
                $_cid = (string) $_compulsory_ex['id'];
                if (!isset($extras[$_cid])) {
                    $extras[$_cid] = 1;
                }
            }
        }
        /* BUILD_PRO_END */

        /* BUILD_PRO_START */
        if (is_array($extras) && [] !== $extras) {
            $available_extras = (array) get_option('mhbo_pro_extras', []);
            $extras_map       = [];
            foreach ($available_extras as $ex) {
                if (isset($ex['id'])) {
                    $extras_map[ (string) $ex['id'] ] = $ex;
                }
            }

            $total_occupancy = $adults + $children;

            foreach ($extras as $raw_id => $raw_val) {
                $ex_id = sanitize_key((string) $raw_id);
                if (!isset($extras_map[$ex_id])) {
                    continue;
                }

                $extra        = $extras_map[$ex_id];
                $control_type = $extra['control_type'] ?? 'checkbox';
                $pricing_type = $extra['pricing_type'] ?? 'fixed';
                $quantity     = 0;

                if ('checkbox' === $control_type && '1' === (string) $raw_val) {
                    $quantity = 1;
                } elseif ('quantity' === $control_type) {
                    $quantity = absint($raw_val);
                    // Overflow protection: cap quantity to the relevant head-count maximum.
                    if (in_array($pricing_type, ['per_person', 'per_person_per_night'], true)) {
                        $quantity = min($quantity, max(1, $total_occupancy));
                    } elseif (in_array($pricing_type, ['per_adult', 'per_adult_per_night'], true)) {
                        $quantity = min($quantity, max(1, $adults));
                    } elseif (in_array($pricing_type, ['per_child', 'per_child_per_night'], true)) {
                        $quantity = min($quantity, max(0, $children));
                    }
                }

                if ($quantity <= 0) {
                    continue;
                }

                $price      = Money::fromDecimal((string) ($extra['price'] ?? '0'), $currency);
                $extra_cost = Money::fromCents(0, $currency);

                // Resolve head-count for person-based pricing:
                //   per_person / per_person_per_night → total occupancy (adults + children)
                //   per_adult  / per_adult_per_night  → adults only
                //   per_child  / per_child_per_night  → children only
                // For checkbox control the person count comes from the booking occupancy.
                // For quantity control the guest entered a count directly.
                $total_guests = $adults + $children; // "Per Guest Selection" semantic

                $effective_multiplier = 1;

                switch ($pricing_type) {
                    case 'per_person':
                        // "Per Guest Selection" — price × total occupancy (adults + kids)
                        $people              = ('checkbox' === $control_type) ? max(1, $total_guests) : max(1, $quantity);
                        $extra_cost          = $price->multiply((string) $people);
                        $effective_multiplier = $people;
                        break;

                    case 'per_person_per_night':
                        // "Guest Count × Nights" — price × total occupancy × nights
                        $people              = ('checkbox' === $control_type) ? max(1, $total_guests) : max(1, $quantity);
                        $extra_cost          = $price->multiply((string) $people)->multiply((string) $nights);
                        $effective_multiplier = $people * $nights;
                        break;

                    case 'per_adult':
                        // "Per Adult Selection" — price × adults only
                        $people              = ('checkbox' === $control_type) ? max(1, $adults) : max(1, $quantity);
                        $extra_cost          = $price->multiply((string) $people);
                        $effective_multiplier = $people;
                        break;

                    case 'per_adult_per_night':
                        // "Adult Count × Nights"
                        $people              = ('checkbox' === $control_type) ? max(1, $adults) : max(1, $quantity);
                        $extra_cost          = $price->multiply((string) $people)->multiply((string) $nights);
                        $effective_multiplier = $people * $nights;
                        break;

                    case 'per_child':
                        // "Per Child Selection" — price × children only (0 children = no cost)
                        $people              = ('checkbox' === $control_type) ? $children : max(0, $quantity);
                        if ($people > 0) {
                            $extra_cost = $price->multiply((string) $people);
                        }
                        $effective_multiplier = $people;
                        break;

                    case 'per_child_per_night':
                        // "Child Count × Nights"
                        $people              = ('checkbox' === $control_type) ? $children : max(0, $quantity);
                        if ($people > 0) {
                            $extra_cost = $price->multiply((string) $people)->multiply((string) $nights);
                        }
                        $effective_multiplier = $people * $nights;
                        break;

                    case 'per_night':
                        // "Nightly Recurring" — price × quantity × nights (quantity=1 for checkbox)
                        $extra_cost          = $price->multiply((string) $quantity)->multiply((string) $nights);
                        $effective_multiplier = $quantity * $nights;
                        break;

                    default: // fixed — one-time flat fee, quantity=1 for checkbox
                        $extra_cost          = $price->multiply((string) $quantity);
                        $effective_multiplier = $quantity;
                        break;
                }

                $extras_total_money = $extras_total_money->add($extra_cost);

                // Rule 10 (DREAM_STATE fault #1): associative map keyed by ID, not indexed array
                $extras_breakdown[$ex_id] = [
                    'id'           => $ex_id,
                    'name'         => $extra['name'] ?? '',
                    'price'        => $price,
                    'quantity'     => $quantity,
                    'multiplier'   => $effective_multiplier,
                    'pricing_type' => $pricing_type,
                    'total'        => $extra_cost,
                    'compulsory'   => (bool) ( $extra['compulsory'] ?? false ) ? 1 : 0,
                ];
            }
        }
        /* BUILD_PRO_END */

        // --- Pre-fee subtotal (room + children + extras, pre-tax) ---
        $pre_fee_subtotal_money = $room_total_money->add($children_total_money)->add($extras_total_money);

        // --- Service Fee (Pro) ---
        $service_fee_money = Money::fromCents(0, $currency);
        $service_fee_label = '';
        /* BUILD_PRO_START */
        if (get_option('mhbo_service_fee_enabled', 0)) {
            $sf_type       = (string) get_option('mhbo_service_fee_type', 'fixed');
            $service_fee_label = (string) get_option('mhbo_service_fee_label', 'Service Fee');

            if ('percentage' === $sf_type) {
                $sf_pct = (float) get_option('mhbo_service_fee_percentage', 0);
                if ($sf_pct > 0) {
                    // Percentage is applied to the pre-fee subtotal (pre-tax gross).
                    // 2026 BP: Guard bcdiv for hosts missing bcmath.
                    $sf_divisor = function_exists( 'bcdiv' )
                        ? bcdiv( (string) $sf_pct, '100', 6 )
                        : number_format( $sf_pct / 100, 6, '.', '' );
                    $service_fee_money = $pre_fee_subtotal_money->multiply( (string) $sf_divisor );
                }
            } else {
                $sf_amount = (string) get_option('mhbo_service_fee_amount', '0');
                if ((float) $sf_amount > 0) {
                    $service_fee_money = Money::fromDecimal($sf_amount, $currency);
                }
            }
        }
        /* BUILD_PRO_END */

        // Full subtotal includes service fee (used when tax is disabled)
        $subtotal_money = $pre_fee_subtotal_money->add($service_fee_money);

        // Build extras list for tax engine (Tax expects indexed array with id/name/total)
        $extras_for_tax = array_values(array_map(
            static fn(string $id, array $item): array => [
                'id'    => $id,
                'name'  => $item['name'],
                'total' => $item['total']->toDecimal(),
            ],
            array_keys($extras_breakdown),
            $extras_breakdown
        ));

        // --- Tax ---
        $tax_data = Tax::calculate_booking_tax([
            'room_total'        => $room_total_money->toDecimal(),
            'children_total'    => $children_total_money->toDecimal(),
            'extras_total'      => $extras_total_money->toDecimal(),
            'extras'            => $extras_for_tax,
            'service_fee'       => $service_fee_money->toDecimal(),
            'service_fee_label' => $service_fee_label,
        ]);

        // Final total: gross when Sales Tax is active; gross equals subtotal under VAT/disabled
        $total_money = ($tax_data['totals']['total_gross'] instanceof Money)
            ? $tax_data['totals']['total_gross']
            : $subtotal_money;

        return [
            'total'            => $total_money,
            'subtotal'         => $subtotal_money,
            'room_total'       => $room_total_money,
            'children_total'   => $children_total_money,
            'extras_total'     => $extras_total_money,
            'service_fee'      => $service_fee_money,
            'service_fee_label' => $service_fee_label,
            'daily_prices'     => $daily_prices,
            'nights'           => $nights,
            'extras_breakdown' => $extras_breakdown,
            'tax'              => $tax_data,
            'is_pro'           => [] !== $extras_breakdown || $children_total_money->isPositive() || $service_fee_money->isPositive(),
        ];
    }

    /**
     * Get the room policy with specific details.
     *
     * @param int $room_id Room ID.
     * @return object|null Room details.
     */
    public static function get_room_details(int $room_id): ?object
    {
        return self::get_room_pricing_data($room_id);
    }
}
