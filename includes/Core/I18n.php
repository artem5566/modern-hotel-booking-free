<?php
declare(strict_types=1);

/**
 * I18n — Multilingual Abstraction Layer
 *
 * Supports qTranslate-X, WPML, Polylang, and vanilla WordPress.
 * Internal storage format: [:en]English[:ro]Romanian[:de]German[:]
 *
 * IMPORTANT — WordPress i18n compliance:
 * All gettext calls (__(), esc_html__(), etc.) MUST use literal string
 * arguments for both the text and the text domain. Never pass variables
 * or constants to these functions — the translation parser reads code
 * statically and cannot resolve runtime values.
 *
 * Correct:   _x( 'Hello', 'greeting', 'modern-hotel-booking' )
 * Incorrect: __ ( ' . variable . ' )
 *
 * If you need to include dynamic values, use printf/sprintf with placeholders:
 *   echo esc_html( sprintf( __( 'Hello %s', 'text-domain' ), $name ) );
 *
 * @package MHBO\Core
 * @since   2.0.0
 */

namespace MHBO\Core;
if (!defined('ABSPATH')) {
    exit;
}

/**
     * I18n class handles all localization and translation logic.
     */
    class I18n
    {
    /**
     * Language override for REST/AJAX contexts where multilingual plugins
     * cannot detect the visitor's language (e.g. Polylang on REST requests).
     *
     * @var string|null 2-letter language code, or null to use auto-detection.
     */
    private static ?string $language_override = null;

    /**
     * Force all subsequent get_current_language() calls to return the given code.
     *
     * Intended for REST/AJAX endpoints that receive a `lang` parameter from
     * the frontend.  Must be called **before** any I18n::get_label() output.
     *
     * @param string $lang 2-letter ISO 639-1 language code.
     */
    public static function set_language_override(string $lang): void
    {
        self::$language_override = sanitize_key(substr($lang, 0, 5));
    }

    /**
     * Remove any active language override (restore auto-detection).
     */
    public static function clear_language_override(): void
    {
        self::$language_override = null;
    }

    /**
     * Initialize translation filters.
     *
     * @return void
     */
    public static function init(): void
    {
        // WordPress 4.6+ auto-loads translations for plugins hosted on WP.org.
        add_filter('gettext_modern-hotel-booking', array(self::class, 'filter_gettext'), 10, 3);

        // 1. Dynamic REST API override passed from JS (e.g. Polylang/WPML modal requests).
        // Hooked at init to switch the global locale before REST callbacks fire.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $mhbo_locale = isset($_REQUEST['mhbo_locale']) ? sanitize_text_field(wp_unslash($_REQUEST['mhbo_locale'])) : '';
        if ('' !== $mhbo_locale && preg_match('/^[a-zA-Z_]{2,10}$/', $mhbo_locale) && self::is_frontend_request()) {
            $lang_slug = substr($mhbo_locale, 0, 2);
            self::set_language_override($lang_slug);

            // Force Polylang to switch its internal current language for registered string translation.
            if (function_exists('PLL')) {
                /** @phpstan-ignore argument.type */
                $pll = call_user_func('PLL');
                if (is_object($pll) && isset($pll->model) && method_exists($pll->model, 'get_language')) {
                    $desired_lang = $pll->model->get_language($lang_slug);
                    if (false !== $desired_lang) {
                        $pll->curlang = $desired_lang;
                    }
                }
            }

            if (function_exists('switch_to_locale')) {
                switch_to_locale($mhbo_locale);
            }

            // Force WPML to switch its internal language.
            // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
            do_action('wpml_switch_language', $lang_slug);
            
            // switch_to_locale does not automatically unload/reload plugin textdomains.
            // We must explicitly do it to purge any JIT-loaded English translations.
            unload_textdomain('modern-hotel-booking');
            $mofile = MHBO_PLUGIN_DIR . 'languages/modern-hotel-booking-' . $mhbo_locale . '.mo';
            load_textdomain('modern-hotel-booking', $mofile);
        }

        // 2. Allow admins to force a specific locale on the public frontend via settings.
        add_filter('plugin_locale', array(self::class, 'maybe_force_site_locale'), 10, 2);

        // If a forced locale is set via settings, explicitly load the text domain
        if (self::is_frontend_request()) {
            $forced = (string) get_option('mhbo_force_frontend_locale', '');
            if ('' !== $forced && 'en_US' !== $forced) {
                // Clear any JIT translations
                unload_textdomain('modern-hotel-booking');
                // Explicitly load the bundled language file
                $mofile = MHBO_PLUGIN_DIR . 'languages/modern-hotel-booking-' . $forced . '.mo';
                load_textdomain('modern-hotel-booking', $mofile);
            }
        }
    }

    /**
     * Check if we are currently serving a public frontend request.
     *
     * @return bool
     */
    public static function is_frontend_request(): bool
    {
        if (wp_doing_ajax()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, not processing data.
            $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
            $frontend_actions = array(
                'mhbo_process_booking',
                'mhbo_create_payment_intent',
                'mhbo_create_paypal_order',
                'mhbo_capture_paypal_order',
                'mhbo_validate_coupon'
            );
            if (in_array($action, $frontend_actions, true)) {
                return true;
            }
            
            $referer = wp_get_referer();
            return $referer && false === strpos((string) $referer, '/wp-admin/');
        }
        
        if (isset($_SERVER['REQUEST_URI'])) {
            $route = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            if (strpos($route, '/wp-json/mhbo/') !== false || strpos($route, '?rest_route=/mhbo/') !== false) {
                return true;
            }
        }

        return !is_admin();
    }

    /**
     * Filter plugin translations to prevent blank strings.
     *
     * If a translation is found to be empty or just whitespace in the .mo file,
     * WP returns it as is. We force it to fallback to the original English string.
     *
     * @param string $translated
     * @param string $text
     * @param string $domain
     * @return string
     */
    public static function filter_gettext(string $translated, string $text, string $domain): string
    {
        if ('' === trim($translated)) {
            return $text;
        }

        // If a forced frontend locale is active and we're on the public frontend,
        // ensure the original (English) string is returned when en_US is forced.
        // This guards against the .mo file being loaded before our plugin_locale filter.
        if (self::is_frontend_request()) {
            // Cache the option value per-request for performance (gettext fires hundreds of times).
            static $forced_locale = null;
            if (null === $forced_locale) {
                $forced_locale = (string) get_option('mhbo_force_frontend_locale', '');
            }
            if ('en_US' === $forced_locale) {
                return $text;
            }
        }

        return $translated;
    }

    /**
     * Force the plugin locale on the public frontend when a specific language is configured.
     *
     * Hooked to the `plugin_locale` filter. Only applies to our text domain and only on
     * non-admin requests. When left blank (default), existing behaviour is preserved.
     *
     * @param string $locale The determined locale.
     * @param string $domain The text domain.
     * @return string
     */
    public static function maybe_force_site_locale(string $locale, string $domain): string
    {
        if ('modern-hotel-booking' !== $domain) {
            return $locale;
        }

        // 1. Dynamic REST API override passed from JS (e.g. Polylang/WPML modal requests)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $mhbo_locale = isset($_REQUEST['mhbo_locale']) ? sanitize_text_field(wp_unslash($_REQUEST['mhbo_locale'])) : '';
        if ('' !== $mhbo_locale && self::is_frontend_request()) {
            return $mhbo_locale;
        }

        // 2. Static admin option override
        $forced = (string) get_option('mhbo_force_frontend_locale', '');
        if ('' === $forced) {
            return $locale; // Disabled — preserve default WP behaviour.
        }

        // Only override on the public frontend (not wp-admin, not AJAX, not REST).
        if (!self::is_frontend_request()) {
            return $locale;
        }

        return $forced;
    }

    /**
     * Get the list of locales supported by the plugin's translation files.
     *
     * Returns an associative array of locale code => native name.
     * Used by the Settings dropdown for the "Force Frontend Language" option.
     *
     * @return array<string, string>
     */
    public static function get_supported_locales(): array
    {
        return array(
            'en_US' => 'English',
            'cs_CZ' => 'Čeština',
            'da_DK' => 'Dansk',
            'de_DE' => 'Deutsch',
            'el'    => 'Ελληνικά',
            'es_ES' => 'Español',
            'fr_FR' => 'Français',
            'hu_HU' => 'Magyar',
            'it_IT' => 'Italiano',
            'nb_NO' => 'Norsk Bokmål',
            'nl_NL' => 'Nederlands',
            'pl_PL' => 'Polski',
            'pt_BR' => 'Português (Brasil)',
            'ro_RO' => 'Română',
            'ru_RU' => 'Русский',
            'sv_SE' => 'Svenska',
        );
    }

    /**
     * Detect which multilingual plugin is active.
     *
     * @return string 'qtranslate'|'wpml'|'polylang'|'none'
     */
    public static function detect_plugin(): string
    {
        if (defined('QTX_VERSION') || function_exists('qtranxf_getLanguage')) {
            return 'qtranslate';
        }
        // Check Polylang BEFORE WPML — Polylang Pro ships a WPML compatibility
        // layer that defines ICL_SITEPRESS_VERSION, which would cause
        // misdetection if the WPML check came first.
        if (function_exists('pll_current_language') || defined('POLYLANG_VERSION')) {
            return 'polylang';
        }
        if (defined('ICL_SITEPRESS_VERSION') || function_exists('icl_get_languages')) {
            return 'wpml';
        }
        if (defined('BOGO_VERSION') || function_exists('bogo_get_post_translations')) {
            return 'bogo';
        }
        return 'none';
    }

    /**
     * Helper to get the 2-letter locale code.
     *
     * @return string
     */
    private static function locale_code(): string
    {
        if (self::is_frontend_request()) {
            $forced = (string) get_option('mhbo_force_frontend_locale', '');
            if ('' !== $forced) {
                return substr($forced, 0, 2);
            }
        }
        return substr(get_locale(), 0, 2);
    }

    /**
     * Get current front-end/admin language code (2-letter).
     *
     * @return string
     */
    public static function get_current_language(): string
    {
        // REST/AJAX language override (set by endpoints that receive a `lang` param).
        if (null !== self::$language_override && '' !== self::$language_override) {
            return self::$language_override;
        }

        // Handle admin side language selection via URL param (e.g., ?lang=ro).
        // Uses filter_input() to avoid nonce-verification warnings — this is a
        // read-only display parameter that does not change state.
        $lang_param = filter_input(INPUT_GET, 'lang', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (is_admin() && '' !== (string) $lang_param) {
            return sanitize_key($lang_param);
        }

        switch (self::detect_plugin()) {
            case 'qtranslate':
                return function_exists('qtranxf_getLanguage') ? call_user_func('qtranxf_getLanguage') : self::locale_code();
            case 'wpml':
                $wpml_lang = apply_filters('wpml_current_language', self::locale_code()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
                return is_string($wpml_lang) && '' !== $wpml_lang ? $wpml_lang : self::locale_code();
            case 'polylang':
                $lang = function_exists('pll_current_language') ? call_user_func('pll_current_language') : self::locale_code();
                return $lang ? $lang : self::locale_code();
            case 'bogo':
                return substr(get_locale(), 0, 2);
            default:
                return self::locale_code();
        }
    }

    /**
     * Get default site language code.
     *
     * @return string
     */
    public static function get_default_language(): string
    {
        switch (self::detect_plugin()) {
            case 'qtranslate':
                $qtranslate_config = self::get_q_config();
                return isset($qtranslate_config['default_language']) ? $qtranslate_config['default_language'] : 'en';
            case 'wpml':
                return apply_filters('wpml_default_language', 'en'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
            case 'polylang':
                $pll_default = function_exists('pll_default_language') ? call_user_func('pll_default_language') : false;
                return is_string($pll_default) && '' !== $pll_default ? $pll_default : 'en';
            case 'bogo':
                $default_locale = get_option('WPLANG', 'en_US');
                return substr((string) ($default_locale ?: 'en_US'), 0, 2);
            default:
                return 'en';
        }
    }

    /**
     * Get all available languages as an array of 2-letter codes.
     *
     * @return string[]
     */
    public static function get_available_languages(): array
    {
        switch (self::detect_plugin()) {
            case 'qtranslate':
                $qtranslate_config = self::get_q_config();
                return isset($qtranslate_config['enabled_languages']) ? $qtranslate_config['enabled_languages'] : [self::locale_code()];

            case 'wpml':
                $langs = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
                return is_array($langs) ? array_keys($langs) : [self::locale_code()];

            case 'polylang':
                if (function_exists('pll_languages_list')) {
                    $list = call_user_func('pll_languages_list', ['fields' => 'slug']);
                    return [] !== $list ? $list : [self::locale_code()];
                }
                return [self::locale_code()];

            default:
                $langs = array_unique(['en', self::locale_code()]);
                return (array) apply_filters('mhbo_i18n_get_available_languages', array_values($langs));
        }
    }

    /**
     * Get native language name for a code.
     *
     * @param string $code 2-letter code.
     * @return string
     */
    public static function get_language_name(string $code): string
    {
        $names = [
            'en' => 'English',
            'ro' => 'Română',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'it' => 'Italiano',
            'es' => 'Español',
            'bg' => 'Български',
            'pl' => 'Polski',
            'hu' => 'Magyar',
            'cs' => 'Čeština',
            'sk' => 'Slovenčina',
            'da' => 'Dansk',
            'sv' => 'Svenska',
            'fi' => 'Suomi',
            'el' => 'Ελληνικά'
        ];
        return isset($names[$code]) ? $names[$code] : strtoupper($code);
    }

    /**
     * Get qTranslate-XT configuration (third-party global).
     *
     * @return array<string, mixed>
     */
    private static function get_q_config(): array
    {
        // Use $GLOBALS to avoid WP repo scanner false-positives for unprefixed globals (this belongs to qTranslate)
        return isset($GLOBALS['q_config']) ? $GLOBALS['q_config'] : [];
    }

    /**
     * Decode a multilingual string.
     * Format: [:en]Hello[:ro]Salut[:]
     *
     * @param mixed       $text The value to decode.
     * @param string|null $lang Optional language code.
     * @param bool        $fallback Whether to fallback to other languages if requested is missing.
     * @return string|null
     */
    public static function decode(mixed $text, ?string $lang = null, bool $fallback = true): ?string
    {
        if ($text === '' || !is_string($text)) {
            return is_scalar($text) ? (string) $text : null;
        }

        if (!$lang) {
            $lang = self::get_current_language();
        }

        // qTranslate style parsing - leverage native function if available (only if fallback is enabled)
        if ($fallback && function_exists('qtranxf_use')) {
            return \call_user_func('qtranxf_use', $lang, $text);
        }

        // Detect if this is a plain string (no multilingual tags)
        $is_plain = false === strpos($text, '[:');

        // Handle plain strings on multilingual sites
        if ($is_plain && 'none' !== self::detect_plugin() && !$fallback) {
            return trim($text);
        }

        // Manual parsing fallback for single-language strings or when fallback is allowed
        if ($is_plain) {
            return $text;
        }

        // qTranslate style parsing
        $blocks = preg_split('/\[:([a-z]{2}(?:_[a-z]{2})?)\]/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (count($blocks) < 3) {
            return $text;
        }

        // Build a map of languages to content
        $map = [];
        for ($i = 1; $i < count($blocks); $i += 2) {
            if (isset($blocks[$i + 1])) {
                $lang_key = strtolower($blocks[$i]);
                $content = $blocks[$i + 1];

                if (substr($content, -3) === '[:]') {
                    $content = substr($content, 0, -3);
                }

                $map[$lang_key] = $content;
            }
        }

        // Normalize requested language
        $lang = strtolower($lang);
        $lang_short = substr($lang, 0, 2);

        // 1. Try exact match
        if (isset($map[$lang]) && '' !== $map[$lang]) {
            return $map[$lang];
        }

        // 2. Try short code match (e.g. 'en' for 'en_US')
        if (isset($map[$lang_short]) && '' !== $map[$lang_short]) {
            return $map[$lang_short];
        }

        // 3. Fallback logic (WP 7.0+ Future-Proofing)
        if ($fallback) {
            $default = strtolower(self::get_default_language());
            $default_short = substr($default, 0, 2);

            // Try site default (full then short)
            if (isset($map[$default])) {
                return apply_filters('mhbo_i18n_decode', $map[$default], $text, $lang);
            }
            if (isset($map[$default_short])) {
                return apply_filters('mhbo_i18n_decode', $map[$default_short], $text, $lang);
            }

            // Fallback to English (Global Standard)
            if (isset($map['en'])) {
                return apply_filters('mhbo_i18n_decode', $map['en'], $text, $lang);
            }

            // Final fallback to any first available non-empty translation
            foreach ($map as $val) {
                if ('' !== $val) {
                    return apply_filters('mhbo_i18n_decode', $val, $text, $lang);
                }
            }
        }

        // 4. Last resort: strip qTranslate tags and return result
        $result = preg_replace('/\[:([a-z]{2}(?:_[a-z]{2})?)\]/i', '', $text);
        $result = str_replace('[:]', '', $result);

        return apply_filters('mhbo_i18n_decode', trim($result) ?: $text, $text, $lang);
    }

    /**
     * Encode an array of [lang => text] into a multilingual string.
     *
     * @param mixed $values The values to encode (typically an associative array).
     * @return string The encoded multilingual string.
     */
    public static function encode(mixed $values): string
    {
        if (!is_array($values)) {
            return (string) $values;
        }
        $out = '';
        foreach ($values as $lang => $text) {
            if ('' !== $text) {
                $out .= "[:{$lang}]{$text}";
            }
        }
        if ('' !== $out) {
            $out .= '[:]';
        }
        return $out;
    }

    /**
     * Decode an already-translated string for multilingual (qTranslate-style) support.
     *
     * -------------------------------------------------------------------------
     * IMPORTANT — Do NOT use this method for gettext string extraction.
     * Always call WordPress __() / esc_html__() etc. with LITERAL strings
     * and a LITERAL text domain FIRST, then pass the result here when you
     * need multilingual (qTranslate-style [:xx]) decoding.
     *
     * Correct usage:
     *   $translated = __( 'Search Rooms', 'modern-hotel-booking' );
     *   $decoded    = I18n::translate_and_decode( $translated, 'ro' );
     *
     * Incorrect (blocks translation parser):
     *   $decoded = I18n::translate_and_decode( $variable );
     * -------------------------------------------------------------------------
     *
     * @param string      $translated Already-translated text (from __() or similar).
     * @param string|null $language   Optional language code for multilingual strings.
     * @return string Decoded text.
     */
    public static function translate_and_decode(string $translated, ?string $language = null): string
    {
        // If translation is empty, return as-is
        if ('' === $translated) {
            return $translated;
        }

        // If the translated string contains multilingual format, decode it
        if (false !== strpos($translated, '[:')) {
            $decoded = self::decode($translated, $language);
            if (null !== $decoded && '' !== $decoded) {
                return $decoded;
            }
        }

        return $translated;
    }

    /**
     * Format currency based on site settings.
     *
     * @param float|int|string|Money $amount The numeric amount to format.
     * @return string The formatted currency string.
     */
    public static function format_currency(mixed $amount, ?int $decimals_override = null): string
    {
        $decimal_separator = (string) apply_filters('mhbo_currency_decimal_separator', '.');
        $thousand_separator = (string) apply_filters('mhbo_currency_thousand_separator', ',');
        $symbol = (string) get_option('mhbo_currency_symbol', '$');
        $position = (string) get_option('mhbo_currency_position', 'before');

        // Handle arrays from legacy or malformed JSON serialization
        if (is_array($amount)) {
            if (isset($amount['amount_in_cents']) && isset($amount['currency'])) {
                $amount = Money::fromCents($amount['amount_in_cents'], $amount['currency']);
            } else {
                $amount = 0; // Default fallback for entirely invalid data
            }
        }

        if ($amount instanceof Money) {
            $decimals = $decimals_override ?? (int) apply_filters('mhbo_currency_decimals', $amount->getCurrencyDecimals());
            $formatted = number_format((float) $amount->toDecimal(), $decimals, $decimal_separator, $thousand_separator);
        } else {
            $decimals = $decimals_override ?? (int) apply_filters('mhbo_currency_decimals', 2);
            $formatted = number_format((float) ($amount ?? 0), $decimals, $decimal_separator, $thousand_separator);
        }

        // Smart Space Logic: Add space if symbol is multi-character or alphanumeric
        $add_space = strlen($symbol) > 1 || preg_match('/^[a-zA-Z0-9]+$/', $symbol);
        $add_space = (bool) apply_filters('mhbo_currency_add_space', $add_space, $symbol);
        $space = $add_space ? ' ' : '';

        $result = ($position === 'before') ? $symbol . $space . $formatted : $formatted . $space . $symbol;

        return (string) apply_filters('mhbo_currency_format', $result, $amount, $symbol, $position);
    }

    /**
     * Format a date string based on WP settings.
     *
     * @param string $date_string The date string to format (Y-m-d).
     * @return string The localized date string.
     */
    public static function format_date(string $date_string): string
    {
        if ('' === $date_string) {
            return '';
        }
        return date_i18n(get_option('date_format'), strtotime($date_string));
    }

/**
     * Get a localized label for a payment method.
     *
     * @param string|null $method The payment method key.
     * @return string The localized label.
     */
    public static function get_payment_method_label(?string $method): string
    {
        if (null === $method || '' === $method) {
            $method = 'arrival';
        }
        return apply_filters('mhbo_payment_method_label', match ($method) {
            'stripe'  => __('Stripe', 'modern-hotel-booking'),
            'paypal'  => __('PayPal', 'modern-hotel-booking'),
            'arrival', 'onsite' => __('Pay on Arrival / Manual', 'modern-hotel-booking'),
            default   => ucfirst($method)
        }, $method);
    }

    /**
     * Get a localized label for front-end/admin use.
     *
     * @param string $key The label key.
     * @return string The localized label.
     */
    public static function get_label(string $key): string
    {
        // Check for database override first
        $override = get_option("mhbo_label_{$key}");

        $labels = self::get_all_default_labels();
        $default_val = isset($labels[$key]) ? $labels[$key] : $key;

        $value = (false !== $override && '' !== $override) ? $override : $default_val;

        // Check for translated string via WPML/Polylang
        $translated = self::get_translated_string("Label: {$key}", $value, 'MHBO Frontend Labels');

        // If translation is found and not empty, decode it (it might still be qTranslate format)
        if ('' !== $translated) {
            $decoded = self::decode($translated);
            if (null !== $decoded && '' !== $decoded) {
                return $decoded;
            }
        }

        // Fallback to English if the result is empty
        if (isset($labels[$key])) {
            return self::decode($labels[$key], 'en');
        }

        return $key;
    }

    /**
     * Shortcut for get_label().
     *
     * @param string $key
     * @return string
     */
    public static function __(string $key): string
    {
        return self::get_label($key);
    }

    /**
     * Echo localized label.
     *
     * @param string $key
     * @return void
     */
    public static function _e(string $key): void
    {
        echo wp_kses_post( self::get_label( $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional raw echo; labels may contain controlled HTML; sibling esc_html_e() handles plain-text contexts.
    }

    /**
     * Echo escaped localized label for HTML.
     *
     * @param string $key
     * @return void
     */
    public static function esc_html_e(string $key): void
    {
        echo esc_html(self::get_label($key));
    }

    /**
     * Echo escaped localized label for attributes.
     *
     * @param string $key
     * @return void
     */
    public static function esc_attr_e(string $key): void
    {
        echo esc_attr(self::get_label($key));
    }

    /**
     * Get all default labels in multilingual format.
     *
     * NOTE: Every __() call below uses a LITERAL string and the LITERAL
     * text domain 'modern-hotel-booking' so that the WordPress translation
     * parser can extract them. Never replace these with variables.
     *
     * @return array
     */
    public static function get_all_default_labels(): array
    {
        $labels = array(
            'btn_search_rooms' => __('Search Rooms', 'modern-hotel-booking'),
            'label_check_in' => __('Check-in', 'modern-hotel-booking'),
            'label_check_out' => __('Check-out', 'modern-hotel-booking'),
            /* translators: %s: check-in time (e.g., 14:00) */
            'label_check_in_from' => __('from %s', 'modern-hotel-booking'),
            /* translators: %s: check-out time (e.g., 11:00) */
            'label_check_out_by' => _x('by %s', 'checkout time', 'modern-hotel-booking'),
            'label_guests' => __('Guests', 'modern-hotel-booking'),
            'label_children' => __('Children', 'modern-hotel-booking'),
            'label_child_ages' => __('Child Ages', 'modern-hotel-booking'),
            /* translators: %d: child number */
            'label_child_n_age' => __('Child %d Age', 'modern-hotel-booking'),
            /* translators: %d: number of guests */
            'label_guests_count' => __('Guests x %d', 'modern-hotel-booking'),
            /* translators: %d: number of children */
            'label_children_count' => __('Children x %d', 'modern-hotel-booking'),
            /* translators: %d: number of nights */
            'label_nights_count' => __('%d Nights', 'modern-hotel-booking'),
            'label_nights_count_single' => __('1 Night', 'modern-hotel-booking'),
            'label_night' => _x('Night', 'noun', 'modern-hotel-booking'),
            'label_nights' => _x('Nights', 'noun', 'modern-hotel-booking'),
            'label_stay_details' => __('Stay Details', 'modern-hotel-booking'),
            'label_room_number' => __('Room Number', 'modern-hotel-booking'),
            /* translators: 1: check-in date, 2: check-out date */
            'label_available_rooms' => __('Available Rooms from %1$s to %2$s', 'modern-hotel-booking'),
            'label_no_rooms' => __('No rooms available for these dates.', 'modern-hotel-booking'),
            'label_checkout_only' => __('This date is restricted to check-outs only.', 'modern-hotel-booking'),
            'label_checkin_only' => __('This date is restricted to check-ins only.', 'modern-hotel-booking'),
            'label_per_night' => __('per night', 'modern-hotel-booking'),
            /* translators: %1$d: number of nights, %2$s: nightly rate */
            'label_total_nights' => __('%1$d nights: %2$s', 'modern-hotel-booking'),
            /* translators: %d: maximum number of guests */
            'label_max_guests' => __('Max guests: %d', 'modern-hotel-booking'),
            'btn_book_now' => __('Book Now', 'modern-hotel-booking'),
            'label_complete_booking' => __('Complete Your Booking', 'modern-hotel-booking'),
            'label_name' => __('Full Name', 'modern-hotel-booking'),
            'label_customer_name' => __('Customer Name', 'modern-hotel-booking'),
            'label_phone' => _x('Phone', 'customer detail', 'modern-hotel-booking'),
            'btn_confirm_booking' => __('Confirm Booking', 'modern-hotel-booking'),
            'btn_pay_confirm' => __('Pay & Confirm', 'modern-hotel-booking'),
            'msg_booking_confirmed' => __('Booking Confirmed!', 'modern-hotel-booking'),
            'msg_booking_confirmed_received' => __('Booking Pending', 'modern-hotel-booking'),
            'msg_confirmation_sent' => __('A confirmation email has been sent to you.', 'modern-hotel-booking'),
            /* translators: %s: customer email address */
            'msg_pending_sent_to' => __('A copy of this pending booking was sent to %s. We will contact you shortly.', 'modern-hotel-booking'),
            /* translators: %s: customer email address */
            'msg_confirmation_sent_to' => __('A copy of this booking confirmation was sent to %s. Thank you!', 'modern-hotel-booking'),
            'msg_booking_confirmed_email' => __('Booking Confirmed & Email Sent!', 'modern-hotel-booking'),
            'msg_booking_cancelled' => __('Booking Cancelled.', 'modern-hotel-booking'),
            'msg_booking_deleted' => __('Booking Deleted.', 'modern-hotel-booking'),
            'msg_manual_booking_added' => __('Manual Booking Added!', 'modern-hotel-booking'),
            'msg_confirm_remove_extra' => __('Are you sure you want to remove this extra?', 'modern-hotel-booking'),
            'msg_failed_save_booking'        => __('Failed to save booking. Please try again.', 'modern-hotel-booking'),
            'msg_failed_update_booking'      => __('Failed to update booking.', 'modern-hotel-booking'),
            'msg_insufficient_permissions'   => __('Insufficient permissions.', 'modern-hotel-booking'),
            'msg_api_saved'                  => __('Settings saved successfully.', 'modern-hotel-booking'),
            'msg_booking_updated'            => __('Booking updated successfully.', 'modern-hotel-booking'),
            'msg_tax_saved'                  => __('Tax settings saved successfully.', 'modern-hotel-booking'),
            'msg_security_failure' => __('Security verification failed. Please try again.', 'modern-hotel-booking'),
            'msg_invalid_request' => __('Invalid request parameters.', 'modern-hotel-booking'),
            'label_bank_acc_name' => __('Account Holder', 'modern-hotel-booking'),
            'label_bank_iban' => __('IBAN / Account Number', 'modern-hotel-booking'),
            'label_reference' => __('Reference', 'modern-hotel-booking'),
            'label_pay_via_revolut' => __('Pay via Revolut', 'modern-hotel-booking'),
            /* Admin Menu & Dashboard */
            'label_dashboard' => __('Dashboard', 'modern-hotel-booking'),
            'label_bookings' => __('Bookings', 'modern-hotel-booking'),
            'label_room_types' => __('Room Types', 'modern-hotel-booking'),
            'label_rooms' => _x('Rooms', 'accommodation count', 'modern-hotel-booking'),
            'label_extensions' => __('Extensions', 'modern-hotel-booking'),
            'label_getting_started' => __('Getting Started', 'modern-hotel-booking'),
            'label_documentation' => __('Documentation', 'modern-hotel-booking'),
            'label_get_support' => __('Get Support', 'modern-hotel-booking'),
            'label_booking_stats' => __('Booking Statistics', 'modern-hotel-booking'),
            'label_total_revenue' => __('Total Revenue', 'modern-hotel-booking'),
            'label_active_bookings' => __('Active Bookings', 'modern-hotel-booking'),
            'label_view_all_bookings' => __('View All Bookings', 'modern-hotel-booking'),
            'label_recent_activity' => __('Recent Activity', 'modern-hotel-booking'),
            'label_quick_links' => __('Quick Links', 'modern-hotel-booking'),
            'label_version_update' => __('Version Update Available', 'modern-hotel-booking'),
             'label_confirm'         => _x('Confirm', 'action', 'modern-hotel-booking'),
             'label_cancel'          => _x('Cancel', 'action', 'modern-hotel-booking'),
             'label_id'              => _x('ID', 'noun', 'modern-hotel-booking'),
             'label_guest'           => _x('Guest', 'noun', 'modern-hotel-booking'),
             'label_dates'           => _x('Dates', 'noun', 'modern-hotel-booking'),
             'label_room'            => _x('Room', 'accommodation unit', 'modern-hotel-booking'),
             'label_total'           => _x('Total', 'noun', 'modern-hotel-booking'),
             'label_status_noun'     => _x('Status', 'noun', 'modern-hotel-booking'),
             'label_payment'         => _x('Payment', 'noun', 'modern-hotel-booking'),
             'label_lang'            => _x('Lang', 'noun', 'modern-hotel-booking'),
             'label_actions'         => _x('Actions', 'noun', 'modern-hotel-booking'),
            // --- Admin: Booking Management ---
            'title_booking_mgmt'    => __('Booking Management', 'modern-hotel-booking'),
            'label_payment_status'  => __('Payment Status', 'modern-hotel-booking'),
            'label_payment_rcvd'    => __('Payment Received', 'modern-hotel-booking'),
            'label_mark_rcvd'       => __('Mark full payment as received', 'modern-hotel-booking'),
            'label_txn_id'          => __('Transaction ID', 'modern-hotel-booking'),
            'desc_txn_id'           => __('Transaction ID from payment gateway (read-only).', 'modern-hotel-booking'),
            'label_pay_amt'         => __('Payment Amount', 'modern-hotel-booking'),
            'desc_pay_amt'          => __('Actual amount paid (may differ from total).', 'modern-hotel-booking'),
            'label_pay_date'        => __('Payment Date', 'modern-hotel-booking'),
            'desc_pay_date'         => __('Date/time payment was completed (read-only).', 'modern-hotel-booking'),
            'label_pay_error'       => __('Payment Error', 'modern-hotel-booking'),
            'desc_pay_error'        => __('Error message from failed payment.', 'modern-hotel-booking'),
            'label_amt_out'         => __('Amount Outstanding', 'modern-hotel-booking'),
            'label_booking_lang'    => __('Language', 'modern-hotel-booking'),
            'label_admin_notes'     => __('Admin Notes', 'modern-hotel-booking'),
            'btn_update_booking'    => __('Update Booking', 'modern-hotel-booking'),
            'btn_delete_booking'    => __('Delete Booking', 'modern-hotel-booking'),
            'btn_download_invoice'  => __('📥 Download Invoice', 'modern-hotel-booking'),
            'btn_email_invoice'     => __('📧 Email Invoice', 'modern-hotel-booking'),
            'msg_confirm_delete_bk' => __('Are you sure you want to delete this booking? This action cannot be undone.', 'modern-hotel-booking'),
            'msg_no_bookings'       => __('No bookings found.', 'modern-hotel-booking'),
            'msg_no_results'        => __('Your search criteria didn\'t return any results.', 'modern-hotel-booking'),
            'label_balance_collected' => __('Balance Collected', 'modern-hotel-booking'),
            'label_balance_due'      => __('Remaining Balance Due', 'modern-hotel-booking'),
            'label_mark_collected'  => __('Mark Balance as Collected', 'modern-hotel-booking'),
            'label_na_short'         => _x('N/A', 'short for not applicable', 'modern-hotel-booking'),
            'msg_deposit_non_refundable_short' => __('(Non-Refundable)', 'modern-hotel-booking'),
            'label_pro_payment_summary' => __('Pro: Payment Summary & Balance Tracking', 'modern-hotel-booking'),
            'label_deposit_policy_snapshot' => __('Deposit Policy Snapshot', 'modern-hotel-booking'),
            'label_collection_status' => __('Collection Status', 'modern-hotel-booking'),
            'label_required_deposit' => __('Required Deposit', 'modern-hotel-booking'),
            'label_refund_deadline'  => __('Refund Deadline', 'modern-hotel-booking'),
            'label_extras_discounts' => __('Extras & Discounts', 'modern-hotel-booking'),
            /* translators: %d: child number (e.g. 1, 2, 3) */
            'label_child_number'     => __('Child %d:', 'modern-hotel-booking'),
            // --- Admin: Payment States ---
            'state_paid_full'       => __('Paid Full', 'modern-hotel-booking'),
            /* translators: %s: balance amount */
            'state_bal_pending'     => __('Bal: %s', 'modern-hotel-booking'),
            /* translators: %s: pending amount */
            'state_outstanding'     => __('Pending: %s', 'modern-hotel-booking'),

            'status_confirmed'      => _x('Confirmed', 'booking status', 'modern-hotel-booking'),
            'status_cancelled'      => _x('Cancelled', 'booking status', 'modern-hotel-booking'),
            'status_pending'        => _x('Pending', 'status', 'modern-hotel-booking'),
            'status_processing'     => _x('Processing', 'payment status', 'modern-hotel-booking'),
            'status_completed'      => _x('Completed', 'booking status', 'modern-hotel-booking'),
            'desc_status_completed' => __('Status Completed', 'modern-hotel-booking'),
            'status_failed'         => _x('Failed', 'payment status', 'modern-hotel-booking'),
            'status_refunded'       => _x('Refunded', 'payment status', 'modern-hotel-booking'),
            'status_checked_in'     => __('Checked In', 'modern-hotel-booking'),
            'status_checked_out'    => __('Checked Out', 'modern-hotel-booking'),
            'status_no_show'        => __('No Show', 'modern-hotel-booking'),
            /* Room Management */
            'label_unit_inventory' => __('Full Unit Inventory', 'modern-hotel-booking'),
            'label_unit_number' => __('Unit #', 'modern-hotel-booking'),
            'label_classification' => __('Classification / Type', 'modern-hotel-booking'),
            'label_select_room_type_placeholder' => __('— Select Room Type —', 'modern-hotel-booking'),
            'label_room_identifier_placeholder' => __('e.g. Room 101, Junior Suite A', 'modern-hotel-booking'),
            'label_override_rate' => __('Override Daily Rate', 'modern-hotel-booking'),
            'label_override_rate_desc' => __('Specific price for this unit. Leave at 0 to use the category default.', 'modern-hotel-booking'),
            'label_operational_status' => __('Operational Status', 'modern-hotel-booking'),
            'label_status_live' => __('Live & Reservable', 'modern-hotel-booking'),
            'label_status_maintenance' => __('Inactive / Maintenance', 'modern-hotel-booking'),
            'label_receiving_bookings' => __('Receiving Bookings', 'modern-hotel-booking'),
            'label_out_of_service' => __('Out of Service', 'modern-hotel-booking'),
            'label_total_units' => __('Total Units', 'modern-hotel-booking'),
            /* Extras / Service Add-ons */
            'label_add_extra'               => __('Add New Service Add-on', 'modern-hotel-booking'),
            'label_service_title'           => __('Service Title', 'modern-hotel-booking'),
            'label_service_title_placeholder' => __('e.g. Premium Breakfast Buffet', 'modern-hotel-booking'),
            'label_base_price'              => __('Base Price', 'modern-hotel-booking'),
            'label_pricing_model'           => __('Pricing Model', 'modern-hotel-booking'),
            'label_booking_input'           => __('Booking Input', 'modern-hotel-booking'),
            'opt_fixed_fee'                 => __('Fixed One-time Fee', 'modern-hotel-booking'),
            'opt_per_person'                => __('Per Guest Selection', 'modern-hotel-booking'),
            'opt_per_adult'                 => __('Per Adult Selection', 'modern-hotel-booking'),
            'opt_per_child'                 => __('Per Child Selection', 'modern-hotel-booking'),
            'opt_per_night'                 => __('Nightly Recurring', 'modern-hotel-booking'),
            'opt_person_night'              => __('Guest Count × Nights', 'modern-hotel-booking'),
            'opt_adult_night'               => __('Adult Count × Nights', 'modern-hotel-booking'),
            'opt_child_night'               => __('Child Count × Nights', 'modern-hotel-booking'),
            'opt_checkbox'                  => __('Selection Toggle (Checkbox)', 'modern-hotel-booking'),
            'opt_quantity'                  => __('Custom Amount (Quantity)', 'modern-hotel-booking'),
            'btn_save'                      => _x('Save', 'action', 'modern-hotel-booking'),
            'btn_delete'                    => __('Delete', 'modern-hotel-booking'),

            'label_service_fee'             => __('Service Fee', 'modern-hotel-booking'),
            'label_service_fee_desc'        => __('Use for cleaning fees, resort fees, or any mandatory service charge. The fee will appear as a locked line item on the booking form and in emails.', 'modern-hotel-booking'),
            'label_public_description'      => __('Public Description', 'modern-hotel-booking'),
            'label_desc_placeholder'        => __('Detail what is included with this service...', 'modern-hotel-booking'),
            'label_compulsory_extra'        => __('Automatically add to every booking (not selectable by guest)', 'modern-hotel-booking'),
            'label_day_mon' => _x('Mon', 'short day name', 'modern-hotel-booking'),
            'label_day_tue' => _x('Tue', 'short day name', 'modern-hotel-booking'),
            'label_day_wed' => _x('Wed', 'short day name', 'modern-hotel-booking'),
            'label_day_thu' => _x('Thu', 'short day name', 'modern-hotel-booking'),
            'label_day_fri' => _x('Fri', 'short day name', 'modern-hotel-booking'),
            'label_day_sat' => _x('Sat', 'short day name', 'modern-hotel-booking'),
            'label_day_sun' => _x('Sun', 'short day name', 'modern-hotel-booking'),
            'msg_invoice_sent' => __('Invoice emailed successfully to the guest.', 'modern-hotel-booking'),
            'msg_invoice_fail' => __('Failed to email invoice. Guest may not have an email address.', 'modern-hotel-booking'),
            'label_ready_for_guests' => __('Ready for Guests', 'modern-hotel-booking'),
            'label_custom_rate_active' => __('Custom rate override active for this unit.', 'modern-hotel-booking'),
            'btn_register_new_unit' => __('Register New Unit', 'modern-hotel-booking'),
            'btn_save_unit_data' => __('Save Unit Data', 'modern-hotel-booking'),
            'btn_modify_unit' => __('Modify Unit Registration', 'modern-hotel-booking'),
            'btn_discard_changes' => __('Discard Changes', 'modern-hotel-booking'),
            'btn_edit_details' => __('Edit Details', 'modern-hotel-booking'),
            'btn_ical_connections' => __('iCal Connections', 'modern-hotel-booking'),
            'msg_confirm_delete_unit' => __('Permanently remove this unit from inventory?', 'modern-hotel-booking'),
            'msg_inventory_empty' => __('Inventory is completely empty.', 'modern-hotel-booking'),
            /* iCal Sync */
            'label_ical_sync' => __('iCal Sync', 'modern-hotel-booking'),
            'label_pending_sync' => __('Pending Sync', 'modern-hotel-booking'),
            'label_connection_label' => __('Connection Label', 'modern-hotel-booking'),
            'label_connection_label_placeholder' => __('e.g. Airbnb, Booking.com', 'modern-hotel-booking'),
            'label_ical_url' => __('iCal Feed URL (HTTPS)', 'modern-hotel-booking'),
            'btn_connect_calendar' => __('Connect Calendar', 'modern-hotel-booking'),
            'btn_force_sync' => __('Force Global Sync', 'modern-hotel-booking'),
            'btn_cancel_return' => __('Cancel & Return', 'modern-hotel-booking'),
            'msg_confirm_disconnect_ical' => __('Disconnect this calendar? Import of bookings will stop.', 'modern-hotel-booking'),
            /* translators: 1: Booking ID, 2: Customer Name */
            'label_booking_number_date' => __('Booking #%1$d - %2$s', 'modern-hotel-booking'),
            'label_guest_fallback' => _x('Guest', 'customer noun', 'modern-hotel-booking'),
            /* translators: %d: Booking ID */
            'label_id_value' => __('Booking ID: %d', 'modern-hotel-booking'),
            /* translators: %s: Status */
            'label_status_value' => __('Status: %s', 'modern-hotel-booking'),
            /* translators: %d: Guests Count */
            'label_guests_count_value' => __('Guests: %d', 'modern-hotel-booking'),
            /* translators: %s: Email */
            'label_email_value' => __('Email: %s', 'modern-hotel-booking'),
            /* translators: %s: Source */
            'label_source_value' => __('Source: %s', 'modern-hotel-booking'),
            'label_external_booking' => __('External Booking', 'modern-hotel-booking'),
            'msg_security_check_token' => __('Security token mismatch. Access denied.', 'modern-hotel-booking'),
            'label_security_error_short' => __('Security Error', 'modern-hotel-booking'),
            /* Categories & Types */
            'label_manage_categories' => __('Manage Room Categories', 'modern-hotel-booking'),
            'label_new_category' => __('New Category Registration', 'modern-hotel-booking'),
            'label_edit_category' => __('Modify Category Details', 'modern-hotel-booking'),
            'label_category_name' => __('Category Name', 'modern-hotel-booking'),
            'label_category_name_placeholder' => __('e.g. Deluxe Ocean Suite', 'modern-hotel-booking'),
            'label_base_rate' => __('Base Nightly Rate', 'modern-hotel-booking'),
            'label_base_rate_desc' => __('Standard price used when no specific room overrides exist.', 'modern-hotel-booking'),
            'label_capacity' => __('Unit Capacity', 'modern-hotel-booking'),
            'label_adults' => _x('Adults', 'count', 'modern-hotel-booking'),
            'label_children_limit' => _x('Children', 'count', 'modern-hotel-booking'),
            'label_category_description' => __('Public Presentation', 'modern-hotel-booking'),
            'label_category_desc_placeholder' => __('Describe the features, view, and unique selling points...', 'modern-hotel-booking'),
            'label_visual_media' => __('Visual Media', 'modern-hotel-booking'),
            'label_featured_image' => __('Featured Cover Image', 'modern-hotel-booking'),
            'label_image_selected' => __('Image Selected', 'modern-hotel-booking'),
            'btn_select_image' => __('Select / Upload Image', 'modern-hotel-booking'),
            'btn_remove_image' => __('Remove Image', 'modern-hotel-booking'),
            'btn_register_category' => __('Register Category', 'modern-hotel-booking'),
            'btn_save_category' => __('Update Category Info', 'modern-hotel-booking'),
            'msg_confirm_delete_category' => __('Permanently delete this category? Linked units will lose their classification.', 'modern-hotel-booking'),
            'msg_categories_empty' => __('No room categories registered yet.', 'modern-hotel-booking'),
            /* Security & Common Failures */
            'msg_nonce_failed' => __('Security verification failed (Invalid Nonce).', 'modern-hotel-booking'),
            'msg_ajax_permission_denied' => __('You do not have permission to perform this action.', 'modern-hotel-booking'),
            'msg_failed_to_delete' => __('Failed to delete item.', 'modern-hotel-booking'),
            'msg_failed_to_save' => __('Failed to save. Please check your input.', 'modern-hotel-booking'),
            'btn_view_site' => __('View Public Booking Page', 'modern-hotel-booking'),
            'label_reservation' => __('RESERVATION', 'modern-hotel-booking'),
            'msg_booking_received' => __('Booking Pending', 'modern-hotel-booking'),
            'msg_booking_received_detail' => __('We have received your request and will contact you shortly.', 'modern-hotel-booking'),
            /* translators: %s: total amount */
            'label_arrival_msg' => __('You will pay %s upon arrival at the hotel.', 'modern-hotel-booking'),

            'label_special_requests' => __('Special Requests / Notes', 'modern-hotel-booking'),
            'label_select_check_in' => __('Select your check-in date', 'modern-hotel-booking'),
            'label_select_check_out' => __('Now select your check-out date', 'modern-hotel-booking'),
            'label_check_in_time' => __('Check-in Time', 'modern-hotel-booking'),
            'label_check_out_time' => __('Check-out Time', 'modern-hotel-booking'),
            'label_select_dates' => __('Select Dates', 'modern-hotel-booking'),
            'label_your_selection' => __('Your Selection', 'modern-hotel-booking'),
            'label_continue_booking' => __('Continue to Booking', 'modern-hotel-booking'),
            'label_dates_selected' => __('Dates selected. Complete the form below.', 'modern-hotel-booking'),
            'label_credit_card' => __('Credit Card', 'modern-hotel-booking'),
            'label_paypal' => __('PayPal', 'modern-hotel-booking'),
            'label_confirm_request' => __('Click below to confirm your booking request.', 'modern-hotel-booking'),
            /* translators: %s: tax name (e.g., VAT) */
            'label_tax_breakdown' => __('Booking Details', 'modern-hotel-booking'),
            'label_tax_none' => _x('None', 'tax type', 'modern-hotel-booking'),
            'label_tax_none_desc' => __('No tax calculation or display. Prices shown as-is.', 'modern-hotel-booking'),
            /* translators: %1$s: tax name, %2$s: tax amount */
            'label_tax_total' => __('Total %1$s: %2$s', 'modern-hotel-booking'),
            /* translators: %s: tax registration number */
            'label_tax_registration' => __('Tax Registration: %s', 'modern-hotel-booking'),
            /* translators: %s: tax amount */
            'label_includes_tax' => __('(includes %s)', 'modern-hotel-booking'),
            /* translators: %1$s: tax label, %2$s: tax rate percentage */
            'label_price_includes_tax' => __('Price includes %1$s (%2$s%%)', 'modern-hotel-booking'),
            /* translators: %1$s: tax label, %2$s: tax rate percentage */
            'label_tax_added_at_checkout' => __('%1$s (%2$s%%) will be added at checkout', 'modern-hotel-booking'),
            'label_subtotal' => __('Subtotal', 'modern-hotel-booking'),
            'label_extras' => __('Extras', 'modern-hotel-booking'),
            'label_stay_dates'    => _x('Stay Dates', 'table header', 'modern-hotel-booking'),
            'email_label_checkin' => __('Arrival Date', 'modern-hotel-booking'),
            'email_label_checkout' => __('Departure Date', 'modern-hotel-booking'),
            'label_amount' => _x('Amount', 'table header', 'modern-hotel-booking'),
            'label_booking_summary' => __('Booking Summary', 'modern-hotel-booking'),
            'label_accommodation' => __('Accommodation', 'modern-hotel-booking'),
            'label_extras_item' => __('Extras', 'modern-hotel-booking'),
            /* translators: %1$s: tax name, %2$s: tax percentage */
            'label_tax_accommodation' => __('%1$s - Accommodation (%2$s%%)', 'modern-hotel-booking'),
            /* translators: %1$s: tax label, %2$s: tax rate percentage */
            'label_tax_extras' => __('%1$s - Extras (%2$s%%)', 'modern-hotel-booking'),
            /* translators: %1$s: tax label, %2$s: tax rate percentage */
            'label_tax_rate' => __('%1$s (%2$s%%)', 'modern-hotel-booking'),
            'label_availability_error' => __('Dates are not available.', 'modern-hotel-booking'),
            'label_room_not_available' => __('Room not Available', 'modern-hotel-booking'),
            /* Moved to consolidated block below */
            'label_secure_payment' => __('Secure Online Payment', 'modern-hotel-booking'),
            'label_security_error' => __('Security verification failed. Please refresh the page.', 'modern-hotel-booking'),
            'label_security_check_failed' => __('Security check failed.', 'modern-hotel-booking'),
            'label_rate_limit_error' => __('Too many attempts. Please wait a minute.', 'modern-hotel-booking'),
            'label_spam_honeypot' => __('Leave this field empty', 'modern-hotel-booking'),
            'label_room_alt_text' => __('Room Image', 'modern-hotel-booking'),
            'label_calendar_no_id' => __('No room ID specified for calendar.', 'modern-hotel-booking'),
            'label_calendar_config_error' => __('Booking Page URL not configured.', 'modern-hotel-booking'),
            'label_loading' => _x('Loading...', 'placeholder', 'modern-hotel-booking'),
            'label_to' => _x('to', 'date range', 'modern-hotel-booking'),
            'btn_processing' => __('Processing...', 'modern-hotel-booking'),
            'label_booking_stats_desc' => __('Visual breakdown of your reservations and revenue.', 'modern-hotel-booking'),
            'label_permission_export_error'  => __('You do not have sufficient permissions to export data.', 'modern-hotel-booking'),
            
            'msg_gdpr_required' => __('Please accept the privacy policy to continue.', 'modern-hotel-booking'),
            'msg_paypal_required' => __('Please use the PayPal button to complete your payment.', 'modern-hotel-booking'),
            'label_enhance_stay' => __('Enhance Your Stay', 'modern-hotel-booking'),
            'label_per_person' => __('per person', 'modern-hotel-booking'),
            'general_search_hotel' => __('To use Gutenberg blocks search for "Hotel:" to bring up the available blocks.', 'modern-hotel-booking'),
            
            'label_per_person_per_night' => __('per person / night', 'modern-hotel-booking'),
            
            /* translators: %s: Tax rate percentage */
            'label_tax_note_includes' => __('Price includes %s', 'modern-hotel-booking'),
            /* translators: %s: Tax rate percentage */
            'label_tax_note_plus' => __('Price plus %s', 'modern-hotel-booking'),
            /* translators: %1$s: tax label, %2$s: tax rate percentage */
            'label_tax_note_includes_multi' => __('Price includes %1$s (%2$s%%)', 'modern-hotel-booking'),
            /* translators: %1$s: tax label, %2$s: tax rate percentage */
            'label_tax_note_plus_multi' => __('Price plus %1$s (%2$s%%)', 'modern-hotel-booking'),
            'label_select_dates_error' => __('Please select check-in and check-out dates.', 'modern-hotel-booking'),
            'label_legend_confirmed' => __('Booked', 'modern-hotel-booking'),
            'label_legend_pending' => __('Pending', 'modern-hotel-booking'),
            'label_legend_available' => __('Available', 'modern-hotel-booking'),
            'label_room_type' => __('Room Type', 'modern-hotel-booking'),
            'label_all_types' => __('All Types', 'modern-hotel-booking'),
            'label_total_starting_from' => __('Total (Starting from)', 'modern-hotel-booking'),
            'label_block_no_room' => __('Please select a Room ID in block settings.', 'modern-hotel-booking'),
            'label_check_in_past' => __('Check-in date cannot be in the past.', 'modern-hotel-booking'),
            'label_check_out_after' => __('Check-out date must be after check-in date.', 'modern-hotel-booking'),
            'label_check_in_future' => __('Check-in date cannot be more than 2 years in the future.', 'modern-hotel-booking'),
            'label_check_out_future' => __('Check-out date cannot be more than 2 years in the future.', 'modern-hotel-booking'),
            'label_name_too_long' => __('Name is too long (maximum 100 characters).', 'modern-hotel-booking'),
            'label_phone_too_long' => __('Phone number is too long (maximum 30 characters).', 'modern-hotel-booking'),
            /* translators: %d: Maximum number of children */
            'label_max_children_error' => __('Error: Maximum children for this room is %d.', 'modern-hotel-booking'),
            'label_price_calc_error' => __('Error calculating price. Please check dates.', 'modern-hotel-booking'),
            'label_invalid_price' => __('Bookings cannot be placed because no valid price has been set for these dates.', 'modern-hotel-booking'),
            'label_fill_all_fields' => __('Please fill in all required fields.', 'modern-hotel-booking'),
            'label_invalid_email' => __('Please provide a valid email address.', 'modern-hotel-booking'),
            /* translators: %s: Field name */
            'label_field_required' => __('The field "%s" is required.', 'modern-hotel-booking'),
            'label_spam_detected' => __('Spam detected.', 'modern-hotel-booking'),
            'label_already_booked' => __('Sorry, this room was just booked by someone else or is unavailable for these dates.', 'modern-hotel-booking'),
            /* translators: %d: Maximum number of adults */
            'label_max_adults_error' => __('Error: Maximum adults for this room is %d.', 'modern-hotel-booking'),
            'label_rest_pro_error' => __('REST API access is a Pro feature.', 'modern-hotel-booking'),
            /* Cleaned duplicate API keys (Moved to ECA/API section below) */
            'label_missing_child_ages' => __('Please provide an age for each child.', 'modern-hotel-booking'),
            'msg_booking_arrival_email' => __('Your booking is confirmed. Payment will be collected on arrival. A confirmation email has been sent.', 'modern-hotel-booking'),
            'label_payment_failed' => __('Payment Failed', 'modern-hotel-booking'),
            'msg_payment_failed_detail' => __('Your payment could not be processed. Please try again or contact us for assistance.', 'modern-hotel-booking'),
            'msg_booking_received_pending' => __('Your reservation is under review and needs to be approved before becoming reserved.', 'modern-hotel-booking'),
            'label_paid' => _x('Paid', 'payment status', 'modern-hotel-booking'),
            'label_amount_paid' => __('Amount Paid:', 'modern-hotel-booking'),
            'label_failed' => _x('Failed', 'payment status', 'modern-hotel-booking'),
            'label_dates_no_longer_available' => __('Sorry, these dates are no longer available. Please select different dates.', 'modern-hotel-booking'),
            'label_invalid_booking_calc' => __('Invalid booking details. Cannot calculate amount.', 'modern-hotel-booking'),
            'label_stripe_not_configured' => __('Stripe is not configured.', 'modern-hotel-booking'),
            'label_setup_failed' => __('Payment setup failed.', 'modern-hotel-booking'),
            /* Cleaned duplicate PayPal/Gateway keys (Moved to consolidated sections below) */
            'label_finalizing' => __('Finalizing...', 'modern-hotel-booking'),
            'label_gateway_not_ready' => __('Credit card payment system is not ready. Please refresh the page or choose another method.', 'modern-hotel-booking'),
            'label_payment_success_form_fail' => __('Payment successful but form submission failed. Please contact support.', 'modern-hotel-booking'),
            'label_payment_cancelled' => __('Payment cancelled.', 'modern-hotel-booking'),
            'label_redirecting' => __('Redirecting...', 'modern-hotel-booking'),
            'label_loading_payment' => __('Loading secure payment form...', 'modern-hotel-booking'),
            'label_payment_capture_failed' => __('Payment capture failed: ', 'modern-hotel-booking'),
            'label_payment_confirmation' => __('Payment Confirmation', 'modern-hotel-booking'),
            'label_privacy_policy' => __('privacy policy', 'modern-hotel-booking'),
            'label_terms_conditions' => __('Terms & Conditions', 'modern-hotel-booking'),
            'gdpr_checkbox_text' => __('I have read and agree to the [privacy_policy].', 'modern-hotel-booking'),
            
            'msg_pay_on_arrival_email' => __('Payment will be collected upon arrival at the property.', 'modern-hotel-booking'),
            'label_amount_due' => __('Amount Due', 'modern-hotel-booking'),
            'label_payment_date' => __('Payment Date', 'modern-hotel-booking'),
            'label_paypal_order_failed' => __('Failed to create PayPal order.', 'modern-hotel-booking'),
            'label_security_verification_failed' => __('Security verification failed. Please refresh the page and try again.', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_paypal_client_id_missing' => __('PayPal %s Client ID is not configured.', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_paypal_secret_missing' => __('PayPal %s Secret is not configured.', 'modern-hotel-booking'),
            'label_stripe_intent_missing' => __('Stripe payment failed: Payment intent missing.', 'modern-hotel-booking'),
            /* translators: %s: Error message */
            'label_stripe_generic_error' => __('Stripe API error: %s', 'modern-hotel-booking'),
            'label_paypal_id_missing' => __('PayPal payment failed: Order ID missing.', 'modern-hotel-booking'),
            'label_payment_required' => __('Payment is required.', 'modern-hotel-booking'),
            'label_api_not_configured' => __('API key has not been configured. Set it in Hotel Booking → Settings.', 'modern-hotel-booking'),
            'label_no_room_available_auto' => __('No available room could be resolved for your selection. Please try different dates or contact us.', 'modern-hotel-booking'),
            'label_invalid_api_key' => __('Invalid or missing API key.', 'modern-hotel-booking'),
            /* Cleaned duplicate Webhook keys (Moved to ECA/API section below) */
            'label_missing_paypal_headers' => __('Missing required PayPal webhook headers.', 'modern-hotel-booking'),
            'label_invalid_customer' => __('Valid customer name and email are required.', 'modern-hotel-booking'),
            'label_invalid_dates' => __('Invalid booking dates.', 'modern-hotel-booking'),
            /* Cleaned duplicate Room/Permission/Stripe keys (Moved to consolidated sections below) */
            /* translators: %s: Environment (Sandbox/Live) */
            'label_stripe_pk_missing' => __('Stripe %s Publishable Key is not configured.', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_stripe_sk_missing' => __('Stripe %s Secret Key is not configured.', 'modern-hotel-booking'),
            /* translators: %1$s: Expected key prefix, %2$s: Environment mode */
            'label_stripe_invalid_pk_format' => __('Invalid publishable key format. Expected key starting with "%1$s" for %2$s mode.', 'modern-hotel-booking'),
            'label_credentials_spaces' => __('Credentials contain extra spaces', 'modern-hotel-booking'),
            'label_mode_mismatch' => __('Using Sandbox credentials in Live mode (or vice versa)', 'modern-hotel-booking'),
            'label_credentials_expired' => __('Credentials have expired or been rotated', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_creds_valid_env' => __('PayPal %s credentials are valid!', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */

            /* translators: %s: Error message */
            'label_connection_failed' => __('Connection failed: %s', 'modern-hotel-booking'),
            /* translators: %s: Error message */
            'label_auth_failed_env' => __('Authentication failed: %s', 'modern-hotel-booking'),
            'label_common_causes' => __('Common causes:', 'modern-hotel-booking'),
            'label_booking_id' => __('Booking ID', 'modern-hotel-booking'),
            'label_reference_number' => __('Reference Number', 'modern-hotel-booking'),
            'label_amenities' => __('Amenities', 'modern-hotel-booking'),
            'btn_view_room' => __('View Room Details', 'modern-hotel-booking'),
            'label_back_to_rooms' => __('Back to Rooms List', 'modern-hotel-booking'),
            'btn_select_room' => __('Select This Room', 'modern-hotel-booking'),
            'label_room_details' => __('Room Summary & Amenities', 'modern-hotel-booking'),
            'label_guest_details' => __('Guest Contact Information', 'modern-hotel-booking'),
            'label_payment_details' => __('Payment & Billing Details', 'modern-hotel-booking'),
            'label_children_count_simple' => __('Children', 'modern-hotel-booking'),
            'label_child' => _x('Child', 'guest category', 'modern-hotel-booking'),
            'label_no_extras' => __('No extras selected.', 'modern-hotel-booking'),
            'label_total_with_tax' => __('Total (including taxes)', 'modern-hotel-booking'),
            'label_booking_dates' => __('Reservation Dates', 'modern-hotel-booking'),
            'label_enable' => _x('Enable', 'action verb', 'modern-hotel-booking'),
            'label_test' => _x('Test', 'environment', 'modern-hotel-booking'),
            'label_live' => _x('Live', 'environment', 'modern-hotel-booking'),
            'label_airbnb_reservation' => __('Airbnb Reservation', 'modern-hotel-booking'),
            'label_google_calendar_event' => __('Google Calendar Event', 'modern-hotel-booking'),
            'label_stripe_currency_mismatch' => /* translators: 1: plugin currency, 2: stripe currency */ __('Warning: Plugin currency (%1$s) does not match your Stripe account currency (%2$s).', 'modern-hotel-booking'),
            
            'block_booking_form_title' => __('Hotel: Booking Form', 'modern-hotel-booking'),
            'block_booking_form_desc' => __('A modern, elegant booking form for your rooms.', 'modern-hotel-booking'),
            'block_room_calendar_title' => __('Hotel: Room Calendar', 'modern-hotel-booking'),
            'block_room_calendar_desc' => __('Show an interactive availability calendar for a specific room.', 'modern-hotel-booking'),
            'block_company_info_title' => __('Hotel: Company Info', 'modern-hotel-booking'),
            'block_company_info_desc' => __('Display your hotel address, contact details, and social links.', 'modern-hotel-booking'),
            'block_whatsapp_button_title' => __('Hotel: WhatsApp Button', 'modern-hotel-booking'),
            'block_whatsapp_button_desc' => __('Floating or inline WhatsApp chat button for direct bookings.', 'modern-hotel-booking'),
            'block_banking_details_title' => __('Hotel: Banking Details', 'modern-hotel-booking'),
            'block_banking_details_desc' => __('Displays bank account information for direct bank transfers.', 'modern-hotel-booking'),
            'block_revolut_details_title' => __('Hotel: Revolut Details', 'modern-hotel-booking'),
            'block_revolut_details_desc' => __('Information for payments via Revolut (RevTag or Link).', 'modern-hotel-booking'),

            // --- AI Concierge ---
            'ai_persona_default'        => __('AI Concierge', 'modern-hotel-booking'),
            'ai_widget_open'           => __('Open AI Concierge chat', 'modern-hotel-booking'),
            'ai_widget_close'          => __('Close chat', 'modern-hotel-booking'),
            'ai_widget_minimize'       => __('Minimize chat', 'modern-hotel-booking'),
            'ai_widget_send'           => __('Send message', 'modern-hotel-booking'),
            'ai_widget_input_placeholder' => __('Ask me anything...', 'modern-hotel-booking'),
            'ai_widget_input_label'    => __('Chat message', 'modern-hotel-booking'),
            'ai_widget_typing'         => __('AI is typing', 'modern-hotel-booking'),
            'ai_widget_error_message'  => __('Sorry, something went wrong. Please try again.', 'modern-hotel-booking'),
            'ai_widget_welcome_message' => __('Hello! I\'m your AI concierge. How can I help you today?', 'modern-hotel-booking'),
            'ai_widget_sugg_check_avail' => __('Check availability', 'modern-hotel-booking'),
            'ai_widget_sugg_room_types' => __('What room types do you have?', 'modern-hotel-booking'),
            'ai_widget_sugg_policies'   => __('What are your policies?', 'modern-hotel-booking'),
            'ai_widget_dialog_label'    => __('Hotel AI Concierge Chat', 'modern-hotel-booking'),
            'ai_widget_message_history' => __('Chat messages', 'modern-hotel-booking'),
            'ai_widget_suggestions'     => __('Quick replies', 'modern-hotel-booking'),
            'ai_cta_high_intent'        => __('Ready to book?', 'modern-hotel-booking'),
            'ai_cta_med_intent'         => __('Looks good!', 'modern-hotel-booking'),
            'ai_cta_book_now'           => __('Book Now', 'modern-hotel-booking'),
            'ai_cta_dismiss'            => __('Dismiss', 'modern-hotel-booking'),
            'ai_handoff_intro'          => __('Need more help? Contact us directly:', 'modern-hotel-booking'),
            'ai_handoff_whatsapp'       => __('WhatsApp', 'modern-hotel-booking'),
            'ai_handoff_email'          => _x('Email', 'contact method', 'modern-hotel-booking'),
            'ai_handoff_phone'          => __('Call Us', 'modern-hotel-booking'),
            // Voice controls.
            'ai_widget_start_voice'     => __('Start voice input', 'modern-hotel-booking'),
            'ai_widget_stop_voice'      => __('Stop voice input', 'modern-hotel-booking'),
            'ai_widget_toggle_voice'    => __('Toggle voice output', 'modern-hotel-booking'),
            'ai_widget_voice_not_supported' => __('Voice input is not supported in your browser.', 'modern-hotel-booking'),
            'ai_widget_voice_denied'    => __('Microphone access denied. Please allow microphone access to use voice input.', 'modern-hotel-booking'),
            // Proactive (Einstein dwell-time) greetings — shown by the chat widget
            // after the configured idle time on pages with booking-relevant content.
            'ai_proactive_default'      => __('Hi there! 👋 Can I help you plan your stay?', 'modern-hotel-booking'),
            'ai_proactive_booking'      => __('Hi! I can answer questions about availability or pricing. Just ask!', 'modern-hotel-booking'),
            'ai_proactive_rooms'        => __('Looking for the perfect room? I can help you choose!', 'modern-hotel-booking'),
            'ai_status_analyzing'       => __('Analyzing your request...', 'modern-hotel-booking'),
            'ai_status_refining'        => __('Refining details...', 'modern-hotel-booking'),
            'ai_error_invalid_nonce'    => __('Invalid nonce.', 'modern-hotel-booking'),
            'ai_error_rate_limit'       => __('Too many requests. Please wait a moment before sending another message.', 'modern-hotel-booking'),
            'ai_error_message_too_long' => __('Message must be 1000 characters or fewer.', 'modern-hotel-booking'),
            'ai_error_empty_response'   => __('The AI did not return a response. Please try again.', 'modern-hotel-booking'),
            'ai_error_provider_not_configured' => __('AI provider not configured. Please set up your API key in Settings → AI Concierge.', 'modern-hotel-booking'),
            'ai_error_bad_custom_url'    => __('Custom AI endpoint URL is missing or invalid. Please update it in Settings → AI Concierge.', 'modern-hotel-booking'),
            'ai_status_cooling_down'    => __('Our AI concierge is currently cooling down due to high demand. Please try again in a moment.', 'modern-hotel-booking'),
            /* translators: %1$d: seconds cooling down */
            'ai_error_cooling_down_seconds' => __('Concierge is cooling down due to high demand. Please try again in %1$ds.', 'modern-hotel-booking'),
            /* translators: %s: provider name */
            'ai_error_unknown_provider' => __('Unknown AI provider: %s', 'modern-hotel-booking'),
            /* translators: %d: HTTP status code */
            'ai_error_bad_json'         => __('Gemini returned non-JSON response (HTTP %d)', 'modern-hotel-booking'),
            'ai_error_unknown_gemini'    => __('Unknown Gemini error', 'modern-hotel-booking'),
            'ai_status_thinking_analyzing' => __('Analyzing your request...', 'modern-hotel-booking'),
            'ai_status_thinking_refining'  => __('Refining details...', 'modern-hotel-booking'),
            /* translators: %d: retry attempt number */
            'ai_status_thinking_retrying'  => __('Model busy, retrying (attempt %d/1)...', 'modern-hotel-booking'),
            /* translators: %s: room type name */
            'ai_suggestion_room_more'      => __('Tell me more about the %s', 'modern-hotel-booking'),
            'ai_suggestion_rooms_info'     => __('Tell me more about your rooms', 'modern-hotel-booking'),
            'ai_suggestion_cancellation'   => __('What is your cancellation policy?', 'modern-hotel-booking'),
            'ai_suggestion_complete_booking' => __('How do I complete my booking?', 'modern-hotel-booking'),
            'ai_suggestion_check_availability' => __('Check availability for these dates', 'modern-hotel-booking'),
            'ai_suggestion_amenities'      => __('What amenities does the hotel offer?', 'modern-hotel-booking'),
            'ai_suggestion_included'       => __('What is included in my stay?', 'modern-hotel-booking'),
            'ai_suggestion_directions'     => __('How do I get directions to the hotel?', 'modern-hotel-booking'),
            'ai_suggestion_modify'         => __('Can I modify my booking later?', 'modern-hotel-booking'),
            'ai_suggestion_new_dates'      => __('Check availability for new dates', 'modern-hotel-booking'),
            'ai_suggestion_refund'         => __('What is your refund policy?', 'modern-hotel-booking'),
            'ai_suggestion_availability'   => __('Check room availability', 'modern-hotel-booking'),
            'ai_suggestion_about_hotel'    => __('Tell me about the hotel', 'modern-hotel-booking'),
            'ai_suggestion_payment'        => __('How do I pay for my stay?', 'modern-hotel-booking'),
            'ai_suggestion_payment_options' => __('What are the payment options?', 'modern-hotel-booking'),
            'ai_suggestion_cancellation_info' => __('Tell me about your cancellation policy', 'modern-hotel-booking'),
            'ai_suggestion_extras'         => __('Can I add breakfast or other extras?', 'modern-hotel-booking'),
            'ai_suggestion_room_types'     => __('What are your room types?', 'modern-hotel-booking'),

            // AI Internal: Prompt Rules
            /* translators: %s: language tag (e.g. en_US) */
            'ai_prompt_lang_rule'       => __('- Language: This page is served in language tag "%s". Always reply in that language. If the guest writes in a different language, match their language instead.', 'modern-hotel-booking'),
            /* translators: %s: guest name */
            'ai_prompt_guest_name'      => __('- CURRENT GUEST NAME: %s.', 'modern-hotel-booking'),
            /* translators: %s: guest email */
            'ai_prompt_guest_email'     => __('- CURRENT GUEST EMAIL: %s.', 'modern-hotel-booking'),
            /* translators: %s: guest phone number */
            'ai_prompt_guest_phone'     => __('- CURRENT GUEST PHONE: %s.', 'modern-hotel-booking'),
            /* translators: %1$s: current date, %2$s: day of the week */
            'ai_prompt_date_context'    => __('- CURRENT DATE (Hotel Timezone): %1$s. Today is %2$s. Always use this as the starting point for availability.', 'modern-hotel-booking'),
            'ai_prompt_scarcity_rule'   => __('- SCARCITY URGENCY: If inventory for a requested date is low (less than 3 units), EXPLICITLY highlight this urgency in your response using bold or ALL CAPS (e.g., "ONLY 2 ROOMS LEFT!").', 'modern-hotel-booking'),
            /* translators: %s: function name */
            'ai_prompt_decisive_rule'   => __('- PROACTIVE MANDATE: If you have the guest\'s name, email, and phone already collected in the context above, YOU MUST call %s immediately for their chosen room. DO NOT WAIT FOR CONFIRMATION.', 'modern-hotel-booking'),

            // AI Internal: Knowledge Base Headers (English by default)
            'ai_kb_header_hotel_overview' => __('=== HOTEL OVERVIEW ===', 'modern-hotel-booking'),
            'ai_kb_header_policies'       => __('--- POLICIES ---', 'modern-hotel-booking'),
            'ai_kb_header_local_guide'    => __('--- LOCAL CONCIERGE GUIDE ---', 'modern-hotel-booking'),
            /* translators: %s: room name */
            'ai_kb_header_rooms'          => __('=== ROOM: %s ===', 'modern-hotel-booking'),
            'ai_kb_header_extras'         => __('=== EXTRAS & ADD-ONS ===', 'modern-hotel-booking'),
            'ai_kb_header_pricing'        => __('=== PRICING & TAX RULES ===', 'modern-hotel-booking'),
            'ai_kb_header_payment'        => __('=== PAYMENT METHODS ===', 'modern-hotel-booking'),
            'ai_kb_header_contact'        => __('=== WHATSAPP & DIRECT CONTACT ===', 'modern-hotel-booking'),
            /* translators: %s: page title */
            'ai_kb_header_page'           => __('=== PAGE: %s ===', 'modern-hotel-booking'),
            /* translators: %s: post title */
            'ai_kb_header_post'           => __('=== POST: %s ===', 'modern-hotel-booking'),
            'ai_kb_label_type_id'        => __('Type ID', 'modern-hotel-booking'),
            'ai_kb_label_base_price'      => __('Base Price per Night', 'modern-hotel-booking'),
            'ai_kb_label_max_adults'      => __('Max Adults', 'modern-hotel-booking'),
            'ai_kb_label_max_children'    => __('Max Children', 'modern-hotel-booking'),
            'ai_kb_label_total_rooms'     => __('Total Rooms Available', 'modern-hotel-booking'),
            'ai_kb_label_description'     => __('Description', 'modern-hotel-booking'),
            'ai_kb_label_amenities'       => __('Room Amenities', 'modern-hotel-booking'),

            // AI Internal: Discovery (llms.txt)
            'ai_discovery_summary_prop_details'     => __('## Core Property Details', 'modern-hotel-booking'),
            'ai_discovery_summary_rooms_amenities'  => __('## Rooms & Amenities', 'modern-hotel-booking'),
            'ai_discovery_summary_rooms_amenities_desc' => __('Our property offers various room types optimized for different guest capacities. See the full manifest for specific features.', 'modern-hotel-booking'),
            'ai_discovery_summary_ai_interaction'   => __('## AI Interaction & Booking', 'modern-hotel-booking'),
            'ai_discovery_summary_ai_interaction_desc' => __('AI Agents can interact with our concierge and booking engine via the following endpoints:', 'modern-hotel-booking'),
            'ai_discovery_summary_manifest'         => __('## Full Site Manifest', 'modern-hotel-booking'),
            'ai_discovery_summary_view_kb'           => __('View Detailed Knowledge Base', 'modern-hotel-booking'),
            'ai_discovery_summary_welcome_default'  => __('Welcome to our hotel.', 'modern-hotel-booking'),

            'ai_label_settings_title'   => __('AI Concierge Settings', 'modern-hotel-booking'),
            'ai_label_settings_menu'    => __('AI Concierge', 'modern-hotel-booking'),
            'ai_mcp_name'               => __('Modern Hotel Booking AI', 'modern-hotel-booking'),
            'ai_mcp_description'        => __('Real-time hotel availability, policies, and booking assistant.', 'modern-hotel-booking'),

'block_business_card_title' => __('Hotel: Business Card', 'modern-hotel-booking'),
            'block_business_card_desc' => __('A compact summary of your hotel for headers or footers.', 'modern-hotel-booking'),
            /* Settings & Navigation (HIERARCHICAL REFINED 2026) */
            'tab_license' => _x('License', 'settings tab', 'modern-hotel-booking'),
            'tab_general' => _x('General', 'settings tab', 'modern-hotel-booking'),
            'tab_pricing' => _x('Pricing', 'settings tab', 'modern-hotel-booking'),
            'tab_emails' => _x('Emails', 'settings tab', 'modern-hotel-booking'),
            'tab_multilingual' => _x('Language', 'settings tab', 'modern-hotel-booking'),
            'tab_amenities' => _x('Amenities', 'settings tab', 'modern-hotel-booking'),
            'tab_business' => _x('Business', 'settings tab', 'modern-hotel-booking'),
            'tab_themes' => _x('Themes', 'settings tab', 'modern-hotel-booking'),
            'tab_gdpr' => _x('GDPR', 'settings tab', 'modern-hotel-booking'),
            'tab_tax' => _x('Tax & VAT', 'settings tab', 'modern-hotel-booking'),
            'tab_deposits' => _x('Deposits', 'settings tab', 'modern-hotel-booking'),
            'tab_webhooks' => _x('Webhooks', 'settings tab', 'modern-hotel-booking'),
            'settings_title' => __('Plugin Settings', 'modern-hotel-booking'),
            'settings_desc_page' => __('Configure the core behavior of the hotel booking system.', 'modern-hotel-booking'),

            'tab_performance' => __('Performance', 'modern-hotel-booking'),
            'performance_settings' => __('Performance & Caching', 'modern-hotel-booking'),
            'performance_desc' => __('Optimize your site speed by configuring object caching and transients.', 'modern-hotel-booking'),
            'performance_enable_cache' => __('Enable Persistent Caching', 'modern-hotel-booking'),
            'performance_cache_label' => __('Activate MHBO Cache', 'modern-hotel-booking'),
            'performance_cache_desc' => __('Reduces database load by caching room rates and availability calculations.', 'modern-hotel-booking'),
            'performance_object_cache' => __('Object Cache Status', 'modern-hotel-booking'),
            'performance_active' => __('Object Cache Active', 'modern-hotel-booking'),
            'performance_object_cache_desc' => __('Your server has persistent object caching active (Redis/Memcached). This is the most efficient configuration.', 'modern-hotel-booking'),
            'performance_using_transients' => __('Using Database Transients', 'modern-hotel-booking'),
            'performance_no_cache_desc' => __('No persistent object cache detected. The plugin will use WordPress database transients instead.', 'modern-hotel-booking'),
            'performance_clear_cache' => __('Purge Caches', 'modern-hotel-booking'),
            'performance_clear_desc' => __('If you notice outdated prices or availability, you can manually clear all MHBO cached data.', 'modern-hotel-booking'),
            'performance_btn_clear_all' => __('Clear All MHBO Cache', 'modern-hotel-booking'),
            'performance_msg_clearing' => __('Clearing cache...', 'modern-hotel-booking'),
            'performance_msg_cleared' => __('All caches purged successfully.', 'modern-hotel-booking'),
            
            'settings_section_general' => __('General Settings', 'modern-hotel-booking'),
            'settings_section_security' => __('Security Settings', 'modern-hotel-booking'),
            'settings_section_currency' => __('Currency & Formatting', 'modern-hotel-booking'),
            
            'settings_label_checkin' => __('Check-in Time', 'modern-hotel-booking'),
            'settings_label_checkout' => __('Check-out Time', 'modern-hotel-booking'),
            'settings_label_notification' => __('Notification Email', 'modern-hotel-booking'),
            'settings_label_booking_page' => __('Booking Page', 'modern-hotel-booking'),
            'settings_label_booking_override' => __('Booking Page URL Override', 'modern-hotel-booking'),
            'settings_label_turnover' => __('Prevent Same-Day Turnover', 'modern-hotel-booking'),
            'settings_label_children' => __('Enable Children Rates', 'modern-hotel-booking'),
            'settings_label_custom_fields' => __('Guest Reservation Fields', 'modern-hotel-booking'),
            'settings_label_uninstall' => __('Save Data on Uninstall', 'modern-hotel-booking'),
            'settings_label_powered_by' => __('Powered By Link', 'modern-hotel-booking'),
            'settings_label_force_frontend_locale' => __('Force Frontend Language', 'modern-hotel-booking'),
            'settings_label_proxies' => __('Trusted Proxies', 'modern-hotel-booking'),
            'settings_label_currency_code' => __('Currency Code (ISO)', 'modern-hotel-booking'),
            'settings_label_currency_symbol' => __('Currency Symbol', 'modern-hotel-booking'),
            'settings_label_currency_pos' => __('Currency Position', 'modern-hotel-booking'),
            'settings_label_decimals' => __('Show Decimals in Prices', 'modern-hotel-booking'),
            
            'settings_desc_turnover' => __('Check this to prevent a room from being booked on the same day it is vacated.', 'modern-hotel-booking'),
            'settings_desc_children' => __('Enable children counting and age-based pricing tiers.', 'modern-hotel-booking'),
            'settings_desc_uninstall' => __('If unchecked, all plugin data and settings will be deleted when the plugin is uninstalled.', 'modern-hotel-booking'),
            'settings_desc_powered_by' => __('Show a small "Powered by MHBO" link in the booking footer.', 'modern-hotel-booking'),
            'settings_desc_force_frontend_locale' => __('Override the plugin language on the public frontend. When set, the booking calendar and forms will always display in the chosen language, regardless of logged-in users\' profile language. Leave on "Auto" to use the default WordPress behaviour.', 'modern-hotel-booking'),
            'settings_opt_locale_auto' => __('— Auto (visitor / profile language) —', 'modern-hotel-booking'),
            'settings_desc_proxies' => __('Enter IP addresses of trusted proxies (one per line) for accurate IP detection.', 'modern-hotel-booking'),
            'settings_desc_decimals' => __('If unchecked, prices will be rounded to the nearest whole number.', 'modern-hotel-booking'),
            'settings_desc_booking_shortcode' => __('The page containing the [hotel_booking] shortcode. This is used for generating booking links.', 'modern-hotel-booking'),

            'settings_opt_after' => __('After (e.g. 100 $)', 'modern-hotel-booking'),
            'settings_opt_before' => __('Before (e.g. $ 100)', 'modern-hotel-booking'),
            'settings_opt_none_page' => __('— Select Page —', 'modern-hotel-booking'),
            
            'settings_msg_saved' => __('Settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_are_you_sure' => __('Are you sure? This action cannot be undone.', 'modern-hotel-booking'),
            'settings_msg_remove_field' => __('Remove this field?', 'modern-hotel-booking'),
            'settings_msg_no_holidays' => __('No holidays configured yet.', 'modern-hotel-booking'),
            
            /* License & Pro Management */
            'settings_label_license_key' => __('License Key', 'modern-hotel-booking'),
            'settings_desc_license_key' => __('Enter your Pro license key to unlock premium features and updates.', 'modern-hotel-booking'),
            'settings_label_license_status' => __('License Status', 'modern-hotel-booking'),
            'settings_label_license_expires' => __('License Expiration', 'modern-hotel-booking'),
            'settings_desc_license_renewal' => __('Renew your license to continue receiving premium support and updates.', 'modern-hotel-booking'),
            'settings_btn_license_activate' => __('Activate License', 'modern-hotel-booking'),
            'settings_btn_license_deactivate' => __('Deactivate License', 'modern-hotel-booking'),
            'settings_btn_license_refresh' => __('Refresh Status', 'modern-hotel-booking'),
            'settings_title_pro_locked' => __('Pro Features Locked', 'modern-hotel-booking'),
            'settings_desc_pro_locked' => __('A valid license is required to access advanced modules like GDPR, Tax, and Dynamic Pricing.', 'modern-hotel-booking'),
            'settings_title_pro_active' => __('Premium Features Active', 'modern-hotel-booking'),
            
            'settings_item_pro_gateways' => __('Advanced Payment Gateways', 'modern-hotel-booking'),
            'settings_item_pro_ical' => __('iCal Synchronization', 'modern-hotel-booking'),
            'settings_item_pro_analytics' => __('Occupancy Analytics', 'modern-hotel-booking'),
            'settings_item_pro_deposits' => __('Partial Payments & Deposits', 'modern-hotel-booking'),
            'settings_item_pro_pricing' => __('Dynamic Pricing Rules', 'modern-hotel-booking'),
            'settings_item_pro_support' => __('Priority Developer Support', 'modern-hotel-booking'),

/* Admin Menu Labels */

            'menu_main' => __('Hotel Booking', 'modern-hotel-booking'),
            'menu_dashboard' => __('Dashboard', 'modern-hotel-booking'),
            'menu_bookings' => __('Bookings', 'modern-hotel-booking'),
            'menu_rooms' => _x('Rooms', 'admin menu', 'modern-hotel-booking'),
            'menu_pricing' => __('Pricing Rules', 'modern-hotel-booking'),
            'menu_settings' => __('Settings', 'modern-hotel-booking'),
            'menu_pro_features' => __('PRO Features', 'modern-hotel-booking'),
            'menu_extras' => __('Extras', 'modern-hotel-booking'),
            'menu_ical' => __('iCal Sync', 'modern-hotel-booking'),
            'menu_payments' => __('Payments', 'modern-hotel-booking'),
            'menu_webhooks' => __('Webhooks', 'modern-hotel-booking'),
            'menu_analytics' => __('Analytics', 'modern-hotel-booking'),
            'menu_appearance' => __('Appearance', 'modern-hotel-booking'),
            'menu_advanced_pricing' => __('Advanced Pricing', 'modern-hotel-booking'),
            'menu_licensing' => __('Licensing', 'modern-hotel-booking'),
            'menu_coupons'   => __('Coupons', 'modern-hotel-booking'),

            /* Coupon System (PRO) */
            'label_coupon_code'      => __('Coupon Code', 'modern-hotel-booking'),
            'placeholder_coupon_code'=> __('Enter coupon code', 'modern-hotel-booking'),
            'btn_apply_coupon'       => _x('Apply', 'action', 'modern-hotel-booking'),
            'btn_remove_coupon'      => __('Remove', 'modern-hotel-booking'),

            /* Admin Headers & Sections */
            'label_room_types_config' => __('Room Types & Configuration', 'modern-hotel-booking'),
            'label_room_types_desc' => __('Define your property layout, base pricing, and maximum capacities.', 'modern-hotel-booking'),
            'label_room_inventory' => __('Room Inventory', 'modern-hotel-booking'),
            'label_inventory_desc' => __('Real-time overview of your physical units, their status, and synchronization settings.', 'modern-hotel-booking'),
            'label_customer_details' => __('Customer Details', 'modern-hotel-booking'),
            'label_extra_guest_info' => __('Extra Guest Information', 'modern-hotel-booking'),
            'label_room_dates' => __('Room & Dates', 'modern-hotel-booking'),

            /* Booking List & Management UI */
            'label_paid_full' => __('Paid Full', 'modern-hotel-booking'),
            /* translators: %s: balance amount */
            'label_bal_pending' => __('Bal: %s', 'modern-hotel-booking'),
            'label_add_booking' => __('Add Booking', 'modern-hotel-booking'),
            'label_pending'     => __('Pending:', 'modern-hotel-booking'),
            'msg_confirm_delete_booking' => __('Are you sure you want to delete this booking? This action cannot be undone.', 'modern-hotel-booking'),
            'label_col_payment' => __('Payment', 'modern-hotel-booking'),
            'label_col_lang' => _x('Lang', 'short for language', 'modern-hotel-booking'),
            'label_col_actions' => __('Actions', 'modern-hotel-booking'),
            'label_col_id'      => _x('ID', 'column header', 'modern-hotel-booking'),
            'label_col_unit'    => __('Unit #', 'modern-hotel-booking'),
            'label_col_rate'    => __('Daily Rate', 'modern-hotel-booking'),
            'label_col_status'  => __('Status', 'modern-hotel-booking'),
            'label_col_mgmt'    => __('Management', 'modern-hotel-booking'),

            /* Manual Booking Form Labels */
            'label_add_manual_booking' => __('Add Manual Booking', 'modern-hotel-booking'),
            /* translators: %d: Booking ID */
            'label_edit_booking_n' => __('Edit Booking #%d', 'modern-hotel-booking'),
            'label_discount_amount'  => __('Discount Amount', 'modern-hotel-booking'),
            'label_no_extras_config' => __('No extras configured.', 'modern-hotel-booking'),
            'label_total_price'      => __('Total Price', 'modern-hotel-booking'),
            'label_deposit_amount'   => __('Deposit Amount', 'modern-hotel-booking'),
            'label_payment_method'   => __('Payment Method', 'modern-hotel-booking'),
            'label_mark_as_received' => __('Mark as received', 'modern-hotel-booking'),
            'label_deposit_received' => __('Deposit Received', 'modern-hotel-booking'),
            'label_payment_info'     => __('Payment Info', 'modern-hotel-booking'),
            'label_select_room'      => __('-- Select Room --', 'modern-hotel-booking'),
            'label_email_addr'       => _x('Email', 'customer detail', 'modern-hotel-booking'),
            'label_pay_arrival_manual' => __('Pay on Arrival / Manual', 'modern-hotel-booking'),

            'label_paid_full_pill'           => __('Paid Full', 'modern-hotel-booking'),
            'label_completed_pill'           => __('Completed', 'modern-hotel-booking'),
            'label_pending_pill'             => __('Pending', 'modern-hotel-booking'),
            'label_confirm_are_you_sure'     => __('Are you sure?', 'modern-hotel-booking'),
            'label_room_type_deleted'        => __('Room Type Deleted.', 'modern-hotel-booking'),
            'label_room_type_updated'        => __('Room Type Updated!', 'modern-hotel-booking'),
            'label_room_type_added'          => __('Room Type Added!', 'modern-hotel-booking'),
            'label_modify_room_config'       => __('Modify Room Configuration', 'modern-hotel-booking'),
            'label_define_new_room'          => __('Define New Room Type', 'modern-hotel-booking'),
            'label_save_config'              => __('Save Configuration', 'modern-hotel-booking'),
            'label_create_room_type'         => __('Create Room Type', 'modern-hotel-booking'),
            'label_room_deleted'             => __('Room Deleted.', 'modern-hotel-booking'),
            'label_feed_added'               => __('Feed Added!', 'modern-hotel-booking'),
            'label_sync_completed'           => __('Sync Completed.', 'modern-hotel-booking'),

            /* iCal Synchronization Labels */
            /* translators: %s: unit/room name or number */
            'label_ical_unit_n' => __('iCal Synchronization — Unit %s', 'modern-hotel-booking'),
            'label_deployment_export' => __('Deployment Export URL', 'modern-hotel-booking'),
            'label_export_desc' => __('Provide this URL to external OTAs (Airbnb, Booking.com) to export this room\'s availability.', 'modern-hotel-booking'),

            /* Dashboard & Widget UI */
            'dash_title' => __('Hotel Booking Overview', 'modern-hotel-booking'),
            'dash_desc' => __('Real-time monitoring of your property operations, revenue, and guest activity.', 'modern-hotel-booking'),
            'dash_total' => __('Total Bookings', 'modern-hotel-booking'),
            'dash_pending' => __('Pending / Action Required', 'modern-hotel-booking'),
            'dash_today' => __('Check-ins Today', 'modern-hotel-booking'),
            'dash_revenue' => __('Stay Revenue', 'modern-hotel-booking'),
            'dash_revenue_desc' => __('Total revenue from completed stays.', 'modern-hotel-booking'),
            'dash_pipeline' => __('Target Pipeline', 'modern-hotel-booking'),
            'dash_pipeline_desc' => __('Projected revenue from confirmed future bookings.', 'modern-hotel-booking'),
            'dash_volume' => __('Total Volume', 'modern-hotel-booking'),
            'dash_volume_desc' => __('Cumulative count of all historical bookings.', 'modern-hotel-booking'),
            'dash_attention' => __('Attention Needed', 'modern-hotel-booking'),
            'dash_attention_desc' => __('Bookings that require manual intervention.', 'modern-hotel-booking'),
            'dash_recent' => __('Recent Activity', 'modern-hotel-booking'),
            'dash_no_bookings' => __('No recent bookings found.', 'modern-hotel-booking'),
            'dash_view_all' => __('View All Bookings', 'modern-hotel-booking'),
            'dash_quick_actions' => __('Quick Actions', 'modern-hotel-booking'),
            'dash_create' => __('Create New Booking', 'modern-hotel-booking'),
            'dash_inventory' => __('Manage Inventory', 'modern-hotel-booking'),
            'dash_hotel_control' => __('Hotel Control Center', 'modern-hotel-booking'),
            'dash_hotel_control_desc' => __('Real-time monitoring of your property operations, revenue, and guest activity.', 'modern-hotel-booking'),
            
            'widget_title' => __('MHBO: Booking Search', 'modern-hotel-booking'),
            'widget_desc' => __('A compact booking search form.', 'modern-hotel-booking'),
            'widget_field_title' => __('Title:', 'modern-hotel-booking'),
            'widget_default_title' => __('Book Your Stay', 'modern-hotel-booking'),

            /* Pro Extra Actions */
            'btn_decrease' => __('Decrease quantity', 'modern-hotel-booking'),
            'btn_increase' => __('Increase quantity', 'modern-hotel-booking'),
            'confirm_remove_extra' => __('Are you sure you want to remove this extra?', 'modern-hotel-booking'),

            /* Pro Banners & Upsells */
            'pro_banner_title' => __('Unlock Premium Hospitality Features', 'modern-hotel-booking'),
            'pro_banner_desc' => __('Power up with iCal Sync, Stripe/PayPal integration, Advanced Pricing rules, and Deep Analytics.', 'modern-hotel-booking'),
            'pro_btn_upgrade' => __('Upgrade to Pro', 'modern-hotel-booking'),
            'pro_need_assistance' => __('Need Assistance?', 'modern-hotel-booking'),
            'pro_assistance_desc' => __('Explore our documentation or contact the support team for property management advice.', 'modern-hotel-booking'),
            'pro_report_issues' => __('Report Issues →', 'modern-hotel-booking'),
            'pro_get_version' => __('Get Pro Version →', 'modern-hotel-booking'),

            /* System Status */
            'status_title' => __('System Status', 'modern-hotel-booking'),
            'status_license' => __('License Status:', 'modern-hotel-booking'),
            'status_unlicensed' => __('Unlicensed', 'modern-hotel-booking'),
            'status_edition' => __('Plugin Edition:', 'modern-hotel-booking'),
            'status_free' => __('Free Version', 'modern-hotel-booking'),
            'status_version' => __('Plugin Version:', 'modern-hotel-booking'),
            'status_db' => __('Database Status:', 'modern-hotel-booking'),
            'status_healthy' => __('Healthy', 'modern-hotel-booking'),

            /* Misc */
            'powered_by' => __('Powered by', 'modern-hotel-booking'),
            'label_date' => _x('Date', 'noun', 'modern-hotel-booking'),
            'label_status' => _x('Status', 'state of entity', 'modern-hotel-booking'),
            'label_status_confirmed' => _x('Confirmed', 'booking status', 'modern-hotel-booking'),
            'label_status_pending'   => _x('Pending', 'booking status', 'modern-hotel-booking'),
            'label_status_cancelled' => _x('Cancelled', 'booking status', 'modern-hotel-booking'),
            'label_source_direct'    => _x('Direct', 'booking source', 'modern-hotel-booking'),
            'label_source_ical'      => _x('iCal Import', 'booking source', 'modern-hotel-booking'),
            'btn_view_all' => __('View All', 'modern-hotel-booking'),
            'label_hotel_revenue_overview'   => __('Hotel Revenue Overview', 'modern-hotel-booking'),
            'menu_room_types'                => __('Room Types', 'modern-hotel-booking'),
            'label_version_updates'          => __('Version Updates', 'modern-hotel-booking'),
            'label_view_changelog'           => __('View Changelog', 'modern-hotel-booking'),
            'status_active'                  => _x('Active', 'status', 'modern-hotel-booking'),
            'label_email'                    => __('Email Address', 'modern-hotel-booking'),
            'msg_no_bookings_found'          => __('No bookings found.', 'modern-hotel-booking'),
            'msg_search_no_results'          => __('Your search criteria didn\'t return any results.', 'modern-hotel-booking'),

            /* Settings Tabs & Sections */
            'settings_label_search' => __('Search Labels...', 'modern-hotel-booking'),
            
            'settings_save_general' => __('Save General Settings', 'modern-hotel-booking'),
            'settings_save_multilingual' => __('Save Multilingual Settings', 'modern-hotel-booking'),
            'settings_save_payments' => __('Save Payment Settings', 'modern-hotel-booking'),
            'settings_save_theme' => __('Save Theme Settings', 'modern-hotel-booking'),
            'settings_save_pricing' => __('Save Pricing Settings', 'modern-hotel-booking'),
            
            'settings_msg_error' => __('Error saving settings.', 'modern-hotel-booking'),
            
            'msg_invalid_booking' => __('Invalid booking ID.', 'modern-hotel-booking'),
            'msg_insufficient_perms' => __('Insufficient permissions.', 'modern-hotel-booking'),
            'msg_unauthorized' => __('Unauthorized.', 'modern-hotel-booking'),
            'msg_security_fail' => __('Security check failed.', 'modern-hotel-booking'),
            /* translators: %s: date the balance was collected */
            'msg_balance_collected' => __('Balance marked as collected by admin on %s.', 'modern-hotel-booking'),

            'enter_license_key'              => __('Please enter a license key.', 'modern-hotel-booking'),
            'connection_error'               => __('Connection error.', 'modern-hotel-booking'),
            
            /* Pro Features & Labels */
            'pro_experience' => __('Pro Experience', 'modern-hotel-booking'),
            'pro_unlocked' => __('All Pro features are unlocked and ready to use.', 'modern-hotel-booking'),
            'pro_required' => __('Pro License Required', 'modern-hotel-booking'),
            'pro_active' => __('Pro License Active', 'modern-hotel-booking'),
            /* translators: %s: license expiry date */
            'pro_expires' => __('Expires: %s', 'modern-hotel-booking'),
            'pro_upgrade' => __('Upgrade to Pro', 'modern-hotel-booking'),
            'pro_manage' => __('Manage License', 'modern-hotel-booking'),
            'pro_enter_key' => __('Enter License Key', 'modern-hotel-booking'),
            'pro_benefits_support' => __('Priority Support', 'modern-hotel-booking'),
            'pro_benefits_updates' => __('Priority Updates', 'modern-hotel-booking'),
            'pro_benefits_features' => __('All Premium Features', 'modern-hotel-booking'),
            
            'pro_tab_overview' => __('Overview', 'modern-hotel-booking'),
            'pro_tab_extras' => __('Booking Extras', 'modern-hotel-booking'),
            'pro_tab_ical' => __('iCal Sync', 'modern-hotel-booking'),
            'pro_tab_payments' => __('Payments', 'modern-hotel-booking'),
            'pro_tab_webhooks' => __('Webhooks', 'modern-hotel-booking'),
            'pro_tab_themes' => __('Pro Themes', 'modern-hotel-booking'),
            'pro_tab_analytics' => __('Analytics', 'modern-hotel-booking'),
            'pro_tab_pricing' => __('Advanced Pricing', 'modern-hotel-booking'),
            'pro_tab_business' => __('Business', 'modern-hotel-booking'),
            'pro_tab_tax' => _x('Tax', 'settings tab', 'modern-hotel-booking'),
            
            'cat_compliance_ux' => __('Compliance & UX', 'modern-hotel-booking'),
            'cat_developer_platform' => __('Developer Platform', 'modern-hotel-booking'),
            
            'feature_gdpr_title' => __('GDPR Data Privacy', 'modern-hotel-booking'),
            'feature_gdpr_desc'  => __('Automated anonymization and consent management.', 'modern-hotel-booking'),
            'feature_analytics_title' => __('Business Analytics', 'modern-hotel-booking'),
            'feature_analytics_desc'  => __('Visual reports on revenue, occupancy, and ADR.', 'modern-hotel-booking'),
            'feature_themes_title' => __('Premium Themes', 'modern-hotel-booking'),
            'feature_themes_desc'  => __('Exclusive design templates for your booking form.', 'modern-hotel-booking'),
            'feature_multilingual_title' => __('Multilingual Support', 'modern-hotel-booking'),
            'feature_multilingual_desc'  => __('Expand your reach with unlimited locale translations.', 'modern-hotel-booking'),
            'feature_rest_api_title' => __('Enterprise REST API', 'modern-hotel-booking'),
            'feature_rest_api_desc'  => __('Connect your hotel to external apps and OTAs.', 'modern-hotel-booking'),
            'feature_webhooks_title' => __('Webhooks & Events', 'modern-hotel-booking'),
            'feature_webhooks_desc'  => __('Real-time notifications for automated workflows.', 'modern-hotel-booking'),
            
            'title_quick_actions' => __('Quick Actions', 'modern-hotel-booking'),
            'action_configure_payments' => __('Configure Payments', 'modern-hotel-booking'),
            'action_setup_tax' => __('Setup Tax Settings', 'modern-hotel-booking'),
            'action_view_analytics' => __('View Analytics', 'modern-hotel-booking'),
            'action_manage_ical' => __('Manage iCal Sync', 'modern-hotel-booking'),
            
            'settings_title_gutenberg' => __('Gutenberg Blocks', 'modern-hotel-booking'),
            
            'label_tax_settings'             => __('Tax Settings', 'modern-hotel-booking'),
            'label_tax_settings_desc'        => __('Configure VAT/Sales Tax settings for your hotel bookings. Tax is disabled by default for backward compatibility.', 'modern-hotel-booking'),
            'label_tax_mode'                 => __('Tax Mode', 'modern-hotel-booking'),
            'label_disabled'                 => __('Disabled', 'modern-hotel-booking'),
            
            /* Admin Room & Type Management */
            'title_content_localization' => __('Content & Localization', 'modern-hotel-booking'),
            'label_room_name' => __('Room Name', 'modern-hotel-booking'),
            'label_description' => __('Description', 'modern-hotel-booking'),
            'title_pricing_capacity' => __('Pricing & Capacity', 'modern-hotel-booking'),
            'label_nightly_base_rate' => __('Nightly Base Rate', 'modern-hotel-booking'),
            'label_adult_capacity' => __('Adult Capacity', 'modern-hotel-booking'),
            'label_child_capacity' => __('Child Capacity', 'modern-hotel-booking'),
            'label_child_free_age' => __('Child Free Age', 'modern-hotel-booking'),
            'label_extra_child_rate' => __('Extra Child Rate', 'modern-hotel-booking'),
            'title_media_amenities' => __('Media & Amenities', 'modern-hotel-booking'),
            'btn_select' => __('Select', 'modern-hotel-booking'),
            'label_standard_amenities' => __('Standard Amenities', 'modern-hotel-booking'),
            'title_defined_room_types' => __('Defined Room Types', 'modern-hotel-booking'),
            'desc_manage_room_types' => __('Manage and edit your existing room configurations.', 'modern-hotel-booking'),
            'msg_no_room_types' => __('No room types defined yet. Create your first category above.', 'modern-hotel-booking'),
            'title_manage_rooms' => __('Manage Rooms', 'modern-hotel-booking'),
            'btn_back_to_rooms' => __('Back to Rooms', 'modern-hotel-booking'),
            'label_deployment_url' => __('Deployment Export URL', 'modern-hotel-booking'),
            'desc_deployment_url' => __('Provide this URL to external OTAs (Airbnb, Booking.com) to export this room\'s availability.', 'modern-hotel-booking'),
            'btn_copied' => __('Copied!', 'modern-hotel-booking'),
            'btn_copy_url' => __('Copy URL', 'modern-hotel-booking'),
            'title_import_calendars' => __('Import External Calendars', 'modern-hotel-booking'),
            'label_service_feed_name' => __('Service / Feed Name', 'modern-hotel-booking'),
            'label_feed_url' => __('Feed URL', 'modern-hotel-booking'),
            'label_last_heartbeat' => __('Last Heartbeat', 'modern-hotel-booking'),
            'msg_no_external_feeds' => __('No external feeds connected yet.', 'modern-hotel-booking'),
            'label_ical_feed_url' => __('iCal Feed URL (HTTPS)', 'modern-hotel-booking'),
            'btn_force_global_sync' => __('Force Global Sync', 'modern-hotel-booking'),
            'label_classification_type' => __('Classification / Type', 'modern-hotel-booking'),
            'label_room_identifier' => __('Room Number / Identifier', 'modern-hotel-booking'),
            'desc_override_rate' => __('Specific price for this unit. Leave at 0 to use the category default.', 'modern-hotel-booking'),
            'title_full_inventory' => __('Full Unit Inventory', 'modern-hotel-booking'),
            'label_edit_details' => __('Edit Details', 'modern-hotel-booking'),
            'label_ical_connections' => __('iCal Connections', 'modern-hotel-booking'),
            'title_service_extras' => __('Service Extras & Add-ons', 'modern-hotel-booking'),
            'title_configure_services' => __('Configure Available Services', 'modern-hotel-booking'),
            'label_pro_not_available'        => __('Pro features are not available.', 'modern-hotel-booking'),
            'label_permission_export'        => __('You do not have sufficient permissions to export data.', 'modern-hotel-booking'),
            'api_err_pro_required'           => __('Pro build required to sync calendars.', 'modern-hotel-booking'),
            'label_none'                     => _x('None', 'selection', 'modern-hotel-booking'),
            
            /* Payment SDK & Gateways */
            'label_paypal_sdk_locale'        => __('PayPal SDK Locale', 'modern-hotel-booking'),
            'desc_paypal_sdk_locale'         => __('Force a specific locale for the PayPal buttons (e.g. en_US, fr_FR).', 'modern-hotel-booking'),
            'label_paypal_sdk_args'          => __('SDK Query Arguments', 'modern-hotel-booking'),
            'desc_paypal_sdk_args'           => __('Additional JSON-formatted arguments for the PayPal SDK loader.', 'modern-hotel-booking'),
            'label_example_regional'         => __('e.g. locale=en_US', 'modern-hotel-booking'),
            'label_example_language'         => __('e.g. lang=fr', 'modern-hotel-booking'),
            'label_test_connection'          => __('Test Connection', 'modern-hotel-booking'),
            'desc_verify_connection'         => __('Verify that your API credentials are valid and can reach the gateway.', 'modern-hotel-booking'),
            'label_pay_on_arrival'           => __('Pay on Arrival (On-Site)', 'modern-hotel-booking'),
            'label_enable_generic'           => __('Enable On-Site Payment', 'modern-hotel-booking'),
            'label_onsite_enable_desc'       => __('Allow guests to book now and pay manually when they arrive.', 'modern-hotel-booking'),
            'label_instructions'             => __('Customer Instructions', 'modern-hotel-booking'),
            'desc_onsite_instructions'       => __('Instructions shown to the customer after selecting this payment method.', 'modern-hotel-booking'),
            'btn_save_payment_settings'      => __('Save Payment Settings', 'modern-hotel-booking'),
            'btn_save_webhook_settings'      => __('Save Webhook Settings', 'modern-hotel-booking'),
            'btn_save_tax_settings'          => __('Save Tax Settings', 'modern-hotel-booking'),
            'btn_save_pricing_settings'      => __('Save Pricing Settings', 'modern-hotel-booking'),

            // Action feedback messages (short-form keys used by save handlers)
            'msg_pricing_saved'              => __('Pricing settings saved successfully.', 'modern-hotel-booking'),
            'msg_payment_saved'              => __('Payment settings saved successfully.', 'modern-hotel-booking'),
            'msg_gdpr_saved'                 => __('GDPR settings saved successfully.', 'modern-hotel-booking'),
            'msg_performance_saved'          => __('Performance settings saved successfully.', 'modern-hotel-booking'),
            'msg_deposit_saved'              => __('Deposit settings saved successfully.', 'modern-hotel-booking'),
            'msg_license_saved'              => __('License settings saved successfully.', 'modern-hotel-booking'),
            /* translators: %d: number of rooms synced */
            'msg_room_synced'                => __('%d room(s) synced successfully.', 'modern-hotel-booking'),

            // API / Webhook UI
            'api_msg_sending'                => __('Sending…', 'modern-hotel-booking'),

            // Plugin action links (wp-admin/plugins.php)
            'label_settings'                 => __('Settings', 'modern-hotel-booking'),

            // GDPR consent textarea default value
            'label_gdpr_consent_text'        => __('I agree to the processing of my personal data for the purpose of managing my booking reservation.', 'modern-hotel-booking'),

'pro_restricted_title' => __('Pro Feature Restricted', 'modern-hotel-booking'),
            'pro_restricted_desc' => __('A valid license is required to access advanced modules.', 'modern-hotel-booking'),
            'pro_upsell_desc' => __('Unlock all premium features to maximize your booking potential.', 'modern-hotel-booking'),
            'pro_experience_desc' => __('Unlock the full potential of your hotel management with premium tools.', 'modern-hotel-booking'),

            /* Advanced Pricing (PRO) */
            'pricing_title_weekend'          => __('Weekend Pricing', 'modern-hotel-booking'),
            'pricing_desc_weekend'           => __('Define specific nightly rates for Friday and Saturday stays.', 'modern-hotel-booking'),
            'pricing_label_weekend_days'     => __('Weekend Days', 'modern-hotel-booking'),
            'pricing_label_weekend_adj'      => __('Weekend Adjustment', 'modern-hotel-booking'),
            'label_fixed_amount_desc'        => __('Add/subtract a flat amount per night.', 'modern-hotel-booking'),
            'label_percentage_desc'          => __('Add/subtract a percentage of the base rate.', 'modern-hotel-booking'),
            'pricing_title_holiday'          => __('Holiday & Peak Season', 'modern-hotel-booking'),
            'pricing_label_holiday_picker'  => __('Holiday Date Picker', 'modern-hotel-booking'),
            'pricing_desc_holiday_picker'   => __('Define specific calendar dates for special pricing rules.', 'modern-hotel-booking'),
            'pricing_label_holiday_adj'     => __('Holiday Adjustment', 'modern-hotel-booking'),
            'btn_enable'                     => __('Enable', 'modern-hotel-booking'),
            'pricing_label_conflict'         => __('Rule Conflict Detected', 'modern-hotel-booking'),
            'pricing_label_conflict_desc'    => __('Multiple rules apply to the same period. Highest priority rule will take effect.', 'modern-hotel-booking'),
            
            'label_desc_per_person'          => __('Price calculation is based on per-person occupancy.', 'modern-hotel-booking'),
            'label_desc_legend_pending'      => __('Reservation awaiting payment or admin approval.', 'modern-hotel-booking'),
            'label_desc_enhance_stay'        => __('Available add-ons to enhance your stay.', 'modern-hotel-booking'),
            'label_desc_legend_available'    => __('Dates available for selection.', 'modern-hotel-booking'),
            'label_desc_legend_confirmed'    => __('Booking finalized and room secured.', 'modern-hotel-booking'),
            'label_desc_check_out_future'    => __('Check-out date must be after check-in.', 'modern-hotel-booking'),
            'label_desc_check_in_future'     => __('Check-in date cannot be in the past.', 'modern-hotel-booking'),
            'label_desc_check_out_after'     => __('Checkout must be strictly after check-in.', 'modern-hotel-booking'),
            'label_desc_check_in_past'       => __('Invalid check-in date.', 'modern-hotel-booking'),
            'label_desc_block_no_room'       => __('No rooms of this type available for selection.', 'modern-hotel-booking'),
            'label_desc_select_dates_error'  => __('Please select both check-in and check-out dates.', 'modern-hotel-booking'),
            
            'pricing_title' => __('Pricing Rules & Schedules', 'modern-hotel-booking'),
            'pricing_desc' => __('Define custom seasonal rates, discounts, and holiday surcharges.', 'modern-hotel-booking'),
            'pricing_add_title' => __('Define New Adjustment', 'modern-hotel-booking'),
            'pricing_campaign' => __('Campaign Name', 'modern-hotel-booking'),
            'pricing_campaign_placeholder' => __('e.g. Summer Special 2026', 'modern-hotel-booking'),
            'pricing_active_period' => __('Active Period', 'modern-hotel-booking'),
            'pricing_until' => _x('until', 'temporal limit', 'modern-hotel-booking'),
            'pricing_change' => __('Price Change', 'modern-hotel-booking'),
            'pricing_percent' => __('Percentage %', 'modern-hotel-booking'),
            'pricing_change_desc' => __('Positive for surcharges, negative for discounts.', 'modern-hotel-booking'),
            'pricing_target_room' => __('Target Room Type', 'modern-hotel-booking'),
            'pricing_all_rooms' => __('All Room Types (Global Rule)', 'modern-hotel-booking'),
            'pricing_btn_create' => __('Create Pricing Rule', 'modern-hotel-booking'),
            'pricing_active_rules' => __('Active & Scheduled Adjustments', 'modern-hotel-booking'),
            'pricing_rule_name' => __('Rule Name', 'modern-hotel-booking'),
            'pricing_validity' => __('Validity', 'modern-hotel-booking'),
            'pricing_impact' => __('Impact', 'modern-hotel-booking'),
            'pricing_applicability' => __('Applicability', 'modern-hotel-booking'),
            'pricing_no_rules' => __('No pricing rules defined.', 'modern-hotel-booking'),
            'pricing_global_label' => __('Global (All Types)', 'modern-hotel-booking'),
            'pricing_delete_confirm' => __('Permanently remove this pricing adjustment?', 'modern-hotel-booking'),
            'pricing_msg_added' => __('Pricing rule created successfully.', 'modern-hotel-booking'),
            'pricing_msg_deleted' => __('Pricing rule removed successfully.', 'modern-hotel-booking'),
            
            'pro_api_title' => __('REST API', 'modern-hotel-booking'),
            
            /* Pro Themes */
            'pricing_title_themes'           => __('Pro Themes', 'modern-hotel-booking'),
            'pricing_desc_themes'            => __('Select a pre-designed visual theme for your booking form.', 'modern-hotel-booking'),
            'theme_midnight_name'            => __('Midnight Dark', 'modern-hotel-booking'),
            'theme_midnight_desc'            => __('Sleek dark mode with gold accents.', 'modern-hotel-booking'),
            'theme_emerald_name'             => __('Forest Emerald', 'modern-hotel-booking'),
            'theme_emerald_desc'             => __('Natural greens and soft earth tones.', 'modern-hotel-booking'),
            'theme_oceanic_name'             => __('Oceanic Blue', 'modern-hotel-booking'),
            'theme_oceanic_desc'             => __('Deep blues and crisp white contrast.', 'modern-hotel-booking'),
            'theme_ruby_name'                => __('Ruby Red', 'modern-hotel-booking'),
            'theme_ruby_desc'                => __('Warm reds and elegant typography.', 'modern-hotel-booking'),
            'theme_urban_name'               => __('Urban Slate', 'modern-hotel-booking'),
            'theme_urban_desc'               => __('Modern industrial greys and minimalist lines.', 'modern-hotel-booking'),
            'theme_lavender_name'            => __('Soft Lavender', 'modern-hotel-booking'),
            'theme_lavender_desc'            => __('Calming purples and light textures.', 'modern-hotel-booking'),
            'theme_custom_name'              => __('Custom CSS', 'modern-hotel-booking'),
            'theme_custom_desc'              => __('Load your own styles (Advanced users only).', 'modern-hotel-booking'),
            'theme_title_css'                => __('Custom CSS Editor', 'modern-hotel-booking'),
            'theme_desc_css'                 => __('Override any theme styles with your own CSS rules.', 'modern-hotel-booking'),
            'btn_return_default'             => __('Restore Default', 'modern-hotel-booking'),
            'btn_save_theme_settings'        => __('Save Theme Settings', 'modern-hotel-booking'),
            
            /* Pro Analytics */
            'label_collected_revenue'        => __('Total Collected Revenue', 'modern-hotel-booking'),
            'label_occupancy_rate'           => __('Occupancy Rate', 'modern-hotel-booking'),
            'label_revpar'                   => __('RevPAR (Revenue per Available Room)', 'modern-hotel-booking'),
            'label_adr'                      => __('ADR (Average Daily Rate)', 'modern-hotel-booking'),
            'label_total_bookings'           => __('Total Bookings', 'modern-hotel-booking'),
            'label_confirmation_rate'        => __('Confirmation Rate', 'modern-hotel-booking'),
            'label_avg_booking_value'        => __('Average Booking Value', 'modern-hotel-booking'),
            'label_tax_collected'            => __('Total Tax Collected', 'modern-hotel-booking'),
            'label_booked'                   => _x('Booked', 'analytic state', 'modern-hotel-booking'),
            'label_available'                => _x('Available', 'analytic state', 'modern-hotel-booking'),
            'label_revenue'                  => __('Projected Revenue', 'modern-hotel-booking'),
            'label_collected'                => _x('Collected', 'payment state', 'modern-hotel-booking'),
            'label_completed'                => _x('Completed', 'booking state', 'modern-hotel-booking'),
            'label_confirmed'                => _x('Confirmed', 'booking state', 'modern-hotel-booking'),
            'label_cancellation_rate_dash'   => __('Cancellation Rate', 'modern-hotel-booking'),
            'label_lost_revenue'             => __('Potential Lost Revenue', 'modern-hotel-booking'),
            'label_cancellations_count'      => __('Counted Cancellations', 'modern-hotel-booking'),
            'pro_api_desc' => __('The REST API allows external systems to query room availability and manage bookings.', 'modern-hotel-booking'),
            'pro_api_key' => __('API Key', 'modern-hotel-booking'),
            'pro_api_generate' => __('Generate', 'modern-hotel-booking'),
            'pro_api_auth_desc' => __('Include as X-MHBO-API-KEY header for authenticated endpoints.', 'modern-hotel-booking'),
            
            'pro_webhooks_title' => __('Webhooks', 'modern-hotel-booking'),
            'pro_webhooks_url' => __('Webhook URL', 'modern-hotel-booking'),
            'pro_webhooks_secret' => __('Webhook Secret', 'modern-hotel-booking'),
            'pro_webhooks_secret_desc' => __('Used to sign webhook payloads using HMAC-SHA256.', 'modern-hotel-booking'),
            'pro_webhooks_log_desc' => __('Log outgoing deliveries (up to 50 entries).', 'modern-hotel-booking'),
            'pro_webhooks_test' => __('Send Test Webhook', 'modern-hotel-booking'),
            'pro_webhooks_clear' => __('Clear History', 'modern-hotel-booking'),
            'pro_webhooks_logs' => __('Delivery Logs', 'modern-hotel-booking'),
            'pro_webhooks_recent' => __('Recent activity (last 50 events).', 'modern-hotel-booking'),
            'pro_webhooks_loading' => __('Loading logs...', 'modern-hotel-booking'),
            'pro_webhooks_no_logs' => __('No activity logs found.', 'modern-hotel-booking'),
            'pro_webhooks_test_sending' => __('Sending...', 'modern-hotel-booking'),
            'pro_webhooks_enter_url' => __('Please enter a Webhook URL first.', 'modern-hotel-booking'),
            'pro_webhooks_confirm_clear' => __('Are you sure you want to clear all webhook logs?', 'modern-hotel-booking'),
            'msg_webhook_sent_success' => __('Test webhook sent successfully! Check the delivery logs below.', 'modern-hotel-booking'),
            'msg_webhook_history_cleared' => __('Webhook history cleared.', 'modern-hotel-booking'),
            
            'pro_api_endpoints' => __('Available Endpoints', 'modern-hotel-booking'),
            'pro_api_method' => __('Method', 'modern-hotel-booking'),
            'pro_api_endpoint' => __('Endpoint', 'modern-hotel-booking'),
            'pro_api_access' => __('Auth / Access', 'modern-hotel-booking'),
            'pro_api_public' => __('Public (Rate Limited)', 'modern-hotel-booking'),
            'pro_api_pro' => __('Pro (Rate Limited)', 'modern-hotel-booking'),
            'pro_api_required' => __('API Key Required', 'modern-hotel-booking'),
            'pro_api_admin' => __('API Key Required / Admin', 'modern-hotel-booking'),
            
            'api_label_actions' => __('Actions', 'modern-hotel-booking'),
            'api_title_logs' => __('Delivery Logs', 'modern-hotel-booking'),
            'api_desc_logs' => __('Recent activity (last 50 events).', 'modern-hotel-booking'),
            'api_msg_loading_logs' => __('Loading logs...', 'modern-hotel-booking'),
            'api_msg_click_copy' => __('Click to copy', 'modern-hotel-booking'),
            'api_msg_no_logs' => __('No activity logs found.', 'modern-hotel-booking'),
            'api_title_endpoints' => __('Available Endpoints', 'modern-hotel-booking'),
            
            'log_time' => _x('Time', 'timestamp', 'modern-hotel-booking'),
            'log_event' => _x('Event', 'log category', 'modern-hotel-booking'),
            'log_status' => _x('Status', 'noun', 'modern-hotel-booking'),
            'log_response' => __('Response', 'modern-hotel-booking'),
            'click_to_copy' => __('Click to copy', 'modern-hotel-booking'),
            'network_error' => __('Network error occurred.', 'modern-hotel-booking'),
            
            // Email Notifications
            'email_payment_summary'          => __('Payment Summary', 'modern-hotel-booking'),
            'email_total_amount'             => __('Total Amount:', 'modern-hotel-booking'),
            'email_deposit_paid'             => __('Deposit Paid:', 'modern-hotel-booking'),
            'email_deposit_required'         => __('Deposit Required:', 'modern-hotel-booking'),
            'email_remaining_balance'        => __('Remaining Balance:', 'modern-hotel-booking'),
            'email_balance_note'             => __('Due at check-in or as per arrival arrangements.', 'modern-hotel-booking'),
            'email_paid_full'                => __('Paid in Full', 'modern-hotel-booking'),
            'email_non_refundable_bold'      => __('Non-Refundable:', 'modern-hotel-booking'),
            'email_non_refundable_text'      => __('This deposit is non-refundable.', 'modern-hotel-booking'),
            /* translators: %s: cancellation deadline date */
            'email_refund_deadline_format'   => __('Refund Deadline: Cancel by %s to qualify for a deposit refund.', 'modern-hotel-booking'),
            'email_status_pending'           => __('Pending Booking', 'modern-hotel-booking'),
            'email_status_confirmed'         => __('Confirmed Booking', 'modern-hotel-booking'),
            'email_status_cancelled'         => __('Cancelled Booking', 'modern-hotel-booking'),
            'email_status_payment'           => __('Payment Confirmation', 'modern-hotel-booking'),
            'email_label_subject'            => __('Subject', 'modern-hotel-booking'),
            'email_label_message'            => __('Message', 'modern-hotel-booking'),

            // GDPR & Privacy
            'gdpr_title'                     => __('GDPR Compliance & Data Privacy (PRO)', 'modern-hotel-booking'),
            'gdpr_enable_suite'              => __('Enable Pro Privacy Suite', 'modern-hotel-booking'),
            'gdpr_desc_suite'                => __('Enables automated data retention, custom cookie prefixing, and advanced privacy controls.', 'modern-hotel-booking'),
            'gdpr_require_consent'           => __('Require Privacy Consent', 'modern-hotel-booking'),
            'gdpr_desc_consent'              => __('Adds a mandatory checkbox to the booking form for guest consent.', 'modern-hotel-booking'),
            'gdpr_terms_page'                => __('Terms & Conditions Page', 'modern-hotel-booking'),
            'gdpr_terms_desc'                => __('Select the page for your Terms & Conditions. Use the [terms_and_conditions] link in your consent text.', 'modern-hotel-booking'),
            'gdpr_consent_text'              => __('Consent Text', 'modern-hotel-booking'),
            'gdpr_consent_desc'              => __('Text displayed next to the consent checkbox. Use the [privacy_policy] link in your text to link to your policy page.', 'modern-hotel-booking'),
            'gdpr_retention_days'            => __('Automated Data Retention', 'modern-hotel-booking'),
            'gdpr_days'                      => _x('days', 'time duration', 'modern-hotel-booking'),
            'gdpr_retention_desc'            => __('Bookings older than this will be automatically anonymized. Set to 0 to disable.', 'modern-hotel-booking'),
            'gdpr_cookie_prefix'             => __('Cookie Name Prefix', 'modern-hotel-booking'),
            'gdpr_cookie_desc'               => __('Customize the prefix for all frontend cookies (e.g. for selection persistence). Helps with compatibility and auditing.', 'modern-hotel-booking'),
            'gdpr_select_page'               => __('— Select a Page —', 'modern-hotel-booking'),

// License & Pro Labels
            'label_license_free'             => _x('Free', 'license status', 'modern-hotel-booking'),
            'label_license_active_pending'   => __('Active (Pending Verification)', 'modern-hotel-booking'),
            'label_license_expired'          => _x('Expired', 'license status', 'modern-hotel-booking'),
            'label_license_active'           => _x('Active', 'license status', 'modern-hotel-booking'),
            'label_license_failed'           => __('Verification Failed', 'modern-hotel-booking'),
            'label_license_inactive'         => _x('Inactive', 'license status', 'modern-hotel-booking'),
            'label_pro_feature'              => __('Pro Feature', 'modern-hotel-booking'),
            /* translators: %s: feature name (e.g., Payment Gateways, Booking Extras) */
            'msg_pro_upsell'                 => __('The %s feature is part of the Modern Hotel Booking Pro Experience.', 'modern-hotel-booking'),
            'msg_pro_upsell_generic'         => __('This feature is part of the Modern Hotel Booking Pro Experience.', 'modern-hotel-booking'),
            'btn_activate_pro'               => __('Activate Pro Now', 'modern-hotel-booking'),
            
            // System & Core Messages
            'msg_too_many_attempts'          => __('Too many attempts. Please wait a minute before trying again.', 'modern-hotel-booking'),
            'msg_license_empty'              => __('License key cannot be empty.', 'modern-hotel-booking'),
            'msg_license_invalid'            => __('Invalid license key.', 'modern-hotel-booking'),
            'msg_license_server_error'       => __('Could not connect to the license server.', 'modern-hotel-booking'),
            'msg_webhook_unsafe'             => __('The webhook URL is considered unsafe and was blocked.', 'modern-hotel-booking'),
            'msg_webhook_test'               => __('This is a test webhook from your booking system. Hello World!', 'modern-hotel-booking'),
            'msg_unknown_error'              => __('Unknown error', 'modern-hotel-booking'),
            'msg_cache_cleared'              => __('Cache cleared successfully.', 'modern-hotel-booking'),
            'msg_cache_failed'               => __('Failed to clear cache.', 'modern-hotel-booking'),
            'msg_cache_unavailable'          => __('Cache class not available.', 'modern-hotel-booking'),
            'msg_permission_denied'          => __('Permission denied.', 'modern-hotel-booking'),
            /* translators: %1$s: tax label, %2$s: tax rate percentage */
            'label_tax_extras_detail'        => __('%1$s - Extras (%2$s%%)', 'modern-hotel-booking'),
            /* translators: %1$s: tax label, %2$s: tax rate percentage */
            'label_tax_rate_detail'          => __('%1$s (%2$s%%)', 'modern-hotel-booking'),
            /* translators: 1: label, 2: rate percentage, 3: formatted amount */
            'label_tax_accommodation_detail' => __('%1$s - Accommodation (%2$s%%): %3$s', 'modern-hotel-booking'),
            /* translators: 1: label, 2: rate percentage, 3: formatted amount */
            'label_tax_extras_amount_detail' => __('%1$s - Extras (%2$s%%): %3$s', 'modern-hotel-booking'),

            // License Management
            'label_license_warning'          => __('License Warning:', 'modern-hotel-booking'),
            'label_license_error'            => __('License Error:', 'modern-hotel-booking'),
            'label_license_fail_text'        => __('License verification failed. Pro features have been disabled. Please check your license or contact support.', 'modern-hotel-booking'),
            'label_license_expired_notice'   => __('License Expired:', 'modern-hotel-booking'),
            'label_license_expired_text'     => __('Your Pro license has expired. Please renew to continue using Pro features.', 'modern-hotel-booking'),
            /* translators: %s: time remaining in grace period (e.g. "3 days") */
            'msg_license_grace_period'       => __('Could not verify license. Grace period active: %s remaining.', 'modern-hotel-booking'),
            'label_license_key'              => __('License Key', 'modern-hotel-booking'),
            'label_license_status'           => __('License Status', 'modern-hotel-booking'),
            'label_activate_license'         => __('Activate License', 'modern-hotel-booking'),
            'label_deactivate_license'       => __('Deactivate License', 'modern-hotel-booking'),
            'label_check_license'            => __('Check License', 'modern-hotel-booking'),

            // Webhooks
            'label_webhook_url'              => __('Webhook URL', 'modern-hotel-booking'),
            'label_webhook_secret'           => __('Webhook Secret', 'modern-hotel-booking'),
            'label_webhook_status'           => __('Webhook Status', 'modern-hotel-booking'),
            'label_last_received'            => __('Last Received', 'modern-hotel-booking'),
            'msg_webhook_connected'          => __('Webhook connected successfully.', 'modern-hotel-booking'),

            // Cache
            'label_clear_cache'              => __('Clear Cache', 'modern-hotel-booking'),
            'label_cache_status'             => __('Cache Status', 'modern-hotel-booking'),
            'label_cache_size'               => __('Cache Size', 'modern-hotel-booking'),
            'msg_cache_cleared_success'      => __('Cache has been cleared successfully.', 'modern-hotel-booking'),

            // System Status
            'label_system_status'            => __('System Status', 'modern-hotel-booking'),
            'label_php_version'              => __('PHP Version', 'modern-hotel-booking'),
            'label_wp_version'               => __('WordPress Version', 'modern-hotel-booking'),
            'label_server_info'              => __('Server Info', 'modern-hotel-booking'),
            'label_memory_limit'             => __('Memory Limit', 'modern-hotel-booking'),

            // UI Label Overrides
            'label_override_search_rooms'    => __('Search Rooms Button', 'modern-hotel-booking'),
            'label_override_check_in'       => __('Check-in Label', 'modern-hotel-booking'),
            'label_override_check_out'      => __('Check-out Label', 'modern-hotel-booking'),
            'label_override_guests'          => __('Guests Label', 'modern-hotel-booking'),
            'label_override_children'        => __('Children Label', 'modern-hotel-booking'),
            'label_override_child_ages'      => __('Child Ages Label', 'modern-hotel-booking'),
            /* translators: %d: child number (e.g. 1, 2, 3) */
            'label_override_child_n_age'     => __('Child %d Age Label', 'modern-hotel-booking'),
            'label_override_select_dates'    => __('Select Dates Label', 'modern-hotel-booking'),
            'label_override_dates_selected'  => __('Dates Selected Message', 'modern-hotel-booking'),
            'label_override_your_selection'  => __('Your Selection Label', 'modern-hotel-booking'),
            'label_override_continue'        => __('Continue to Booking Button', 'modern-hotel-booking'),
            'label_override_avail_error'     => __('Dates Not Available Error', 'modern-hotel-booking'),
            'label_override_stay_dates'      => __('Stay Dates Label', 'modern-hotel-booking'),
            'label_override_guide_checkin'   => __('Select Check-in Guide', 'modern-hotel-booking'),
            'label_override_guide_checkout'  => __('Select Check-out Guide', 'modern-hotel-booking'),
            'label_override_cal_no_id'       => __('Calendar No ID Error', 'modern-hotel-booking'),
            'label_override_cal_config'      => __('Calendar Config Error', 'modern-hotel-booking'),

            // Settings Label Descriptions & Groups
            'settings_group_search'          => __('Search & Calendar', 'modern-hotel-booking'),
            'settings_group_results'         => __('Results & Pricing', 'modern-hotel-booking'),
            'settings_group_booking'         => __('Booking Form', 'modern-hotel-booking'),
            'settings_group_confirmation'    => __('Confirmation Messages', 'modern-hotel-booking'),
            'settings_group_payments'        => __('Payments', 'modern-hotel-booking'),
            'settings_group_tax'             => __('Tax & Summary', 'modern-hotel-booking'),
            'settings_group_amenities'       => _x('Amenities', 'state of entity', 'modern-hotel-booking'),
            'label_amenity_wifi'             => __('Free WiFi', 'modern-hotel-booking'),
            'label_amenity_ac'               => __('Air Conditioning', 'modern-hotel-booking'),
            'label_amenity_tv'               => __('Smart TV', 'modern-hotel-booking'),
            'label_amenity_breakfast'        => __('Breakfast Included', 'modern-hotel-booking'),
            'label_amenity_pool'             => __('Pool View', 'modern-hotel-booking'),
            'label_amenity_minibar'          => __('Mini Bar', 'modern-hotel-booking'),
            'label_amenity_safe'             => __('In-room Safe', 'modern-hotel-booking'),
            'label_amenity_parking'          => __('Free Parking', 'modern-hotel-booking'),
            'label_amenity_balcony'          => __('Private Balcony', 'modern-hotel-booking'),

            /* translators: 1: placeholder example %s, 2: placeholder example %d */
            'settings_labels_desc'           => __('Leave a field empty to use the default English text for that language. Use <code>%1$s</code> or <code>%2$d</code> as placeholders where they appear in the default text.', 'modern-hotel-booking'),

            'label_desc_available_rooms'     => __('Available Rooms Message', 'modern-hotel-booking'),
            'label_desc_no_rooms'            => __('No Rooms Found Message', 'modern-hotel-booking'),
            'label_desc_per_night'           => __('Per Night Text', 'modern-hotel-booking'),
            'label_desc_total_nights'        => __('Total Nights Price Summary', 'modern-hotel-booking'),
            'label_desc_max_guests'          => __('Max Guests Text', 'modern-hotel-booking'),
            'label_desc_loading'             => __('Loading Message', 'modern-hotel-booking'),
            'label_desc_to'                  => __('To Separator', 'modern-hotel-booking'),
            'label_desc_book_now'            => __('Book Now Button', 'modern-hotel-booking'),
            'label_desc_processing'          => __('Processing Button Text', 'modern-hotel-booking'),

            'label_desc_complete_booking'    => __('Complete Booking Title', 'modern-hotel-booking'),
            'label_desc_total'               => __('Total Price Label', 'modern-hotel-booking'),
            'label_desc_name'                => __('Full Name Label', 'modern-hotel-booking'),
            'label_desc_email'               => __('Email Address Label', 'modern-hotel-booking'),
            'label_desc_phone'               => __('Phone Number Label', 'modern-hotel-booking'),
            'label_desc_special_requests'    => __('Special Requests Label', 'modern-hotel-booking'),
            'label_desc_secure_payment'      => __('Secure Payment Message', 'modern-hotel-booking'),
            'label_desc_security_error'      => __('Security Error Message', 'modern-hotel-booking'),
            'label_desc_rate_limit_error'    => __('Rate Limit Error Message', 'modern-hotel-booking'),
            'label_desc_spam_honeypot'       => __('Spam Honeypot Label', 'modern-hotel-booking'),
            'label_desc_confirm_booking'     => __('Confirm Booking Button', 'modern-hotel-booking'),
            'label_desc_pay_confirm'         => __('Pay & Confirm Button', 'modern-hotel-booking'),
            'label_desc_confirm_request'     => __('Confirm Request Message', 'modern-hotel-booking'),
            'label_desc_room_not_found'      => __('Room Not Found Error', 'modern-hotel-booking'),
            'label_desc_name_too_long'       => __('Name Too Long Error', 'modern-hotel-booking'),
            'label_desc_phone_too_long'      => __('Phone Too Long Error', 'modern-hotel-booking'),
            'label_desc_max_children_error'  => __('Max Children Error', 'modern-hotel-booking'),
            'label_desc_max_adults_error'    => __('Max Adults Error', 'modern-hotel-booking'),
            'label_desc_price_calc_error'    => __('Price Calculation Error', 'modern-hotel-booking'),
            'label_desc_fill_all_fields'     => __('Missing Fields Error', 'modern-hotel-booking'),
            'label_desc_field_required'      => __('Required Field Error', 'modern-hotel-booking'),
            'label_desc_spam_detected'       => __('Spam Detected Error', 'modern-hotel-booking'),
            'label_desc_already_booked'      => __('Room Already Booked Error', 'modern-hotel-booking'),
            'label_desc_invalid_email'       => __('Invalid Email Error', 'modern-hotel-booking'),

            'label_desc_booking_confirmed'   => __('Booking Success Message', 'modern-hotel-booking'),
            'label_desc_confirmation_sent'   => __('Email Sent Message', 'modern-hotel-booking'),
            'label_desc_booking_received'    => __('Request Received Title', 'modern-hotel-booking'),
            'label_desc_booking_received_detail' => __('Request Received Detail', 'modern-hotel-booking'),
            'label_desc_arrival_msg'         => __('Pay on Arrival Message', 'modern-hotel-booking'),
            'label_desc_gdpr_required'       => __('GDPR Required Warning', 'modern-hotel-booking'),
            'label_desc_privacy_policy'      => __('Privacy Policy Link Text', 'modern-hotel-booking'),
            'label_desc_terms_conditions'    => __('Terms & Conditions Link Text', 'modern-hotel-booking'),
            'label_desc_paypal_required'     => __('PayPal Required Warning', 'modern-hotel-booking'),
            'label_desc_payment_success_email' => __('Payment Success Confirmation', 'modern-hotel-booking'),
            'label_desc_booking_arrival_email' => __('Booking arrival confirmation', 'modern-hotel-booking'),
            'label_desc_payment_failed_detail' => __('Payment Failed Detail', 'modern-hotel-booking'),
            'label_desc_booking_received_pending' => __('Booking Received Pending Detail', 'modern-hotel-booking'),
            'label_desc_msg_booking_confirmed_received' => __('Booking Pending Title', 'modern-hotel-booking'),
            /* translators: %s: customer email address */
            'label_desc_msg_pending_sent_to' => __('Pending Email Sent Notice (%s)', 'modern-hotel-booking'),
            /* translators: %s: customer email address */
            'label_desc_msg_confirmation_sent_to' => __('Confirmation Email Sent Notice (%s)', 'modern-hotel-booking'),
            'label_desc_label_nights_count' => __('Nights Count (Plural)', 'modern-hotel-booking'),
            'label_desc_label_nights_count_single' => __('Nights Count (Single)', 'modern-hotel-booking'),
            'label_desc_label_check_in_from' => __('Check-in Time Label', 'modern-hotel-booking'),
            'label_desc_label_check_out_by' => __('Check-out Time Label', 'modern-hotel-booking'),
            'label_desc_label_room_number' => __('Room Number Label', 'modern-hotel-booking'),
            'label_desc_reservation' => __('Reservation Header', 'modern-hotel-booking'),
            'label_desc_stay_details' => __('Stay Details Header', 'modern-hotel-booking'),
            'label_desc_room_details' => __('Room Details Header', 'modern-hotel-booking'),
            'label_desc_guest_details' => __('Guest Details Header', 'modern-hotel-booking'),
            'label_desc_payment_details' => __('Payment Details Header', 'modern-hotel-booking'),
            'label_desc_amenities' => __('Amenities Header', 'modern-hotel-booking'),
            'label_desc_check_in_time' => __('Check-in Time Label', 'modern-hotel-booking'),
            'label_desc_check_out_time' => __('Check-out Time Label', 'modern-hotel-booking'),
            'label_desc_checkin_only' => __('Check-in Only Restriction', 'modern-hotel-booking'),
            'label_desc_checkout_only' => __('Check-out Only Restriction', 'modern-hotel-booking'),
            'label_desc_night' => __('Night (Singular)', 'modern-hotel-booking'),
            'label_desc_nights' => __('Nights (Plural)', 'modern-hotel-booking'),
            'label_desc_bank_acc_name' => __('Bank Account Holder', 'modern-hotel-booking'),
            'label_desc_bank_iban' => __('Bank IBAN / Account #', 'modern-hotel-booking'),
            'label_desc_bank_swift' => __('Bank SWIFT/BIC', 'modern-hotel-booking'),
            'label_desc_revolut_tag' => __('Revolut Tag', 'modern-hotel-booking'),
            'label_desc_reference' => __('Payment Reference Label', 'modern-hotel-booking'),
            'label_desc_child' => __('Child (Singular)', 'modern-hotel-booking'),
            /* translators: %d: number of guests */
            'label_desc_guests_count' => __('Guests Count Label (%d)', 'modern-hotel-booking'),
            /* translators: %d: number of children */
            'label_desc_children_count' => __('Children Count Label (%d)', 'modern-hotel-booking'),
            /* translators: %d: child number */
            'label_desc_child_n_age' => __('Child Age Label (%d)', 'modern-hotel-booking'),
            'label_desc_booking_id' => __('Booking ID Label', 'modern-hotel-booking'),
            'label_desc_reference_number' => __('Reference Number Label', 'modern-hotel-booking'),
            'label_desc_total_with_tax' => __('Total with Tax Label', 'modern-hotel-booking'),
            'label_desc_btn_view_room' => __('View Room Details Button', 'modern-hotel-booking'),
            'label_desc_btn_select_room' => __('Select This Room Button', 'modern-hotel-booking'),
            'label_desc_back_to_rooms' => __('Back to Rooms Button', 'modern-hotel-booking'),
            'label_desc_adults' => __('Adults (Plural)', 'modern-hotel-booking'),
            'label_desc_balance_due' => __('Balance Due Label', 'modern-hotel-booking'),
            'label_desc_deposit_paid' => __('Deposit Paid Label', 'modern-hotel-booking'),
            'label_desc_total_starting_from' => __('Starting From Label', 'modern-hotel-booking'),

            'label_desc_payment_method'      => __('Payment Method Label', 'modern-hotel-booking'),
            'label_desc_pay_arrival'         => __('Pay on Arrival Option', 'modern-hotel-booking'),
            'label_desc_credit_card'         => __('Credit Card Option', 'modern-hotel-booking'),
            'label_desc_paypal'              => __('PayPal Option', 'modern-hotel-booking'),
            'label_desc_payment_status'      => __('Payment Status Label', 'modern-hotel-booking'),
            'label_desc_paid'                => __('Paid Badge Text', 'modern-hotel-booking'),
            'label_desc_amount_paid'         => __('Amount Paid Label', 'modern-hotel-booking'),
            'label_desc_transaction_id'      => __('Transaction ID Label', 'modern-hotel-booking'),
            'label_desc_failed'              => __('Failed Badge Text', 'modern-hotel-booking'),
            'label_desc_payment_failed'      => __('Payment Failed Title', 'modern-hotel-booking'),
            'label_desc_dates_no_longer_available' => __('Dates Taken Error', 'modern-hotel-booking'),
            'label_desc_invalid_booking_calc' => __('Invalid Booking Calc Error', 'modern-hotel-booking'),
            'label_desc_stripe_not_configured' => __('Stripe Not Configured Error', 'modern-hotel-booking'),
            'label_desc_paypal_not_configured' => __('PayPal Not Configured Error', 'modern-hotel-booking'),
            'label_desc_paypal_connection_error' => __('PayPal Connection Error', 'modern-hotel-booking'),
            'label_desc_paypal_auth_failed'      => __('PayPal Auth Error', 'modern-hotel-booking'),
            'label_desc_paypal_order_create_error' => __('PayPal Order Error', 'modern-hotel-booking'),
            'label_desc_paypal_currency_unsupported' => __('PayPal Currency Error', 'modern-hotel-booking'),
            'label_desc_paypal_generic_error'    => __('PayPal Generic Error', 'modern-hotel-booking'),
            'label_desc_missing_order_id'        => __('Missing Order ID Error', 'modern-hotel-booking'),
            'label_desc_paypal_capture_error'    => __('PayPal Capture Error', 'modern-hotel-booking'),
            'label_desc_payment_already_processed' => __('Payment Already Processed Error', 'modern-hotel-booking'),
            'label_desc_payment_declined_paypal' => __('Payment Declined Error', 'modern-hotel-booking'),
            'label_desc_stripe_intent_missing'   => __('Stripe Intent Missing Error', 'modern-hotel-booking'),
            'label_desc_paypal_id_missing'       => __('PayPal ID Missing Error', 'modern-hotel-booking'),
            'label_desc_payment_required'        => __('Payment Required Error', 'modern-hotel-booking'),
            'label_desc_rest_pro_error'          => __('REST Pro Access Error', 'modern-hotel-booking'),
            'label_desc_api_rate_limit'          => __('API Rate Limit Error', 'modern-hotel-booking'),
            /* translators: %d: minimum number of nights */
            'api_err_min_stay'                   => __('Minimum stay is %d nights.', 'modern-hotel-booking'),
            /* translators: %d: maximum number of nights */
            'api_err_max_stay'                   => __('Maximum stay is %d nights.', 'modern-hotel-booking'),
            /* translators: %s: earliest valid checkout date (e.g. "May 29") */
            'min_stay_hint'                      => __('Please select %s or later as your check-out date.', 'modern-hotel-booking'),
            /* translators: %s: latest valid checkout date (e.g. "Jun 2") */
            'max_stay_hint'                      => __('Please select %s or earlier as your check-out date.', 'modern-hotel-booking'),
            /* translators: %d: minimum number of nights (shown in hover tooltip) */
            'tooltip_min_hint'                   => __('⚠ Min stay: %d nights', 'modern-hotel-booking'),
            /* translators: %d: maximum number of nights (shown in hover tooltip) */
            'tooltip_max_hint'                   => __('⚠ Max stay: %d nights', 'modern-hotel-booking'),
            'api_err_generic'                    => __('Something went wrong. Please try again.', 'modern-hotel-booking'),
            'api_err_nonce'                      => __('Security check failed. Please refresh the page and try again.', 'modern-hotel-booking'),
            'api_err_network'                    => __('A network error occurred. Please check your connection and try again.', 'modern-hotel-booking'),
            'label_desc_payment_confirmation'    => __('Email: Payment Confirmation Title', 'modern-hotel-booking'),
            'label_desc_payment_info'            => __('Email: Payment Info Title', 'modern-hotel-booking'),
            'label_desc_pay_on_arrival_email'    => __('Email: Pay on Arrival Detail', 'modern-hotel-booking'),
            'label_desc_amount_due'              => __('Email: Amount Due Label', 'modern-hotel-booking'),
            'label_desc_payment_date'            => __('Email: Payment Date Label', 'modern-hotel-booking'),
            'label_desc_paypal_order_failed'     => __('PayPal Order Creation Failed', 'modern-hotel-booking'),
            'label_desc_security_verification_failed' => __('Security Verification Failed', 'modern-hotel-booking'),
            'label_desc_paypal_client_id_missing' => __('PayPal Client ID Missing', 'modern-hotel-booking'),
            'label_desc_paypal_secret_missing'    => __('PayPal Secret Missing', 'modern-hotel-booking'),
            'label_desc_api_not_configured'       => __('API Key Not Configured', 'modern-hotel-booking'),
            'label_desc_invalid_api_key'          => __('Invalid API Key', 'modern-hotel-booking'),
            'label_desc_webhook_sig_required'     => __('Webhook Signature Required', 'modern-hotel-booking'),
            'label_desc_stripe_webhook_secret_missing' => __('Stripe Webhook Secret Missing', 'modern-hotel-booking'),
            'label_desc_invalid_stripe_sig_format' => __('Invalid Stripe Signature Format', 'modern-hotel-booking'),
            'label_desc_webhook_expired'          => __('Webhook Expired', 'modern-hotel-booking'),
            'label_desc_invalid_stripe_sig'       => __('Invalid Stripe Signature', 'modern-hotel-booking'),
            'label_desc_missing_paypal_headers'   => __('Missing PayPal Headers', 'modern-hotel-booking'),
            'label_desc_invalid_customer'         => __('Invalid Customer Data', 'modern-hotel-booking'),
            'label_desc_invalid_dates'            => __('Invalid Booking Dates', 'modern-hotel-booking'),
            'label_desc_booking_failed'           => __('Booking Creation Failed', 'modern-hotel-booking'),
            'label_desc_permission_denied'        => __('Permission Denied', 'modern-hotel-booking'),
            'label_desc_stripe_pk_missing'        => __('Stripe PK Missing', 'modern-hotel-booking'),
            'label_desc_stripe_sk_missing'        => __('Stripe SK Missing', 'modern-hotel-booking'),
            'label_desc_stripe_invalid_pk_format' => __('Invalid Stripe PK Format', 'modern-hotel-booking'),
            'label_desc_credentials_spaces'       => __('Credentials Space Warning', 'modern-hotel-booking'),
            'label_desc_mode_mismatch'            => __('Sandbox/Live Mismatch Warning', 'modern-hotel-booking'),
            'label_desc_credentials_expired'      => __('Credentials Expired Warning', 'modern-hotel-booking'),
            'label_desc_creds_valid_env'          => __('PayPal Success Message', 'modern-hotel-booking'),
            'label_desc_stripe_creds_valid'       => __('Stripe Success Message', 'modern-hotel-booking'),
            'label_desc_connection_failed'        => __('Connection Failed Error', 'modern-hotel-booking'),
            'label_desc_auth_failed_env'          => __('Authentication Failed Error', 'modern-hotel-booking'),
            'label_desc_common_causes'            => __('Common Causes Title', 'modern-hotel-booking'),
            'label_desc_stripe_generic_error'     => __('Stripe Generic Error', 'modern-hotel-booking'),
            'label_desc_per_adult'                => __('Per Adult Text', 'modern-hotel-booking'),
            'label_desc_per_child'                => __('Per Child Text', 'modern-hotel-booking'),
            'label_desc_per_person_per_night'     => __('Per Person / Night Text', 'modern-hotel-booking'),
            'label_desc_per_adult_per_night'      => __('Per Adult / Night Text', 'modern-hotel-booking'),
            'label_desc_per_child_per_night'      => __('Per Child / Night Text', 'modern-hotel-booking'),

            'label_desc_booking_summary'          => __('Booking Summary Title', 'modern-hotel-booking'),
            'label_desc_accommodation'            => __('Accommodation Row Label', 'modern-hotel-booking'),
            'label_desc_extras_item'              => __('Extras Row Label', 'modern-hotel-booking'),
            'label_desc_tax_breakdown'            => __('Tax Breakdown Label', 'modern-hotel-booking'),
            'label_desc_tax_total'                => __('Tax Total Label', 'modern-hotel-booking'),
            'label_desc_tax_registration'         => __('Tax Registration Label', 'modern-hotel-booking'),
            'label_desc_includes_tax'             => __('Includes Tax Suffix', 'modern-hotel-booking'),
            'label_desc_price_includes_tax'       => __('Price Includes Tax Note', 'modern-hotel-booking'),
            'label_desc_tax_added_at_checkout'    => __('Tax Added at Checkout Note', 'modern-hotel-booking'),
            'label_desc_subtotal'                 => __('Subtotal Label', 'modern-hotel-booking'),
            'label_desc_room'                     => __('Room Column Label', 'modern-hotel-booking'),
            'label_desc_extras'                   => __('Extras Column Label', 'modern-hotel-booking'),
            'label_desc_item'                     => __('Item Column Label', 'modern-hotel-booking'),
            'label_desc_amount'                   => __('Amount Column Label', 'modern-hotel-booking'),
            'label_desc_tax_accommodation'        => __('Tax Accommodation Rate Label', 'modern-hotel-booking'),
            'label_desc_tax_extras'               => __('Tax Extras Rate Label', 'modern-hotel-booking'),
            'label_desc_tax_rate'                 => __('Generic Tax Rate Label', 'modern-hotel-booking'),
            /* translators: %s: tax label (e.g. "VAT") */
            'label_desc_tax_note_includes'        => __('Tax Note (includes %s)', 'modern-hotel-booking'),
            /* translators: %s: tax label (e.g. "VAT") */
            'label_desc_tax_note_plus'            => __('Tax Note (plus %s)', 'modern-hotel-booking'),
            /* translators: 1: tax label, 2: accommodation tax rate percentage, 3: extras tax rate percentage */
            'label_desc_tax_note_includes_multi'  => __('Tax Note Multi (includes %1$s: %2$s%% / %3$s%%)', 'modern-hotel-booking'),
            /* translators: 1: tax label, 2: accommodation tax rate percentage, 3: extras tax rate percentage */
            'label_desc_tax_note_plus_multi'      => __('Tax Note Multi (plus %1$s: %2$s%% / %3$s%%)', 'modern-hotel-booking'),

            'amenity_free_wifi'               => __('Free WiFi', 'modern-hotel-booking'),
            'amenity_air_conditioning'        => __('Air Conditioning', 'modern-hotel-booking'),
            'amenity_smart_tv'                => __('Smart TV', 'modern-hotel-booking'),
            'amenity_breakfast_included'      => __('Breakfast Included', 'modern-hotel-booking'),
            'amenity_pool_view'               => __('Pool View', 'modern-hotel-booking'),

            // Booking Messages
            'booking_msg_manual_admin'       => __('Manual booking added by admin.', 'modern-hotel-booking'),

            // Custom Fields UI
            'cf_field_id'                    => __('Field ID (slug)', 'modern-hotel-booking'),
            'cf_type'                        => _x('Type', 'setting category', 'modern-hotel-booking'),
            'cf_type_text'                   => _x('Text', 'input type', 'modern-hotel-booking'),
            'cf_type_number'                 => __('Number', 'modern-hotel-booking'),
            'cf_type_textarea'               => __('Textarea', 'modern-hotel-booking'),
            'cf_label_multilingual'          => __('Label (Multilingual)', 'modern-hotel-booking'),
            'cf_required'                    => __('Required Field', 'modern-hotel-booking'),
            'cf_btn_add'                     => __('+ Add New Guest Field', 'modern-hotel-booking'),
            'cf_desc'                        => __('Define extra fields for your booking form. Labels support multilingual tags.', 'modern-hotel-booking'),
            
            'theme_reset_success' => __('Theme reset to default successfully.', 'modern-hotel-booking'),
            'theme_active' => __('Active Theme', 'modern-hotel-booking'),
            'theme_custom_colors' => __('Custom Branding', 'modern-hotel-booking'),
            
            /* translators: %s: plugin version number (e.g. "2.1.0") */
            'version_updates' => __('Version %s Updates', 'modern-hotel-booking'),
            'view_changelog' => __('View Full Changelog →', 'modern-hotel-booking'),
            'changelog_readme' => __('Please check the plugin readme.txt for the latest updates.', 'modern-hotel-booking'),
            
            'cat_payments' => __('Payment Processing', 'modern-hotel-booking'),
            'cat_tax' => __('Tax Management', 'modern-hotel-booking'),
            'cat_booking' => __('Booking Management', 'modern-hotel-booking'),
            'cat_compliance' => __('Compliance & UX', 'modern-hotel-booking'),
            'cat_dev' => __('Developer Platform', 'modern-hotel-booking'),
            
            'configure' => __('Configure', 'modern-hotel-booking'),
            'btn_configure' => __('Configure', 'modern-hotel-booking'),
            'view' => _x('View', 'action', 'modern-hotel-booking'),
            'btn_view' => _x('View', 'action', 'modern-hotel-booking'),
            'edit' => _x('Edit', 'action', 'modern-hotel-booking'),
            'delete' => __('Delete', 'modern-hotel-booking'),
            'save' => _x('Save', 'action', 'modern-hotel-booking'),
            'cancel' => __('Cancel', 'modern-hotel-booking'),
            'add_new' => __('Add New', 'modern-hotel-booking'),
            'update' => __('Update', 'modern-hotel-booking'),
            'copy' => _x('Copy', 'action', 'modern-hotel-booking'),
            'duplicate' => __('Duplicate', 'modern-hotel-booking'),
            'search' => __('Search', 'modern-hotel-booking'),
            'filter' => __('Filter', 'modern-hotel-booking'),
            'reset' => _x('Reset', 'action', 'modern-hotel-booking'),
            'all' => _x('All', 'selection', 'modern-hotel-booking'),
            'none' => _x('None', 'selection', 'modern-hotel-booking'),
            'active' => _x('Active', 'status', 'modern-hotel-booking'),
            'inactive' => _x('Inactive', 'status', 'modern-hotel-booking'),
            'enabled' => _x('Enabled', 'status', 'modern-hotel-booking'),
            'disabled' => _x('Disabled', 'status', 'modern-hotel-booking'),
            'yes' => _x('Yes', 'confirmation', 'modern-hotel-booking'),
            'no' => _x('No', 'confirmation', 'modern-hotel-booking'),
            'on' => _x('On', 'toggle', 'modern-hotel-booking'),
            'off' => _x('Off', 'toggle', 'modern-hotel-booking'),
            
            /* Date & Time Names */
            'day_monday' => __('Monday', 'modern-hotel-booking'),
            'day_tuesday' => __('Tuesday', 'modern-hotel-booking'),
            'day_wednesday' => __('Wednesday', 'modern-hotel-booking'),
            'day_thursday' => __('Thursday', 'modern-hotel-booking'),
            'day_friday' => __('Friday', 'modern-hotel-booking'),
            'day_saturday' => __('Saturday', 'modern-hotel-booking'),
            'day_sunday' => __('Sunday', 'modern-hotel-booking'),
            'mon_january'   => _x('January', 'month name', 'modern-hotel-booking'),
            'mon_february'  => _x('February', 'month name', 'modern-hotel-booking'),
            'mon_march'     => _x('March', 'month name', 'modern-hotel-booking'),
            'mon_april'     => _x('April', 'month name', 'modern-hotel-booking'),
            'mon_may'       => _x('May', 'month name', 'modern-hotel-booking'),
            'mon_june'      => _x('June', 'month name', 'modern-hotel-booking'),
            'mon_july'      => _x('July', 'month name', 'modern-hotel-booking'),
            'mon_august'    => _x('August', 'month name', 'modern-hotel-booking'),
            'mon_september' => _x('September', 'month name', 'modern-hotel-booking'),
            'mon_october'   => _x('October', 'month name', 'modern-hotel-booking'),
            'mon_november'  => _x('November', 'month name', 'modern-hotel-booking'),
            'mon_december'  => _x('December', 'month name', 'modern-hotel-booking'),
            
            /* Admin Column & List Labels */
            'col_name' => _x('Name', 'table column header', 'modern-hotel-booking'),
            'col_email' => _x('Email', 'table column header', 'modern-hotel-booking'),
            'col_phone' => _x('Phone', 'table column header', 'modern-hotel-booking'),
            'col_method' => _x('Method', 'table column header', 'modern-hotel-booking'),
            'col_room' => _x('Room', 'table column header', 'modern-hotel-booking'),
            'label_item' => _x('Item', 'table column header', 'modern-hotel-booking'),
            'label_tax' => _x('Tax', 'table column header', 'modern-hotel-booking'),
            'label_plugin_name' => __('Modern Hotel Booking', 'modern-hotel-booking'),
            'col_type' => _x('Type', 'table column header', 'modern-hotel-booking'),
            'col_check_in' => _x('Check-in', 'table column header', 'modern-hotel-booking'),
            'col_check_out' => _x('Check-out', 'table column header', 'modern-hotel-booking'),
            'col_nights' => _x('Nights', 'table column header', 'modern-hotel-booking'),
            'col_guests' => _x('Guests', 'table column header', 'modern-hotel-booking'),
            'col_created' => _x('Created', 'table column header', 'modern-hotel-booking'),

/* General Help & Hints */
            'help_title' => __('Support & Documentation', 'modern-hotel-booking'),
            'help_desc' => __('Need help with your setup? Check our resources below.', 'modern-hotel-booking'),
            'help_docs' => __('Official Documentation', 'modern-hotel-booking'),
            'help_support' => __('Open Support Ticket', 'modern-hotel-booking'),
            'help_version' => __('Version', 'modern-hotel-booking'),
            'help_license' => __('License', 'modern-hotel-booking'),
            'help_system' => __('System Stats', 'modern-hotel-booking'),

            /* Settings Support & Messages */
            /* translators: 1: placeholder example %s, 2: placeholder example %d */
            'settings_label_search_desc' => __('Leave a field empty to use the default English text for that language. Use %1$s or %2$d as placeholders where they appear in the default text.', 'modern-hotel-booking'),
            'settings_msg_multilingual_saved' => __('Multilingual settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_gdpr_saved' => __('GDPR settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_general_saved' => __('General settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_themes_saved' => __('Theme settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_invalid_currency' => __('Invalid currency code.', 'modern-hotel-booking'),
            'settings_msg_permissions' => __('Insufficient permissions.', 'modern-hotel-booking'),
            'settings_msg_pricing_saved' => __('Pricing settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_amenities_saved' => __('Amenities settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_payments_saved' => __('Payment settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_api_saved' => __('API settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_business_saved' => __('Business settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_tax_saved' => __('Tax settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_performance_saved' => __('Performance settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_license_saved' => __('License settings saved successfully.', 'modern-hotel-booking'),
            'settings_msg_deposits_saved' => __('Deposits settings saved successfully.', 'modern-hotel-booking'),

            /* Global Stay Limits (Pro) */
            'settings_label_global_min_stay' => __('Global Stay Limits', 'modern-hotel-booking'),
            'settings_label_global_min_nights' => __('Min Nights', 'modern-hotel-booking'),
            'settings_label_global_max_nights' => __('Max Nights', 'modern-hotel-booking'),
            'settings_desc_global_stay'      => __('Default minimum and maximum stay length in nights. Enter 0 to disable. Per-room and per-type calendar overrides always take priority. Use Admin Calendar → Bulk Update for seasonal rules (e.g. 7-night minimum in August).', 'modern-hotel-booking'),

            /* Calendar tooltip blocked-date reasons */
            'calendar_reason_booked'      => __('Booked', 'modern-hotel-booking'),
            'calendar_reason_manual'      => __('Unavailable', 'modern-hotel-booking'),
            'calendar_reason_maintenance' => __('Maintenance', 'modern-hotel-booking'),

            /* Admin setup guide */
            'setup_guide_title'           => __('Shortcode Setup Guide', 'modern-hotel-booking'),
            'setup_guide_single_room'     => __('Single-room property: Use [mhbo_room_calendar room_id="X"] to display availability for a specific room. Replace X with the Room ID from the table below.', 'modern-hotel-booking'),
            'setup_guide_multi_room'      => __('Multi-room property: Use [modern_hotel_booking] to display a search form that shows availability across all room types.', 'modern-hotel-booking'),
            'setup_guide_your_rooms'      => __('Your Configured Rooms', 'modern-hotel-booking'),
            'setup_guide_col_id'          => __('Room ID', 'modern-hotel-booking'),
            'setup_guide_col_name'        => __('Room Name', 'modern-hotel-booking'),
            'setup_guide_col_type'        => __('Room Type', 'modern-hotel-booking'),
            'setup_guide_col_shortcode'   => __('Shortcode', 'modern-hotel-booking'),
            'setup_guide_no_rooms'        => __('No rooms configured yet. Add rooms via the Rooms menu.', 'modern-hotel-booking'),

            /* Button Labels */
            'settings_btn_save_payments'         => __('Save Payment Settings', 'modern-hotel-booking'),
            'settings_btn_save_webhooks'         => __('Save Webhook Settings', 'modern-hotel-booking'),
            'settings_btn_save_themes'           => __('Save Theme Settings', 'modern-hotel-booking'),
            'settings_btn_save_tax'              => __('Save Tax Settings', 'modern-hotel-booking'),
            'settings_btn_save_general'          => __('Save Settings', 'modern-hotel-booking'),

            /* Settings Tabs & Navigation */
            'tab_api' => __('REST API', 'modern-hotel-booking'),
            'tab_help' => __('Help & Support', 'modern-hotel-booking'),

/* Feature Dashboard & Upsell */
            'pro_upsell_title' => __('Pro Feature', 'modern-hotel-booking'),
            'pro_upsell_full_desc' => __('Unlock this feature and many more with a Pro license. Get access to payment processing, VAT/TAX management, analytics, and more.', 'modern-hotel-booking'),
            'pro_upsell_upgrade' => __('Upgrade to Pro', 'modern-hotel-booking'),
            'pro_upsell_enter_key' => __('Enter License Key', 'modern-hotel-booking'),
            'pro_upsell_support' => __('Priority Support', 'modern-hotel-booking'),
            'pro_upsell_updates' => __('Priority Updates', 'modern-hotel-booking'),
            'pro_upsell_all' => __('All Premium Features', 'modern-hotel-booking'),
            'pro_not_available' => __('Pro features are not available.', 'modern-hotel-booking'),
            'qa_title' => __('Quick Actions', 'modern-hotel-booking'),
            'qa_gateways' => __('Configure Payment Gateways', 'modern-hotel-booking'),
            'qa_tax' => __('Set Up Tax Settings', 'modern-hotel-booking'),
            'qa_analytics' => __('View Analytics', 'modern-hotel-booking'),
            'qa_ical' => __('Manage iCal Feeds', 'modern-hotel-booking'),

            /* Feature Categories */
            'feat_cat_payments' => __('Payment Processing', 'modern-hotel-booking'),
            'feat_cat_tax' => __('Tax Management', 'modern-hotel-booking'),
            'feat_cat_booking' => __('Booking Management', 'modern-hotel-booking'),
            'feat_cat_compliance' => __('Compliance & UX', 'modern-hotel-booking'),
            'feat_cat_dev' => __('Developer Platform', 'modern-hotel-booking'),
            'feat_popular' => __('Popular', 'modern-hotel-booking'),
            'feat_new' => _x('New', 'status label', 'modern-hotel-booking'),
            'feat_coming_soon' => __('Coming Soon', 'modern-hotel-booking'),
            'feat_updated' => __('Updated', 'modern-hotel-booking'),

            /* Feature Cards */
            'feat_stripe_title' => __('Stripe Integration', 'modern-hotel-booking'),
            'feat_stripe_desc' => __('Accept payments with Stripe including Apple Pay, Google Pay, and link support.', 'modern-hotel-booking'),
            'feat_paypal_title' => __('PayPal Integration', 'modern-hotel-booking'),
            'feat_paypal_desc' => __('Accept PayPal payments with automatic webhook verification and status updates.', 'modern-hotel-booking'),
            'feat_invoicing_title' => __('Invoicing System', 'modern-hotel-booking'),
            'feat_invoicing_desc' => __('Professional PDF invoices generated automatically.', 'modern-hotel-booking'),
            'feat_partial_title' => __('Partial Payments', 'modern-hotel-booking'),
            'feat_partial_desc' => __('Accept deposits or split payments. Complete control over payment thresholds.', 'modern-hotel-booking'),

            // API & Webhooks
            'col_api_key'                        => _x('API Key', 'table column header', 'modern-hotel-booking'),
            'api_btn_generate'                   => __('Generate New Key', 'modern-hotel-booking'),
            'api_label_webhook_url'              => __('Webhook URL', 'modern-hotel-booking'),
            'api_label_webhook_secret'           => __('Webhook Secret (HMAC)', 'modern-hotel-booking'),
            'api_placeholder_webhook_secret'     => __('Enter secret for validation', 'modern-hotel-booking'),
            'api_label_logging'                  => __('Debug Logging', 'modern-hotel-booking'),
            'business_info_title'  => __('Business Information', 'modern-hotel-booking'),
            'business_info_desc'   => __('Configure your company details, contact info, and payment methods. All fields are optional.', 'modern-hotel-booking'),
            'business_info_tab_company'    => __('Company', 'modern-hotel-booking'),
            'business_info_tab_whatsapp'   => __('WhatsApp', 'modern-hotel-booking'),
            'business_info_tab_banking'    => __('Bank Transfer', 'modern-hotel-booking'),
            'business_info_tab_revolut'    => __('Revolut', 'modern-hotel-booking'),
            'business_info_tab_shortcodes' => __('Shortcodes & Blocks', 'modern-hotel-booking'),

            'business_label_company_name'    => __('Company Name', 'modern-hotel-booking'),
            'business_label_contact_name'    => __('Contact Name', 'modern-hotel-booking'),
            'business_label_address_1'       => __('Address Line 1', 'modern-hotel-booking'),
            'business_label_address_2'       => __('Address Line 2', 'modern-hotel-booking'),
            'business_label_city'            => _x('City', 'customer detail', 'modern-hotel-booking'),
            'business_label_state'           => __('State / Region', 'modern-hotel-booking'),
            'business_label_postcode'        => __('Postcode / ZIP', 'modern-hotel-booking'),
            'business_label_country'         => __('Country', 'modern-hotel-booking'),
            'business_label_telephone'       => __('Telephone', 'modern-hotel-booking'),
            'business_label_email'           => _x('Email', 'customer detail', 'modern-hotel-booking'),
            'business_label_website'         => __('Website URL', 'modern-hotel-booking'),
            'business_label_tax_id'          => __('Tax / VAT ID', 'modern-hotel-booking'),
            'business_label_registration_no' => __('Registration No.', 'modern-hotel-booking'),
            'business_label_logo'            => __('Company Logo', 'modern-hotel-booking'),
            'business_label_select_logo'     => __('Select Logo', 'modern-hotel-booking'),
            'business_label_wa_enable'       => __('Enable WhatsApp', 'modern-hotel-booking'),
            'business_label_wa_enable_desc'  => __('Enable WhatsApp contact button', 'modern-hotel-booking'),
            'business_label_wa_phone'        => __('Phone Number', 'modern-hotel-booking'),
            'business_label_wa_phone_desc'   => __('International format with country code.', 'modern-hotel-booking'),
            'business_label_wa_msg'          => __('Default Message', 'modern-hotel-booking'),
            'business_label_wa_btn'          => __('Button Text', 'modern-hotel-booking'),
            'business_label_wa_style'        => __('Display Style', 'modern-hotel-booking'),
            'business_label_wa_pos'          => __('Floating Position', 'modern-hotel-booking'),
            'business_label_bank_enable'     => __('Enable Bank Transfer', 'modern-hotel-booking'),
            'business_label_bank_enable_desc'=> __('Show bank transfer details to guests', 'modern-hotel-booking'),
            'business_label_bank_name'       => __('Bank Name', 'modern-hotel-booking'),
            'business_label_bank_acc_name'   => __('Account Holder Name', 'modern-hotel-booking'),
            'business_label_bank_acc_no'     => __('Account Number', 'modern-hotel-booking'),
            'business_label_bank_iban'       => _x('IBAN', 'financial detail', 'modern-hotel-booking'),
            'business_label_bank_swift'      => __('SWIFT / BIC', 'modern-hotel-booking'),
            'business_label_bank_sort'       => __('Sort Code', 'modern-hotel-booking'),
            'business_label_bank_address'    => __('Branch Address', 'modern-hotel-booking'),
            'business_label_bank_prefix'     => __('Reference Prefix', 'modern-hotel-booking'),
            'business_label_bank_prefix_desc'=> __('e.g. "BOOKING-" produces "BOOKING-1234".', 'modern-hotel-booking'),
            'business_label_bank_instr'      => __('Payment Instructions', 'modern-hotel-booking'),
            'business_label_rev_enable'      => __('Enable Revolut', 'modern-hotel-booking'),
            'business_label_rev_enable_desc' => __('Show Revolut payment option', 'modern-hotel-booking'),
            'business_label_rev_name'        => __('Revolut Name', 'modern-hotel-booking'),
            'business_label_rev_tag'         => __('Revolut Tag', 'modern-hotel-booking'),
            'business_label_rev_iban'        => __('Revolut IBAN', 'modern-hotel-booking'),
            'business_label_rev_link'        => __('Revolut.me Link', 'modern-hotel-booking'),
            'business_label_rev_qr'          => __('QR Code', 'modern-hotel-booking'),
            'business_label_rev_select_qr'   => __('Select QR Code', 'modern-hotel-booking'),
            'business_label_rev_instr'       => __('Payment Instructions', 'modern-hotel-booking'),

            'business_info_style_button'     => __('Inline Button', 'modern-hotel-booking'),
            'business_info_style_floating'   => __('Floating Button', 'modern-hotel-booking'),
            'business_info_style_link'       => __('Text Link', 'modern-hotel-booking'),
            'general_pos_bottom_right'       => __('Bottom Right', 'modern-hotel-booking'),
            'general_pos_bottom_left'        => __('Bottom Left', 'modern-hotel-booking'),
            'general_btn_remove'             => __('Remove', 'modern-hotel-booking'),
            'general_label_shortcode'        => __('Shortcode', 'modern-hotel-booking'),
            'general_label_attributes'       => __('Attributes', 'modern-hotel-booking'),
            'feat_whatsapp_title' => __('WhatsApp Integration', 'modern-hotel-booking'),
            'feat_whatsapp_desc' => __('Direct inquiry button and chat functionality for faster guest communication.', 'modern-hotel-booking'),
            'feat_vat_country_title' => __('Country-Specific VAT', 'modern-hotel-booking'),
            'feat_vat_country_desc' => __('Set different VAT rates per country for international guests.', 'modern-hotel-booking'),
            'feat_tax_breakdown_title' => __('Tax Breakdown Display', 'modern-hotel-booking'),
            'feat_tax_breakdown_desc' => __('Detailed tax display by item in booking confirmations.', 'modern-hotel-booking'),
            'feat_seasonal_title' => __('Seasonal Pricing', 'modern-hotel-booking'),
            'feat_seasonal_desc' => __('Set automated weekend and holiday multipliers for dynamic pricing.', 'modern-hotel-booking'),
            'feat_extras_title' => __('Booking Extras', 'modern-hotel-booking'),
            'feat_extras_desc' => __('Offer guided tours, airport transfers, and local experiences.', 'modern-hotel-booking'),
            'feat_ical_title' => __('iCal Synchronization', 'modern-hotel-booking'),
            'feat_ical_desc' => __('Bi-directional sync with platforms like Airbnb and Booking.com.', 'modern-hotel-booking'),
            'feat_analytics_title' => __('Analytics Dashboard', 'modern-hotel-booking'),
            'feat_analytics_desc' => __('Track occupancy rates and ADR (Average Daily Rate) trends.', 'modern-hotel-booking'),
            'feat_gdpr_title' => __('GDPR Compliance', 'modern-hotel-booking'),
            'feat_gdpr_desc' => __('Automated data retention and user consent management tools.', 'modern-hotel-booking'),
            'feat_theme_title' => __('Theme Customization', 'modern-hotel-booking'),
            'feat_theme_desc' => __('Designer presets and custom branding for the booking form.', 'modern-hotel-booking'),
            'feat_multilingual_title' => __('Multilingual Support', 'modern-hotel-booking'),
            'feat_multilingual_desc' => __('Native translation support and compatibility with WPML/Polylang.', 'modern-hotel-booking'),
            'feat_rest_api_title' => __('REST API', 'modern-hotel-booking'),
            'feat_rest_api_desc' => __('Full programmatic access for external integrations.', 'modern-hotel-booking'),

            /* Settings Detail Labels & Buttons */
            'label_api_base_desc' => __('The REST API allows external systems to query room availability and manage bookings. API base:', 'modern-hotel-booking'),
            'label_api_key_header' => __('Include as X-MHBO-API-KEY header for authenticated endpoints (GET /bookings, etc.).', 'modern-hotel-booking'),
            'label_webhook_json_desc' => __('Receive a JSON POST for booking events: booking_created, booking_confirmed, booking_cancelled.', 'modern-hotel-booking'),
            'label_webhook_hmac_desc' => __('Used to sign webhook payloads using HMAC-SHA256. Recommendation: Use a separate secret from your API key.', 'modern-hotel-booking'),
            'label_webhook_debug_logging' => __('Enable Debug Logging', 'modern-hotel-booking'),
            'label_webhook_logging_desc' => __('Log outgoing deliveries (up to 50 entries). Disable this for slightly better performance if not debugging.', 'modern-hotel-booking'),
            'btn_clear_history' => __('Clear History', 'modern-hotel-booking'),
            'btn_send_test' => __('Send Test Webhook', 'modern-hotel-booking'),
            'btn_reset_theme' => __('Return to Default', 'modern-hotel-booking'),
            'btn_save_changes' => __('Save Changes', 'modern-hotel-booking'),
            'btn_apply_theme' => __('Apply Theme', 'modern-hotel-booking'),
            'label_reset_theme_confirm' => __('Reset all theme settings to default?', 'modern-hotel-booking'),

            /* Pricing & Rules */
            'label_weekend_pricing' => __('Weekend Pricing', 'modern-hotel-booking'),
            'label_weekend_days' => __('Weekend Days', 'modern-hotel-booking'),
            'label_weekend_adj' => __('Weekend Adjustment', 'modern-hotel-booking'),
            'label_holiday_pricing' => __('Holiday Pricing', 'modern-hotel-booking'),
            'label_holiday_picker' => __('Holiday Date Picker', 'modern-hotel-booking'),
            'label_holiday_adj' => __('Holiday Adjustment', 'modern-hotel-booking'),
            'label_conflict_res' => __('Conflict Resolution', 'modern-hotel-booking'),
            'label_weekend_pricing_desc' => __('Define which days are considered weekends and set the adjustment.', 'modern-hotel-booking'),
            'label_holiday_pricing_desc' => __('Add specific dates that should trigger holiday pricing.', 'modern-hotel-booking'),
            'label_multiplier_desc' => __('Multiplier (1.2 = +20%)', 'modern-hotel-booking'),
            'label_percent_desc' => __('Percentage (20 = +20%)', 'modern-hotel-booking'),
            'label_fixed_desc' => __('Fixed Amount (20 = +$20)', 'modern-hotel-booking'),
            'label_conflict_overlap' => __('Use larger of weekend vs holiday adjustment if they overlap', 'modern-hotel-booking'),

            /* Tax & Compliance */
            'label_tax_settings_title' => __('Tax Settings', 'modern-hotel-booking'),
            'msg_tax_settings_desc'    => __('Configure VAT/Sales Tax settings for your hotel bookings. Tax is disabled by default for backward compatibility.', 'modern-hotel-booking'),
            'label_tax_mode_label'     => __('Tax Mode', 'modern-hotel-booking'),
            'label_tax_mode_disabled' => __('Disabled', 'modern-hotel-booking'),
            'label_tax_mode_vat' => __('VAT Inclusive', 'modern-hotel-booking'),
            'label_tax_mode_sales' => __('Sales Tax Exclusive', 'modern-hotel-booking'),
            'label_tax_mode_disabled_desc' => __('No tax calculation or display. Prices shown as-is.', 'modern-hotel-booking'),
            'label_tax_mode_vat_desc' => __('Prices include tax (EU, UK, Australia). Shows breakdown: Net + Tax = Gross.', 'modern-hotel-booking'),
            'label_tax_mode_sales_desc' => __('Tax added on top of prices (US, Canada). Shows: Subtotal + Tax = Total.', 'modern-hotel-booking'),
            'label_tax_registration_num' => __('Tax Registration Number', 'modern-hotel-booking'),
            'label_tax_registration_desc' => __('Your VAT ID, GSTIN, or tax registration number. Displayed on invoices and receipts.', 'modern-hotel-booking'),
            'label_accommodation_tax' => __('Accommodation Tax Rate', 'modern-hotel-booking'),
            'label_accommodation_tax_desc' => __('Tax rate for rooms and children charges. Example: 10 for 10%', 'modern-hotel-booking'),
            'label_extras_tax' => __('Extras Tax Rate', 'modern-hotel-booking'),
            'label_extras_tax_desc' => __('Tax rate for extras (add-ons like breakfast, transfers). Set same as accommodation if unsure.', 'modern-hotel-booking'),
            'label_display_options' => __('Display Options', 'modern-hotel-booking'),
            'label_show_tax_frontend' => __('Show tax breakdown on frontend booking form', 'modern-hotel-booking'),
            'label_show_tax_email' => __('Show tax breakdown in confirmation emails', 'modern-hotel-booking'),
            'label_rounding_mode' => __('Rounding Mode', 'modern-hotel-booking'),
            'label_rounding_per_total' => __('Per Total (Recommended)', 'modern-hotel-booking'),
            'label_rounding_per_line' => __('Per Line Item', 'modern-hotel-booking'),
            'label_rounding_desc' => __('Per Total: Round tax on final total. Per Line: Round each item\'s tax separately.', 'modern-hotel-booking'),
            'label_decimal_places' => __('Decimal Places', 'modern-hotel-booking'),
            'label_decimal_places_desc' => __('Number of decimal places for tax amounts (usually 2).', 'modern-hotel-booking'),
            'label_tax_name' => __('Tax Name', 'modern-hotel-booking'),
            'label_gdpr_retention_desc' => __('Number of days before check-in when the deposit becomes non-refundable (if not already marked as such).', 'modern-hotel-booking'),
            'label_tax_label_desc' => __('Examples: VAT, GST, Sales Tax, IVA, MwSt, TVA', 'modern-hotel-booking'),

            /* Deposits & Partial Payments */
            'label_deposits_title' => __('Deposit & Partial Payments', 'modern-hotel-booking'),
            'label_deposits_desc' => __('Configure how deposits and partial payments are handled during checkout.', 'modern-hotel-booking'),
            'label_enable_deposits' => __('Enable Deposits', 'modern-hotel-booking'),
            'label_enable_deposits_desc' => __('If enabled, guests can pay a deposit instead of the full amount at checkout.', 'modern-hotel-booking'),
            'label_deposit_type' => __('Deposit Type', 'modern-hotel-booking'),
            'label_deposit_type_pct' => __('Percentage of Total', 'modern-hotel-booking'),
            'label_deposit_type_fixed' => __('Fixed Amount', 'modern-hotel-booking'),
            'label_deposit_type_first_night' => __('First Night\'s Rate', 'modern-hotel-booking'),
            'label_deposit_value' => __('Deposit Value', 'modern-hotel-booking'),
            'label_non_refundable' => __('Non-Refundable', 'modern-hotel-booking'),
            'label_non_refundable_desc' => __('Mark this deposit as non-refundable in emails and checkout.', 'modern-hotel-booking'),
            'label_refund_deadline_desc' => __('Number of days before check-in when the deposit becomes non-refundable (if not already marked as such).', 'modern-hotel-booking'),
            'label_days_before_checkin' => __('days before check-in', 'modern-hotel-booking'),
            'label_guest_choice' => __('Allow Guest Choice', 'modern-hotel-booking'),
            'label_guest_choice_desc' => __('If enabled, guests can choose between paying the deposit or the full amount.', 'modern-hotel-booking'),

            /* Amenities & Performance */
            'label_room_amenities_desc' => __('Manage the list of amenities available for rooms. Once added, you can translate them in the \"Frontend Labels\" tab.', 'modern-hotel-booking'),
            'label_amenity_add_new' => __('Add New Amenity', 'modern-hotel-booking'),
            'label_amenity_placeholder' => __('e.g. Hot Tub', 'modern-hotel-booking'),
            'label_amenity_key_internal' => __('Key (Internal)', 'modern-hotel-booking'),
            'label_amenity_no_found' => __('No amenities found.', 'modern-hotel-booking'),
            'label_enable_cache' => __('Enable Object Caching', 'modern-hotel-booking'),
            'btn_clear_cache' => __('Clear Plugin Cache', 'modern-hotel-booking'),

            /* Licensing */
            'label_license_desc' => __('Enter your license key to activate Pro features and receive automatic updates.', 'modern-hotel-booking'),
            'msg_license_activated' => __('Pro status activated successfully! Thank you for your support.', 'modern-hotel-booking'),
            'msg_license_key_updated' => __('License key updated. Please re-activate if necessary.', 'modern-hotel-booking'),
            'msg_license_removed' => __('License key removed and deactivated.', 'modern-hotel-booking'),

            /* Themes & Appearance */
            'label_theme_presets' => __('Theme Presets', 'modern-hotel-booking'),
            'label_theme_presets_desc' => __('Choose a professional color palette for your booking frontend.', 'modern-hotel-booking'),
            'label_custom_theme' => __('Custom Theme', 'modern-hotel-booking'),
            'label_custom_theme_desc' => __('Define your own brand colors below.', 'modern-hotel-booking'),
            'label_custom_branding' => __('Custom Branding', 'modern-hotel-booking'),
            'label_primary_color' => __('Primary Color', 'modern-hotel-booking'),
            'label_secondary_color' => __('Secondary Color', 'modern-hotel-booking'),
            'label_accent_color' => __('Accent Color', 'modern-hotel-booking'),
            'label_adv_custom_css' => __('Advanced Custom CSS', 'modern-hotel-booking'),
            'label_adv_custom_css_desc' => __('Inject additional CSS directly into the booking frontend.', 'modern-hotel-booking'),
            'label_midnight_coast' => __('Midnight Coast', 'modern-hotel-booking'),
            'label_midnight_coast_desc' => __('Our signature classic luxury look.', 'modern-hotel-booking'),
            'label_emerald_forest' => __('Emerald Forest', 'modern-hotel-booking'),
            'label_emerald_forest_desc' => __('Rich greens for nature-inspired properties.', 'modern-hotel-booking'),
            'label_oceanic_drift' => __('Oceanic Drift', 'modern-hotel-booking'),
            'label_oceanic_drift_desc' => __('Deep blues and bright highlights.', 'modern-hotel-booking'),
            'label_ruby_sunset' => __('Ruby Sunset', 'modern-hotel-booking'),
            'label_ruby_sunset_desc' => __('Warm tones for cozy boutiques.', 'modern-hotel-booking'),
            'label_urban_modern' => __('Urban Modern', 'modern-hotel-booking'),
            'label_urban_modern_desc' => __('Minimalist grays for city lofts.', 'modern-hotel-booking'),
            'label_lavender_breeze' => __('Lavender Breeze', 'modern-hotel-booking'),
            'label_lavender_breeze_desc' => __('Elegant purples for spa and wellness.', 'modern-hotel-booking'),

            /* Email System */
            'email_booking_status_subject' => __('Booking {status} - #{booking_id}', 'modern-hotel-booking'),
            'email_booking_status_message' => __('Hello {customer_name}, your booking #{booking_id} status is now {status}.', 'modern-hotel-booking'),
            'email_payment_confirmation_subject' => __('Payment Confirmation - Booking #{booking_id}', 'modern-hotel-booking'),
            'email_payment_confirmation_heading' => __('Payment Confirmation', 'modern-hotel-booking'),
            /* translators: %s: customer name */
            'email_dear_customer' => __('Dear %s,', 'modern-hotel-booking'),
            'email_payment_thank_you' => __('Thank you for your payment. Your booking has been confirmed.', 'modern-hotel-booking'),
            'email_booking_details' => __('Booking Details', 'modern-hotel-booking'),
            'email_booking_id' => __('Booking ID:', 'modern-hotel-booking'),
            'email_check_in' => __('Check-in:', 'modern-hotel-booking'),
            'email_check_out' => __('Check-out:', 'modern-hotel-booking'),
            'email_contact_us_prompt' => __('If you have any questions, please don\'t hesitate to contact us.', 'modern-hotel-booking'),
            'email_best_regards' => __('Best regards,', 'modern-hotel-booking'),
            'email_payment_details' => __('Payment Details', 'modern-hotel-booking'),
            'email_amount_paid' => __('Amount Paid:', 'modern-hotel-booking'),
            'email_transaction_id' => __('Transaction ID:', 'modern-hotel-booking'),
            'email_payment_method' => __('Payment Method:', 'modern-hotel-booking'),
            'email_payment_date' => __('Payment Date:', 'modern-hotel-booking'),
            /* translators: 1: tax label, 2: accommodation tax rate percentage, 3: extras tax rate percentage */
            'email_tax_inclusive_split' => __('Price includes %1$s - Accommodation: %2$s%%, Extras: %3$s%%', 'modern-hotel-booking'),
            /* translators: 1: tax label, 2: accommodation tax rate percentage, 3: extras tax rate percentage */
            'email_tax_exclusive_split' => __('%1$s added at checkout - Accommodation: %2$s%%, Extras: %3$s%%', 'modern-hotel-booking'),
            'label_hotel_booking' => __('Hotel Booking', 'modern-hotel-booking'),
            /* translators: %d: booking ID number */
            'label_hotel_booking_id' => __('Hotel Booking #%d', 'modern-hotel-booking'),

            /* Privacy Policy & GDPR */
            'privacy_policy_heading' => __('Modern Hotel Booking', 'modern-hotel-booking'),
            'privacy_policy_collection_desc' => __('This plugin collects and displays the following information:', 'modern-hotel-booking'),
            'privacy_policy_guest_data' => __('Guest Data', 'modern-hotel-booking'),
            'privacy_policy_guest_data_desc_1' => __('Name, Email, and Phone Number', 'modern-hotel-booking'),
            'privacy_policy_guest_data_desc_2' => __('Booking Dates and Room Preferences', 'modern-hotel-booking'),
            'privacy_policy_business_info' => __('Business Information', 'modern-hotel-booking'),
            'privacy_policy_business_info_desc_1' => __('The site administrator may configure business details (Company Name, Address, Tax ID) and payment information (Bank Transfer IBAN, Revolut Tag) to be displayed on the frontend and in booking emails.', 'modern-hotel-booking'),
            'privacy_policy_business_info_desc_2' => __('This data is stored in the WordPress options table and is used solely for facilitation of the booking process.', 'modern-hotel-booking'),
            'gdpr_booking_id' => __('Booking ID', 'modern-hotel-booking'),
            'gdpr_room_id' => __('Room ID', 'modern-hotel-booking'),
            'gdpr_check_in' => __('Check-in Date', 'modern-hotel-booking'),
            'gdpr_check_out' => __('Check-out Date', 'modern-hotel-booking'),
            'gdpr_total_price' => __('Total Price', 'modern-hotel-booking'),
            'gdpr_status' => __('Status', 'modern-hotel-booking'),
            'gdpr_customer_name' => __('Customer Name', 'modern-hotel-booking'),
            'gdpr_customer_phone' => __('Customer Phone', 'modern-hotel-booking'),
            'gdpr_admin_notes' => __('Admin Notes', 'modern-hotel-booking'),
            'gdpr_custom_fields' => __('Custom Fields', 'modern-hotel-booking'),
            'gdpr_booking_extras' => __('Booking Extras', 'modern-hotel-booking'),
            'gdpr_payment_errors' => __('Payment Error Logs', 'modern-hotel-booking'),
            'gdpr_group_label' => __('Hotel Bookings', 'modern-hotel-booking'),

            /* Appearance & UI */
            'label_licensing_tab'               => __('Licensing', 'modern-hotel-booking'),
            'label_register_unit'               => __('Register New Unit', 'modern-hotel-booking'),

            /* Payment Gateways (Settings) */
            'label_payment_gateways'            => __('Payment Gateways', 'modern-hotel-booking'),
            'desc_payment_gateways'             => __('Enable and configure payment methods for your guests.', 'modern-hotel-booking'),
            
            /* Stripe Settings */
            'label_stripe_config'               => __('Stripe (Credit/Debit Cards)', 'modern-hotel-booking'),
            'label_stripe_enable_desc'          => __('Accept credit/debit card payments via Stripe', 'modern-hotel-booking'),
            'label_stripe_test_publishable_key' => __('Test Publishable Key', 'modern-hotel-booking'),
            'label_stripe_test_secret_key'      => __('Test Secret Key', 'modern-hotel-booking'),
            'label_stripe_live_publishable_key' => __('Live Publishable Key', 'modern-hotel-booking'),
            'label_stripe_live_secret_key'      => __('Live Secret Key', 'modern-hotel-booking'),
            'label_stripe_test_webhook_secret'  => __('Test Webhook Secret', 'modern-hotel-booking'),
            'label_stripe_live_webhook_secret'  => __('Live Webhook Secret', 'modern-hotel-booking'),
            /* translators: %s: environment (Test/Live) */
            'label_stripe_creds_valid'          => __('Stripe %s credentials are valid.', 'modern-hotel-booking'),
            /* translators: %s: currency code (e.g. USD) */
            'label_stripe_acc_default_curr'     => __('Account default: %s', 'modern-hotel-booking'),
            /* translators: 1: plugin currency code, 2: Stripe account currency code */
            'msg_plugin_currency_mismatch'      => __('The plugin currency (%1$s) does not match your Stripe account\'s default currency (%2$s). This may cause payment processing issues.', 'modern-hotel-booking'),
            
            /* PayPal Settings */
            'label_paypal_config'               => __('PayPal', 'modern-hotel-booking'),
            'label_paypal_enable_desc'          => __('Accept payments via PayPal', 'modern-hotel-booking'),
            'label_paypal_webhook_dashboard'    => __('Webhook URL for PayPal Dashboard:', 'modern-hotel-booking'),
            'label_paypal_webhook_desc'         => __('Copy this URL to your PayPal App settings. For local testing, use a service like ngrok or Local by Flywheel "Live Links" to provide an HTTPS tunnel.', 'modern-hotel-booking'),
            'label_paypal_sandbox_client_id'    => __('Sandbox Client ID', 'modern-hotel-booking'),
            'label_paypal_sandbox_secret'       => __('Sandbox Secret', 'modern-hotel-booking'),
            'label_paypal_sandbox_webhook_id'   => __('Sandbox Webhook ID', 'modern-hotel-booking'),
            'label_paypal_live_client_id'       => __('Live Client ID', 'modern-hotel-booking'),
            'label_paypal_live_secret'          => __('Live Secret', 'modern-hotel-booking'),
            'label_paypal_live_webhook_id'      => __('Live Webhook ID', 'modern-hotel-booking'),
            'desc_webhook_id_required'          => __('Required for automated payment verification.', 'modern-hotel-booking'),

            /* Braintree Settings */
            'label_braintree_config'            => __('Braintree (Credit/Debit Cards, Apple Pay)', 'modern-hotel-booking'),
            'label_braintree_enable_desc'       => __('Accept card and wallet payments via Braintree (PayPal Service)', 'modern-hotel-booking'),
            'label_braintree_sandbox_merchant_id' => __('Sandbox Merchant ID', 'modern-hotel-booking'),
            'label_braintree_sandbox_public_key'  => __('Sandbox Public Key', 'modern-hotel-booking'),
            'label_braintree_sandbox_private_key' => __('Sandbox Private Key', 'modern-hotel-booking'),
            'label_braintree_live_merchant_id'    => __('Live Merchant ID', 'modern-hotel-booking'),
            'label_braintree_live_public_key'     => __('Live Public Key', 'modern-hotel-booking'),
            'label_braintree_live_private_key'    => __('Live Private Key', 'modern-hotel-booking'),
            'label_braintree_merchant_account_id' => __('Merchant Account ID (Multi-Currency)', 'modern-hotel-booking'),
            'label_braintree_merchant_account_id_desc' => __('Optional: Required if your Braintree account uses currency-specific Merchant Account IDs (e.g. your_company_ron for RON, EUR, or GBP). Leave blank to use your default Braintree merchant account.', 'modern-hotel-booking'),
            'label_test_braintree_btn'          => __('Test Braintree Connection', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_braintree_creds_valid_env' => __('Braintree %s credentials are valid!', 'modern-hotel-booking'),
            'label_braintree'                   => __('Credit Card / Wallet (Braintree)', 'modern-hotel-booking'),
            'label_desc_braintree'              => __('Label for Braintree payment option on checkout.', 'modern-hotel-booking'),

            /* Elementor Widgets */
            'label_elementor_booking_form'      => __('Hotel Booking Form', 'modern-hotel-booking'),
            'label_elementor_room_grid'         => __('Room Grid Showcase', 'modern-hotel-booking'),
            'label_elementor_calendar'          => __('Availability Calendar', 'modern-hotel-booking'),
            'label_elementor_ai_concierge'      => __('AI Concierge Chat', 'modern-hotel-booking'),
            'label_elementor_guest_portal'      => __('Guest Booking Portal', 'modern-hotel-booking'),
            'label_elementor_pricing_matrix'    => __('Dynamic Pricing Matrix', 'modern-hotel-booking'),
            'label_elementor_single_room_card'  => __('Single Room Card', 'modern-hotel-booking'),
            
            /* Helper Gateway Labels */
            'gateway_stripe'                    => __('Stripe', 'modern-hotel-booking'),
            'gateway_paypal'                    => __('PayPal', 'modern-hotel-booking'),
            'label_pay_arrival'                 => __('Pay on Arrival', 'modern-hotel-booking'),
            
            /* Generic Settings Labels */
            'label_enable_gateway'              => __('Enable', 'modern-hotel-booking'),
            'label_gateway_mode'                => _x('Mode', 'setting', 'modern-hotel-booking'),
            'label_mode_test'                   => _x('Test', 'gateway mode', 'modern-hotel-booking'),
            'label_mode_live'                   => _x('Live', 'gateway mode', 'modern-hotel-booking'),
            'label_mode_sandbox'                => __('Sandbox', 'modern-hotel-booking'),
            'label_secret_stored_securely'      => __('Secret key is stored securely. Enter a new value to update.', 'modern-hotel-booking'),
            'label_sdk_settings'                => __('SDK Settings', 'modern-hotel-booking'),
            'label_paypal_sdk_locale_label'     => __('PayPal SDK Locale', 'modern-hotel-booking'),
            'label_paypal_sdk_locale_desc'      => __('Optional: Forces a specific language/region for the PayPal interface (e.g., en_US, ro_RO). Leave blank for auto-detection.', 'modern-hotel-booking'),
            'label_paypal_sdk_args_label'       => __('PayPal SDK Custom Arguments', 'modern-hotel-booking'),
            'label_paypal_sdk_args_desc'        => __('Advanced: Add custom query parameters to the PayPal SDK URL. Use this to force a language or disable problematic features.', 'modern-hotel-booking'),
            'label_test_stripe_btn'             => __('Test Stripe Credentials', 'modern-hotel-booking'),
            
            /* iCal Module Labels */
            'label_ical'                        => _x('iCal', 'sync format', 'modern-hotel-booking'),
            'label_airbnb_res'                  => __('Airbnb Reservation', 'modern-hotel-booking'),
            'label_booking_res'                 => __('Booking.com Reservation', 'modern-hotel-booking'),
            'label_google_res'                  => __('Google Calendar Event', 'modern-hotel-booking'),
            'label_unknown_error'               => __('Unknown error', 'modern-hotel-booking'),
            'label_sync_instr_airbnb'           => __('Go to your Airbnb listing → Calendar → Availability', 'modern-hotel-booking'),
            'label_sync_instr_export_airbnb'     => __('Under "Connect calendars," click "Export Calendar"', 'modern-hotel-booking'),
            'label_sync_instr_copy_ical'        => __('Copy the iCal URL and add it as a new connection above', 'modern-hotel-booking'),
            'label_airbnb_sync_desc'            => __('To import YOUR calendar into Airbnb, use the Export URL above in Airbnb\'s import field', 'modern-hotel-booking'),
            'label_sync_instr_booking'          => __('Go to Booking.com Extranet → Rates & Availability → Sync calendars', 'modern-hotel-booking'),
            'label_booking_sync_instr_export'    => __('Copy the export URL from Booking.com and add it as a new connection above', 'modern-hotel-booking'),
            'label_booking_sync_desc'           => __('To export YOUR calendar to Booking.com, use the Export URL above in Booking.com\'s import field', 'modern-hotel-booking'),
            'label_how_to_setup_sync'           => __('How to set up calendar sync', 'modern-hotel-booking'),
            'msg_ical_sync_desc'                => __('Sync your availability with external platforms like Airbnb and Booking.com.', 'modern-hotel-booking'),
            /* translators: %s: human-readable time elapsed (e.g. "5 minutes") */
            'label_ago'                         => __('%s ago', 'modern-hotel-booking'),
            'label_copy'                        => _x('Copy', 'action', 'modern-hotel-booking'),
            'label_sync'                        => _x('Sync', 'action', 'modern-hotel-booking'),
            
            /* iCal AJAX Messages */
            'msg_ssrf_blocked'                  => __('SSRF Protection: URL blocklisted or invalid', 'modern-hotel-booking'),
            'msg_conn_added'                    => __('Connection added successfully', 'modern-hotel-booking'),
            'msg_conn_failed'                   => __('Failed to add connection', 'modern-hotel-booking'),
            'msg_conn_updated'                  => __('Connection updated successfully', 'modern-hotel-booking'),
            'msg_conn_upd_failed'               => __('Failed to update connection', 'modern-hotel-booking'),
            'msg_conn_deleted'                  => __('Connection deleted successfully', 'modern-hotel-booking'),
            'msg_conn_del_failed'               => __('Failed to delete connection', 'modern-hotel-booking'),
            'msg_invalid_ical'                  => __('Invalid iCal format', 'modern-hotel-booking'),
            'msg_conn_successful'               => __('Connection successful', 'modern-hotel-booking'),
            'msg_settings_saved'                => __('Settings saved successfully', 'modern-hotel-booking'),
            
            /* iCal UI Elements */
            'label_import_only'                 => __('Import Only', 'modern-hotel-booking'),
            'label_export_only'                 => __('Export Only', 'modern-hotel-booking'),
            'label_bidirectional'                => __('Bidirectional', 'modern-hotel-booking'),
            'label_ical_sync_title'             => __('📅 iCal Calendar Sync', 'modern-hotel-booking'),
            'label_export_url'                  => __('Export URL', 'modern-hotel-booking'),
            'label_export_url_desc'             => __('Share this URL with Airbnb, Booking.com, or other platforms to export your availability.', 'modern-hotel-booking'),
            'label_calendar_connections'        => __('Calendar Connections', 'modern-hotel-booking'),
            /* translators: %s: human-readable time elapsed (e.g. "5 minutes") */
            'label_synced_ago'                  => __('Synced %s ago', 'modern-hotel-booking'),
            'label_never_synced'                => __('Never synced', 'modern-hotel-booking'),
            'label_add_connection_btn'          => __('+ Add Connection', 'modern-hotel-booking'),
            'label_sync_all_btn'                => __('Sync All', 'modern-hotel-booking'),
            'label_all_synced'                  => __('All rooms have been synced.', 'modern-hotel-booking'),
            /* translators: %s: room number or name */
            'label_room_synced'                 => __('Room #%s has been synced.', 'modern-hotel-booking'),
            'ical_external_label'               => __('External', 'modern-hotel-booking'),
            /* translators: 1: first name, 2: last name, 3: booking/reference number */
            'ical_guest_placeholder'            => __('%1$s %2$s #%3$s', 'modern-hotel-booking'),
            /* translators: 1: imported count, 2: updated count, 3: cancelled count, 4: skipped count */
            'ical_sync_summary'                 => __('Imported: %1$d, Updated: %2$d, Cancelled: %3$d, Skipped: %4$d', 'modern-hotel-booking'),
            
            /* iCal Settings Labels */
            'label_sync_settings'               => __('⚙️ Sync Settings', 'modern-hotel-booking'),
            'label_enable_auto_sync'            => __('Enable Auto Sync', 'modern-hotel-booking'),
            'label_auto_sync_desc'              => __('Automatically sync calendars', 'modern-hotel-booking'),
            'label_sync_interval'               => __('Sync Interval', 'modern-hotel-booking'),
            'label_every_5m'                    => __('Every 5 Minutes', 'modern-hotel-booking'),
            'label_every_15m'                   => __('Every 15 Minutes', 'modern-hotel-booking'),
            'label_every_1h'                    => __('Every Hour', 'modern-hotel-booking'),
            'label_every_6h'                    => __('Every 6 Hours', 'modern-hotel-booking'),
            'label_daily'                       => _x('Daily', 'frequency', 'modern-hotel-booking'),
            'label_enable_retry'                => __('Enable Retry Logic', 'modern-hotel-booking'),
            'label_retry_desc'                  => __('Retry failed syncs with exponential backoff (1h, 2h, 4h)', 'modern-hotel-booking'),
            'label_failure_notifications'       => __('Email Notifications', 'modern-hotel-booking'),
            'label_failure_notif_desc'          => __('Send email on sync failure', 'modern-hotel-booking'),
            'label_notif_email'                 => __('Notification Email', 'modern-hotel-booking'),
            /* translators: 1: imported count, 2: updated count, 3: cancelled count, 4: skipped count */
            'label_sync_summary'                => __('Imported: %1$d, Updated: %2$d, Cancelled: %3$d, Skipped: %4$d', 'modern-hotel-booking'),
            
            /* Final Settings & Feature Labels */
            'label_settings_pro_only'           => __('Pro Features Teaser', 'modern-hotel-booking'),
            'label_settings_behavior'           => __('Behavior & Defaults', 'modern-hotel-booking'),
            'label_settings_theme_branding'     => __('Theme & Branding', 'modern-hotel-booking'),
            'label_settings_vat_tax'            => __('VAT / Tax Management', 'modern-hotel-booking'),
            'label_settings_pricing_seasonal'   => __('Pricing & Holiday Rules', 'modern-hotel-booking'),
            'label_settings_ical_sync'          => __('iCal Synchronization', 'modern-hotel-booking'),
            'label_settings_api_webhooks'       => __('API & Webhooks', 'modern-hotel-booking'),
            'label_settings_gdpr_privacy'       => __('GDPR & Data Privacy', 'modern-hotel-booking'),
            'label_settings_business_contact'   => __('Business & Contact Info', 'modern-hotel-booking'),
            'label_settings_appearance_ux'      => __('Appearance & UX', 'modern-hotel-booking'),
            'label_settings_performance_status' => __('Performance & Status', 'modern-hotel-booking'),
            'label_settings_license_updates'    => __('License & Updates', 'modern-hotel-booking'),
            
            'label_feat_cat_header'             => __('Premium Feature Grid', 'modern-hotel-booking'),
            'label_feat_cat_sub'                => __('Unlock professional workflows for high-conversion booking management.', 'modern-hotel-booking'),
            'label_feat_cat_payments_title'     => __('Payment Logic & Gateways', 'modern-hotel-booking'),
            'label_feat_cat_payments_desc'      => __('Multi-gateway support, partial payments, and automatic verification.', 'modern-hotel-booking'),
            'label_feat_cat_inventory_title'    => __('Inventory & Sync Control', 'modern-hotel-booking'),
            'label_feat_cat_inventory_desc'     => __('Bi-directional iCal sync with Airbnb, Booking.com, and Google Calendar.', 'modern-hotel-booking'),
            'label_feat_cat_pricing_title'      => __('Dynamic Pricing Engine', 'modern-hotel-booking'),
            'label_feat_cat_pricing_desc'       => __('Weekend multipliers, holiday rules, and bulk season adjustments.', 'modern-hotel-booking'),
            'label_feat_cat_reporting_title'    => __('Analytics & Reporting', 'modern-hotel-booking'),
            'label_feat_cat_reporting_desc'     => __('Track occupancy, ADR, and revenue trends in a centralized dashboard.', 'modern-hotel-booking'),
            'label_feat_cat_ux_title'           => __('Premium Presence & Branding', 'modern-hotel-booking'),
            'label_feat_cat_ux_desc'            => __('Visual theme designer, custom icons, and advanced CSS overrides.', 'modern-hotel-booking'),
            'label_feat_cat_infra_title'        => __('Global Infrastructure', 'modern-hotel-booking'),
            'label_feat_cat_infra_desc'         => __('Developer REST API, HMAC webhooks, and advanced object caching.', 'modern-hotel-booking'),
            
            'label_theme_midnight'              => __('Midnight Coast (Default)', 'modern-hotel-booking'),
            'label_theme_midnight_desc'         => __('Luxurious dark blue and gold aesthetic.', 'modern-hotel-booking'),
            'label_theme_forest'                => __('Emerald Forest', 'modern-hotel-booking'),
            'label_theme_forest_desc'           => __('Deep greens for nature and eco-lodges.', 'modern-hotel-booking'),
            'label_theme_ocean'                 => __('Oceanic Drift', 'modern-hotel-booking'),
            'label_theme_ocean_desc'            => __('Bright blues for coastal and summer vibes.', 'modern-hotel-booking'),
            'label_theme_ruby'                  => __('Ruby Sunset', 'modern-hotel-booking'),
            'label_theme_ruby_desc'             => __('Warm tones for boutique and romantic stays.', 'modern-hotel-booking'),
            'label_theme_urban'                 => __('Urban Industrial', 'modern-hotel-booking'),
            'label_theme_urban_desc'            => __('Modern concrete and charcoal for city lofts.', 'modern-hotel-booking'),
            
            /* iCal & Synchronization (Admin) */
            'placeholder_connection_name'       => __('Connection Name', 'modern-hotel-booking'),
            'btn_sync_conn'                     => _x('Sync', 'action', 'modern-hotel-booking'),
            'msg_all_synced'                    => __('All rooms have been synced.', 'modern-hotel-booking'),
            'title_ical_sync_admin'             => __('📅 iCal Calendar Sync', 'modern-hotel-booking'),
            'label_failure_threshold'           => __('Failure Email Threshold', 'modern-hotel-booking'),
            'desc_failure_threshold'            => __('Send email only after X consecutive failures.', 'modern-hotel-booking'),
            'label_conflict_resolution'         => __('Conflict Resolution', 'modern-hotel-booking'),
            'label_local_wins'                  => __('Local Wins (Skip External)', 'modern-hotel-booking'),
            'label_external_wins'               => __('External Wins (Overwrite Local)', 'modern-hotel-booking'),
            'desc_conflict_resolution'          => __('Determines what happens when an external event overlaps with an internal booking.', 'modern-hotel-booking'),
            'label_success_notifications'       => __('Success Notifications', 'modern-hotel-booking'),
            'label_notify_success'              => __('Notify when a sync succeeds after a failure.', 'modern-hotel-booking'),
            'btn_save_settings_ical'            => __('Save Settings', 'modern-hotel-booking'),
            'btn_sync_all_rooms'                => __('Sync All Rooms', 'modern-hotel-booking'),
            'label_confirm_sync_all'            => __('Sync all rooms? This may take a moment.', 'modern-hotel-booking'),
            'col_room_num'                      => _x('Room', 'table column header', 'modern-hotel-booking'),
            'col_room_type'                     => _x('Room Type', 'table column header', 'modern-hotel-booking'),
            'col_sync_platforms'                => _x('Platforms', 'table column header', 'modern-hotel-booking'),
            'col_sync_connections'              => _x('Connections', 'table column header', 'modern-hotel-booking'),
            'col_sync_last_synced'              => _x('Last Synced', 'table column header', 'modern-hotel-booking'),
            'col_sync_export_url'               => _x('Export URL', 'table column header', 'modern-hotel-booking'),
            'col_sync_actions'                  => _x('Actions', 'table column header', 'modern-hotel-booking'),
            'msg_no_rooms_found_admin'          => __('No rooms found. Create rooms first.', 'modern-hotel-booking'),
            'msg_ssrf_protection'               => __('SSRF Protection: URL blocklisted or invalid', 'modern-hotel-booking'),
            'msg_invalid_room_id'               => __('Invalid Room ID.', 'modern-hotel-booking'),

            /* Analytics & Intelligence (Admin) */
            'label_collected_mtd'               => __('Collected (MTD)', 'modern-hotel-booking'),
            'label_outstanding_balance'         => __('Outstanding Balance', 'modern-hotel-booking'),
            'label_guests_now'                  => __('Guests Now', 'modern-hotel-booking'),
            'label_pending_bookings_count'      => __('pending bookings', 'modern-hotel-booking'),
            'label_partially_paid_count'        => __('partially paid', 'modern-hotel-booking'),
            'label_view_full_analytics'         => __('View Full Analytics →', 'modern-hotel-booking'),
            'label_analytics_intelligence'      => __('Analytics Intelligence', 'modern-hotel-booking'),
            'label_range_7d'                    => _x('7D', '7 days abbreviation', 'modern-hotel-booking'),
            'label_range_30d'                   => _x('30D', '30 days abbreviation', 'modern-hotel-booking'),
            'label_range_90d'                   => _x('90D', '90 days abbreviation', 'modern-hotel-booking'),
            'label_range_year'                  => _x('Year', 'time period', 'modern-hotel-booking'),
            'label_starts'                      => __('Starts', 'modern-hotel-booking'),
            'label_ends'                        => _x('Ends', 'temporal limit', 'modern-hotel-booking'),
            'label_update'                      => __('Update', 'modern-hotel-booking'),
            'title_kpi'                         => __('Key Performance Indicators', 'modern-hotel-booking'),
            'msg_vs_prev_period'                => __('vs previous period', 'modern-hotel-booking'),
            'label_includes'                    => __('Includes', 'modern-hotel-booking'),
            'label_tax_lower'                   => _x('tax', 'noun', 'modern-hotel-booking'),
            'msg_of_total'                      => __('of total', 'modern-hotel-booking'),
            'label_pending_payments'            => __('Pending payments', 'modern-hotel-booking'),
            'label_nights_lower'                => __('nights', 'modern-hotel-booking'),
            'label_adr_full'                    => __('Average Daily Rate', 'modern-hotel-booking'),
            'label_revpar_full'                 => __('Revenue Per Available Room', 'modern-hotel-booking'),
            'label_confirmed_lower'             => __('confirmed', 'modern-hotel-booking'),
            'label_per_booking'                 => __('Per booking', 'modern-hotel-booking'),
            'label_vat_sales_tax'               => __('VAT / Sales Tax', 'modern-hotel-booking'),
            'title_revenue_trends'              => __('Revenue Trends', 'modern-hotel-booking'),
            'title_occupancy_dow'               => __('Occupancy by Day of Week', 'modern-hotel-booking'),
            'title_occupancy_forecast'          => __('30-Day Occupancy Forecast', 'modern-hotel-booking'),
            'title_booking_sources'             => __('Booking Sources', 'modern-hotel-booking'),
            'msg_no_source_data'                => __('No booking data available', 'modern-hotel-booking'),
            'title_revenue_by_source'           => __('Revenue by Source', 'modern-hotel-booking'),
            'label_source_col'                  => __('Source', 'modern-hotel-booking'),
            'label_bookings_col'                => __('Bookings', 'modern-hotel-booking'),
            'label_revenue_col'                 => __('Revenue', 'modern-hotel-booking'),
            'label_collected_col'               => __('Collected', 'modern-hotel-booking'),
            'title_room_type_performance'       => __('Room Type Performance', 'modern-hotel-booking'),
            'msg_no_room_performance'           => __('No room performance data available', 'modern-hotel-booking'),
            'label_room_type_col'               => __('Room Type', 'modern-hotel-booking'),
            'label_occupancy_col'               => __('Occupancy', 'modern-hotel-booking'),
            'title_tax_vat_analytics'           => __('Tax / VAT Analytics', 'modern-hotel-booking'),
            'title_tax_breakdown'               => __('Tax Breakdown by Rate', 'modern-hotel-booking'),
            'label_tax_rate_col'                => __('Tax Rate', 'modern-hotel-booking'),
            'label_tax_collected_col'           => __('Tax Collected', 'modern-hotel-booking'),
            'title_payment_methods'             => __('Payment Methods', 'modern-hotel-booking'),
            'msg_no_payment_data'               => __('No payment data available', 'modern-hotel-booking'),
            'title_payment_status_breakdown'    => __('Payment Status Breakdown', 'modern-hotel-booking'),
            'label_payment_status_col'          => __('Status', 'modern-hotel-booking'),
            'label_payment_count_col'           => _x('Count', 'column header', 'modern-hotel-booking'),
            'label_payment_total_col'           => _x('Total', 'column header', 'modern-hotel-booking'),
            'title_realtime_activity'           => __('Real-time Activity', 'modern-hotel-booking'),
            'title_recent_bookings'             => __('Recent Bookings', 'modern-hotel-booking'),
            'msg_no_recent_bookings'            => __('No recent bookings', 'modern-hotel-booking'),
            'title_today_operations'           => __('Today\'s Operations', 'modern-hotel-booking'),
            'title_checkins'                    => __('Check-ins', 'modern-hotel-booking'),
            'title_checkouts'                   => __('Check-outs', 'modern-hotel-booking'),
            'msg_no_ops_today'                  => __('No check-ins or check-outs today', 'modern-hotel-booking'),
            'title_alerts'                      => __('Alerts', 'modern-hotel-booking'),
            'title_failed_payments'             => __('Failed Payments', 'modern-hotel-booking'),
            'title_recent_cancellations'        => __('Recent Cancellations', 'modern-hotel-booking'),
            'title_monthly_demand'              => __('Monthly Demand', 'modern-hotel-booking'),
            'title_lead_time_analysis'          => __('Lead Time Analysis', 'modern-hotel-booking'),
            'msg_no_lead_time_data'             => __('No lead time data available', 'modern-hotel-booking'),
            'label_lead_time_col'               => __('Lead Time', 'modern-hotel-booking'),
            'title_cancellation_tracking'       => __('Cancellation Tracking', 'modern-hotel-booking'),
            'msg_from_cancelled_bookings'       => __('From cancelled bookings', 'modern-hotel-booking'),
            'label_total_cancelled'             => __('Total cancelled', 'modern-hotel-booking'),
            'title_cancellations_by_lead_time'  => __('Cancellations by Lead Time', 'modern-hotel-booking'),
            'label_revenue_impact_col'          => __('Revenue Impact', 'modern-hotel-booking'),
            'label_export_financial'            => __('Export Financial Report (CSV)', 'modern-hotel-booking'),
            'label_export_occupancy'            => __('Export Occupancy Report (CSV)', 'modern-hotel-booking'),
            'label_export_tax'                  => __('Export Tax Report (CSV)', 'modern-hotel-booking'),
            'label_export_payments'             => __('Export Payments (CSV)', 'modern-hotel-booking'),
            
            /* Update & Handler Labels */
            'msg_no_changelog'                  => __('There is no changelog available.', 'modern-hotel-booking'),
            'label_view_details'                => __('View details', 'modern-hotel-booking'),
            /* translators: %s: Plugin name */
            'msg_more_info_about'               => __('More information about %s', 'modern-hotel-booking'),
            'label_check_for_updates'           => __('Check for updates', 'modern-hotel-booking'),
            /* translators: %s: plugin name */
            'msg_plugin_up_to_date'             => __('The %s plugin is up to date.', 'modern-hotel-booking'),
            /* translators: %s: plugin name */
            'msg_plugin_update_available'       => __('A new version of the %s plugin is available.', 'modern-hotel-booking'),
            /* translators: %s: plugin name */
            'msg_update_checker_failed'         => __('Could not determine if updates are available for %s.', 'modern-hotel-booking'),
            /* translators: %s: update checker status string */
            'msg_unknown_update_status'         => __('Unknown update checker status "%s"', 'modern-hotel-booking'),
            'label_net_revenue'                 => __('Net Revenue', 'modern-hotel-booking'),
            'label_gross_revenue'               => __('Gross Revenue', 'modern-hotel-booking'),
            'label_before_tax'                  => __('Before tax', 'modern-hotel-booking'),
            'label_including_tax'               => __('Including tax', 'modern-hotel-booking'),
            'label_taxed_bookings_lower'        => __('taxed bookings', 'modern-hotel-booking'),
            'label_total_tax_collected'         => __('Total Tax Collected', 'modern-hotel-booking'),
            'label_insufficient_permissions_admin' => __('Insufficient permissions for this administrative action.', 'modern-hotel-booking'),
            'msg_insufficient_perms_short'       => __('Insufficient permissions.', 'modern-hotel-booking'),
            'label_paid_full_short'              => __('Paid Full', 'modern-hotel-booking'),
            /* translators: %s: balance amount */
            'label_bal_sprintf'                  => __('Bal: %s', 'modern-hotel-booking'),
            /* translators: %s: pending amount */
            'label_pending_sprintf'              => __('Pending: %s', 'modern-hotel-booking'),

            'msg_confirm_remove'                 => __('Are you sure?', 'modern-hotel-booking'),
            'msg_room_type_deleted'              => __('Room Type Deleted.', 'modern-hotel-booking'),
            'msg_room_type_updated'              => __('Room Type Updated!', 'modern-hotel-booking'),
            'msg_room_type_added'                => __('Room Type Added!', 'modern-hotel-booking'),
            'title_room_types_config'            => __('Room Types & Configuration', 'modern-hotel-booking'),
            'desc_room_types_config'             => __('Define your property layout, base pricing, and maximum capacities.', 'modern-hotel-booking'),
            'title_modify_room_config'           => __('Modify Room Configuration', 'modern-hotel-booking'),
            'title_define_new_room'              => __('Define New Room Type', 'modern-hotel-booking'),
            'btn_save_config'                    => __('Save Configuration', 'modern-hotel-booking'),
            'btn_create_room_type'               => __('Create Room Type', 'modern-hotel-booking'),
            'msg_room_deleted'                   => __('Room Deleted.', 'modern-hotel-booking'),
            'msg_feed_added'                     => __('Feed Added!', 'modern-hotel-booking'),
            'msg_sync_completed'                 => __('Sync Completed.', 'modern-hotel-booking'),
            'msg_room_updated'                   => __('Room Updated!', 'modern-hotel-booking'),
            'msg_room_added'                     => __('Room Added!', 'modern-hotel-booking'),
            'title_room_inventory'               => __('Room Inventory', 'modern-hotel-booking'),
            'desc_room_inventory'                => __('Real-time overview of your physical units, their status, and synchronization settings.', 'modern-hotel-booking'),
            'msg_no_extras_desc'                 => __('No extras defined yet. Create your first service add-on below.', 'modern-hotel-booking'),
            'msg_extras_examples'                => __('Add breakfast, airport transfers, tour packages, or custom experiences.', 'modern-hotel-booking'),
            /* translators: %s: unit/room name or number */
            'title_ical_sync_sprintf'            => __('iCal Synchronization — Unit %s', 'modern-hotel-booking'),
            'label_ago_short'                    => _x('ago', 'time elapsed', 'modern-hotel-booking'),
            'title_modify_unit'                  => __('Modify Unit Registration', 'modern-hotel-booking'),
            'title_new_unit'                     => __('New Room Registration', 'modern-hotel-booking'),
            'btn_save_unit'                      => __('Save Unit Data', 'modern-hotel-booking'),
            'btn_register_unit'                  => __('Register New Unit', 'modern-hotel-booking'),
            
            /* Licensing & Validation */
            /* translators: %s: time remaining in grace period (e.g. "3 days") */
            'msg_license_grace'                 => __('Could not verify license. Grace period active: %s remaining.', 'modern-hotel-booking'),
            'msg_license_disabled'              => __('License verification failed. Pro features have been disabled. Please check your license or contact support.', 'modern-hotel-booking'),
            'msg_license_invalid_format'        => __('Invalid license key format.', 'modern-hotel-booking'),
            'msg_license_unexpected_format'     => __('Unexpected response format from server. Please contact support.', 'modern-hotel-booking'),
            'msg_license_invalid_server_error'  => __('Invalid license key or server error.', 'modern-hotel-booking'),
            /* translators: %d: number of hours since last successful verification */
            'msg_license_server_unreachable_recent' => __('Could not reach the license server, but your license was last verified %d hour(s) ago and remains active.', 'modern-hotel-booking'),

            /* Frontend Blocks */
            'label_block_business_info'         => __('Hotel: Business Info', 'modern-hotel-booking'),

            /* Settings Dashboard & Pro Features */
            'label_payment_processing'          => __('Payment Processing', 'modern-hotel-booking'),
            'label_stripe_integration'          => __('Stripe Integration', 'modern-hotel-booking'),
            'msg_stripe_desc'                   => __('Accept payments with Stripe including Apple Pay, Google Pay, and link support.', 'modern-hotel-booking'),
            'label_paypal_integration'          => __('PayPal Integration', 'modern-hotel-booking'),
            'msg_paypal_desc'                   => __('Accept PayPal payments with automatic webhook verification and status updates.', 'modern-hotel-booking'),
            'label_invoicing_system'            => __('Invoicing System', 'modern-hotel-booking'),
            'msg_invoicing_desc'                => __('Professional PDF invoices generated automatically. Access via booking management.', 'modern-hotel-booking'),
            'label_invoicing_available'         => __('Available', 'modern-hotel-booking'),
            'label_coming_soon'                 => __('Coming Soon', 'modern-hotel-booking'),
            'label_partial_payments'            => __('Partial Payments', 'modern-hotel-booking'),
            'msg_partial_payments_desc'         => __('Accept deposits or split payments. Complete control over payment thresholds.', 'modern-hotel-booking'),
            'label_hmac_webhooks'               => __('HMAC Webhooks', 'modern-hotel-booking'),
            'msg_hmac_webhooks_desc'            => __('Secure HMAC-signed webhooks with delivery logging, payload inspector, and connection testing tools.', 'modern-hotel-booking'),
            'label_tax_management'              => __('Tax Management', 'modern-hotel-booking'),
            'label_vat_tax_system'              => __('VAT/TAX System', 'modern-hotel-booking'),
            'msg_vat_tax_desc'                  => __('Three modes: VAT inclusive, Sales Tax exclusive, or Disabled. Flexible configuration.', 'modern-hotel-booking'),
            'label_business_information_title'  => __('Business Information', 'modern-hotel-booking'),
            'msg_business_info_desc'            => __('Display company details, bank transfer info, and Revolut details on invoices.', 'modern-hotel-booking'),
            'label_whatsapp_integration'        => __('WhatsApp Integration', 'modern-hotel-booking'),
            'msg_whatsapp_desc'                 => __('Direct inquiry button and chat functionality for faster guest communication.', 'modern-hotel-booking'),
            'label_country_specific_vat'        => __('Country-Specific VAT', 'modern-hotel-booking'),
            'msg_country_vat_desc'              => __('Set different VAT rates per country for international guests.', 'modern-hotel-booking'),
            'label_tax_breakdown_display'       => __('Tax Breakdown Display', 'modern-hotel-booking'),
            'msg_tax_breakdown_desc'            => __('Detailed tax display by room, children, and extras in booking confirmations.', 'modern-hotel-booking'),
            'label_booking_management'           => __('Booking Management', 'modern-hotel-booking'),
            'label_seasonal_pricing'            => __('Seasonal Pricing', 'modern-hotel-booking'),
            'msg_seasonal_pricing_desc'         => __('Set automated weekend and holiday multipliers for dynamic pricing.', 'modern-hotel-booking'),
            'label_booking_extras'              => __('Booking Extras', 'modern-hotel-booking'),
            'msg_booking_extras_desc'           => __('Offer guided tours, airport transfers, breakfast packages, and local experiences.', 'modern-hotel-booking'),
            'label_ical_synchronization'        => __('iCal Synchronization', 'modern-hotel-booking'),

            /* Settings Messages (v2.3.0) */
            'msg_multilingual_saved'             => __('Multilingual settings saved successfully.', 'modern-hotel-booking'),
            'msg_general_saved'                  => __('General settings saved successfully.', 'modern-hotel-booking'),
            'msg_amenity_removed'                => __('Amenity removed successfully.', 'modern-hotel-booking'),
            'msg_amenity_added'                  => __('Amenity added successfully.', 'modern-hotel-booking'),

            /* Shared UI & Table Headers */
            'btn_add'                            => _x('Add', 'action', 'modern-hotel-booking'),
            'label_column_label'                 => _x('Label', 'table column header', 'modern-hotel-booking'),
            'label_column_key'                   => _x('Internal Key', 'table column header', 'modern-hotel-booking'),
            'label_column_action'                => _x('Action', 'table column header', 'modern-hotel-booking'),

            /* Business & Shortcodes */
            'settings_label_desc'                => __('Description', 'modern-hotel-booking'),
            'shortcode_desc_company_info'        => __('Displays the property company information.', 'modern-hotel-booking'),
            'shortcode_desc_whatsapp'            => __('Displays a WhatsApp contact button.', 'modern-hotel-booking'),
            'shortcode_desc_banking'             => __('Displays bank transfer details for manual payments.', 'modern-hotel-booking'),
            'shortcode_desc_revolut'             => __('Displays Revolut payment details and QR code.', 'modern-hotel-booking'),
            'shortcode_desc_card'                => __('Displays a modern business card with property info.', 'modern-hotel-booking'),
            'shortcode_desc_all_methods'         => __('Displays all available manual payment methods for a booking.', 'modern-hotel-booking'),
            'label_desc_room_alt_text'           => __('Default ALT text for room images if not provided.', 'modern-hotel-booking'),

            /* Amenities & Tax Settings */
            'label_tax_disabled_opt'            => __('Disabled', 'modern-hotel-booking'),
            'label_free_wifi'                   => __('Free WiFi', 'modern-hotel-booking'),
            'label_air_conditioning'            => __('Air Conditioning', 'modern-hotel-booking'),
            'label_smart_tv'                    => __('Smart TV', 'modern-hotel-booking'),
            'label_breakfast_included'          => __('Breakfast Included', 'modern-hotel-booking'),
            'label_pool_view'                   => __('Pool View', 'modern-hotel-booking'),
            'label_room_amenities_title'        => __('Room Amenities', 'modern-hotel-booking'),
            'msg_room_amenities_desc'           => __('Manage the list of amenities available for rooms. After adding here, you can translate them in the "Frontend Labels" tab.', 'modern-hotel-booking'),
            'label_add_new_amenity'             => __('Add New Amenity', 'modern-hotel-booking'),
            'label_no_amenities_found'          => __('No amenities found.', 'modern-hotel-booking'),
            'label_are_you_sure'                => __('Are you sure?', 'modern-hotel-booking'),

            // Email Labels
            'label_email_payment_summary'  => __('Payment Summary', 'modern-hotel-booking'),
            'label_email_total_amount'     => __('Total Amount:', 'modern-hotel-booking'),
            'label_email_deposit_paid'     => __('Deposit Paid:', 'modern-hotel-booking'),
            'label_email_deposit_required' => __('Deposit Required:', 'modern-hotel-booking'),
            'label_email_remaining_balance' => __('Remaining Balance:', 'modern-hotel-booking'),
            'label_email_due_at_checkin'   => __('Due at check-in or as per arrival arrangements.', 'modern-hotel-booking'),
            'label_email_paid_full'        => __('Paid in Full', 'modern-hotel-booking'),
            'label_email_non_refundable'   => __('Non-Refundable:', 'modern-hotel-booking'),
            'msg_email_non_refundable_desc' => __('This deposit is non-refundable.', 'modern-hotel-booking'),
            /* translators: %s: cancellation deadline date */
            'msg_email_refund_deadline'    => __('Refund Deadline: Cancel by %s to qualify for a deposit refund.', 'modern-hotel-booking'),

            // Licensing & Plural Labels
            'msg_license_not_configured'   => __('License system not configured. Please contact support.', 'modern-hotel-booking'),
            'label_day'                    => _x('day', 'time unit', 'modern-hotel-booking'),
            'label_days'                   => _x('days', 'time unit', 'modern-hotel-booking'),
            'label_hour'                   => _x('hour', 'time unit', 'modern-hotel-booking'),
            'label_hours'                  => _x('hours', 'time unit', 'modern-hotel-booking'),
            'label_connection'             => __('connection', 'modern-hotel-booking'),
            'label_connections'            => __('connections', 'modern-hotel-booking'),

            /* v2.3.0 "Diamond-Grade" Labels */
            'label_amenities_hub'            => __('Amenities Hub', 'modern-hotel-booking'),
            'label_booking_table_headers'    => __('Booking Table Headers', 'modern-hotel-booking'),
            'label_stripe_currency_warnings' => __('Stripe Currency Warnings', 'modern-hotel-booking'),
            'label_ical_module_description'  => __('iCal Module Description', 'modern-hotel-booking'),
            'label_session_timeout_warning'  => __('Session Timeout Warning', 'modern-hotel-booking'),

            /* AI Concierge & Conversational Feedback */
            'ai_feedback_invalid_email'      => __('The email address you provided doesn\'t look quite right. Could you please double-check it?', 'modern-hotel-booking'),
            /* translators: 1: Room name, 2: Check-in date, 3: Check-out date */
            'ai_feedback_booking_success'    => _x('I\'ve successfully booked the %1$s for you from %2$s to %3$s.', 'ai feedback', 'modern-hotel-booking'),
            'ai_feedback_error_occurred'     => __('I encountered a slight issue while processing your booking. Let me try that again.', 'modern-hotel-booking'),
        );

$labels['email_placeholders_desc'] = __('Available placeholders: {customer_name}, {customer_email}, {customer_phone}, {site_name}, {booking_id}, {booking_token}, {status}, {check_in}, {check_out}, {check_in_time}, {check_out_time}, {nights}, {total_price}, {guests}, {children}, {children_ages}, {room_name}, {custom_fields}, {booking_extras}, {payment_details}, {tax_breakdown}, {tax_breakdown_text}, {tax_total}, {tax_registration_number}, {company_name}, {company_address}, {company_phone}, {company_email}, {company_website}, {company_registration}, {whatsapp_number}, {whatsapp_link}, {view_url}, {special_requests}, {arrival_time}', 'modern-hotel-booking');

return $labels;

    }

    /**
     * Register a string for translation with WPML/Polylang.
     *
     * @param string $name    String name/identifier.
     * @param string $value   String value.
     * @param string $context Context/package name.
     * @return void
     */
    public static function register_string(string $name, string $value, string $context = 'Modern Hotel Booking'): void
    {
        $plugin = self::detect_plugin();

        if ('wpml' === $plugin) {
            do_action('wpml_register_single_string', $context, $name, $value); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML action
        } elseif ('polylang' === $plugin) {
            if (function_exists('pll_register_string')) {
                call_user_func('pll_register_string', $name, $value, $context);
            }
        }
    }

    /**
     * Get a translated string from WPML/Polylang
     *
     * @param string $name String name/identifier
     * @param string $default Default value if not translated
     * @param string $context Context/package name
     * @param string|null $language Language code (optional)
     * @return string Translated string
     */
    public static function get_translated_string(string $name, string $default = '', string $context = 'Modern Hotel Booking', ?string $language = null): string
    {
        $plugin = self::detect_plugin();

        if (null === $language) {
            $language = self::get_current_language();
        }

        if ('wpml' === $plugin) {
            $translated = apply_filters('wpml_translate_single_string', $default, $context, $name, $language); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter
            return '' !== $translated ? $translated : $default;
        } elseif ('polylang' === $plugin) {
            if (function_exists('pll__')) {
                $translated = call_user_func('pll__', $default);
                return '' !== $translated ? $translated : $default;
            }
        }

        $decoded = self::decode($default, $language);
        return (null !== $decoded && '' !== $decoded) ? $decoded : $default;
    }

    /**
     * Register all plugin strings for translation
     * Should be called on plugin activation or admin init
     */
    public static function register_plugin_strings(): void
    {
        // Register tax-related strings
        $tax_label = get_option('mhbo_tax_label', 'VAT');
        self::register_string('Tax Label', $tax_label, 'MHBO Tax Settings');

        // Register email template subjects and messages
        $statuses = ['pending', 'confirmed', 'cancelled', 'payment'];
        foreach ($statuses as $status) {
            $subject = get_option("mhbo_email_{$status}_subject", '');
            if ('' !== $subject) {
                self::register_string("Email {$status} Subject", $subject, 'MHBO Email Templates');
            }
            $message = get_option("mhbo_email_{$status}_message", '');
            if ('' !== $message) {
                self::register_string("Email {$status} Message", $message, 'MHBO Email Templates');
            }
        }

        // Register frontend labels
        $labels = self::get_all_default_labels();
        foreach ($labels as $key => $default_val) {
            $label = get_option("mhbo_label_{$key}", '');
            if ('' !== $label) {
                self::register_string("Label: {$key}", $label, 'MHBO Frontend Labels');
            } else {
                self::register_string("Label: {$key}", $default_val, 'MHBO Frontend Labels');
            }
        }
    }

    /**
     * Translate a booking status slug.
     *
     * @param string $status The status slug.
     * @return string The translated status.
     */
    public static function translate_status(string $status): string
    {
        switch ($status) {
            case 'pending':
                return self::get_label('status_pending');
            case 'confirmed':
                return self::get_label('status_confirmed');
            case 'cancelled':
                return self::get_label('status_cancelled');
            default:
                $key = "status_{$status}";
                $label = self::get_label($key);
                return $label !== $key ? $label : ucfirst($status);
        }
    }

    /**
     * Translate a payment method slug.
     *
     * @param string $method The payment method slug.
     * @return string The translated payment method.
     */
    public static function translate_payment_method(string $method): string
    {
        switch ($method) {
            case 'onsite':
            case 'arrival':
                return self::get_label('label_pay_arrival');
            case 'stripe':
                return self::get_label('gateway_stripe');
            case 'paypal':
                return self::get_label('gateway_paypal');
            default:
                return ucfirst($method);
        }
    }

    /**
     * Translate a payment status slug.
     *
     * @param string $status
     * @return string
     */
    public static function translate_payment_status($status): string
    {
        switch ($status) {
            case 'pending':
                return self::get_label('status_pending');
            case 'processing':
                return self::get_label('status_processing');
            case 'completed':
                return self::get_label('status_completed');
            case 'failed':
                return self::get_label('status_failed');
            case 'refunded':
                return self::get_label('status_refunded');
            default:
                return ucfirst($status);
        }
    }

    /**
     * Get the translated ID for a given WordPress Page ID based on the current language context.
     * Supports WPML and Polylang via `wpml_object_id` filter and `pll_get_post`.
     *
     * @param int $page_id The original Page ID.
     * @return int The translated Page ID, or the original if no translation is found.
     */
    public static function get_translated_page_id(int $page_id): int
    {
        if ($page_id <= 0) {
            return 0;
        }

        $current_lang = self::get_current_language();

        // 0. Explicit per-language booking page setting override
        if ($current_lang !== self::get_default_language()) {
            $lang_specific_page = (int) get_option('mhbo_booking_page_' . $current_lang, 0);
            if ($lang_specific_page > 0) {
                return $lang_specific_page;
            }
        }

        // 1. WPML / Polylang compatibility layer
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
        if (has_filter('wpml_object_id')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
            $translated_id = apply_filters('wpml_object_id', $page_id, 'page', true, $current_lang);
            if ($translated_id && is_numeric($translated_id)) {
                return (int) $translated_id;
            }
        }

        // 2. Polylang native (fallback)
        if (function_exists('pll_get_post')) {
            /** @phpstan-ignore argument.type */
            $translated_id = call_user_func('pll_get_post', $page_id, $current_lang);
            if ($translated_id && is_numeric($translated_id)) {
                return (int) $translated_id;
            }
        }

        // 3. Bogo native compatibility
        if (function_exists('bogo_get_post_translations')) {
            /** @var array<string, \WP_Post>|false $translations */
            /** @phpstan-ignore argument.type */
            $translations = call_user_func('bogo_get_post_translations', $page_id);
            if (is_array($translations)) {
                $current_locale = get_locale();
                if (isset($translations[$current_locale])) {
                    return (int) $translations[$current_locale]->ID;
                }
                foreach ($translations as $locale_key => $trans_post) {
                    if (substr($locale_key, 0, 2) === $current_lang) {
                        return (int) $trans_post->ID;
                    }
                }
            }
        }

        return $page_id;
    }

    /**
     * Check if a currency code is a valid ISO-4217 code.
     *
     * This list includes common currencies supported by major payment processors (Stripe/PayPal).
     *
     * @param string $code 3-letter currency code.
     * @return bool
     */
    public static function is_valid_currency(string $code): bool
    {
        $code = strtoupper(trim($code));
        $valid_codes = [
            'USD',
            'EUR',
            'GBP',
            'JPY',
            'CAD',
            'AUD',
            'CHF',
            'CNY',
            'SEK',
            'NZD',
            'KRW',
            'SGD',
            'NOK',
            'MXN',
            'INR',
            'RUB',
            'ZAR',
            'TRY',
            'BRL',
            'TWD',
            'DKK',
            'PLN',
            'THB',
            'IDR',
            'HUF',
            'CZK',
            'ILS',
            'CLP',
            'PHP',
            'AED',
            'COP',
            'SAR',
            'MYR',
            'RON',
            'VND',
            'ARS',
            'EGP',
            'IRR',
            'KWD',
            'LKR',
            'UAH',
            'VEF',
            'HNL',
            'GTQ',
            'CRC',
            'DOP',
            'PEN',
            'UYU',
            'PYG',
            'BOB',
            'NIO',
            'ISK',
            'HRK',
            'BGN',
            'EEK',
            'SKK',
            'SIT',
            'CYP',
            'MTL',
            'TZS',
            'UGX',
            'KES',
            'GHS',
            'NGN',
            'ZMW',
            'MUR',
            'SCR',
            'MGA',
            'MAD',
            'TND',
            'OMR',
            'BHD',
            'JOD',
            'LBP',
            'AMD',
            'AZN',
            'GEL',
            'KZT',
            'UZS',
            'TJS',
            'KGS',
            'AFN',
            'PKR',
            'BDT',
            'NPR',
            'MVR',
            'MMK',
            'LAK',
            'KHR',
            'MOP',
            'HKD',
            'FJD',
            'XPF',
            'XAF',
            'XOF',
            'XCD',
            'ANG',
            'AWG',
            'BBD',
            'BSD',
            'BZD',
            'BMD',
            'GIP',
            'JMD',
            'KYD',
            'LRD',
            'SBD',
            'SRD',
            'TOP',
            'TTD',
            'VUV',
            'WST',
            'XDR'
        ];
        return in_array($code, $valid_codes, true);
    }
}
