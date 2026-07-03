<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/jetengine-manage-taxonomy', [
    'label'       => __('JetEngine: Manage Taxonomy', 'wp-ultra-mcp'),
    'description' => __('Manage JetEngine taxonomies (the jet_taxonomies table). actions: list; get (slug); create (slug + singular + plural + object_type: array of post-type slugs to attach to, optional args + meta_fields); update (slug — merges args/object_type, replaces meta_fields when provided); delete (slug, confirm). Registration applies on the NEXT request.', 'wp-ultra-mcp'),
    'category'    => 'jetengine',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete']],
            'slug'        => ['type' => 'string'],
            'singular'    => ['type' => 'string'],
            'plural'      => ['type' => 'string'],
            'object_type' => ['type' => 'array'],
            'args'        => ['type' => 'object'],
            'meta_fields' => ['type' => 'array'],
            'confirm'     => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'taxonomies' => ['type' => 'array'],
            'taxonomy'   => ['type' => 'object'],
            'id'         => ['type' => 'integer'],
            'deleted'    => ['type' => 'boolean'],
            'note'       => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_jetengine_manage_taxonomy_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_jetengine_manage_taxonomy_cb(array $input) {
    $pro = wpultra_je_require();
    if (is_wp_error($pro)) { return $pro; }
    $action = (string) ($input['action'] ?? 'list');
    $note = 'Changes apply on the NEXT request.';

    if ($action === 'list') {
        return wpultra_ok(['taxonomies' => array_map(static fn($r) => wpultra_je_shape_row($r), wpultra_je_rows('tax'))]);
    }

    $slug = sanitize_key((string) ($input['slug'] ?? ''));
    if ($slug === '') { return wpultra_err('missing_slug', 'slug is required.'); }
    $row = wpultra_je_row('tax', $slug);

    if ($action === 'get') {
        if (!$row) { return wpultra_err('not_found', "No JetEngine taxonomy '$slug'."); }
        return wpultra_ok(['taxonomy' => wpultra_je_shape_row($row, true)]);
    }

    if ($action === 'create') {
        if ($row) { return wpultra_err('exists', "JetEngine taxonomy '$slug' already exists — use update."); }
        $object_type = array_values(array_map('sanitize_key', (array) ($input['object_type'] ?? [])));
        if ($object_type === []) { return wpultra_err('missing_object_type', 'object_type (post types to attach to) is required.'); }
        $singular = (string) ($input['singular'] ?? ucfirst($slug));
        $plural   = (string) ($input['plural'] ?? $singular . 's');
        $fields = wpultra_je_normalize_fields($input['meta_fields'] ?? []);
        if (is_string($fields)) { return wpultra_err('bad_fields', $fields); }
        $id = wpultra_je_row_write('tax', [
            'slug'        => $slug,
            'status'      => 'publish',
            'object_type' => $object_type,
            'labels'      => wpultra_je_build_labels($singular, $plural),
            'args'        => array_merge([
                'public' => true, 'show_ui' => true, 'show_in_rest' => true,
                'hierarchical' => true, 'rewrite' => true, 'show_admin_column' => true,
            ], (array) ($input['args'] ?? [])),
            'meta_fields' => $fields,
        ]);
        if (is_wp_error($id)) { return $id; }
        wpultra_audit_log('jetengine-manage-taxonomy', "created taxonomy $slug (#$id)", true);
        return wpultra_ok(['id' => (int) $id, 'note' => $note]);
    }

    if ($action === 'update') {
        if (!$row) { return wpultra_err('not_found', "No JetEngine taxonomy '$slug'."); }
        $data = [];
        if (isset($input['singular']) || isset($input['plural'])) {
            $labels = is_array($row['labels']) ? $row['labels'] : [];
            $data['labels'] = array_merge($labels, wpultra_je_build_labels(
                (string) ($input['singular'] ?? ($labels['singular_name'] ?? $slug)),
                (string) ($input['plural'] ?? ($labels['name'] ?? $slug))
            ));
        }
        if (isset($input['object_type']) && is_array($input['object_type'])) {
            $data['object_type'] = array_values(array_map('sanitize_key', $input['object_type']));
        }
        if (isset($input['args']) && is_array($input['args'])) {
            $data['args'] = array_merge(is_array($row['args']) ? $row['args'] : [], $input['args']);
        }
        if (array_key_exists('meta_fields', $input)) {
            $fields = wpultra_je_normalize_fields($input['meta_fields']);
            if (is_string($fields)) { return wpultra_err('bad_fields', $fields); }
            $data['meta_fields'] = $fields;
        }
        if ($data === []) { return wpultra_err('nothing_to_update', 'Provide singular/plural, object_type, args, or meta_fields.'); }
        $id = wpultra_je_row_write('tax', $data, (int) $row['id']);
        if (is_wp_error($id)) { return $id; }
        wpultra_audit_log('jetengine-manage-taxonomy', "updated taxonomy $slug", true);
        return wpultra_ok(['id' => (int) $row['id'], 'note' => $note]);
    }

    if ($action === 'delete') {
        if (($input['confirm'] ?? false) !== true) { return wpultra_err('confirm_required', 'Deleting a taxonomy requires confirm: true.'); }
        if (!$row) { return wpultra_ok(['deleted' => false]); }
        if ((string) $row['status'] === 'built-in') { return wpultra_err('built_in', "'$slug' is a built-in row — edit it instead."); }
        $ok = wpultra_je_row_delete('tax', (int) $row['id']);
        wpultra_audit_log('jetengine-manage-taxonomy', "deleted taxonomy $slug", $ok);
        return wpultra_ok(['deleted' => $ok, 'note' => $note]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
