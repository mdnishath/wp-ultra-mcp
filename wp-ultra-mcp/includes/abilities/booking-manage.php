<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/verticals/booking.php — require it defensively
// so this ability works regardless of bootstrap load order (mirrors woo-bulk-edit).
if (!function_exists('wpultra_book_slots') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/verticals/booking.php')) {
    require_once WPULTRA_DIR . 'includes/verticals/booking.php';
}

wp_register_ability('wpultra/booking-manage', [
    'label'       => __('Booking: Services, Staff, Availability & Appointments', 'wp-ultra-mcp'),
    'description' => __(
        'A lightweight appointment / booking vertical: define SERVICES (what can be booked), a STAFF roster (who does it, with weekly hours), compute free SLOTS, take BOOKINGS without double-booking, and auto-remind customers by email. '
        . 'MODEL: services are a private CPT (name, duration_min default 60, price — informational + currency-agnostic, buffer_min default 0, staff_ids[] to pin who offers it — empty = whole roster, active flag); the staff roster lives in the option wpultra_booking_staff as [{id, name, email, hours}] where hours = {mon..sun: [["09:00","17:00"], ...]} (list of ["HH:MM","HH:MM"] windows per day; [] or a missing day = day off; multiple windows model a lunch break; new staff without hours get Mon-Fri 9-5). Bookings are a private CPT with {service_id, staff_id, customer{name,email,phone}, start_ts, end_ts, status pending|confirmed|cancelled|completed, note, reminded}. '
        . 'SLOT ENGINE: candidate starts every 30 min inside the staff member\'s working windows for that weekday; a slot is offered only when the full duration fits inside a window AND, with buffer_min clearance on BOTH sides, it overlaps no pending/confirmed booking; past slots are dropped. Cancelled/completed bookings never block. With an EMPTY roster the business itself is the resource (staff_id 0, 24/7 windows, conflict-checked against all bookings). All *_ts values are unix seconds; dates ("Y-m-d") and "Y-m-d H:i" strings resolve in the SITE timezone. '
        . 'ACTIONS: '
        . 'service-add {name (required), duration_min?, price?, buffer_min?, staff_ids?, active?} · '
        . 'service-update {service_id, ...same fields, any subset} · '
        . 'service-list (all services) · service-delete {service_id} (moves to trash). '
        . 'staff-set {staff: {id?, name, email?, hours?}} — upsert into the roster (no id = create with next id; existing id = merge). staff-list · staff-delete {staff_id}. '
        . 'availability {service_id, date "Y-m-d", staff_id? (0/omit = every candidate)} — per-staff free slots [{start_ts, end_ts, time "HH:MM"}]. '
        . 'book {service_id, start ("Y-m-d H:i" | unix ts | "HH:MM" with date), date?, staff_id? (0 = auto-assign first free candidate), customer {name, email, phone?}, note?, skip_slot_check? (true = admin force-book, bypasses hours/conflict checks)} — creates a PENDING booking after an atomic slot re-check, emails admin + assigned staff + a "request received" mail to the customer. '
        . 'booking-list {filters: {status?, staff_id?, service_id?, from? "Y-m-d", to? "Y-m-d", search? (customer name/email), limit? default 50}} · booking-get {booking_id} · '
        . 'booking-status {booking_id, status} — transition-validated (pending→confirmed|cancelled, confirmed→completed|cancelled; completed/cancelled terminal); flipping to confirmed/cancelled emails the customer · '
        . 'booking-delete {booking_id} (trash). '
        . 'FRONT-END: the [wpultra_booking] shortcode (attrs service="ID", staff="ID") renders a booking form (nonce + honeypot protected); submissions become pending bookings via the same slot check. REMINDERS: a daily cron (wpultra_book_reminders) emails customers whose pending/confirmed booking starts within 24h (filter wpultra_book_reminder_lead) and marks them reminded. '
        . 'Examples: {action:"service-add", name:"Haircut", duration_min:45, buffer_min:15, price:30} · '
        . '{action:"staff-set", staff:{name:"Ana", email:"ana@salon.test", hours:{mon:[["09:00","17:00"]], tue:[["09:00","12:00"],["13:00","17:00"]]}}} · '
        . '{action:"availability", service_id:12, date:"2026-07-10"} · '
        . '{action:"book", service_id:12, staff_id:1, start:"2026-07-10 10:00", customer:{name:"Sam", email:"sam@x.test"}} · '
        . '{action:"booking-status", booking_id:99, status:"confirmed"}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'verticals',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['service-add', 'service-update', 'service-list', 'service-delete', 'staff-set', 'staff-list', 'staff-delete', 'availability', 'book', 'booking-list', 'booking-get', 'booking-status', 'booking-delete'],
            ],
            'service_id'   => ['type' => 'integer'],
            'staff_id'     => ['type' => 'integer'],
            'booking_id'   => ['type' => 'integer'],
            'name'         => ['type' => 'string'],
            'duration_min' => ['type' => 'integer'],
            'price'        => ['type' => 'number'],
            'buffer_min'   => ['type' => 'integer'],
            'staff_ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
            'active'       => ['type' => 'boolean'],
            'staff'        => [
                'type'       => ['array', 'object'],
                'properties' => [
                    'id'    => ['type' => 'integer'],
                    'name'  => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'hours' => ['type' => ['array', 'object']],
                ],
            ],
            'date'     => ['type' => 'string'],
            'start'    => ['type' => ['string', 'integer']],
            'customer' => [
                'type'       => ['array', 'object'],
                'properties' => [
                    'name'  => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                ],
            ],
            'note'            => ['type' => 'string'],
            'status'          => ['type' => 'string', 'enum' => ['pending', 'confirmed', 'cancelled', 'completed']],
            'skip_slot_check' => ['type' => 'boolean'],
            'filters'         => [
                'type'       => ['array', 'object'],
                'properties' => [
                    'status'     => ['type' => 'string'],
                    'staff_id'   => ['type' => 'integer'],
                    'service_id' => ['type' => 'integer'],
                    'from'       => ['type' => 'string'],
                    'to'         => ['type' => 'string'],
                    'search'     => ['type' => 'string'],
                    'limit'      => ['type' => 'integer'],
                ],
            ],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'service'      => ['type' => 'object'],
            'services'     => ['type' => 'array', 'items' => ['type' => 'object']],
            'staff'        => ['type' => 'array', 'items' => ['type' => 'object']],
            'staff_id'     => ['type' => 'integer'],
            'availability' => ['type' => 'object'],
            'booking'      => ['type' => 'object'],
            'bookings'     => ['type' => 'array', 'items' => ['type' => 'object']],
            'count'        => ['type' => 'integer'],
            'deleted'      => ['type' => 'boolean'],
            'message'      => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_booking_manage_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_booking_manage_cb(array $input) {
    if (!function_exists('wpultra_book_slots')) {
        return wpultra_err('booking_engine_missing', 'The booking engine (includes/verticals/booking.php) is not loaded.');
    }
    $action = (string) ($input['action'] ?? '');
    switch ($action) {
        case 'service-add':      return wpultra_booking_act_service_add($input);
        case 'service-update':   return wpultra_booking_act_service_update($input);
        case 'service-list':
            $services = wpultra_book_service_list(false);
            return wpultra_ok(['services' => $services, 'count' => count($services)]);
        case 'service-delete':   return wpultra_booking_act_service_delete($input);
        case 'staff-set':        return wpultra_booking_act_staff_set($input);
        case 'staff-list':
            $roster = wpultra_book_staff_all();
            return wpultra_ok(['staff' => $roster, 'count' => count($roster)]);
        case 'staff-delete':     return wpultra_booking_act_staff_delete($input);
        case 'availability':     return wpultra_booking_act_availability($input);
        case 'book':             return wpultra_booking_act_book($input);
        case 'booking-list':     return wpultra_booking_act_list($input);
        case 'booking-get':      return wpultra_booking_act_get($input);
        case 'booking-status':   return wpultra_booking_act_status($input);
        case 'booking-delete':   return wpultra_booking_act_delete($input);
        default:
            return wpultra_err('unknown_action', "Unknown action '$action'. Known: service-add, service-update, service-list, service-delete, staff-set, staff-list, staff-delete, availability, book, booking-list, booking-get, booking-status, booking-delete.");
    }
}

/** Shape a booking for output (adds site-timezone human times). */
function wpultra_booking_out(array $meta, int $id): array {
    $b = wpultra_book_shape($meta, $id);
    $b['start'] = wpultra_book_fmt($b['start_ts']);
    $b['end'] = wpultra_book_fmt($b['end_ts']);
    return $b;
}

/** @return array|WP_Error */
function wpultra_booking_act_service_add(array $input) {
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') { return wpultra_err('missing_name', 'name is required to create a service.'); }
    $id = wpultra_book_service_insert($name, $input);
    if (is_wp_error($id)) { return $id; }
    wpultra_audit_log('booking-manage', "service-add #$id '$name'", true);
    return wpultra_ok(['service' => wpultra_book_service_load((int) $id)]);
}

/** @return array|WP_Error */
function wpultra_booking_act_service_update(array $input) {
    $id = (int) ($input['service_id'] ?? 0);
    $svc = wpultra_book_service_load($id);
    if ($svc === null) { return wpultra_err('service_not_found', "No service with id $id."); }

    // Merge: provided fields overwrite the stored blob, missing fields kept.
    $meta = $svc;
    foreach (['duration_min', 'price', 'buffer_min', 'staff_ids', 'active'] as $k) {
        if (array_key_exists($k, $input)) { $meta[$k] = $input[$k]; }
    }
    update_post_meta($id, '_wpultra_service', wpultra_book_service_normalize($meta));
    $name = trim((string) ($input['name'] ?? ''));
    if ($name !== '') {
        wp_update_post(['ID' => $id, 'post_title' => wp_slash(wpultra_book_trim_len($name, 120))]);
    }
    wpultra_audit_log('booking-manage', "service-update #$id", true);
    return wpultra_ok(['service' => wpultra_book_service_load($id)]);
}

/** @return array|WP_Error */
function wpultra_booking_act_service_delete(array $input) {
    $id = (int) ($input['service_id'] ?? 0);
    if (wpultra_book_service_load($id) === null) { return wpultra_err('service_not_found', "No service with id $id."); }
    $res = function_exists('wp_trash_post') ? wp_trash_post($id) : wp_delete_post($id, false);
    if (!$res) { return wpultra_err('delete_failed', "Could not delete service $id."); }
    wpultra_audit_log('booking-manage', "service-delete #$id", true);
    return wpultra_ok(['deleted' => true, 'message' => "Service $id moved to trash."]);
}

/** @return array|WP_Error */
function wpultra_booking_act_staff_set(array $input) {
    $staff = is_array($input['staff'] ?? null) ? $input['staff'] : [];
    $existing = null;
    if ((int) ($staff['id'] ?? 0) > 0) {
        $existing = wpultra_book_staff_find(wpultra_book_staff_all(), (int) $staff['id']);
        if ($existing === null) { return wpultra_err('staff_not_found', 'No staff member with id ' . (int) $staff['id'] . '. Omit id to create.'); }
    }
    // On a merge-update, name may be omitted — validate against the merged view.
    $check = $staff;
    if ($existing !== null && !array_key_exists('name', $check)) { $check['name'] = (string) ($existing['name'] ?? ''); }
    $v = wpultra_book_staff_validate($check);
    if ($v !== true) { return wpultra_err('invalid_staff', (string) $v); }

    $res = wpultra_book_staff_upsert(wpultra_book_staff_all(), $staff);
    wpultra_book_staff_save_all($res['list']);
    wpultra_audit_log('booking-manage', 'staff-set #' . $res['id'], true);
    return wpultra_ok(['staff_id' => $res['id'], 'staff' => $res['list'], 'count' => count($res['list'])]);
}

/** @return array|WP_Error */
function wpultra_booking_act_staff_delete(array $input) {
    $id = (int) ($input['staff_id'] ?? 0);
    $list = wpultra_book_staff_all();
    if (wpultra_book_staff_find($list, $id) === null) { return wpultra_err('staff_not_found', "No staff member with id $id."); }
    $list = wpultra_book_staff_remove($list, $id);
    wpultra_book_staff_save_all($list);
    wpultra_audit_log('booking-manage', "staff-delete #$id", true);
    return wpultra_ok(['staff' => $list, 'count' => count($list), 'message' => "Staff $id removed. Stale ids in service staff_ids are ignored automatically."]);
}

/** @return array|WP_Error */
function wpultra_booking_act_availability(array $input) {
    $service_id = (int) ($input['service_id'] ?? 0);
    $date = trim((string) ($input['date'] ?? ''));
    if ($service_id <= 0) { return wpultra_err('missing_service', 'service_id is required.'); }
    if ($date === '') { return wpultra_err('missing_date', 'date (Y-m-d) is required.'); }
    $res = wpultra_book_availability($service_id, $date, (int) ($input['staff_id'] ?? 0));
    if (is_wp_error($res)) { return $res; }
    $count = 0;
    foreach ($res['staff'] as $row) { $count += count($row['slots']); }
    return wpultra_ok(['availability' => $res, 'count' => $count]);
}

/** @return array|WP_Error */
function wpultra_booking_act_book(array $input) {
    $service_id = (int) ($input['service_id'] ?? 0);
    if ($service_id <= 0) { return wpultra_err('missing_service', 'service_id is required.'); }
    $customer = is_array($input['customer'] ?? null) ? $input['customer'] : [];
    $start = wpultra_book_parse_start($input['start'] ?? '', trim((string) ($input['date'] ?? '')), wpultra_book_tz());
    if ($start === null) {
        return wpultra_err('invalid_start', 'start must be "Y-m-d H:i", a unix timestamp, or "HH:MM" together with date.');
    }
    $id = wpultra_book_create(
        $service_id,
        (int) ($input['staff_id'] ?? 0),
        $customer,
        $start,
        (string) ($input['note'] ?? ''),
        ($input['skip_slot_check'] ?? false) === true
    );
    if (is_wp_error($id)) {
        wpultra_audit_log('booking-manage', 'book failed: ' . $id->get_error_message(), false);
        return $id;
    }
    $meta = wpultra_book_load((int) $id);
    wpultra_audit_log('booking-manage', "book #$id service=$service_id", true);
    return wpultra_ok(['booking' => wpultra_booking_out((array) $meta, (int) $id)]);
}

/** @return array|WP_Error */
function wpultra_booking_act_list(array $input) {
    $f = is_array($input['filters'] ?? null) ? $input['filters'] : [];
    $tz = wpultra_book_tz();
    $filters = [
        'status'     => (string) ($f['status'] ?? ''),
        'staff_id'   => (int) ($f['staff_id'] ?? 0),
        'service_id' => (int) ($f['service_id'] ?? 0),
        'search'     => (string) ($f['search'] ?? ''),
    ];
    if (trim((string) ($f['from'] ?? '')) !== '') {
        $b = wpultra_book_day_bounds(trim((string) $f['from']), $tz);
        if ($b === null) { return wpultra_err('invalid_from', 'filters.from must be Y-m-d.'); }
        $filters['from_ts'] = $b[0];
    }
    if (trim((string) ($f['to'] ?? '')) !== '') {
        $b = wpultra_book_day_bounds(trim((string) $f['to']), $tz);
        if ($b === null) { return wpultra_err('invalid_to', 'filters.to must be Y-m-d.'); }
        $filters['to_ts'] = $b[1]; // inclusive end date → exclusive next midnight
    }
    $limit = (int) ($f['limit'] ?? 50);
    $limit = max(1, min(200, $limit ?: 50));

    $items = wpultra_book_query($filters);
    $total = count($items);
    $out = [];
    foreach (array_slice($items, 0, $limit) as $it) {
        $out[] = wpultra_booking_out($it['meta'], $it['id']);
    }
    return wpultra_ok(['bookings' => $out, 'count' => $total]);
}

/** @return array|WP_Error */
function wpultra_booking_act_get(array $input) {
    $id = (int) ($input['booking_id'] ?? 0);
    $meta = wpultra_book_load($id);
    if ($meta === null) { return wpultra_err('booking_not_found', "No booking with id $id."); }
    return wpultra_ok(['booking' => wpultra_booking_out($meta, $id)]);
}

/** @return array|WP_Error */
function wpultra_booking_act_status(array $input) {
    $id = (int) ($input['booking_id'] ?? 0);
    $meta = wpultra_book_load($id);
    if ($meta === null) { return wpultra_err('booking_not_found', "No booking with id $id."); }
    $to = trim((string) ($input['status'] ?? ''));
    if (!in_array($to, wpultra_book_statuses(), true)) {
        return wpultra_err('unknown_status', "Unknown status '$to'. Known: " . implode(', ', wpultra_book_statuses()));
    }
    $from = (string) ($meta['status'] ?? 'pending');
    if (!wpultra_book_can_transition($from, $to)) {
        return wpultra_err('illegal_transition', "Cannot move booking $id from '$from' to '$to'.");
    }
    if ($from !== $to) {
        $meta['status'] = $to;
        wpultra_book_save($id, $meta);
        wpultra_book_send_status_email($id, $meta, $to);
    }
    wpultra_audit_log('booking-manage', "booking-status #$id $from->$to", true);
    return wpultra_ok(['booking' => wpultra_booking_out($meta, $id)]);
}

/** @return array|WP_Error */
function wpultra_booking_act_delete(array $input) {
    $id = (int) ($input['booking_id'] ?? 0);
    if (wpultra_book_load($id) === null) { return wpultra_err('booking_not_found', "No booking with id $id."); }
    $res = function_exists('wp_trash_post') ? wp_trash_post($id) : wp_delete_post($id, false);
    if (!$res) { return wpultra_err('delete_failed', "Could not delete booking $id."); }
    wpultra_audit_log('booking-manage', "booking-delete #$id", true);
    return wpultra_ok(['deleted' => true, 'message' => "Booking $id moved to trash."]);
}
