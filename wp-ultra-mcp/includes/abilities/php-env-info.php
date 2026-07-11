<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/php-env-info', [
    'label'       => __('PHP Environment Info', 'wp-ultra-mcp'),
    'description' => __('One-call hosting-environment report — the first step of every hosting-issue diagnosis. Read-only, no input. Reports: php (version/SAPI/OS + key ini settings), extensions (curl/gd/imagick/zip/mbstring/intl/openssl/mysqli/exif/xml/json/dom/fileinfo/iconv/sodium/opcache loaded state + missing_recommended), opcache (enabled/memory/hit rate), database ($wpdb version/server info/prefix/charset), wordpress (version, memory limits, WP_DEBUG, multisite, language, home/site URL), server (software, HTTPS, disk free/total/percent), and a rule-evaluated warnings list (low memory_limit, short max_execution_time, small upload/post limits, missing extensions, low disk space, outdated PHP, disabled OPcache). Every read degrades to null on a locked-down host instead of failing.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'                 => 'object',
        'properties'           => [],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'             => ['type' => 'boolean'],
            'php'                 => ['type' => 'object'],
            'extensions'          => ['type' => 'object'],
            'missing_recommended' => ['type' => 'array'],
            'opcache'             => ['type' => 'object'],
            'database'            => ['type' => 'object'],
            'wordpress'           => ['type' => 'object'],
            'server'              => ['type' => 'object'],
            'warnings'            => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_php_env_info_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/** @return array */
function wpultra_php_env_info_cb(array $input) {
    return wpultra_ok(wpultra_env_collect());
}
