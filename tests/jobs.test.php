<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
require __DIR__ . '/../wp-ultra-mcp/includes/jobs/engine.php';
require __DIR__ . '/../wp-ultra-mcp/includes/jobs/handlers.php';

it('state machine: active vs terminal', function () {
    assert_true(wpultra_jobs_is_active('queued'));
    assert_true(wpultra_jobs_is_active('running'));
    assert_true(!wpultra_jobs_is_active('done'));
    assert_true(!wpultra_jobs_is_active('failed'));
    assert_true(!wpultra_jobs_is_active('cancelled'));
});

it('next_status transitions from active states', function () {
    assert_eq('running',   wpultra_jobs_next_status('queued', 'start'));
    assert_eq('running',   wpultra_jobs_next_status('running', 'slice_ok'));
    assert_eq('done',      wpultra_jobs_next_status('running', 'slice_done'));
    assert_eq('failed',    wpultra_jobs_next_status('running', 'error'));
    assert_eq('cancelled', wpultra_jobs_next_status('queued', 'cancel'));
});

it('next_status: terminal states never move', function () {
    foreach (['done', 'failed', 'cancelled'] as $s) {
        assert_eq($s, wpultra_jobs_next_status($s, 'slice_ok'));
        assert_eq($s, wpultra_jobs_next_status($s, 'cancel'));
        assert_eq($s, wpultra_jobs_next_status($s, 'error'));
    }
});

it('progress_pct clamps and handles zero total', function () {
    assert_eq(0,   wpultra_jobs_progress_pct(0, 0));
    assert_eq(100, wpultra_jobs_progress_pct(5, 0)); // work done, no known total
    assert_eq(50,  wpultra_jobs_progress_pct(5, 10));
    assert_eq(100, wpultra_jobs_progress_pct(10, 10));
    assert_eq(100, wpultra_jobs_progress_pct(99, 10)); // over-count clamps
    assert_eq(33,  wpultra_jobs_progress_pct(1, 3));   // floors
});

it('log_append keeps only the most recent WPULTRA_JOBS_LOG_CAP lines', function () {
    $log = [];
    for ($i = 1; $i <= WPULTRA_JOBS_LOG_CAP + 10; $i++) { $log = wpultra_jobs_log_append($log, "line $i"); }
    assert_eq(WPULTRA_JOBS_LOG_CAP, count($log));
    assert_eq('line ' . (WPULTRA_JOBS_LOG_CAP + 10), $log[count($log) - 1]);
    assert_eq('line 11', $log[0]); // first 10 dropped
});

it('shape exposes id/status/progress with computed percent', function () {
    $blob = wpultra_jobs_new_blob('site-audit', ['post_type' => ['post']]);
    $blob['progress'] = ['processed' => 3, 'total' => 12];
    $s = wpultra_jobs_shape(7, 'running', $blob, '2026-07-02 00:00:00', '2026-07-02 00:01:00');
    assert_eq(7, $s['id']);
    assert_eq('site-audit', $s['type']);
    assert_eq('running', $s['status']);
    assert_eq(3, $s['progress']['processed']);
    assert_eq(25, $s['progress']['percent']);
});

it('validate_start rejects unknown type and missing params', function () {
    $reg = wpultra_jobs_handlers();
    assert_true(is_string(wpultra_jobs_validate_start('', [], $reg)));
    assert_true(is_string(wpultra_jobs_validate_start('nope', [], $reg)));
    // search-replace needs search + replace + confirm
    assert_true(is_string(wpultra_jobs_validate_start('search-replace', ['search' => 'a'], $reg)));
    assert_true(is_string(wpultra_jobs_validate_start('search-replace', ['search' => 'a', 'replace' => 'b'], $reg))); // no confirm
    assert_eq(true, wpultra_jobs_validate_start('search-replace', ['search' => 'a', 'replace' => 'b', 'confirm' => true], $reg));
});

it('validate_start: bulk-post-meta requires a set map + confirm', function () {
    $reg = wpultra_jobs_handlers();
    assert_true(is_string(wpultra_jobs_validate_start('bulk-post-meta', [], $reg)));
    assert_true(is_string(wpultra_jobs_validate_start('bulk-post-meta', ['set' => ['k' => 1]], $reg))); // no confirm
    assert_eq(true, wpultra_jobs_validate_start('bulk-post-meta', ['set' => ['k' => 1], 'confirm' => true], $reg));
});

it('validate_start: site-audit needs no params', function () {
    assert_eq(true, wpultra_jobs_validate_start('site-audit', [], wpultra_jobs_handlers()));
});

it('sr_advance: full batch stays on same table and advances offset', function () {
    $n = wpultra_jobs_sr_advance(['ti' => 0, 'offset' => 0], 500, 500, 3);
    assert_eq(0, $n['ti']);
    assert_eq(500, $n['offset']);
    assert_true(!$n['done']);
});

it('sr_advance: partial batch moves to next table at offset 0', function () {
    $n = wpultra_jobs_sr_advance(['ti' => 0, 'offset' => 500], 120, 500, 3);
    assert_eq(1, $n['ti']);
    assert_eq(0, $n['offset']);
    assert_true(!$n['done']);
});

it('sr_advance: exhausting the last table is done', function () {
    $n = wpultra_jobs_sr_advance(['ti' => 2, 'offset' => 0], 10, 500, 3);
    assert_eq(3, $n['ti']);
    assert_true($n['done']);
});

it('offset_advance: done on short page or reaching total', function () {
    $a = wpultra_jobs_offset_advance(0, 100, 100, 100, 250);
    assert_eq(100, $a['offset']);
    assert_true(!$a['done']); // full page, more remain
    $b = wpultra_jobs_offset_advance(200, 50, 100, 250, 250);
    assert_true($b['done']); // short page
    $c = wpultra_jobs_offset_advance(200, 100, 100, 300, 300);
    assert_true($c['done']); // reached total exactly on a full page
});

it('handler registry exposes the three built-in types', function () {
    $reg = wpultra_jobs_handlers();
    foreach (['search-replace', 'bulk-post-meta', 'site-audit'] as $t) {
        assert_true(isset($reg[$t]), "missing $t");
        assert_true(is_callable($reg[$t]['handler']), "$t handler not callable");
    }
});

run_tests();
