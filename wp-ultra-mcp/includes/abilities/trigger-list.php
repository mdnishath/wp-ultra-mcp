<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/trigger-list', [
    'label'       => __('List Event Triggers', 'wp-ultra-mcp'),
    'description' => __('List registered event triggers (id, event, action_type, target, enabled). Pass events:true to instead return the catalogue of supported events and action types.', 'wp-ultra-mcp'),
    'category'    => 'triggers',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => ['events' => ['type' => 'boolean']],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'triggers' => ['type' => 'array'],
            'events'   => ['type' => 'object'],
            'actions'  => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_trigger_list_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_trigger_list_cb(array $input) {
    if (!empty($input['events'])) {
        return wpultra_ok(['events' => wpultra_triggers_supported_events(), 'actions' => wpultra_triggers_action_types()]);
    }
    $rows = array_map('wpultra_triggers_shape', wpultra_triggers_load());
    return wpultra_ok(['triggers' => array_values($rows)]);
}
