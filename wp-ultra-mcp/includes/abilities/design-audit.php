<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The 'elementor' category's engine loop (bootstrap-mcp.php) does not yet know this new engine
// file's name, so require it directly here rather than depending on that list being edited.
require_once WPULTRA_DIR . 'includes/elementor/audit.php';

wp_register_ability('wpultra/design-audit', [
    'label'       => __('Elementor: Design Audit (Page Consistency Report)', 'wp-ultra-mcp'),
    'description' => __('Page-wide token-consistency + off-scale-spacing + contrast report for an Elementor page. Walks every element and tallies token vs hardcoded usage (overall + per color/typography/spacing category), counts distinct hardcoded colors/font-families/sizes, lists margin/padding/gap values that fall off the allowed spacing scale, and flags text/background color pairs (both hardcoded) below the WCAG AA 4.5:1 contrast ratio. The "why does it look almost right?" detector — read-only, not a getComputedStyle readback.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'       => ['type' => 'integer', 'description' => 'Post whose Elementor content should be audited.'],
            'spacing_scale' => [
                'type'  => 'array',
                'items' => ['type' => 'number'],
                'description' => 'Allowed px spacing values. Default: [0,4,8,12,16,24,32,48,64,96].',
            ],
        ],
        'required'             => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'            => ['type' => 'boolean'],
            'summary'            => ['type' => 'object'],
            'off_scale_spacing'  => ['type' => 'array'],
            'contrast_warnings'  => ['type' => 'array'],
            'recommendations'    => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_design_audit_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_design_audit_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) {
        return wpultra_err('bad_input', 'post_id is required.');
    }
    $scale = isset($input['spacing_scale']) && is_array($input['spacing_scale'])
        ? $input['spacing_scale']
        : [0, 4, 8, 12, 16, 24, 32, 48, 64, 96];
    return wpultra_audit_run($post_id, $scale);
}
