<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/analyze.php';

it('perfect-ish page scores high and keyword checks pass', function () {
    $data = [
        'title' => 'Best Blue Widgets Guide', 'meta_description' => str_repeat('Blue widgets are great. ', 6),
        'focus_keyword' => 'blue widgets', 'h1' => 'Best Blue Widgets',
        'first_paragraph' => 'Blue widgets are the best widgets you can buy.',
        'body_text' => str_repeat('blue widgets are useful and blue widgets help. ', 40),
        'slug' => 'best-blue-widgets', 'internal_links' => 3, 'external_links' => 1,
        'images_total' => 2, 'images_missing_alt' => 0,
    ];
    $r = wpultra_seo_score($data);
    assert_true($r['score'] >= 70, 'score should be high, got ' . $r['score']);
    $byId = [];
    foreach ($r['checks'] as $c) { $byId[$c['id']] = $c['status']; }
    assert_eq('pass', $byId['keyword_in_title']);
    assert_eq('pass', $byId['keyword_in_h1']);
    assert_eq('pass', $byId['keyword_in_first_paragraph']);
});

it('missing keyword + no meta scores low with fails', function () {
    $data = [
        'title' => 'Untitled', 'meta_description' => '', 'focus_keyword' => 'blue widgets',
        'h1' => 'Hello', 'first_paragraph' => 'Welcome to my site.', 'body_text' => 'Some short text.',
        'slug' => 'hello', 'internal_links' => 0, 'external_links' => 0, 'images_total' => 1, 'images_missing_alt' => 1,
    ];
    $r = wpultra_seo_score($data);
    assert_true($r['score'] < 50, 'score should be low, got ' . $r['score']);
    $byId = [];
    foreach ($r['checks'] as $c) { $byId[$c['id']] = $c['status']; }
    assert_eq('fail', $byId['keyword_in_title']);
    assert_eq('fail', $byId['has_meta_description']);
    assert_eq('fail', $byId['images_have_alt']);
});

it('word count is unicode-aware for Bengali content', function () {
    // 5 Bengali "words". str_word_count() returns 0 here; the /u splitter must count 5.
    $bn = 'আমার সোনার বাংলা আমি তোমায়';
    assert_eq(5, wpultra_seo_word_count($bn));
    $data = [
        'title' => 'x', 'meta_description' => '', 'focus_keyword' => '',
        'h1' => '', 'first_paragraph' => '', 'body_text' => $bn,
        'slug' => 'x', 'internal_links' => 0, 'external_links' => 0, 'images_total' => 0, 'images_missing_alt' => 0,
    ];
    $r = wpultra_seo_score($data);
    $byId = [];
    foreach ($r['checks'] as $c) { $byId[$c['id']] = $c['message']; }
    assert_contains('(5)', $byId['content_length']); // word count reflected, not (0)
});

run_tests();
