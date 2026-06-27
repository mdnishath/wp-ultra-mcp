<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once WPULTRA_DIR . 'includes/memory/cpt.php';

wp_register_ability('wpultra/memory-get', [
    'label'       => __('Get Memory', 'wp-ultra-mcp'),
    'description' => __('Retrieve a single persistent memory entry by ID.', 'wp-ultra-mcp'),
    'category'    => 'memory',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'id'          => ['type' => 'integer'],
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'type'        => ['type' => 'string'],
            'content'     => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_memory_get',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_memory_get(array $input) {
    $id = (int) ($input['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'wpultra_memory') { return wpultra_err('not_found', "No memory $id."); }
    return wpultra_ok(wpultra_memory_shape($p) + ['content' => $p->post_content]);
}
