<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/jetengine/engine.php';

it('labels builder produces the JetEngine label set', function () {
    $l = wpultra_je_build_labels('Book', 'Books');
    assert_eq('Books', $l['name']);
    assert_eq('Book', $l['singular_name']);
    assert_eq('Add New Book', $l['add_new']);
    assert_eq('Search Books', $l['search_items']);
});

it('default cpt args merge overrides', function () {
    $a = wpultra_je_default_cpt_args(['show_in_rest' => false, 'menu_icon' => 'dashicons-book']);
    assert_eq(false, $a['show_in_rest']);
    assert_eq('dashicons-book', $a['menu_icon']);
    assert_eq(true, $a['public']); // default survives
    assert_eq(['title', 'editor', 'thumbnail'], $a['supports']);
});

it('field normalizer builds JetEngine field shape with defaults', function () {
    $f = wpultra_je_normalize_fields([
        ['name' => 'price', 'type' => 'number'],
        ['name' => 'video_url', 'type' => 'text', 'title' => 'Video URL', 'width' => '50%'],
        ['name' => 'level', 'type' => 'select', 'options' => [['key' => 'a', 'value' => 'A']]],
    ]);
    assert_true(is_array($f));
    assert_eq('Price', $f[0]['title']);            // auto title
    assert_eq('field', $f[0]['object_type']);
    assert_eq('100%', $f[0]['width']);
    assert_eq('Video URL', $f[1]['title']);
    assert_eq('50%', $f[1]['width']);
    assert_eq(1, count($f[2]['options']));
});

it('field normalizer rejects bad names, dup names, unknown types', function () {
    assert_true(is_string(wpultra_je_normalize_fields([['name' => 'BadName', 'type' => 'text']])));
    assert_true(is_string(wpultra_je_normalize_fields([['name' => 'a', 'type' => 'text'], ['name' => 'a', 'type' => 'text']])));
    assert_true(is_string(wpultra_je_normalize_fields([['name' => 'x', 'type' => 'hologram']])));
    assert_eq([], wpultra_je_normalize_fields(null));
    assert_true(is_string(wpultra_je_normalize_fields('nope')));
});

it('row shaper compacts and expands', function () {
    $row = [
        'id' => 7, 'slug' => 'book', 'status' => 'publish',
        'labels' => ['name' => 'Books', 'singular_name' => 'Book'],
        'args' => ['public' => true],
        'meta_fields' => [['name' => 'price'], ['name' => 'isbn']],
    ];
    $c = wpultra_je_shape_row($row);
    assert_eq('Books', $c['name']);
    assert_eq(['price', 'isbn'], $c['fields']);
    assert_true(!isset($c['args']));
    $f = wpultra_je_shape_row($row, true);
    assert_eq(['public' => true], $f['args']);
});

it('meta-box id allocator increments past the max', function () {
    assert_eq('meta-1', wpultra_je_next_meta_box_id([]));
    assert_eq('meta-3', wpultra_je_next_meta_box_id([['id' => 'meta-2'], ['id' => 'meta-1']]));
    assert_eq('meta-8', wpultra_je_next_meta_box_id([['id' => 'meta-7'], ['id' => 'custom-x']]));
});

run_tests();
