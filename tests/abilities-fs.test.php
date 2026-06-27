<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$tmp = sys_get_temp_dir() . '/wpultra_fs_' . uniqid();
mkdir($tmp, 0777, true);
if (!defined('ABSPATH')) { define('ABSPATH', $tmp . '/'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', $tmp . '/wp-content'); }
if (!defined('WPULTRA_SANDBOX_DIR')) { define('WPULTRA_SANDBOX_DIR', $tmp . '/wp-content/wpultra-sandbox/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) { $GLOBALS['__ab'][$n] = $a; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/write-file.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/read-file.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/edit-file.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/list-directory.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/delete-file.php';

it('write then read roundtrips inside the jail', function () {
    $w = wpultra_write_file(['path' => 'a/b.txt', 'content' => 'hello']);
    assert_true($w['success'], 'write ok');
    $r = wpultra_read_file(['path' => 'a/b.txt']);
    assert_eq('hello', $r['content'], 'read back');
});
it('write blocks traversal outside base', function () {
    assert_wp_error(wpultra_write_file(['path' => '../escape.txt', 'content' => 'x']), 'jail');
});
it('write blocks .php outside sandbox', function () {
    assert_wp_error(wpultra_write_file(['path' => 'wp-content/themes/x/functions.php', 'content' => '<?php']), 'sandbox');
});
it('edit replaces a unique substring', function () {
    wpultra_write_file(['path' => 'e.txt', 'content' => 'foo BAR baz']);
    $e = wpultra_edit_file(['path' => 'e.txt', 'old_string' => 'BAR', 'new_string' => 'QUX']);
    assert_true($e['success'], 'edit ok');
    assert_eq('foo QUX baz', wpultra_read_file(['path' => 'e.txt'])['content']);
});
it('list-directory returns entries', function () {
    $l = wpultra_list_directory(['path' => 'a']);
    assert_true($l['success'], 'list ok');
    assert_true(count($l['entries']) >= 1, 'has entries');
});
it('delete removes a file', function () {
    wpultra_write_file(['path' => 'd.txt', 'content' => 'x']);
    $d = wpultra_delete_file(['path' => 'd.txt']);
    assert_true($d['success'], 'delete ok');
    assert_wp_error(wpultra_read_file(['path' => 'd.txt']), 'gone');
});

run_tests();
