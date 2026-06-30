<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_woo_settings_whitelist(): array {
    return [
        'woocommerce_currency',
        'woocommerce_currency_pos',
        'woocommerce_price_thousand_sep',
        'woocommerce_price_decimal_sep',
        'woocommerce_price_num_decimals',
        'woocommerce_default_country',
        'woocommerce_weight_unit',
        'woocommerce_dimension_unit',
        'woocommerce_allowed_countries',
        'woocommerce_ship_to_countries',
        'woocommerce_calc_taxes',
        'woocommerce_prices_include_tax',
        'woocommerce_enable_coupons',
        'woocommerce_store_address',
        'woocommerce_store_city',
        'woocommerce_store_postcode',
    ];
}

function wpultra_woo_get_settings(): array {
    $gateways = [];
    if (function_exists('WC') && WC()->payment_gateways()) {
        foreach (WC()->payment_gateways()->payment_gateways() as $gw) {
            $gateways[] = ['id' => $gw->id, 'enabled' => ($gw->enabled === 'yes'), 'title' => $gw->get_title()];
        }
    }
    $zones = [];
    if (class_exists('WC_Shipping_Zones')) {
        foreach (WC_Shipping_Zones::get_zones() as $z) {
            $methods = [];
            foreach (($z['shipping_methods'] ?? []) as $m) { $methods[] = ['id' => $m->id, 'title' => $m->get_title(), 'enabled' => ($m->is_enabled())]; }
            $zones[] = ['id' => $z['zone_id'], 'name' => $z['zone_name'], 'methods' => $methods];
        }
    }
    return [
        'general' => [
            'currency'        => get_option('woocommerce_currency'),
            'currency_pos'    => get_option('woocommerce_currency_pos'),
            'default_country' => get_option('woocommerce_default_country'),
            'weight_unit'     => get_option('woocommerce_weight_unit'),
            'dimension_unit'  => get_option('woocommerce_dimension_unit'),
            'coupons_enabled' => get_option('woocommerce_enable_coupons'),
        ],
        'tax' => [
            'calc_taxes'         => get_option('woocommerce_calc_taxes'),
            'prices_include_tax' => get_option('woocommerce_prices_include_tax'),
        ],
        'payment_gateways' => $gateways,
        'shipping_zones'   => $zones,
    ];
}

function wpultra_woo_update_settings(array $input) {
    $updated = [];
    $rejected = [];
    $whitelist = wpultra_woo_settings_whitelist();

    if (!empty($input['options']) && is_array($input['options'])) {
        foreach ($input['options'] as $key => $val) {
            if (!in_array($key, $whitelist, true)) { $rejected[] = ['key' => $key, 'reason' => 'not_whitelisted']; continue; }
            update_option($key, $val);
            $updated[$key] = $val;
        }
    }

    if (!empty($input['gateway']) && is_array($input['gateway'])) {
        $gid = (string) ($input['gateway']['id'] ?? '');
        $enabled = !empty($input['gateway']['enabled']);
        if ($gid !== '' && function_exists('WC') && WC()->payment_gateways()) {
            $all = WC()->payment_gateways()->payment_gateways();
            if (isset($all[$gid])) {
                $opt = 'woocommerce_' . $gid . '_settings';
                $s = get_option($opt, []);
                if (!is_array($s)) { $s = []; }
                $s['enabled'] = $enabled ? 'yes' : 'no';
                update_option($opt, $s);
                $updated['gateway:' . $gid] = $enabled ? 'enabled' : 'disabled';
            } else {
                $rejected[] = ['key' => 'gateway:' . $gid, 'reason' => 'gateway_not_found'];
            }
        }
    }
    return ['updated' => $updated, 'rejected' => $rejected];
}
