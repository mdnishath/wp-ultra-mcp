<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/jetengine-manage-cpt', [
    'label'       => __('JetEngine: Manage CPT', 'wp-ultra-mcp'),
    'description' => __('Manage JetEngine custom post types (the jet_post_types table). actions: list; get (slug → full labels/args/meta_fields); create (slug + singular + plural, optional args overrides + meta_fields: [{name, type: text|textarea|wysiwyg|number|date|switcher|select|media|gallery|repeater|..., title?, options?, width?}]); update (slug — merges args, REPLACES meta_fields when provided); delete (slug, confirm — rows with status "built-in" are protected). Registration applies on the NEXT request (JetEngine reads the table at boot).', 'wp-ultra-mcp'),
    'category'    => 'jetengine',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete']],
            'slug'        => ['type' => 'string'],
            'singular'    => ['type' => 'string'],
            'plural'      => ['type' => 'string'],
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
            'post_types' => ['type' => 'array'],
            'post_type'  => ['type' => 'object'],
            'id'         => ['type' => 'integer'],
            'deleted'    => ['type' => 'boolean'],
            'note'       => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_jetengine_manage_cpt_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_jetengine_manage_cpt_cb(array $input) {
    $pro = wpultra_je_require();
    if (is_wp_error($pro)) { return $pro; }
    $action = (string) ($input['action'] ?? 'list');
    $note = 'Changes apply on the NEXT request (JetEngine registers from the table at boot).';

    if ($action === 'list') {
        return wpultra_ok(['post_types' => array_map(static fn($r) => wpultra_je_shape_row($r), wpultra_je_rows('cpt'))]);
    }

    $slug = sanitize_key((string) ($input['slug'] ?? ''));
    if ($slug === '') { return wpultra_err('missing_slug', 'slug is required.'); }
    $row = wpultra_je_row('cpt', $slug);

    if ($action === 'get') {
        if (!$row) { return wpultra_err('not_found', "No JetEngine CPT '$slug'."); }
        return wpultra_ok(['post_type' => wpultra_je_shape_row($row, true)]);
    }

    if ($action === 'create') {
        if ($row) { return wpultra_err('exists', "JetEngine CPT '$slug' already exists — use update."); }
        if (in_array($slug, wpultra_reserved_post_types(), true)) { return wpultra_err('reserved', "Slug '$slug' is reserved."); }
        $singular = (string) ($input['singular'] ?? ucfirst($slug));
        $plural   = (string) ($input['plural'] ?? $singular . 's');
        $fields = wpultra_je_normalize_fields($input['meta_fields'] ?? []);
        if (is_string($fields)) { return wpultra_err('bad_fields', $fields); }
        $id = wpultra_je_row_write('cpt', [
            'slug'        => $slug,
            'status'      => 'publish',
            'labels'      => wpultra_je_build_labels($singular, $plural),
            'args'        => wpultra_je_default_cpt_args((array) ($input['args'] ?? [])),
            'meta_fields' => $fields,
        ]);
        if (is_wp_error($id)) { return $id; }
        wpultra_audit_log('jetengine-manage-cpt', "created CPT $slug (#$id, " . count($fields) . ' fields)', true);
        return wpultra_ok(['id' => (int) $id, 'note' => $note]);
    }

    if ($action === 'update') {
        if (!$row) { return wpultra_err('not_found', "No JetEngine CPT '$slug'."); }
        $data = [];
        if (isset($input['singular']) || isset($input['plural'])) {
            $labels = is_array($row['labels']) ? $row['labels'] : [];
            $data['labels'] = array_merge($labels, wpultra_je_build_labels(
                (string) ($input['singular'] ?? ($labels['singular_name'] ?? $slug)),
                (string) ($input['plural'] ?? ($labels['name'] ?? $slug))
            ));
        }
        if (isset($input['args']) && is_array($input['args'])) {
            $data['args'] = array_merge(is_array($row['args']) ? $row['args'] : [], $input['args']);
        }
        if (array_key_exists('meta_fields', $input)) {
            $fields = wpultra_je_normalize_fields($input['meta_fields']);
            if (is_string($fields)) { return wpultra_err('bad_fields', $fields); }
            $data['meta_fields'] = $fields;
        }
        if ($data === []) { return wpultra_err('nothing_to_update', 'Provide singular/plural, args, or meta_fields.'); }
        $id = wpultra_je_row_write('cpt', $data, (int) $row['id']);
        if (is_wp_error($id)) { return $id; }
        wpultra_audit_log('jetengine-manage-cpt', "updated CPT $slug", true);
        return wpultra_ok(['id' => (int) $row['id'], 'note' => $note]);
    }

    if ($action === 'delete') {
        if (($input['confirm'] ?? false) !== true) { return wpultra_err('confirm_required', 'Deleting a CPT requires confirm: true (its posts stay in the DB but become orphaned).'); }
        if (!$row) { return wpultra_ok(['deleted' => false]); }
        if ((string) $row['status'] === 'built-in') { return wpultra_err('built_in', "'$slug' is a built-in row (core type meta) — edit it instead of deleting."); }
        $ok = wpultra_je_row_delete('cpt', (int) $row['id']);
        wpultra_audit_log('jetengine-manage-cpt', "deleted CPT $slug", $ok);
        return wpultra_ok(['deleted' => $ok, 'note' => $note]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
