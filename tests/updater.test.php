<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('WPULTRA_VERSION')) { define('WPULTRA_VERSION', '0.14.0'); }
if (!function_exists('plugin_basename')) { function plugin_basename($f) { return 'wp-ultra-mcp/wp-ultra-mcp.php'; } }
require __DIR__ . '/../wp-ultra-mcp/includes/system/updater.php';

function updater_fixture(array $over = []): array {
    return array_merge([
        'tag_name'     => 'v0.14.0',
        'html_url'     => 'https://github.com/mdnishath/wp-ultra-mcp/releases/tag/v0.14.0',
        'published_at' => '2026-07-02T12:00:00Z',
        'assets'       => [
            ['name' => 'other.txt', 'browser_download_url' => 'https://github.com/x/other.txt'],
            ['name' => 'wp-ultra-mcp.zip', 'browser_download_url' => 'https://github.com/mdnishath/wp-ultra-mcp/releases/download/v0.14.0/wp-ultra-mcp.zip'],
        ],
    ], $over);
}

it('parse_release extracts version + the wp-ultra-mcp.zip asset', function () {
    $r = wpultra_updater_parse_release(updater_fixture());
    assert_eq('0.14.0', $r['version']);
    assert_contains('/download/v0.14.0/wp-ultra-mcp.zip', $r['zip_url']);
    assert_contains('/releases/tag/v0.14.0', $r['notes_url']);
});

it('parse_release strips the v prefix and tolerates a bare tag', function () {
    $r = wpultra_updater_parse_release(updater_fixture(['tag_name' => '1.2.3']));
    assert_eq('1.2.3', $r['version']);
});

it('parse_release returns null without a usable tag or zip asset', function () {
    assert_eq(null, wpultra_updater_parse_release(updater_fixture(['tag_name' => ''])));
    assert_eq(null, wpultra_updater_parse_release(updater_fixture(['tag_name' => 'latest'])));
    assert_eq(null, wpultra_updater_parse_release(updater_fixture(['assets' => []])));
    assert_eq(null, wpultra_updater_parse_release(null));
    assert_eq(null, wpultra_updater_parse_release(['message' => 'Not Found']));
});

it('parse_release rejects a zip asset hosted off github.com', function () {
    $fx = updater_fixture();
    $fx['assets'] = [['name' => 'wp-ultra-mcp.zip', 'browser_download_url' => 'https://evil.example/wp-ultra-mcp.zip']];
    assert_eq(null, wpultra_updater_parse_release($fx));
});

it('is_newer compares semantic versions', function () {
    assert_true(wpultra_updater_is_newer('0.13.0', '0.14.0'));
    assert_true(!wpultra_updater_is_newer('0.14.0', '0.14.0'));
    assert_true(!wpultra_updater_is_newer('0.14.1', '0.14.0'));
    assert_true(wpultra_updater_is_newer('0.9.9', '0.10.0'));
});

it('release_from_location derives version + zip url from the redirect target', function () {
    $r = wpultra_updater_release_from_location('https://github.com/mdnishath/wp-ultra-mcp/releases/tag/v0.14.0');
    assert_eq('0.14.0', $r['version']);
    assert_eq('https://github.com/mdnishath/wp-ultra-mcp/releases/download/v0.14.0/wp-ultra-mcp.zip', $r['zip_url']);
});

it('release_from_location rejects foreign hosts, repos, and non-version tags', function () {
    assert_eq(null, wpultra_updater_release_from_location('https://evil.example/mdnishath/wp-ultra-mcp/releases/tag/v1.0.0'));
    assert_eq(null, wpultra_updater_release_from_location('https://github.com/other/repo/releases/tag/v1.0.0'));
    assert_eq(null, wpultra_updater_release_from_location('https://github.com/mdnishath/wp-ultra-mcp/releases/tag/latest'));
    assert_eq(null, wpultra_updater_release_from_location(''));
});

it('build_update_item shapes the WP core transient entry', function () {
    $release = wpultra_updater_parse_release(updater_fixture());
    $item = wpultra_updater_build_update_item('wp-ultra-mcp/wp-ultra-mcp.php', 'wp-ultra-mcp', $release);
    assert_eq('wp-ultra-mcp/wp-ultra-mcp.php', $item->plugin);
    assert_eq('wp-ultra-mcp', $item->slug);
    assert_eq('0.14.0', $item->new_version);
    assert_contains('wp-ultra-mcp.zip', $item->package);
    assert_contains('github.com', $item->url);
});

run_tests();
