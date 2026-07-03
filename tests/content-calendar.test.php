<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
require __DIR__ . '/../wp-ultra-mcp/includes/content/calendar.php';

it('slots generates daily slots (interval_days=1) starting at the given datetime', function () {
    $slots = wpultra_calendar_slots('2026-08-01 09:00:00', 1, 3);
    assert_eq(['2026-08-01 09:00:00', '2026-08-02 09:00:00', '2026-08-03 09:00:00'], $slots);
});

it('slots handles a fractional 0.5-day interval as exactly 12 hours', function () {
    $slots = wpultra_calendar_slots('2026-08-01 00:00:00', 0.5, 4);
    assert_eq([
        '2026-08-01 00:00:00',
        '2026-08-01 12:00:00',
        '2026-08-02 00:00:00',
        '2026-08-02 12:00:00',
    ], $slots);
});

it('slots returns exactly $count entries', function () {
    $slots = wpultra_calendar_slots('2026-01-01 00:00:00', 1, 5);
    assert_eq(5, count($slots));

    $none = wpultra_calendar_slots('2026-01-01 00:00:00', 1, 0);
    assert_eq([], $none);
});

it('slots returns empty array for an unparseable start date', function () {
    assert_eq([], wpultra_calendar_slots('not-a-date', 1, 3));
});

it('slots accumulates with no float drift over 30 slots at a fractional interval', function () {
    $slots = wpultra_calendar_slots('2026-01-01 00:00:00', 0.5, 30);
    assert_eq(30, count($slots));
    // Slot 29 (0-indexed) is 29 * 0.5 = 14.5 days after start: 2026-01-15 12:00:00.
    assert_eq('2026-01-15 12:00:00', $slots[29]);
    // Every slot's time-of-day must be exactly 00:00:00 or 12:00:00 — any float
    // drift would eventually push a slot's seconds/minutes off those marks.
    foreach ($slots as $i => $slot) {
        $time_part = substr($slot, 11);
        assert_true($time_part === '00:00:00' || $time_part === '12:00:00', "slot $i time drifted: $time_part");
    }
});

it('slots with interval_days=2 doubles the spacing', function () {
    $slots = wpultra_calendar_slots('2026-03-01 06:00:00', 2, 3);
    assert_eq(['2026-03-01 06:00:00', '2026-03-03 06:00:00', '2026-03-05 06:00:00'], $slots);
});

it('validate_date accepts strtotime-parseable strings', function () {
    assert_true(wpultra_calendar_validate_date('2026-08-01 09:00:00') === true);
    assert_true(wpultra_calendar_validate_date('tomorrow') === true);
    assert_true(wpultra_calendar_validate_date('+1 week') === true);
});

it('validate_date rejects empty and unparseable strings', function () {
    $empty = wpultra_calendar_validate_date('');
    assert_true(is_string($empty), 'empty date returns an error message string');

    $bad = wpultra_calendar_validate_date('definitely-not-a-date-xyz');
    assert_true(is_string($bad), 'garbage date returns an error message string');
});

it('group_by_day buckets rows by their Y-m-d date', function () {
    $grouped = wpultra_calendar_group_by_day([
        ['id' => 1, 'date' => '2026-08-01 09:00:00'],
        ['id' => 2, 'date' => '2026-08-01 15:00:00'],
        ['id' => 3, 'date' => '2026-08-02 09:00:00'],
    ]);
    assert_eq(2, count($grouped['2026-08-01']));
    assert_eq(1, count($grouped['2026-08-02']));
    assert_eq(1, $grouped['2026-08-01'][0]['id']);
});

it('group_by_day buckets rows with missing/unparseable dates under the empty-string key', function () {
    $grouped = wpultra_calendar_group_by_day([
        ['id' => 1],
        ['id' => 2, 'date' => 'garbage'],
    ]);
    assert_eq(2, count($grouped['']));
});

it('group_by_day preserves row order within a day', function () {
    $grouped = wpultra_calendar_group_by_day([
        ['id' => 5, 'date' => '2026-08-01 09:00:00'],
        ['id' => 6, 'date' => '2026-08-01 08:00:00'],
    ]);
    assert_eq(5, $grouped['2026-08-01'][0]['id']);
    assert_eq(6, $grouped['2026-08-01'][1]['id']);
});

run_tests();
