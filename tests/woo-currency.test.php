<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_currency/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/currency.php';

/** Shared fixture: base BDT, two configured currencies, US/GB geo map. */
function cur_fixture(): array {
    return [
        'enabled' => true,
        'base'    => 'BDT',
        'currencies' => [
            'USD' => ['rate' => 0.0091, 'symbol' => '$', 'decimals' => 2],
            'GBP' => ['rate' => 0.0072],
        ],
        'geo_defaults' => ['US' => 'USD', 'GB' => 'GBP', 'BD' => 'BDT'],
    ];
}

/* ============================================================
 * wpultra_cur_normalize_code
 * ============================================================ */

it('normalize_code trims and uppercases', function () {
    assert_eq('USD', wpultra_cur_normalize_code('  usd '));
    assert_eq('EUR', wpultra_cur_normalize_code('Eur'));
    assert_eq('', wpultra_cur_normalize_code('   '));
});

/* ============================================================
 * wpultra_cur_convert
 * ============================================================ */

it('convert multiplies and rounds to 4dp: "100" @ 0.0091 → "0.91"', function () {
    assert_eq('0.91', wpultra_cur_convert('100', 0.0091));
});

it('convert rounds half-away at the 4th decimal', function () {
    assert_eq('0.9091', wpultra_cur_convert('99.9', 0.0091));   // 0.909090…
    assert_eq('123.4568', wpultra_cur_convert('123.45678', 1.0));
});

it('convert passes empty string through unchanged (unset sale price)', function () {
    assert_eq('', wpultra_cur_convert('', 2.5));
});

it('convert passes non-numeric strings through unchanged', function () {
    assert_eq('abc', wpultra_cur_convert('abc', 2.5));
    assert_eq('12,50', wpultra_cur_convert('12,50', 2.5));
});

it('convert with zero or negative rate returns the amount unchanged', function () {
    assert_eq('100', wpultra_cur_convert('100', 0.0));
    assert_eq('100', wpultra_cur_convert('100', -1.0));
});

it('convert accepts int and float amounts and returns numeric strings', function () {
    assert_eq('200', wpultra_cur_convert(100, 2.0));
    assert_eq('25.5', wpultra_cur_convert(10.2, 2.5));
});

it('convert returns "" for null and non-scalar garbage', function () {
    assert_eq('', wpultra_cur_convert(null, 2.0));
    assert_eq('', wpultra_cur_convert(['x'], 2.0));
    assert_eq('', wpultra_cur_convert(true, 2.0));
});

it('convert handles rate 1.0 as identity (numerically)', function () {
    assert_eq('99.99', wpultra_cur_convert('99.99', 1.0));
});

/* ============================================================
 * wpultra_cur_rate
 * ============================================================ */

it('rate: base currency → 1.0', function () {
    assert_eq(1.0, wpultra_cur_rate(cur_fixture(), 'BDT'));
    assert_eq(1.0, wpultra_cur_rate(cur_fixture(), ' bdt '), 'normalized before lookup');
});

it('rate: configured currency → its rate', function () {
    assert_eq(0.0091, wpultra_cur_rate(cur_fixture(), 'USD'));
    assert_eq(0.0072, wpultra_cur_rate(cur_fixture(), 'gbp'));
});

it('rate: unknown code → 0.0 (do not convert)', function () {
    assert_eq(0.0, wpultra_cur_rate(cur_fixture(), 'JPY'));
    assert_eq(0.0, wpultra_cur_rate(cur_fixture(), ''));
});

it('rate: empty config → 0.0 even for base-looking codes', function () {
    assert_eq(0.0, wpultra_cur_rate([], 'USD'));
});

/* ============================================================
 * wpultra_cur_validate_config — matrix
 * ============================================================ */

it('validate: full valid fixture passes', function () {
    assert_eq(true, wpultra_cur_validate_config(cur_fixture()));
});

it('validate: empty config passes (nothing configured yet)', function () {
    assert_eq(true, wpultra_cur_validate_config([]));
});

it('validate: enabled must be a boolean', function () {
    $r = wpultra_cur_validate_config(['enabled' => 'yes']);
    assert_true(is_string($r), 'string error expected');
    assert_contains('enabled', $r);
});

it('validate: base must be 3-letter uppercase', function () {
    assert_true(is_string(wpultra_cur_validate_config(['base' => 'usd'])));
    assert_true(is_string(wpultra_cur_validate_config(['base' => 'USDX'])));
    assert_eq(true, wpultra_cur_validate_config(['base' => 'USD']));
});

it('validate: currency codes must be 3-letter uppercase', function () {
    $r = wpultra_cur_validate_config(['currencies' => ['usd' => ['rate' => 1.0]]]);
    assert_true(is_string($r));
    $r2 = wpultra_cur_validate_config(['currencies' => ['EURO' => ['rate' => 1.0]]]);
    assert_true(is_string($r2));
});

it('validate: rate is required, numeric and > 0', function () {
    assert_true(is_string(wpultra_cur_validate_config(['currencies' => ['USD' => []]])), 'missing rate');
    assert_true(is_string(wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 'x']]])), 'non-numeric rate');
    assert_true(is_string(wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 0]]])), 'zero rate');
    assert_true(is_string(wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => -0.5]]])), 'negative rate');
    assert_eq(true, wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 0.0091]]]));
});

it('validate: decimals must be an int 0..4', function () {
    assert_true(is_string(wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 1, 'decimals' => 5]]])));
    assert_true(is_string(wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 1, 'decimals' => -1]]])));
    assert_true(is_string(wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 1, 'decimals' => 1.5]]])));
    assert_eq(true, wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 1, 'decimals' => 0]]]));
    assert_eq(true, wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 1, 'decimals' => 4]]]));
});

it('validate: symbol must be a non-empty string when present', function () {
    assert_true(is_string(wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 1, 'symbol' => '']]])));
    assert_true(is_string(wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 1, 'symbol' => 5]]])));
    assert_eq(true, wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 1, 'symbol' => '$']]]));
});

it('validate: unknown keys rejected at both levels', function () {
    $r = wpultra_cur_validate_config(['bogus' => 1]);
    assert_true(is_string($r));
    assert_contains('unknown config key', $r);
    $r2 = wpultra_cur_validate_config(['currencies' => ['USD' => ['rate' => 1, 'markup' => 5]]]);
    assert_true(is_string($r2));
    assert_contains('unknown key', $r2);
});

it('validate: base itself must not appear in the currencies map', function () {
    $cfg = ['base' => 'BDT', 'currencies' => ['BDT' => ['rate' => 1.0]]];
    $r = wpultra_cur_validate_config($cfg);
    assert_true(is_string($r));
    assert_contains('base', $r);
});

it('validate: geo_defaults country must be 2-letter uppercase', function () {
    $cfg = cur_fixture();
    $cfg['geo_defaults'] = ['usa' => 'USD'];
    assert_true(is_string(wpultra_cur_validate_config($cfg)));
    $cfg['geo_defaults'] = ['us' => 'USD'];
    assert_true(is_string(wpultra_cur_validate_config($cfg)));
});

it('validate: geo_defaults must target a configured code or base', function () {
    $cfg = cur_fixture();
    $cfg['geo_defaults'] = ['JP' => 'JPY']; // JPY not configured
    $r = wpultra_cur_validate_config($cfg);
    assert_true(is_string($r));
    assert_contains('JPY', $r);
    // base as target is fine
    $cfg['geo_defaults'] = ['BD' => 'BDT'];
    assert_eq(true, wpultra_cur_validate_config($cfg));
});

/* ============================================================
 * wpultra_cur_pick — precedence matrix
 * ============================================================ */

it('pick: valid query wins over everything', function () {
    assert_eq('GBP', wpultra_cur_pick(cur_fixture(), 'GBP', 'USD', 'US'));
});

it('pick: query is normalized (lowercase + whitespace accepted)', function () {
    assert_eq('USD', wpultra_cur_pick(cur_fixture(), ' usd ', '', ''));
});

it('pick: query may select the base explicitly (switch back)', function () {
    assert_eq('BDT', wpultra_cur_pick(cur_fixture(), 'BDT', 'USD', 'US'));
});

it('pick: invalid query falls through to a valid cookie', function () {
    assert_eq('USD', wpultra_cur_pick(cur_fixture(), 'JPY', 'USD', 'GB'));
    assert_eq('USD', wpultra_cur_pick(cur_fixture(), 'nonsense', 'usd', ''));
});

it('pick: invalid query + invalid cookie fall through to geo default', function () {
    assert_eq('GBP', wpultra_cur_pick(cur_fixture(), 'JPY', 'XXX', 'GB'));
    assert_eq('USD', wpultra_cur_pick(cur_fixture(), '', '', 'US'));
});

it('pick: unknown geo country falls through to base', function () {
    assert_eq('BDT', wpultra_cur_pick(cur_fixture(), '', '', 'DE'));
});

it('pick: geo mapping to an unconfigured code falls through to base', function () {
    $cfg = cur_fixture();
    $cfg['geo_defaults']['FR'] = 'JPY'; // invalid target (would fail validate, but pick must be defensive)
    assert_eq('BDT', wpultra_cur_pick($cfg, '', '', 'FR'));
});

it('pick: geo level skipped entirely when geo_defaults empty', function () {
    $cfg = cur_fixture();
    $cfg['geo_defaults'] = [];
    assert_eq('BDT', wpultra_cur_pick($cfg, '', '', 'US'));
});

it('pick: all empty inputs → base', function () {
    assert_eq('BDT', wpultra_cur_pick(cur_fixture(), '', '', ''));
});

it('pick: no base configured → empty string when nothing matches', function () {
    assert_eq('', wpultra_cur_pick(['currencies' => ['USD' => ['rate' => 1]]], 'JPY', '', ''));
    assert_eq('USD', wpultra_cur_pick(['currencies' => ['USD' => ['rate' => 1]]], 'USD', '', ''));
});

it('pick: cookie geo and base ignored when query valid even with geo country set', function () {
    assert_eq('USD', wpultra_cur_pick(cur_fixture(), 'USD', 'GBP', 'GB'));
});

/* ============================================================
 * wpultra_cur_preview
 * ============================================================ */

it('preview builds a converted table across every configured currency', function () {
    $t = wpultra_cur_preview(cur_fixture(), 1000.0);
    assert_eq(['USD' => '9.1', 'GBP' => '7.2'], $t);
});

it('preview with no currencies → empty table', function () {
    assert_eq([], wpultra_cur_preview(['base' => 'BDT'], 100.0));
});

it('preview skips broken specs by yielding a passthrough (rate 0 → unchanged amount)', function () {
    $cfg = ['currencies' => ['XXX' => ['nope' => 1]]];
    assert_eq(['XXX' => '50'], wpultra_cur_preview($cfg, 50.0));
});

/* ============================================================
 * wpultra_cur_switcher_html
 * ============================================================ */

it('switcher html renders a GET form with all codes and marks the selected one', function () {
    $html = wpultra_cur_switcher_html(['BDT', 'USD', 'GBP'], 'USD', '/shop/', []);
    assert_contains('method="get"', $html);
    assert_contains('action="/shop/"', $html);
    assert_contains('name="currency"', $html);
    assert_contains('<option value="BDT">BDT</option>', $html);
    assert_contains('<option value="USD" selected>USD</option>', $html);
    assert_contains('<option value="GBP">GBP</option>', $html);
});

it('switcher html preserves other query params as hidden inputs but drops currency', function () {
    $html = wpultra_cur_switcher_html(['BDT', 'USD'], 'BDT', '/shop/', ['s' => 'shoes', 'currency' => 'USD', 'page' => 2]);
    assert_contains('<input type="hidden" name="s" value="shoes">', $html);
    assert_contains('<input type="hidden" name="page" value="2">', $html);
    assert_true(substr_count($html, 'name="currency"') === 1, 'currency appears only as the select');
});

it('switcher html escapes attribute values', function () {
    $html = wpultra_cur_switcher_html(['BDT'], 'BDT', '/shop/?"><script>', ['q' => '"><img src=x>']);
    assert_true(!str_contains($html, '<script>'), 'no raw script tag');
    assert_true(!str_contains($html, '<img'), 'no raw img tag');
    assert_contains('&quot;&gt;', $html);
});

run_tests();
