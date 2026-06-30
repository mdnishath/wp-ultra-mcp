<?php
require_once __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
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

run_tests();
