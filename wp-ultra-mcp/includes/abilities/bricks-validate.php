<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/bricks-validate', [
    'label'       => __('Bricks: Validate Tree', 'wp-ultra-mcp'),
    'description' => __('Dry-run validate a Bricks flat elements array (supplied via `elements`, or a post\'s current content via `post_id`): foundation checks (ids present/unique, parent refs exist) PLUS deep parent↔children two-way consistency, and — on a live Bricks install — unknown element names vs the real registry. Fix everything it reports before bricks-set-content.', 'wp-ultra-mcp'),
    'category'    => 'bricks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'  => ['type' => 'integer'],
            'elements' => ['type' => 'array'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'         => ['type' => 'boolean'],
            'ok'              => ['type' => 'boolean'],
            'errors'          => ['type' => 'array'],
            'unknown_elements' => ['type' => 'array'],
            'count'           => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_bricks_validate_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_bricks_validate_cb(array $input) {
    if (isset($input['elements']) && is_array($input['elements'])) {
        $elements = $input['elements'];
    } elseif (!empty($input['post_id'])) {
        if (!get_post((int) $input['post_id'])) { return wpultra_err('not_found', 'No such post.'); }
        $elements = wpultra_bricks_raw((int) $input['post_id']);
    } else {
        return wpultra_err('missing_input', 'Provide elements or post_id.');
    }

    $errors = [];
    $report = wpultra_bricks_validate_tree($elements);
    foreach ((array) ($report['errors'] ?? []) as $e) { $errors[] = (string) $e; }
    $consistent = wpultra_bricks_consistency($elements);
    if ($consistent !== true) { $errors[] = (string) $consistent; }

    // Registry check (live Bricks only).
    $unknown = [];
    try {
        if (class_exists('\\Bricks\\Elements') && is_array(\Bricks\Elements::$elements ?? null) && \Bricks\Elements::$elements !== []) {
            $known = array_fill_keys(array_keys(\Bricks\Elements::$elements), true);
            foreach ($elements as $el) {
                $n = (string) ($el['name'] ?? '');
                if ($n !== '' && !isset($known[$n]) && !in_array($n, $unknown, true)) { $unknown[] = $n; }
            }
        }
    } catch (\Throwable $e) {
        // registry unavailable — structural checks still stand
    }

    return wpultra_ok([
        'ok'               => $errors === [] && $unknown === [],
        'errors'           => $errors,
        'unknown_elements' => $unknown,
        'count'            => count($elements),
    ]);
}
