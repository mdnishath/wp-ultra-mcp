<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/design.php';

it('validates hex colors', function () {
    assert_true(wpultra_el_is_hex_color('#0055FF'), '6-digit');
    assert_true(wpultra_el_is_hex_color('#abc'), '3-digit');
    assert_eq(false, wpultra_el_is_hex_color('red'), 'name');
    assert_eq(false, wpultra_el_is_hex_color('#12'), 'short');
});
it('slugifies labels', function () {
    assert_eq('brand-blue', wpultra_el_slug('Brand Blue'));
    assert_eq('my-color-1', wpultra_el_slug('My Color #1'));
    assert_eq('item', wpultra_el_slug('!!!'));
});

run_tests();
