<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Event triggers: when a WordPress event fires (post published, comment posted,
 * user registered, WooCommerce order placed, form submitted), run a configured
 * action — POST to an external webhook, auto-run a saved playbook, or just log
 * the event for the AI to poll. Reacts to the site instead of only being driven
 * by it.
 *
 * Delivery is ASYNC: the WP hook records the event to a capped log and schedules
 * a one-off cron dispatch, so a slow webhook or a heavy playbook never blocks the
 * checkout / publish / comment request that triggered it.
 *
 * Store: option `wpultra_triggers` (array of trigger defs), option
 * `wpultra_trigger_log` (capped ring of fired events, newest first).
 */

const WPULTRA_TRIGGERS_OPTION   = 'wpultra_triggers';
const WPULTRA_TRIGGER_LOG_OPTION = 'wpultra_trigger_log';
const WPULTRA_TRIGGER_LOG_CAP    = 100;
const WPULTRA_TRIGGER_DISPATCH_HOOK = 'wpultra_trigger_dispatch';

/* ------------------------------------------------------------------ *
 * PURE helpers — no WordPress.
 * ------------------------------------------------------------------ */

/** event slug => human label. The events the engine knows how to hook. */
function wpultra_triggers_supported_events(): array {
    return [
        'post_published'  => 'A post/page/CPT is published',
        'post_updated'    => 'An already-published post is updated',
        'comment_posted'  => 'A comment is submitted',
        'user_registered' => 'A new user registers',
        'order_placed'    => 'A WooCommerce order is created',
        'order_status'    => 'A WooCommerce order changes status',
        'form_submitted'  => 'A form is submitted (CF7 / WPForms / Gravity / Fluent)',
    ];
}

/** action types a trigger can perform. */
function wpultra_triggers_action_types(): array {
    return ['webhook', 'playbook', 'log'];
}

/** Pure: next id = max+1. */
function wpultra_triggers_next_id(array $triggers): int {
    $max = 0;
    foreach ($triggers as $t) { $max = max($max, (int) ($t['id'] ?? 0)); }
    return $max + 1;
}

/** Pure: enabled triggers bound to $event. */
function wpultra_triggers_match(array $triggers, string $event): array {
    $out = [];
    foreach ($triggers as $t) {
        if ((string) ($t['event'] ?? '') !== $event) { continue; }
        if (($t['enabled'] ?? true) === false) { continue; }
        $out[] = $t;
    }
    return $out;
}

/** Pure: validate a trigger definition. @return true|string */
function wpultra_triggers_validate(array $def) {
    $event = (string) ($def['event'] ?? '');
    if (!isset(wpultra_triggers_supported_events()[$event])) {
        return "Unknown event '$event'. Supported: " . implode(', ', array_keys(wpultra_triggers_supported_events())) . '.';
    }
    $action = (string) ($def['action_type'] ?? '');
    if (!in_array($action, wpultra_triggers_action_types(), true)) {
        return "action_type must be one of: " . implode(', ', wpultra_triggers_action_types()) . '.';
    }
    if ($action === 'webhook') {
        $url = (string) ($def['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) { return 'webhook triggers require an http(s) url.'; }
    }
    if ($action === 'playbook' && (string) ($def['playbook'] ?? '') === '') {
        return 'playbook triggers require a saved playbook slug.';
    }
    return true;
}

/** Pure: build the event payload handed to a webhook/playbook/log. */
function wpultra_triggers_build_payload(string $event, array $data, string $site_url = '', string $fired_at = ''): array {
    return [
        'event'    => $event,
        'site'     => $site_url,
        'fired_at' => $fired_at,
        'data'     => $data,
    ];
}

/** Pure: prepend to a capped log (newest first). */
function wpultra_triggers_log_push(array $log, array $entry, int $cap = WPULTRA_TRIGGER_LOG_CAP): array {
    array_unshift($log, $entry);
    if (count($log) > $cap) { $log = array_slice($log, 0, $cap); }
    return array_values($log);
}

/** Pure: compact trigger shape for listing (drops nothing sensitive — no secrets stored). */
function wpultra_triggers_shape(array $t): array {
    return [
        'id'          => (int) ($t['id'] ?? 0),
        'event'       => (string) ($t['event'] ?? ''),
        'action_type' => (string) ($t['action_type'] ?? ''),
        'target'      => (string) ($t['url'] ?? $t['playbook'] ?? ''),
        'enabled'     => ($t['enabled'] ?? true) !== false,
        'label'       => (string) ($t['label'] ?? ''),
        'created'     => (string) ($t['created'] ?? ''),
    ];
}

/* ------------------------------------------------------------------ *
 * Store.
 * ------------------------------------------------------------------ */

function wpultra_triggers_load(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_TRIGGERS_OPTION, []) : [];
    return is_array($v) ? $v : [];
}
function wpultra_triggers_save(array $triggers): void {
    if (function_exists('update_option')) { update_option(WPULTRA_TRIGGERS_OPTION, $triggers, false); }
}
function wpultra_triggers_log_load(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_TRIGGER_LOG_OPTION, []) : [];
    return is_array($v) ? $v : [];
}
function wpultra_triggers_log_save(array $log): void {
    if (function_exists('update_option')) { update_option(WPULTRA_TRIGGER_LOG_OPTION, $log, false); }
}

/** Create a trigger. @return int id */
function wpultra_triggers_create(array $def): int {
    $triggers = wpultra_triggers_load();
    $id = wpultra_triggers_next_id($triggers);
    $def['id'] = $id;
    $def['enabled'] = ($def['enabled'] ?? true) !== false;
    $def['created'] = function_exists('current_time') ? (string) current_time('mysql', true) : '';
    $triggers[] = $def;
    wpultra_triggers_save($triggers);
    return $id;
}

/** Delete a trigger by id. */
function wpultra_triggers_delete(int $id): bool {
    $triggers = wpultra_triggers_load();
    $before = count($triggers);
    $triggers = array_values(array_filter($triggers, static fn($t) => (int) ($t['id'] ?? 0) !== $id));
    wpultra_triggers_save($triggers);
    return count($triggers) < $before;
}

/** Enable/disable a trigger by id. */
function wpultra_triggers_set_enabled(int $id, bool $enabled): bool {
    $triggers = wpultra_triggers_load();
    $found = false;
    foreach ($triggers as &$t) {
        if ((int) ($t['id'] ?? 0) === $id) { $t['enabled'] = $enabled; $found = true; break; }
    }
    unset($t);
    if ($found) { wpultra_triggers_save($triggers); }
    return $found;
}

/* ------------------------------------------------------------------ *
 * Firing (thin WP wrappers) — record + schedule async dispatch.
 * ------------------------------------------------------------------ */

/**
 * Called from each WP event hook. Records the event to the log once, then for
 * every matching trigger schedules an async cron dispatch (webhook/playbook) or
 * just notes the log hit. Never throws into the host request.
 */
function wpultra_triggers_fire(string $event, array $data): void {
    try {
        if (function_exists('wpultra_category_enabled') && !wpultra_category_enabled('triggers')) { return; }
        $triggers = wpultra_triggers_match(wpultra_triggers_load(), $event);
        if ($triggers === []) { return; }

        $site = function_exists('home_url') ? (string) home_url() : '';
        $when = function_exists('current_time') ? (string) current_time('mysql', true) : '';
        $payload = wpultra_triggers_build_payload($event, $data, $site, $when);

        foreach ($triggers as $t) {
            $id = (int) ($t['id'] ?? 0);
            // Always log the hit so the AI can poll trigger-log even for 'log' actions.
            $log = wpultra_triggers_log_load();
            $log = wpultra_triggers_log_push($log, [
                'trigger_id'  => $id,
                'event'       => $event,
                'action_type' => (string) ($t['action_type'] ?? ''),
                'fired_at'    => $when,
                'summary'     => wpultra_triggers_summarize($event, $data),
            ]);
            wpultra_triggers_log_save($log);

            // webhook / playbook run async so a slow endpoint can't block checkout/publish.
            $action = (string) ($t['action_type'] ?? '');
            if (($action === 'webhook' || $action === 'playbook') && function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time(), WPULTRA_TRIGGER_DISPATCH_HOOK, [$id, $payload]);
                if (function_exists('spawn_cron')) { spawn_cron(); }
            }
        }
    } catch (\Throwable $e) {
        // Never let a trigger break the underlying WP action.
    }
}

/** Pure-ish: one-line human summary of an event for the log. */
function wpultra_triggers_summarize(string $event, array $data): string {
    switch ($event) {
        case 'post_published':
        case 'post_updated':   return trim(($data['post_type'] ?? 'post') . ' #' . ($data['post_id'] ?? '?') . ' "' . ($data['title'] ?? '') . '"');
        case 'comment_posted': return 'comment #' . ($data['comment_id'] ?? '?') . ' on post #' . ($data['post_id'] ?? '?');
        case 'user_registered':return 'user #' . ($data['user_id'] ?? '?') . ' (' . ($data['email'] ?? '') . ')';
        case 'order_placed':
        case 'order_status':   return 'order #' . ($data['order_id'] ?? '?') . ' status=' . ($data['status'] ?? '');
        case 'form_submitted': return ($data['plugin'] ?? 'form') . ' form "' . ($data['form'] ?? '') . '"';
        default:               return $event;
    }
}

/**
 * Async cron dispatcher: perform one trigger's action with the captured payload.
 * Registered on WPULTRA_TRIGGER_DISPATCH_HOOK.
 */
function wpultra_triggers_dispatch(int $id, array $payload): void {
    try {
        $triggers = wpultra_triggers_load();
        $trigger = null;
        foreach ($triggers as $t) { if ((int) ($t['id'] ?? 0) === $id) { $trigger = $t; break; } }
        if ($trigger === null || ($trigger['enabled'] ?? true) === false) { return; }

        $action = (string) ($trigger['action_type'] ?? '');
        if ($action === 'webhook') {
            $url = (string) ($trigger['url'] ?? '');
            $headers = ['Content-Type' => 'application/json'];
            if (!empty($trigger['secret'])) { $headers['X-WPUltra-Signature'] = hash_hmac('sha256', (string) wp_json_encode($payload), (string) $trigger['secret']); }
            wp_safe_remote_post($url, [
                'timeout' => 15,
                'headers' => $headers,
                'body'    => (string) wp_json_encode($payload),
            ]);
            wpultra_audit_log('trigger-webhook', "POST $url for {$payload['event']}", true);
        } elseif ($action === 'playbook') {
            $slug = (string) ($trigger['playbook'] ?? '');
            $doc = function_exists('wpultra_playbook_load') ? wpultra_playbook_load($slug) : null;
            if ($doc === null) { return; }
            $parsed = wpultra_playbook_parse($doc);
            if ($parsed === null) { return; }
            // Dispatch runs on a WP-Cron request, which may not have fired the
            // abilities registry — the playbook's steps call wp_get_ability(), so
            // force registration once if it hasn't happened yet on this request.
            if (function_exists('did_action') && !did_action('wp_abilities_api_init')) {
                do_action('wp_abilities_api_categories_init');
                do_action('wp_abilities_api_init');
            }
            // The event payload becomes the playbook's inputs (available as {input.*}).
            wpultra_playbook_run_steps($parsed['steps'], $payload, false, true);
            wpultra_audit_log('trigger-playbook', "ran playbook '$slug' for {$payload['event']}", true);
        }
    } catch (\Throwable $e) {
        // swallow — cron will not retry a fatal.
    }
}

/* ------------------------------------------------------------------ *
 * Hook registration (always-on; events fire outside the REST loop).
 * ------------------------------------------------------------------ */

function wpultra_triggers_boot_runtime(): void {
    if (function_exists('wpultra_category_enabled') && !wpultra_category_enabled('triggers')) { return; }

    add_action(WPULTRA_TRIGGER_DISPATCH_HOOK, 'wpultra_triggers_dispatch', 10, 2);

    // Post published / updated.
    add_action('transition_post_status', function ($new, $old, $post) {
        if (!is_object($post)) { return; }
        if (in_array($post->post_type, ['revision', 'nav_menu_item'], true)) { return; }
        if (function_exists('wpultra_reserved_post_types') && in_array($post->post_type, wpultra_reserved_post_types(), true)) { return; }
        $data = ['post_id' => (int) $post->ID, 'post_type' => (string) $post->post_type, 'title' => (string) $post->post_title, 'status' => (string) $new];
        if ($new === 'publish' && $old !== 'publish') { wpultra_triggers_fire('post_published', $data); }
        elseif ($new === 'publish' && $old === 'publish') { wpultra_triggers_fire('post_updated', $data); }
    }, 10, 3);

    // Comment posted.
    add_action('wp_insert_comment', function ($comment_id, $comment) {
        $data = ['comment_id' => (int) $comment_id, 'post_id' => (int) ($comment->comment_post_ID ?? 0), 'author' => (string) ($comment->comment_author ?? '')];
        wpultra_triggers_fire('comment_posted', $data);
    }, 10, 2);

    // User registered.
    add_action('user_register', function ($user_id) {
        $u = function_exists('get_userdata') ? get_userdata((int) $user_id) : null;
        $data = ['user_id' => (int) $user_id, 'email' => $u ? (string) $u->user_email : '', 'login' => $u ? (string) $u->user_login : ''];
        wpultra_triggers_fire('user_registered', $data);
    }, 10, 1);

    // WooCommerce (guarded).
    add_action('woocommerce_new_order', function ($order_id) {
        wpultra_triggers_fire('order_placed', ['order_id' => (int) $order_id]);
    }, 10, 1);
    add_action('woocommerce_order_status_changed', function ($order_id, $from, $to) {
        wpultra_triggers_fire('order_status', ['order_id' => (int) $order_id, 'from' => (string) $from, 'status' => (string) $to]);
    }, 10, 3);

    // Forms (guarded — each hook only fires when its plugin is active).
    add_action('wpcf7_mail_sent', function ($cf) {
        $id = is_object($cf) && method_exists($cf, 'id') ? $cf->id() : 0;
        wpultra_triggers_fire('form_submitted', ['plugin' => 'cf7', 'form' => (string) $id]);
    }, 10, 1);
    add_action('wpforms_process_complete', function ($fields, $entry, $form_data) {
        wpultra_triggers_fire('form_submitted', ['plugin' => 'wpforms', 'form' => (string) ($form_data['id'] ?? '')]);
    }, 10, 3);
    add_action('gform_after_submission', function ($entry, $form) {
        wpultra_triggers_fire('form_submitted', ['plugin' => 'gravity', 'form' => (string) ($form['id'] ?? '')]);
    }, 10, 2);
    add_action('fluentform/submission_inserted', function ($entry_id, $form_data, $form) {
        wpultra_triggers_fire('form_submitted', ['plugin' => 'fluent', 'form' => (string) (is_object($form) ? ($form->id ?? '') : '')]);
    }, 10, 3);
}
