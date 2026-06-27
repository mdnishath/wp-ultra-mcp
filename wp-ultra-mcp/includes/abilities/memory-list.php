<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once WPULTRA_DIR . 'includes/memory/cpt.php';

wp_register_ability('wpultra/memory-list', [
    'label'       => __('List Memories', 'wp-ultra-mcp'),
    'description' => __('List all persistent memory entries, optionally filtered by type.', 'wp-ultra-mcp'),
    'category'    => 'memory',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'type' => ['type' => 'string', 'enum' => ['user', 'feedback', 'project', 'reference']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'memories' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_memory_list',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_memory_list(array $input) {
    $args = ['post_type' => 'wpultra_memory', 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC'];
    $posts = get_posts($args);
    $out = [];
    $filter = (string) ($input['type'] ?? '');
    foreach ($posts as $p) {
        $shaped = wpultra_memory_shape($p);
        if ($filter !== '' && $shaped['type'] !== $filter) { continue; }
        $out[] = $shaped;
    }
    return wpultra_ok(['memories' => $out]);
}
