<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/bricks/engine.php';

/** Fixture: flat Bricks element array — a container with two children, one nested. */
function bricks_fix(): array {
    return [
        ['id' => 'root001', 'name' => 'container', 'parent' => 0, 'children' => ['head001', 'text001'], 'settings' => []],
        ['id' => 'head001', 'name' => 'heading', 'parent' => 'root001', 'children' => [], 'settings' => ['text' => 'Hello']],
        ['id' => 'text001', 'name' => 'text-basic', 'parent' => 'root001', 'children' => ['span001'], 'settings' => []],
        ['id' => 'span001', 'name' => 'text', 'parent' => 'text001', 'children' => [], 'settings' => []],
    ];
}

it('build tree nests children under their parent, roots at top', function () {
    $tree = wpultra_bricks_build_tree(bricks_fix());
    assert_eq(1, count($tree));
    assert_eq('root001', $tree[0]['id']);
    assert_eq('container', $tree[0]['name']);
    assert_eq(2, count($tree[0]['children']));
    assert_eq('head001', $tree[0]['children'][0]['id']);
    assert_eq('text001', $tree[0]['children'][1]['id']);
    assert_eq('span001', $tree[0]['children'][1]['children'][0]['id']);
});

it('build tree treats parent "0"/""/null/missing as root', function () {
    $flat = [
        ['id' => 'a', 'name' => 'x', 'parent' => '0', 'children' => []],
        ['id' => 'b', 'name' => 'x', 'parent' => '', 'children' => []],
        ['id' => 'c', 'name' => 'x', 'parent' => null, 'children' => []],
        ['id' => 'd', 'name' => 'x', 'children' => []], // missing parent key
    ];
    $tree = wpultra_bricks_build_tree($flat);
    assert_eq(4, count($tree));
    assert_eq(['a', 'b', 'c', 'd'], array_map(fn($n) => $n['id'], $tree));
});

it('build tree ignores a child id that does not resolve (dangling ref)', function () {
    $flat = [
        ['id' => 'root', 'name' => 'container', 'parent' => 0, 'children' => ['ghost', 'kid']],
        ['id' => 'kid', 'name' => 'text', 'parent' => 'root', 'children' => []],
    ];
    $tree = wpultra_bricks_build_tree($flat);
    assert_eq(1, count($tree[0]['children']));
    assert_eq('kid', $tree[0]['children'][0]['id']);
});

it('build tree is depth-guarded against cycles', function () {
    // a's parent is b, b's parent is a — a self-referencing cycle via children lists.
    $flat = [
        ['id' => 'a', 'name' => 'x', 'parent' => 'b', 'children' => ['b']],
        ['id' => 'b', 'name' => 'x', 'parent' => 'a', 'children' => ['a']],
    ];
    // Neither is a root (both have non-empty parent), so build_tree returns no top-level nodes
    // and never infinite-loops.
    $tree = wpultra_bricks_build_tree($flat, 5);
    assert_eq([], $tree);
});

it('find returns the raw flat element by id', function () {
    $node = wpultra_bricks_find(bricks_fix(), 'text001');
    assert_eq('text-basic', $node['name']);
    assert_eq(null, wpultra_bricks_find(bricks_fix(), 'nope'));
});

it('validate passes a well-formed flat array', function () {
    $report = wpultra_bricks_validate_tree(bricks_fix());
    assert_true($report['ok'], implode('; ', $report['errors']));
    assert_eq(4, $report['count']);
});

it('validate fails when an element is missing id', function () {
    $report = wpultra_bricks_validate_tree([
        ['name' => 'container', 'parent' => 0, 'children' => []],
    ]);
    assert_eq(false, $report['ok']);
    assert_contains("missing an 'id'", $report['errors'][0]);
});

it('validate fails when an element is missing name', function () {
    $report = wpultra_bricks_validate_tree([
        ['id' => 'a', 'parent' => 0, 'children' => []],
    ]);
    assert_eq(false, $report['ok']);
    assert_contains("missing a 'name'", implode(' ', $report['errors']));
});

it('validate fails when parent id does not exist', function () {
    $report = wpultra_bricks_validate_tree([
        ['id' => 'a', 'name' => 'x', 'parent' => 'ghost-parent', 'children' => []],
    ]);
    assert_eq(false, $report['ok']);
    assert_contains('ghost-parent', implode(' ', $report['errors']));
});

it('validate fails on duplicate ids', function () {
    $report = wpultra_bricks_validate_tree([
        ['id' => 'a', 'name' => 'x', 'parent' => 0, 'children' => []],
        ['id' => 'a', 'name' => 'y', 'parent' => 0, 'children' => []],
    ]);
    assert_eq(false, $report['ok']);
    assert_contains('Duplicate', implode(' ', $report['errors']));
});

it('validate accepts root parent expressed as "0", "", or missing', function () {
    $report = wpultra_bricks_validate_tree([
        ['id' => 'a', 'name' => 'x', 'parent' => '0'],
        ['id' => 'b', 'name' => 'x', 'parent' => ''],
        ['id' => 'c', 'name' => 'x'],
    ]);
    assert_true($report['ok'], implode('; ', $report['errors']));
});

it('enabled_post_types reads the postTypes key from bricks_global_settings, pure', function () {
    assert_eq(['page', 'post'], wpultra_bricks_enabled_post_types(['postTypes' => ['page', 'post']]));
    assert_eq([], wpultra_bricks_enabled_post_types([]));
    assert_eq([], wpultra_bricks_enabled_post_types(['postTypes' => 'not-an-array']));
});

it('shape_elements maps a registry of class-name-less config arrays to name/label/category', function () {
    $registry = [
        'container' => ['label' => 'Container', 'category' => 'general'],
        'heading'   => ['name' => 'Heading', 'category' => 'basic'],
    ];
    $shaped = wpultra_bricks_shape_elements($registry);
    assert_eq(2, count($shaped));
    assert_eq('container', $shaped[0]['name']);
    assert_eq('Container', $shaped[0]['label']);
    assert_eq('general', $shaped[0]['category']);
    assert_eq('heading', $shaped[1]['name']);
    assert_eq('Heading', $shaped[1]['label']);
});

it('active/version/list-elements/status degrade gracefully without the Bricks classes', function () {
    assert_eq(false, wpultra_bricks_active());
    assert_eq(null, wpultra_bricks_version());
    assert_eq([], wpultra_bricks_list_elements());
    $status = wpultra_bricks_status();
    assert_eq(false, $status['active']);
    assert_eq(null, $status['version']);
    assert_eq([], $status['post_types']);
});

run_tests();
