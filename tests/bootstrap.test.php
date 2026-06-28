<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', '/tmp/wp-content'); }
if (!defined('WPULTRA_MCP_ADAPTER_CLASS')) { define('WPULTRA_MCP_ADAPTER_CLASS', 'No\\Such\\Class'); }
if (!defined('WPULTRA_DIR')) { define('WPULTRA_DIR', __DIR__ . '/../wp-ultra-mcp/'); }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/bootstrap-mcp.php';

it('ability file list is complete and unique', function () {
    $files = wpultra_ability_files();
    assert_eq(23, count($files), 'count');
    assert_eq(count($files), count(array_unique($files)), 'unique');
    assert_true(in_array('execute-wp-query', $files, true), 'has sql');
    assert_true(in_array('memory-save', $files, true), 'has memory');
    assert_true(in_array('create-post', $files, true), 'has wp content');
    assert_true(in_array('ability-write', $files, true), 'has recipe crud');
});
it('adapter-unavailable boot is a no-op (no throw)', function () {
    wpultra_boot();
    assert_true(true, 'did not throw');
});

run_tests();
