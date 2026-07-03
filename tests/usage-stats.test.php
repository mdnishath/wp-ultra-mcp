<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/usage.php';

/* ------------------------------------------------------------------ totals */

it('usage_totals sums calls/fails and counts distinct abilities', function () {
    $rows = [
        ['action' => 'write-file', 'calls' => 10, 'fails' => 1, 'fail_rate' => 0.1],
        ['action' => 'read-file',  'calls' => 20, 'fails' => 0, 'fail_rate' => 0.0],
        ['action' => 'execute-php', 'calls' => 5, 'fails' => 5, 'fail_rate' => 1.0],
    ];
    $t = wpultra_usage_totals($rows);
    assert_eq(35, $t['calls']);
    assert_eq(6, $t['fails']);
    assert_eq(3, $t['abilities']);
    assert_eq('read-file', $t['top_action'], 'read-file has the most calls (20)');
});

it('usage_totals on empty input returns zeroed totals with no top action', function () {
    $t = wpultra_usage_totals([]);
    assert_eq(0, $t['calls']);
    assert_eq(0, $t['fails']);
    assert_eq(0, $t['abilities']);
    assert_eq('', $t['top_action']);
});

it('usage_totals ignores non-array rows and missing keys default to 0', function () {
    $rows = [
        ['action' => 'a', 'calls' => 3, 'fails' => 1],
        'not-an-array',
        ['action' => 'b'], // no calls/fails
    ];
    $t = wpultra_usage_totals($rows);
    assert_eq(3, $t['calls']);
    assert_eq(1, $t['fails']);
    assert_eq(3, $t['abilities'], 'count($rows) counts all elements, including the bad one');
    assert_eq('a', $t['top_action'], 'a (3 calls) beats b (0 calls)');
});

/* ------------------------------------------------------------------ sort matrix */

$sample = [
    ['action' => 'a', 'calls' => 10, 'fails' => 1, 'fail_rate' => 0.1],
    ['action' => 'b', 'calls' => 4,  'fails' => 4, 'fail_rate' => 1.0],
    ['action' => 'c', 'calls' => 8,  'fails' => 4, 'fail_rate' => 0.5],
];

it('usage_sort by calls (desc)', function () use ($sample) {
    $r = wpultra_usage_sort($sample, 'calls');
    assert_eq(['a', 'c', 'b'], array_column($r, 'action'));
});

it('usage_sort by fails (desc)', function () use ($sample) {
    $r = wpultra_usage_sort($sample, 'fails');
    // b and c tie at 4 fails; tiebreak is calls desc, so c (8 calls) before b (4 calls).
    assert_eq(['c', 'b', 'a'], array_column($r, 'action'));
});

it('usage_sort by fail_rate (desc)', function () use ($sample) {
    $r = wpultra_usage_sort($sample, 'fail_rate');
    assert_eq(['b', 'c', 'a'], array_column($r, 'action'));
});

it('usage_sort falls back to calls for an unknown key', function () use ($sample) {
    $r = wpultra_usage_sort($sample, 'bogus');
    assert_eq(['a', 'c', 'b'], array_column($r, 'action'));
});

it('usage_sort does not mutate the input array', function () use ($sample) {
    $before = $sample;
    wpultra_usage_sort($sample, 'fails');
    assert_eq($before, $sample);
});

it('usage_sort breaks exact ties deterministically by action name', function () {
    $rows = [
        ['action' => 'zeta',  'calls' => 5, 'fails' => 1, 'fail_rate' => 0.2],
        ['action' => 'alpha', 'calls' => 5, 'fails' => 1, 'fail_rate' => 0.2],
    ];
    $r = wpultra_usage_sort($rows, 'calls');
    assert_eq(['alpha', 'zeta'], array_column($r, 'action'));
});

/* ------------------------------------------------------------------ bar width */

it('usage_bar_width computes a proportional 0-100 width', function () {
    assert_eq(100, wpultra_usage_bar_width(50, 50));
    assert_eq(50, wpultra_usage_bar_width(25, 50));
    assert_eq(0, wpultra_usage_bar_width(0, 50));
    assert_eq(20, wpultra_usage_bar_width(1, 5));
});

it('usage_bar_width guards a zero or negative max (no division by zero)', function () {
    assert_eq(0, wpultra_usage_bar_width(10, 0));
    assert_eq(0, wpultra_usage_bar_width(10, -5));
});

it('usage_bar_width clamps negative calls to 0 and never exceeds 100', function () {
    assert_eq(0, wpultra_usage_bar_width(-3, 10));
    assert_eq(100, wpultra_usage_bar_width(999, 10));
});

run_tests();
