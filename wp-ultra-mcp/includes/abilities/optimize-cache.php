<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/optimize-cache', [
    'label'       => __('Optimize Cache', 'wp-ultra-mcp'),
    'description' => __('Honestly-scoped cache control (no home-grown page cache). action:status reports the cache posture — detected page-cache plugin, external object cache, whether browser-caching/gzip rules are in the managed .htaccess block, and the image-lazyload flag. action:enable writes browser-caching + gzip rules via the server-rules engine, turns on the lazyload flag (adds loading="lazy" to attachment images on every request), and purges known caches; action:disable clears the managed rules block and turns lazyload off. enable/disable require confirm:true. Per-feature toggles: browser_rules, lazyload, purge (all default true). NOTE: on nginx servers the .htaccess rules have no effect — the response flags this; add the composed directives to the nginx config manually.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'        => ['type' => 'string', 'enum' => ['status', 'enable', 'disable'], 'default' => 'status'],
            'browser_rules' => ['type' => 'boolean', 'default' => true],
            'lazyload'      => ['type' => 'boolean', 'default' => true],
            'purge'         => ['type' => 'boolean', 'default' => true],
            'confirm'       => ['type' => 'boolean'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
            'status'  => ['type' => 'object'],
            'result'  => ['type' => 'object'],
            'note'    => ['type' => 'string'],
        ],
        'required' => ['success', 'action'],
    ],
    'execute_callback'    => 'wpultra_optimize_cache_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** @return array|WP_Error */
function wpultra_optimize_cache_cb(array $input) {
    $action = (string) ($input['action'] ?? 'status');

    if ($action === 'status') {
        return wpultra_ok(['action' => 'status', 'status' => wpultra_optimize_cache_status()]);
    }

    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'Cache configure writes .htaccess rules and toggles options. Re-run with confirm: true.');
    }

    $enable = $action === 'enable';
    $result = wpultra_optimize_cache_configure($enable, [
        'confirm'       => true,
        'browser_rules' => ($input['browser_rules'] ?? true) === true,
        'lazyload'      => ($input['lazyload'] ?? true) === true,
        'purge'         => ($input['purge'] ?? true) === true,
    ]);
    if (is_wp_error($result)) { return $result; }

    $note = '';
    if (function_exists('wpultra_rules_is_nginx') && wpultra_rules_is_nginx() && ($input['browser_rules'] ?? true) === true) {
        $note = 'WARNING: this server appears to be nginx — the .htaccess browser-caching/gzip rules have NO effect; add equivalent expires/gzip directives to the nginx config manually. Lazyload and purge still applied.';
    }

    wpultra_audit_log('optimize-cache', $action . ($note !== '' ? ' (nginx caveat)' : ''), true);

    return wpultra_ok([
        'action' => $action,
        'result' => $result,
        'note'   => $note,
    ]);
}
