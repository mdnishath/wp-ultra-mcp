<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/playbook-delete', [
    'label'       => __('Delete Playbook', 'wp-ultra-mcp'),
    'description' => __('Delete a saved playbook by slug. Idempotent — returns deleted:false if no such playbook exists.', 'wp-ultra-mcp'),
    'category'    => 'playbooks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['slug' => ['type' => 'string']],
        'required'             => ['slug'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'slug'    => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_playbook_delete_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

function wpultra_playbook_delete_cb(array $input) {
    $slug = sanitize_title((string) ($input['slug'] ?? ''));
    if ($slug === '') { return wpultra_err('bad_slug', 'A valid slug is required.'); }
    $deleted = wpultra_playbook_delete($slug);
    if ($deleted) { wpultra_audit_log('playbook-delete', "deleted playbook $slug", true); }
    return wpultra_ok(['slug' => $slug, 'deleted' => $deleted]);
}
