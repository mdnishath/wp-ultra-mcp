<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_atrans/'); }
// helpers.php provides wpultra_err / wpultra_ok / wpultra_reserved_post_types.
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/i18n/autotranslate.php';

/* ============================================================
 * norm_lang
 * ============================================================ */

it('norm_lang lowercases + trims', function () {
    assert_eq('fr', wpultra_atrans_norm_lang('  FR '));
    assert_eq('fr-fr', wpultra_atrans_norm_lang('FR-fr'));
    assert_eq('', wpultra_atrans_norm_lang('   '));
});

/* ============================================================
 * pick_targets
 * ============================================================ */

it('pick_targets returns all fresh posts when none translated', function () {
    $posts = [['id' => 1, 'type' => 'post'], ['id' => 2, 'type' => 'page']];
    assert_eq([1, 2], wpultra_atrans_pick_targets($posts, 'fr', []));
});

it('pick_targets drops already-translated ids', function () {
    $posts = [['id' => 1], ['id' => 2], ['id' => 3]];
    assert_eq([1, 3], wpultra_atrans_pick_targets($posts, 'fr', [2]));
});

it('pick_targets filters by post_types allow-list', function () {
    $posts = [['id' => 1, 'type' => 'post'], ['id' => 2, 'type' => 'page'], ['id' => 3, 'type' => 'product']];
    assert_eq([2], wpultra_atrans_pick_targets($posts, 'fr', [], ['post_types' => ['page']]));
});

it('pick_targets respects the limit', function () {
    $posts = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]];
    assert_eq([1, 2], wpultra_atrans_pick_targets($posts, 'fr', [], ['limit' => 2]));
});

it('pick_targets de-dupes repeated ids', function () {
    $posts = [['id' => 5], ['id' => 5], ['id' => 6]];
    assert_eq([5, 6], wpultra_atrans_pick_targets($posts, 'fr', []));
});

it('pick_targets skips posts already in the target language', function () {
    $posts = [['id' => 1, 'lang' => 'fr'], ['id' => 2, 'lang' => 'en'], ['id' => 3, 'lang' => 'FR']];
    assert_eq([2], wpultra_atrans_pick_targets($posts, 'fr', []));
});

it('pick_targets keeps same-lang posts when skip_same_lang is off', function () {
    $posts = [['id' => 1, 'lang' => 'fr'], ['id' => 2, 'lang' => 'en']];
    assert_eq([1, 2], wpultra_atrans_pick_targets($posts, 'fr', [], ['skip_same_lang' => false]));
});

it('pick_targets ignores rows with no/invalid id', function () {
    $posts = [['id' => 0], ['type' => 'post'], ['id' => 7]];
    assert_eq([7], wpultra_atrans_pick_targets($posts, 'fr', []));
});

/* ============================================================
 * prompt
 * ============================================================ */

it('prompt names the target language + preserve rules in the system message', function () {
    $p = wpultra_atrans_prompt(['title' => 'Hi', 'content' => '<p>x</p>'], 'Bengali');
    assert_true(isset($p['system'], $p['user']));
    assert_contains('Bengali', $p['system']);
    assert_contains('HTML', $p['system']);
    assert_contains('wp:', $p['system']);       // Gutenberg block preservation
    assert_contains('hortcode', $p['system']);  // shortcode preservation
    assert_contains('URL', $p['system']);
});

it('prompt embeds the fields as JSON in the user message', function () {
    $p = wpultra_atrans_prompt(['title' => 'Hello', 'content' => 'World', 'excerpt' => 'E'], 'fr');
    assert_contains('Hello', $p['user']);
    assert_contains('World', $p['user']);
    assert_contains('fr', $p['user']);
});

it('prompt handles missing fields gracefully', function () {
    $p = wpultra_atrans_prompt([], 'de');
    assert_contains('de', $p['system']);
    assert_contains('"title":""', str_replace(' ', '', $p['user']));
});

/* ============================================================
 * parse
 * ============================================================ */

it('parse reads a bare JSON object', function () {
    $r = wpultra_atrans_parse('{"title":"Bonjour","content":"<p>Salut</p>","excerpt":"E"}');
    assert_true(is_array($r));
    assert_eq('Bonjour', $r['title']);
    assert_eq('<p>Salut</p>', $r['content']);
    assert_eq('E', $r['excerpt']);
});

it('parse strips a ```json fenced block', function () {
    $r = wpultra_atrans_parse("```json\n{\"title\":\"Hola\",\"content\":\"c\",\"excerpt\":\"\"}\n```");
    assert_true(is_array($r));
    assert_eq('Hola', $r['title']);
});

it('parse strips a plain fenced block', function () {
    $r = wpultra_atrans_parse("```\n{\"title\":\"A\",\"content\":\"B\",\"excerpt\":\"C\"}\n```");
    assert_true(is_array($r));
    assert_eq('A', $r['title']);
});

it('parse extracts a JSON object embedded in prose', function () {
    $r = wpultra_atrans_parse('Sure! Here you go: {"title":"T","content":"C","excerpt":"X"} — done.');
    assert_true(is_array($r));
    assert_eq('T', $r['title']);
    assert_eq('X', $r['excerpt']);
});

it('parse fills missing keys with empty strings', function () {
    $r = wpultra_atrans_parse('{"title":"only"}');
    assert_true(is_array($r));
    assert_eq('only', $r['title']);
    assert_eq('', $r['content']);
    assert_eq('', $r['excerpt']);
});

it('parse returns an error string on garbage', function () {
    assert_true(is_string(wpultra_atrans_parse('this is not json at all')));
    assert_true(is_string(wpultra_atrans_parse('')));
});

/* ============================================================
 * protect / restore tokens — round-trip exactness
 * ============================================================ */

it('protect/restore round-trips a shortcode exactly', function () {
    $html = 'Before [gallery ids="1,2,3"] after.';
    $p = wpultra_atrans_protect_tokens($html);
    assert_true(!str_contains($p['text'], '[gallery'), 'shortcode tokenised out');
    assert_eq($html, wpultra_atrans_restore_tokens($p['text'], $p['tokens']));
});

it('protect/restore round-trips Gutenberg block delimiters exactly', function () {
    $html = '<!-- wp:paragraph {"align":"center"} --><p>Hi</p><!-- /wp:paragraph -->';
    $p = wpultra_atrans_protect_tokens($html);
    assert_true(!str_contains($p['text'], '<!-- wp:'), 'block open tokenised');
    assert_true(!str_contains($p['text'], '<!-- /wp:'), 'block close tokenised');
    assert_eq($html, wpultra_atrans_restore_tokens($p['text'], $p['tokens']));
});

it('protect/restore round-trips a bare URL exactly', function () {
    $html = 'Visit https://example.com/path?a=b&c=d for more.';
    $p = wpultra_atrans_protect_tokens($html);
    assert_true(!str_contains($p['text'], 'https://example.com'), 'url tokenised out');
    assert_eq($html, wpultra_atrans_restore_tokens($p['text'], $p['tokens']));
});

it('protect/restore round-trips a mixed nested payload exactly', function () {
    $html = '<!-- wp:shortcode -->[embed]https://youtu.be/abc[/embed]<!-- /wp:shortcode --> plus [button url="https://x.io/y"]Click[/button] and a link https://z.org/page.';
    $p = wpultra_atrans_protect_tokens($html);
    assert_eq($html, wpultra_atrans_restore_tokens($p['text'], $p['tokens']));
});

it('protect leaves plain text untouched and restore is a no-op', function () {
    $html = 'Just plain human sentence with no markup.';
    $p = wpultra_atrans_protect_tokens($html);
    assert_eq($html, $p['text']);
    assert_eq([], $p['tokens']);
    assert_eq($html, wpultra_atrans_restore_tokens($html, []));
});

it('protect tokenises multiple shortcodes distinctly and restores all', function () {
    $html = '[a] middle [b] end [/a]';
    $p = wpultra_atrans_protect_tokens($html);
    assert_eq(3, count($p['tokens']), 'three shortcode tokens');
    assert_eq($html, wpultra_atrans_restore_tokens($p['text'], $p['tokens']));
});

it('restore is unambiguous across 10+ tokens (no prefix collision)', function () {
    $parts = [];
    for ($i = 0; $i < 12; $i++) { $parts[] = "[sc$i]"; }
    $html = implode(' word ', $parts);
    $p = wpultra_atrans_protect_tokens($html);
    assert_eq(12, count($p['tokens']));
    assert_eq($html, wpultra_atrans_restore_tokens($p['text'], $p['tokens']));
});

/* ============================================================
 * job blob shaping
 * ============================================================ */

it('new_job builds a queued blob with the right shape', function () {
    $job = wpultra_atrans_new_job('FR', [3, 1, 2], 'ai');
    assert_eq('fr', $job['target_lang']);
    assert_eq([3, 1, 2], $job['queue']);
    assert_eq(0, $job['cursor']);
    assert_eq(3, $job['total']);
    assert_eq('ai', $job['source']);
    assert_eq('queued', $job['status']);
});

it('new_job coerces an unknown source to caller', function () {
    $job = wpultra_atrans_new_job('fr', [1], 'weird');
    assert_eq('caller', $job['source']);
});

it('is_active true for queued/running, false for terminal', function () {
    assert_true(wpultra_atrans_is_active('queued'));
    assert_true(wpultra_atrans_is_active('running'));
    assert_true(!wpultra_atrans_is_active('done'));
    assert_true(!wpultra_atrans_is_active('failed'));
    assert_true(!wpultra_atrans_is_active('cancelled'));
});

it('shape_job computes percent + remaining', function () {
    $job = wpultra_atrans_new_job('fr', [1, 2, 3, 4], 'ai');
    $job['cursor'] = 1;
    $job['status'] = 'running';
    $s = wpultra_atrans_shape_job($job);
    assert_eq(4, $s['total']);
    assert_eq(1, $s['processed']);
    assert_eq(3, $s['remaining']);
    assert_eq(25, $s['percent']);
    assert_eq('running', $s['status']);
});

it('shape_job reports 100% for an empty queue', function () {
    $job = wpultra_atrans_new_job('fr', [], 'caller');
    $s = wpultra_atrans_shape_job($job);
    assert_eq(0, $s['total']);
    assert_eq(100, $s['percent']);
});

run_tests();
