<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/executor.php';

add_action('init', function () {
    register_post_type('wpultra_ability', [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt', 'revisions'], 'rewrite' => false,
    ]);
});

function wpultra_recipe_all(): array {
    $posts = get_posts(['post_type' => 'wpultra_ability', 'post_status' => 'publish', 'numberposts' => 500]);
    $out = [];
    foreach ($posts as $p) {
        $parsed = wpultra_recipe_parse($p->post_content);
        $out[] = [
            'slug' => $p->post_name, 'post_id' => $p->ID,
            'name' => is_wp_error($parsed) ? $p->post_name : $parsed['name'],
            'description' => $p->post_excerpt,
            'category' => is_wp_error($parsed) ? 'custom' : $parsed['category'],
            'run' => is_wp_error($parsed) ? '' : $parsed['run'],
            'raw' => $p->post_content,
        ];
    }
    return $out;
}

function wpultra_recipe_input_schema(array $input): array {
    $props = []; $required = [];
    foreach ($input as $key => $def) {
        $type = (string) ($def['type'] ?? 'string');
        $props[$key] = ['type' => in_array($type, ['string', 'integer', 'number', 'boolean', 'array', 'object'], true) ? $type : 'string'];
        if (!empty($def['required'])) { $required[] = $key; }
    }
    $schema = ['type' => 'object', 'properties' => (object) $props];
    if ($required) { $schema['required'] = $required; }
    return $schema;
}

function wpultra_recipe_register_all(): void {
    if (!function_exists('wp_register_ability')) { return; }
    foreach (wpultra_recipe_all() as $row) {
        $parsed = wpultra_recipe_parse($row['raw']);
        if (is_wp_error($parsed) || wpultra_recipe_validate($parsed) !== true) { continue; }
        $slug = $row['slug'];
        wp_register_ability('wpultra/' . $slug, [
            'label' => $parsed['name'] !== '' ? $parsed['name'] : $slug,
            'description' => $parsed['description'] !== '' ? $parsed['description'] : ('Custom ability: ' . $slug),
            'category' => 'custom',
            'input_schema' => wpultra_recipe_input_schema($parsed['input']),
            'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']], 'required' => ['success']],
            'execute_callback' => function (array $in = []) use ($parsed) { return wpultra_recipe_execute($parsed, $in); },
            'permission_callback' => 'wpultra_permission_callback',
            'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'],
                'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
        ]);
    }
}
