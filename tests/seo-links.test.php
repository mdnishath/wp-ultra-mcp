<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/links.php';

it('wraps first unlinked occurrence of the anchor', function () {
    $r = wpultra_seo_wrap_anchor('<p>Buy blue widgets today. Blue widgets rock.</p>', 'blue widgets', 'http://x.test/bw');
    assert_eq(true, $r['inserted']);
    assert_true(strpos($r['content'], '<a href="http://x.test/bw">blue widgets</a>') !== false);
    // only the first occurrence wrapped
    assert_eq(1, substr_count($r['content'], '<a href='));
});

it('does not double-wrap an already-linked anchor', function () {
    $html = '<p>See <a href="http://y/">blue widgets</a> here. blue widgets again.</p>';
    $r = wpultra_seo_wrap_anchor($html, 'blue widgets', 'http://x.test/bw');
    // the already-linked first occurrence is skipped; the second (plain) is wrapped
    assert_eq(true, $r['inserted']);
    assert_eq(2, substr_count($r['content'], '<a href='));
});

it('reports not-inserted when anchor absent', function () {
    $r = wpultra_seo_wrap_anchor('<p>Nothing here.</p>', 'blue widgets', 'http://x/bw');
    assert_eq(false, $r['inserted']);
    assert_eq('<p>Nothing here.</p>', $r['content']);
});

it('ranks candidates by term + keyword overlap', function () {
    $source = ['keywords' => ['blue', 'widgets']];
    $cands = [
        ['id' => 1, 'title' => 'A', 'terms' => ['x'], 'keywords' => ['blue', 'widgets']],
        ['id' => 2, 'title' => 'B', 'terms' => ['x'], 'keywords' => ['red']],
        ['id' => 3, 'title' => 'C', 'terms' => ['x'], 'keywords' => ['widgets']],
    ];
    $r = wpultra_seo_rank_candidates($source, $cands);
    assert_eq(1, $r[0]['id']); // most overlap first
    assert_eq(2, count($r));    // id=2 (zero overlap) dropped
});

run_tests();
