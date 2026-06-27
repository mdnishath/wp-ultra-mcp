<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/list-directory', [
    'label'       => __('List Directory', 'wp-ultra-mcp'),
    'description' => __('List the entries of a directory within the allowed base directory.', 'wp-ultra-mcp'),
    'category'    => 'filesystem',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'path'  => ['type' => 'string'],
            'limit' => ['type' => 'integer'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'path'    => ['type' => 'string'],
            'entries' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'size' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_list_directory',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_list_directory(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? '.'), true);
    if (is_wp_error($resolved)) { return $resolved; }
    if (!is_dir($resolved)) { return wpultra_err('not_a_directory', "Not a directory: $resolved"); }
    $limit = max(1, min(5000, (int) ($input['limit'] ?? 500)));
    $entries = [];
    foreach (scandir($resolved) as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $full = $resolved . '/' . $name;
        $entries[] = ['name' => $name, 'type' => is_dir($full) ? 'dir' : 'file', 'size' => is_file($full) ? filesize($full) : 0];
        if (count($entries) >= $limit) { break; }
    }
    return wpultra_ok(['path' => $resolved, 'entries' => $entries]);
}
