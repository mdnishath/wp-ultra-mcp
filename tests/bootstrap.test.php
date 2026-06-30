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
    assert_eq(83, count($files), 'count');
    assert_eq(count($files), count(array_unique($files)), 'unique');
    assert_true(in_array('execute-wp-query', $files, true), 'has sql');
    assert_true(in_array('memory-save', $files, true), 'has memory');
    assert_true(in_array('create-post', $files, true), 'has wp content');
    assert_true(in_array('ability-write', $files, true), 'has recipe crud');
    assert_true(in_array('elementor-get-content', $files, true), 'has elementor');
    assert_true(in_array('elementor-add-element', $files, true), 'has elementor mutation');
    assert_true(in_array('elementor-get-design-system', $files, true), 'has design');
    assert_true(in_array('elementor-manage-global-colors', $files, true), 'has design write');
    assert_true(in_array('elementor-upsert-global-class', $files, true), 'has classes');
    assert_true(in_array('gutenberg-get-content', $files, true), 'has gutenberg read');
    assert_true(in_array('woo-store-status', $files, true), 'has woocommerce');
    assert_true(in_array('woo-insert-product-block', $files, true), 'has woo bridge');
    assert_true(in_array('seo-status', $files, true), 'has seo');
});
it('adapter-unavailable boot is a no-op (no throw)', function () {
    wpultra_boot();
    assert_true(true, 'did not throw');
});

it('category map covers every ability file exactly once', function () {
    $files = wpultra_ability_files();
    $mapped = [];
    foreach (wpultra_ability_category_map() as $cat => $list) {
        foreach ($list as $f) { $mapped[] = $f; }
    }
    sort($files); $u = array_unique($mapped); sort($u);
    assert_eq(count($files), count($mapped), 'no file mapped twice');
    assert_eq($files, $u, 'mapped set equals ability file set');
});

it('file_category reverse lookup works', function () {
    assert_eq('code-execution', wpultra_file_category('execute-php'));
    assert_eq('elementor', wpultra_file_category('elementor-add-element'));
    assert_eq('custom', wpultra_file_category('ability-write'));
    assert_eq('', wpultra_file_category('does-not-exist'));
});

it('no categories disabled by default', function () {
    assert_eq([], wpultra_disabled_categories());
    assert_true(wpultra_category_enabled('code-execution'), 'enabled by default');
});

run_tests();
