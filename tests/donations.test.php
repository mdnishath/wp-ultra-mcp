<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_donations/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/verticals/donations.php';

/* ============================================================
 * progress — clamping, divide-by-zero safety
 * ============================================================ */

it('progress 0 raised -> 0 pct, full goal remaining, not reached', function () {
    $p = wpultra_donate_progress(0.0, 1000.0);
    assert_eq(0.0, $p['pct']);
    assert_eq(1000.0, $p['remaining']);
    assert_eq(false, $p['reached']);
});

it('progress partial -> rounded pct and remaining', function () {
    $p = wpultra_donate_progress(250.0, 1000.0);
    assert_eq(25.0, $p['pct']);
    assert_eq(750.0, $p['remaining']);
    assert_eq(false, $p['reached']);
});

it('progress exactly reached -> 100 pct, 0 remaining, reached true', function () {
    $p = wpultra_donate_progress(1000.0, 1000.0);
    assert_eq(100.0, $p['pct']);
    assert_eq(0.0, $p['remaining']);
    assert_eq(true, $p['reached']);
});

it('progress over 100 is clamped to 100, remaining floored at 0, reached true', function () {
    $p = wpultra_donate_progress(1500.0, 1000.0);
    assert_eq(100.0, $p['pct']);
    assert_eq(0.0, $p['remaining']);
    assert_eq(true, $p['reached']);
});

it('progress goal 0 is safe (no divide-by-zero), pct 0, not reached', function () {
    $p = wpultra_donate_progress(500.0, 0.0);
    assert_eq(0.0, $p['pct']);
    assert_eq(0.0, $p['remaining']);
    assert_eq(false, $p['reached']);
});

it('progress rounds pct to one decimal place', function () {
    $p = wpultra_donate_progress(333.0, 1000.0);
    assert_eq(33.3, $p['pct']);
});

/* ============================================================
 * sum — completed-only, distinct donors
 * ============================================================ */

it('sum counts only completed donations', function () {
    $s = wpultra_donate_sum([
        ['status' => 'completed', 'amount' => 100, 'donor' => ['email' => 'a@x.com']],
        ['status' => 'pending',   'amount' => 50,  'donor' => ['email' => 'b@x.com']],
        ['status' => 'refunded',  'amount' => 25,  'donor' => ['email' => 'c@x.com']],
        ['status' => 'failed',    'amount' => 10,  'donor' => ['email' => 'd@x.com']],
    ]);
    assert_eq(100.0, $s['raised']);
    assert_eq(1, $s['count']);
    assert_eq(1, $s['donor_count']);
});

it('sum counts distinct donor emails case-insensitively', function () {
    $s = wpultra_donate_sum([
        ['status' => 'completed', 'amount' => 40, 'donor' => ['email' => 'Same@X.com']],
        ['status' => 'completed', 'amount' => 60, 'donor' => ['email' => 'same@x.com']],
        ['status' => 'completed', 'amount' => 10, 'donor' => ['email' => 'other@x.com']],
    ]);
    assert_eq(110.0, $s['raised']);
    assert_eq(3, $s['count']);
    assert_eq(2, $s['donor_count']);
});

it('sum with an empty/emailless completed donation still counts amount but not donor', function () {
    $s = wpultra_donate_sum([
        ['status' => 'completed', 'amount' => 20, 'donor' => ['email' => '']],
        ['status' => 'completed', 'amount' => 30],
    ]);
    assert_eq(50.0, $s['raised']);
    assert_eq(2, $s['count']);
    assert_eq(0, $s['donor_count']);
});

it('sum of an empty list is zero', function () {
    $s = wpultra_donate_sum([]);
    assert_eq(0.0, $s['raised']);
    assert_eq(0, $s['count']);
    assert_eq(0, $s['donor_count']);
});

/* ============================================================
 * validate — donation input
 * ============================================================ */

it('validate accepts a good donation', function () {
    assert_eq(true, wpultra_donate_validate([
        'amount' => 25, 'donor' => ['email' => 'rahim@example.com'], 'recurring' => 'monthly', 'currency' => 'USD',
    ]));
});

it('validate rejects amount <= 0', function () {
    assert_true(is_string(wpultra_donate_validate(['amount' => 0, 'donor' => ['email' => 'a@x.com']])));
    assert_true(is_string(wpultra_donate_validate(['amount' => -5, 'donor' => ['email' => 'a@x.com']])));
});

it('validate rejects a bad email', function () {
    $r = wpultra_donate_validate(['amount' => 10, 'donor' => ['email' => 'not-an-email']]);
    assert_true(is_string($r));
    assert_contains('email', $r);
});

it('validate rejects an unknown recurring value', function () {
    $r = wpultra_donate_validate(['amount' => 10, 'donor' => ['email' => 'a@x.com'], 'recurring' => 'weekly']);
    assert_true(is_string($r));
    assert_contains('recurring', $r);
});

it('validate rejects a non-3-letter currency', function () {
    $r = wpultra_donate_validate(['amount' => 10, 'donor' => ['email' => 'a@x.com'], 'currency' => 'DOLLARS']);
    assert_true(is_string($r));
    assert_contains('currency', $r);
});

it('validate defaults recurring to none and currency to USD', function () {
    assert_eq(true, wpultra_donate_validate(['amount' => 10, 'donor' => ['email' => 'a@x.com']]));
});

/* ============================================================
 * validate_campaign
 * ============================================================ */

it('validate_campaign accepts a good campaign', function () {
    assert_eq(true, wpultra_donate_validate_campaign([
        'goal_amount' => 5000, 'currency' => 'EUR', 'status' => 'active', 'deadline' => null,
    ]));
});

it('validate_campaign rejects goal <= 0', function () {
    assert_true(is_string(wpultra_donate_validate_campaign(['goal_amount' => 0])));
    assert_true(is_string(wpultra_donate_validate_campaign(['goal_amount' => -100])));
});

it('validate_campaign rejects an unknown status', function () {
    $r = wpultra_donate_validate_campaign(['goal_amount' => 100, 'status' => 'paused']);
    assert_true(is_string($r));
    assert_contains('status', $r);
});

it('validate_campaign rejects a non-numeric deadline', function () {
    $r = wpultra_donate_validate_campaign(['goal_amount' => 100, 'deadline' => 'tomorrow']);
    assert_true(is_string($r));
    assert_contains('deadline', $r);
});

it('validate_campaign accepts a null deadline and a future unix deadline', function () {
    assert_eq(true, wpultra_donate_validate_campaign(['goal_amount' => 100, 'deadline' => null]));
    assert_eq(true, wpultra_donate_validate_campaign(['goal_amount' => 100, 'deadline' => 4102444800]));
});

/* ============================================================
 * next_charge
 * ============================================================ */

it('next_charge none -> null', function () {
    assert_eq(null, wpultra_donate_next_charge('none', 1000, 1000));
});

it('next_charge monthly advances ~30 days from a not-yet-passed base', function () {
    $now = 1_000_000;
    $next = wpultra_donate_next_charge('monthly', $now, $now);
    assert_eq($now + WPULTRA_DONATE_MONTH_SECS, $next);
});

it('next_charge yearly advances ~365 days', function () {
    $now = 2_000_000;
    $next = wpultra_donate_next_charge('yearly', $now, $now);
    assert_eq($now + WPULTRA_DONATE_YEAR_SECS, $next);
});

it('next_charge catches up one step when the base is far in the past', function () {
    $from = 0;
    $now  = 100_000_000;
    $next = wpultra_donate_next_charge('monthly', $from, $now);
    assert_eq($now + WPULTRA_DONATE_MONTH_SECS, $next);
    assert_true($next > $now);
});

/* ============================================================
 * recurring_due
 * ============================================================ */

it('recurring_due picks due, completed, recurring donations only', function () {
    $now = 1_000_000;
    $due = wpultra_donate_recurring_due([
        ['recurring' => 'monthly', 'status' => 'completed', 'next_charge' => $now - 10],  // DUE
        ['recurring' => 'monthly', 'status' => 'completed', 'next_charge' => $now + 999],  // future
        ['recurring' => 'none',    'status' => 'completed', 'next_charge' => $now - 10],    // not recurring
        ['recurring' => 'yearly',  'status' => 'pending',   'next_charge' => $now - 10],    // not completed
        ['recurring' => 'monthly', 'status' => 'completed', 'next_charge' => null],         // no schedule
    ], $now);
    assert_eq(1, count($due));
    assert_eq('monthly', $due[0]['recurring']);
    assert_eq($now - 10, $due[0]['next_charge']);
});

it('recurring_due includes a donation due exactly at now', function () {
    $now = 500;
    $due = wpultra_donate_recurring_due([
        ['recurring' => 'yearly', 'status' => 'completed', 'next_charge' => $now],
    ], $now);
    assert_eq(1, count($due));
});

/* ============================================================
 * is_expired / status
 * ============================================================ */

it('is_expired is false when deadline is null/missing', function () {
    assert_eq(false, wpultra_donate_is_expired(['deadline' => null], 1000));
    assert_eq(false, wpultra_donate_is_expired([], 1000));
});

it('is_expired boundary: deadline == now is expired, deadline > now is not', function () {
    assert_eq(true,  wpultra_donate_is_expired(['deadline' => 1000], 1000));
    assert_eq(true,  wpultra_donate_is_expired(['deadline' => 999], 1000));
    assert_eq(false, wpultra_donate_is_expired(['deadline' => 1001], 1000));
});

it('status returns completed when goal reached', function () {
    assert_eq('completed', wpultra_donate_status(['goal_amount' => 100, 'raised' => 100], 1000));
    assert_eq('completed', wpultra_donate_status(['goal_amount' => 100, 'raised' => 150], 1000));
});

it('status returns closed when deadline passed and goal not reached', function () {
    assert_eq('closed', wpultra_donate_status(['goal_amount' => 100, 'raised' => 10, 'deadline' => 500], 1000));
});

it('status returns active for an ongoing under-goal campaign', function () {
    assert_eq('active', wpultra_donate_status(['goal_amount' => 100, 'raised' => 10, 'deadline' => 5000], 1000));
});

it('status keeps an explicitly closed campaign closed even when goal reached', function () {
    assert_eq('closed', wpultra_donate_status(['goal_amount' => 100, 'raised' => 200, 'status' => 'closed'], 1000));
});

/* ============================================================
 * receipt_html — escaping + content
 * ============================================================ */

it('receipt_html includes donor, amount, campaign, date and a tax note', function () {
    $html = wpultra_donate_receipt_html(
        ['donor' => ['name' => 'Rahim'], 'amount' => 50, 'currency' => 'USD', 'created' => 1_600_000_000],
        ['title' => 'Rebuild the shelter']
    );
    assert_contains('Rahim', $html);
    assert_contains('USD 50.00', $html);
    assert_contains('Rebuild the shelter', $html);
    assert_contains('2020-09-13', $html);
    assert_contains('tax', $html);
});

it('receipt_html escapes HTML in the donor name and campaign title', function () {
    $html = wpultra_donate_receipt_html(
        ['donor' => ['name' => '<script>x</script>'], 'amount' => 10, 'currency' => 'usd', 'created' => 1_600_000_000],
        ['title' => 'A & B <b>bold</b>']
    );
    assert_true(!str_contains($html, '<script>'), 'raw script tag must not appear');
    assert_contains('&lt;script&gt;', $html);
    assert_contains('&amp;', $html);
});

it('receipt_html shows Anonymous donor when anonymous flag set', function () {
    $html = wpultra_donate_receipt_html(
        ['donor' => ['name' => 'Secret Person'], 'amount' => 5, 'currency' => 'USD', 'anonymous' => true, 'created' => 1_600_000_000],
        ['title' => 'Cause']
    );
    assert_contains('Anonymous donor', $html);
    assert_true(!str_contains($html, 'Secret Person'), 'anonymous donor name hidden');
});

it('receipt_html includes a gateway reference when present', function () {
    $html = wpultra_donate_receipt_html(
        ['donor' => ['name' => 'X'], 'amount' => 5, 'currency' => 'USD', 'gateway_ref' => 'pi_abc123', 'created' => 1_600_000_000],
        ['title' => 'Cause']
    );
    assert_contains('pi_abc123', $html);
});

/* ============================================================
 * tiers_suggest
 * ============================================================ */

it('tiers_suggest returns a sensible ascending list under the goal', function () {
    $tiers = wpultra_donate_tiers_suggest(1000.0);
    assert_true(count($tiers) >= 3, 'at least 3 tiers');
    assert_true(count($tiers) <= 6, 'at most 6 tiers');
    // Ascending
    $sorted = $tiers;
    sort($sorted);
    assert_eq($sorted, $tiers);
    // All under the goal and positive
    foreach ($tiers as $t) {
        assert_true($t > 0 && $t < 1000.0, "tier $t within (0, goal)");
    }
});

it('tiers_suggest falls back to a floor for a tiny or zero goal', function () {
    assert_eq([10.0, 25.0, 50.0], wpultra_donate_tiers_suggest(0.0));
    assert_eq([10.0, 25.0, 50.0], wpultra_donate_tiers_suggest(-100.0));
});

it('tiers_suggest scales up for a large goal', function () {
    $tiers = wpultra_donate_tiers_suggest(100000.0);
    assert_true(count($tiers) >= 3);
    // Largest suggested tier should be meaningfully bigger than the small-goal floor.
    assert_true(max($tiers) > 100.0, 'large goal yields larger tiers');
    foreach ($tiers as $t) { assert_true($t < 100000.0); }
});

/* ============================================================
 * new_donation_meta — shape + computed next_charge
 * ============================================================ */

it('new_donation_meta computes next_charge for recurring and null for one-time', function () {
    $now = 1_000_000;
    $one = wpultra_donate_new_donation_meta(5, ['name' => 'A', 'email' => 'A@X.com'], 10.0, 'usd', 'none', $now);
    assert_eq(null, $one['next_charge']);
    assert_eq('usd', strtolower($one['currency']));
    assert_eq('USD', $one['currency']);
    assert_eq('a@x.com', $one['donor']['email']); // lowercased
    assert_eq('pending', $one['status']);

    $rec = wpultra_donate_new_donation_meta(5, ['email' => 'b@x.com'], 10.0, 'USD', 'monthly', $now);
    assert_eq($now + WPULTRA_DONATE_MONTH_SECS, $rec['next_charge']);
});

it('new_donation_meta coerces an unknown recurring/status to safe defaults', function () {
    $m = wpultra_donate_new_donation_meta(1, ['email' => 'a@x.com'], 5.0, 'USD', 'weekly', 1000, false, 'weird');
    assert_eq('none', $m['recurring']);
    assert_eq('pending', $m['status']);
    assert_eq(null, $m['next_charge']);
});

run_tests();
