<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_feed/'); }
// helpers.php provides wpultra_ok / wpultra_err / wpultra_audit_log (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/content/feed-import.php';

/* ============================================================
 * parse_xml — RSS <item>
 * ============================================================ */

$RSS = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel>
    <title>Example Blog</title>
    <item>
      <title>First Post</title>
      <link>https://example.com/first?utm_source=feed</link>
      <guid isPermaLink="false">urn:uuid:1111</guid>
      <description>Short summary one.</description>
      <content:encoded><![CDATA[<p>Full <a href="https://x.com/?fbclid=abc">body</a> here.</p>]]></content:encoded>
      <dc:creator>Jane Doe</dc:creator>
      <pubDate>Tue, 01 Jul 2025 12:00:00 +0000</pubDate>
    </item>
    <item>
      <title>Second Post</title>
      <link>https://example.com/second</link>
      <description>Second summary.</description>
    </item>
  </channel>
</rss>
XML;

it('parse_xml extracts RSS <item> title/link/guid/content/date/author', function () use ($RSS) {
    $items = wpultra_feed_parse_xml($RSS);
    assert_eq(2, count($items), 'two items parsed');
    assert_eq('First Post', $items[0]['title']);
    assert_eq('urn:uuid:1111', $items[0]['guid']);
    assert_contains('Full', $items[0]['content'], 'content:encoded used as body');
    assert_eq('Jane Doe', $items[0]['author'], 'dc:creator mapped to author');
    assert_contains('example.com/first', $items[0]['link']);
    assert_true($items[0]['date'] !== '', 'pubDate captured');
});

it('parse_xml tolerates missing fields on an RSS item', function () use ($RSS) {
    $items = wpultra_feed_parse_xml($RSS);
    assert_eq('Second Post', $items[1]['title']);
    assert_eq('', $items[1]['guid'], 'missing guid → empty string');
    assert_eq('', $items[1]['author'], 'missing author tolerated');
});

/* ============================================================
 * parse_xml — Atom <entry>
 * ============================================================ */

$ATOM = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Atom Example</title>
  <entry>
    <title>Atom One</title>
    <link rel="alternate" href="https://example.org/atom-one"/>
    <id>tag:example.org,2025:atom-one</id>
    <updated>2025-07-01T10:00:00Z</updated>
    <summary>Atom summary.</summary>
    <content type="html">&lt;p&gt;Atom body.&lt;/p&gt;</content>
    <author><name>Atom Author</name></author>
  </entry>
</feed>
XML;

it('parse_xml extracts Atom <entry> title/link/guid/content/date/author', function () use ($ATOM) {
    $items = wpultra_feed_parse_xml($ATOM);
    assert_eq(1, count($items), 'one entry parsed');
    assert_eq('Atom One', $items[0]['title']);
    assert_eq('https://example.org/atom-one', $items[0]['link'], 'rel=alternate href chosen');
    assert_eq('tag:example.org,2025:atom-one', $items[0]['guid']);
    assert_contains('Atom body', $items[0]['content']);
    assert_eq('Atom Author', $items[0]['author'], 'nested author/name mapped');
    assert_true($items[0]['date'] !== '', 'updated captured as date');
});

it('parse_xml falls back to summary when Atom entry has no content', function () {
    $xml = '<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>T</title><summary>Only summary.</summary></entry></feed>';
    $items = wpultra_feed_parse_xml($xml);
    assert_eq('Only summary.', $items[0]['content'], 'summary used when content absent');
});

it('parse_xml on malformed XML returns [] (not fatal)', function () {
    assert_eq([], wpultra_feed_parse_xml('<rss><channel><item>oops'));
    assert_eq([], wpultra_feed_parse_xml('not xml at all'));
    assert_eq([], wpultra_feed_parse_xml(''));
});

/* ============================================================
 * item_hash
 * ============================================================ */

it('item_hash prefers guid, then link, then title+date', function () {
    $withGuid = wpultra_feed_item_hash(['guid' => 'G1', 'link' => 'L1', 'title' => 'T', 'date' => 'D']);
    $sameGuidDiffLink = wpultra_feed_item_hash(['guid' => 'G1', 'link' => 'OTHER', 'title' => 'X', 'date' => 'Y']);
    assert_eq($withGuid, $sameGuidDiffLink, 'guid drives the hash when present');

    $noGuid = wpultra_feed_item_hash(['guid' => '', 'link' => 'L1', 'title' => 'T', 'date' => 'D']);
    $sameLink = wpultra_feed_item_hash(['link' => 'L1', 'title' => 'DIFF', 'date' => 'DIFF']);
    assert_eq($noGuid, $sameLink, 'link drives the hash when no guid');

    $titleDate = wpultra_feed_item_hash(['title' => 'Hello', 'date' => '2025']);
    $titleDate2 = wpultra_feed_item_hash(['title' => 'Hello', 'date' => '2025']);
    assert_eq($titleDate, $titleDate2, 'title+date stable');
    $diff = wpultra_feed_item_hash(['title' => 'Hello', 'date' => '2026']);
    assert_true($titleDate !== $diff, 'different date → different hash');
});

it('item_hash is a stable non-empty string', function () {
    $h = wpultra_feed_item_hash(['guid' => 'abc']);
    assert_true(is_string($h) && strlen($h) === 64, 'sha256 hex');
});

/* ============================================================
 * filter_new
 * ============================================================ */

it('filter_new drops already-seen items and keeps new ones in order', function () {
    $items = [
        ['guid' => 'A', 'title' => 'a'],
        ['guid' => 'B', 'title' => 'b'],
        ['guid' => 'C', 'title' => 'c'],
    ];
    $seen = [wpultra_feed_item_hash(['guid' => 'B'])];
    $new = wpultra_feed_filter_new($items, $seen);
    assert_eq(2, count($new));
    assert_eq('A', $new[0]['guid']);
    assert_eq('C', $new[1]['guid'], 'order preserved, B dropped');
});

it('filter_new keeps everything when seen set is empty', function () {
    $items = [['guid' => 'A'], ['guid' => 'B']];
    assert_eq(2, count(wpultra_feed_filter_new($items, [])));
});

it('seen_merge dedupes and caps to the newest', function () {
    $seen = ['h1', 'h2', 'h3'];
    $merged = wpultra_feed_seen_merge($seen, ['h3', 'h4'], 3);
    assert_eq(3, count($merged), 'capped at 3');
    assert_eq(['h2', 'h3', 'h4'], $merged, 'oldest dropped, dupes removed');
});

/* ============================================================
 * to_postarr
 * ============================================================ */

it('to_postarr defaults to draft and maps title/content/excerpt/meta', function () {
    $item = ['title' => 'My Item', 'content' => '<p>Body text</p>', 'summary' => 'A summary here.', 'link' => 'https://src.example/post', 'guid' => 'G9'];
    $arr = wpultra_feed_to_postarr($item, ['source_feed' => 'My Feed']);
    assert_eq('draft', $arr['post_status'], 'draft by default');
    assert_eq('My Item', $arr['post_title']);
    assert_contains('Body text', $arr['post_content']);
    assert_contains('summary', $arr['post_excerpt']);
    assert_eq('post', $arr['post_type']);
    assert_eq('https://src.example/post', $arr['meta_input']['wpultra_feed_source_url']);
    assert_eq('My Feed', $arr['meta_input']['wpultra_feed_source_feed']);
    assert_eq('G9', $arr['meta_input']['wpultra_feed_guid']);
});

it('to_postarr adds an attribution line only when opted in', function () {
    $item = ['title' => 'X', 'content' => 'Body', 'link' => 'https://src.example/a'];
    $without = wpultra_feed_to_postarr($item, []);
    assert_true(!str_contains($without['post_content'], 'wpultra-feed-attribution'), 'no attribution by default');

    $with = wpultra_feed_to_postarr($item, ['attribution' => true, 'source_feed' => 'Src']);
    assert_contains('wpultra-feed-attribution', $with['post_content']);
    assert_contains('https://src.example/a', $with['post_content']);
});

it('to_postarr honors an explicit publish status and content_override', function () {
    $arr = wpultra_feed_to_postarr(['title' => 'T', 'content' => 'orig'], ['post_status' => 'publish', 'content_override' => '<p>rewritten</p>']);
    assert_eq('publish', $arr['post_status']);
    assert_contains('rewritten', $arr['post_content']);
    assert_true(!str_contains($arr['post_content'], 'orig'), 'override replaces original body');
});

it('to_postarr falls back to a placeholder title', function () {
    $arr = wpultra_feed_to_postarr(['content' => 'x'], []);
    assert_contains('untitled', $arr['post_title']);
});

/* ============================================================
 * clean_content
 * ============================================================ */

it('clean_content strips <script>/<style>/<iframe> but keeps <p>/<a>', function () {
    $html = '<p>Hello <a href="https://ok.example">link</a></p><script>alert(1)</script><style>.x{}</style>';
    $out = wpultra_feed_clean_content($html);
    assert_contains('<p>Hello', $out);
    assert_contains('<a href="https://ok.example"', $out);
    assert_true(!str_contains($out, '<script'), 'script removed');
    assert_true(!str_contains($out, 'alert(1)'), 'script body removed');
    assert_true(!str_contains($out, '<style'), 'style removed');
});

it('clean_content strips inline event handlers and javascript: URIs', function () {
    $out = wpultra_feed_clean_content('<a href="javascript:evil()" onclick="x()">bad</a>');
    assert_true(!str_contains($out, 'onclick'), 'onclick removed');
    assert_true(!str_contains($out, 'javascript:'), 'javascript URI neutralized');
});

it('clean_content strips tracking params from links', function () {
    $out = wpultra_feed_clean_content('<a href="https://e.com/p?id=5&utm_source=feed&fbclid=zzz&keep=1">t</a>');
    assert_contains('id=5', $out);
    assert_contains('keep=1', $out);
    assert_true(!str_contains($out, 'utm_source'), 'utm_ dropped');
    assert_true(!str_contains($out, 'fbclid'), 'fbclid dropped');
});

it('strip_tracking_params preserves fragment and non-tracking params', function () {
    $u = wpultra_feed_strip_tracking_params('https://e.com/x?a=1&utm_medium=x&b=2#sec');
    assert_eq('https://e.com/x?a=1&b=2#sec', $u);
    // No query → unchanged.
    assert_eq('https://e.com/x', wpultra_feed_strip_tracking_params('https://e.com/x'));
});

/* ============================================================
 * rewrite_prompt
 * ============================================================ */

it('rewrite_prompt returns {system,user} referencing original wording and tone', function () {
    $p = wpultra_feed_rewrite_prompt(['title' => 'Big News', 'content' => 'Some content.', 'link' => 'https://n.example/x'], 'friendly');
    assert_true(isset($p['system']) && isset($p['user']), 'both keys present');
    assert_contains('ORIGINAL', $p['system']);
    assert_contains('friendly', $p['system'], 'tone injected');
    assert_contains('Big News', $p['user'], 'title in user prompt');
    assert_contains('Some content.', $p['user'], 'content in user prompt');
});

it('rewrite_prompt defaults tone to neutral', function () {
    $p = wpultra_feed_rewrite_prompt(['title' => 'T'], '');
    assert_contains('neutral', $p['system']);
});

/* ============================================================
 * default_record / make_id / is_valid_url
 * ============================================================ */

it('default_record has draft status, auto_import off, deterministic id', function () {
    $r = wpultra_feed_default_record('https://feed.example/rss', 'Feed X');
    assert_eq('draft', $r['post_status']);
    assert_eq(false, $r['auto_import']);
    assert_eq(false, $r['rewrite']);
    assert_eq('Feed X', $r['name']);
    assert_eq(wpultra_feed_make_id('https://feed.example/rss'), $r['id'], 'id derived from url');
    assert_eq([], $r['seen']);
});

it('is_valid_url accepts http(s) with a host and rejects junk', function () {
    assert_true(wpultra_feed_is_valid_url('https://example.com/feed'));
    assert_true(wpultra_feed_is_valid_url('http://sub.example.org/rss.xml'));
    assert_true(!wpultra_feed_is_valid_url('ftp://example.com/x'), 'ftp rejected');
    assert_true(!wpultra_feed_is_valid_url('not a url'), 'plain text rejected');
    assert_true(!wpultra_feed_is_valid_url('https://'), 'no host rejected');
});

it('excerpt strips tags and caps word count', function () {
    $long = str_repeat('word ', 100);
    $ex = wpultra_feed_excerpt('<p>' . $long . '</p>', 10);
    assert_true(str_ends_with($ex, '…'), 'ellipsis appended when truncated');
    assert_eq(10, count(explode(' ', str_replace('…', '', trim($ex)))), '10 words kept');
});

run_tests();
