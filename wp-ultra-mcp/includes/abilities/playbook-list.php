<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/playbook-list', [
    'label'       => __('List / Get Playbooks', 'wp-ultra-mcp'),
    'description' => __('List saved playbooks (slug, name, description, step count), or pass a `slug` to return that playbook\'s full document for review or editing.', 'wp-ultra-mcp'),
    'category'    => 'playbooks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['slug' => ['type' => 'string']],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'playbooks' => ['type' => 'array'],
            'document'  => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_playbook_list_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_playbook_list_cb(array $input) {
    if (!empty($input['slug'])) {
        $doc = wpultra_playbook_load((string) $input['slug']);
        if ($doc === null) { return wpultra_err('not_found', "No saved playbook '{$input['slug']}'."); }
        return wpultra_ok(['document' => $doc]);
    }
    return wpultra_ok(['playbooks' => wpultra_playbook_list()]);
}
