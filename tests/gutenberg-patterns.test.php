<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
// Stub parse_blocks: return one real block per "<!-- wp:NAME" marker, plus a null-name whitespace chunk.
if (!function_exists('parse_blocks')) {
    function parse_blocks($content) {
        $out = [];
        if (preg_match_all('/<!--\s*wp:([a-z0-9\/-]+)/i', (string) $content, $m)) {
            foreach ($m[1] as $name) {
                $out[] = ['blockName' => $name, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
            }
        }
        $out[] = ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n", 'innerContent' => ["\n"]];
        return $out;
    }
}
require __DIR__ . '/../wp-ultra-mcp/includes/gutenberg/patterns.php';

it('pattern_blocks returns top-level blocks and skips null-name chunks', function () {
    $blocks = wpultra_gb_pattern_blocks('<!-- wp:heading --><!-- /wp:heading --><!-- wp:paragraph --><!-- /wp:paragraph -->');
    assert_eq(2, count($blocks));
    assert_eq('heading', $blocks[0]['blockName']);
    assert_eq('paragraph', $blocks[1]['blockName']);
});

it('pattern_blocks on empty content is an empty array', function () {
    assert_eq([], wpultra_gb_pattern_blocks(''));
});

run_tests();
