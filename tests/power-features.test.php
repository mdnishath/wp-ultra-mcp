<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('plugin_basename')) { function plugin_basename($f) { return 'wp-ultra-mcp/wp-ultra-mcp.php'; } }
if (!defined('WPULTRA_FILE')) { define('WPULTRA_FILE', '/x/wp-ultra-mcp/wp-ultra-mcp.php'); }
require __DIR__ . '/../wp-ultra-mcp/includes/media/engine.php';
require __DIR__ . '/../wp-ultra-mcp/includes/users/engine.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/engine.php';

it('media pick_filename derives a safe name from a URL', function () {
    assert_eq('photo.jpg', wpultra_media_pick_filename('https://x.test/a/b/photo.jpg?w=100'));
    assert_eq('my-file.png', wpultra_media_pick_filename('my file.png'));
    assert_eq('download', wpultra_media_pick_filename('', 'download'));
    assert_eq('upload', wpultra_media_pick_filename('https://x.test/', 'upload')); // no basename
});

it('user role privilege gate flags admin/super-admin only', function () {
    assert_true(wpultra_user_role_is_privileged('administrator'));
    assert_true(wpultra_user_role_is_privileged('Super-Admin'));
    assert_true(!wpultra_user_role_is_privileged('editor'));
    assert_true(!wpultra_user_role_is_privileged('subscriber'));
});

it('system self-protection recognizes the plugin by its own directory', function () {
    assert_true(wpultra_system_is_self('wp-ultra-mcp/wp-ultra-mcp.php'), 'exact');
    assert_true(wpultra_system_is_self('wp-ultra-mcp/other.php'), 'same dir, different file');
    assert_true(!wpultra_system_is_self('akismet/akismet.php'), 'other plugin');
});

run_tests();
