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
it('merge settings shallow', function () {
    $out = wpultra_el_merge_settings(el_fix(), 'head001', ['title' => ['$$type' => 'html-v3', 'value' => 'x']], false);
    $node = wpultra_el_find($out, 'head001');
    assert_true(isset($node['settings']['tag']) && isset($node['settings']['title']), 'both keys present');
});

run_tests();
