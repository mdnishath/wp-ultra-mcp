<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/translation-set-content', [
    'label'       => __('Set Translation Content', 'wp-ultra-mcp'),
    'description' => __('Fill in the AI-translated content on a translation post created by duplicate-to-language. Workflow: 1) call duplicate-to-language to create the shell translation post; 2) YOU (the AI) translate the source title/content/excerpt/meta/Elementor text strings; 3) call this ability with post_id = the TRANSLATION post to write those strings in. title/content/excerpt are written via wp_update_post; meta is an object of key => value pairs written via update_post_meta. elementor_texts is an ordered list of {find, replace} pairs applied as safe in-place substitutions inside the post\'s _elementor_data JSON string — this translates Elementor builder text without touching (and risking corrupting) the surrounding widget/JSON structure. At least one of title/content/excerpt/meta/elementor_texts is required. TRANSLATEPRESS MODE: pass trp_strings = [{original, translated, language}] INSTEAD of post_id to write human translations into TranslatePress\'s string dictionary (status=2). Only strings TRP has already discovered can be translated — a string enters the dictionary the first time its page renders in that language (check coverage via translation-status third_party).', 'wp-ultra-mcp'),
    'category'    => 'multilingual',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'post_id'         => ['type' => 'integer', 'description' => 'The TRANSLATION post created by duplicate-to-language (not the source post).'],
            'title'           => ['type' => 'string'],
            'content'         => ['type' => 'string'],
            'excerpt'         => ['type' => 'string'],
            'meta'            => ['type' => 'object', 'description' => 'meta_key => value pairs to write via update_post_meta.'],
            'elementor_texts' => [
                'type'  => 'array',
                'description' => 'Ordered list of {find, replace} string substitutions applied inside _elementor_data.',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'find'    => ['type' => 'string'],
                        'replace' => ['type' => 'string'],
                    ],
                    'required'             => ['find', 'replace'],
                    'additionalProperties' => false,
                ],
            ],
            'trp_strings' => [
                'type'  => 'array',
                'description' => 'TranslatePress mode: dictionary strings to translate (no post_id needed).',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'original'   => ['type' => 'string'],
                        'translated' => ['type' => 'string'],
                        'language'   => ['type' => 'string', 'description' => 'TRP language code, e.g. bn_BD.'],
                    ],
                    'required'             => ['original', 'translated', 'language'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'                => ['type' => 'boolean'],
            'post_id'                => ['type' => 'integer'],
            'updated_fields'         => ['type' => 'array'],
            'elementor_replacements' => ['type' => 'integer'],
            'updated'                => ['type' => 'integer', 'description' => 'TranslatePress mode: dictionary rows updated.'],
            'results'                => ['type' => 'array', 'description' => 'TranslatePress mode: per-string outcome.'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_translation_set_content',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_translation_set_content(array $input) {
    // TranslatePress mode: dictionary string writes, no post involved.
    if (!empty($input['trp_strings']) && is_array($input['trp_strings'])) {
        if (!function_exists('wpultra_i18n_trp_set_strings')) {
            return wpultra_err('multilingual_unavailable', 'The TranslatePress adapter (includes/i18n/adapters.php) is not loaded.');
        }
        $res = wpultra_i18n_trp_set_strings($input['trp_strings']);
        if (is_wp_error($res)) {
            wpultra_audit_log('translation-set-content', 'trp_strings failed: ' . $res->get_error_message(), false);
            return $res;
        }
        wpultra_audit_log('translation-set-content', "trp_strings wrote {$res['updated']} dictionary row(s)", true);
        return wpultra_ok($res);
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) { return wpultra_err('missing_post_id', 'post_id is required (or pass trp_strings for TranslatePress).'); }

    $result = wpultra_i18n_fill($post_id, $input);
    if (is_wp_error($result)) {
        wpultra_audit_log('translation-set-content', "set-content on post $post_id failed: " . $result->get_error_message(), false);
        return $result;
    }

    // Clear Elementor's CSS cache when builder text was touched — mirrors wpultra_el_write()'s
    // best-effort cache invalidation so the translated page renders with fresh CSS immediately.
    if (in_array('elementor_texts', $result['updated_fields'], true)) {
        try {
            if (class_exists('\\Elementor\\Plugin')) {
                $p = \Elementor\Plugin::$instance;
                if (isset($p->files_manager)) { $p->files_manager->clear_cache(); }
            }
            delete_post_meta($post_id, '_elementor_css');
            clean_post_cache($post_id);
        } catch (\Throwable $e) { /* cache clear is best-effort */ }
    }

    wpultra_audit_log('translation-set-content', "wrote translated content onto post $post_id (" . implode(',', $result['updated_fields']) . ')', true);
    return wpultra_ok($result);
}
