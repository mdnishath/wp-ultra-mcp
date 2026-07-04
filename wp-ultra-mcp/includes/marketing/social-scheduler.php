<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Social media scheduler engine (Roadmap D6) — a CALENDAR + QUEUE that shares
 * arbitrary posts to Facebook / Instagram / X / LinkedIn at chosen times.
 *
 * Delivery model (honest): WordPress is a poor place to hold FB/IG/X/LinkedIn
 * OAuth tokens, so this scheduler does NOT talk to any network API directly.
 * Instead it owns the calendar, the queue, the per-network message variants and
 * the timing; when an item is DUE, a WP-Cron tick POSTs it to ONE configured
 * automation webhook (Zapier / Make / Buffer / n8n) which fans it out to the
 * networks. This mirrors the existing social-autopost ability, but that one is a
 * per-new-post trigger — THIS one is a real scheduling queue for any content at
 * any time (e.g. "queue these 5 posts, one per day at 09:00").
 *
 * Storage: a single option `wpultra_social_queue` holding a list of items:
 *   {id, status: scheduled|sent|failed|cancelled, networks: [..], attempts,
 *    content: {text, link, image_url}, variants: {network => text}, post_id?,
 *    scheduled_at (unix), created_at, sent_at?, result?}
 * Config options: `wpultra_social_webhook` (the automation URL),
 * `wpultra_social_hmac` (optional signing secret),
 * `wpultra_social_defaults` ({networks: [..]}).
 *
 * Layout: PURE functions first (prefix wpultra_social_, no WordPress calls,
 * unit-tested by tests/social-scheduler.test.php), WP-touching wrappers after.
 * The always-on runtime contract is wpultra_social_boot() (cheap + idempotent),
 * called by the controller; it registers a recurring `wpultra_social_tick` cron
 * (every 5 min) that fires due items.
 */

if (!defined('WPULTRA_SOCIAL_QUEUE_OPT'))    { define('WPULTRA_SOCIAL_QUEUE_OPT', 'wpultra_social_queue'); }
if (!defined('WPULTRA_SOCIAL_WEBHOOK_OPT'))  { define('WPULTRA_SOCIAL_WEBHOOK_OPT', 'wpultra_social_webhook'); }
if (!defined('WPULTRA_SOCIAL_HMAC_OPT'))     { define('WPULTRA_SOCIAL_HMAC_OPT', 'wpultra_social_hmac'); }
if (!defined('WPULTRA_SOCIAL_DEFAULTS_OPT')) { define('WPULTRA_SOCIAL_DEFAULTS_OPT', 'wpultra_social_defaults'); }
if (!defined('WPULTRA_SOCIAL_TICK_HOOK'))    { define('WPULTRA_SOCIAL_TICK_HOOK', 'wpultra_social_tick'); }
if (!defined('WPULTRA_SOCIAL_SCHEDULE'))     { define('WPULTRA_SOCIAL_SCHEDULE', 'wpultra_social_5min'); }
if (!defined('WPULTRA_SOCIAL_MAX_ATTEMPTS')) { define('WPULTRA_SOCIAL_MAX_ATTEMPTS', 3); }

/* =====================================================================
 * PURE — no WordPress calls. Unit-testable.
 * ===================================================================== */

/** PURE. The networks this scheduler understands. */
function wpultra_social_networks(): array {
    return ['facebook', 'instagram', 'x', 'linkedin'];
}

/** PURE. The item lifecycle statuses. */
function wpultra_social_statuses(): array {
    return ['scheduled', 'sent', 'failed', 'cancelled'];
}

/**
 * PURE. Per-network character limit for the post text. X (Twitter) is the tight
 * one at 280; the others allow much longer bodies so we cap them generously.
 */
function wpultra_social_char_limit(string $network): int {
    switch ($network) {
        case 'x':         return 280;
        case 'instagram': return 2200;
        case 'facebook':  return 63206;
        case 'linkedin':  return 3000;
        default:          return 280; // unknown -> be conservative
    }
}

/** PURE. Does $text fit within $network's limit (multibyte-aware)? */
function wpultra_social_fits(string $text, string $network): bool {
    return wpultra_social_strlen($text) <= wpultra_social_char_limit($network);
}

/** PURE. Multibyte-safe string length (falls back to strlen). */
function wpultra_social_strlen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

/** PURE. Multibyte-safe substring (falls back to substr). */
function wpultra_social_substr(string $s, int $start, ?int $len = null): string {
    if (function_exists('mb_substr')) { return mb_substr($s, $start, $len); }
    return $len === null ? substr($s, $start) : substr($s, $start, $len);
}

/**
 * PURE. Truncate $text to fit $network's limit on a WORD boundary, appending an
 * ellipsis (…). Short text is returned unchanged. The ellipsis is counted
 * inside the limit so the result always fits.
 */
function wpultra_social_truncate(string $text, string $network): string {
    $limit = wpultra_social_char_limit($network);
    if (wpultra_social_strlen($text) <= $limit) { return $text; }
    $ellipsis = '…';
    $budget = $limit - wpultra_social_strlen($ellipsis);
    if ($budget < 1) { return wpultra_social_substr($text, 0, $limit); }
    $slice = wpultra_social_substr($text, 0, $budget);
    // Back off to the last whitespace so we don't cut a word in half.
    $trimmed = preg_replace('/\s+\S*$/u', '', $slice);
    if (!is_string($trimmed) || $trimmed === '') { $trimmed = $slice; }
    return rtrim($trimmed) . $ellipsis;
}

/**
 * PURE. Items that are ready to send: status === 'scheduled' AND scheduled_at
 * is at or before $now. Ignores sent / failed / cancelled / future items.
 * @param array<int,array> $queue
 * @return array<int,array>
 */
function wpultra_social_due(array $queue, int $now): array {
    $out = [];
    foreach ($queue as $item) {
        if (!is_array($item)) { continue; }
        if (($item['status'] ?? '') !== 'scheduled') { continue; }
        if ((int) ($item['scheduled_at'] ?? 0) > $now) { continue; }
        $out[] = $item;
    }
    return array_values($out);
}

/**
 * PURE. Validate a scheduling item. Returns true or a human error string.
 * Rules: networks non-empty + all valid enum; content.text non-empty OR a link;
 * scheduled_at a positive int; image_url (if present) http(s)-shaped.
 */
function wpultra_social_validate_item(array $item): bool|string {
    $networks = $item['networks'] ?? null;
    if (!is_array($networks) || $networks === []) {
        return 'networks must be a non-empty list.';
    }
    $known = wpultra_social_networks();
    foreach ($networks as $n) {
        if (!is_string($n) || !in_array($n, $known, true)) {
            return "unknown network '" . (is_string($n) ? $n : gettype($n)) . "'; valid: " . implode(', ', $known) . '.';
        }
    }

    $content = is_array($item['content'] ?? null) ? $item['content'] : [];
    $text = is_string($content['text'] ?? null) ? trim($content['text']) : '';
    $link = is_string($content['link'] ?? null) ? trim($content['link']) : '';
    if ($text === '' && $link === '') {
        return 'content.text or content.link is required.';
    }
    if ($link !== '' && !preg_match('#^https?://#i', $link)) {
        return 'content.link must be an http(s) URL.';
    }

    $img = is_string($content['image_url'] ?? null) ? trim($content['image_url']) : '';
    if ($img !== '' && !preg_match('#^https?://#i', $img)) {
        return 'content.image_url must be an http(s) URL.';
    }

    $when = $item['scheduled_at'] ?? null;
    if (!is_int($when) || $when <= 0) {
        return 'scheduled_at must be a positive unix timestamp.';
    }

    return true;
}

/**
 * PURE. Generate $count evenly-spaced future timestamps, the first at $start and
 * each subsequent one $interval_s later. Used for "queue these N posts, one per
 * day". $interval_s is clamped to a positive minimum.
 * @return array<int,int>
 */
function wpultra_social_next_slots(int $start, int $count, int $interval_s): array {
    $count = max(0, $count);
    $interval_s = max(1, $interval_s);
    $slots = [];
    for ($i = 0; $i < $count; $i++) {
        $slots[] = $start + ($i * $interval_s);
    }
    return $slots;
}

/**
 * PURE. Render the payload for ONE network from an item: the per-network text
 * (variants[network] override if set, else content.text), truncated to the
 * network's limit, plus the shared link and image.
 * @return array{network:string,text:string,link:string,image_url:string,truncated:bool,char_limit:int}
 */
function wpultra_social_render_variant(array $item, string $network): array {
    $content  = is_array($item['content'] ?? null) ? $item['content'] : [];
    $variants = is_array($item['variants'] ?? null) ? $item['variants'] : [];

    $raw = '';
    if (isset($variants[$network]) && is_string($variants[$network]) && $variants[$network] !== '') {
        $raw = $variants[$network];
    } elseif (is_string($content['text'] ?? null)) {
        $raw = $content['text'];
    }

    $text = wpultra_social_truncate($raw, $network);
    $link = is_string($content['link'] ?? null) ? trim($content['link']) : '';
    $img  = is_string($content['image_url'] ?? null) ? trim($content['image_url']) : '';

    return [
        'network'    => $network,
        'text'       => $text,
        'link'       => $link,
        'image_url'  => $img,
        'truncated'  => wpultra_social_strlen($raw) > wpultra_social_strlen($text),
        'char_limit' => wpultra_social_char_limit($network),
    ];
}

/**
 * PURE. The full multi-network body POSTed to the automation webhook: the
 * network list plus a per-network rendered variant, the shared content, and
 * identifying fields the automation (and an HMAC signer) can key on.
 * @return array
 */
function wpultra_social_webhook_payload(array $item): array {
    $networks = is_array($item['networks'] ?? null) ? array_values($item['networks']) : [];
    $variants = [];
    foreach ($networks as $network) {
        if (!is_string($network)) { continue; }
        $variants[$network] = wpultra_social_render_variant($item, $network);
    }
    $content = is_array($item['content'] ?? null) ? $item['content'] : [];

    return [
        'event'        => 'social.scheduled_post',
        'id'           => (string) ($item['id'] ?? ''),
        'networks'     => $networks,
        'variants'     => $variants,
        'content'      => [
            'text'      => is_string($content['text'] ?? null) ? $content['text'] : '',
            'link'      => is_string($content['link'] ?? null) ? $content['link'] : '',
            'image_url' => is_string($content['image_url'] ?? null) ? $content['image_url'] : '',
        ],
        'post_id'      => isset($item['post_id']) ? (int) $item['post_id'] : 0,
        'scheduled_at' => (int) ($item['scheduled_at'] ?? 0),
    ];
}

/**
 * PURE. Group SCHEDULED items by calendar day (Y-m-d in UTC) within the inclusive
 * [$from, $to] range. Returns a day-keyed map, days sorted ascending, each day a
 * list of items sorted by scheduled_at. Empty range / no matches -> [].
 * @param array<int,array> $queue
 * @return array<string,array<int,array>>
 */
function wpultra_social_calendar(array $queue, int $from, int $to): array {
    $days = [];
    foreach ($queue as $item) {
        if (!is_array($item)) { continue; }
        if (($item['status'] ?? '') !== 'scheduled') { continue; }
        $ts = (int) ($item['scheduled_at'] ?? 0);
        if ($ts < $from || $ts > $to) { continue; }
        $day = gmdate('Y-m-d', $ts);
        $days[$day][] = $item;
    }
    ksort($days);
    foreach ($days as $day => $items) {
        usort($items, static fn($a, $b) => ((int) ($a['scheduled_at'] ?? 0)) <=> ((int) ($b['scheduled_at'] ?? 0)));
        $days[$day] = $items;
    }
    return $days;
}

/**
 * PURE. Compact summary counts of a queue by status (plus total). Used by the
 * status action.
 * @param array<int,array> $queue
 * @return array<string,int>
 */
function wpultra_social_counts(array $queue): array {
    $counts = ['total' => 0];
    foreach (wpultra_social_statuses() as $s) { $counts[$s] = 0; }
    foreach ($queue as $item) {
        if (!is_array($item)) { continue; }
        $counts['total']++;
        $s = (string) ($item['status'] ?? '');
        if (isset($counts[$s])) { $counts[$s]++; }
    }
    return $counts;
}

/** PURE. Build a fresh, normalized queue item from validated inputs. */
function wpultra_social_make_item(string $id, array $networks, array $content, array $variants, int $scheduled_at, int $created_at, int $post_id = 0): array {
    return [
        'id'           => $id,
        'status'       => 'scheduled',
        'attempts'     => 0,
        'networks'     => array_values($networks),
        'content'      => [
            'text'      => is_string($content['text'] ?? null) ? $content['text'] : '',
            'link'      => is_string($content['link'] ?? null) ? $content['link'] : '',
            'image_url' => is_string($content['image_url'] ?? null) ? $content['image_url'] : '',
        ],
        'variants'     => $variants,
        'post_id'      => $post_id,
        'scheduled_at' => $scheduled_at,
        'created_at'   => $created_at,
        'sent_at'      => null,
        'result'       => null,
    ];
}

/* =====================================================================
 * WP-touching wrappers — guarded so the file stays harness-loadable.
 * ===================================================================== */

/** Load the queue option as a list. */
function wpultra_social_queue_load(): array {
    if (!function_exists('get_option')) { return []; }
    $q = get_option(WPULTRA_SOCIAL_QUEUE_OPT, []);
    return is_array($q) ? array_values(array_filter($q, 'is_array')) : [];
}

/** Persist the queue option. */
function wpultra_social_queue_save(array $queue): void {
    if (!function_exists('update_option')) { return; }
    update_option(WPULTRA_SOCIAL_QUEUE_OPT, array_values($queue), false);
}

/** Find an item by id. @return array|null */
function wpultra_social_find(string $id): ?array {
    foreach (wpultra_social_queue_load() as $item) {
        if ((string) ($item['id'] ?? '') === $id) { return $item; }
    }
    return null;
}

/** Insert an item and persist. */
function wpultra_social_enqueue(array $item): void {
    $queue = wpultra_social_queue_load();
    $queue[] = $item;
    wpultra_social_queue_save($queue);
}

/** Replace an item in place by id and persist. Returns whether it existed. */
function wpultra_social_replace(array $item): bool {
    $id = (string) ($item['id'] ?? '');
    $queue = wpultra_social_queue_load();
    $found = false;
    foreach ($queue as $i => $existing) {
        if ((string) ($existing['id'] ?? '') === $id) { $queue[$i] = $item; $found = true; break; }
    }
    if ($found) { wpultra_social_queue_save($queue); }
    return $found;
}

/** Generate a reasonably-unique item id. */
function wpultra_social_new_id(): string {
    if (function_exists('wp_generate_uuid4')) { return (string) wp_generate_uuid4(); }
    return 'soc_' . bin2hex(random_bytes(8));
}

/** The configured automation webhook URL (or ''). */
function wpultra_social_webhook(): string {
    if (!function_exists('get_option')) { return ''; }
    return (string) get_option(WPULTRA_SOCIAL_WEBHOOK_OPT, '');
}

/**
 * Deliver one item to the automation webhook via wp_remote_post, optionally
 * HMAC-signed. Returns [ok(bool), detail(string)].
 * @return array{0:bool,1:string}
 */
function wpultra_social_deliver(array $item): array {
    $url = wpultra_social_webhook();
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return [false, 'no automation webhook configured (run action:config with a webhook URL).'];
    }
    if (!function_exists('wp_remote_post')) {
        return [false, 'wp_remote_post unavailable.'];
    }

    $body    = (string) (function_exists('wp_json_encode') ? wp_json_encode(wpultra_social_webhook_payload($item)) : json_encode(wpultra_social_webhook_payload($item)));
    $headers = ['Content-Type' => 'application/json'];
    $secret  = function_exists('get_option') ? (string) get_option(WPULTRA_SOCIAL_HMAC_OPT, '') : '';
    if ($secret !== '') { $headers['X-WPUltra-Signature'] = hash_hmac('sha256', $body, $secret); }

    $poster = function_exists('wp_safe_remote_post') ? 'wp_safe_remote_post' : 'wp_remote_post';
    $res = $poster($url, ['timeout' => 15, 'headers' => $headers, 'body' => $body]);
    if (function_exists('is_wp_error') && is_wp_error($res)) {
        return [false, 'webhook error: ' . $res->get_error_message()];
    }
    $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($res) : 0;
    if ($code >= 200 && $code < 300) { return [true, "webhook accepted (HTTP $code)."]; }
    return [false, "webhook returned HTTP $code."];
}

/**
 * Fire all due items now: deliver each, mark sent on success, or bump attempts
 * and mark failed once the retry cap is hit (a failed-under-cap item stays
 * 'scheduled' so the next tick retries it). Returns how many were sent.
 */
function wpultra_social_run_due(int $now): int {
    $due = wpultra_social_due(wpultra_social_queue_load(), $now);
    $sent = 0;
    foreach ($due as $item) {
        [$ok, $detail] = wpultra_social_deliver($item);
        $fresh = wpultra_social_find((string) ($item['id'] ?? ''));
        if ($fresh === null) { continue; }
        $fresh['attempts'] = (int) ($fresh['attempts'] ?? 0) + 1;
        $fresh['result']   = $detail;
        if ($ok) {
            $fresh['status']  = 'sent';
            $fresh['sent_at'] = $now;
            $sent++;
        } elseif ($fresh['attempts'] >= WPULTRA_SOCIAL_MAX_ATTEMPTS) {
            $fresh['status'] = 'failed';
        }
        // else: leave status 'scheduled' for a later retry.
        wpultra_social_replace($fresh);
        wpultra_audit_log('social-scheduler', "deliver {$fresh['id']} ok=" . ($ok ? '1' : '0') . " ({$detail})", $ok);
    }
    return $sent;
}

/** Cron tick handler — fires due items. */
function wpultra_social_tick_handler(): void {
    try {
        wpultra_social_run_due(function_exists('current_time') ? (int) current_time('timestamp', true) : time());
    } catch (\Throwable $e) {
        // swallow — cron will not retry a fatal.
    }
}

/** Add a 5-minute cron interval. */
function wpultra_social_cron_schedules(array $schedules): array {
    if (!isset($schedules[WPULTRA_SOCIAL_SCHEDULE])) {
        $schedules[WPULTRA_SOCIAL_SCHEDULE] = ['interval' => 300, 'display' => 'Every 5 minutes (WP-Ultra social)'];
    }
    return $schedules;
}

/**
 * Always-on runtime contract. Cheap + idempotent. Registers the tick hook, the
 * custom interval, and ensures a recurring event exists so due items fire.
 */
function wpultra_social_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    if (!function_exists('add_action')) { return; }
    if (function_exists('wpultra_category_enabled') && !wpultra_category_enabled('marketing')) { return; }

    add_filter('cron_schedules', 'wpultra_social_cron_schedules');
    add_action(WPULTRA_SOCIAL_TICK_HOOK, 'wpultra_social_tick_handler');

    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
        if (!wp_next_scheduled(WPULTRA_SOCIAL_TICK_HOOK)) {
            wp_schedule_event(time() + 60, WPULTRA_SOCIAL_SCHEDULE, WPULTRA_SOCIAL_TICK_HOOK);
        }
    }
}
