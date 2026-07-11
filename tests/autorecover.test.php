<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/autorecover.php';

/* ------------------------------------------------------------------ *
 * wpultra_autorecover_culprit_from_path
 * ------------------------------------------------------------------ */

it('culprit_from_path resolves a plugin subfolder (unix, matching plugins_dir)', function () {
    assert_eq('bad-plugin', wpultra_autorecover_culprit_from_path(
        '/var/www/html/wp-content/plugins/bad-plugin/includes/x.php',
        '/var/www/html/wp-content/plugins'
    ));
});

it('culprit_from_path resolves a plugin subfolder via the generic wp-content/plugins fallback', function () {
    // file is ABSPATH-relative (as stored in the fatal ring) while plugins_dir is absolute —
    // the fallback regex must still find the culprit.
    assert_eq('bad-plugin', wpultra_autorecover_culprit_from_path(
        'wp-content/plugins/bad-plugin/includes/x.php',
        '/var/www/html/wp-content/plugins'
    ));
});

it('culprit_from_path handles Windows separators and trailing slash on plugins_dir', function () {
    assert_eq('bad-plugin', wpultra_autorecover_culprit_from_path(
        'C:\\wp\\wp-content\\plugins\\bad-plugin\\includes\\x.php',
        'C:\\wp\\wp-content\\plugins\\'
    ));
});

it('culprit_from_path is case-insensitive on the plugins_dir prefix but preserves slug case', function () {
    assert_eq('Bad-Plugin', wpultra_autorecover_culprit_from_path(
        'C:/WP/WP-CONTENT/PLUGINS/Bad-Plugin/x.php',
        'c:/wp/wp-content/plugins'
    ));
});

it('culprit_from_path returns a single-file plugin\'s own filename with no subfolder as the slug', function () {
    assert_eq('hello.php', wpultra_autorecover_culprit_from_path(
        '/var/www/html/wp-content/plugins/hello.php',
        '/var/www/html/wp-content/plugins'
    ));
});

it('culprit_from_path returns null for a mu-plugins path (not deactivatable via active_plugins)', function () {
    assert_eq(null, wpultra_autorecover_culprit_from_path(
        '/var/www/html/wp-content/mu-plugins/foo/bar.php',
        '/var/www/html/wp-content/plugins'
    ));
});

it('culprit_from_path returns null for a theme path', function () {
    assert_eq(null, wpultra_autorecover_culprit_from_path(
        '/var/www/html/wp-content/themes/mytheme/functions.php',
        '/var/www/html/wp-content/plugins'
    ));
});

it('culprit_from_path returns null when the culprit is wp-ultra-mcp itself', function () {
    assert_eq(null, wpultra_autorecover_culprit_from_path(
        '/var/www/html/wp-content/plugins/wp-ultra-mcp/includes/system/engine.php',
        '/var/www/html/wp-content/plugins'
    ));
});

it('culprit_from_path is case-insensitive when detecting self (WP-ULTRA-MCP)', function () {
    assert_eq(null, wpultra_autorecover_culprit_from_path(
        '/var/www/html/wp-content/plugins/WP-ULTRA-MCP/includes/x.php',
        '/var/www/html/wp-content/plugins'
    ));
});

it('culprit_from_path returns null for an empty file path', function () {
    assert_eq(null, wpultra_autorecover_culprit_from_path('', '/var/www/html/wp-content/plugins'));
});

it('culprit_from_path returns null when the file is entirely unrelated (core file)', function () {
    assert_eq(null, wpultra_autorecover_culprit_from_path(
        '/var/www/html/wp-includes/functions.php',
        '/var/www/html/wp-content/plugins'
    ));
});

/* ------------------------------------------------------------------ *
 * wpultra_autorecover_find_active_by_slug
 * ------------------------------------------------------------------ */

it('find_active_by_slug matches on the folder segment', function () {
    assert_eq('bad-plugin/bad-plugin.php', wpultra_autorecover_find_active_by_slug('bad-plugin', ['other/other.php', 'bad-plugin/bad-plugin.php']));
});

it('find_active_by_slug is case-insensitive', function () {
    assert_eq('Bad-Plugin/bad-plugin.php', wpultra_autorecover_find_active_by_slug('bad-plugin', ['Bad-Plugin/bad-plugin.php']));
});

it('find_active_by_slug returns null when the slug is not active', function () {
    assert_eq(null, wpultra_autorecover_find_active_by_slug('inactive-plugin', ['other/other.php']));
});

/* ------------------------------------------------------------------ *
 * wpultra_autorecover_plan
 * ------------------------------------------------------------------ */

it('plan: deactivate-plugin strategy plans to drop the diagnosed active culprit', function () {
    $fatal = ['file' => 'wp-content/plugins/bad-plugin/includes/x.php'];
    $active = ['wp-ultra-mcp/wp-ultra-mcp.php', 'bad-plugin/bad-plugin.php'];
    $plan = wpultra_autorecover_plan($fatal, $active, 'wp-ultra-mcp/wp-ultra-mcp.php', 'deactivate-plugin');
    assert_eq('deactivate-plugin', $plan['action']);
    assert_eq('bad-plugin/bad-plugin.php', $plan['plugin']);
});

it('plan: deactivate-plugin with an unknown culprit path is a no-op culprit-unknown', function () {
    $fatal = ['file' => 'wp-content/themes/mytheme/functions.php'];
    $active = ['wp-ultra-mcp/wp-ultra-mcp.php'];
    $plan = wpultra_autorecover_plan($fatal, $active, 'wp-ultra-mcp/wp-ultra-mcp.php', 'deactivate-plugin');
    assert_eq('no-op', $plan['action']);
    assert_eq('culprit-unknown', $plan['reason']);
    assert_eq(null, $plan['plugin']);
});

it('plan: diagnosed culprit that is not currently active is a no-op culprit-not-active', function () {
    $fatal = ['file' => 'wp-content/plugins/dormant-plugin/x.php'];
    $active = ['wp-ultra-mcp/wp-ultra-mcp.php']; // dormant-plugin not in the active list
    $plan = wpultra_autorecover_plan($fatal, $active, 'wp-ultra-mcp/wp-ultra-mcp.php', 'deactivate-plugin');
    assert_eq('no-op', $plan['action']);
    assert_eq('culprit-not-active', $plan['reason']);
    assert_eq('dormant-plugin', $plan['plugin']);
});

it('plan: never selects self, even if the diagnosed folder equals a renamed self basename', function () {
    // self plugin folder is unusually named (e.g. re-installed under a suffixed folder);
    // the dynamic self_basename check must still catch it even though the path-parser's
    // hardcoded "wp-ultra-mcp" check would not.
    $fatal = ['file' => 'wp-content/plugins/wp-ultra-mcp-2/includes/x.php'];
    $active = ['wp-ultra-mcp-2/wp-ultra-mcp-2.php'];
    $plan = wpultra_autorecover_plan($fatal, $active, 'wp-ultra-mcp-2/wp-ultra-mcp-2.php', 'deactivate-plugin');
    assert_eq('no-op', $plan['action']);
    assert_eq('culprit-unknown', $plan['reason']);
});

it('plan: undo-last strategy always plans undo-last regardless of the fatal', function () {
    $plan = wpultra_autorecover_plan([], [], 'wp-ultra-mcp/wp-ultra-mcp.php', 'undo-last');
    assert_eq('undo-last', $plan['action']);
    assert_eq(null, $plan['plugin']);
});

it('plan: auto strategy prefers deactivate-plugin when a culprit is clearly implicated and active', function () {
    $fatal = ['file' => 'wp-content/plugins/bad-plugin/includes/x.php'];
    $active = ['wp-ultra-mcp/wp-ultra-mcp.php', 'bad-plugin/bad-plugin.php'];
    $plan = wpultra_autorecover_plan($fatal, $active, 'wp-ultra-mcp/wp-ultra-mcp.php', 'auto');
    assert_eq('deactivate-plugin', $plan['action']);
    assert_eq('bad-plugin/bad-plugin.php', $plan['plugin']);
});

it('plan: auto strategy falls back to undo-last when no culprit is implicated', function () {
    $fatal = ['file' => 'wp-content/themes/mytheme/functions.php'];
    $plan = wpultra_autorecover_plan($fatal, ['wp-ultra-mcp/wp-ultra-mcp.php'], 'wp-ultra-mcp/wp-ultra-mcp.php', 'auto');
    assert_eq('undo-last', $plan['action']);
});

it('plan: auto strategy falls back to undo-last when the culprit is diagnosed but not active', function () {
    $fatal = ['file' => 'wp-content/plugins/dormant-plugin/x.php'];
    $plan = wpultra_autorecover_plan($fatal, ['wp-ultra-mcp/wp-ultra-mcp.php'], 'wp-ultra-mcp/wp-ultra-mcp.php', 'auto');
    assert_eq('undo-last', $plan['action']);
});

it('plan: unknown strategy is a safe no-op', function () {
    $plan = wpultra_autorecover_plan([], [], 'wp-ultra-mcp/wp-ultra-mcp.php', 'nonsense');
    assert_eq('no-op', $plan['action']);
    assert_eq('unknown-strategy', $plan['reason']);
});

it('plan: missing file key on the fatal (malicious/invalid input) is treated as no culprit', function () {
    $plan = wpultra_autorecover_plan(['message' => 'boom'], ['wp-ultra-mcp/wp-ultra-mcp.php'], 'wp-ultra-mcp/wp-ultra-mcp.php', 'deactivate-plugin');
    assert_eq('no-op', $plan['action']);
    assert_eq('culprit-unknown', $plan['reason']);
});

it('plan diagnoses the SAME culprit as status when the plugins-dir prefix is non-default (BF2.1 status/recover parity)', function () {
    // Stand-in for a customized WP_PLUGIN_DIR whose ABSPATH-relative path is NOT the
    // conventional 'wp-content/plugins' (and doesn't even contain that substring, so the
    // culprit_from_path() generic regex fallback can't rescue a caller that ignores it).
    $custom_prefix = 'content/mu-plugins-custom/plugins';
    $fatal_file    = $custom_prefix . '/bad-plugin/includes/x.php';
    $active        = ['wp-ultra-mcp/wp-ultra-mcp.php', 'bad-plugin/bad-plugin.php'];

    // This is what wpultra_autorecover_status() does: resolve the culprit via the derived
    // (custom) prefix.
    $status_culprit = wpultra_autorecover_culprit_from_path($fatal_file, $custom_prefix);
    assert_eq('bad-plugin', $status_culprit);

    // recover()/plan() must resolve the identical prefix (passed in as the 5th arg, mirroring
    // what wpultra_autorecover_recover() now passes from wpultra_autorecover_plugins_dir_rel())
    // rather than a hardcoded 'wp-content/plugins' — otherwise it would diagnose no culprit
    // at all (culprit-unknown) while status already advised 'bad-plugin', and the two paths
    // would disagree.
    $fatal = ['file' => $fatal_file];
    $plan  = wpultra_autorecover_plan($fatal, $active, 'wp-ultra-mcp/wp-ultra-mcp.php', 'deactivate-plugin', $custom_prefix);
    assert_eq('deactivate-plugin', $plan['action']);
    assert_eq('bad-plugin/bad-plugin.php', $plan['plugin']);
    assert_eq($status_culprit, wpultra_autorecover_culprit_from_path($fatal_file, $custom_prefix));

    // Regression guard: proves the bug this fix addresses — if plan() fell back to the
    // hardcoded default prefix instead of the caller-supplied custom one, it would fail to
    // find the culprit under this custom plugins dir (no 'wp-content/plugins' substring
    // anywhere in the path), disagreeing with status's diagnosis above.
    $stale_plan = wpultra_autorecover_plan($fatal, $active, 'wp-ultra-mcp/wp-ultra-mcp.php', 'deactivate-plugin', 'wp-content/plugins');
    assert_eq('no-op', $stale_plan['action']);
    assert_eq('culprit-unknown', $stale_plan['reason']);
});

/* ------------------------------------------------------------------ *
 * wpultra_autorecover_silent_deactivate
 * ------------------------------------------------------------------ */

it('silent_deactivate refuses to drop the self plugin even if explicitly asked (self-check runs before any option write)', function () {
    $active = ['wp-ultra-mcp/wp-ultra-mcp.php', 'bad-plugin/bad-plugin.php'];
    $ok = wpultra_autorecover_silent_deactivate('wp-ultra-mcp/wp-ultra-mcp.php', $active, 'wp-ultra-mcp/wp-ultra-mcp.php');
    assert_true($ok === false, 'must refuse to deactivate self');
});

it('silent_deactivate self-check is folder-based, tolerant of a different main-file name', function () {
    $active = ['wp-ultra-mcp/wp-ultra-mcp.php'];
    $ok = wpultra_autorecover_silent_deactivate('wp-ultra-mcp/legacy-bootstrap.php', $active, 'wp-ultra-mcp/wp-ultra-mcp.php');
    assert_true($ok === false, 'must refuse based on folder match even if the file name differs');
});

run_tests();
