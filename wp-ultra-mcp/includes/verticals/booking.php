<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Booking / appointments engine (roadmap E1).
 *
 * Model:
 *   Services — CPT `wpultra_service` (private, no UI). post_title = service
 *     name; meta `_wpultra_service` = {duration_min (default 60), price
 *     (informational, currency-agnostic), staff_ids[], buffer_min (default 0),
 *     active (bool)}.
 *   Staff — option `wpultra_booking_staff` = [{id, name, email, hours}],
 *     hours = {mon..sun => [['09:00','17:00'], ...] | [] (day off)}.
 *   Bookings — CPT `wpultra_booking` (private, no UI). post_title =
 *     "name — service @ datetime"; meta `_wpultra_booking` = {service_id,
 *     staff_id, customer{name,email,phone}, start_ts, end_ts, status
 *     (pending|confirmed|cancelled|completed), note, created_at, reminded}.
 *
 * The heart is the PURE slot engine: candidate starts every $step_min on a
 * grid inside the staff member's working windows for the day, minus anything
 * overlapping a busy interval (padded by the service buffer on both sides),
 * minus past slots. Exhaustively unit-tested by tests/booking.test.php.
 *
 * Runtime (wpultra_booking_boot, called by the controller on plugins_loaded):
 * CPT registration on init, the [wpultra_booking] shortcode, a front-end POST
 * handler (nonce + honeypot) on template_redirect, and a daily cron
 * `wpultra_book_reminders` that emails customers ~24h before their slot.
 *
 * PURE functions FIRST (prefix wpultra_book_, no WP calls — harness
 * loadable); thin WordPress wrappers after, each guarded.
 */

if (!defined('WPULTRA_BOOK_SERVICE_CPT')) { define('WPULTRA_BOOK_SERVICE_CPT', 'wpultra_service'); }
if (!defined('WPULTRA_BOOK_CPT')) { define('WPULTRA_BOOK_CPT', 'wpultra_booking'); }
if (!defined('WPULTRA_BOOK_CRON')) { define('WPULTRA_BOOK_CRON', 'wpultra_book_reminders'); }
if (!defined('WPULTRA_BOOK_STAFF_OPTION')) { define('WPULTRA_BOOK_STAFF_OPTION', 'wpultra_booking_staff'); }

/* =====================================================================
 * PURE core — no WordPress calls (harness-loadable).
 * ===================================================================== */

/** Canonical weekday keys, Monday first (index = date('N') - 1). Pure. */
function wpultra_book_day_keys(): array {
    return ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
}

/** Booking lifecycle statuses. Pure. */
function wpultra_book_statuses(): array {
    return ['pending', 'confirmed', 'cancelled', 'completed'];
}

/** Statuses that occupy a slot (block other bookings). Pure. */
function wpultra_book_active_statuses(): array {
    return ['pending', 'confirmed'];
}

/**
 * Legal status transitions. pending → confirmed|cancelled; confirmed →
 * completed|cancelled; completed/cancelled are terminal. Same-state is a
 * no-op and allowed. Pure.
 */
function wpultra_book_can_transition(string $from, string $to): bool {
    $all = wpultra_book_statuses();
    if (!in_array($from, $all, true) || !in_array($to, $all, true)) { return false; }
    if ($from === $to) { return true; }
    $allowed = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];
    return in_array($to, $allowed[$from], true);
}

/** Strict-ish email shape check (full-string match). Pure. */
function wpultra_book_email_valid(string $email): bool {
    return (bool) preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email);
}

/** Length-cap helper that survives missing mbstring. Pure. */
function wpultra_book_trim_len(string $s, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/**
 * 'HH:MM' → minutes since midnight (0..1440). Minutes are REQUIRED and
 * 2-digit; '24:00' is a valid window END. Null when malformed or out of
 * range. Pure.
 */
function wpultra_book_hhmm_to_min(string $hhmm): ?int {
    $hhmm = trim($hhmm);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) { return null; }
    $h = (int) $m[1];
    $i = (int) $m[2];
    if ($i > 59) { return null; }
    if ($h > 24 || ($h === 24 && $i !== 0)) { return null; }
    return $h * 60 + $i;
}

/** Minutes since midnight → 'HH:MM' (1440 → '24:00'). Pure. */
function wpultra_book_min_to_hhmm(int $min): string {
    $min = max(0, min(1440, $min));
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/**
 * Convert a windows list [['09:00','17:00'], ...] to minute pairs
 * [[540,1020], ...], sorted by start. Null when ANY entry is malformed
 * (not a 2-item pair, bad HH:MM, or start >= end). Pure.
 */
function wpultra_book_windows_to_min(array $windows): ?array {
    $out = [];
    foreach ($windows as $w) {
        if (!is_array($w) || count($w) !== 2) { return null; }
        $vals = array_values($w);
        $s = wpultra_book_hhmm_to_min((string) $vals[0]);
        $e = wpultra_book_hhmm_to_min((string) $vals[1]);
        if ($s === null || $e === null || $s >= $e) { return null; }
        $out[] = [$s, $e];
    }
    usort($out, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    return $out;
}

/**
 * Validate a full hours map {mon..sun => windows[]}. Unknown day keys and
 * malformed windows are errors; missing days are fine (treated as off).
 * Returns true or an error string. Pure.
 */
function wpultra_book_hours_validate(array $hours) {
    $days = wpultra_book_day_keys();
    foreach ($hours as $day => $windows) {
        $day = strtolower((string) $day);
        if (!in_array($day, $days, true)) {
            return "Unknown day key '$day'. Use: " . implode(', ', $days);
        }
        if (!is_array($windows)) { return "hours.$day must be an array of ['HH:MM','HH:MM'] pairs."; }
        if ($windows !== [] && wpultra_book_windows_to_min($windows) === null) {
            return "hours.$day has an invalid window. Each window is ['HH:MM','HH:MM'] with start < end.";
        }
    }
    return true;
}

/**
 * Normalize an hours map: lowercase day keys, known days only, windows
 * re-emitted as sorted zero-padded HH:MM pairs. Invalid windows are DROPPED
 * (validate first if you want hard errors). Days absent from the input are
 * absent from the output. Pure.
 */
function wpultra_book_hours_normalize(array $hours): array {
    $out = [];
    foreach (wpultra_book_day_keys() as $day) {
        $raw = null;
        foreach ($hours as $k => $v) {
            if (strtolower((string) $k) === $day) { $raw = $v; break; }
        }
        if (!is_array($raw)) { continue; }
        $mins = [];
        foreach ($raw as $w) {
            if (!is_array($w) || count($w) !== 2) { continue; }
            $vals = array_values($w);
            $s = wpultra_book_hhmm_to_min((string) $vals[0]);
            $e = wpultra_book_hhmm_to_min((string) $vals[1]);
            if ($s === null || $e === null || $s >= $e) { continue; }
            $mins[] = [$s, $e];
        }
        usort($mins, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $out[$day] = array_map(
            static fn(array $p): array => [wpultra_book_min_to_hhmm($p[0]), wpultra_book_min_to_hhmm($p[1])],
            $mins
        );
    }
    return $out;
}

/**
 * Validate one staff record {id?, name, email?, hours?}: name required;
 * email (when non-empty) must look like an email; hours (when present) must
 * validate. Returns true or an error string. Pure.
 */
function wpultra_book_staff_validate(array $staff) {
    $name = trim((string) ($staff['name'] ?? ''));
    if ($name === '') { return 'Staff name is required.'; }
    $email = trim((string) ($staff['email'] ?? ''));
    if ($email !== '' && !wpultra_book_email_valid($email)) {
        return "Invalid staff email: $email";
    }
    if (array_key_exists('hours', $staff)) {
        if (!is_array($staff['hours'])) { return 'hours must be a map of day => windows.'; }
        $h = wpultra_book_hours_validate($staff['hours']);
        if ($h !== true) { return (string) $h; }
    }
    return true;
}

/** Default working hours: Mon–Fri 09:00–17:00, weekend off. Pure. */
function wpultra_book_default_hours(): array {
    $out = [];
    foreach (wpultra_book_day_keys() as $d) {
        $out[$d] = in_array($d, ['sat', 'sun'], true) ? [] : [['09:00', '17:00']];
    }
    return $out;
}

/**
 * Upsert a staff record into the roster. id 0/missing assigns the next id
 * (max+1); an existing id merges (given fields overwrite, missing fields
 * kept). New members without hours get the default Mon–Fri 9–5.
 * Returns ['list' => roster, 'id' => int]. Pure.
 */
function wpultra_book_staff_upsert(array $list, array $staff): array {
    $list = array_values(array_filter($list, 'is_array'));
    $id = (int) ($staff['id'] ?? 0);
    $max = 0;
    $idx = -1;
    foreach ($list as $i => $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid > $max) { $max = $sid; }
        if ($id > 0 && $sid === $id) { $idx = $i; }
    }
    if ($id <= 0) { $id = $max + 1; }

    $existing = $idx >= 0 ? $list[$idx] : [];
    $rec = [
        'id'    => $id,
        'name'  => wpultra_book_trim_len(trim((string) ($staff['name'] ?? ($existing['name'] ?? ''))), 120),
        'email' => strtolower(trim((string) ($staff['email'] ?? ($existing['email'] ?? '')))),
        'hours' => wpultra_book_hours_normalize(
            is_array($staff['hours'] ?? null) ? $staff['hours']
                : (is_array($existing['hours'] ?? null) ? $existing['hours'] : wpultra_book_default_hours())
        ),
    ];
    if ($idx >= 0) { $list[$idx] = $rec; } else { $list[] = $rec; }
    return ['list' => $list, 'id' => $id];
}

/** Find a staff record by id. Null when absent. Pure. */
function wpultra_book_staff_find(array $list, int $id): ?array {
    foreach ($list as $s) {
        if (is_array($s) && (int) ($s['id'] ?? 0) === $id) { return $s; }
    }
    return null;
}

/** Remove a staff record by id (order preserved, reindexed). Pure. */
function wpultra_book_staff_remove(array $list, int $id): array {
    $out = [];
    foreach ($list as $s) {
        if (is_array($s) && (int) ($s['id'] ?? 0) === $id) { continue; }
        $out[] = $s;
    }
    return $out;
}

/**
 * A staff member's working windows for one day key, as normalized HH:MM
 * pairs. Missing day / invalid key / no hours → [] (day off). Pure.
 */
function wpultra_book_day_hours(array $staff, string $day_key): array {
    $day_key = strtolower(trim($day_key));
    if (!in_array($day_key, wpultra_book_day_keys(), true)) { return []; }
    $hours = is_array($staff['hours'] ?? null) ? $staff['hours'] : [];
    $norm = wpultra_book_hours_normalize($hours);
    return $norm[$day_key] ?? [];
}

/** Half-open interval overlap: [s1,e1) vs [s2,e2) — touching does NOT overlap. Pure. */
function wpultra_book_overlaps(int $s1, int $e1, int $s2, int $e2): bool {
    return $s1 < $e2 && $s2 < $e1;
}

/** Normalize a busy list to valid [{start_ts,end_ts}] intervals (end > start > 0). Pure. */
function wpultra_book_busy_normalize(array $busy): array {
    $out = [];
    foreach ($busy as $b) {
        if (!is_array($b)) { continue; }
        $s = (int) ($b['start_ts'] ?? 0);
        $e = (int) ($b['end_ts'] ?? 0);
        if ($s <= 0 || $e <= $s) { continue; }
        $out[] = ['start_ts' => $s, 'end_ts' => $e];
    }
    return $out;
}

/**
 * THE slot engine. Generate bookable start times for one day.
 *
 * $windows      HH:MM pairs — a day's working windows, e.g. [['09:00','17:00']]
 * $duration_min appointment length in minutes (defaults to 60 when < 1)
 * $buffer_min   clearance kept around BUSY intervals, both sides (>= 0)
 * $busy         [{start_ts, end_ts}] existing appointments (absolute unix ts)
 * $day_start_ts unix ts of the day's local midnight
 * $step_min     candidate grid step (defaults to 30 when < 1)
 * $now          when > 0, slots starting before $now are dropped (past slots)
 *
 * A slot occupies [start, start+duration); it is offered only when it fits
 * entirely inside a window AND overlaps no buffer-padded busy interval.
 * Returns sorted, deduped [{start_ts, end_ts, time:'HH:MM'}, ...]. Pure.
 */
function wpultra_book_slots(array $windows, int $duration_min, int $buffer_min, array $busy, int $day_start_ts, int $step_min = 30, int $now = 0): array {
    if ($duration_min < 1) { $duration_min = 60; }
    if ($step_min < 1) { $step_min = 30; }
    if ($buffer_min < 0) { $buffer_min = 0; }
    $mins = wpultra_book_windows_to_min($windows);
    if ($mins === null || $mins === []) { return []; }

    $busy = wpultra_book_busy_normalize($busy);
    $pad = $buffer_min * 60;
    $dur = $duration_min * 60;

    $slots = [];
    foreach ($mins as [$ws, $we]) {
        for ($t = $ws; $t + $duration_min <= $we; $t += $step_min) {
            $start = $day_start_ts + $t * 60;
            if ($now > 0 && $start < $now) { continue; }
            $end = $start + $dur;
            $free = true;
            foreach ($busy as $b) {
                if (wpultra_book_overlaps($start, $end, $b['start_ts'] - $pad, $b['end_ts'] + $pad)) {
                    $free = false;
                    break;
                }
            }
            if ($free) {
                $slots[$start] = ['start_ts' => $start, 'end_ts' => $end, 'time' => wpultra_book_min_to_hhmm($t)];
            }
        }
    }
    ksort($slots);
    return array_values($slots);
}

/**
 * Can an appointment start at $start_ts? Off-grid starts are allowed as long
 * as the slot fits fully inside ONE working window, overlaps no buffer-padded
 * busy interval, and is not in the past.
 * Returns true, or a reason string: 'past' | 'outside_hours' | 'conflict'. Pure.
 */
function wpultra_book_can_book(array $windows, int $duration_min, int $buffer_min, array $busy, int $day_start_ts, int $start_ts, int $now = 0) {
    if ($duration_min < 1) { $duration_min = 60; }
    if ($buffer_min < 0) { $buffer_min = 0; }
    if ($now > 0 && $start_ts < $now) { return 'past'; }

    $mins = wpultra_book_windows_to_min($windows);
    if ($mins === null || $mins === []) { return 'outside_hours'; }
    $end_ts = $start_ts + $duration_min * 60;
    $inside = false;
    foreach ($mins as [$ws, $we]) {
        if ($start_ts >= $day_start_ts + $ws * 60 && $end_ts <= $day_start_ts + $we * 60) {
            $inside = true;
            break;
        }
    }
    if (!$inside) { return 'outside_hours'; }

    $pad = $buffer_min * 60;
    foreach (wpultra_book_busy_normalize($busy) as $b) {
        if (wpultra_book_overlaps($start_ts, $end_ts, $b['start_ts'] - $pad, $b['end_ts'] + $pad)) {
            return 'conflict';
        }
    }
    return true;
}

/**
 * Normalize a service meta blob: duration_min default 60 (clamped 5..1440),
 * price >= 0 (2dp, informational — currency-agnostic), staff_ids unique
 * positive ints, buffer_min clamped 0..240, active bool default true. Pure.
 */
function wpultra_book_service_normalize(array $in): array {
    $dur = is_numeric($in['duration_min'] ?? null) ? (int) $in['duration_min'] : 60;
    if ($dur <= 0) { $dur = 60; } elseif ($dur < 5) { $dur = 5; } elseif ($dur > 1440) { $dur = 1440; }
    $price = is_numeric($in['price'] ?? null) ? round(max(0.0, (float) $in['price']), 2) : 0.0;
    $buffer = is_numeric($in['buffer_min'] ?? null) ? (int) $in['buffer_min'] : 0;
    $buffer = max(0, min(240, $buffer));
    $ids = [];
    foreach ((array) ($in['staff_ids'] ?? []) as $sid) {
        $sid = (int) $sid;
        if ($sid > 0 && !in_array($sid, $ids, true)) { $ids[] = $sid; }
    }
    $active = array_key_exists('active', $in)
        ? ($in['active'] === true || $in['active'] === 1 || $in['active'] === '1')
        : true;
    return [
        'duration_min' => $dur,
        'price'        => $price,
        'staff_ids'    => $ids,
        'buffer_min'   => $buffer,
        'active'       => $active,
    ];
}

/** Canonical output shape for one service. Pure. */
function wpultra_book_service_shape(array $meta, int $id, string $name): array {
    return array_merge(['id' => $id, 'name' => $name], wpultra_book_service_normalize($meta));
}

/**
 * Validate a customer block {name, email, phone?}: name + valid email
 * required. Returns true or an error string. Pure.
 */
function wpultra_book_customer_validate(array $c) {
    if (trim((string) ($c['name'] ?? '')) === '') { return 'Customer name is required.'; }
    $email = trim((string) ($c['email'] ?? ''));
    if ($email === '') { return 'Customer email is required.'; }
    if (!wpultra_book_email_valid($email)) { return "Invalid customer email: $email"; }
    return true;
}

/** Booking post title: "name — service @ when". Pure. */
function wpultra_book_title(string $name, string $service, string $when): string {
    return wpultra_book_trim_len(trim($name), 80) . ' — ' . wpultra_book_trim_len(trim($service), 80) . ' @ ' . $when;
}

/** Fresh meta blob for a new booking (status pending, not reminded). Pure. */
function wpultra_book_new_meta(int $service_id, int $staff_id, array $customer, int $start_ts, int $end_ts, string $note, int $now): array {
    return [
        'service_id' => $service_id,
        'staff_id'   => $staff_id,
        'customer'   => [
            'name'  => wpultra_book_trim_len(trim((string) ($customer['name'] ?? '')), 120),
            'email' => strtolower(trim((string) ($customer['email'] ?? ''))),
            'phone' => wpultra_book_trim_len(trim((string) ($customer['phone'] ?? '')), 40),
        ],
        'start_ts'   => $start_ts,
        'end_ts'     => $end_ts,
        'status'     => 'pending',
        'note'       => wpultra_book_trim_len(trim($note), 1000),
        'created_at' => $now,
        'reminded'   => false,
    ];
}

/** Canonical output shape for one booking. Pure. */
function wpultra_book_shape(array $meta, int $id): array {
    $c = is_array($meta['customer'] ?? null) ? $meta['customer'] : [];
    return [
        'id'         => $id,
        'service_id' => (int) ($meta['service_id'] ?? 0),
        'staff_id'   => (int) ($meta['staff_id'] ?? 0),
        'customer'   => [
            'name'  => (string) ($c['name'] ?? ''),
            'email' => (string) ($c['email'] ?? ''),
            'phone' => (string) ($c['phone'] ?? ''),
        ],
        'start_ts'   => (int) ($meta['start_ts'] ?? 0),
        'end_ts'     => (int) ($meta['end_ts'] ?? 0),
        'status'     => (string) ($meta['status'] ?? 'pending'),
        'note'       => (string) ($meta['note'] ?? ''),
        'created_at' => (int) ($meta['created_at'] ?? 0),
        'reminded'   => ($meta['reminded'] ?? false) === true,
    ];
}

/**
 * Busy intervals from a list of booking meta blobs, for one staff member.
 * staff_id 0 means "count EVERY active booking" (used when no roster is
 * configured). Only pending/confirmed bookings occupy slots. Pure.
 */
function wpultra_book_busy_intervals(array $metas, int $staff_id = 0): array {
    $out = [];
    foreach ($metas as $m) {
        if (!is_array($m)) { continue; }
        if (!in_array((string) ($m['status'] ?? ''), wpultra_book_active_statuses(), true)) { continue; }
        if ($staff_id > 0 && (int) ($m['staff_id'] ?? 0) !== $staff_id) { continue; }
        $s = (int) ($m['start_ts'] ?? 0);
        $e = (int) ($m['end_ts'] ?? 0);
        if ($s > 0 && $e > $s) { $out[] = ['start_ts' => $s, 'end_ts' => $e]; }
    }
    return $out;
}

/**
 * Is a reminder due? status pending/confirmed, not yet reminded, and the
 * start is in the future but within $lead seconds of $now. Pure.
 */
function wpultra_book_reminder_due(array $meta, int $now, int $lead = 86400): bool {
    if (($meta['reminded'] ?? false) === true) { return false; }
    if (!in_array((string) ($meta['status'] ?? ''), wpultra_book_active_statuses(), true)) { return false; }
    $start = (int) ($meta['start_ts'] ?? 0);
    return $start > $now && $start <= $now + $lead;
}

/**
 * Filter a list of {id, meta} bookings: exact status, staff_id, service_id;
 * from_ts (inclusive) / to_ts (exclusive) range against start_ts (each only
 * when > 0); case-insensitive substring search on customer name/email.
 * Order preserved. Pure.
 */
function wpultra_book_filter(array $items, array $filters): array {
    $status = trim((string) ($filters['status'] ?? ''));
    $staff = (int) ($filters['staff_id'] ?? 0);
    $service = (int) ($filters['service_id'] ?? 0);
    $from = (int) ($filters['from_ts'] ?? 0);
    $to = (int) ($filters['to_ts'] ?? 0);
    $search = strtolower(trim((string) ($filters['search'] ?? '')));
    $out = [];
    foreach ($items as $it) {
        $m = is_array($it['meta'] ?? null) ? $it['meta'] : [];
        if ($status !== '' && (string) ($m['status'] ?? '') !== $status) { continue; }
        if ($staff > 0 && (int) ($m['staff_id'] ?? 0) !== $staff) { continue; }
        if ($service > 0 && (int) ($m['service_id'] ?? 0) !== $service) { continue; }
        $start = (int) ($m['start_ts'] ?? 0);
        if ($from > 0 && $start < $from) { continue; }
        if ($to > 0 && $start >= $to) { continue; }
        if ($search !== '') {
            $c = is_array($m['customer'] ?? null) ? $m['customer'] : [];
            $hay = strtolower((string) ($c['name'] ?? '') . ' ' . (string) ($c['email'] ?? ''));
            if (!str_contains($hay, $search)) { continue; }
        }
        $out[] = $it;
    }
    return $out;
}

/** Weekday key ('mon'..'sun') for a timestamp in a timezone (UTC default). Pure. */
function wpultra_book_day_key(int $ts, ?DateTimeZone $tz = null): string {
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz ?? new DateTimeZone('UTC'));
    return strtolower($dt->format('D'));
}

/**
 * Midnight bounds for a 'Y-m-d' date in a timezone: [day_start_ts,
 * day_end_ts] (end = next midnight, DST-safe). Null on a malformed or
 * impossible date. Pure.
 */
function wpultra_book_day_bounds(string $date, ?DateTimeZone $tz = null): ?array {
    $date = trim($date);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) { return null; }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return null; }
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz ?? new DateTimeZone('UTC'));
    if ($start === false) { return null; }
    return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()];
}

/**
 * Parse a start moment into a unix timestamp.
 * Accepts: positive int / all-digit string (unix ts), 'Y-m-d H:i' (resolved
 * in $tz), or a bare 'HH:MM' combined with $date ('Y-m-d'). Null when
 * unparseable or the calendar date rolls over (e.g. Feb 30). Pure.
 */
function wpultra_book_parse_start($start, string $date = '', ?DateTimeZone $tz = null): ?int {
    $tz = $tz ?? new DateTimeZone('UTC');
    if (is_int($start)) { return $start > 0 ? $start : null; }
    if (!is_string($start) && !is_float($start)) { return null; }
    $s = trim((string) $start);
    if ($s === '') { return null; }
    if (preg_match('/^\d{6,}$/', $s)) { return (int) $s; }
    if (preg_match('/^\d{1,2}:\d{2}$/', $s) && trim($date) !== '') {
        $min = wpultra_book_hhmm_to_min($s);
        if ($min === null || $min >= 1440) { return null; }
        $s = trim($date) . ' ' . $s;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{1,2}:\d{2}$/', $s)) {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $s, $tz);
        $err = DateTimeImmutable::getLastErrors();
        $bad = is_array($err) && (($err['warning_count'] ?? 0) > 0 || ($err['error_count'] ?? 0) > 0);
        if ($dt === false || $bad) { return null; }
        return $dt->getTimestamp();
    }
    return null;
}

/* =====================================================================
 * WordPress wrappers — CPTs, persistence, availability, runtime.
 * ===================================================================== */

function wpultra_book_register_cpts(): void {
    if (!function_exists('register_post_type')) { return; }
    $common = [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title'], 'rewrite' => false,
    ];
    register_post_type(WPULTRA_BOOK_SERVICE_CPT, $common + ['labels' => ['name' => 'WP-Ultra Services']]);
    register_post_type(WPULTRA_BOOK_CPT, $common + ['labels' => ['name' => 'WP-Ultra Bookings']]);
}

/** Site timezone (falls back to UTC outside full WP). */
function wpultra_book_tz(): DateTimeZone {
    if (function_exists('wp_timezone')) {
        try { return wp_timezone(); } catch (\Throwable $e) {}
    }
    return new DateTimeZone('UTC');
}

/** Format a ts as 'Y-m-d H:i' in the site timezone. */
function wpultra_book_fmt(int $ts): string {
    if (function_exists('wp_date')) {
        $s = wp_date('Y-m-d H:i', $ts);
        if (is_string($s)) { return $s; }
    }
    return (new DateTimeImmutable('@' . $ts))->setTimezone(wpultra_book_tz())->format('Y-m-d H:i');
}

/** The staff roster (option wpultra_booking_staff). */
function wpultra_book_staff_all(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_BOOK_STAFF_OPTION, []) : [];
    return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
}

function wpultra_book_staff_save_all(array $list): void {
    if (function_exists('update_option')) { update_option(WPULTRA_BOOK_STAFF_OPTION, array_values($list), false); }
}

/** Load a service as its canonical shape. Null when the id is not a live service. */
function wpultra_book_service_load(int $id): ?array {
    if ($id <= 0 || !function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_BOOK_SERVICE_CPT || $post->post_status === 'trash') { return null; }
    $meta = get_post_meta($id, '_wpultra_service', true);
    return wpultra_book_service_shape(is_array($meta) ? $meta : [], $id, (string) $post->post_title);
}

/** Insert a service. @return int|WP_Error */
function wpultra_book_service_insert(string $name, array $meta) {
    if (!function_exists('wp_insert_post')) { return wpultra_err('no_wp', 'WordPress not loaded.'); }
    $id = wp_insert_post([
        'post_type'   => WPULTRA_BOOK_SERVICE_CPT,
        'post_status' => 'publish',
        'post_title'  => wp_slash(wpultra_book_trim_len(trim($name), 120)),
    ], true);
    if (is_wp_error($id)) { return $id; }
    update_post_meta((int) $id, '_wpultra_service', wpultra_book_service_normalize($meta));
    return (int) $id;
}

/** All services, newest first. [{id, name, …meta}] */
function wpultra_book_service_list(bool $active_only = false): array {
    if (!function_exists('get_posts')) { return []; }
    $ids = get_posts([
        'post_type' => WPULTRA_BOOK_SERVICE_CPT, 'post_status' => 'publish',
        'numberposts' => 200, 'orderby' => 'date', 'order' => 'DESC',
        'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => true,
    ]);
    $out = [];
    foreach ((array) $ids as $id) {
        $s = wpultra_book_service_load((int) $id);
        if ($s === null) { continue; }
        if ($active_only && $s['active'] !== true) { continue; }
        $out[] = $s;
    }
    return $out;
}

/** Load a booking meta blob. Null when the id is not a live booking. */
function wpultra_book_load(int $id): ?array {
    if ($id <= 0 || !function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_BOOK_CPT || $post->post_status === 'trash') { return null; }
    $meta = get_post_meta($id, '_wpultra_booking', true);
    return is_array($meta) ? $meta : [];
}

/** Persist a booking meta blob + keep the "name — service @ when" title in sync. */
function wpultra_book_save(int $id, array $meta): void {
    if (!function_exists('update_post_meta')) { return; }
    update_post_meta($id, '_wpultra_booking', $meta);
    $service = wpultra_book_service_load((int) ($meta['service_id'] ?? 0));
    $c = is_array($meta['customer'] ?? null) ? $meta['customer'] : [];
    $title = wpultra_book_title(
        (string) ($c['name'] ?? ''),
        $service !== null ? $service['name'] : ('service #' . (int) ($meta['service_id'] ?? 0)),
        wpultra_book_fmt((int) ($meta['start_ts'] ?? 0))
    );
    $post = function_exists('get_post') ? get_post($id) : null;
    if ($post && $post->post_title !== $title && function_exists('wp_update_post')) {
        wp_update_post(['ID' => $id, 'post_title' => wp_slash($title)]);
    }
}

/** Insert a booking post from a meta blob. @return int|WP_Error */
function wpultra_book_insert(array $meta) {
    if (!function_exists('wp_insert_post')) { return wpultra_err('no_wp', 'WordPress not loaded.'); }
    $id = wp_insert_post([
        'post_type'   => WPULTRA_BOOK_CPT,
        'post_status' => 'publish',
        'post_title'  => 'booking',
    ], true);
    if (is_wp_error($id)) { return $id; }
    wpultra_book_save((int) $id, $meta);
    return (int) $id;
}

/**
 * Fetch newest-first bookings and apply the pure filter. Scans up to $scan
 * posts then filters in PHP (fields live inside the serialized blob).
 * Returns [{id, meta}, ...].
 */
function wpultra_book_query(array $filters = [], int $scan = 500): array {
    if (!function_exists('get_posts')) { return []; }
    $ids = get_posts([
        'post_type' => WPULTRA_BOOK_CPT, 'post_status' => 'publish',
        'numberposts' => max(1, $scan), 'orderby' => 'date', 'order' => 'DESC',
        'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => true,
    ]);
    $items = [];
    foreach ((array) $ids as $id) {
        $meta = wpultra_book_load((int) $id);
        if ($meta !== null) { $items[] = ['id' => (int) $id, 'meta' => $meta]; }
    }
    return wpultra_book_filter($items, $filters);
}

/**
 * Staff candidates for a service: the service's staff_ids that still exist
 * in the roster; when the service pins nobody, the whole roster.
 */
function wpultra_book_service_staff(array $service): array {
    $roster = wpultra_book_staff_all();
    $pinned = (array) ($service['staff_ids'] ?? []);
    if ($pinned === []) { return $roster; }
    $out = [];
    foreach ($pinned as $sid) {
        $s = wpultra_book_staff_find($roster, (int) $sid);
        if ($s !== null) { $out[] = $s; }
    }
    return $out;
}

/** 24/7 pseudo-staff used when no roster is configured (the business is the resource). */
function wpultra_book_pseudo_staff(): array {
    $hours = [];
    foreach (wpultra_book_day_keys() as $d) { $hours[$d] = [['00:00', '24:00']]; }
    return ['id' => 0, 'name' => 'Business', 'email' => '', 'hours' => $hours];
}

/**
 * Availability for a service on a date: per-staff free slots (30-min grid,
 * past slots already dropped). With an EMPTY roster one pseudo staff row
 * (staff_id 0, 24/7) is conflict-checked against ALL active bookings.
 * @return array|WP_Error {date, day, service_id, duration_min, buffer_min, staff:[{staff_id,name,slots[]}]}
 */
function wpultra_book_availability(int $service_id, string $date, int $staff_id = 0) {
    $service = wpultra_book_service_load($service_id);
    if ($service === null) { return wpultra_err('service_not_found', "No service with id $service_id."); }
    if ($service['active'] !== true) { return wpultra_err('service_inactive', "Service '{$service['name']}' is inactive."); }
    $tz = wpultra_book_tz();
    $bounds = wpultra_book_day_bounds($date, $tz);
    if ($bounds === null) { return wpultra_err('invalid_date', "date must be Y-m-d, got '$date'."); }
    [$day_start, $day_end] = $bounds;
    $day_key = wpultra_book_day_key($day_start, $tz);
    $now = time();

    // Bookings touching this day (small scan, filtered in PHP).
    $metas = array_column(wpultra_book_query(['from_ts' => $day_start - 86400, 'to_ts' => $day_end + 86400]), 'meta');

    $candidates = wpultra_book_service_staff($service);
    if ($staff_id > 0) {
        $one = wpultra_book_staff_find($candidates, $staff_id);
        if ($one === null) { return wpultra_err('staff_not_found', "Staff $staff_id is not available for this service."); }
        $candidates = [$one];
    }
    if ($candidates === []) { $candidates = [wpultra_book_pseudo_staff()]; }

    $rows = [];
    foreach ($candidates as $staff) {
        $sid = (int) ($staff['id'] ?? 0);
        $rows[] = [
            'staff_id' => $sid,
            'name'     => (string) ($staff['name'] ?? ''),
            'slots'    => wpultra_book_slots(
                wpultra_book_day_hours($staff, $day_key),
                (int) $service['duration_min'],
                (int) $service['buffer_min'],
                wpultra_book_busy_intervals($metas, $sid),
                $day_start,
                30,
                $now
            ),
        ];
    }
    return [
        'date'         => trim($date),
        'day'          => $day_key,
        'service_id'   => $service_id,
        'duration_min' => (int) $service['duration_min'],
        'buffer_min'   => (int) $service['buffer_min'],
        'staff'        => $rows,
    ];
}

/**
 * Create a booking (status pending) with an atomic slot re-check.
 * staff_id 0 auto-assigns the first candidate whose slot is free (or stays 0
 * when no roster exists — then only conflicts/past are checked).
 * $skip_slot_check bypasses hours/conflict validation (admin force-book).
 * Emails admin + assigned staff + customer. @return int|WP_Error
 */
function wpultra_book_create(int $service_id, int $staff_id, array $customer, int $start_ts, string $note = '', bool $skip_slot_check = false) {
    $service = wpultra_book_service_load($service_id);
    if ($service === null) { return wpultra_err('service_not_found', "No service with id $service_id."); }
    if ($service['active'] !== true && !$skip_slot_check) {
        return wpultra_err('service_inactive', "Service '{$service['name']}' is inactive.");
    }
    $cv = wpultra_book_customer_validate($customer);
    if ($cv !== true) { return wpultra_err('invalid_customer', (string) $cv); }
    if ($start_ts <= 0) { return wpultra_err('invalid_start', 'start must be a valid future date-time.'); }

    $tz = wpultra_book_tz();
    $now = time();
    $duration = (int) $service['duration_min'];
    $buffer = (int) $service['buffer_min'];
    $end_ts = $start_ts + $duration * 60;
    $day = (new DateTimeImmutable('@' . $start_ts))->setTimezone($tz)->format('Y-m-d');
    $bounds = wpultra_book_day_bounds($day, $tz);
    $day_start = $bounds !== null ? $bounds[0] : $start_ts;
    $day_key = wpultra_book_day_key($start_ts, $tz);

    $metas = array_column(wpultra_book_query(['from_ts' => $day_start - 86400, 'to_ts' => $day_start + 2 * 86400]), 'meta');
    $candidates = wpultra_book_service_staff($service);

    $chosen = max(0, $staff_id);
    if (!$skip_slot_check) {
        if ($candidates === []) {
            // No roster: business-level conflict check only (24/7 window).
            $ok = wpultra_book_can_book([['00:00', '24:00']], $duration, $buffer, wpultra_book_busy_intervals($metas, 0), $day_start, $start_ts, $now);
            if ($ok !== true) { return wpultra_err('slot_unavailable', "Slot not available: $ok."); }
            $chosen = 0;
        } elseif ($staff_id > 0) {
            $staff = wpultra_book_staff_find($candidates, $staff_id);
            if ($staff === null) { return wpultra_err('staff_not_found', "Staff $staff_id is not available for this service."); }
            $ok = wpultra_book_can_book(wpultra_book_day_hours($staff, $day_key), $duration, $buffer, wpultra_book_busy_intervals($metas, $staff_id), $day_start, $start_ts, $now);
            if ($ok !== true) { return wpultra_err('slot_unavailable', "Slot not available for {$staff['name']}: $ok."); }
        } else {
            $chosen = 0;
            $last = 'outside_hours';
            foreach ($candidates as $staff) {
                $sid = (int) ($staff['id'] ?? 0);
                if ($sid <= 0) { continue; }
                $ok = wpultra_book_can_book(wpultra_book_day_hours($staff, $day_key), $duration, $buffer, wpultra_book_busy_intervals($metas, $sid), $day_start, $start_ts, $now);
                if ($ok === true) { $chosen = $sid; break; }
                $last = (string) $ok;
            }
            if ($chosen === 0) { return wpultra_err('slot_unavailable', "No staff free at that time: $last."); }
        }
    }

    $meta = wpultra_book_new_meta($service_id, $chosen, $customer, $start_ts, $end_ts, $note, $now);
    $id = wpultra_book_insert($meta);
    if (is_wp_error($id)) { return $id; }
    wpultra_book_send_new_emails((int) $id, $meta, $service);
    return (int) $id;
}

/** Notify admin (+ assigned staff) and confirm receipt to the customer. Best-effort. */
function wpultra_book_send_new_emails(int $id, array $meta, array $service): void {
    if (!function_exists('wp_mail')) { return; }
    try {
        $c = is_array($meta['customer'] ?? null) ? $meta['customer'] : [];
        $when = wpultra_book_fmt((int) ($meta['start_ts'] ?? 0));
        $site = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'this site';
        $line = "Service: {$service['name']}\nWhen: $when\nName: " . (string) ($c['name'] ?? '')
            . "\nEmail: " . (string) ($c['email'] ?? '') . "\nPhone: " . (string) ($c['phone'] ?? '')
            . "\nNote: " . (string) ($meta['note'] ?? '') . "\nBooking ID: $id";
        $admin = function_exists('get_option') ? (string) get_option('admin_email') : '';
        if ($admin !== '') {
            wp_mail($admin, "[$site] New booking #$id — {$service['name']} @ $when", "A new booking request arrived.\n\n$line");
        }
        $staff = wpultra_book_staff_find(wpultra_book_staff_all(), (int) ($meta['staff_id'] ?? 0));
        $semail = $staff !== null ? (string) ($staff['email'] ?? '') : '';
        if ($semail !== '' && strtolower($semail) !== strtolower($admin)) {
            wp_mail($semail, "[$site] New booking #$id — {$service['name']} @ $when", "You have a new booking.\n\n$line");
        }
        $cemail = (string) ($c['email'] ?? '');
        if ($cemail !== '') {
            wp_mail($cemail, "[$site] Booking received — {$service['name']} @ $when",
                'Hi ' . (string) ($c['name'] ?? '') . ",\n\nWe received your booking request for {$service['name']} on $when. It is pending confirmation — we'll be in touch shortly.\n\nThanks,\n$site");
        }
    } catch (\Throwable $e) { /* email failures never break booking creation */ }
}

/** Email the customer when a booking flips to confirmed / cancelled. Best-effort. */
function wpultra_book_send_status_email(int $id, array $meta, string $status): void {
    if (!function_exists('wp_mail') || !in_array($status, ['confirmed', 'cancelled'], true)) { return; }
    try {
        $c = is_array($meta['customer'] ?? null) ? $meta['customer'] : [];
        $cemail = (string) ($c['email'] ?? '');
        if ($cemail === '') { return; }
        $service = wpultra_book_service_load((int) ($meta['service_id'] ?? 0));
        $sname = $service !== null ? $service['name'] : 'your appointment';
        $when = wpultra_book_fmt((int) ($meta['start_ts'] ?? 0));
        $site = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'this site';
        $body = $status === 'confirmed'
            ? 'Hi ' . (string) ($c['name'] ?? '') . ",\n\nYour booking for $sname on $when is CONFIRMED. See you then!\n\n$site"
            : 'Hi ' . (string) ($c['name'] ?? '') . ",\n\nYour booking for $sname on $when has been cancelled. Reply to this email if that's unexpected.\n\n$site";
        wp_mail($cemail, "[$site] Booking $status — $sname @ $when", $body);
    } catch (\Throwable $e) {}
}

/**
 * Daily reminder pass (the `wpultra_book_reminders` cron handler): email
 * customers whose appointment starts within the lead window (filter
 * wpultra_book_reminder_lead, default 24h), mark them reminded.
 * Returns {checked, sent}.
 */
function wpultra_book_reminders_run(): array {
    $now = time();
    $lead = (int) (function_exists('apply_filters') ? apply_filters('wpultra_book_reminder_lead', 86400) : 86400);
    if ($lead < 3600) { $lead = 86400; }
    $items = wpultra_book_query(['from_ts' => $now, 'to_ts' => $now + $lead + 60]);
    $sent = 0;
    foreach ($items as $it) {
        $meta = $it['meta'];
        if (!wpultra_book_reminder_due($meta, $now, $lead)) { continue; }
        try {
            $c = is_array($meta['customer'] ?? null) ? $meta['customer'] : [];
            $cemail = (string) ($c['email'] ?? '');
            if ($cemail !== '' && function_exists('wp_mail')) {
                $service = wpultra_book_service_load((int) ($meta['service_id'] ?? 0));
                $sname = $service !== null ? $service['name'] : 'your appointment';
                $when = wpultra_book_fmt((int) ($meta['start_ts'] ?? 0));
                $site = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'this site';
                wp_mail($cemail, "[$site] Reminder — $sname @ $when",
                    'Hi ' . (string) ($c['name'] ?? '') . ",\n\nA friendly reminder: your booking for $sname is coming up on $when.\n\nSee you soon,\n$site");
            }
            $meta['reminded'] = true;
            wpultra_book_save((int) $it['id'], $meta);
            $sent++;
        } catch (\Throwable $e) { /* keep processing the rest */ }
    }
    if ($sent > 0 && function_exists('wpultra_audit_log')) {
        wpultra_audit_log('booking-manage', "reminder cron sent=$sent", true);
    }
    return ['checked' => count($items), 'sent' => $sent];
}

/* ------------------------------------------------------------------ *
 * Front-end: [wpultra_booking] shortcode + POST handler.
 * ------------------------------------------------------------------ */

/** Render the booking form. Attrs: service="ID" (omit for a dropdown), staff="ID". */
function wpultra_book_shortcode($atts): string {
    try {
        $atts = is_array($atts) ? $atts : [];
        $service_id = (int) ($atts['service'] ?? 0);
        $staff_id = (int) ($atts['staff'] ?? 0);
        $services = wpultra_book_service_list(true);
        if ($services === []) {
            return '<div class="wpultra-booking">' . esc_html__('No bookable services are configured yet.', 'wp-ultra-mcp') . '</div>';
        }

        $msg = '';
        if (isset($_GET['wpultra_booked'])) {
            $msg = '<p class="wpultra-booking-ok" style="color:#1a7f37">' . esc_html__('Thanks! Your booking request was received — we will confirm it shortly.', 'wp-ultra-mcp') . '</p>';
        } elseif (isset($_GET['wpultra_book_err'])) {
            $codes = [
                'slot_taken' => __('Sorry, that time is not available. Please pick another slot.', 'wp-ultra-mcp'),
                'invalid'    => __('Please fill in your name, a valid email, a date and a time.', 'wp-ultra-mcp'),
                'past'       => __('That time is in the past — please pick an upcoming slot.', 'wp-ultra-mcp'),
            ];
            $code = (string) $_GET['wpultra_book_err'];
            $msg = '<p class="wpultra-booking-err" style="color:#b42318">' . esc_html($codes[$code] ?? $codes['invalid']) . '</p>';
        }

        if ($service_id > 0 && wpultra_book_service_load($service_id) !== null) {
            $svc_field = '<input type="hidden" name="service_id" value="' . esc_attr((string) $service_id) . '">';
        } else {
            $opts = '';
            foreach ($services as $s) {
                $label = $s['name'] . ' (' . $s['duration_min'] . ' min' . ($s['price'] > 0 ? ', ' . $s['price'] : '') . ')';
                $opts .= '<option value="' . esc_attr((string) $s['id']) . '">' . esc_html($label) . '</option>';
            }
            $svc_field = '<p><label>' . esc_html__('Service', 'wp-ultra-mcp') . '<br><select name="service_id" required>' . $opts . '</select></label></p>';
        }

        $staff_field = '<input type="hidden" name="staff_id" value="' . esc_attr((string) max(0, $staff_id)) . '">';
        if ($staff_id <= 0) {
            $roster = wpultra_book_staff_all();
            if (count($roster) > 1) {
                $opts = '<option value="0">' . esc_html__('Any available', 'wp-ultra-mcp') . '</option>';
                foreach ($roster as $st) {
                    $opts .= '<option value="' . esc_attr((string) (int) ($st['id'] ?? 0)) . '">' . esc_html((string) ($st['name'] ?? '')) . '</option>';
                }
                $staff_field = '<p><label>' . esc_html__('Staff', 'wp-ultra-mcp') . '<br><select name="staff_id">' . $opts . '</select></label></p>';
            }
        }

        $min_date = substr(wpultra_book_fmt(time()), 0, 10);
        $nonce = function_exists('wp_nonce_field') ? wp_nonce_field('wpultra_book', '_wpultra_book_nonce', true, false) : '';

        return '<div class="wpultra-booking">' . $msg
            . '<form method="post" action="">'
            . $nonce
            . '<input type="hidden" name="wpultra_book_action" value="book">'
            . '<span style="display:none!important" aria-hidden="true"><input type="text" name="wpultra_hp" value="" tabindex="-1" autocomplete="off"></span>'
            . $svc_field
            . $staff_field
            . '<p><label>' . esc_html__('Date', 'wp-ultra-mcp') . '<br><input type="date" name="book_date" min="' . esc_attr($min_date) . '" required></label></p>'
            . '<p><label>' . esc_html__('Time', 'wp-ultra-mcp') . '<br><input type="time" name="book_time" step="1800" required></label></p>'
            . '<p><label>' . esc_html__('Your name', 'wp-ultra-mcp') . '<br><input type="text" name="book_name" required maxlength="120"></label></p>'
            . '<p><label>' . esc_html__('Email', 'wp-ultra-mcp') . '<br><input type="email" name="book_email" required maxlength="200"></label></p>'
            . '<p><label>' . esc_html__('Phone', 'wp-ultra-mcp') . '<br><input type="tel" name="book_phone" maxlength="40"></label></p>'
            . '<p><label>' . esc_html__('Note', 'wp-ultra-mcp') . '<br><textarea name="book_note" rows="3" maxlength="1000"></textarea></label></p>'
            . '<p><button type="submit">' . esc_html__('Request booking', 'wp-ultra-mcp') . '</button></p>'
            . '</form></div>';
    } catch (\Throwable $e) {
        return '';
    }
}

/** template_redirect: handle the booking-form POST. Never throws to the page. */
function wpultra_book_handle_post(): void {
    try {
        if (($_POST['wpultra_book_action'] ?? '') !== 'book') { return; }
        if (function_exists('is_admin') && is_admin()) { return; }

        $back = function_exists('wp_get_referer') ? (string) wp_get_referer() : '';
        if ($back === '' && isset($_SERVER['REQUEST_URI'])) { $back = (string) $_SERVER['REQUEST_URI']; }
        $redirect = static function (array $args) use ($back): void {
            $url = function_exists('remove_query_arg') ? remove_query_arg(['wpultra_booked', 'wpultra_book_err'], $back) : $back;
            if (function_exists('add_query_arg')) { $url = add_query_arg($args, $url); }
            if (function_exists('wp_safe_redirect')) { wp_safe_redirect($url); exit; }
        };

        // Honeypot filled → pretend success, store nothing (bot).
        if (trim((string) ($_POST['wpultra_hp'] ?? '')) !== '') { $redirect(['wpultra_booked' => '1']); return; }
        $nonce = (string) ($_POST['_wpultra_book_nonce'] ?? '');
        if (!function_exists('wp_verify_nonce') || !wp_verify_nonce($nonce, 'wpultra_book')) {
            $redirect(['wpultra_book_err' => 'invalid']);
            return;
        }

        $unslash = static fn($v): string => trim((string) (function_exists('wp_unslash') ? wp_unslash($v) : $v));
        $service_id = (int) ($_POST['service_id'] ?? 0);
        $staff_id = max(0, (int) ($_POST['staff_id'] ?? 0));
        $date = $unslash($_POST['book_date'] ?? '');
        $time = $unslash($_POST['book_time'] ?? '');
        $customer = [
            'name'  => $unslash($_POST['book_name'] ?? ''),
            'email' => $unslash($_POST['book_email'] ?? ''),
            'phone' => $unslash($_POST['book_phone'] ?? ''),
        ];
        $note = $unslash($_POST['book_note'] ?? '');

        $start = wpultra_book_parse_start($time, $date, wpultra_book_tz());
        if ($service_id <= 0 || $start === null || wpultra_book_customer_validate($customer) !== true) {
            $redirect(['wpultra_book_err' => 'invalid']);
            return;
        }
        $res = wpultra_book_create($service_id, $staff_id, $customer, $start, $note);
        if (is_wp_error($res)) {
            $m = $res->get_error_message();
            $code = str_contains($m, 'past') ? 'past' : ($res->get_error_code() === 'slot_unavailable' ? 'slot_taken' : 'invalid');
            if (function_exists('wpultra_audit_log')) { wpultra_audit_log('booking-manage', 'front-end booking rejected: ' . $m, false); }
            $redirect(['wpultra_book_err' => $code]);
            return;
        }
        if (function_exists('wpultra_audit_log')) { wpultra_audit_log('booking-manage', "front-end booking #$res created (service $service_id)", true); }
        $redirect(['wpultra_booked' => '1']);
    } catch (\Throwable $e) { /* never break the page */ }
}

/* ------------------------------------------------------------------ *
 * Boot — the controller calls this on plugins_loaded. Cheap + idempotent.
 * ------------------------------------------------------------------ */

function wpultra_booking_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    if (!function_exists('add_action')) { return; }
    if (function_exists('wpultra_category_enabled') && !wpultra_category_enabled('verticals')) { return; }

    if (function_exists('did_action') && did_action('init')) {
        wpultra_book_register_cpts();
    } else {
        add_action('init', 'wpultra_book_register_cpts');
    }

    if (function_exists('add_shortcode')) {
        add_shortcode('wpultra_booking', 'wpultra_book_shortcode');
    }
    add_action('template_redirect', 'wpultra_book_handle_post', 4);

    add_action(WPULTRA_BOOK_CRON, 'wpultra_book_reminders_run');
    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
        if (!wp_next_scheduled(WPULTRA_BOOK_CRON)) {
            wp_schedule_event(time() + 300, 'daily', WPULTRA_BOOK_CRON);
        }
    }
}
