<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/send-email', [
    'label'       => __('Send Email', 'wp-ultra-mcp'),
    'description' => __('Send an email via wp_mail(), reporting success/failure detail and which SMTP plugin (if any) is handling delivery.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'to'      => ['type' => 'string'],
            'subject' => ['type' => 'string'],
            'body'    => ['type' => 'string'],
            'html'    => ['type' => 'boolean', 'default' => false],
        ],
        'required'             => ['to', 'subject', 'body'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'sent'          => ['type' => 'boolean'],
            'to'            => ['type' => 'string'],
            'smtp_detected' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_send_email_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_send_email_cb(array $input) {
    $to = (string) ($input['to'] ?? '');
    $subject = (string) ($input['subject'] ?? '');
    $body = (string) ($input['body'] ?? '');
    $html = (bool) ($input['html'] ?? false);
    return wpultra_devtools_send_email($to, $subject, $body, $html);
}
