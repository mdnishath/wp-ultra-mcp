<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/content-plan', [
    'label'       => __('Content: Plan', 'wp-ultra-mcp'),
    'description' => __('Step 1 of the AI content pipeline. Given a keyword, returns a deterministic outline scaffold (title suggestions, H2 sections with writing hints, meta-description hint), SEO title/meta-description length guidance, and — if a source post_id is given — suggested internal-link targets. The AI CLIENT then writes the actual copy as a blocks[] spec and calls wpultra/content-generate. Read-only.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'keyword'  => ['type' => 'string'],
            'sections' => ['type' => 'integer', 'default' => 5],
            'audience' => ['type' => 'string'],
            'post_id'  => ['type' => 'integer'],
        ],
        'required'             => ['keyword'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'          => ['type' => 'boolean'],
            'outline'          => ['type' => 'object'],
            'seo_guidance'     => ['type' => 'object'],
            'internal_targets' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_content_plan_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_content_plan_cb(array $input) {
    $keyword = (string) ($input['keyword'] ?? '');
    if (trim($keyword) === '') { return wpultra_err('missing_keyword', 'keyword is required.'); }
    $sections = isset($input['sections']) ? (int) $input['sections'] : 5;

    $outline = wpultra_pipeline_outline_scaffold($keyword, $sections);
    if (!empty($input['audience'])) {
        $outline['meta_hint'] .= ' Target audience: ' . (string) $input['audience'] . '.';
    }

    $seo_guidance = [
        'title'       => 'SEO title 50–60 chars; put the focus keyword near the front.',
        'description' => 'Meta description 120–160 chars; include the keyword once and a clear benefit.',
        'focus_keyword' => trim($keyword),
    ];

    $internal_targets = [];
    if (!empty($input['post_id']) && function_exists('wpultra_seo_suggest_links')) {
        $pid = (int) $input['post_id'];
        if (function_exists('get_post') && get_post($pid)) {
            $internal_targets = wpultra_seo_suggest_links($pid, 5);
        }
    }

    return wpultra_ok([
        'outline'          => $outline,
        'seo_guidance'     => $seo_guidance,
        'internal_targets' => $internal_targets,
    ]);
}
