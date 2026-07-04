<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_loyalty/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses the WP_Error stub from the harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/loyalty.php';

/* ============================================================
 * wpultra_loyalty_earn — floor(total x rate), never negative.
 * ============================================================ */

it('earn floors the product of total and rate', function () {
    assert_eq(1234, wpultra_loyalty_earn(1234.56, 1.0));
    assert_eq(99, wpultra_loyalty_earn(99.99, 1.0));
});

it('earn applies fractional rates', function () {
    assert_eq(100, wpultra_loyalty_earn(200.0, 0.5));
    assert_eq(49, wpultra_loyalty_earn(99.0, 0.5)); // 49.5 -> floor 49
});

it('earn with rate above 1 multiplies up', function () {
    assert_eq(250, wpultra_loyalty_earn(50.0, 5.0));
});

it('earn returns 0 for zero or negative total', function () {
    assert_eq(0, wpultra_loyalty_earn(0.0, 1.0));
    assert_eq(0, wpultra_loyalty_earn(-100.0, 1.0));
});

it('earn returns 0 for zero or negative rate', function () {
    assert_eq(0, wpultra_loyalty_earn(100.0, 0.0));
    assert_eq(0, wpultra_loyalty_earn(100.0, -1.0));
});

it('earn on a sub-unit total with rate 1 is 0 (floor)', function () {
    assert_eq(0, wpultra_loyalty_earn(0.99, 1.0));
});

/* ============================================================
 * wpultra_loyalty_redeem_value — points x rate, 2dp, never negative.
 * ============================================================ */

it('redeem_value computes points x rate rounded to 2dp', function () {
    assert_eq(1.0, wpultra_loyalty_redeem_value(100, 0.01));
    assert_eq(5.0, wpultra_loyalty_redeem_value(333, 0.015)); // 4.995 -> 5.00
    assert_eq(4.99, wpultra_loyalty_redeem_value(499, 0.01));
});

it('redeem_value returns 0.0 for zero/negative points or rate', function () {
    assert_eq(0.0, wpultra_loyalty_redeem_value(0, 0.01));
    assert_eq(0.0, wpultra_loyalty_redeem_value(-50, 0.01));
    assert_eq(0.0, wpultra_loyalty_redeem_value(100, 0.0));
    assert_eq(0.0, wpultra_loyalty_redeem_value(100, -0.01));
});

/* ============================================================
 * wpultra_loyalty_can_redeem — error-string contract.
 * ============================================================ */

it('can_redeem returns true when points within [min, balance]', function () {
    assert_true(wpultra_loyalty_can_redeem(100, 500, 100));
    assert_true(wpultra_loyalty_can_redeem(500, 500, 100));
});

it('can_redeem returns below_minimum when points < min', function () {
    assert_eq('below_minimum', wpultra_loyalty_can_redeem(99, 500, 100));
    assert_eq('below_minimum', wpultra_loyalty_can_redeem(0, 500, 100));
    // Negative points also rejected as below_minimum.
    assert_eq('below_minimum', wpultra_loyalty_can_redeem(-10, 500, 1));
});

it('can_redeem returns insufficient_balance when points > balance', function () {
    assert_eq('insufficient_balance', wpultra_loyalty_can_redeem(501, 500, 100));
});

it('can_redeem returns not_integer for fractional or non-numeric points', function () {
    assert_eq('not_integer', wpultra_loyalty_can_redeem(10.5, 500, 1));
    assert_eq('not_integer', wpultra_loyalty_can_redeem('10.5', 500, 1));
    assert_eq('not_integer', wpultra_loyalty_can_redeem('abc', 500, 1));
    assert_eq('not_integer', wpultra_loyalty_can_redeem(true, 500, 1));
});

it('can_redeem accepts integer-valued numeric strings and floats', function () {
    assert_true(wpultra_loyalty_can_redeem('150', 500, 100));
    assert_true(wpultra_loyalty_can_redeem(150.0, 500, 100));
});

/* ============================================================
 * wpultra_loyalty_ledger_push — newest last, capped ring buffer.
 * ============================================================ */

it('ledger_push appends the entry newest LAST with normalized fields', function () {
    $l = wpultra_loyalty_ledger_push([], ['at' => 1000, 'delta' => 50, 'reason' => 'order', 'ref' => 'order:7']);
    $l = wpultra_loyalty_ledger_push($l, ['at' => 2000, 'delta' => -20, 'reason' => 'redeem', 'ref' => 'coupon:pts-abc']);
    assert_eq(2, count($l));
    assert_eq(['at' => 1000, 'delta' => 50, 'reason' => 'order', 'ref' => 'order:7'], $l[0]);
    assert_eq(['at' => 2000, 'delta' => -20, 'reason' => 'redeem', 'ref' => 'coupon:pts-abc'], $l[1]);
});

it('ledger_push caps at 200 keeping the NEWEST entries', function () {
    $l = [];
    for ($i = 1; $i <= 205; $i++) {
        $l = wpultra_loyalty_ledger_push($l, ['at' => $i, 'delta' => 1, 'reason' => 'r', 'ref' => "x:$i"]);
    }
    assert_eq(200, count($l));
    assert_eq(6, $l[0]['at'], 'oldest 5 dropped');
    assert_eq(205, $l[199]['at'], 'newest kept last');
});

it('ledger_push honors a custom cap and normalizes a nonsense cap', function () {
    $l = [];
    for ($i = 1; $i <= 5; $i++) {
        $l = wpultra_loyalty_ledger_push($l, ['at' => $i, 'delta' => 1], 3);
    }
    assert_eq(3, count($l));
    assert_eq(3, $l[0]['at']);
    assert_eq(5, $l[2]['at']);
    // cap < 1 falls back to the default 200 rather than emptying the ledger.
    $l2 = wpultra_loyalty_ledger_push([['at' => 1, 'delta' => 1, 'reason' => '', 'ref' => '']], ['at' => 2, 'delta' => 1], 0);
    assert_eq(2, count($l2));
});

it('ledger_push fills missing entry fields with defaults', function () {
    $l = wpultra_loyalty_ledger_push([], ['delta' => 5]);
    assert_eq(5, $l[0]['delta']);
    assert_eq('', $l[0]['reason']);
    assert_eq('', $l[0]['ref']);
    assert_true($l[0]['at'] > 0, 'at defaults to now');
});

/* ============================================================
 * wpultra_loyalty_code — '<prefix>-xxxxxxxx' with injectable rand.
 * ============================================================ */

it('code produces pts-xxxxxxxx (8 lowercase alnum) with a deterministic rand', function () {
    $seq = [0, 1, 2, 3, 10, 20, 30, 35]; // indexes into a-z0-9
    $i = 0;
    $rand = function (int $lo, int $hi) use (&$seq, &$i): int { return $seq[$i++]; };
    assert_eq('pts-abcdku49', wpultra_loyalty_code('pts', $rand));
});

it('code respects the gift prefix', function () {
    $rand = function (int $lo, int $hi): int { return 0; };
    assert_eq('gift-aaaaaaaa', wpultra_loyalty_code('gift', $rand));
});

it('code is deterministic for the same injected rand sequence', function () {
    $mk = function () {
        $i = 0;
        return function (int $lo, int $hi) use (&$i): int { return (7 * $i++) % ($hi + 1); };
    };
    assert_eq(wpultra_loyalty_code('pts', $mk()), wpultra_loyalty_code('pts', $mk()));
});

it('code with the default (secure) rand matches the required format', function () {
    for ($n = 0; $n < 20; $n++) {
        $c = wpultra_loyalty_code('pts');
        assert_true((bool) preg_match('/^pts-[a-z0-9]{8}$/', $c), "format: $c");
        $g = wpultra_loyalty_code('gift');
        assert_true((bool) preg_match('/^gift-[a-z0-9]{8}$/', $g), "format: $g");
    }
});

/* ============================================================
 * wpultra_loyalty_validate_config / merge_config / defaults.
 * ============================================================ */

it('default_config has the documented defaults', function () {
    $d = wpultra_loyalty_default_config();
    assert_eq(false, $d['enabled']);
    assert_eq(1.0, $d['earn_rate']);
    assert_eq(0.01, $d['redeem_rate']);
    assert_eq(100, $d['min_redeem']);
    assert_eq('completed', $d['award_on']);
});

it('validate_config accepts a full valid patch', function () {
    assert_true(wpultra_loyalty_validate_config([
        'enabled' => true, 'earn_rate' => 2, 'redeem_rate' => 0.02, 'min_redeem' => 50, 'award_on' => 'processing',
    ]));
    assert_true(wpultra_loyalty_validate_config([])); // empty patch is fine
});

it('validate_config rejects non-positive rates', function () {
    assert_contains('earn_rate', (string) wpultra_loyalty_validate_config(['earn_rate' => 0]));
    assert_contains('earn_rate', (string) wpultra_loyalty_validate_config(['earn_rate' => -1]));
    assert_contains('redeem_rate', (string) wpultra_loyalty_validate_config(['redeem_rate' => 0]));
    assert_contains('redeem_rate', (string) wpultra_loyalty_validate_config(['redeem_rate' => 'x']));
});

it('validate_config rejects min_redeem < 1 or non-integer', function () {
    assert_contains('min_redeem', (string) wpultra_loyalty_validate_config(['min_redeem' => 0]));
    assert_contains('min_redeem', (string) wpultra_loyalty_validate_config(['min_redeem' => 10.5]));
    assert_true(wpultra_loyalty_validate_config(['min_redeem' => 1]));
});

it('validate_config rejects a bad award_on and a non-bool enabled', function () {
    assert_contains('award_on', (string) wpultra_loyalty_validate_config(['award_on' => 'shipped']));
    assert_contains('enabled', (string) wpultra_loyalty_validate_config(['enabled' => 'yes']));
});

it('validate_config rejects unknown keys', function () {
    assert_contains('unknown config key', (string) wpultra_loyalty_validate_config(['bonus_rate' => 2]));
});

it('merge_config layers patch over current over defaults and normalizes types', function () {
    $cfg = wpultra_loyalty_merge_config(['earn_rate' => 2, 'enabled' => true], ['min_redeem' => '50']);
    assert_eq(true, $cfg['enabled']);
    assert_eq(2.0, $cfg['earn_rate']);
    assert_eq(0.01, $cfg['redeem_rate'], 'untouched key keeps default');
    assert_eq(50, $cfg['min_redeem']);
    assert_eq('completed', $cfg['award_on']);
    // A junk award_on already in storage normalizes back to completed.
    $cfg2 = wpultra_loyalty_merge_config(['award_on' => 'garbage'], []);
    assert_eq('completed', $cfg2['award_on']);
});

/* ============================================================
 * wpultra_loyalty_gift_html — escaped template.
 * ============================================================ */

it('gift_html includes amount, code, note, and store name', function () {
    $html = wpultra_loyalty_gift_html([
        'amount' => '1000.00', 'currency' => 'BDT', 'code' => 'gift-a1b2c3d4',
        'note' => 'Happy birthday!', 'store' => 'My Shop',
    ]);
    assert_contains('1000.00', $html);
    assert_contains('BDT', $html);
    assert_contains('gift-a1b2c3d4', $html);
    assert_contains('Happy birthday!', $html);
    assert_contains('My Shop', $html);
});

it('gift_html escapes an XSS attempt in the note (no raw <script>)', function () {
    $html = wpultra_loyalty_gift_html([
        'amount' => '10', 'code' => 'gift-aaaaaaaa',
        'note' => '<script>alert(1)</script>', 'store' => 's',
    ]);
    assert_true(!str_contains($html, '<script>'), 'raw script tag must not appear');
    assert_contains('&lt;script&gt;', $html);
});

it('gift_html escapes hostile store name and code too', function () {
    $html = wpultra_loyalty_gift_html([
        'amount' => '10', 'code' => '"><img src=x onerror=alert(1)>',
        'note' => '', 'store' => '<b>Store</b>',
    ]);
    assert_true(!str_contains($html, '<img'), 'raw img injection must not appear');
    assert_true(!str_contains($html, '<b>Store</b>'), 'raw store markup must not appear');
    assert_contains('&lt;b&gt;Store&lt;/b&gt;', $html);
});

it('gift_html omits the note block when the note is empty', function () {
    $html = wpultra_loyalty_gift_html(['amount' => '10', 'code' => 'gift-aaaaaaaa', 'note' => '', 'store' => '']);
    assert_true(!str_contains($html, '&ldquo;'), 'no empty quoted note');
});

run_tests();
