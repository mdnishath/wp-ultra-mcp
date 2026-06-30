<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-set-meta', [
    'label'       => __('SEO: Set Meta', 'wp-ultra-mcp'),
    'description' => __('Set a post\'s SEO meta (title, description, focus_keyword, canonical, robots_noindex, robots_nofollow, og_*, twitter_*). Validated; writes via the active driver. Returns rejected + warnings.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id'             => ['type' => 'integer'],
            'title'               => ['type' => 'string'],
            'description'         => ['type' => 'string'],
            'focus_keyword'       => ['type' => 'string'],
            'canonical'           => ['type' => 'string'],
            'robots_noindex'      => ['type' => 'boolean'],
            'robots_nofollow'     => ['type' => 'boolean'],
            'og_title'            => ['type' => 'string'],
            'og_description'      => ['type' => 'string'],
            'og_image'            => ['type' => 'string'],
            'twitter_title'       => ['type' => 'string'],
            'twitter_description' => ['type' => 'string'],
        ],
        'required'   => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'rejected' => ['type' => 'array'], 'warnings' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_set_meta_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_set_meta_cb(array $input) {
    $id = (int) ($input['post_id'] ?? 0);
    $fields = $input;
    unset($fields['post_id']);
    $res = wpultra_seo_set_meta($id, $fields);
    wpultra_audit_log('seo-set-meta', is_wp_error($res) ? 'failed' : ('post ' . $id), !is_wp_error($res));
    if (is_wp_error($res)) { return $res; }
    return wpultra_ok(['rejected' => $res['rejected'], 'warnings' => $res['warnings']]);
}
