<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/conflict-bisect', [
    'label'       => __('Conflict Bisect', 'wp-ultra-mcp'),
    'description' => __('Automated plugin-conflict hunter: snapshots the active plugin set, deactivates everything except WP-Ultra-MCP itself, then binary-searches by re-enabling halves while probing the site over HTTP, to isolate the single plugin (or the theme/core) causing a fatal error or 5xx response. wp-ultra-mcp is in every subset tested and is NEVER deactivated. Plugins are toggled by writing the active_plugins option directly (no activation/deactivation hooks fire). The original active_plugins (and theme, if theme_check ran) are ALWAYS restored, even on a crash or timeout mid-run, and restoration is verified by re-reading the option. Pass confirm:true to run (this temporarily deactivates live plugins). Optional theme_check:true also probes with a default twenty* theme once the culprit is narrowed to "theme or core". KNOWN CAVEAT: on single-PHP-worker dev hosts (e.g. Local by Flywheel) a loopback probe issued from inside this same request can deadlock the one worker; run this against a URL served by a multi-worker host and keep max_probes low if only a single-worker host is available.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'url'         => ['type' => 'string'],
            'confirm'     => ['type' => 'boolean'],
            'theme_check' => ['type' => 'boolean', 'default' => false],
            'max_probes'  => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 25],
        ],
        'required'             => ['confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'verdict'     => ['type' => 'string', 'enum' => ['plugin', 'theme_or_core', 'healthy', 'inconclusive']],
            'culprit'     => ['type' => ['string', 'null']],
            'steps'       => ['type' => 'array'],
            'probes_used' => ['type' => 'integer'],
            'restored'    => ['type' => 'boolean'],
            'note'        => ['type' => 'string'],
        ],
        'required' => ['success', 'verdict', 'steps', 'probes_used', 'restored'],
    ],
    'execute_callback'    => 'wpultra_conflict_bisect_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_conflict_bisect_cb(array $input) {
    return wpultra_bisect_run($input);
}
