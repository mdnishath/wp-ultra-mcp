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
    'meta' => ['destructive' => true],
    'permission_callback' => 'wpultra_permission_callback',
    'callback'            => 'wpultra_execute_php',
]);

function wpultra_execute_php(array $input) {
    $code = (string) ($input['code'] ?? '');
    if ($code === '') { return wpultra_err('empty_code', 'code is required.'); }
    $code = preg_replace('/^\s*<\?php/', '', $code); // tolerate a leading tag
    $warnings = [];
    set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
    $prev = ini_get('max_execution_time');
    if (function_exists('set_time_limit')) { @set_time_limit(defined('WPULTRA_CLI_TIMEOUT') ? WPULTRA_CLI_TIMEOUT : 30); }
    ob_start();
    try {
        $return = eval($code);
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
