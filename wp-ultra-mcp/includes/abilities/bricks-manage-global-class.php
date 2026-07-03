<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-manage-global-class', [
    'label'       => __('Bricks: Manage Global Classes', 'wp-ultra-mcp'),
    'description' => __('Bricks global CSS classes (option bricks_global_classes). actions: list; upsert (name + settings = Bricks style controls map, optional id to update); delete (id, confirm); apply / remove (post_id + element_id + class_id — toggles the class id in the element\'s _cssGlobalClasses setting). Reusable styling the Bricks way, mirroring Elementor\'s global-class arc.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['list', 'upsert', 'delete', 'apply', 'remove']],
            'name'       => ['type' => 'string'],
            'settings'   => ['type' => 'object'],
            'class_id'   => ['type' => 'string'],
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'confirm'    => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'classes' => ['type' => 'array'],
            'id'      => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
            'applied' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_manage_global_class_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_bricks_manage_global_class_cb(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $rows = array_map(static fn($c) => [
            'id'   => (string) ($c['id'] ?? ''),
            'name' => (string) ($c['name'] ?? ''),
            'settings_keys' => array_keys((array) ($c['settings'] ?? [])),
        ], wpultra_bricks_classes());
        return wpultra_ok(['classes' => $rows]);
    }

    if ($action === 'upsert') {
        $res = wpultra_bricks_class_upsert((string) ($input['name'] ?? ''), (array) ($input['settings'] ?? []), isset($input['class_id']) ? (string) $input['class_id'] : null);
        if (is_wp_error($res)) { return $res; }
        wpultra_audit_log('bricks-manage-global-class', "upsert class {$res['id']}", true);
        return wpultra_ok($res);
    }

    if ($action === 'delete') {
        if (($input['confirm'] ?? false) !== true) { return wpultra_err('confirm_required', 'Deleting a class requires confirm: true.'); }
        $ok = wpultra_bricks_class_delete((string) ($input['class_id'] ?? ''));
        wpultra_audit_log('bricks-manage-global-class', "delete class {$input['class_id']}", $ok);
        return wpultra_ok(['deleted' => $ok]);
    }

    if ($action === 'apply' || $action === 'remove') {
        $class_id = (string) ($input['class_id'] ?? '');
        if ($class_id === '') { return wpultra_err('missing_class_id', 'class_id is required.'); }
        $known = array_map(static fn($c) => (string) ($c['id'] ?? ''), wpultra_bricks_classes());
        if ($action === 'apply' && !in_array($class_id, $known, true)) {
            return wpultra_err('unknown_class', "No global class '$class_id' — upsert it first.");
        }
        $applied = [];
        $res = wpultra_bricks_mutate((int) ($input['post_id'] ?? 0), function (array $elements) use ($input, $class_id, $action, &$applied) {
            $idx = wpultra_bricks_index($elements);
            $eid = (string) ($input['element_id'] ?? '');
            if (!isset($idx[$eid])) { return "Element $eid not found."; }
            $cur = array_map('strval', (array) ($elements[$idx[$eid]]['settings']['_cssGlobalClasses'] ?? []));
            $cur = array_values(array_filter($cur, static fn($c) => $c !== $class_id));
            if ($action === 'apply') { $cur[] = $class_id; }
            $elements[$idx[$eid]]['settings']['_cssGlobalClasses'] = $cur;
            $applied = $cur;
            return $elements;
        });
        if (is_wp_error($res)) { return $res; }
        wpultra_audit_log('bricks-manage-global-class', "$action $class_id on {$input['element_id']}", true);
        return wpultra_ok(['applied' => $applied]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
