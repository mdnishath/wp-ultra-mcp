<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/errors.php';

/* ------------------------------------------------------------------ *
 * wpultra_errors_trim_path
 * ------------------------------------------------------------------ */

it('trim_path strips ABSPATH prefix (forward slashes)', function () {
    assert_eq('wp-content/plugins/foo/foo.php', wpultra_errors_trim_path('/var/www/html/wp-content/plugins/foo/foo.php', '/var/www/html'));
});

it('trim_path strips ABSPATH prefix (windows backslashes)', function () {
    assert_eq('wp-content/wpultra-widgets/bar/bar.php', wpultra_errors_trim_path('C:\\wp\\wp-content\\wpultra-widgets\\bar\\bar.php', 'C:\\wp'));
});

it('trim_path returns normalized path unchanged when not under abspath', function () {
    assert_eq('/elsewhere/file.php', wpultra_errors_trim_path('/elsewhere/file.php', '/var/www/html'));
});

it('trim_path handles exact abspath match', function () {
    assert_eq('', wpultra_errors_trim_path('/var/www/html', '/var/www/html'));
});

/* ------------------------------------------------------------------ *
 * wpultra_errors_suggest — the suggestion matrix
 * ------------------------------------------------------------------ */

it('suggest: recent undo snapshot surfaces undo-restore', function () {
    $now = 2_000_000_000;
    $undo = [['id' => 7, 'created' => gmdate('Y-m-d H:i:s', $now - 60)]]; // 60s ago
    $s = wpultra_errors_suggest(['message' => 'x', 'file' => 'wp-content/themes/x/functions.php'], $undo, $now);
    assert_true(in_array('undo-restore id 7 may revert the change that broke this', $s, true), 'expected undo-restore suggestion');
});

it('suggest: undo snapshot older than the recent window is not surfaced', function () {
    $now = 2_000_000_000;
    $undo = [['id' => 7, 'created' => gmdate('Y-m-d H:i:s', $now - 400)]]; // 400s ago > 300s window
    $s = wpultra_errors_suggest(['message' => 'x', 'file' => 'wp-content/themes/x/functions.php'], $undo, $now);
    foreach ($s as $line) {
        assert_true(!str_contains($line, 'undo-restore'), 'stale undo snapshot must not be suggested');
    }
});

it('suggest: widget path suggests quarantine + regen', function () {
    $s = wpultra_errors_suggest(['message' => 'x', 'file' => 'wp-content/wpultra-widgets/my-widget/my-widget.php'], [], time());
    assert_true(in_array('widget quarantine will auto-skip it; regenerate via create-atomic-widget', $s, true), 'expected widget suggestion');
});

it('suggest: plugin path suggests deactivating that plugin', function () {
    $s = wpultra_errors_suggest(['message' => 'x', 'file' => 'wp-content/plugins/broken-plugin/broken-plugin.php'], [], time());
    assert_true(in_array('deactivate plugin broken-plugin via manage-plugin-theme', $s, true), 'expected plugin suggestion');
});

it('suggest: generic fallback when nothing else matches', function () {
    $s = wpultra_errors_suggest(['message' => 'x', 'file' => 'wp-content/themes/twentytwentyfive/functions.php'], [], time());
    assert_eq(1, count($s));
    assert_true(str_contains($s[0], 'review the error'), 'expected generic suggestion');
});

it('suggest: widget path takes priority alongside a stale undo (undo omitted, widget included)', function () {
    $now = 2_000_000_000;
    $undo = [['id' => 3, 'created' => gmdate('Y-m-d H:i:s', $now - 1000)]];
    $s = wpultra_errors_suggest(['message' => 'x', 'file' => 'wp-content/wpultra-widgets/w/w.php'], $undo, $now);
    assert_eq(['widget quarantine will auto-skip it; regenerate via create-atomic-widget'], $s);
});

it('suggest: recent undo + plugin path both appear', function () {
    $now = 2_000_000_000;
    $undo = [['id' => 9, 'created' => gmdate('Y-m-d H:i:s', $now - 10)]];
    $s = wpultra_errors_suggest(['message' => 'x', 'file' => 'wp-content/plugins/acme/acme.php'], $undo, $now);
    assert_eq(2, count($s));
    assert_true(in_array('undo-restore id 9 may revert the change that broke this', $s, true), 'expected undo suggestion present');
    assert_true(in_array('deactivate plugin acme via manage-plugin-theme', $s, true), 'expected plugin suggestion present');
});

/* ------------------------------------------------------------------ *
 * wpultra_errors_is_dupe
 * ------------------------------------------------------------------ */

it('is_dupe: same message+file within the window is a dupe', function () {
    $ring = [['message' => 'boom', 'file' => 'a.php', 'ts' => 1000]];
    $entry = ['message' => 'boom', 'file' => 'a.php', 'ts' => 1030];
    assert_true(wpultra_errors_is_dupe($ring, $entry, 60), 'expected dupe within 60s window');
});

it('is_dupe: same message+file outside the window is not a dupe', function () {
    $ring = [['message' => 'boom', 'file' => 'a.php', 'ts' => 1000]];
    $entry = ['message' => 'boom', 'file' => 'a.php', 'ts' => 1200];
    assert_true(!wpultra_errors_is_dupe($ring, $entry, 60), 'expected no dupe past window');
});

it('is_dupe: different message is not a dupe', function () {
    $ring = [['message' => 'boom', 'file' => 'a.php', 'ts' => 1000]];
    $entry = ['message' => 'bang', 'file' => 'a.php', 'ts' => 1010];
    assert_true(!wpultra_errors_is_dupe($ring, $entry, 60), 'different message must not dupe');
});

it('is_dupe: different file is not a dupe', function () {
    $ring = [['message' => 'boom', 'file' => 'a.php', 'ts' => 1000]];
    $entry = ['message' => 'boom', 'file' => 'b.php', 'ts' => 1010];
    assert_true(!wpultra_errors_is_dupe($ring, $entry, 60), 'different file must not dupe');
});

it('is_dupe: empty ring is never a dupe', function () {
    assert_true(!wpultra_errors_is_dupe([], ['message' => 'boom', 'file' => 'a.php', 'ts' => 1000], 60));
});

/* ------------------------------------------------------------------ *
 * ring push / entry shape
 * ------------------------------------------------------------------ */

it('ring_push prepends newest-first and caps the length', function () {
    $ring = [];
    for ($i = 1; $i <= 5; $i++) {
        $ring = wpultra_errors_ring_push($ring, ['ts' => $i], 3);
    }
    assert_eq(3, count($ring));
    assert_eq(5, $ring[0]['ts']);
    assert_eq(3, $ring[2]['ts']);
});

it('make_entry truncates message to 500 and url to 200 chars', function () {
    $long_msg = str_repeat('x', 600);
    $long_url = str_repeat('y', 300);
    $e = wpultra_errors_make_entry(123, $long_msg, 'f.php', 10, $long_url, ['s']);
    assert_eq(500, strlen($e['message']));
    assert_eq(200, strlen($e['url']));
    assert_eq(123, $e['ts']);
    assert_eq('f.php', $e['file']);
    assert_eq(10, $e['line']);
    assert_eq(['s'], $e['suggestions']);
});

run_tests();
