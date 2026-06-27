<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!defined('WPULTRA_DIR')) { define('WPULTRA_DIR', __DIR__ . '/../wp-ultra-mcp/'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', '/tmp'); }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/bootstrap-mcp.php';
require __DIR__ . '/../wp-ultra-mcp/includes/admin/connect-page.php';
require __DIR__ . '/../wp-ultra-mcp/includes/admin/abilities-page.php';

it('admin render functions are defined', function () {
    assert_true(function_exists('wpultra_connect_render'), 'connect');
    assert_true(function_exists('wpultra_abilities_render'), 'abilities');
});

run_tests();
