<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/list-registry', [
    'label'       => __('List Registry (Diagnostics)', 'wp-ultra-mcp'),
    'description' => __('Inspect a core WordPress registry: post-types, taxonomies, shortcodes, roles, hooks (callbacks attached to a given hook), image-sizes, or rest-routes. Returns compact descriptors, never full objects.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'what' => ['type' => 'string', 'enum' => ['post-types', 'taxonomies', 'shortcodes', 'roles', 'hooks', 'image-sizes', 'rest-routes']],
            'hook' => ['type' => 'string'],
        ],
        'required'             => ['what'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'what'    => ['type' => 'string'],
            'hook'    => ['type' => 'string'],
            'items'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_list_registry_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_list_registry_cb(array $input) {
    if (($input['what'] ?? '') === 'hooks' && trim((string) ($input['hook'] ?? '')) === '') {
        return wpultra_err('missing_hook', "hook is required when what='hooks'.");
    }
    return wpultra_devtools_list_registry($input);
}
