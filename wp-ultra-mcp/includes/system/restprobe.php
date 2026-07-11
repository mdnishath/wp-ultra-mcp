<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * REST probe engine — invoke an arbitrary WP REST route internally and return
 * status/headers/body. The REST twin of graphql-query.php.
 *
 * Design: dispatch through rest_do_request() (in-process dispatch), never an HTTP
 * loopback, so the route runs as the current (already-authenticated MCP) user with
 * no loopback deadlock and no auth-header juggling. Mirrors the pattern used by
 * wpultra_analytics_dispatch() in system/analytics.php.
 *
 * Split:
 *  - PURE, testable core: wpultra_restprobe_validate(), wpultra_restprobe_normalize_body(),
 *    wpultra_restprobe_shape_headers(), wpultra_restprobe_shape_response(). No WordPress calls.
 *  - WP-touching: wpultra_restprobe_run() (builds + fires the WP_REST_Request).
 */

// ---------------------------------------------------------------------------
// Pure core
// ---------------------------------------------------------------------------

/** Methods this probe is willing to dispatch. */
function wpultra_restprobe_allowed_methods(): array {
    return ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
}

/**
 * PURE. Validate the requested route/method/confirm combination.
 * - $route must be non-empty and start with '/' (an absolute REST path, e.g. "/wp/v2/posts").
 * - $method is matched case-insensitively against the allowed set.
 * - Any method other than GET mutates site data, so it requires $confirm === true.
 * @return true|WP_Error
 */
function wpultra_restprobe_validate(string $route, string $method, bool $confirm) {
    $route = trim($route);
    if ($route === '' || $route[0] !== '/') {
        return wpultra_err('invalid_route', 'route must be an absolute REST path starting with "/", e.g. "/wp/v2/posts".');
    }

    $method = strtoupper(trim($method));
    $allowed = wpultra_restprobe_allowed_methods();
    if (!in_array($method, $allowed, true)) {
        return wpultra_err('invalid_method', 'method must be one of: ' . implode(', ', $allowed) . '.');
    }

    if ($method !== 'GET' && !$confirm) {
        return wpultra_err('unconfirmed', "Method $method can mutate site data. Re-run with confirm:true.");
    }

    return true;
}

/**
 * PURE. Normalize a REST response body into a display-safe string.
 * - A string body is passed through unchanged (already text).
 * - null/bool/int/float are rendered as their JSON literal (e.g. null, true, 42).
 * - Arrays/objects are JSON-encoded.
 */
function wpultra_restprobe_normalize_body($data): string {
    if (is_string($data)) { return $data; }
    if ($data === null || is_bool($data) || is_int($data) || is_float($data)) {
        $encoded = json_encode($data);
        return $encoded === false ? '' : $encoded;
    }
    if (is_array($data) || is_object($data)) {
        $encoded = json_encode($data);
        return $encoded === false ? '[unserializable]' : $encoded;
    }
    return '';
}

/** Max characters kept per header value before it is capped. */
function wpultra_restprobe_header_value_cap(): int { return 2000; }

/**
 * PURE. Normalize a raw header map (as returned by WP_REST_Response::get_headers())
 * into a flat assoc array of strings. Multi-value headers (array values) are
 * comma-joined. Nothing is dropped — these are the site's own routes, not another
 * party's secrets — but values are capped so one huge header can't blow up the payload.
 * @return array<string,string>
 */
function wpultra_restprobe_shape_headers(array $headers): array {
    $cap = wpultra_restprobe_header_value_cap();
    $out = [];
    foreach ($headers as $name => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        } else {
            $value = (string) $value;
        }
        if (strlen($value) > $cap) { $value = substr($value, 0, $cap); }
        $out[(string) $name] = $value;
    }
    return $out;
}

/**
 * PURE. Shape a raw (status, data, headers) triple into the ability's response
 * fields: status, ok (2xx), normalized headers, and a body capped at $cap chars
 * (with a truncated flag).
 * @return array{status:int,ok:bool,headers:array<string,string>,body:string,truncated:bool}
 */
function wpultra_restprobe_shape_response(int $status, $data, array $headers, int $cap): array {
    $cap = max(0, $cap);
    $body = wpultra_restprobe_normalize_body($data);
    $truncated = false;
    if ($cap > 0 && strlen($body) > $cap) {
        $body = substr($body, 0, $cap);
        $truncated = true;
    } elseif ($cap === 0 && $body !== '') {
        $body = '';
        $truncated = true;
    }

    return [
        'status'    => $status,
        'ok'        => ($status >= 200 && $status < 300),
        'headers'   => wpultra_restprobe_shape_headers($headers),
        'body'      => $body,
        'truncated' => $truncated,
    ];
}

/** Default body cap (characters) applied by the ability. */
function wpultra_restprobe_default_cap(): int { return 20000; }

// ---------------------------------------------------------------------------
// WP-touching: dispatch
// ---------------------------------------------------------------------------

/**
 * WP-touching. Build + fire an internal REST request and shape the result.
 * Assumes $route/$method have already passed wpultra_restprobe_validate().
 * @param array<string,mixed> $params query params (GET) or body params (others)
 * @return array{status:int,ok:bool,headers:array,body:string,truncated:bool}|WP_Error
 */
function wpultra_restprobe_run(string $route, string $method, array $params, ?int $cap = null) {
    if (!class_exists('WP_REST_Request') || !function_exists('rest_do_request')) {
        return wpultra_err('rest_unavailable', 'The WordPress REST infrastructure is unavailable in this context.');
    }

    $method = strtoupper($method);
    $cap = $cap ?? wpultra_restprobe_default_cap();

    $request = new WP_REST_Request($method, $route);
    if ($method === 'GET') {
        $request->set_query_params($params);
    } else {
        $request->set_body_params($params);
    }

    $response = rest_do_request($request);

    if (function_exists('is_wp_error') && is_wp_error($response)) {
        /** @var WP_Error $response */
        return wpultra_err('rest_dispatch_error', 'REST dispatch failed: ' . $response->get_error_message(), $response->get_error_data());
    }

    $status  = method_exists($response, 'get_status') ? (int) $response->get_status() : 0;
    $data    = method_exists($response, 'get_data') ? $response->get_data() : null;
    $headers = method_exists($response, 'get_headers') ? $response->get_headers() : [];

    return wpultra_restprobe_shape_response($status, $data, is_array($headers) ? $headers : [], $cap);
}
