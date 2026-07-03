<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/media-generate', [
    'label'       => __('Generate Image → Media Library → Featured Image', 'wp-ultra-mcp'),
    'description' => __('One flow: get an image (from `url`, `data_base64`, or a `prompt` — server-side generation needs the `wpultra_openai_api_key` option / WPULTRA_OPENAI_API_KEY constant), upload it to the Media Library, apply alt/title/caption, and set it as the featured image on `post_id` in the same call.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'prompt'      => ['type' => 'string'],
            'url'         => ['type' => 'string'],
            'data_base64' => ['type' => 'string'],
            'post_id'     => ['type' => 'integer'],
            'set_featured' => ['type' => 'boolean'],
            'alt'         => ['type' => 'string'],
            'title'       => ['type' => 'string'],
            'caption'     => ['type' => 'string'],
            'filename'    => ['type' => 'string'],
            'size'        => ['type' => 'string', 'enum' => ['1024x1024', '1536x1024', '1024x1536', 'auto']],
            'model'       => ['type' => 'string', 'default' => 'gpt-image-1'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'id'           => ['type' => 'integer'],
            'url'          => ['type' => 'string'],
            'title'        => ['type' => 'string'],
            'mime'         => ['type' => 'string'],
            'alt'          => ['type' => 'string'],
            'edit_url'     => ['type' => 'string'],
            'featured_set' => ['type' => 'boolean'],
            'source_mode'  => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_media_generate_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_media_generate_ability(array $input) {
    $res = wpultra_media_gen_run($input);
    if (is_wp_error($res)) {
        wpultra_audit_log('media-generate', 'error: ' . $res->get_error_message(), false);
        return $res;
    }
    wpultra_audit_log('media-generate', 'id=' . (string) ($res['id'] ?? '?') . ' mode=' . (string) ($res['source_mode'] ?? '?'), true);
    return wpultra_ok($res);
}
