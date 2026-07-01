<?php
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../wp-ultra-mcp/includes/seo/audit.php';

it('audit_post flags missing title, short desc, thin content, missing alt, noindex', function () {
    $issues = wpultra_seo_audit_post(['seo_title' => '', 'seo_desc' => 'short', 'focus_keyword' => '', 'word_count' => 100, 'images_missing_alt' => 2, 'noindex' => true]);
    $codes = array_map(function ($i) { return $i['code']; }, $issues);
    assert_true(in_array('missing_seo_title', $codes, true));
    assert_true(in_array('meta_description_too_short', $codes, true));
    assert_true(in_array('missing_focus_keyword', $codes, true));
    assert_true(in_array('thin_content', $codes, true));
    assert_true(in_array('missing_image_alt', $codes, true));
    assert_true(in_array('noindex_set', $codes, true));
});

it('audit_post is clean for a good post', function () {
    $issues = wpultra_seo_audit_post(['seo_title' => 'A good SEO title here', 'seo_desc' => str_repeat('x', 140), 'focus_keyword' => 'widgets', 'word_count' => 800, 'images_missing_alt' => 0, 'noindex' => false]);
    assert_eq(0, count($issues));
});

it('expand_template replaces tokens', function () {
    $r = wpultra_seo_expand_template('%title% %sep% %sitename%', ['title' => 'Post', 'sep' => '|', 'sitename' => 'Site']);
    assert_eq('Post | Site', $r);
});

run_tests();
