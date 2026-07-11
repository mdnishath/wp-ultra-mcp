<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_pixeldiff/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses the WP_Error stub from harness.php).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/ai/pixeldiff.php';

/* ============================================================
 * wpultra_pxdiff_channel_diff
 * ============================================================ */

it('channel_diff: identical pixels -> 0', function () {
    assert_eq(0, wpultra_pxdiff_channel_diff([10, 20, 30], [10, 20, 30]));
});

it('channel_diff: returns the MAX abs delta across channels, not the sum', function () {
    // r delta=5, g delta=40, b delta=1 -> max is 40.
    assert_eq(40, wpultra_pxdiff_channel_diff([100, 50, 10], [105, 90, 9]));
});

it('channel_diff: works with keyed r/g/b arrays too', function () {
    assert_eq(15, wpultra_pxdiff_channel_diff(['r' => 200, 'g' => 100, 'b' => 50], ['r' => 200, 'g' => 115, 'b' => 50]));
});

it('channel_diff: order of args does not matter (abs delta)', function () {
    assert_eq(40, wpultra_pxdiff_channel_diff([105, 90, 9], [100, 50, 10]));
});

/* ============================================================
 * wpultra_pxdiff_is_different
 * ============================================================ */

it('is_different: identical pixels are never different', function () {
    assert_true(!wpultra_pxdiff_is_different([1, 2, 3], [1, 2, 3], 10));
});

it('is_different: delta exactly AT tolerance is NOT different (strictly greater-than)', function () {
    assert_true(!wpultra_pxdiff_is_different([0, 0, 0], [10, 0, 0], 10));
});

it('is_different: delta one over tolerance IS different', function () {
    assert_true(wpultra_pxdiff_is_different([0, 0, 0], [11, 0, 0], 10));
});

it('is_different: zero tolerance flags any single-value delta', function () {
    assert_true(wpultra_pxdiff_is_different([0, 0, 0], [1, 0, 0], 0));
    assert_true(!wpultra_pxdiff_is_different([0, 0, 0], [0, 0, 0], 0));
});

/* ============================================================
 * wpultra_pxdiff_summarize
 * ============================================================ */

it('summarize: zero compared pixels guards divide-by-zero (0.0 pct)', function () {
    $s = wpultra_pxdiff_summarize(0, 0, 0, true);
    assert_eq(0.0, $s['mismatch_pct']);
    assert_eq(0, $s['different_pixels']);
    assert_eq(0, $s['compared_pixels']);
});

it('summarize: dimension mismatch always verdicts dimension_mismatch regardless of pixel counts', function () {
    $s = wpultra_pxdiff_summarize(0, 100, 0, false);
    assert_eq('dimension_mismatch', $s['verdict']);
    assert_eq(false, $s['dimension_match']);
});

it('summarize: zero different pixels + matching dims -> pixel_perfect', function () {
    $s = wpultra_pxdiff_summarize(0, 500, 0, true);
    assert_eq('pixel_perfect', $s['verdict']);
    assert_eq(0.0, $s['mismatch_pct']);
});

it('summarize: small mismatch pct -> near_identical', function () {
    $s = wpultra_pxdiff_summarize(1, 10000, 12, true); // 0.01%
    assert_eq('near_identical', $s['verdict']);
});

it('summarize: moderate mismatch pct -> minor_diff', function () {
    $s = wpultra_pxdiff_summarize(200, 10000, 80, true); // 2%
    assert_eq('minor_diff', $s['verdict']);
});

it('summarize: large mismatch pct -> major_diff', function () {
    $s = wpultra_pxdiff_summarize(6000, 10000, 255, true); // 60%
    assert_eq('major_diff', $s['verdict']);
});

it('summarize: mismatch_pct math is correct and rounded', function () {
    $s = wpultra_pxdiff_summarize(1, 3, 5, true);
    assert_eq(round((1 / 3) * 100, 4), $s['mismatch_pct']);
});

it('summarize: max_channel_delta is passed through unchanged', function () {
    $s = wpultra_pxdiff_summarize(3, 10, 199, true);
    assert_eq(199, $s['max_channel_delta']);
});

/* ============================================================
 * wpultra_pxdiff_bbox_update
 * ============================================================ */

it('bbox_update: starting from null creates a 1x1 box at the point', function () {
    $b = wpultra_pxdiff_bbox_update(null, 5, 9);
    assert_eq(['x' => 5, 'y' => 9, 'w' => 1, 'h' => 1], $b);
});

it('bbox_update: growing to a point down-right expands w/h, keeps x/y', function () {
    $b = wpultra_pxdiff_bbox_update(['x' => 5, 'y' => 9, 'w' => 1, 'h' => 1], 10, 12);
    assert_eq(['x' => 5, 'y' => 9, 'w' => 6, 'h' => 4], $b);
});

it('bbox_update: growing to a point up-left moves x/y and expands w/h', function () {
    $b = wpultra_pxdiff_bbox_update(['x' => 5, 'y' => 9, 'w' => 6, 'h' => 4], 2, 3);
    assert_eq(['x' => 2, 'y' => 3, 'w' => 9, 'h' => 10], $b);
});

it('bbox_update: a point already inside the box does not change it', function () {
    $box = ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10];
    $b = wpultra_pxdiff_bbox_update($box, 5, 5);
    assert_eq($box, $b);
});

it('bbox_update: scattered points converge to their tight bounding box', function () {
    $points = [[3, 3], [8, 1], [1, 9], [6, 6]];
    $box = null;
    foreach ($points as [$x, $y]) { $box = wpultra_pxdiff_bbox_update($box, $x, $y); }
    // x range 1..8 (w=8), y range 1..9 (h=9)
    assert_eq(['x' => 1, 'y' => 1, 'w' => 8, 'h' => 9], $box);
});

/* ============================================================
 * wpultra_pxdiff_decode_input
 * ============================================================ */

it('decode_input: recognizes an http URL', function () {
    $r = wpultra_pxdiff_decode_input('http://example.test/a.png');
    assert_eq('url', $r['kind']);
    assert_eq('http://example.test/a.png', $r['payload']);
});

it('decode_input: recognizes an https URL', function () {
    $r = wpultra_pxdiff_decode_input('https://example.test/a.png?x=1');
    assert_eq('url', $r['kind']);
    assert_eq('https://example.test/a.png?x=1', $r['payload']);
});

it('decode_input: strips a data:image/png;base64, prefix', function () {
    $b64 = base64_encode('not-really-a-png-but-valid-base64');
    $r = wpultra_pxdiff_decode_input('data:image/png;base64,' . $b64);
    assert_eq('base64', $r['kind']);
    assert_eq($b64, $r['payload']);
});

it('decode_input: strips a data:image/jpeg;base64, prefix', function () {
    $b64 = base64_encode('jpeg-bytes-stand-in');
    $r = wpultra_pxdiff_decode_input('data:image/jpeg;base64,' . $b64);
    assert_eq('base64', $r['kind']);
    assert_eq($b64, $r['payload']);
});

it('decode_input: accepts a raw base64 string with no data-uri prefix', function () {
    $b64 = base64_encode('raw-payload-bytes');
    $r = wpultra_pxdiff_decode_input($b64);
    assert_eq('base64', $r['kind']);
    assert_eq($b64, $r['payload']);
});

it('decode_input: invalid base64 characters return a WP_Error', function () {
    $r = wpultra_pxdiff_decode_input('not base64!! ###');
    assert_wp_error($r, 'expected an error for garbage input');
});

it('decode_input: empty string returns a WP_Error', function () {
    $r = wpultra_pxdiff_decode_input('');
    assert_wp_error($r, 'expected an error for empty input');
});

it('decode_input: whitespace-only string returns a WP_Error', function () {
    $r = wpultra_pxdiff_decode_input('   ');
    assert_wp_error($r, 'expected an error for whitespace-only input');
});

/* ============================================================
 * wpultra_pxdiff_compare_rect (extra pure geometry helper — no GD needed)
 * ============================================================ */

it('compare_rect: no region -> overlapping top-left rect of the smaller dims', function () {
    $r = wpultra_pxdiff_compare_rect(800, 600, 400, 900, null);
    assert_eq(['ox' => 0, 'oy' => 0, 'w' => 400, 'h' => 600], $r);
});

it('compare_rect: identical dims with no region uses full size', function () {
    $r = wpultra_pxdiff_compare_rect(100, 50, 100, 50, null);
    assert_eq(['ox' => 0, 'oy' => 0, 'w' => 100, 'h' => 50], $r);
});

it('compare_rect: region is clamped to fit inside both images', function () {
    $r = wpultra_pxdiff_compare_rect(100, 100, 80, 80, ['x' => 10, 'y' => 10, 'w' => 90, 'h' => 90]);
    // From x=10,y=10: image A allows 90 wide, image B only allows 70 (80-10); min(90,90,70)=70.
    assert_eq(['ox' => 10, 'oy' => 10, 'w' => 70, 'h' => 70], $r);
});

it('compare_rect: region fully outside an image collapses to zero area', function () {
    $r = wpultra_pxdiff_compare_rect(50, 50, 50, 50, ['x' => 100, 'y' => 100, 'w' => 10, 'h' => 10]);
    assert_eq(0, $r['w']);
    assert_eq(0, $r['h']);
});

/* ============================================================
 * wpultra_pxdiff_stride_for (extra pure helper — no GD needed)
 * ============================================================ */

it('stride_for: small area under budget walks every pixel (stride 1)', function () {
    assert_eq(1, wpultra_pxdiff_stride_for(100, 100, 2000000));
});

it('stride_for: area over budget picks a stride > 1', function () {
    $stride = wpultra_pxdiff_stride_for(4000, 3000, 2000000); // 12M px vs 2M budget
    assert_true($stride > 1, 'expected stride > 1 for an over-budget area');
    // Sampled area should now be at/under budget.
    $sampled_w = (int) ceil(4000 / $stride);
    $sampled_h = (int) ceil(3000 / $stride);
    assert_true($sampled_w * $sampled_h <= 2000000 * 1.5, 'stride should bring sampled pixel count near the budget');
});

it('stride_for: zero/negative budget never divides by zero (falls back to stride 1)', function () {
    assert_eq(1, wpultra_pxdiff_stride_for(500, 500, 0));
});

run_tests();
