<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/write-file', [
    'label'       => __('Write File', 'wp-ultra-mcp'),
    'description' => __('Write or append content to a file within the allowed base directory.', 'wp-ultra-mcp'),
    'category'    => 'filesystem',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'path'    => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'append'  => ['type' => 'boolean'],
        ],
        'required'             => ['path', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'path'          => ['type' => 'string'],
            'bytes_written' => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_write_file',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_write_file(array $input) {
    $resolved = wpultra_resolve_path((string) ($input['path'] ?? ''), false);
    if (is_wp_error($resolved)) { return $resolved; }
    $content = (string) ($input['content'] ?? '');
    $append = ($input['append'] ?? false) === true;
    // When writing an executable file (allowed only inside the sandbox), make sure the
    // sandbox carries its deny-.htaccess/index.php so the file can't be run by URL.
    if (function_exists('wpultra_sandbox_harden') && wpultra_path_requires_sandbox($resolved)) {
        wpultra_sandbox_harden();
    }
    $dir = dirname($resolved);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { return wpultra_err('mkdir_failed', "Could not create dir: $dir"); }
    if ($append) {
        $ok = file_put_contents($resolved, $content, FILE_APPEND);
    } else {
        $tmp = $resolved . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false) { return wpultra_err('write_failed', "Could not write tmp for: $resolved"); }
        if (!rename($tmp, $resolved)) { @unlink($tmp); return wpultra_err('rename_failed', "Could not finalize: $resolved"); }
        $ok = strlen($content);
    }
    if ($ok === false) { return wpultra_err('write_failed', "Could not write: $resolved"); }
    return wpultra_ok(['path' => $resolved, 'bytes_written' => strlen($content)]);
}
