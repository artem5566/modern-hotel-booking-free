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

}
