<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * RSS / Atom feed importer (Roadmap D5) — pull external feeds into DRAFT posts.
 *
 * Flow: fetch a feed URL → parse RSS <item> / Atom <entry> → dedupe against the
 * per-feed "seen" hash set → map each new item to a post array → (optionally) run
 * an AI rewrite to avoid verbatim duplicate content → wp_insert_post() as a DRAFT.
 *
 * SAFETY: everything imports as a DRAFT by default. Auto-publish requires an
 * explicit post_status in the feed config AND auto_import turned on. The AI
 * rewrite is opt-in per-feed (needs an OpenAI key via includes/ai/setup.php);
 * without a key, items import raw with an attribution line.
 *
 * Config (option `wpultra_feeds`): a list of feed records —
 *   {id, url, name, post_type, post_status, category?, author?, auto_import: bool,
 *    rewrite: bool, tone?, max_per_run, last_polled, seen: [hashes] (capped)}
 * Cron (`wpultra_feed_cron`) polls only feeds with auto_import on.
 *
 * Layout: PURE functions first (prefix wpultra_feed_, no WordPress calls —
 * unit-tested by tests/feed-import.test.php), WP-touching wrappers after. The
 * runtime contract is wpultra_feed_boot() (cheap + idempotent), called by the
 * controller on plugins_loaded.
 */

if (!defined('WPULTRA_FEEDS_OPTION'))   { define('WPULTRA_FEEDS_OPTION', 'wpultra_feeds'); }
if (!defined('WPULTRA_FEED_CRON_HOOK')) { define('WPULTRA_FEED_CRON_HOOK', 'wpultra_feed_cron'); }
if (!defined('WPULTRA_FEED_SEEN_CAP'))  { define('WPULTRA_FEED_SEEN_CAP', 500); }

/* =====================================================================
 * PURE — no WordPress calls. Unit-testable.
 * ===================================================================== */

/**
 * PURE. Parse an RSS or Atom document into a normalized item list. Never fatal:
 * malformed XML yields []. Handles RSS <item> and Atom <entry>. Each item:
 *   {title, link, guid, content, summary, author, date, image}
 * Missing fields tolerated (empty string).
 *
 * @return array<int,array<string,string>>
 */
function wpultra_feed_parse_xml(string $xml): array {
    $xml = trim($xml);
    if ($xml === '') { return []; }

    // Parse defensively — libxml errors must not surface or halt.
    $prev = libxml_use_internal_errors(true);
    // LIBXML_NONET: never fetch external entities (XXE guard).
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($doc === false) { return []; }

    $items = [];

    // --- RSS: <rss><channel><item>… (also RDF <rdf:RDF><item>) ---
    // channel/item lives under the channel; RDF items are siblings of channel.
    $rss_items = [];
    if (isset($doc->channel) && isset($doc->channel->item)) {
        foreach ($doc->channel->item as $it) { $rss_items[] = $it; }
    }
    // RSS 1.0 / RDF: items sit at the document root.
    if ($rss_items === [] && isset($doc->item)) {
        foreach ($doc->item as $it) { $rss_items[] = $it; }
    }
    foreach ($rss_items as $it) {
        $items[] = wpultra_feed_extract_rss_item($it);
    }
    if ($items !== []) { return $items; }

    // --- Atom: <feed><entry>… ---
    if (isset($doc->entry)) {
        foreach ($doc->entry as $entry) {
            $items[] = wpultra_feed_extract_atom_entry($entry);
        }
    }
    return $items;
}

/**
 * PURE. Extract a normalized item from an RSS <item> SimpleXMLElement.
 * @return array<string,string>
 */
function wpultra_feed_extract_rss_item(\SimpleXMLElement $it): array {
    $ns = $it->getNamespaces(true);

    $title   = wpultra_feed_node_text($it->title ?? null);
    $link    = wpultra_feed_node_text($it->link ?? null);
    $guid    = wpultra_feed_node_text($it->guid ?? null);
    $summary = wpultra_feed_node_text($it->description ?? null);
    $date    = wpultra_feed_node_text($it->pubDate ?? null);

    // content:encoded (namespaced) is the full body when present.
    $content = $summary;
    if (isset($ns['content'])) {
        $c = $it->children($ns['content']);
        if ($c !== null && isset($c->encoded)) {
            $enc = wpultra_feed_node_text($c->encoded);
            if ($enc !== '') { $content = $enc; }
        }
    }

    // dc:creator / author.
    $author = wpultra_feed_node_text($it->author ?? null);
    if ($author === '' && isset($ns['dc'])) {
        $dc = $it->children($ns['dc']);
        if ($dc !== null && isset($dc->creator)) { $author = wpultra_feed_node_text($dc->creator); }
    }
    // dc:date fallback.
    if ($date === '' && isset($ns['dc'])) {
        $dc = $it->children($ns['dc']);
        if ($dc !== null && isset($dc->date)) { $date = wpultra_feed_node_text($dc->date); }
    }

    // enclosure / media:thumbnail image.
    $image = '';
    if (isset($it->enclosure)) {
        $type = (string) ($it->enclosure['type'] ?? '');
        $url  = (string) ($it->enclosure['url'] ?? '');
        if ($url !== '' && ($type === '' || stripos($type, 'image') === 0)) { $image = $url; }
    }
    if ($image === '' && isset($ns['media'])) {
        $media = $it->children($ns['media']);
        if ($media !== null) {
            if (isset($media->thumbnail)) { $image = (string) ($media->thumbnail['url'] ?? ''); }
            if ($image === '' && isset($media->content)) { $image = (string) ($media->content['url'] ?? ''); }
        }
    }

    return [
        'title'   => $title,
        'link'    => $link,
        'guid'    => $guid,
        'content' => $content,
        'summary' => $summary,
        'author'  => $author,
        'date'    => $date,
        'image'   => $image,
    ];
}

/**
 * PURE. Extract a normalized item from an Atom <entry> SimpleXMLElement.
 * @return array<string,string>
 */
function wpultra_feed_extract_atom_entry(\SimpleXMLElement $entry): array {
    $title   = wpultra_feed_node_text($entry->title ?? null);
    $summary = wpultra_feed_node_text($entry->summary ?? null);
    $content = wpultra_feed_node_text($entry->content ?? null);
    if ($content === '') { $content = $summary; }
    $guid    = wpultra_feed_node_text($entry->id ?? null);
    $date    = wpultra_feed_node_text($entry->updated ?? null);
    if ($date === '') { $date = wpultra_feed_node_text($entry->published ?? null); }

    // Atom author is a nested <author><name>.
    $author = '';
    if (isset($entry->author) && isset($entry->author->name)) {
        $author = wpultra_feed_node_text($entry->author->name);
    }

    // Atom link: prefer rel="alternate" href, else the first href.
    $link = '';
    $image = '';
    if (isset($entry->link)) {
        foreach ($entry->link as $l) {
            $rel  = (string) ($l['rel'] ?? '');
            $href = (string) ($l['href'] ?? '');
            $type = (string) ($l['type'] ?? '');
            if ($href === '') { continue; }
            if ($rel === 'enclosure' && ($type === '' || stripos($type, 'image') === 0)) {
                if ($image === '') { $image = $href; }
                continue;
            }
            if ($rel === 'alternate' || $rel === '') { $link = $href; }
            if ($link === '') { $link = $href; }
        }
    }

    return [
        'title'   => $title,
        'link'    => $link,
        'guid'    => $guid !== '' ? $guid : $link,
        'content' => $content,
        'summary' => $summary,
        'author'  => $author,
        'date'    => $date,
        'image'   => $image,
    ];
}

/** PURE. Trimmed text of a SimpleXMLElement node (or '' when null). */
function wpultra_feed_node_text($node): string {
    if ($node === null) { return ''; }
    return trim((string) $node);
}

/**
 * PURE. Stable identity hash for a feed item. Prefers guid, falls back to link,
 * then to title+date. Empty item → hash of ''. Deterministic across runs.
 */
function wpultra_feed_item_hash(array $item): string {
    $guid  = trim((string) ($item['guid'] ?? ''));
    $link  = trim((string) ($item['link'] ?? ''));
    $title = trim((string) ($item['title'] ?? ''));
    $date  = trim((string) ($item['date'] ?? ''));

    if ($guid !== '') { $basis = 'g:' . $guid; }
    elseif ($link !== '') { $basis = 'l:' . $link; }
    else { $basis = 't:' . $title . '|' . $date; }

    return hash('sha256', $basis);
}

/**
 * PURE. Drop items whose hash is already in $seen_hashes; keep new ones in order.
 * @param array<int,array> $items
 * @param array<int,string> $seen_hashes
 * @return array<int,array>
 */
function wpultra_feed_filter_new(array $items, array $seen_hashes): array {
    $seen = array_flip(array_map('strval', $seen_hashes));
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) { continue; }
        $h = wpultra_feed_item_hash($item);
        if (isset($seen[$h])) { continue; }
        $out[] = $item;
    }
    return $out;
}

/**
 * PURE. Append new hashes to the seen set and cap it (keep most recent, FIFO).
 * @param array<int,string> $seen
 * @param array<int,string> $add
 * @return array<int,string>
 */
function wpultra_feed_seen_merge(array $seen, array $add, int $cap = WPULTRA_FEED_SEEN_CAP): array {
    $merged = array_values(array_unique(array_map('strval', array_merge($seen, $add))));
    if ($cap > 0 && count($merged) > $cap) {
        $merged = array_slice($merged, -$cap);
    }
    return $merged;
}

/**
 * PURE. Clean feed HTML for storage: strip <script>/<style>/<iframe>, drop tracking
 * query params from href/src URLs, keep basic formatting (<p>, <a>, <strong>…).
 * The WP wrapper additionally runs wp_kses_post().
 */
function wpultra_feed_clean_content(string $html): string {
    if ($html === '') { return ''; }

    // Remove dangerous element blocks entirely (tag + inner content).
    $html = preg_replace('#<(script|style|iframe|object|embed|noscript)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    // Remove self-closing / orphan variants of those tags.
    $html = preg_replace('#</?(script|style|iframe|object|embed|noscript)\b[^>]*>#is', '', $html) ?? $html;
    // Strip inline event handlers (onclick=, onload=…) and javascript: URIs.
    $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
    $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2#i', '$1=$2#$2', $html) ?? $html;

    // Strip common tracking query params from URLs inside href/src attributes.
    $html = preg_replace_callback(
        '#\b(href|src)\s*=\s*"([^"]*)"#i',
        static function (array $m): string {
            return $m[1] . '="' . wpultra_feed_strip_tracking_params($m[2]) . '"';
        },
        $html
    ) ?? $html;

    return trim($html);
}

/**
 * PURE. Remove tracking query params (utm_*, fbclid, gclid, mc_*, ref, …) from a URL,
 * preserving other params and the fragment. Non-URL strings pass through unchanged-ish.
 */
function wpultra_feed_strip_tracking_params(string $url): string {
    if ($url === '' || strpos($url, '?') === false) { return $url; }

    // Split off fragment.
    $frag = '';
    if (($hp = strpos($url, '#')) !== false) {
        $frag = substr($url, $hp);
        $url  = substr($url, 0, $hp);
    }

    [$base, $query] = explode('?', $url, 2);
    if ($query === '') { return $base . $frag; }

    $tracking = ['utm_', 'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', 'ref_src', 'igshid', 'yclid', '_hsenc', '_hsmi', 'vero_id', 'oly_enc_id', 'oly_anon_id'];
    $keep = [];
    foreach (explode('&', $query) as $pair) {
        if ($pair === '') { continue; }
        $key = strtolower(explode('=', $pair, 2)[0]);
        $drop = false;
        foreach ($tracking as $t) {
            if ($t[strlen($t) - 1] === '_') { // prefix match (utm_, mc_)
                if (strpos($key, $t) === 0) { $drop = true; break; }
            } elseif ($key === $t) { $drop = true; break; }
        }
        if (!$drop) { $keep[] = $pair; }
    }

    $out = $base;
    if ($keep !== []) { $out .= '?' . implode('&', $keep); }
    return $out . $frag;
}

/**
 * PURE. Build a wp_insert_post-shaped array from a normalized feed item + options.
 * $opts: {post_type?, post_status? (default 'draft'), author?, attribution? (bool),
 *         source_feed? (feed name/id), content_override? (AI-rewritten body)}
 * The WP wrapper sanitizes post_content with wp_kses_post and inserts.
 *
 * @return array<string,mixed>
 */
function wpultra_feed_to_postarr(array $item, array $opts = []): array {
    $title   = trim((string) ($item['title'] ?? ''));
    if ($title === '') { $title = '(untitled feed item)'; }

    $body = (string) ($opts['content_override'] ?? '');
    if ($body === '') {
        $body = wpultra_feed_clean_content((string) ($item['content'] ?? ($item['summary'] ?? '')));
    }

    $link = trim((string) ($item['link'] ?? ''));
    if (!empty($opts['attribution']) && $link !== '') {
        $src = trim((string) ($opts['source_feed'] ?? ''));
        $label = $src !== '' ? $src : $link;
        $body .= "\n<p class=\"wpultra-feed-attribution\"><em>Source: <a href=\"" . $link . "\" rel=\"nofollow noopener\">" . $label . '</a></em></p>';
    }

    $status = (string) ($opts['post_status'] ?? 'draft');
    if (!in_array($status, ['draft', 'publish', 'pending', 'private', 'future'], true)) { $status = 'draft'; }

    $postarr = [
        'post_title'   => $title,
        'post_content' => $body,
        'post_excerpt' => wpultra_feed_excerpt((string) ($item['summary'] ?? ''), 55),
        'post_status'  => $status,
        'post_type'    => (string) ($opts['post_type'] ?? 'post'),
        'meta_input'   => [
            'wpultra_feed_source_url'  => $link,
            'wpultra_feed_source_feed' => (string) ($opts['source_feed'] ?? ''),
            'wpultra_feed_imported_at' => (string) ($opts['imported_at'] ?? gmdate('Y-m-d H:i:s')),
            'wpultra_feed_guid'        => (string) ($item['guid'] ?? ''),
        ],
    ];

    if (!empty($opts['author'])) { $postarr['post_author'] = (int) $opts['author']; }

    // Carry the item's own publish date only when the caller opts in (keep_date).
    if (!empty($opts['keep_date'])) {
        $d = trim((string) ($item['date'] ?? ''));
        if ($d !== '') { $postarr['post_date_source'] = $d; } // WP wrapper converts to post_date.
    }

    return $postarr;
}

/** PURE. Plain-text excerpt: strip tags, collapse whitespace, cap at $words words. */
function wpultra_feed_excerpt(string $html, int $words = 55): string {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    if ($text === '') { return ''; }
    $parts = explode(' ', $text);
    if (count($parts) <= $words) { return $text; }
    return implode(' ', array_slice($parts, 0, $words)) . '…';
}

/**
 * PURE. Build the {system,user} prompt asking an LLM to rewrite a feed item in
 * original wording (avoid verbatim copying / duplicate-content penalties). The WP
 * wrapper feeds this to wpultra_ai_chat and uses the result as the post body.
 * Opt-in: only runs when a feed has rewrite=true AND a key is configured.
 *
 * @return array{system:string,user:string}
 */
function wpultra_feed_rewrite_prompt(array $item, string $tone = 'neutral'): array {
    $tone = trim($tone) !== '' ? trim($tone) : 'neutral';
    $title   = (string) ($item['title'] ?? '');
    $summary = (string) ($item['summary'] ?? '');
    $content = (string) ($item['content'] ?? $summary);
    $link    = (string) ($item['link'] ?? '');

    $system = 'You are an editor who rewrites third-party news/blog items into ORIGINAL wording for republishing. '
        . 'Preserve every fact, name, number, and quote accurately, but do NOT copy sentences verbatim — paraphrase fully to avoid duplicate-content penalties. '
        . 'Keep basic HTML formatting (<p>, <a>, <strong>, <ul>/<li>). Do not invent facts. '
        . 'Write in a ' . $tone . ' tone. Return only the rewritten HTML body, no title, no preamble.';

    $user = "Rewrite the following item in original words:\n\n"
        . 'TITLE: ' . $title . "\n"
        . ($link !== '' ? 'SOURCE URL: ' . $link . "\n" : '')
        . "\nCONTENT:\n" . $content;

    return ['system' => $system, 'user' => $user];
}

/**
 * PURE. Default record for a newly-added feed. Caller overrides fields.
 * @return array<string,mixed>
 */
function wpultra_feed_default_record(string $url, string $name = ''): array {
    return [
        'id'          => wpultra_feed_make_id($url),
        'url'         => $url,
        'name'        => $name !== '' ? $name : $url,
        'post_type'   => 'post',
        'post_status' => 'draft',
        'category'    => 0,
        'author'      => 0,
        'auto_import' => false,
        'rewrite'     => false,
        'tone'        => 'neutral',
        'max_per_run' => 10,
        'last_polled' => 0,
        'seen'        => [],
    ];
}

/** PURE. Deterministic short feed id derived from its URL. */
function wpultra_feed_make_id(string $url): string {
    return 'feed_' . substr(hash('sha256', trim($url)), 0, 12);
}

/**
 * PURE. Basic URL-shape validation for a feed source: http(s), has a host,
 * no control chars. The WP wrapper additionally runs wp_http_validate_url.
 */
function wpultra_feed_is_valid_url(string $url): bool {
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x1f]/', $url)) { return false; }
    if (!preg_match('#^https?://#i', $url)) { return false; }
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && $host !== '';
}

/* =====================================================================
 * WordPress-touching wrappers (guarded). Not exercised by pure tests.
 * ===================================================================== */

/** Runtime contract. Registers the cron hook + reconciles the schedule. Cheap + idempotent. */
function wpultra_feed_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    if (!function_exists('add_action')) { return; }

    add_action(WPULTRA_FEED_CRON_HOOK, 'wpultra_feed_cron_run');
    // Reconcile the poll schedule against current config (adds/clears the event).
    if (function_exists('did_action') && did_action('init')) {
        wpultra_feed_reconcile_schedule();
    } else {
        add_action('init', 'wpultra_feed_reconcile_schedule');
    }
}

/** Read the feeds config option as a list. */
function wpultra_feed_get_config(): array {
    if (!function_exists('get_option')) { return []; }
    $cfg = get_option(WPULTRA_FEEDS_OPTION, []);
    return is_array($cfg) ? array_values($cfg) : [];
}

/** Persist the feeds config. */
function wpultra_feed_save_config(array $feeds): void {
    if (!function_exists('update_option')) { return; }
    update_option(WPULTRA_FEEDS_OPTION, array_values($feeds), false);
}

/** Find a feed record by id (or null). */
function wpultra_feed_find(string $feed_id): ?array {
    foreach (wpultra_feed_get_config() as $f) {
        if ((string) ($f['id'] ?? '') === $feed_id) { return $f; }
    }
    return null;
}

/**
 * Schedule (or clear) the daily poll depending on whether any feed has auto_import on.
 */
function wpultra_feed_reconcile_schedule(): void {
    if (!function_exists('wp_next_scheduled')) { return; }
    $any_auto = false;
    foreach (wpultra_feed_get_config() as $f) {
        if (!empty($f['auto_import'])) { $any_auto = true; break; }
    }
    $next = wp_next_scheduled(WPULTRA_FEED_CRON_HOOK);
    if ($any_auto && !$next && function_exists('wp_schedule_event')) {
        wp_schedule_event(time() + 300, 'hourly', WPULTRA_FEED_CRON_HOOK);
    } elseif (!$any_auto && $next && function_exists('wp_unschedule_event')) {
        wp_unschedule_event($next, WPULTRA_FEED_CRON_HOOK);
    }
}

/** Cron handler: poll every auto_import feed and import new items as configured. */
function wpultra_feed_cron_run(): void {
    foreach (wpultra_feed_get_config() as $f) {
        if (empty($f['auto_import'])) { continue; }
        $res = wpultra_feed_import_run((string) ($f['id'] ?? ''), (int) ($f['max_per_run'] ?? 10));
        if (is_wp_error($res)) {
            wpultra_audit_log('feed-import', 'cron poll failed for ' . ($f['name'] ?? $f['id'] ?? '?') . ': ' . $res->get_error_message(), false);
        }
    }
}

/**
 * Fetch + parse a feed URL. Prefers WP core fetch_feed() (SimplePie); falls back
 * to the pure parser on the raw body. Returns a normalized item list or WP_Error.
 *
 * @return array<int,array>|WP_Error
 */
function wpultra_feed_fetch(string $url) {
    if (!wpultra_feed_is_valid_url($url)) {
        return wpultra_err('bad_feed_url', "Invalid feed URL: $url");
    }
    if (function_exists('wp_http_validate_url') && !wp_http_validate_url($url)) {
        return wpultra_err('blocked_feed_url', "Feed URL failed WP HTTP validation (SSRF guard): $url");
    }

    // Try WP core SimplePie first.
    if (function_exists('fetch_feed')) {
        $feed = fetch_feed($url);
        if (!is_wp_error($feed)) {
            $items = [];
            $rows = $feed->get_items();
            foreach ($rows as $row) {
                $items[] = [
                    'title'   => (string) $row->get_title(),
                    'link'    => (string) $row->get_permalink(),
                    'guid'    => (string) ($row->get_id() ?: $row->get_permalink()),
                    'content' => (string) $row->get_content(),
                    'summary' => (string) $row->get_description(),
                    'author'  => (string) ($row->get_author() ? $row->get_author()->get_name() : ''),
                    'date'    => (string) $row->get_date('Y-m-d H:i:s'),
                    'image'   => '',
                ];
            }
            return $items;
        }
        // Fall through to raw fetch on SimplePie error.
    }

    // Raw fetch → pure parser.
    if (!function_exists('wp_remote_get')) {
        return wpultra_err('http_unavailable', 'No HTTP transport available to fetch the feed.');
    }
    $resp = wp_remote_get($url, ['timeout' => 30, 'redirection' => 3]);
    if (is_wp_error($resp)) { return wpultra_err('feed_unreachable', $resp->get_error_message()); }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return wpultra_err('feed_http_error', "Feed returned HTTP $code.");
    }
    $body = (string) wp_remote_retrieve_body($resp);
    $items = wpultra_feed_parse_xml($body);
    if ($items === []) {
        return wpultra_err('feed_unparseable', 'Feed returned no parseable RSS/Atom items.');
    }
    return $items;
}

/**
 * Preview a feed: fetch + parse + dedupe against a feed's seen set (if $feed_id given).
 * Read-only — no writes. Returns {items:[…], total, new}.
 *
 * @return array|WP_Error
 */
function wpultra_feed_preview(string $url_or_id, int $max = 10) {
    $url = $url_or_id;
    $seen = [];
    if (strpos($url_or_id, 'feed_') === 0) {
        $rec = wpultra_feed_find($url_or_id);
        if ($rec === null) { return wpultra_err('unknown_feed', "No feed with id '$url_or_id'."); }
        $url  = (string) $rec['url'];
        $seen = is_array($rec['seen'] ?? null) ? $rec['seen'] : [];
    }
    $items = wpultra_feed_fetch($url);
    if (is_wp_error($items)) { return $items; }

    $new = wpultra_feed_filter_new($items, $seen);
    if ($max > 0) { $new = array_slice($new, 0, $max); }

    $preview = [];
    foreach ($new as $it) {
        $preview[] = [
            'title'   => (string) ($it['title'] ?? ''),
            'link'    => (string) ($it['link'] ?? ''),
            'date'    => (string) ($it['date'] ?? ''),
            'excerpt' => wpultra_feed_excerpt((string) ($it['summary'] ?? ($it['content'] ?? '')), 40),
        ];
    }
    return ['items' => $preview, 'total' => count($items), 'new' => count($new)];
}

/**
 * Import new items from a configured feed into draft posts. Confirm-gated at the
 * ability layer. Dedupes, optionally AI-rewrites, inserts, and advances the seen set.
 *
 * @return array|WP_Error
 */
function wpultra_feed_import_run(string $feed_id, int $max = 0) {
    $rec = wpultra_feed_find($feed_id);
    if ($rec === null) { return wpultra_err('unknown_feed', "No feed with id '$feed_id'."); }
    if (!function_exists('wp_insert_post')) { return wpultra_err('wp_unavailable', 'wp_insert_post() unavailable.'); }

    $items = wpultra_feed_fetch((string) $rec['url']);
    if (is_wp_error($items)) { return $items; }

    $seen = is_array($rec['seen'] ?? null) ? $rec['seen'] : [];
    $new  = wpultra_feed_filter_new($items, $seen);
    $cap  = $max > 0 ? $max : (int) ($rec['max_per_run'] ?? 10);
    if ($cap > 0) { $new = array_slice($new, 0, $cap); }

    $created = [];
    $new_hashes = [];
    foreach ($new as $item) {
        $new_hashes[] = wpultra_feed_item_hash($item);

        $override = '';
        if (!empty($rec['rewrite']) && function_exists('wpultra_ai_has_key') && wpultra_ai_has_key()) {
            $prompt = wpultra_feed_rewrite_prompt($item, (string) ($rec['tone'] ?? 'neutral'));
            $out = wpultra_ai_chat($prompt['system'], $prompt['user'], ['max_tokens' => 1500]);
            if (!is_wp_error($out) && is_string($out) && trim($out) !== '') {
                $override = wpultra_feed_clean_content($out);
            }
        }

        $postarr = wpultra_feed_to_postarr($item, [
            'post_type'        => (string) ($rec['post_type'] ?? 'post'),
            'post_status'      => (string) ($rec['post_status'] ?? 'draft'),
            'author'           => (int) ($rec['author'] ?? 0),
            'attribution'      => empty($rec['rewrite']) || $override === '',
            'source_feed'      => (string) ($rec['name'] ?? $rec['url'] ?? ''),
            'content_override' => $override,
        ]);

        // Sanitize the body with wp_kses_post before insert.
        if (function_exists('wp_kses_post')) {
            $postarr['post_content'] = wp_kses_post($postarr['post_content']);
        }
        unset($postarr['post_date_source']);

        $meta = $postarr['meta_input'] ?? [];
        unset($postarr['meta_input']);
        $insert = $postarr;
        if ($meta !== []) { $insert['meta_input'] = $meta; }

        $id = wp_insert_post(function_exists('wp_slash') ? wp_slash($insert) : $insert, true);
        if (is_wp_error($id)) { continue; }

        // Assign category if configured.
        if (!empty($rec['category']) && function_exists('wp_set_post_categories')) {
            wp_set_post_categories((int) $id, [(int) $rec['category']], false);
        }
        $created[] = ['post_id' => (int) $id, 'title' => (string) $postarr['post_title']];
    }

    // Advance the seen set (cap) and last_polled.
    $rec['seen'] = wpultra_feed_seen_merge($seen, $new_hashes);
    $rec['last_polled'] = function_exists('time') ? time() : 0;
    $feeds = wpultra_feed_get_config();
    foreach ($feeds as $i => $f) {
        if ((string) ($f['id'] ?? '') === $feed_id) { $feeds[$i] = $rec; break; }
    }
    wpultra_feed_save_config($feeds);

    wpultra_audit_log('feed-import', sprintf('imported %d item(s) from %s', count($created), $rec['name'] ?? $feed_id), true);
    return ['created' => $created, 'imported' => count($created), 'available_new' => count($new)];
}
