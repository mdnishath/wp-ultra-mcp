<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_linkgraph/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/seo/linkgraph.php';

/* ------------------------------------------------------------
 * Fixtures.
 * ------------------------------------------------------------ */

// A small site: 1 links 2, 1 links 3, 2 links 3. Node 4 is an orphan+dead-end.
function lg_fixture(): array {
    return [
        ['id' => 1, 'title' => 'Guide to Coffee Beans', 'keywords' => ['coffee', 'beans', 'roast'], 'outbound_internal' => [2, 3]],
        ['id' => 2, 'title' => 'Espresso Machines',      'keywords' => ['espresso', 'machine'],       'outbound_internal' => [3]],
        ['id' => 3, 'title' => 'Best Coffee Grinders',   'keywords' => ['coffee', 'grinder'],          'outbound_internal' => []],
        ['id' => 4, 'title' => 'Cold Brew Coffee Recipe','keywords' => ['coffee', 'cold', 'brew'],     'outbound_internal' => []],
    ];
}

/* ============================================================
 * build — nodes inbound/outbound + edges.
 * ============================================================ */

it('build counts inbound and outbound per node', function () {
    $g = wpultra_lgraph_build(lg_fixture());
    assert_eq(4, count($g['nodes']), 'four nodes');
    assert_eq(2, $g['nodes'][1]['outbound'], 'node 1 outbound');
    assert_eq(0, $g['nodes'][1]['inbound'], 'node 1 inbound');
    assert_eq(1, $g['nodes'][2]['inbound'], 'node 2 inbound (from 1)');
    assert_eq(1, $g['nodes'][2]['outbound'], 'node 2 outbound (to 3)');
    assert_eq(2, $g['nodes'][3]['inbound'], 'node 3 inbound (from 1 and 2)');
    assert_eq(0, $g['nodes'][3]['outbound'], 'node 3 outbound');
    assert_eq('Espresso Machines', $g['nodes'][2]['title']);
});

it('build records directed edges and dedupes / drops self + unknown targets', function () {
    $posts = [
        ['id' => 1, 'title' => 'A', 'outbound_internal' => [2, 2, 1, 99]], // dup, self, unknown
        ['id' => 2, 'title' => 'B', 'outbound_internal' => []],
    ];
    $g = wpultra_lgraph_build($posts);
    assert_eq([[1, 2]], $g['edges'], 'one clean edge only');
    assert_eq(1, $g['nodes'][1]['outbound']);
    assert_eq(1, $g['nodes'][2]['inbound']);
});

it('build skips posts with non-positive ids', function () {
    $g = wpultra_lgraph_build([['id' => 0, 'title' => 'x'], ['id' => 5, 'title' => 'y']]);
    assert_eq(1, count($g['nodes']));
    assert_true(isset($g['nodes'][5]));
});

/* ============================================================
 * orphans / dead_ends.
 * ============================================================ */

it('orphans finds 0-inbound nodes and excludes linked ones', function () {
    $g = wpultra_lgraph_build(lg_fixture());
    $ids = array_column(wpultra_lgraph_orphans($g), 'id');
    sort($ids);
    assert_eq([1, 4], $ids, 'nodes 1 and 4 have no inbound; 2 and 3 excluded');
});

it('dead_ends finds 0-outbound nodes', function () {
    $g = wpultra_lgraph_build(lg_fixture());
    $ids = array_column(wpultra_lgraph_dead_ends($g), 'id');
    sort($ids);
    assert_eq([3, 4], $ids, 'nodes 3 and 4 have no outbound');
});

/* ============================================================
 * hubs — ranked by inbound desc.
 * ============================================================ */

it('hubs ranks nodes by inbound descending', function () {
    $g = wpultra_lgraph_build(lg_fixture());
    $hubs = wpultra_lgraph_hubs($g);
    // Node 3 (2 inbound) first, then 2 (1 inbound), then 1 and 4 (0) by id.
    assert_eq(3, $hubs[0]['id']);
    assert_eq(2, $hubs[0]['inbound']);
    assert_eq(2, $hubs[1]['id']);
    assert_eq(1, $hubs[1]['inbound']);
    // Remaining two are the zero-inbound nodes, id-ascending.
    assert_eq(1, $hubs[2]['id']);
    assert_eq(4, $hubs[3]['id']);
});

/* ============================================================
 * over_linked — outbound > max.
 * ============================================================ */

it('over_linked returns nodes above max outbound', function () {
    $posts = [
        ['id' => 1, 'title' => 'A', 'outbound_internal' => [2, 3, 4]],
        ['id' => 2, 'title' => 'B', 'outbound_internal' => [3]],
        ['id' => 3, 'title' => 'C', 'outbound_internal' => []],
        ['id' => 4, 'title' => 'D', 'outbound_internal' => []],
    ];
    $g = wpultra_lgraph_build($posts);
    $over2 = array_column(wpultra_lgraph_over_linked($g, 2), 'id');
    assert_eq([1], $over2, 'only node 1 (3 outbound) exceeds max=2');
    assert_eq([], wpultra_lgraph_over_linked($g, 3), 'nothing exceeds max=3');
});

/* ============================================================
 * token_overlap — Jaccard, stopwords, case.
 * ============================================================ */

it('token_overlap: identical -> 1.0', function () {
    assert_eq(1.0, wpultra_lgraph_token_overlap(['coffee', 'beans'], ['coffee', 'beans']));
});

it('token_overlap: disjoint -> 0.0', function () {
    assert_eq(0.0, wpultra_lgraph_token_overlap(['coffee'], ['tea']));
});

it('token_overlap: partial overlap is a fraction', function () {
    // {coffee, beans} vs {coffee, grinder} => inter 1, union 3 => 1/3.
    $v = wpultra_lgraph_token_overlap(['coffee', 'beans'], ['coffee', 'grinder']);
    assert_true(abs($v - (1 / 3)) < 1e-9, 'expected ~0.3333, got ' . $v);
});

it('token_overlap: stopwords are ignored', function () {
    // 'the' is a stopword — it must not count toward union or intersection.
    $v = wpultra_lgraph_token_overlap(['the', 'coffee'], ['the', 'coffee']);
    assert_eq(1.0, $v);
    $v2 = wpultra_lgraph_token_overlap(['the', 'and'], ['the', 'and']);
    assert_eq(0.0, $v2, 'all-stopword lists have empty token sets');
});

it('token_overlap: case-insensitive', function () {
    assert_eq(1.0, wpultra_lgraph_token_overlap(['Coffee', 'BEANS'], ['coffee', 'beans']));
});

it('tokenize drops short tokens, stopwords, and punctuation', function () {
    $t = wpultra_lgraph_tokenize('The Best Coffee-Beans, in 2024!');
    // Normalize to strings: PHP array-key dedup coerces the numeric token '2024' to int 2024.
    $t = array_map('strval', $t);
    sort($t);
    // 'the'/'in' stopwords; 'coffee','beans','best','2024' kept (>=3 chars).
    assert_eq(['2024', 'beans', 'best', 'coffee'], $t);
});

/* ============================================================
 * suggest_links — the site-wide value.
 * ============================================================ */

it('suggest_links matches orphans to overlapping sources, excluding self + existing links', function () {
    $posts = lg_fixture();
    $g = wpultra_lgraph_build($posts);
    $sugg = wpultra_lgraph_suggest_links($posts, $g, 5);

    // Orphans are 1 and 4. Every suggestion targets an orphan.
    foreach ($sugg as $s) {
        assert_true(in_array($s['target_id'], [1, 4], true), 'target is an orphan');
        assert_true($s['source_id'] !== $s['target_id'], 'no self-link');
    }

    // Node 4 (cold brew coffee) should get node 1 or 3 as a source via "coffee" overlap.
    $forFour = array_values(array_filter($sugg, static fn($s) => $s['target_id'] === 4));
    assert_true(count($forFour) >= 1, 'orphan 4 gets at least one source');
    $sources4 = array_column($forFour, 'source_id');
    assert_true(in_array(1, $sources4, true) || in_array(3, $sources4, true), 'coffee source suggested for 4');

    // Anchor suggestion comes from the target title.
    foreach ($forFour as $s) {
        assert_eq('Cold Brew Coffee Recipe', $s['anchor_suggestion']);
    }
});

it('suggest_links excludes a source that already links to the target', function () {
    // Make node 4 orphan but have node 1 already link to it; node 3 also overlaps.
    $posts = [
        ['id' => 1, 'title' => 'Coffee Guide', 'keywords' => ['coffee'], 'outbound_internal' => [4]],
        ['id' => 3, 'title' => 'Coffee Grinders', 'keywords' => ['coffee'], 'outbound_internal' => []],
        ['id' => 4, 'title' => 'Cold Brew Coffee', 'keywords' => ['coffee'], 'outbound_internal' => []],
    ];
    $g = wpultra_lgraph_build($posts);
    // Node 4 has inbound 1 (from node 1) -> not an orphan anymore, so no suggestions target it.
    $sugg = wpultra_lgraph_suggest_links($posts, $g, 5);
    $targets = array_column($sugg, 'target_id');
    assert_true(!in_array(4, $targets, true), 'node 4 already has inbound, not orphan-targeted');
});

it('suggest_links respects the per_post cap', function () {
    // Orphan target 10 overlaps with many sources.
    $posts = [
        ['id' => 10, 'title' => 'Coffee Roasting', 'keywords' => ['coffee', 'roast'], 'outbound_internal' => []],
        ['id' => 11, 'title' => 'Coffee Beans A', 'keywords' => ['coffee'], 'outbound_internal' => []],
        ['id' => 12, 'title' => 'Coffee Beans B', 'keywords' => ['coffee'], 'outbound_internal' => []],
        ['id' => 13, 'title' => 'Coffee Beans C', 'keywords' => ['coffee'], 'outbound_internal' => []],
    ];
    $g = wpultra_lgraph_build($posts);
    $sugg = wpultra_lgraph_suggest_links($posts, $g, 2);
    // 11,12,13 are also orphans; but for target 10 specifically, at most 2 sources.
    $forTen = array_filter($sugg, static fn($s) => $s['target_id'] === 10);
    assert_true(count($forTen) <= 2, 'per_post cap honored for target 10');
});

/* ============================================================
 * report — shape.
 * ============================================================ */

it('report returns the link-health snapshot shape', function () {
    $g = wpultra_lgraph_build(lg_fixture());
    $r = wpultra_lgraph_report($g);
    foreach (['total_posts', 'total_links', 'orphans', 'dead_ends', 'avg_outbound', 'top_hubs', 'over_linked'] as $k) {
        assert_true(array_key_exists($k, $r), "report has key $k");
    }
    assert_eq(4, $r['total_posts']);
    assert_eq(3, $r['total_links']);
    assert_eq(2, $r['orphans'], 'nodes 1 and 4');
    assert_eq(2, $r['dead_ends'], 'nodes 3 and 4');
    // avg outbound = (2+1+0+0)/4 = 0.75.
    assert_true(abs($r['avg_outbound'] - 0.75) < 1e-9, 'avg_outbound 0.75, got ' . $r['avg_outbound']);
    assert_true(is_array($r['top_hubs']));
    assert_eq(3, $r['top_hubs'][0]['id'], 'top hub is node 3');
});

it('report on an empty graph is safe', function () {
    $r = wpultra_lgraph_report(wpultra_lgraph_build([]));
    assert_eq(0, $r['total_posts']);
    assert_eq(0.0, $r['avg_outbound']);
    assert_eq([], $r['top_hubs']);
});

it('boot is callable and returns void', function () {
    wpultra_lgraph_boot();
    assert_true(true, 'boot ran without error');
});

run_tests();
