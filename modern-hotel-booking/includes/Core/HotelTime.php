<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hotel timezone-aware date helpers.
 *
 * Centralises all date operations so every system (pricing, iCal, availability,
 * calendar UI) uses the admin-configured hotel timezone rather than raw UTC
 * (gmdate) or the WordPress site timezone.
 *
 * Option key: mhbo_hotel_timezone (IANA identifier, e.g. "Europe/Bucharest")
 * Default:    Falls back to WP timezone_string, then numeric gmt_offset, then UTC.
 *
 * IMPORTANT: Call HotelTime::reset() any time the mhbo_hotel_timezone option
 * is saved so the cached \DateTimeZone object is refreshed.
 *
 * @package MHBO\Core
 * @since 2.3.8
 */
class HotelTime
{
    /** @var \DateTimeZone|null Runtime-cached timezone object (process-scoped). */
    private static ?\DateTimeZone $tz = null;

    // ------------------------------------------------------------------ //
    // Public API
    // ------------------------------------------------------------------ //

    /**
     * Get the hotel's configured \DateTimeZone object.
     * Falls back to WP site timezone, then numeric offset, then UTC.
     */
    public static function timezone(): \DateTimeZone
    {
        if (null !== self::$tz) {
            return self::$tz;
        }

        $tz_string = (string) get_option('mhbo_hotel_timezone', '');

        // Fall back to WordPress site timezone.
        if ('' === $tz_string) {
            $tz_string = (string) get_option('timezone_string', '');
        }

        // Fall back to numeric offset (e.g. gmt_offset = 5.5 → "+05:30").
        if ('' === $tz_string) {
            $offset   = (float) get_option('gmt_offset', 0);
            $h        = (int) $offset;
            $m        = abs((int) round(($offset - $h) * 60));
            $tz_string = sprintf('%+03d:%02d', $h, $m);
        }

        try {
            self::$tz = new \DateTimeZone($tz_string);
        } catch (\Exception $e) {
            self::$tz = new \DateTimeZone('UTC');
        }

        return self::$tz;
    }

    /**
     * Invalidate the cached timezone object.
     * Hook this to update_option_mhbo_hotel_timezone via:
     *   add_action('update_option_mhbo_hotel_timezone', ['MHBO\Core\HotelTime', 'reset']);
     */
    public static function reset(): void
    {
        self::$tz = null;
    }

    /**
     * Timezone-aware replacement for gmdate().
     *
     * @param string $format    PHP date format string.
     * @param int    $timestamp Unix timestamp.
     * @return string Formatted date string in hotel timezone.
     */
    public static function date(string $format, int $timestamp): string
    {
        return (string) wp_date($format, $timestamp, self::timezone());
    }

    /**
     * Current date in hotel timezone as Y-m-d.
     */
    public static function today(): string
    {
        return self::date('Y-m-d', time());
    }

    /**
     * Convert a Y-m-d string to a Unix timestamp for midnight in hotel timezone.
     *
     * @param string $ymd Date in Y-m-d format.
     * @return int Unix timestamp for midnight of that date in hotel timezone.
     */
    public static function midnight(string $ymd): int
    {
        try {
            $dt = new \DateTime($ymd . ' 00:00:00', self::timezone());
            return (int) $dt->getTimestamp();
        } catch (\Exception $e) {
            return (int) strtotime($ymd);
        }
    }

    /**
     * Lowercase day name (e.g. 'friday') for a date in hotel timezone.
     *
     * Replaces: strtolower(gmdate('l', (int) strtotime($date)))
     * which resolves in UTC and can be off-by-one near midnight in non-UTC hotels.
     *
     * @param string $ymd Date in Y-m-d format.
     * @return string Lowercase English day name ('monday'…'sunday').
     */
    public static function day_name(string $ymd): string
    {
        return strtolower(self::date('l', self::midnight($ymd)));
    }

    /**
     * ISO 8601 day-of-week number (1=Mon … 7=Sun) in hotel timezone.
     *
     * @param string $ymd Date in Y-m-d format.
     * @return int 1 (Monday) through 7 (Sunday).
     */
    public static function day_of_week(string $ymd): int
    {
        return (int) self::date('N', self::midnight($ymd));
    }
}
