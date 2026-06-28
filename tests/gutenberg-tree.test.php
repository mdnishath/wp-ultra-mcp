<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
// parse_blocks is only used by the normalizer 'markup' branch; minimal stub for tests.
if (!function_exists('parse_blocks')) {
    function parse_blocks($content) {
        if (preg_match('/<!--\s*wp:([a-z0-9\/-]+)/i', (string) $content, $m)) {
            // WordPress: bare names (no slash) are in the core namespace.
            $name = str_contains($m[1], '/') ? $m[1] : 'core/' . $m[1];
            return [['blockName' => $name, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]];
        }
        return [];
    }
}
require __DIR__ . '/../wp-ultra-mcp/includes/gutenberg/tree.php';

function gb_sample(): array {
    return [
        ['blockName' => 'core/paragraph', 'attrs' => ['content' => 'A'], 'innerBlocks' => [], 'innerHTML' => '<p>A</p>', 'innerContent' => ['<p>A</p>']],
        ['blockName' => 'core/group', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [null, null], 'innerBlocks' => [
            ['blockName' => 'core/heading', 'attrs' => ['level' => 2], 'innerBlocks' => [], 'innerHTML' => '<h2>H</h2>', 'innerContent' => ['<h2>H</h2>']],
            ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n", 'innerContent' => ["\n"]],
        ]],
    ];
}

it('path<->str round-trips', function () {
    assert_eq('0/2/1', wpultra_gb_path_to_str([0, 2, 1]));
    assert_eq([0, 2, 1], wpultra_gb_str_to_path('0/2/1'));
    assert_eq([], wpultra_gb_str_to_path(''));
});

it('compact tree attaches paths and skips null-name blocks', function () {
    $t = wpultra_gb_compact_tree(gb_sample());
    assert_eq('0', $t[0]['path']);
    assert_eq('core/paragraph', $t[0]['blockName']);
    assert_eq('1', $t[1]['path']);
    assert_eq('1/0', $t[1]['innerBlocks'][0]['path']);   // heading
    assert_eq(1, count($t[1]['innerBlocks']));            // null-name child skipped
});

it('locate finds nested node with parent + index', function () {
    $loc = wpultra_gb_locate(gb_sample(), [1, 0]);
    assert_eq('core/heading', $loc['node']['blockName']);
    assert_eq([1], $loc['parent_path']);
    assert_eq(0, $loc['index']);
    assert_eq(null, wpultra_gb_locate(gb_sample(), [9]));
});

it('insert at root and nested', function () {
    $blk = ['blockName' => 'core/spacer', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => ['']];
    $out = wpultra_gb_insert(gb_sample(), [], 1, $blk);
    assert_eq('core/spacer', $out[1]['blockName']);
    $out2 = wpultra_gb_insert(gb_sample(), [1], 0, $blk);
    assert_eq('core/spacer', $out2[1]['innerBlocks'][0]['blockName']);
    assert_wp_error(wpultra_gb_insert(gb_sample(), [9], 0, $blk));
});

it('remove deletes target', function () {
    $out = wpultra_gb_remove(gb_sample(), [0]);
    assert_eq('core/group', $out[0]['blockName']);
    assert_wp_error(wpultra_gb_remove(gb_sample(), [5]));
});

it('move relocates node to new parent and index', function () {
    $out = wpultra_gb_move(gb_sample(), [0], [1], 0); // paragraph into group at index 0
    assert_eq('core/paragraph', $out[0]['innerBlocks'][0]['blockName']);
});

it('merge_attrs shallow', function () {
    $out = wpultra_gb_merge_attrs(gb_sample(), [0], ['content' => 'B', 'align' => 'left'], false);
    assert_eq('B', $out[0]['attrs']['content']);
    assert_eq('left', $out[0]['attrs']['align']);
});

it('normalize structured leaf, container, and markup mode', function () {
    $leaf = wpultra_gb_normalize_block(['name' => 'core/paragraph', 'attributes' => ['content' => 'Hi'], 'inner_html' => '<p>Hi</p>']);
    assert_eq('core/paragraph', $leaf['blockName']);
    assert_eq(['<p>Hi</p>'], $leaf['innerContent']);
    $container = wpultra_gb_normalize_block(['name' => 'core/group', 'inner_blocks' => [['name' => 'core/spacer']]]);
    assert_eq([null], $container['innerContent']); // one null per inner block
    $fromMarkup = wpultra_gb_normalize_block(['markup' => '<!-- wp:separator --><hr/><!-- /wp:separator -->']);
    assert_eq('core/separator', $fromMarkup['blockName']);
    assert_wp_error(wpultra_gb_normalize_block([]));
});

run_tests();
