<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-rest-bundle', [
    'label'       => __('Headless: REST Bundle', 'wp-ultra-mcp'),
    'description' => __('REST fallback for headless frontends that skip GraphQL: public read-only routes under /wp-json/wpultra/headless/v1 — /menus (nav menus + items, stable shape), /settings (title, language, timezone, icon), /tokens (theme colors + font sizes), /fields/{post_id} (public custom fields of a published post; ACF-formatted when ACF is active). actions: `status` (default), `enable` (optionally routes:{menus,settings,tokens,fields} to switch parts off), `disable`. Off by default.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['status', 'enable', 'disable'], 'default' => 'status'],
            'routes' => ['type' => 'object', 'properties' => [
                'menus'    => ['type' => 'boolean'],
                'settings' => ['type' => 'boolean'],
                'tokens'   => ['type' => 'boolean'],
                'fields'   => ['type' => 'boolean'],
            ], 'additionalProperties' => false, 'description' => 'For action:enable — which routes stay on (all default true).'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'enabled' => ['type' => 'boolean'],
            'routes'  => ['type' => 'object'],
            'urls'    => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_rest_bundle_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_rest_bundle_cb(array $input) {
    $action = (string) ($input['action'] ?? 'status');

    if ($action === 'enable') {
        $cfg = ['enabled' => true, 'routes' => (array) ($input['routes'] ?? [])];
        update_option('wpultra_headless_rest', wpultra_headless_rest_shape_config($cfg), false);
    } elseif ($action === 'disable') {
        $cfg = wpultra_headless_rest_config();
        $cfg['enabled'] = false;
        update_option('wpultra_headless_rest', $cfg, false);
    } elseif ($action !== 'status') {
        return wpultra_err('bad_action', "Unknown action '$action'.");
    }

    $cfg  = wpultra_headless_rest_config();
    $base = rest_url('wpultra/headless/v1');
    $urls = [];
    if ($cfg['enabled']) {
        foreach (['menus' => '/menus', 'settings' => '/settings', 'tokens' => '/tokens', 'fields' => '/fields/{post_id}'] as $key => $path) {
            if ($cfg['routes'][$key]) { $urls[$key] = untrailingslashit($base) . $path; }
        }
    }
    return wpultra_ok(['enabled' => $cfg['enabled'], 'routes' => $cfg['routes'], 'urls' => $urls]);
}
