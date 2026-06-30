<?php
require_once __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/settings.php';

it('whitelist contains core keys and excludes arbitrary', function () {
    $wl = wpultra_woo_settings_whitelist();
    assert_true(in_array('woocommerce_currency', $wl, true));
    assert_true(in_array('woocommerce_weight_unit', $wl, true));
    assert_true(!in_array('siteurl', $wl, true));
    assert_true(!in_array('admin_email', $wl, true));
});

run_tests();
