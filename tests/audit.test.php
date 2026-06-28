<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

// In-memory option store so the audit ring buffer can be exercised.
$GLOBALS['__opts'] = [];
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('current_time')) { function current_time($t, $gmt = 0) { return '2026-01-01 00:00:00'; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 5; } }

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';

it('audit log appends entries with action, user, and ok flag', function () {
    $GLOBALS['__opts'] = [];
    wpultra_audit_log('execute-php', 'return 1;', true);
    wpultra_audit_log('delete-file', '/var/www/x.txt', false);
    $log = get_option('wpultra_audit', []);
    assert_eq(2, count($log), 'two entries');
    assert_eq('execute-php', $log[0]['action']);
    assert_eq(5, $log[0]['user']);
    assert_true($log[0]['ok'], 'first ok');
    assert_eq(false, $log[1]['ok'], 'second failed');
});

it('audit log is capped to a ring buffer (newest kept)', function () {
    // apply_filters is stubbed by the harness to return its value unchanged, so the cap is 200.
    $GLOBALS['__opts'] = [];
    for ($i = 0; $i < 260; $i++) { wpultra_audit_log('execute-php', "call-$i", true); }
    $log = get_option('wpultra_audit', []);
    assert_eq(200, count($log), 'capped at 200');
    assert_eq('call-259', $log[count($log) - 1]['summary'], 'newest retained');
    assert_eq('call-60', $log[0]['summary'], 'oldest trimmed off');
});

run_tests();
