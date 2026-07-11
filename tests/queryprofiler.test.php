<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/queryprofiler.php';

/* ------------------------------------------------------------------ *
 * wpultra_qprof_normalize_sql
 * ------------------------------------------------------------------ */

it('normalize_sql collapses internal whitespace/newlines', function () {
    $sql = "SELECT *\n FROM   wp_posts  WHERE ID = 1";
    assert_eq('SELECT * FROM wp_posts WHERE ID = ?', wpultra_qprof_normalize_sql($sql));
});

it('normalize_sql masks numeric literals', function () {
    assert_eq(
        'SELECT * FROM wp_posts WHERE ID = ? AND menu_order = ?',
        wpultra_qprof_normalize_sql('SELECT * FROM wp_posts WHERE ID = 42 AND menu_order = 7')
    );
});

it('normalize_sql masks single-quoted string literals', function () {
    assert_eq(
        "SELECT * FROM wp_posts WHERE post_status = ?",
        wpultra_qprof_normalize_sql("SELECT * FROM wp_posts WHERE post_status = 'publish'")
    );
});

it('normalize_sql masks double-quoted string literals', function () {
    assert_eq(
        'SELECT * FROM wp_posts WHERE post_status = ?',
        wpultra_qprof_normalize_sql('SELECT * FROM wp_posts WHERE post_status = "publish"')
    );
});

it('normalize_sql makes structurally-identical queries with different literals equal', function () {
    $a = wpultra_qprof_normalize_sql("SELECT * FROM wp_postmeta WHERE post_id = 10 AND meta_key = 'foo'");
    $b = wpultra_qprof_normalize_sql("SELECT * FROM wp_postmeta WHERE post_id = 99 AND meta_key = 'bar'");
    assert_eq($a, $b);
});

it('normalize_sql handles decimal numeric literals', function () {
    assert_eq(
        'SELECT * FROM wp_options WHERE option_value = ?',
        wpultra_qprof_normalize_sql('SELECT * FROM wp_options WHERE option_value = 3.14')
    );
});

it('normalize_sql masks both single- and double-quoted literals in one statement', function () {
    assert_eq(
        "SELECT * FROM wp_posts WHERE post_status = ? AND post_title = ?",
        wpultra_qprof_normalize_sql("SELECT * FROM wp_posts WHERE post_status = 'publish' AND post_title = \"hello\"")
    );
});

it('normalize_sql: two double-quoted literals each containing an apostrophe do not swallow the clause between them (regression)', function () {
    $sql = 'SELECT * FROM t WHERE a = "it\'s here" AND b = "test\'s ok"';
    $normalized = wpultra_qprof_normalize_sql($sql);
    assert_eq('SELECT * FROM t WHERE a = ? AND b = ?', $normalized);
});

it('normalize_sql handles escaped quotes inside literals (doubled single-quote and backslash-escaped double-quote)', function () {
    assert_eq(
        "SELECT * FROM wp_posts WHERE post_title = ? AND note = ?",
        wpultra_qprof_normalize_sql("SELECT * FROM wp_posts WHERE post_title = 'O''Brien' AND note = \"a\\\"b\"")
    );
});

/* ------------------------------------------------------------------ *
 * wpultra_qprof_caller
 * ------------------------------------------------------------------ */

it('caller: returns the last meaningful frame', function () {
    assert_eq('WP_Query->get_posts', wpultra_qprof_caller('require, wp-blog-header.php, WP_Query->query, WP_Query->get_posts'));
});

it('caller: skips a trailing require/include frame', function () {
    assert_eq('WP_Query->get_posts', wpultra_qprof_caller('WP_Query->get_posts, require_once'));
});

it('caller: empty stack returns unknown', function () {
    assert_eq('unknown', wpultra_qprof_caller(''));
});

it('caller: all-skip-listed stack falls back to the last raw frame', function () {
    assert_eq('require_once', wpultra_qprof_caller('require, require_once'));
});

/* ------------------------------------------------------------------ *
 * wpultra_qprof_analyze
 * ------------------------------------------------------------------ */

it('analyze: empty query set returns zeroed report', function () {
    $r = wpultra_qprof_analyze([], 10);
    assert_eq(0, $r['total_queries']);
    assert_eq(0.0, $r['total_time_ms']);
    assert_eq([], $r['slowest']);
    assert_eq([], $r['duplicates']);
});

it('analyze: detects a duplicate group after normalization (count >= 2)', function () {
    $queries = [
        ["SELECT * FROM wp_postmeta WHERE post_id = 1 AND meta_key = 'foo'", 0.001, 'caller_a'],
        ["SELECT * FROM wp_postmeta WHERE post_id = 2 AND meta_key = 'bar'", 0.002, 'caller_a'],
        ["SELECT * FROM wp_postmeta WHERE post_id = 3 AND meta_key = 'baz'", 0.003, 'caller_a'],
        ["SELECT option_value FROM wp_options WHERE option_name = 'siteurl'", 0.0005, 'caller_b'],
    ];
    $r = wpultra_qprof_analyze($queries, 10);
    assert_eq(4, $r['total_queries']);
    assert_eq(1, count($r['duplicates']));
    assert_eq(3, $r['duplicates'][0]['count']);
    assert_eq('SELECT * FROM wp_postmeta WHERE post_id = ? AND meta_key = ?', $r['duplicates'][0]['normalized_sql']);
    assert_eq(6.0, $r['duplicates'][0]['total_ms']); // (0.001+0.002+0.003)*1000
});

it('analyze: no duplicates when every normalized query is unique', function () {
    $queries = [
        ['SELECT 1', 0.001, 'a'],
        ['SELECT 2 FROM wp_posts', 0.001, 'b'],
    ];
    $r = wpultra_qprof_analyze($queries, 10);
    assert_eq([], $r['duplicates']);
});

it('analyze: slowest is ordered descending by duration and capped at top-N', function () {
    $queries = [
        ['SELECT a', 0.001, 'c1'],
        ['SELECT b', 0.050, 'c2'],
        ['SELECT c', 0.010, 'c3'],
        ['SELECT d', 0.200, 'c4'],
        ['SELECT e', 0.005, 'c5'],
    ];
    $r = wpultra_qprof_analyze($queries, 2);
    assert_eq(2, count($r['slowest']));
    assert_eq('SELECT d', $r['slowest'][0]['sql_excerpt']);
    assert_eq(200.0, $r['slowest'][0]['ms']);
    assert_eq('SELECT b', $r['slowest'][1]['sql_excerpt']);
    assert_eq(50.0, $r['slowest'][1]['ms']);
});

it('analyze: ms values are rounded to 2 decimal places', function () {
    $queries = [
        ['SELECT x', 0.0016666, 'c'],
    ];
    $r = wpultra_qprof_analyze($queries, 10);
    assert_eq(1.67, $r['slowest'][0]['ms']);
    assert_eq(1.67, $r['total_time_ms']);
});

it('analyze: total_time_ms sums every captured query', function () {
    $queries = [
        ['SELECT x', 0.001, 'c'],
        ['SELECT y', 0.002, 'c'],
        ['SELECT z', 0.003, 'c'],
    ];
    $r = wpultra_qprof_analyze($queries, 10);
    assert_eq(6.0, $r['total_time_ms']);
});

it('analyze: caller is derived from the raw call-stack column', function () {
    $queries = [
        ['SELECT x', 0.010, 'require, WP_Query->get_posts'],
    ];
    $r = wpultra_qprof_analyze($queries, 10);
    assert_eq('WP_Query->get_posts', $r['slowest'][0]['caller']);
});

it('analyze: long sql is truncated in sql_excerpt', function () {
    $long = 'SELECT ' . str_repeat('a, ', 100) . 'z FROM wp_posts';
    $queries = [[$long, 0.001, 'c']];
    $r = wpultra_qprof_analyze($queries, 10);
    assert_true(strlen($r['slowest'][0]['sql_excerpt']) <= 203, 'excerpt should be capped (200 chars + ellipsis)');
    assert_true(str_ends_with($r['slowest'][0]['sql_excerpt'], '...'), 'truncated excerpt should end with ellipsis');
});

it('analyze: malformed rows (missing sql/duration, non-array) are skipped without a fatal error', function () {
    $queries = [
        ['SELECT ok', 0.001, 'c'],
        'not-an-array',
        [], // missing both indexes
        [0 => 'SELECT missing_duration'], // missing index 1
    ];
    $r = wpultra_qprof_analyze($queries, 10);
    assert_eq(1, $r['total_queries']);
    assert_eq('SELECT ok', $r['slowest'][0]['sql_excerpt']);
});

it('analyze: top is clamped to at least 1 even if given 0 or negative', function () {
    $queries = [
        ['SELECT a', 0.001, 'c'],
        ['SELECT b', 0.002, 'c'],
    ];
    $r = wpultra_qprof_analyze($queries, 0);
    assert_eq(1, count($r['slowest']));
});

run_tests();
