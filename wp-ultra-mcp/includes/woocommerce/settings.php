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
        'woocommerce_specific_allowed_countries',
        'woocommerce_ship_to_countries',
        'woocommerce_calc_taxes',
        'woocommerce_prices_include_tax',
        'woocommerce_enable_coupons',
        'woocommerce_store_address',
        'woocommerce_store_city',
        'woocommerce_store_postcode',
    ];
}

/**
 * Validate a single whitelisted setting value.
 * Returns ['ok' => true, 'value' => <coerced>] or ['ok' => false, 'reason' => <why>].
 * $all is the full options map being written (so companion-key checks can see siblings).
 */
function wpultra_woo_validate_setting(string $key, $val, array $all): array {
    // yes/no toggles
    $yesno = [
        'woocommerce_calc_taxes', 'woocommerce_prices_include_tax',
        'woocommerce_manage_stock', 'woocommerce_enable_coupons',
    ];
    if (in_array($key, $yesno, true)) {
        return ['ok' => true, 'value' => wpultra_woo_coerce_bool($val) ? 'yes' : 'no'];
    }

    if ($key === 'woocommerce_price_num_decimals') {
        if (!is_numeric($val) || (int) $val < 0) { return ['ok' => false, 'reason' => 'expected_non_negative_int']; }
        return ['ok' => true, 'value' => (int) $val];
    }

    if ($key === 'woocommerce_currency') {
        if (function_exists('get_woocommerce_currencies')) {
            $codes = array_keys(get_woocommerce_currencies());
            if (!in_array((string) $val, $codes, true)) { return ['ok' => false, 'reason' => 'invalid_currency']; }
        }
        return ['ok' => true, 'value' => (string) $val];
    }

    if ($key === 'woocommerce_allowed_countries' && (string) $val === 'specific') {
        // 'specific' with no companion list bricks checkout — require the companion.
        $companion = $all['woocommerce_specific_allowed_countries'] ?? null;
        if (empty($companion) || (is_array($companion) && $companion === [])) {
            return ['ok' => false, 'reason' => 'specific_requires_companion_list'];
        }
    }

    return ['ok' => true, 'value' => $val];
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
        $opts = $input['options'];
        foreach ($opts as $key => $val) {
            if (!in_array($key, $whitelist, true)) { $rejected[] = ['key' => $key, 'reason' => 'not_whitelisted']; continue; }
            $check = wpultra_woo_validate_setting((string) $key, $val, $opts);
            if (!$check['ok']) { $rejected[] = ['key' => $key, 'reason' => $check['reason']]; continue; }
            update_option($key, $check['value']);
            $updated[$key] = $check['value'];
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

function wpultra_woo_manage_review(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $args = ['type' => 'review', 'number' => (int) ($input['per_page'] ?? 50)];
        if (!empty($input['product_id'])) { $args['post_id'] = (int) $input['product_id']; }
        if (!empty($input['status']))     { $args['status'] = (string) $input['status']; }
        $comments = get_comments($args);
        $rows = [];
        foreach ($comments as $cm) {
            $rows[] = ['id' => (int) $cm->comment_ID, 'product_id' => (int) $cm->comment_post_ID, 'author' => $cm->comment_author, 'content' => $cm->comment_content, 'rating' => (int) get_comment_meta($cm->comment_ID, 'rating', true), 'approved' => ($cm->comment_approved === '1')];
        }
        return ['count' => count($rows), 'reviews' => $rows];
    }

    if ($action === 'create') {
        $pid = (int) ($input['product_id'] ?? 0);
        if (!$pid || get_post_type($pid) !== 'product') { return wpultra_err('invalid_product', 'create review requires a valid product_id.'); }
        $cid = wp_insert_comment([
            'comment_post_ID'  => $pid,
            'comment_author'   => (string) ($input['author'] ?? 'Guest'),
            'comment_author_email' => (string) ($input['email'] ?? ''),
            'comment_content'  => (string) ($input['content'] ?? ''),
            'comment_type'     => 'review',
            'comment_approved' => 1,
        ]);
        if (!$cid) { return wpultra_err('review_create_failed', 'wp_insert_comment returned 0.'); }
        if (isset($input['rating'])) { update_comment_meta($cid, 'rating', max(1, min(5, (int) $input['rating']))); }
        return ['id' => (int) $cid, 'product_id' => $pid];
    }

    $id = (int) ($input['id'] ?? 0);
    if (!$id || !get_comment($id)) { return wpultra_err('review_not_found', "No review with id $id."); }
    if ($action === 'delete') { wp_delete_comment($id, !empty($input['force'])); return ['id' => $id, 'deleted' => true]; }
    $map = ['approve' => 'approve', 'unapprove' => 'hold', 'spam' => 'spam', 'trash' => 'trash'];
    if (!isset($map[$action])) { return wpultra_err('bad_action', "Unknown review action '$action'."); }
    wp_set_comment_status($id, $map[$action]);
    return ['id' => $id, 'status' => $map[$action]];
}
