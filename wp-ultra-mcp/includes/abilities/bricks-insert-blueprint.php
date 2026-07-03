<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-insert-blueprint', [
    'label'       => __('Bricks: Insert Blueprint', 'wp-ultra-mcp'),
    'description' => __('Insert a built-in structural section skeleton (navbar | hero | feature-grid | cta | footer) into a Bricks page with fresh collision-free ids, appended at the top level. Omit `name` to list the available blueprints instead. Skeletons carry layout + placeholder text; restyle after with bricks-edit-element / global classes.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'name'    => ['type' => 'string', 'enum' => ['navbar', 'hero', 'feature-grid', 'cta', 'footer']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'blueprints'   => ['type' => 'object'],
            'inserted_ids' => ['type' => 'array'],
            'count'        => ['type' => 'integer'],
            'elements'     => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_insert_blueprint_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_bricks_insert_blueprint_cb(array $input) {
    $all = wpultra_bricks_blueprints();
    if (empty($input['name'])) {
        return wpultra_ok(['blueprints' => array_map(static fn($b) => $b['description'], $all)]);
    }
    $name = (string) $input['name'];
    if (!isset($all[$name])) { return wpultra_err('bad_blueprint', "No blueprint '$name'."); }
    if (empty($input['post_id'])) { return wpultra_err('missing_post_id', 'post_id is required to insert.'); }

    $inserted = [];
    $res = wpultra_bricks_mutate((int) $input['post_id'], function (array $elements) use ($all, $name, &$inserted) {
        $reided = wpultra_bricks_blueprint_reid($all[$name]['elements'], array_keys(wpultra_bricks_index($elements)));
        $inserted = array_map(static fn($el) => (string) $el['id'], $reided);
        return array_merge($elements, $reided);
    });
    if (is_wp_error($res)) { return $res; }
    wpultra_audit_log('bricks-insert-blueprint', "post {$input['post_id']} <- blueprint '$name' (" . count($inserted) . ' nodes)', true);
    return wpultra_ok(['inserted_ids' => $inserted] + $res);
}
