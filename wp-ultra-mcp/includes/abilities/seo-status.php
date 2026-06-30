<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-status', [
    'label'       => __('SEO: Status', 'wp-ultra-mcp'),
    'description' => __('Report the SEO setup: active mode (yoast/rankmath/native), plugin version, sitemap state, site name/url, published post count.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'status' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_status_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_status_cb(array $input) {
    return wpultra_ok(['status' => wpultra_seo_status()]);
}
