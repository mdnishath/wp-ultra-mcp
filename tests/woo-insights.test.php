<?php
require_once __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
require_once __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/insights.php';

// ---- wpultra_woo_insights_aggregate_customers() ----

it('aggregates orders by email, summing totals', function () {
    $fixtures = [
        ['email' => 'a@example.com', 'total' => '10.00'],
        ['email' => 'a@example.com', 'total' => '5.50'],
        ['email' => 'b@example.com', 'total' => '3.00'],
    ];
    $rows = wpultra_woo_insights_aggregate_customers($fixtures, 1);
    assert_eq(2, count($rows));
    // sorted by total_spent desc: a (15.50) before b (3.00)
    assert_eq('a@example.com', $rows[0]['email']);
    assert_eq(2, $rows[0]['orders']);
    assert_eq(15.5, $rows[0]['total_spent']);
    assert_eq('b@example.com', $rows[1]['email']);
    assert_eq(1, $rows[1]['orders']);
    assert_eq(3.0, $rows[1]['total_spent']);
});

it('dedupes case/whitespace-varied emails into one customer', function () {
    $fixtures = [
        ['email' => 'Same@Example.com', 'total' => '10'],
        ['email' => ' same@example.com ', 'total' => '20'],
    ];
    $rows = wpultra_woo_insights_aggregate_customers($fixtures, 1);
    assert_eq(1, count($rows));
    assert_eq('same@example.com', $rows[0]['email']);
    assert_eq(2, $rows[0]['orders']);
    assert_eq(30.0, $rows[0]['total_spent']);
});

it('filters out customers below min_orders', function () {
    $fixtures = [
        ['email' => 'a@example.com', 'total' => '10'],
        ['email' => 'b@example.com', 'total' => '10'],
        ['email' => 'b@example.com', 'total' => '10'],
    ];
    $rows = wpultra_woo_insights_aggregate_customers($fixtures, 2);
    assert_eq(1, count($rows));
    assert_eq('b@example.com', $rows[0]['email']);
});

it('ignores fixtures with empty/missing email', function () {
    $fixtures = [
        ['email' => '', 'total' => '10'],
        ['total' => '10'],
        ['email' => 'a@example.com', 'total' => '10'],
    ];
    $rows = wpultra_woo_insights_aggregate_customers($fixtures, 1);
    assert_eq(1, count($rows));
    assert_eq('a@example.com', $rows[0]['email']);
});

it('sorts customers by total_spent descending, not order count', function () {
    $fixtures = [
        ['email' => 'few-big@example.com', 'total' => '100'],
        ['email' => 'few-big@example.com', 'total' => '100'],
        ['email' => 'many-small@example.com', 'total' => '1'],
        ['email' => 'many-small@example.com', 'total' => '1'],
        ['email' => 'many-small@example.com', 'total' => '1'],
    ];
    $rows = wpultra_woo_insights_aggregate_customers($fixtures, 2);
    assert_eq('few-big@example.com', $rows[0]['email']);
    assert_eq(200.0, $rows[0]['total_spent']);
    assert_eq('many-small@example.com', $rows[1]['email']);
});

// ---- wpultra_woo_insights_split_stock() ----

it('splits stock: outofstock status always goes to out_of_stock regardless of qty', function () {
    $fixtures = [
        ['qty' => 5, 'status' => 'outofstock'],
    ];
    $r = wpultra_woo_insights_split_stock($fixtures, 3);
    assert_eq(1, count($r['out_of_stock']));
    assert_eq(0, count($r['low_stock']));
});

it('splits stock: qty <= 0 counts as out_of_stock even if status says instock', function () {
    $fixtures = [
        ['qty' => 0, 'status' => 'instock'],
        ['qty' => -1, 'status' => 'instock'],
    ];
    $r = wpultra_woo_insights_split_stock($fixtures, 3);
    assert_eq(2, count($r['out_of_stock']));
    assert_eq(0, count($r['low_stock']));
});

it('splits stock: qty at threshold boundary is low_stock (inclusive)', function () {
    $fixtures = [
        ['qty' => 3, 'status' => 'instock'], // == threshold -> low
        ['qty' => 4, 'status' => 'instock'], // > threshold -> neither
        ['qty' => 1, 'status' => 'instock'], // < threshold -> low
    ];
    $r = wpultra_woo_insights_split_stock($fixtures, 3);
    assert_eq(2, count($r['low_stock']));
    assert_eq(0, count($r['out_of_stock']));
    assert_eq(3, $r['low_stock'][0]['qty']);
    assert_eq(1, $r['low_stock'][1]['qty']);
});

it('splits stock: qty above threshold and in stock is excluded from both buckets', function () {
    $fixtures = [
        ['qty' => 50, 'status' => 'instock'],
    ];
    $r = wpultra_woo_insights_split_stock($fixtures, 3);
    assert_eq(0, count($r['out_of_stock']));
    assert_eq(0, count($r['low_stock']));
});

it('splits stock: null qty (not managed) is left out of both buckets unless status is outofstock', function () {
    $fixtures = [
        ['qty' => null, 'status' => 'instock'],
        ['qty' => null, 'status' => 'outofstock'],
    ];
    $r = wpultra_woo_insights_split_stock($fixtures, 3);
    assert_eq(1, count($r['out_of_stock']));
    assert_eq(0, count($r['low_stock']));
});

// ---- wpultra_woo_insights_summary() ----

it('summary builder counts rows and sums the money key', function () {
    $rows = [['total' => '10.00'], ['total' => '5.25'], ['total' => '0.75']];
    $s = wpultra_woo_insights_summary($rows, 'total');
    assert_eq(3, $s['count']);
    assert_eq(16.0, $s['value']);
});

it('summary builder handles empty rows', function () {
    $s = wpultra_woo_insights_summary([], 'total');
    assert_eq(0, $s['count']);
    assert_eq(0.0, $s['value']);
});

it('summary builder avoids float drift across many small values', function () {
    $rows = array_fill(0, 10, ['total' => '0.10']);
    $s = wpultra_woo_insights_summary($rows, 'total');
    assert_eq(10, $s['count']);
    assert_eq(1.0, $s['value']);
});

// ---- WooCommerce-absent graceful errors (guard reuse, mirrors woo-extras.test.php) ----

it('insights functions are pure and do not require WooCommerce to be loaded', function () {
    // Sanity: these pure helpers must not reference WC_* classes or wc_* functions directly.
    assert_true(function_exists('wpultra_woo_insights_aggregate_customers'));
    assert_true(function_exists('wpultra_woo_insights_split_stock'));
    assert_true(function_exists('wpultra_woo_insights_summary'));
});

run_tests();
