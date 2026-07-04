<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-deploy', [
    'label'       => __('Headless: Deploy Config', 'wp-ultra-mcp'),
    'description' => __('Production deployment kit for the headless frontend. provider: `vercel` or `netlify` — returns the provider config file, the exact env-var list with LIVE values (GraphQL endpoint, preview secret, revalidate secret) plus placeholders to fill, and step-by-step instructions. Pass build_hook_url (a Vercel/Netlify Deploy Hook you created in their dashboard) to also wire a WP-side trigger that kicks a FULL rebuild on publish (complementing headless-revalidate\'s instant ISR refresh). Honest: this never holds your hosting credentials.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'provider'       => ['type' => 'string', 'enum' => ['vercel', 'netlify'], 'default' => 'vercel'],
            'build_hook_url' => ['type' => 'string', 'description' => 'Optional Deploy Hook URL — wires publish → full rebuild.'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'provider'     => ['type' => 'string'],
            'files'        => ['type' => 'array'],
            'env'          => ['type' => 'array'],
            'build_hook'   => ['type' => 'object'],
            'instructions' => ['type' => 'array'],
            'warnings'     => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_deploy_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_deploy_cb(array $input) {
    $provider = (string) ($input['provider'] ?? 'vercel');
    $files = wpultra_headless_deploy_files($provider);
    if (is_string($files)) { return wpultra_err('bad_provider', $files); }

    $perms = wpultra_headless_permalinks();
    $route = (string) apply_filters('graphql_endpoint', 'graphql');
    $env = wpultra_headless_deploy_env([
        'endpoint'          => $perms['pretty'] ? trailingslashit(home_url()) . $route : add_query_arg('graphql', 'true', trailingslashit(home_url())),
        'preview_secret'    => function_exists('wpultra_headless_preview_config') ? wpultra_headless_preview_config()['secret'] : '',
        'revalidate_secret' => function_exists('wpultra_headless_reval_config') ? wpultra_headless_reval_config()['secret'] : '',
    ]);

    $build_hook = ['configured' => false];
    $hook_url = (string) ($input['build_hook_url'] ?? '');
    if ($hook_url !== '') {
        if (!function_exists('wpultra_triggers_create')) {
            return wpultra_err('triggers_disabled', 'The triggers category is disabled — the build-hook wiring rides the triggers engine.');
        }
        if (!preg_match('#^https://#i', $hook_url)) {
            return wpultra_err('bad_hook', 'build_hook_url must be an https URL from your hosting dashboard.');
        }
        $cfg = wpultra_headless_deploy_config();
        foreach ($cfg['trigger_ids'] as $old) { wpultra_triggers_delete((int) $old); }
        $def = wpultra_headless_deploy_trigger_def($hook_url);
        $check = wpultra_triggers_validate($def);
        if ($check !== true) { return wpultra_err('bad_trigger', (string) $check); }
        $id = wpultra_triggers_create($def);
        update_option('wpultra_headless_deploy', ['build_hook' => $hook_url, 'trigger_ids' => [$id]], false);
        $build_hook = ['configured' => true, 'trigger_id' => $id, 'url' => $hook_url];
    }

    $warnings = [];
    $host = parse_url(home_url(), PHP_URL_HOST) ?: '';
    if (str_ends_with((string) $host, '.local') || $host === 'localhost') {
        $warnings[] = "This WordPress runs on '$host' — hosted build servers cannot reach it. Point the endpoint env at the site's public URL before deploying.";
    }

    return wpultra_ok([
        'provider'     => $provider,
        'files'        => $files,
        'env'          => $env,
        'build_hook'   => $build_hook,
        'instructions' => [
            'Push the frontend repo to GitHub/GitLab and import it in the ' . ucfirst($provider) . ' dashboard (framework auto-detects Next.js).',
            'Write the returned config file into the repo root.',
            'Add every env var from `env` in the project settings (fill the placeholders).',
            'Optional: create a Deploy Hook in the dashboard and re-run headless-deploy with build_hook_url to rebuild on every publish.',
            'After the first deploy, re-run headless-preview and headless-revalidate with the production URL so the editor loop points at prod.',
        ],
        'warnings'     => $warnings,
    ]);
}
