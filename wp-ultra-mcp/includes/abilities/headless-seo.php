<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-seo', [
    'label'       => __('Headless: SEO Bridge', 'wp-ultra-mcp'),
    'description' => __('SEO for the headless frontend without extra addons: this plugin registers a `wpSeo` GraphQL field on every ContentNode (title, description, canonical, OG/Twitter, noindex/nofollow) resolved through the WP-Ultra SEO driver — the SAME field works with Yoast, Rank Math, or native meta. Canonicals on the WP host are rewritten to the frontend origin (headless-preview frontend_url). Returns the frontend files: lib/seo.ts (fragment + Next.js metadata mapper), a headless-aware app/robots.ts, and the posts page upgraded to use wpSeo.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'seo_mode'   => ['type' => 'string'],
            'files'      => ['type' => 'array'],
            'file_count' => ['type' => 'integer'],
            'next_steps' => ['type' => 'array'],
            'warnings'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_seo_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_seo_cb(array $input) {
    $files = wpultra_headless_seo_manifest();
    $warnings = [];
    if ((wpultra_headless_detect()['wp-graphql'] ?? null) === null) {
        $warnings[] = 'WPGraphQL is not active — the wpSeo field only exists once headless-setup has run.';
    }
    if (function_exists('wpultra_headless_preview_config') && wpultra_headless_preview_config()['frontend_url'] === '') {
        $warnings[] = 'No frontend origin configured (headless-preview) — canonicals will keep the WP host until you enable it.';
    }
    return wpultra_ok([
        'seo_mode'   => function_exists('wpultra_seo_mode') ? (string) wpultra_seo_mode() : '',
        'files'      => $files,
        'file_count' => count($files),
        'next_steps' => [
            'Write the files into the frontend repo (app/posts/[slug]/page.tsx is an upgrade of the scaffold version).',
            'Query `wpSeo { title description canonical … }` on any post/page — verify with graphql-query.',
            'Set titles/descriptions from WP as usual (seo-set-meta) — the frontend picks them up on the next revalidate.',
            'Keep the WP host out of the index (seo-manage-robots) so the frontend canonical wins.',
        ],
        'warnings'   => $warnings,
    ]);
}
