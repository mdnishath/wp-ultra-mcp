<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_events/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/verticals/events.php';

/* ============================================================
 * wpultra_event_remaining
 * ============================================================ */

it('remaining = capacity - sold for a normal type', function () {
    assert_eq(7, wpultra_event_remaining(['capacity' => 10, 'sold' => 3]));
});

it('remaining floors at 0 when sold-out (or oversold)', function () {
    assert_eq(0, wpultra_event_remaining(['capacity' => 10, 'sold' => 10]));
    assert_eq(0, wpultra_event_remaining(['capacity' => 10, 'sold' => 12]));
});

it('remaining is PHP_INT_MAX for unlimited (capacity 0)', function () {
    assert_eq(PHP_INT_MAX, wpultra_event_remaining(['capacity' => 0, 'sold' => 999]));
    // missing capacity is also treated as unlimited
    assert_eq(PHP_INT_MAX, wpultra_event_remaining(['sold' => 5]));
});

/* ============================================================
 * wpultra_event_can_book
 * ============================================================ */

it('can_book ok when qty within remaining', function () {
    $r = wpultra_event_can_book(['capacity' => 10, 'sold' => 2], 3);
    assert_true($r['ok'], 'ok true');
    assert_eq('ok', $r['reason']);
    assert_eq(8, $r['remaining']);
});

it('can_book bad_qty when qty < 1', function () {
    $r = wpultra_event_can_book(['capacity' => 10, 'sold' => 0], 0);
    assert_true($r['ok'] === false);
    assert_eq('bad_qty', $r['reason']);
    $r2 = wpultra_event_can_book(['capacity' => 10, 'sold' => 0], -1);
    assert_eq('bad_qty', $r2['reason']);
});

it('can_book sold_out when remaining is 0', function () {
    $r = wpultra_event_can_book(['capacity' => 5, 'sold' => 5], 1);
    assert_true($r['ok'] === false);
    assert_eq('sold_out', $r['reason']);
    assert_eq(0, $r['remaining']);
});

it('can_book not_enough when qty exceeds remaining', function () {
    $r = wpultra_event_can_book(['capacity' => 5, 'sold' => 3], 4);
    assert_true($r['ok'] === false);
    assert_eq('not_enough', $r['reason']);
    assert_eq(2, $r['remaining']);
});

it('can_book ok for unlimited type with any positive qty', function () {
    $r = wpultra_event_can_book(['capacity' => 0, 'sold' => 100], 500);
    assert_true($r['ok']);
    assert_eq('ok', $r['reason']);
    assert_eq(PHP_INT_MAX, $r['remaining']);
});

/* ============================================================
 * wpultra_event_ticket_code
 * ============================================================ */

it('ticket_code format embeds event id and seq, deterministic with injected rand', function () {
    $code = wpultra_event_ticket_code(12, 7, static fn() => 0x1A2B);
    assert_eq('EVT-12-0007-1A2B', $code);
});

it('ticket_code pads seq to 4 and masks rand to 4 hex', function () {
    // rand larger than 16 bits is masked to the low 16 bits.
    $code = wpultra_event_ticket_code(3, 42, static fn() => 0x1F0FFFF); // low16 = FFFF
    assert_eq('EVT-3-0042-FFFF', $code);
});

it('ticket_code is deterministic for the same inputs', function () {
    $a = wpultra_event_ticket_code(5, 1, static fn() => 0);
    $b = wpultra_event_ticket_code(5, 1, static fn() => 0);
    assert_eq($a, $b);
    assert_eq('EVT-5-0001-0000', $a);
});

/* ============================================================
 * wpultra_event_validate / validate_ticket_type
 * ============================================================ */

it('validate accepts a well-formed event', function () {
    $ev = ['title' => 'Launch', 'start' => 1000, 'end' => 2000, 'status' => 'published',
           'ticket_types' => [['name' => 'GA', 'price' => 10, 'capacity' => 50]]];
    assert_true(wpultra_event_validate($ev) === true);
});

it('validate fails when start is not before end', function () {
    $err = wpultra_event_validate(['title' => 'X', 'start' => 2000, 'end' => 1000, 'status' => 'draft']);
    assert_true(is_string($err));
    assert_contains('start must be before end', $err);
});

it('validate fails on a bad status', function () {
    $err = wpultra_event_validate(['title' => 'X', 'start' => 1, 'end' => 2, 'status' => 'live']);
    assert_true(is_string($err));
    assert_contains("status 'live' is invalid", $err);
});

it('validate fails on missing title', function () {
    $err = wpultra_event_validate(['title' => '  ', 'start' => 1, 'end' => 2]);
    assert_contains('title is required', (string) $err);
});

it('validate fails when a nested ticket type is invalid', function () {
    $err = wpultra_event_validate(['title' => 'X', 'start' => 1, 'end' => 2,
        'ticket_types' => [['name' => 'GA', 'price' => -5, 'capacity' => 10]]]);
    assert_true(is_string($err));
    assert_contains('ticket_types[0]', (string) $err);
});

it('validate_ticket_type rejects negative price', function () {
    $err = wpultra_event_validate_ticket_type(['name' => 'GA', 'price' => -1, 'capacity' => 0]);
    assert_contains('price must be a number >= 0', (string) $err);
});

it('validate_ticket_type rejects negative capacity', function () {
    $err = wpultra_event_validate_ticket_type(['name' => 'GA', 'price' => 0, 'capacity' => -3]);
    assert_contains('capacity must be an integer >= 0', (string) $err);
});

it('validate_ticket_type accepts free unlimited type (price 0, capacity 0)', function () {
    assert_true(wpultra_event_validate_ticket_type(['name' => 'RSVP', 'price' => 0, 'capacity' => 0]) === true);
});

it('validate_ticket_type rejects empty name', function () {
    assert_contains('name is required', (string) wpultra_event_validate_ticket_type(['name' => '', 'price' => 1, 'capacity' => 1]));
});

/* ============================================================
 * wpultra_event_calendar
 * ============================================================ */

it('calendar groups events by start day, sorted within a day', function () {
    // Two events on the same UTC day (2021-01-01), out of order by start.
    $day = 1609459200; // 2021-01-01 00:00:00 UTC
    $events = [
        ['title' => 'B', 'start' => $day + 7200, 'end' => $day + 9000],
        ['title' => 'A', 'start' => $day + 3600, 'end' => $day + 5400],
    ];
    $cal = wpultra_event_calendar($events, $day, $day + 86400);
    assert_eq(2, $cal['count']);
    assert_true(isset($cal['days']['2021-01-01']), 'day key present');
    $names = array_column($cal['days']['2021-01-01'], 'title');
    assert_eq(['A', 'B'], $names, 'sorted by start ascending');
});

it('calendar filters out events outside the window', function () {
    $day = 1609459200;
    $events = [
        ['title' => 'in',  'start' => $day + 3600, 'end' => $day + 7200],
        ['title' => 'out', 'start' => $day + 10 * 86400, 'end' => $day + 10 * 86400 + 3600],
    ];
    $cal = wpultra_event_calendar($events, $day, $day + 86400);
    assert_eq(1, $cal['count']);
    assert_eq(['in'], array_column($cal['days']['2021-01-01'], 'title'));
});

it('calendar includes an event that overlaps the window edge', function () {
    // Event started before `from` but ends inside the window => overlaps.
    $from = 1609459200;
    $events = [['title' => 'straddle', 'start' => $from - 3600, 'end' => $from + 3600]];
    $cal = wpultra_event_calendar($events, $from, $from + 86400);
    assert_eq(1, $cal['count']);
});

it('calendar returns empty structure for no matches', function () {
    $cal = wpultra_event_calendar([], 1000, 2000);
    assert_eq(0, $cal['count']);
    assert_eq([], $cal['days']);
});

it('calendar days are sorted ascending across multiple days', function () {
    $d1 = 1609459200;             // 2021-01-01
    $d3 = $d1 + 2 * 86400;        // 2021-01-03
    $events = [
        ['title' => 'later', 'start' => $d3 + 3600, 'end' => $d3 + 7200],
        ['title' => 'early', 'start' => $d1 + 3600, 'end' => $d1 + 7200],
    ];
    $cal = wpultra_event_calendar($events, $d1, $d3 + 86400);
    assert_eq(['2021-01-01', '2021-01-03'], array_keys($cal['days']));
});

/* ============================================================
 * wpultra_event_is_upcoming / is_past
 * ============================================================ */

it('is_upcoming true only when start is strictly after now', function () {
    assert_true(wpultra_event_is_upcoming(['start' => 1001], 1000));
    assert_true(wpultra_event_is_upcoming(['start' => 1000], 1000) === false, 'boundary: start == now is not upcoming');
    assert_true(wpultra_event_is_upcoming(['start' => 999], 1000) === false);
});

it('is_past true only when end is at/before now', function () {
    assert_true(wpultra_event_is_past(['end' => 1000], 1000), 'boundary: end == now is past');
    assert_true(wpultra_event_is_past(['end' => 999], 1000));
    assert_true(wpultra_event_is_past(['end' => 1001], 1000) === false);
    assert_true(wpultra_event_is_past(['end' => 0], 1000) === false, 'no end => not past');
});

/* ============================================================
 * wpultra_event_ics
 * ============================================================ */

it('ics emits a valid VEVENT with UTC times', function () {
    $ics = wpultra_event_ics(['title' => 'Launch', 'start' => 1609459200, 'end' => 1609462800]);
    assert_contains('BEGIN:VCALENDAR', $ics);
    assert_contains('BEGIN:VEVENT', $ics);
    assert_contains('END:VEVENT', $ics);
    assert_contains('END:VCALENDAR', $ics);
    assert_contains('DTSTART:20210101T000000Z', $ics);
    assert_contains('DTEND:20210101T010000Z', $ics);
    assert_contains('SUMMARY:Launch', $ics);
});

it('ics escapes commas/semicolons/newlines in the summary', function () {
    $ics = wpultra_event_ics(['title' => "A, B; C\nD", 'start' => 1609459200, 'end' => 1609462800]);
    assert_contains('SUMMARY:A\\, B\\; C\\nD', $ics);
});

it('ics includes escaped location when present', function () {
    $ics = wpultra_event_ics([
        'title' => 'X', 'start' => 1609459200, 'end' => 1609462800,
        'location' => ['name' => 'Hall A', 'address' => '1 Main St, Town'],
    ]);
    assert_contains('LOCATION:Hall A\\, 1 Main St\\, Town', $ics);
});

/* ============================================================
 * wpultra_event_ticket_html
 * ============================================================ */

it('ticket_html escapes attendee fields and includes the code', function () {
    $html = wpultra_event_ticket_html(
        ['code' => 'EVT-1-0001-ABCD', 'qty' => 2, 'attendee' => ['name' => '<b>Bob</b>', 'email' => 'a@b.co']],
        ['title' => 'Launch & Party', 'start' => 1609459200]
    );
    // Attendee name must be escaped, raw tag absent.
    assert_true(!str_contains($html, '<b>Bob</b>'), 'raw attendee tag escaped');
    assert_contains('&lt;b&gt;Bob&lt;/b&gt;', $html);
    // Event title ampersand escaped.
    assert_contains('Launch &amp; Party', $html);
    // Code present both as text and in the data attribute.
    assert_contains('EVT-1-0001-ABCD', $html);
    assert_contains('data-ticket-code="EVT-1-0001-ABCD"', $html);
    assert_contains('Qty: 2', $html);
});

it('ticket_html handles a missing attendee gracefully', function () {
    $html = wpultra_event_ticket_html(['code' => 'EVT-1-0002-0000', 'qty' => 1], ['title' => 'X', 'start' => 0]);
    assert_contains('EVT-1-0002-0000', $html);
    assert_contains('wpultra-ticket', $html);
});

run_tests();
