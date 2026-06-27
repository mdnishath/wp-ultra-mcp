<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/run-wp-cli', [
    'label'       => __('Run WP-CLI', 'wp-ultra-mcp'),
    'description' => __('Execute a WP-CLI command and return its output.', 'wp-ultra-mcp'),
    'category'    => 'code-execution',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'args' => [
                'type'  => 'array',
                'items' => ['type' => 'string'],
            ],
        ],
        'required'             => ['args'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'exit_code' => ['type' => 'integer'],
            'stdout'    => ['type' => 'string'],
            'stderr'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'meta' => ['destructive' => true],
    'permission_callback' => 'wpultra_permission_callback',
    'callback'            => 'wpultra_run_wp_cli',
]);

function wpultra_find_wp_cli(): string {
    foreach (['wp', '/usr/local/bin/wp', '/usr/bin/wp'] as $c) {
        if ($c === 'wp') { return 'wp'; }
        if (is_executable($c)) { return $c; }
    }
    return 'wp';
}

function wpultra_run_wp_cli(array $input) {
    if (!function_exists('proc_open')) { return wpultra_err('proc_disabled', 'proc_open is disabled in PHP.'); }
    $args = array_values(array_filter((array) ($input['args'] ?? []), 'is_string'));
    if ($args === []) { return wpultra_err('no_args', 'args must be a non-empty array of strings.'); }
    $cmd = array_merge([wpultra_find_wp_cli()], $args);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, ABSPATH);
    if (!is_resource($proc)) { return wpultra_err('spawn_failed', 'Could not start wp-cli.'); }
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return wpultra_ok(['exit_code' => $code, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr]);
}
