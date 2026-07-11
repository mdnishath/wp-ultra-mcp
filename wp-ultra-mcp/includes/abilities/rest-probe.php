<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/rest-probe', [
    'label'       => __('REST: Probe Route', 'wp-ultra-mcp'),
    'description' => __('Invoke an arbitrary WP REST API route internally (via rest_do_request, no HTTP loopback) and return its status, headers, and body — runs as the authenticated MCP user. The REST twin of graphql-query; complements list-registry\'s rest-routes listing (which only enumerates routes without calling them). GET is unrestricted; POST/PUT/PATCH/DELETE mutate site data and are confirm-gated (re-run with confirm:true). params become query args for GET or body params otherwise. The body is capped (first 20000 characters) with a truncated flag.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'route'   => ['type' => 'string', 'description' => 'Absolute REST route, e.g. "/wp/v2/posts".'],
            'method'  => ['type' => 'string', 'description' => 'HTTP method. Default GET.', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
            'params'  => ['type' => 'object', 'description' => 'Query params (GET) or body params (POST/PUT/PATCH/DELETE).', 'additionalProperties' => true, 'properties' => []],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true for any non-GET method.'],
        ],
        'required'             => ['route'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'route'     => ['type' => 'string'],
            'method'    => ['type' => 'string'],
            'status'    => ['type' => 'integer'],
            'ok'        => ['type' => 'boolean'],
            'headers'   => ['type' => 'object', 'additionalProperties' => true, 'properties' => []],
            'body'      => ['type' => 'string'],
            'truncated' => ['type' => 'boolean'],
        ],
        'required' => ['success', 'route', 'method', 'status', 'ok', 'headers', 'body', 'truncated'],
    ],
    'execute_callback'    => 'wpultra_rest_probe_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_rest_probe_cb(array $input) {
    $route  = trim((string) ($input['route'] ?? ''));
    $method = strtoupper(trim((string) ($input['method'] ?? 'GET')));
    if ($method === '') { $method = 'GET'; }
    $confirm = ($input['confirm'] ?? false) === true;

    $valid = wpultra_restprobe_validate($route, $method, $confirm);
    if (is_wp_error($valid)) { return $valid; }

    $params = is_array($input['params'] ?? null) ? $input['params'] : [];

    $result = wpultra_restprobe_run($route, $method, $params, wpultra_restprobe_default_cap());
    if (is_wp_error($result)) { return $result; }

    return wpultra_ok(array_merge(['route' => $route, 'method' => $method], $result));
}
