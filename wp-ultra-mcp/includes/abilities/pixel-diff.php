<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine — the ai engine-file loader in bootstrap-mcp.php
// may not (yet) list pixeldiff.php, so load it regardless of order (mirrors how
// visual-diff.php leans on its own engine file).
if (!function_exists('wpultra_pxdiff_compare') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/ai/pixeldiff.php')) {
    require_once WPULTRA_DIR . 'includes/ai/pixeldiff.php';
}

wp_register_ability('wpultra/pixel-diff', [
    'label'       => __('Pixel Diff', 'wp-ultra-mcp'),
    'description' => __(
        'Server-side PIXEL comparison of two screenshots — closes the "is it actually pixel-perfect?" loop '
        . 'with a NUMBER + a diff-heatmap image, not eyeballs. YOU (the calling AI, e.g. Claude Code, which can '
        . 'drive a browser) capture the before/after screenshots; this ability does the pixel math with GD '
        . '(always available in WordPress). '
        . 'INPUT: a and b — each EITHER an http(s) URL (fetched server-side, 15s timeout, 15MB cap) or a base64 '
        . 'image string (with or without a data:image/...;base64, prefix). Optional: tolerance (0-255 per-channel, '
        . 'default 10 — a pixel counts as different only when some channel delta exceeds this), save_heatmap '
        . '(default true), region {x,y,w,h} to crop both images to the same rectangle before comparing. '
        . 'If the two images have different dimensions and no region is given, it compares the overlapping '
        . 'top-left rectangle and reports the dimension mismatch prominently — a size mismatch IS itself a '
        . 'pixel-perfect failure. '
        . 'For very large images it samples on a stride to bound runtime (reported as sample_stride / sampling_note). '
        . 'OUTPUT: width, height, compared_pixels, different_pixels, mismatch_pct, max_channel_delta, '
        . 'dimension_match, verdict (pixel_perfect / near_identical / minor_diff / major_diff / dimension_mismatch), '
        . 'bounding_box_of_changes {x,y,w,h} (null when nothing differs), and — when save_heatmap is true and '
        . 'the uploads dir is writable — heatmap_url: a PNG showing changed pixels in red over a dimmed grayscale '
        . 'of image A, saved under wp-content/uploads/wpultra-pixeldiff/. Read-only: it fetches/decodes images and '
        . 'writes only a diff artifact to uploads, nothing else on the site changes.',
        'wp-ultra-mcp'
    ),
    'category'    => 'ai',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'a'             => ['type' => 'string'],
            'b'             => ['type' => 'string'],
            'tolerance'     => ['type' => 'integer'],
            'save_heatmap'  => ['type' => 'boolean'],
            'region'        => [
                'type'       => 'object',
                'properties' => [
                    'x' => ['type' => 'integer'],
                    'y' => ['type' => 'integer'],
                    'w' => ['type' => 'integer'],
                    'h' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
        ],
        'required'             => ['a', 'b'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'                 => ['type' => 'boolean'],
            'width'                   => ['type' => 'integer'],
            'height'                  => ['type' => 'integer'],
            'compared_pixels'         => ['type' => 'integer'],
            'different_pixels'        => ['type' => 'integer'],
            'mismatch_pct'            => ['type' => 'number'],
            'max_channel_delta'       => ['type' => 'integer'],
            'dimension_match'         => ['type' => 'boolean'],
            'verdict'                 => ['type' => 'string'],
            'bounding_box_of_changes' => ['type' => 'object'],
            'sample_stride'           => ['type' => 'integer'],
            'sampling_note'           => ['type' => 'string'],
            'dimension_mismatch_note' => ['type' => 'string'],
            'source_dimensions'       => ['type' => 'object'],
            'heatmap_url'             => ['type' => 'string'],
            'heatmap_note'            => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_pixel_diff_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_pixel_diff_cb(array $input) {
    if (!function_exists('wpultra_pxdiff_compare')) {
        return wpultra_err('pxdiff_engine_missing', 'The pixel-diff engine (includes/ai/pixeldiff.php) is not loaded.');
    }

    $result = wpultra_pxdiff_compare($input);
    if (is_wp_error($result)) { return $result; }

    wpultra_audit_log(
        'pixel-diff',
        "verdict={$result['verdict']} mismatch_pct={$result['mismatch_pct']} dimension_match=" . ($result['dimension_match'] ? '1' : '0'),
        true
    );

    return wpultra_ok($result);
}
