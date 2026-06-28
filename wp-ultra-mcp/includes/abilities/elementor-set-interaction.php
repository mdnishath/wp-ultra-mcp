<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-set-interaction', [
    'label'       => __('Elementor: Set Interaction', 'wp-ultra-mcp'),
    'description' => __('Attach an entrance/exit animation interaction to an Elementor element.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'trigger'    => ['type' => 'string', 'enum' => ['load', 'scrollIn']],
            'effect'     => ['type' => 'string', 'enum' => ['fade', 'slide', 'scale']],
            'type'       => ['type' => 'string', 'enum' => ['in', 'out']],
            'duration'   => ['type' => 'integer'],
        ],
        'required'             => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_set_interaction',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_set_interaction(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $eid = (string) ($input['element_id'] ?? '');
    if ($post_id <= 0 || $eid === '') { return wpultra_err('bad_input', 'post_id and element_id are required.'); }
    $trigger = in_array(($input['trigger'] ?? ''), ['load', 'scrollIn'], true) ? $input['trigger'] : 'scrollIn';
    $effect = in_array(($input['effect'] ?? ''), ['fade', 'slide', 'scale'], true) ? $input['effect'] : 'fade';
    $type = in_array(($input['type'] ?? ''), ['in', 'out'], true) ? $input['type'] : 'in';
    $duration = max(0, (int) ($input['duration'] ?? 600));
    $interactions = wpultra_el_fade_interaction((string) $trigger, (string) $effect, (string) $type, $duration);
    return wpultra_el_set_interaction($post_id, $eid, $interactions);
}
