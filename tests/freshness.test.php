<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_freshness/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/content/freshness.php';

$DAY = 86400;

/* ============================================================
 * word_count
 * ============================================================ */

it('word_count returns 0 for empty', function () {
    assert_eq(0, wpultra_fresh_word_count(''));
});

it('word_count counts plain words', function () {
    assert_eq(5, wpultra_fresh_word_count('one two three four five'));
});

it('word_count strips HTML tags', function () {
    assert_eq(3, wpultra_fresh_word_count('<p>hello <strong>brave</strong> world</p>'));
});

it('word_count strips Gutenberg block comments', function () {
    $html = '<!-- wp:paragraph --><p>alpha beta gamma</p><!-- /wp:paragraph -->';
    assert_eq(3, wpultra_fresh_word_count($html));
});

it('word_count strips shortcodes', function () {
    assert_eq(2, wpultra_fresh_word_count('[gallery ids="1,2,3"] hello world [/gallery]'));
});

it('word_count is multibyte-safe', function () {
    // Three unicode words.
    assert_eq(3, wpultra_fresh_word_count('café naïve résumé'));
    assert_eq(2, wpultra_fresh_word_count('日本語 テスト'));
});

it('word_count decodes entities so nbsp does not glue words', function () {
    assert_eq(2, wpultra_fresh_word_count('hello&nbsp;world'));
});

it('word_count returns 0 for markup-only content', function () {
    assert_eq(0, wpultra_fresh_word_count('<img src="x.jpg" /><!-- wp:image --><!-- /wp:image -->'));
});

/* ============================================================
 * reading_time
 * ============================================================ */

it('reading_time is 0 for no words', function () {
    assert_eq(0, wpultra_fresh_reading_time(0));
});

it('reading_time is at least 1 minute for any content', function () {
    assert_eq(1, wpultra_fresh_reading_time(10));
    assert_eq(1, wpultra_fresh_reading_time(200));
});

it('reading_time scales at ~200 wpm', function () {
    assert_eq(2, wpultra_fresh_reading_time(201));
    assert_eq(5, wpultra_fresh_reading_time(1000));
});

/* ============================================================
 * score — staleness by age
 * ============================================================ */

it('score marks very stale for >2y since modified', function () use ($DAY) {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now - 800 * $DAY, 'word_count' => 1200, 'images' => 3, 'internal_links' => 4, 'has_meta_desc' => true], $now);
    assert_eq(100, $s['stale_score']);
    assert_eq('very_stale', $s['age_bucket']);
});

it('score marks stale for >1y', function () use ($DAY) {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now - 400 * $DAY, 'word_count' => 1200, 'images' => 3, 'internal_links' => 4, 'has_meta_desc' => true], $now);
    assert_eq(75, $s['stale_score']);
    assert_eq('stale', $s['age_bucket']);
});

it('score marks aging for >6mo', function () use ($DAY) {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now - 200 * $DAY, 'word_count' => 1200, 'images' => 3, 'internal_links' => 4, 'has_meta_desc' => true], $now);
    assert_eq(50, $s['stale_score']);
    assert_eq('aging', $s['age_bucket']);
});

it('score marks fresh for <3mo', function () use ($DAY) {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now - 30 * $DAY, 'word_count' => 1200, 'images' => 3, 'internal_links' => 4, 'has_meta_desc' => true], $now);
    assert_eq(0, $s['stale_score']);
    assert_eq('fresh', $s['age_bucket']);
    assert_eq('low', $s['priority']);
});

it('score boosts staleness for stale high-traffic posts', function () use ($DAY) {
    $now = 1_700_000_000;
    $base = wpultra_fresh_score(['modified_ts' => $now - 400 * $DAY, 'word_count' => 1200, 'images' => 3, 'internal_links' => 4, 'has_meta_desc' => true], $now);
    $boosted = wpultra_fresh_score(['modified_ts' => $now - 400 * $DAY, 'word_count' => 1200, 'images' => 3, 'internal_links' => 4, 'has_meta_desc' => true, 'traffic' => 5000], $now);
    assert_true($boosted['stale_score'] > $base['stale_score'], 'high traffic raises stale score');
});

/* ============================================================
 * score — thinness
 * ============================================================ */

it('score flags very thin under 300 words', function () {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now, 'word_count' => 150, 'images' => 2, 'internal_links' => 2, 'has_meta_desc' => true], $now);
    $codes = array_column($s['reasons'], 'code');
    assert_true(in_array('very_thin', $codes, true), 'very_thin reason present');
    assert_true($s['thin_score'] >= 50, 'thin score high');
});

it('score flags thin under 600 words', function () {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now, 'word_count' => 450, 'images' => 2, 'internal_links' => 2, 'has_meta_desc' => true], $now);
    $codes = array_column($s['reasons'], 'code');
    assert_true(in_array('thin', $codes, true), 'thin reason present');
});

it('score penalises no images', function () {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now, 'word_count' => 1200, 'images' => 0, 'internal_links' => 2, 'has_meta_desc' => true], $now);
    $codes = array_column($s['reasons'], 'code');
    assert_true(in_array('no_images', $codes, true), 'no_images reason present');
});

it('score penalises no internal links', function () {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now, 'word_count' => 1200, 'images' => 2, 'internal_links' => 0, 'has_meta_desc' => true], $now);
    $codes = array_column($s['reasons'], 'code');
    assert_true(in_array('no_internal_links', $codes, true), 'no_internal_links reason present');
});

it('score penalises missing meta description', function () {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now, 'word_count' => 1200, 'images' => 2, 'internal_links' => 2, 'has_meta_desc' => false], $now);
    $codes = array_column($s['reasons'], 'code');
    assert_true(in_array('no_meta_desc', $codes, true), 'no_meta_desc reason present');
});

it('score gives a clean fresh post a low thin_score', function () {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now, 'word_count' => 1500, 'images' => 3, 'internal_links' => 5, 'has_meta_desc' => true], $now);
    assert_eq(0, $s['thin_score']);
    assert_eq([], $s['reasons']);
});

/* ============================================================
 * score — priority
 * ============================================================ */

it('score priority is high for old and thin', function () use ($DAY) {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now - 800 * $DAY, 'word_count' => 200, 'images' => 0, 'internal_links' => 0, 'has_meta_desc' => false], $now);
    assert_eq('high', $s['priority']);
});

it('score priority is low for a fresh full post', function () {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now, 'word_count' => 1500, 'images' => 3, 'internal_links' => 5, 'has_meta_desc' => true], $now);
    assert_eq('low', $s['priority']);
});

it('score defaults age to now when no timestamps given', function () {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['word_count' => 1500, 'images' => 3, 'internal_links' => 5, 'has_meta_desc' => true], $now);
    assert_eq(0, $s['age_days']);
    assert_eq(0, $s['stale_score']);
});

/* ============================================================
 * suggest_actions
 * ============================================================ */

it('suggest_actions maps each reason to a to-do', function () use ($DAY) {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now - 800 * $DAY, 'word_count' => 200, 'images' => 0, 'internal_links' => 0, 'has_meta_desc' => false], $now);
    $todo = wpultra_fresh_suggest_actions($s);
    $codes = array_column($todo, 'code');
    assert_true(in_array('no_meta_desc', $codes, true), 'meta-desc to-do');
    assert_true(in_array('very_thin', $codes, true), 'expand to-do');
    assert_true(in_array('no_internal_links', $codes, true), 'internal-links to-do');
    assert_true(in_array('no_images', $codes, true), 'image to-do');
    assert_true(in_array('very_stale', $codes, true), 'update-year to-do');
    assert_true(in_array('add_faq', $codes, true), 'faq to-do for thin/stale');
});

it('suggest_actions is empty for a fresh full post', function () {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now, 'word_count' => 1500, 'images' => 3, 'internal_links' => 5, 'has_meta_desc' => true], $now);
    $todo = wpultra_fresh_suggest_actions($s);
    assert_eq([], $todo);
});

it('suggest_actions handles missing reasons key', function () {
    $todo = wpultra_fresh_suggest_actions([]);
    assert_eq([], $todo);
});

/* ============================================================
 * report
 * ============================================================ */

it('report ranks by priority', function () {
    $scored = [
        ['id' => 1, 'priority' => 'low',    'stale_score' => 0,   'thin_score' => 0],
        ['id' => 2, 'priority' => 'high',   'stale_score' => 100, 'thin_score' => 50],
        ['id' => 3, 'priority' => 'medium', 'stale_score' => 50,  'thin_score' => 20],
    ];
    $r = wpultra_fresh_report($scored);
    assert_eq('high', $r['posts'][0]['priority']);
    assert_eq('medium', $r['posts'][1]['priority']);
    assert_eq('low', $r['posts'][2]['priority']);
});

it('report ties break by combined score descending', function () {
    $scored = [
        ['id' => 1, 'priority' => 'high', 'stale_score' => 75,  'thin_score' => 10],
        ['id' => 2, 'priority' => 'high', 'stale_score' => 100, 'thin_score' => 50],
    ];
    $r = wpultra_fresh_report($scored);
    assert_eq(2, $r['posts'][0]['id']);
});

it('report computes summary counts', function () {
    $scored = [
        ['id' => 1, 'priority' => 'high',   'stale_score' => 100, 'thin_score' => 50],
        ['id' => 2, 'priority' => 'medium', 'stale_score' => 50,  'thin_score' => 20],
        ['id' => 3, 'priority' => 'low',    'stale_score' => 0,   'thin_score' => 0],
    ];
    $r = wpultra_fresh_report($scored);
    assert_eq(3, $r['summary']['total']);
    assert_eq(1, $r['summary']['high']);
    assert_eq(1, $r['summary']['medium']);
    assert_eq(1, $r['summary']['low']);
    assert_eq(2, $r['summary']['stale']);
    assert_eq(1, $r['summary']['thin']);
    assert_eq(1, $r['summary']['fresh']);
    assert_eq(2, $r['summary']['needs_attention']);
});

it('report handles empty input', function () {
    $r = wpultra_fresh_report([]);
    assert_eq(0, $r['summary']['total']);
    assert_eq([], $r['posts']);
});

/* ============================================================
 * ai_prompt shape
 * ============================================================ */

it('ai_prompt returns system and user strings', function () use ($DAY) {
    $now = 1_700_000_000;
    $s = wpultra_fresh_score(['modified_ts' => $now - 800 * $DAY, 'word_count' => 200, 'images' => 0, 'internal_links' => 0, 'has_meta_desc' => false], $now);
    $p = wpultra_fresh_ai_prompt(['title' => 'Old SEO Guide', 'excerpt' => 'A short intro.'], $s);
    assert_true(is_string($p['system']) && $p['system'] !== '', 'system present');
    assert_true(is_string($p['user']) && $p['user'] !== '', 'user present');
    assert_contains('JSON', $p['system']);
    assert_contains('Old SEO Guide', $p['user']);
});

/* ============================================================
 * parse_suggestions
 * ============================================================ */

it('parse_suggestions parses clean JSON', function () {
    $json = '{"title":"New Title","intro":"Fresh intro.","sections":["A","B","C"],"notes":["do x"]}';
    $r = wpultra_fresh_parse_suggestions($json);
    assert_true($r['parsed'], 'parsed flag');
    assert_eq('New Title', $r['title']);
    assert_eq('Fresh intro.', $r['intro']);
    assert_eq(3, count($r['sections']));
    assert_eq(1, count($r['notes']));
});

it('parse_suggestions parses fenced JSON', function () {
    $json = "Here you go:\n```json\n{\"title\":\"T\",\"intro\":\"I\",\"sections\":[\"x\"],\"notes\":[]}\n```\nDone.";
    $r = wpultra_fresh_parse_suggestions($json);
    assert_true($r['parsed'], 'parsed');
    assert_eq('T', $r['title']);
});

it('parse_suggestions extracts object from surrounding prose', function () {
    $json = 'Sure! {"title":"Z","intro":"","sections":[],"notes":[]} hope that helps';
    $r = wpultra_fresh_parse_suggestions($json);
    assert_true($r['parsed'], 'parsed');
    assert_eq('Z', $r['title']);
});

it('parse_suggestions returns empty shape for garbage', function () {
    $r = wpultra_fresh_parse_suggestions('this is not json at all');
    assert_true($r['parsed'] === false, 'not parsed');
    assert_eq('', $r['title']);
    assert_eq([], $r['sections']);
});

it('parse_suggestions returns empty shape for empty string', function () {
    $r = wpultra_fresh_parse_suggestions('');
    assert_true($r['parsed'] === false, 'not parsed');
});

it('parse_suggestions filters non-string section entries', function () {
    $json = '{"title":"T","intro":"I","sections":["ok", 5, "", "also"],"notes":[null,"n"]}';
    $r = wpultra_fresh_parse_suggestions($json);
    assert_eq(['ok', 'also'], $r['sections']);
    assert_eq(['n'], $r['notes']);
});

run_tests();
