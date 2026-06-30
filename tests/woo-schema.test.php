<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/schema.php';

it('coerces money', function () {
    assert_eq('19.99', wpultra_woo_coerce_money('19.99'));
    assert_eq('20', wpultra_woo_coerce_money(20));
    assert_eq(null, wpultra_woo_coerce_money(''));
});

it('coerces bool', function () {
    assert_true(wpultra_woo_coerce_bool('yes'));
    assert_true(wpultra_woo_coerce_bool(1));
    assert_true(!wpultra_woo_coerce_bool('no'));
    assert_true(!wpultra_woo_coerce_bool(false));
});

it('validate keeps known fields and coerces', function () {
    $r = wpultra_woo_validate_product(['name' => 'Hat', 'regular_price' => '9.5', 'manage_stock' => 'yes']);
    assert_eq('Hat', $r['clean']['name']);
    assert_eq('9.5', $r['clean']['regular_price']);
    assert_eq(true, $r['clean']['manage_stock']);
    assert_eq([], $r['rejected']);
});

it('validate rejects unknown field', function () {
    $r = wpultra_woo_validate_product(['name' => 'Hat', 'frobnicate' => 1]);
    assert_eq('Hat', $r['clean']['name']);
    assert_eq(1, count($r['rejected']));
    assert_eq('frobnicate', $r['rejected'][0]['field']);
    assert_eq('unknown_field', $r['rejected'][0]['reason']);
});

it('validate rejects bad enum', function () {
    $r = wpultra_woo_validate_product(['type' => 'wormhole']);
    assert_eq(1, count($r['rejected']));
    assert_eq('invalid_enum', $r['rejected'][0]['reason']);
    assert_true(!isset($r['clean']['type']));
});

run_tests();
