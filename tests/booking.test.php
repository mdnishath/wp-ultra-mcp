<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_booking/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/verticals/booking.php';

/* Slot math below uses day_start_ts = 0 (epoch midnight), so minute offsets
 * convert directly: 09:00 == 32400, 10:00 == 36000, 17:00 == 61200 … */

/* ============================================================
 * Time primitives.
 * ============================================================ */

it('hhmm_to_min parses valid times incl. the 24:00 window end', function () {
    assert_eq(540, wpultra_book_hhmm_to_min('09:00'));
    assert_eq(545, wpultra_book_hhmm_to_min('9:05'));
    assert_eq(1439, wpultra_book_hhmm_to_min('23:59'));
    assert_eq(1440, wpultra_book_hhmm_to_min('24:00'));
    assert_eq(0, wpultra_book_hhmm_to_min('00:00'));
    assert_eq(600, wpultra_book_hhmm_to_min(' 10:00 '), 'trims whitespace');
});

it('hhmm_to_min rejects malformed / out-of-range times', function () {
    foreach (['24:01', '25:00', '12:60', '12', '12:5', 'ab:cd', '12:345', '', '9.30'] as $bad) {
        assert_eq(null, wpultra_book_hhmm_to_min($bad), "should reject '$bad'");
    }
});

it('min_to_hhmm zero-pads and clamps', function () {
    assert_eq('09:00', wpultra_book_min_to_hhmm(540));
    assert_eq('00:00', wpultra_book_min_to_hhmm(0));
    assert_eq('24:00', wpultra_book_min_to_hhmm(1440));
    assert_eq('24:00', wpultra_book_min_to_hhmm(9999), 'clamped high');
    assert_eq('00:00', wpultra_book_min_to_hhmm(-5), 'clamped low');
});

it('windows_to_min converts and sorts; any malformed entry poisons the list', function () {
    assert_eq([[540, 720], [780, 1020]], wpultra_book_windows_to_min([['13:00', '17:00'], ['09:00', '12:00']]));
    assert_eq([], wpultra_book_windows_to_min([]));
    assert_eq(null, wpultra_book_windows_to_min([['09:00']]), 'not a pair');
    assert_eq(null, wpultra_book_windows_to_min([['17:00', '09:00']]), 'start >= end');
    assert_eq(null, wpultra_book_windows_to_min([['09:00', '09:00']]), 'zero-length');
    assert_eq(null, wpultra_book_windows_to_min([['09:00', '12:00'], 'oops']), 'non-array entry');
});

/* ============================================================
 * Hours map: validate + normalize + day_hours.
 * ============================================================ */

it('hours_validate accepts a good week and empty (off) days', function () {
    assert_true(wpultra_book_hours_validate([
        'mon' => [['09:00', '17:00']],
        'tue' => [['09:00', '12:00'], ['13:00', '17:00']],
        'sat' => [],
    ]) === true);
    assert_true(wpultra_book_hours_validate([]) === true, 'empty map = always off');
});

it('hours_validate rejects unknown day keys and bad windows', function () {
    $e1 = wpultra_book_hours_validate(['monday' => [['09:00', '17:00']]]);
    assert_true(is_string($e1) && str_contains($e1, 'monday'), 'unknown day key');
    $e2 = wpultra_book_hours_validate(['mon' => [['17:00', '09:00']]]);
    assert_true(is_string($e2), 'inverted window');
    $e3 = wpultra_book_hours_validate(['mon' => 'nine to five']);
    assert_true(is_string($e3), 'non-array windows');
});

it('hours_normalize lowercases keys, zero-pads, sorts and drops junk', function () {
    $n = wpultra_book_hours_normalize([
        'MON' => [['13:00', '17:00'], ['9:00', '12:00'], ['bad'], ['18:00', '17:30']],
        'holiday' => [['09:00', '10:00']],
    ]);
    assert_eq(['mon' => [['09:00', '12:00'], ['13:00', '17:00']]], $n);
});

it('day_hours returns the normalized windows for a day, [] for off/unknown', function () {
    $staff = ['id' => 1, 'name' => 'Ana', 'hours' => ['mon' => [['9:00', '17:00']], 'sun' => []]];
    assert_eq([['09:00', '17:00']], wpultra_book_day_hours($staff, 'mon'));
    assert_eq([['09:00', '17:00']], wpultra_book_day_hours($staff, ' MON '), 'case/space tolerant');
    assert_eq([], wpultra_book_day_hours($staff, 'sun'), 'explicit off day');
    assert_eq([], wpultra_book_day_hours($staff, 'tue'), 'missing day = off');
    assert_eq([], wpultra_book_day_hours($staff, 'xyz'), 'unknown key');
    assert_eq([], wpultra_book_day_hours(['name' => 'x'], 'mon'), 'no hours at all');
});

it('default_hours is Mon-Fri 9-5, weekend off', function () {
    $h = wpultra_book_default_hours();
    assert_eq([['09:00', '17:00']], $h['mon']);
    assert_eq([['09:00', '17:00']], $h['fri']);
    assert_eq([], $h['sat']);
    assert_eq([], $h['sun']);
});

/* ============================================================
 * Staff roster (pure list ops).
 * ============================================================ */

it('staff_validate enforces name, email shape and hours validity', function () {
    assert_true(is_string(wpultra_book_staff_validate([])), 'name required');
    assert_true(is_string(wpultra_book_staff_validate(['name' => 'Ana', 'email' => 'nope'])), 'bad email');
    assert_true(is_string(wpultra_book_staff_validate(['name' => 'Ana', 'hours' => 'x'])), 'hours not a map');
    assert_true(is_string(wpultra_book_staff_validate(['name' => 'Ana', 'hours' => ['fun' => []]])), 'bad day key');
    assert_true(wpultra_book_staff_validate(['name' => 'Ana']) === true, 'minimal ok');
    assert_true(wpultra_book_staff_validate(['name' => 'Ana', 'email' => 'a@b.co', 'hours' => ['mon' => [['09:00', '17:00']]]]) === true);
});

it('staff_upsert assigns next id (max+1) and applies default hours', function () {
    $r1 = wpultra_book_staff_upsert([], ['name' => 'Ana', 'email' => 'ANA@Salon.Test']);
    assert_eq(1, $r1['id']);
    assert_eq('ana@salon.test', $r1['list'][0]['email'], 'email lowercased');
    assert_eq([['09:00', '17:00']], $r1['list'][0]['hours']['mon'], 'default hours');
    assert_eq([], $r1['list'][0]['hours']['sat']);

    $r2 = wpultra_book_staff_upsert($r1['list'], ['name' => 'Bo']);
    assert_eq(2, $r2['id']);
    assert_eq(2, count($r2['list']));

    // Gap in ids: next is max+1, not count+1.
    $r3 = wpultra_book_staff_upsert([['id' => 7, 'name' => 'Cy']], ['name' => 'Di']);
    assert_eq(8, $r3['id']);
});

it('staff_upsert with an existing id merges (given fields overwrite, rest kept)', function () {
    $list = wpultra_book_staff_upsert([], ['name' => 'Ana', 'email' => 'a@b.co', 'hours' => ['mon' => [['10:00', '14:00']]]])['list'];
    $r = wpultra_book_staff_upsert($list, ['id' => 1, 'email' => 'new@b.co']);
    assert_eq(1, $r['id']);
    assert_eq(1, count($r['list']), 'no duplicate row');
    assert_eq('Ana', $r['list'][0]['name'], 'name kept');
    assert_eq('new@b.co', $r['list'][0]['email'], 'email replaced');
    assert_eq(['mon' => [['10:00', '14:00']]], $r['list'][0]['hours'], 'hours kept');
});

it('staff_find and staff_remove work by id', function () {
    $list = [['id' => 1, 'name' => 'Ana'], ['id' => 2, 'name' => 'Bo']];
    assert_eq('Bo', wpultra_book_staff_find($list, 2)['name']);
    assert_eq(null, wpultra_book_staff_find($list, 9));
    $after = wpultra_book_staff_remove($list, 1);
    assert_eq(1, count($after));
    assert_eq(2, $after[0]['id']);
    assert_eq($list, wpultra_book_staff_remove($list, 9), 'removing a ghost is a no-op');
});

/* ============================================================
 * THE slot engine.
 * ============================================================ */

it('slots: full 9-5 day, 60min service, 30min grid → 15 slots 09:00..16:00', function () {
    $s = wpultra_book_slots([['09:00', '17:00']], 60, 0, [], 0);
    assert_eq(15, count($s));
    assert_eq(['start_ts' => 32400, 'end_ts' => 36000, 'time' => '09:00'], $s[0]);
    assert_eq('16:00', $s[14]['time'], 'last slot ends exactly at close');
    assert_eq(57600, $s[14]['start_ts']);
});

it('slots: the service must FIT the window (no slot may run past close)', function () {
    assert_eq(1, count(wpultra_book_slots([['09:00', '10:00']], 60, 0, [], 0)), 'exactly one fits');
    assert_eq([], wpultra_book_slots([['09:00', '10:00']], 90, 0, [], 0), 'too long to fit');
});

it('slots: busy interval knocks out every overlapping slot (touching is OK)', function () {
    // Busy 10:00-11:00. 60-min slots overlapping it: 09:30, 10:00, 10:30.
    $busy = [['start_ts' => 36000, 'end_ts' => 39600]];
    $s = wpultra_book_slots([['09:00', '17:00']], 60, 0, $busy, 0);
    assert_eq(12, count($s));
    $times = array_column($s, 'time');
    assert_true(in_array('09:00', $times, true), '09:00 ends exactly at busy start — allowed');
    assert_true(!in_array('09:30', $times, true));
    assert_true(!in_array('10:00', $times, true));
    assert_true(!in_array('10:30', $times, true));
    assert_true(in_array('11:00', $times, true), '11:00 starts exactly at busy end — allowed');
});

it('slots: buffer pads the busy interval on BOTH sides', function () {
    // Busy 10:00-11:00 + 30min buffer → effectively 09:30-11:30 blocked.
    $busy = [['start_ts' => 36000, 'end_ts' => 39600]];
    $s = wpultra_book_slots([['09:00', '17:00']], 60, 30, $busy, 0);
    assert_eq(10, count($s));
    assert_eq('11:30', $s[0]['time'], '09:00-11:00 all gone (the 09:00 slot would end inside the pre-buffer)');
});

it('slots: multiple windows model a lunch break', function () {
    $s = wpultra_book_slots([['09:00', '12:00'], ['13:00', '17:00']], 60, 0, [], 0);
    assert_eq(12, count($s), '5 morning + 7 afternoon');
    $times = array_column($s, 'time');
    assert_true(in_array('11:00', $times, true), 'last morning start');
    assert_true(!in_array('11:30', $times, true), 'would spill into lunch');
    assert_true(!in_array('12:00', $times, true), 'lunch');
    assert_true(in_array('13:00', $times, true), 'first afternoon start');
});

it('slots: past slots are dropped when $now is given', function () {
    $noon = 12 * 3600; // 12:00 on the epoch day
    $s = wpultra_book_slots([['09:00', '17:00']], 60, 0, [], 0, 30, $noon);
    assert_eq(9, count($s), '12:00..16:00 remain');
    assert_eq('12:00', $s[0]['time'], 'a slot starting exactly at $now survives');
});

it('slots: custom step + result ordering + dedupe across overlapping windows', function () {
    assert_eq(3, count(wpultra_book_slots([['09:00', '10:00']], 30, 0, [], 0, 15)), '09:00/09:15/09:30');
    $s = wpultra_book_slots([['10:00', '12:00'], ['09:00', '11:00']], 60, 0, [], 0);
    assert_eq(['09:00', '09:30', '10:00', '10:30', '11:00'], array_column($s, 'time'), 'sorted + deduped');
});

it('slots: garbage in → empty out (never throws)', function () {
    assert_eq([], wpultra_book_slots([], 60, 0, [], 0), 'no windows');
    assert_eq([], wpultra_book_slots([['17:00', '09:00']], 60, 0, [], 0), 'inverted window');
    assert_eq([], wpultra_book_slots(['nonsense'], 60, 0, [], 0), 'malformed window');
    // Invalid busy entries are ignored, not fatal.
    $s = wpultra_book_slots([['09:00', '10:00']], 60, 0, [['start_ts' => 0, 'end_ts' => 0], 'junk'], 0);
    assert_eq(1, count($s));
    // Non-positive duration/step fall back to defaults instead of looping forever.
    assert_eq(15, count(wpultra_book_slots([['09:00', '17:00']], 0, 0, [], 0, 0)));
});

it('slots: absolute day offset — same windows on a later day shift by day_start_ts', function () {
    $day = 86400 * 10;
    $s = wpultra_book_slots([['09:00', '10:00']], 60, 0, [], $day);
    assert_eq($day + 32400, $s[0]['start_ts']);
    assert_eq('09:00', $s[0]['time'], 'time label stays day-relative');
});

/* ============================================================
 * can_book — off-grid single-slot check.
 * ============================================================ */

it('can_book: allows off-grid starts inside a window', function () {
    assert_true(wpultra_book_can_book([['09:00', '17:00']], 60, 0, [], 0, 33000) === true, '09:10 start ok');
});

it('can_book: outside_hours before open / spilling past close / empty windows', function () {
    assert_eq('outside_hours', wpultra_book_can_book([['09:00', '17:00']], 60, 0, [], 0, 30600), '08:30');
    assert_eq('outside_hours', wpultra_book_can_book([['09:00', '17:00']], 60, 0, [], 0, 59400), '16:30 ends 17:30');
    assert_true(wpultra_book_can_book([['09:00', '17:00']], 60, 0, [], 0, 57600) === true, '16:00 ends exactly at close');
    assert_eq('outside_hours', wpultra_book_can_book([], 60, 0, [], 0, 36000), 'day off');
});

it('can_book: conflict honors the buffer, touching without buffer is fine', function () {
    $busy = [['start_ts' => 36000, 'end_ts' => 39600]]; // 10:00-11:00
    assert_eq('conflict', wpultra_book_can_book([['09:00', '17:00']], 60, 0, $busy, 0, 37800), '10:30 overlaps');
    assert_true(wpultra_book_can_book([['09:00', '17:00']], 60, 0, $busy, 0, 39600) === true, '11:00 touches busy end');
    assert_eq('conflict', wpultra_book_can_book([['09:00', '17:00']], 60, 15, $busy, 0, 39600), '11:00 hits the 15min buffer');
});

it('can_book: past beats every other check', function () {
    assert_eq('past', wpultra_book_can_book([['09:00', '17:00']], 60, 0, [], 0, 36000, 40000));
    assert_true(wpultra_book_can_book([['09:00', '17:00']], 60, 0, [], 0, 36000, 36000) === true, 'start == now is not past');
});

/* ============================================================
 * Intervals, busy lists, overlap.
 * ============================================================ */

it('overlaps is half-open: touching endpoints do not overlap', function () {
    assert_true(!wpultra_book_overlaps(0, 10, 10, 20));
    assert_true(!wpultra_book_overlaps(10, 20, 0, 10));
    assert_true(wpultra_book_overlaps(0, 11, 10, 20));
    assert_true(wpultra_book_overlaps(12, 13, 10, 20), 'fully inside');
});

it('busy_intervals: only pending/confirmed block; staff 0 counts everyone', function () {
    $metas = [
        ['staff_id' => 1, 'status' => 'pending',   'start_ts' => 100, 'end_ts' => 200],
        ['staff_id' => 1, 'status' => 'cancelled', 'start_ts' => 300, 'end_ts' => 400],
        ['staff_id' => 2, 'status' => 'confirmed', 'start_ts' => 500, 'end_ts' => 600],
        ['staff_id' => 1, 'status' => 'completed', 'start_ts' => 700, 'end_ts' => 800],
        ['staff_id' => 1, 'status' => 'confirmed', 'start_ts' => 0,   'end_ts' => 100], // invalid start
        'junk',
    ];
    assert_eq([['start_ts' => 100, 'end_ts' => 200]], wpultra_book_busy_intervals($metas, 1));
    assert_eq([['start_ts' => 500, 'end_ts' => 600]], wpultra_book_busy_intervals($metas, 2));
    assert_eq(2, count(wpultra_book_busy_intervals($metas, 0)), 'staff 0 = all active bookings');
});

/* ============================================================
 * Service + customer + booking blobs.
 * ============================================================ */

it('service_normalize applies defaults and clamps', function () {
    $m = wpultra_book_service_normalize([]);
    assert_eq(60, $m['duration_min']);
    assert_eq(0.0, $m['price']);
    assert_eq([], $m['staff_ids']);
    assert_eq(0, $m['buffer_min']);
    assert_eq(true, $m['active']);

    assert_eq(5, wpultra_book_service_normalize(['duration_min' => 3])['duration_min'], 'floor 5');
    assert_eq(60, wpultra_book_service_normalize(['duration_min' => 0])['duration_min'], 'non-positive → default');
    assert_eq(1440, wpultra_book_service_normalize(['duration_min' => 9000])['duration_min'], 'cap 1 day');
    assert_eq(0.0, wpultra_book_service_normalize(['price' => -5])['price']);
    assert_eq(19.99, wpultra_book_service_normalize(['price' => '19.99'])['price']);
    assert_eq(240, wpultra_book_service_normalize(['buffer_min' => 999])['buffer_min']);
    assert_eq([2, 3], wpultra_book_service_normalize(['staff_ids' => [2, 2, 0, -1, 3]])['staff_ids'], 'deduped, positives only');
    assert_eq(false, wpultra_book_service_normalize(['active' => false])['active']);
    assert_eq(true, wpultra_book_service_normalize(['active' => '1'])['active']);
    assert_eq(false, wpultra_book_service_normalize(['active' => 0])['active']);
});

it('service_shape merges id + name over the normalized meta', function () {
    $s = wpultra_book_service_shape(['duration_min' => 45], 12, 'Haircut');
    assert_eq(12, $s['id']);
    assert_eq('Haircut', $s['name']);
    assert_eq(45, $s['duration_min']);
    assert_eq(true, $s['active']);
});

it('customer_validate requires name + a valid email', function () {
    assert_true(is_string(wpultra_book_customer_validate([])));
    assert_true(is_string(wpultra_book_customer_validate(['name' => 'Sam'])), 'email required');
    assert_true(is_string(wpultra_book_customer_validate(['name' => 'Sam', 'email' => 'nope'])));
    assert_true(is_string(wpultra_book_customer_validate(['email' => 's@x.co'])), 'name required');
    assert_true(wpultra_book_customer_validate(['name' => 'Sam', 'email' => 's@x.co']) === true);
});

it('title builds "name — service @ when"', function () {
    assert_eq('Sam — Haircut @ 2026-07-10 10:00', wpultra_book_title('Sam', 'Haircut', '2026-07-10 10:00'));
    assert_eq('Sam — Haircut @ x', wpultra_book_title('  Sam ', ' Haircut ', 'x'), 'trims');
});

it('new_meta → shape roundtrip (pending, not reminded, email lowercased)', function () {
    $meta = wpultra_book_new_meta(12, 3, ['name' => ' Sam ', 'email' => 'SAM@X.CO', 'phone' => '017'], 1000, 4600, 'note', 999);
    assert_eq('pending', $meta['status']);
    assert_eq(false, $meta['reminded']);
    assert_eq('sam@x.co', $meta['customer']['email']);
    assert_eq('Sam', $meta['customer']['name']);
    assert_eq(999, $meta['created_at']);

    $b = wpultra_book_shape($meta, 55);
    assert_eq(55, $b['id']);
    assert_eq(12, $b['service_id']);
    assert_eq(3, $b['staff_id']);
    assert_eq(1000, $b['start_ts']);
    assert_eq(4600, $b['end_ts']);
    assert_eq('note', $b['note']);
    // Shape survives an empty blob with safe defaults.
    $empty = wpultra_book_shape([], 1);
    assert_eq('pending', $empty['status']);
    assert_eq(['name' => '', 'email' => '', 'phone' => ''], $empty['customer']);
});

/* ============================================================
 * Status transitions + reminders.
 * ============================================================ */

it('can_transition enforces the lifecycle matrix', function () {
    assert_true(wpultra_book_can_transition('pending', 'confirmed'));
    assert_true(wpultra_book_can_transition('pending', 'cancelled'));
    assert_true(!wpultra_book_can_transition('pending', 'completed'), 'must confirm first');
    assert_true(wpultra_book_can_transition('confirmed', 'completed'));
    assert_true(wpultra_book_can_transition('confirmed', 'cancelled'));
    assert_true(!wpultra_book_can_transition('confirmed', 'pending'), 'no going back');
    assert_true(!wpultra_book_can_transition('completed', 'cancelled'), 'terminal');
    assert_true(!wpultra_book_can_transition('cancelled', 'confirmed'), 'terminal');
    assert_true(wpultra_book_can_transition('completed', 'completed'), 'same-state no-op ok');
    assert_true(!wpultra_book_can_transition('nope', 'confirmed'));
    assert_true(!wpultra_book_can_transition('pending', 'nope'));
});

it('reminder_due: future start within the lead window, active, not yet reminded', function () {
    $now = 1000000;
    $base = ['status' => 'confirmed', 'reminded' => false, 'start_ts' => $now + 3600];
    assert_true(wpultra_book_reminder_due($base, $now));
    assert_true(wpultra_book_reminder_due(['status' => 'pending'] + $base, $now), 'pending also reminded');
    assert_true(!wpultra_book_reminder_due(['reminded' => true] + $base, $now), 'already reminded');
    assert_true(!wpultra_book_reminder_due(['status' => 'cancelled'] + $base, $now));
    assert_true(!wpultra_book_reminder_due(['status' => 'completed'] + $base, $now));
    assert_true(!wpultra_book_reminder_due(['start_ts' => $now - 10] + $base, $now), 'already started');
    assert_true(!wpultra_book_reminder_due(['start_ts' => $now + 86401] + $base, $now), 'beyond the 24h lead');
    assert_true(wpultra_book_reminder_due(['start_ts' => $now + 86400] + $base, $now), 'exactly at the lead edge');
    assert_true(wpultra_book_reminder_due(['start_ts' => $now + 7 * 86400] + $base, $now, 8 * 86400), 'custom lead');
});

/* ============================================================
 * Booking list filter.
 * ============================================================ */

it('filter matches status / staff / service / range / search', function () {
    $items = [
        ['id' => 1, 'meta' => ['status' => 'pending', 'staff_id' => 1, 'service_id' => 10, 'start_ts' => 1000, 'customer' => ['name' => 'Sam Aref', 'email' => 'sam@x.co']]],
        ['id' => 2, 'meta' => ['status' => 'confirmed', 'staff_id' => 2, 'service_id' => 10, 'start_ts' => 2000, 'customer' => ['name' => 'Bo', 'email' => 'bo@y.co']]],
        ['id' => 3, 'meta' => ['status' => 'cancelled', 'staff_id' => 1, 'service_id' => 11, 'start_ts' => 3000, 'customer' => ['name' => 'Cy', 'email' => 'cy@z.co']]],
    ];
    assert_eq([1], array_column(wpultra_book_filter($items, ['status' => 'pending']), 'id'));
    assert_eq([1, 3], array_column(wpultra_book_filter($items, ['staff_id' => 1]), 'id'));
    assert_eq([1, 2], array_column(wpultra_book_filter($items, ['service_id' => 10]), 'id'));
    assert_eq([2, 3], array_column(wpultra_book_filter($items, ['from_ts' => 2000]), 'id'), 'from is inclusive');
    assert_eq([1], array_column(wpultra_book_filter($items, ['to_ts' => 2000]), 'id'), 'to is exclusive');
    assert_eq([2], array_column(wpultra_book_filter($items, ['from_ts' => 1500, 'to_ts' => 2500]), 'id'));
    assert_eq([1], array_column(wpultra_book_filter($items, ['search' => 'AREF']), 'id'), 'case-insensitive name');
    assert_eq([2], array_column(wpultra_book_filter($items, ['search' => 'bo@y']), 'id'), 'email substring');
    assert_eq([3], array_column(wpultra_book_filter($items, ['status' => 'cancelled', 'staff_id' => 1]), 'id'), 'combined');
    assert_eq([1, 2, 3], array_column(wpultra_book_filter($items, []), 'id'), 'no filters = everything');
});

/* ============================================================
 * Dates, timezones, parsing.
 * ============================================================ */

it('day_bounds returns [midnight, next midnight] and rejects impossible dates', function () {
    $b = wpultra_book_day_bounds('2026-07-06');
    assert_true(is_array($b));
    assert_eq(86400, $b[1] - $b[0], 'exactly one day in UTC');
    assert_eq(null, wpultra_book_day_bounds('2026-02-30'), 'impossible calendar date');
    assert_eq(null, wpultra_book_day_bounds('2026/07/06'), 'wrong separator');
    assert_eq(null, wpultra_book_day_bounds('06-07-2026'));
    assert_eq(null, wpultra_book_day_bounds(''));
});

it('day_key resolves the weekday in the given timezone', function () {
    // 2026-07-06 is a Monday.
    $utc = wpultra_book_day_bounds('2026-07-06')[0];
    assert_eq('mon', wpultra_book_day_key($utc));
    // Dhaka midnight for the same date is 6h earlier in UTC — still Monday locally, Sunday in UTC.
    $dhaka = new DateTimeZone('Asia/Dhaka');
    $local_midnight = wpultra_book_day_bounds('2026-07-06', $dhaka)[0];
    assert_eq($utc - 6 * 3600, $local_midnight, 'Dhaka is UTC+6');
    assert_eq('mon', wpultra_book_day_key($local_midnight, $dhaka));
    assert_eq('sun', wpultra_book_day_key($local_midnight), 'same instant is still Sunday in UTC');
});

it('parse_start accepts unix ts, "Y-m-d H:i" and "HH:MM"+date', function () {
    assert_eq(1780000000, wpultra_book_parse_start(1780000000));
    assert_eq(1780000000, wpultra_book_parse_start('1780000000'), 'numeric string');
    assert_eq(90000, wpultra_book_parse_start('1970-01-02 01:00'), 'datetime in UTC');
    assert_eq(86400 + 37800, wpultra_book_parse_start('10:30', '1970-01-02'), 'bare time + date');
    // Timezone-aware: 01:00 Dhaka = 19:00 UTC the previous day.
    assert_eq(90000 - 6 * 3600, wpultra_book_parse_start('1970-01-02 01:00', '', new DateTimeZone('Asia/Dhaka')));
});

it('parse_start rejects garbage, rollovers and past-midnight times', function () {
    assert_eq(null, wpultra_book_parse_start(''));
    assert_eq(null, wpultra_book_parse_start(0));
    assert_eq(null, wpultra_book_parse_start(-5));
    assert_eq(null, wpultra_book_parse_start('tomorrow'));
    assert_eq(null, wpultra_book_parse_start('2026-02-30 10:00'), 'Feb 30 must not roll over into March');
    assert_eq(null, wpultra_book_parse_start('10:30'), 'bare time without a date');
    assert_eq(null, wpultra_book_parse_start('24:00', '2026-07-06'), '24:00 is a window end, not a start');
    assert_eq(null, wpultra_book_parse_start(['x']));
});

run_tests();
