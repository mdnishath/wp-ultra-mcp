<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_nlquery/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/ai/nlquery.php';

// A fixed "now" so relative-date tests are deterministic: 2026-07-15 (a Wednesday).
$NOW = gmmktime(12, 0, 0, 7, 15, 2026);

/* ============================================================
 * Report catalog shape.
 * ============================================================ */

it('reports() returns the eight documented reports with well-formed specs', function () {
    $r = wpultra_nlq_reports();
    $expected = ['top_products', 'sales_summary', 'sales_by_day', 'top_customers', 'low_stock', 'new_users', 'post_counts', 'top_content'];
    foreach ($expected as $id) {
        assert_true(isset($r[$id]), "report $id present");
        assert_true(isset($r[$id]['label']) && is_string($r[$id]['label']), "$id has label");
        assert_true(isset($r[$id]['description']) && is_string($r[$id]['description']), "$id has description");
        assert_true(array_key_exists('needs_woo', $r[$id]), "$id has needs_woo");
        assert_true(isset($r[$id]['params_spec']) && is_array($r[$id]['params_spec']), "$id has params_spec");
    }
});

it('woo reports are flagged needs_woo, content reports are not', function () {
    $r = wpultra_nlq_reports();
    assert_eq(true, $r['top_products']['needs_woo']);
    assert_eq(true, $r['low_stock']['needs_woo']);
    assert_eq(false, $r['post_counts']['needs_woo']);
    assert_eq(false, $r['new_users']['needs_woo']);
});

it('report_ids matches the catalog keys', function () {
    assert_eq(array_keys(wpultra_nlq_reports()), wpultra_nlq_report_ids());
});

/* ============================================================
 * resolve_date.
 * ============================================================ */

it('resolve_date handles today / yesterday', function () use ($NOW) {
    assert_eq('2026-07-15', wpultra_nlq_resolve_date('today', $NOW));
    assert_eq('2026-07-14', wpultra_nlq_resolve_date('yesterday', $NOW));
    // case-insensitive + trimmed
    assert_eq('2026-07-15', wpultra_nlq_resolve_date('  TODAY ', $NOW));
});

it('resolve_date handles Nd relative offsets', function () use ($NOW) {
    assert_eq('2026-07-08', wpultra_nlq_resolve_date('7d', $NOW));
    assert_eq('2026-06-15', wpultra_nlq_resolve_date('30d', $NOW));
    assert_eq('2026-07-15', wpultra_nlq_resolve_date('0d', $NOW));
});

it('resolve_date handles this-month / last-month', function () use ($NOW) {
    assert_eq('2026-07-01', wpultra_nlq_resolve_date('this-month', $NOW));
    assert_eq('2026-06-01', wpultra_nlq_resolve_date('last-month', $NOW));
});

it('resolve_date rolls last-month across a year boundary', function () {
    $jan = gmmktime(12, 0, 0, 1, 10, 2026);
    assert_eq('2025-12-01', wpultra_nlq_resolve_date('last-month', $jan));
});

it('resolve_date accepts an explicit valid YYYY-MM-DD', function () use ($NOW) {
    assert_eq('2025-02-28', wpultra_nlq_resolve_date('2025-02-28', $NOW));
});

it('resolve_date returns empty for garbage and invalid dates', function () use ($NOW) {
    assert_eq('', wpultra_nlq_resolve_date('', $NOW));
    assert_eq('', wpultra_nlq_resolve_date('next tuesday', $NOW));
    assert_eq('', wpultra_nlq_resolve_date('2025-13-40', $NOW)); // impossible date
    assert_eq('', wpultra_nlq_resolve_date('lastmonth', $NOW));
});

/* ============================================================
 * validate_intent.
 * ============================================================ */

it('validate_intent accepts a valid report + params', function () {
    $c = wpultra_nlq_reports();
    assert_true(true === wpultra_nlq_validate_intent(['report' => 'top_products', 'params' => ['limit' => 5]], $c));
});

it('validate_intent rejects an unknown report', function () {
    $c = wpultra_nlq_reports();
    $res = wpultra_nlq_validate_intent(['report' => 'drop_tables', 'params' => []], $c);
    assert_true(is_string($res), 'returns an error string');
    assert_contains('Unknown report', $res);
});

it('validate_intent rejects a missing report id', function () {
    $c = wpultra_nlq_reports();
    $res = wpultra_nlq_validate_intent(['params' => []], $c);
    assert_true(is_string($res));
    assert_contains('Missing "report"', $res);
});

it('validate_intent rejects a bad param type', function () {
    $c = wpultra_nlq_reports();
    $res = wpultra_nlq_validate_intent(['report' => 'top_products', 'params' => ['limit' => ['x']]], $c);
    assert_true(is_string($res));
    assert_contains('limit', $res);
});

it('validate_intent accepts a numeric string for an int param', function () {
    $c = wpultra_nlq_reports();
    assert_true(true === wpultra_nlq_validate_intent(['report' => 'low_stock', 'params' => ['threshold' => '3']], $c));
});

it('validate_intent rejects a non-array params', function () {
    $c = wpultra_nlq_reports();
    $res = wpultra_nlq_validate_intent(['report' => 'top_products', 'params' => 'oops'], $c);
    assert_true(is_string($res));
    assert_contains('params', $res);
});

/* ============================================================
 * normalize_params.
 * ============================================================ */

it('normalize_params fills date + limit defaults', function () use ($NOW) {
    $spec = wpultra_nlq_reports()['top_products']['params_spec'];
    $out = wpultra_nlq_normalize_params('top_products', [], $spec, $NOW);
    assert_eq('2026-06-15', $out['date_from']); // 30d default
    assert_eq('2026-07-15', $out['date_to']);   // today default
    assert_eq(10, $out['limit']);               // default
});

it('normalize_params clamps limit to 1..100', function () use ($NOW) {
    $spec = wpultra_nlq_reports()['top_products']['params_spec'];
    assert_eq(100, wpultra_nlq_normalize_params('top_products', ['limit' => 5000], $spec, $NOW)['limit']);
    assert_eq(1, wpultra_nlq_normalize_params('top_products', ['limit' => 0], $spec, $NOW)['limit']);
    assert_eq(1, wpultra_nlq_normalize_params('top_products', ['limit' => -9], $spec, $NOW)['limit']);
});

it('normalize_params resolves relative date expressions', function () use ($NOW) {
    $spec = wpultra_nlq_reports()['sales_summary']['params_spec'];
    $out = wpultra_nlq_normalize_params('sales_summary', ['date_from' => 'last-month', 'date_to' => 'today'], $spec, $NOW);
    assert_eq('2026-06-01', $out['date_from']);
    assert_eq('2026-07-15', $out['date_to']);
});

it('normalize_params swaps an inverted date range', function () use ($NOW) {
    $spec = wpultra_nlq_reports()['sales_summary']['params_spec'];
    $out = wpultra_nlq_normalize_params('sales_summary', ['date_from' => 'today', 'date_to' => '30d'], $spec, $NOW);
    // today (2026-07-15) > 30d (2026-06-15) → swapped so from <= to.
    assert_eq('2026-06-15', $out['date_from']);
    assert_eq('2026-07-15', $out['date_to']);
});

it('normalize_params falls back to default when a date expr is garbage', function () use ($NOW) {
    $spec = wpultra_nlq_reports()['sales_summary']['params_spec'];
    $out = wpultra_nlq_normalize_params('sales_summary', ['date_from' => 'blah'], $spec, $NOW);
    assert_eq('2026-06-15', $out['date_from']); // falls back to 30d default
});

it('normalize_params clamps threshold min to 0', function () use ($NOW) {
    $spec = wpultra_nlq_reports()['low_stock']['params_spec'];
    assert_eq(0, wpultra_nlq_normalize_params('low_stock', ['threshold' => -5], $spec, $NOW)['threshold']);
    assert_eq(3, wpultra_nlq_normalize_params('low_stock', ['threshold' => 3], $spec, $NOW)['threshold']);
});

it('normalize_params trims string params and applies defaults', function () use ($NOW) {
    $spec = wpultra_nlq_reports()['post_counts']['params_spec'];
    $out = wpultra_nlq_normalize_params('post_counts', ['post_type' => '  page  '], $spec, $NOW);
    assert_eq('page', $out['post_type']);
    assert_eq('', $out['status']); // default
});

/* ============================================================
 * prepare (validate + normalize pipeline).
 * ============================================================ */

it('prepare returns {report, params} for a valid intent', function () use ($NOW) {
    $res = wpultra_nlq_prepare('top_products', ['limit' => 3], $NOW);
    assert_true(is_array($res));
    assert_eq('top_products', $res['report']);
    assert_eq(3, $res['params']['limit']);
});

it('prepare returns an error string for an unknown report', function () use ($NOW) {
    $res = wpultra_nlq_prepare('nope', [], $NOW);
    assert_true(is_string($res));
    assert_contains('Unknown report', $res);
});

/* ============================================================
 * intent_prompt.
 * ============================================================ */

it('intent_prompt embeds every catalog id and the question', function () {
    $c = wpultra_nlq_reports();
    $p = wpultra_nlq_intent_prompt('top sellers last month', $c);
    assert_true(isset($p['system'], $p['user']));
    foreach (array_keys($c) as $id) {
        assert_contains($id, $p['system']);
    }
    assert_contains('top sellers last month', $p['user']);
    // It must instruct JSON-only output.
    assert_contains('JSON', $p['system']);
});

/* ============================================================
 * parse_intent.
 * ============================================================ */

it('parse_intent parses a plain JSON object', function () {
    $res = wpultra_nlq_parse_intent('{"report":"top_products","params":{"limit":5}}');
    assert_true(is_array($res));
    assert_eq('top_products', $res['report']);
    assert_eq(5, $res['params']['limit']);
});

it('parse_intent parses a fenced ```json block', function () {
    $res = wpultra_nlq_parse_intent("```json\n{\"report\":\"sales_summary\",\"params\":{}}\n```");
    assert_true(is_array($res));
    assert_eq('sales_summary', $res['report']);
    assert_eq([], $res['params']);
});

it('parse_intent extracts an object from surrounding prose', function () {
    $res = wpultra_nlq_parse_intent('Sure! Here is the intent: {"report":"low_stock","params":{"threshold":3}} — enjoy.');
    assert_true(is_array($res));
    assert_eq('low_stock', $res['report']);
});

it('parse_intent defaults params to [] when absent', function () {
    $res = wpultra_nlq_parse_intent('{"report":"top_content"}');
    assert_true(is_array($res));
    assert_eq([], $res['params']);
});

it('parse_intent errors on garbage', function () {
    assert_true(is_string(wpultra_nlq_parse_intent('not json at all')));
    assert_true(is_string(wpultra_nlq_parse_intent('')));
    assert_true(is_string(wpultra_nlq_parse_intent('{"params":{}}'))); // missing report
});

/* ============================================================
 * format_answer.
 * ============================================================ */

it('format_answer summarizes top_products', function () {
    $result = ['columns' => ['product', 'qty', 'revenue'], 'rows' => [['Widget', 42, 12000.0], ['Gadget', 10, 3000.0]]];
    $ans = wpultra_nlq_format_answer($result, 'top_products', '');
    assert_contains('Widget', $ans);
    assert_contains('42 sold', $ans);
    assert_contains('12,000.00', $ans);
});

it('format_answer honors a currency prefix', function () {
    $result = ['columns' => ['product', 'qty', 'revenue'], 'rows' => [['Widget', 42, 12000.0]]];
    $ans = wpultra_nlq_format_answer($result, 'top_products', 'X');
    assert_contains('X12,000.00', $ans);
});

it('format_answer returns a no-data sentence for empty rows', function () {
    $ans = wpultra_nlq_format_answer(['columns' => [], 'rows' => []], 'top_products');
    assert_contains('No data', $ans);
});

it('format_answer uses the summary for sales_summary', function () {
    $result = [
        'columns' => ['metric', 'value'],
        'rows'    => [['orders', 5]],
        'summary' => ['orders' => 5, 'gross' => 500.0, 'net' => 420.0, 'avg' => 100.0],
    ];
    $ans = wpultra_nlq_format_answer($result, 'sales_summary', '');
    assert_contains('5 orders', $ans);
    assert_contains('500.00', $ans);
    assert_contains('100.00', $ans);
});

it('format_answer summarizes top_customers and low_stock', function () {
    $cust = ['rows' => [['Jane Doe', 999.5], ['John', 100.0]]];
    assert_contains('Jane Doe', wpultra_nlq_format_answer($cust, 'top_customers', ''));

    $stock = ['rows' => [['A', 1, 'sku-a'], ['B', 2, 'sku-b']]];
    assert_contains('2 products', wpultra_nlq_format_answer($stock, 'low_stock', ''));
});

it('format_answer falls back to a row-count sentence for an unknown report', function () {
    $ans = wpultra_nlq_format_answer(['rows' => [[1], [2], [3]]], 'mystery');
    assert_contains('3 rows', $ans);
});

run_tests();
