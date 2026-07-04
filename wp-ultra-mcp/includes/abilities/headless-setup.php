<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-setup', [
    'label'       => __('Headless: Setup', 'wp-ultra-mcp'),
    'description' => __('One-call headless backend setup (confirm-gated): installs + activates the WPGraphQL bundle (core, JWT auth, Smart Cache; plus WPGraphQL-for-ACF when ACF is active and WooGraphQL when WooCommerce is active), forces pretty permalinks, stores the allowed frontend CORS origin(s), and generates the JWT signing secret. Run headless-status first to see what is missing. Re-runnable: already-installed pieces are skipped.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'confirm'           => ['type' => 'boolean', 'description' => 'Must be true — installs plugins and writes config.'],
            'origins'           => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Frontend origin(s) to allow via CORS, e.g. ["http://localhost:3000", "https://front.example.com"]. Omit to leave CORS unchanged.'],
            'install'           => ['type' => 'boolean', 'default' => true, 'description' => 'false = configure only (permalinks, CORS, secret), install nothing.'],
            'pretty_permalinks' => ['type' => 'boolean', 'default' => true, 'description' => 'Force /%postname%/ when the site is on plain permalinks.'],
        ],
        'required'             => ['confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'            => ['type' => 'boolean'],
            'plugins'            => ['type' => 'array'],
            'permalinks_changed' => ['type' => 'boolean'],
            'cors'               => ['type' => 'object'],
            'jwt_secret'         => ['type' => 'string'],
            'status'             => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_setup_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_setup_cb(array $input) {
    // Installers, activation hooks, and rewrite flushes all like to echo HTML
    // (upgrader skin, FS-credentials form, plugin notices) — any stray byte
    // corrupts the JSON-RPC response, so buffer the ENTIRE run and discard.
    ob_start();
    try {
        return wpultra_headless_setup_run($input);
    } finally {
        ob_end_clean();
    }
}

function wpultra_headless_setup_run(array $input) {
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'headless-setup installs plugins and writes config. Re-run with confirm:true.');
    }

    // Validate origins up-front so a typo aborts before any install.
    $origins = null;
    if (array_key_exists('origins', $input)) {
        $origins = wpultra_headless_validate_origins((array) $input['origins']);
        if (is_string($origins)) { return wpultra_err('bad_origin', $origins); }
    }

    $ctx = [
        'acf' => class_exists('ACF') || function_exists('acf_get_field_groups'),
        'woo' => class_exists('WooCommerce'),
    ];
    $report = [];

    // 1) Install + activate the bundle. The upgrader skin echoes HTML progress,
    //    which would corrupt the JSON-RPC response — buffer and discard it.
    if (($input['install'] ?? true) !== false) {
        if (!function_exists('wpultra_system_install_plugin')) {
            return wpultra_err('system_disabled', 'The system category (plugin installer) is disabled — enable it or run with install:false.');
        }
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        foreach (wpultra_headless_bundle_plan(wpultra_headless_detect(), $ctx) as $item) {
            if ($item['action'] === 'already') {
                $report[] = ['key' => $item['key'], 'result' => 'already-installed'];
                continue;
            }
            // Detection only sees ACTIVE plugins — an inactive copy on disk just
            // needs activating (re-installing over it would fail on folder-exists).
            $inactive = wpultra_headless_match_installed(array_keys(get_plugins()), $item['key']);
            if ($inactive !== '') {
                $act = wpultra_system_activate_plugin($inactive);
                $report[] = ['key' => $item['key'], 'result' => is_wp_error($act) ? 'failed: ' . $act->get_error_message() : 'activated'];
                continue;
            }
            try {
                $res = wpultra_system_install_plugin($item['source']);
                if (!is_wp_error($res) && !empty($res['plugin'])) {
                    $act = wpultra_system_activate_plugin((string) $res['plugin']);
                    if (is_wp_error($act)) { $res = $act; }
                }
            } catch (\Throwable $e) {
                $res = wpultra_err('install_exception', $e->getMessage());
            }
            $report[] = [
                'key'    => $item['key'],
                'result' => is_wp_error($res) ? 'failed: ' . $res->get_error_message() : 'installed+activated',
            ];
        }
    }

    // 2) Pretty permalinks (GraphQL routing + every frontend slug route needs them).
    $permalinks_changed = false;
    if (($input['pretty_permalinks'] ?? true) !== false && !wpultra_headless_permalinks()['pretty']) {
        update_option('permalink_structure', '/%postname%/');
        if (function_exists('flush_rewrite_rules')) { flush_rewrite_rules(); }
        $permalinks_changed = true;
    }

    // 3) CORS origins (only when the caller passed them).
    if (is_array($origins)) {
        update_option('wpultra_headless_cors', ['origins' => $origins], false);
    }

    // 4) JWT signing secret: a wp-config constant wins; otherwise generate once
    //    and store — the runtime filter feeds it to WPGraphQL-JWT on every request.
    if (defined('GRAPHQL_JWT_AUTH_SECRET_KEY') && constant('GRAPHQL_JWT_AUTH_SECRET_KEY') !== '') {
        $jwt_secret = 'constant';
    } elseif (wpultra_headless_jwt_secret() !== '') {
        $jwt_secret = 'existing';
    } else {
        update_option('wpultra_headless_jwt_secret', wpultra_headless_generate_secret(), false);
        $jwt_secret = 'generated';
    }

    // Fresh readiness snapshot so the caller sees the after-state in the same call.
    $detected = wpultra_headless_detect();
    $perms    = wpultra_headless_permalinks();
    $cors     = wpultra_headless_shape_cors(get_option('wpultra_headless_cors', []));
    return wpultra_ok([
        'plugins'            => $report,
        'permalinks_changed' => $permalinks_changed,
        'cors'               => $cors,
        'jwt_secret'         => $jwt_secret,
        'status'             => wpultra_headless_readiness($detected, $perms['pretty'], $cors['enabled'], $ctx),
    ]);
}
