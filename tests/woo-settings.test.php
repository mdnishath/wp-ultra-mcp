<?php
require_once __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/schema.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/settings.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/reports.php';

it('whitelist contains core keys and excludes arbitrary', function () {
    $wl = wpultra_woo_settings_whitelist();
    assert_true(in_array('woocommerce_currency', $wl, true));
    assert_true(in_array('woocommerce_weight_unit', $wl, true));
    assert_true(!in_array('siteurl', $wl, true));
    assert_true(!in_array('admin_email', $wl, true));
});

it('report money sums totals', function () {
    $r = wpultra_woo_report_money([['total' => '10.00'], ['total' => '5.50'], ['total' => '4.50']]);
    assert_eq(3, $r['order_count']);
    assert_eq('20', (string) (0 + $r['gross']));
});

it('report money nets out refunds', function () {
    $r = wpultra_woo_report_money([
        ['total' => '10.00', 'refunded' => '2.50'],
        ['total' => '5.50',  'refunded' => '0'],
    ]);
    assert_eq('15.5', (string) (0 + $r['gross']));  // 10 + 5.5
    assert_eq('13', (string) (0 + $r['net']));      // 15.5 - 2.5
});

it('report money integer-cents avoids float drift', function () {
    // 0.1 + 0.2 in floats = 0.30000000000000004; cents accumulation stays exact.
    $r = wpultra_woo_report_money([['total' => '0.1'], ['total' => '0.2']]);
    assert_eq('0.3', (string) (0 + $r['gross']));
});

it('settings validate coerces yes/no keys', function () {
    $r = wpultra_woo_validate_setting('woocommerce_calc_taxes', 'true', []);
    assert_true($r['ok']);
    assert_eq('yes', $r['value']);
    $r2 = wpultra_woo_validate_setting('woocommerce_prices_include_tax', 0, []);
    assert_eq('no', $r2['value']);
});

it('settings validate rejects negative num_decimals', function () {
    $r = wpultra_woo_validate_setting('woocommerce_price_num_decimals', '-1', []);
    assert_true($r['ok'] === false);
    assert_eq('expected_non_negative_int', $r['reason']);
    $ok = wpultra_woo_validate_setting('woocommerce_price_num_decimals', '2', []);
    assert_eq(2, $ok['value']);
});

it('settings validate guards specific allowed_countries', function () {
    $bad = wpultra_woo_validate_setting('woocommerce_allowed_countries', 'specific', ['woocommerce_allowed_countries' => 'specific']);
    assert_true($bad['ok'] === false);
    assert_eq('specific_requires_companion_list', $bad['reason']);
    $good = wpultra_woo_validate_setting('woocommerce_allowed_countries', 'specific', [
        'woocommerce_allowed_countries' => 'specific',
        'woocommerce_specific_allowed_countries' => ['US', 'CA'],
    ]);
    assert_true($good['ok']);
});

run_tests();
