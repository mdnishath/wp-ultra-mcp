<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/read-debug-log', [
    'label'       => __('Read Debug Log', 'wp-ultra-mcp'),
    'description' => __('Read the last N lines of the WordPress debug.log file.', 'wp-ultra-mcp'),
    'category'    => 'diagnostics',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'lines' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'path'    => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'exists'  => ['type' => 'boolean'],
            'note'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_read_debug_log',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_debug_log_path(): string {
    if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') { return WP_DEBUG_LOG; }
    return WP_CONTENT_DIR . '/debug.log';
}

function wpultra_read_debug_log(array $input) {
    $path = wpultra_debug_log_path();
    if (!is_readable($path)) {
        return wpultra_ok(['path' => $path, 'exists' => false, 'content' => '',
            'note' => 'No debug.log found. Set WP_DEBUG and WP_DEBUG_LOG=true in wp-config.php to capture errors.']);
    }
    $n = max(1, min(5000, (int) ($input['lines'] ?? 100)));
    // Read the tail from the end instead of loading the whole file — a multi-GB debug.log
    // would otherwise OOM the request.
    $tail = wpultra_tail_lines($path, $n);
    if ($tail === null) { return wpultra_err('read_failed', "Could not read: $path"); }
    return wpultra_ok(['path' => $path, 'exists' => true, 'content' => implode("\n", $tail)]);
}

/** Return the last $n lines of a file without loading it entirely. Null on open failure. */
function wpultra_tail_lines(string $path, int $n): ?array {
    $fh = @fopen($path, 'rb');
    if (!$fh) { return null; }
    $buffer = '';
    $chunk = 8192;
    $pos = fseek($fh, 0, SEEK_END);
    $filesize = ftell($fh);
    $read = 0;
    $lines = 0;
    while ($read < $filesize && $lines <= $n) {
        $step = (int) min($chunk, $filesize - $read);
        $read += $step;
        fseek($fh, $filesize - $read, SEEK_SET);
        $buffer = fread($fh, $step) . $buffer;
        $lines = substr_count($buffer, "\n");
    }
    fclose($fh);
    $all = explode("\n", rtrim($buffer, "\n"));
    return array_slice($all, -$n);
}
