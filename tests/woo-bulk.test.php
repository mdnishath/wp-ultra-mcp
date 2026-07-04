<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/bulk.php';

// ---------------------------------------------------------------------------
// wpultra_woo_bulk_new_price
// ---------------------------------------------------------------------------

it('new_price percent increase', function () {
    $r = wpultra_woo_bulk_new_price(100.0, ['mode' => 'percent', 'direction' => 'increase', 'amount' => 10]);
    assert_eq(110.0, $r);
});

it('new_price percent decrease', function () {
    $r = wpultra_woo_bulk_new_price(100.0, ['mode' => 'percent', 'direction' => 'decrease', 'amount' => 20]);
    assert_eq(80.0, $r);
});

it('new_price fixed increase', function () {
    $r = wpultra_woo_bulk_new_price(50.0, ['mode' => 'fixed', 'direction' => 'increase', 'amount' => 5]);
    assert_eq(55.0, $r);
});

it('new_price fixed decrease', function () {
    $r = wpultra_woo_bulk_new_price(50.0, ['mode' => 'fixed', 'direction' => 'decrease', 'amount' => 5]);
    assert_eq(45.0, $r);
});

it('new_price clamps at zero on fixed decrease past zero', function () {
    $r = wpultra_woo_bulk_new_price(10.0, ['mode' => 'fixed', 'direction' => 'decrease', 'amount' => 25]);
    assert_eq(0.0, $r);
});

it('new_price clamps at zero on percent decrease of 100+', function () {
    $r = wpultra_woo_bulk_new_price(40.0, ['mode' => 'percent', 'direction' => 'decrease', 'amount' => 150]);
    assert_eq(0.0, $r);
});

it('new_price rounds to 2 decimal places', function () {
    $r = wpultra_woo_bulk_new_price(19.999, ['mode' => 'fixed', 'direction' => 'increase', 'amount' => 0.001]);
    assert_eq(20.0, $r);

    $r2 = wpultra_woo_bulk_new_price(10.0, ['mode' => 'percent', 'direction' => 'increase', 'amount' => 33.333]);
    assert_eq(13.33, $r2);
});

it('new_price defaults to fixed/increase/zero when adjust is empty', function () {
    $r = wpultra_woo_bulk_new_price(10.0, []);
    assert_eq(10.0, $r);
});

it('new_price handles zero-current percent math without error', function () {
    $r = wpultra_woo_bulk_new_price(0.0, ['mode' => 'percent', 'direction' => 'increase', 'amount' => 50]);
    assert_eq(0.0, $r);
});

// ---------------------------------------------------------------------------
// wpultra_woo_bulk_validate_changes
// ---------------------------------------------------------------------------

it('validate_changes rejects empty changes', function () {
    $r = wpultra_woo_bulk_validate_changes([]);
    assert_contains('must not be empty', $r);
});

it('validate_changes rejects unknown key', function () {
    $r = wpultra_woo_bulk_validate_changes(['bogus_field' => 1]);
    assert_contains('unknown change key: bogus_field', $r);
});

it('validate_changes accepts a plain regular_price set', function () {
    assert_eq(true, wpultra_woo_bulk_validate_changes(['regular_price' => 19.99]));
});

it('validate_changes rejects non-numeric regular_price', function () {
    $r = wpultra_woo_bulk_validate_changes(['regular_price' => 'abc']);
    assert_contains('regular_price must be numeric', $r);
});

it('validate_changes accepts a full price_adjust shape', function () {
    $r = wpultra_woo_bulk_validate_changes(['price_adjust' => [
        'mode' => 'percent', 'target' => 'regular', 'direction' => 'decrease', 'amount' => 20,
    ]]);
    assert_eq(true, $r);
});

it('validate_changes rejects price_adjust that is not an object', function () {
    $r = wpultra_woo_bulk_validate_changes(['price_adjust' => 'nope']);
    assert_contains('price_adjust must be an object', $r);
});

it('validate_changes rejects bad price_adjust.mode', function () {
    $r = wpultra_woo_bulk_validate_changes(['price_adjust' => [
        'mode' => 'wat', 'target' => 'regular', 'direction' => 'increase', 'amount' => 1,
    ]]);
    assert_contains('price_adjust.mode', $r);
});

it('validate_changes rejects bad price_adjust.target', function () {
    $r = wpultra_woo_bulk_validate_changes(['price_adjust' => [
        'mode' => 'fixed', 'target' => 'wat', 'direction' => 'increase', 'amount' => 1,
    ]]);
    assert_contains('price_adjust.target', $r);
});

it('validate_changes rejects bad price_adjust.direction', function () {
    $r = wpultra_woo_bulk_validate_changes(['price_adjust' => [
        'mode' => 'fixed', 'target' => 'regular', 'direction' => 'sideways', 'amount' => 1,
    ]]);
    assert_contains('price_adjust.direction', $r);
});

it('validate_changes rejects non-numeric price_adjust.amount', function () {
    $r = wpultra_woo_bulk_validate_changes(['price_adjust' => [
        'mode' => 'fixed', 'target' => 'regular', 'direction' => 'increase', 'amount' => 'lots',
    ]]);
    assert_contains('price_adjust.amount must be numeric', $r);
});

it('validate_changes rejects negative price_adjust.amount', function () {
    $r = wpultra_woo_bulk_validate_changes(['price_adjust' => [
        'mode' => 'fixed', 'target' => 'regular', 'direction' => 'increase', 'amount' => -5,
    ]]);
    assert_contains('must not be negative', $r);
});

it('validate_changes accepts stock_adjust with integer amount', function () {
    assert_eq(true, wpultra_woo_bulk_validate_changes(['stock_adjust' => ['amount' => -3]]));
});

it('validate_changes rejects stock_adjust with non-integer amount', function () {
    $r = wpultra_woo_bulk_validate_changes(['stock_adjust' => ['amount' => 1.5]]);
    assert_contains('stock_adjust.amount must be an integer', $r);
});

it('validate_changes rejects non-integer stock_quantity', function () {
    $r = wpultra_woo_bulk_validate_changes(['stock_quantity' => 3.5]);
    assert_contains('stock_quantity must be an integer', $r);
});

it('validate_changes rejects bad stock_status', function () {
    $r = wpultra_woo_bulk_validate_changes(['stock_status' => 'maybe']);
    assert_contains('stock_status must be', $r);
});

it('validate_changes rejects bad status', function () {
    $r = wpultra_woo_bulk_validate_changes(['status' => 'archived']);
    assert_contains('status must be', $r);
});

it('validate_changes rejects bad catalog_visibility', function () {
    $r = wpultra_woo_bulk_validate_changes(['catalog_visibility' => 'nowhere']);
    assert_contains('catalog_visibility must be', $r);
});

it('validate_changes flags sale_price >= regular_price', function () {
    $r = wpultra_woo_bulk_validate_changes(['regular_price' => 10, 'sale_price' => 10]);
    assert_contains('sale_price must be less than regular_price', $r);

    $r2 = wpultra_woo_bulk_validate_changes(['regular_price' => 10, 'sale_price' => 20]);
    assert_contains('sale_price must be less than regular_price', $r2);
});

it('validate_changes accepts sale_price below regular_price', function () {
    assert_eq(true, wpultra_woo_bulk_validate_changes(['regular_price' => 20, 'sale_price' => 10]));
});

it('validate_changes accepts a valid sale schedule', function () {
    assert_eq(true, wpultra_woo_bulk_validate_changes(['sale_from' => '2026-01-01', 'sale_to' => '2026-01-31']));
});

it('validate_changes rejects malformed sale date', function () {
    $r = wpultra_woo_bulk_validate_changes(['sale_from' => '01/01/2026']);
    assert_contains('sale_from must be Y-m-d', $r);
});

it('validate_changes rejects sale_from after sale_to', function () {
    $r = wpultra_woo_bulk_validate_changes(['sale_from' => '2026-02-01', 'sale_to' => '2026-01-01']);
    assert_contains('sale_from must not be after sale_to', $r);
});

it('validate_changes accepts add_category/remove_category as string or array', function () {
    assert_eq(true, wpultra_woo_bulk_validate_changes(['add_category' => 'sale']));
    assert_eq(true, wpultra_woo_bulk_validate_changes(['remove_category' => ['sale', 'clearance']]));
});

it('validate_changes rejects non-string/array add_category', function () {
    $r = wpultra_woo_bulk_validate_changes(['add_category' => 123]);
    assert_contains('add_category must be a string or array of slugs', $r);
});

// ---------------------------------------------------------------------------
// wpultra_woo_bulk_diff
// ---------------------------------------------------------------------------

it('diff reports only changed fields', function () {
    $before = ['regular_price' => '10', 'stock_quantity' => 5, 'status' => 'publish'];
    $after  = ['regular_price' => '8', 'stock_quantity' => 5, 'status' => 'draft'];
    $changed = wpultra_woo_bulk_diff($before, $after);
    sort($changed);
    assert_eq(['regular_price', 'status'], $changed);
});

it('diff is empty when nothing changed', function () {
    $snap = ['a' => 1, 'b' => 'x'];
    assert_eq([], wpultra_woo_bulk_diff($snap, $snap));
});

it('diff picks up new and removed keys', function () {
    $before = ['a' => 1];
    $after = ['a' => 1, 'b' => 2];
    assert_eq(['b'], wpultra_woo_bulk_diff($before, $after));
});

it('diff treats type differences as changes (strict compare)', function () {
    $before = ['manage_stock' => false];
    $after = ['manage_stock' => true];
    assert_eq(['manage_stock'], wpultra_woo_bulk_diff($before, $after));
});

// ---------------------------------------------------------------------------
// wpultra_woo_bulk_clamp_limit
// ---------------------------------------------------------------------------

it('clamp_limit defaults to 100 for non-positive input', function () {
    assert_eq(100, wpultra_woo_bulk_clamp_limit(0));
    assert_eq(100, wpultra_woo_bulk_clamp_limit(-5));
});

it('clamp_limit caps at 500', function () {
    assert_eq(500, wpultra_woo_bulk_clamp_limit(10000));
});

it('clamp_limit passes through in-range values', function () {
    assert_eq(42, wpultra_woo_bulk_clamp_limit(42));
});

run_tests();
