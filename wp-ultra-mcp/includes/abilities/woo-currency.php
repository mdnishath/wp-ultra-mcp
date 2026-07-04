<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/woocommerce/currency.php; require it
// defensively so this ability works regardless of load order (mirrors
// woo-bulk-edit leaning on its engine file).
if (!function_exists('wpultra_cur_convert') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/woocommerce/currency.php')) {
    require_once WPULTRA_DIR . 'includes/woocommerce/currency.php';
}

wp_register_ability('wpultra/woo-currency', [
    'label'       => __('WooCommerce: Multi-Currency + Geo Pricing', 'wp-ultra-mcp'),
    'description' => __(
        'Configure a lightweight WOOCS-style multi-currency runtime: the visitor picks a display currency via a switcher '
        . '([wpultra_currency_switcher] shortcode / ?currency=USD in any URL) or gets a geo-IP default, and the runtime multiplies '
        . 'product prices by the configured MANUAL rate and switches the active WooCommerce currency code — cart, totals and the '
        . 'ORDER are recorded in the selected currency (fine with HPOS: orders simply store whatever currency/total was active at checkout). '
        . 'Selection precedence per visit: ?currency= query param (persisted in a 30-day cookie) > existing cookie > geo-IP country default > base currency. '
        . 'Config model: {enabled, currencies: {code: {rate, symbol?, decimals?}}, geo_defaults: {countryCode: currencyCode}, base (auto-filled from the store currency at save time)}. '
        . 'rate means 1 base unit = rate × code units — e.g. base BDT with {"USD":{"rate":0.0091}} shows a 1000 BDT product as 9.10 USD. '
        . 'ACTIONS: '
        . '"config" {enabled?, currencies?, geo_defaults?} — validate + merge + save; NOTE: passing currencies REPLACES the whole currency map (send the complete map every time), same for geo_defaults; reversible, no confirm needed. '
        . '"set-rates" {rates: {code: rate}} — update just the rates of already-configured codes (daily rate refresh without resending symbols/decimals). '
        . '"status" — current config, base currency, and which visitor-selection mechanisms are active (query/cookie/geo). '
        . '"preview" {amount} — conversion table {code: converted} for a base-currency amount across every configured currency. '
        . '"geo-defaults" {map} — replace the country→currency map, e.g. {"US":"USD","GB":"GBP"} (validated: 2-letter countries, targets must be configured codes or base). '
        . 'Examples: {action:"config", enabled:true, currencies:{"USD":{"rate":0.0091,"symbol":"$","decimals":2},"EUR":{"rate":0.0084,"symbol":"€","decimals":2}}} then {action:"geo-defaults", map:{"US":"USD","DE":"EUR","FR":"EUR"}}. '
        . 'HONEST CAVEATS: rates are manual — the owner must update them (there is no automatic exchange-rate feed); shipping flat rates and coupon amounts are NOT converted (they stay numerically as configured, so a "100" flat rate means 100 of whatever currency is active); the payment gateway must itself support the selected currency or checkout will fail/fall back; geo detection uses WC_Geolocation and is only as accurate as WooCommerce\'s geo database.',
        'wp-ultra-mcp'
    ),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['config', 'status', 'set-rates', 'preview', 'geo-defaults']],
            'enabled' => ['type' => 'boolean'],
            'currencies' => [
                'type' => 'object',
                'additionalProperties' => [
                    'type'       => 'object',
                    'properties' => [
                        'rate'     => ['type' => 'number'],
                        'symbol'   => ['type' => 'string'],
                        'decimals' => ['type' => 'integer'],
                    ],
                    'required' => ['rate'],
                ],
            ],
            'geo_defaults' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'rates'        => ['type' => 'object', 'additionalProperties' => ['type' => 'number']],
            'map'          => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'amount'       => ['type' => 'number'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
            'config'  => ['type' => 'object'],
            'base'    => ['type' => 'string'],
            'preview' => ['type' => 'object'],
            'selection' => ['type' => 'object'],
            'note'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_currency_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_woo_currency_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    if (!function_exists('wpultra_cur_validate_config')) {
        return wpultra_err('currency_engine_missing', 'The currency engine (includes/woocommerce/currency.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');
    $cfg = get_option('wpultra_currency', []);
    if (!is_array($cfg)) { $cfg = []; }
    $base = function_exists('get_woocommerce_currency') ? wpultra_cur_normalize_code((string) get_woocommerce_currency()) : '';

    // ---------------------------------------------------------------- status
    if ($action === 'status') {
        $enabled = !empty($cfg['enabled']);
        $has_rates = is_array($cfg['currencies'] ?? null) && count($cfg['currencies']) > 0;
        return wpultra_ok([
            'action' => 'status',
            'config' => $cfg,
            'base'   => $base,
            'selection' => [
                'armed'        => $enabled && $has_rates,
                'query_param'  => 'currency',
                'cookie'       => 'wpultra_currency',
                'cookie_days'  => 30,
                'geo'          => $enabled && $has_rates && !empty($cfg['geo_defaults']) && class_exists('WC_Geolocation'),
                'shortcode'    => '[wpultra_currency_switcher]',
            ],
            'note' => $enabled && $has_rates
                ? 'Runtime armed: conversion filters activate per-visitor when a non-base currency is selected.'
                : 'Runtime idle: enable it and configure at least one currency rate.',
        ]);
    }

    // --------------------------------------------------------------- preview
    if ($action === 'preview') {
        if (!isset($input['amount']) || !is_numeric($input['amount'])) {
            return wpultra_err('missing_amount', 'preview requires a numeric amount (in the base currency).');
        }
        $merged = $cfg;
        $merged['base'] = $base !== '' ? $base : ($cfg['base'] ?? '');
        return wpultra_ok([
            'action'  => 'preview',
            'base'    => (string) $merged['base'],
            'preview' => wpultra_cur_preview($merged, (float) $input['amount']),
        ]);
    }

    // ---------------------------------------------------------------- config
    if ($action === 'config') {
        $new = $cfg;
        if (array_key_exists('enabled', $input)) {
            if (!is_bool($input['enabled'])) { return wpultra_err('invalid_enabled', 'enabled must be a boolean.'); }
            $new['enabled'] = $input['enabled'];
        }
        if (array_key_exists('currencies', $input)) {
            if (!is_array($input['currencies'])) { return wpultra_err('invalid_currencies', 'currencies must be an object of code => {rate, symbol?, decimals?}.'); }
            // REPLACES the whole map (documented) — normalize codes on the way in.
            $map = [];
            foreach ($input['currencies'] as $code => $spec) {
                $map[wpultra_cur_normalize_code((string) $code)] = is_array($spec) ? $spec : [];
            }
            $new['currencies'] = $map;
        }
        if (array_key_exists('geo_defaults', $input)) {
            if (!is_array($input['geo_defaults'])) { return wpultra_err('invalid_geo_defaults', 'geo_defaults must be an object of countryCode => currencyCode.'); }
            $geo = [];
            foreach ($input['geo_defaults'] as $country => $code) {
                $geo[strtoupper(trim((string) $country))] = wpultra_cur_normalize_code((string) $code);
            }
            $new['geo_defaults'] = $geo;
        }
        if ($base !== '') { $new['base'] = $base; } // auto-fill at save time

        $valid = wpultra_cur_validate_config($new);
        if ($valid !== true) { return wpultra_err('invalid_config', (string) $valid); }

        update_option('wpultra_currency', $new, true); // autoloaded: the boot guard reads it on every request
        $n = count($new['currencies'] ?? []);
        wpultra_audit_log('woo-currency', 'config saved: enabled=' . (!empty($new['enabled']) ? 'yes' : 'no') . " currencies=$n base=" . ($new['base'] ?? '?'), true);
        return wpultra_ok([
            'action' => 'config',
            'config' => $new,
            'base'   => $base,
            'note'   => !empty($new['enabled']) && $n > 0
                ? 'Saved and armed (takes effect on the next front-end request).'
                : 'Saved. Runtime stays idle until enabled with at least one currency.',
        ]);
    }

    // ------------------------------------------------------------- set-rates
    if ($action === 'set-rates') {
        if (!is_array($input['rates'] ?? null) || empty($input['rates'])) {
            return wpultra_err('missing_rates', 'set-rates requires rates: {code: rate}.');
        }
        if (!is_array($cfg['currencies'] ?? null) || empty($cfg['currencies'])) {
            return wpultra_err('no_currencies', 'No currencies configured yet — use action:"config" with a currencies map first.');
        }
        $new = $cfg;
        foreach ($input['rates'] as $code => $rate) {
            $code = wpultra_cur_normalize_code((string) $code);
            if (!isset($new['currencies'][$code])) {
                return wpultra_err('unknown_currency', "Currency $code is not configured — add it via action:\"config\" first.");
            }
            if (!is_numeric($rate) || (float) $rate <= 0) {
                return wpultra_err('invalid_rate', "Rate for $code must be a number > 0.");
            }
            $new['currencies'][$code]['rate'] = (float) $rate;
        }
        if ($base !== '') { $new['base'] = $base; }
        $valid = wpultra_cur_validate_config($new);
        if ($valid !== true) { return wpultra_err('invalid_config', (string) $valid); }

        update_option('wpultra_currency', $new, true);
        wpultra_audit_log('woo-currency', 'rates updated: ' . implode(',', array_map('strval', array_keys($input['rates']))), true);
        return wpultra_ok(['action' => 'set-rates', 'config' => $new, 'base' => $base]);
    }

    // ---------------------------------------------------------- geo-defaults
    if ($action === 'geo-defaults') {
        if (!is_array($input['map'] ?? null)) {
            return wpultra_err('missing_map', 'geo-defaults requires map: {countryCode: currencyCode} (an empty object clears geo detection).');
        }
        $geo = [];
        foreach ($input['map'] as $country => $code) {
            $geo[strtoupper(trim((string) $country))] = wpultra_cur_normalize_code((string) $code);
        }
        $new = $cfg;
        $new['geo_defaults'] = $geo;
        if ($base !== '') { $new['base'] = $base; }
        $valid = wpultra_cur_validate_config($new);
        if ($valid !== true) { return wpultra_err('invalid_config', (string) $valid); }

        update_option('wpultra_currency', $new, true);
        wpultra_audit_log('woo-currency', 'geo defaults replaced: ' . count($geo) . ' countries', true);
        return wpultra_ok(['action' => 'geo-defaults', 'config' => $new, 'base' => $base]);
    }

    return wpultra_err('unknown_action', 'action must be one of: config, status, set-rates, preview, geo-defaults.');
}
