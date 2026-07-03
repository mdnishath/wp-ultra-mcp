<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/bricks/ops.php';

function bx_fixture(): array {
    return [
        ['id' => 'sec111', 'name' => 'section',   'parent' => '0',      'children' => ['con111'], 'settings' => []],
        ['id' => 'con111', 'name' => 'container', 'parent' => 'sec111', 'children' => ['hed111', 'txt111'], 'settings' => []],
        ['id' => 'hed111', 'name' => 'heading',   'parent' => 'con111', 'children' => [], 'settings' => ['text' => 'Hi', 'tag' => 'h1']],
        ['id' => 'txt111', 'name' => 'text-basic', 'parent' => 'con111', 'children' => [], 'settings' => ['text' => 'Body']],
    ];
}

it('consistency passes the fixture and catches one-way links', function () {
    assert_eq(true, wpultra_bricks_consistency(bx_fixture()));
    $bad = bx_fixture();
    $bad[1]['children'] = ['hed111']; // txt111 still says parent=con111 but is not listed
    assert_true(is_string(wpultra_bricks_consistency($bad)));
    $bad2 = bx_fixture();
    $bad2[2]['parent'] = 'ghost1';
    assert_true(is_string(wpultra_bricks_consistency($bad2)));
});

it('insert wires parent + children both ways, at position', function () {
    $node = ['id' => 'btn111', 'name' => 'button', 'settings' => ['text' => 'Go'], 'children' => []];
    $out = wpultra_bricks_op_insert(bx_fixture(), $node, 'con111', 1);
    assert_true(is_array($out));
    assert_eq(true, wpultra_bricks_consistency($out));
    $idx = wpultra_bricks_index($out);
    assert_eq(['hed111', 'btn111', 'txt111'], $out[$idx['con111']]['children']);
    assert_eq('con111', $out[$idx['btn111']]['parent']);
});

it('insert at root splices among roots and rejects duplicate ids', function () {
    $node = ['id' => 'sec222', 'name' => 'section', 'children' => [], 'settings' => []];
    $out = wpultra_bricks_op_insert(bx_fixture(), $node, '0', 0);
    assert_eq('sec222', $out[0]['id']); // before the existing root
    assert_eq(true, wpultra_bricks_consistency($out));
    assert_true(is_string(wpultra_bricks_op_insert(bx_fixture(), ['id' => 'sec111', 'name' => 'x'], '0', 0)));
});

it('edit deep-merges settings without clobbering siblings keys', function () {
    $out = wpultra_bricks_op_edit(bx_fixture(), 'hed111', ['text' => 'New']);
    $idx = wpultra_bricks_index($out);
    assert_eq('New', $out[$idx['hed111']]['settings']['text']);
    assert_eq('h1', $out[$idx['hed111']]['settings']['tag']); // untouched key survives
    assert_true(is_string(wpultra_bricks_op_edit(bx_fixture(), 'nope', [])));
});

it('delete removes the whole subtree and fixes the parent list', function () {
    $out = wpultra_bricks_op_delete(bx_fixture(), 'con111');
    assert_eq(1, count($out)); // only the section remains
    assert_eq([], $out[0]['children']);
    assert_eq(true, wpultra_bricks_consistency($out));
});

it('move relocates with cycle guard', function () {
    // add a second container to move the heading into
    $fx = wpultra_bricks_op_insert(bx_fixture(), ['id' => 'con222', 'name' => 'container', 'children' => [], 'settings' => []], 'sec111', 1);
    $out = wpultra_bricks_op_move($fx, 'hed111', 'con222', 0);
    assert_true(is_array($out));
    assert_eq(true, wpultra_bricks_consistency($out));
    $idx = wpultra_bricks_index($out);
    assert_eq(['hed111'], $out[$idx['con222']]['children']);
    assert_eq(['txt111'], $out[$idx['con111']]['children']);
    // cycle: cannot move the section into its own descendant
    assert_true(is_string(wpultra_bricks_op_move($fx, 'sec111', 'con111', 0)));
});

it('blueprints are internally consistent and re-id collision-free', function () {
    foreach (wpultra_bricks_blueprints() as $name => $bp) {
        assert_eq(true, wpultra_bricks_consistency($bp['elements']), "blueprint $name");
        $reided = wpultra_bricks_blueprint_reid($bp['elements'], ['sec111', 'con111']);
        assert_eq(true, wpultra_bricks_consistency($reided), "reided $name");
        foreach ($reided as $el) {
            assert_true(!str_starts_with((string) $el['id'], 'bp'), 'ids replaced');
            assert_true(!in_array($el['id'], ['sec111', 'con111'], true), 'avoids existing');
        }
    }
});

it('in_subtree walks children transitively', function () {
    assert_true(wpultra_bricks_in_subtree(bx_fixture(), 'sec111', 'txt111'));
    assert_true(wpultra_bricks_in_subtree(bx_fixture(), 'con111', 'con111'));
    assert_true(!wpultra_bricks_in_subtree(bx_fixture(), 'hed111', 'sec111'));
});

it('new_id is 6 chars and avoids taken ids', function () {
    $id = wpultra_bricks_new_id(['abc123']);
    assert_eq(6, strlen($id));
    assert_true($id !== 'abc123');
    assert_true((bool) preg_match('/^[a-z0-9]{6}$/', $id));
});

run_tests();
