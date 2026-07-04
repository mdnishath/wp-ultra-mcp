<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/*
 * Events + ticketing vertical (Roadmap E4).
 *
 * Two private CPTs model an event and its tickets:
 *   - wpultra_event  : title, post_content = description, meta {start,end,location,ticket_types[],rsvp_only,status}
 *   - wpultra_ticket : auto title, meta {event_id,ticket_type_id,attendee,qty,status,code,purchased}
 *
 * CAPACITY MODEL
 *   Each ticket_type carries {id, name, price, capacity, sold}. `capacity == 0` means
 *   UNLIMITED — wpultra_event_remaining() returns PHP_INT_MAX for it. Otherwise remaining
 *   is max(0, capacity - sold). Booking re-checks capacity, then increments `sold`.
 *
 * RSVP vs PAID
 *   - rsvp_only events (or free ticket_types, price == 0) create a free RESERVATION:
 *     a wpultra_ticket record with status "confirmed" and a generated code. No money.
 *   - When WooCommerce is active (wpultra_woo_active) AND the ticket_type has price > 0,
 *     a booking CAN create a best-effort Woo order as a payment bridge; the ticket is
 *     stored "reserved" until confirmed. If Woo is absent, a priced ticket still books as
 *     a reservation (the site owner collects payment out-of-band) — documented, not fatal.
 *
 * PURE core is prefixed wpultra_event_ and requires no WordPress runtime (harness-loadable).
 * WordPress wrappers come after and are guarded by function_exists / defined checks.
 */

// ---------------------------------------------------------------------------
// PURE core (unit-tested; no WordPress runtime required)
// ---------------------------------------------------------------------------

if (!defined('WPULTRA_EVENT_CPT')) { define('WPULTRA_EVENT_CPT', 'wpultra_event'); }
if (!defined('WPULTRA_TICKET_CPT')) { define('WPULTRA_TICKET_CPT', 'wpultra_ticket'); }

/** PURE. Allowed event statuses. */
function wpultra_event_statuses(): array {
    return ['draft', 'published', 'cancelled'];
}

/** PURE. Allowed ticket statuses. */
function wpultra_event_ticket_statuses(): array {
    return ['reserved', 'confirmed', 'cancelled', 'checked_in'];
}

/**
 * PURE. Remaining seats for a ticket type.
 * capacity == 0 (or missing/negative) is treated as UNLIMITED and returns PHP_INT_MAX.
 * Otherwise max(0, capacity - sold) — never negative even if sold overshoots.
 */
function wpultra_event_remaining(array $ticket_type): int {
    $capacity = (int) ($ticket_type['capacity'] ?? 0);
    if ($capacity <= 0) { return PHP_INT_MAX; }
    $sold = (int) ($ticket_type['sold'] ?? 0);
    $remaining = $capacity - $sold;
    return $remaining > 0 ? $remaining : 0;
}

/**
 * PURE. Can `qty` tickets be booked against this ticket type?
 * Returns {ok:bool, reason:'ok'|'bad_qty'|'sold_out'|'not_enough', remaining:int}.
 * qty < 1 => bad_qty; remaining 0 => sold_out; qty > remaining => not_enough.
 * For unlimited types remaining is reported as PHP_INT_MAX.
 */
function wpultra_event_can_book(array $ticket_type, int $qty): array {
    $remaining = wpultra_event_remaining($ticket_type);
    if ($qty < 1) {
        return ['ok' => false, 'reason' => 'bad_qty', 'remaining' => $remaining];
    }
    if ($remaining <= 0) {
        return ['ok' => false, 'reason' => 'sold_out', 'remaining' => 0];
    }
    if ($qty > $remaining) {
        return ['ok' => false, 'reason' => 'not_enough', 'remaining' => $remaining];
    }
    return ['ok' => true, 'reason' => 'ok', 'remaining' => $remaining];
}

/**
 * PURE. Build a unique-ish ticket code.
 * Format: EVT-{event_id}-{seq padded to 4}-{4 uppercase hex from $rand}.
 * $rand is a callable returning an int (e.g. fn() => random_int(0, 0xFFFF)); injecting
 * it keeps this deterministic under test. Example: EVT-12-0007-1A2B.
 */
function wpultra_event_ticket_code(int $event_id, int $seq, callable $rand): string {
    $suffix = strtoupper(str_pad(dechex(((int) $rand()) & 0xFFFF), 4, '0', STR_PAD_LEFT));
    return sprintf('EVT-%d-%04d-%s', max(0, $event_id), max(0, $seq), $suffix);
}

/** PURE. Validate a single ticket type. Returns true or a human-readable error string. */
function wpultra_event_validate_ticket_type(array $tt) {
    if (trim((string) ($tt['name'] ?? '')) === '') { return 'ticket type name is required.'; }
    $price = $tt['price'] ?? 0;
    if (!is_numeric($price) || (float) $price < 0) { return 'ticket type price must be a number >= 0.'; }
    $capacity = $tt['capacity'] ?? 0;
    if (!is_numeric($capacity) || (int) $capacity < 0) { return 'ticket type capacity must be an integer >= 0 (0 = unlimited).'; }
    $sold = $tt['sold'] ?? 0;
    if (!is_numeric($sold) || (int) $sold < 0) { return 'ticket type sold must be an integer >= 0.'; }
    return true;
}

/**
 * PURE. Validate a whole event array. Returns true or a human-readable error string.
 * Requires: title; start & end unix ints with start < end; status in the enum;
 * every ticket_type valid.
 */
function wpultra_event_validate(array $ev) {
    if (trim((string) ($ev['title'] ?? '')) === '') { return 'event title is required.'; }

    $start = $ev['start'] ?? null;
    $end   = $ev['end'] ?? null;
    if (!is_numeric($start) || (int) $start <= 0) { return 'start must be a positive unix timestamp.'; }
    if (!is_numeric($end) || (int) $end <= 0) { return 'end must be a positive unix timestamp.'; }
    if ((int) $start >= (int) $end) { return 'start must be before end.'; }

    $status = (string) ($ev['status'] ?? 'draft');
    if (!in_array($status, wpultra_event_statuses(), true)) {
        return "status '$status' is invalid (allowed: " . implode(', ', wpultra_event_statuses()) . ').';
    }

    $types = $ev['ticket_types'] ?? [];
    if (!is_array($types)) { return 'ticket_types must be an array.'; }
    foreach ($types as $i => $tt) {
        if (!is_array($tt)) { return "ticket_types[$i] must be an object."; }
        $v = wpultra_event_validate_ticket_type($tt);
        if ($v !== true) { return "ticket_types[$i]: $v"; }
    }
    return true;
}

/** PURE. True if the event begins strictly after $now. */
function wpultra_event_is_upcoming(array $ev, int $now): bool {
    $start = (int) ($ev['start'] ?? 0);
    return $start > $now;
}

/** PURE. True if the event has already ended at/before $now (end <= now). */
function wpultra_event_is_past(array $ev, int $now): bool {
    $end = (int) ($ev['end'] ?? 0);
    return $end > 0 && $end <= $now;
}

/**
 * PURE. Group events overlapping the window [$from, $to] by their start DATE (Y-m-d, UTC),
 * each day's events sorted by start ascending, days sorted ascending.
 * An event overlaps when start <= to AND end >= from. Returns:
 *   ['days' => [ 'Y-m-d' => [ {ev...}, ... ] ], 'count' => int, 'from' => $from, 'to' => $to].
 */
function wpultra_event_calendar(array $events, int $from, int $to): array {
    $matched = [];
    foreach ($events as $ev) {
        if (!is_array($ev)) { continue; }
        $start = (int) ($ev['start'] ?? 0);
        $end   = (int) ($ev['end'] ?? $start);
        if ($start <= 0) { continue; }
        // Overlap test against the window.
        if ($start <= $to && $end >= $from) { $matched[] = $ev; }
    }
    // Sort matched events by start (stable-ish; secondary by title for determinism).
    usort($matched, static function ($a, $b) {
        $sa = (int) ($a['start'] ?? 0);
        $sb = (int) ($b['start'] ?? 0);
        if ($sa !== $sb) { return $sa <=> $sb; }
        return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
    $days = [];
    foreach ($matched as $ev) {
        $key = gmdate('Y-m-d', (int) $ev['start']);
        $days[$key][] = $ev;
    }
    ksort($days);
    return ['days' => $days, 'count' => count($matched), 'from' => $from, 'to' => $to];
}

/** PURE. Escape a value for an iCalendar (RFC 5545) text property. */
function wpultra_event_ics_escape(string $s): string {
    // Order matters: backslash first, then the structural chars, then fold newlines.
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace([';', ','], ['\\;', '\\,'], $s);
    $s = str_replace(["\r\n", "\r", "\n"], '\\n', $s);
    return $s;
}

/**
 * PURE. Render a single event as a VCALENDAR/VEVENT .ics string ("add to calendar").
 * Times are emitted as UTC (…Z). Summary/description/location are escaped.
 */
function wpultra_event_ics(array $ev): string {
    $start = (int) ($ev['start'] ?? 0);
    $end   = (int) ($ev['end'] ?? $start);
    $uid   = (string) ($ev['uid'] ?? ('wpultra-' . ($ev['id'] ?? '0') . '@' . (string) ($ev['host'] ?? 'wp-ultra-mcp')));

    $summary = wpultra_event_ics_escape((string) ($ev['title'] ?? 'Event'));
    $descr   = wpultra_event_ics_escape((string) ($ev['description'] ?? ''));
    $loc     = '';
    if (isset($ev['location']) && is_array($ev['location'])) {
        $parts = array_filter([
            (string) ($ev['location']['name'] ?? ''),
            (string) ($ev['location']['address'] ?? ''),
        ], static fn($p) => $p !== '');
        $loc = wpultra_event_ics_escape(implode(', ', $parts));
    }

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//WP Ultra MCP//Events//EN',
        'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT',
        'UID:' . wpultra_event_ics_escape($uid),
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . gmdate('Ymd\THis\Z', $start),
        'DTEND:' . gmdate('Ymd\THis\Z', $end > 0 ? $end : $start),
        'SUMMARY:' . $summary,
    ];
    if ($descr !== '') { $lines[] = 'DESCRIPTION:' . $descr; }
    if ($loc !== '')   { $lines[] = 'LOCATION:' . $loc; }
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';
    // RFC 5545 uses CRLF line endings.
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * PURE. Render a print-ready HTML ticket. Everything is HTML-escaped. The ticket CODE is
 * printed as plain text — a scannable QR image is a client concern (a real deployment can
 * turn the code into a QR); we keep the raw code so any renderer can encode it. Documented.
 */
function wpultra_event_ticket_html(array $ticket, array $ev): string {
    $esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $code = (string) ($ticket['code'] ?? '');
    $attendee = is_array($ticket['attendee'] ?? null) ? $ticket['attendee'] : [];
    $name  = (string) ($attendee['name'] ?? '');
    $email = (string) ($attendee['email'] ?? '');
    $qty   = (int) ($ticket['qty'] ?? 1);
    $when  = (int) ($ev['start'] ?? 0);
    $whenStr = $when > 0 ? gmdate('Y-m-d H:i', $when) . ' UTC' : '';
    $loc = '';
    if (isset($ev['location']) && is_array($ev['location'])) {
        $loc = trim((string) ($ev['location']['name'] ?? '') . ' ' . (string) ($ev['location']['address'] ?? ''));
    }

    $rows = [];
    $rows[] = '<h2 class="wpultra-ticket__event">' . $esc($ev['title'] ?? 'Event') . '</h2>';
    if ($whenStr !== '') { $rows[] = '<p class="wpultra-ticket__when">' . $esc($whenStr) . '</p>'; }
    if ($loc !== '')     { $rows[] = '<p class="wpultra-ticket__loc">' . $esc($loc) . '</p>'; }
    if ($name !== '')    { $rows[] = '<p class="wpultra-ticket__attendee">' . $esc($name) . ($email !== '' ? ' &lt;' . $esc($email) . '&gt;' : '') . '</p>'; }
    $rows[] = '<p class="wpultra-ticket__qty">Qty: ' . $qty . '</p>';
    // Code as plain text; a QR would encode this exact string client-side.
    $rows[] = '<p class="wpultra-ticket__code" data-ticket-code="' . $esc($code) . '">Ticket code: <strong>' . $esc($code) . '</strong></p>';

    return '<div class="wpultra-ticket">' . "\n  " . implode("\n  ", $rows) . "\n" . '</div>';
}

// ---------------------------------------------------------------------------
// WordPress-facing wrappers (runtime only, guarded)
// ---------------------------------------------------------------------------

/** Register the two private CPTs. No-op without WordPress. */
function wpultra_event_register_cpts(): void {
    if (!function_exists('register_post_type')) { return; }
    register_post_type(WPULTRA_EVENT_CPT, [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title', 'editor'], 'rewrite' => false,
    ]);
    register_post_type(WPULTRA_TICKET_CPT, [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title'], 'rewrite' => false,
    ]);
}

/**
 * Runtime boot: register the CPTs on init. Cheap; idempotent — safe on every request.
 * Called by the controller and defensively by the ability.
 */
function wpultra_event_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    if (function_exists('did_action') && did_action('init')) { wpultra_event_register_cpts(); }
    elseif (function_exists('add_action')) { add_action('init', 'wpultra_event_register_cpts'); }
}

/** Read one event's normalized data blob (or WP_Error). */
function wpultra_event_get(int $event_id) {
    if (!function_exists('get_post')) { return wpultra_err('no_wp', 'WordPress runtime required.'); }
    $post = get_post($event_id);
    if (!$post || $post->post_type !== WPULTRA_EVENT_CPT) {
        return wpultra_err('event_not_found', "No event with id $event_id.");
    }
    $meta = [
        'start'        => (int) get_post_meta($event_id, '_wpultra_event_start', true),
        'end'          => (int) get_post_meta($event_id, '_wpultra_event_end', true),
        'location'     => (array) (get_post_meta($event_id, '_wpultra_event_location', true) ?: []),
        'ticket_types' => (array) (get_post_meta($event_id, '_wpultra_event_ticket_types', true) ?: []),
        'rsvp_only'    => (bool) get_post_meta($event_id, '_wpultra_event_rsvp_only', true),
        'status'       => (string) (get_post_meta($event_id, '_wpultra_event_status', true) ?: 'draft'),
    ];
    return wpultra_ok(array_merge([
        'id'          => $event_id,
        'title'       => (string) $post->post_title,
        'description' => (string) $post->post_content,
    ], $meta));
}

/**
 * Upsert an event. $in: {event_id?, title, description?, start, end, location?, rsvp_only?,
 * status?, ticket_types?}. Validates via the pure validator. Returns the stored event blob.
 */
function wpultra_event_upsert(array $in) {
    if (!function_exists('wp_insert_post')) { return wpultra_err('no_wp', 'WordPress runtime required.'); }

    $event_id = (int) ($in['event_id'] ?? 0);

    // Merge onto existing meta so a partial update doesn't wipe untouched fields.
    $existing = [];
    if ($event_id > 0) {
        $got = wpultra_event_get($event_id);
        if (is_wp_error($got)) { return $got; }
        $existing = $got;
    }

    $ev = [
        'title'        => (string) ($in['title'] ?? ($existing['title'] ?? '')),
        'description'  => (string) ($in['description'] ?? ($existing['description'] ?? '')),
        'start'        => (int) ($in['start'] ?? ($existing['start'] ?? 0)),
        'end'          => (int) ($in['end'] ?? ($existing['end'] ?? 0)),
        'location'     => is_array($in['location'] ?? null) ? $in['location'] : ($existing['location'] ?? []),
        'rsvp_only'    => array_key_exists('rsvp_only', $in) ? (bool) $in['rsvp_only'] : (bool) ($existing['rsvp_only'] ?? false),
        'status'       => (string) ($in['status'] ?? ($existing['status'] ?? 'draft')),
        'ticket_types' => wpultra_event_normalize_ticket_types(
            is_array($in['ticket_types'] ?? null) ? $in['ticket_types'] : ($existing['ticket_types'] ?? [])
        ),
    ];

    $valid = wpultra_event_validate($ev);
    if ($valid !== true) { return wpultra_err('invalid_event', (string) $valid); }

    $postarr = [
        'post_type'    => WPULTRA_EVENT_CPT,
        'post_status'  => 'private',
        'post_title'   => $ev['title'],
        'post_content' => wp_slash($ev['description']),
    ];
    if ($event_id > 0) { $postarr['ID'] = $event_id; }
    $id = wp_insert_post($postarr, true);
    if (is_wp_error($id)) { return $id; }
    $id = (int) $id;

    update_post_meta($id, '_wpultra_event_start', $ev['start']);
    update_post_meta($id, '_wpultra_event_end', $ev['end']);
    update_post_meta($id, '_wpultra_event_location', $ev['location']);
    update_post_meta($id, '_wpultra_event_ticket_types', $ev['ticket_types']);
    update_post_meta($id, '_wpultra_event_rsvp_only', $ev['rsvp_only'] ? 1 : 0);
    update_post_meta($id, '_wpultra_event_status', $ev['status']);

    return wpultra_event_get($id);
}

/** PURE-ish. Normalize/assign ids + defaults to a list of ticket types. */
function wpultra_event_normalize_ticket_types(array $types): array {
    $out = [];
    $seq = 0;
    foreach ($types as $tt) {
        if (!is_array($tt)) { continue; }
        $seq++;
        $id = (string) ($tt['id'] ?? '');
        if ($id === '') { $id = 'tt' . $seq; }
        $out[] = [
            'id'       => $id,
            'name'     => (string) ($tt['name'] ?? ''),
            'price'    => round((float) ($tt['price'] ?? 0), 2),
            'capacity' => max(0, (int) ($tt['capacity'] ?? 0)),
            'sold'     => max(0, (int) ($tt['sold'] ?? 0)),
        ];
    }
    return $out;
}

/** Add or replace a ticket type on an event (nested). $tt without id => new. */
function wpultra_event_manage_ticket_type(int $event_id, array $tt) {
    $got = wpultra_event_get($event_id);
    if (is_wp_error($got)) { return $got; }
    $v = wpultra_event_validate_ticket_type($tt);
    if ($v !== true) { return wpultra_err('invalid_ticket_type', (string) $v); }

    $types = $got['ticket_types'];
    $id = (string) ($tt['id'] ?? '');
    $replaced = false;
    if ($id !== '') {
        foreach ($types as $k => $existing) {
            if ((string) ($existing['id'] ?? '') === $id) {
                // Preserve sold count unless explicitly overridden.
                $tt['sold'] = array_key_exists('sold', $tt) ? $tt['sold'] : ($existing['sold'] ?? 0);
                $types[$k] = $tt;
                $replaced = true;
                break;
            }
        }
    }
    if (!$replaced) { $types[] = $tt; }

    return wpultra_event_upsert(['event_id' => $event_id, 'ticket_types' => $types]);
}

/**
 * Book/register a ticket: atomic capacity re-check → increment sold → generate code → store
 * a wpultra_ticket → (best-effort) email confirmation. $args: {event_id, ticket_type_id,
 * attendee{name,email}, qty}. Returns the stored ticket blob.
 */
function wpultra_event_register(array $args) {
    if (!function_exists('wp_insert_post')) { return wpultra_err('no_wp', 'WordPress runtime required.'); }

    $event_id = (int) ($args['event_id'] ?? 0);
    $tt_id    = (string) ($args['ticket_type_id'] ?? '');
    $qty      = (int) ($args['qty'] ?? 1);
    $attendee = is_array($args['attendee'] ?? null) ? $args['attendee'] : [];

    $got = wpultra_event_get($event_id);
    if (is_wp_error($got)) { return $got; }
    if (($got['status'] ?? '') === 'cancelled') {
        return wpultra_err('event_cancelled', 'This event is cancelled; booking is closed.');
    }
    if (trim((string) ($attendee['name'] ?? '')) === '') {
        return wpultra_err('missing_attendee', 'attendee.name is required.');
    }

    // Locate the ticket type.
    $types = $got['ticket_types'];
    $idx = null;
    foreach ($types as $k => $tt) {
        if ((string) ($tt['id'] ?? '') === $tt_id) { $idx = $k; break; }
    }
    if ($idx === null) { return wpultra_err('ticket_type_not_found', "No ticket type '$tt_id' on event $event_id."); }

    // Atomic-ish re-check against the freshly-read sold count.
    $check = wpultra_event_can_book($types[$idx], $qty);
    if (!$check['ok']) {
        return wpultra_err('cannot_book', "Cannot book: {$check['reason']} (remaining {$check['remaining']}).", $check);
    }

    // Increment sold and persist BEFORE minting the ticket, to close the race window.
    $types[$idx]['sold'] = (int) ($types[$idx]['sold'] ?? 0) + $qty;
    update_post_meta($event_id, '_wpultra_event_ticket_types', $types);

    $price     = (float) ($types[$idx]['price'] ?? 0);
    $rsvp_only = (bool) ($got['rsvp_only'] ?? false);
    $is_paid   = ($price > 0 && !$rsvp_only);

    // A paid ticket with Woo active starts "reserved" (pending payment); everything else is
    // "confirmed" immediately (free RSVP or out-of-band payment).
    $woo_bridge = ($is_paid && function_exists('wpultra_woo_active') && wpultra_woo_active());
    $status = $woo_bridge ? 'reserved' : 'confirmed';

    // Sequence for the code = number of existing tickets for this event + 1.
    $seq = wpultra_event_ticket_count($event_id) + 1;
    $code = wpultra_event_ticket_code($event_id, $seq, static fn() => random_int(0, 0xFFFF));

    $ticket_id = wp_insert_post([
        'post_type'   => WPULTRA_TICKET_CPT,
        'post_status' => 'private',
        'post_title'  => 'ticket:' . $code,
    ], true);
    if (is_wp_error($ticket_id)) {
        // Roll back the sold increment on failure.
        $types[$idx]['sold'] = max(0, (int) $types[$idx]['sold'] - $qty);
        update_post_meta($event_id, '_wpultra_event_ticket_types', $types);
        return $ticket_id;
    }
    $ticket_id = (int) $ticket_id;

    update_post_meta($ticket_id, '_wpultra_ticket_event', $event_id);
    update_post_meta($ticket_id, '_wpultra_ticket_type', $tt_id);
    update_post_meta($ticket_id, '_wpultra_ticket_attendee', $attendee);
    update_post_meta($ticket_id, '_wpultra_ticket_qty', $qty);
    update_post_meta($ticket_id, '_wpultra_ticket_status', $status);
    update_post_meta($ticket_id, '_wpultra_ticket_code', $code);
    update_post_meta($ticket_id, '_wpultra_ticket_purchased', time());

    // Best-effort Woo order bridge (guarded, non-fatal).
    $order_id = 0;
    if ($woo_bridge) {
        $order_id = wpultra_event_maybe_create_woo_order($event_id, $types[$idx], $qty, $attendee, $ticket_id);
        if ($order_id > 0) { update_post_meta($ticket_id, '_wpultra_ticket_order', $order_id); }
    }

    // Best-effort confirmation email.
    $emailed = false;
    $email = (string) ($attendee['email'] ?? '');
    if ($email !== '' && function_exists('wp_mail') && function_exists('is_email') && is_email($email)) {
        $subject = sprintf('Your ticket for %s', (string) $got['title']);
        $body = "You're registered for \"{$got['title']}\".\n\nTicket code: $code\nQuantity: $qty\nStatus: $status\n";
        $emailed = (bool) wp_mail($email, $subject, $body);
    }

    return wpultra_ok([
        'ticket_id'      => $ticket_id,
        'event_id'       => $event_id,
        'ticket_type_id' => $tt_id,
        'attendee'       => $attendee,
        'qty'            => $qty,
        'status'         => $status,
        'code'           => $code,
        'paid'           => $is_paid,
        'order_id'       => $order_id,
        'emailed'        => $emailed,
    ]);
}

/** Count wpultra_ticket posts for an event. */
function wpultra_event_ticket_count(int $event_id): int {
    if (!function_exists('get_posts')) { return 0; }
    $ids = get_posts([
        'post_type'   => WPULTRA_TICKET_CPT,
        'post_status' => 'private',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_key'    => '_wpultra_ticket_event',
        'meta_value'  => $event_id,
    ]);
    return is_array($ids) ? count($ids) : 0;
}

/** Best-effort WooCommerce order for a paid ticket. Returns order id, or 0 on any failure. */
function wpultra_event_maybe_create_woo_order(int $event_id, array $ticket_type, int $qty, array $attendee, int $ticket_id): int {
    if (!function_exists('wc_create_order')) { return 0; }
    try {
        $order = wc_create_order();
        if (is_wp_error($order) || !is_object($order)) { return 0; }
        $line = (float) ($ticket_type['price'] ?? 0) * $qty;
        if (method_exists($order, 'add_fee')) {
            // Simplest bridge: add the ticket as a fee line so no WC product is required.
            if (class_exists('WC_Order_Item_Fee')) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name(sprintf('Ticket: %s (x%d)', (string) ($ticket_type['name'] ?? 'Ticket'), $qty));
                $fee->set_amount((string) $line);
                $fee->set_total((string) $line);
                $order->add_item($fee);
            }
        }
        if (method_exists($order, 'set_billing_email') && !empty($attendee['email'])) {
            $order->set_billing_email((string) $attendee['email']);
        }
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data('_wpultra_event_id', $event_id);
            $order->update_meta_data('_wpultra_ticket_id', $ticket_id);
        }
        if (method_exists($order, 'calculate_totals')) { $order->calculate_totals(); }
        if (method_exists($order, 'set_status')) { $order->set_status('pending'); }
        $order->save();
        return (int) $order->get_id();
    } catch (\Throwable $e) {
        return 0;
    }
}

/** Read one ticket's normalized blob (or WP_Error). */
function wpultra_event_get_ticket(int $ticket_id) {
    if (!function_exists('get_post')) { return wpultra_err('no_wp', 'WordPress runtime required.'); }
    $post = get_post($ticket_id);
    if (!$post || $post->post_type !== WPULTRA_TICKET_CPT) {
        return wpultra_err('ticket_not_found', "No ticket with id $ticket_id.");
    }
    return wpultra_ok([
        'ticket_id'      => $ticket_id,
        'event_id'       => (int) get_post_meta($ticket_id, '_wpultra_ticket_event', true),
        'ticket_type_id' => (string) get_post_meta($ticket_id, '_wpultra_ticket_type', true),
        'attendee'       => (array) (get_post_meta($ticket_id, '_wpultra_ticket_attendee', true) ?: []),
        'qty'            => (int) get_post_meta($ticket_id, '_wpultra_ticket_qty', true),
        'status'         => (string) (get_post_meta($ticket_id, '_wpultra_ticket_status', true) ?: 'reserved'),
        'code'           => (string) get_post_meta($ticket_id, '_wpultra_ticket_code', true),
        'purchased'      => (int) get_post_meta($ticket_id, '_wpultra_ticket_purchased', true),
    ]);
}

/** Find a ticket id by its unique code (0 if none). */
function wpultra_event_ticket_id_by_code(string $code): int {
    if (!function_exists('get_posts') || trim($code) === '') { return 0; }
    $ids = get_posts([
        'post_type'   => WPULTRA_TICKET_CPT,
        'post_status' => 'private',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => '_wpultra_ticket_code',
        'meta_value'  => $code,
    ]);
    return (is_array($ids) && $ids) ? (int) $ids[0] : 0;
}

/** Check a ticket in (status -> checked_in). By id or code. */
function wpultra_event_check_in(int $ticket_id) {
    $got = wpultra_event_get_ticket($ticket_id);
    if (is_wp_error($got)) { return $got; }
    if ($got['status'] === 'cancelled') {
        return wpultra_err('ticket_cancelled', 'This ticket was cancelled and cannot be checked in.');
    }
    if ($got['status'] === 'checked_in') {
        return wpultra_err('already_checked_in', 'This ticket is already checked in.', $got);
    }
    update_post_meta($ticket_id, '_wpultra_ticket_status', 'checked_in');
    $got['status'] = 'checked_in';
    return wpultra_ok($got);
}

/** Cancel a ticket (status -> cancelled) and release its seats back to the ticket type. */
function wpultra_event_cancel_ticket(int $ticket_id) {
    $got = wpultra_event_get_ticket($ticket_id);
    if (is_wp_error($got)) { return $got; }
    if ($got['status'] === 'cancelled') { return wpultra_ok($got); }

    update_post_meta($ticket_id, '_wpultra_ticket_status', 'cancelled');

    // Release seats back to the event's ticket type sold count.
    $event = wpultra_event_get((int) $got['event_id']);
    if (!is_wp_error($event)) {
        $types = $event['ticket_types'];
        foreach ($types as $k => $tt) {
            if ((string) ($tt['id'] ?? '') === $got['ticket_type_id']) {
                $types[$k]['sold'] = max(0, (int) ($tt['sold'] ?? 0) - (int) $got['qty']);
                update_post_meta((int) $got['event_id'], '_wpultra_event_ticket_types', $types);
                break;
            }
        }
    }
    $got['status'] = 'cancelled';
    return wpultra_ok($got);
}

/** List tickets for an event, optionally filtered by status. */
function wpultra_event_list_tickets(int $event_id, string $status = '') {
    if (!function_exists('get_posts')) { return wpultra_err('no_wp', 'WordPress runtime required.'); }
    $ids = get_posts([
        'post_type'   => WPULTRA_TICKET_CPT,
        'post_status' => 'private',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_key'    => '_wpultra_ticket_event',
        'meta_value'  => $event_id,
    ]);
    $tickets = [];
    foreach ((array) $ids as $tid) {
        $t = wpultra_event_get_ticket((int) $tid);
        if (is_wp_error($t)) { continue; }
        if ($status !== '' && $t['status'] !== $status) { continue; }
        unset($t['success']);
        $tickets[] = $t;
    }
    return wpultra_ok(['event_id' => $event_id, 'total' => count($tickets), 'tickets' => $tickets]);
}

/** List every event's blob (for the pure calendar builder). */
function wpultra_event_all(): array {
    if (!function_exists('get_posts')) { return []; }
    $ids = get_posts([
        'post_type'   => WPULTRA_EVENT_CPT,
        'post_status' => 'private',
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);
    $out = [];
    foreach ((array) $ids as $eid) {
        $ev = wpultra_event_get((int) $eid);
        if (!is_wp_error($ev)) { unset($ev['success']); $out[] = $ev; }
    }
    return $out;
}
