<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_undo_coverage_test/'); }
if (!is_dir(ABSPATH)) { mkdir(ABSPATH, 0755, true); }

// Stateful get_option/update_option/delete_option so full restore-dispatch round
// trips (wpultra_undo_restore()) can be exercised end-to-end, same spirit as the
// non-stateful stubs in siteops.test.php / common-context.md but backed by an
// in-memory store so we can assert on what actually got written.
$GLOBALS['__opts'] = [];
if (!function_exists('get_option')) {
    function get_option($k, $default = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $default; }
}
if (!function_exists('update_option')) {
    function update_option($k, $v, $autoload = null) { $GLOBALS['__opts'][$k] = $v; return true; }
}
if (!function_exists('delete_option')) {
    function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) { return '2026-07-11 00:00:00'; }
}

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/undo/engine.php';

/* ------------------------------------------------------------------ *
 * Regression guards: BF2.6 must not change the original four-type list.
 * ------------------------------------------------------------------ */

it('wpultra_undo_supported_types is unchanged by the BF2.6 extension', function () {
    assert_eq(['option', 'custom_css', 'theme_json', 'term'], wpultra_undo_supported_types());
});

it('wpultra_undo_extended_types adds the three new BF2.6 types on top of the original four', function () {
    assert_eq(
        ['option', 'custom_css', 'theme_json', 'term', 'file', 'active_plugins', 'active_theme'],
        wpultra_undo_extended_types()
    );
});

/* ------------------------------------------------------------------ *
 * Capture gate: new types now pass; still-unsupported types still don't.
 * ------------------------------------------------------------------ */

it('capture accepts the new type "file" (previously gated out)', function () {
    $GLOBALS['__opts'] = [];
    $id = wpultra_undo_capture('file', '/tmp/x.php', 'old content', 'write-file');
    assert_true($id > 0, 'expected a non-zero snapshot id for a now-supported type');
});

it('capture accepts "active_plugins" and "active_theme" too', function () {
    $GLOBALS['__opts'] = [];
    assert_true(wpultra_undo_capture('active_plugins', 'active_plugins', ['a/a.php'], 'x') > 0);
    assert_true(wpultra_undo_capture('active_theme', 'active_theme', ['template' => 't', 'stylesheet' => 's'], 'x') > 0);
});

it('capture still gates out a genuinely unsupported type', function () {
    $GLOBALS['__opts'] = [];
    $id = wpultra_undo_capture('bogus', 'target', 'before', 'label');
    assert_eq(0, $id);
});

/* ------------------------------------------------------------------ *
 * wpultra_undo_file_restore_plan — pure decision logic.
 * ------------------------------------------------------------------ */

it('file_restore_plan: absent sentinel means delete', function () {
    assert_eq(['op' => 'delete'], wpultra_undo_file_restore_plan(WPULTRA_UNDO_ABSENT));
});

it('file_restore_plan: prior contents mean rewrite with those contents', function () {
    assert_eq(['op' => 'rewrite', 'contents' => 'hello world'], wpultra_undo_file_restore_plan('hello world'));
});

it('file_restore_plan: an empty-string prior content is a valid rewrite (not absent)', function () {
    assert_eq(['op' => 'rewrite', 'contents' => ''], wpultra_undo_file_restore_plan(''));
});

/* ------------------------------------------------------------------ *
 * wpultra_undo_restore_file — filesystem restore branch (real tmp files,
 * no WordPress needed for the file operations themselves).
 * ------------------------------------------------------------------ */

it('restore_file: rewrites the file back to its captured prior contents', function () {
    $tmp = rtrim(ABSPATH, '/\\') . '/wpultra_undo_test_' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($tmp, 'MUTATED');
    $entry = wpultra_undo_make_entry(1, 'file', $tmp, 'ORIGINAL', 'edit-file', '');
    $res = wpultra_undo_restore_file($entry);
    assert_true(!is_wp_error($res), 'expected a successful restore');
    assert_eq('reverted', $res['action']);
    assert_eq('ORIGINAL', file_get_contents($tmp));
    @unlink($tmp);
});

it('restore_file: deletes the file when the capture recorded it as previously absent', function () {
    $tmp = rtrim(ABSPATH, '/\\') . '/wpultra_undo_test_' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($tmp, 'CREATED BY write-file');
    $entry = wpultra_undo_make_entry(2, 'file', $tmp, WPULTRA_UNDO_ABSENT, 'write-file', '');
    $res = wpultra_undo_restore_file($entry);
    assert_true(!is_wp_error($res), 'expected a successful restore');
    assert_eq('deleted', $res['action']);
    assert_true(!is_file($tmp), 'file should no longer exist');
});

it('restore_file: refuses a target outside the filesystem jail', function () {
    // The jail base (wpultra_filesystem_base_dir(), unfiltered = ABSPATH) is the
    // per-test temp dir created above; a path elsewhere is outside the jail even
    // though it looks like a normal absolute path.
    $entry = wpultra_undo_make_entry(3, 'file', '/etc/passwd', 'whatever', 'edit-file', '');
    $res = wpultra_undo_restore_file($entry);
    assert_wp_error($res, 'expected the jail check to reject an out-of-base path');
    assert_eq('path_outside_base', $res->get_error_code());
});

/* ------------------------------------------------------------------ *
 * wpultra_undo_restore_active_plugins / wpultra_undo_restore_active_theme —
 * direct option-write restores (matches conflict-bisect's silent-toggle style).
 * ------------------------------------------------------------------ */

it('active_plugins: entry round-trips the before-array verbatim', function () {
    $e = wpultra_undo_make_entry(4, 'active_plugins', 'active_plugins', ['a/a.php', 'b/b.php'], 'toggle plugin b', '');
    assert_eq(['a/a.php', 'b/b.php'], $e['before']);
});

it('active_plugins: restore writes active_plugins back to the captured list', function () {
    $GLOBALS['__opts']['active_plugins'] = ['a/a.php']; // simulate current (post-mutation) state
    $entry = wpultra_undo_make_entry(5, 'active_plugins', 'active_plugins', ['a/a.php', 'b/b.php'], 'x', '');
    $res = wpultra_undo_restore_active_plugins($entry);
    assert_true(!is_wp_error($res));
    assert_eq(['a/a.php', 'b/b.php'], get_option('active_plugins'));
});

it('active_theme: restore writes template/stylesheet back to the captured pair', function () {
    $GLOBALS['__opts']['template'] = 'new-theme';
    $GLOBALS['__opts']['stylesheet'] = 'new-theme';
    $entry = wpultra_undo_make_entry(6, 'active_theme', 'active_theme', ['template' => 'old-theme', 'stylesheet' => 'old-theme-child'], 'x', '');
    $res = wpultra_undo_restore_active_theme($entry);
    assert_true(!is_wp_error($res));
    assert_eq('old-theme', get_option('template'));
    assert_eq('old-theme-child', get_option('stylesheet'));
});

/* ------------------------------------------------------------------ *
 * Full dispatch: wpultra_undo_restore() end-to-end for a new type, and an
 * unknown type still errors cleanly (does not silently no-op or corrupt state).
 * ------------------------------------------------------------------ */

it('undo_restore: full round trip for a newly-supported type (active_plugins)', function () {
    $GLOBALS['__opts'] = [
        'active_plugins'    => ['a/a.php'],
        'wpultra_undo_stack' => [
            ['id' => 42, 'type' => 'active_plugins', 'target' => 'active_plugins', 'before' => ['a/a.php', 'b/b.php'], 'label' => 'x', 'created' => ''],
        ],
    ];
    $res = wpultra_undo_restore(42);
    assert_true(!is_wp_error($res));
    assert_true($res['restored']);
    assert_eq(['a/a.php', 'b/b.php'], get_option('active_plugins'));
    assert_eq(null, wpultra_undo_find(get_option('wpultra_undo_stack'), 42), 'restored entry must be popped off the stack');
});

it('undo_restore: an unknown snapshot type still errors cleanly (no crash, no silent no-op)', function () {
    $GLOBALS['__opts'] = [
        'wpultra_undo_stack' => [
            ['id' => 99, 'type' => 'bogus', 'target' => 'x', 'before' => 'y', 'label' => 'z', 'created' => ''],
        ],
    ];
    $res = wpultra_undo_restore(99);
    assert_wp_error($res, 'expected an error for an unsupported snapshot type');
    assert_eq('unsupported_type', $res->get_error_code());
    // Unresolvable entry must NOT be silently dropped from the stack.
    assert_true(wpultra_undo_find(get_option('wpultra_undo_stack'), 99) !== null, 'unresolved entry must remain in the stack');
});

run_tests();
