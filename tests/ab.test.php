<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_ab/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/marketing/ab.php';

/** Build a minimal valid title test for reuse. */
function ab_fixture(array $over = []): array {
    return array_merge([
        'id'       => 'ab-test01',
        'name'     => 'Headline test',
        'post_id'  => 12,
        'kind'     => 'title',
        'variants' => [
            ['key' => 'a', 'control' => true],
            ['key' => 'b', 'title' => 'New Headline'],
        ],
        'goal'     => ['type' => 'click', 'selector' => '.cta'],
    ], $over);
}

/** Set stats buckets on a fixture. $rows = ['a' => [views, conversions], ...] */
function ab_with_stats(array $test, array $rows): array {
    foreach ($rows as $key => [$views, $conv]) {
        $test['stats'][$key] = ['views' => $views, 'conversions' => $conv];
    }
    return $test;
}

/* ============================================================
 * wpultra_ab_new_id
 * ============================================================ */

it('new_id matches ab- + 6 lowercase alnum with default randomness', function () {
    for ($i = 0; $i < 20; $i++) {
        assert_true((bool) preg_match('/^ab-[a-z0-9]{6}$/', wpultra_ab_new_id()), 'id format');
    }
});

it('new_id is deterministic with injected randomness', function () {
    assert_eq('ab-aaaaaa', wpultra_ab_new_id(function ($min, $max) { return 0; }));
    $i = 0;
    assert_eq('ab-abcdef', wpultra_ab_new_id(function ($min, $max) use (&$i) { return $i++; }));
    assert_eq('ab-999999', wpultra_ab_new_id(function ($min, $max) { return 35; }));
});

/* ============================================================
 * wpultra_ab_validate
 * ============================================================ */

it('validate accepts a valid title test', function () {
    assert_eq(true, wpultra_ab_validate(ab_fixture()));
});

it('validate accepts a valid content test with a control variant', function () {
    $t = ab_fixture([
        'kind'     => 'content',
        'variants' => [
            ['key' => 'a', 'control' => true],
            ['key' => 'b', 'find' => 'Get a Quote', 'replace' => 'Book Now'],
        ],
        'goal'     => ['type' => 'visit', 'url_contains' => '/thank-you'],
    ]);
    assert_eq(true, wpultra_ab_validate($t));
});

it('validate rejects a bad kind', function () {
    $r = wpultra_ab_validate(ab_fixture(['kind' => 'color']));
    assert_true(is_string($r), 'error string returned');
    assert_contains('kind', (string) $r);
});

it('validate rejects fewer than 2 variants', function () {
    $r = wpultra_ab_validate(ab_fixture(['variants' => [['key' => 'a', 'title' => 'Only one']]]));
    assert_true(is_string($r));
    assert_contains('2 variants', (string) $r);
});

it('validate rejects duplicate variant keys', function () {
    $r = wpultra_ab_validate(ab_fixture(['variants' => [
        ['key' => 'a', 'title' => 'One'],
        ['key' => 'a', 'title' => 'Two'],
    ]]));
    assert_true(is_string($r));
    assert_contains('duplicate', (string) $r);
});

it('validate rejects an invalid variant key (uppercase / too long / empty)', function () {
    foreach (['A', str_repeat('x', 21), '', 'has space'] as $bad) {
        $r = wpultra_ab_validate(ab_fixture(['variants' => [
            ['key' => $bad, 'title' => 'One'],
            ['key' => 'b', 'title' => 'Two'],
        ]]));
        assert_true(is_string($r), "key '$bad' rejected");
    }
});

it('validate rejects a title variant without a title (non-control)', function () {
    $r = wpultra_ab_validate(ab_fixture(['variants' => [
        ['key' => 'a', 'control' => true],
        ['key' => 'b'],
    ]]));
    assert_true(is_string($r));
    assert_contains('title', (string) $r);
});

it('validate rejects a content variant without find (non-control)', function () {
    $r = wpultra_ab_validate(ab_fixture([
        'kind'     => 'content',
        'variants' => [
            ['key' => 'a', 'control' => true],
            ['key' => 'b', 'replace' => 'Book Now'],
        ],
    ]));
    assert_true(is_string($r));
    assert_contains('find', (string) $r);
});

it('validate rejects bad goals and accepts both goal types', function () {
    assert_true(is_string(wpultra_ab_validate(ab_fixture(['goal' => null]))), 'missing goal');
    assert_true(is_string(wpultra_ab_validate(ab_fixture(['goal' => ['type' => 'hover']]))), 'unknown type');
    assert_true(is_string(wpultra_ab_validate(ab_fixture(['goal' => ['type' => 'click', 'selector' => '  ']]))), 'blank selector');
    assert_true(is_string(wpultra_ab_validate(ab_fixture(['goal' => ['type' => 'visit']]))), 'missing url_contains');
    assert_eq(true, wpultra_ab_validate(ab_fixture(['goal' => ['type' => 'visit', 'url_contains' => '/ok']])));
});

it('validate rejects post_id <= 0 and empty name', function () {
    assert_true(is_string(wpultra_ab_validate(ab_fixture(['post_id' => 0]))));
    assert_true(is_string(wpultra_ab_validate(ab_fixture(['post_id' => -3]))));
    assert_true(is_string(wpultra_ab_validate(ab_fixture(['name' => '  ']))));
});

it('validate rejects a non-positive min_samples', function () {
    assert_true(is_string(wpultra_ab_validate(ab_fixture(['min_samples' => 0]))));
    assert_true(is_string(wpultra_ab_validate(ab_fixture(['min_samples' => 'lots']))));
    assert_eq(true, wpultra_ab_validate(ab_fixture(['min_samples' => 50])));
});

/* ============================================================
 * wpultra_ab_normalize
 * ============================================================ */

it('normalize fills defaults: draft status, min_samples 100, zeroed stats, timestamps', function () {
    $t = wpultra_ab_normalize(ab_fixture(), '2026-07-03 00:00:00');
    assert_eq('draft', $t['status']);
    assert_eq(100, $t['min_samples']);
    assert_eq(false, $t['auto_apply']);
    assert_eq(null, $t['winner']);
    assert_eq(false, $t['applied']);
    assert_eq('2026-07-03 00:00:00', $t['created_at']);
    assert_eq(['views' => 0, 'conversions' => 0], $t['stats']['a']);
    assert_eq(['views' => 0, 'conversions' => 0], $t['stats']['b']);
});

it('normalize clamps min_samples into [10, 1000000] and preserves existing stats', function () {
    assert_eq(10, wpultra_ab_normalize(ab_fixture(['min_samples' => 1]))['min_samples']);
    assert_eq(1000000, wpultra_ab_normalize(ab_fixture(['min_samples' => 99999999]))['min_samples']);
    $t = wpultra_ab_normalize(ab_with_stats(ab_fixture(), ['a' => [5, 2]]));
    assert_eq(['views' => 5, 'conversions' => 2], $t['stats']['a']);
});

/* ============================================================
 * wpultra_ab_pick_variant + wpultra_ab_variant_for
 * ============================================================ */

it('pick_variant returns the key selected by the injected rand', function () {
    $keys = ['a', 'b', 'c'];
    assert_eq('a', wpultra_ab_pick_variant($keys, function ($min, $max) { return $min; }));
    assert_eq('c', wpultra_ab_pick_variant($keys, function ($min, $max) { return $max; }));
    assert_eq('b', wpultra_ab_pick_variant($keys, function ($min, $max) { return 1; }));
});

it('pick_variant asks rand for the full inclusive range and survives an out-of-range rand', function () {
    $seen = null;
    wpultra_ab_pick_variant(['a', 'b', 'c'], function ($min, $max) use (&$seen) { $seen = [$min, $max]; return 0; });
    assert_eq([0, 2], $seen);
    assert_eq('a', wpultra_ab_pick_variant(['a', 'b'], function ($min, $max) { return 99; }), 'clamped to first');
    assert_eq('', wpultra_ab_pick_variant([], function ($min, $max) { return 0; }), 'empty keys');
});

it('variant_for finds by key, null otherwise', function () {
    $t = ab_fixture();
    assert_eq('New Headline', wpultra_ab_variant_for($t, 'b')['title']);
    assert_eq(null, wpultra_ab_variant_for($t, 'z'));
    assert_eq(null, wpultra_ab_variant_for($t, null));
    assert_eq(null, wpultra_ab_variant_for($t, ''));
});

/* ============================================================
 * wpultra_ab_apply_content
 * ============================================================ */

it('apply_content replaces find with replace (CTA text swap)', function () {
    $v = ['key' => 'b', 'find' => 'Get a Quote', 'replace' => 'Book Now'];
    assert_eq('<a class="cta">Book Now</a>', wpultra_ab_apply_content('<a class="cta">Get a Quote</a>', $v));
});

it('apply_content swaps a hero image URL', function () {
    $v = ['key' => 'b', 'find' => 'https://x.test/hero-a.jpg', 'replace' => 'https://x.test/hero-b.jpg'];
    assert_eq('<img src="https://x.test/hero-b.jpg">', wpultra_ab_apply_content('<img src="https://x.test/hero-a.jpg">', $v));
});

it('apply_content is a no-op for control variants', function () {
    assert_eq('unchanged', wpultra_ab_apply_content('unchanged', ['key' => 'a', 'control' => true, 'find' => 'unchanged', 'replace' => 'nope']));
});

it('apply_content is a no-op for empty find, empty replace, or find==replace', function () {
    assert_eq('text', wpultra_ab_apply_content('text', ['key' => 'b']));
    assert_eq('text', wpultra_ab_apply_content('text', ['key' => 'b', 'find' => '', 'replace' => 'x']));
    assert_eq('text', wpultra_ab_apply_content('text', ['key' => 'b', 'find' => 'text', 'replace' => '']));
    assert_eq('text', wpultra_ab_apply_content('text', ['key' => 'b', 'find' => 'text', 'replace' => 'text']));
});

it('apply_content leaves content alone when find is absent', function () {
    assert_eq('hello world', wpultra_ab_apply_content('hello world', ['key' => 'b', 'find' => 'missing', 'replace' => 'x']));
});

/* ============================================================
 * wpultra_ab_stats_add
 * ============================================================ */

it('stats_add increments views and conversions, initializing missing buckets', function () {
    $t = ab_fixture();
    $t = wpultra_ab_stats_add($t, 'a', 'views');
    $t = wpultra_ab_stats_add($t, 'a', 'views');
    $t = wpultra_ab_stats_add($t, 'a', 'conversions');
    assert_eq(['views' => 2, 'conversions' => 1], $t['stats']['a']);
    assert_true(!isset($t['stats']['b']), 'untouched bucket stays absent');
});

it('stats_add ignores unknown metrics and empty variant keys', function () {
    $t = ab_fixture();
    assert_eq($t, wpultra_ab_stats_add($t, 'a', 'clicks'));
    assert_eq($t, wpultra_ab_stats_add($t, '', 'views'));
});

/* ============================================================
 * wpultra_ab_z + wpultra_ab_winner
 * ============================================================ */

it('winner is null while any variant is below min_samples', function () {
    // b is hugely better but a only has 99 views (min 100).
    $t = ab_with_stats(ab_fixture(['min_samples' => 100]), ['a' => [99, 0], 'b' => [1000, 500]]);
    assert_eq(null, wpultra_ab_winner($t));
});

it('winner returns the leader on a clearly significant split (z ~ 6.26)', function () {
    $t = ab_with_stats(ab_fixture(['min_samples' => 100]), ['a' => [1000, 100], 'b' => [1000, 200]]);
    assert_eq('b', wpultra_ab_winner($t));
    $zt = wpultra_ab_z($t);
    assert_eq('b', $zt['leader']);
    assert_eq('a', $zt['runner_up']);
    assert_true(abs($zt['z'] - 6.2622) < 0.01, 'z close to 6.26, got ' . $zt['z']);
});

it('winner is null when the lead is not significant (z ~ 0.45)', function () {
    $t = ab_with_stats(ab_fixture(['min_samples' => 100]), ['a' => [100, 10], 'b' => [100, 12]]);
    assert_eq(null, wpultra_ab_winner($t));
    assert_true(wpultra_ab_z($t)['z'] < 1.64);
});

it('winner boundary: z just under 1.64 fails, comfortably over passes', function () {
    // 20% vs 13% at n=100 -> z ~ 1.33 (no winner).
    $under = ab_with_stats(ab_fixture(['min_samples' => 100]), ['a' => [100, 13], 'b' => [100, 20]]);
    assert_eq(null, wpultra_ab_winner($under));
    // 25% vs 12% at n=100 -> z ~ 2.37 (winner b).
    $over = ab_with_stats(ab_fixture(['min_samples' => 100]), ['a' => [100, 12], 'b' => [100, 25]]);
    assert_eq('b', wpultra_ab_winner($over));
});

it('winner guards division by zero: all-zero and all-converting variants yield null', function () {
    $zero = ab_with_stats(ab_fixture(['min_samples' => 10]), ['a' => [50, 0], 'b' => [50, 0]]);
    assert_eq(null, wpultra_ab_winner($zero));
    assert_eq(0.0, wpultra_ab_z($zero)['z']);
    $full = ab_with_stats(ab_fixture(['min_samples' => 10]), ['a' => [50, 50], 'b' => [50, 50]]);
    assert_eq(null, wpultra_ab_winner($full));
    assert_eq(0.0, wpultra_ab_z($full)['z']);
});

it('winner with zero views everywhere is null (no crash)', function () {
    assert_eq(null, wpultra_ab_winner(ab_fixture()));
    assert_eq(0.0, wpultra_ab_z(ab_fixture())['z']);
});

it('winner with 3 variants compares the top two by rate', function () {
    $t = ab_fixture(['min_samples' => 100, 'variants' => [
        ['key' => 'a', 'control' => true],
        ['key' => 'b', 'title' => 'B'],
        ['key' => 'c', 'title' => 'C'],
    ]]);
    // c (20%) vs b (10%) is the decisive pair; a (5%) is irrelevant.
    $t = ab_with_stats($t, ['a' => [1000, 50], 'b' => [1000, 100], 'c' => [1000, 200]]);
    $zt = wpultra_ab_z($t);
    assert_eq('c', $zt['leader']);
    assert_eq('b', $zt['runner_up']);
    assert_eq('c', wpultra_ab_winner($t));
    // But if variant a lacks samples, no winner even though c crushes b.
    $t2 = ab_with_stats($t, ['a' => [50, 5]]);
    assert_eq(null, wpultra_ab_winner($t2));
});

it('winner respects a custom min_samples', function () {
    $t = ab_with_stats(ab_fixture(['min_samples' => 20]), ['a' => [25, 2], 'b' => [25, 15]]);
    assert_eq('b', wpultra_ab_winner($t)); // 60% vs 8% at n=25 is significant
    $t['min_samples'] = 30;
    assert_eq(null, wpultra_ab_winner($t));
});

/* ============================================================
 * wpultra_ab_shape
 * ============================================================ */

it('shape adds computed per-variant rates, z, significance and projected winner', function () {
    $t = ab_with_stats(ab_fixture(['min_samples' => 100]), ['a' => [1000, 100], 'b' => [1000, 200]]);
    $s = wpultra_ab_shape($t);
    $rows = $s['computed']['variants'];
    assert_eq('a', $rows[0]['key']);
    assert_eq(0.1, $rows[0]['rate']);
    assert_eq(true, $rows[0]['control']);
    assert_eq(0.2, $rows[1]['rate']);
    assert_eq(true, $s['computed']['significant']);
    assert_eq('b', $s['computed']['leader']);
    assert_eq('b', $s['computed']['projected_winner']);
    assert_true(abs($s['computed']['z'] - 6.2622) < 0.01);
});

it('shape on a fresh test reports zero rates and no winner', function () {
    $s = wpultra_ab_shape(wpultra_ab_normalize(ab_fixture()));
    assert_eq(0.0, $s['computed']['variants'][0]['rate']);
    assert_eq(false, $s['computed']['significant']);
    assert_eq(null, $s['computed']['projected_winner']);
});

run_tests();
