<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-bulk-set-meta', [
    'label'       => __('SEO: Bulk Set Meta', 'wp-ultra-mcp'),
    'description' => __('Apply SEO meta across many posts by rule. filter: missing_title|missing_description|all. title_template/description_template support %title% %sitename% %sep%. noindex sets robots. DRY-RUN by default (preview); pass dry_run:false (or apply:true) to write. limit caps scope.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'filter' => ['type' => 'string', 'enum' => ['missing_title', 'missing_description', 'all']],
            'title_template' => ['type' => 'string'], 'description_template' => ['type' => 'string'],
            'noindex' => ['type' => 'boolean'], 'sep' => ['type' => 'string'],
            'dry_run' => ['type' => 'boolean'], 'apply' => ['type' => 'boolean'], 'limit' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'dry_run' => ['type' => 'boolean'], 'truncated' => ['type' => 'boolean'], 'applied' => ['type' => 'array'], 'applied_count' => ['type' => 'integer'], 'skipped' => ['type' => 'integer']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_bulk_set_meta_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_bulk_set_meta_cb(array $input) {
    $res = wpultra_seo_bulk_set_meta($input);
    if (!$res['dry_run']) { wpultra_audit_log('seo-bulk-set-meta', 'applied ' . $res['applied_count'], true); }
    return wpultra_ok($res);
}
