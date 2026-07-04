<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_ai_kb/'); }
// helpers.php provides wpultra_err / wpultra_ok. setup.php provides wpultra_ai_cosine
// (used by the rank cosine path). kb.php is the engine under test.
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/ai/setup.php';
require __DIR__ . '/../wp-ultra-mcp/includes/ai/kb.php';

/* ============================================================
 * clean_html — strip tags/script/style, decode, collapse ws.
 * ============================================================ */

it('clean_html strips script and style contents entirely', function () {
    $out = wpultra_kb_clean_html('<p>Hello</p><script>alert(1)</script><style>.x{color:red}</style><b>World</b>');
    assert_eq('Hello World', $out);
});

it('clean_html strips all tags and collapses whitespace', function () {
    $out = wpultra_kb_clean_html("<div>  foo   \n\n  <span>bar</span>  </div>");
    assert_eq('foo bar', $out);
});

it('clean_html decodes HTML entities', function () {
    $out = wpultra_kb_clean_html('<p>Ben &amp; Jerry&#8217;s &nbsp; ice cream</p>');
    assert_contains('Ben & Jerry', $out);
    assert_true(!str_contains($out, '&amp;'), 'ampersand entity decoded');
});

it('clean_html on empty input returns empty string', function () {
    assert_eq('', wpultra_kb_clean_html(''));
});

/* ============================================================
 * chunk_text — cap, overlap, no mid-word split, edges.
 * ============================================================ */

it('chunk_text returns [] for empty/whitespace input', function () {
    assert_eq([], wpultra_kb_chunk_text(''));
    assert_eq([], wpultra_kb_chunk_text("   \n  \t "));
});

it('chunk_text returns a single chunk for tiny input', function () {
    $out = wpultra_kb_chunk_text('Just a short sentence.', 1000, 100);
    assert_eq(1, count($out));
    assert_eq('Just a short sentence.', $out[0]);
});

it('chunk_text respects the max_chars cap on every chunk', function () {
    $word = 'lorem ';
    $text = trim(str_repeat($word, 400)); // ~2400 chars
    $chunks = wpultra_kb_chunk_text($text, 200, 40);
    assert_true(count($chunks) > 1, 'splits into multiple chunks');
    foreach ($chunks as $c) {
        // ASCII input, so byte length == char length here.
        assert_true(strlen($c) <= 200, 'chunk within cap: len=' . strlen($c));
    }
});

it('chunk_text never splits mid-word', function () {
    $text = trim(str_repeat('alpha bravo charlie delta echo ', 30));
    $chunks = wpultra_kb_chunk_text($text, 120, 20);
    $known = ['alpha', 'bravo', 'charlie', 'delta', 'echo'];
    foreach ($chunks as $c) {
        foreach (explode(' ', $c) as $tok) {
            if ($tok === '') { continue; }
            assert_true(in_array($tok, $known, true), "token is a whole word: '$tok'");
        }
    }
});

it('chunk_text produces overlapping consecutive chunks', function () {
    // Distinct numbered words so overlap is unambiguous (no repeated tokens).
    $words = [];
    for ($i = 0; $i < 120; $i++) { $words[] = 'w' . $i; }
    $text = implode(' ', $words);
    $chunks = wpultra_kb_chunk_text($text, 150, 50);
    assert_true(count($chunks) >= 2, 'multiple chunks');
    // The tail of chunk[0] should share at least one word with the head of chunk[1].
    $tailWords = array_slice(explode(' ', $chunks[0]), -8);
    $headWords = array_slice(explode(' ', $chunks[1]), 0, 8);
    $shared = array_intersect($tailWords, $headWords);
    assert_true(count($shared) > 0, 'consecutive chunks overlap by at least one word');
});

/* ============================================================
 * keyword_score — overlap, boost, case-insensitivity.
 * ============================================================ */

it('keyword_score is 0 when there is no overlap', function () {
    assert_eq(0.0, wpultra_kb_keyword_score('refund policy', 'purple monkey dishwasher'));
});

it('keyword_score is case-insensitive', function () {
    $a = wpultra_kb_keyword_score('Refund Policy', 'our refund policy is generous');
    $b = wpultra_kb_keyword_score('refund policy', 'OUR REFUND POLICY IS GENEROUS');
    assert_true($a > 0.0, 'scores above zero');
    assert_eq($a, $b, 'case does not change the score');
});

it('keyword_score boosts repeated exact terms', function () {
    $few = wpultra_kb_keyword_score('shipping', 'shipping details here');
    $many = wpultra_kb_keyword_score('shipping', 'shipping shipping shipping fast shipping');
    assert_true($many > $few, 'more occurrences score higher');
});

it('keyword_score ignores stopwords in the query', function () {
    // "the" and "is" are stopwords; only "warranty" should drive the score.
    $s = wpultra_kb_keyword_score('what is the warranty', 'the warranty is one year');
    assert_true($s > 0.0, 'matches on the content word');
});

/* ============================================================
 * rank — cosine path, keyword path, null skip, cap, empty.
 * ============================================================ */

$mkChunk = static function (string $id, string $text, ?array $emb): array {
    return ['id' => $id, 'post_id' => (int) $id, 'title' => "T$id", 'url' => "https://x/$id", 'text' => $text, 'embedding' => $emb];
};

it('rank returns [] for empty chunks', function () {
    assert_eq([], wpultra_kb_rank([], [1.0, 0.0], 'q', 3));
});

it('rank cosine path picks the nearest vector', function () use ($mkChunk) {
    $chunks = [
        $mkChunk('1', 'far', [0.0, 1.0]),
        $mkChunk('2', 'near', [1.0, 0.1]),
        $mkChunk('3', 'mid', [0.7, 0.7]),
    ];
    $out = wpultra_kb_rank($chunks, [1.0, 0.0], 'anything', 1);
    assert_eq(1, count($out));
    assert_eq('2', $out[0]['id'], 'closest to the query vector wins');
});

it('rank cosine path skips chunks with null or mismatched embeddings', function () use ($mkChunk) {
    $chunks = [
        $mkChunk('1', 'no embedding', null),
        $mkChunk('2', 'wrong length', [1.0, 0.0, 0.0]),
        $mkChunk('3', 'good', [0.9, 0.1]),
    ];
    $out = wpultra_kb_rank($chunks, [1.0, 0.0], 'q', 5);
    assert_eq(1, count($out), 'only the valid-embedding chunk ranks');
    assert_eq('3', $out[0]['id']);
});

it('rank keyword path used when no query vector', function () use ($mkChunk) {
    $chunks = [
        $mkChunk('1', 'nothing relevant here', null),
        $mkChunk('2', 'refund refund refund policy', null),
        $mkChunk('3', 'a single refund mention', null),
    ];
    $out = wpultra_kb_rank($chunks, null, 'refund policy', 2);
    assert_eq(2, count($out), 'top_k cap applied');
    assert_eq('2', $out[0]['id'], 'strongest keyword match ranks first');
});

it('rank caps results to top_k', function () use ($mkChunk) {
    $chunks = [];
    for ($i = 1; $i <= 6; $i++) { $chunks[] = $mkChunk((string) $i, "term$i term", null); }
    $out = wpultra_kb_rank($chunks, null, 'term', 3);
    assert_eq(3, count($out));
});

it('rank output rows carry id/post_id/title/url/text/score', function () use ($mkChunk) {
    $out = wpultra_kb_rank([$mkChunk('7', 'hello world', [1.0, 0.0])], [1.0, 0.0], 'q', 1);
    $row = $out[0];
    foreach (['id', 'post_id', 'title', 'url', 'text', 'score'] as $k) {
        assert_true(array_key_exists($k, $row), "row has key $k");
    }
    assert_eq(7, $row['post_id']);
    assert_eq('https://x/7', $row['url']);
});

/* ============================================================
 * build_prompt — numbered context, question, "only from context".
 * ============================================================ */

it('build_prompt numbers the context and includes the question', function () {
    $ctx = [
        ['title' => 'Shipping', 'text' => 'We ship in 3 days.'],
        ['title' => 'Returns', 'text' => 'Returns within 30 days.'],
    ];
    $p = wpultra_kb_build_prompt('How long is shipping?', $ctx);
    assert_true(is_array($p) && isset($p['system'], $p['user']));
    assert_contains('[1]', $p['user']);
    assert_contains('[2]', $p['user']);
    assert_contains('We ship in 3 days.', $p['user']);
    assert_contains('How long is shipping?', $p['user']);
});

it('build_prompt system message forbids outside knowledge', function () {
    $p = wpultra_kb_build_prompt('q', [['title' => 't', 'text' => 'x']]);
    $sys = strtolower($p['system']);
    assert_true(str_contains($sys, 'only from') || str_contains($sys, 'only the'), 'system says only from context');
    assert_contains('context', $sys);
});

/* ============================================================
 * widget_html — escapes hostile input, contains rest path.
 * ============================================================ */

it('widget_html escapes a hostile title and greeting', function () {
    $html = wpultra_kb_widget_html([
        'rest_url' => 'https://site.test/wp-json/wpultra/v1/chat',
        'title'    => '<script>alert("xss")</script>',
        'greeting' => '<img src=x onerror=alert(1)>',
    ]);
    // The raw injection must not appear verbatim in the head/greeting output.
    assert_true(!str_contains($html, '<script>alert("xss")</script>'), 'title script not raw');
    assert_true(!str_contains($html, '<img src=x onerror=alert(1)>'), 'greeting img not raw');
    // The escaped form should be present.
    assert_contains('&lt;script&gt;', $html);
});

it('widget_html embeds the rest chat path', function () {
    $html = wpultra_kb_widget_html(['rest_url' => 'https://site.test/wp-json/wpultra/v1/chat']);
    assert_contains('wpultra/v1/chat', $html);
    assert_contains('wpultra-chat-panel', $html);
});

run_tests();
