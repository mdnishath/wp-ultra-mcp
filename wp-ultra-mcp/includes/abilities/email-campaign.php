<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensive engine require so this ability works regardless of load order
// (mirrors woo-bulk-edit → includes/woocommerce/bulk.php).
if (!function_exists('wpultra_campaign_status_options') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/marketing/campaigns.php')) {
    require_once WPULTRA_DIR . 'includes/marketing/campaigns.php';
}

wp_register_ability('wpultra/email-campaign', [
    'label'       => __('Email Campaigns', 'wp-ultra-mcp'),
    'description' => __(
        'Compose, queue-send and schedule real email newsletters/campaigns without a mailing plugin — batched wp_mail() delivery over a WP-Cron chain so large lists never time out. '
        . 'One action-dispatched tool. Actions: '
        . 'create {name, subject, body_html, recipients, batch_size?} — save a draft campaign (recipients: {source: "users"|"emails"|"newsletter", role? for users, emails?: [..] for an explicit list}). '
        . 'update {id, ...same fields} — edit a DRAFT only (campaigns that are sending/scheduled/sent are locked). '
        . 'get {id} / status {id} — full shaped campaign incl. progress {total, sent, failed, remaining, pct}. '
        . 'list — recent campaigns (max 50), newest first. '
        . 'test-send {id, test_email} — mail ONE copy to test_email now with a "[TEST] " subject prefix; no status change, always do this before a real send. '
        . 'send-now {id, confirm:true} — resolve recipients, build the send queue and start the batch chain immediately; returns the queued count; refuses an empty queue. '
        . 'schedule {id, send_at, confirm:true} — queue the campaign to start at send_at, a unix timestamp or a "Y-m-d H:i" SITE-LOCAL time string (e.g. "2026-07-04 09:00"); must be in the future. '
        . 'cancel {id} — stop a sending or scheduled campaign (the batch chain halts; already-sent emails cannot be recalled). '
        . 'delete {id, confirm:true} — remove a draft/sent/cancelled campaign (never mid-send; cancel first). '
        . 'Recipient sources: "users" = WordPress users (optionally filtered by role, e.g. {source:"users", role:"subscriber"}); "emails" = explicit list {source:"emails", emails:["a@b.com",...]} (deduped + validated); "newsletter" = MailPoet subscribed addresses when MailPoet is active (MC4WP stores subscribers remotely in Mailchimp and cannot be used — you get a clear error with alternatives). '
        . 'Delivery: batch_size emails per cron tick (default 20, clamp 1-100), ~5s between ticks; a bad address is counted as failed and never aborts the batch. Set the From header via the wpultra_campaign_from option (e.g. "Shop <news@example.com>"). '
        . 'Examples: {action:"create", name:"July promo", subject:"July deals", body_html:"<h1>Hi!</h1>...", recipients:{source:"users", role:"subscriber"}} then {action:"test-send", id:12, test_email:"me@example.com"} then {action:"schedule", id:12, send_at:"2026-07-04 09:00", confirm:true}. Or {action:"send-now", id:12, confirm:true} for immediate delivery. Poll {action:"status", id:12} for progress.',
        'wp-ultra-mcp'
    ),
    'category'    => 'marketing',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['create', 'update', 'get', 'list', 'delete', 'test-send', 'send-now', 'schedule', 'cancel', 'status'],
            ],
            'id'         => ['type' => 'integer'],
            'name'       => ['type' => 'string'],
            'subject'    => ['type' => 'string'],
            'body_html'  => ['type' => 'string'],
            'recipients' => [
                'type'       => 'object',
                'properties' => [
                    'source' => ['type' => 'string', 'enum' => ['users', 'emails', 'newsletter']],
                    'role'   => ['type' => 'string'],
                    'emails' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'additionalProperties' => false,
            ],
            'batch_size' => ['type' => 'integer'],
            'send_at'    => ['type' => ['integer', 'string'], 'description' => 'Unix timestamp or "Y-m-d H:i" site-local time.'],
            'test_email' => ['type' => 'string'],
            'confirm'    => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'action'    => ['type' => 'string'],
            'campaign'  => ['type' => 'object'],
            'campaigns' => ['type' => 'array'],
            'queued'    => ['type' => 'integer'],
            'sent'      => ['type' => 'boolean'],
            'deleted'   => ['type' => 'boolean'],
            'message'   => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_email_campaign_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_email_campaign_ability(array $input) {
    if (!function_exists('wpultra_campaign_status_options')) {
        return wpultra_err('campaign_engine_missing', 'The campaigns engine (includes/marketing/campaigns.php) is not loaded.');
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'create':    return wpultra_email_campaign_create($input);
        case 'update':    return wpultra_email_campaign_update($input);
        case 'get':
        case 'status':    return wpultra_email_campaign_get($input, $action);
        case 'list':      return wpultra_ok(['action' => 'list', 'campaigns' => wpultra_campaign_list(50)]);
        case 'delete':    return wpultra_email_campaign_delete($input);
        case 'test-send': return wpultra_email_campaign_test_send($input);
        case 'send-now':  return wpultra_email_campaign_send_now($input);
        case 'schedule':  return wpultra_email_campaign_schedule($input);
        case 'cancel':    return wpultra_email_campaign_cancel($input);
        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Known: create, update, get, list, delete, test-send, send-now, schedule, cancel, status.");
    }
}

/** Load + type-check a campaign or return a WP_Error. @return array|WP_Error */
function wpultra_email_campaign_require(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id < 1) { return wpultra_err('missing_id', 'id is required for this action.'); }
    $c = wpultra_campaign_load($id);
    if ($c === null) { return wpultra_err('not_found', "Campaign $id not found."); }
    return $c;
}

/** @return array|WP_Error */
function wpultra_email_campaign_create(array $input) {
    $valid = wpultra_campaign_validate_input($input, time());
    if ($valid !== true) { return wpultra_err('invalid_input', (string) $valid); }

    $id = wp_insert_post([
        'post_type'    => WPULTRA_CAMPAIGN_CPT,
        'post_status'  => 'private',
        'post_title'   => sanitize_text_field((string) $input['name']),
        'post_content' => (string) $input['body_html'],
    ], true);
    if (is_wp_error($id)) { return $id; }
    $id = (int) $id;

    $meta = wpultra_campaign_default_meta();
    $meta['subject']         = trim((string) $input['subject']);
    $meta['recipients_spec'] = wpultra_email_campaign_spec_from_input((array) $input['recipients']);
    $meta['batch_size']      = wpultra_campaign_clamp_batch_size($input['batch_size'] ?? 20);
    wpultra_campaign_save_meta($id, $meta);

    wpultra_audit_log('email-campaign', "created campaign #$id '{$input['name']}'", true);
    return wpultra_ok(['action' => 'create', 'campaign' => wpultra_campaign_shape($meta, $id, (string) $input['name'])]);
}

/** Normalize the recipients input into the stored spec shape. */
function wpultra_email_campaign_spec_from_input(array $rec): array {
    $spec = ['source' => (string) ($rec['source'] ?? '')];
    if (isset($rec['role']) && is_string($rec['role']) && trim($rec['role']) !== '') {
        $spec['role'] = trim($rec['role']);
    }
    if ($spec['source'] === 'emails') {
        $spec['emails'] = wpultra_campaign_clean_emails((array) ($rec['emails'] ?? []));
    }
    return $spec;
}

/** @return array|WP_Error */
function wpultra_email_campaign_update(array $input) {
    $c = wpultra_email_campaign_require($input);
    if (is_wp_error($c)) { return $c; }
    if ($c['meta']['status'] !== 'draft') {
        return wpultra_err('not_editable', "Only draft campaigns can be edited (status: {$c['meta']['status']}). Cancel it first if it is scheduled.");
    }

    // Merge the incoming fields over the current state, then validate the whole.
    $merged = [
        'name'       => array_key_exists('name', $input) ? (string) $input['name'] : $c['name'],
        'subject'    => array_key_exists('subject', $input) ? (string) $input['subject'] : $c['meta']['subject'],
        'body_html'  => array_key_exists('body_html', $input) ? (string) $input['body_html'] : $c['body'],
        'recipients' => array_key_exists('recipients', $input) ? (array) $input['recipients'] : (array) $c['meta']['recipients_spec'],
    ];
    if (array_key_exists('batch_size', $input)) { $merged['batch_size'] = $input['batch_size']; }

    $valid = wpultra_campaign_validate_input($merged, time());
    if ($valid !== true) { return wpultra_err('invalid_input', (string) $valid); }

    $res = wp_update_post([
        'ID'           => $c['id'],
        'post_title'   => sanitize_text_field($merged['name']),
        'post_content' => $merged['body_html'],
    ], true);
    if (is_wp_error($res)) { return $res; }

    $meta = $c['meta'];
    $meta['subject']         = trim($merged['subject']);
    $meta['recipients_spec'] = wpultra_email_campaign_spec_from_input($merged['recipients']);
    if (array_key_exists('batch_size', $input)) {
        $meta['batch_size'] = wpultra_campaign_clamp_batch_size($input['batch_size']);
    }
    wpultra_campaign_save_meta($c['id'], $meta);

    wpultra_audit_log('email-campaign', "updated campaign #{$c['id']}", true);
    return wpultra_ok(['action' => 'update', 'campaign' => wpultra_campaign_shape($meta, $c['id'], $merged['name'])]);
}

/** @return array|WP_Error */
function wpultra_email_campaign_get(array $input, string $action) {
    $c = wpultra_email_campaign_require($input);
    if (is_wp_error($c)) { return $c; }
    return wpultra_ok(['action' => $action, 'campaign' => wpultra_campaign_shape($c['meta'], $c['id'], $c['name'])]);
}

/** @return array|WP_Error */
function wpultra_email_campaign_delete(array $input) {
    $c = wpultra_email_campaign_require($input);
    if (is_wp_error($c)) { return $c; }
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'Deleting a campaign is permanent. Re-run with confirm: true.');
    }
    $status = $c['meta']['status'];
    if (!in_array($status, ['draft', 'sent', 'cancelled'], true)) {
        return wpultra_err('not_deletable', "Cannot delete a campaign while it is $status. Cancel it first.");
    }
    wpultra_campaign_unschedule($c['id']);
    $deleted = wp_delete_post($c['id'], true);
    if (!$deleted) { return wpultra_err('delete_failed', "Failed to delete campaign #{$c['id']}."); }

    wpultra_audit_log('email-campaign', "deleted campaign #{$c['id']} '{$c['name']}'", true);
    return wpultra_ok(['action' => 'delete', 'deleted' => true, 'message' => "Campaign #{$c['id']} deleted."]);
}

/** @return array|WP_Error */
function wpultra_email_campaign_test_send(array $input) {
    $c = wpultra_email_campaign_require($input);
    if (is_wp_error($c)) { return $c; }
    $test = wpultra_campaign_clean_emails([(string) ($input['test_email'] ?? '')]);
    if ($test === []) { return wpultra_err('bad_test_email', 'test_email must be a valid email address.'); }

    $sent = false;
    try {
        $sent = (bool) wp_mail($test[0], '[TEST] ' . (string) $c['meta']['subject'], $c['body'], wpultra_campaign_headers());
    } catch (\Throwable $e) {
        return wpultra_err('mail_failed', 'wp_mail threw: ' . $e->getMessage());
    }

    wpultra_audit_log('email-campaign', "test-send campaign #{$c['id']} to {$test[0]} sent=" . ($sent ? '1' : '0'), $sent);
    return wpultra_ok([
        'action'  => 'test-send',
        'sent'    => $sent,
        'message' => $sent ? "Test email sent to {$test[0]}." : "wp_mail reported failure sending to {$test[0]} — check the site's mail configuration (SMTP plugin?).",
    ]);
}

/** @return array|WP_Error */
function wpultra_email_campaign_send_now(array $input) {
    $c = wpultra_email_campaign_require($input);
    if (is_wp_error($c)) { return $c; }
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'send-now emails real recipients immediately. Re-run with confirm: true (tip: do a test-send first).');
    }
    $status = $c['meta']['status'];
    if (!in_array($status, ['draft', 'scheduled', 'cancelled'], true)) {
        return wpultra_err('bad_status', "Cannot start sending from status '$status'.");
    }
    wpultra_campaign_unschedule($c['id']); // drop a pending scheduled start, if any

    $res = wpultra_campaign_begin_send($c['id']);
    if (is_wp_error($res)) {
        wpultra_audit_log('email-campaign', "send-now #{$c['id']} failed: " . $res->get_error_code(), false);
        return $res;
    }

    wpultra_audit_log('email-campaign', "send-now #{$c['id']} queued={$res['queued']}", true);
    $fresh = wpultra_campaign_load($c['id']);
    return wpultra_ok([
        'action'   => 'send-now',
        'queued'   => (int) $res['queued'],
        'campaign' => $fresh ? wpultra_campaign_shape($fresh['meta'], $fresh['id'], $fresh['name']) : null,
        'message'  => "Sending started: {$res['queued']} recipients queued in batches of " . (int) ($fresh['meta']['batch_size'] ?? 20) . '.',
    ]);
}

/** @return array|WP_Error */
function wpultra_email_campaign_schedule(array $input) {
    $c = wpultra_email_campaign_require($input);
    if (is_wp_error($c)) { return $c; }
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'schedule will email real recipients at the given time. Re-run with confirm: true.');
    }
    $status = $c['meta']['status'];
    if (!in_array($status, ['draft', 'scheduled', 'cancelled'], true)) {
        return wpultra_err('bad_status', "Cannot schedule from status '$status'.");
    }

    $offset = (int) round(((float) get_option('gmt_offset', 0)) * 3600);
    $ts = wpultra_campaign_parse_send_at($input['send_at'] ?? null, $offset);
    if ($ts === false) {
        return wpultra_err('bad_send_at', 'send_at must be a unix timestamp or a "Y-m-d H:i" site-local time string.');
    }
    if ($ts <= time()) {
        return wpultra_err('send_at_past', 'send_at must be in the future.');
    }

    wpultra_campaign_unschedule($c['id']); // replace any previous schedule
    $meta = $c['meta'];
    $meta['status']       = 'scheduled';
    $meta['scheduled_at'] = $ts;
    $meta['last_error']   = '';
    wpultra_campaign_save_meta($c['id'], $meta);
    wp_schedule_single_event($ts, WPULTRA_CAMPAIGN_START_HOOK, [$c['id']]);

    wpultra_audit_log('email-campaign', "scheduled #{$c['id']} at $ts", true);
    return wpultra_ok([
        'action'   => 'schedule',
        'campaign' => wpultra_campaign_shape($meta, $c['id'], $c['name']),
        'message'  => 'Campaign scheduled for ' . gmdate('Y-m-d H:i', $ts + $offset) . ' site time (' . gmdate('Y-m-d H:i', $ts) . ' UTC).',
    ]);
}

/** @return array|WP_Error */
function wpultra_email_campaign_cancel(array $input) {
    $c = wpultra_email_campaign_require($input);
    if (is_wp_error($c)) { return $c; }
    $status = $c['meta']['status'];
    if (!in_array($status, ['sending', 'scheduled'], true)) {
        return wpultra_err('bad_status', "Only sending or scheduled campaigns can be cancelled (status: $status).");
    }

    $meta = $c['meta'];
    $meta['status'] = 'cancelled';
    wpultra_campaign_save_meta($c['id'], $meta);
    wpultra_campaign_unschedule($c['id']); // best-effort; the tick also self-stops on status

    wpultra_audit_log('email-campaign', "cancelled #{$c['id']} (was $status)", true);
    return wpultra_ok([
        'action'   => 'cancel',
        'campaign' => wpultra_campaign_shape($meta, $c['id'], $c['name']),
        'message'  => $status === 'sending'
            ? 'Sending stopped. Already-delivered emails cannot be recalled; progress is preserved.'
            : 'Scheduled send cancelled.',
    ]);
}
