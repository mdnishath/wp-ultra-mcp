<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-manage-library', [
    'label'       => __('Elementor Pro: Manage Theme-Builder Library', 'wp-ultra-mcp'),
    'description' => __('Manage Elementor Pro library templates (theme builder). actions: list (optionally by type), get (template + conditions + compact element tree), create (title + type: header|footer|single|single-page|single-post|archive|popup|section|container|page|loop-item|error-404, optional elements tree — same format as elementor-set-content, validated), delete (confirm), get-conditions, set-conditions (conditions: array of strings like "include/general", "include/singular/page/12", "exclude/archive/category" — Pro\'s native format; flushes the conditions cache). A header/footer with include/general renders site-wide immediately.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'delete', 'get-conditions', 'set-conditions']],
            'template_id' => ['type' => 'integer'],
            'type'       => ['type' => 'string'],
            'title'      => ['type' => 'string'],
            'elements'   => ['type' => 'array'],
            'conditions' => ['type' => 'array'],
            'confirm'    => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'templates'  => ['type' => 'array'],
            'template'   => ['type' => 'object'],
            'template_id' => ['type' => 'integer'],
            'conditions' => ['type' => 'array'],
            'deleted'    => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_manage_library_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_manage_library_cb(array $input) {
    $pro = wpultra_epro_require();
    if (is_wp_error($pro)) { return $pro; }
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        return wpultra_ok(['templates' => wpultra_epro_templates((string) ($input['type'] ?? ''))]);
    }

    $tid = (int) ($input['template_id'] ?? 0);
    if (in_array($action, ['get', 'delete', 'get-conditions', 'set-conditions'], true)) {
        $post = get_post($tid);
        if (!$post || $post->post_type !== 'elementor_library') {
            return wpultra_err('not_found', "No library template with id $tid.");
        }
    }

    if ($action === 'get') {
        return wpultra_ok(['template' => [
            'id'         => $tid,
            'title'      => get_the_title($tid),
            'type'       => (string) get_post_meta($tid, '_elementor_template_type', true),
            'status'     => (string) get_post_status($tid),
            'conditions' => (array) get_post_meta($tid, '_elementor_conditions', true),
            'elements'   => function_exists('wpultra_el_compact_tree') ? wpultra_el_compact_tree(wpultra_el_raw($tid)) : [],
        ]]);
    }

    if ($action === 'get-conditions') {
        return wpultra_ok(['conditions' => (array) get_post_meta($tid, '_elementor_conditions', true)]);
    }

    if ($action === 'set-conditions') {
        $conds = array_values(array_map('strval', (array) ($input['conditions'] ?? [])));
        foreach ($conds as $c) {
            $v = wpultra_epro_validate_condition($c);
            if ($v !== true) { return wpultra_err('bad_condition', (string) $v); }
        }
        update_post_meta($tid, '_elementor_conditions', $conds);
        wpultra_epro_flush_conditions();
        wpultra_audit_log('elementor-manage-library', "template $tid conditions = " . implode(',', $conds), true);
        return wpultra_ok(['template_id' => $tid, 'conditions' => $conds]);
    }

    if ($action === 'create') {
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, wpultra_epro_template_types(), true)) {
            return wpultra_err('bad_type', "type must be one of: " . implode(', ', wpultra_epro_template_types()) . '.');
        }
        $title = sanitize_text_field((string) ($input['title'] ?? ucfirst($type)));
        $tid = (int) wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'elementor_library',
            'post_status' => 'publish',
        ], true);
        if ($tid <= 0) { return wpultra_err('create_failed', 'Could not create the template.'); }
        update_post_meta($tid, '_elementor_template_type', $type);
        update_post_meta($tid, '_elementor_edit_mode', 'builder');
        if (!empty($input['elements']) && is_array($input['elements']) && function_exists('wpultra_el_write')) {
            if (function_exists('wpultra_el_validate_tree')) {
                $report = wpultra_el_validate_tree($input['elements']);
                if ($report['ok']) { $input['elements'] = $report['normalized_tree']; }
            }
            $w = wpultra_el_write($tid, $input['elements']);
            if (is_wp_error($w)) { return $w; }
        }
        wpultra_epro_flush_conditions();
        wpultra_audit_log('elementor-manage-library', "created $type template $tid '$title'", true);
        return wpultra_ok(['template_id' => $tid, 'template' => ['id' => $tid, 'title' => $title, 'type' => $type, 'status' => 'publish']]);
    }

    if ($action === 'delete') {
        if (($input['confirm'] ?? false) !== true) {
            return wpultra_err('confirm_required', 'Deleting a template requires confirm: true.');
        }
        $ok = (bool) wp_delete_post($tid, true);
        if ($ok) { wpultra_epro_flush_conditions(); }
        wpultra_audit_log('elementor-manage-library', "deleted template $tid", $ok);
        return wpultra_ok(['template_id' => $tid, 'deleted' => $ok]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
