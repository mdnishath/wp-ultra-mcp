<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — deployment config generator (Roadmap-3, H3.4).
 *
 * Honest scope: emits provider config + the exact env-var list (values pulled
 * from the live headless config) + wires a WP-side deploy-hook trigger so
 * publishing kicks a full rebuild. It never holds deploy credentials — the
 * user creates the project/hook in the Vercel/Netlify dashboard.
 */

/**
 * Provider config files. Pure. @return array<int,array{path:string,content:string}>|string
 */
function wpultra_headless_deploy_files(string $provider) {
    if ($provider === 'vercel') {
        return [[
            'path' => 'vercel.json',
            'content' => <<<'EOT'
{
  "$schema": "https://openapi.vercel.sh/vercel.json",
  "framework": "nextjs",
  "headers": [
    {
      "source": "/api/revalidate",
      "headers": [{ "key": "Cache-Control", "value": "no-store" }]
    }
  ]
}
EOT,
        ]];
    }
    if ($provider === 'netlify') {
        return [[
            'path' => 'netlify.toml',
            'content' => <<<'EOT'
[build]
  command = "npm run build"
  publish = ".next"

[[plugins]]
  package = "@netlify/plugin-nextjs"
EOT,
        ]];
    }
    return "Unknown provider '$provider' — supported: vercel, netlify.";
}

/**
 * The env-var list for the hosting dashboard. Pure over live config values.
 * @param array{endpoint:string,preview_secret:string,revalidate_secret:string} $ctx
 * @return array<int,array{key:string,value:string,note:string}>
 */
function wpultra_headless_deploy_env(array $ctx): array {
    return [
        [
            'key'   => 'NEXT_PUBLIC_WORDPRESS_GRAPHQL_ENDPOINT',
            'value' => (string) ($ctx['endpoint'] ?? ''),
            'note'  => 'The WordPress GraphQL endpoint — must be reachable from the host\'s build servers (a public URL, not .local).',
        ],
        [
            'key'   => 'NEXT_PUBLIC_SITE_URL',
            'value' => 'https://<your-production-domain>',
            'note'  => 'The frontend\'s own production URL (sitemap + robots use it).',
        ],
        [
            'key'   => 'WORDPRESS_PREVIEW_SECRET',
            'value' => (string) ($ctx['preview_secret'] ?? ''),
            'note'  => 'Matches the WP-side headless-preview secret so the editor Preview button works in production.',
        ],
        [
            'key'   => 'REVALIDATE_SECRET',
            'value' => (string) ($ctx['revalidate_secret'] ?? ''),
            'note'  => 'Matches the WP-side headless-revalidate secret so publish/update refreshes ISR.',
        ],
        [
            'key'   => 'WORDPRESS_AUTH',
            'value' => 'Basic <base64 user:application-password>',
            'note'  => 'Server-side auth for draft preview fetches — mint with headless-auth create-app-password.',
        ],
    ];
}

/** The full-rebuild trigger def for a provider deploy hook. Pure. */
function wpultra_headless_deploy_trigger_def(string $build_hook_url): array {
    return [
        'event'       => 'post_published',
        'action_type' => 'webhook',
        'url'         => $build_hook_url,
        'label'       => 'headless-deploy:build-hook',
        // Deploy hooks ignore the body; send minimal context anyway for logs.
        'template'    => ['event' => '{event}', 'post_id' => '{data.post_id}'],
    ];
}

/** Stored deploy config (trigger ids so re-runs replace, not duplicate). */
function wpultra_headless_deploy_config(): array {
    $v = function_exists('get_option') ? get_option('wpultra_headless_deploy', []) : [];
    return is_array($v) ? array_merge(['build_hook' => '', 'trigger_ids' => []], $v) : ['build_hook' => '', 'trigger_ids' => []];
}
