<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-build-site', [
    'label'       => __('Headless: Build Full Site', 'wp-ultra-mcp'),
    'description' => __('Generate a COMPLETE Next.js frontend from this site\'s live content model: the base starter (headless-scaffold) plus a paginated /blog index, /search (GraphQL search), the theme design tokens as CSS variables (app/wp-tokens.css), and an archive + single route for EVERY GraphQL-exposed custom post type (expose CPTs first with headless-expose). Returns the full file manifest for the AI to write into the frontend repo. Next.js only — the Vite starter (headless-scaffold framework:vite) covers SPA cases.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'name'         => ['type' => 'string', 'description' => 'npm package name (default: derived from the site title).'],
            'include_base' => ['type' => 'boolean', 'default' => true, 'description' => 'false = only the extra files (when the base scaffold is already written).'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'files'      => ['type' => 'array'],
            'file_count' => ['type' => 'integer'],
            'model'      => ['type' => 'array'],
            'routes'     => ['type' => 'array'],
            'next_steps' => ['type' => 'array'],
            'warnings'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_build_site_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_build_site_cb(array $input) {
    // Live content model → prepared rows for the pure planner. Product is
    // excluded here (headless-woo owns the storefront routes).
    $rows = [];
    foreach (get_post_types(['public' => true], 'objects') as $slug => $obj) {
        if (in_array($slug, ['post', 'page', 'attachment', 'product', 'product_variation'], true)) { continue; }
        $rows[] = [
            'slug'    => (string) $slug,
            'builtin' => !empty($obj->_builtin),
            'single'  => !empty($obj->show_in_graphql) ? (string) ($obj->graphql_single_name ?? '') : '',
            'plural'  => !empty($obj->show_in_graphql) ? (string) ($obj->graphql_plural_name ?? '') : '',
        ];
    }
    $model  = wpultra_headless_build_model($rows);
    $tokens = wpultra_headless_shape_tokens(function_exists('wp_get_global_settings') ? (array) wp_get_global_settings() : []);

    $files = wpultra_headless_build_manifest($model, $tokens);
    if (($input['include_base'] ?? true) !== false) {
        $title = (string) get_option('blogname', 'wordpress');
        $name  = (string) ($input['name'] ?? '');
        if ($name === '') { $name = sanitize_title($title . '-frontend') ?: 'wp-headless-frontend'; }
        $perms = wpultra_headless_permalinks();
        $route = (string) apply_filters('graphql_endpoint', 'graphql');
        $base  = wpultra_headless_scaffold_manifest('next', [
            'endpoint'   => $perms['pretty'] ? trailingslashit(home_url()) . $route : add_query_arg('graphql', 'true', trailingslashit(home_url())),
            'site_title' => $title,
            'site_url'   => home_url(),
            'name'       => $name,
        ]);
        if (is_array($base)) {
            $extra_paths = array_column($files, 'path');
            foreach (array_reverse($base) as $f) {
                if (!in_array($f['path'], $extra_paths, true)) { array_unshift($files, $f); }
            }
        }
    }

    $warnings = [];
    if (($detected = wpultra_headless_detect())['wp-graphql'] === null) {
        $warnings[] = 'WPGraphQL is NOT active — run headless-setup first or nothing will fetch.';
    }
    $unexposed = [];
    foreach ($rows as $r) {
        if (!$r['builtin'] && $r['single'] === '') { $unexposed[] = $r['slug']; }
    }
    if ($unexposed !== []) {
        $warnings[] = 'Public types NOT in GraphQL (no routes generated): ' . implode(', ', $unexposed) . ' — expose them with headless-expose, then re-run.';
    }

    $routes = array_merge(
        ['/', '/blog', '/search', '/posts/[slug]', '/[slug]'],
        ...array_map(static fn(array $m): array => ["/{$m['route']}", "/{$m['route']}/[slug]"], $model)
    );
    return wpultra_ok([
        'files'      => $files,
        'file_count' => count($files),
        'model'      => $model,
        'routes'     => $routes,
        'next_steps' => [
            'Write every file into the frontend repo (paths relative to the project root).',
            "Import the design tokens in app/layout.tsx: import './wp-tokens.css' — then use var(--wp-color-…) in styles.",
            'npm install (first time), then npm run build / npm run dev.',
            'Wire headless-preview and headless-revalidate for the live-editing loop.',
        ],
        'warnings'   => $warnings,
    ]);
}
