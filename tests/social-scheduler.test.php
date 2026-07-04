<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_social/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/marketing/social-scheduler.php';

/* ============================================================
 * Small factory for a valid item at a given time.
 * ============================================================ */
function soc_item(array $over = []): array {
    return wpultra_social_make_item(
        $over['id']       ?? 'id1',
        $over['networks'] ?? ['x', 'facebook'],
        $over['content']  ?? ['text' => 'hello world', 'link' => '', 'image_url' => ''],
        $over['variants'] ?? [],
        $over['scheduled_at'] ?? 2000,
        $over['created_at']   ?? 1000,
        $over['post_id']      ?? 0
    );
}

/* ============================================================
 * due — only scheduled + past; ignores sent/cancelled/future.
 * ============================================================ */

it('due returns only scheduled items at or before now', function () {
    $queue = [
        soc_item(['id' => 'past', 'scheduled_at' => 100]),
        soc_item(['id' => 'now', 'scheduled_at' => 500]),
        soc_item(['id' => 'future', 'scheduled_at' => 900]),
    ];
    $due = wpultra_social_due($queue, 500);
    $ids = array_column($due, 'id');
    assert_eq(['past', 'now'], $ids);
});

it('due ignores non-scheduled statuses even if past', function () {
    $sent = soc_item(['id' => 's', 'scheduled_at' => 100]); $sent['status'] = 'sent';
    $canc = soc_item(['id' => 'c', 'scheduled_at' => 100]); $canc['status'] = 'cancelled';
    $fail = soc_item(['id' => 'f', 'scheduled_at' => 100]); $fail['status'] = 'failed';
    $ok   = soc_item(['id' => 'ok', 'scheduled_at' => 100]);
    $due = wpultra_social_due([$sent, $canc, $fail, $ok], 999);
    assert_eq(['ok'], array_column($due, 'id'));
});

it('due skips non-array garbage entries', function () {
    $due = wpultra_social_due(['nope', 42, soc_item(['scheduled_at' => 1])], 100);
    assert_eq(1, count($due));
});

/* ============================================================
 * validate_item.
 * ============================================================ */

it('validate_item accepts a well-formed item', function () {
    assert_true(wpultra_social_validate_item(soc_item()) === true);
});

it('validate_item rejects empty networks', function () {
    $r = wpultra_social_validate_item(soc_item(['networks' => []]));
    assert_true(is_string($r) && str_contains($r, 'networks'));
});

it('validate_item rejects an unknown network', function () {
    $r = wpultra_social_validate_item(soc_item(['networks' => ['x', 'tiktok']]));
    assert_true(is_string($r) && str_contains($r, 'tiktok'));
});

it('validate_item rejects empty content (no text and no link)', function () {
    $r = wpultra_social_validate_item(soc_item(['content' => ['text' => '', 'link' => '', 'image_url' => '']]));
    assert_true(is_string($r) && str_contains($r, 'content'));
});

it('validate_item accepts a link with no text', function () {
    $item = soc_item(['content' => ['text' => '', 'link' => 'https://a.com/p', 'image_url' => '']]);
    assert_true(wpultra_social_validate_item($item) === true);
});

it('validate_item rejects a non-http link', function () {
    $r = wpultra_social_validate_item(soc_item(['content' => ['text' => 'hi', 'link' => 'javascript:1', 'image_url' => '']]));
    assert_true(is_string($r) && str_contains($r, 'link'));
});

it('validate_item rejects a bad image_url', function () {
    $r = wpultra_social_validate_item(soc_item(['content' => ['text' => 'hi', 'link' => '', 'image_url' => 'ftp://x']]));
    assert_true(is_string($r) && str_contains($r, 'image_url'));
});

it('validate_item rejects a non-int / non-positive scheduled_at', function () {
    $bad = soc_item(); $bad['scheduled_at'] = '2000'; // string
    assert_true(is_string(wpultra_social_validate_item($bad)));
    $zero = soc_item(['scheduled_at' => 0]);
    assert_true(is_string(wpultra_social_validate_item($zero)));
});

/* ============================================================
 * next_slots — N evenly spaced from start.
 * ============================================================ */

it('next_slots generates N timestamps spaced by interval', function () {
    assert_eq([1000, 1000 + 86400, 1000 + 172800], wpultra_social_next_slots(1000, 3, 86400));
});

it('next_slots with count 0 yields an empty list', function () {
    assert_eq([], wpultra_social_next_slots(1000, 0, 60));
});

it('next_slots clamps a non-positive interval to 1', function () {
    assert_eq([500, 501, 502], wpultra_social_next_slots(500, 3, 0));
});

/* ============================================================
 * char_limit / fits / truncate.
 * ============================================================ */

it('char_limit knows the networks (x is 280)', function () {
    assert_eq(280, wpultra_social_char_limit('x'));
    assert_eq(2200, wpultra_social_char_limit('instagram'));
    assert_eq(3000, wpultra_social_char_limit('linkedin'));
    assert_eq(63206, wpultra_social_char_limit('facebook'));
    assert_eq(280, wpultra_social_char_limit('unknown'));
});

it('fits respects the x 280 boundary', function () {
    assert_true(wpultra_social_fits(str_repeat('a', 280), 'x'));
    assert_true(!wpultra_social_fits(str_repeat('a', 281), 'x'));
});

it('truncate leaves short text unchanged', function () {
    assert_eq('short and sweet', wpultra_social_truncate('short and sweet', 'x'));
});

it('truncate cuts on a word boundary and appends an ellipsis, staying within the limit', function () {
    $text = str_repeat('word ', 100); // 500 chars, way over 280
    $out  = wpultra_social_truncate($text, 'x');
    assert_true(wpultra_social_strlen($out) <= 280, 'result within limit');
    assert_true(str_ends_with($out, '…'), 'ends with ellipsis');
    // Word boundary: strip the trailing ellipsis; the body ends on a whole word.
    $body = rtrim(substr($out, 0, -strlen('…')));
    assert_true(str_ends_with($body, 'word'), 'cut on a whole word');
});

/* ============================================================
 * render_variant — override vs default, per-network truncation, link+image.
 * ============================================================ */

it('render_variant uses the default text when no variant override', function () {
    $item = soc_item(['content' => ['text' => 'default text', 'link' => 'https://a.com', 'image_url' => 'https://a.com/i.png']]);
    $v = wpultra_social_render_variant($item, 'x');
    assert_eq('default text', $v['text']);
    assert_eq('https://a.com', $v['link']);
    assert_eq('https://a.com/i.png', $v['image_url']);
    assert_eq('x', $v['network']);
});

it('render_variant prefers a per-network variant override', function () {
    $item = soc_item([
        'content'  => ['text' => 'generic', 'link' => '', 'image_url' => ''],
        'variants' => ['x' => 'punchy x copy'],
    ]);
    assert_eq('punchy x copy', wpultra_social_render_variant($item, 'x')['text']);
    // A network without an override falls back to the default.
    assert_eq('generic', wpultra_social_render_variant($item, 'facebook')['text']);
});

it('render_variant truncates the variant to the network limit', function () {
    $long = str_repeat('spam ', 100); // >280
    $item = soc_item(['content' => ['text' => $long, 'link' => '', 'image_url' => '']]);
    $vx = wpultra_social_render_variant($item, 'x');
    assert_true(wpultra_social_strlen($vx['text']) <= 280);
    assert_true($vx['truncated'] === true);
    // Facebook's huge limit keeps it intact.
    $vf = wpultra_social_render_variant($item, 'facebook');
    assert_true($vf['truncated'] === false);
});

/* ============================================================
 * webhook_payload — all networks rendered.
 * ============================================================ */

it('webhook_payload renders every network and carries content', function () {
    $item = soc_item([
        'networks' => ['x', 'linkedin'],
        'content'  => ['text' => 'shared body', 'link' => 'https://a.com/p', 'image_url' => 'https://a.com/i.png'],
        'variants' => ['x' => 'x only'],
    ]);
    $p = wpultra_social_webhook_payload($item);
    assert_eq('social.scheduled_post', $p['event']);
    assert_eq(['x', 'linkedin'], $p['networks']);
    assert_true(isset($p['variants']['x']) && isset($p['variants']['linkedin']));
    assert_eq('x only', $p['variants']['x']['text']);
    assert_eq('shared body', $p['variants']['linkedin']['text']);
    assert_eq('https://a.com/p', $p['content']['link']);
    assert_eq('https://a.com/i.png', $p['content']['image_url']);
});

/* ============================================================
 * calendar — day grouping, range filter, sorting, empty.
 * ============================================================ */

it('calendar groups scheduled items by UTC day within the range', function () {
    // 2026-07-05 09:00 UTC = 1783587600 ; +1 day = 1783674000
    $d1a = gmmktime(9, 0, 0, 7, 5, 2026);
    $d1b = gmmktime(14, 0, 0, 7, 5, 2026);
    $d2  = gmmktime(9, 0, 0, 7, 6, 2026);
    $queue = [
        soc_item(['id' => 'a', 'scheduled_at' => $d1b]),
        soc_item(['id' => 'b', 'scheduled_at' => $d1a]),
        soc_item(['id' => 'c', 'scheduled_at' => $d2]),
    ];
    $cal = wpultra_social_calendar($queue, $d1a - 3600, $d2 + 3600);
    assert_eq(['2026-07-05', '2026-07-06'], array_keys($cal));
    // Within the first day, sorted by time: b (09:00) before a (14:00).
    assert_eq(['b', 'a'], array_column($cal['2026-07-05'], 'id'));
    assert_eq(['c'], array_column($cal['2026-07-06'], 'id'));
});

it('calendar filters out items outside the range and non-scheduled ones', function () {
    $d = gmmktime(9, 0, 0, 7, 5, 2026);
    $sent = soc_item(['id' => 'sent', 'scheduled_at' => $d]); $sent['status'] = 'sent';
    $queue = [
        soc_item(['id' => 'in', 'scheduled_at' => $d]),
        soc_item(['id' => 'before', 'scheduled_at' => $d - 100000]),
        $sent,
    ];
    $cal = wpultra_social_calendar($queue, $d - 3600, $d + 3600);
    assert_eq(1, count($cal));
    assert_eq(['in'], array_column($cal['2026-07-05'], 'id'));
});

it('calendar returns empty for an empty queue', function () {
    assert_eq([], wpultra_social_calendar([], 0, 999999999));
});

/* ============================================================
 * counts — status tally.
 * ============================================================ */

it('counts tallies by status with a total', function () {
    $sent = soc_item(); $sent['status'] = 'sent';
    $c = wpultra_social_counts([soc_item(), soc_item(), $sent]);
    assert_eq(3, $c['total']);
    assert_eq(2, $c['scheduled']);
    assert_eq(1, $c['sent']);
    assert_eq(0, $c['failed']);
});

run_tests();
