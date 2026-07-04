<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-status', [
    'label'       => __('Headless: Status', 'wp-ultra-mcp'),
    'description' => __('Headless-readiness report in one call: WPGraphQL stack detection (core, JWT auth, ACF addon, WooGraphQL, Smart Cache) with versions, GraphQL endpoint URL, permalink structure, CORS state, auth mode, plus a 0-100 readiness score with what is missing and how to fix it. The orientation call before headless-setup / graphql-introspect / graphql-query.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'          => ['type' => 'boolean'],
            'ready'            => ['type' => 'boolean'],
            'score'            => ['type' => 'integer'],
            'graphql_endpoint' => ['type' => 'string'],
            'plugins'          => ['type' => 'object'],
            'permalinks'       => ['type' => 'object'],
            'cors'             => ['type' => 'object'],
            'auth'             => ['type' => 'object'],
            'missing'          => ['type' => 'array'],
            'recommendations'  => ['type' => 'array'],
        ],
        'required' => ['success', 'ready', 'score'],
    ],
    'execute_callback'    => 'wpultra_headless_status_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_status_cb(array $input) {
    $detected   = wpultra_headless_detect();
    $permalinks = wpultra_headless_permalinks();
    $cors       = wpultra_headless_shape_cors(get_option('wpultra_headless_cors', []));
    $ctx        = [
        'acf' => class_exists('ACF') || function_exists('acf_get_field_groups'),
        'woo' => class_exists('WooCommerce'),
    ];
    $readiness = wpultra_headless_readiness($detected, $permalinks['pretty'], $cors['enabled'], $ctx);

    // WPGraphQL serves /graphql from the site root (pretty permalinks) or via
    // the ?graphql query var on plain permalinks.
    $endpoint = '';
    if (($detected['wp-graphql'] ?? null) !== null) {
        $route    = function_exists('apply_filters') ? (string) apply_filters('graphql_endpoint', 'graphql') : 'graphql';
        $endpoint = $permalinks['pretty']
            ? trailingslashit(home_url()) . $route
            : add_query_arg('graphql', 'true', trailingslashit(home_url()));
    }

    $jwt_secret_defined = defined('GRAPHQL_JWT_AUTH_SECRET_KEY') && constant('GRAPHQL_JWT_AUTH_SECRET_KEY') !== '';
    return wpultra_ok([
        'ready'            => $readiness['ready'],
        'score'            => $readiness['score'],
        'graphql_endpoint' => $endpoint,
        'plugins'          => $detected,
        'permalinks'       => $permalinks,
        'cors'             => $cors,
        'auth'             => [
            'mode'                  => wpultra_headless_auth_mode($detected, $jwt_secret_defined),
            'jwt_secret_defined'    => $jwt_secret_defined,
            'application_passwords' => function_exists('wp_is_application_passwords_available') ? wp_is_application_passwords_available() : false,
        ],
        'missing'          => $readiness['missing'],
        'recommendations'  => $readiness['recommendations'],
    ]);
}
