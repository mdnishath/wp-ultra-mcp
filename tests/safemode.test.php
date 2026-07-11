<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

$GLOBALS['__opts'] = [];
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $ac = true) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('current_time')) { function current_time($type, $gmt = 0) { return '2026-07-11 00:00:00'; } }
if (!function_exists('wp_register_ability')) { function wp_register_ability($slug, $args) { $GLOBALS['__abilities'][$slug] = $args; return true; } }

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/errors.php';
require __DIR__ . '/../wp-ultra-mcp/includes/sandbox/manage.php';

/* ------------------------------------------------------------------ *
 * wpultra_safemode_sentinel_path — pure path join
 * ------------------------------------------------------------------ */

it('sentinel_path joins dir + filename with a single slash regardless of trailing slash', function () {
    assert_eq('/a/b/.crashed', wpultra_safemode_sentinel_path('/a/b'));
    assert_eq('/a/b/.crashed', wpultra_safemode_sentinel_path('/a/b/'));
    assert_eq('/a/b/.crashed', wpultra_safemode_sentinel_path('/a/b\\'));
});

/* ------------------------------------------------------------------ *
 * wpultra_safemode_validate_cause
 * ------------------------------------------------------------------ */

it('validate_cause rejects an empty string', function () {
    assert_wp_error(wpultra_safemode_validate_cause(''));
});

it('validate_cause rejects a too-short cause', function () {
    $r = wpultra_safemode_validate_cause('short');
    assert_wp_error($r);
    assert_eq('cause_required', $r->get_error_code());
});

it('validate_cause rejects a cause that is only whitespace padding', function () {
    assert_wp_error(wpultra_safemode_validate_cause('   abc   '));
});

it('validate_cause accepts a trimmed cause >= 10 chars', function () {
    assert_eq(true, wpultra_safemode_validate_cause('fixed undefined function foo() in snippet'));
});

it('validate_cause rejects non-string input', function () {
    assert_wp_error(wpultra_safemode_validate_cause(null));
    assert_wp_error(wpultra_safemode_validate_cause(12345678901));
    assert_wp_error(wpultra_safemode_validate_cause(['not', 'a', 'string']));
});

/* ------------------------------------------------------------------ *
 * wpultra_safemode_ring_push
 * ------------------------------------------------------------------ */

it('ring_push prepends newest-first and caps at 20 by default', function () {
    $ring = [];
    for ($i = 1; $i <= 25; $i++) {
        $ring = wpultra_safemode_ring_push($ring, ['n' => $i]);
    }
    assert_eq(20, count($ring));
    assert_eq(25, $ring[0]['n']);
    assert_eq(6, $ring[19]['n']);
});

it('ring_push respects a custom cap', function () {
    $ring = [];
    for ($i = 1; $i <= 5; $i++) { $ring = wpultra_safemode_ring_push($ring, ['n' => $i], 3); }
    assert_eq(3, count($ring));
    assert_eq(5, $ring[0]['n']);
    assert_eq(3, $ring[2]['n']);
});

/* ------------------------------------------------------------------ *
 * how-to-clear note
 * ------------------------------------------------------------------ */

it('how_to_clear note mentions the cause requirement and next-request resumption', function () {
    $note = wpultra_safemode_how_to_clear_note();
    assert_true(str_contains($note, 'cause'), 'note should mention cause');
    assert_true(str_contains($note, 'NEXT request'), 'note should mention resuming on the next request');
});

/* ------------------------------------------------------------------ *
 * status / arm / clear — file behaviour against a real temp sandbox dir
 * ------------------------------------------------------------------ */

$GLOBALS['__test_dir'] = sys_get_temp_dir() . '/wpultra-safemode-test-' . uniqid();
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', $GLOBALS['__test_dir']); }
@mkdir(WP_CONTENT_DIR . '/wpultra-sandbox', 0755, true);

it('status reports inactive when the sentinel does not exist', function () {
    $s = wpultra_safemode_status();
    assert_eq(false, $s['active']);
    assert_eq(false, $s['sentinel_exists']);
    assert_true($s['sentinel_content'] === null);
    assert_true($s['sentinel_mtime'] === null);
});

it('arm without confirm at the engine layer still writes the sentinel (gate lives in the ability layer)', function () {
    $r = wpultra_safemode_do_arm('testing arm');
    assert_true(!is_wp_error($r));
    assert_eq(true, $r['armed']);
    $s = wpultra_safemode_status();
    assert_eq(true, $s['active']);
    assert_true(str_contains((string) $s['sentinel_content'], 'testing arm'), 'sentinel content should include the reason');
    assert_true(str_contains((string) $s['sentinel_content'], 'safe-mode-manage'), 'sentinel content should record armed_by');
});

it('clear refuses without a cause', function () {
    $r = wpultra_safemode_do_clear('');
    assert_wp_error($r);
    assert_eq('cause_required', $r->get_error_code());
});

it('clear refuses with a too-short cause', function () {
    assert_wp_error(wpultra_safemode_do_clear('short'));
});

it('clear deletes the sentinel and records a ring entry when the cause is valid', function () {
    wpultra_safemode_do_arm('setup for clear test'); // ensure armed
    $r = wpultra_safemode_do_clear('execute-php snippet called undefined function foo(); snippet fixed');
    assert_true(!is_wp_error($r));
    assert_eq(true, $r['cleared']);
    assert_eq(true, $r['was_active']);
    $s = wpultra_safemode_status();
    assert_eq(false, $s['active']);
    $clears = get_option('wpultra_safemode_clears', []);
    assert_true(count($clears) >= 1, 'expected at least one clear ring entry');
    assert_true(str_contains($clears[0]['cause'], 'undefined function foo'));
    assert_true(str_contains((string) $clears[0]['content'], 'setup for clear test'), 'ring entry should keep the cleared sentinel content');
});

it('clear on an already-inactive sentinel still succeeds but reports was_active=false', function () {
    $r = wpultra_safemode_do_clear('nothing to clear here, just testing idempotence');
    assert_true(!is_wp_error($r));
    assert_eq(true, $r['cleared']);
    assert_eq(false, $r['was_active']);
});

/* ------------------------------------------------------------------ *
 * status.last_fatal wiring against the error-reports ring
 * ------------------------------------------------------------------ */

it('status.last_fatal reflects the newest captured error report', function () {
    update_option('wpultra_error_log', [
        ['ts' => 200, 'message' => 'newest', 'file' => 'a.php', 'line' => 1, 'url' => '/', 'suggestions' => []],
        ['ts' => 100, 'message' => 'older', 'file' => 'b.php', 'line' => 2, 'url' => '/', 'suggestions' => []],
    ]);
    $s = wpultra_safemode_status();
    assert_true($s['last_fatal'] !== null);
    assert_eq('newest', $s['last_fatal']['message']);
});

it('status.last_fatal is null when no errors were captured', function () {
    update_option('wpultra_error_log', []);
    $s = wpultra_safemode_status();
    assert_true($s['last_fatal'] === null);
});

/* ------------------------------------------------------------------ *
 * Ability wrapper: confirm-gating + action dispatch
 * ------------------------------------------------------------------ */

require __DIR__ . '/../wp-ultra-mcp/includes/abilities/safe-mode-manage.php';

it('ability: default action is status and is read-only (no confirm needed)', function () {
    $r = wpultra_safe_mode_manage_cb([]);
    assert_true(!is_wp_error($r));
    assert_eq('status', $r['action']);
    assert_eq(true, $r['success']);
});

it('ability: clear without confirm is refused', function () {
    $r = wpultra_safe_mode_manage_cb(['action' => 'clear', 'cause' => 'a valid enough cause string']);
    assert_wp_error($r);
    assert_eq('confirm_required', $r->get_error_code());
});

it('ability: clear with confirm but no cause is refused', function () {
    $r = wpultra_safe_mode_manage_cb(['action' => 'clear', 'confirm' => true]);
    assert_wp_error($r);
    assert_eq('cause_required', $r->get_error_code());
});

it('ability: arm without confirm is refused', function () {
    $r = wpultra_safe_mode_manage_cb(['action' => 'arm', 'confirm' => false]);
    assert_wp_error($r);
    assert_eq('confirm_required', $r->get_error_code());
});

it('ability: arm with confirm succeeds and clear with confirm+cause then succeeds', function () {
    $armed = wpultra_safe_mode_manage_cb(['action' => 'arm', 'confirm' => true, 'reason' => 'maintenance window']);
    assert_true(!is_wp_error($armed));
    assert_eq(true, $armed['armed']);

    $cleared = wpultra_safe_mode_manage_cb([
        'action'  => 'clear',
        'confirm' => true,
        'cause'   => 'maintenance window finished, re-enabling code exec',
    ]);
    assert_true(!is_wp_error($cleared));
    assert_eq(true, $cleared['cleared']);
});

it('ability: unknown action is rejected', function () {
    $r = wpultra_safe_mode_manage_cb(['action' => 'bogus']);
    assert_wp_error($r);
    assert_eq('bad_action', $r->get_error_code());
});

run_tests();
