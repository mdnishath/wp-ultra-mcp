<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/duplicate-to-language', [
    'label'       => __('Duplicate Post To Language', 'wp-ultra-mcp'),
    'description' => __('Duplicate a post/page/CPT as a translation in another language (WPML or Polylang), copying meta (Elementor-safe) and taxonomy terms and linking the copy as a translation of the source. Requires an active multilingual plugin — call translation-status first.', 'wp-ultra-mcp'),
    'category'    => 'multilingual',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'     => ['type' => 'integer'],
            'target_lang' => ['type' => 'string'],
            'overwrite'   => ['type' => 'boolean'],
        ],
        'required'             => ['post_id', 'target_lang'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'post_id'     => ['type' => 'integer'],
            'plugin'      => ['type' => 'string'],
            'target_lang' => ['type' => 'string'],
            'edit_link'   => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_duplicate_to_language',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_duplicate_to_language(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) { return wpultra_err('missing_post_id', 'post_id is required.'); }
    $target_lang = trim((string) ($input['target_lang'] ?? ''));
    if ($target_lang === '') { return wpultra_err('missing_target_lang', 'target_lang is required.'); }
    $overwrite = !empty($input['overwrite']);

    $result = wpultra_i18n_duplicate_to_language($post_id, $target_lang, $overwrite);
    if (is_wp_error($result)) {
        wpultra_audit_log('duplicate-to-language', "duplicate of post $post_id to '$target_lang' failed", false);
        return $result;
    }
    wpultra_audit_log('duplicate-to-language', "duplicated post $post_id to '$target_lang' as new post {$result['post_id']}", true);
    return wpultra_ok($result);
}
