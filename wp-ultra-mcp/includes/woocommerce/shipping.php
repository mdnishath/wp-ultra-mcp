<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/**
 * Woo extras engine: shipping zones/methods, tax rates, payment gateway settings.
 * Mirrors includes/woocommerce/{settings,coupons}.php style. Availability guard reused
 * from includes/woocommerce/setup.php: wpultra_woo_active().
 */

// ---------------------------------------------------------------------------
// Pure helpers (testable without WordPress)
// ---------------------------------------------------------------------------

/**
 * Pure: true when a gateway/option setting key looks like a secret (API keys, tokens,
 * passwords, client secrets, private keys, webhook signing secrets, ...) — case-insensitive.
 * Mirrors wpultra_option_is_sensitive() in includes/system/options.php; kept as a local
 * copy here since the woocommerce domain must not depend on includes/system/*.
 */
function wpultra_woo_setting_is_sensitive(string $key): bool {
    $k = strtolower(trim($key));
    if ($k === '') { return false; }
    $patterns = ['secret', 'password', 'token', 'private_key', 'privatekey', 'api_key', 'apikey', 'signing', 'passphrase'];
    foreach ($patterns as $p) {
        if (str_contains($k, $p)) { return true; }
    }
    if (str_ends_with($k, '_key') || str_ends_with($k, '_pass')) { return true; }
    return false;
}

/** Pure: mask a value that belongs to a sensitive key. Never echoes original content/length hints beyond a fixed mask. */
function wpultra_woo_mask_value() {
    return '••••';
}

/**
 * Pure: given a gateway settings assoc array, return a copy with every sensitive-looking
 * key's value replaced by the mask. Non-sensitive keys pass through unchanged.
 */
function wpultra_woo_mask_gateway_settings(array $settings): array {
    $out = [];
    foreach ($settings as $key => $value) {
        $out[$key] = wpultra_woo_setting_is_sensitive((string) $key) ? wpultra_woo_mask_value() : $value;
    }
    return $out;
}

/** Pure: allowed shipping method ids this ability supports creating/updating. */
function wpultra_woo_shipping_method_types(): array {
    return ['flat_rate', 'free_shipping', 'local_pickup'];
}

/**
 * Pure: normalize/validate raw tax-rate input into WC_Tax::_insert_tax_rate-ready shape.
 * Returns ['ok'=>true,'rate'=>array,'postcodes'=>array,'cities'=>array] or ['ok'=>false,'reason'=>string].
 * 'rate' holds ONLY real woocommerce_tax_rates columns — postcode/city are NOT columns of that
 * table (WC applies them via _update_tax_rate_postcodes/_update_tax_rate_cities against separate
 * tables), so they are returned as their own outputs and never embedded in the DB-bound rate array.
 * Coerces country/state to uppercase 2-letter-ish strings (left as-is if longer — WC allows
 * multi-value comma lists), rate to a numeric string, priority/compound/shipping to expected types.
 */
function wpultra_woo_normalize_tax_rate(array $input): array {
    $rate = (string) ($input['rate'] ?? '');
    if ($rate === '' || !is_numeric($rate)) {
        return ['ok' => false, 'reason' => 'rate_must_be_numeric'];
    }

    $country = strtoupper(trim((string) ($input['country'] ?? '')));
    $state   = strtoupper(trim((string) ($input['state'] ?? '')));
    $postcode = trim((string) ($input['postcode'] ?? ''));
    $city     = trim((string) ($input['city'] ?? ''));
    $name     = (string) ($input['name'] ?? '');
    $class    = (string) ($input['class'] ?? '');
    $priority = isset($input['priority']) ? max(0, (int) $input['priority']) : 1;
    $compound = !empty($input['compound']);
    $shipping = array_key_exists('shipping', $input) ? !empty($input['shipping']) : true;

    // postcode/city may be a semicolon-separated list; split into arrays for the WC helpers.
    $postcodes = $postcode !== '' ? array_values(array_filter(array_map('trim', explode(';', $postcode)), 'strlen')) : [];
    $cities    = $city !== '' ? array_values(array_filter(array_map('trim', explode(';', $city)), 'strlen')) : [];

    return [
        'ok' => true,
        'rate' => [
            'tax_rate_country'  => $country,
            'tax_rate_state'    => $state,
            'tax_rate'          => (string) (0 + (float) $rate),
            'tax_rate_name'     => $name !== '' ? $name : 'Tax',
            'tax_rate_priority' => $priority,
            'tax_rate_compound' => $compound ? 1 : 0,
            'tax_rate_shipping' => $shipping ? 1 : 0,
            'tax_rate_order'    => 0,
            'tax_rate_class'    => $class,
        ],
        'postcodes' => $postcodes,
        'cities'    => $cities,
    ];
}

/** Pure: shape a WC_Shipping_Zone-like array (already-extracted primitives) for output. */
function wpultra_woo_shape_zone(array $zone, array $methods): array {
    return [
        'id'      => (int) ($zone['zone_id'] ?? 0),
        'name'    => (string) ($zone['zone_name'] ?? ''),
        'order'   => (int) ($zone['zone_order'] ?? 0),
        'methods' => $methods,
    ];
}

/** Pure: shape a single shipping method's settings for output (title/cost/min_amount passthrough, no secrets expected here). */
function wpultra_woo_shape_method(int $instance_id, string $method_id, string $title, bool $enabled, array $settings): array {
    return [
        'instance_id' => $instance_id,
        'method_id'   => $method_id,
        'title'       => $title,
        'enabled'     => $enabled,
        'settings'    => $settings,
    ];
}

// ---------------------------------------------------------------------------
// Shipping zones (WP-dependent; thin wrappers around WC_Shipping_Zones)
// ---------------------------------------------------------------------------

/** @return array|WP_Error */
function wpultra_woo_shipping_zone_manage(array $input) {
    if (!class_exists('WC_Shipping_Zones')) {
        return wpultra_err('woocommerce_inactive', 'WooCommerce shipping zones are unavailable.');
    }
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $zones = [];
        foreach (WC_Shipping_Zones::get_zones() as $z) {
            $methods = [];
            foreach (($z['shipping_methods'] ?? []) as $m) {
                $methods[] = wpultra_woo_shape_method((int) $m->get_instance_id(), (string) $m->id, (string) $m->get_title(), (bool) $m->is_enabled(), (array) ($m->instance_settings ?? []));
            }
            $zones[] = wpultra_woo_shape_zone($z, $methods);
        }
        // "Rest of the World" (zone 0) is not in get_zones(); include it explicitly.
        $rest = new WC_Shipping_Zone(0);
        $rest_methods = [];
        foreach ($rest->get_shipping_methods() as $m) {
            $rest_methods[] = wpultra_woo_shape_method((int) $m->get_instance_id(), (string) $m->id, (string) $m->get_title(), (bool) $m->is_enabled(), (array) ($m->instance_settings ?? []));
        }
        $zones[] = wpultra_woo_shape_zone(['zone_id' => 0, 'zone_name' => $rest->get_zone_name(), 'zone_order' => PHP_INT_MAX], $rest_methods);
        return ['count' => count($zones), 'zones' => $zones];
    }

    if ($action === 'get') {
        $id = (int) ($input['id'] ?? -1);
        $zone = WC_Shipping_Zones::get_zone($id);
        if (!$zone) { return wpultra_err('zone_not_found', "No shipping zone with id $id."); }
        $methods = [];
        foreach ($zone->get_shipping_methods() as $m) {
            $methods[] = wpultra_woo_shape_method((int) $m->get_instance_id(), (string) $m->id, (string) $m->get_title(), (bool) $m->is_enabled(), (array) ($m->instance_settings ?? []));
        }
        return wpultra_woo_shape_zone(['zone_id' => $zone->get_id(), 'zone_name' => $zone->get_zone_name(), 'zone_order' => $zone->get_zone_order()], $methods);
    }

    if ($action === 'create') {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') { return wpultra_err('name_required', 'Creating a shipping zone requires a name.'); }
        $zone = new WC_Shipping_Zone();
        $zone->set_zone_name($name);
        if (isset($input['order'])) { $zone->set_zone_order((int) $input['order']); }
        if (!empty($input['locations']) && is_array($input['locations'])) { $zone->set_locations($input['locations']); }
        $id = $zone->save();
        return ['id' => (int) $id, 'name' => $name];
    }

    if ($action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        $zone = WC_Shipping_Zones::get_zone($id);
        if (!$zone) { return wpultra_err('zone_not_found', "No shipping zone with id $id."); }
        if (isset($input['name'])) { $zone->set_zone_name((string) $input['name']); }
        if (isset($input['order'])) { $zone->set_zone_order((int) $input['order']); }
        if (isset($input['locations']) && is_array($input['locations'])) { $zone->set_locations($input['locations']); }
        $zone->save();
        return ['id' => (int) $zone->get_id(), 'name' => $zone->get_zone_name()];
    }

    if ($action === 'delete') {
        if (empty($input['confirm'])) { return wpultra_err('confirm_required', 'Deleting a shipping zone requires confirm: true.'); }
        $id = (int) ($input['id'] ?? 0);
        $zone = WC_Shipping_Zones::get_zone($id);
        if (!$zone) { return wpultra_err('zone_not_found', "No shipping zone with id $id."); }
        WC_Shipping_Zones::delete_zone($id);
        return ['id' => $id, 'deleted' => true];
    }

    if (in_array($action, ['add-method', 'update-method', 'remove-method'], true)) {
        $zone_id = (int) ($input['id'] ?? -1);
        $zone = WC_Shipping_Zones::get_zone($zone_id);
        if (!$zone) { return wpultra_err('zone_not_found', "No shipping zone with id $zone_id."); }

        if ($action === 'add-method') {
            $method_id = (string) ($input['method_id'] ?? '');
            if (!in_array($method_id, wpultra_woo_shipping_method_types(), true)) {
                return wpultra_err('invalid_method_id', "method_id must be one of: " . implode(', ', wpultra_woo_shipping_method_types()));
            }
            $instance_id = (int) $zone->add_shipping_method($method_id);
            if (!$instance_id) { return wpultra_err('method_add_failed', 'add_shipping_method returned 0.'); }
            if (!empty($input['settings']) && is_array($input['settings'])) {
                wpultra_woo_apply_method_settings($zone_id, $instance_id, $input['settings']);
            }
            return ['zone_id' => $zone_id, 'instance_id' => $instance_id, 'method_id' => $method_id];
        }

        $instance_id = (int) ($input['instance_id'] ?? 0);
        if ($action === 'remove-method') {
            if (empty($input['confirm'])) { return wpultra_err('confirm_required', 'Removing a shipping method requires confirm: true.'); }
            $zone->delete_shipping_method($instance_id);
            return ['zone_id' => $zone_id, 'instance_id' => $instance_id, 'deleted' => true];
        }

        // update-method
        if (empty($input['settings']) || !is_array($input['settings'])) {
            return wpultra_err('settings_required', 'update-method requires a settings object.');
        }
        $ok = wpultra_woo_apply_method_settings($zone_id, $instance_id, $input['settings']);
        if (!$ok) { return wpultra_err('method_not_found', "No shipping method instance $instance_id in zone $zone_id."); }
        return ['zone_id' => $zone_id, 'instance_id' => $instance_id, 'updated' => true];
    }

    return wpultra_err('bad_action', "Unknown shipping zone action '$action'.");
}

/** Apply a title/cost/min_amount settings object to a shipping method instance's option row. Returns bool found. */
function wpultra_woo_apply_method_settings(int $zone_id, int $instance_id, array $settings): bool {
    $zone = class_exists('WC_Shipping_Zones') ? WC_Shipping_Zones::get_zone($zone_id) : null;
    if (!$zone) { return false; }
    $found = null;
    foreach ($zone->get_shipping_methods() as $m) {
        if ((int) $m->get_instance_id() === $instance_id) { $found = $m; break; }
    }
    if (!$found) { return false; }
    $opt = $found->get_instance_option_key();
    $existing = get_option($opt, []);
    if (!is_array($existing)) { $existing = []; }
    foreach (['title', 'cost', 'min_amount'] as $field) {
        if (isset($settings[$field])) { $existing[$field] = $settings[$field]; }
    }
    // Allow passthrough of any other gateway-specific keys the caller supplies.
    foreach ($settings as $k => $v) {
        if (!in_array($k, ['title', 'cost', 'min_amount'], true)) { $existing[$k] = $v; }
    }
    update_option($opt, $existing);
    return true;
}

// ---------------------------------------------------------------------------
// Tax rates (WP-dependent; thin wrappers around WC_Tax)
// ---------------------------------------------------------------------------

/** @return array|WP_Error */
function wpultra_woo_tax_rate_manage(array $input) {
    if (!class_exists('WC_Tax')) {
        return wpultra_err('woocommerce_inactive', 'WooCommerce tax API is unavailable.');
    }
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { return wpultra_err('wp_unavailable', 'Database access is unavailable.'); }
        $class = (string) ($input['class'] ?? '');
        $rows = $wpdb->get_results(
            $class !== ''
                ? $wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s ORDER BY tax_rate_order ASC", $class)
                : "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order ASC",
            ARRAY_A
        );
        $rates = [];
        foreach ((array) $rows as $r) {
            $rates[] = [
                'id'       => (int) ($r['tax_rate_id'] ?? 0),
                'country'  => (string) ($r['tax_rate_country'] ?? ''),
                'state'    => (string) ($r['tax_rate_state'] ?? ''),
                'postcode' => (string) ($r['postcode'] ?? ''),
                'city'     => (string) ($r['city'] ?? ''),
                'rate'     => (string) ($r['tax_rate'] ?? ''),
                'name'     => (string) ($r['tax_rate_name'] ?? ''),
                'priority' => (int) ($r['tax_rate_priority'] ?? 0),
                'compound' => !empty($r['tax_rate_compound']),
                'shipping' => !empty($r['tax_rate_shipping']),
                'class'    => (string) ($r['tax_rate_class'] ?? ''),
            ];
        }
        return ['count' => count($rates), 'rates' => $rates];
    }

    if ($action === 'create') {
        $norm = wpultra_woo_normalize_tax_rate($input);
        if (!$norm['ok']) { return wpultra_err('invalid_tax_rate', (string) $norm['reason']); }
        $id = null;
        WC_Tax::_insert_tax_rate($norm['rate'], $id);
        if (!$id) { return wpultra_err('tax_rate_create_failed', '_insert_tax_rate did not return an id.'); }
        // postcode/city live in separate WC tables — apply only when supplied (empty would wipe existing).
        if (!empty($norm['postcodes'])) { WC_Tax::_update_tax_rate_postcodes((int) $id, $norm['postcodes']); }
        if (!empty($norm['cities']))    { WC_Tax::_update_tax_rate_cities((int) $id, $norm['cities']); }
        return ['id' => (int) $id];
    }

    if ($action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) { return wpultra_err('id_required', 'update requires an id.'); }
        $norm = wpultra_woo_normalize_tax_rate($input);
        if (!$norm['ok']) { return wpultra_err('invalid_tax_rate', (string) $norm['reason']); }
        foreach ($norm['rate'] as $key => $val) {
            WC_Tax::_update_tax_rate($id, [$key => $val]);
        }
        // postcode/city live in separate WC tables — apply only when supplied (empty would wipe existing).
        if (!empty($norm['postcodes'])) { WC_Tax::_update_tax_rate_postcodes($id, $norm['postcodes']); }
        if (!empty($norm['cities']))    { WC_Tax::_update_tax_rate_cities($id, $norm['cities']); }
        return ['id' => $id, 'updated' => true];
    }

    if ($action === 'delete') {
        if (empty($input['confirm'])) { return wpultra_err('confirm_required', 'Deleting a tax rate requires confirm: true.'); }
        $id = (int) ($input['id'] ?? 0);
        if (!$id) { return wpultra_err('id_required', 'delete requires an id.'); }
        WC_Tax::_delete_tax_rate($id);
        return ['id' => $id, 'deleted' => true];
    }

    return wpultra_err('bad_action', "Unknown tax rate action '$action'.");
}

// ---------------------------------------------------------------------------
// Payment gateways (WP-dependent; thin wrappers around WC()->payment_gateways())
// ---------------------------------------------------------------------------

/** @return array|WP_Error */
function wpultra_woo_gateway_manage(array $input) {
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        return wpultra_err('woocommerce_inactive', 'WooCommerce payment gateways are unavailable.');
    }
    $action = (string) ($input['action'] ?? 'list');
    $all = WC()->payment_gateways()->payment_gateways();

    if ($action === 'list') {
        $rows = [];
        foreach ($all as $gw) {
            $rows[] = [
                'id'      => $gw->id,
                'title'   => $gw->get_title(),
                'enabled' => ($gw->enabled === 'yes'),
                'order'   => (int) ($gw->order ?? 0),
            ];
        }
        return ['count' => count($rows), 'gateways' => $rows];
    }

    $gid = (string) ($input['gateway'] ?? ($input['id'] ?? ''));
    if ($gid === '' || !isset($all[$gid])) { return wpultra_err('gateway_not_found', "No payment gateway with id '$gid'."); }
    $gw = $all[$gid];

    if ($action === 'get') {
        $settings = is_array($gw->settings ?? null) ? $gw->settings : [];
        return [
            'id'          => $gw->id,
            'title'       => $gw->get_title(),
            'description' => method_exists($gw, 'get_description') ? $gw->get_description() : '',
            'enabled'     => ($gw->enabled === 'yes'),
            'settings'    => wpultra_woo_mask_gateway_settings($settings),
        ];
    }

    if ($action === 'enable' || $action === 'disable') {
        $opt = 'woocommerce_' . $gid . '_settings';
        $s = get_option($opt, []);
        if (!is_array($s)) { $s = []; }
        $s['enabled'] = ($action === 'enable') ? 'yes' : 'no';
        update_option($opt, $s);
        return ['id' => $gid, 'enabled' => ($action === 'enable')];
    }

    if ($action === 'update-settings') {
        if (empty($input['settings']) || !is_array($input['settings'])) {
            return wpultra_err('settings_required', 'update-settings requires a settings object.');
        }
        $opt = 'woocommerce_' . $gid . '_settings';
        $s = get_option($opt, []);
        if (!is_array($s)) { $s = []; }
        foreach ($input['settings'] as $key => $val) {
            $s[$key] = $val;
        }
        update_option($opt, $s);
        return ['id' => $gid, 'settings' => wpultra_woo_mask_gateway_settings($s)];
    }

    return wpultra_err('bad_action', "Unknown payment gateway action '$action'.");
}
