<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_seopilot/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/ai/seopilot.php';

/* ============================================================
 * clip — length, word boundary, no mid-entity cut.
 * ============================================================ */

it('clip leaves short strings unchanged', function () {
    assert_eq('Hello world', wpultra_seopilot_clip('Hello world', 60));
});

it('clip caps at max characters', function () {
    $out = wpultra_seopilot_clip(str_repeat('ab ', 60), 20);
    assert_true(wpultra_seopilot_strlen($out) <= 20, 'within limit');
});

it('clip breaks on a word boundary (no mid-word cut)', function () {
    $out = wpultra_seopilot_clip('The quickbrownfox jumped over lazy dogs', 12);
    // 'The quickbrownfox' is 17 chars; must not slice inside 'quickbrownfox'.
    assert_true(!str_contains($out, 'quickb') || str_contains($out, 'quickbrownfox') === false, 'boundary respected');
    assert_true(wpultra_seopilot_strlen($out) <= 12);
});

it('clip does not leave a dangling half entity', function () {
    // Force a cut right after an entity start.
    $out = wpultra_seopilot_clip('Cats &amp; Dogs living together forever', 7);
    assert_true(!preg_match('/&[a-z0-9#]*$/i', $out), 'no trailing partial entity: ' . $out);
});

it('clip collapses whitespace', function () {
    assert_eq('a b c', wpultra_seopilot_clip("a   b\n c", 60));
});

/* ============================================================
 * pick_targets — needs detection + disabled steps.
 * ============================================================ */

$allSteps = ['fix_meta' => true, 'internal_links' => true, 'schema' => true];

it('pick_targets: missing title becomes a meta target', function () use ($allSteps) {
    $audits = [['post_id' => 1, 'seo_title' => '', 'seo_desc' => str_repeat('x', 130), 'internal_links' => 3, 'has_schema' => true]];
    $t = wpultra_seopilot_pick_targets($audits, $allSteps);
    assert_eq(1, count($t));
    assert_true(in_array('meta', $t[0]['needs'], true), 'meta needed');
    assert_true(!in_array('links', $t[0]['needs'], true), 'links not needed');
    assert_true(!in_array('schema', $t[0]['needs'], true), 'schema not needed');
});

it('pick_targets: short description becomes a meta target', function () use ($allSteps) {
    $audits = [['post_id' => 2, 'seo_title' => 'A perfectly good title here', 'seo_desc' => 'too short', 'internal_links' => 1, 'has_schema' => true]];
    $t = wpultra_seopilot_pick_targets($audits, $allSteps);
    assert_true(in_array('meta', $t[0]['needs'], true), 'short desc → meta');
});

it('pick_targets: over-long title becomes a meta target', function () use ($allSteps) {
    $audits = [['post_id' => 3, 'seo_title' => str_repeat('word ', 20), 'seo_desc' => str_repeat('x', 130), 'internal_links' => 2, 'has_schema' => true]];
    $t = wpultra_seopilot_pick_targets($audits, $allSteps);
    assert_true(in_array('meta', $t[0]['needs'], true), 'long title → meta');
});

it('pick_targets: has-meta + no-links becomes a links target only', function () use ($allSteps) {
    $audits = [['post_id' => 4, 'seo_title' => 'Good title', 'seo_desc' => str_repeat('x', 130), 'internal_links' => 0, 'has_schema' => true]];
    $t = wpultra_seopilot_pick_targets($audits, $allSteps);
    assert_eq(['links'], $t[0]['needs']);
});

it('pick_targets: no schema becomes a schema target', function () use ($allSteps) {
    $audits = [['post_id' => 5, 'seo_title' => 'Good title', 'seo_desc' => str_repeat('x', 130), 'internal_links' => 2, 'has_schema' => false]];
    $t = wpultra_seopilot_pick_targets($audits, $allSteps);
    assert_eq(['schema'], $t[0]['needs']);
});

it('pick_targets: fully-healthy post is dropped', function () use ($allSteps) {
    $audits = [['post_id' => 6, 'seo_title' => 'Good title', 'seo_desc' => str_repeat('x', 130), 'internal_links' => 2, 'has_schema' => true]];
    assert_eq(0, count(wpultra_seopilot_pick_targets($audits, $allSteps)));
});

it('pick_targets: respects disabled steps', function () {
    // Post needs meta+links+schema, but only links is enabled.
    $audits = [['post_id' => 7, 'seo_title' => '', 'seo_desc' => '', 'internal_links' => 0, 'has_schema' => false]];
    $t = wpultra_seopilot_pick_targets($audits, ['fix_meta' => false, 'internal_links' => true, 'schema' => false]);
    assert_eq(['links'], $t[0]['needs']);
});

it('pick_targets: all steps disabled yields no targets', function () {
    $audits = [['post_id' => 8, 'seo_title' => '', 'seo_desc' => '', 'internal_links' => 0, 'has_schema' => false]];
    assert_eq(0, count(wpultra_seopilot_pick_targets($audits, ['fix_meta' => false, 'internal_links' => false, 'schema' => false])));
});

/* ============================================================
 * meta_prompt — fields embedded, limits stated.
 * ============================================================ */

it('meta_prompt embeds title/excerpt/keyword and states limits', function () {
    $p = wpultra_seopilot_meta_prompt(['title' => 'Best Coffee Beans', 'excerpt' => 'A guide to roasting.', 'focus_keyword' => 'coffee beans']);
    assert_true(isset($p['system'], $p['user']), 'both messages present');
    assert_contains('Best Coffee Beans', $p['user']);
    assert_contains('A guide to roasting.', $p['user']);
    assert_contains('coffee beans', $p['user']);
    assert_contains('60', $p['system']);   // title char limit
    assert_contains('155', $p['system']);  // desc char limit
    assert_contains('JSON', $p['system']);
});

it('meta_prompt handles empty fields gracefully', function () {
    $p = wpultra_seopilot_meta_prompt([]);
    assert_contains('(untitled)', $p['user']);
    assert_contains('(none)', $p['user']);
});

/* ============================================================
 * parse_meta — raw / fenced / prose-embedded / garbage.
 * ============================================================ */

it('parse_meta parses raw JSON', function () {
    $r = wpultra_seopilot_parse_meta('{"title":"Hi there","description":"A nice long description here."}');
    assert_true(is_array($r), 'array result');
    assert_eq('Hi there', $r['title']);
    assert_contains('nice long', $r['description']);
});

it('parse_meta parses a ```json fenced block', function () {
    $r = wpultra_seopilot_parse_meta("```json\n{\"title\":\"Fenced\",\"description\":\"Body copy.\"}\n```");
    assert_true(is_array($r));
    assert_eq('Fenced', $r['title']);
});

it('parse_meta extracts JSON embedded in prose', function () {
    $r = wpultra_seopilot_parse_meta('Sure! Here you go: {"title":"Embedded","description":"Yep."} Hope that helps.');
    assert_true(is_array($r));
    assert_eq('Embedded', $r['title']);
});

it('parse_meta clips over-long fields to the limits', function () {
    $long = str_repeat('word ', 60);
    $r = wpultra_seopilot_parse_meta('{"title":"' . $long . '","description":"' . $long . '"}');
    assert_true(wpultra_seopilot_strlen($r['title']) <= 60, 'title clipped');
    assert_true(wpultra_seopilot_strlen($r['description']) <= 155, 'desc clipped');
});

it('parse_meta returns an error string for garbage', function () {
    assert_true(is_string(wpultra_seopilot_parse_meta('not json at all')), 'garbage → string');
    assert_true(is_string(wpultra_seopilot_parse_meta('')), 'empty → string');
    assert_true(is_string(wpultra_seopilot_parse_meta('{"foo":"bar"}')), 'no title/desc → string');
});

/* ============================================================
 * fallback_meta — title→title, excerpt→description, empties.
 * ============================================================ */

it('fallback_meta uses title for title and excerpt for description', function () {
    $r = wpultra_seopilot_fallback_meta('My Great Post', 'This is the opening paragraph of the post.');
    assert_eq('My Great Post', $r['title']);
    assert_contains('opening paragraph', $r['description']);
});

it('fallback_meta falls back to title when excerpt is empty', function () {
    $r = wpultra_seopilot_fallback_meta('Only A Title', '');
    assert_eq('Only A Title', $r['title']);
    assert_eq('Only A Title', $r['description']);
});

it('fallback_meta clips within limits', function () {
    $r = wpultra_seopilot_fallback_meta(str_repeat('word ', 40), str_repeat('sentence ', 60));
    assert_true(wpultra_seopilot_strlen($r['title']) <= 60);
    assert_true(wpultra_seopilot_strlen($r['description']) <= 155);
});

/* ============================================================
 * summarize — rollup.
 * ============================================================ */

it('summarize counts applied stages and distinct audited posts', function () {
    $actions = [
        ['post_id' => 1, 'stage' => 'meta', 'action' => 'set_meta', 'applied' => true],
        ['post_id' => 1, 'stage' => 'links', 'action' => 'insert_link', 'applied' => true],
        ['post_id' => 2, 'stage' => 'schema', 'action' => 'set_schema', 'applied' => true],
        ['post_id' => 3, 'stage' => 'links', 'action' => 'noop', 'applied' => false],
    ];
    $s = wpultra_seopilot_summarize($actions);
    assert_eq(3, $s['audited']);
    assert_eq(1, $s['meta_fixed']);
    assert_eq(1, $s['links_added']);
    assert_eq(1, $s['schema_added']);
    assert_eq(1, $s['skipped']);
});

it('summarize: dry-run (not applied) does not count as fixed', function () {
    $actions = [['post_id' => 1, 'stage' => 'meta', 'action' => 'set_meta', 'applied' => false]];
    $s = wpultra_seopilot_summarize($actions);
    assert_eq(0, $s['meta_fixed']);
    assert_eq(1, $s['audited']);
});

/* ============================================================
 * interval mapper.
 * ============================================================ */

it('interval maps daily/weekly and defaults safely', function () {
    assert_eq('daily', wpultra_seopilot_interval('daily'));
    assert_eq('weekly', wpultra_seopilot_interval('weekly'));
    assert_eq('daily', wpultra_seopilot_interval('hourly'));
    assert_eq('daily', wpultra_seopilot_interval(''));
});

/* ============================================================
 * validate_config — enums, clamps, flags.
 * ============================================================ */

it('validate_config defaults to safe dry-run and disabled', function () {
    $c = wpultra_seopilot_validate_config([]);
    assert_true($c['dry_run_default'] === true, 'dry_run_default true');
    assert_true($c['enabled'] === false, 'disabled by default');
});

it('validate_config enforces the recurrence enum', function () {
    assert_eq('weekly', wpultra_seopilot_validate_config(['recurrence' => 'weekly'])['recurrence']);
    assert_eq('daily', wpultra_seopilot_validate_config(['recurrence' => 'nonsense'])['recurrence']);
});

it('validate_config clamps limit_per_run', function () {
    assert_eq(1, wpultra_seopilot_validate_config(['scope' => ['limit_per_run' => 0]])['scope']['limit_per_run']);
    assert_eq(100, wpultra_seopilot_validate_config(['scope' => ['limit_per_run' => 9999]])['scope']['limit_per_run']);
    assert_eq(15, wpultra_seopilot_validate_config(['scope' => ['limit_per_run' => 15]])['scope']['limit_per_run']);
});

it('validate_config coerces step flags and preserves defaults', function () {
    $c = wpultra_seopilot_validate_config(['steps' => ['schema' => false]]);
    assert_true($c['steps']['schema'] === false, 'schema off');
    assert_true($c['steps']['fix_meta'] === true, 'fix_meta default on');
    assert_true($c['steps']['internal_links'] === true, 'links default on');
});

it('validate_config falls back to default post_types when empty', function () {
    $c = wpultra_seopilot_validate_config(['scope' => ['post_types' => []]]);
    assert_eq(['post', 'page'], $c['scope']['post_types']);
});

it('validate_config caps history to 20', function () {
    $hist = [];
    for ($i = 0; $i < 40; $i++) { $hist[] = ['ts' => (string) $i]; }
    $c = wpultra_seopilot_validate_config(['history' => $hist]);
    assert_eq(20, count($c['history']));
});

run_tests();
