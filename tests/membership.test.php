<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_membership/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/verticals/membership.php';

/* ============================================================
 * new_id — deterministic minting via injected randomness.
 * ============================================================ */

it('new_id mints an lvl- prefixed 6-hex id from injected bytes', function () {
    $id = wpultra_member_new_id(fn(int $n) => str_repeat("\x3f", $n)); // 3 bytes of 0x3f
    assert_eq('lvl-3f3f3f', $id);
});

it('new_id pads a short random source to a stable id', function () {
    $id = wpultra_member_new_id(fn(int $n) => "\x0a"); // only 1 byte -> "0a"
    assert_eq('lvl-0a0000', $id);
});

/* ============================================================
 * rule_matches — by id / category / type, AND across dimensions.
 * ============================================================ */

it('rule_matches by post id', function () {
    $rule = ['match' => ['post_ids' => [10, 20]]];
    assert_true(wpultra_member_rule_matches($rule, ['id' => 20, 'categories' => [], 'post_type' => 'post']));
    assert_true(!wpultra_member_rule_matches($rule, ['id' => 30, 'categories' => [], 'post_type' => 'post']));
});

it('rule_matches by category (OR within the dimension)', function () {
    $rule = ['match' => ['categories' => [5, 7]]];
    assert_true(wpultra_member_rule_matches($rule, ['id' => 1, 'categories' => [7, 99], 'post_type' => 'post']));
    assert_true(!wpultra_member_rule_matches($rule, ['id' => 1, 'categories' => [100], 'post_type' => 'post']));
});

it('rule_matches by post type', function () {
    $rule = ['match' => ['post_types' => ['product']]];
    assert_true(wpultra_member_rule_matches($rule, ['id' => 1, 'categories' => [], 'post_type' => 'product']));
    assert_true(!wpultra_member_rule_matches($rule, ['id' => 1, 'categories' => [], 'post_type' => 'post']));
});

it('rule_matches requires EVERY specified dimension (AND)', function () {
    $rule = ['match' => ['categories' => [5], 'post_types' => ['post']]];
    // category matches but type does not -> no match
    assert_true(!wpultra_member_rule_matches($rule, ['id' => 1, 'categories' => [5], 'post_type' => 'page']));
    // both match
    assert_true(wpultra_member_rule_matches($rule, ['id' => 1, 'categories' => [5], 'post_type' => 'post']));
});

it('rule_matches returns false for an empty match clause (targets nothing)', function () {
    assert_true(!wpultra_member_rule_matches(['match' => []], ['id' => 1, 'categories' => [1], 'post_type' => 'post']));
    assert_true(!wpultra_member_rule_matches([], ['id' => 1, 'categories' => [1], 'post_type' => 'post']));
});

/* ============================================================
 * can_access — the core security decision, every branch.
 * ============================================================ */

$NOW = 1_700_000_000;

it('can_access denies no_membership when member is empty', function () use ($NOW) {
    $d = wpultra_member_can_access([], ['require_level' => 'any', 'drip_days' => 0], $NOW);
    assert_true($d['allowed'] === false);
    assert_eq('no_membership', $d['reason']);
});

it('can_access treats a member with no level_id as no_membership', function () use ($NOW) {
    $d = wpultra_member_can_access(['since' => 1], ['require_level' => 'any', 'drip_days' => 0], $NOW);
    assert_eq('no_membership', $d['reason']);
});

it('can_access denies expired when expires < now', function () use ($NOW) {
    $member = ['level_id' => 'lvl-a', 'since' => 1, 'expires' => $NOW - 10, 'status' => 'active'];
    $d = wpultra_member_can_access($member, ['require_level' => 'any', 'drip_days' => 0], $NOW);
    assert_true($d['allowed'] === false);
    assert_eq('expired', $d['reason']);
});

it('can_access denies expired when status is cancelled even if not time-expired', function () use ($NOW) {
    $member = ['level_id' => 'lvl-a', 'since' => 1, 'expires' => null, 'status' => 'cancelled'];
    $d = wpultra_member_can_access($member, ['require_level' => 'any', 'drip_days' => 0], $NOW);
    assert_eq('expired', $d['reason']);
});

it('can_access denies wrong_level when member level != require_level', function () use ($NOW) {
    $member = ['level_id' => 'lvl-basic', 'since' => 1, 'expires' => null, 'status' => 'active'];
    $d = wpultra_member_can_access($member, ['require_level' => 'lvl-gold', 'drip_days' => 0], $NOW);
    assert_true($d['allowed'] === false);
    assert_eq('wrong_level', $d['reason']);
});

it('can_access allows when require_level is "any" and member is active', function () use ($NOW) {
    $member = ['level_id' => 'lvl-basic', 'since' => 1, 'expires' => null, 'status' => 'active'];
    $d = wpultra_member_can_access($member, ['require_level' => 'any', 'drip_days' => 0], $NOW);
    assert_true($d['allowed'] === true);
    assert_eq('ok', $d['reason']);
});

it('can_access denies dripping with unlock_at when drip window not yet elapsed', function () use ($NOW) {
    $since = $NOW - (2 * 86400); // joined 2 days ago
    $member = ['level_id' => 'lvl-a', 'since' => $since, 'expires' => null, 'status' => 'active'];
    $d = wpultra_member_can_access($member, ['require_level' => 'any', 'drip_days' => 7], $NOW);
    assert_true($d['allowed'] === false);
    assert_eq('dripping', $d['reason']);
    assert_eq($since + 7 * 86400, $d['unlock_at']);
});

it('can_access allows once the drip window has elapsed', function () use ($NOW) {
    $since = $NOW - (10 * 86400); // joined 10 days ago
    $member = ['level_id' => 'lvl-a', 'since' => $since, 'expires' => null, 'status' => 'active'];
    $d = wpultra_member_can_access($member, ['require_level' => 'any', 'drip_days' => 7], $NOW);
    assert_true($d['allowed'] === true);
    assert_eq('ok', $d['reason']);
    assert_true(!isset($d['unlock_at']));
});

it('can_access unlocks exactly at the boundary (now == unlock_at)', function () use ($NOW) {
    $since = $NOW - (7 * 86400); // exactly the drip window
    $member = ['level_id' => 'lvl-a', 'since' => $since, 'expires' => null, 'status' => 'active'];
    $d = wpultra_member_can_access($member, ['require_level' => 'any', 'drip_days' => 7], $NOW);
    assert_true($d['allowed'] === true, 'now == unlock_at grants access');
});

it('can_access happy path: right level, not expired, no drip', function () use ($NOW) {
    $member = ['level_id' => 'lvl-gold', 'since' => 1, 'expires' => $NOW + 999, 'status' => 'active'];
    $d = wpultra_member_can_access($member, ['require_level' => 'lvl-gold', 'drip_days' => 0], $NOW);
    assert_true($d['allowed'] === true);
    assert_eq('ok', $d['reason']);
});

it('can_access checks expiry BEFORE level (an expired gold member is expired, not wrong_level)', function () use ($NOW) {
    $member = ['level_id' => 'lvl-basic', 'since' => 1, 'expires' => $NOW - 1, 'status' => 'active'];
    $d = wpultra_member_can_access($member, ['require_level' => 'lvl-gold', 'drip_days' => 0], $NOW);
    assert_eq('expired', $d['reason']);
});

/* ============================================================
 * teaser — word count, no mid-tag cut, marker present.
 * ============================================================ */

it('teaser keeps the first N words as plain text', function () {
    $out = wpultra_member_teaser('one two three four five six', 3);
    assert_contains('one two three', $out);
    assert_true(!str_contains($out, 'four'), 'words past the limit are dropped');
});

it('teaser appends an ellipsis when content was truncated', function () {
    $out = wpultra_member_teaser('one two three four', 2);
    assert_contains('…', $out);
});

it('teaser strips HTML tags — never cuts mid-tag', function () {
    $html = '<p><strong>Hello</strong> beautiful <em>world</em> of membership content here</p>';
    $out = wpultra_member_teaser($html, 3);
    // No source tags survive into the teaser body.
    assert_true(!str_contains($out, '<strong>'), 'no <strong> tag leaked');
    assert_true(!str_contains($out, '<em>'), 'no <em> tag leaked');
    assert_contains('Hello beautiful world', $out);
});

it('teaser always includes the paywall marker', function () {
    $out = wpultra_member_teaser('some words', 1);
    assert_contains('data-wpultra-paywall="1"', $out);
});

it('teaser with 0 words emits only the paywall marker (no teaser body)', function () {
    $out = wpultra_member_teaser('some words here', 0);
    assert_true(!str_contains($out, 'wpultra-member-teaser'), 'no teaser body div');
    assert_contains('data-wpultra-paywall="1"', $out);
});

it('teaser strips shortcodes and block comments', function () {
    $c = "<!-- wp:paragraph -->[gallery id=1]Real words follow now<!-- /wp:paragraph -->";
    $out = wpultra_member_teaser($c, 2);
    assert_true(!str_contains($out, 'wp:paragraph'), 'block comment stripped');
    assert_true(!str_contains($out, 'gallery'), 'shortcode stripped');
    assert_contains('Real words', $out);
});

/* ============================================================
 * is_expired + status.
 * ============================================================ */

it('is_expired: null/absent expires means never expires', function () use ($NOW) {
    assert_true(wpultra_member_is_expired(['expires' => null], $NOW) === false);
    assert_true(wpultra_member_is_expired([], $NOW) === false);
    assert_true(wpultra_member_is_expired(['expires' => ''], $NOW) === false);
});

it('is_expired: past expiry is expired, future is not', function () use ($NOW) {
    assert_true(wpultra_member_is_expired(['expires' => $NOW - 1], $NOW) === true);
    assert_true(wpultra_member_is_expired(['expires' => $NOW + 1], $NOW) === false);
});

it('status returns active / expired / cancelled correctly', function () use ($NOW) {
    assert_eq('active', wpultra_member_status(['expires' => $NOW + 100, 'status' => 'active'], $NOW));
    assert_eq('expired', wpultra_member_status(['expires' => $NOW - 100, 'status' => 'active'], $NOW));
    assert_eq('cancelled', wpultra_member_status(['expires' => $NOW + 100, 'status' => 'cancelled'], $NOW));
    assert_eq('active', wpultra_member_status(['expires' => null], $NOW), 'lifetime with no status = active');
});

/* ============================================================
 * validate_level.
 * ============================================================ */

it('validate_level accepts a well-formed level', function () {
    assert_true(wpultra_member_validate_level(['id' => 'lvl-a', 'name' => 'Gold', 'price' => 9.99, 'period' => 'month']) === true);
});

it('validate_level rejects a missing id', function () {
    $r = wpultra_member_validate_level(['name' => 'Gold', 'period' => 'month']);
    assert_true(is_string($r) && str_contains($r, 'id'));
});

it('validate_level rejects a missing name', function () {
    $r = wpultra_member_validate_level(['id' => 'lvl-a', 'name' => '  ', 'period' => 'month']);
    assert_true(is_string($r) && str_contains($r, 'name'));
});

it('validate_level rejects a bad period', function () {
    $r = wpultra_member_validate_level(['id' => 'lvl-a', 'name' => 'Gold', 'period' => 'weekly']);
    assert_true(is_string($r) && str_contains($r, 'period'));
});

it('validate_level rejects a negative price', function () {
    $r = wpultra_member_validate_level(['id' => 'lvl-a', 'name' => 'Gold', 'period' => 'month', 'price' => -1]);
    assert_true(is_string($r) && str_contains($r, 'price'));
});

it('validate_level accepts a lifetime period with no price', function () {
    assert_true(wpultra_member_validate_level(['id' => 'lvl-a', 'name' => 'Founder', 'period' => 'lifetime']) === true);
});

/* ============================================================
 * validate_rule.
 * ============================================================ */

it('validate_rule accepts a well-formed rule targeting a known level', function () {
    $r = wpultra_member_validate_rule(
        ['id' => 'rule-a', 'match' => ['categories' => [5]], 'require_level' => 'lvl-gold', 'drip_days' => 7],
        ['lvl-gold', 'lvl-basic']
    );
    assert_true($r === true);
});

it('validate_rule accepts require_level "any"', function () {
    $r = wpultra_member_validate_rule(['id' => 'rule-a', 'match' => ['post_types' => ['post']], 'require_level' => 'any'], []);
    assert_true($r === true);
});

it('validate_rule rejects a require_level referencing an unknown level', function () {
    $r = wpultra_member_validate_rule(
        ['id' => 'rule-a', 'match' => ['categories' => [5]], 'require_level' => 'lvl-ghost'],
        ['lvl-gold']
    );
    assert_true(is_string($r) && str_contains($r, 'lvl-ghost'));
});

it('validate_rule rejects an empty match clause', function () {
    $r = wpultra_member_validate_rule(['id' => 'rule-a', 'match' => [], 'require_level' => 'any'], []);
    assert_true(is_string($r) && str_contains($r, 'match'));
});

it('validate_rule rejects a missing id', function () {
    $r = wpultra_member_validate_rule(['match' => ['post_ids' => [1]]], []);
    assert_true(is_string($r) && str_contains($r, 'id'));
});

it('validate_rule rejects a negative drip_days', function () {
    $r = wpultra_member_validate_rule(
        ['id' => 'rule-a', 'match' => ['post_ids' => [1]], 'require_level' => 'any', 'drip_days' => -3],
        []
    );
    assert_true(is_string($r) && str_contains($r, 'drip_days'));
});

/* ============================================================
 * normalize helpers.
 * ============================================================ */

it('normalize_rule coerces types and drops unknown keys', function () {
    $r = wpultra_member_normalize_rule([
        'id' => 'rule-a',
        'match' => ['post_ids' => ['10', '20'], 'categories' => [3], 'extra' => 'x'],
        'require_level' => 'lvl-a',
        'drip_days' => '5',
        'teaser_words' => '40',
        'junk' => 'ignored',
    ]);
    assert_eq([10, 20], $r['match']['post_ids']);
    assert_eq(5, $r['drip_days']);
    assert_eq(40, $r['teaser_words']);
    assert_true(!isset($r['junk']), 'unknown key dropped');
    assert_true(!isset($r['match']['extra']), 'unknown match dimension dropped');
});

it('normalize_level defaults price to 0 and period to month for bad input', function () {
    $l = wpultra_member_normalize_level(['id' => 'lvl-a', 'name' => ' Gold ', 'period' => 'bogus']);
    assert_eq('Gold', $l['name']);
    assert_eq(0.0, $l['price']);
    assert_eq('month', $l['period']);
});

run_tests();
