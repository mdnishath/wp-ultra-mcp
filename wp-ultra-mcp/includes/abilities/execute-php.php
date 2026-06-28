<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/execute-php', [
    'label'       => __('Execute PHP', 'wp-ultra-mcp'),
    'description' => __('Evaluate a snippet of PHP code and return the output and return value.', 'wp-ultra-mcp'),
    'category'    => 'code-execution',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'code' => ['type' => 'string'],
        ],
        'required'             => ['code'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'return_value' => ['type' => ['string', 'number', 'boolean', 'null']],
            'output'       => ['type' => 'string'],
            'error'        => ['type' => 'string'],
            'error_class'  => ['type' => 'string'],
            'warnings'     => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_execute_php',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_execute_php(array $input) {
    $code = (string) ($input['code'] ?? '');
    if ($code === '') { return wpultra_err('empty_code', 'code is required.'); }

    // Safe-mode guard: refuse execution if sandbox previously crashed.
    // function_exists check ensures no-op when runtime.php is not loaded (e.g. unit tests).
    if (function_exists('wpultra_sandbox_crashed') && wpultra_sandbox_crashed()) {
        return wpultra_err('safe_mode', 'Sandbox safe mode is active after a crash. Read the debug log, fix the offending sandbox file, then clear safe mode in wp-admin.');
    }

    $code = preg_replace('/^\s*<\?php/', '', $code); // tolerate a leading tag
    $warnings = [];
    set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
    $prev = ini_get('max_execution_time');
    if (function_exists('set_time_limit')) { @set_time_limit(defined('WPULTRA_CLI_TIMEOUT') ? WPULTRA_CLI_TIMEOUT : 30); }
    ob_start();
    try {
        // Wrap eval in sandbox guard so a fatal records the .crashed sentinel.
        // Falls back to a plain closure call when runtime.php is not loaded (unit tests).
        $evalFn = function () use ($code) { return eval($code); };
        $return = function_exists('wpultra_sandbox_guard') ? wpultra_sandbox_guard($evalFn) : $evalFn();
        $output = ob_get_clean();
        restore_error_handler();
        if (function_exists('set_time_limit')) { @set_time_limit((int) $prev); }
        if (!is_scalar($return) && $return !== null) { $return = print_r($return, true); }
        return wpultra_ok(['return_value' => $return, 'output' => $output, 'warnings' => $warnings]);
    } catch (\Throwable $e) {
        $output = ob_get_clean();
        restore_error_handler();
        if (function_exists('set_time_limit')) { @set_time_limit((int) $prev); }
        return ['success' => false, 'error' => $e->getMessage(), 'error_class' => get_class($e), 'output' => $output, 'warnings' => $warnings];
    }
}
