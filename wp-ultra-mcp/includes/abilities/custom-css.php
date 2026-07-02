<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/custom-css', [
    'label'       => __('Custom CSS', 'wp-ultra-mcp'),
    'description' => __('Get, replace, or append the site\'s additional custom CSS (works on classic and block themes).', 'wp-ultra-mcp'),
    'category'    => 'fse',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'set', 'append']],
            'css'    => ['type' => 'string'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'css'     => ['type' => 'string'],
            'length'  => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_custom_css',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_custom_css(array $input) {
    $action = (string) ($input['action'] ?? '');
    if (!in_array($action, ['get', 'set', 'append'], true)) {
        return wpultra_err('bad_action', 'action must be get, set, or append.');
    }

    if ($action === 'get') {
        $res = wpultra_fse_custom_css_get();
        if (is_wp_error($res)) { return $res; }
        return wpultra_ok($res);
    }

    $css = (string) ($input['css'] ?? '');
    if ($css === '') { return wpultra_err('missing_css', 'css is required for set/append.'); }

    $res = wpultra_fse_custom_css_set($css, $action === 'append');
    if (is_wp_error($res)) {
        wpultra_audit_log('custom-css', "$action failed: " . $res->get_error_message(), false);
        return $res;
    }
    wpultra_audit_log('custom-css', "$action (" . $res['length'] . ' bytes)');
    return wpultra_ok($res);
}
