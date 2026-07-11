<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The 'elementor' category's engine loop (bootstrap-mcp.php) does not yet know this new engine
// file's name, so require it directly here rather than depending on that list being edited.
require_once WPULTRA_DIR . 'includes/elementor/inspect.php';

wp_register_ability('wpultra/inspect-element', [
    'label'       => __('Elementor: Inspect Element (CSS Readback)', 'wp-ultra-mcp'),
    'description' => __('CSS readback without a browser: return the RESOLVED declared styles that will actually ship for one Elementor element — its own settings/atomic props, applied global classes\' props (all variants), and referenced kit variables resolved to concrete values. Flags each value as token-driven or hardcoded (hardcoded_count vs token_count) — the "why does it look almost right" signal for closing pixel gaps server-side. Read-only; not getComputedStyle.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'           => ['type' => 'integer', 'description' => 'Post whose Elementor content contains the element.'],
            'element_id'        => ['type' => 'string', 'description' => 'The Elementor element id to inspect.'],
            'resolve_variables' => ['type' => 'boolean', 'description' => 'Resolve global-*-variable refs to their concrete kit value (default true).'],
        ],
        'required'             => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'               => ['type' => 'boolean'],
            'element'               => ['type' => 'object'],
            'own_settings'          => ['type' => 'object'],
            'applied_classes'       => ['type' => 'array'],
            'variables_used'        => ['type' => 'array'],
            'compiled_css_excerpt'  => ['type' => 'string'],
            'notes'                 => ['type' => 'array'],
            'flags'                 => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_inspect_element_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_inspect_element_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $element_id = (string) ($input['element_id'] ?? '');
    $resolve_variables = array_key_exists('resolve_variables', $input) ? ($input['resolve_variables'] === true) : true;
    if ($post_id <= 0 || $element_id === '') {
        return wpultra_err('bad_input', 'post_id and element_id are required.');
    }
    return wpultra_elinspect_run($post_id, $element_id, $resolve_variables);
}
