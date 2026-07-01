<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-schema', [
    'label'       => __('SEO: Manage Schema', 'wp-ultra-mcp'),
    'description' => __('Attach JSON-LD structured data to a post (rendered in wp_head). action: get|set|delete. set needs post_id, type (Article|Product|FAQPage|BreadcrumbList), fields (type-specific). Additive to any SEO plugin schema.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['get', 'set', 'delete']], 'post_id' => ['type' => 'integer'], 'type' => ['type' => 'string'], 'fields' => ['type' => 'object']], 'required' => ['action', 'post_id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'schema' => ['type' => 'object'], 'preview' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_schema_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_schema_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    if (!get_post($id)) { return wpultra_err('post_not_found', "No post with id $id."); }
    $action = (string) ($input['action'] ?? 'get');
    if ($action === 'set') {
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, ['Article', 'Product', 'FAQPage', 'BreadcrumbList'], true)) { return wpultra_err('bad_type', 'type must be Article|Product|FAQPage|BreadcrumbList.'); }
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        wpultra_seo_set_schema($id, $type, $fields);
        wpultra_audit_log('seo-manage-schema', "set $type on $id", true);
        return wpultra_ok(['schema' => ['type' => $type, 'fields' => $fields], 'preview' => wpultra_seo_build_jsonld($type, $fields)]);
    }
    if ($action === 'delete') {
        delete_post_meta($id, '_wpultra_seo_schema');
        wpultra_audit_log('seo-manage-schema', "delete on $id", true);
        return wpultra_ok(['schema' => []]);
    }
    return wpultra_ok(['schema' => wpultra_seo_get_schema($id)]);
}
