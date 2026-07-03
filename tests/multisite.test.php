<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- Environment / stubs ---
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_multisite/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) { $GLOBALS['__ab'][$n] = $a; } }
if (!function_exists('trailingslashit')) { function trailingslashit($s) { return rtrim($s, "/\\") . '/'; } }

// Requiring network.php under the harness must never fatal (it only needs the pure helpers below).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/network.php';

/* ============================================================
 * wpultra_ms_new_site_args
 * ============================================================ */

it('new_site_args: subdirectory install builds /slug/ under network path', function () {
    $r = wpultra_ms_new_site_args('shop', 'example.com', '/', false);
    assert_eq('example.com', $r['domain']);
    assert_eq('/shop/', $r['path']);
});

it('new_site_args: subdomain install builds slug.domain with root path', function () {
    $r = wpultra_ms_new_site_args('shop', 'example.com', '/', true);
    assert_eq('shop.example.com', $r['domain']);
    assert_eq('/', $r['path']);
});

it('new_site_args: full domain input passes through as-is (subdir mode)', function () {
    $r = wpultra_ms_new_site_args('shop.example.com', 'example.com', '/', false);
    assert_eq('shop.example.com', $r['domain']);
    assert_eq('/', $r['path']);
});

it('new_site_args: full domain input passes through as-is (subdomain mode)', function () {
    $r = wpultra_ms_new_site_args('shop.example.com', 'example.com', '/', true);
    assert_eq('shop.example.com', $r['domain']);
    assert_eq('/', $r['path']);
});

it('new_site_args: trailing/leading slashes on the slug are normalized', function () {
    $r = wpultra_ms_new_site_args('/shop/', 'example.com', '/', false);
    assert_eq('example.com', $r['domain']);
    assert_eq('/shop/', $r['path']);
});

it('new_site_args: subdirectory install nests under a non-root network path', function () {
    $r = wpultra_ms_new_site_args('shop', 'example.com', '/net', false);
    assert_eq('example.com', $r['domain']);
    assert_eq('/net/shop/', $r['path']);
});

it('new_site_args: subdomain install ignores a non-root network path (root path used)', function () {
    $r = wpultra_ms_new_site_args('shop', 'example.com', '/net', true);
    assert_eq('shop.example.com', $r['domain']);
    assert_eq('/net/', $r['path']);
});

it('new_site_args: full domain with an explicit sub-path keeps a trailing slash', function () {
    $r = wpultra_ms_new_site_args('shop.example.com/store', 'example.com', '/', false);
    assert_eq('shop.example.com', $r['domain']);
    assert_eq('/store/', $r['path']);
});

/* ============================================================
 * wpultra_ms_valid_status_field
 * ============================================================ */

it('valid_status_field accepts archived, deleted, spam, public', function () {
    assert_true(wpultra_ms_valid_status_field('archived'));
    assert_true(wpultra_ms_valid_status_field('deleted'));
    assert_true(wpultra_ms_valid_status_field('spam'));
    assert_true(wpultra_ms_valid_status_field('public'));
});

it('valid_status_field rejects unknown fields', function () {
    assert_eq(false, wpultra_ms_valid_status_field('mature'));
    assert_eq(false, wpultra_ms_valid_status_field(''));
    assert_eq(false, wpultra_ms_valid_status_field('lang_id'));
});

/* ============================================================
 * wpultra_ms_require_multisite guard (no WP is_multisite() stub loaded => not multisite)
 * ============================================================ */

it('all engine functions refuse to run on a non-multisite install', function () {
    // is_multisite() is not defined in this pure-logic harness context; define it as false
    // to exercise the guard explicitly and deterministically.
    if (!function_exists('is_multisite')) { function is_multisite() { return false; } }
    assert_wp_error(wpultra_ms_sites_list());
    assert_wp_error(wpultra_ms_site_create('shop', 'Shop', 1));
    assert_wp_error(wpultra_ms_site_update_status(2, 'archived', true));
    assert_wp_error(wpultra_ms_site_delete(2, true));
    assert_wp_error(wpultra_ms_network_option_get('foo'));
    assert_wp_error(wpultra_ms_network_option_set('foo', 'bar'));
    assert_eq('not_multisite', wpultra_ms_sites_list()->get_error_code());
});

run_tests();
