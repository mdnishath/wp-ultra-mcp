<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_linkfix/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/linkfix.php';

/* ============================================================
 * wpultra_lf_extract_hrefs
 * ============================================================ */

it('extract_hrefs finds double-quoted hrefs', function () {
    $html = '<p><a href="https://example.com/a">A</a> and <a href="/local/">B</a></p>';
    assert_eq(['https://example.com/a', '/local/'], wpultra_lf_extract_hrefs($html));
});

it('extract_hrefs finds single-quoted hrefs', function () {
    $html = "<a href='/one/'>1</a><a href='mailto:x@y.z'>2</a>";
    assert_eq(['/one/', 'mailto:x@y.z'], wpultra_lf_extract_hrefs($html));
});

it('extract_hrefs de-dupes while preserving first-seen order', function () {
    $html = '<a href="/a/">1</a><a href="/b/">2</a><a href="/a/">3</a>';
    assert_eq(['/a/', '/b/'], wpultra_lf_extract_hrefs($html));
});

it('extract_hrefs does NOT match data-href', function () {
    $html = '<div data-href="/fake/">x</div>';
    assert_eq([], wpultra_lf_extract_hrefs($html));
    // Real href alongside a data-href still found.
    $html2 = '<a data-href="/fake/" href="/real/">y</a>';
    assert_eq(['/real/'], wpultra_lf_extract_hrefs($html2));
});

it('extract_hrefs skips empty hrefs and returns [] for href-less html', function () {
    assert_eq([], wpultra_lf_extract_hrefs('<a href="">x</a>'));
    assert_eq([], wpultra_lf_extract_hrefs('<p>no links here</p>'));
    assert_eq([], wpultra_lf_extract_hrefs(''));
});

it('extract_hrefs tolerates whitespace around = and mixed case HREF', function () {
    $html = '<a HREF = "/spaced/">x</a>';
    assert_eq(['/spaced/'], wpultra_lf_extract_hrefs($html));
});

/* ============================================================
 * wpultra_lf_classify
 * ============================================================ */

it('classify: relative path is internal with normalized path', function () {
    $c = wpultra_lf_classify('/about-us/', 'example.com');
    assert_eq('internal', $c['type']);
    assert_eq('/about-us/', $c['path']);
    // No leading slash → one is added.
    $c2 = wpultra_lf_classify('services/plumbing/', 'example.com');
    assert_eq('internal', $c2['type']);
    assert_eq('/services/plumbing/', $c2['path']);
});

it('classify: absolute same-host URL is internal, query stripped from path', function () {
    $c = wpultra_lf_classify('https://example.com/blog/post-1/?utm=x#frag', 'example.com');
    assert_eq('internal', $c['type']);
    assert_eq('/blog/post-1/', $c['path']);
    // Host comparison is case-insensitive.
    $c2 = wpultra_lf_classify('https://EXAMPLE.com/x/', 'example.com');
    assert_eq('internal', $c2['type']);
});

it('classify: other-host URL is external', function () {
    assert_eq('external', wpultra_lf_classify('https://other.com/page/', 'example.com')['type']);
    // Protocol-relative other host too.
    assert_eq('external', wpultra_lf_classify('//cdn.other.com/lib.js', 'example.com')['type']);
});

it('classify: protocol-relative same host is internal', function () {
    $c = wpultra_lf_classify('//example.com/pricing/', 'example.com');
    assert_eq('internal', $c['type']);
    assert_eq('/pricing/', $c['path']);
});

it('classify: #anchor / mailto / tel / javascript / data', function () {
    assert_eq('anchor', wpultra_lf_classify('#top', 'example.com')['type']);
    assert_eq('mailto', wpultra_lf_classify('mailto:me@example.com', 'example.com')['type']);
    assert_eq('other', wpultra_lf_classify('tel:+8801700000000', 'example.com')['type']);
    assert_eq('other', wpultra_lf_classify('javascript:void(0)', 'example.com')['type']);
    assert_eq('other', wpultra_lf_classify('data:text/plain;base64,eA==', 'example.com')['type']);
});

it('classify: empty and bare-query hrefs are other', function () {
    assert_eq('other', wpultra_lf_classify('', 'example.com')['type']);
    assert_eq('other', wpultra_lf_classify('?page=2', 'example.com')['type']);
});

it('classify: bare same-host URL (no path) is internal with path /', function () {
    $c = wpultra_lf_classify('https://example.com', 'example.com');
    assert_eq('internal', $c['type']);
    assert_eq('/', $c['path']);
});

/* ============================================================
 * wpultra_lf_skip_path
 * ============================================================ */

it('skip_path skips core endpoints, feeds, assets — keeps content paths', function () {
    assert_true(wpultra_lf_skip_path('/wp-content/uploads/x.png'));
    assert_true(wpultra_lf_skip_path('/wp-admin/'));
    assert_true(wpultra_lf_skip_path('/wp-json/wp/v2/posts'));
    assert_true(wpultra_lf_skip_path('/wp-login.php'));
    assert_true(wpultra_lf_skip_path('/blog/feed/'));
    assert_true(wpultra_lf_skip_path('/docs/manual.pdf'));
    assert_true(wpultra_lf_skip_path('/'));
    assert_true(!wpultra_lf_skip_path('/about-us/'));
    assert_true(!wpultra_lf_skip_path('/blog/hello-world/'));
});

/* ============================================================
 * wpultra_lf_norm_path + wpultra_lf_last_segment
 * ============================================================ */

it('norm_path matches the seo store normalization semantics', function () {
    assert_eq('/', wpultra_lf_norm_path(''));
    assert_eq('/a/', wpultra_lf_norm_path('a'));
    assert_eq('/a/b/', wpultra_lf_norm_path('/A/B/'));
    assert_eq('/a/', wpultra_lf_norm_path('/a///'));
});

it('last_segment strips query/fragment and returns the final segment', function () {
    assert_eq('hello-world', wpultra_lf_last_segment('/blog/hello-world/'));
    assert_eq('hello-world', wpultra_lf_last_segment('/hello-world?utm=x#f'));
    assert_eq('', wpultra_lf_last_segment('/'));
    assert_eq('', wpultra_lf_last_segment(''));
});

/* ============================================================
 * wpultra_lf_top_paths (pure fallback for wpultra_404_top)
 * ============================================================ */

it('top_paths aggregates by path, sorts hits desc, ties by most-recent last', function () {
    $ring = [
        ['path' => '/a/', 'ts' => '2026-07-01 10:00:00'],
        ['path' => '/b/', 'ts' => '2026-07-02 10:00:00'],
        ['path' => '/a/', 'ts' => '2026-07-03 10:00:00'],
        ['path' => '/c/', 'ts' => '2026-07-04 10:00:00'],
        ['path' => '',    'ts' => '2026-07-04 11:00:00'], // dropped
    ];
    $top = wpultra_lf_top_paths($ring);
    assert_eq('/a/', $top[0]['path']);
    assert_eq(2, $top[0]['hits']);
    assert_eq('2026-07-03 10:00:00', $top[0]['last']);
    // /b/ and /c/ both 1 hit — /c/ is more recent so it comes first.
    assert_eq('/c/', $top[1]['path']);
    assert_eq('/b/', $top[2]['path']);
});

/* ============================================================
 * wpultra_lf_similarity + wpultra_lf_rank
 * ============================================================ */

it('similarity: exact slug scores 100', function () {
    assert_eq(100.0, wpultra_lf_similarity('hello-world', 'hello-world'));
});

it('similarity: hyphen/underscore/case normalization makes equivalents exact', function () {
    assert_eq(100.0, wpultra_lf_similarity('Hello_World', 'hello-world'));
    assert_eq(100.0, wpultra_lf_similarity('hello world', 'HELLO-WORLD'));
});

it('similarity: near-miss scores high, garbage scores low, empty scores 0', function () {
    assert_true(wpultra_lf_similarity('hello-wrld', 'hello-world') >= 55.0, 'typo slug still >= 55');
    assert_true(wpultra_lf_similarity('zqxjk', 'hello-world') < 55.0, 'garbage < 55');
    assert_eq(0.0, wpultra_lf_similarity('', 'hello-world'));
    assert_eq(0.0, wpultra_lf_similarity('a', ''));
});

it('rank: exact slug ranks first at 100, near-miss above garbage', function () {
    $map = [
        ['post_id' => 1, 'slug' => 'contact', 'post_type' => 'page'],
        ['post_id' => 2, 'slug' => 'hello-world', 'post_type' => 'post'],
        ['post_id' => 3, 'slug' => 'hello-word', 'post_type' => 'post'],
    ];
    $ranked = wpultra_lf_rank($map, 'hello-world');
    assert_eq(2, $ranked[0]['post_id']);
    assert_eq(100.0, $ranked[0]['score']);
    assert_eq(3, $ranked[1]['post_id'], 'near-miss slug second');
    assert_eq(1, $ranked[2]['post_id'], 'unrelated slug last');
    assert_true($ranked[1]['score'] > $ranked[2]['score']);
});

it('rank: drops rows without a slug and handles an empty map', function () {
    $ranked = wpultra_lf_rank([['post_id' => 9], ['post_id' => 10, 'slug' => 'x']], 'x');
    assert_eq(1, count($ranked));
    assert_eq(10, $ranked[0]['post_id']);
    assert_eq([], wpultra_lf_rank([], 'anything'));
});

/* ============================================================
 * wpultra_lf_replace
 * ============================================================ */

it('replace: counts every exact occurrence and rewrites them all', function () {
    $c = '<a href="https://old.com/x/">a</a> text https://old.com/x/ <img src="https://old.com/x/">';
    $r = wpultra_lf_replace($c, 'https://old.com/x/', 'https://new.com/y/');
    assert_eq(3, $r['count']);
    assert_true(strpos($r['content'], 'https://old.com/x/') === false, 'no old URL left');
    assert_contains('href="https://new.com/y/"', $r['content']);
});

it('replace: zero occurrences, empty old, and old===new are no-ops', function () {
    $r = wpultra_lf_replace('nothing here', 'https://old.com/', 'https://new.com/');
    assert_eq(0, $r['count']);
    assert_eq('nothing here', $r['content']);
    assert_eq(0, wpultra_lf_replace('abc', '', 'x')['count']);
    assert_eq(0, wpultra_lf_replace('abc', 'abc', 'abc')['count']);
});

/* ============================================================
 * wpultra_lf_validate_redirect
 * ============================================================ */

it('validate_redirect accepts path→path, path→URL, path→post_id', function () {
    assert_eq(true, wpultra_lf_validate_redirect(['from' => '/old/', 'to' => '/new/']));
    assert_eq(true, wpultra_lf_validate_redirect(['from' => '/old/', 'to' => 'https://example.com/new/']));
    assert_eq(true, wpultra_lf_validate_redirect(['from' => '/old/', 'to' => 42]));
    assert_eq(true, wpultra_lf_validate_redirect(['from' => '/old/', 'to' => '42']));
});

it('validate_redirect rejects a from that is missing or not a path', function () {
    assert_true(is_string(wpultra_lf_validate_redirect(['to' => '/new/'])), 'missing from');
    assert_true(is_string(wpultra_lf_validate_redirect(['from' => 'old-page', 'to' => '/new/'])), 'no leading slash');
    assert_true(is_string(wpultra_lf_validate_redirect(['from' => '', 'to' => '/new/'])), 'empty from');
});

it('validate_redirect rejects an empty/garbage to and non-positive post_id', function () {
    assert_true(is_string(wpultra_lf_validate_redirect(['from' => '/old/'])), 'missing to');
    assert_true(is_string(wpultra_lf_validate_redirect(['from' => '/old/', 'to' => ''])), 'empty to');
    assert_true(is_string(wpultra_lf_validate_redirect(['from' => '/old/', 'to' => 'not a url'])), 'garbage to');
    assert_true(is_string(wpultra_lf_validate_redirect(['from' => '/old/', 'to' => 0])), 'post_id 0');
});

it('validate_redirect rejects a self-loop (normalized path equality)', function () {
    assert_true(is_string(wpultra_lf_validate_redirect(['from' => '/same/', 'to' => '/same/'])), 'identical');
    assert_true(is_string(wpultra_lf_validate_redirect(['from' => '/Same', 'to' => '/same/'])), 'case/slash-normalized loop');
    // Same path on ANOTHER field shape: to with a query but same path still loops.
    assert_true(is_string(wpultra_lf_validate_redirect(['from' => '/same/', 'to' => '/same/?v=2'])), 'query-only difference still loops');
});

it('validate_redirect: cross-checking norm against the seo store shape', function () {
    // The engine's norm must agree with wpultra_seo_norm_path semantics so the
    // pure pre-validation and wpultra_seo_add_redirect never disagree on loops.
    assert_eq(wpultra_lf_norm_path('/A/b'), '/a/b/');
    assert_eq(wpultra_lf_norm_path('a/b/'), '/a/b/');
});

run_tests();
