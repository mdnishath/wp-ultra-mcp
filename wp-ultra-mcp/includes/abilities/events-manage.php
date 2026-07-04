<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine so this ability works regardless of load order.
if (!function_exists('wpultra_event_upsert') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/verticals/events.php')) {
    require_once WPULTRA_DIR . 'includes/verticals/events.php';
}

wp_register_ability('wpultra/events-manage', [
    'label'       => __('Events & Ticketing', 'wp-ultra-mcp'),
    'description' => __(
        'Run events end-to-end: create/update events, define ticket types, take RSVPs and paid ticket bookings, enforce capacity, check attendees in, and view a calendar. '
        . 'ACTIONS: '
        . 'manage-event {event_id?, title, start (unix), end (unix), description?, location{name,address}?, rsvp_only?, status: draft|published|cancelled, ticket_types[]?} — upsert an event (start must be < end). '
        . 'list-events {} — every event with computed remaining seats. '
        . 'get-event {event_id}. '
        . 'manage-ticket-type {event_id, ticket_type{id?, name, price, capacity}} — add or replace a ticket type nested in the event (capacity 0 = UNLIMITED). '
        . 'register {event_id, ticket_type_id, attendee{name,email}, qty (default 1), confirm:true} — capacity is RE-CHECKED atomically, sold is incremented, a unique code is minted, a ticket record is stored, and a confirmation email is sent (best-effort). confirm:true is required to write. '
        . 'list-tickets {event_id, status?: reserved|confirmed|cancelled|checked_in}. '
        . 'check-in {ticket_code | ticket_id} — mark a ticket checked_in. '
        . 'cancel-ticket {ticket_code | ticket_id} — cancel a ticket and release its seats. '
        . 'calendar {from (unix), to (unix)} — events overlapping the window, grouped by start day. '
        . 'CAPACITY MODEL: each ticket type has capacity & sold; remaining = capacity - sold, and capacity 0 means unlimited. '
        . 'RSVP vs PAID: rsvp_only events and price-0 ticket types book a FREE reservation (status confirmed). A priced ticket books "reserved" and, when WooCommerce is active, best-effort creates a pending Woo order as a payment bridge; without WooCommerce a priced ticket still books (owner collects payment out-of-band). '
        . 'Add-to-calendar (.ics) and a print-ready ticket are produced by the engine.',
        'wp-ultra-mcp'
    ),
    'category'    => 'verticals',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'         => ['type' => 'string', 'enum' => ['manage-event', 'list-events', 'get-event', 'manage-ticket-type', 'register', 'list-tickets', 'check-in', 'cancel-ticket', 'calendar']],
            'event_id'       => ['type' => 'integer'],
            'title'          => ['type' => 'string'],
            'description'    => ['type' => 'string'],
            'start'          => ['type' => 'integer'],
            'end'            => ['type' => 'integer'],
            'location'       => [
                'type'       => 'object',
                'properties' => [
                    'name'    => ['type' => 'string'],
                    'address' => ['type' => 'string'],
                ],
            ],
            'rsvp_only'      => ['type' => 'boolean'],
            'status'         => ['type' => 'string', 'enum' => ['draft', 'published', 'cancelled']],
            'ticket_types'   => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'       => ['type' => 'string'],
                        'name'     => ['type' => 'string'],
                        'price'    => ['type' => 'number'],
                        'capacity' => ['type' => 'integer'],
                    ],
                ],
            ],
            'ticket_type'    => [
                'type'       => 'object',
                'properties' => [
                    'id'       => ['type' => 'string'],
                    'name'     => ['type' => 'string'],
                    'price'    => ['type' => 'number'],
                    'capacity' => ['type' => 'integer'],
                ],
            ],
            'ticket_type_id' => ['type' => 'string'],
            'attendee'       => [
                'type'       => 'object',
                'properties' => [
                    'name'  => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ],
            ],
            'qty'            => ['type' => 'integer', 'default' => 1],
            'ticket_id'      => ['type' => 'integer'],
            'ticket_code'    => ['type' => 'string'],
            'ticket_status'  => ['type' => 'string', 'enum' => ['reserved', 'confirmed', 'cancelled', 'checked_in']],
            'from'           => ['type' => 'integer'],
            'to'             => ['type' => 'integer'],
            'confirm'        => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'event'   => ['type' => 'object'],
            'events'  => ['type' => 'array'],
            'ticket'  => ['type' => 'object'],
            'tickets' => ['type' => 'array'],
            'calendar' => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_events_manage_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_events_manage_cb(array $input) {
    if (!function_exists('wpultra_event_upsert')) {
        return wpultra_err('events_engine_missing', 'The events engine (includes/verticals/events.php) is not loaded.');
    }
    if (function_exists('wpultra_event_boot')) { wpultra_event_boot(); }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'manage-event': {
            $res = wpultra_event_upsert($input);
            if (is_wp_error($res)) {
                wpultra_audit_log('events-manage', 'manage-event failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('events-manage', "manage-event id={$res['id']} status={$res['status']}", true);
            return wpultra_ok(['event' => wpultra_events_decorate_event($res)]);
        }

        case 'list-events': {
            $events = wpultra_event_all();
            $out = array_map('wpultra_events_decorate_event', $events);
            return wpultra_ok(['events' => $out, 'total' => count($out)]);
        }

        case 'get-event': {
            $event_id = (int) ($input['event_id'] ?? 0);
            if ($event_id <= 0) { return wpultra_err('missing_event_id', 'event_id is required.'); }
            $res = wpultra_event_get($event_id);
            if (is_wp_error($res)) { return $res; }
            return wpultra_ok(['event' => wpultra_events_decorate_event($res)]);
        }

        case 'manage-ticket-type': {
            $event_id = (int) ($input['event_id'] ?? 0);
            if ($event_id <= 0) { return wpultra_err('missing_event_id', 'event_id is required.'); }
            $tt = is_array($input['ticket_type'] ?? null) ? $input['ticket_type'] : [];
            if ($tt === []) { return wpultra_err('missing_ticket_type', 'ticket_type is required.'); }
            $res = wpultra_event_manage_ticket_type($event_id, $tt);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('events-manage', "manage-ticket-type event=$event_id", true);
            return wpultra_ok(['event' => wpultra_events_decorate_event($res)]);
        }

        case 'register': {
            $event_id = (int) ($input['event_id'] ?? 0);
            if ($event_id <= 0) { return wpultra_err('missing_event_id', 'event_id is required.'); }
            if (trim((string) ($input['ticket_type_id'] ?? '')) === '') {
                return wpultra_err('missing_ticket_type_id', 'ticket_type_id is required.');
            }
            // Booking writes (increments sold, mints a ticket, may create a Woo order) — gate on confirm.
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('unconfirmed', 'Booking a ticket is a write. Re-run with confirm:true.');
            }
            $res = wpultra_event_register([
                'event_id'       => $event_id,
                'ticket_type_id' => (string) $input['ticket_type_id'],
                'attendee'       => is_array($input['attendee'] ?? null) ? $input['attendee'] : [],
                'qty'            => (int) ($input['qty'] ?? 1),
            ]);
            if (is_wp_error($res)) {
                wpultra_audit_log('events-manage', 'register failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('events-manage', "register event=$event_id code={$res['code']} qty={$res['qty']}", true);
            return wpultra_ok(['ticket' => $res]);
        }

        case 'list-tickets': {
            $event_id = (int) ($input['event_id'] ?? 0);
            if ($event_id <= 0) { return wpultra_err('missing_event_id', 'event_id is required.'); }
            $status = (string) ($input['ticket_status'] ?? '');
            $res = wpultra_event_list_tickets($event_id, $status);
            if (is_wp_error($res)) { return $res; }
            return wpultra_ok(['tickets' => $res['tickets'], 'total' => $res['total'], 'event_id' => $event_id]);
        }

        case 'check-in': {
            $ticket_id = wpultra_events_resolve_ticket_id($input);
            if (is_wp_error($ticket_id)) { return $ticket_id; }
            $res = wpultra_event_check_in($ticket_id);
            if (is_wp_error($res)) {
                wpultra_audit_log('events-manage', 'check-in failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('events-manage', "check-in ticket=$ticket_id", true);
            return wpultra_ok(['ticket' => $res]);
        }

        case 'cancel-ticket': {
            $ticket_id = wpultra_events_resolve_ticket_id($input);
            if (is_wp_error($ticket_id)) { return $ticket_id; }
            $res = wpultra_event_cancel_ticket($ticket_id);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('events-manage', "cancel-ticket ticket=$ticket_id", true);
            return wpultra_ok(['ticket' => $res]);
        }

        case 'calendar': {
            $from = (int) ($input['from'] ?? 0);
            $to   = (int) ($input['to'] ?? 0);
            if ($from <= 0 || $to <= 0 || $from > $to) {
                return wpultra_err('bad_range', 'from and to must be positive unix timestamps with from <= to.');
            }
            $calendar = wpultra_event_calendar(wpultra_event_all(), $from, $to);
            return wpultra_ok(['calendar' => $calendar]);
        }
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}

/** Resolve ticket_id from either an explicit id or a ticket_code. */
function wpultra_events_resolve_ticket_id(array $input) {
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    if ($ticket_id > 0) { return $ticket_id; }
    $code = (string) ($input['ticket_code'] ?? '');
    if (trim($code) !== '' && function_exists('wpultra_event_ticket_id_by_code')) {
        $found = wpultra_event_ticket_id_by_code($code);
        if ($found > 0) { return $found; }
        return wpultra_err('ticket_not_found', "No ticket with code '$code'.");
    }
    return wpultra_err('missing_ticket', 'ticket_id or ticket_code is required.');
}

/** Decorate an event blob with computed remaining seats per ticket type + upcoming/past flags. */
function wpultra_events_decorate_event(array $ev): array {
    unset($ev['success']);
    $now = time();
    if (isset($ev['ticket_types']) && is_array($ev['ticket_types'])) {
        foreach ($ev['ticket_types'] as $k => $tt) {
            if (!is_array($tt)) { continue; }
            $remaining = wpultra_event_remaining($tt);
            $ev['ticket_types'][$k]['remaining'] = $remaining;
            $ev['ticket_types'][$k]['unlimited'] = ((int) ($tt['capacity'] ?? 0) <= 0);
        }
    }
    $ev['is_upcoming'] = wpultra_event_is_upcoming($ev, $now);
    $ev['is_past']     = wpultra_event_is_past($ev, $now);
    return $ev;
}
