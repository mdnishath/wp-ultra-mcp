<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/optimize-images', [
    'label'       => __('Optimize Images', 'wp-ultra-mcp'),
    'description' => __('Shrink oversized image attachments in place: any image wider than max_width OR larger than threshold_kb is resized to max_width and (by default) converted to WebP, overwriting the original attachment file. Processes one batch of up to `limit` images per call starting at `offset`; the response returns next_offset (or null when done) so large libraries can be looped. Requires confirm:true — this overwrites files. Returns processed count, total saved_bytes, and next_offset.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'max_width'    => ['type' => 'integer', 'default' => 1920, 'minimum' => 1],
            'threshold_kb' => ['type' => 'integer', 'default' => 300, 'minimum' => 0],
            'limit'        => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            'offset'       => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
            'convert_webp' => ['type' => 'boolean', 'default' => true],
            'confirm'      => ['type' => 'boolean'],
        ],
        'required'             => ['confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'processed'   => ['type' => 'integer'],
            'saved_bytes' => ['type' => 'integer'],
            'next_offset' => ['type' => ['integer', 'null']],
            'scanned'     => ['type' => 'integer'],
            'items'       => ['type' => 'array'],
        ],
        'required' => ['success', 'processed', 'saved_bytes'],
    ],
    'execute_callback'    => 'wpultra_optimize_images_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_optimize_images_cb(array $input) {
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'Optimizing images overwrites the original files. Re-run with confirm:true.');
    }

    $result = wpultra_optimize_images([
        'max_width'    => (int) ($input['max_width'] ?? 1920),
        'threshold_kb' => (int) ($input['threshold_kb'] ?? 300),
        'limit'        => (int) ($input['limit'] ?? 20),
        'offset'       => (int) ($input['offset'] ?? 0),
        'convert_webp' => ($input['convert_webp'] ?? true) === true,
    ]);

    wpultra_audit_log(
        'optimize-images',
        "processed={$result['processed']} saved_bytes={$result['saved_bytes']} "
            . 'next_offset=' . ($result['next_offset'] === null ? 'done' : (string) $result['next_offset']),
        true
    );

    return wpultra_ok($result);
}
