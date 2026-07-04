<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_pricing/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/pricing.php';

/* ============================================================
 * Small builders.
 * ============================================================ */

function rule_tiered(array $over = []): array {
    return array_merge([
        'id'      => 'pr-tier01',
        'name'    => 'Tiered',
        'enabled' => true,
        'type'    => 'tiered_qty',
        'scope'   => ['products' => 'all'],
        'config'  => ['tiers' => [['min_qty' => 3, 'discount_pct' => 10], ['min_qty' => 10, 'discount_pct' => 20]]],
    ], $over);
}

function rule_role(array $over = []): array {
    return array_merge([
        'id'      => 'pr-role01',
        'name'    => 'Wholesale',
        'enabled' => true,
        'type'    => 'role_price',
        'scope'   => ['products' => 'all'],
        'config'  => ['role' => 'wholesale', 'discount_pct' => 20],
    ], $over);
}

function rule_bogo(array $over = []): array {
    return array_merge([
        'id'      => 'pr-bogo01',
        'name'    => 'Buy 2 Get 1',
        'enabled' => true,
        'type'    => 'bogo',
        'scope'   => ['products' => [2]],
        'config'  => ['buy_qty' => 2, 'get_qty' => 1, 'discount_pct' => 100],
    ], $over);
}

function rule_cart(array $over = []): array {
    return array_merge([
        'id'      => 'pr-cart01',
        'name'    => 'Spend & Save',
        'enabled' => true,
        'type'    => 'cart_discount',
        'scope'   => ['products' => 'all'],
        'config'  => ['min_total' => 100, 'discount_pct' => 5],
    ], $over);
}

/* ============================================================
 * new_id
 * ============================================================ */

it('new_id has pr- prefix + 6 lowercase alnum chars', function () {
    $id = wpultra_pricing_new_id();
    assert_true((bool) preg_match('/^pr-[a-z0-9]{6}$/', $id), "format: $id");
});

it('new_id is deterministic through the injected rand callable', function () {
    assert_eq('pr-aaaaaa', wpultra_pricing_new_id(fn (int $min, int $max): int => 0));
    assert_eq('pr-999999', wpultra_pricing_new_id(fn (int $min, int $max): int => 35));
});

/* ============================================================
 * validate
 * ============================================================ */

it('validate accepts a well-formed tiered_qty rule', function () {
    assert_eq(true, wpultra_pricing_validate(rule_tiered()));
});

it('validate accepts well-formed bogo / cart_discount / role_price rules', function () {
    assert_eq(true, wpultra_pricing_validate(rule_bogo()));
    assert_eq(true, wpultra_pricing_validate(rule_cart()));
    assert_eq(true, wpultra_pricing_validate(rule_role()));
    // cart_discount with flat amount instead of pct.
    assert_eq(true, wpultra_pricing_validate(rule_cart(['config' => ['min_total' => 50, 'amount' => 10]])));
});

it('validate rejects empty / missing name', function () {
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['name' => '']))));
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['name' => '   ']))));
});

it('validate rejects unknown type', function () {
    $err = wpultra_pricing_validate(rule_tiered(['type' => 'flash_sale']));
    assert_true(is_string($err));
    assert_contains('type must be one of', $err);
});

it('validate rejects bad scope shapes', function () {
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['scope' => 'all']))), 'scope must be array');
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['scope' => ['products' => 'some']]))), "products string other than 'all'");
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['scope' => ['products' => []]]))), 'empty products array');
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['scope' => ['products' => [0]]]))), 'non-positive id');
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['scope' => ['products' => ['abc']]]))), 'non-int id');
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['scope' => ['products' => 'all', 'categories' => 'tea']]))), 'categories must be array');
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['scope' => ['products' => 'all', 'categories' => ['']]]))), 'empty slug');
});

it('validate accepts scope with product ids and categories', function () {
    assert_eq(true, wpultra_pricing_validate(rule_tiered(['scope' => ['products' => [1, 2, 3], 'categories' => ['tea', 'coffee']]])));
});

it('validate tiered_qty: rejects empty tiers, min_qty < 1, non-ascending, dup, pct out of range', function () {
    $mk = fn (array $tiers) => rule_tiered(['config' => ['tiers' => $tiers]]);
    assert_true(is_string(wpultra_pricing_validate(rule_tiered(['config' => []]))), 'missing tiers');
    assert_true(is_string(wpultra_pricing_validate($mk([]))), 'empty tiers');
    assert_true(is_string(wpultra_pricing_validate($mk([['min_qty' => 0, 'discount_pct' => 10]]))), 'min_qty 0');
    assert_true(is_string(wpultra_pricing_validate($mk([['min_qty' => 5, 'discount_pct' => 10], ['min_qty' => 3, 'discount_pct' => 20]]))), 'descending');
    assert_true(is_string(wpultra_pricing_validate($mk([['min_qty' => 5, 'discount_pct' => 10], ['min_qty' => 5, 'discount_pct' => 20]]))), 'duplicate');
    assert_true(is_string(wpultra_pricing_validate($mk([['min_qty' => 3, 'discount_pct' => 101]]))), 'pct > 100');
    assert_true(is_string(wpultra_pricing_validate($mk([['min_qty' => 3, 'discount_pct' => -1]]))), 'pct < 0');
});

it('validate bogo: rejects buy/get < 1 and pct out of range', function () {
    assert_true(is_string(wpultra_pricing_validate(rule_bogo(['config' => ['buy_qty' => 0, 'get_qty' => 1, 'discount_pct' => 50]]))));
    assert_true(is_string(wpultra_pricing_validate(rule_bogo(['config' => ['buy_qty' => 2, 'get_qty' => 0, 'discount_pct' => 50]]))));
    assert_true(is_string(wpultra_pricing_validate(rule_bogo(['config' => ['buy_qty' => 2, 'get_qty' => 1, 'discount_pct' => 101]]))));
});

it('validate cart_discount: exactly one of discount_pct/amount, min_total >= 0', function () {
    $err = wpultra_pricing_validate(rule_cart(['config' => ['min_total' => 100, 'discount_pct' => 5, 'amount' => 10]]));
    assert_true(is_string($err), 'both pct and amount');
    assert_contains('exactly one', $err);
    assert_true(is_string(wpultra_pricing_validate(rule_cart(['config' => ['min_total' => 100]]))), 'neither');
    assert_true(is_string(wpultra_pricing_validate(rule_cart(['config' => ['min_total' => -1, 'discount_pct' => 5]]))), 'negative min_total');
    assert_true(is_string(wpultra_pricing_validate(rule_cart(['config' => ['min_total' => 100, 'amount' => 0]]))), 'amount 0');
});

it('validate role_price: rejects empty role and pct out of range', function () {
    assert_true(is_string(wpultra_pricing_validate(rule_role(['config' => ['role' => '', 'discount_pct' => 10]]))));
    assert_true(is_string(wpultra_pricing_validate(rule_role(['config' => ['role' => 'wholesale', 'discount_pct' => 120]]))));
});

/* ============================================================
 * scope_match
 * ============================================================ */

it("scope_match: products 'all' matches any product", function () {
    assert_eq(true, wpultra_pricing_scope_match(['products' => 'all'], 42, []));
});

it('scope_match: id whitelist', function () {
    assert_eq(true, wpultra_pricing_scope_match(['products' => [1, 42]], 42, []));
    assert_eq(false, wpultra_pricing_scope_match(['products' => [1, 42]], 7, []));
});

it('scope_match: non-empty categories are an ADDITIONAL constraint', function () {
    $scope = ['products' => 'all', 'categories' => ['tea']];
    assert_eq(true, wpultra_pricing_scope_match($scope, 42, ['tea', 'drinks']));
    assert_eq(false, wpultra_pricing_scope_match($scope, 42, ['coffee']));
    assert_eq(false, wpultra_pricing_scope_match($scope, 42, []), 'product with no categories');
    // Both product id AND category must match.
    $scope2 = ['products' => [42], 'categories' => ['tea']];
    assert_eq(true, wpultra_pricing_scope_match($scope2, 42, ['tea']));
    assert_eq(false, wpultra_pricing_scope_match($scope2, 42, ['coffee']), 'id ok, cat mismatch');
    assert_eq(false, wpultra_pricing_scope_match($scope2, 7, ['tea']), 'cat ok, id mismatch');
});

it('scope_match: empty categories array is ignored', function () {
    assert_eq(true, wpultra_pricing_scope_match(['products' => 'all', 'categories' => []], 42, []));
});

/* ============================================================
 * tier_pct
 * ============================================================ */

it('tier_pct: 0.0 below the lowest tier', function () {
    assert_eq(0.0, wpultra_pricing_tier_pct([['min_qty' => 3, 'discount_pct' => 10]], 2));
});

it('tier_pct: boundary is inclusive', function () {
    assert_eq(10.0, wpultra_pricing_tier_pct([['min_qty' => 3, 'discount_pct' => 10]], 3));
});

it('tier_pct: highest matching min_qty wins', function () {
    $tiers = [['min_qty' => 3, 'discount_pct' => 10], ['min_qty' => 10, 'discount_pct' => 20]];
    assert_eq(10.0, wpultra_pricing_tier_pct($tiers, 9));
    assert_eq(20.0, wpultra_pricing_tier_pct($tiers, 10));
    assert_eq(20.0, wpultra_pricing_tier_pct($tiers, 100));
});

it('tier_pct: order-independent (unsorted tiers still resolve correctly)', function () {
    $tiers = [['min_qty' => 10, 'discount_pct' => 20], ['min_qty' => 3, 'discount_pct' => 10]];
    assert_eq(10.0, wpultra_pricing_tier_pct($tiers, 5));
    assert_eq(20.0, wpultra_pricing_tier_pct($tiers, 12));
});

/* ============================================================
 * bogo_discount
 * ============================================================ */

it('bogo: no discount below one complete group', function () {
    assert_eq(0.0, wpultra_pricing_bogo_discount(2, 2, 1, 100.0, 20.0), 'qty 2 < group 3');
    assert_eq(0.0, wpultra_pricing_bogo_discount(0, 2, 1, 100.0, 20.0));
});

it('bogo: one complete group, 100% = one unit free', function () {
    assert_eq(20.0, wpultra_pricing_bogo_discount(3, 2, 1, 100.0, 20.0));
});

it('bogo: incomplete second group earns nothing extra', function () {
    assert_eq(20.0, wpultra_pricing_bogo_discount(5, 2, 1, 100.0, 20.0), 'qty 5 = 1 group + 2 spare');
});

it('bogo: multiple complete groups multiply', function () {
    assert_eq(40.0, wpultra_pricing_bogo_discount(6, 2, 1, 100.0, 20.0));
    // buy 3 get 2 at 50% off, qty 10 → 2 groups × 2 units × 5.00 × 50% = 10.00
    assert_eq(10.0, wpultra_pricing_bogo_discount(10, 3, 2, 50.0, 5.0));
});

it('bogo: rounds to 2dp', function () {
    // 1 group × 1 unit × 9.99 × 33% = 3.2967 → 3.30
    assert_eq(3.3, wpultra_pricing_bogo_discount(3, 2, 1, 33.0, 9.99));
});

it('bogo: guards degenerate inputs', function () {
    assert_eq(0.0, wpultra_pricing_bogo_discount(10, 0, 1, 100.0, 20.0), 'buy 0');
    assert_eq(0.0, wpultra_pricing_bogo_discount(10, 2, 0, 100.0, 20.0), 'get 0');
    assert_eq(0.0, wpultra_pricing_bogo_discount(10, 2, 1, 0.0, 20.0), 'pct 0');
    assert_eq(0.0, wpultra_pricing_bogo_discount(10, 2, 1, 100.0, 0.0), 'free product');
});

/* ============================================================
 * cart_discount
 * ============================================================ */

it('cart_discount: 0 below threshold, inclusive at threshold', function () {
    $cfg = ['min_total' => 100, 'discount_pct' => 5];
    assert_eq(0.0, wpultra_pricing_cart_discount(99.99, $cfg));
    assert_eq(5.0, wpultra_pricing_cart_discount(100.0, $cfg));
});

it('cart_discount: percent of subtotal, rounded 2dp', function () {
    assert_eq(7.5, wpultra_pricing_cart_discount(150.0, ['min_total' => 100, 'discount_pct' => 5]));
    assert_eq(12.35, wpultra_pricing_cart_discount(123.45, ['min_total' => 0, 'discount_pct' => 10]));
});

it('cart_discount: flat amount, capped at the subtotal', function () {
    assert_eq(10.0, wpultra_pricing_cart_discount(150.0, ['min_total' => 100, 'amount' => 10]));
    assert_eq(120.0, wpultra_pricing_cart_discount(120.0, ['min_total' => 100, 'amount' => 500]), 'cap at subtotal');
});

it('cart_discount: 0 for empty/zero subtotal', function () {
    assert_eq(0.0, wpultra_pricing_cart_discount(0.0, ['min_total' => 0, 'discount_pct' => 50]));
});

/* ============================================================
 * best_item_pct
 * ============================================================ */

it('best_item_pct: picks the largest pct across tiered + role rules (no stacking)', function () {
    $rules = ['a' => rule_tiered(), 'b' => rule_role()];
    // qty 3 → tier 10%; wholesale role → 20%; best = 20 (NOT 30).
    assert_eq(20.0, wpultra_pricing_best_item_pct($rules, 1, [], 'wholesale', 3));
    // qty 10 → tier 20%; role 20% → still 20.
    assert_eq(20.0, wpultra_pricing_best_item_pct($rules, 1, [], 'wholesale', 10));
    // Non-wholesale: only the tier applies.
    assert_eq(10.0, wpultra_pricing_best_item_pct($rules, 1, [], 'customer', 3));
});

it('best_item_pct: ignores disabled rules', function () {
    $rules = ['a' => rule_tiered(['enabled' => false])];
    assert_eq(0.0, wpultra_pricing_best_item_pct($rules, 1, [], '', 10));
});

it('best_item_pct: ignores rules whose scope does not match', function () {
    $rules = ['a' => rule_tiered(['scope' => ['products' => [99]]])];
    assert_eq(0.0, wpultra_pricing_best_item_pct($rules, 1, [], '', 10));
    assert_eq(20.0, wpultra_pricing_best_item_pct($rules, 99, [], '', 10));
});

it('best_item_pct: role rule requires the exact role (and a logged-in role at all)', function () {
    $rules = ['a' => rule_role()];
    assert_eq(0.0, wpultra_pricing_best_item_pct($rules, 1, [], 'customer', 1));
    assert_eq(0.0, wpultra_pricing_best_item_pct($rules, 1, [], '', 1), 'logged out');
    assert_eq(20.0, wpultra_pricing_best_item_pct($rules, 1, [], 'wholesale', 1));
});

it('best_item_pct: bogo and cart_discount rules never contribute item pct', function () {
    $rules = ['a' => rule_bogo(['scope' => ['products' => 'all']]), 'b' => rule_cart()];
    assert_eq(0.0, wpultra_pricing_best_item_pct($rules, 2, [], 'wholesale', 10));
});

it('best_item_pct: clamps to 100', function () {
    $rules = ['a' => rule_role(['config' => ['role' => 'vip', 'discount_pct' => 250]])]; // corrupt stored value
    assert_eq(100.0, wpultra_pricing_best_item_pct($rules, 1, [], 'vip', 1));
});

/* ============================================================
 * preview — the dry-run heart.
 * ============================================================ */

function preview_rules(): array {
    return [
        'pr-tier01' => rule_tiered(),                          // 10% @3+, 20% @10+ — all products
        'pr-role01' => rule_role(),                            // wholesale 20% — all products
        'pr-bogo01' => rule_bogo(),                            // buy2get1 free — product 2 only
        'pr-cart01' => rule_cart(),                            // 5% off when subtotal >= 100
    ];
}

function preview_cart(): array {
    return [
        ['product_id' => 1, 'qty' => 3, 'price' => 10.0, 'categories' => []],
        ['product_id' => 2, 'qty' => 3, 'price' => 20.0, 'categories' => []],
    ];
}

it('preview: multi-rule multi-line cart as a plain customer', function () {
    $p = wpultra_pricing_preview(preview_rules(), preview_cart(), 'customer');

    assert_eq(2, count($p['lines']));
    // Line 1: qty 3 → tier 10% → 30.00 → 27.00
    assert_eq(1, $p['lines'][0]['product_id']);
    assert_eq(30.0, $p['lines'][0]['original_line_total']);
    assert_eq(10.0, $p['lines'][0]['discount_pct']);
    assert_eq(27.0, $p['lines'][0]['discounted_line_total']);
    // Line 2: qty 3 → tier 10% too (rule is all-products) → 60.00 → 54.00
    assert_eq(2, $p['lines'][1]['product_id']);
    assert_eq(60.0, $p['lines'][1]['original_line_total']);
    assert_eq(10.0, $p['lines'][1]['discount_pct']);
    assert_eq(54.0, $p['lines'][1]['discounted_line_total']);

    // Fees: bogo on product 2 — 1 group, 1 unit free at the DISCOUNTED unit (18.00).
    // cart_discount: item subtotal 81.00 < 100 → no fee.
    assert_eq(1, count($p['fees']));
    assert_eq('Buy 2 Get 1', $p['fees'][0]['label']);
    assert_eq(-18.0, $p['fees'][0]['amount']);

    assert_eq(90.0, $p['totals']['before']);
    assert_eq(27.0, $p['totals']['discount']); // 9 item + 18 bogo
    assert_eq(63.0, $p['totals']['after']);
});

it('preview: wholesale role beats the tier (best single pct, no stacking)', function () {
    $p = wpultra_pricing_preview(preview_rules(), preview_cart(), 'wholesale');
    assert_eq(20.0, $p['lines'][0]['discount_pct']);
    assert_eq(24.0, $p['lines'][0]['discounted_line_total']);
    assert_eq(20.0, $p['lines'][1]['discount_pct']);
    assert_eq(48.0, $p['lines'][1]['discounted_line_total']);
    // bogo unit is the discounted 16.00.
    assert_eq(-16.0, $p['fees'][0]['amount']);
    assert_eq(90.0, $p['totals']['before']);
    assert_eq(34.0, $p['totals']['discount']); // 18 item + 16 bogo
    assert_eq(56.0, $p['totals']['after']);
});

it('preview: cart_discount fee triggers off the item-discounted subtotal', function () {
    // Bigger cart: 12 × 10.00 → tier 20% → 96.00; plus 1 × 10.00 (no tier) → 10.00. Subtotal 106.00 >= 100.
    $cart = [
        ['product_id' => 1, 'qty' => 12, 'price' => 10.0, 'categories' => []],
        ['product_id' => 3, 'qty' => 1, 'price' => 10.0, 'categories' => []],
    ];
    $p = wpultra_pricing_preview(['t' => rule_tiered(), 'c' => rule_cart()], $cart, '');
    assert_eq(1, count($p['fees']));
    assert_eq('Spend & Save', $p['fees'][0]['label']);
    assert_eq(-5.3, $p['fees'][0]['amount']); // 5% of 106.00
    assert_eq(130.0, $p['totals']['before']);
    assert_eq(29.3, $p['totals']['discount']); // 24 item + 5.30 fee
    assert_eq(100.7, $p['totals']['after']);
});

it('preview: flat-amount cart discount is capped at the subtotal', function () {
    $rules = ['c' => rule_cart(['config' => ['min_total' => 10, 'amount' => 500]])];
    $cart = [['product_id' => 1, 'qty' => 2, 'price' => 15.0, 'categories' => []]];
    $p = wpultra_pricing_preview($rules, $cart, '');
    assert_eq(-30.0, $p['fees'][0]['amount']);
    assert_eq(0.0, $p['totals']['after']);
});

it('preview: category-scoped rule only touches lines with a matching slug', function () {
    $rules = ['t' => rule_tiered(['scope' => ['products' => 'all', 'categories' => ['tea']]])];
    $cart = [
        ['product_id' => 1, 'qty' => 5, 'price' => 10.0, 'categories' => ['tea']],
        ['product_id' => 2, 'qty' => 5, 'price' => 10.0, 'categories' => ['coffee']],
    ];
    $p = wpultra_pricing_preview($rules, $cart, '');
    assert_eq(10.0, $p['lines'][0]['discount_pct']);
    assert_eq(0.0, $p['lines'][1]['discount_pct']);
});

it('preview: disabled rules do nothing', function () {
    $rules = [
        't' => rule_tiered(['enabled' => false]),
        'b' => rule_bogo(['enabled' => false]),
        'c' => rule_cart(['enabled' => false]),
    ];
    $p = wpultra_pricing_preview($rules, preview_cart(), 'wholesale');
    assert_eq(0.0, $p['lines'][0]['discount_pct']);
    assert_eq([], $p['fees']);
    assert_eq(90.0, $p['totals']['before']);
    assert_eq(0.0, $p['totals']['discount']);
    assert_eq(90.0, $p['totals']['after']);
});

it('preview: skips malformed lines (qty < 1, non-array)', function () {
    $cart = [
        'junk',
        ['product_id' => 1, 'qty' => 0, 'price' => 10.0, 'categories' => []],
        ['product_id' => 1, 'qty' => 2, 'price' => 10.0, 'categories' => []],
    ];
    $p = wpultra_pricing_preview([], $cart, '');
    assert_eq(1, count($p['lines']));
    assert_eq(20.0, $p['totals']['before']);
});

it('preview: two fee rules produce two separate labelled fees', function () {
    $rules = [
        'b' => rule_bogo(['scope' => ['products' => 'all']]),
        'c' => rule_cart(['config' => ['min_total' => 0, 'discount_pct' => 10]]),
    ];
    $cart = [['product_id' => 5, 'qty' => 3, 'price' => 10.0, 'categories' => []]];
    $p = wpultra_pricing_preview($rules, $cart, '');
    assert_eq(2, count($p['fees']));
    assert_eq('Buy 2 Get 1', $p['fees'][0]['label']);
    assert_eq(-10.0, $p['fees'][0]['amount']);
    assert_eq('Spend & Save', $p['fees'][1]['label']);
    assert_eq(-3.0, $p['fees'][1]['amount']); // 10% of the 30.00 item subtotal (fees don't feed each other)
    assert_eq(17.0, $p['totals']['after']);
});

it('preview: empty rules / empty cart are safe', function () {
    $p = wpultra_pricing_preview([], [], '');
    assert_eq([], $p['lines']);
    assert_eq([], $p['fees']);
    assert_eq(['before' => 0.0, 'discount' => 0.0, 'after' => 0.0], $p['totals']);
});

/* ============================================================
 * summarize
 * ============================================================ */

it('summarize: compact list row with scope summary', function () {
    $s = wpultra_pricing_summarize(rule_tiered(['created_at' => '2026-07-04 00:00:00']));
    assert_eq('pr-tier01', $s['id']);
    assert_eq('tiered_qty', $s['type']);
    assert_eq(true, $s['enabled']);
    assert_eq('all products', $s['scope']);
    assert_eq('2026-07-04 00:00:00', $s['created_at']);

    $s2 = wpultra_pricing_summarize(rule_bogo(['scope' => ['products' => [2, 3], 'categories' => ['tea']]]));
    assert_eq('2 product(s); categories: tea', $s2['scope']);
    assert_eq(false, wpultra_pricing_summarize(rule_bogo(['enabled' => false]))['enabled']);
});

/* ============================================================
 * intish helper
 * ============================================================ */

it('intish accepts ints, whole floats, digit strings; rejects the rest', function () {
    assert_eq(true, wpultra_pricing_intish(5));
    assert_eq(true, wpultra_pricing_intish(5.0));
    assert_eq(true, wpultra_pricing_intish('5'));
    assert_eq(false, wpultra_pricing_intish(5.5));
    assert_eq(false, wpultra_pricing_intish('5.5'));
    assert_eq(false, wpultra_pricing_intish('abc'));
    assert_eq(false, wpultra_pricing_intish(null));
    assert_eq(false, wpultra_pricing_intish([]));
});

run_tests();
