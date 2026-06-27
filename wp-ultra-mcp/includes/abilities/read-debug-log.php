<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

if (function_exists('wp_register_ability')) {
    wp_register_ability('read-debug-log', [
        'slug'        => 'read-debug-log',
        'category'    => 'diagnostics',
        'description' => 'Read the last N lines of the WordPress debug.log file.',
        'input'       => [
            'lines' => ['type' => 'integer'],
        ],
        'output'      => [
            'success' => ['type' => 'boolean'],
            'path'    => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'exists'  => ['type' => 'boolean'],
        ],
        'meta'        => ['readonly' => true],
        'callback'    => 'wpultra_read_debug_log',
    ]);
}

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
    $all = file($path, FILE_IGNORE_NEW_LINES);
    if ($all === false) { return wpultra_err('read_failed', "Could not read: $path"); }
    $tail = array_slice($all, -$n);
    return wpultra_ok(['path' => $path, 'exists' => true, 'content' => implode("\n", $tail)]);
}
