<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/theme-json-set', [
    'label'       => __('Set Theme JSON', 'wp-ultra-mcp'),
    'description' => __('Write global styles/settings (theme.json user layer) for the active block theme. Deep-merges over existing user data by default; set merge:false to replace the provided sections outright.', 'wp-ultra-mcp'),
    'category'    => 'fse',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'settings' => ['type' => 'object'],
            'styles'   => ['type' => 'object'],
            'merge'    => ['type' => 'boolean', 'default' => true],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'post_id'  => ['type' => 'integer'],
            'settings' => ['type' => 'object'],
            'styles'   => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_theme_json_set',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_theme_json_set(array $input) {
    $settings = (array) ($input['settings'] ?? []);
    $styles = (array) ($input['styles'] ?? []);
    $merge = array_key_exists('merge', $input) ? (bool) $input['merge'] : true;
    if ($settings === [] && $styles === []) {
        return wpultra_err('missing_input', 'Provide at least one of settings or styles.');
    }
    $res = wpultra_fse_theme_json_set($settings, $styles, $merge);
    if (is_wp_error($res)) {
        wpultra_audit_log('theme-json-set', 'failed: ' . $res->get_error_message(), false);
        return $res;
    }
    wpultra_audit_log('theme-json-set', 'updated global styles (merge=' . ($merge ? 'true' : 'false') . ')');
    return wpultra_ok($res);
}
