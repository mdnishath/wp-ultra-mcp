<?php
require_once __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require_once __DIR__ . '/../wp-ultra-mcp/includes/helpers.php'; // wpultra_ok()/wpultra_err() used by the graceful-degrade paths below
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/shipping.php';

// ---- wpultra_woo_setting_is_sensitive() ----

it('sensitive matcher flags secret/key/token/password-like keys', function () {
    assert_true(wpultra_woo_setting_is_sensitive('secret_key'));
    assert_true(wpultra_woo_setting_is_sensitive('api_key'));
    assert_true(wpultra_woo_setting_is_sensitive('client_secret'));
    assert_true(wpultra_woo_setting_is_sensitive('password'));
    assert_true(wpultra_woo_setting_is_sensitive('stripe_secret_key'));
    assert_true(wpultra_woo_setting_is_sensitive('paypal_api_token'));
    assert_true(wpultra_woo_setting_is_sensitive('private_key'));
    assert_true(wpultra_woo_setting_is_sensitive('webhook_signing_secret'));
    assert_true(wpultra_woo_setting_is_sensitive('SECRET_KEY')); // case-insensitive
});

it('sensitive matcher leaves ordinary settings alone', function () {
    assert_true(wpultra_woo_setting_is_sensitive('title') === false);
    assert_true(wpultra_woo_setting_is_sensitive('description') === false);
    assert_true(wpultra_woo_setting_is_sensitive('enabled') === false);
    assert_true(wpultra_woo_setting_is_sensitive('cost') === false);
    assert_true(wpultra_woo_setting_is_sensitive('instructions') === false);
});

it('sensitive matcher catches trailing _key and _pass suffixes', function () {
    assert_true(wpultra_woo_setting_is_sensitive('publishable_key')); // ends with "_key" -> masked (conservative default)
    assert_true(wpultra_woo_setting_is_sensitive('merchant_key'));
    assert_true(wpultra_woo_setting_is_sensitive('admin_pass'));
});

// ---- wpultra_woo_mask_gateway_settings() ----

it('mask replaces only sensitive keys, keeps others intact', function () {
    $settings = [
        'title' => 'Stripe',
        'description' => 'Pay with card',
        'secret_key' => 'sk_live_abc123',
        'publishable_key' => 'pk_live_xyz789',
        'webhook_secret' => 'whsec_abc',
    ];
    $masked = wpultra_woo_mask_gateway_settings($settings);
    assert_eq('Stripe', $masked['title']);
    assert_eq('Pay with card', $masked['description']);
    assert_eq('••••', $masked['secret_key']);
    assert_eq('••••', $masked['webhook_secret']);
    // publishable_key contains "key" but not "_key" suffix boundary issue: still masked since ends with "_key".
    assert_eq('••••', $masked['publishable_key']);
});

it('mask never leaks original sensitive value anywhere in output', function () {
    $settings = ['api_key' => 'super-secret-value-12345'];
    $masked = wpultra_woo_mask_gateway_settings($settings);
    $encoded = json_encode($masked);
    assert_true(strpos($encoded, 'super-secret-value-12345') === false, 'original secret leaked into masked output');
});

// ---- wpultra_woo_normalize_tax_rate() ----

it('tax rate normalizer rejects non-numeric rate', function () {
    $r = wpultra_woo_normalize_tax_rate(['rate' => 'abc']);
    assert_true($r['ok'] === false);
    assert_eq('rate_must_be_numeric', $r['reason']);
});

it('tax rate normalizer rejects missing rate', function () {
    $r = wpultra_woo_normalize_tax_rate([]);
    assert_true($r['ok'] === false);
    assert_eq('rate_must_be_numeric', $r['reason']);
});

it('tax rate normalizer coerces country/state to uppercase and defaults', function () {
    $r = wpultra_woo_normalize_tax_rate(['rate' => '20', 'country' => 'us', 'state' => 'ca']);
    assert_true($r['ok']);
    assert_eq('US', $r['rate']['tax_rate_country']);
    assert_eq('CA', $r['rate']['tax_rate_state']);
    assert_eq('Tax', $r['rate']['tax_rate_name']);
    assert_eq(1, $r['rate']['tax_rate_priority']);
    assert_eq(0, $r['rate']['tax_rate_compound']);
    assert_eq(1, $r['rate']['tax_rate_shipping']); // defaults true
});

it('tax rate normalizer respects compound/shipping/priority/name/class overrides', function () {
    $r = wpultra_woo_normalize_tax_rate([
        'rate' => '7.5', 'name' => 'VAT', 'class' => 'reduced-rate',
        'priority' => 2, 'compound' => true, 'shipping' => false,
    ]);
    assert_true($r['ok']);
    assert_eq('7.5', $r['rate']['tax_rate']);
    assert_eq('VAT', $r['rate']['tax_rate_name']);
    assert_eq('reduced-rate', $r['rate']['tax_rate_class']);
    assert_eq(2, $r['rate']['tax_rate_priority']);
    assert_eq(1, $r['rate']['tax_rate_compound']);
    assert_eq(0, $r['rate']['tax_rate_shipping']);
});

it('tax rate normalizer clamps negative priority to zero', function () {
    $r = wpultra_woo_normalize_tax_rate(['rate' => '1', 'priority' => -5]);
    assert_true($r['ok']);
    assert_eq(0, $r['rate']['tax_rate_priority']);
});

// ---- wpultra_woo_shipping_method_types() ----

it('shipping method types is a fixed, testable enum', function () {
    $types = wpultra_woo_shipping_method_types();
    assert_true(in_array('flat_rate', $types, true));
    assert_true(in_array('free_shipping', $types, true));
    assert_true(in_array('local_pickup', $types, true));
    assert_eq(3, count($types));
});

// ---- wpultra_woo_shape_zone() / wpultra_woo_shape_method() ----

it('shape_zone extracts id/name/order and carries methods through', function () {
    $z = wpultra_woo_shape_zone(['zone_id' => 3, 'zone_name' => 'Europe', 'zone_order' => 1], [['instance_id' => 5]]);
    assert_eq(3, $z['id']);
    assert_eq('Europe', $z['name']);
    assert_eq(1, $z['order']);
    assert_eq([['instance_id' => 5]], $z['methods']);
});

it('shape_method builds a compact descriptor', function () {
    $m = wpultra_woo_shape_method(7, 'flat_rate', 'Flat rate', true, ['cost' => '5.00']);
    assert_eq(7, $m['instance_id']);
    assert_eq('flat_rate', $m['method_id']);
    assert_eq('Flat rate', $m['title']);
    assert_true($m['enabled']);
    assert_eq(['cost' => '5.00'], $m['settings']);
});

// ---- WooCommerce-absent graceful errors (guard reuse) ----

it('shipping zone manage errors gracefully when WC_Shipping_Zones is absent', function () {
    if (class_exists('WC_Shipping_Zones')) { return; } // already loaded elsewhere in this php process — skip
    $res = wpultra_woo_shipping_zone_manage(['action' => 'list']);
    assert_wp_error($res);
    assert_eq('woocommerce_inactive', $res->get_error_code());
});

it('tax rate manage errors gracefully when WC_Tax is absent', function () {
    if (class_exists('WC_Tax')) { return; }
    $res = wpultra_woo_tax_rate_manage(['action' => 'list']);
    assert_wp_error($res);
    assert_eq('woocommerce_inactive', $res->get_error_code());
});

it('gateway manage errors gracefully when WC() is absent', function () {
    if (function_exists('WC')) { return; }
    $res = wpultra_woo_gateway_manage(['action' => 'list']);
    assert_wp_error($res);
    assert_eq('woocommerce_inactive', $res->get_error_code());
});

run_tests();
