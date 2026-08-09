<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

// In-memory option store that COUNTS writes — the point of C1.10 is one
// persisted write per option per request, however many entries were logged.
$GLOBALS['__opts'] = [];
$GLOBALS['__writes'] = [];
if (!function_exists('get_option'))    { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; $GLOBALS['__writes'][$k] = ($GLOBALS['__writes'][$k] ?? 0) + 1; return true; } }
if (!function_exists('current_time'))  { function current_time($t, $g = 0) { return '2026-01-01 00:00:00'; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/selftest/engine.php'; // wpultra_stats_apply

function reset_buffer() {
    $GLOBALS['__opts'] = [];
    $GLOBALS['__writes'] = [];
    $GLOBALS['__wpultra_audit_buffer'] = [];
}

it('N logged entries persist with ONE audit write and ONE stats write', function () {
    reset_buffer();
    for ($i = 1; $i <= 20; $i++) { wpultra_audit_write('create-post', "post $i", true); }
    assert_eq(0, $GLOBALS['__writes']['wpultra_audit'] ?? 0, 'no write before flush');
    wpultra_audit_flush();
    assert_eq(1, $GLOBALS['__writes']['wpultra_audit'] ?? 0, 'one audit write');
    assert_eq(1, $GLOBALS['__writes']['wpultra_ability_stats'] ?? 0, 'one stats write');
    assert_eq(20, count($GLOBALS['__opts']['wpultra_audit']));
    assert_eq(20, (int) $GLOBALS['__opts']['wpultra_ability_stats']['create-post']['calls']);
});

it('flush is idempotent — second flush writes nothing', function () {
    reset_buffer();
    wpultra_audit_write('option-set', 'x', true);
    wpultra_audit_flush();
    wpultra_audit_flush();
    assert_eq(1, $GLOBALS['__writes']['wpultra_audit'] ?? 0);
    assert_eq(1, count($GLOBALS['__opts']['wpultra_audit']));
});

it('ring trims to wpultra_audit_max across buffered + persisted entries', function () {
    reset_buffer();
    // Pre-existing persisted log of 195 + 10 buffered = trims to last 200.
    $pre = [];
    for ($i = 0; $i < 195; $i++) { $pre[] = ['ts' => 't', 'user' => 0, 'action' => 'old', 'summary' => "old $i", 'ok' => true]; }
    $GLOBALS['__opts']['wpultra_audit'] = $pre;
    for ($i = 0; $i < 10; $i++) { wpultra_audit_write('new-action', "new $i", true); }
    wpultra_audit_flush();
    $log = $GLOBALS['__opts']['wpultra_audit'];
    assert_eq(200, count($log));
    assert_eq('new 9', end($log)['summary']); // newest kept
    assert_eq('old 5', $log[0]['summary']);   // oldest 5 dropped
});

it('failed entries feed stats fails + last_error, truncated to 200 chars', function () {
    reset_buffer();
    wpultra_audit_write('site-migrate', str_repeat('e', 250), false);
    wpultra_audit_write('site-migrate', 'ok now', true);
    wpultra_audit_flush();
    $s = $GLOBALS['__opts']['wpultra_ability_stats']['site-migrate'];
    assert_eq(2, (int) $s['calls']);
    assert_eq(1, (int) $s['fails']);
    assert_eq(200, strlen((string) $s['last_error']));
});

it('mixed actions aggregate into per-action tallies in one pass', function () {
    reset_buffer();
    wpultra_audit_write('a', 'x', true);
    wpultra_audit_write('b', 'y', false);
    wpultra_audit_write('a', 'z', true);
    wpultra_audit_flush();
    assert_eq(2, (int) $GLOBALS['__opts']['wpultra_ability_stats']['a']['calls']);
    assert_eq(1, (int) $GLOBALS['__opts']['wpultra_ability_stats']['b']['fails']);
    assert_eq(1, $GLOBALS['__writes']['wpultra_ability_stats'] ?? 0);
});

it('activity reader flushes the buffer transparently', function () {
    reset_buffer();
    wpultra_audit_write('delete-post', 'trashed', true);
    // wpultra_activity_read() calls wpultra_audit_flush() before reading.
    require_once __DIR__ . '/../wp-ultra-mcp/includes/system/activity.php';
    $rows = wpultra_activity_read([]);
    assert_eq(1, count($rows));
    assert_eq('delete-post', $rows[0]['action']);
});

run_tests();
