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
            'allow_unsafe' => ['type' => 'boolean'],
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
    'execute_callback'    => 'wpultra_run_wp_cli',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
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
    // Same self-healing gate execute-php uses — a crashed sandbox suspends code execution,
    // and `wp eval`/`wp shell` would otherwise be an easy bypass.
    if (wpultra_safe_mode_active()) {
        return wpultra_err('safe_mode', 'Sandbox safe mode is active after a crash. Clear it in wp-admin before running WP-CLI.');
    }
    // Dangerous subcommands (eval/shell/db query/config set…) require explicit opt-in so a
    // hallucinating client can't silently pivot to arbitrary PHP/SQL/config rewrite.
    $unsafe = wpultra_wp_cli_unsafe_command($args);
    $allow  = ($input['allow_unsafe'] ?? false) === true || (defined('WPULTRA_WP_CLI_ALLOW_UNSAFE') && WPULTRA_WP_CLI_ALLOW_UNSAFE);
    if ($unsafe !== '' && !$allow) {
        wpultra_audit_log('run-wp-cli', "blocked unsafe: $unsafe", false);
        return wpultra_err('unsafe_command', "WP-CLI '$unsafe' runs arbitrary code/SQL. Re-run with allow_unsafe: true to proceed.");
    }
    $cmd = array_merge([wpultra_find_wp_cli()], $args);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, ABSPATH);
    if (!is_resource($proc)) { return wpultra_err('spawn_failed', 'Could not start wp-cli.'); }

    // Drain stdout AND stderr concurrently with non-blocking reads. Reading one pipe
    // to EOF before the other deadlocks once the unread pipe's OS buffer (~64KB) fills.
    $timeout = defined('WPULTRA_CLI_TIMEOUT') ? (int) WPULTRA_CLI_TIMEOUT : 30;
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = ''; $stderr = '';
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $start = microtime(true);
    $timed_out = false;
    while ($open) {
        if ((microtime(true) - $start) > $timeout) { $timed_out = true; break; }
        $read = array_values($open); $w = null; $x = null;
        $ready = @stream_select($read, $w, $x, 1); // wake at least 1×/s to re-check timeout
        if ($ready === false) { break; }
        foreach ($read as $stream) {
            $key = ($stream === $pipes[1]) ? 1 : 2;
            $chunk = fread($stream, 8192);
            if (($chunk === '' || $chunk === false) && feof($stream)) { fclose($stream); unset($open[$key]); continue; }
            if ($key === 1) { $stdout .= (string) $chunk; } else { $stderr .= (string) $chunk; }
        }
    }
    foreach ($open as $stream) { fclose($stream); }
    if ($timed_out) {
        proc_terminate($proc, 9);
        proc_close($proc);
        wpultra_audit_log('run-wp-cli', implode(' ', array_slice($args, 0, 8)), false);
        return wpultra_err('cli_timeout', "wp-cli timed out after {$timeout}s.");
    }
    $code = proc_close($proc);
    wpultra_audit_log('run-wp-cli', implode(' ', array_slice($args, 0, 8)), $code === 0);
    return wpultra_ok(['exit_code' => $code, 'stdout' => $stdout, 'stderr' => $stderr]);
}
