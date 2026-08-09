<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

// In-memory option store + minimal WP stubs the audit path touches.
$GLOBALS['__opts'] = [];
if (!function_exists('get_option'))    { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('current_time'))  { function current_time($t, $g = 0) { return '2026-01-01 00:00:00'; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }

// wp_get_ability stub: readonly for names ending in -readonly, else non-readonly.
if (!function_exists('wp_get_ability')) {
    function wp_get_ability($name) {
        if (strpos($name, 'unknown') !== false) { return null; }
        $readonly = str_ends_with($name, '-readonly');
        return new class($readonly) {
            private bool $ro;
            public function __construct(bool $ro) { $this->ro = $ro; }
            public function get_meta_item($k, $d = null) { return $k === 'annotations' ? ['readonly' => $this->ro] : $d; }
        };
    }
}

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';

function reset_audit() {
    $GLOBALS['__opts'] = [];
    $GLOBALS['__wpultra_audit_frames'] = [];
    $GLOBALS['__wpultra_audit_buffer'] = [];
}
// C1.10: writes buffer in-request and persist on flush (shutdown in production).
function audit_log_entries(): array { wpultra_audit_flush(); return $GLOBALS['__opts']['wpultra_audit'] ?? []; }

it('a write ability that never self-logs is centrally logged once on success', function () {
    reset_audit();
    wpultra_audit_central_before('wpultra/create-post', []);
    wpultra_audit_central_after('wpultra/create-post', [], ['success' => true]);
    $log = audit_log_entries();
    assert_eq(1, count($log));
    assert_eq('create-post', $log[0]['action']);
    assert_true($log[0]['ok']);
});

it('a self-logging ability is NOT double-logged', function () {
    reset_audit();
    wpultra_audit_central_before('wpultra/delete-post', []);
    wpultra_audit_log('delete-post', "trashed 'Hello'", true); // ability's own rich entry
    wpultra_audit_central_after('wpultra/delete-post', [], ['success' => true]);
    $log = audit_log_entries();
    assert_eq(1, count($log));
    assert_eq("trashed 'Hello'", $log[0]['summary']); // rich summary kept, no generic dup
});

it('a failed call (no after-hook) is logged as failure at shutdown', function () {
    reset_audit();
    wpultra_audit_central_before('wpultra/create-post', []);
    // do_execute returned WP_Error → core skips wp_after_execute_ability.
    wpultra_audit_central_shutdown();
    $log = audit_log_entries();
    assert_eq(1, count($log));
    assert_eq('create-post', $log[0]['action']);
    assert_eq(false, $log[0]['ok']);
});

it('readonly abilities are not centrally logged', function () {
    reset_audit();
    wpultra_audit_central_before('wpultra/list-readonly', []);
    wpultra_audit_central_after('wpultra/list-readonly', [], ['success' => true]);
    assert_eq(0, count(audit_log_entries()));
});

it('non-wpultra abilities are ignored', function () {
    reset_audit();
    wpultra_audit_central_before('core/something', []);
    wpultra_audit_central_after('core/something', [], ['success' => true]);
    assert_eq(0, count(audit_log_entries()));
});

it('a failed self-logging call keeps its rich entry, no generic dup at shutdown', function () {
    reset_audit();
    wpultra_audit_central_before('wpultra/site-migrate', []);
    wpultra_audit_log('site-migrate', 'export failed: disk full', false);
    wpultra_audit_central_shutdown();
    $log = audit_log_entries();
    assert_eq(1, count($log));
    assert_eq('export failed: disk full', $log[0]['summary']);
    assert_eq(false, $log[0]['ok']);
});

it('sequential calls: success then failure both recorded correctly', function () {
    reset_audit();
    wpultra_audit_central_before('wpultra/create-post', []);
    wpultra_audit_central_after('wpultra/create-post', [], ['success' => true]);
    wpultra_audit_central_before('wpultra/option-set', []);
    wpultra_audit_central_shutdown(); // second call failed
    $log = audit_log_entries();
    assert_eq(2, count($log));
    assert_true($log[0]['ok']);
    assert_eq('option-set', $log[1]['action']);
    assert_eq(false, $log[1]['ok']);
});

run_tests();
