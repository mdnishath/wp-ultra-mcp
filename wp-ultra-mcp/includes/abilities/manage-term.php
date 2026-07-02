<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-term', [
    'label'       => __('Manage Term', 'wp-ultra-mcp'),
    'description' => __('List, create, update, or delete taxonomy terms (categories, tags, or any custom taxonomy).', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'create', 'update', 'delete']],
            'taxonomy'    => ['type' => 'string'],
            'term_id'     => ['type' => 'integer'],
            'name'        => ['type' => 'string'],
            'slug'        => ['type' => 'string'],
            'parent'      => ['type' => 'integer'],
            'description' => ['type' => 'string'],
            'meta'        => ['type' => 'object'],
            'search'      => ['type' => 'string'],
            'hide_empty'  => ['type' => 'boolean'],
            'confirm'     => ['type' => 'boolean'],
        ],
        'required'             => ['action', 'taxonomy'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'terms'   => ['type' => 'array'],
            'term'    => ['type' => 'object'],
            'term_id' => ['type' => 'integer'],
            'deleted' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_term',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_manage_term(array $input) {
    $action = (string) ($input['action'] ?? '');
    $taxonomy = (string) ($input['taxonomy'] ?? '');
    if ($taxonomy === '') { return wpultra_err('missing_taxonomy', 'taxonomy is required.'); }

    switch ($action) {
        case 'list':
            $result = wpultra_structure_term_list($taxonomy, $input);
            if (is_wp_error($result)) { return $result; }
            return wpultra_ok($result);

        case 'create':
            $result = wpultra_structure_term_create($taxonomy, $input);
            if (is_wp_error($result)) { wpultra_audit_log('manage-term', "create failed in $taxonomy", false); return $result; }
            wpultra_audit_log('manage-term', "created term '{$input['name']}' in $taxonomy");
            return wpultra_ok($result);

        case 'update':
            $term_id = (int) ($input['term_id'] ?? 0);
            if ($term_id <= 0) { return wpultra_err('missing_term_id', 'term_id is required to update a term.'); }
            $result = wpultra_structure_term_update($taxonomy, $term_id, $input);
            if (is_wp_error($result)) { wpultra_audit_log('manage-term', "update failed for term $term_id", false); return $result; }
            wpultra_audit_log('manage-term', "updated term $term_id in $taxonomy");
            return wpultra_ok($result);

        case 'delete':
            $term_id = (int) ($input['term_id'] ?? 0);
            if ($term_id <= 0) { return wpultra_err('missing_term_id', 'term_id is required to delete a term.'); }
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('confirm_required', 'Deleting a term requires confirm: true.');
            }
            $result = wpultra_structure_term_delete($taxonomy, $term_id);
            if (is_wp_error($result)) { wpultra_audit_log('manage-term', "delete failed for term $term_id", false); return $result; }
            wpultra_audit_log('manage-term', "deleted term $term_id from $taxonomy");
            return wpultra_ok($result);

        default:
            return wpultra_err('invalid_action', "Unknown action '$action'.");
    }
}
