<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-scaffold', [
    'label'       => __('Headless: Scaffold Frontend', 'wp-ultra-mcp'),
    'description' => __('Generate a complete starter frontend wired to this site\'s WPGraphQL endpoint. framework: `next` (App Router + TypeScript + SSG/ISR + draft mode + metadata + sitemap + /api/revalidate — the recommended default for content/SEO sites) or `vite` (React SPA + graphql-request + router — for app-like frontends). Returns a file manifest [{path, content}] for the AI to WRITE INTO A SEPARATE FRONTEND REPO (this plugin\'s filesystem is jailed to WordPress), plus next_steps. Site title, URL, and GraphQL endpoint are pre-filled from the live site.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'framework' => ['type' => 'string', 'enum' => ['next', 'vite'], 'default' => 'next'],
            'name'      => ['type' => 'string', 'description' => 'npm package name for the project (default: derived from the site title).'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'framework'  => ['type' => 'string'],
            'files'      => ['type' => 'array'],
            'file_count' => ['type' => 'integer'],
            'context'    => ['type' => 'object'],
            'next_steps' => ['type' => 'array'],
            'warnings'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_scaffold_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_scaffold_cb(array $input) {
    $framework = (string) ($input['framework'] ?? 'next');
    $title     = (string) get_option('blogname', 'wordpress');
    $name      = (string) ($input['name'] ?? '');
    if ($name === '') {
        $name = sanitize_title($title . '-frontend') ?: 'wp-headless-frontend';
    }

    $detected = wpultra_headless_detect();
    $perms    = wpultra_headless_permalinks();
    $route    = (string) apply_filters('graphql_endpoint', 'graphql');
    $endpoint = $perms['pretty']
        ? trailingslashit(home_url()) . $route
        : add_query_arg('graphql', 'true', trailingslashit(home_url()));

    $ctx = [
        'endpoint'   => $endpoint,
        'site_title' => $title,
        'site_url'   => home_url(),
        'name'       => $name,
    ];
    $files = wpultra_headless_scaffold_manifest($framework, $ctx);
    if (is_string($files)) { return wpultra_err('bad_framework', $files); }

    $warnings = [];
    if (($detected['wp-graphql'] ?? null) === null) {
        $warnings[] = 'WPGraphQL is NOT active on this site — the scaffold will not fetch data until you run headless-setup.';
    }
    $cors = wpultra_headless_shape_cors(get_option('wpultra_headless_cors', []));
    $dev_origin = $framework === 'next' ? 'http://localhost:3000' : 'http://localhost:5173';
    if ($framework === 'vite' && !in_array($dev_origin, $cors['origins'], true)) {
        $warnings[] = "CORS does not allow $dev_origin yet — browser queries will fail; run headless-setup with origins:[\"$dev_origin\"]. (Next.js fetches server-side, so it does not need CORS.)";
    }

    $steps = $framework === 'next'
        ? [
            "Write every file in `files` into a NEW directory (e.g. ./$name) — paths are relative to the project root.",
            'cp .env.local.example .env.local, then set REVALIDATE_SECRET and WORDPRESS_PREVIEW_SECRET to real values.',
            'npm install',
            'npm run dev → http://localhost:3000 renders live WordPress content.',
            'Later: headless-preview wires the WP Preview button; headless-revalidate wires publish/update → /api/revalidate.',
        ]
        : [
            "Write every file in `files` into a NEW directory (e.g. ./$name) — paths are relative to the project root.",
            'cp .env.example .env',
            "Ensure CORS allows $dev_origin (headless-setup origins).",
            'npm install',
            'npm run dev → http://localhost:5173 renders live WordPress content.',
        ];

    return wpultra_ok([
        'framework'  => $framework,
        'files'      => $files,
        'file_count' => count($files),
        'context'    => $ctx,
        'next_steps' => $steps,
        'warnings'   => $warnings,
    ]);
}
