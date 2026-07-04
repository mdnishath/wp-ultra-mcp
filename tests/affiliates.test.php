<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_affiliates/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/marketing/affiliates.php';

/* ============================================================
 * wpultra_aff_valid_code / wpultra_aff_normalize_code
 * ============================================================ */

it('valid_code accepts well-formed codes', function () {
    assert_true(wpultra_aff_valid_code('abc'));
    assert_true(wpultra_aff_valid_code('john-doe-a1b2'));
    assert_true(wpultra_aff_valid_code('123'));
    assert_true(wpultra_aff_valid_code(str_repeat('a', 32)), '32 chars is the max');
});

it('valid_code rejects malformed codes', function () {
    assert_eq(false, wpultra_aff_valid_code(''));
    assert_eq(false, wpultra_aff_valid_code('ab'), 'too short');
    assert_eq(false, wpultra_aff_valid_code(str_repeat('a', 33)), 'too long');
    assert_eq(false, wpultra_aff_valid_code('John'), 'uppercase');
    assert_eq(false, wpultra_aff_valid_code('a_b_c'), 'underscore');
    assert_eq(false, wpultra_aff_valid_code('a b c'), 'space');
    assert_eq(false, wpultra_aff_valid_code('abc!'), 'punctuation');
});

it('normalize_code trims and lowercases', function () {
    assert_eq('john-doe', wpultra_aff_normalize_code('  JOHN-Doe  '));
    assert_eq('abc', wpultra_aff_normalize_code("abc\n"));
    assert_eq('', wpultra_aff_normalize_code('   '));
});

/* ============================================================
 * wpultra_aff_commission
 * ============================================================ */

it('commission computes total * rate% rounded to 2dp', function () {
    assert_eq(10.0, wpultra_aff_commission(100.0, 10.0));
    assert_eq(7.5, wpultra_aff_commission(99.99, 7.5));   // 7.49925 -> 7.5
    assert_eq(0.33, wpultra_aff_commission(3.33, 10.0));  // 0.333 -> 0.33
    assert_eq(0.0, wpultra_aff_commission(0.0, 50.0));
});

it('commission clamps rate to 0..100 and negative totals to 0', function () {
    assert_eq(0.0, wpultra_aff_commission(100.0, -5.0), 'negative rate -> 0');
    assert_eq(100.0, wpultra_aff_commission(100.0, 250.0), 'rate over 100 clamps to 100');
    assert_eq(0.0, wpultra_aff_commission(-50.0, 10.0), 'negative total -> 0');
});

/* ============================================================
 * wpultra_aff_referral_link
 * ============================================================ */

it('referral_link appends ?ref= when the base has no query', function () {
    assert_eq('https://x.com/?ref=abc', wpultra_aff_referral_link('https://x.com/', 'abc'));
    assert_eq('https://x.com/page?ref=abc', wpultra_aff_referral_link('https://x.com/page', 'abc'));
});

it('referral_link appends &ref= when the base already has a query', function () {
    assert_eq('https://x.com/?p=1&ref=abc', wpultra_aff_referral_link('https://x.com/?p=1', 'abc'));
});

it('referral_link handles a base ending in ? or & without doubling the separator', function () {
    assert_eq('https://x.com/?ref=abc', wpultra_aff_referral_link('https://x.com/?', 'abc'));
    assert_eq('https://x.com/?p=1&ref=abc', wpultra_aff_referral_link('https://x.com/?p=1&', 'abc'));
});

it('referral_link normalizes the code and defaults an empty base to /', function () {
    assert_eq('https://x.com/?ref=abc', wpultra_aff_referral_link('https://x.com/', '  ABC '));
    assert_eq('/?ref=abc', wpultra_aff_referral_link('', 'abc'));
});

/* ============================================================
 * wpultra_aff_validate
 * ============================================================ */

it('validate accepts a full valid input', function () {
    assert_true(wpultra_aff_validate(['name' => 'John Doe', 'email' => 'john@example.com', 'code' => 'john-1', 'rate_pct' => 15]));
    assert_true(wpultra_aff_validate(['name' => 'J', 'email' => 'j@x.io']), 'code and rate optional');
    assert_true(wpultra_aff_validate(['name' => 'J', 'email' => 'j@x.io', 'rate_pct' => 0]), 'rate 0 ok');
    assert_true(wpultra_aff_validate(['name' => 'J', 'email' => 'j@x.io', 'rate_pct' => 100]), 'rate 100 ok');
});

it('validate rejects missing name, bad email, bad code, out-of-range rate', function () {
    assert_true(is_string(wpultra_aff_validate(['email' => 'j@x.io'])), 'missing name');
    assert_true(is_string(wpultra_aff_validate(['name' => '  ', 'email' => 'j@x.io'])), 'blank name');
    assert_true(is_string(wpultra_aff_validate(['name' => str_repeat('n', 201), 'email' => 'j@x.io'])), 'name too long');
    assert_true(is_string(wpultra_aff_validate(['name' => 'J', 'email' => 'nope'])), 'bad email');
    assert_true(is_string(wpultra_aff_validate(['name' => 'J'])), 'missing email');
    assert_true(is_string(wpultra_aff_validate(['name' => 'J', 'email' => 'j@x.io', 'code' => 'x!'])), 'bad code shape');
    assert_true(is_string(wpultra_aff_validate(['name' => 'J', 'email' => 'j@x.io', 'rate_pct' => -1])), 'rate below 0');
    assert_true(is_string(wpultra_aff_validate(['name' => 'J', 'email' => 'j@x.io', 'rate_pct' => 101])), 'rate above 100');
    assert_true(is_string(wpultra_aff_validate(['name' => 'J', 'email' => 'j@x.io', 'rate_pct' => 'ten'])), 'non-numeric rate');
});

it('validate normalizes provided codes before shape-checking (uppercase input ok)', function () {
    assert_true(wpultra_aff_validate(['name' => 'J', 'email' => 'j@x.io', 'code' => '  JOHN-1 ']));
});

/* ============================================================
 * wpultra_aff_gen_code
 * ============================================================ */

it('gen_code slugifies the name and appends the suffix', function () {
    assert_eq('john-doe-a1b2', wpultra_aff_gen_code('John Doe', 'a1b2'));
    assert_eq('john-doe', wpultra_aff_gen_code('John Doe'));
});

it('gen_code output always passes valid_code', function () {
    foreach ([
        ['', ''], ['', 'x9'], ['!!!', ''], ['a', ''], ['Zoë & Sons — Ltd.', 'k2'],
        [str_repeat('very-long-name-', 10), 'a1b2'], ['日本語', 'z9'],
    ] as [$name, $suffix]) {
        $code = wpultra_aff_gen_code($name, $suffix);
        assert_true(wpultra_aff_valid_code($code), "valid for name='$name' suffix='$suffix' got '$code'");
    }
});

it('gen_code truncates long names to fit 32 chars including the suffix', function () {
    $code = wpultra_aff_gen_code(str_repeat('abc', 20), 'a1b2');
    assert_true(strlen($code) <= 32, 'within 32 chars');
    assert_eq('a1b2', substr($code, -4), 'suffix preserved');
});

/* ============================================================
 * wpultra_aff_can_transition (lifecycle)
 * ============================================================ */

it('can_transition allows pending->approved|rejected and approved->paid only', function () {
    assert_true(wpultra_aff_can_transition('pending', 'approved'));
    assert_true(wpultra_aff_can_transition('pending', 'rejected'));
    assert_true(wpultra_aff_can_transition('approved', 'paid'));

    assert_eq(false, wpultra_aff_can_transition('pending', 'paid'), 'no pending->paid shortcut');
    assert_eq(false, wpultra_aff_can_transition('approved', 'rejected'));
    assert_eq(false, wpultra_aff_can_transition('approved', 'pending'));
    assert_eq(false, wpultra_aff_can_transition('paid', 'approved'), 'paid is terminal');
    assert_eq(false, wpultra_aff_can_transition('rejected', 'approved'), 'rejected is terminal');
    assert_eq(false, wpultra_aff_can_transition('bogus', 'approved'), 'unknown from-state');
});

/* ============================================================
 * wpultra_aff_report (payout rollup)
 * ============================================================ */

function aff_ref_row(int $aid, string $status, float $total, float $comm, string $code = 'c-' . '000'): array {
    return ['affiliate_id' => $aid, 'code' => $code, 'order_id' => 1, 'order_total' => $total, 'commission' => $comm, 'status' => $status];
}

it('report rolls up per-affiliate buckets and grand totals', function () {
    $rows = [
        aff_ref_row(7, 'pending', 100.0, 10.0, 'seven'),
        aff_ref_row(7, 'approved', 200.0, 20.0, 'seven'),
        aff_ref_row(7, 'paid', 50.0, 5.0, 'seven'),
        aff_ref_row(9, 'rejected', 80.0, 8.0, 'nine'),
        aff_ref_row(9, 'pending', 20.0, 2.0, 'nine'),
    ];
    $r = wpultra_aff_report($rows);

    $a7 = $r['affiliates'][7];
    assert_eq(100.0, $a7['pending_total']);
    assert_eq(200.0, $a7['approved_total']);
    assert_eq(50.0, $a7['paid_total']);
    assert_eq(0.0, $a7['rejected_total']);
    assert_eq(10.0, $a7['commission_pending']);
    assert_eq(20.0, $a7['commission_approved']);
    assert_eq(5.0, $a7['commission_paid']);
    assert_eq(3, $a7['referral_count']);
    assert_eq('seven', $a7['code']);
    assert_eq(7, $a7['affiliate_id']);

    $a9 = $r['affiliates'][9];
    assert_eq(80.0, $a9['rejected_total']);
    assert_eq(20.0, $a9['pending_total']);
    assert_eq(2.0, $a9['commission_pending']);
    assert_eq(2, $a9['referral_count']);

    $t = $r['totals'];
    assert_eq(120.0, $t['pending_total']);
    assert_eq(200.0, $t['approved_total']);
    assert_eq(50.0, $t['paid_total']);
    assert_eq(80.0, $t['rejected_total']);
    assert_eq(12.0, $t['commission_pending']);
    assert_eq(20.0, $t['commission_approved']);
    assert_eq(5.0, $t['commission_paid']);
    assert_eq(5, $t['referral_count']);
});

it('report on empty input returns no affiliates and zeroed totals', function () {
    $r = wpultra_aff_report([]);
    assert_eq([], $r['affiliates']);
    assert_eq(0.0, $r['totals']['pending_total']);
    assert_eq(0.0, $r['totals']['commission_paid']);
    assert_eq(0, $r['totals']['referral_count']);
});

it('report counts unknown-status rows in referral_count but no money bucket', function () {
    $r = wpultra_aff_report([aff_ref_row(3, 'weird', 500.0, 50.0)]);
    $a = $r['affiliates'][3];
    assert_eq(1, $a['referral_count']);
    assert_eq(0.0, $a['pending_total'] + $a['approved_total'] + $a['paid_total'] + $a['rejected_total']);
    assert_eq(1, $r['totals']['referral_count']);
    assert_eq(0.0, $r['totals']['pending_total']);
});

it('report rounds accumulated money to 2dp and skips non-array rows', function () {
    $rows = [
        aff_ref_row(1, 'pending', 10.111, 1.011),
        aff_ref_row(1, 'pending', 10.111, 1.011),
        'garbage',
        null,
    ];
    $r = wpultra_aff_report($rows);
    assert_eq(20.22, $r['affiliates'][1]['pending_total']);
    assert_eq(2.02, $r['affiliates'][1]['commission_pending']);
    assert_eq(2, $r['totals']['referral_count'], 'non-array rows ignored');
});

it('report tolerates missing keys (defaults to affiliate 0, zero money)', function () {
    $r = wpultra_aff_report([['status' => 'pending']]);
    assert_true(isset($r['affiliates'][0]), 'orphaned rows land under affiliate 0');
    assert_eq(0.0, $r['affiliates'][0]['pending_total']);
    assert_eq(1, $r['affiliates'][0]['referral_count']);
});

/* ============================================================
 * shapes
 * ============================================================ */

it('shape_affiliate includes email by default and casts types', function () {
    $meta = ['email' => 'j@x.io', 'code' => 'john-1', 'rate_pct' => '15', 'status' => 'active', 'clicks' => '3', 'created_at' => '2026-07-03 00:00:00'];
    $s = wpultra_aff_shape_affiliate(5, 'John', $meta);
    assert_eq(5, $s['id']);
    assert_eq('John', $s['name']);
    assert_eq('john-1', $s['code']);
    assert_eq(15.0, $s['rate_pct']);
    assert_eq(3, $s['clicks']);
    assert_eq('j@x.io', $s['email']);
});

it('shape_affiliate with include_email=false never exposes the email (report mode)', function () {
    $s = wpultra_aff_shape_affiliate(5, 'John', ['email' => 'secret@x.io', 'code' => 'john-1']);
    assert_true(array_key_exists('email', $s), 'default includes email');
    $s2 = wpultra_aff_shape_affiliate(5, 'John', ['email' => 'secret@x.io', 'code' => 'john-1'], false);
    assert_eq(false, array_key_exists('email', $s2), 'report mode omits email');
    assert_eq('john-1', $s2['code'], 'code still present');
    assert_eq('John', $s2['name'] ?? 'John');
});

it('shape_affiliate applies sane defaults for empty meta', function () {
    $s = wpultra_aff_shape_affiliate(1, 'X', []);
    assert_eq('active', $s['status']);
    assert_eq(0, $s['clicks']);
    assert_eq(0.0, $s['rate_pct']);
    assert_eq('', $s['code']);
});

it('shape_referral casts every field and defaults status to pending', function () {
    $meta = ['affiliate_id' => '7', 'code' => 'john-1', 'order_id' => '42', 'order_total' => '99.99', 'commission' => '10.0', 'status' => 'approved', 'created_at' => 't', 'note' => 'n'];
    $s = wpultra_aff_shape_referral(11, $meta);
    assert_eq(11, $s['id']);
    assert_eq(7, $s['affiliate_id']);
    assert_eq(42, $s['order_id']);
    assert_eq(99.99, $s['order_total']);
    assert_eq(10.0, $s['commission']);
    assert_eq('approved', $s['status']);
    assert_eq('n', $s['note']);

    $empty = wpultra_aff_shape_referral(1, []);
    assert_eq('pending', $empty['status']);
    assert_eq(0, $empty['order_id']);
});

/* ============================================================
 * misc pure surface
 * ============================================================ */

it('referral_statuses lists the four lifecycle states', function () {
    assert_eq(['pending', 'approved', 'rejected', 'paid'], wpultra_aff_referral_statuses());
});

it('engine defines the boot contract and CPT constants', function () {
    assert_true(function_exists('wpultra_affiliates_boot'), 'boot fn exists');
    assert_eq('wpultra_affiliate', WPULTRA_AFF_CPT);
    assert_eq('wpultra_referral', WPULTRA_AFF_REF_CPT);
    assert_true(strlen(WPULTRA_AFF_CPT) <= 20, 'post type name within the WP 20-char limit');
    assert_true(strlen(WPULTRA_AFF_REF_CPT) <= 20, 'post type name within the WP 20-char limit');
});

run_tests();
