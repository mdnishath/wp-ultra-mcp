<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/newsletter-status', [
    'label'       => __('Newsletter Plugins Status', 'wp-ultra-mcp'),
    'description' => __('Reports which newsletter plugins (MailPoet, MC4WP / Mailchimp for WordPress) are active, their version, and the mailing lists each exposes (id, name, subscriber_count when known). Call FIRST before newsletter-subscribe — an installed:false plugin cannot be used.', 'wp-ultra-mcp'),
    'category'    => 'newsletter',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'active_count' => ['type' => 'integer'],
            'plugins'      => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_newsletter_status',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_newsletter_status(array $input) {
    return wpultra_ok(wpultra_news_status());
}
