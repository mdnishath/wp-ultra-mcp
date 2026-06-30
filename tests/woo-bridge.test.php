<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/bridge.php';

it('builds grid shortcode', function () {
    assert_eq('[products limit="4" columns="4"]', wpultra_woo_build_shortcode('grid', []));
});
it('builds single product_page', function () {
    assert_eq('[product_page id="9"]', wpultra_woo_build_shortcode('single', ['id' => 9]));
});
it('builds add_to_cart', function () {
    assert_eq('[add_to_cart id="9"]', wpultra_woo_build_shortcode('add_to_cart', ['id' => 9]));
});
it('builds on-sale via products', function () {
    assert_eq('[products limit="3" columns="3" on_sale="true"]', wpultra_woo_build_shortcode('sale', ['limit' => 3, 'columns' => 3]));
});

run_tests();
