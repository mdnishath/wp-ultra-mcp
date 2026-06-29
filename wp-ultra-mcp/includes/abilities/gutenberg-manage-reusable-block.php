<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/gutenberg-manage-reusable-block', [
    'label'       => __('Gutenberg: Manage Reusable Block', 'wp-ultra-mcp'),
    'description' => __('Create/update/get/list synced (reusable) blocks (the wp_block CPT). Reference one in a post by inserting a core/block block: markup "<!-- wp:block {\\"ref\\":<id>} /-->".', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['create', 'update', 'get', 'list']],
            'id'      => ['type' => 'integer'],
            'title'   => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'search'  => ['type' => 'string'],
        ],
        'required'   => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'count' => ['type' => 'integer'], 'blocks' => ['type' => 'array']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_gb_manage_reusable_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_gb_manage_reusable_cb(array $input) {
    $action = (string) ($input['action'] ?? '');
    if ($action === 'list') {
        $list = wpultra_gb_reusable_list((string) ($input['search'] ?? ''));
        return wpultra_ok(['count' => count($list), 'blocks' => $list]);
    }
    if ($action === 'get') {
        $r = wpultra_gb_reusable_get((int) ($input['id'] ?? 0));
        return is_wp_error($r) ? $r : wpultra_ok($r);
    }
    if ($action === 'create' || $action === 'update') {
        $args = ['title' => (string) ($input['title'] ?? '')];
        if (isset($input['content'])) { $args['content'] = (string) $input['content']; }
        if ($action === 'update') { $args['id'] = (int) ($input['id'] ?? 0); }
        $r = wpultra_gb_reusable_save($args);
        if (is_wp_error($r)) { return $r; }
        wpultra_audit_log('gutenberg-manage-reusable-block', "$action reusable block {$r['id']}", true);
        return wpultra_ok($r);
    }
    return wpultra_err('bad_action', 'action must be one of: create, update, get, list.');
}
