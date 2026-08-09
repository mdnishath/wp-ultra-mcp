<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/register-block-pattern', [
    'label'       => __('Register Block Pattern', 'wp-ultra-mcp'),
    'description' => __('Create, update, list, and delete custom (unsynced) block patterns that appear in the block inserter and in gutenberg-list-patterns. Persisted across requests and re-registered on init. actions: `list`, `save` (name + block-markup content + optional title/categories/description; creates or updates), `delete` (confirm:true). For SYNCED reusable blocks use gutenberg-manage-reusable-block instead.', 'wp-ultra-mcp'),
    'category'    => 'gutenberg',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'save', 'delete'], 'default' => 'list'],
            'name'        => ['type' => 'string', 'description' => 'Pattern name; namespaced as wpultra/<slug> if no namespace given.'],
            'title'       => ['type' => 'string'],
            'content'     => ['type' => 'string', 'description' => 'Block markup (e.g. <!-- wp:paragraph -->…<!-- /wp:paragraph -->).'],
            'categories'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'description' => ['type' => 'string'],
            'confirm'     => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'patterns' => ['type' => 'array'],
            'count'    => ['type' => 'integer'],
            'name'     => ['type' => 'string'],
            'saved'    => ['type' => 'boolean'],
            'updated'  => ['type' => 'boolean'],
            'deleted'  => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_register_block_pattern_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
