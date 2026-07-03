<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/newsletter-subscribe', [
    'label'       => __('Subscribe to Newsletter', 'wp-ultra-mcp'),
    'description' => __('Subscribes an email to a mailing list via MailPoet or MC4WP (`driver` optional — auto-picked when exactly one is active). `list_ids` selects target list(s); required for MC4WP, optional for MailPoet (subscribes with no list otherwise). Optional first_name/last_name. IMPORTANT: the caller is responsible for having lawful consent (e.g. a checked opt-in checkbox) before invoking this ability — it does not itself collect or verify consent.', 'wp-ultra-mcp'),
    'category'    => 'newsletter',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'email'      => ['type' => 'string'],
            'driver'     => ['type' => 'string', 'enum' => ['mailpoet', 'mc4wp']],
            'first_name' => ['type' => 'string'],
            'last_name'  => ['type' => 'string'],
            'list_ids'   => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]],
        ],
        'required'             => ['email'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'plugin'  => ['type' => 'string'],
            'email'   => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_newsletter_subscribe',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_newsletter_subscribe(array $input) {
    $email = trim((string) ($input['email'] ?? ''));
    if ($email === '') { return wpultra_err('missing_email', 'email is required.'); }
    if (!wpultra_news_valid_email($email)) { return wpultra_err('bad_email', 'A valid email is required.'); }

    $explicit = (string) ($input['driver'] ?? '');
    $driver   = wpultra_news_driver($explicit);
    if (is_wp_error($driver)) { return $driver; }

    $opts = [
        'first_name' => (string) ($input['first_name'] ?? ''),
        'last_name'  => (string) ($input['last_name'] ?? ''),
        'list_ids'   => (array) ($input['list_ids'] ?? []),
    ];

    $result = wpultra_news_subscribe($driver, $email, $opts);
    $ok = !is_wp_error($result);
    wpultra_audit_log('newsletter-subscribe', "subscribe {$email} via {$driver}" . ($ok ? '' : ' (failed)'), $ok);
    if (!$ok) { return $result; }
    return wpultra_ok($result);
}
