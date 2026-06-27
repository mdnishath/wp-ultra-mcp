<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/memory-save', [
    'label'       => __('Save Memory', 'wp-ultra-mcp'),
    'description' => __('Create or update a persistent memory entry.', 'wp-ultra-mcp'),
    'category'    => 'memory',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'id'          => ['type' => 'integer'],
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'content'     => ['type' => 'string'],
            'type'        => ['type' => 'string', 'enum' => ['user', 'feedback', 'project', 'reference']],
        ],
        'required'             => ['name', 'content', 'type'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_memory_save',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_memory_save(array $input) {
    $type = (string) ($input['type'] ?? '');
    if (!in_array($type, ['user', 'feedback', 'project', 'reference'], true)) {
        return wpultra_err('bad_type', "type must be one of user|feedback|project|reference.");
    }
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') { return wpultra_err('missing_name', 'name is required.'); }
    $postarr = [
        'post_type' => 'wpultra_memory', 'post_status' => 'publish', 'post_title' => $name,
        'post_excerpt' => (string) ($input['description'] ?? ''), 'post_content' => (string) ($input['content'] ?? ''),
    ];
    if (!empty($input['id'])) { $postarr['ID'] = (int) $input['id']; }
    $id = wp_insert_post($postarr, true);
    if (is_wp_error($id)) { return $id; }
    update_post_meta((int) $id, '_wpultra_memory_type', $type);
    return wpultra_ok(['id' => (int) $id]);
}
