<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Email campaigns engine (Roadmap A1) — compose, queue-send and schedule real
 * newsletters without a mailing plugin.
 *
 * Storage: private CPT `wpultra_campaign` (post_title = campaign name,
 * post_content = HTML body) + a single meta blob `_wpultra_campaign`:
 *   {subject, status: draft|scheduled|sending|sent|cancelled, recipients_spec,
 *    queue: [emails...], cursor, sent_count, fail_count, batch_size,
 *    scheduled_at, started_at, finished_at, last_error}
 *
 * Sending: WP-Cron batch chain (the proven jobs-engine pattern). Each tick of
 * `wpultra_campaign_send_tick` mails one batch_size slice of the queue via
 * wp_mail(), advances the cursor, saves, and reschedules itself until the
 * queue is drained (status → sent). A scheduled campaign gets a single event
 * `wpultra_campaign_send_start` at scheduled_at whose handler flips it to
 * sending, builds the queue and fires the first tick. Cancelling simply flips
 * status — the tick refuses to run for anything that is not 'sending', so the
 * chain stops itself.
 *
 * Layout: PURE functions first (no WordPress calls — unit-tested by
 * tests/campaigns.test.php), WP-touching wrappers after. The always-on
 * runtime contract is wpultra_campaigns_boot() (cheap + idempotent), called
 * by the controller on plugins_loaded.
 */

if (!defined('WPULTRA_CAMPAIGN_CPT'))        { define('WPULTRA_CAMPAIGN_CPT', 'wpultra_campaign'); }
if (!defined('WPULTRA_CAMPAIGN_META'))       { define('WPULTRA_CAMPAIGN_META', '_wpultra_campaign'); }
if (!defined('WPULTRA_CAMPAIGN_TICK_HOOK'))  { define('WPULTRA_CAMPAIGN_TICK_HOOK', 'wpultra_campaign_send_tick'); }
if (!defined('WPULTRA_CAMPAIGN_START_HOOK')) { define('WPULTRA_CAMPAIGN_START_HOOK', 'wpultra_campaign_send_start'); }

/* =====================================================================
 * PURE — no WordPress calls. Unit-testable.
 * ===================================================================== */

/** PURE. The campaign lifecycle statuses. */
function wpultra_campaign_status_options(): array {
    return ['draft', 'scheduled', 'sending', 'sent', 'cancelled'];
}

/** PURE. The recipient source kinds. */
function wpultra_campaign_source_options(): array {
    return ['users', 'emails', 'newsletter'];
}

/** PURE. Fresh default meta blob for a new campaign. */
function wpultra_campaign_default_meta(): array {
    return [
        'subject'         => '',
        'status'          => 'draft',
        'recipients_spec' => [],
        'queue'           => [],
        'cursor'          => 0,
        'sent_count'      => 0,
        'fail_count'      => 0,
        'batch_size'      => 20,
        'scheduled_at'    => null,
        'started_at'      => null,
        'finished_at'     => null,
        'last_error'      => '',
    ];
}

/**
 * PURE. Lowercase + trim, keep only email-shaped strings, dedupe, reindex.
 * @param array<int,mixed> $emails
 * @return array<int,string>
 */
function wpultra_campaign_clean_emails(array $emails): array {
    $out = [];
    foreach ($emails as $e) {
        if (!is_string($e) && !is_numeric($e)) { continue; }
        $e = strtolower(trim((string) $e));
        if ($e === '' || strlen($e) > 254) { continue; }
        if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $e)) { continue; }
        $out[$e] = true;
    }
    return array_keys($out);
}

/** PURE. Clamp a batch size into the allowed 1..100 window. */
function wpultra_campaign_clamp_batch_size($value, int $default = 20): int {
    if (!is_numeric($value)) { return $default; }
    return max(1, min(100, (int) $value));
}

/**
 * PURE. The next slice of the queue starting at $cursor.
 * @param array<int,string> $queue
 * @return array<int,string>
 */
function wpultra_campaign_next_batch(array $queue, int $cursor, int $batch_size): array {
    if ($cursor < 0) { $cursor = 0; }
    if ($batch_size < 1) { $batch_size = 1; }
    return array_values(array_slice($queue, $cursor, $batch_size));
}

/**
 * PURE. Progress summary derived from the meta blob.
 * pct is cursor-based (processed / total), not delivery-success based.
 * @return array{total:int,sent:int,failed:int,remaining:int,pct:int}
 */
function wpultra_campaign_progress(array $meta): array {
    $total  = count((array) ($meta['queue'] ?? []));
    $cursor = max(0, (int) ($meta['cursor'] ?? 0));
    return [
        'total'     => $total,
        'sent'      => (int) ($meta['sent_count'] ?? 0),
        'failed'    => (int) ($meta['fail_count'] ?? 0),
        'remaining' => max(0, $total - $cursor),
        'pct'       => $total > 0 ? (int) round(min($cursor, $total) / $total * 100) : 0,
    ];
}

/**
 * PURE. Validate create/update input. $now is injected so the future-check on
 * send_at is deterministic under test (never calls time()).
 * Expects: name, subject, body_html, recipients {source, role?, emails?},
 * optional batch_size (1..100) and optional numeric send_at (> $now).
 * @return true|string  true when valid, else a human error message.
 */
function wpultra_campaign_validate_input(array $in, int $now) {
    if (trim((string) ($in['name'] ?? '')) === '')    { return 'name is required.'; }
    if (trim((string) ($in['subject'] ?? '')) === '') { return 'subject is required.'; }
    if (trim((string) ($in['body_html'] ?? '')) === '') { return 'body_html is required.'; }

    $rec = $in['recipients'] ?? null;
    if (!is_array($rec)) {
        return 'recipients is required: {source: users|emails|newsletter, role?, emails?}.';
    }
    $source = (string) ($rec['source'] ?? '');
    if (!in_array($source, wpultra_campaign_source_options(), true)) {
        return 'recipients.source must be one of: ' . implode(', ', wpultra_campaign_source_options()) . '.';
    }
    if ($source === 'emails') {
        $clean = wpultra_campaign_clean_emails((array) ($rec['emails'] ?? []));
        if ($clean === []) { return 'recipients.emails must contain at least one valid email address.'; }
    }
    if ($source === 'users' && isset($rec['role']) && !is_string($rec['role'])) {
        return 'recipients.role must be a string role slug.';
    }

    if (array_key_exists('batch_size', $in) && $in['batch_size'] !== null) {
        if (!is_numeric($in['batch_size'])) { return 'batch_size must be a number.'; }
        $b = (int) $in['batch_size'];
        if ($b < 1 || $b > 100) { return 'batch_size must be between 1 and 100.'; }
    }

    if (array_key_exists('send_at', $in) && $in['send_at'] !== null) {
        if (!is_numeric($in['send_at'])) { return 'send_at must be a unix timestamp (resolve date strings before validating).'; }
        if ((int) $in['send_at'] <= $now) { return 'send_at must be in the future.'; }
    }

    return true;
}

/**
 * PURE. Parse a send_at value: a unix timestamp (int or numeric string) or a
 * 'Y-m-d H:i[:s]' site-local time string. $tz_offset_seconds is the site's
 * UTC offset (injected — no WP call), used to convert local → unix UTC.
 * @return int|false  unix timestamp, or false when unparseable.
 */
function wpultra_campaign_parse_send_at($send_at, int $tz_offset_seconds = 0) {
    if (is_int($send_at)) { return $send_at; }
    if (is_float($send_at)) { return (int) $send_at; }
    if (!is_string($send_at)) { return false; }
    $send_at = trim($send_at);
    if ($send_at === '') { return false; }
    if (preg_match('/^\d+$/', $send_at)) { return (int) $send_at; }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $send_at, $m)) {
        return false;
    }
    $utc = gmmktime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), (int) $m[2], (int) $m[3], (int) $m[1]);
    if ($utc === false) { return false; }
    return $utc - $tz_offset_seconds;
}

/**
 * PURE. Safe output shape for one campaign — never dumps the full queue or
 * the full explicit email list, only counts + a first-3 preview.
 */
function wpultra_campaign_shape(array $meta, int $id = 0, string $name = ''): array {
    $queue = array_values((array) ($meta['queue'] ?? []));

    $spec    = (array) ($meta['recipients_spec'] ?? []);
    $specOut = ['source' => (string) ($spec['source'] ?? '')];
    if (isset($spec['role']) && is_string($spec['role']) && $spec['role'] !== '') {
        $specOut['role'] = $spec['role'];
    }
    if (isset($spec['emails']) && is_array($spec['emails'])) {
        $specOut['email_count']    = count($spec['emails']);
        $specOut['emails_preview'] = array_slice(array_values($spec['emails']), 0, 3);
    }

    return [
        'id'              => $id,
        'name'            => $name,
        'subject'         => (string) ($meta['subject'] ?? ''),
        'status'          => (string) ($meta['status'] ?? 'draft'),
        'batch_size'      => (int) ($meta['batch_size'] ?? 20),
        'recipients_spec' => $specOut,
        'scheduled_at'    => isset($meta['scheduled_at']) && $meta['scheduled_at'] !== null ? (int) $meta['scheduled_at'] : null,
        'started_at'      => isset($meta['started_at']) && $meta['started_at'] !== null ? (int) $meta['started_at'] : null,
        'finished_at'     => isset($meta['finished_at']) && $meta['finished_at'] !== null ? (int) $meta['finished_at'] : null,
        'last_error'      => (string) ($meta['last_error'] ?? ''),
        'queue_preview'   => array_slice($queue, 0, 3),
        'progress'        => wpultra_campaign_progress($meta),
    ];
}

/**
 * WP_Error factory usable both under WordPress (helpers.php loaded) and under
 * the bare test harness. Mirrors wpultra_news_err().
 */
function wpultra_campaign_err(string $code, string $message): WP_Error {
    if (function_exists('wpultra_err')) { return wpultra_err($code, $message); }
    return new WP_Error($code, $message);
}

/* =====================================================================
 * WP-TOUCHING — runtime boot, CPT, persistence, recipients, sending.
 * ===================================================================== */

/**
 * Always-on runtime. Called by the controller on plugins_loaded — cheap and
 * idempotent (a second call is a no-op). Registers the private CPT on init
 * and the two cron action handlers (batch tick + scheduled start).
 */
function wpultra_campaigns_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    if (!function_exists('add_action')) { return; }

    if (function_exists('did_action') && did_action('init')) {
        wpultra_campaign_register_cpt();
    } else {
        add_action('init', 'wpultra_campaign_register_cpt');
    }
    add_action(WPULTRA_CAMPAIGN_TICK_HOOK, 'wpultra_campaign_send_tick_handler', 10, 1);
    add_action(WPULTRA_CAMPAIGN_START_HOOK, 'wpultra_campaign_send_start_handler', 10, 1);
}

function wpultra_campaign_register_cpt(): void {
    if (!function_exists('register_post_type')) { return; }
    register_post_type(WPULTRA_CAMPAIGN_CPT, [
        'public'       => false,
        'show_ui'      => false,
        'show_in_rest' => false,
        'supports'     => ['title', 'editor'],
        'rewrite'      => false,
    ]);
}

/** Load a campaign. @return array{id:int,name:string,body:string,meta:array}|null */
function wpultra_campaign_load(int $id): ?array {
    if (!function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_CAMPAIGN_CPT) { return null; }
    $meta = function_exists('get_post_meta') ? get_post_meta($id, WPULTRA_CAMPAIGN_META, true) : [];
    if (!is_array($meta)) { $meta = []; }
    return [
        'id'   => $id,
        'name' => (string) $post->post_title,
        'body' => (string) $post->post_content,
        'meta' => array_merge(wpultra_campaign_default_meta(), $meta),
    ];
}

function wpultra_campaign_save_meta(int $id, array $meta): void {
    if (function_exists('update_post_meta')) {
        update_post_meta($id, WPULTRA_CAMPAIGN_META, $meta);
    }
}

/**
 * Resolve a recipients spec to a clean, deduped email list.
 * Sources: 'users' (get_users, optional role), 'emails' (explicit list),
 * 'newsletter' (wpultra_newsletter_subscribers() if some future engine defines
 * it, else a direct MailPoet getSubscribers() read probed via method_exists —
 * MC4WP stores its subscribers remotely in Mailchimp so it cannot serve as a
 * local recipient source).
 * @return array<int,string>|WP_Error
 */
function wpultra_campaign_resolve_recipients(array $spec) {
    $source = (string) ($spec['source'] ?? '');

    if ($source === 'emails') {
        $emails = wpultra_campaign_clean_emails((array) ($spec['emails'] ?? []));
        if ($emails === []) {
            return wpultra_campaign_err('no_recipients', 'The explicit email list contains no valid addresses.');
        }
        return $emails;
    }

    if ($source === 'users') {
        if (!function_exists('get_users')) {
            return wpultra_campaign_err('wp_unavailable', 'get_users() is unavailable in this context.');
        }
        $args = ['fields' => ['user_email'], 'number' => -1];
        $role = trim((string) ($spec['role'] ?? ''));
        if ($role !== '') { $args['role__in'] = [$role]; }
        $emails = [];
        foreach ((array) get_users($args) as $u) {
            $emails[] = is_object($u) ? (string) ($u->user_email ?? '') : (string) (((array) $u)['user_email'] ?? '');
        }
        $emails = wpultra_campaign_clean_emails($emails);
        if ($emails === []) {
            return wpultra_campaign_err('no_recipients', $role !== '' ? "No users with role '$role' have a valid email." : 'No users with a valid email were found.');
        }
        return $emails;
    }

    if ($source === 'newsletter') {
        // Future-proof hook point: a dedicated subscriber-read function wins.
        if (function_exists('wpultra_newsletter_subscribers')) {
            $emails = wpultra_newsletter_subscribers();
            if (is_wp_error($emails)) { return $emails; }
            $emails = wpultra_campaign_clean_emails((array) $emails);
            if ($emails === []) { return wpultra_campaign_err('no_recipients', 'The newsletter source returned no valid addresses.'); }
            return $emails;
        }
        // Direct MailPoet read — same class probe as includes/newsletter/engine.php,
        // plus a method_exists() guard so we never call an API this MailPoet
        // version does not expose.
        if (class_exists('\\MailPoet\\API\\API')) {
            try {
                $api = \MailPoet\API\API::MP('v1');
                if (method_exists($api, 'getSubscribers')) {
                    $emails = [];
                    $limit  = 500;
                    $offset = 0;
                    do {
                        $page = (array) $api->getSubscribers(['status' => 'subscribed'], $limit, $offset);
                        foreach ($page as $sub) {
                            $arr = (array) $sub;
                            if (!empty($arr['email'])) { $emails[] = (string) $arr['email']; }
                        }
                        $offset += $limit;
                    } while (count($page) === $limit && $offset < 50000);
                    $emails = wpultra_campaign_clean_emails($emails);
                    if ($emails === []) { return wpultra_campaign_err('no_recipients', 'MailPoet has no subscribed addresses.'); }
                    return $emails;
                }
                return wpultra_campaign_err('newsletter_unavailable', 'This MailPoet version does not expose getSubscribers(); use recipients source "users" or "emails" instead.');
            } catch (\Throwable $e) {
                return wpultra_campaign_err('newsletter_error', 'MailPoet subscriber read failed: ' . $e->getMessage());
            }
        }
        return wpultra_campaign_err('newsletter_unavailable', 'No local newsletter subscriber source is available: MailPoet is not active, and MC4WP keeps subscribers remotely in Mailchimp. Use recipients source "users" (optionally with role: "subscriber") or "emails" instead.');
    }

    return wpultra_campaign_err('bad_source', "Unknown recipients source '$source'. Known: " . implode(', ', wpultra_campaign_source_options()) . '.');
}

/** Mail headers: HTML content type + optional From (option wpultra_campaign_from). */
function wpultra_campaign_headers(): array {
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $from = function_exists('get_option') ? trim((string) get_option('wpultra_campaign_from', '')) : '';
    if ($from !== '') { $headers[] = 'From: ' . $from; }
    return $headers;
}

/**
 * Resolve recipients, build the queue, flip status to 'sending' and schedule
 * the first tick. Shared by send-now and the scheduled-start handler.
 * @return array{queued:int}|WP_Error
 */
function wpultra_campaign_begin_send(int $id) {
    $c = wpultra_campaign_load($id);
    if ($c === null) { return wpultra_campaign_err('not_found', "Campaign $id not found."); }
    $meta = $c['meta'];

    $queue = wpultra_campaign_resolve_recipients((array) $meta['recipients_spec']);
    if (is_wp_error($queue)) { return $queue; }
    if ($queue === []) { return wpultra_campaign_err('empty_queue', 'Recipient resolution produced an empty queue — refusing to start.'); }

    $meta['queue']      = $queue;
    $meta['cursor']     = 0;
    $meta['sent_count'] = 0;
    $meta['fail_count'] = 0;
    $meta['status']     = 'sending';
    $meta['started_at'] = time();
    $meta['last_error'] = '';
    wpultra_campaign_save_meta($id, $meta);

    if (function_exists('wp_schedule_single_event')) {
        wp_schedule_single_event(time() + 1, WPULTRA_CAMPAIGN_TICK_HOOK, [$id]);
    }
    if (function_exists('spawn_cron')) { spawn_cron(); }

    return ['queued' => count($queue)];
}

/**
 * Cron handler: the scheduled start. Flips scheduled → sending (via
 * begin_send). Any status other than 'scheduled' (cancelled, already sending)
 * makes this a no-op. On a resolution failure the campaign drops back to
 * draft with last_error set so the operator can inspect + retry.
 */
function wpultra_campaign_send_start_handler($campaign_id): void {
    $id = (int) $campaign_id;
    $c = wpultra_campaign_load($id);
    if ($c === null) { return; }
    if (($c['meta']['status'] ?? '') !== 'scheduled') { return; }

    $res = wpultra_campaign_begin_send($id);
    if (is_wp_error($res)) {
        $meta = $c['meta'];
        $meta['status']     = 'draft';
        $meta['last_error'] = 'Scheduled start failed: ' . $res->get_error_message();
        wpultra_campaign_save_meta($id, $meta);
        if (function_exists('wpultra_audit_log')) {
            wpultra_audit_log('email-campaign', "scheduled start of #$id failed: " . $res->get_error_code(), false);
        }
    }
}

/**
 * Cron handler: send one batch, advance the cursor, reschedule while the
 * queue has remaining entries. A per-email exception is swallowed and counted
 * as a failure — one bad address never kills the batch or the chain. Only
 * runs while status === 'sending' (cancel flips the status, stopping the
 * chain naturally).
 */
function wpultra_campaign_send_tick_handler($campaign_id): void {
    $id = (int) $campaign_id;
    $c = wpultra_campaign_load($id);
    if ($c === null) { return; }
    $meta = $c['meta'];
    if (($meta['status'] ?? '') !== 'sending') { return; }

    $queue   = array_values((array) ($meta['queue'] ?? []));
    $cursor  = max(0, (int) ($meta['cursor'] ?? 0));
    $batch   = wpultra_campaign_next_batch($queue, $cursor, wpultra_campaign_clamp_batch_size($meta['batch_size'] ?? 20));
    $subject = (string) ($meta['subject'] ?? '');
    $headers = wpultra_campaign_headers();

    foreach ($batch as $email) {
        try {
            $sent = function_exists('wp_mail') ? wp_mail($email, $subject, $c['body'], $headers) : false;
            if ($sent) {
                $meta['sent_count'] = (int) ($meta['sent_count'] ?? 0) + 1;
            } else {
                $meta['fail_count'] = (int) ($meta['fail_count'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $meta['fail_count'] = (int) ($meta['fail_count'] ?? 0) + 1;
            $meta['last_error'] = 'wp_mail threw for ' . $email . ': ' . $e->getMessage();
        }
    }

    $cursor += count($batch);
    $meta['cursor'] = $cursor;

    if ($cursor < count($queue)) {
        wpultra_campaign_save_meta($id, $meta);
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 5, WPULTRA_CAMPAIGN_TICK_HOOK, [$id]);
        }
        if (function_exists('spawn_cron')) { spawn_cron(); }
    } else {
        $meta['status']      = 'sent';
        $meta['finished_at'] = time();
        wpultra_campaign_save_meta($id, $meta);
        if (function_exists('wpultra_audit_log')) {
            wpultra_audit_log('email-campaign', "campaign #$id finished: sent={$meta['sent_count']} failed={$meta['fail_count']}", true);
        }
    }
}

/** Best-effort: unschedule any pending cron events for a campaign. */
function wpultra_campaign_unschedule(int $id): void {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) { return; }
    foreach ([WPULTRA_CAMPAIGN_TICK_HOOK, WPULTRA_CAMPAIGN_START_HOOK] as $hook) {
        $guard = 0;
        while (($ts = wp_next_scheduled($hook, [$id])) && $guard < 20) {
            wp_unschedule_event($ts, $hook, [$id]);
            $guard++;
        }
    }
}

/**
 * Recent campaigns (newest first), shaped, capped.
 * @return array<int,array>
 */
function wpultra_campaign_list(int $limit = 50): array {
    if (!function_exists('get_posts')) { return []; }
    $limit = max(1, min(50, $limit));
    $posts = get_posts([
        'post_type'   => WPULTRA_CAMPAIGN_CPT,
        'post_status' => 'any',
        'numberposts' => $limit,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);
    $out = [];
    foreach ((array) $posts as $post) {
        $meta = function_exists('get_post_meta') ? get_post_meta((int) $post->ID, WPULTRA_CAMPAIGN_META, true) : [];
        if (!is_array($meta)) { $meta = []; }
        $meta = array_merge(wpultra_campaign_default_meta(), $meta);
        $out[] = wpultra_campaign_shape($meta, (int) $post->ID, (string) $post->post_title);
    }
    return $out;
}
