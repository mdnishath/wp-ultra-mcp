<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Content freshness + auto-refresh engine (Roadmap D3).
 *
 * Finds stale and thin posts, scores them, produces a deterministic refresh
 * to-do list, and — with confirmation — applies SAFE, reversible refreshes.
 *
 * Design follows the plugin's pure-core / thin-WP-wrapper split:
 *
 *  - PURE (prefix wpultra_fresh_): the scoring math, word counting, reading
 *    time, deterministic suggestion list, the site-wide report, the AI prompt
 *    shape, and the AI-response parser. All unit-testable with no WordPress.
 *
 *  - WP WRAPPERS (guarded by function_exists / is-WP checks): scan the post
 *    library, load a single post's metrics, apply an approved refresh, and
 *    "touch" a post's modified date.
 *
 * SAFETY: apply() NEVER invents or auto-rewrites live content. It only writes
 * title/content SUPPLIED by the caller (or AI-generated text the caller has
 * approved) and bumps the modified date. "touch" only bumps post_modified.
 * Both are confirm-gated.
 */

// ===========================================================================
// PURE: time constants
// ===========================================================================

/** Seconds in a day. Pure. */
function wpultra_fresh_day(): int { return 86400; }

/**
 * PURE. Thresholds (in days) that bucket a post's age-since-modified.
 * Filter-free so tests are deterministic.
 * @return array{very_stale:int,stale:int,aging:int,fresh:int}
 */
function wpultra_fresh_age_thresholds(): array {
    return [
        'very_stale' => 730, // > 2 years
        'stale'      => 365, // > 1 year
        'aging'      => 180, // > 6 months
        'fresh'      => 90,  // < 3 months is fresh
    ];
}

// ===========================================================================
// PURE: word count + reading time
// ===========================================================================

/**
 * PURE. Codepoint-safe word count of post HTML. Strips Gutenberg block
 * comments, shortcodes, and HTML tags first, then counts whitespace-separated
 * tokens. Empty / markup-only content returns 0.
 */
function wpultra_fresh_word_count(string $html): int {
    if ($html === '') { return 0; }
    // Strip Gutenberg block delimiters: <!-- wp:paragraph {...} --> ... <!-- /wp:paragraph -->
    $s = preg_replace('/<!--\s*\/?wp:.*?-->/us', ' ', $html);
    $s = is_string($s) ? $s : $html;
    // Strip any remaining HTML comments.
    $s = preg_replace('/<!--.*?-->/us', ' ', $s);
    $s = is_string($s) ? $s : '';
    // Strip shortcodes: [gallery ...], [/caption], [foo] etc.
    $s = preg_replace('/\[\/?[a-zA-Z][^\]]*\]/us', ' ', $s);
    $s = is_string($s) ? $s : '';
    // Strip HTML tags.
    $s = strip_tags($s);
    // Decode entities so "&nbsp;" doesn't glue words together.
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normalize whitespace (unicode-aware).
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = is_string($s) ? trim($s) : '';
    if ($s === '') { return 0; }
    // Count tokens. preg_match_all is unicode-safe for word runs.
    $n = preg_match_all('/[^\s]+/u', $s, $m);
    return $n === false ? 0 : (int) $n;
}

/**
 * PURE. Estimated reading time in whole minutes for a word count.
 * ~200 wpm, minimum 1 minute for any non-empty content.
 */
function wpultra_fresh_reading_time(int $words): int {
    if ($words <= 0) { return 0; }
    $wpm = 200;
    $mins = (int) ceil($words / $wpm);
    return $mins < 1 ? 1 : $mins;
}

// ===========================================================================
// PURE: staleness + thinness scoring (the heart)
// ===========================================================================

/**
 * PURE. Score a post's staleness and thinness from its metrics.
 *
 * @param array $meta {
 *   modified_ts?:int, published_ts?:int, word_count?:int, internal_links?:int,
 *   outbound_links?:int, images?:int, has_meta_desc?:bool, comment_count?:int,
 *   traffic?:int  // optional; boosts priority for high-traffic stale content
 * }
 * @param int $now  Current unix timestamp.
 * @return array{
 *   stale_score:int, thin_score:int, reasons:array<int,array{code:string,label:string,weight:int}>,
 *   priority:string, age_bucket:string, age_days:int, word_count:int
 * }
 */
function wpultra_fresh_score(array $meta, int $now): array {
    $mod  = (int) ($meta['modified_ts'] ?? ($meta['published_ts'] ?? $now));
    if ($mod <= 0) { $mod = $now; }
    $age_days = (int) floor(max(0, $now - $mod) / wpultra_fresh_day());

    $th = wpultra_fresh_age_thresholds();
    $reasons = [];

    // ---- Staleness (age since modified) ----
    $stale = 0;
    $bucket = 'fresh';
    if ($age_days > $th['very_stale']) {
        $stale = 100; $bucket = 'very_stale';
        $reasons[] = ['code' => 'very_stale', 'label' => 'Not updated in over 2 years', 'weight' => 100];
    } elseif ($age_days > $th['stale']) {
        $stale = 75; $bucket = 'stale';
        $reasons[] = ['code' => 'stale', 'label' => 'Not updated in over a year', 'weight' => 75];
    } elseif ($age_days > $th['aging']) {
        $stale = 50; $bucket = 'aging';
        $reasons[] = ['code' => 'aging', 'label' => 'Not updated in over 6 months', 'weight' => 50];
    } elseif ($age_days > $th['fresh']) {
        $stale = 25; $bucket = 'settling';
        $reasons[] = ['code' => 'settling', 'label' => 'Over 3 months since last update', 'weight' => 25];
    } else {
        $stale = 0; $bucket = 'fresh';
    }

    // High-traffic stale content is a bigger deal — nudge the staleness up.
    $traffic = isset($meta['traffic']) ? (int) $meta['traffic'] : -1;
    if ($traffic > 0 && $stale >= 50) {
        $stale = min(100, $stale + 10);
        $reasons[] = ['code' => 'stale_high_traffic', 'label' => 'Stale but still receiving traffic', 'weight' => 10];
    }
    $stale = max(0, min(100, $stale));

    // ---- Thinness ----
    $wc = (int) ($meta['word_count'] ?? 0);
    $thin = 0;
    if ($wc < 300) {
        $thin += 50;
        $reasons[] = ['code' => 'very_thin', 'label' => 'Very thin content (under 300 words)', 'weight' => 50];
    } elseif ($wc < 600) {
        $thin += 25;
        $reasons[] = ['code' => 'thin', 'label' => 'Thin content (under 600 words)', 'weight' => 25];
    }

    $images = (int) ($meta['images'] ?? 0);
    if ($images <= 0) {
        $thin += 15;
        $reasons[] = ['code' => 'no_images', 'label' => 'No images', 'weight' => 15];
    }

    $internal = (int) ($meta['internal_links'] ?? 0);
    if ($internal <= 0) {
        $thin += 20;
        $reasons[] = ['code' => 'no_internal_links', 'label' => 'No internal links', 'weight' => 20];
    }

    $has_meta = !empty($meta['has_meta_desc']);
    if (!$has_meta) {
        $thin += 15;
        $reasons[] = ['code' => 'no_meta_desc', 'label' => 'Missing meta description', 'weight' => 15];
    }

    $thin = max(0, min(100, $thin));

    // ---- Priority ----
    $priority = wpultra_fresh_priority($stale, $thin, $age_days, $wc);

    return [
        'stale_score' => $stale,
        'thin_score'  => $thin,
        'reasons'     => array_values($reasons),
        'priority'    => $priority,
        'age_bucket'  => $bucket,
        'age_days'    => $age_days,
        'word_count'  => $wc,
    ];
}

/**
 * PURE. Combine staleness + thinness (plus an old-and-thin boost) into a
 * high/medium/low priority label.
 */
function wpultra_fresh_priority(int $stale, int $thin, int $age_days, int $word_count): string {
    $combined = ($stale * 0.6) + ($thin * 0.4);

    // Old (over a year) AND thin content is the classic "refresh me" candidate.
    $old_and_thin = ($age_days > wpultra_fresh_age_thresholds()['stale']) && ($word_count > 0 ? $word_count < 600 : true) && $word_count < 600;
    if ($old_and_thin) { $combined += 15; }

    if ($combined >= 60 || ($stale >= 75 && $thin >= 40)) { return 'high'; }
    if ($combined >= 30) { return 'medium'; }
    return 'low';
}

// ===========================================================================
// PURE: deterministic refresh suggestions
// ===========================================================================

/**
 * PURE. Turn a score's reasons into a concrete, deduplicated to-do list.
 * A genuinely fresh post yields an empty (or near-empty) list.
 *
 * @param array $score  Output of wpultra_fresh_score().
 * @return array<int,array{code:string,action:string,priority:string}>
 */
function wpultra_fresh_suggest_actions(array $score): array {
    $reasons = is_array($score['reasons'] ?? null) ? $score['reasons'] : [];
    $codes = [];
    foreach ($reasons as $r) {
        if (is_array($r) && isset($r['code'])) { $codes[(string) $r['code']] = true; }
    }

    $map = [
        'no_meta_desc'       => ['action' => 'Add a meta description (150–160 characters).', 'priority' => 'high'],
        'very_thin'          => ['action' => 'Expand the content to at least 600 words with useful detail.', 'priority' => 'high'],
        'thin'               => ['action' => 'Add more depth — aim for 600+ words.', 'priority' => 'medium'],
        'no_internal_links'  => ['action' => 'Add at least 2 internal links to related posts.', 'priority' => 'medium'],
        'no_images'          => ['action' => 'Add at least one relevant image (with alt text).', 'priority' => 'medium'],
        'very_stale'         => ['action' => 'Update the year and any dated facts in the title, intro, and body.', 'priority' => 'high'],
        'stale'              => ['action' => 'Refresh dated references and re-verify the facts.', 'priority' => 'high'],
        'aging'              => ['action' => 'Review for accuracy and bump the modified date.', 'priority' => 'low'],
        'settling'           => ['action' => 'Do a light review and refresh where useful.', 'priority' => 'low'],
        'stale_high_traffic' => ['action' => 'Prioritise this refresh — it still gets traffic.', 'priority' => 'high'],
    ];

    $out = [];
    // Emit in the canonical map order for deterministic output.
    foreach ($map as $code => $spec) {
        if (isset($codes[$code])) {
            $out[] = ['code' => $code, 'action' => $spec['action'], 'priority' => $spec['priority']];
        }
    }

    // Add an FAQ suggestion when content is thin/stale enough to warrant a rewrite.
    if ((int) ($score['thin_score'] ?? 0) >= 40 || (int) ($score['stale_score'] ?? 0) >= 75) {
        $out[] = ['code' => 'add_faq', 'action' => 'Add an FAQ section answering common questions.', 'priority' => 'low'];
    }

    return $out;
}

// ===========================================================================
// PURE: AI prompt + response parsing
// ===========================================================================

/**
 * PURE. Build the {system,user} prompt asking the AI for concrete rewrite
 * suggestions as strict JSON.
 *
 * @param array $post   {title?, excerpt?, url?, content_preview?}
 * @param array $score  Output of wpultra_fresh_score().
 * @return array{system:string,user:string}
 */
function wpultra_fresh_ai_prompt(array $post, array $score): array {
    $title   = (string) ($post['title'] ?? '');
    $excerpt = (string) ($post['excerpt'] ?? ($post['content_preview'] ?? ''));

    $reasons = [];
    foreach (($score['reasons'] ?? []) as $r) {
        if (is_array($r) && isset($r['label'])) { $reasons[] = '- ' . (string) $r['label']; }
    }
    $reason_txt = $reasons === [] ? '(none flagged)' : implode("\n", $reasons);

    $system = 'You are a senior content editor helping refresh an existing, dated blog post. '
        . 'Return ONLY strict JSON with the exact keys: '
        . '"title" (an improved, current title string), '
        . '"intro" (a refreshed 2-3 sentence opening paragraph string), '
        . '"sections" (an array of 3 short strings, each a heading for a new section to add), '
        . '"notes" (an array of short strings with other concrete improvements). '
        . 'Do not include markdown fences or any prose outside the JSON object.';

    $user = "Current title: {$title}\n"
        . "Excerpt / preview: {$excerpt}\n"
        . "Freshness issues detected:\n{$reason_txt}\n\n"
        . 'Suggest concrete refreshes as JSON.';

    return ['system' => $system, 'user' => $user];
}

/**
 * PURE. Parse an AI JSON response (raw, fenced, or with surrounding prose) into
 * a normalized suggestion structure. Garbage yields a safe empty shape with a
 * parse-error flag.
 *
 * @return array{title:string,intro:string,sections:array<int,string>,notes:array<int,string>,parsed:bool}
 */
function wpultra_fresh_parse_suggestions(string $ai_json): array {
    $empty = ['title' => '', 'intro' => '', 'sections' => [], 'notes' => [], 'parsed' => false];

    $s = trim($ai_json);
    if ($s === '') { return $empty; }

    // Strip a ```json ... ``` (or plain ```) fence if present.
    if (preg_match('/```(?:json)?\s*(.+?)```/us', $s, $m)) {
        $s = trim($m[1]);
    }

    $data = json_decode($s, true);
    // Fall back: extract the first {...} object from surrounding prose.
    if (!is_array($data)) {
        if (preg_match('/\{.*\}/us', $s, $m2)) {
            $data = json_decode($m2[0], true);
        }
    }
    if (!is_array($data)) { return $empty; }

    $sections = [];
    foreach ((array) ($data['sections'] ?? []) as $sec) {
        if (is_string($sec) && trim($sec) !== '') { $sections[] = trim($sec); }
    }
    $notes = [];
    foreach ((array) ($data['notes'] ?? []) as $note) {
        if (is_string($note) && trim($note) !== '') { $notes[] = trim($note); }
    }

    return [
        'title'    => is_string($data['title'] ?? null) ? trim((string) $data['title']) : '',
        'intro'    => is_string($data['intro'] ?? null) ? trim((string) $data['intro']) : '',
        'sections' => $sections,
        'notes'    => $notes,
        'parsed'   => true,
    ];
}

// ===========================================================================
// PURE: site-wide report
// ===========================================================================

/**
 * PURE. Rank scored posts by priority and produce summary counts.
 *
 * @param array<int,array> $scored_posts  Each item must carry at least
 *        {id?, title?, priority, stale_score, thin_score, age_bucket?}.
 * @return array{posts:array<int,array>, summary:array{total:int,high:int,medium:int,low:int,stale:int,thin:int,fresh:int,needs_attention:int}}
 */
function wpultra_fresh_report(array $scored_posts): array {
    $rank = ['high' => 0, 'medium' => 1, 'low' => 2];

    $rows = array_values(array_filter($scored_posts, 'is_array'));

    // Sort: priority first, then combined score descending, stable-ish via id.
    usort($rows, static function (array $a, array $b) use ($rank): int {
        $pa = $rank[(string) ($a['priority'] ?? 'low')] ?? 2;
        $pb = $rank[(string) ($b['priority'] ?? 'low')] ?? 2;
        if ($pa !== $pb) { return $pa <=> $pb; }
        $sa = (int) ($a['stale_score'] ?? 0) + (int) ($a['thin_score'] ?? 0);
        $sb = (int) ($b['stale_score'] ?? 0) + (int) ($b['thin_score'] ?? 0);
        if ($sa !== $sb) { return $sb <=> $sa; }
        return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
    });

    $summary = [
        'total' => count($rows), 'high' => 0, 'medium' => 0, 'low' => 0,
        'stale' => 0, 'thin' => 0, 'fresh' => 0, 'needs_attention' => 0,
    ];
    foreach ($rows as $r) {
        $pri = (string) ($r['priority'] ?? 'low');
        if (isset($summary[$pri])) { $summary[$pri]++; }
        $stale = (int) ($r['stale_score'] ?? 0);
        $thin  = (int) ($r['thin_score'] ?? 0);
        if ($stale >= 50) { $summary['stale']++; }
        if ($thin  >= 40) { $summary['thin']++; }
        if ($stale < 25 && $thin < 30) { $summary['fresh']++; }
        if ($pri === 'high' || $pri === 'medium') { $summary['needs_attention']++; }
    }

    return ['posts' => $rows, 'summary' => $summary];
}

// ===========================================================================
// Runtime boot contract
// ===========================================================================

/** Runtime boot hook (no-op — this feature is ability-driven). Cheap. */
function wpultra_fresh_boot(): void {
    // Intentionally empty: nothing to register on boot.
}

// ===========================================================================
// WP WRAPPERS (guarded) — post metrics, scan, apply, touch
// ===========================================================================

/**
 * Load a single post's freshness metrics from WordPress. WP-only.
 * @return array|WP_Error  Metrics array suitable for wpultra_fresh_score().
 */
function wpultra_fresh_post_metrics(int $post_id) {
    if (!function_exists('get_post')) { return wpultra_err('no_wp', 'WordPress runtime unavailable.'); }
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('post_not_found', "Post $post_id not found."); }

    $content = (string) ($post->post_content ?? '');
    $wc = wpultra_fresh_word_count($content);

    $modified_ts = 0;
    if (!empty($post->post_modified_gmt)) { $modified_ts = (int) strtotime((string) $post->post_modified_gmt . ' UTC'); }
    $published_ts = 0;
    if (!empty($post->post_date_gmt)) { $published_ts = (int) strtotime((string) $post->post_date_gmt . ' UTC'); }

    $home = function_exists('home_url') ? (string) home_url() : '';
    $host = $home !== '' && function_exists('wp_parse_url') ? (string) wp_parse_url($home, PHP_URL_HOST) : '';

    // Count images: <img> tags + Gutenberg image blocks + a featured image.
    $images = preg_match_all('/<img\b/i', $content, $ignore);
    $images = $images === false ? 0 : (int) $images;
    if (function_exists('has_post_thumbnail') && has_post_thumbnail($post_id)) { $images++; }

    // Count links, split internal vs outbound by host.
    $internal = 0; $outbound = 0;
    if (preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $content, $lm)) {
        foreach ($lm[1] as $href) {
            $href = (string) $href;
            if ($href === '' || str_starts_with($href, '#')) { continue; }
            $lh = function_exists('wp_parse_url') ? (string) wp_parse_url($href, PHP_URL_HOST) : '';
            if ($lh === '' || ($host !== '' && $lh === $host)) { $internal++; } else { $outbound++; }
        }
    }

    // Meta description: Yoast or Rank Math, if present.
    $has_meta = false;
    if (function_exists('get_post_meta')) {
        $yoast = (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        $rank  = (string) get_post_meta($post_id, 'rank_math_description', true);
        $has_meta = trim($yoast) !== '' || trim($rank) !== '';
    }

    $comments = isset($post->comment_count) ? (int) $post->comment_count : 0;

    return [
        'modified_ts'    => $modified_ts,
        'published_ts'   => $published_ts,
        'word_count'     => $wc,
        'internal_links' => $internal,
        'outbound_links' => $outbound,
        'images'         => $images,
        'has_meta_desc'  => $has_meta,
        'comment_count'  => $comments,
    ];
}

/**
 * Scan the post library, score each, and return a ranked report. WP-only.
 * @param array $scope {post_types?:array, limit?:int}
 * @return array|WP_Error
 */
function wpultra_fresh_audit(array $scope) {
    if (!function_exists('get_posts')) { return wpultra_err('no_wp', 'WordPress runtime unavailable.'); }

    $post_types = array_values(array_filter(array_map('strval', (array) ($scope['post_types'] ?? ['post', 'page']))));
    if ($post_types === []) { $post_types = ['post', 'page']; }
    // Never touch the plugin's private CPTs.
    $reserved = function_exists('wpultra_reserved_post_types') ? wpultra_reserved_post_types() : [];
    $post_types = array_values(array_diff($post_types, $reserved));
    if ($post_types === []) { return wpultra_err('no_post_types', 'No allowed post types to scan.'); }

    $limit = (int) ($scope['limit'] ?? 50);
    if ($limit < 1) { $limit = 50; }
    if ($limit > 500) { $limit = 500; }

    $ids = get_posts([
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'modified',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    $now = function_exists('current_time') ? (int) current_time('timestamp', true) : time();
    $scored = [];
    foreach ((array) $ids as $pid) {
        $pid = (int) $pid;
        $metrics = wpultra_fresh_post_metrics($pid);
        if (is_wp_error($metrics)) { continue; }
        $score = wpultra_fresh_score($metrics, $now);
        $scored[] = [
            'id'          => $pid,
            'title'       => function_exists('get_the_title') ? (string) get_the_title($pid) : '',
            'post_type'   => function_exists('get_post_type') ? (string) get_post_type($pid) : '',
            'priority'    => $score['priority'],
            'stale_score' => $score['stale_score'],
            'thin_score'  => $score['thin_score'],
            'age_bucket'  => $score['age_bucket'],
            'age_days'    => $score['age_days'],
            'word_count'  => $score['word_count'],
        ];
    }

    return wpultra_fresh_report($scored);
}

/**
 * Apply a caller-approved refresh: update supplied title/content and bump the
 * modified date. Confirm-gated. NEVER invents content. WP-only.
 * @return array|WP_Error
 */
function wpultra_fresh_apply(int $post_id, array $fields, bool $confirm) {
    if (!$confirm) { return wpultra_err('confirm_required', 'apply requires confirm:true.'); }
    if (!function_exists('wp_update_post') || !function_exists('get_post')) {
        return wpultra_err('no_wp', 'WordPress runtime unavailable.');
    }
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('post_not_found', "Post $post_id not found."); }

    $post_type = function_exists('get_post_type') ? (string) get_post_type($post_id) : '';
    if ($post_type !== '' && function_exists('wpultra_reserved_post_types')
        && in_array($post_type, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "Post $post_id is a plugin-internal '$post_type'; not manageable here.");
    }

    $title   = array_key_exists('title', $fields) ? (string) $fields['title'] : null;
    $content = array_key_exists('content', $fields) ? (string) $fields['content'] : null;
    if (($title === null || trim($title) === '') && ($content === null || trim($content) === '')) {
        return wpultra_err('nothing_to_apply', 'Supply refreshed title and/or content to apply.');
    }

    $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
    $now_gmt = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');

    $update = [
        'ID'                => $post_id,
        'post_modified'     => $now,
        'post_modified_gmt' => $now_gmt,
    ];
    $changed = [];
    if ($title !== null && trim($title) !== '') { $update['post_title'] = $title; $changed[] = 'title'; }
    if ($content !== null && trim($content) !== '') { $update['post_content'] = $content; $changed[] = 'content'; }
    $changed[] = 'modified';

    $res = wp_update_post($update, true);
    if (is_wp_error($res)) { return $res; }

    return [
        'post_id'  => $post_id,
        'changed'  => $changed,
        'modified' => $now_gmt,
    ];
}

/**
 * "Touch" a post: bump post_modified to now (a legit freshness signal) without
 * changing content. Confirm-gated. WP-only.
 * @return array|WP_Error
 */
function wpultra_fresh_touch(int $post_id, bool $confirm) {
    if (!$confirm) { return wpultra_err('confirm_required', 'touch requires confirm:true.'); }
    if (!function_exists('wp_update_post') || !function_exists('get_post')) {
        return wpultra_err('no_wp', 'WordPress runtime unavailable.');
    }
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('post_not_found', "Post $post_id not found."); }

    $post_type = function_exists('get_post_type') ? (string) get_post_type($post_id) : '';
    if ($post_type !== '' && function_exists('wpultra_reserved_post_types')
        && in_array($post_type, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "Post $post_id is a plugin-internal '$post_type'; not manageable here.");
    }

    $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
    $now_gmt = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
    $res = wp_update_post(['ID' => $post_id, 'post_modified' => $now, 'post_modified_gmt' => $now_gmt], true);
    if (is_wp_error($res)) { return $res; }
    return ['post_id' => $post_id, 'modified' => $now_gmt, 'touched' => true];
}
