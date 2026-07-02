<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/undo/engine.php';

it('supported types are the four reversible targets', function () {
    assert_eq(['option', 'custom_css', 'theme_json', 'term'], wpultra_undo_supported_types());
});

it('next_id is max+1 and 1 on an empty stack', function () {
    assert_eq(1, wpultra_undo_next_id([]));
    assert_eq(6, wpultra_undo_next_id([['id' => 5], ['id' => 2]]));
});

it('push prepends newest-first and caps the stack', function () {
    $stack = [];
    for ($i = 1; $i <= 5; $i++) {
        $stack = wpultra_undo_push($stack, ['id' => $i], 3);
    }
    assert_eq(3, count($stack));
    assert_eq(5, $stack[0]['id']); // newest first
    assert_eq(3, $stack[2]['id']); // oldest kept
});

it('find and remove operate by id', function () {
    $stack = [['id' => 3], ['id' => 2], ['id' => 1]];
    assert_eq(2, wpultra_undo_find($stack, 2)['id']);
    assert_eq(null, wpultra_undo_find($stack, 9));
    $after = wpultra_undo_remove($stack, 2);
    assert_eq(2, count($after));
    assert_eq(null, wpultra_undo_find($after, 2));
});

it('shape omits the before-payload', function () {
    $entry = wpultra_undo_make_entry(7, 'option', 'blogname', 'Old Name', 'Overwrite option blogname', '2026-07-02 00:00:00');
    $s = wpultra_undo_shape($entry);
    assert_eq(7, $s['id']);
    assert_eq('option', $s['type']);
    assert_eq('blogname', $s['target']);
    assert_true(!array_key_exists('before', $s), 'before must not leak into the compact shape');
});

it('make_entry preserves the before value verbatim (incl. the absent sentinel)', function () {
    $e = wpultra_undo_make_entry(1, 'option', 'x', WPULTRA_UNDO_ABSENT, 'Create option x', '');
    assert_eq(WPULTRA_UNDO_ABSENT, $e['before']);
    $e2 = wpultra_undo_make_entry(2, 'term', 'category:5', ['term_id' => 5, 'name' => 'News'], 'Update term News', '');
    assert_eq('News', $e2['before']['name']);
});

run_tests();
