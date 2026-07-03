<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * WooCommerce Subscriptions + Bookings support (roadmap #19).
 *
 * Both extensions are optional third-party plugins. Every entry point in this
 * file degrades gracefully (WP_Error 'subscriptions_unavailable' /
 * 'bookings_unavailable') when the corresponding plugin isn't installed, so
 * loading this file unconditionally (as woocommerce/setup.php etc. already
 * are) never breaks a store that lacks these extensions.
 */

// ---------------------------------------------------------------------------
// Detection
// ---------------------------------------------------------------------------

/** True when WooCommerce Subscriptions is active. */
function wpultra_woo_ext_subscriptions_active(): bool {
    return class_exists('WC_Subscriptions') && function_exists('wcs_get_subscriptions');
}

/** True when WooCommerce Bookings is active. */
function wpultra_woo_ext_bookings_active(): bool {
    return class_exists('WC_Bookings') && class_exists('WC_Booking');
}

/** Snapshot of which extensions are installed (+ version when known). Safe to call anytime. */
function wpultra_woo_ext_detect(): array {
    $subs = wpultra_woo_ext_subscriptions_active();
    $bookings = wpultra_woo_ext_bookings_active();
    return [
        'subscriptions' => [
            'active'  => $subs,
            'version' => $subs && property_exists('WC_Subscriptions', 'version') ? WC_Subscriptions::$version : null,
        ],
        'bookings' => [
            'active'  => $bookings,
            'version' => $bookings && defined('WC_BOOKINGS_VERSION') ? WC_BOOKINGS_VERSION : null,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Pure helpers (testable without WordPress/WooCommerce loaded)
// ---------------------------------------------------------------------------

/** Allowed WC Subscriptions statuses an ability may set via update_status(). Pure. */
function wpultra_woo_ext_valid_sub_status(string $status): bool {
    return in_array($status, ['active', 'on-hold', 'cancelled', 'pending-cancel', 'expired'], true);
}

/** Allowed WC Bookings statuses an ability may set via update_status(). Pure. */
function wpultra_woo_ext_valid_booking_status(string $status): bool {
    return in_array($status, ['unpaid', 'pending-confirmation', 'confirmed', 'paid', 'cancelled', 'complete'], true);
}

/**
 * Shape a subscription-like array (from a fixture, or from a real WC_Subscription
 * via get_id/get_status/... coerced to an array by the caller) into the compact
 * row this plugin returns to the AI. Pure — no WooCommerce classes required.
 */
function wpultra_woo_ext_sub_shape(array $sub): array {
    return [
        'id'            => (int) ($sub['id'] ?? 0),
        'status'        => (string) ($sub['status'] ?? ''),
        'total'         => (string) ($sub['total'] ?? '0'),
        'currency'      => (string) ($sub['currency'] ?? ''),
        'billing_email' => (string) ($sub['billing_email'] ?? ''),
        'customer_id'   => (int) ($sub['customer_id'] ?? 0),
        'next_payment'  => isset($sub['next_payment']) && $sub['next_payment'] !== '' ? (string) $sub['next_payment'] : null,
        'start_date'    => isset($sub['start_date']) && $sub['start_date'] !== '' ? (string) $sub['start_date'] : null,
        'end_date'      => isset($sub['end_date']) && $sub['end_date'] !== '' ? (string) $sub['end_date'] : null,
    ];
}

/**
 * Shape a booking-like array (from a fixture, or a real WC_Booking) into the
 * compact row this plugin returns to the AI. Pure — no WooCommerce classes required.
 */
function wpultra_woo_ext_booking_shape(array $booking): array {
    return [
        'id'          => (int) ($booking['id'] ?? 0),
        'status'      => (string) ($booking['status'] ?? ''),
        'product_id'  => (int) ($booking['product_id'] ?? 0),
        'customer_id' => (int) ($booking['customer_id'] ?? 0),
        'start'       => isset($booking['start']) && $booking['start'] !== '' ? (string) $booking['start'] : null,
        'end'         => isset($booking['end']) && $booking['end'] !== '' ? (string) $booking['end'] : null,
        'cost'        => isset($booking['cost']) ? (string) $booking['cost'] : null,
        'persons'     => isset($booking['persons']) ? (int) $booking['persons'] : null,
    ];
}

// ---------------------------------------------------------------------------
// Subscriptions
// ---------------------------------------------------------------------------

function wpultra_woo_ext_subscriptions_unavailable() {
    return wpultra_err('subscriptions_unavailable', 'WooCommerce Subscriptions is not installed/active.');
}

function wpultra_woo_ext_bookings_unavailable() {
    return wpultra_err('bookings_unavailable', 'WooCommerce Bookings is not installed/active.');
}

/** Turn a live WC_Subscription (extends WC_Order) into our compact row. */
function wpultra_woo_ext_sub_row_from_object($sub): array {
    $next_payment = null;
    if (method_exists($sub, 'get_date')) {
        $np = $sub->get_date('next_payment');
        $next_payment = $np !== '' && $np !== null ? (string) $np : null;
    }
    $start = null;
    $end = null;
    if (method_exists($sub, 'get_date')) {
        $s = $sub->get_date('start');
        $start = $s !== '' && $s !== null ? (string) $s : null;
        $e = $sub->get_date('end');
        $end = $e !== '' && $e !== null ? (string) $e : null;
    }
    return wpultra_woo_ext_sub_shape([
        'id'            => $sub->get_id(),
        'status'        => $sub->get_status(),
        'total'         => $sub->get_total(),
        'currency'      => method_exists($sub, 'get_currency') ? $sub->get_currency() : '',
        'billing_email' => $sub->get_billing_email(),
        'customer_id'   => $sub->get_customer_id(),
        'next_payment'  => $next_payment,
        'start_date'    => $start,
        'end_date'      => $end,
    ]);
}

/** List subscriptions with optional filters: subscription_status, customer_id, page, per_page. */
function wpultra_woo_ext_subscriptions_list(array $args) {
    if (!wpultra_woo_ext_subscriptions_active()) { return wpultra_woo_ext_subscriptions_unavailable(); }

    $q = [
        'subscriptions_per_page' => isset($args['per_page']) ? (int) $args['per_page'] : 20,
        'offset'                 => isset($args['page']) ? max(0, ((int) $args['page'] - 1)) * (isset($args['per_page']) ? (int) $args['per_page'] : 20) : 0,
        'return'                 => 'objects',
    ];
    if (!empty($args['status']))      { $q['subscription_status'] = $args['status']; }
    if (!empty($args['customer_id'])) { $q['customer_id'] = (int) $args['customer_id']; }

    $subs = call_user_func('wcs_get_subscriptions', $q);
    $rows = [];
    foreach ((array) $subs as $sub) { $rows[] = wpultra_woo_ext_sub_row_from_object($sub); }
    return ['count' => count($rows), 'subscriptions' => $rows];
}

/** Fetch a single subscription by id. */
function wpultra_woo_ext_subscription_get(int $id) {
    if (!wpultra_woo_ext_subscriptions_active()) { return wpultra_woo_ext_subscriptions_unavailable(); }
    $sub = function_exists('wcs_get_subscription') ? call_user_func('wcs_get_subscription', $id) : false;
    if (!$sub) { return wpultra_err('subscription_not_found', "No subscription with id $id."); }
    return wpultra_woo_ext_sub_row_from_object($sub);
}

/**
 * Change a subscription's status. $status must be one of the WC Subscriptions
 * allowed statuses (active, on-hold, cancelled, pending-cancel, expired).
 */
function wpultra_woo_ext_subscription_set_status(int $id, string $status) {
    if (!wpultra_woo_ext_subscriptions_active()) { return wpultra_woo_ext_subscriptions_unavailable(); }
    if (!wpultra_woo_ext_valid_sub_status($status)) {
        return wpultra_err('invalid_status', "Invalid subscription status: $status");
    }
    $sub = function_exists('wcs_get_subscription') ? call_user_func('wcs_get_subscription', $id) : false;
    if (!$sub) { return wpultra_err('subscription_not_found', "No subscription with id $id."); }
    $sub->update_status($status);
    return wpultra_woo_ext_sub_row_from_object($sub);
}

// ---------------------------------------------------------------------------
// Bookings
// ---------------------------------------------------------------------------

/** Turn a live WC_Booking into our compact row. */
function wpultra_woo_ext_booking_row_from_object($booking): array {
    return wpultra_woo_ext_booking_shape([
        'id'          => $booking->get_id(),
        'status'      => $booking->get_status(),
        'product_id'  => $booking->get_product_id(),
        'customer_id' => $booking->get_customer_id(),
        'start'       => $booking->get_start() ? gmdate('c', (int) $booking->get_start()) : null,
        'end'         => $booking->get_end() ? gmdate('c', (int) $booking->get_end()) : null,
        'cost'        => method_exists($booking, 'get_cost') ? $booking->get_cost() : null,
        'persons'     => method_exists($booking, 'get_persons') ? $booking->get_persons() : null,
    ]);
}

/** List bookings (CPT wc_booking) with optional filters: status, product_id, date_from/date_to (ISO), page, per_page. */
function wpultra_woo_ext_bookings_list(array $args) {
    if (!wpultra_woo_ext_bookings_active()) { return wpultra_woo_ext_bookings_unavailable(); }

    $per_page = isset($args['per_page']) ? (int) $args['per_page'] : 20;
    $page = isset($args['page']) ? max(1, (int) $args['page']) : 1;

    $q = [
        'post_type'      => 'wc_booking',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'fields'         => 'ids',
        'post_status'    => 'any',
    ];
    if (!empty($args['status'])) { $q['post_status'] = $args['status']; }
    if (!empty($args['product_id'])) {
        $q['meta_query'] = [['key' => '_booking_product_id', 'value' => (int) $args['product_id']]];
    }
    if (!empty($args['date_from']) || !empty($args['date_to'])) {
        $date_query = [];
        if (!empty($args['date_from'])) { $date_query['after'] = $args['date_from']; }
        if (!empty($args['date_to']))   { $date_query['before'] = $args['date_to']; }
        $date_query['inclusive'] = true;
        $q['date_query'] = [$date_query];
    }

    $ids = get_posts($q);
    $rows = [];
    foreach ((array) $ids as $bid) {
        $booking = new WC_Booking((int) $bid);
        $rows[] = wpultra_woo_ext_booking_row_from_object($booking);
    }
    return ['count' => count($rows), 'bookings' => $rows];
}

/** Fetch a single booking by id. */
function wpultra_woo_ext_booking_get(int $id) {
    if (!wpultra_woo_ext_bookings_active()) { return wpultra_woo_ext_bookings_unavailable(); }
    $booking = new WC_Booking($id);
    if (!$booking->get_id()) { return wpultra_err('booking_not_found', "No booking with id $id."); }
    return wpultra_woo_ext_booking_row_from_object($booking);
}

/**
 * Change a booking's status. $status must be one of the WC Bookings allowed
 * statuses (unpaid, pending-confirmation, confirmed, paid, cancelled, complete).
 */
function wpultra_woo_ext_booking_set_status(int $id, string $status) {
    if (!wpultra_woo_ext_bookings_active()) { return wpultra_woo_ext_bookings_unavailable(); }
    if (!wpultra_woo_ext_valid_booking_status($status)) {
        return wpultra_err('invalid_status', "Invalid booking status: $status");
    }
    $booking = new WC_Booking($id);
    if (!$booking->get_id()) { return wpultra_err('booking_not_found', "No booking with id $id."); }
    $booking->update_status($status);
    return wpultra_woo_ext_booking_row_from_object($booking);
}
