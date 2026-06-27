<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once WPULTRA_DIR . 'includes/memory/cpt.php';

wp_register_ability('wpultra/memory-delete', [
    'label'       => __('Delete Memory', 'wp-ultra-mcp'),
    'description' => __('Permanently delete a persistent memory entry. Idempotent — returns deleted:false if not found.', 'wp-ultra-mcp'),
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
            'success' => ['type' => 'boolean'],
            'id'      => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_memory_delete',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

function wpultra_memory_delete(array $input) {
    $id = (int) ($input['id'] ?? 0);
    $p = get_post($id);
    if (!$p || $p->post_type !== 'wpultra_memory') { return wpultra_ok(['id' => $id, 'deleted' => false]); }
    wp_delete_post($id, true);
    return wpultra_ok(['id' => $id, 'deleted' => true]);
}
