<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Multi-currency + geo pricing engine (roadmap-2 B6).
 *
 * Lightweight WOOCS-style model: the visitor picks a display currency via
 * ?currency=XXX (or a geo-IP default), the runtime multiplies product prices
 * by an owner-maintained manual rate AND switches the active currency code —
 * so cart, totals and the ORDER are recorded in the selected currency.
 *
 * Config lives in the autoloaded option `wpultra_currency`:
 *   {
 *     enabled:      bool,
 *     base:         'BDT'  (auto-filled from get_woocommerce_currency() at save time),
 *     currencies:   { 'USD' => ['rate' => 0.0091, 'symbol' => '$', 'decimals' => 2], ... }
 *                   (rate: 1 base unit = rate × code units),
 *     geo_defaults: { 'US' => 'USD', 'GB' => 'GBP', ... }
 *   }
 *
 * PURE functions (prefix wpultra_cur_) come first — no WordPress/WooCommerce
 * dependency, unit-tested via tests/woo-currency.test.php. The WP/WC runtime
 * (wpultra_currency_*) follows, every WP call guarded.
 */

// ---------------------------------------------------------------------------
// PURE: code normalization
// ---------------------------------------------------------------------------

/** Trim + uppercase a currency code. Pure. */
function wpultra_cur_normalize_code(string $code): string {
    return strtoupper(trim($code));
}

// ---------------------------------------------------------------------------
// PURE: conversion math
// ---------------------------------------------------------------------------

/**
 * Convert an amount (numeric string or number, as WooCommerce price getters
 * hand it over) by a manual rate. Returns the converted amount as a numeric
 * string rounded to 4 decimal places internally — WooCommerce formats display
 * decimals itself via wc_get_price_decimals.
 *
 * Passthrough rules:
 *  - ''            → '' (unset prices stay unset — critical for sale_price)
 *  - non-numeric   → returned unchanged (never mangle unexpected input)
 *  - rate <= 0     → returned unchanged ("don't convert" sentinel)
 * Pure.
 */
function wpultra_cur_convert($amount, float $rate): string {
    if ($amount === '' || $amount === null) { return ''; }
    if (!is_string($amount) && !is_int($amount) && !is_float($amount)) { return ''; }
    if (!is_numeric($amount)) { return (string) $amount; }
    if ($rate <= 0.0) { return (string) $amount; }
    return (string) round(((float) $amount) * $rate, 4);
}

/**
 * Rate lookup: the base currency converts at 1.0, a configured currency at
 * its configured rate, an unknown code at 0.0 (meaning "don't convert").
 * Pure.
 */
function wpultra_cur_rate(array $cfg, string $code): float {
    $code = wpultra_cur_normalize_code($code);
    if ($code === '') { return 0.0; }
    $base = wpultra_cur_normalize_code((string) ($cfg['base'] ?? ''));
    if ($code === $base && $base !== '') { return 1.0; }
    $currencies = is_array($cfg['currencies'] ?? null) ? $cfg['currencies'] : [];
    foreach ($currencies as $c => $spec) {
        if (wpultra_cur_normalize_code((string) $c) !== $code) { continue; }
        $rate = is_array($spec) ? ($spec['rate'] ?? 0) : 0;
        return is_numeric($rate) ? (float) $rate : 0.0;
    }
    return 0.0;
}

// ---------------------------------------------------------------------------
// PURE: config validation
// ---------------------------------------------------------------------------

/**
 * Validate a full config array. Returns true when valid, or a string
 * describing the first problem found.
 *
 * Rules: currency codes are 3-letter uppercase; every rate > 0; decimals (if
 * present) 0..4; symbol (if present) a non-empty string; geo_defaults maps
 * 2-letter uppercase country codes to a configured currency code or the base.
 * Pure.
 */
function wpultra_cur_validate_config(array $cfg) {
    if (array_key_exists('enabled', $cfg) && !is_bool($cfg['enabled'])) {
        return 'enabled must be a boolean';
    }

    $base = '';
    if (array_key_exists('base', $cfg)) {
        if (!is_string($cfg['base']) || !preg_match('/^[A-Z]{3}$/', $cfg['base'])) {
            return 'base must be a 3-letter uppercase currency code';
        }
        $base = $cfg['base'];
    }

    $codes = [];
    if (array_key_exists('currencies', $cfg)) {
        if (!is_array($cfg['currencies'])) { return 'currencies must be an object of code => {rate, symbol?, decimals?}'; }
        foreach ($cfg['currencies'] as $code => $spec) {
            if (!is_string($code) || !preg_match('/^[A-Z]{3}$/', $code)) {
                return "currency code must be 3-letter uppercase: " . var_export($code, true);
            }
            if ($base !== '' && $code === $base) {
                return "currency $code is the base currency — do not configure a rate for it";
            }
            if (!is_array($spec)) { return "currencies.$code must be an object with a rate"; }
            if (!isset($spec['rate']) || !is_numeric($spec['rate'])) { return "currencies.$code.rate must be numeric"; }
            if ((float) $spec['rate'] <= 0) { return "currencies.$code.rate must be > 0"; }
            if (array_key_exists('decimals', $spec)) {
                $d = $spec['decimals'];
                if (!is_numeric($d) || (int) $d != (float) $d || (int) $d < 0 || (int) $d > 4) {
                    return "currencies.$code.decimals must be an integer 0..4";
                }
            }
            if (array_key_exists('symbol', $spec)) {
                if (!is_string($spec['symbol']) || $spec['symbol'] === '') {
                    return "currencies.$code.symbol must be a non-empty string";
                }
            }
            foreach (array_keys($spec) as $k) {
                if (!in_array($k, ['rate', 'symbol', 'decimals'], true)) {
                    return "currencies.$code has unknown key: $k";
                }
            }
            $codes[] = $code;
        }
    }

    if (array_key_exists('geo_defaults', $cfg)) {
        if (!is_array($cfg['geo_defaults'])) { return 'geo_defaults must be an object of countryCode => currencyCode'; }
        foreach ($cfg['geo_defaults'] as $country => $code) {
            if (!is_string($country) || !preg_match('/^[A-Z]{2}$/', $country)) {
                return 'geo_defaults country must be a 2-letter uppercase code: ' . var_export($country, true);
            }
            if (!is_string($code) || !preg_match('/^[A-Z]{3}$/', $code)) {
                return "geo_defaults.$country must be a 3-letter uppercase currency code";
            }
            if (!in_array($code, $codes, true) && ($base === '' || $code !== $base)) {
                return "geo_defaults.$country maps to $code which is neither a configured currency nor the base";
            }
        }
    }

    foreach (array_keys($cfg) as $k) {
        if (!in_array($k, ['enabled', 'base', 'currencies', 'geo_defaults'], true)) {
            return "unknown config key: $k";
        }
    }

    return true;
}

// ---------------------------------------------------------------------------
// PURE: visitor currency selection
// ---------------------------------------------------------------------------

/**
 * Resolve the visitor's display currency with precedence
 *   query param > cookie > geo-IP default > base,
 * where an invalid value at any level falls through to the next.
 *
 * A value is valid when it names a configured currency OR the base itself
 * (visitors may explicitly switch back to base). The geo level only applies
 * when geo_defaults is non-empty and the country maps to a valid code.
 * Pure.
 */
function wpultra_cur_pick(array $cfg, string $query_param, string $cookie, string $geo_country): string {
    $base = wpultra_cur_normalize_code((string) ($cfg['base'] ?? ''));
    $currencies = is_array($cfg['currencies'] ?? null) ? $cfg['currencies'] : [];
    $allowed = [];
    foreach (array_keys($currencies) as $c) { $allowed[] = wpultra_cur_normalize_code((string) $c); }
    if ($base !== '') { $allowed[] = $base; }

    $q = wpultra_cur_normalize_code($query_param);
    if ($q !== '' && in_array($q, $allowed, true)) { return $q; }

    $c = wpultra_cur_normalize_code($cookie);
    if ($c !== '' && in_array($c, $allowed, true)) { return $c; }

    $geo_defaults = is_array($cfg['geo_defaults'] ?? null) ? $cfg['geo_defaults'] : [];
    if (!empty($geo_defaults)) {
        $country = strtoupper(trim($geo_country));
        if ($country !== '' && isset($geo_defaults[$country])) {
            $g = wpultra_cur_normalize_code((string) $geo_defaults[$country]);
            if ($g !== '' && in_array($g, $allowed, true)) { return $g; }
        }
    }

    return $base;
}

// ---------------------------------------------------------------------------
// PURE: preview table
// ---------------------------------------------------------------------------

/**
 * Build a conversion table for a base-currency amount: one entry per
 * configured currency, {code => converted numeric string}. Pure.
 */
function wpultra_cur_preview(array $cfg, float $base_amount): array {
    $out = [];
    $currencies = is_array($cfg['currencies'] ?? null) ? $cfg['currencies'] : [];
    foreach ($currencies as $code => $spec) {
        $rate = is_array($spec) && isset($spec['rate']) && is_numeric($spec['rate']) ? (float) $spec['rate'] : 0.0;
        $out[wpultra_cur_normalize_code((string) $code)] = wpultra_cur_convert((string) $base_amount, $rate);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// PURE: switcher markup
// ---------------------------------------------------------------------------

/**
 * Build the [wpultra_currency_switcher] form: a GET <select> of currency
 * codes that preserves the current URL's other query params as hidden
 * inputs. Everything is escaped here so the renderer stays trivial. Pure.
 */
function wpultra_cur_switcher_html(array $codes, string $selected, string $action, array $params): string {
    $esc = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    $selected = wpultra_cur_normalize_code($selected);

    $hidden = '';
    foreach ($params as $k => $v) {
        if (!is_scalar($v)) { continue; }
        if ((string) $k === 'currency') { continue; }
        $hidden .= '<input type="hidden" name="' . $esc((string) $k) . '" value="' . $esc((string) $v) . '">';
    }

    $options = '';
    foreach ($codes as $code) {
        $code = wpultra_cur_normalize_code((string) $code);
        if ($code === '') { continue; }
        $sel = $code === $selected ? ' selected' : '';
        $options .= '<option value="' . $esc($code) . '"' . $sel . '>' . $esc($code) . '</option>';
    }

    return '<form class="wpultra-currency-switcher" method="get" action="' . $esc($action) . '">'
        . $hidden
        . '<select name="currency" onchange="this.form.submit()">' . $options . '</select>'
        . '<noscript><button type="submit">Go</button></noscript>'
        . '</form>';
}

// ---------------------------------------------------------------------------
// WP/WC runtime — everything below touches WordPress and is guarded.
// ---------------------------------------------------------------------------

/** Load + sanity-shape the config option. */
function wpultra_currency_config(): array {
    $cfg = function_exists('get_option') ? get_option('wpultra_currency', []) : [];
    return is_array($cfg) ? $cfg : [];
}

/** Per-request selected currency code (static store). '' = not resolved yet / base. */
function wpultra_currency_selected(?string $set = null): string {
    static $selected = '';
    if ($set !== null) { $selected = $set; }
    return $selected;
}

/**
 * Runtime entry point — the controller calls this on plugins_loaded.
 * Cheap guard: the autoloaded option must be enabled AND have at least one
 * rate configured before any hook is armed.
 */
function wpultra_currency_boot(): void {
    if (!function_exists('add_action') || !function_exists('get_option')) { return; }

    // The switcher shortcode is always registered (it renders '' when the
    // feature is off) so a disabled feature never leaks raw shortcode text.
    if (function_exists('add_shortcode')) {
        add_shortcode('wpultra_currency_switcher', 'wpultra_currency_switcher_shortcode');
    }

    $cfg = wpultra_currency_config();
    if (empty($cfg['enabled'])) { return; }
    if (!is_array($cfg['currencies'] ?? null) || count($cfg['currencies']) < 1) { return; }

    add_action('init', 'wpultra_currency_on_init', 5);
}

/**
 * Front-end selection: resolve the visitor's currency (query > cookie >
 * geo > base) and, when the result differs from base, arm the conversion
 * filters for the rest of the request.
 */
function wpultra_currency_on_init(): void {
    // Front-end only: never touch admin, REST, AJAX or cron requests.
    if (function_exists('is_admin') && is_admin()) { return; }
    if (defined('REST_REQUEST') && REST_REQUEST) { return; }
    if (defined('DOING_CRON') && DOING_CRON) { return; }
    if (function_exists('wp_doing_cron') && wp_doing_cron()) { return; }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) { return; }

    $cfg = wpultra_currency_config();
    if (empty($cfg['enabled']) || empty($cfg['currencies'])) { return; }
    if (!isset($cfg['base']) && function_exists('get_woocommerce_currency')) {
        $cfg['base'] = get_woocommerce_currency();
    }

    $query  = isset($_GET['currency']) ? (string) $_GET['currency'] : '';        // phpcs:ignore
    $cookie = isset($_COOKIE['wpultra_currency']) ? (string) $_COOKIE['wpultra_currency'] : '';

    // Geo-IP is only consulted when neither query nor cookie holds a valid
    // code — a lookup on every request would be wasteful.
    $allowed = [];
    foreach (array_keys($cfg['currencies']) as $c) { $allowed[] = wpultra_cur_normalize_code((string) $c); }
    $base_norm = wpultra_cur_normalize_code((string) ($cfg['base'] ?? ''));
    if ($base_norm !== '') { $allowed[] = $base_norm; }
    $query_or_cookie_hit = in_array(wpultra_cur_normalize_code($query), $allowed, true)
        || in_array(wpultra_cur_normalize_code($cookie), $allowed, true);

    $geo_country = '';
    if (!$query_or_cookie_hit && !empty($cfg['geo_defaults']) && is_array($cfg['geo_defaults']) && class_exists('WC_Geolocation')) {
        try {
            $geo = WC_Geolocation::geolocate_ip();
            $geo_country = is_array($geo) ? (string) ($geo['country'] ?? '') : '';
        } catch (\Throwable $e) {
            $geo_country = '';
        }
    }

    $selected = wpultra_cur_pick($cfg, $query, $cookie, $geo_country);
    if ($selected === '') { return; }
    wpultra_currency_selected($selected);

    // A valid explicit switch persists for 30 days.
    $q_norm = wpultra_cur_normalize_code($query);
    if ($q_norm !== '' && $q_norm === $selected && !headers_sent()) {
        $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
        $secure = function_exists('is_ssl') ? is_ssl() : false;
        setcookie('wpultra_currency', $selected, time() + 30 * 86400, $path, $domain, $secure, false);
        $_COOKIE['wpultra_currency'] = $selected;
    }

    $base = wpultra_cur_normalize_code((string) ($cfg['base'] ?? ''));
    if ($selected === $base) { return; } // base currency: no filters, zero overhead.

    $rate = wpultra_cur_rate($cfg, $selected);
    if ($rate <= 0.0) { return; }

    wpultra_currency_arm_filters($cfg, $selected, $rate);
}

/** Arm the conversion filter set for a non-base selected currency. */
function wpultra_currency_arm_filters(array $cfg, string $selected, float $rate): void {
    if (!function_exists('add_filter')) { return; }

    // 1. Active currency code — cart, totals and the order record follow it.
    add_filter('woocommerce_currency', static function ($code) use ($selected) {
        return $selected;
    }, 99);

    // 2. Price conversion at the CRUD-getter level (once per read — do NOT
    //    also filter wc_price, that would double-convert).
    $convert = static function ($price, ...$unused) use ($rate) {
        if (is_string($price) || is_int($price) || is_float($price)) {
            return wpultra_cur_convert($price, $rate);
        }
        return $price;
    };
    $price_filters = [
        'woocommerce_product_get_price',
        'woocommerce_product_get_regular_price',
        'woocommerce_product_get_sale_price',
        'woocommerce_product_variation_get_price',
        'woocommerce_product_variation_get_regular_price',
        'woocommerce_product_variation_get_sale_price',
        'woocommerce_variation_prices_price',
        'woocommerce_variation_prices_regular_price',
        'woocommerce_variation_prices_sale_price',
    ];
    foreach ($price_filters as $f) {
        add_filter($f, $convert, 99, 2);
    }

    $spec = [];
    foreach (($cfg['currencies'] ?? []) as $code => $s) {
        if (wpultra_cur_normalize_code((string) $code) === $selected && is_array($s)) { $spec = $s; }
    }

    // 3. Per-currency decimals.
    if (isset($spec['decimals']) && is_numeric($spec['decimals'])) {
        $decimals = (int) $spec['decimals'];
        add_filter('wc_get_price_decimals', static function ($d) use ($decimals) {
            return $decimals;
        }, 99);
    }

    // 4. Per-currency symbol.
    if (isset($spec['symbol']) && is_string($spec['symbol']) && $spec['symbol'] !== '') {
        $symbol = $spec['symbol'];
        add_filter('woocommerce_currency_symbol', static function ($sym, $code) use ($symbol, $selected) {
            return $code === $selected ? $symbol : $sym;
        }, 99, 2);
    }
}

/** [wpultra_currency_switcher] renderer. */
function wpultra_currency_switcher_shortcode(): string {
    $cfg = wpultra_currency_config();
    if (empty($cfg['enabled']) || empty($cfg['currencies']) || !is_array($cfg['currencies'])) { return ''; }

    $base = wpultra_cur_normalize_code((string) ($cfg['base'] ?? ''));
    if ($base === '' && function_exists('get_woocommerce_currency')) {
        $base = wpultra_cur_normalize_code((string) get_woocommerce_currency());
    }

    $codes = [];
    if ($base !== '') { $codes[] = $base; }
    foreach (array_keys($cfg['currencies']) as $c) {
        $c = wpultra_cur_normalize_code((string) $c);
        if ($c !== '' && !in_array($c, $codes, true)) { $codes[] = $c; }
    }
    if (count($codes) < 2) { return ''; }

    $selected = wpultra_currency_selected();
    if ($selected === '') { $selected = $base; }

    // Preserve the current URL (path + non-currency query params).
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
    $params = [];
    $qs = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');
    if ($qs !== '') { parse_str($qs, $params); }

    return wpultra_cur_switcher_html($codes, $selected, $path, $params);
}
