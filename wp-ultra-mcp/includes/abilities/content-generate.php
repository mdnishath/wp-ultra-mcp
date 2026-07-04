<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/content-generate', [
    'label'       => __('Content: Generate', 'wp-ultra-mcp'),
    'description' => __('Step 2 of the AI content pipeline. Takes AI-written content as a simple blocks[] spec ([{type: heading|paragraph|list|image, level?, text?, items?, image_id?}]), builds valid Gutenberg markup, creates the post (draft by default), sets SEO meta (focus_keyword/meta_description) via the active driver, and returns post_id + readability + a next-steps checklist. FLOW: content-plan → (AI writes blocks) → content-generate → media-generate(set featured) → seo-insert-internal-link → update-post(publish); or wrap the whole flow in a playbook.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'title'            => ['type' => 'string'],
            'blocks'           => ['type' => 'array'],
            'status'           => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private', 'future'], 'default' => 'draft'],
            'excerpt'          => ['type' => 'string'],
            'focus_keyword'    => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
            'seo_title'        => ['type' => 'string'],
            'category_ids'     => ['type' => 'array', 'items' => ['type' => 'integer']],
            'tag_names'        => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required'             => ['title', 'blocks'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'post_id'      => ['type' => 'integer'],
            'status'       => ['type' => 'string'],
            'permalink'    => ['type' => 'string'],
            'edit_url'     => ['type' => 'string'],
            'readability'  => ['type' => 'object'],
            'seo_warnings' => ['type' => 'array'],
            'next_steps'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_content_generate_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_content_generate_cb(array $input) {
    $res = wpultra_pipeline_create_draft($input);
    wpultra_audit_log(
        'content-generate',
        is_wp_error($res) ? ('failed: ' . $res->get_error_message()) : ('post ' . ($res['post_id'] ?? 0) . ' (' . ($res['status'] ?? '') . ')'),
        !is_wp_error($res)
    );
    return $res;
}
