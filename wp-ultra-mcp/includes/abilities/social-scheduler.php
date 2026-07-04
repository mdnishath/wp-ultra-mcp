<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensive engine require so this ability works regardless of load order
// (mirrors email-campaign -> includes/marketing/campaigns.php).
if (!function_exists('wpultra_social_networks') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/marketing/social-scheduler.php')) {
    require_once WPULTRA_DIR . 'includes/marketing/social-scheduler.php';
}

wp_register_ability('wpultra/social-scheduler', [
    'label'       => __('Social Media Scheduler (Calendar + Queue)', 'wp-ultra-mcp'),
    'description' => __(
        'Schedule arbitrary posts to Facebook / Instagram / X (Twitter) / LinkedIn at chosen times — a real CALENDAR + QUEUE, not a per-new-post trigger (that is the separate social-autopost ability). '
        . 'Delivery model (honest): WordPress never stores FB/IG/X/LinkedIn OAuth tokens. This scheduler owns the calendar, queue, timing and per-network message variants; when an item is DUE a WP-Cron tick (every 5 min) POSTs it to ONE automation webhook you configure (Zapier / Make / Buffer / n8n) which fans it out to the networks. Optionally HMAC-signed (header X-WPUltra-Signature: sha256 over the JSON body). '
        . 'Per-network char limits are enforced by truncating each variant on a word boundary with an ellipsis: X=280, Instagram=2200, LinkedIn=3000, Facebook=63206. '
        . 'One action-dispatched tool. Actions: '
        . 'config {webhook, hmac_secret?, default_networks?} — set the automation webhook (and optional signing secret / default network list). Do this first. '
        . 'schedule {networks, text|link, image_url?, variants?, scheduled_at, post_id?} — validate and enqueue one item. scheduled_at is a unix timestamp or a "Y-m-d H:i" SITE-LOCAL time string; must be in the future. variants is an optional {network => text} override map. '
        . 'queue-batch {items[]  OR  post_ids[] + networks + start + interval} — enqueue many at evenly-spaced future slots (e.g. one per day). start is a unix ts or "Y-m-d H:i" site-local; interval is seconds between posts (e.g. 86400 for daily). With items[], each item is a full schedule payload. With post_ids[], each source post becomes an item sharing the same networks/text at consecutive slots. '
        . 'list {status?} — the queue (optionally filtered by status: scheduled|sent|failed|cancelled). '
        . 'calendar {from, to} — scheduled items grouped by day across a range (unix ts or "Y-m-d H:i" site-local bounds). '
        . 'cancel {id} — cancel a scheduled item (already-sent items cannot be recalled). '
        . 'send-now {id, confirm:true} — deliver an item to the webhook immediately (confirm-gated: it posts publicly via the automation). '
        . 'status — webhook configured? + per-status counts. '
        . 'Examples: {action:"config", webhook:"https://hooks.zapier.com/...", default_networks:["facebook","linkedin"]} then {action:"schedule", networks:["x","linkedin"], text:"New post is live!", link:"https://site.com/p", scheduled_at:"2026-07-05 09:00", variants:{x:"New post 🔥 https://site.com/p"}}. Batch: {action:"queue-batch", post_ids:[10,11,12], networks:["facebook"], start:"2026-07-05 09:00", interval:86400}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'marketing',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['schedule', 'queue-batch', 'list', 'calendar', 'cancel', 'send-now', 'config', 'status'],
            ],
            'id'           => ['type' => 'string', 'description' => 'cancel / send-now: the queue item id.'],
            'networks'     => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['facebook', 'instagram', 'x', 'linkedin']]],
            'text'         => ['type' => 'string'],
            'link'         => ['type' => 'string'],
            'image_url'    => ['type' => 'string'],
            'variants'     => [
                'type'       => 'object',
                'properties' => [
                    'facebook'  => ['type' => 'string'],
                    'instagram' => ['type' => 'string'],
                    'x'         => ['type' => 'string'],
                    'linkedin'  => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'scheduled_at' => ['type' => ['integer', 'string'], 'description' => 'Unix timestamp or "Y-m-d H:i" site-local time.'],
            'post_id'      => ['type' => 'integer', 'description' => 'schedule: optional source WP post id.'],
            'items'        => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'queue-batch: full schedule payloads.'],
            'post_ids'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'queue-batch: source post ids to spread across slots.'],
            'start'        => ['type' => ['integer', 'string'], 'description' => 'queue-batch: first slot (unix ts or site-local "Y-m-d H:i").'],
            'interval'     => ['type' => 'integer', 'description' => 'queue-batch: seconds between slots (e.g. 86400 daily).'],
            'from'         => ['type' => ['integer', 'string'], 'description' => 'calendar: range start.'],
            'to'           => ['type' => ['integer', 'string'], 'description' => 'calendar: range end.'],
            'status'       => ['type' => 'string', 'enum' => ['scheduled', 'sent', 'failed', 'cancelled']],
            'webhook'      => ['type' => 'string', 'description' => 'config: automation webhook URL.'],
            'hmac_secret'  => ['type' => 'string', 'description' => 'config: optional HMAC signing secret.'],
            'default_networks' => ['type' => 'array', 'items' => ['type' => 'string']],
            'confirm'      => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'action'   => ['type' => 'string'],
            'item'     => ['type' => 'object'],
            'items'    => ['type' => 'array'],
            'calendar' => ['type' => 'object'],
            'counts'   => ['type' => 'object'],
            'queued'   => ['type' => 'integer'],
            'message'  => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_social_scheduler_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_social_scheduler_ability(array $input) {
    if (!function_exists('wpultra_social_networks')) {
        return wpultra_err('social_engine_missing', 'The social-scheduler engine (includes/marketing/social-scheduler.php) is not loaded.');
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'config':      return wpultra_social_ability_config($input);
        case 'schedule':    return wpultra_social_ability_schedule($input);
        case 'queue-batch': return wpultra_social_ability_queue_batch($input);
        case 'list':        return wpultra_social_ability_list($input);
        case 'calendar':    return wpultra_social_ability_calendar($input);
        case 'cancel':      return wpultra_social_ability_cancel($input);
        case 'send-now':    return wpultra_social_ability_send_now($input);
        case 'status':      return wpultra_social_ability_status();
        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Known: config, schedule, queue-batch, list, calendar, cancel, send-now, status.");
    }
}

/** Parse a unix ts OR a "Y-m-d H:i" site-local string into a UTC unix ts. @return int|false */
function wpultra_social_parse_time($val) {
    if (is_int($val)) { return $val; }
    if (is_numeric($val)) { return (int) $val; }
    if (!is_string($val) || trim($val) === '') { return false; }
    $offset = function_exists('get_option') ? (int) round(((float) get_option('gmt_offset', 0)) * 3600) : 0;
    $local  = strtotime(trim($val));
    if ($local === false) { return false; }
    // strtotime read it as server-local UTC; shift by the site offset to get true UTC.
    return $local - $offset;
}

/** @return array|WP_Error */
function wpultra_social_ability_config(array $input) {
    $changed = [];
    if (array_key_exists('webhook', $input)) {
        $url = trim((string) $input['webhook']);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            return wpultra_err('invalid_webhook', 'webhook must be an http(s) URL (or "" to clear).');
        }
        update_option(WPULTRA_SOCIAL_WEBHOOK_OPT, $url, false);
        $changed[] = 'webhook';
    }
    if (array_key_exists('hmac_secret', $input)) {
        update_option(WPULTRA_SOCIAL_HMAC_OPT, (string) $input['hmac_secret'], false);
        $changed[] = 'hmac_secret';
    }
    if (array_key_exists('default_networks', $input)) {
        $known = wpultra_social_networks();
        $nets  = array_values(array_filter((array) $input['default_networks'], static fn($n) => is_string($n) && in_array($n, $known, true)));
        update_option(WPULTRA_SOCIAL_DEFAULTS_OPT, ['networks' => $nets], false);
        $changed[] = 'default_networks';
    }
    if ($changed === []) {
        return wpultra_err('nothing_to_set', 'Pass webhook, hmac_secret and/or default_networks.');
    }
    wpultra_audit_log('social-scheduler', 'config updated: ' . implode(', ', $changed), true);
    return wpultra_ok([
        'action'  => 'config',
        'message' => 'Updated: ' . implode(', ', $changed) . '.',
        'counts'  => ['webhook_configured' => wpultra_social_webhook() !== '' ? 1 : 0],
    ]);
}

/** Build + validate a single item from schedule-shaped input. @return array|WP_Error */
function wpultra_social_build_item(array $input, int $now) {
    $networks = isset($input['networks']) && is_array($input['networks']) && $input['networks'] !== []
        ? array_values($input['networks'])
        : (array) (function_exists('get_option') ? (get_option(WPULTRA_SOCIAL_DEFAULTS_OPT, [])['networks'] ?? []) : []);

    $content = [
        'text'      => (string) ($input['text'] ?? ''),
        'link'      => (string) ($input['link'] ?? ''),
        'image_url' => (string) ($input['image_url'] ?? ''),
    ];
    $variants = [];
    if (isset($input['variants']) && is_array($input['variants'])) {
        foreach ($input['variants'] as $net => $txt) {
            if (is_string($net) && is_string($txt) && $txt !== '') { $variants[$net] = $txt; }
        }
    }

    $ts = wpultra_social_parse_time($input['scheduled_at'] ?? null);
    if ($ts === false) {
        return wpultra_err('bad_scheduled_at', 'scheduled_at must be a unix timestamp or a "Y-m-d H:i" site-local time string.');
    }

    $item = wpultra_social_make_item(
        wpultra_social_new_id(),
        $networks,
        $content,
        $variants,
        (int) $ts,
        $now,
        (int) ($input['post_id'] ?? 0)
    );

    $valid = wpultra_social_validate_item($item);
    if ($valid !== true) { return wpultra_err('invalid_item', (string) $valid); }
    if ($ts <= $now) { return wpultra_err('scheduled_at_past', 'scheduled_at must be in the future.'); }
    return $item;
}

/** @return array|WP_Error */
function wpultra_social_ability_schedule(array $input) {
    $now  = time();
    $item = wpultra_social_build_item($input, $now);
    if (is_wp_error($item)) { return $item; }
    wpultra_social_enqueue($item);
    wpultra_audit_log('social-scheduler', "scheduled {$item['id']} for {$item['scheduled_at']}", true);
    return wpultra_ok([
        'action'  => 'schedule',
        'item'    => $item,
        'message' => 'Scheduled for ' . gmdate('Y-m-d H:i', (int) $item['scheduled_at']) . ' UTC across ' . implode(', ', $item['networks']) . '.',
    ]);
}

/** @return array|WP_Error */
function wpultra_social_ability_queue_batch(array $input) {
    $now = time();

    // Mode A: explicit items[]. When start + interval are supplied, items that
    // don't carry their own scheduled_at are auto-spaced across future slots
    // (so "queue these 3 posts one per day from tomorrow" works with items[] too,
    // not only post_ids[]).
    if (isset($input['items']) && is_array($input['items']) && $input['items'] !== []) {
        $auto_slots = [];
        $start_raw  = wpultra_social_parse_time($input['start'] ?? null);
        $interval   = (int) ($input['interval'] ?? 0);
        if ($start_raw !== false && $interval >= 1) {
            $auto_slots = wpultra_social_next_slots((int) $start_raw, count($input['items']), $interval);
        }
        $built = [];
        foreach ($input['items'] as $i => $raw) {
            if (!is_array($raw)) { return wpultra_err('bad_item', "items[$i] must be an object."); }
            if (!isset($raw['scheduled_at']) && isset($auto_slots[$i])) { $raw['scheduled_at'] = (int) $auto_slots[$i]; }
            $item = wpultra_social_build_item($raw, $now);
            if (is_wp_error($item)) { return wpultra_err('invalid_item', "items[$i]: " . $item->get_error_message()); }
            $built[] = $item;
        }
        foreach ($built as $item) { wpultra_social_enqueue($item); }
        wpultra_audit_log('social-scheduler', 'queue-batch enqueued ' . count($built) . ' items', true);
        return wpultra_ok(['action' => 'queue-batch', 'queued' => count($built), 'items' => $built, 'message' => count($built) . ' items queued.']);
    }

    // Mode B: post_ids[] + networks + start + interval -> spaced slots.
    $post_ids = array_values(array_filter((array) ($input['post_ids'] ?? []), 'is_numeric'));
    if ($post_ids === []) {
        return wpultra_err('nothing_to_queue', 'Provide items[] OR post_ids[] with networks + start + interval.');
    }
    $networks = isset($input['networks']) && is_array($input['networks']) && $input['networks'] !== []
        ? array_values($input['networks'])
        : (array) (get_option(WPULTRA_SOCIAL_DEFAULTS_OPT, [])['networks'] ?? []);
    if ($networks === []) {
        return wpultra_err('no_networks', 'networks is required (or set default_networks via config).');
    }
    $start = wpultra_social_parse_time($input['start'] ?? null);
    if ($start === false) { return wpultra_err('bad_start', 'start must be a unix ts or "Y-m-d H:i" site-local time.'); }
    $interval = (int) ($input['interval'] ?? 0);
    if ($interval < 1) { return wpultra_err('bad_interval', 'interval must be a positive number of seconds (e.g. 86400 for daily).'); }

    $slots = wpultra_social_next_slots((int) $start, count($post_ids), $interval);
    $built = [];
    foreach ($post_ids as $idx => $pid) {
        $pid = (int) $pid;
        $text = '';
        $link = '';
        if (function_exists('get_the_title')) { $text = (string) get_the_title($pid); }
        if (function_exists('get_permalink')) { $link = (string) get_permalink($pid); }
        $item = wpultra_social_make_item(
            wpultra_social_new_id(),
            $networks,
            ['text' => $text, 'link' => $link, 'image_url' => ''],
            [],
            (int) $slots[$idx],
            $now,
            $pid
        );
        $valid = wpultra_social_validate_item($item);
        if ($valid !== true) { return wpultra_err('invalid_item', "post $pid: " . (string) $valid); }
        if ((int) $slots[$idx] <= $now) { return wpultra_err('slot_past', 'start must be in the future.'); }
        $built[] = $item;
    }
    foreach ($built as $item) { wpultra_social_enqueue($item); }
    wpultra_audit_log('social-scheduler', 'queue-batch enqueued ' . count($built) . ' posts', true);
    return wpultra_ok([
        'action'  => 'queue-batch',
        'queued'  => count($built),
        'items'   => $built,
        'message' => count($built) . ' posts queued from ' . gmdate('Y-m-d H:i', (int) $start) . ' UTC, every ' . $interval . 's.',
    ]);
}

/** @return array */
function wpultra_social_ability_list(array $input) {
    $queue  = wpultra_social_queue_load();
    $status = isset($input['status']) ? (string) $input['status'] : '';
    if ($status !== '') {
        $queue = array_values(array_filter($queue, static fn($i) => (string) ($i['status'] ?? '') === $status));
    }
    return wpultra_ok(['action' => 'list', 'items' => $queue, 'counts' => wpultra_social_counts(wpultra_social_queue_load())]);
}

/** @return array|WP_Error */
function wpultra_social_ability_calendar(array $input) {
    $from = wpultra_social_parse_time($input['from'] ?? null);
    $to   = wpultra_social_parse_time($input['to'] ?? null);
    if ($from === false || $to === false) {
        return wpultra_err('bad_range', 'from and to must each be a unix ts or "Y-m-d H:i" site-local time.');
    }
    if ($to < $from) { return wpultra_err('bad_range', 'to must be >= from.'); }
    $cal = wpultra_social_calendar(wpultra_social_queue_load(), (int) $from, (int) $to);
    return wpultra_ok(['action' => 'calendar', 'calendar' => $cal, 'counts' => ['days' => count($cal)]]);
}

/** @return array|WP_Error */
function wpultra_social_ability_cancel(array $input) {
    $id = (string) ($input['id'] ?? '');
    if ($id === '') { return wpultra_err('missing_id', 'id is required to cancel.'); }
    $item = wpultra_social_find($id);
    if ($item === null) { return wpultra_err('not_found', "Item '$id' not found."); }
    if (($item['status'] ?? '') !== 'scheduled') {
        return wpultra_err('not_cancellable', "Only scheduled items can be cancelled (status: {$item['status']}).");
    }
    $item['status'] = 'cancelled';
    wpultra_social_replace($item);
    wpultra_audit_log('social-scheduler', "cancelled $id", true);
    return wpultra_ok(['action' => 'cancel', 'item' => $item, 'message' => "Item $id cancelled."]);
}

/** @return array|WP_Error */
function wpultra_social_ability_send_now(array $input) {
    $id = (string) ($input['id'] ?? '');
    if ($id === '') { return wpultra_err('missing_id', 'id is required for send-now.'); }
    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('unconfirmed', 'send-now posts publicly via your automation immediately. Re-run with confirm: true.');
    }
    $item = wpultra_social_find($id);
    if ($item === null) { return wpultra_err('not_found', "Item '$id' not found."); }
    if (!in_array(($item['status'] ?? ''), ['scheduled', 'failed'], true)) {
        return wpultra_err('bad_status', "Cannot send an item that is '{$item['status']}'.");
    }

    [$ok, $detail] = wpultra_social_deliver($item);
    $item['attempts'] = (int) ($item['attempts'] ?? 0) + 1;
    $item['result']   = $detail;
    if ($ok) { $item['status'] = 'sent'; $item['sent_at'] = time(); }
    else { $item['status'] = 'failed'; }
    wpultra_social_replace($item);
    wpultra_audit_log('social-scheduler', "send-now $id ok=" . ($ok ? '1' : '0') . " ($detail)", $ok);

    if (!$ok) { return wpultra_err('delivery_failed', $detail, ['item' => $item]); }
    return wpultra_ok(['action' => 'send-now', 'item' => $item, 'message' => $detail]);
}

/** @return array */
function wpultra_social_ability_status() {
    $webhook = wpultra_social_webhook();
    return wpultra_ok([
        'action'  => 'status',
        'counts'  => wpultra_social_counts(wpultra_social_queue_load()),
        'message' => $webhook !== ''
            ? 'Automation webhook configured. Due items post every ~5 min via WP-Cron.'
            : 'No automation webhook configured yet — run action:config with a Zapier/Make/Buffer webhook URL before scheduling.',
    ]);
}
