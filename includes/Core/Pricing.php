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
                    "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status IN ('confirmed', 'completed') AND check_in <= DATE(%s) AND check_out >= DATE(%s) AND id != %d",
                    $room_id, $check_out, $check_in, $exclude_booking_id
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status IN ('confirmed', 'completed') AND check_in < DATE(%s) AND check_out > DATE(%s) AND id != %d",
                    $room_id, $check_out, $check_in, $exclude_booking_id
                ));
            }
        } else {
            if ($prevent_same_day) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status IN ('confirmed', 'completed') AND check_in <= DATE(%s) AND check_out >= DATE(%s)",
                    $room_id, $check_out, $check_in
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status IN ('confirmed', 'completed') AND check_in < DATE(%s) AND check_out > DATE(%s)",
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

        // Reset surcharge tracking at the start of every calculation so it's
        // self-contained — callers no longer need to remember to call
        // reset_surcharge_tracking() themselves before invoking this method.
        self::reset_surcharge_tracking();

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

// --- Children discount: flat amount per child under the configured age
        // limit, per night stayed. ---
        // Free-tier feature — not gated behind MHBO_IS_PRO, since it's a simple flat
        // per-child rule rather than the more complex Pro children-pricing engine.
        $child_discount_age_limit = (int) get_option('mhbo_child_discount_age_limit', 10);
        $child_discount_per_child_night = Money::fromDecimal(
            (string) get_option('mhbo_child_discount_amount', '500'),
            $currency
        );

        $discount_eligible_count = 0;
        foreach ($child_ages as $age) {
            if ((int) $age < $child_discount_age_limit) {
                $discount_eligible_count++;
            }
        }

        $child_discount_money = ($discount_eligible_count > 0)
            ? $child_discount_per_child_night->multiply((string) ($discount_eligible_count * $nights))
            : Money::fromCents(0, $currency);

        // --- Extras pricing (Pro) ---
        $extras_total_money = Money::fromCents(0, $currency);
        $extras_breakdown   = [];

        // --- Pre-fee subtotal (room + children + extras, pre-tax) ---
        $pre_fee_subtotal_money = $room_total_money->add($children_total_money)->add($extras_total_money);

        // Clamp discount so it can never exceed what's being discounted
        if ($child_discount_money->compare($pre_fee_subtotal_money) > 0) {
            $child_discount_money = $pre_fee_subtotal_money;
        }
        $pre_fee_subtotal_money = $pre_fee_subtotal_money->subtract($child_discount_money);

        // Apply the same discount to the room-total figure fed into the tax engine,
        // so total_gross (which downstream code treats as the authoritative total)
        // reflects the discount even when tax is enabled.
        $room_total_for_tax = $room_total_money->subtract($child_discount_money);
        if ($room_total_for_tax->compare(Money::fromCents(0, $currency)) < 0) {
            $room_total_for_tax = Money::fromCents(0, $currency);
        }

        // --- Service Fee (Pro) ---
        $service_fee_money = Money::fromCents(0, $currency);
        $service_fee_label = '';

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
            'room_total'        => $room_total_for_tax->toDecimal(),
            'children_total'    => $children_total_money->toDecimal(),
            'extras_total'      => $extras_total_money->toDecimal(),
            'extras'            => $extras_for_tax,
            'service_fee'       => $service_fee_money->toDecimal(),
            'service_fee_label' => $service_fee_label,
        ]);

        // The room breakdown line currently includes the peak-season surcharge
        // (baked in by calculate_daily_price_money()'s multiplier), since it must
        // for total_gross to be correct. But now that the surcharge is shown as
        // its own line item below, strip it back out of the displayed room amount
        // so "Room" + "Surcharge" + "Discount" visually sum to the grand total
        // instead of double-counting the surcharge.
        if (self::$surcharge_total instanceof Money && self::$surcharge_total->isPositive()
            && isset($tax_data['breakdown']['room']) && is_array($tax_data['breakdown']['room'])) {
            $surcharge_decimal = self::$surcharge_total->toDecimal();
            foreach (['gross', 'gross_amount', 'net'] as $field) {
                if (isset($tax_data['breakdown']['room'][$field])) {
                    $tax_data['breakdown']['room'][$field] = function_exists('bcsub')
                        ? bcsub((string) $tax_data['breakdown']['room'][$field], $surcharge_decimal, 2)
                        : number_format((float) $tax_data['breakdown']['room'][$field] - (float) $surcharge_decimal, 2, '.', '');
                }
            }
        }

        // Inject Free-tier line items (peak surcharge, child discount) into the
        // breakdown structure so Tax::format_tax_breakdown()/render_breakdown_html()
        // can render them as ordinary line items, same as room/children/extras.
        if (self::$surcharge_total instanceof Money && self::$surcharge_total->isPositive()) {
            $tax_data['breakdown']['peak_surcharge'] = [
                'label' => sprintf(
                    I18n::get_label('label_peak_season_surcharge'),
                    get_option( 'mhbo_peak_season_short_stay_threshold', 3)
                ),
                'gross' => self::$surcharge_total->toDecimal(),
            ];
        }

        if ($child_discount_money->isPositive()) {
            $tax_data['breakdown']['child_discount'] = [
                'label' => sprintf(
                    I18n::get_label('label_child_discount'),
                    $discount_eligible_count,
                    $nights,
                    $child_discount_age_limit
                ),
                'gross' => $child_discount_money->toDecimal(),
            ];
        }

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
            'child_discount'       => $child_discount_money,
            'child_discount_count' => $discount_eligible_count,            
            'is_pro'           => [] !== $extras_breakdown || $children_total_money->isPositive() || $service_fee_money->isPositive(),
        ];
    }

    /**
     * Calculate deposit from a booking total.
     *
     * Deposit Tax Lag fix (DREAM_STATE fault #3): deposit is computed on the
     * post-tax $total, so Sales Tax is already baked in. The $first_night Money
     * passed by callers is also the post-tax first-night value, ensuring the
     * "first_night" deposit type never under-collects in Sales Tax mode.
     *
     * @param Money $total       Full booking total (post-tax).
     * @param Money $first_night First night room rate (post-tax, no one-time extras).
     * @return array{deposit_money: Money, remaining_money: Money, deposit_type: string, deposit_value: float, deposit_type_label: string, is_non_refundable: bool}|false
     */
    public static function calculate_deposit_money(Money $total, Money $first_night): array|false
    {
        if ($total->isZero()) {
            return false;
        }

        $deposit_type        = (string) get_option('mhbo_deposit_type', 'percentage');
        $deposit_value       = (float)  get_option('mhbo_deposit_value', 20);
        $is_non_refundable   = (bool)   get_option('mhbo_deposit_non_refundable', 0);
        $currency            = $total->getCurrency();

        switch ($deposit_type) {
            case 'first_night':
                $deposit_money      = $first_night;
                $deposit_type_label = I18n::get_label('label_first_night_rate');
                break;
            case 'fixed':
                $deposit_money      = Money::fromDecimal((string) max(0.01, $deposit_value), $currency);
                $deposit_type_label = $deposit_money->format();
                break;
            default: // percentage
                $clamped            = max(1, min(100, $deposit_value));
                $multiplier         = function_exists( 'bcdiv' ) ? bcdiv( (string) $clamped, '100', 4 ) : number_format( $clamped / 100, 4, '.', '' );
                $deposit_money      = $total->multiply( $multiplier );
                $deposit_type_label = $clamped . '%';
                break;
        }

        // Clamp: deposit cannot exceed total
        if ($deposit_money->compare($total) > 0) {
            $deposit_money = $total;
        }

        $remaining_money = $total->subtract($deposit_money);

        return [
            'deposit_money'      => $deposit_money,
            'remaining_money'    => $remaining_money,
            'deposit_type'       => $deposit_type,
            'deposit_value'      => $deposit_value,
            'deposit_type_label' => $deposit_type_label,
            'is_non_refundable'  => $is_non_refundable,
        ];
    }

    /**
     * Render the Professional Deposit Selection UI.
     *
     * @param Money                $total The total gross amount.
     * @param array<string, mixed> $calc  The calculation results from the booking form.
     * @return void
     */
    public static function render_deposit_selection_html(Money $total, array $calc): void
    {
        if (!get_option('mhbo_deposits_enabled', 0)) {
            return;
        }

        // No deposit for 1-night bookings — no time to collect the remaining balance before checkout.
        $nights = isset($calc['nights']) ? (int) $calc['nights'] : 0;
        if ($nights === 1) {
            return;
        }

        $currency          = $total->getCurrency();
        $has_total         = !$total->isZero();

        // Prefer the children-inclusive first-night total if pre-computed by the caller.
        if (isset($calc['first_night_total']) && $calc['first_night_total'] instanceof Money) {
            $first_night_money = $calc['first_night_total'];
        } else {
            $first_night_money = Money::fromCents(0, $currency);
            if (isset($calc['daily_prices']) && [] !== $calc['daily_prices']) {
                $first = reset($calc['daily_prices']);
                $first_night_money = $first instanceof Money
                    ? $first
                    : Money::fromDecimal((string) $first, $currency);
            }
        }

        // Validate deposit settings are usable (bail for nonsense configs, not for zero initial total).
        $deposit_type_check  = (string) get_option('mhbo_deposit_type', 'percentage');
        $deposit_value_check = (float)  get_option('mhbo_deposit_value', 20);
        if ('percentage' === $deposit_type_check && $deposit_value_check <= 0) {
            return;
        }
        if ('fixed' === $deposit_type_check && $deposit_value_check <= 0) {
            return;
        }

        // Only validate deposit vs total when we actually have a real total.
        // When total is 0 (no dates yet), we still render the skeleton so the DOM
        // elements exist for the JS to update once dates are selected.
        $deposit_data = $has_total ? self::calculate_deposit_money($total, $first_night_money) : null;
        if ($has_total && (!$deposit_data || $deposit_data['deposit_money']->isZero() || $deposit_data['deposit_money']->compare($total) >= 0)) {
            return;
        }

        $deposit_money   = $deposit_data ? $deposit_data['deposit_money']   : Money::fromCents(0, $currency);
        $remaining_money = $deposit_data ? $deposit_data['remaining_money'] : Money::fromCents(0, $currency);
        $allow_choice    = get_option('mhbo_deposit_allow_guest_choice', 1);

        // Hide wrapper until JS populates amounts from the REST price response.
        $wrapper_style = $has_total ? 'margin-bottom: 30px;' : 'margin-bottom: 30px; display: none;';
        ?>
        <div class="mhbo-deposit-options-wrapper" style="<?php echo esc_attr($wrapper_style); ?>">
            <h4 style="margin-top:25px; margin-bottom:15px;"><?php echo esc_html(I18n::get_label('label_payment_options')); ?></h4>
            <div class="mhbo-deposit-options">
                <!-- Pay in Full Card -->
                <div class="mhbo-payment-card <?php echo $allow_choice ? 'active' : 'disabled'; ?>" data-payment-type="full" data-amount="<?php echo esc_attr($total->toDecimal()); ?>">
                    <input type="radio" name="mhbo_payment_type" value="full" <?php checked(true); ?> <?php disabled(!$allow_choice); ?>>
                    <div class="mhbo-card-header">
                        <div class="mhbo-card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <span class="mhbo-card-title"><?php echo esc_html(I18n::get_label('label_pay_full')); ?></span>
                    </div>
                    <div class="mhbo-card-amount mhbo-full-amount"><?php echo esc_html($total->format()); ?></div>
                    <div class="mhbo-card-description mhbo-full-description"><?php echo esc_html(I18n::get_label('label_pay_full_desc')); ?></div>
                    <div class="mhbo-card-selected-indicator"></div>
                </div>

                <!-- Pay Deposit Card -->
                <div class="mhbo-payment-card <?php echo esc_attr(!$allow_choice ? 'active' : ''); ?>"
                    data-payment-type="deposit"
                    data-amount="<?php echo esc_attr($deposit_money->toDecimal()); ?>"
                    data-balance="<?php echo esc_attr($remaining_money->toDecimal()); ?>"
                    data-deposit-type="<?php echo esc_attr((string) get_option('mhbo_deposit_type', 'percentage')); ?>"
                    data-deposit-value="<?php echo esc_attr((string) get_option('mhbo_deposit_value', 20)); ?>"
                    data-first-night-amount="<?php echo esc_attr($first_night_money->toDecimal()); ?>">
                    <input type="radio" name="mhbo_payment_type" value="deposit" <?php checked(!$allow_choice); ?>>
                    <?php if (!$allow_choice) : ?>
                        <div class="mhbo-card-badge"><?php echo esc_html(I18n::get_label('label_required')); ?></div>
                    <?php endif; ?>
                    <div class="mhbo-card-header">
                        <div class="mhbo-card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        </div>
                        <span class="mhbo-card-title"><?php echo esc_html(I18n::get_label('label_pay_deposit')); ?></span>
                    </div>
                    <div class="mhbo-card-amount mhbo-deposit-amount"><?php echo esc_html($deposit_money->format()); ?></div>
                    <div class="mhbo-card-description mhbo-deposit-description">
                        <?php
                        printf(
                            esc_html(I18n::get_label('label_pay_deposit_desc')),
                            '<strong class="mhbo-deposit-balance-display">' . esc_html(I18n::format_currency($remaining_money)) . '</strong>'
                        );
                        if ($deposit_data && $deposit_data['is_non_refundable']) {
                            echo ' <div class="mhbo-deposit-note">' . esc_html(I18n::get_label('msg_deposit_non_refundable')) . '</div>';
                        }
                        ?>
                    </div>
                    <div class="mhbo-card-selected-indicator"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Check if the currently configured deposit is non-refundable.
     *
     * @return bool
     */
    public static function is_deposit_non_refundable(): bool
    {
        return (bool) get_option('mhbo_deposit_non_refundable', 0);
    }

    /**
     * Register pricing filters.
     * Called once from Plugin::init() — keeps all pricing logic in one place.
     */
    public static function register_hooks(): void
    {
        add_filter(
            'mhbo_calculate_stay_price_money',
            [ self::class, 'apply_short_stay_peak_surcharge' ],
            10,
            3
        );
    }

    /** @var Money|null Accumulated peak-season short-stay surcharge for the current calculation. */
    private static ?Money $surcharge_total = null;

    /** @var int Number of nights the surcharge was applied to, for the current calculation. */
    private static int $surcharge_nights = 0;

    /**
     * Reset surcharge tracking. Call once before running calculate_booking_money()
     * for a fresh booking calculation (e.g. at the top of render_booking_form()).
     */
    public static function reset_surcharge_tracking(): void
    {
        self::$surcharge_total  = null;
        self::$surcharge_nights = 0;
    }

    /**
     * Returns info about any peak-season short-stay surcharge applied since the
     * last reset_surcharge_tracking() call.
     *
     * @return array{nights:int, total:?Money}
     */
    public static function get_surcharge_info(): array
    {
        return [
            'nights' => self::$surcharge_nights,
            'total'  => self::$surcharge_total,
        ];
    }

    public static function apply_short_stay_peak_surcharge( Money $price, int $room_id, string $date ): Money
    {
        $nights = (int) ( $GLOBALS['mhbo_current_stay_nights'] ?? 0 );

        if ( $nights === 0 || $nights >= get_option( 'mhbo_peak_season_short_stay_threshold', 3) ) {
            return $price;
        }

        $day          = new \DateTime( $date );
        $year         = (int) $day->format( 'Y' );
        $season_start = new \DateTime( "{$year}-07-01" );
        $season_end   = new \DateTime( "{$year}-09-01" );

        if ( $day < $season_start || $day >= $season_end ) {
            return $price;
        }

        // Peak season short stay — apply 10% surcharge
        $surcharged = $price->multiply( '1.10' );

        // Track the extra amount so the UI can show a line item without
        // re-deriving the season/night rules elsewhere.
        $extra = $surcharged->subtract( $price );
        self::$surcharge_total  = self::$surcharge_total ? self::$surcharge_total->add( $extra ) : $extra;
        self::$surcharge_nights++;

        return $surcharged;
    }    
}