<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/form-list', [
    'label'       => __('List Forms', 'wp-ultra-mcp'),
    'description' => __('Lists forms across all active form plugins (or one via `plugin`). Each entry gives id, title, plugin, shortcode string, entries_count (null when the plugin stores none — e.g. CF7 without Flamingo, WPForms Lite), and entries_supported.', 'wp-ultra-mcp'),
    'category'    => 'forms',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'plugin' => ['type' => 'string', 'enum' => ['cf7', 'wpforms', 'gravity', 'fluent']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'forms'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_form_list',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_form_list(array $input) {
    $filter   = isset($input['plugin']) ? (string) $input['plugin'] : '';
    $detected = wpultra_forms_detect();
    $forms    = [];
    foreach (wpultra_forms_known_plugins() as $key) {
        if ($filter !== '' && $filter !== $key) { continue; }
        if (($detected[$key] ?? null) === null) { continue; }
        $fn = "wpultra_forms_{$key}_list";
        if (function_exists($fn)) { $forms = array_merge($forms, (array) $fn()); }
    }
    return wpultra_ok(['forms' => $forms]);
}
