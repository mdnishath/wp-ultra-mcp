<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_wishlist/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/wishlist.php';

/* ============================================================
 * wpultra_wl_sanitize_ids
 * ============================================================ */

it('sanitize_ids keeps positive ints, drops garbage, dedupes, preserves order', function () {
    $in = [3, '7', 0, -4, 'abc', 3.0, 3, null, [], '12', true, 5.5];
    assert_eq([3, 7, 12], wpultra_wl_sanitize_ids($in));
});

it('sanitize_ids applies the cap', function () {
    assert_eq([1, 2, 3], wpultra_wl_sanitize_ids([1, 2, 3, 4, 5], 3));
});

it('sanitize_ids cap 0 means unlimited', function () {
    assert_eq([1, 2, 3, 4, 5], wpultra_wl_sanitize_ids([1, 2, 3, 4, 5], 0));
});

/* ============================================================
 * wpultra_wl_add / wpultra_wl_remove
 * ============================================================ */

it('add appends a new product id', function () {
    assert_eq([1, 2, 9], wpultra_wl_add([1, 2], 9, 100));
});

it('add to an empty list works', function () {
    assert_eq([42], wpultra_wl_add([], 42, 30));
});

it('add dedupes an already-present id', function () {
    assert_eq([1, 2, 3], wpultra_wl_add([1, 2, 3], 2, 100));
});

it('add ignores non-positive product ids', function () {
    assert_eq([1, 2], wpultra_wl_add([1, 2], 0, 100));
    assert_eq([1, 2], wpultra_wl_add([1, 2], -7, 100));
});

it('add silently drops the new id when the list is at cap', function () {
    assert_eq([1, 2, 3], wpultra_wl_add([1, 2, 3], 9, 3));
});

it('add sanitizes garbage in the incoming list first', function () {
    assert_eq([1, 2, 5], wpultra_wl_add(['1', 'junk', 0, 2, 2], 5, 100));
});

it('add trims an oversize incoming list to the cap before adding', function () {
    // 5 ids, cap 3 -> sanitized to [1,2,3], already full, add dropped.
    assert_eq([1, 2, 3], wpultra_wl_add([1, 2, 3, 4, 5], 9, 3));
});

it('remove drops a present id and keeps order', function () {
    assert_eq([1, 3], wpultra_wl_remove([1, 2, 3], 2));
});

it('remove of an absent id is a no-op', function () {
    assert_eq([1, 2, 3], wpultra_wl_remove([1, 2, 3], 99));
});

it('remove sanitizes garbage on the way through', function () {
    assert_eq([4], wpultra_wl_remove(['x', 0, -1, 4, '5', 5], 5));
});

/* ============================================================
 * cookie parse / serialize
 * ============================================================ */

it('parse_cookie parses a clean comma list', function () {
    assert_eq([12, 34, 56], wpultra_wl_parse_cookie('12,34,56', 30));
});

it('parse_cookie drops garbage tokens (non-numeric, zero, negative, float, dupes, empties)', function () {
    assert_eq([3, 8], wpultra_wl_parse_cookie('abc,0,-5,2.5,3,,3,8,1e3', 30));
});

it('parse_cookie applies the guest cap', function () {
    assert_eq([1, 2, 3], wpultra_wl_parse_cookie('1,2,3,4,5', 3));
});

it('parse_cookie of an empty/whitespace string is an empty list', function () {
    assert_eq([], wpultra_wl_parse_cookie('', 30));
    assert_eq([], wpultra_wl_parse_cookie('   ', 30));
});

it('to_cookie serializes and sanitizes', function () {
    assert_eq('1,2,3', wpultra_wl_to_cookie([1, 2, 3]));
    assert_eq('7,9', wpultra_wl_to_cookie(['7', 'junk', 0, 9, 9]));
    assert_eq('', wpultra_wl_to_cookie([]));
});

it('cookie round-trip is stable: parse(to_cookie(parse(raw))) === parse(raw)', function () {
    $raw = '5,junk,5,0,11,-2,42';
    $once = wpultra_wl_parse_cookie($raw, 30);
    $twice = wpultra_wl_parse_cookie(wpultra_wl_to_cookie($once), 30);
    assert_eq($once, $twice);
    assert_eq([5, 11, 42], $once);
});

/* ============================================================
 * wpultra_wl_subs_add / wpultra_wl_subs_remove
 * ============================================================ */

it('subs_add happy path lowercases and appends', function () {
    $out = wpultra_wl_subs_add(['a@b.com'], 'New.Person@Example.COM', 500);
    assert_eq(['a@b.com', 'new.person@example.com'], $out);
});

it('subs_add rejects an invalid email with the error string', function () {
    assert_eq('invalid_email', wpultra_wl_subs_add([], 'not-an-email', 500));
    assert_eq('invalid_email', wpultra_wl_subs_add([], '', 500));
    assert_eq('invalid_email', wpultra_wl_subs_add([], 'a@b', 500));
});

it('subs_add detects an existing subscriber case-insensitively', function () {
    assert_eq('already_subscribed', wpultra_wl_subs_add(['A@B.com'], 'a@b.com', 500));
});

it('subs_add returns cap_reached when the list is full', function () {
    assert_eq('cap_reached', wpultra_wl_subs_add(['a@x.com', 'b@x.com'], 'c@x.com', 2));
});

it('subs_add sanitizes prior garbage in the stored list', function () {
    $out = wpultra_wl_subs_add(['ok@x.com', 'broken', 42, '  OK@X.COM  '], 'new@x.com', 500);
    assert_eq(['ok@x.com', 'new@x.com'], $out);
});

it('subs_remove removes case-insensitively and is a no-op for absent emails', function () {
    assert_eq(['b@x.com'], wpultra_wl_subs_remove(['A@X.com', 'b@x.com'], 'a@x.com'));
    assert_eq(['a@x.com'], wpultra_wl_subs_remove(['a@x.com'], 'ghost@x.com'));
});

/* ============================================================
 * wpultra_wl_notify_html — escaping
 * ============================================================ */

it('notify_html escapes an XSS product name', function () {
    $html = wpultra_wl_notify_html([
        'name'  => '<script>alert(1)</script>',
        'price' => '99.00',
        'url'   => 'https://shop.test/p/1',
        'store' => 'My Shop',
    ]);
    assert_true(strpos($html, '<script>') === false, 'raw script tag must not survive');
    assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
});

it('notify_html includes price, url, and store (escaped)', function () {
    $html = wpultra_wl_notify_html([
        'name'  => 'Widget',
        'price' => "\u{09F3}1,200", // BDT taka sign
        'url'   => 'https://shop.test/p/9?a=1&b=2',
        'store' => 'Shop & Co',
    ]);
    assert_contains('Widget is back in stock!', $html);
    assert_contains("\u{09F3}1,200", $html);
    assert_contains('https://shop.test/p/9?a=1&amp;b=2', $html);
    assert_contains('Shop &amp; Co', $html);
});

it('notify_html neutralizes attribute-breakout attempts in the url', function () {
    $html = wpultra_wl_notify_html([
        'name'  => 'X',
        'price' => '',
        'url'   => '"><script>evil()</script>',
        'store' => '',
    ]);
    assert_true(strpos($html, '<script>evil') === false, 'url must not break out of href attribute');
    assert_contains('&quot;&gt;&lt;script&gt;', $html);
});

it('notify_html omits the price row when price is empty and falls back to "this store"', function () {
    $html = wpultra_wl_notify_html(['name' => 'X', 'price' => '', 'url' => 'https://a.test', 'store' => '']);
    assert_true(strpos($html, 'Price:') === false, 'no price row');
    assert_contains('this store', $html);
});

/* ============================================================
 * wpultra_wl_top — analytics
 * ============================================================ */

it('top counts across wishlists and sorts descending', function () {
    $out = wpultra_wl_top([[1, 2, 3], [2, 3], [3]]);
    assert_eq([
        ['product_id' => 3, 'count' => 3],
        ['product_id' => 2, 'count' => 2],
        ['product_id' => 1, 'count' => 1],
    ], $out);
});

it('top breaks count ties by ascending product_id', function () {
    $out = wpultra_wl_top([[9, 4], [4, 9], [7]]);
    assert_eq([
        ['product_id' => 4, 'count' => 2],
        ['product_id' => 9, 'count' => 2],
        ['product_id' => 7, 'count' => 1],
    ], $out);
});

it('top counts a product at most once per wishlist', function () {
    $out = wpultra_wl_top([[5, 5, 5], [5]]);
    assert_eq([['product_id' => 5, 'count' => 2]], $out);
});

it('top ignores garbage entries and non-array wishlists', function () {
    $out = wpultra_wl_top([[0, -1, 'junk', 2], 'not-a-list', null, [2]]);
    assert_eq([['product_id' => 2, 'count' => 2]], $out);
});

it('top of no wishlists is an empty list', function () {
    assert_eq([], wpultra_wl_top([]));
    assert_eq([], wpultra_wl_top([[], []]));
});

run_tests();
