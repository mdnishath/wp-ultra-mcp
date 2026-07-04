<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/**
 * AI SEO auto-pilot (roadmap F5).
 *
 * A scheduled pipeline that keeps a site's on-page SEO healthy without a human
 * in the loop: AUDIT posts (reusing the existing SEO audit engine) → decide
 * which posts need which fixes → FIX META (generate a good title/description
 * with AI, or a deterministic fallback when no key) → add INTERNAL LINKS →
 * ensure JSON-LD SCHEMA. Every stage records a before/after action so the run
 * is fully auditable.
 *
 * SAFETY — dry-run first. Both the scheduled cron run and the ability DEFAULT
 * to dry_run:true: the pilot PREVIEWS every change and writes nothing. Live
 * writes require an explicit dry_run:false (and, from the ability, confirm:true).
 * The cron run respects the config's dry_run_default (which itself defaults to
 * true), so an enabled-but-unconfigured autopilot only ever previews.
 *
 * This engine ORCHESTRATES the existing SEO engine — it never re-implements
 * auditing or meta writing. It calls:
 *   - wpultra_seo_audit_extract() / wpultra_seo_audit_post()  (includes/seo/audit.php)
 *   - wpultra_seo_set_meta()                                   (includes/seo/meta.php)
 *   - wpultra_seo_extract_post()                               (includes/seo/analyze.php)
 *   - wpultra_seo_suggest_links() / wpultra_seo_insert_link()  (includes/seo/links.php)
 *   - wpultra_seo_get_schema() / wpultra_seo_set_schema()      (includes/seo/technical.php)
 *   - wpultra_ai_has_key() / wpultra_ai_chat()                 (includes/ai/setup.php)
 *
 * PURE functions first (prefix wpultra_seopilot_, no WordPress — unit-tested in
 * tests/seopilot.test.php); thin WP wrappers after. The controller calls
 * wpultra_seopilot_boot() on plugins_loaded; boot registers the cron handler
 * and cheaply reconciles the recurring event with the config.
 */

const WPULTRA_SEOPILOT_OPTION       = 'wpultra_seopilot';
const WPULTRA_SEOPILOT_SCHED_MARKER = 'wpultra_seopilot_sched';
const WPULTRA_SEOPILOT_EVENT        = 'wpultra_seopilot_cron';
const WPULTRA_SEOPILOT_HISTORY_CAP  = 20;
const WPULTRA_SEOPILOT_LIMIT_MAX    = 100;
const WPULTRA_SEOPILOT_TITLE_MAX    = 60;
const WPULTRA_SEOPILOT_DESC_MAX     = 155;

/* ===================================================================== *
 * PURE core — no WordPress calls. Everything here is unit-testable.
 * ===================================================================== */

/** PURE. Default configuration shape for the `wpultra_seopilot` option. */
function wpultra_seopilot_defaults(): array {
    return [
        'enabled'         => false,
        'recurrence'      => 'daily',      // daily | weekly
        'scope'           => [
            'post_types'    => ['post', 'page'],
            'limit_per_run' => 20,
        ],
        'steps'           => [
            'fix_meta'       => true,
            'internal_links' => true,
            'schema'         => true,
        ],
        'dry_run_default' => true,          // SAFE: cron previews unless flipped off
        'last_run'        => 0,
        'last_report'     => [],
        'history'         => [],            // newest-first, capped at WPULTRA_SEOPILOT_HISTORY_CAP
    ];
}

/**
 * PURE. Map a recurrence keyword to a WP-Cron schedule slug.
 * daily → 'daily', weekly → 'weekly'; anything else → 'daily' (safe default).
 */
function wpultra_seopilot_interval(string $recurrence): string {
    return $recurrence === 'weekly' ? 'weekly' : 'daily';
}

/**
 * PURE. UTF-8-aware character count (mirrors wpultra_seo_strlen so titles in
 * non-Latin scripts aren't over-counted as raw bytes).
 */
function wpultra_seopilot_strlen(string $s): int {
    return function_exists('mb_strlen') ? (int) mb_strlen($s) : (int) preg_match_all('/./us', $s, $__m);
}

/**
 * PURE. Clip a string to at most $max characters WITHOUT cutting a word (or an
 * HTML entity) in half. Trims trailing whitespace/punctuation from the cut.
 * Short strings pass through unchanged.
 */
function wpultra_seopilot_clip(string $s, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    if ($max <= 0) { return ''; }
    if (wpultra_seopilot_strlen($s) <= $max) { return $s; }

    // Cut on a code-point boundary first (never split a multibyte char).
    $cut = function_exists('mb_substr') ? mb_substr($s, 0, $max) : $s;
    if (!function_exists('mb_substr')) {
        // Byte-safe fallback: walk code points.
        preg_match_all('/./us', $s, $m);
        $cut = implode('', array_slice($m[0], 0, $max));
    }

    // Prefer breaking at the last space so we don't cut a word mid-way.
    $lastSpace = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ') : strrpos($cut, ' ');
    if ($lastSpace !== false && $lastSpace > 0) {
        $cut = function_exists('mb_substr') ? mb_substr($cut, 0, $lastSpace) : substr($cut, 0, $lastSpace);
    }

    // Never leave a dangling half-entity (…&am) at the tail.
    $cut = preg_replace('/&[a-z0-9#]*$/i', '', $cut) ?? $cut;
    return rtrim($cut, " \t\n\r\0\x0B.,;:—-");
}

/**
 * PURE. Decide, from a list of per-post audit records, which posts need which
 * fixes given the enabled steps. Each $audits entry is
 *   {post_id, seo_title, seo_desc, internal_links, has_schema}
 * (the WP wrapper builds these from wpultra_seo_audit_extract + extract_post).
 * $steps is {fix_meta,internal_links,schema} booleans.
 *
 * A post is a target for:
 *   - meta  : title missing/empty OR too short/long OR description missing/short
 *   - links : it has zero internal links
 *   - schema: it has no JSON-LD schema set
 * Only enabled steps contribute; a post with no needed+enabled fix is dropped.
 *
 * @return array<int,array{post_id:int,needs:array<int,string>}>
 */
function wpultra_seopilot_pick_targets(array $audits, array $steps): array {
    $wantMeta   = !empty($steps['fix_meta']);
    $wantLinks  = !empty($steps['internal_links']);
    $wantSchema = !empty($steps['schema']);

    $out = [];
    foreach ($audits as $a) {
        $needs = [];
        $title = trim((string) ($a['seo_title'] ?? ''));
        $desc  = trim((string) ($a['seo_desc'] ?? ''));

        if ($wantMeta) {
            $titleBad = ($title === '')
                || wpultra_seopilot_strlen($title) > WPULTRA_SEOPILOT_TITLE_MAX;
            $descBad = ($desc === '')
                || wpultra_seopilot_strlen($desc) < 120;
            if ($titleBad || $descBad) { $needs[] = 'meta'; }
        }
        if ($wantLinks && (int) ($a['internal_links'] ?? 0) <= 0) { $needs[] = 'links'; }
        if ($wantSchema && empty($a['has_schema'])) { $needs[] = 'schema'; }

        if ($needs !== []) {
            $out[] = ['post_id' => (int) ($a['post_id'] ?? 0), 'needs' => $needs];
        }
    }
    return $out;
}

/**
 * PURE. Build the {system,user} chat messages that ask the model for a fresh
 * SEO title and description as JSON. Embeds the post's title, excerpt and focus
 * keyword and states the hard character limits so the model self-clips.
 *
 * @param array{title?:string,excerpt?:string,focus_keyword?:string} $post_summary
 * @return array{system:string,user:string}
 */
function wpultra_seopilot_meta_prompt(array $post_summary): array {
    $title   = trim((string) ($post_summary['title'] ?? ''));
    $excerpt = trim((string) ($post_summary['excerpt'] ?? ''));
    $focus   = trim((string) ($post_summary['focus_keyword'] ?? ''));

    $system = 'You are an expert SEO copywriter. Given a web page, write a compelling, '
        . 'accurate meta title and meta description. Respond with STRICT JSON only, no prose, '
        . 'in the shape {"title": string, "description": string}. '
        . 'The title MUST be at most ' . WPULTRA_SEOPILOT_TITLE_MAX . ' characters. '
        . 'The description MUST be at most ' . WPULTRA_SEOPILOT_DESC_MAX . ' characters '
        . 'and ideally at least 120. Do not keyword-stuff; write for humans.';

    $user = "Page title: " . ($title !== '' ? $title : '(untitled)') . "\n"
        . "Focus keyword: " . ($focus !== '' ? $focus : '(none)') . "\n"
        . "Excerpt / opening: " . ($excerpt !== '' ? $excerpt : '(no excerpt available)') . "\n\n"
        . 'Write the SEO title (<=' . WPULTRA_SEOPILOT_TITLE_MAX . ' chars) and meta description (<='
        . WPULTRA_SEOPILOT_DESC_MAX . ' chars) as JSON.';

    return ['system' => $system, 'user' => $user];
}

/**
 * PURE. Parse the model's reply into {title,description}. Accepts raw JSON, a
 * ```json fenced block, or JSON embedded in surrounding prose. Returns an error
 * STRING when no usable object/fields can be extracted (caller falls back).
 *
 * @return array{title:string,description:string}|string
 */
function wpultra_seopilot_parse_meta(string $ai_json) {
    $raw = trim($ai_json);
    if ($raw === '') { return 'empty AI response'; }

    // Strip a ```json ... ``` (or plain ```) fence if present.
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw, $m)) { $raw = trim($m[1]); }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // Last resort: grab the first {...} object in the text.
        if (preg_match('/\{.*\}/s', $raw, $mm)) { $data = json_decode($mm[0], true); }
    }
    if (!is_array($data)) { return 'AI response was not valid JSON'; }

    $title = isset($data['title']) ? trim((string) $data['title']) : '';
    $desc  = isset($data['description']) ? trim((string) $data['description']) : '';
    if ($title === '' && $desc === '') { return 'AI JSON had no title or description'; }

    return [
        'title'       => wpultra_seopilot_clip($title, WPULTRA_SEOPILOT_TITLE_MAX),
        'description' => wpultra_seopilot_clip($desc, WPULTRA_SEOPILOT_DESC_MAX),
    ];
}

/**
 * PURE. Deterministic fallback meta when there's no AI key. Title = the post
 * title clipped to the limit; description = the excerpt (or the title again)
 * clipped to the limit. Never returns a mid-word cut.
 *
 * @return array{title:string,description:string}
 */
function wpultra_seopilot_fallback_meta(string $title, string $excerpt): array {
    $title   = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    $excerpt = trim(preg_replace('/\s+/u', ' ', $excerpt) ?? $excerpt);
    $descSrc = $excerpt !== '' ? $excerpt : $title;
    return [
        'title'       => wpultra_seopilot_clip($title, WPULTRA_SEOPILOT_TITLE_MAX),
        'description' => wpultra_seopilot_clip($descSrc, WPULTRA_SEOPILOT_DESC_MAX),
    ];
}

/**
 * PURE. Roll a flat list of per-post stage actions into headline counts.
 * Each action is {post_id, stage, action, applied}. schema/links/meta counts
 * reflect only APPLIED (or, in dry-run, WOULD-apply) actions; skipped counts
 * no-op stages.
 *
 * @return array{audited:int,meta_fixed:int,links_added:int,schema_added:int,skipped:int}
 */
function wpultra_seopilot_summarize(array $actions): array {
    $posts = [];
    $out = ['audited' => 0, 'meta_fixed' => 0, 'links_added' => 0, 'schema_added' => 0, 'skipped' => 0];
    foreach ($actions as $a) {
        $posts[(int) ($a['post_id'] ?? 0)] = true;
        $stage = (string) ($a['stage'] ?? '');
        $did   = !empty($a['applied']);
        $act   = (string) ($a['action'] ?? '');
        if ($act === 'skip' || $act === 'noop') { $out['skipped']++; continue; }
        if (!$did) { continue; }
        if ($stage === 'meta')   { $out['meta_fixed']++; }
        elseif ($stage === 'links')  { $out['links_added']++; }
        elseif ($stage === 'schema') { $out['schema_added']++; }
    }
    $out['audited'] = count($posts);
    return $out;
}

/**
 * PURE. Validate + normalize a (defaults-merged) config. Clamps limit, enforces
 * the recurrence enum, coerces the steps/scope flags. Returns the cleaned
 * config (never a WP_Error — invalid values are corrected, not rejected).
 */
function wpultra_seopilot_validate_config(array $cfg): array {
    $d = wpultra_seopilot_defaults();
    $out = $d;

    $out['enabled']         = !empty($cfg['enabled']);
    $out['recurrence']      = in_array(($cfg['recurrence'] ?? ''), ['daily', 'weekly'], true)
        ? (string) $cfg['recurrence'] : $d['recurrence'];
    $out['dry_run_default'] = array_key_exists('dry_run_default', $cfg)
        ? (bool) $cfg['dry_run_default'] : $d['dry_run_default'];

    // scope
    $scope = is_array($cfg['scope'] ?? null) ? $cfg['scope'] : [];
    $types = $scope['post_types'] ?? $d['scope']['post_types'];
    $types = is_array($types) ? array_values(array_filter(array_map('strval', $types), fn($t) => $t !== '')) : [];
    if ($types === []) { $types = $d['scope']['post_types']; }
    $limit = (int) ($scope['limit_per_run'] ?? $d['scope']['limit_per_run']);
    $limit = max(1, min(WPULTRA_SEOPILOT_LIMIT_MAX, $limit));
    $out['scope'] = ['post_types' => $types, 'limit_per_run' => $limit];

    // steps
    $steps = is_array($cfg['steps'] ?? null) ? $cfg['steps'] : [];
    $out['steps'] = [
        'fix_meta'       => array_key_exists('fix_meta', $steps) ? (bool) $steps['fix_meta'] : $d['steps']['fix_meta'],
        'internal_links' => array_key_exists('internal_links', $steps) ? (bool) $steps['internal_links'] : $d['steps']['internal_links'],
        'schema'         => array_key_exists('schema', $steps) ? (bool) $steps['schema'] : $d['steps']['schema'],
    ];

    // preserve state fields
    $out['last_run']    = (int) ($cfg['last_run'] ?? 0);
    $out['last_report'] = is_array($cfg['last_report'] ?? null) ? $cfg['last_report'] : [];
    $hist = is_array($cfg['history'] ?? null) ? $cfg['history'] : [];
    $out['history'] = array_slice(array_values($hist), 0, WPULTRA_SEOPILOT_HISTORY_CAP);

    return $out;
}

/* ===================================================================== *
 * WP wrappers — guarded so the file still loads under the pure harness.
 * ===================================================================== */

if (function_exists('get_option')) {

    /** Current config, defaults-merged and validated. */
    function wpultra_seopilot_config(): array {
        $stored = get_option(WPULTRA_SEOPILOT_OPTION, []);
        if (!is_array($stored)) { $stored = []; }
        return wpultra_seopilot_validate_config(array_merge(wpultra_seopilot_defaults(), $stored));
    }

    /** Persist a validated config. */
    function wpultra_seopilot_save_config(array $cfg): array {
        $clean = wpultra_seopilot_validate_config($cfg);
        update_option(WPULTRA_SEOPILOT_OPTION, $clean, false);
        return $clean;
    }
}

if (function_exists('get_posts') || function_exists('wpultra_seo_audit_extract')) {

    /**
     * Build the audit record for one post (reusing the SEO audit + analyze
     * engines). Adds internal_links and has_schema so pick_targets can decide.
     */
    function wpultra_seopilot_audit_one(int $post_id): array {
        $base    = function_exists('wpultra_seo_audit_extract') ? wpultra_seo_audit_extract($post_id) : [];
        $extract = function_exists('wpultra_seo_extract_post') ? wpultra_seo_extract_post($post_id) : [];
        $schema  = function_exists('wpultra_seo_get_schema') ? wpultra_seo_get_schema($post_id) : [];
        return [
            'post_id'        => $post_id,
            'seo_title'      => (string) ($base['seo_title'] ?? ''),
            'seo_desc'       => (string) ($base['seo_desc'] ?? ''),
            'focus_keyword'  => (string) ($base['focus_keyword'] ?? ''),
            'internal_links' => (int) ($extract['internal_links'] ?? 0),
            'excerpt'        => (string) ($extract['first_paragraph'] ?? ''),
            'title'          => (string) ($extract['title'] ?? (function_exists('get_the_title') ? get_the_title($post_id) : '')),
            'has_schema'     => !empty($schema['type']),
        ];
    }

    /** Generate {title,description} for a post (AI when keyed, else fallback). */
    function wpultra_seopilot_gen_meta(array $audit): array {
        $title   = (string) ($audit['title'] ?? '');
        $excerpt = (string) ($audit['excerpt'] ?? '');
        if (function_exists('wpultra_ai_has_key') && wpultra_ai_has_key() && function_exists('wpultra_ai_chat')) {
            $p = wpultra_seopilot_meta_prompt([
                'title'         => $title,
                'excerpt'       => $excerpt,
                'focus_keyword' => (string) ($audit['focus_keyword'] ?? ''),
            ]);
            $reply = wpultra_ai_chat($p['system'], $p['user'], ['json' => true, 'max_tokens' => 300]);
            if (is_string($reply)) {
                $parsed = wpultra_seopilot_parse_meta($reply);
                if (is_array($parsed) && ($parsed['title'] !== '' || $parsed['description'] !== '')) {
                    // Fill any empty field from the deterministic fallback.
                    $fb = wpultra_seopilot_fallback_meta($title, $excerpt);
                    if ($parsed['title'] === '') { $parsed['title'] = $fb['title']; }
                    if ($parsed['description'] === '') { $parsed['description'] = $fb['description']; }
                    $parsed['source'] = 'ai';
                    return $parsed;
                }
            }
        }
        $fb = wpultra_seopilot_fallback_meta($title, $excerpt);
        $fb['source'] = 'fallback';
        return $fb;
    }

    /**
     * Run the full pipeline. $opts is a validated config subtree
     * {scope:{post_types,limit_per_run}, steps:{...}}. $dry_run is authoritative
     * (the caller decides it from the ability flag or the config default).
     *
     * @return array{dry_run:bool,actions:array,summary:array,targets:int}
     */
    function wpultra_seopilot_run(array $opts, bool $dry_run): array {
        $types = $opts['scope']['post_types'] ?? ['post', 'page'];
        $limit = (int) ($opts['scope']['limit_per_run'] ?? 20);
        $limit = max(1, min(WPULTRA_SEOPILOT_LIMIT_MAX, $limit));
        $steps = is_array($opts['steps'] ?? null) ? $opts['steps'] : ['fix_meta' => true, 'internal_links' => true, 'schema' => true];

        $ids = function_exists('get_posts')
            ? get_posts(['post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids'])
            : [];
        if ($ids && function_exists('update_meta_cache')) { update_meta_cache('post', array_map('intval', $ids)); }

        $audits = [];
        foreach ($ids as $id) { $audits[(int) $id] = wpultra_seopilot_audit_one((int) $id); }
        $targets = wpultra_seopilot_pick_targets(array_values($audits), $steps);

        $actions = [];
        foreach ($targets as $t) {
            $pid   = (int) $t['post_id'];
            $needs = $t['needs'];
            $audit = $audits[$pid] ?? wpultra_seopilot_audit_one($pid);

            if (in_array('meta', $needs, true)) {
                $actions[] = wpultra_seopilot_stage_meta($pid, $audit, $dry_run);
            }
            if (in_array('links', $needs, true)) {
                $actions[] = wpultra_seopilot_stage_links($pid, $dry_run);
            }
            if (in_array('schema', $needs, true)) {
                $actions[] = wpultra_seopilot_stage_schema($pid, $audit, $dry_run);
            }
        }

        return [
            'dry_run' => $dry_run,
            'targets' => count($targets),
            'actions' => $actions,
            'summary' => wpultra_seopilot_summarize($actions),
        ];
    }

    /** META stage for one post → the recorded action row. */
    function wpultra_seopilot_stage_meta(int $post_id, array $audit, bool $dry_run): array {
        $gen = wpultra_seopilot_gen_meta($audit);
        $before = ['title' => (string) ($audit['seo_title'] ?? ''), 'description' => (string) ($audit['seo_desc'] ?? '')];
        $after  = ['title' => $gen['title'], 'description' => $gen['description']];

        $applied = false;
        if (!$dry_run && function_exists('wpultra_seo_set_meta')) {
            $fields = [];
            if ($after['title'] !== '') { $fields['title'] = $after['title']; }
            if ($after['description'] !== '') { $fields['description'] = $after['description']; }
            if ($fields) {
                $res = wpultra_seo_set_meta($post_id, $fields);
                $applied = !(function_exists('is_wp_error') && is_wp_error($res));
            }
        }
        return [
            'post_id' => $post_id, 'stage' => 'meta', 'action' => 'set_meta',
            'source'  => $gen['source'] ?? 'fallback',
            'before'  => $before, 'after' => $after, 'applied' => $applied,
        ];
    }

    /** INTERNAL-LINKS stage for one post → the recorded action row. */
    function wpultra_seopilot_stage_links(int $post_id, bool $dry_run): array {
        $suggestions = function_exists('wpultra_seo_suggest_links') ? wpultra_seo_suggest_links($post_id, 1) : [];
        $pick = $suggestions[0] ?? null;
        if (!$pick) {
            return ['post_id' => $post_id, 'stage' => 'links', 'action' => 'noop', 'before' => 0, 'after' => 0, 'applied' => false];
        }
        $anchor = (string) ($pick['anchor_suggestion'] ?? $pick['target_title'] ?? '');
        $url    = (string) ($pick['target_url'] ?? '');
        $applied = false;
        if (!$dry_run && function_exists('wpultra_seo_insert_link') && $anchor !== '' && $url !== '') {
            $res = wpultra_seo_insert_link($post_id, $anchor, $url);
            $applied = is_array($res) && !empty($res['inserted']);
        }
        return [
            'post_id' => $post_id, 'stage' => 'links', 'action' => 'insert_link',
            'before'  => 0, 'after' => ['anchor' => $anchor, 'url' => $url, 'target_id' => (int) ($pick['target_id'] ?? 0)],
            'applied' => $applied,
        ];
    }

    /** SCHEMA stage for one post → the recorded action row. */
    function wpultra_seopilot_stage_schema(int $post_id, array $audit, bool $dry_run): array {
        // Choose a sensible default schema type: Article for posts/pages.
        $type = 'Article';
        $fields = [
            'headline' => (string) ($audit['title'] ?? ''),
            'date'     => function_exists('get_the_date') ? (string) get_the_date('c', $post_id) : '',
        ];
        $applied = false;
        if (!$dry_run && function_exists('wpultra_seo_set_schema')) {
            $res = wpultra_seo_set_schema($post_id, $type, $fields);
            $applied = is_array($res) && !empty($res['type']);
        }
        return [
            'post_id' => $post_id, 'stage' => 'schema', 'action' => 'set_schema',
            'before'  => empty($audit['has_schema']) ? 'none' : 'present',
            'after'   => ['type' => $type], 'applied' => $applied,
        ];
    }

    /** Preview what the pilot WOULD do to a single post (always dry). */
    function wpultra_seopilot_preview_post(int $post_id): array {
        if (function_exists('get_post') && !get_post($post_id)) {
            return wpultra_err('post_not_found', "No post with id $post_id.");
        }
        $cfg   = function_exists('wpultra_seopilot_config') ? wpultra_seopilot_config() : wpultra_seopilot_validate_config(wpultra_seopilot_defaults());
        $audit = wpultra_seopilot_audit_one($post_id);
        $targets = wpultra_seopilot_pick_targets([$audit], $cfg['steps']);
        $needs = $targets[0]['needs'] ?? [];

        $actions = [];
        if (in_array('meta', $needs, true))   { $actions[] = wpultra_seopilot_stage_meta($post_id, $audit, true); }
        if (in_array('links', $needs, true))  { $actions[] = wpultra_seopilot_stage_links($post_id, true); }
        if (in_array('schema', $needs, true)) { $actions[] = wpultra_seopilot_stage_schema($post_id, $audit, true); }

        return [
            'post_id' => $post_id,
            'needs'   => $needs,
            'actions' => $actions,
            'summary' => wpultra_seopilot_summarize($actions),
        ];
    }
}

if (function_exists('update_option')) {

    /** Record a run into last_run/last_report + the history ring. */
    function wpultra_seopilot_record_run(array $report): void {
        $cfg = wpultra_seopilot_config();
        $entry = [
            'ts'      => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
            'dry_run' => !empty($report['dry_run']),
            'summary' => $report['summary'] ?? [],
        ];
        $cfg['last_run']    = time();
        $cfg['last_report'] = $report;
        array_unshift($cfg['history'], $entry);
        $cfg['history'] = array_slice($cfg['history'], 0, WPULTRA_SEOPILOT_HISTORY_CAP);
        update_option(WPULTRA_SEOPILOT_OPTION, wpultra_seopilot_validate_config($cfg), false);
    }
}

/** WP-Cron handler — the scheduled run. Respects the config's dry_run_default. */
function wpultra_seopilot_cron_handler(): void {
    if (!function_exists('wpultra_seopilot_config')) { return; }
    $cfg = wpultra_seopilot_config();
    if (empty($cfg['enabled'])) { return; }
    $dry = !empty($cfg['dry_run_default']); // SAFE default
    $report = wpultra_seopilot_run($cfg, $dry);
    if (function_exists('wpultra_seopilot_record_run')) { wpultra_seopilot_record_run($report); }
    if (function_exists('wpultra_audit_log')) {
        $s = $report['summary'];
        wpultra_audit_log('seo-autopilot', ($dry ? 'cron dry-run' : 'cron applied') . ' meta=' . ($s['meta_fixed'] ?? 0) . ' links=' . ($s['links_added'] ?? 0) . ' schema=' . ($s['schema_added'] ?? 0), true);
    }
}

/**
 * Always-on runtime. The controller calls this on plugins_loaded — cheap and
 * idempotent. Registers the cron handler and reconciles the recurring event
 * with the config (marker option detects recurrence changes).
 */
function wpultra_seopilot_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;

    if (function_exists('add_action')) {
        add_action(WPULTRA_SEOPILOT_EVENT, 'wpultra_seopilot_cron_handler');
    }
    if (!function_exists('get_option') || !function_exists('update_option')
        || !function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }

    $cfg = wpultra_seopilot_config();
    $desired = !empty($cfg['enabled']) ? wpultra_seopilot_interval((string) $cfg['recurrence']) : '';
    $marker = (string) get_option(WPULTRA_SEOPILOT_SCHED_MARKER, '');

    if ($desired === '') {
        if ($marker !== '') {
            if (function_exists('wp_clear_scheduled_hook')) { wp_clear_scheduled_hook(WPULTRA_SEOPILOT_EVENT); }
            update_option(WPULTRA_SEOPILOT_SCHED_MARKER, '', false);
        }
        return;
    }

    if ($marker !== $desired && function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook(WPULTRA_SEOPILOT_EVENT);
    }
    if (!wp_next_scheduled(WPULTRA_SEOPILOT_EVENT)) {
        wp_schedule_event(time() + 120, $desired, WPULTRA_SEOPILOT_EVENT);
    }
    if ($marker !== $desired) {
        update_option(WPULTRA_SEOPILOT_SCHED_MARKER, $desired, false);
    }
}
