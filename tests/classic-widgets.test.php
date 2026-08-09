<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/widgets.php';

it('split_id separates base and index', function () {
    assert_eq(['text', 2], wpultra_widget_split_id('text-2'));
    assert_eq(['nav_menu', 3], wpultra_widget_split_id('nav_menu-3'));
    assert_eq(['recent-posts', 0], wpultra_widget_split_id('recent-posts')); // no trailing index
});

it('move_in_map relocates a widget and dedupes it from other sidebars', function () {
    $map = [
        'sidebar-1' => ['text-2', 'search-3'],
        'sidebar-2' => ['nav_menu-1'],
    ];
    $out = wpultra_widget_move_in_map($map, 'text-2', 'sidebar-2', 0);
    assert_eq(['search-3'], $out['sidebar-1']);              // removed from origin
    assert_eq(['text-2', 'nav_menu-1'], $out['sidebar-2']);  // inserted at position 0
});

it('move_in_map appends when position is -1 and creates a missing target', function () {
    $map = ['sidebar-1' => ['a-1']];
    $out = wpultra_widget_move_in_map($map, 'a-1', 'wp_inactive_widgets', -1);
    assert_eq([], $out['sidebar-1']);
    assert_eq(['a-1'], $out['wp_inactive_widgets']);
});

it('move_in_map clamps an out-of-range position to the end', function () {
    $map = ['s' => ['a', 'b'], 't' => ['x']];
    $out = wpultra_widget_move_in_map($map, 'a', 't', 99);
    assert_eq(['x', 'a'], $out['t']);
});

run_tests();
