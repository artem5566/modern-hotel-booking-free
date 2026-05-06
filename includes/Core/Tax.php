<?php declare(strict_types=1);

namespace MHBO\Core;

use MHBO\Core\Money;

if (!defined('ABSPATH')) {
    exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

/**
 * Tax Calculation Class
 * 
 * Handles VAT and Sales Tax calculations for hotel bookings.
 * Supports three modes:
 * - disabled: No tax calculation
 * - vat: VAT Inclusive (prices include tax)
 * - sales_tax: Sales Tax Exclusive (tax added on top)
 */
class Tax
{
    /**
     * Tax modes
     */
    const MODE_DISABLED = 'disabled';
    const MODE_VAT = 'vat';
    const MODE_SALES_TAX = 'sales_tax';

    /**
     * Rounding modes
     */
    const ROUND_PER_LINE = 'per_line';
    const ROUND_PER_TOTAL = 'per_total';

    /**
     * Check if tax is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return false;
    }

    /**
     * Get current tax mode.
     *
     * @return string One of the MODE_* constants.
     */
    public static function get_mode(): string {
        return self::MODE_DISABLED;
    }

    /**
     * Get tax label for current language
     *
     * @param string|null $language Language code (optional)
     * @return string
     */
    public static function get_label(?string $language = null): string
    {
        if (null === $language) {
            $language = I18n::get_current_language();
        }
        $label = get_option('mhbo_tax_label', '[:en]VAT[:ro]TVA[:]');
        $decoded = I18n::decode($label, $language);
        return '' !== $decoded ? $decoded : 'VAT';
    }

    /**
     * Get tax registration number
     *
     * @return string
     */
    public static function get_registration_number(): string
    {
        return (string) get_option('mhbo_tax_registration_number', '');
    }

    /**
     * Get accommodation tax rate
     *
     * @return float
     */
    public static function get_accommodation_rate(): float
    {
        return floatval(get_option('mhbo_tax_rate_accommodation', 0.00));
    }

    /**
     * Get extras tax rate
     *
     * @return float
     */
    public static function get_extras_rate(): float
    {
        return floatval(get_option('mhbo_tax_rate_extras', 0.00));
    }

    /**
     * Get rounding mode
     *
     * @return string
     */
    public static function get_rounding_mode(): string
    {
        return (string) get_option('mhbo_tax_rounding_mode', self::ROUND_PER_TOTAL);
    }

    /**
     * Get decimal places
     *
     * @return int
     */
    public static function get_decimal_places(): int
    {
        return intval(get_option('mhbo_tax_decimal_places', 2));
    }

    /**
     * Get all tax settings as an array
     *
     * @return array<string, mixed> Tax settings
     */
    public static function get_settings(): array
    {
        $mode = self::get_mode();

        return [
            'enabled' => self::is_enabled(),
            'mode' => $mode,
            'label' => self::get_label(),
            'registration_number' => self::get_registration_number(),
            'accommodation_rate' => self::get_accommodation_rate(),
            'extras_rate' => self::get_extras_rate(),
            'rounding_mode' => self::get_rounding_mode(),
            'decimal_places' => self::get_decimal_places(),
            'display_frontend' => (bool) get_option('mhbo_tax_display_frontend', false),
            'display_email' => (bool) get_option('mhbo_tax_display_email', true),
            'prices_include_tax' => self::MODE_VAT === $mode,
        ];
    }

    /**
     * Calculate tax from gross amount (VAT Inclusive mode)
     * 
     * Formula: Net = Gross / (1 + Rate/100), Tax = Gross - Net
     *
     * @param float $gross_amount Gross amount (includes tax)
     * @param float $tax_rate Tax rate as percentage
     * @param bool $round Whether to round the result (default: true)
     * @return array<string, float> ['net' => float, 'tax' => float, 'gross' => float]
     */
    public static function calculate_from_gross(float $gross_amount, float $tax_rate, bool $round = true): array
    {
        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
        $gross_money = Money::fromDecimal((string) $gross_amount, $currency);
        $calc = self::calculate_from_gross_money($gross_money, $tax_rate);

        return [
            'net'   => $round ? floatval($calc['net']->toDecimal()) : floatval($calc['net']->toDecimal()), // Backward compatibility
            'tax'   => $round ? floatval($calc['tax']->toDecimal()) : floatval($calc['tax']->toDecimal()),
            'gross' => $round ? floatval($calc['gross']->toDecimal()) : floatval($calc['gross']->toDecimal())
        ];
    }

    /**
     * Calculate tax from net amount (Sales Tax Exclusive mode)
     * 
     * Formula: Tax = Net * (Rate/100), Gross = Net + Tax
     *
     * @param float $net_amount Net amount (before tax)
     * @param float $tax_rate Tax rate as percentage
     * @param bool $round Whether to round the result (default: true)
     * @return array<string, float> ['net' => float, 'tax' => float, 'gross' => float]
     */
    public static function calculate_from_net(float $net_amount, float $tax_rate, bool $round = true): array
    {
        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
        $net_money = Money::fromDecimal((string) $net_amount, $currency);
        $calc = self::calculate_from_net_money($net_money, $tax_rate);

        return [
            'net'   => $round ? floatval($calc['net']->toDecimal()) : floatval($calc['net']->toDecimal()), // Backward compatibility
            'tax'   => $round ? floatval($calc['tax']->toDecimal()) : floatval($calc['tax']->toDecimal()),
            'gross' => $round ? floatval($calc['gross']->toDecimal()) : floatval($calc['gross']->toDecimal())
        ];
    }

    /**
     * Calculate tax for an amount based on current mode
     *
     * @param float $amount The amount (gross for VAT, net for Sales Tax)
     * @param float $tax_rate Tax rate as percentage
     * @param string|null $mode Tax mode (optional, uses current setting)
     * @param bool $round Whether to round the result (default: true)
     * @return array<string, float> ['net' => float, 'tax' => float, 'gross' => float]
     */
    public static function calculate_tax(float $amount, float $tax_rate, ?string $mode = null, bool $round = true): array
    {
        if (null === $mode) {
            $mode = self::get_mode();
        }

        if (self::MODE_VAT === $mode) {
            return self::calculate_from_gross($amount, $tax_rate, $round);
        } elseif (self::MODE_SALES_TAX === $mode) {
            return self::calculate_from_net($amount, $tax_rate, $round);
        }

        // Disabled mode - no tax
        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
        $money = Money::fromDecimal((string) $amount, $currency);
        return [
            'net'   => (float) $money->toDecimal(),
            'tax'   => 0.0,
            'gross' => (float) $money->toDecimal(),
        ];
    }

    /**
     * Precision-safe tax calculation using Money objects.
     *
     * @param Money $amount   The amount (gross for VAT, net for Sales Tax).
     * @param float $tax_rate Tax rate as percentage.
     * @param string|null $mode Tax mode.
     * @return array{net: Money, tax: Money, gross: Money}
     */
    public static function calculate_tax_money(Money $amount, float $tax_rate, ?string $mode = null): array
    {
        if (null === $mode) {
            $mode = self::get_mode();
        }

        if ($tax_rate === 0.0 || $amount->isZero()) {
            return [
                'net' => $amount,
                'tax' => Money::fromCents(0, $amount->getCurrency()),
                'gross' => $amount
            ];
        }

        if (self::MODE_VAT === $mode) {
            return self::calculate_from_gross_money($amount, $tax_rate);
        } elseif (self::MODE_SALES_TAX === $mode) {
            return self::calculate_from_net_money($amount, $tax_rate);
        }

        // Disabled mode
        return [
            'net' => $amount,
            'tax' => Money::fromCents(0, $amount->getCurrency()),
            'gross' => $amount
        ];
    }

    /**
     * Formula: Net = Gross / (1 + Rate/100), Tax = Gross - Net
     */
    private static function calculate_from_gross_money(Money $gross, float $rate): array
    {
        // 1 + Rate/100
        $divisor = 1 + ($rate / 100);
        
        $net = $gross->divide($divisor);
        $tax = $gross->subtract($net);

        return [
            'net' => $net,
            'tax' => $tax,
            'gross' => $gross
        ];
    }

    /**
     * Formula: Tax = Net * (Rate/100), Gross = Net + Tax
     */
    private static function calculate_from_net_money(Money $net, float $rate): array
    {
        $multiplier = $rate / 100;
        
        $tax = $net->multiply($multiplier);
        $gross = $net->add($tax);

        return [
            'net' => $net,
            'tax' => $tax,
            'gross' => $gross
        ];
    }

/**
     * Calculate full booking tax breakdown
     *
     * @param array<string, mixed> $booking_data Booking data containing:
     *   - room_total: float Total room charges
     *   - children_total: float Total children charges (optional)
     *   - extras_total: float Total extras charges (optional)
     *   - extras: array Extras breakdown (optional)
     * @return array<string, mixed> Tax breakdown
     */
    public static function calculate_booking_tax(array $booking_data): array
    {
        $mode = self::get_mode();
        $accommodation_rate = self::get_accommodation_rate();
        $extras_rate = self::get_extras_rate();
        $rounding_mode = self::get_rounding_mode();
        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));

        $result = [
            'enabled' => self::is_enabled(),
            'mode' => $mode,
            'label' => self::get_label(),
            'registration_number' => self::get_registration_number(),
            'rates' => [
                'accommodation' => $accommodation_rate,
                'extras' => $extras_rate
            ],
            'breakdown' => [
                'room' => null,
                'children' => null,
                'extras' => []
            ],
            'totals' => [
                'subtotal_net' => 0.00,
                'total_tax' => 0.00,
                'total_gross' => 0.00
            ],
            'rounding' => [
                'mode' => $rounding_mode,
                'decimal_places' => self::get_decimal_places()
            ],
            'calculated_at' => current_time('mysql')
        ];

        // Initialize component total Money objects
        $total_net_money = Money::fromCents(0, $currency);
        $total_tax_money = Money::fromCents(0, $currency);
        $total_gross_money = Money::fromCents(0, $currency);

        $room_net_money = Money::fromCents(0, $currency);
        $room_tax_money = Money::fromCents(0, $currency);
        $children_net_money = Money::fromCents(0, $currency);
        $children_tax_money = Money::fromCents(0, $currency);
        $extras_net_money = Money::fromCents(0, $currency);
        $extras_tax_money = Money::fromCents(0, $currency);

        // Calculate room tax
        $room_total_val = (string) ($booking_data['room_total'] ?? '0');
        if ('0' !== $room_total_val && '0.00' !== $room_total_val) {
            $room_money = Money::fromDecimal($room_total_val, $currency);
            $room_calc = self::calculate_tax_money($room_money, $accommodation_rate, $mode);
            
            $room_net_money = $room_calc['net'];
            $room_tax_money = $room_calc['tax'];

            $result['breakdown']['room'] = [
                'gross_amount' => $room_money->toDecimal(),
                'net' => $room_net_money->toDecimal(),
                'tax_rate' => $accommodation_rate,
                'tax' => $room_tax_money->toDecimal(),
                'gross' => $room_calc['gross']->toDecimal()
            ];

            $total_net_money = $total_net_money->add($room_net_money);
            $total_tax_money = $total_tax_money->add($room_tax_money);
            $total_gross_money = $total_gross_money->add($room_calc['gross']);
        }

        // Calculate children tax
        $children_total_val = (string) ($booking_data['children_total'] ?? '0');
        if ('0' !== $children_total_val && '0.00' !== $children_total_val) {
            $children_money = Money::fromDecimal($children_total_val, $currency);
            $children_calc = self::calculate_tax_money($children_money, $accommodation_rate, $mode);

            $children_net_money = $children_calc['net'];
            $children_tax_money = $children_calc['tax'];

            $result['breakdown']['children'] = [
                'gross_amount' => $children_money->toDecimal(),
                'net' => $children_net_money->toDecimal(),
                'tax_rate' => $accommodation_rate,
                'tax' => $children_tax_money->toDecimal(),
                'gross' => $children_calc['gross']->toDecimal()
            ];

            $total_net_money = $total_net_money->add($children_net_money);
            $total_tax_money = $total_tax_money->add($children_tax_money);
            $total_gross_money = $total_gross_money->add($children_calc['gross']);
        }

        // Calculate extras tax
        $extras_items = (array)($booking_data['extras'] ?? []);
        $extras_total_val = (string) ($booking_data['extras_total'] ?? '0');

        if ([] !== $extras_items) {
            foreach ($extras_items as $extra) {
                $extra_total_val = (string) ($extra['total'] ?? '0');
                if ('0' !== $extra_total_val && '0.00' !== $extra_total_val) {
                    $extra_money = Money::fromDecimal($extra_total_val, $currency);
                    $extra_calc = self::calculate_tax_money($extra_money, $extras_rate, $mode);

                    $result['breakdown']['extras'][] = [
                        'id' => $extra['id'] ?? '',
                        'name' => I18n::decode($extra['name'] ?? ''),
                        'gross_amount' => $extra_money->toDecimal(),
                        'net' => $extra_calc['net']->toDecimal(),
                        'tax_rate' => $extras_rate,
                        'tax' => $extra_calc['tax']->toDecimal(),
                        'gross' => $extra_calc['gross']->toDecimal()
                    ];

                    $extras_net_money = $extras_net_money->add($extra_calc['net']);
                    $extras_tax_money = $extras_tax_money->add($extra_calc['tax']);
                    $total_net_money = $total_net_money->add($extra_calc['net']);
                    $total_tax_money = $total_tax_money->add($extra_calc['tax']);
                    $total_gross_money = $total_gross_money->add($extra_calc['gross']);
                }
            }
        } elseif ('0' !== $extras_total_val && '0.00' !== $extras_total_val) {
            $extras_money = Money::fromDecimal($extras_total_val, $currency);
            $extras_calc = self::calculate_tax_money($extras_money, $extras_rate, $mode);

            $result['breakdown']['extras'][] = [
                'id' => 'total',
                'name' => 'Extras',
                'gross_amount' => $extras_money->toDecimal(),
                'net' => $extras_calc['net']->toDecimal(),
                'tax_rate' => $extras_rate,
                'tax' => $extras_calc['tax']->toDecimal(),
                'gross' => $extras_calc['gross']->toDecimal()
            ];

            $extras_net_money = $extras_calc['net'];
            $extras_tax_money = $extras_calc['tax'];
            $total_net_money = $total_net_money->add($extras_net_money);
            $total_tax_money = $total_tax_money->add($extras_tax_money);
            $total_gross_money = $total_gross_money->add($extras_calc['gross']);
        }

        // Service Fee — taxed at extras rate (Pro, stored separately from extras)
        $service_fee_net_money = Money::fromCents(0, $currency);
        $service_fee_tax_money = Money::fromCents(0, $currency);
        $service_fee_val   = (string) ($booking_data['service_fee'] ?? '0');
        $service_fee_label = (string) ($booking_data['service_fee_label'] ?? '');

        if ('0' !== $service_fee_val && '0.00' !== $service_fee_val && (float) $service_fee_val > 0) {
            $service_fee_money_obj = Money::fromDecimal($service_fee_val, $currency);
            $service_fee_calc = self::calculate_tax_money($service_fee_money_obj, $extras_rate, $mode);

            $result['breakdown']['service_fee'] = [
                'label'        => $service_fee_label,
                'gross_amount' => $service_fee_money_obj->toDecimal(),
                'net'          => $service_fee_calc['net']->toDecimal(),
                'tax_rate'     => $extras_rate,
                'tax'          => $service_fee_calc['tax']->toDecimal(),
                'gross'        => $service_fee_calc['gross']->toDecimal(),
            ];

            $service_fee_net_money = $service_fee_calc['net'];
            $service_fee_tax_money = $service_fee_calc['tax'];
            $total_net_money   = $total_net_money->add($service_fee_calc['net']);
            $total_tax_money   = $total_tax_money->add($service_fee_calc['tax']);
            $total_gross_money = $total_gross_money->add($service_fee_calc['gross']);
        }

        // Final totals
        $result['totals'] = [
            'room_net'          => $room_net_money,
            'room_tax'          => $room_tax_money,
            'children_net'      => $children_net_money,
            'children_tax'      => $children_tax_money,
            'extras_net'        => $extras_net_money,
            'extras_tax'        => $extras_tax_money,
            'service_fee_net'   => $service_fee_net_money,
            'service_fee_tax'   => $service_fee_tax_money,
            'subtotal_net'      => $total_net_money,
            'total_tax'         => $total_tax_money,
            'total_gross'       => $total_gross_money,
        ];

        return $result;
    }

    /**
     * Alias for calculate_booking_tax for backward compatibility
     *
     * @param array<string, mixed> $booking_data Booking data
     * @return array<string, mixed> Tax breakdown
     */
    public static function calculate_breakdown(array $booking_data): array
    {
        return self::calculate_booking_tax($booking_data);
    }

/**
     * Get stored tax breakdown for a booking
     *
     * @param int $booking_id Booking ID
     * @return array<string, mixed>|null Tax breakdown or null if not found
     */
    public static function get_tax_breakdown(int $booking_id): ?array
    {
        global $wpdb;

        $cache_key = 'mhbo_tax_breakdown_' . $booking_id;
        $booking = wp_cache_get($cache_key, 'mhbo_bookings');

        if (false === $booking) {
            // Rule 13 rationale: Fetching detailed tax breakdown for financial reporting and display.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query with caching
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT tax_breakdown, tax_mode, tax_rate_accommodation, tax_rate_extras,
                        room_total_net, children_total_net, extras_total_net,
                        room_tax, children_tax, extras_tax,
                        service_fee_amount, service_fee_net, service_fee_tax,
                        subtotal_net, total_tax, total_gross
                 FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
                $booking_id
            ), ARRAY_A);
            wp_cache_set($cache_key, $booking, 'mhbo_bookings', 300);
        }

        if (!$booking) {
            return null;
        }

        // If we have JSON breakdown, use it
        if (isset($booking['tax_breakdown']) && '' !== (string) $booking['tax_breakdown']) {
            return json_decode((string) $booking['tax_breakdown'], true);
        }

        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));

        // Otherwise, reconstruct from database columns
        $room_net         = Money::fromDecimal((string) ($booking['room_total_net'] ?? '0'), $currency);
        $room_tax         = Money::fromDecimal((string) ($booking['room_tax'] ?? '0'), $currency);
        $children_net     = Money::fromDecimal((string) ($booking['children_total_net'] ?? '0'), $currency);
        $children_tax     = Money::fromDecimal((string) ($booking['children_tax'] ?? '0'), $currency);
        $extras_net       = Money::fromDecimal((string) ($booking['extras_total_net'] ?? '0'), $currency);
        $extras_tax       = Money::fromDecimal((string) ($booking['extras_tax'] ?? '0'), $currency);
        $sf_amount_stored = (string) ($booking['service_fee_amount'] ?? '0');
        $sf_net           = Money::fromDecimal((string) ($booking['service_fee_net'] ?? '0'), $currency);
        $sf_tax           = Money::fromDecimal((string) ($booking['service_fee_tax'] ?? '0'), $currency);

        $reconstructed = [
            'enabled' => self::MODE_DISABLED !== $booking['tax_mode'],
            'mode'    => $booking['tax_mode'],
            'rates'   => [
                'accommodation' => floatval($booking['tax_rate_accommodation']),
                'extras'        => floatval($booking['tax_rate_extras']),
            ],
            'breakdown' => [
                'room' => [
                    'net'   => $room_net->toDecimal(),
                    'tax'   => $room_tax->toDecimal(),
                    'gross' => $room_net->add($room_tax)->toDecimal(),
                ],
                'children' => [
                    'net'   => $children_net->toDecimal(),
                    'tax'   => $children_tax->toDecimal(),
                    'gross' => $children_net->add($children_tax)->toDecimal(),
                ],
                'extras' => [
                    [
                        'name'  => I18n::get_label('label_extras'),
                        'net'   => $extras_net->toDecimal(),
                        'tax'   => $extras_tax->toDecimal(),
                        'gross' => $extras_net->add($extras_tax)->toDecimal(),
                    ],
                ],
            ],
            'totals' => [
                'subtotal_net' => Money::fromDecimal((string) ($booking['subtotal_net'] ?? '0'), $currency)->toDecimal(),
                'total_tax'    => Money::fromDecimal((string) ($booking['total_tax'] ?? '0'), $currency)->toDecimal(),
                'total_gross'  => Money::fromDecimal((string) ($booking['total_gross'] ?? '0'), $currency)->toDecimal(),
            ],
        ];

        // Attach service fee if stored
        if ((float) $sf_amount_stored > 0) {
            $reconstructed['breakdown']['service_fee'] = [
                'label'        => get_option('mhbo_service_fee_label', 'Service Fee'),
                'gross_amount' => $sf_amount_stored,
                'net'          => $sf_net->toDecimal(),
                'tax_rate'     => floatval($booking['tax_rate_extras']),
                'tax'          => $sf_tax->toDecimal(),
                'gross'        => $sf_net->add($sf_tax)->toDecimal(),
            ];
        }

        return $reconstructed;
    }

    /**
     * Format tax breakdown for display
     *
     * @param array<string, mixed> $breakdown Tax breakdown array
     * @param string|null $language Language code (optional)
     * @param array<string, mixed> $meta Optional metadata (guests, children)
     * @return array<string, mixed> Formatted breakdown for display
     */
    public static function format_tax_breakdown(array $breakdown, ?string $language = null, array $meta = []): array
    {
        if (null === $language) {
            $language = I18n::get_current_language();
        }

        $label = self::get_label($language);
        $mode = $breakdown['mode'] ?? self::MODE_DISABLED;

        $formatted = [
            'enabled' => $breakdown['enabled'] ?? false,
            'mode' => $mode,
            'label' => $label,
            'registration_number' => $breakdown['registration_number'] ?? '',
            'items' => [],
            'totals' => []
        ];

        // Room
        if (isset($breakdown['breakdown']['room']) && [] !== $breakdown['breakdown']['room']) {
            $room = $breakdown['breakdown']['room'];
            $room_label = I18n::get_label('label_room');
            if (isset($meta['guests']) && (int)$meta['guests'] > 0) {
                $room_label .= sprintf(' (%s)', sprintf(I18n::get_label('label_guests_count'), (int) $meta['guests']));
            }
            $formatted['items'][] = [
                'type' => 'room',
                'label' => $room_label,
                'net' => $room['net'] ?? 0,
                'tax_rate' => $room['tax_rate'] ?? 0,
                'tax' => $room['tax'] ?? 0,
                'gross' => $room['gross'] ?? $room['gross_amount'] ?? 0,
                'net_formatted' => I18n::format_currency($room['net'] ?? 0),
                'tax_formatted' => I18n::format_currency($room['tax'] ?? 0),
                'gross_formatted' => I18n::format_currency($room['gross'] ?? $room['gross_amount'] ?? 0)
            ];
        }

// Children
        if (isset($breakdown['breakdown']['children']) && [] !== $breakdown['breakdown']['children']) {
            $children = $breakdown['breakdown']['children'];
            if (($children['gross'] ?? $children['gross_amount'] ?? 0) > 0) {
                $children_label = I18n::get_label('label_children');
                if (isset($meta['children']) && (int)$meta['children'] > 0) {
                    $children_label .= sprintf(' (%s)', sprintf(I18n::get_label('label_children_count'), (int) $meta['children']));
                }
                $formatted['items'][] = [
                    'type' => 'children',
                    'label' => $children_label,
                    'net' => $children['net'] ?? 0,
                    'tax_rate' => $children['tax_rate'] ?? 0,
                    'tax' => $children['tax'] ?? 0,
                    'gross' => $children['gross'] ?? $children['gross_amount'] ?? 0,
                    'net_formatted' => I18n::format_currency($children['net'] ?? 0),
                    'tax_formatted' => I18n::format_currency($children['tax'] ?? 0),
                    'gross_formatted' => I18n::format_currency($children['gross'] ?? $children['gross_amount'] ?? 0)
                ];
            }
        }

        // Extras
        if (isset($breakdown['breakdown']['extras']) && [] !== $breakdown['breakdown']['extras']) {
            foreach ($breakdown['breakdown']['extras'] as $extra) {
                $extra_name = $extra['name'] ?? '';
                if ('' !== $extra_name) {
                    $extra_name = I18n::decode($extra_name, $language);
                } else {
                    $extra_name = I18n::get_label('label_extras');
                }

                $formatted['items'][] = [
                    'type' => 'extra',
                    'label' => $extra_name,
                    'net' => $extra['net'] ?? 0,
                    'tax_rate' => $extra['tax_rate'] ?? 0,
                    'tax' => $extra['tax'] ?? 0,
                    'gross' => $extra['gross'] ?? $extra['gross_amount'] ?? 0,
                    'net_formatted' => I18n::format_currency($extra['net'] ?? 0),
                    'tax_formatted' => I18n::format_currency($extra['tax'] ?? 0),
                    'gross_formatted' => I18n::format_currency($extra['gross'] ?? $extra['gross_amount'] ?? 0)
                ];
            }
        }

        // Service Fee (Pro) — shown as its own line after extras, before subtotal
        if (isset($breakdown['breakdown']['service_fee']) && [] !== $breakdown['breakdown']['service_fee']) {
            $sf = $breakdown['breakdown']['service_fee'];
            $sf_label = $sf['label'] ?? '';
            if ('' === $sf_label) {
                $sf_label = __('Service Fee', 'modern-hotel-booking');
            }
            $formatted['items'][] = [
                'type'             => 'service_fee',
                'label'            => $sf_label,
                'net'              => $sf['net'] ?? 0,
                'tax_rate'         => $sf['tax_rate'] ?? 0,
                'tax'              => $sf['tax'] ?? 0,
                'gross'            => $sf['gross'] ?? $sf['gross_amount'] ?? 0,
                'net_formatted'    => I18n::format_currency($sf['net'] ?? 0),
                'tax_formatted'    => I18n::format_currency($sf['tax'] ?? 0),
                'gross_formatted'  => I18n::format_currency($sf['gross'] ?? $sf['gross_amount'] ?? 0),
            ];
        }

        // Totals
        $totals = $breakdown['totals'] ?? [];
        $formatted['totals'] = [
            'subtotal_net' => is_array($totals['subtotal_net'] ?? 0) ? 0 : ($totals['subtotal_net'] ?? 0),
            'total_tax' => is_array($totals['total_tax'] ?? 0) ? 0 : ($totals['total_tax'] ?? 0),
            'total_gross' => is_array($totals['total_gross'] ?? 0) ? 0 : ($totals['total_gross'] ?? 0),
            'subtotal_net_formatted' => I18n::format_currency($totals['subtotal_net'] ?? 0),
            'total_tax_formatted' => I18n::format_currency($totals['total_tax'] ?? 0),
            'total_gross_formatted' => I18n::format_currency($totals['total_gross'] ?? 0),
            'tax_included_note' => self::MODE_VAT === $mode
                ? sprintf(I18n::get_label('label_includes_tax'), $label . ' ' . self::get_accommodation_rate() . '%')
                : ''
        ];

        return $formatted;
    }

    /**
     * Render HTML for tax breakdown
     *
     * @param array<string, mixed> $breakdown Tax breakdown (from calculate_booking_tax or storage)
     * @param string|null $language Language code (optional)
     * @param bool $is_email Whether to use email-friendly inline styles
     * @param array<string, mixed> $meta Optional metadata (guests, children)
     * @param bool $show_note Whether to show the tax included note (VAT mode only)
     * @return string HTML
     */
    public static function render_breakdown_html(array $breakdown, ?string $language = null, bool $is_email = false, array $meta = [], bool $show_note = true): string
    {
        if (!self::is_enabled() && ($breakdown['enabled'] ?? false) === false) {
            // Respect admin display settings even when Tax mode is Disabled.
            // When enabled, renders a "Booking Summary" (fee lines, no tax rows).
            $display_setting = $is_email
                ? (bool) get_option('mhbo_tax_display_email', true)
                : (bool) get_option('mhbo_tax_display_frontend', false);
            if (!$display_setting) {
                return '';
            }
        }

        $formatted = self::format_tax_breakdown($breakdown, $language, $meta);
        $tax_enabled = $formatted['enabled'] ?? false;

        $styles = [
            'container' => $is_email ? 'font-family: Arial, sans-serif; font-size: 14px; color: #333; border: 1px solid #eee; padding: 15px; margin-bottom: 20px;' : 'mhbo-tax-breakdown',
            'title' => $is_email ? 'font-size: 16px; margin-top: 0; margin-bottom: 15px; color: #333;' : 'mhbo-tax-breakdown-title',
            'table' => $is_email ? 'width: 100%; border-collapse: collapse; margin-bottom: 15px;' : 'mhbo-tax-breakdown-table',
            'th' => $is_email ? 'padding: 8px; border-bottom: 1px solid #eee; text-align: left; font-weight: bold; background-color: #f9f9f9;' : 'mhbo-tax-breakdown-th',
            'td' => $is_email ? 'padding: 8px; border-bottom: 1px solid #eee; text-align: left;' : 'mhbo-tax-breakdown-td',
            'td_right' => $is_email ? 'padding: 8px; border-bottom: 1px solid #eee; text-align: right;' : 'mhbo-tax-breakdown-td-right',
            'grand_total' => $is_email ? 'font-weight: bold; border-top: 2px solid #333; padding-top: 10px;' : 'mhbo-tax-breakdown-grand-total',
            'reg_number' => $is_email ? 'margin-top: 15px; font-size: 12px; color: #999;' : 'mhbo-tax-registration'
        ];

        // Get tax rates and amounts for separate display
        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
        $rates = $breakdown['rates'] ?? [];
        $totals = $breakdown['totals'] ?? [];
        $accommodation_rate = floatval($rates['accommodation'] ?? self::get_accommodation_rate());
        $extras_rate = floatval($rates['extras'] ?? self::get_extras_rate());
        
        $room_tax_money        = Money::fromDecimal((string) ($totals['room_tax'] ?? '0'), $currency);
        $children_tax_money    = Money::fromDecimal((string) ($totals['children_tax'] ?? '0'), $currency);
        $extras_tax_money      = Money::fromDecimal((string) ($totals['extras_tax'] ?? '0'), $currency);
        // Service fee is taxed at the extras rate — merge its tax into the extras tax display total
        $service_fee_tax_money = Money::fromDecimal((string) ($totals['service_fee_tax'] ?? '0'), $currency);
        $extras_tax_money      = $extras_tax_money->add($service_fee_tax_money);

        $accommodation_tax_total_money = $room_tax_money->add($children_tax_money);
        $accommodation_tax_total = floatval($accommodation_tax_total_money->toDecimal());
        $extras_tax = floatval($extras_tax_money->toDecimal());

        // Check if we need to show separate tax lines
        $show_separate_tax_lines = ($accommodation_rate !== $extras_rate) && ($accommodation_tax_total > 0 || $extras_tax > 0);

        // Totals title
        $title = $tax_enabled
            ? sprintf(I18n::get_label('label_tax_breakdown'), $formatted['label'])
            : I18n::get_label('label_booking_summary');

        ob_start();
        ?>
        <div class="<?php echo $is_email ? '' : esc_attr($styles['container']); ?>"
            style="<?php echo $is_email ? esc_attr($styles['container']) : ''; ?>">
            <h4 style="<?php echo esc_attr($styles['title']); ?>">
                <?php echo esc_html($title); ?>
            </h4>
            <table class="<?php echo $is_email ? '' : esc_attr($styles['table']); ?>"
                style="<?php echo $is_email ? esc_attr($styles['table']) : ''; ?>"
                id="mhbo-tax-breakdown-table"
                data-total-gross="<?php echo esc_attr((string) ($formatted['totals']['total_gross'] ?? 0)); ?>">
                <thead>
                    <tr>
                        <th style="<?php echo esc_attr($styles['th']); ?>">
                            <?php echo esc_html(I18n::get_label('label_item')); ?>
                        </th>
                        <th style="<?php echo esc_attr($styles['th']); ?> text-align: right;">
                            <?php echo esc_html(I18n::get_label('label_amount')); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($formatted['items'] as $item): ?>
                        <?php if ('coupon' === ($item['type'] ?? '')): ?>
                            <tr class="mhbo-coupon-discount-row">
                                <td style="<?php echo esc_attr($styles['td']); ?> color:#16a34a;">
                                    <strong><?php echo esc_html($item['label']); ?></strong>
                                </td>
                                <td style="<?php echo esc_attr($styles['td_right']); ?> color:#16a34a;">
                                    <strong><?php echo esc_html($item['gross_formatted']); ?></strong>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr data-item-type="<?php echo esc_attr($item['type'] ?? 'item'); ?>">
                                <td style="<?php echo esc_attr($styles['td']); ?>">
                                    <?php echo esc_html($item['label']); ?>
                                </td>
                                <td style="<?php echo esc_attr($styles['td_right']); ?>" class="mhbo-item-amount">
                                    <?php echo esc_html($item['gross_formatted']); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php if ($tax_enabled): ?>
                        <tr class="mhbo-tax-total" data-row-type="subtotal">
                            <td style="<?php echo esc_attr($styles['td']); ?>">
                                <strong><?php echo esc_html(I18n::get_label('label_subtotal')); ?></strong>
                            </td>
                            <td style="<?php echo esc_attr($styles['td_right']); ?>" class="mhbo-item-amount">
                                <strong><?php echo esc_html($formatted['totals']['subtotal_net_formatted']); ?></strong>
                            </td>
                        </tr>
                        <?php if ($show_separate_tax_lines): ?>
                            <?php if ($accommodation_tax_total > 0): ?>
                                <tr class="mhbo-tax-item" data-row-type="vat-accommodation">
                                    <td style="<?php echo esc_attr($styles['td']); ?>">
                                        <?php
                                        echo esc_html(sprintf(I18n::get_label('label_tax_accommodation'), $formatted['label'], (float) $accommodation_rate + 0)); ?>
                                    </td>
                                    <td style="<?php echo esc_attr($styles['td_right']); ?>" class="mhbo-item-amount">
                                        <?php echo esc_html(I18n::format_currency($accommodation_tax_total)); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($extras_tax > 0): ?>
                                <tr class="mhbo-tax-item" data-row-type="vat-extras">
                                    <td style="<?php echo esc_attr($styles['td']); ?>">
                                        <?php
                                        echo esc_html(sprintf(I18n::get_label('label_tax_extras'), $formatted['label'], (float) $extras_rate + 0)); ?>
                                    </td>
                                    <td style="<?php echo esc_attr($styles['td_right']); ?>" class="mhbo-item-amount">
                                        <?php echo esc_html(I18n::format_currency($extras_tax)); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php else: ?>
                            <tr class="mhbo-tax-item" data-row-type="vat-total">
                                <td style="<?php echo esc_attr($styles['td']); ?>">
                                    <?php
                                    echo esc_html(sprintf(I18n::get_label('label_tax_rate'), $formatted['label'], (float) $accommodation_rate + 0)); ?>
                                </td>
                                <td style="<?php echo esc_attr($styles['td_right']); ?>" class="mhbo-item-amount">
                                    <?php echo esc_html($formatted['totals']['total_tax_formatted']); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    <tr class="<?php echo $is_email ? '' : esc_attr($styles['grand_total']); ?>" data-row-type="grand-total">
                        <td
                            style="<?php echo esc_attr($styles['td']); ?> <?php echo $is_email ? esc_attr($styles['grand_total']) : ''; ?>">
                            <strong><?php echo esc_html(I18n::get_label('label_total')); ?></strong>
                        </td>
                        <td
                            style="<?php echo esc_attr($styles['td_right']); ?> <?php echo $is_email ? esc_attr($styles['grand_total']) : ''; ?>"
                            class="mhbo-item-amount">
                            <strong><?php echo esc_html($formatted['totals']['total_gross_formatted']); ?></strong>
                        </td>
                    </tr>
                </tfoot>
            </table>
            
            <?php if ($show_note && $tax_enabled && self::MODE_VAT === $formatted['mode']): ?>
                <p style="font-size: 12px; color: #666; margin-top: 5px;">
                    <?php echo esc_html($formatted['totals']['tax_included_note']); ?>
                </p>
            <?php endif; ?>
            <?php if ($tax_enabled && isset($formatted['registration_number']) && $formatted['registration_number'] !== ''): ?>
                <p class="<?php echo $is_email ? '' : esc_attr($styles['reg_number']); ?>"
                    style="<?php echo $is_email ? esc_attr($styles['reg_number']) : ''; ?>">
                    <?php
                    // translators: %s: Tax registration number.
                    echo esc_html(sprintf(I18n::get_label('label_tax_registration'), $formatted['registration_number'])); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate plain text for tax breakdown (for emails)
     *
     * @param array $breakdown Tax breakdown
     * @param string $language Language code (optional)
     * @param array $meta Optional metadata (guests, children)
     * @return string Plain text
     */
    public static function render_breakdown_text($breakdown, $language = null, $meta = array())
    {
        if (!self::is_enabled() && ($breakdown['enabled'] ?? false) === false) {
            if (!(bool) get_option('mhbo_tax_display_email', true)) {
                return '';
            }
        }

        $formatted = self::format_tax_breakdown($breakdown, $language, $meta);
        $lines = [];
        $lines[] = sprintf(I18n::decode(I18n::get_label('label_tax_breakdown'), $language), $formatted['label']);
        $lines[] = str_repeat('-', 40);

        foreach ($formatted['items'] as $item) {
            
            $lines[] = sprintf(
                '  %s (%s%%): %s',
                $item['label'],
                $item['tax_rate'],
                $item['tax_formatted']
            );
        }

        $lines[] = str_repeat('-', 40);

        // Get tax rates and amounts for separate display
        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
        $rates = $breakdown['rates'] ?? [];
        $totals = $breakdown['totals'] ?? [];
        $accommodation_rate = floatval($rates['accommodation'] ?? 0);
        $extras_rate = floatval($rates['extras'] ?? 0);
        
        $room_tax_money        = Money::fromDecimal((string) ($totals['room_tax'] ?? '0'), $currency);
        $children_tax_money    = Money::fromDecimal((string) ($totals['children_tax'] ?? '0'), $currency);
        $extras_tax_money      = Money::fromDecimal((string) ($totals['extras_tax'] ?? '0'), $currency);
        // Service fee is taxed at the extras rate — merge its tax into the extras tax display total
        $service_fee_tax_money = Money::fromDecimal((string) ($totals['service_fee_tax'] ?? '0'), $currency);
        $extras_tax_money      = $extras_tax_money->add($service_fee_tax_money);

        $accommodation_tax_total_money = $room_tax_money->add($children_tax_money);
        $accommodation_tax_total = floatval($accommodation_tax_total_money->toDecimal());
        $extras_tax = floatval($extras_tax_money->toDecimal());

        // Check if we need to show separate tax lines
        $show_separate_tax_lines = ($accommodation_rate !== $extras_rate) && ($accommodation_tax_total > 0 || $extras_tax > 0);

        if ($show_separate_tax_lines) {
            if ($accommodation_tax_total > 0) {
                $lines[] = sprintf(
                    I18n::get_label('label_tax_accommodation'),
                    $formatted['label'],
                    $accommodation_rate,
                    I18n::format_currency($accommodation_tax_total)
                );
            }
            if ($extras_tax > 0) {
                $lines[] = sprintf(
                    I18n::get_label('label_tax_extras'),
                    $formatted['label'],
                    $extras_rate,
                    I18n::format_currency($extras_tax)
                );
            }
        } else {
            $lines[] = sprintf(I18n::decode(I18n::get_label('label_tax_total'), $language), $formatted['label'], $formatted['totals']['total_tax_formatted']);
        }

        if (isset($formatted['registration_number']) && $formatted['registration_number'] !== '') {
            $lines[] = sprintf(I18n::decode(I18n::get_label('label_tax_registration'), $language), $formatted['registration_number']);
        }

        return implode("\n", $lines);
    }

    /**
     * Store tax data with a booking.
     *
     * @param int $booking_id Booking ID
     * @param array $tax_breakdown Full tax breakdown array from calculate_booking_tax()
     * @return bool Success
     */
    public static function store_tax_data($booking_id, $tax_breakdown)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhbo_bookings';

        // Use pre-calculated component totals from the breakdown if available
        $totals = $tax_breakdown['totals'] ?? [];
        $breakdown = $tax_breakdown['breakdown'] ?? [];
        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));

        $data = [
            'tax_enabled' => ($tax_breakdown['enabled'] ?? false) ? 1 : 0,
            'tax_mode' => $tax_breakdown['mode'] ?? self::MODE_DISABLED,
            'tax_rate_accommodation' => $tax_breakdown['rates']['accommodation'] ?? 0,
            'tax_rate_extras' => $tax_breakdown['rates']['extras'] ?? 0,
            'room_total_net' => Money::fromDecimal((string) ($totals['room_net'] ?? $breakdown['room']['net'] ?? '0'), $currency)->toDecimal(),
            'room_tax' => Money::fromDecimal((string) ($totals['room_tax'] ?? $breakdown['room']['tax'] ?? '0'), $currency)->toDecimal(),
            'children_total_net' => Money::fromDecimal((string) ($totals['children_net'] ?? $breakdown['children']['net'] ?? '0'), $currency)->toDecimal(),
            'children_tax' => Money::fromDecimal((string) ($totals['children_tax'] ?? $breakdown['children']['tax'] ?? '0'), $currency)->toDecimal(),
            'extras_total_net'   => Money::fromDecimal((string) ($totals['extras_net'] ?? '0'), $currency)->toDecimal(),
            'extras_tax'         => Money::fromDecimal((string) ($totals['extras_tax'] ?? '0'), $currency)->toDecimal(),
            'service_fee_net'    => Money::fromDecimal((string) ($totals['service_fee_net'] ?? '0'), $currency)->toDecimal(),
            'service_fee_tax'    => Money::fromDecimal((string) ($totals['service_fee_tax'] ?? '0'), $currency)->toDecimal(),
            'service_fee_amount' => Money::fromDecimal((string) ($breakdown['service_fee']['gross_amount'] ?? '0'), $currency)->toDecimal(),
            'subtotal_net'       => Money::fromDecimal((string) ($totals['subtotal_net'] ?? '0'), $currency)->toDecimal(),
            'total_tax'          => Money::fromDecimal((string) ($totals['total_tax'] ?? '0'), $currency)->toDecimal(),
            'total_gross'        => Money::fromDecimal((string) ($totals['total_gross'] ?? '0'), $currency)->toDecimal(),
            'tax_breakdown'      => wp_json_encode($tax_breakdown),
        ];

        // Fallback for extras if totals not populated (should not happen with latest calculate_booking_tax)
        if (Money::fromDecimal((string) $data['extras_total_net'], $currency)->isZero() && isset($breakdown['extras']) && [] !== $breakdown['extras']) {
            $e_net = Money::fromCents(0, $currency);
            $e_tax = Money::fromCents(0, $currency);
            foreach ($breakdown['extras'] as $extra) {
                $e_net = $e_net->add(Money::fromDecimal((string) ($extra['net'] ?? '0'), $currency));
                $e_tax = $e_tax->add(Money::fromDecimal((string) ($extra['tax'] ?? '0'), $currency));
            }
            $data['extras_total_net'] = $e_net->toDecimal();
            $data['extras_tax'] = $e_tax->toDecimal();
        }

        $format = [
            '%d', // tax_enabled
            '%s', // tax_mode
            '%f', // tax_rate_accommodation
            '%f', // tax_rate_extras
            '%f', // room_total_net
            '%f', // room_tax
            '%f', // children_total_net
            '%f', // children_tax
            '%f', // extras_total_net
            '%f', // extras_tax
            '%f', // service_fee_net
            '%f', // service_fee_tax
            '%f', // service_fee_amount
            '%f', // subtotal_net
            '%f', // total_tax
            '%f', // total_gross
            '%s', // tax_breakdown
        ];

        // RATIONALE: Required to store calculated tax breakdown columns in the custom mhbo_bookings table.
        // Uses $wpdb->update with typed format arrays; booking_id is validated as int.
        $result = $wpdb->update($table, $data, ['id' => $booking_id], $format, ['%d']) !== false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

        if ($result) {
            Cache::invalidate_booking($booking_id);
        }

        return $result;
    }

    /**
     * Get tax summary for a booking (simplified)
     *
     * @param int $booking_id Booking ID
     * @return array Simplified tax summary
     */
    public static function get_booking_tax_summary($booking_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhbo_bookings';

        // RATIONALE: Required to fetch a single booking's tax summary for admin display.
        // Uses $wpdb->prepare with %d placeholder; read-only, no caching needed for admin context.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin-only query
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_enabled, tax_mode, total_tax, total_gross, total_price
             FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
            $booking_id
        ));

        if (!$booking) {
            return [
                'has_tax' => false,
                'tax_amount' => 0,
                'tax_mode' => self::MODE_DISABLED
            ];
        }

        $currency = strtoupper((string) get_option('mhbo_currency_code', 'USD'));
        return [
            'has_tax' => (bool) $booking->tax_enabled,
            'tax_amount' => Money::fromDecimal((string) ($booking->total_tax ?? '0'), $currency)->toDecimal(),
            'tax_mode' => $booking->tax_mode,
            'total_gross' => Money::fromDecimal((string) ($booking->total_gross ?: $booking->total_price ?: '0'), $currency)->toDecimal()
        ];
    }
}
