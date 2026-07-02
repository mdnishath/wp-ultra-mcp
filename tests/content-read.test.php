<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/content/engine.php';

it('build_query_args defaults post_type to post, per_page to 20, order to DESC', function () {
    $args = wpultra_content_build_query_args([]);
    assert_eq('post', $args['post_type']);
    assert_eq(20, $args['posts_per_page']);
    assert_eq(1, $args['paged']);
    assert_eq('DESC', $args['order']);
    assert_eq('date', $args['orderby']);
});

it('build_query_args honors post_type=any, custom status, search, orderby/order', function () {
    $args = wpultra_content_build_query_args([
        'post_type' => 'any',
        'status'    => 'publish',
        'search'    => 'hello',
        'orderby'   => 'title',
        'order'     => 'asc',
    ]);
    assert_eq('any', $args['post_type']);
    assert_eq('publish', $args['post_status']);
    assert_eq('hello', $args['s']);
    assert_eq('title', $args['orderby']);
    assert_eq('ASC', $args['order']);
});

it('build_query_args caps per_page at 100 and floors invalid values to defaults', function () {
    $args = wpultra_content_build_query_args(['per_page' => 500]);
    assert_eq(100, $args['posts_per_page']);

    $args2 = wpultra_content_build_query_args(['per_page' => -5, 'page' => -1]);
    assert_eq(20, $args2['posts_per_page']);
    assert_eq(1, $args2['paged']);
});

it('build_query_args wires meta_key/meta_value and simplified tax_query', function () {
    $args = wpultra_content_build_query_args([
        'meta_key'   => 'color',
        'meta_value' => 'blue',
        'tax_query'  => ['taxonomy' => 'category', 'terms' => ['news', 'updates']],
    ]);
    assert_eq('color', $args['meta_key']);
    assert_eq('blue', $args['meta_value']);
    assert_eq('category', $args['tax_query'][0]['taxonomy']);
    assert_eq(['news', 'updates'], $args['tax_query'][0]['terms']);
});

it('build_query_args omits tax_query when taxonomy or terms missing', function () {
    $args = wpultra_content_build_query_args(['tax_query' => ['taxonomy' => 'category']]);
    assert_true(!array_key_exists('tax_query', $args));
});

it('total_pages computes ceil division and floors at 1', function () {
    assert_eq(1, wpultra_content_total_pages(0, 20));
    assert_eq(1, wpultra_content_total_pages(5, 20));
    assert_eq(2, wpultra_content_total_pages(21, 20));
    assert_eq(3, wpultra_content_total_pages(60, 20));
    assert_eq(1, wpultra_content_total_pages(10, 0));
});

it('trim_excerpt strips tags, collapses whitespace, truncates at 160 with ellipsis', function () {
    $short = wpultra_content_trim_excerpt('<p>Hello   world</p>');
    assert_eq('Hello world', $short);

    $long = str_repeat('word ', 60); // 300 chars
    $trimmed = wpultra_content_trim_excerpt($long, 160);
    // Byte-length bound is generous: without mbstring the '…' ellipsis itself
    // costs up to 3 bytes, so allow the 160-char cap plus a small margin.
    assert_true(strlen($trimmed) <= 165, 'trimmed length within bound + ellipsis');
    assert_true(str_ends_with($trimmed, '…'), 'ends with ellipsis');
});

it('shape_list_row never carries post_content and maps expected fields', function () {
    $row = wpultra_content_shape_list_row([
        'id' => 5, 'title' => 'Hi', 'slug' => 'hi', 'status' => 'publish',
        'type' => 'post', 'date' => '2026-01-01 00:00:00', 'modified' => '2026-01-02 00:00:00',
        'author' => 2, 'excerpt' => 'body text here', 'edit_link' => 'http://x/wp-admin/post.php?post=5',
    ]);
    assert_eq(5, $row['id']);
    assert_eq('Hi', $row['title']);
    assert_eq('body text here', $row['excerpt']);
    assert_true(!array_key_exists('post_content', $row), 'no raw post_content key');
    assert_true(!array_key_exists('content', $row), 'no content key either');
});

it('filter_meta drops underscore-prefixed keys unless include_private is true', function () {
    $meta = ['title_alt' => 'x', '_elementor_data' => '{}', 'color' => 'blue'];
    $public = wpultra_content_filter_meta($meta, false);
    assert_true(array_key_exists('title_alt', $public));
    assert_true(array_key_exists('color', $public));
    assert_true(!array_key_exists('_elementor_data', $public));

    $all = wpultra_content_filter_meta($meta, true);
    assert_true(array_key_exists('_elementor_data', $all));
});

it('group_terms_by_taxonomy buckets flat term rows by taxonomy', function () {
    $grouped = wpultra_content_group_terms_by_taxonomy([
        ['taxonomy' => 'category', 'id' => 1, 'name' => 'News'],
        ['taxonomy' => 'category', 'id' => 2, 'name' => 'Updates'],
        ['taxonomy' => 'post_tag', 'id' => 3, 'name' => 'Featured'],
    ]);
    assert_eq(2, count($grouped['category']));
    assert_eq(1, count($grouped['post_tag']));
    assert_true(!array_key_exists('taxonomy', $grouped['category'][0]), 'taxonomy key stripped from row');
});

it('snippet finds the query and returns a radius window with ellipses on both sides', function () {
    $content = str_repeat('lorem ipsum dolor sit amet consectetur adipiscing elit ', 5) . 'NEEDLE' . str_repeat(' more filler text here', 10);
    $snippet = wpultra_content_snippet($content, 'NEEDLE', 20);
    assert_contains('NEEDLE', $snippet);
    assert_true(str_starts_with($snippet, '…'), 'leading ellipsis when truncated at start');
    assert_true(str_ends_with($snippet, '…'), 'trailing ellipsis when truncated at end');
});

it('snippet is case-insensitive and strips HTML tags first', function () {
    $content = '<p>Some <strong>Hello World</strong> intro text padding padding padding</p>';
    $snippet = wpultra_content_snippet($content, 'hello world', 10);
    assert_contains('Hello World', $snippet);
    assert_true(!str_contains($snippet, '<strong>'), 'tags stripped before matching');
});

it('snippet falls back to a leading trim when the query has no match', function () {
    $content = str_repeat('abc def ghi ', 30);
    $snippet = wpultra_content_snippet($content, 'zzz-not-present', 20);
    assert_true($snippet !== '', 'non-empty fallback');
    assert_true(!str_contains($snippet, 'zzz-not-present'));
});

it('snippet on empty content returns empty string', function () {
    assert_eq('', wpultra_content_snippet('', 'anything', 20));
    assert_eq('', wpultra_content_snippet('   ', 'anything', 20));
});

it('build_duplicate_postarr defaults new_title to "<title> (Copy)" and status to draft', function () {
    $postarr = wpultra_content_build_duplicate_postarr([
        'title' => 'Original', 'content' => 'body', 'excerpt' => 'exc', 'post_type' => 'post',
    ], []);
    assert_eq('Original (Copy)', $postarr['post_title']);
    assert_eq('draft', $postarr['post_status']);
    assert_eq('post', $postarr['post_type']);
    assert_eq('body', $postarr['post_content']);
});

it('build_duplicate_postarr honors explicit new_title and new_status', function () {
    $postarr = wpultra_content_build_duplicate_postarr([
        'title' => 'Original', 'content' => '', 'excerpt' => '', 'post_type' => 'page',
    ], ['new_title' => 'Custom Name', 'new_status' => 'publish']);
    assert_eq('Custom Name', $postarr['post_title']);
    assert_eq('publish', $postarr['post_status']);
});

it('build_duplicate_postarr copies parent/menu_order/comment_status/author when present', function () {
    $postarr = wpultra_content_build_duplicate_postarr([
        'title' => 'T', 'content' => '', 'excerpt' => '', 'post_type' => 'post',
        'post_parent' => 9, 'menu_order' => 3, 'comment_status' => 'open', 'ping_status' => 'open',
        'post_author' => 7,
    ], []);
    assert_eq(9, $postarr['post_parent']);
    assert_eq(3, $postarr['menu_order']);
    assert_eq('open', $postarr['comment_status']);
    assert_eq('open', $postarr['ping_status']);
    assert_eq(7, $postarr['post_author']);
});

it('duplicate_meta_keys drops reserved WP-managed keys but keeps everything else', function () {
    $keys = ['_elementor_data', '_wp_old_slug', 'color', '_edit_lock', '_thumbnail_id', '_edit_last'];
    $kept = wpultra_content_duplicate_meta_keys($keys);
    assert_true(in_array('_elementor_data', $kept, true), 'elementor data kept');
    assert_true(in_array('color', $kept, true), 'custom meta kept');
    assert_true(in_array('_thumbnail_id', $kept, true), 'thumbnail id kept');
    assert_true(!in_array('_wp_old_slug', $kept, true), 'old slug dropped');
    assert_true(!in_array('_edit_lock', $kept, true), 'edit lock dropped');
    assert_true(!in_array('_edit_last', $kept, true), 'edit last dropped');
});

run_tests();
