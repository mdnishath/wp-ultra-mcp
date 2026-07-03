<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/jetengine-manage-meta-box', [
    'label'       => __('JetEngine: Manage Meta Box', 'wp-ultra-mcp'),
    'description' => __('Manage standalone JetEngine meta boxes (option jet_engine_meta_boxes) — field groups attached to existing post types without touching the CPT row. actions: list; get (id); create (title + allowed_post_type: array of post-type slugs + meta_fields — same field shape as jetengine-manage-cpt); update (id — replaces title/allowed_post_type/meta_fields when provided); delete (id, confirm). Field VALUES are plain postmeta — read/write them with get-post / update-post meta.', 'wp-ultra-mcp'),
    'category'    => 'jetengine',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'            => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete']],
            'id'                => ['type' => 'string'],
            'title'             => ['type' => 'string'],
            'allowed_post_type' => ['type' => 'array'],
            'meta_fields'       => ['type' => 'array'],
            'confirm'           => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'meta_boxes' => ['type' => 'array'],
            'meta_box'   => ['type' => 'object'],
            'id'         => ['type' => 'string'],
            'deleted'    => ['type' => 'boolean'],
            'note'       => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_jetengine_manage_meta_box_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_jetengine_manage_meta_box_cb(array $input) {
    $pro = wpultra_je_require();
    if (is_wp_error($pro)) { return $pro; }
    $action = (string) ($input['action'] ?? 'list');
    $boxes = wpultra_je_meta_boxes();
    $note = 'Changes apply on the NEXT request.';

    if ($action === 'list') {
        return wpultra_ok(['meta_boxes' => array_map(static fn($b) => [
            'id'                => (string) ($b['id'] ?? ''),
            'title'             => (string) ($b['args']['name'] ?? $b['args']['title'] ?? ''),
            'allowed_post_type' => (array) ($b['args']['allowed_post_type'] ?? []),
            'fields'            => array_values(array_map(static fn($f) => (string) ($f['name'] ?? ''), (array) ($b['meta_fields'] ?? []))),
        ], $boxes)]);
    }

    if ($action === 'create') {
        $title = (string) ($input['title'] ?? '');
        if ($title === '') { return wpultra_err('missing_title', 'title is required.'); }
        $fields = wpultra_je_normalize_fields($input['meta_fields'] ?? []);
        if (is_string($fields)) { return wpultra_err('bad_fields', $fields); }
        if ($fields === []) { return wpultra_err('missing_fields', 'meta_fields is required for a meta box.'); }
        $id = wpultra_je_next_meta_box_id($boxes);
        $boxes[] = [
            'id'   => $id,
            'args' => [
                'name'              => $title,
                'object_type'       => 'post',
                'allowed_post_type' => array_values(array_map('sanitize_key', (array) ($input['allowed_post_type'] ?? ['post']))),
            ],
            'meta_fields' => $fields,
        ];
        wpultra_je_meta_boxes_save($boxes);
        wpultra_audit_log('jetengine-manage-meta-box', "created meta box $id '$title'", true);
        return wpultra_ok(['id' => $id, 'note' => $note]);
    }

    $id = (string) ($input['id'] ?? '');
    if ($id === '') { return wpultra_err('missing_id', 'id is required.'); }
    $found = null;
    foreach ($boxes as $i => $b) {
        if ((string) ($b['id'] ?? '') === $id) { $found = $i; break; }
    }

    if ($action === 'get') {
        if ($found === null) { return wpultra_err('not_found', "No meta box '$id'."); }
        return wpultra_ok(['meta_box' => $boxes[$found]]);
    }

    if ($action === 'update') {
        if ($found === null) { return wpultra_err('not_found', "No meta box '$id'."); }
        if (isset($input['title'])) { $boxes[$found]['args']['name'] = (string) $input['title']; }
        if (isset($input['allowed_post_type'])) { $boxes[$found]['args']['allowed_post_type'] = array_values(array_map('sanitize_key', (array) $input['allowed_post_type'])); }
        if (array_key_exists('meta_fields', $input)) {
            $fields = wpultra_je_normalize_fields($input['meta_fields']);
            if (is_string($fields)) { return wpultra_err('bad_fields', $fields); }
            $boxes[$found]['meta_fields'] = $fields;
        }
        wpultra_je_meta_boxes_save($boxes);
        wpultra_audit_log('jetengine-manage-meta-box', "updated meta box $id", true);
        return wpultra_ok(['id' => $id, 'note' => $note]);
    }

    if ($action === 'delete') {
        if (($input['confirm'] ?? false) !== true) { return wpultra_err('confirm_required', 'Deleting a meta box requires confirm: true.'); }
        if ($found === null) { return wpultra_ok(['deleted' => false]); }
        unset($boxes[$found]);
        wpultra_je_meta_boxes_save($boxes);
        wpultra_audit_log('jetengine-manage-meta-box', "deleted meta box $id", true);
        return wpultra_ok(['deleted' => true, 'note' => $note]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
