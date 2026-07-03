<?php
require_once __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require_once __DIR__ . '/../wp-ultra-mcp/includes/helpers.php'; // wpultra_ok()/wpultra_err() used by the graceful-degrade paths below
require_once __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/extensions.php';

// ---- wpultra_woo_ext_valid_sub_status() ----

it('valid sub status accepts the full allowed set', function () {
    assert_true(wpultra_woo_ext_valid_sub_status('active'));
    assert_true(wpultra_woo_ext_valid_sub_status('on-hold'));
    assert_true(wpultra_woo_ext_valid_sub_status('cancelled'));
    assert_true(wpultra_woo_ext_valid_sub_status('pending-cancel'));
    assert_true(wpultra_woo_ext_valid_sub_status('expired'));
});

it('valid sub status rejects unknown/garbage/case-mismatched values', function () {
    assert_true(wpultra_woo_ext_valid_sub_status('') === false);
    assert_true(wpultra_woo_ext_valid_sub_status('processing') === false); // an order status, not a subscription status
    assert_true(wpultra_woo_ext_valid_sub_status('Active') === false); // case-sensitive
    assert_true(wpultra_woo_ext_valid_sub_status('ACTIVE') === false);
    assert_true(wpultra_woo_ext_valid_sub_status('paused') === false);
});

// ---- wpultra_woo_ext_valid_booking_status() ----

it('valid booking status accepts the full allowed set', function () {
    assert_true(wpultra_woo_ext_valid_booking_status('unpaid'));
    assert_true(wpultra_woo_ext_valid_booking_status('pending-confirmation'));
    assert_true(wpultra_woo_ext_valid_booking_status('confirmed'));
    assert_true(wpultra_woo_ext_valid_booking_status('paid'));
    assert_true(wpultra_woo_ext_valid_booking_status('cancelled'));
    assert_true(wpultra_woo_ext_valid_booking_status('complete'));
});

it('valid booking status rejects unknown/garbage/case-mismatched values', function () {
    assert_true(wpultra_woo_ext_valid_booking_status('') === false);
    assert_true(wpultra_woo_ext_valid_booking_status('active') === false); // a subscription status, not a booking status
    assert_true(wpultra_woo_ext_valid_booking_status('Confirmed') === false); // case-sensitive
    assert_true(wpultra_woo_ext_valid_booking_status('in-progress') === false);
});

// ---- wpultra_woo_ext_sub_shape() ----

it('sub_shape maps a full fixture into the compact row', function () {
    $row = wpultra_woo_ext_sub_shape([
        'id' => 42, 'status' => 'active', 'total' => '19.99', 'currency' => 'USD',
        'billing_email' => 'a@b.com', 'customer_id' => 7,
        'next_payment' => '2026-08-01 00:00:00', 'start_date' => '2026-01-01 00:00:00', 'end_date' => '',
    ]);
    assert_eq(42, $row['id']);
    assert_eq('active', $row['status']);
    assert_eq('19.99', $row['total']);
    assert_eq('USD', $row['currency']);
    assert_eq('a@b.com', $row['billing_email']);
    assert_eq(7, $row['customer_id']);
    assert_eq('2026-08-01 00:00:00', $row['next_payment']);
    assert_eq('2026-01-01 00:00:00', $row['start_date']);
    assert_eq(null, $row['end_date']);
});

it('sub_shape defaults missing fixture keys safely (no notices, sane types)', function () {
    $row = wpultra_woo_ext_sub_shape([]);
    assert_eq(0, $row['id']);
    assert_eq('', $row['status']);
    assert_eq('0', $row['total']);
    assert_eq('', $row['currency']);
    assert_eq('', $row['billing_email']);
    assert_eq(0, $row['customer_id']);
    assert_eq(null, $row['next_payment']);
    assert_eq(null, $row['start_date']);
    assert_eq(null, $row['end_date']);
});

it('sub_shape coerces numeric-looking strings for id/customer_id', function () {
    $row = wpultra_woo_ext_sub_shape(['id' => '15', 'customer_id' => '3']);
    assert_eq(15, $row['id']);
    assert_eq(3, $row['customer_id']);
});

// ---- wpultra_woo_ext_booking_shape() ----

it('booking_shape maps a full fixture into the compact row', function () {
    $row = wpultra_woo_ext_booking_shape([
        'id' => 101, 'status' => 'confirmed', 'product_id' => 55, 'customer_id' => 9,
        'start' => '2026-09-01T10:00:00+00:00', 'end' => '2026-09-01T12:00:00+00:00',
        'cost' => '150.00', 'persons' => 2,
    ]);
    assert_eq(101, $row['id']);
    assert_eq('confirmed', $row['status']);
    assert_eq(55, $row['product_id']);
    assert_eq(9, $row['customer_id']);
    assert_eq('2026-09-01T10:00:00+00:00', $row['start']);
    assert_eq('2026-09-01T12:00:00+00:00', $row['end']);
    assert_eq('150.00', $row['cost']);
    assert_eq(2, $row['persons']);
});

it('booking_shape defaults missing fixture keys safely (no notices, sane types)', function () {
    $row = wpultra_woo_ext_booking_shape([]);
    assert_eq(0, $row['id']);
    assert_eq('', $row['status']);
    assert_eq(0, $row['product_id']);
    assert_eq(0, $row['customer_id']);
    assert_eq(null, $row['start']);
    assert_eq(null, $row['end']);
    assert_eq(null, $row['cost']);
    assert_eq(null, $row['persons']);
});

it('booking_shape treats empty-string start/end as null (not an empty date string)', function () {
    $row = wpultra_woo_ext_booking_shape(['start' => '', 'end' => '']);
    assert_eq(null, $row['start']);
    assert_eq(null, $row['end']);
});

// ---- wpultra_woo_ext_detect() ----

it('detect reports both extensions inactive when neither class exists', function () {
    if (class_exists('WC_Subscriptions') || class_exists('WC_Bookings')) { return; } // already loaded elsewhere in this php process — skip
    $map = wpultra_woo_ext_detect();
    assert_true(is_array($map));
    assert_true(array_key_exists('subscriptions', $map));
    assert_true(array_key_exists('bookings', $map));
    assert_eq(false, $map['subscriptions']['active']);
    assert_eq(null, $map['subscriptions']['version']);
    assert_eq(false, $map['bookings']['active']);
    assert_eq(null, $map['bookings']['version']);
});

// ---- graceful degrade when the extension classes are absent ----

it('subscriptions_list errors gracefully when WC_Subscriptions is absent', function () {
    if (class_exists('WC_Subscriptions')) { return; }
    $res = wpultra_woo_ext_subscriptions_list([]);
    assert_wp_error($res);
    assert_eq('subscriptions_unavailable', $res->get_error_code());
});

it('subscription_get errors gracefully when WC_Subscriptions is absent', function () {
    if (class_exists('WC_Subscriptions')) { return; }
    $res = wpultra_woo_ext_subscription_get(1);
    assert_wp_error($res);
    assert_eq('subscriptions_unavailable', $res->get_error_code());
});

it('subscription_set_status errors gracefully when WC_Subscriptions is absent', function () {
    if (class_exists('WC_Subscriptions')) { return; }
    $res = wpultra_woo_ext_subscription_set_status(1, 'active');
    assert_wp_error($res);
    assert_eq('subscriptions_unavailable', $res->get_error_code());
});

it('bookings_list errors gracefully when WC_Bookings is absent', function () {
    if (class_exists('WC_Bookings')) { return; }
    $res = wpultra_woo_ext_bookings_list([]);
    assert_wp_error($res);
    assert_eq('bookings_unavailable', $res->get_error_code());
});

it('booking_get errors gracefully when WC_Bookings is absent', function () {
    if (class_exists('WC_Bookings')) { return; }
    $res = wpultra_woo_ext_booking_get(1);
    assert_wp_error($res);
    assert_eq('bookings_unavailable', $res->get_error_code());
});

it('booking_set_status errors gracefully when WC_Bookings is absent', function () {
    if (class_exists('WC_Bookings')) { return; }
    $res = wpultra_woo_ext_booking_set_status(1, 'confirmed');
    assert_wp_error($res);
    assert_eq('bookings_unavailable', $res->get_error_code());
});

run_tests();
