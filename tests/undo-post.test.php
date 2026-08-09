<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

// ---- Stubs: an in-memory post + meta + option store ----
$GLOBALS['__opts'] = [];
$GLOBALS['__post'] = (object) ['ID' => 42, 'post_title' => 'Orig', 'post_content' => 'body', 'post_excerpt' => 'ex', 'post_status' => 'publish'];
$GLOBALS['__meta'] = [42 => ['_elementor_data' => '[{"id":"a"}]', '_elementor_edit_mode' => 'builder']];

if (!function_exists('get_option'))       { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option'))    { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('delete_option'))    { function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; } }
if (!function_exists('current_time'))     { function current_time($t, $g = 0) { return '2026-01-01 00:00:00'; } }
if (!function_exists('get_post'))         { function get_post($id) { return ((int) $id === 42) ? $GLOBALS['__post'] : null; } }
if (!function_exists('get_post_meta'))    { function get_post_meta($id, $k, $s = false) { return $GLOBALS['__meta'][$id][$k] ?? ''; } }
if (!function_exists('update_post_meta')) { function update_post_meta($id, $k, $v) { $GLOBALS['__meta'][$id][$k] = $v; return true; } }
if (!function_exists('delete_post_meta')) { function delete_post_meta($id, $k) { unset($GLOBALS['__meta'][$id][$k]); return true; } }
if (!function_exists('wp_slash'))         { function wp_slash($v) { return $v; } }
if (!function_exists('wp_update_post'))   { function wp_update_post($arr, $e = false) { foreach ($arr as $k => $v) { if ($k !== 'ID') { $GLOBALS['__post']->$k = $v; } } return (int) $arr['ID']; } }
if (!function_exists('wpultra_category_enabled')) { function wpultra_category_enabled($c) { return true; } }

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/undo/engine.php';

it('post is an extended (capturable) undo type', function () {
    assert_true(in_array('post', wpultra_undo_extended_types(), true));
});

it('capture_post snapshots fields + builder meta', function () {
    $GLOBALS['__opts'] = [];
    $id = wpultra_undo_capture_post(42, 'test');
    assert_true($id > 0);
    $stack = wpultra_undo_load_stack();
    $entry = wpultra_undo_find($stack, $id);
    assert_eq('post', $entry['type']);
    assert_eq('Orig', $entry['before']['fields']['post_title']);
    assert_eq('[{"id":"a"}]', $entry['before']['meta']['_elementor_data']);
});

it('restore_post reverts fields and builder meta after a mutation', function () {
    $GLOBALS['__opts'] = [];
    $id = wpultra_undo_capture_post(42, 'before edit');
    // Simulate an edit.
    $GLOBALS['__post']->post_title = 'Changed';
    $GLOBALS['__meta'][42]['_elementor_data'] = '[{"id":"b"}]';
    // Restore.
    $r = wpultra_undo_restore($id);
    assert_true($r['restored']);
    assert_eq('Orig', $GLOBALS['__post']->post_title);
    assert_eq('[{"id":"a"}]', $GLOBALS['__meta'][42]['_elementor_data']);
    // Entry consumed.
    assert_eq(null, wpultra_undo_find(wpultra_undo_load_stack(), $id));
});

it('restore_post errors when the post is gone', function () {
    $entry = wpultra_undo_make_entry(1, 'post', '999', ['fields' => []], 'x', '');
    assert_wp_error(wpultra_undo_restore_post($entry));
});

run_tests();
