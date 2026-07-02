<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/site-snapshot', [
    'label'       => __('Site Snapshot', 'wp-ultra-mcp'),
    'description' => __('One-call orientation summary: site identity (name/url/wp+php version/locale/timezone/permalinks/multisite), active theme (+parent), plugins (active w/ version, inactive count), content counts per public post_type and taxonomy, users per role, menus + theme locations, and detected ecosystem plugins (page builders, SEO, field, form, i18n). Compact — no post lists. Call this FIRST to orient before other abilities.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'include' => [
                'type'  => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => ['plugins', 'themes', 'content', 'users', 'menus', 'elementor', 'woocommerce', 'seo', 'fields'],
                ],
            ],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'snapshot'  => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_site_snapshot',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_site_snapshot(array $input) {
    $sections = wpultra_snapshot_resolve_sections($input['include'] ?? null);
    $snapshot = wpultra_snapshot_build($sections);
    return wpultra_ok(['snapshot' => $snapshot]);
}
