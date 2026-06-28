<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/setup.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/tree.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/blueprints.php';

function all_ids(array $nodes, array &$acc): void {
    foreach ($nodes as $n) {
        if (!is_array($n)) { continue; }
        if (isset($n['id'])) { $acc[] = $n['id']; }
        if (!empty($n['elements']) && is_array($n['elements'])) { all_ids($n['elements'], $acc); }
    }
}

it('library exposes the 5 named blueprints, each with a tree', function () {
    $b = wpultra_el_blueprints();
    assert_eq(['navbar', 'hero', 'feature-grid', 'cta', 'footer'], array_keys($b));
    foreach ($b as $name => $bp) {
        assert_true(!empty($bp['description']) && !empty($bp['summary']), "$name has description+summary");
        assert_true(is_array($bp['tree']) && $bp['tree'] !== [], "$name has a tree");
        assert_eq('e-flexbox', $bp['tree'][0]['elType']);
    }
});

it('hero blueprint has the documented structure', function () {
    $hero = wpultra_el_blueprints()['hero']['tree'];
    $kids = $hero[0]['elements'];
    assert_eq(['e-heading', 'e-paragraph', 'e-button'], array_map(fn($n) => $n['widgetType'], $kids));
    assert_eq('h1', $kids[0]['settings']['tag']);            // raw scalar tag
    assert_true(is_string($kids[0]['settings']['title']), 'title is a raw scalar');
});

it('reid replaces every id with a unique fresh id and preserves structure', function () {
    $tree = wpultra_el_blueprints()['feature-grid']['tree'];
    $out = wpultra_el_blueprint_reid($tree);
    $ids = []; all_ids($out, $ids);
    assert_true(!in_array('bp', $ids, true), 'no placeholder bp ids remain');
    assert_eq(count($ids), count(array_unique($ids)), 'all ids unique');
    // structure preserved: same widget types in the same shape
    assert_eq('e-flexbox', $out[0]['elType']);
    assert_eq(3, count($out[0]['elements']));               // 3 columns
});

it('reid avoids ids already on the page', function () {
    $existing = [['id' => 'aaaaaaa', 'elType' => 'e-flexbox', 'elements' => [['id' => 'bbbbbbb', 'elType' => 'widget', 'elements' => []]]]];
    $out = wpultra_el_blueprint_reid(wpultra_el_blueprints()['cta']['tree'], $existing);
    $ids = []; all_ids($out, $ids);
    assert_true(!in_array('aaaaaaa', $ids, true) && !in_array('bbbbbbb', $ids, true), 'no collision with existing ids');
});

run_tests();
