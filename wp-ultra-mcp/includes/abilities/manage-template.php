<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-template', [
    'label'       => __('Manage Block Template', 'wp-ultra-mcp'),
    'description' => __('List, get, create/update, delete, or reset block templates and template parts (wp_template / wp_template_part) on a block theme.', 'wp-ultra-mcp'),
    'category'    => 'fse',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'get', 'upsert', 'delete', 'reset']],
            'type'    => ['type' => 'string', 'enum' => ['wp_template', 'wp_template_part'], 'default' => 'wp_template'],
            'slug'    => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'title'   => ['type' => 'string'],
            'area'    => ['type' => 'string', 'description' => 'wp_template_part area term (e.g. header, footer, uncategorized). Only used when type=wp_template_part on upsert.'],
            'confirm' => ['type' => 'boolean', 'default' => false],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'templates' => ['type' => 'array'],
            'slug'      => ['type' => 'string'],
            'title'     => ['type' => 'string'],
            'source'    => ['type' => 'string'],
            'content'   => ['type' => 'string'],
            'post_id'   => ['type' => 'integer'],
            'type'      => ['type' => 'string'],
            'created'   => ['type' => 'boolean'],
            'deleted'   => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_template',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_manage_template(array $input) {
    $action = (string) ($input['action'] ?? '');
    $type = (string) ($input['type'] ?? 'wp_template');
    if (!in_array($action, ['list', 'get', 'upsert', 'delete', 'reset'], true)) {
        return wpultra_err('bad_action', 'action must be one of list, get, upsert, delete, reset.');
    }
    if (!in_array($type, ['wp_template', 'wp_template_part'], true)) {
        return wpultra_err('bad_type', 'type must be wp_template or wp_template_part.');
    }

    if ($action === 'list') {
        $res = wpultra_fse_template_list($type);
        if (is_wp_error($res)) { return $res; }
        return wpultra_ok($res);
    }

    $slug = trim((string) ($input['slug'] ?? ''));
    if ($slug === '') { return wpultra_err('missing_slug', 'slug is required for this action.'); }

    if ($action === 'get') {
        $res = wpultra_fse_template_get($slug, $type);
        if (is_wp_error($res)) { return $res; }
        return wpultra_ok($res);
    }

    if ($action === 'upsert') {
        $content = (string) ($input['content'] ?? '');
        $title = (string) ($input['title'] ?? '');
        $area = (string) ($input['area'] ?? '');
        $res = wpultra_fse_template_upsert($slug, $type, $content, $title, $area);
        if (is_wp_error($res)) {
            wpultra_audit_log('manage-template', "upsert $type/$slug failed: " . $res->get_error_message(), false);
            return $res;
        }
        wpultra_audit_log('manage-template', "upsert $type/$slug");
        return wpultra_ok($res);
    }

    // delete / reset both require confirm
    if (empty($input['confirm'])) {
        return wpultra_err('confirm_required', "Set confirm:true to $action template '$slug'.");
    }
    if ($action === 'delete') {
        $res = wpultra_fse_template_delete($slug, $type);
    } else {
        $res = wpultra_fse_template_reset($slug, $type);
    }
    if (is_wp_error($res)) {
        wpultra_audit_log('manage-template', "$action $type/$slug failed: " . $res->get_error_message(), false);
        return $res;
    }
    wpultra_audit_log('manage-template', "$action $type/$slug");
    return wpultra_ok($res);
}
