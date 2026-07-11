<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/jserrors.php';

/* ------------------------------------------------------------------ *
 * wpultra_jserrors_sanitize_payload
 * ------------------------------------------------------------------ */

$identity = static fn(string $s): string => $s;

it('sanitize_payload: caps message to 500 chars', function () use ($identity) {
    $raw = ['message' => str_repeat('x', 600)];
    $out = wpultra_jserrors_sanitize_payload($raw, $identity, $identity);
    assert_eq(500, strlen($out['message']));
});

it('sanitize_payload: caps stack to 2000 chars', function () use ($identity) {
    $raw = ['stack' => str_repeat('y', 3000)];
    $out = wpultra_jserrors_sanitize_payload($raw, $identity, $identity);
    assert_eq(2000, strlen($out['stack']));
});

it('sanitize_payload: caps url to 500 chars', function () use ($identity) {
    $raw = ['url' => 'https://example.com/' . str_repeat('z', 600)];
    $out = wpultra_jserrors_sanitize_payload($raw, $identity, $identity);
    assert_eq(500, strlen($out['url']));
});

it('sanitize_payload: caps source/ua to 300 chars', function () use ($identity) {
    $raw = ['source' => str_repeat('s', 400), 'ua' => str_repeat('u', 400)];
    $out = wpultra_jserrors_sanitize_payload($raw, $identity, $identity);
    assert_eq(300, strlen($out['source']));
    assert_eq(300, strlen($out['ua']));
});

it('sanitize_payload: missing fields default to empty string / zero', function () use ($identity) {
    $out = wpultra_jserrors_sanitize_payload([], $identity, $identity);
    assert_eq('', $out['message']);
    assert_eq('', $out['source']);
    assert_eq('', $out['stack']);
    assert_eq('', $out['url']);
    assert_eq('', $out['ua']);
    assert_eq(0, $out['lineno']);
    assert_eq(0, $out['colno']);
});

it('sanitize_payload: lineno/colno coerced to non-negative ints', function () use ($identity) {
    $out = wpultra_jserrors_sanitize_payload(['lineno' => '42', 'colno' => -7], $identity, $identity);
    assert_eq(42, $out['lineno']);
    assert_eq(0, $out['colno']); // negative clamped to 0
});

it('sanitize_payload: non-numeric lineno/colno default to 0 (malicious input)', function () use ($identity) {
    $out = wpultra_jserrors_sanitize_payload(['lineno' => 'DROP TABLE', 'colno' => ['a' => 'b']], $identity, $identity);
    assert_eq(0, $out['lineno']);
    assert_eq(0, $out['colno']);
});

it('sanitize_payload: applies the given sanitizer callables', function () {
    $upper = static fn(string $s): string => strtoupper($s);
    $prefix = static fn(string $s): string => 'URL:' . $s;
    $out = wpultra_jserrors_sanitize_payload(['message' => 'boom', 'url' => 'http://x'], $upper, $prefix);
    assert_eq('BOOM', $out['message']);
    assert_eq('URL:http://x', $out['url']);
});

it('sanitize_payload: non-string raw values are coerced safely', function () use ($identity) {
    $out = wpultra_jserrors_sanitize_payload(['message' => 123, 'source' => null], $identity, $identity);
    assert_eq('123', $out['message']);
    assert_eq('', $out['source']);
});

/* ------------------------------------------------------------------ *
 * wpultra_jserrors_dedupe_key
 * ------------------------------------------------------------------ */

it('dedupe_key: identical message/source/lineno/colno produce the same key', function () {
    $a = ['message' => 'boom', 'source' => 'app.js', 'lineno' => 10, 'colno' => 5];
    $b = ['message' => 'boom', 'source' => 'app.js', 'lineno' => 10, 'colno' => 5];
    assert_eq(wpultra_jserrors_dedupe_key($a), wpultra_jserrors_dedupe_key($b));
});

it('dedupe_key: differing lineno changes the key', function () {
    $a = ['message' => 'boom', 'source' => 'app.js', 'lineno' => 10, 'colno' => 5];
    $b = ['message' => 'boom', 'source' => 'app.js', 'lineno' => 11, 'colno' => 5];
    assert_true(wpultra_jserrors_dedupe_key($a) !== wpultra_jserrors_dedupe_key($b));
});

it('dedupe_key: differing message changes the key', function () {
    $a = ['message' => 'boom', 'source' => 'app.js', 'lineno' => 10, 'colno' => 5];
    $b = ['message' => 'bang', 'source' => 'app.js', 'lineno' => 10, 'colno' => 5];
    assert_true(wpultra_jserrors_dedupe_key($a) !== wpultra_jserrors_dedupe_key($b));
});

it('dedupe_key: ignores extra fields like ts/ip/stack', function () {
    $a = ['message' => 'boom', 'source' => 'app.js', 'lineno' => 10, 'colno' => 5];
    $b = ['message' => 'boom', 'source' => 'app.js', 'lineno' => 10, 'colno' => 5, 'ts' => 999, 'ip' => 'abc', 'stack' => 'whatever'];
    assert_eq(wpultra_jserrors_dedupe_key($a), wpultra_jserrors_dedupe_key($b));
});

it('dedupe_key: missing fields default consistently (stable, no notices)', function () {
    $key = wpultra_jserrors_dedupe_key([]);
    assert_eq(32, strlen($key));
    assert_eq($key, wpultra_jserrors_dedupe_key([]));
});

/* ------------------------------------------------------------------ *
 * wpultra_jserrors_ring_push
 * ------------------------------------------------------------------ */

it('ring_push: prepends newest-first', function () {
    $ring = [];
    $ring = wpultra_jserrors_ring_push($ring, ['ts' => 1], 10);
    $ring = wpultra_jserrors_ring_push($ring, ['ts' => 2], 10);
    assert_eq(2, $ring[0]['ts']);
    assert_eq(1, $ring[1]['ts']);
});

it('ring_push: evicts oldest entries once over cap', function () {
    $ring = [];
    for ($i = 1; $i <= 5; $i++) {
        $ring = wpultra_jserrors_ring_push($ring, ['ts' => $i], 3);
    }
    assert_eq(3, count($ring));
    assert_eq(5, $ring[0]['ts']);
    assert_eq(3, $ring[2]['ts']);
});

it('ring_push: default cap is WPULTRA_JSERRORS_CAP (50)', function () {
    $ring = [];
    for ($i = 1; $i <= 55; $i++) {
        $ring = wpultra_jserrors_ring_push($ring, ['ts' => $i]);
    }
    assert_eq(50, count($ring));
    assert_eq(55, $ring[0]['ts']);
});

it('ring_push: cap 0 means unbounded', function () {
    $ring = [];
    for ($i = 1; $i <= 5; $i++) {
        $ring = wpultra_jserrors_ring_push($ring, ['ts' => $i], 0);
    }
    assert_eq(5, count($ring));
});

/* ------------------------------------------------------------------ *
 * wpultra_jserrors_hash_ip / make_entry / is_recent_dupe (small pure helpers)
 * ------------------------------------------------------------------ */

it('hash_ip: empty ip returns empty string', function () {
    assert_eq('', wpultra_jserrors_hash_ip(''));
});

it('hash_ip: non-empty ip returns a stable 12-char fingerprint, never the raw ip', function () {
    $h = wpultra_jserrors_hash_ip('203.0.113.7');
    assert_eq(12, strlen($h));
    assert_true(!str_contains($h, '203.0.113.7'));
    assert_eq($h, wpultra_jserrors_hash_ip('203.0.113.7'));
});

it('make_entry: attaches ts, ip, and a dedupe key derived from the sanitized payload', function () {
    $sanitized = ['message' => 'boom', 'source' => 'app.js', 'lineno' => 1, 'colno' => 2, 'stack' => '', 'url' => '', 'ua' => ''];
    $entry = wpultra_jserrors_make_entry(1000, $sanitized, 'abcd1234ef56');
    assert_eq(1000, $entry['ts']);
    assert_eq('abcd1234ef56', $entry['ip']);
    assert_eq(wpultra_jserrors_dedupe_key($sanitized), $entry['key']);
    assert_eq('boom', $entry['message']);
});

it('is_recent_dupe: same key within window is a dupe', function () {
    $ring = [['key' => 'k1', 'ts' => 1000]];
    assert_true(wpultra_jserrors_is_recent_dupe($ring, 'k1', 1020, 30));
});

it('is_recent_dupe: same key past window is not a dupe', function () {
    $ring = [['key' => 'k1', 'ts' => 1000]];
    assert_true(!wpultra_jserrors_is_recent_dupe($ring, 'k1', 1200, 30));
});

it('is_recent_dupe: different key is never a dupe', function () {
    $ring = [['key' => 'k1', 'ts' => 1000]];
    assert_true(!wpultra_jserrors_is_recent_dupe($ring, 'k2', 1005, 30));
});

it('is_recent_dupe: empty ring is never a dupe', function () {
    assert_true(!wpultra_jserrors_is_recent_dupe([], 'k1', 1000, 30));
});

/* ------------------------------------------------------------------ *
 * store wrappers outside WordPress (get_option/update_option undefined) —
 * must no-op safely, never fatal.
 * ------------------------------------------------------------------ */

it('load_ring: returns [] when get_option is unavailable', function () {
    assert_eq([], wpultra_jserrors_load_ring());
});

it('is_enabled: returns false when get_option is unavailable', function () {
    assert_true(!wpultra_jserrors_is_enabled());
});

it('save_ring/set_enabled/clear: no-op without fataling when WP functions are unavailable', function () {
    wpultra_jserrors_save_ring([['ts' => 1]]);
    wpultra_jserrors_set_enabled(true);
    wpultra_jserrors_clear();
    assert_true(true); // reaching here means no fatal was thrown
});

it('read: filters by limit and defaults to the full (empty) ring outside WordPress', function () {
    assert_eq([], wpultra_jserrors_read(['limit' => 5]));
});

it('within_limit: true when transient functions are unavailable (fail open)', function () {
    assert_true(wpultra_jserrors_within_limit());
});

it('register_routes: no-op without fataling when register_rest_route is unavailable', function () {
    wpultra_jserrors_register_routes();
    assert_true(true);
});

it('enqueue: no-op without fataling when wp_enqueue_script is unavailable', function () {
    wpultra_jserrors_enqueue();
    assert_true(true);
});

it('boot: registers hooks via add_action without fataling', function () {
    wpultra_jserrors_boot();
    assert_true(true);
});

/* ------------------------------------------------------------------ *
 * snippet sanity
 * ------------------------------------------------------------------ */

it('snippet: references sendBeacon and both listeners, stays compact', function () {
    $js = wpultra_jserrors_snippet();
    assert_contains('sendBeacon', $js);
    assert_contains("addEventListener('error'", $js);
    assert_contains("addEventListener('unhandledrejection'", $js);
    assert_true(strlen($js) < 1200, 'snippet should stay small (< 1200 chars unminified)');
});

it('snippet: reads the endpoint from window.wpultraJsErrEndpoint instead of a hardcoded path', function () {
    $js = wpultra_jserrors_snippet();
    assert_contains('window.wpultraJsErrEndpoint', $js);
    assert_true(!str_contains($js, "sendBeacon('/wp-json"), 'the beacon path must not be hardcoded (breaks under Plain permalinks / subdirectory installs)');
});

/* ------------------------------------------------------------------ *
 * wpultra_jserrors_endpoint_snippet — injects the real REST URL so the
 * hardcoded '/wp-json/...' path (which 404s under Plain permalinks or a
 * subdirectory install) is never relied on.
 * ------------------------------------------------------------------ */

it('endpoint_snippet: defines window.wpultraJsErrEndpoint', function () {
    $js = wpultra_jserrors_endpoint_snippet();
    assert_contains('window.wpultraJsErrEndpoint=', $js);
});

it('endpoint_snippet: falls back to the literal REST path outside WordPress (no rest_url available)', function () {
    $js = wpultra_jserrors_endpoint_snippet();
    // json_encode() escapes '/' to '\/' by default, so match on the unescaped form.
    assert_contains('wp-json\/wpultra\/v1\/jserror', $js);
});

run_tests();
