<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/tree.php';

function el_fix(): array {
    return [[
        'id' => 'row0001', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [
            ['id' => 'head001', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['tag' => ['$$type' => 'string', 'value' => 'h2']], 'elements' => []],
            ['id' => 'btn0001', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => [], 'elements' => []],
        ],
    ]];
}

it('compact tree shapes nodes', function () {
    $c = wpultra_el_compact_tree(el_fix());
    assert_eq('row0001', $c[0]['id']);
    assert_eq('e-flexbox', $c[0]['elType']);
    assert_eq('e-heading', $c[0]['children'][0]['widgetType']);
});
it('find returns the node', function () {
    assert_eq('e-button', wpultra_el_find(el_fix(), 'btn0001')['widgetType']);
    assert_eq(null, wpultra_el_find(el_fix(), 'nope'));
});
it('insert under parent at position', function () {
    $node = ['id' => 'img0001', 'elType' => 'widget', 'widgetType' => 'e-image', 'settings' => [], 'elements' => []];
    $out = wpultra_el_insert(el_fix(), 'row0001', 1, $node);
    assert_eq('img0001', $out[0]['elements'][1]['id']);
    assert_eq('btn0001', $out[0]['elements'][2]['id']);
});
it('insert at root', function () {
    $node = ['id' => 'r2', 'elType' => 'e-div-block', 'settings' => [], 'elements' => []];
    $out = wpultra_el_insert(el_fix(), null, 0, $node);
    assert_eq('r2', $out[0]['id']);
});
it('insert errors on missing parent', function () {
    assert_wp_error(wpultra_el_insert(el_fix(), 'nope', 0, ['id' => 'x', 'elType' => 'widget', 'widgetType' => 'e-image', 'elements' => []]));
});
it('insert rejects a leaf-widget parent (parent_not_container)', function () {
    $node = ['id' => 'x', 'elType' => 'widget', 'widgetType' => 'e-image', 'settings' => [], 'elements' => []];
    $err = wpultra_el_insert(el_fix(), 'btn0001', 0, $node); // btn0001 is a leaf widget
    assert_wp_error($err);
    assert_eq('parent_not_container', $err->get_error_code());
});
it('container-type helper accepts flexbox/div-block but not widget', function () {
    assert_true(wpultra_el_is_container_type('e-flexbox'), 'flexbox is a container');
    assert_true(wpultra_el_is_container_type('e-div-block'), 'div-block is a container');
    assert_eq(false, wpultra_el_is_container_type('widget'));
});
it('remove deletes node', function () {
    $out = wpultra_el_remove(el_fix(), 'head001');
    assert_eq(1, count($out[0]['elements']));
    assert_eq('btn0001', $out[0]['elements'][0]['id']);
});
it('move relocates subtree', function () {
    $out = wpultra_el_move(el_fix(), 'head001', null, 0);
    assert_eq('head001', $out[0]['id']);
    assert_eq(1, count($out[1]['elements']));
});
it('move within same parent places element at the requested final index', function () {
    // Siblings a,b,c. `pos` is the desired FINAL index: move a→2 yields b,c,a; move c→0 yields c,a,b.
    $tree = [['id' => 'p', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [
        ['id' => 'a', 'elType' => 'widget', 'elements' => []],
        ['id' => 'b', 'elType' => 'widget', 'elements' => []],
        ['id' => 'c', 'elType' => 'widget', 'elements' => []],
    ]]];
    $fwd = wpultra_el_move($tree, 'a', 'p', 2);
    assert_eq(['b', 'c', 'a'], array_map(fn($n) => $n['id'], $fwd[0]['elements']));
    $back = wpultra_el_move($tree, 'c', 'p', 0);
    assert_eq(['c', 'a', 'b'], array_map(fn($n) => $n['id'], $back[0]['elements']));
});
it('locate reports parent and index', function () {
    $loc = wpultra_el_locate(el_fix(), 'btn0001');
    assert_eq('row0001', $loc['parent_id']);
    assert_eq(1, $loc['index']);
    assert_eq('', wpultra_el_locate(el_fix(), 'row0001')['parent_id']);
});
it('merge settings shallow', function () {
    $out = wpultra_el_merge_settings(el_fix(), 'head001', ['title' => ['$$type' => 'html-v3', 'value' => 'x']], false);
    $node = wpultra_el_find($out, 'head001');
    assert_true(isset($node['settings']['tag']) && isset($node['settings']['title']), 'both keys present');
});

run_tests();
