<?php
declare(strict_types=1);

// Pure-logic tests for the analytics reader engine (Site Kit GA4 + Search Console).
// Only the WordPress-free functions are exercised: build_params, flatten_ga4,
// flatten_sc, resolve_date, name_list, numify, extract_ga4_totals.

require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('wpultra_err')) { function wpultra_err($code, $msg, $data = '') { return new WP_Error($code, $msg, $data); } }
require __DIR__ . '/../wp-ultra-mcp/includes/system/analytics.php';

// Fixed clock: 2026-07-03 (UTC) → unix ts for deterministic relative-date tests.
$NOW = gmmktime(12, 0, 0, 7, 3, 2026);

// ---------------------------------------------------------------------------
// resolve_date
// ---------------------------------------------------------------------------

it('resolve_date passes through an explicit Y-m-d', function () use ($NOW) {
    assert_eq('2026-01-15', wpultra_analytics_resolve_date('2026-01-15', $NOW));
});

it('resolve_date handles today / yesterday / NdaysAgo', function () use ($NOW) {
    assert_eq('2026-07-03', wpultra_analytics_resolve_date('today', $NOW));
    assert_eq('2026-07-02', wpultra_analytics_resolve_date('yesterday', $NOW));
    assert_eq('2026-06-05', wpultra_analytics_resolve_date('28daysAgo', $NOW));
    assert_eq('2026-07-03', wpultra_analytics_resolve_date('0daysAgo', $NOW));
});

it('resolve_date rejects garbage with empty string', function () use ($NOW) {
    assert_eq('', wpultra_analytics_resolve_date('last week', $NOW));
    assert_eq('', wpultra_analytics_resolve_date('', $NOW));
    assert_eq('', wpultra_analytics_resolve_date('2026/07/01', $NOW)); // wrong separator → not accepted
});

// ---------------------------------------------------------------------------
// build_params — defaults, validation, clamp, whitelist
// ---------------------------------------------------------------------------

it('build_params applies defaults (metrics, dimensions, 28-day range, limit 10)', function () use ($NOW) {
    $p = wpultra_analytics_build_params([], $NOW);
    assert_true(!is_wp_error($p), 'defaults should validate');
    assert_eq('[{"name":"totalUsers"},{"name":"screenPageViews"}]', $p['metrics']);
    assert_eq('[{"name":"date"}]', $p['dimensions']);
    assert_eq('2026-06-05', $p['startDate']);
    assert_eq('2026-07-03', $p['endDate']);
    assert_eq(10, $p['limit']);
});

it('build_params accepts custom comma-separated metrics/dimensions', function () use ($NOW) {
    $p = wpultra_analytics_build_params(['metrics' => 'sessions, engagementRate', 'dimensions' => 'pagePath'], $NOW);
    assert_true(!is_wp_error($p), 'custom names should validate');
    assert_eq('[{"name":"sessions"},{"name":"engagementRate"}]', $p['metrics']);
    assert_eq('[{"name":"pagePath"}]', $p['dimensions']);
});

it('build_params rejects a non-alphanumeric metric name (injection guard)', function () use ($NOW) {
    $p = wpultra_analytics_build_params(['metrics' => 'totalUsers"},{"name":"evil'], $NOW);
    assert_wp_error($p);
    assert_eq('invalid_metric', $p->get_error_code());
});

it('build_params rejects a bad dimension name', function () use ($NOW) {
    $p = wpultra_analytics_build_params(['dimensions' => 'page path'], $NOW);
    assert_wp_error($p);
    assert_eq('invalid_dimension', $p->get_error_code());
});

it('build_params validates dates and rejects an inverted range', function () use ($NOW) {
    $bad = wpultra_analytics_build_params(['start_date' => 'garbage'], $NOW);
    assert_wp_error($bad);
    assert_eq('invalid_start_date', $bad->get_error_code());

    $inv = wpultra_analytics_build_params(['start_date' => '2026-07-01', 'end_date' => '2026-06-01'], $NOW);
    assert_wp_error($inv);
    assert_eq('date_range_inverted', $inv->get_error_code());
});

it('build_params clamps limit to 1..100', function () use ($NOW) {
    assert_eq(100, wpultra_analytics_build_params(['limit' => 5000], $NOW)['limit']);
    assert_eq(1, wpultra_analytics_build_params(['limit' => 0], $NOW)['limit']);
    assert_eq(1, wpultra_analytics_build_params(['limit' => -7], $NOW)['limit']);
});

// ---------------------------------------------------------------------------
// flatten_ga4 — realistic fixture: 2 dimensions, 2 metrics, 3 rows
// ---------------------------------------------------------------------------

function analytics_ga4_fixture(): array {
    // Shape as Site Kit returns it: a numerically-indexed list wrapping the report body.
    return [[
        'dimensionHeaders' => [['name' => 'date'], ['name' => 'pagePath']],
        'metricHeaders'    => [['name' => 'totalUsers'], ['name' => 'screenPageViews']],
        'rows' => [
            ['dimensionValues' => [['value' => '20260701'], ['value' => '/']],       'metricValues' => [['value' => '120'], ['value' => '340']]],
            ['dimensionValues' => [['value' => '20260702'], ['value' => '/blog']],   'metricValues' => [['value' => '90'], ['value' => '210']]],
            ['dimensionValues' => [['value' => '20260703'], ['value' => '/pricing']],'metricValues' => [['value' => '45'], ['value' => '77.5']]],
        ],
        'totals' => [
            ['metricValues' => [['value' => '255'], ['value' => '627.5']]],
        ],
    ]];
}

it('flatten_ga4 produces 3 flat rows keyed by dimension+metric names', function () {
    $rows = wpultra_analytics_flatten_ga4(analytics_ga4_fixture());
    assert_eq(3, count($rows));
    assert_eq(['date' => '20260701', 'pagePath' => '/', 'totalUsers' => 120, 'screenPageViews' => 340], $rows[0]);
    assert_eq(['date' => '20260702', 'pagePath' => '/blog', 'totalUsers' => 90, 'screenPageViews' => 210], $rows[1]);
    // 77.5 must stay a float.
    assert_eq(77.5, $rows[2]['screenPageViews']);
    assert_true(is_float($rows[2]['screenPageViews']), 'decimal metric stays float');
    assert_true(is_int($rows[0]['totalUsers']), 'integer metric becomes int');
});

it('flatten_ga4 works on the un-wrapped report body too', function () {
    $body = analytics_ga4_fixture()[0]; // strip the numeric list wrapper
    $rows = wpultra_analytics_flatten_ga4($body);
    assert_eq(3, count($rows));
    assert_eq(120, $rows[0]['totalUsers']);
});

it('flatten_ga4 returns [] for an empty/garbage response', function () {
    assert_eq([], wpultra_analytics_flatten_ga4([]));
    assert_eq([], wpultra_analytics_flatten_ga4(['nonsense' => 1]));
});

it('extract_ga4_totals maps report totals to metric names', function () {
    $body = analytics_ga4_fixture()[0];
    $totals = wpultra_analytics_extract_ga4_totals($body);
    assert_eq(['totalUsers' => 255, 'screenPageViews' => 627.5], $totals);
});

// ---------------------------------------------------------------------------
// flatten_sc — Search Console fixture
// ---------------------------------------------------------------------------

function analytics_sc_fixture(): array {
    return [
        'rows' => [
            ['keys' => ['wordpress mcp'],    'clicks' => 52, 'impressions' => 1200, 'ctr' => 0.0433, 'position' => 3.4],
            ['keys' => ['site kit reader'],  'clicks' => 18, 'impressions' => 640,  'ctr' => 0.0281, 'position' => 7.1],
            ['keys' => ['ga4 through mcp'],  'clicks' => 4,  'impressions' => 300,  'ctr' => 0.0133, 'position' => 12.0],
        ],
    ];
}

it('flatten_sc maps keys to dimension names + numeric metrics', function () {
    $rows = wpultra_analytics_flatten_sc(analytics_sc_fixture(), ['query']);
    assert_eq(3, count($rows));
    assert_eq([
        'query' => 'wordpress mcp', 'clicks' => 52, 'impressions' => 1200, 'ctr' => 0.0433, 'position' => 3.4,
    ], $rows[0]);
    assert_true(is_int($rows[0]['clicks']), 'clicks int');
    assert_true(is_float($rows[0]['ctr']), 'ctr float');
});

it('flatten_sc honours a page dimension and multi-key rows', function () {
    $resp = ['rows' => [['keys' => ['/pricing', '2026-07-01'], 'clicks' => 9, 'impressions' => 50, 'ctr' => 0.18, 'position' => 2.0]]];
    $rows = wpultra_analytics_flatten_sc($resp, ['page', 'date']);
    assert_eq('/pricing', $rows[0]['page']);
    assert_eq('2026-07-01', $rows[0]['date']);
    assert_eq(9, $rows[0]['clicks']);
});

it('flatten_sc returns [] for an empty response', function () {
    assert_eq([], wpultra_analytics_flatten_sc([], ['query']));
});

// ---------------------------------------------------------------------------
// numify + name_list edge cases
// ---------------------------------------------------------------------------

it('numify casts numeric strings, leaves non-numeric untouched', function () {
    assert_eq(42, wpultra_analytics_numify('42'));
    assert_eq(3.5, wpultra_analytics_numify('3.5'));
    assert_eq('n/a', wpultra_analytics_numify('n/a'));
    assert_eq(7, wpultra_analytics_numify(7));
});

it('name_list falls back to defaults on empty, accepts arrays, rejects bad chars', function () {
    assert_eq(['date'], wpultra_analytics_name_list('', ['date']));
    assert_eq(['a', 'b'], wpultra_analytics_name_list(['a', 'b'], ['date']));
    assert_eq(null, wpultra_analytics_name_list('good, b@d', ['date']));
});

run_tests();
