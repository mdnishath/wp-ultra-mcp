<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/graphql-query', [
    'label'       => __('GraphQL: Run Query', 'wp-ultra-mcp'),
    'description' => __('Execute a GraphQL query or mutation against this site\'s WPGraphQL schema, server-side (runs as the authenticated MCP user — no HTTP round-trip). Queries run freely; mutations are confirm-gated (re-run with confirm:true). Supports variables and operation_name. Use graphql-introspect first to learn the schema, then test the exact queries your frontend will ship.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'query'          => ['type' => 'string', 'description' => 'The GraphQL document, e.g. "{ posts(first: 5) { nodes { title uri } } }".'],
            'variables'      => ['type' => 'object', 'description' => 'Variables for the document, e.g. {"n": 5}.', 'additionalProperties' => true, 'properties' => []],
            'operation_name' => ['type' => 'string', 'description' => 'Which operation to run when the document contains several.'],
            'confirm'        => ['type' => 'boolean', 'description' => 'Required true to run a mutation.'],
        ],
        'required'             => ['query'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'operation' => ['type' => 'string'],
            'data'      => ['type' => ['object', 'null']],
            'errors'    => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_graphql_query_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_graphql_query_cb(array $input) {
    $query = trim((string) ($input['query'] ?? ''));
    if ($query === '') { return wpultra_err('missing_query', 'Provide a GraphQL document in `query`.'); }

    $op = wpultra_headless_operation_type($query);
    if ($op === '') { return wpultra_err('bad_query', 'That does not look like a GraphQL document (no operation found).'); }
    if ($op === 'subscription') { return wpultra_err('unsupported', 'Subscriptions cannot run over this transport.'); }
    if ($op === 'mutation' && ($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'This document contains a mutation, which changes site data. Re-run with confirm:true.');
    }

    $res = wpultra_headless_run_graphql($query, (array) ($input['variables'] ?? []), (string) ($input['operation_name'] ?? ''));
    if (is_wp_error($res)) { return $res; }

    $out = ['operation' => $op, 'data' => $res['data'] ?? null];
    if (!empty($res['errors'])) {
        // GraphQL partial-success: surface the errors but keep success=true so
        // the caller sees both data and what failed (standard GraphQL shape).
        $out['errors'] = array_values(array_map(static function ($e): array {
            $e = (array) $e;
            return [
                'message'   => (string) ($e['message'] ?? ''),
                'path'      => isset($e['path']) ? (array) $e['path'] : [],
                'locations' => isset($e['locations']) ? (array) $e['locations'] : [],
            ];
        }, (array) $res['errors']));
    }
    return wpultra_ok($out);
}
