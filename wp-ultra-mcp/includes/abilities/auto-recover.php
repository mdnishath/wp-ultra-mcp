<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/auto-recover', [
    'label'       => __('Auto Recover', 'wp-ultra-mcp'),
    'description' => __('Self-healing on a self-captured fatal error. `status` (read-only, default) reports whether auto-recover is armed (opted in), the recent fatal-error ring, and — for the newest fatal — the diagnosed culprit plugin (parsed from the file path), whether that plugin is currently active, and the last undo entry available; it is purely advisory and takes no action. `arm`/`disarm` (confirm-gated) toggle the opt-in. `recover` (confirm-gated, requires armed) executes one strategy: deactivate-plugin resolves the culprit plugin folder from the newest fatal and, only if it maps to a currently-active plugin, deactivates it via a direct active_plugins option write (never deactivate_plugins(), so no activation hooks fire); undo-last restores the most recent undo-stack snapshot; auto prefers deactivate-plugin when a fatal clearly implicates a specific active plugin, else falls back to undo-last. wp-ultra-mcp itself can never be selected as the culprit or deactivated. Every recover action reports how to reverse it (reactivate the plugin, or note that the undo entry was consumed with no automatic redo) and is logged to the audit log.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['status', 'recover', 'arm', 'disarm'], 'default' => 'status'],
            'strategy' => ['type' => 'string', 'enum' => ['deactivate-plugin', 'undo-last', 'auto'], 'default' => 'auto'],
            'confirm'  => ['type' => 'boolean'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'           => ['type' => 'boolean'],
            'armed'             => ['type' => 'boolean'],
            'recent_fatals'     => ['type' => 'array'],
            'newest_fatal'      => ['type' => ['object', 'null']],
            'diagnosed_culprit' => ['type' => ['string', 'null']],
            'culprit_active'    => ['type' => ['boolean', 'null']],
            'last_undo'         => ['type' => ['object', 'null']],
            'action'            => ['type' => 'string'],
            'plugin'            => ['type' => ['string', 'null']],
            'restored'          => ['type' => 'object'],
            'reverse'           => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_auto_recover_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_auto_recover_cb(array $input) {
    return wpultra_autorecover_run($input);
}
