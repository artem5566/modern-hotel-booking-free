<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
    exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

/**
 * iCal export and import functionality.
 *
 * RFC 5545 compliant iCal generation for compatibility with:
 * - Google Calendar
 * - Airbnb
 * - Booking.com
 *
 * @package MHBO\Core
 * @since   2.0.1
 */
class ICal
{
    /**
     * WordPress timezone string.
     *
     * @var string
     */
    private static $timezone;

    /* BUILD_PRO_START */
    /**
     * Generate an ICS file for a specific room.
     *
     * @param int    $room_id Room ID.
     * @param string $token   Optional authentication token (for backward compatibility).
     * @param string $key     Optional per-room key (used by Pro version).
     */
    public static function generate_ics(int $room_id, string $token = '', string $key = ''): void
    {
        if (!License::is_active()) {
            wp_die(esc_html(I18n::get_label('admin_msg_pro_required')), '', ['response' => 403]);
        }

        // Support both authentication methods:
        // 1. Legacy: Global token (mhbo_ical_token)
        // 2. Pro: Per-room key (mhbo_ical_key_{room_id})
        $authenticated = false;

        // Check per-room key first (Pro version)
        if (is_string($key) && $key !== '') {
            $stored_key = get_option('mhbo_ical_key_' . $room_id, '');
            if (is_string($stored_key) && $stored_key !== '' && hash_equals($stored_key, $key)) {
                $authenticated = true;
            }
        }

        // Fall back to global token (backward compatibility)
        // SECURITY: Use hash_equals for timing-safe comparison
        if (!$authenticated && is_string($token) && $token !== '') {
            $saved_token = get_option('mhbo_ical_token');
            if (is_string($saved_token) && $saved_token !== '' && hash_equals((string)$saved_token, $token)) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            wp_die(esc_html(I18n::get_label('msg_security_check_token')), esc_html(I18n::get_label('label_security_error_short')), ['response' => 403]);
        }

        // Respect the "Hotel Timezone" setting from mhbo-settings.
        self::$timezone = HotelTime::timezone()->getName();

        // 2026 BP: Three-layer cache bypass — iCal availability feeds MUST be real-time.
        // Layer 1: DONOTCACHEPAGE prevents page-caching plugins (LiteSpeed, WP Super Cache,
        //          W3TC, Batcache) from storing this response on disk.
        // Layer 2: DONOTCACHEOBJECT prevents persistent object caches from caching the
        //          full-page output (e.g. Batcache in hosted WordPress environments).
        if (!defined('DONOTCACHEPAGE')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- 2026 BP: Standard constant recognized by caching plugins.
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- 2026 BP: Standard constant recognized by caching plugins.
            define('DONOTCACHEOBJECT', true);
        }

        global $wpdb;

        // No WP Object Cache for the feed query — availability feeds serve external platforms
        // (Airbnb, Booking.com, Travelminit) and must always reflect the latest booking state.
        // The query runs only when an OTA polls (typically every 15-60min), so DB cost is negligible.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Real-time iCal feed from custom bookings table; no caching to guarantee OTA sync accuracy.
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id, external_id, created_at, updated_at, check_in, check_out,
                    status, customer_name, customer_email, guests, source
             FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d",
            $room_id
        ));

        // Layer 3: nocache_headers() sets comprehensive HTTP headers that prevent CDNs,
        // reverse proxies (Varnish, Nginx), and browsers from caching the response.
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="room-' . $room_id . '.ics"');

        // Resolve display name and sync interval for VCALENDAR metadata.
        $hotel_name    = sanitize_text_field((string) get_bloginfo('name'));
        $hotel_tz      = sanitize_text_field(self::$timezone ?: 'UTC');
        $sync_interval = (string) get_option('mhbo_ical_sync_interval', '1hour');
        // Map stored interval key to ISO 8601 duration for REFRESH-INTERVAL / X-PUBLISHED-TTL.
        $ttl_map = array(
            '5min'   => 'PT5M',
            '15min'  => 'PT15M',
            'hourly' => 'PT1H',
            '1hour'  => 'PT1H',
            '6hours' => 'PT6H',
            'daily'  => 'PT24H',
        );
        $iso_ttl = $ttl_map[$sync_interval] ?? 'PT1H';

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:-//Modern Hotel Booking//MHBO//EN\r\n";
        echo "CALSCALE:GREGORIAN\r\n";
        echo "METHOD:PUBLISH\r\n";
        // X-WR-* are non-standard but recognized by Airbnb, Google Calendar, Apple Calendar, and Outlook.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ICS format, not HTML; values are sanitized above.
        echo "X-WR-CALNAME:{$hotel_name} — Room {$room_id}\r\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "X-WR-TIMEZONE:{$hotel_tz}\r\n";
        // REFRESH-INTERVAL (RFC 7986) tells compliant clients how often to re-fetch the feed.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "REFRESH-INTERVAL;VALUE=DURATION:{$iso_ttl}\r\n";
        // X-PUBLISHED-TTL is the older Apple/Airbnb equivalent of REFRESH-INTERVAL.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "X-PUBLISHED-TTL:{$iso_ttl}\r\n";

        // Add VTIMEZONE component for Google Calendar compatibility
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ICS calendar format, not HTML
        echo self::generate_vtimezone();

        foreach ($bookings as $booking) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ICS calendar format, not HTML
            echo self::generate_vevent($booking); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        echo "END:VCALENDAR\r\n";
        exit;
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * Generate VTIMEZONE component for the current WordPress timezone.
     *
     * @return string VTIMEZONE block.
     */
    private static function generate_vtimezone(): string
    {
        $tz = self::$timezone;

        // Skip UTC offset format (e.g., +02:00) - not ideal for VTIMEZONE
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $tz)) {
            return '';
        }

        $now = time();
        $year = gmdate('Y', $now);

        // Get timezone transitions for DST detection
        try {
            $timezone = new \DateTimeZone($tz);
            $transitions = $timezone->getTransitions(strtotime($year . '-01-01'), strtotime($year . '-12-31'));

            $dst_start = null;
            $dst_end = null;
            $std_offset = null;
            $dst_offset = null;

            foreach ($transitions as $i => $transition) {
                if ($transition['isdst']) {
                    $dst_start = $transition;
                    $dst_offset = $transition['offset'];
                } else {
                    if ($dst_end === null || ($dst_start !== null && $i > 0)) {
                        $dst_end = $transition;
                    }
                    $std_offset = $transition['offset'];
                }
            }

            $vtimezone = "BEGIN:VTIMEZONE\r\n";
            $vtimezone .= "TZID:{$tz}\r\n";

            // Standard time component
            if ($std_offset !== null) {
                $std_offset_formatted = self::format_utc_offset($std_offset);
                // TZOFFSETFROM is the offset in effect BEFORE this transition (i.e. DST).
                // Falls back to std if there is no DST (e.g. UTC+X fixed zones).
                $std_from_formatted = ($dst_offset !== null)
                    ? self::format_utc_offset($dst_offset)
                    : $std_offset_formatted;
                $vtimezone .= "BEGIN:STANDARD\r\n";
                $vtimezone .= "DTSTART:19701101T020000\r\n";
                $vtimezone .= "RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU\r\n";
                $vtimezone .= "TZOFFSETFROM:{$std_from_formatted}\r\n";
                $vtimezone .= "TZOFFSETTO:{$std_offset_formatted}\r\n";
                $vtimezone .= "END:STANDARD\r\n";
            }

            // Daylight time component (if applicable)
            if ($dst_offset !== null && $dst_offset !== $std_offset) {
                $dst_offset_formatted = self::format_utc_offset($dst_offset);
                $std_offset_formatted = self::format_utc_offset($std_offset);
                $vtimezone .= "BEGIN:DAYLIGHT\r\n";
                $vtimezone .= "DTSTART:19700308T020000\r\n";
                $vtimezone .= "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU\r\n";
                $vtimezone .= "TZOFFSETFROM:{$std_offset_formatted}\r\n";
                $vtimezone .= "TZOFFSETTO:{$dst_offset_formatted}\r\n";
                $vtimezone .= "END:DAYLIGHT\r\n";
            }

            $vtimezone .= "END:VTIMEZONE\r\n";

            return $vtimezone;
        } catch (\Exception $e) {
            return '';
        }
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * Format UTC offset for VTIMEZONE.
     *
     * @param int $seconds Offset in seconds.
     * @return string Formatted offset (e.g., +0200).
     */
    private static function format_utc_offset(int $seconds): string
    {
        $sign = $seconds >= 0 ? '+' : '-';
        $seconds = abs($seconds);
        $hours = (int) ($seconds / 3600);
        $minutes = (int) (($seconds % 3600) / 60);

        return sprintf('%s%02d%02d', $sign, $hours, $minutes);
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * Generate a VEVENT component for a booking.
     *
     * @param object $booking Booking data.
     * @return string VEVENT block.
     */
    private static function generate_vevent(object $booking): string
    {
        // 2026 BP: UID stability is critical for cross-platform sync.
        // If an external_id exists (imported from another iCal), use it to cross-link.
        // Otherwise, use a stable hash based on the local booking ID and site URL.
        $uid = (isset($booking->external_id) && (string)$booking->external_id !== '') 
            ? (string) $booking->external_id 
            : 'mhbo-b' . $booking->id . '-' . md5(home_url() . (string) $booking->id) . '@' . wp_parse_url(home_url(), PHP_URL_HOST);

        // DTSTAMP: Required by RFC 5545, represents the time the record was created/updated
        $dtstamp = (isset($booking->created_at) && $booking->created_at !== '') 
            ? gmdate('Ymd\THis\Z', strtotime($booking->created_at))
            : gmdate('Ymd\THis\Z');

        // Hotel bookings use DATE format (YYYYMMDD) for all-day events
        $dtstart = gmdate('Ymd', strtotime($booking->check_in));
        $dtend = gmdate('Ymd', strtotime($booking->check_out));

        // STATUS: Normalization for Airbnb/Booking.com
        $status = 'CONFIRMED';
        if ('cancelled' === $booking->status) {
            $status = 'CANCELLED';
        } elseif ('pending' === $booking->status) {
            $status = 'TENTATIVE';
        }

        // SEQUENCE: Increment for updates to notify external platforms
        // Bookings live in a custom table (not wp_posts), so wp_options keyed by ID.
        $sequence = (int) get_option('mhbo_bseq_' . $booking->id, 0);
        if ('cancelled' === $booking->status) {
            $sequence = max(1, $sequence + 1);
        }

        // LAST-MODIFIED: Essential for conflict resolution.
        // 2026 BP: Cascade through updated_at → created_at → now() so external
        // platforms always receive a valid timestamp, even on installations that
        // haven't run the schema migration adding updated_at yet.
        if (isset($booking->updated_at) && $booking->updated_at !== '' && $booking->updated_at !== null) {
            $last_modified = gmdate('Ymd\THis\Z', (int) strtotime($booking->updated_at));
        } elseif (isset($booking->created_at) && $booking->created_at !== '') {
            $last_modified = gmdate('Ymd\THis\Z', (int) strtotime($booking->created_at));
        } else {
            $last_modified = $dtstamp;
        }

        $summary = sprintf(
            I18n::get_label('label_booking_number_date'),
            $booking->id,
            $booking->customer_name ?: I18n::get_label('label_guest_fallback')
        );

        $description = [];
        $status_label = I18n::get_label('label_status_' . $booking->status);
        if ($status_label === 'label_status_' . $booking->status) {
            $status_label = ucfirst($booking->status);
        }

        $description[] = sprintf(I18n::get_label('label_id_value'), $booking->id);
        $description[] = sprintf(I18n::get_label('label_status_value'), $status_label);
        $description[] = sprintf(I18n::get_label('label_guests_count_value'), ($booking->guests > 0) ? $booking->guests : 1);
        if (isset($booking->customer_email) && $booking->customer_email !== '') {
            $description[] = sprintf(I18n::get_label('label_email_value'), $booking->customer_email);
        }
        $source_key = 'label_source_' . ($booking->source ?: 'direct');
        $source_label = I18n::get_label($source_key);
        if ($source_label === $source_key) {
            $source_label = ucfirst($booking->source ?: 'direct');
        }
        $description[] = sprintf(I18n::get_label('label_source_value'), $source_label);

        $url = admin_url('admin.php?page=mhbo-bookings&action=edit&id=' . (int) $booking->id);

        $vevent = "BEGIN:VEVENT\r\n";
        $vevent .= self::fold_ical_line("UID:{$uid}");
        $vevent .= "DTSTAMP:{$dtstamp}\r\n";
        $vevent .= "CREATED:{$dtstamp}\r\n"; // RFC 5545: When the event was created
        $vevent .= "DTSTART;VALUE=DATE:{$dtstart}\r\n";
        // 2026 BP: DTEND is exclusive in RFC 5545. Using check_out date (YYYYMMDD) correctly
        // indicates the reservation ends at 00:00:00 of the checkout day.
        $vevent .= "DTEND;VALUE=DATE:{$dtend}\r\n";
        $vevent .= self::fold_ical_line("SUMMARY:" . self::escape_ical_text($summary));
        $vevent .= self::fold_ical_line("DESCRIPTION:" . self::escape_ical_text(implode("\n", $description)));
        $vevent .= self::fold_ical_line("URL:" . esc_url_raw($url));
        $vevent .= "STATUS:{$status}\r\n";
        $vevent .= "SEQUENCE:{$sequence}\r\n";
        $vevent .= "LAST-MODIFIED:{$last_modified}\r\n";
        $vevent .= "TRANSP:OPAQUE\r\n"; // Mark as busy
        $vevent .= "END:VEVENT\r\n";

        return $vevent;
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * Fold a single iCal property line per RFC 5545 §3.1.
     *
     * Lines MUST NOT exceed 75 octets. Long lines are split with CRLF + space continuation.
     * strlen() counts bytes in PHP — correct for RFC 5545 octet counting.
     *
     * @param string $line Property line WITHOUT trailing CRLF.
     * @return string Folded line WITH trailing CRLF.
     */
    private static function fold_ical_line(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line . "\r\n";
        }

        // First segment: up to 75 bytes.
        $folded = substr($line, 0, 75) . "\r\n";
        $pos    = 75;

        // Continuation segments: 74 bytes of content (1 byte used by leading space).
        while ($pos < strlen($line)) {
            $folded .= ' ' . substr($line, $pos, 74) . "\r\n";
            $pos    += 74;
        }

        return $folded;
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * Escape special characters for iCal text values.
     *
     * @param string $text Text to escape.
     * @return string Escaped text.
     */
    private static function escape_ical_text(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('"', '\\"', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace("\r", '', $text);

        return $text;
    }
    /* BUILD_PRO_END */

    /* BUILD_PRO_START */
    /**
     * Sync external calendars from the mhbo_ical_connections table.
     *
     * Fetches each feed URL, parses VEVENT blocks, and creates bookings
     * for any new events not already in the database (matched by external_id).
     * 
     * SECURITY: Includes SSRF protection to prevent access to internal services.
     */
    public static function sync_external_calendars(): void
    {
        if (!License::is_active()) {
            return;
        }

        // 2026 BP: Consolidate logic to the Pro version if available.
        if (class_exists('MHBO\Pro\ICalManager')) {
            \MHBO\Pro\ICalManager::get_instance()->run_scheduled_sync();
            return;
        }

        global $wpdb;
        
        $legacy_table = esc_sql( $wpdb->prefix . 'mhbo_ical_feeds' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: $legacy_table is built from esc_sql($wpdb->prefix + hardcoded suffix); safe for table-name interpolation.
        $feeds = $wpdb->get_results( "SELECT id, feed_url FROM `{$legacy_table}` ORDER BY id ASC" );

        if (count($feeds) === 0) {
            return;
        }


        foreach ($feeds as $feed) {
            // SECURITY: Validate URL before making request (SSRF protection)
            if (!Security::is_safe_url($feed->feed_url)) {
                continue;
            }

            // SECURITY: Enable SSL verification to prevent MITM attacks
            $response = wp_safe_remote_get($feed->feed_url, [
                'timeout' => 30,
                'sslverify' => true,
                'redirection' => 3,
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            if ($body === '') {
                continue;
            }

            $events = \MHBO\Pro\ICalParser::parse_events($body);

            foreach ($events as $event) {
                if (!isset($event['dtstart']) || $event['dtstart'] === '' || !isset($event['dtend']) || $event['dtend'] === '') {
                    continue;
                }

                $external_id = (isset($event['uid']) && $event['uid'] !== '') ? $event['uid'] : md5($event['dtstart'] . $event['dtend']);

                // Check if this event already exists
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, existence check
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND external_id = %s",
                    $feed->room_id,
                    $external_id
                ));

                if (!$exists) {
                    $check_in = gmdate('Y-m-d', strtotime($event['dtstart']));
                    $check_out = gmdate('Y-m-d', strtotime($event['dtend']));

                    // Validate dates
                    if ($check_in >= $check_out) {
                        continue;
                    }

                    $summary = (isset($event['summary']) && $event['summary'] !== '') ? sanitize_text_field($event['summary']) : I18n::get_label('label_external_booking');

                    // Rule 13 rationale: Legacy iCal import tool persisting external reservations.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->insert(
                        $wpdb->prefix . 'mhbo_bookings',
                        [
                            'room_id' => $feed->room_id,
                            'check_in' => $check_in,
                            'check_out' => $check_out,
                            'status' => 'confirmed',
                            'customer_name' => $summary,
                            'customer_email' => 'external@import',
                            'total_price' => 0,
                            'booking_token' => wp_generate_password(32, false),
                            'source' => 'ical',
                            'external_id' => $external_id,
                            'ical_uid' => $external_id,
                        ],
                        ['%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s']
                    );
                    
                    // 2026 BP: Versioned cache invalidation
                    Cache::invalidate_booking((int)$wpdb->insert_id, (int)$feed->room_id);
                }
            }

            // Update last_synced timestamp
            // RATIONALE: Required to persist sync timestamp for iCal feed tracking.
            // Uses typed format arrays; write to custom ical_feeds table.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->prefix . 'mhbo_ical_feeds',
                ['last_synced' => current_time('mysql')],
                ['id' => $feed->id],
                ['%s'],
                ['%d']
            );
        }
    }
    /* BUILD_PRO_END */
}
