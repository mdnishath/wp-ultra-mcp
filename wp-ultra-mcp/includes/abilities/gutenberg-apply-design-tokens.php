<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once __DIR__ . '/../fse/engine.php';
require_once __DIR__ . '/../fse/tokens.php';

wp_register_ability('wpultra/gutenberg-apply-design-tokens', [
    'label'       => __('Gutenberg: Apply Design Tokens', 'wp-ultra-mcp'),
    'description' => __('Mint theme.json design tokens (color palette, font families, font sizes, spacing sizes) from a perceived reference\'s palette/fonts/sizes brief, merged into the USER global-styles layer. Idempotent by slug (re-running with the same role/title updates the token in place instead of duplicating it). Returns the created slugs plus the --wp--preset--* CSS custom properties the caller can reference.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'colors' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'hex' => ['type' => 'string']],
                'required' => ['title', 'hex'], 'additionalProperties' => false,
            ]],
            'fonts' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'family' => ['type' => 'string']],
                'required' => ['title', 'family'], 'additionalProperties' => false,
            ]],
            'sizes' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'size' => ['type' => 'number'], 'unit' => ['type' => 'string']],
                'required' => ['title', 'size'], 'additionalProperties' => false,
            ]],
            'confirm' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'slugs'    => ['type' => 'array'],
            'css_vars' => ['type' => 'array'],
            'dropped'  => ['type' => 'array', 'description' => 'Human-readable reasons for any brief entries skipped due to invalid hex/font-family/size (present only when non-empty).'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_gutenberg_apply_design_tokens_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_gutenberg_apply_design_tokens_cb(array $input) {
    $brief = [
        'colors' => $input['colors'] ?? null,
        'fonts'  => $input['fonts'] ?? null,
        'sizes'  => $input['sizes'] ?? null,
    ];
    if ($brief['colors'] === null && $brief['fonts'] === null && $brief['sizes'] === null) {
        return wpultra_err('empty_brief', 'Provide at least one of colors, fonts, or sizes.');
    }
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('confirm_required', 'Applying design tokens writes theme.json (global styles) — re-run with confirm:true.');
    }
    if (!function_exists('wpultra_gutenberg_apply_tokens')) {
        return wpultra_err('fse_unavailable', 'Gutenberg token engine unavailable.');
    }

    $res = wpultra_gutenberg_apply_tokens($brief);
    if (is_wp_error($res)) { return $res; }

    wpultra_audit_log('gutenberg-apply-design-tokens', count($res['slugs']) . ' token(s) applied', true);
    return wpultra_ok($res);
}
