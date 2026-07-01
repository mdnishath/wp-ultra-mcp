<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/selftest/engine.php';

it('stats_apply tallies calls and failures', function () {
    $s = [];
    $s = wpultra_stats_apply($s, 'write-file', true);
    $s = wpultra_stats_apply($s, 'write-file', false);
    $s = wpultra_stats_apply($s, 'write-file', true);
    assert_eq(3, $s['write-file']['calls']);
    assert_eq(1, $s['write-file']['fails']);
});

it('stats_rank orders by failure rate then calls', function () {
    $stats = [
        'a' => ['calls' => 10, 'fails' => 1],   // 0.1
        'b' => ['calls' => 4,  'fails' => 4],   // 1.0
        'c' => ['calls' => 8,  'fails' => 4],   // 0.5
    ];
    $r = wpultra_stats_rank($stats, 10);
    assert_eq('b', $r[0]['action'], 'highest fail rate first');
    assert_eq('c', $r[1]['action']);
    assert_eq('a', $r[2]['action']);
    assert_eq(1.0, $r[0]['fail_rate']);
});

it('provider_matrix flags a router/adapter name mismatch (the Meta Box C1 class of bug)', function () {
    // Simulate the real regression: metabox read/write named with the wrong prefix.
    $defined = [
        'wpultra_fields_acf_read' => 1, 'wpultra_fields_acf_write' => 1,
        'wpultra_fields_acf_list_groups' => 1, 'wpultra_fields_acf_get_group' => 1,
        // metabox: list_groups/get_group present, read/write MISSING (the bug)
        'wpultra_fields_metabox_list_groups' => 1, 'wpultra_fields_metabox_get_group' => 1,
    ];
    $exists = fn($fn) => isset($defined[$fn]);
    $missing = wpultra_selftest_provider_matrix(['acf', 'metabox'], $exists);
    assert_eq(['wpultra_fields_metabox_read', 'wpultra_fields_metabox_write'], $missing);
    // Healthy case: acf only → nothing missing.
    assert_eq([], wpultra_selftest_provider_matrix(['acf'], $exists));
});

it('subsystem_matrix reports only the broken subsystems', function () {
    $exists = fn($fn) => $fn !== 'wpultra_gb_save'; // pretend gutenberg save is missing
    $broken = wpultra_selftest_subsystem_matrix([
        'elementor' => ['wpultra_el_read'],
        'gutenberg' => ['wpultra_gb_load', 'wpultra_gb_save'],
    ], $exists);
    assert_true(!isset($broken['elementor']), 'elementor healthy');
    assert_eq(['wpultra_gb_save'], $broken['gutenberg']);
});

it('summarize rolls checks into an overall verdict', function () {
    $ok = wpultra_selftest_summarize([
        ['name' => 'a', 'ok' => true], ['name' => 'b', 'ok' => true],
    ]);
    assert_true($ok['ok']);
    assert_eq([], $ok['failed']);
    $bad = wpultra_selftest_summarize([
        ['name' => 'a', 'ok' => true], ['name' => 'b', 'ok' => false],
    ]);
    assert_true(!$bad['ok']);
    assert_eq(['b'], $bad['failed']);
});

run_tests();
