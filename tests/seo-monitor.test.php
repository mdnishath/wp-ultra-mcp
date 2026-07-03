<?php
require_once __DIR__ . '/harness.php';

// ---- ABSPATH: wpultra_indexnow_key() writes a verification file at ABSPATH.<key>.txt ----
$__tmp = sys_get_temp_dir() . '/wpultra_monitor_' . uniqid();
mkdir($__tmp, 0777, true);
if (!defined('ABSPATH')) { define('ABSPATH', $__tmp . '/'); }

// ---- Extra stubs (option store + URL/parse helpers used by monitor.php) ----
$GLOBALS['__opts'] = [];
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $autoload = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('home_url')) { function home_url($p = '') { return 'http://example.com' . $p; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return $u; } }
if (!function_exists('current_time')) { function current_time($type, $gmt = 0) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('wpultra_err')) { function wpultra_err($code, $msg, $data = '') { return new WP_Error($code, $msg, $data); } }
if (!function_exists('wpultra_audit_log')) { function wpultra_audit_log($a, $s, $ok = true) {} }

require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/monitor.php';

// ---- IndexNow URL validator ----

it('validate_urls accepts same-host http(s) urls', function () {
    [$valid, $rejected] = wpultra_indexnow_validate_urls([
        'https://example.com/a/',
        'http://example.com/b/',
    ], 'example.com');
    assert_eq(2, count($valid));
    assert_eq(0, count($rejected));
});

it('validate_urls rejects cross-host urls', function () {
    [$valid, $rejected] = wpultra_indexnow_validate_urls([
        'https://example.com/a/',
        'https://evil.com/b/',
    ], 'example.com');
    assert_eq(1, count($valid));
    assert_eq(1, count($rejected));
    assert_eq('https://evil.com/b/', $rejected[0]);
});

it('validate_urls rejects junk (no scheme, malformed, empty)', function () {
    [$valid, $rejected] = wpultra_indexnow_validate_urls([
        'not-a-url',
        'ftp://example.com/x',
        '',
        '   ',
    ], 'example.com');
    assert_eq(0, count($valid));
    // empty/whitespace-only entries are skipped outright, not counted as rejected
    assert_eq(2, count($rejected));
});

it('validate_urls caps at 100 and rejects the overflow', function () {
    $urls = [];
    for ($i = 0; $i < 105; $i++) { $urls[] = "https://example.com/p$i/"; }
    [$valid, $rejected] = wpultra_indexnow_validate_urls($urls, 'example.com');
    assert_eq(100, count($valid));
    assert_eq(5, count($rejected));
});

it('validate_urls de-dupes without counting duplicates as rejected', function () {
    [$valid, $rejected] = wpultra_indexnow_validate_urls([
        'https://example.com/a/',
        'https://example.com/a/',
    ], 'example.com');
    assert_eq(1, count($valid));
    assert_eq(0, count($rejected));
});

// ---- 404 should_log matrix ----

it('should_log skips common static asset extensions', function () {
    foreach (['/style.css', '/app.js', '/logo.png', '/photo.jpg', '/font.woff2', '/icon.svg'] as $p) {
        assert_eq(false, wpultra_404_should_log($p), "expected asset skipped: $p");
    }
});

it('should_log skips favicon/apple-touch-icon and source maps/backups', function () {
    foreach (['/favicon.ico', '/apple-touch-icon.png', '/apple-touch-icon-precomposed.png', '/app.js.map', '/index.php~', '/config.php.bak'] as $p) {
        assert_eq(false, wpultra_404_should_log($p), "expected noise skipped: $p");
    }
});

it('should_log skips wp-content asset subpaths', function () {
    assert_eq(false, wpultra_404_should_log('/wp-content/uploads/2024/01/missing-file.pdf'));
});

it('should_log logs real page paths', function () {
    foreach (['/old-blog-post/', '/products/widget', '/about-us'] as $p) {
        assert_eq(true, wpultra_404_should_log($p), "expected page logged: $p");
    }
});

it('should_log rejects empty path', function () {
    assert_eq(false, wpultra_404_should_log(''));
});

// ---- 404 grouping/sort ----

it('top groups by path and counts hits', function () {
    $ring = [
        ['path' => '/a/', 'referer' => '', 'ts' => '2026-01-01 00:00:00'],
        ['path' => '/a/', 'referer' => '', 'ts' => '2026-01-02 00:00:00'],
        ['path' => '/b/', 'referer' => '', 'ts' => '2026-01-01 00:00:00'],
    ];
    $top = wpultra_404_top($ring);
    assert_eq(2, count($top));
    assert_eq('/a/', $top[0]['path']);
    assert_eq(2, $top[0]['hits']);
    assert_eq('2026-01-02 00:00:00', $top[0]['last']);
    assert_eq('/b/', $top[1]['path']);
    assert_eq(1, $top[1]['hits']);
});

it('top sorts by hits desc', function () {
    $ring = [
        ['path' => '/rare/', 'ts' => '2026-01-01 00:00:00'],
        ['path' => '/common/', 'ts' => '2026-01-01 00:00:00'],
        ['path' => '/common/', 'ts' => '2026-01-02 00:00:00'],
        ['path' => '/common/', 'ts' => '2026-01-03 00:00:00'],
    ];
    $top = wpultra_404_top($ring);
    assert_eq('/common/', $top[0]['path']);
    assert_eq(3, $top[0]['hits']);
    assert_eq('/rare/', $top[1]['path']);
});

it('top ignores entries with empty path', function () {
    $top = wpultra_404_top([['path' => '', 'ts' => '2026-01-01 00:00:00']]);
    assert_eq(0, count($top));
});

// ---- IndexNow key generation ----

it('indexnow_key generates and persists a 32-hex key', function () {
    $GLOBALS['__opts'] = [];
    $key = wpultra_indexnow_key();
    assert_true((bool) preg_match('/^[a-f0-9]{32}$/', $key), 'key format');
    assert_eq($key, $GLOBALS['__opts']['wpultra_indexnow_key']);
    // Calling again returns the same persisted key.
    assert_eq($key, wpultra_indexnow_key());
});

run_tests();
