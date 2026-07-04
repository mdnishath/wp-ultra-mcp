<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_popups/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/marketing/popups.php';

/* ============================================================
 * defaults
 * ============================================================ */

it('defaults: disabled, time trigger, sane numbers, zeroed a/b stats', function () {
    $d = wpultra_popup_defaults();
    assert_eq(false, $d['enabled']);
    assert_eq('time', $d['trigger']);
    assert_eq(50, $d['scroll_pct']);
    assert_eq(5, $d['delay_s']);
    assert_eq('all', $d['pages']);
    assert_eq(7, $d['frequency_days']);
    assert_eq('', $d['variant_b_html']);
    assert_eq(['impressions' => 0, 'conversions' => 0], $d['stats']['a']);
    assert_eq(['impressions' => 0, 'conversions' => 0], $d['stats']['b']);
    assert_true(array_key_exists('created_at', $d));
});

/* ============================================================
 * validate
 * ============================================================ */

it('validate: full valid input passes', function () {
    assert_eq(true, wpultra_popup_validate([
        'trigger' => 'exit-intent', 'scroll_pct' => 80, 'delay_s' => 0,
        'pages' => [1, 2, 3], 'frequency_days' => 30, 'variant_b_html' => '<p>B</p>',
    ]));
});

it('validate: empty input passes (partial update friendly)', function () {
    assert_eq(true, wpultra_popup_validate([]));
});

it('validate: rejects unknown trigger', function () {
    $r = wpultra_popup_validate(['trigger' => 'hover']);
    assert_true(is_string($r), 'error string expected');
    assert_contains('trigger', (string) $r);
});

it('validate: scroll_pct range 1..100 enforced', function () {
    assert_true(is_string(wpultra_popup_validate(['scroll_pct' => 0])), '0 rejected');
    assert_true(is_string(wpultra_popup_validate(['scroll_pct' => 101])), '101 rejected');
    assert_eq(true, wpultra_popup_validate(['scroll_pct' => 1]));
    assert_eq(true, wpultra_popup_validate(['scroll_pct' => 100]));
});

it('validate: delay_s range 0..300 enforced', function () {
    assert_true(is_string(wpultra_popup_validate(['delay_s' => -1])), '-1 rejected');
    assert_true(is_string(wpultra_popup_validate(['delay_s' => 301])), '301 rejected');
    assert_eq(true, wpultra_popup_validate(['delay_s' => 0]));
    assert_eq(true, wpultra_popup_validate(['delay_s' => 300]));
});

it('validate: frequency_days range 0..365 enforced', function () {
    assert_true(is_string(wpultra_popup_validate(['frequency_days' => -1])), '-1 rejected');
    assert_true(is_string(wpultra_popup_validate(['frequency_days' => 366])), '366 rejected');
    assert_eq(true, wpultra_popup_validate(['frequency_days' => 0]));
    assert_eq(true, wpultra_popup_validate(['frequency_days' => 365]));
});

it('validate: non-integer numeric fields rejected', function () {
    assert_true(is_string(wpultra_popup_validate(['scroll_pct' => 'lots'])), 'string rejected');
    assert_true(is_string(wpultra_popup_validate(['delay_s' => [5]])), 'array rejected');
});

it('validate: pages shapes — all/home/id-array pass, junk fails', function () {
    assert_eq(true, wpultra_popup_validate(['pages' => 'all']));
    assert_eq(true, wpultra_popup_validate(['pages' => 'home']));
    assert_eq(true, wpultra_popup_validate(['pages' => [10, 20]]));
    assert_eq(true, wpultra_popup_validate(['pages' => ['10']]), 'numeric-string ids ok');
    assert_true(is_string(wpultra_popup_validate(['pages' => 'everywhere'])), 'bad string rejected');
    assert_true(is_string(wpultra_popup_validate(['pages' => 42])), 'bare int rejected');
    assert_true(is_string(wpultra_popup_validate(['pages' => [0]])), 'non-positive id rejected');
    assert_true(is_string(wpultra_popup_validate(['pages' => ['about-us']])), 'slug rejected');
});

it('validate: variant_b_html must be a string', function () {
    assert_eq(true, wpultra_popup_validate(['variant_b_html' => '']));
    assert_true(is_string(wpultra_popup_validate(['variant_b_html' => ['x']])));
});

/* ============================================================
 * meta_merge
 * ============================================================ */

it('meta_merge: applies only supplied keys with canonical types', function () {
    $meta = wpultra_popup_defaults();
    $out = wpultra_popup_meta_merge($meta, ['trigger' => 'scroll', 'scroll_pct' => '75', 'pages' => ['3', 4]]);
    assert_eq('scroll', $out['trigger']);
    assert_eq(75, $out['scroll_pct']);
    assert_eq([3, 4], $out['pages']);
    assert_eq(5, $out['delay_s'], 'untouched key keeps default');
});

it('meta_merge: never touches enabled/stats/created_at', function () {
    $meta = wpultra_popup_defaults();
    $meta['enabled'] = true;
    $meta['stats']['a']['impressions'] = 9;
    $meta['created_at'] = '2026-01-01 00:00:00';
    $out = wpultra_popup_meta_merge($meta, ['trigger' => 'time', 'delay_s' => 10, 'frequency_days' => 1, 'variant_b_html' => '<p>B</p>']);
    assert_eq(true, $out['enabled']);
    assert_eq(9, $out['stats']['a']['impressions']);
    assert_eq('2026-01-01 00:00:00', $out['created_at']);
    assert_eq('<p>B</p>', $out['variant_b_html']);
});

/* ============================================================
 * page_match
 * ============================================================ */

it('page_match: "all" matches everything', function () {
    assert_eq(true, wpultra_popup_page_match('all', ['is_home' => false, 'post_id' => 0]));
    assert_eq(true, wpultra_popup_page_match('all', ['is_home' => true, 'post_id' => 42]));
});

it('page_match: "home" follows is_home', function () {
    assert_eq(true, wpultra_popup_page_match('home', ['is_home' => true, 'post_id' => 0]));
    assert_eq(false, wpultra_popup_page_match('home', ['is_home' => false, 'post_id' => 7]));
});

it('page_match: id array matches the current post id (loose input types)', function () {
    assert_eq(true, wpultra_popup_page_match([5, 6], ['is_home' => false, 'post_id' => 5]));
    assert_eq(true, wpultra_popup_page_match(['6'], ['is_home' => false, 'post_id' => 6]), 'string ids coerced');
    assert_eq(false, wpultra_popup_page_match([5, 6], ['is_home' => false, 'post_id' => 7]));
    assert_eq(false, wpultra_popup_page_match([], ['is_home' => true, 'post_id' => 1]), 'empty array matches nothing');
});

it('page_match: junk pages value never matches', function () {
    assert_eq(false, wpultra_popup_page_match('everywhere', ['is_home' => true, 'post_id' => 1]));
    assert_eq(false, wpultra_popup_page_match(null, ['is_home' => true, 'post_id' => 1]));
});

/* ============================================================
 * pick_variant
 * ============================================================ */

it('pick_variant: no variant B => always a, rand never consulted', function () {
    $calls = 0;
    $rand = function (int $lo, int $hi) use (&$calls): int { $calls++; return 1; };
    assert_eq('a', wpultra_popup_pick_variant('', $rand));
    assert_eq('a', wpultra_popup_pick_variant("  \n ", $rand), 'whitespace-only B counts as empty');
    assert_eq(0, $calls, 'rand not called');
});

it('pick_variant: 50/50 split driven by injected rand', function () {
    assert_eq('a', wpultra_popup_pick_variant('<p>B</p>', fn(int $lo, int $hi): int => 0));
    assert_eq('b', wpultra_popup_pick_variant('<p>B</p>', fn(int $lo, int $hi): int => 1));
});

/* ============================================================
 * stats_add
 * ============================================================ */

it('stats_add: increments the right variant/counter', function () {
    $meta = wpultra_popup_defaults();
    $meta = wpultra_popup_stats_add($meta, 'a', 'impression');
    $meta = wpultra_popup_stats_add($meta, 'a', 'impression');
    $meta = wpultra_popup_stats_add($meta, 'a', 'conversion');
    $meta = wpultra_popup_stats_add($meta, 'b', 'impression');
    assert_eq(2, $meta['stats']['a']['impressions']);
    assert_eq(1, $meta['stats']['a']['conversions']);
    assert_eq(1, $meta['stats']['b']['impressions']);
    assert_eq(0, $meta['stats']['b']['conversions']);
});

it('stats_add: unknown event or variant leaves meta unchanged', function () {
    $meta = wpultra_popup_defaults();
    assert_eq($meta, wpultra_popup_stats_add($meta, 'a', 'view'));
    assert_eq($meta, wpultra_popup_stats_add($meta, 'c', 'impression'));
});

it('stats_add: repairs a missing/partial stats subtree', function () {
    $meta = ['enabled' => true]; // no stats key at all
    $out = wpultra_popup_stats_add($meta, 'b', 'conversion');
    assert_eq(1, $out['stats']['b']['conversions']);
    assert_eq(0, $out['stats']['a']['impressions']);

    $partial = ['stats' => ['a' => ['impressions' => 3]]]; // b missing, conversions missing
    $out2 = wpultra_popup_stats_add($partial, 'a', 'impression');
    assert_eq(4, $out2['stats']['a']['impressions']);
    assert_eq(0, $out2['stats']['a']['conversions']);
    assert_eq(0, $out2['stats']['b']['impressions']);
});

/* ============================================================
 * rates
 * ============================================================ */

it('rates: div0 guarded — zero impressions => 0.0 pct, winner null', function () {
    $r = wpultra_popup_rates(wpultra_popup_defaults()['stats']);
    assert_eq(0.0, $r['a']['rate_pct']);
    assert_eq(0.0, $r['b']['rate_pct']);
    assert_eq(null, $r['winner']);
});

it('rates: computes per-variant pct rounded to 2dp and picks the winner', function () {
    $r = wpultra_popup_rates([
        'a' => ['impressions' => 3, 'conversions' => 1],
        'b' => ['impressions' => 100, 'conversions' => 5],
    ]);
    assert_eq(33.33, $r['a']['rate_pct']);
    assert_eq(5.0, $r['b']['rate_pct']);
    assert_eq('a', $r['winner']);

    $r2 = wpultra_popup_rates([
        'a' => ['impressions' => 100, 'conversions' => 2],
        'b' => ['impressions' => 100, 'conversions' => 9],
    ]);
    assert_eq('b', $r2['winner']);
});

it('rates: tie => winner null; missing keys treated as zero', function () {
    $tie = wpultra_popup_rates([
        'a' => ['impressions' => 10, 'conversions' => 1],
        'b' => ['impressions' => 10, 'conversions' => 1],
    ]);
    assert_eq(null, $tie['winner']);

    $sparse = wpultra_popup_rates(['a' => ['impressions' => 4, 'conversions' => 1]]);
    assert_eq(25.0, $sparse['a']['rate_pct']);
    assert_eq(0, $sparse['b']['impressions']);
    assert_eq('a', $sparse['winner']);
});

/* ============================================================
 * js_config
 * ============================================================ */

it('js_config: exact front-end shape with normalized types', function () {
    $cfg = wpultra_popup_js_config([[
        'id' => '12', 'trigger' => 'scroll', 'scroll_pct' => 70,
        'delay_s' => 3, 'frequency_days' => 14, 'variant' => 'b',
    ]]);
    assert_eq(1, count($cfg));
    assert_eq(
        ['id' => 12, 'trigger' => 'scroll', 'scroll_pct' => 70, 'delay_s' => 3, 'frequency_days' => 14, 'variant' => 'b'],
        $cfg[0]
    );
    // Key order + types survive JSON encoding for the inline script.
    assert_contains('"trigger":"scroll"', (string) json_encode($cfg[0]));
});

it('js_config: clamps ranges and defaults missing keys', function () {
    $cfg = wpultra_popup_js_config([['id' => 5, 'scroll_pct' => 999, 'delay_s' => -2, 'frequency_days' => 9999]]);
    assert_eq(100, $cfg[0]['scroll_pct']);
    assert_eq(0, $cfg[0]['delay_s']);
    assert_eq(365, $cfg[0]['frequency_days']);
    assert_eq('time', $cfg[0]['trigger'], 'missing trigger defaults to time');
    assert_eq('a', $cfg[0]['variant'], 'missing variant defaults to a');

    $cfg2 = wpultra_popup_js_config([['id' => 6, 'trigger' => 'bogus', 'variant' => 'z']]);
    assert_eq('time', $cfg2[0]['trigger'], 'unknown trigger coerced to time');
    assert_eq('a', $cfg2[0]['variant'], 'unknown variant coerced to a');
});

it('js_config: drops entries without a positive id and reindexes', function () {
    $cfg = wpultra_popup_js_config([
        ['id' => 0, 'trigger' => 'time'],
        'junk',
        ['id' => 9, 'trigger' => 'exit-intent'],
    ]);
    assert_eq(1, count($cfg));
    assert_eq(9, $cfg[0]['id']);
    assert_eq('exit-intent', $cfg[0]['trigger']);
});

/* ============================================================
 * index_sync
 * ============================================================ */

it('index_sync: upserts enabled state', function () {
    $idx = wpultra_popup_index_sync([], 3, false);
    assert_eq([3 => false], $idx);
    $idx = wpultra_popup_index_sync($idx, 3, true);
    assert_eq([3 => true], $idx);
    $idx = wpultra_popup_index_sync($idx, 8, true);
    assert_eq([3 => true, 8 => true], $idx);
});

it('index_sync: null enabled removes the entry (delete)', function () {
    $idx = wpultra_popup_index_sync([3 => true, 8 => false], 3, null);
    assert_eq([8 => false], $idx);
    // Removing a missing id is a no-op, not an error.
    assert_eq([8 => false], wpultra_popup_index_sync($idx, 99, null));
});

it('index_sync: non-positive ids are ignored', function () {
    assert_eq([1 => true], wpultra_popup_index_sync([1 => true], 0, true));
    assert_eq([1 => true], wpultra_popup_index_sync([1 => true], -5, null));
});

/* ============================================================
 * inline JS builder (pure string transform)
 * ============================================================ */

it('inline_js: injects the JSON config and keeps the runtime intact', function () {
    $json = json_encode(['rest' => 'https://x.test/wp-json/wpultra/v1/track', 'popups' => [['id' => 1]]]);
    $js = wpultra_popup_inline_js((string) $json);
    assert_contains('var CFG=' . $json, $js, 'config injected verbatim');
    assert_true(!str_contains($js, '__WPULTRA_POPUP_CFG__'), 'placeholder fully replaced');
    assert_contains('navigator.sendBeacon', $js);
    assert_contains('kind:"popup"', $js);
    assert_contains('e.clientY<=0', $js, 'exit-intent arming present');
    assert_contains('localStorage', $js, 'frequency cap present');
});

run_tests();
