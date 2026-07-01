<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/research.php';

it('keyword gaps splits covered vs gaps', function () {
    $cands = ['blue widgets', 'red widgets', 'green widgets'];
    $index = [
        ['post_id' => 1, 'title' => 'Best Blue Widgets', 'focus_keyword' => 'blue widgets', 'title_lc' => 'best blue widgets'],
        ['post_id' => 2, 'title' => 'Red Widget Guide', 'focus_keyword' => '', 'title_lc' => 'red widget guide'],
    ];
    $r = wpultra_seo_keyword_gaps($cands, $index);
    $coveredKw = array_map(function ($c) { return $c['keyword']; }, $r['covered']);
    assert_true(in_array('blue widgets', $coveredKw, true)); // focus keyword match
    assert_true(in_array('green widgets', $r['gaps'], true)); // no page
});

it('competitor compare finds missing headings/keywords + word delta', function () {
    $ours = ['title' => 'Mine', 'headings' => ['Intro', 'Pricing'], 'word_count' => 400, 'keywords' => ['blue']];
    $theirs = ['title' => 'Theirs', 'headings' => ['Intro', 'Pricing', 'FAQ'], 'word_count' => 1000, 'keywords' => ['blue', 'cheap']];
    $r = wpultra_seo_competitor_compare($ours, $theirs);
    assert_true(in_array('FAQ', $r['missing_headings'], true));
    assert_true(in_array('cheap', $r['missing_keywords'], true));
    assert_eq(-600, $r['word_count_delta']);
});

run_tests();
