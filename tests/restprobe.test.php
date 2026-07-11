<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('wpultra_err')) { function wpultra_err($code, $msg, $data = '') { return new WP_Error($code, $msg, $data); } }
require __DIR__ . '/../wp-ultra-mcp/includes/system/restprobe.php';

/* ------------------------------------------------------------------ *
 * wpultra_restprobe_validate
 * ------------------------------------------------------------------ */

it('validate: rejects a route missing the leading slash', function () {
    $r = wpultra_restprobe_validate('wp/v2/posts', 'GET', false);
    assert_wp_error($r, 'expected WP_Error for missing leading slash');
    assert_eq('invalid_route', $r->get_error_code());
});

it('validate: rejects an empty route', function () {
    $r = wpultra_restprobe_validate('', 'GET', false);
    assert_wp_error($r, 'expected WP_Error for empty route');
    assert_eq('invalid_route', $r->get_error_code());
});

it('validate: rejects an unknown method', function () {
    $r = wpultra_restprobe_validate('/wp/v2/posts', 'TRACE', false);
    assert_wp_error($r, 'expected WP_Error for unsupported method');
    assert_eq('invalid_method', $r->get_error_code());
});

it('validate: accepts a lowercase method and treats it case-insensitively', function () {
    $r = wpultra_restprobe_validate('/wp/v2/posts', 'get', false);
    assert_eq(true, $r);
});

it('validate: GET never requires confirm', function () {
    $r = wpultra_restprobe_validate('/wp/v2/posts', 'GET', false);
    assert_eq(true, $r);
});

it('validate: POST without confirm is rejected', function () {
    $r = wpultra_restprobe_validate('/wp/v2/posts', 'POST', false);
    assert_wp_error($r, 'expected WP_Error for unconfirmed mutating method');
    assert_eq('unconfirmed', $r->get_error_code());
});

it('validate: POST with confirm is accepted', function () {
    $r = wpultra_restprobe_validate('/wp/v2/posts', 'POST', true);
    assert_eq(true, $r);
});

it('validate: DELETE without confirm is rejected', function () {
    $r = wpultra_restprobe_validate('/wp/v2/posts/5', 'DELETE', false);
    assert_wp_error($r, 'expected WP_Error for unconfirmed DELETE');
    assert_eq('unconfirmed', $r->get_error_code());
});

it('validate: PUT and PATCH with confirm are both accepted', function () {
    assert_eq(true, wpultra_restprobe_validate('/wp/v2/posts/5', 'PUT', true));
    assert_eq(true, wpultra_restprobe_validate('/wp/v2/posts/5', 'PATCH', true));
});

/* ------------------------------------------------------------------ *
 * wpultra_restprobe_normalize_body
 * ------------------------------------------------------------------ */

it('normalize_body: passes an already-string body through unchanged', function () {
    assert_eq('plain text body', wpultra_restprobe_normalize_body('plain text body'));
});

it('normalize_body: null becomes the literal "null"', function () {
    assert_eq('null', wpultra_restprobe_normalize_body(null));
});

it('normalize_body: boolean scalars become JSON literals', function () {
    assert_eq('true', wpultra_restprobe_normalize_body(true));
    assert_eq('false', wpultra_restprobe_normalize_body(false));
});

it('normalize_body: numeric scalars are stringified', function () {
    assert_eq('42', wpultra_restprobe_normalize_body(42));
});

it('normalize_body: arrays are JSON-encoded', function () {
    assert_eq('{"id":1,"title":"Hello"}', wpultra_restprobe_normalize_body(['id' => 1, 'title' => 'Hello']));
});

it('normalize_body: list arrays are JSON-encoded as JSON arrays', function () {
    assert_eq('[1,2,3]', wpultra_restprobe_normalize_body([1, 2, 3]));
});

/* ------------------------------------------------------------------ *
 * wpultra_restprobe_shape_headers
 * ------------------------------------------------------------------ */

it('shape_headers: string values pass through', function () {
    $out = wpultra_restprobe_shape_headers(['Content-Type' => 'application/json']);
    assert_eq(['Content-Type' => 'application/json'], $out);
});

it('shape_headers: multi-value (array) headers are flattened to a comma-joined string', function () {
    $out = wpultra_restprobe_shape_headers(['Link' => ['<a>; rel="next"', '<b>; rel="prev"']]);
    assert_eq(['Link' => '<a>; rel="next", <b>; rel="prev"'], $out);
});

it('shape_headers: non-string scalar values are stringified', function () {
    $out = wpultra_restprobe_shape_headers(['X-Count' => 42]);
    assert_eq(['X-Count' => '42'], $out);
});

it('shape_headers: an overlong header value is capped', function () {
    $out = wpultra_restprobe_shape_headers(['X-Big' => str_repeat('a', 3000)]);
    assert_eq(2000, strlen($out['X-Big']));
});

it('shape_headers: empty header map yields an empty array', function () {
    assert_eq([], wpultra_restprobe_shape_headers([]));
});

/* ------------------------------------------------------------------ *
 * wpultra_restprobe_shape_response
 * ------------------------------------------------------------------ */

it('shape_response: 200 status is ok, body untruncated when under cap', function () {
    $out = wpultra_restprobe_shape_response(200, ['id' => 1], ['Content-Type' => 'application/json'], 20000);
    assert_eq(200, $out['status']);
    assert_true($out['ok']);
    assert_eq(false, $out['truncated']);
    assert_eq('{"id":1}', $out['body']);
    assert_eq(['Content-Type' => 'application/json'], $out['headers']);
});

it('shape_response: 299 is still ok, 300 is not, 199 is not', function () {
    assert_true(wpultra_restprobe_shape_response(299, null, [], 100)['ok']);
    assert_true(!wpultra_restprobe_shape_response(300, null, [], 100)['ok']);
    assert_true(!wpultra_restprobe_shape_response(199, null, [], 100)['ok']);
});

it('shape_response: 404 is not ok', function () {
    $out = wpultra_restprobe_shape_response(404, ['code' => 'rest_no_route'], [], 20000);
    assert_true(!$out['ok']);
    assert_eq(404, $out['status']);
});

it('shape_response: body longer than cap is truncated and flagged', function () {
    $big = ['data' => str_repeat('x', 100)];
    $out = wpultra_restprobe_shape_response(200, $big, [], 20);
    assert_eq(20, strlen($out['body']));
    assert_true($out['truncated']);
});

it('shape_response: body under cap is not flagged truncated', function () {
    $out = wpultra_restprobe_shape_response(200, ['a' => 1], [], 20000);
    assert_true(!$out['truncated']);
});

it('shape_response: null data normalizes without truncation', function () {
    $out = wpultra_restprobe_shape_response(204, null, [], 20000);
    assert_eq('null', $out['body']);
    assert_true(!$out['truncated']);
});

it('shape_response: scalar (non-array) data is handled', function () {
    $out = wpultra_restprobe_shape_response(200, 'ok', [], 20000);
    assert_eq('ok', $out['body']);
});

run_tests();
