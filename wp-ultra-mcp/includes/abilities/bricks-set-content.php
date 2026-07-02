<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-set-content', [
    'label'       => __('Bricks: Set Content', 'wp-ultra-mcp'),
    'description' => __('Overwrite the entire Bricks flat element array of a post. Validates every element has id+name and that parent references resolve before writing.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'  => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
            'confirm'  => ['type' => 'boolean'],
        ],
        'required'             => ['post_id', 'elements', 'confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'post_id'       => ['type' => 'integer'],
            'element_count' => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_set_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_bricks_set_content(array $input) {
    if (!wpultra_bricks_active()) { return wpultra_err('bricks_unavailable', 'Bricks is not installed/active on this site.'); }
    $post_id = (int) ($input['post_id'] ?? 0);
    $elements = $input['elements'] ?? null;
    if (is_string($elements)) { $elements = json_decode($elements, true); }
    if (!is_array($elements)) { return wpultra_err('bad_elements', 'elements must be an array (or JSON string).'); }
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'Pass confirm:true to overwrite the Bricks content of this post.');
    }
    $report = wpultra_bricks_validate_tree($elements);
    if (!$report['ok']) {
        return wpultra_err('tree_invalid', count($report['errors']) . ' element(s) failed validation.', ['errors' => $report['errors']]);
    }
    $res = wpultra_bricks_write($post_id, $elements);
    if (is_wp_error($res)) {
        wpultra_audit_log('bricks-set-content', "post {$post_id}: write failed", false);
        return $res;
    }
    wpultra_audit_log('bricks-set-content', "post {$post_id}: wrote {$report['count']} element(s)", true);
    return $res;
}
