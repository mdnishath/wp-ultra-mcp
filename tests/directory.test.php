<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_directory/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/verticals/directory.php';

/* ============================================================
 * haversine — the map / near-me core.
 * ============================================================ */

it('haversine same point is 0', function () {
    assert_eq(0.0, round(wpultra_dir_haversine(40.7128, -74.0060, 40.7128, -74.0060), 6));
});

it('haversine NYC -> LA ~3936 km', function () {
    // New York (40.7128,-74.0060) to Los Angeles (34.0522,-118.2437).
    $d = wpultra_dir_haversine(40.7128, -74.0060, 34.0522, -118.2437);
    assert_true($d > 3900 && $d < 3970, "NYC-LA distance was $d km");
});

it('haversine London -> Paris ~343 km', function () {
    $d = wpultra_dir_haversine(51.5074, -0.1278, 48.8566, 2.3522);
    assert_true($d > 330 && $d < 355, "London-Paris distance was $d km");
});

it('haversine is symmetric', function () {
    $ab = wpultra_dir_haversine(51.5074, -0.1278, 48.8566, 2.3522);
    $ba = wpultra_dir_haversine(48.8566, 2.3522, 51.5074, -0.1278);
    assert_eq(round($ab, 8), round($ba, 8));
});

it('haversine one degree of latitude ~111 km', function () {
    $d = wpultra_dir_haversine(0.0, 0.0, 1.0, 0.0);
    assert_true($d > 110 && $d < 112, "one degree lat was $d km");
});

/* ============================================================
 * within_radius
 * ============================================================ */

it('within_radius true when inside', function () {
    // ~0.11 km north of origin.
    $listing = ['lat' => 0.001, 'lng' => 0.0];
    assert_true(wpultra_dir_within_radius($listing, 0.0, 0.0, 1.0));
});

it('within_radius false when outside', function () {
    $listing = ['lat' => 1.0, 'lng' => 0.0]; // ~111 km away
    assert_true(wpultra_dir_within_radius($listing, 0.0, 0.0, 50.0) === false);
});

it('within_radius edge counts as inside', function () {
    $listing = ['lat' => 1.0, 'lng' => 0.0];
    $d = wpultra_dir_haversine(1.0, 0.0, 0.0, 0.0);
    assert_true(wpultra_dir_within_radius($listing, 0.0, 0.0, $d)); // radius == exact distance
});

it('within_radius false when listing has no coords', function () {
    assert_true(wpultra_dir_within_radius(['name' => 'x'], 0.0, 0.0, 1000.0) === false);
});

it('within_radius false for out-of-range coords', function () {
    assert_true(wpultra_dir_within_radius(['lat' => 200, 'lng' => 0], 0.0, 0.0, 1000.0) === false);
});

/* ============================================================
 * sort_by_distance
 * ============================================================ */

it('sort_by_distance orders ascending', function () {
    $listings = [
        ['name' => 'far',  'lat' => 1.0,   'lng' => 0.0],
        ['name' => 'near', 'lat' => 0.001, 'lng' => 0.0],
        ['name' => 'mid',  'lat' => 0.1,   'lng' => 0.0],
    ];
    $sorted = wpultra_dir_sort_by_distance($listings, 0.0, 0.0);
    assert_eq('near', $sorted[0]['name']);
    assert_eq('mid', $sorted[1]['name']);
    assert_eq('far', $sorted[2]['name']);
});

it('sort_by_distance attaches distance_km', function () {
    $sorted = wpultra_dir_sort_by_distance([['name' => 'a', 'lat' => 0.0, 'lng' => 0.0]], 0.0, 0.0);
    assert_eq(0.0, $sorted[0]['distance_km']);
});

it('sort_by_distance puts no-coords listings last with null distance', function () {
    $listings = [
        ['name' => 'nocoord'],
        ['name' => 'near', 'lat' => 0.001, 'lng' => 0.0],
    ];
    $sorted = wpultra_dir_sort_by_distance($listings, 0.0, 0.0);
    assert_eq('near', $sorted[0]['name']);
    assert_eq('nocoord', $sorted[1]['name']);
    assert_true($sorted[1]['distance_km'] === null);
});

it('sort_by_distance keeps stable order among multiple no-coords', function () {
    $listings = [
        ['name' => 'nc1'],
        ['name' => 'near', 'lat' => 0.001, 'lng' => 0.0],
        ['name' => 'nc2'],
    ];
    $sorted = wpultra_dir_sort_by_distance($listings, 0.0, 0.0);
    assert_eq('near', $sorted[0]['name']);
    assert_eq('nc1', $sorted[1]['name']);
    assert_eq('nc2', $sorted[2]['name']);
});

/* ============================================================
 * filter
 * ============================================================ */

$mkListings = static function (): array {
    return [
        ['name' => 'Joe Pizza',    'address' => '5th Ave',   'category' => 'food',   'price_range' => '$',   'featured' => true],
        ['name' => 'Bob Plumbing', 'address' => 'Main St',   'category' => 'service','price_range' => '$$',  'featured' => false],
        ['name' => 'Ace Cafe',     'address' => 'Pizza Lane','categories' => ['food','drink'], 'price_range' => '$$$', 'featured' => false],
    ];
};

it('filter by category', function () use ($mkListings) {
    $out = wpultra_dir_filter($mkListings(), ['category' => 'food']);
    assert_eq(2, count($out));
});

it('filter by category is case-insensitive', function () use ($mkListings) {
    $out = wpultra_dir_filter($mkListings(), ['category' => 'FOOD']);
    assert_eq(2, count($out));
});

it('filter by price_range', function () use ($mkListings) {
    $out = wpultra_dir_filter($mkListings(), ['price_range' => '$$']);
    assert_eq(1, count($out));
    assert_eq('Bob Plumbing', $out[0]['name']);
});

it('filter by search substring (name) case-insensitive', function () use ($mkListings) {
    $out = wpultra_dir_filter($mkListings(), ['search' => 'PIZZA']);
    // matches "Joe Pizza" (name) AND "Ace Cafe" (address "Pizza Lane")
    assert_eq(2, count($out));
});

it('filter by search substring in address only', function () use ($mkListings) {
    $out = wpultra_dir_filter($mkListings(), ['search' => 'main']);
    assert_eq(1, count($out));
    assert_eq('Bob Plumbing', $out[0]['name']);
});

it('filter featured_only', function () use ($mkListings) {
    $out = wpultra_dir_filter($mkListings(), ['featured_only' => true]);
    assert_eq(1, count($out));
    assert_eq('Joe Pizza', $out[0]['name']);
});

it('filter combined category + search', function () use ($mkListings) {
    $out = wpultra_dir_filter($mkListings(), ['category' => 'food', 'search' => 'pizza']);
    // food AND (pizza in name/address): Joe Pizza + Ace Cafe(Pizza Lane)
    assert_eq(2, count($out));
});

it('filter empty filters returns all', function () use ($mkListings) {
    assert_eq(3, count(wpultra_dir_filter($mkListings(), [])));
});

it('filter no match returns empty', function () use ($mkListings) {
    assert_eq(0, count(wpultra_dir_filter($mkListings(), ['search' => 'zzzznomatch'])));
});

/* ============================================================
 * is_featured — the monetization gate.
 * ============================================================ */

it('is_featured true when featured with no expiry', function () {
    assert_true(wpultra_dir_is_featured(['featured' => true, 'featured_until' => null], 1000));
});

it('is_featured true when featured expiry in future', function () {
    assert_true(wpultra_dir_is_featured(['featured' => true, 'featured_until' => 2000], 1000));
});

it('is_featured false when featured expiry in past', function () {
    assert_true(wpultra_dir_is_featured(['featured' => true, 'featured_until' => 500], 1000) === false);
});

it('is_featured false when not featured', function () {
    assert_true(wpultra_dir_is_featured(['featured' => false, 'featured_until' => null], 1000) === false);
});

it('is_featured false when featured flag missing', function () {
    assert_true(wpultra_dir_is_featured(['featured_until' => 9999], 1000) === false);
});

it('is_featured true when featured_until absent', function () {
    assert_true(wpultra_dir_is_featured(['featured' => true], 1000));
});

it('is_featured false at exact expiry (strictly greater)', function () {
    assert_true(wpultra_dir_is_featured(['featured' => true, 'featured_until' => 1000], 1000) === false);
});

/* ============================================================
 * rank — featured float to top, order preserved otherwise.
 * ============================================================ */

it('rank floats featured to top preserving order', function () {
    $now = 1000;
    $listings = [
        ['name' => 'a'],
        ['name' => 'b', 'featured' => true, 'featured_until' => null],
        ['name' => 'c'],
        ['name' => 'd', 'featured' => true, 'featured_until' => 5000],
    ];
    $ranked = wpultra_dir_rank($listings, $now);
    assert_eq('b', $ranked[0]['name']);
    assert_eq('d', $ranked[1]['name']);
    assert_eq('a', $ranked[2]['name']);
    assert_eq('c', $ranked[3]['name']);
});

it('rank treats expired-featured as normal', function () {
    $now = 1000;
    $listings = [
        ['name' => 'x'],
        ['name' => 'expired', 'featured' => true, 'featured_until' => 500],
        ['name' => 'live', 'featured' => true, 'featured_until' => 5000],
    ];
    $ranked = wpultra_dir_rank($listings, $now);
    assert_eq('live', $ranked[0]['name']);
    assert_eq('x', $ranked[1]['name']);
    assert_eq('expired', $ranked[2]['name']);
});

it('rank with no featured preserves order', function () {
    $ranked = wpultra_dir_rank([['name' => '1'], ['name' => '2'], ['name' => '3']], 1000);
    assert_eq(['1', '2', '3'], array_column($ranked, 'name'));
});

/* ============================================================
 * validate
 * ============================================================ */

it('validate requires a name', function () {
    assert_true(is_string(wpultra_dir_validate([])));
    assert_true(is_string(wpultra_dir_validate(['name' => '   '])));
});

it('validate passes a minimal valid listing', function () {
    assert_true(wpultra_dir_validate(['name' => 'Joe']) === true);
});

it('validate accepts title as a name alias', function () {
    assert_true(wpultra_dir_validate(['title' => 'Joe']) === true);
});

it('validate rejects out-of-range latitude', function () {
    assert_true(is_string(wpultra_dir_validate(['name' => 'x', 'lat' => 95, 'lng' => 0])));
});

it('validate rejects out-of-range longitude', function () {
    assert_true(is_string(wpultra_dir_validate(['name' => 'x', 'lat' => 0, 'lng' => 200])));
});

it('validate rejects half-provided coords', function () {
    assert_true(is_string(wpultra_dir_validate(['name' => 'x', 'lat' => 40])));
});

it('validate accepts valid coords', function () {
    assert_true(wpultra_dir_validate(['name' => 'x', 'lat' => 40.7, 'lng' => -74.0]) === true);
});

it('validate rejects bad email', function () {
    assert_true(is_string(wpultra_dir_validate(['name' => 'x', 'email' => 'not-an-email'])));
});

it('validate accepts good email', function () {
    assert_true(wpultra_dir_validate(['name' => 'x', 'email' => 'a@b.com']) === true);
});

it('validate rejects bad website', function () {
    assert_true(is_string(wpultra_dir_validate(['name' => 'x', 'website' => 'javascript:alert(1)'])));
});

it('validate accepts good website', function () {
    assert_true(wpultra_dir_validate(['name' => 'x', 'website' => 'https://example.com']) === true);
});

it('validate rejects bad phone', function () {
    assert_true(is_string(wpultra_dir_validate(['name' => 'x', 'phone' => 'call-me-maybe'])));
});

it('validate accepts good phone', function () {
    assert_true(wpultra_dir_validate(['name' => 'x', 'phone' => '+1 (212) 555-0100']) === true);
});

it('validate rejects bad status', function () {
    assert_true(is_string(wpultra_dir_validate(['name' => 'x', 'status' => 'bogus'])));
});

it('validate accepts enum status', function () {
    assert_true(wpultra_dir_validate(['name' => 'x', 'status' => 'pending']) === true);
});

it('validate rejects bad price_range', function () {
    assert_true(is_string(wpultra_dir_validate(['name' => 'x', 'price_range' => 'cheap'])));
});

it('validate accepts price_range token', function () {
    assert_true(wpultra_dir_validate(['name' => 'x', 'price_range' => '$$$']) === true);
});

/* ============================================================
 * sanitize_submission
 * ============================================================ */

it('sanitize strips unknown keys', function () {
    $out = wpultra_dir_sanitize_submission(['name' => 'Joe', 'evil' => 'x', 'featured' => true, 'status' => 'published']);
    assert_true(!array_key_exists('evil', $out));
    // front-end can never self-feature or self-publish
    assert_true($out['featured'] === false);
    assert_eq('pending', $out['status']);
});

it('sanitize clamps length', function () {
    $out = wpultra_dir_sanitize_submission(['name' => str_repeat('a', 500)]);
    assert_eq(200, strlen($out['name']));
});

it('sanitize coerces coords and drops out-of-range', function () {
    $ok = wpultra_dir_sanitize_submission(['name' => 'x', 'lat' => '40.7', 'lng' => '-74.0']);
    assert_eq(40.7, $ok['lat']);
    assert_eq(-74.0, $ok['lng']);
    $bad = wpultra_dir_sanitize_submission(['name' => 'x', 'lat' => '999', 'lng' => '0']);
    assert_true($bad['lat'] === null && $bad['lng'] === null);
});

it('sanitize drops half-provided coords', function () {
    $out = wpultra_dir_sanitize_submission(['name' => 'x', 'lat' => '40.7']);
    assert_true($out['lat'] === null && $out['lng'] === null);
});

it('sanitize keeps only known price_range token', function () {
    assert_eq('$$', wpultra_dir_sanitize_submission(['name' => 'x', 'price_range' => '$$'])['price_range']);
    assert_eq('', wpultra_dir_sanitize_submission(['name' => 'x', 'price_range' => 'expensive'])['price_range']);
});

it('sanitize lowercases email', function () {
    assert_eq('joe@example.com', wpultra_dir_sanitize_submission(['name' => 'x', 'email' => 'Joe@Example.COM'])['email']);
});

it('sanitize normalizes categories (scalar or array), dedupes, caps', function () {
    $out = wpultra_dir_sanitize_submission(['name' => 'x', 'categories' => ['food', 'food', 'drink']]);
    assert_eq(['food', 'drink'], $out['categories']);
    // scalar category coerces to a one-item list
    $out2 = wpultra_dir_sanitize_submission(['name' => 'x', 'category' => 'food']);
    assert_eq(['food'], $out2['categories']);
});

it('sanitize drops array/object values for scalar fields', function () {
    $out = wpultra_dir_sanitize_submission(['name' => ['array', 'value']]);
    assert_eq('', $out['name']);
});

it('sanitize accepts title as name alias', function () {
    assert_eq('Joe', wpultra_dir_sanitize_submission(['title' => 'Joe'])['name']);
});

/* ============================================================
 * shape
 * ============================================================ */

it('shape produces the canonical output keys', function () {
    $shaped = wpultra_dir_shape(['name' => 'Joe', 'lat' => 40.7, 'lng' => -74.0, 'featured' => true, 'featured_until' => 5000], 42, 'https://x/listing/joe');
    assert_eq(42, $shaped['id']);
    assert_eq('Joe', $shaped['name']);
    assert_eq(40.7, $shaped['lat']);
    assert_eq(true, $shaped['featured']);
    assert_eq(5000, $shaped['featured_until']);
    assert_eq('https://x/listing/joe', $shaped['permalink']);
});

it('shape defaults missing coords to null', function () {
    $shaped = wpultra_dir_shape(['name' => 'Joe'], 1);
    assert_true($shaped['lat'] === null && $shaped['lng'] === null);
    assert_true($shaped['featured'] === false);
    assert_true($shaped['featured_until'] === null);
});

/* ============================================================
 * helpers: statuses / price ranges / validators
 * ============================================================ */

it('statuses enum is stable', function () {
    assert_eq(['pending', 'published', 'rejected'], wpultra_dir_statuses());
});

it('price_ranges enum is stable', function () {
    assert_eq(['$', '$$', '$$$', '$$$$'], wpultra_dir_price_ranges());
});

it('has_coords guards missing/invalid', function () {
    assert_true(wpultra_dir_has_coords(['lat' => 1, 'lng' => 2]));
    assert_true(wpultra_dir_has_coords(['lat' => 'x', 'lng' => 2]) === false);
    assert_true(wpultra_dir_has_coords(['lat' => null, 'lng' => null]) === false);
    assert_true(wpultra_dir_has_coords([]) === false);
});

it('in_category matches scalar and array forms', function () {
    assert_true(wpultra_dir_in_category(['category' => 'Food'], 'food'));
    assert_true(wpultra_dir_in_category(['categories' => ['a', 'Food']], 'food'));
    assert_true(wpultra_dir_in_category(['category' => 'food'], 'drink') === false);
    // empty query matches everything
    assert_true(wpultra_dir_in_category(['category' => 'food'], ''));
});

run_tests();
