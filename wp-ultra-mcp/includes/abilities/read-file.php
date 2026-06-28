<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/read-file', [
    'label'       => __('Read File', 'wp-ultra-mcp'),
    'description' => __('Read the contents of a file within the allowed base directory.', 'wp-ultra-mcp'),
    'category'    => 'filesystem',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'path'      => ['type' => 'string'],
            'max_bytes' => ['type' => 'integer'],
        ],
        'required'             => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'path'      => ['type' => 'string'],
            'content'   => ['type' => 'string'],
            'truncated' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_read_file',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_read_file(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? ''), true);
    if (is_wp_error($resolved)) { return $resolved; }
    $max = max(1, (int) ($input['max_bytes'] ?? 200000));
    // Read at most $max+1 bytes so a multi-GB file can't OOM the request; the extra
    // byte lets us detect truncation without loading the whole file.
    $fh = @fopen($resolved, 'rb');
    if ($fh === false) { return wpultra_err('read_failed', "Could not read: $resolved"); }
    $content = stream_get_contents($fh, $max + 1);
    fclose($fh);
    if ($content === false) { return wpultra_err('read_failed', "Could not read: $resolved"); }
    $truncated = false;
    if (strlen($content) > $max) { $content = substr($content, 0, $max); $truncated = true; }
    return wpultra_ok(['path' => $resolved, 'content' => $content, 'truncated' => $truncated]);
}
