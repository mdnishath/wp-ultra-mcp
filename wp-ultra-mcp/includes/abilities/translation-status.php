<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/translation-status', [
    'label'       => __('Translation Status', 'wp-ultra-mcp'),
    'description' => __('Reports which multilingual plugin (WPML or Polylang) is active, configured languages (code, name, default flag), and per-post-type translated/untranslated counts. Call FIRST before duplicate-to-language — an empty active_plugin means no multilingual plugin is installed.', 'wp-ultra-mcp'),
    'category'    => 'multilingual',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success'          => ['type' => 'boolean'],
            'active_plugin'    => ['type' => 'string'],
            'languages'        => ['type' => 'array'],
            'post_type_counts' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_translation_status',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_translation_status(array $input) {
    $status = wpultra_i18n_status();
    return wpultra_ok($status);
}
