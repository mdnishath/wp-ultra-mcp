<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-preview', [
    'label'       => __('Headless: Draft Preview', 'wp-ultra-mcp'),
    'description' => __('Wire the WP editor\'s Preview button to the headless frontend (the #1 headless pain). actions: `status`, `enable` (frontend_url required; generates the preview secret and rewrites preview links to {frontend_url}{route}?secret&id&slug&type&status), `disable`, `rotate-secret`. Returns the frontend-side files too (Next.js: /api/preview + /api/exit-preview + /preview/[id] draft-mode pages; Vite: guarded /preview route) plus the env vars to set. Draft fetches need auth: create an Application Password and set WORDPRESS_AUTH on the frontend.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['status', 'enable', 'disable', 'rotate-secret'], 'default' => 'status'],
            'frontend_url' => ['type' => 'string', 'description' => 'Frontend origin, e.g. http://localhost:3000 (for action:enable).'],
            'route'        => ['type' => 'string', 'default' => '/api/preview', 'description' => 'Preview route on the frontend.'],
            'framework'    => ['type' => 'string', 'enum' => ['next', 'vite'], 'default' => 'next', 'description' => 'Which frontend files to return.'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'config'       => ['type' => 'object'],
            'files'        => ['type' => 'array'],
            'env'          => ['type' => 'object'],
            'instructions' => ['type' => 'array'],
            'example_link' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_preview_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_preview_cb(array $input) {
    $action = (string) ($input['action'] ?? 'status');
    $cfg    = wpultra_headless_preview_config();

    if ($action === 'enable') {
        $valid = wpultra_headless_preview_validate($input);
        if (is_string($valid)) { return wpultra_err('bad_input', $valid); }
        $cfg['enabled']      = true;
        $cfg['frontend_url'] = $valid['frontend_url'];
        $cfg['route']        = $valid['route'];
        if ($cfg['secret'] === '') { $cfg['secret'] = wpultra_headless_generate_secret(); }
        update_option('wpultra_headless_preview', $cfg, false);
    } elseif ($action === 'rotate-secret') {
        $cfg['secret'] = wpultra_headless_generate_secret();
        update_option('wpultra_headless_preview', $cfg, false);
    } elseif ($action === 'disable') {
        $cfg['enabled'] = false;
        update_option('wpultra_headless_preview', $cfg, false);
    } elseif ($action !== 'status') {
        return wpultra_err('bad_action', "Unknown action '$action'.");
    }

    $framework = (string) ($input['framework'] ?? 'next');
    $out = ['config' => $cfg];
    if ($cfg['enabled']) {
        $out['files'] = wpultra_headless_preview_manifest($framework, $cfg);
        $out['env']   = $framework === 'next'
            ? [
                'WORDPRESS_PREVIEW_SECRET' => $cfg['secret'],
                'WORDPRESS_AUTH'           => 'Basic <base64 of user:application-password> — create one via manage-user application passwords; used server-side only.',
            ]
            : [
                'VITE_WORDPRESS_PREVIEW_SECRET' => $cfg['secret'],
                'VITE_WORDPRESS_AUTH'           => 'Basic <base64 of user:application-password> — SPA env ships to the browser; use a low-privilege preview-only user.',
            ];
        $out['instructions'] = [
            'Write the returned files into the frontend repo (they extend the headless-scaffold starter).',
            'Set the env vars shown in `env` on the frontend, then restart it.',
            'In wp-admin, open any draft and hit Preview — it now opens the frontend draft view.',
        ];
        $out['example_link'] = wpultra_headless_preview_link($cfg, ['id' => 123, 'slug' => 'example-draft', 'type' => 'post', 'status' => 'draft']);
    }
    return wpultra_ok($out);
}
