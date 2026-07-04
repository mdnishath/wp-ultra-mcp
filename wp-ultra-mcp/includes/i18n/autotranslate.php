<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Full-site autotranslate engine (roadmap D2). Translates every post/page/
 * product into a target language in one command by ORCHESTRATING the existing
 * multilingual engine (includes/i18n/engine.php) + the shared AI helper
 * (includes/ai/setup.php). It never re-implements WPML/Polylang linkage or the
 * JSON-safe content writer — for each source post it:
 *
 *   1. wpultra_i18n_duplicate_to_language()  — creates + LINKS the translation
 *      shell in the target language (WPML/Polylang aware, Elementor-safe meta).
 *   2. translates the title/content/excerpt (caller-supplied OR server-side AI).
 *   3. wpultra_i18n_fill()                    — writes the translated strings in
 *      (JSON-safe find/replace under the hood).
 *
 * Two honest translation sources:
 *   (a) CALLER-SUPPLIED (always available): the calling AI passes translated
 *       {title, content, excerpt} per post; we write them into the linked copy.
 *   (b) SERVER-SIDE AI (needs an OpenAI key): we translate each post's text via
 *       wpultra_ai_chat(). Without a key the batch either returns the source
 *       strings for the caller to translate + re-submit, OR does structure-only
 *       (creates the linked shells for the caller to fill later).
 *
 * PURE helpers are prefixed wpultra_atrans_ and carry no WordPress calls; the
 * thin WP wrappers live at the bottom, each guarded for harness-loadability.
 */

// ---------------------------------------------------------------------------
// Constants (option-based, CPT-free async queue).
// ---------------------------------------------------------------------------

const WPULTRA_ATRANS_OPTION    = 'wpultra_atrans_job';   // the single active job blob
const WPULTRA_ATRANS_TICK_HOOK = 'wpultra_atrans_tick';  // cron hook: translate one post/tick

// ---------------------------------------------------------------------------
// PURE: language-code normaliser
// ---------------------------------------------------------------------------

/**
 * Pure: normalise a raw language code to a lowercase, trimmed slug. Accepts
 * loose caller input like 'FR', ' fr ', 'fr-FR' and returns 'fr-fr'. Empty in →
 * empty out. Used so target_lang comparisons are consistent everywhere.
 */
function wpultra_atrans_norm_lang(string $code): string {
    return strtolower(trim($code));
}

// ---------------------------------------------------------------------------
// PURE: batch target selection
// ---------------------------------------------------------------------------

/**
 * Pure: from a list of candidate source posts, drop those that already have a
 * translation in $target_lang, and return the queue of post IDs still needing
 * one.
 *
 * @param array<int,array{id:int,type?:string,lang?:string}> $posts
 *        Each row: id (required), type (optional post_type), lang (optional
 *        source language of the post).
 * @param string $target_lang        The language we want translations in.
 * @param array<int,int|string> $already_translated  Source post IDs that already
 *        have a $target_lang translation (as reported by the plugin).
 * @param array $opts  {post_types?: string[] (allow-list), limit?: int (>0 caps
 *        the queue), skip_same_lang?: bool (default true — a post already in the
 *        target language is skipped)}.
 * @return array<int,int>  ordered, de-duplicated list of post IDs to translate.
 */
function wpultra_atrans_pick_targets(array $posts, string $target_lang, array $already_translated = [], array $opts = []): array {
    $target = wpultra_atrans_norm_lang($target_lang);
    $done   = [];
    foreach ($already_translated as $id) { $done[(int) $id] = true; }

    $types = [];
    if (!empty($opts['post_types']) && is_array($opts['post_types'])) {
        foreach ($opts['post_types'] as $t) { $types[(string) $t] = true; }
    }
    $limit = isset($opts['limit']) ? (int) $opts['limit'] : 0;
    $skip_same_lang = !array_key_exists('skip_same_lang', $opts) || !empty($opts['skip_same_lang']);

    $queue = [];
    $seen  = [];
    foreach ($posts as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) { continue; }

        // Type allow-list (when provided).
        if ($types !== []) {
            $type = (string) ($row['type'] ?? '');
            if (!isset($types[$type])) { continue; }
        }

        // Already has a target-language translation.
        if (isset($done[$id])) { continue; }

        // Post is already in the target language — nothing to translate.
        if ($skip_same_lang) {
            $lang = wpultra_atrans_norm_lang((string) ($row['lang'] ?? ''));
            if ($lang !== '' && $target !== '' && $lang === $target) { continue; }
        }

        $seen[$id] = true;
        $queue[]   = $id;
        if ($limit > 0 && count($queue) >= $limit) { break; }
    }
    return $queue;
}

// ---------------------------------------------------------------------------
// PURE: token protection (belt-and-suspenders with the prompt)
// ---------------------------------------------------------------------------

/**
 * Pure: replace shortcodes, Gutenberg block delimiters and URLs in $html with
 * opaque placeholder tokens so the translation model can't mangle them. Returns
 * {text, tokens} where tokens maps placeholder => original substring. Restore
 * with wpultra_atrans_restore_tokens().
 *
 * Placeholders use a form no natural-language sentence contains and that a
 * translator will pass through verbatim: «⟦WPUTOKn⟧». Order of extraction is:
 * block comments first (they may wrap shortcodes/URLs), then shortcodes, then
 * bare URLs — each pass operates on the already-tokenised string so nested
 * cases (a URL inside a shortcode inside a block) collapse cleanly.
 *
 * @return array{text:string, tokens:array<string,string>}
 */
function wpultra_atrans_protect_tokens(string $html): array {
    $tokens = [];
    $i = 0;
    $mk = static function (string $match) use (&$tokens, &$i): string {
        $ph = '⟦WPUTOK' . $i . '⟧';
        $tokens[$ph] = $match;
        $i++;
        return $ph;
    };

    // 1) Gutenberg block delimiters: <!-- wp:... --> and <!-- /wp:... -->
    $html = (string) preg_replace_callback(
        '/<!--\s*\/?wp:.*?-->/s',
        static fn(array $m): string => $mk($m[0]),
        $html
    );

    // 2) Shortcodes: [tag ...] and closing [/tag]. Skip already-tokenised text.
    $html = (string) preg_replace_callback(
        '/\[\/?[a-zA-Z][a-zA-Z0-9_\-]*(?:[^\[\]]*)\]/',
        static fn(array $m): string => $mk($m[0]),
        $html
    );

    // 3) Bare URLs (http/https). href/src attribute URLs are usually already
    //    inside block/shortcode tokens; this catches free-standing links.
    $html = (string) preg_replace_callback(
        '#https?://[^\s"\'<>()\[\]⟦⟧]+#u',
        static fn(array $m): string => $mk($m[0]),
        $html
    );

    return ['text' => $html, 'tokens' => $tokens];
}

/**
 * Pure: inverse of wpultra_atrans_protect_tokens(). Substitutes every
 * placeholder back with its original substring. Longest placeholder first so
 * ⟦WPUTOK1⟧ is never a prefix-collision with ⟦WPUTOK10⟧ (str_replace on the
 * full delimited token is already unambiguous, but we sort defensively).
 *
 * @param array<string,string> $tokens
 */
function wpultra_atrans_restore_tokens(string $text, array $tokens): string {
    if ($tokens === []) { return $text; }
    $keys = array_keys($tokens);
    usort($keys, static fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));
    foreach ($keys as $ph) {
        $text = str_replace((string) $ph, (string) $tokens[$ph], $text);
    }
    return $text;
}

// ---------------------------------------------------------------------------
// PURE: translation prompt
// ---------------------------------------------------------------------------

/**
 * Pure: build the {system, user} chat messages that instruct the model to
 * translate a post into $target_lang while preserving all machine-readable
 * markup. The system prompt hard-codes the preservation rules; the user prompt
 * carries the JSON payload of the (token-protected) fields.
 *
 * @param array{title?:string,content?:string,excerpt?:string} $post
 * @return array{system:string,user:string}
 */
function wpultra_atrans_prompt(array $post, string $target_lang): array {
    $target = trim($target_lang);
    $system = "You are a professional website translator. Translate the given WordPress post fields into the target language: {$target}.\n"
        . "Preserve the meaning and tone. CRITICAL — keep the following EXACTLY as-is, untranslated and byte-for-byte:\n"
        . "- HTML tags and attributes (<a href=\"...\">, <img src=\"...\">, class names, ids)\n"
        . "- Gutenberg block comments (<!-- wp:paragraph -->, <!-- /wp:paragraph -->, and their JSON attributes)\n"
        . "- Shortcodes in square brackets ([contact-form-7 ...], [gallery], [/vc_row])\n"
        . "- URLs, email addresses, file paths, and any ⟦WPUTOK…⟧ placeholder tokens\n"
        . "Only translate the human-readable text between/around those elements.\n"
        . "Return ONLY a JSON object with the keys \"title\", \"content\", and \"excerpt\" — each holding the translated value (use an empty string for a field that was empty or absent). Do not add commentary.";

    $payload = [
        'title'   => (string) ($post['title'] ?? ''),
        'content' => (string) ($post['content'] ?? ''),
        'excerpt' => (string) ($post['excerpt'] ?? ''),
    ];
    $user = "Translate these fields to {$target}. Return the JSON object described.\n\n"
        . (string) wp_json_encode($payload);

    return ['system' => $system, 'user' => $user];
}

// ---------------------------------------------------------------------------
// PURE: parse the model's JSON reply
// ---------------------------------------------------------------------------

/**
 * Pure: parse an AI translation reply into {title, content, excerpt}. Accepts
 * a bare JSON object, a ```json fenced``` block, or a JSON object embedded in
 * surrounding prose (first balanced-looking {...} span). Returns the array on
 * success, or a human-readable error string on failure — never throws.
 *
 * @return array{title:string,content:string,excerpt:string}|string
 */
function wpultra_atrans_parse(string $ai_json) {
    $raw = trim($ai_json);
    if ($raw === '') { return 'Empty AI response.'; }

    // Strip a ```json ... ``` (or plain ``` ... ```) fence if present.
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw, $m)) {
        $raw = trim($m[1]);
    }

    $data = json_decode($raw, true);

    // Fall back to the first {...} span embedded in prose.
    if (!is_array($data)) {
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($raw, $start, $end - $start + 1), true);
        }
    }

    if (!is_array($data)) {
        return 'AI response was not valid JSON.';
    }

    return [
        'title'   => (string) ($data['title'] ?? ''),
        'content' => (string) ($data['content'] ?? ''),
        'excerpt' => (string) ($data['excerpt'] ?? ''),
    ];
}

// ---------------------------------------------------------------------------
// PURE: async-job blob shaping
// ---------------------------------------------------------------------------

/** Pure: valid autotranslate job statuses. */
function wpultra_atrans_states(): array {
    return ['queued', 'running', 'done', 'failed', 'cancelled'];
}

/** Pure: is the job still doing (or about to do) work? */
function wpultra_atrans_is_active(string $status): bool {
    return $status === 'queued' || $status === 'running';
}

/**
 * Pure: fresh job blob for a batch translation.
 *
 * @param array<int,int> $queue  post IDs to translate
 * @param string $source  'ai' (server-side) or 'caller' (structure-only shells)
 */
function wpultra_atrans_new_job(string $target_lang, array $queue, string $source): array {
    $queue = array_values(array_map('intval', $queue));
    return [
        'target_lang' => wpultra_atrans_norm_lang($target_lang),
        'queue'       => $queue,
        'cursor'      => 0,
        'done'        => [],
        'failed'      => [],
        'source'      => $source === 'ai' ? 'ai' : 'caller',
        'status'      => 'queued',
        'total'       => count($queue),
        'message'     => '',
        'cancel'      => false,
    ];
}

/**
 * Pure: shape a job blob for ability output (adds a percent + remaining count).
 */
function wpultra_atrans_shape_job(array $job): array {
    $total  = (int) ($job['total'] ?? count((array) ($job['queue'] ?? [])));
    $cursor = (int) ($job['cursor'] ?? 0);
    $done   = array_values((array) ($job['done'] ?? []));
    $failed = array_values((array) ($job['failed'] ?? []));
    $pct = $total > 0 ? (int) floor(($cursor / $total) * 100) : ($total === 0 ? 100 : 0);
    $pct = max(0, min(100, $pct));
    return [
        'status'      => (string) ($job['status'] ?? 'queued'),
        'source'      => (string) ($job['source'] ?? 'caller'),
        'target_lang' => (string) ($job['target_lang'] ?? ''),
        'total'       => $total,
        'processed'   => $cursor,
        'remaining'   => max(0, $total - $cursor),
        'percent'     => $pct,
        'done'        => $done,
        'failed'      => $failed,
        'message'     => (string) ($job['message'] ?? ''),
    ];
}

// ===========================================================================
// Thin wrappers — the only functions below call WordPress / plugin APIs.
// ===========================================================================

/**
 * Report multilingual status for the ability's `status` action: which plugin is
 * active + the available languages + whether server-side AI is available.
 * Reuses wpultra_i18n_status() wholesale.
 */
function wpultra_atrans_status(): array {
    $base = function_exists('wpultra_i18n_status')
        ? wpultra_i18n_status()
        : ['active_plugin' => '', 'languages' => [], 'post_type_counts' => []];
    $base['ai_available'] = function_exists('wpultra_ai_has_key') && wpultra_ai_has_key();
    return $base;
}

/**
 * Collect candidate source posts for a batch. Returns rows {id, type, lang}
 * suitable for wpultra_atrans_pick_targets(). Restricted to the default
 * language (so we translate originals, not existing translations) when the
 * plugin can report per-post language.
 *
 * @param array $scope {post_types?: string[], limit?: int}
 * @return array<int,array{id:int,type:string,lang:string}>
 */
function wpultra_atrans_collect_sources(array $scope): array {
    $types = !empty($scope['post_types']) && is_array($scope['post_types'])
        ? array_values(array_map('strval', $scope['post_types']))
        : ['post', 'page'];
    // Include Woo products when the type wasn't explicitly narrowed and Woo is present.
    if (empty($scope['post_types']) && post_type_exists('product') && !in_array('product', $types, true)) {
        $types[] = 'product';
    }
    // Never touch the plugin's private CPTs.
    $reserved = function_exists('wpultra_reserved_post_types') ? wpultra_reserved_post_types() : [];
    $types = array_values(array_diff($types, $reserved));

    $limit = isset($scope['limit']) ? max(0, (int) $scope['limit']) : 0;
    $plugin = function_exists('wpultra_i18n_active_plugin') ? wpultra_i18n_active_plugin() : '';
    $default_lang = wpultra_atrans_default_lang($plugin);

    $rows = [];
    foreach ($types as $pt) {
        if (!post_type_exists($pt)) { continue; }
        $args = [
            'post_type'      => $pt,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        // Restrict Polylang queries to the default language so we don't re-queue
        // existing translations as if they were sources.
        if ($plugin === 'polylang' && $default_lang !== '') { $args['lang'] = $default_lang; }
        $ids = get_posts($args);
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            $lang = wpultra_atrans_post_lang($id, $plugin);
            $rows[] = ['id' => $id, 'type' => $pt, 'lang' => $lang];
        }
    }
    return $rows;
}

/** Live: site default language code for the active plugin ('' when none/unknown). */
function wpultra_atrans_default_lang(string $plugin): string {
    if ($plugin === 'polylang' && function_exists('pll_default_language')) {
        return wpultra_atrans_norm_lang((string) pll_default_language());
    }
    if ($plugin === 'wpml' && function_exists('apply_filters')) {
        return wpultra_atrans_norm_lang((string) apply_filters('wpml_default_language', ''));
    }
    return '';
}

/** Live: language code of a single post for the active plugin ('' when unknown). */
function wpultra_atrans_post_lang(int $post_id, string $plugin): string {
    if ($plugin === 'polylang' && function_exists('pll_get_post_language')) {
        return wpultra_atrans_norm_lang((string) pll_get_post_language($post_id));
    }
    if ($plugin === 'wpml' && function_exists('apply_filters')) {
        $info = apply_filters('wpml_post_language_details', null, $post_id);
        if (is_array($info) && isset($info['language_code'])) {
            return wpultra_atrans_norm_lang((string) $info['language_code']);
        }
    }
    return '';
}

/** Live: source post IDs (from the given candidates) that already have a $target_lang translation. */
function wpultra_atrans_already_translated(array $post_ids, string $target_lang, string $plugin): array {
    $target = wpultra_atrans_norm_lang($target_lang);
    $done = [];
    foreach ($post_ids as $id) {
        $id = (int) $id;
        $translations = [];
        if ($plugin === 'polylang' && function_exists('pll_get_post_translations')) {
            $translations = (array) pll_get_post_translations($id);
        } elseif ($plugin === 'wpml' && function_exists('apply_filters')) {
            $type = get_post_type($id) ?: 'post';
            $element_type = (string) apply_filters('wpml_element_type', $type);
            $trid = apply_filters('wpml_element_trid', null, $id, $element_type);
            if ($trid !== null) {
                $group = (array) apply_filters('wpml_get_element_translations', null, $trid, $element_type);
                foreach ($group as $code => $t) {
                    if (!empty($t->element_id)) { $translations[wpultra_atrans_norm_lang((string) $code)] = (int) $t->element_id; }
                }
            }
        }
        // Normalise Polylang keys too.
        $norm = [];
        foreach ($translations as $code => $tid) { $norm[wpultra_atrans_norm_lang((string) $code)] = (int) $tid; }
        if (isset($norm[$target]) && $norm[$target] > 0 && $norm[$target] !== $id) { $done[] = $id; }
    }
    return $done;
}

/**
 * Translate ONE source post into $target_lang and write the linked translation.
 *
 * Flow: duplicate-to-language (create+link shell) → obtain translated strings
 * (caller-supplied when given, else server-side AI when a key is set, else
 * leave the shell as a structure-only copy) → wpultra_i18n_fill() writes them.
 *
 * @param array $supplied {title?, content?, excerpt?} caller-supplied translations.
 *              When any is present, AI is skipped for that field.
 * @return array|WP_Error {source_id, translation_id, plugin, target_lang,
 *              method: 'caller'|'ai'|'structure-only', updated_fields, edit_link}
 */
function wpultra_atrans_translate_post(int $post_id, string $target_lang, array $supplied = [], bool $overwrite = false) {
    if (!function_exists('wpultra_i18n_duplicate_to_language')) {
        return wpultra_err('engine_missing', 'The multilingual engine (includes/i18n/engine.php) is not loaded.');
    }
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('not_found', "No post with id $post_id."); }

    // Decide method + gather the strings BEFORE mutating anything.
    $has_supplied = false;
    foreach (['title', 'content', 'excerpt'] as $k) {
        if (array_key_exists($k, $supplied) && trim((string) $supplied[$k]) !== '') { $has_supplied = true; }
    }

    $method = 'structure-only';
    $fields = [];

    if ($has_supplied) {
        $method = 'caller';
        foreach (['title', 'content', 'excerpt'] as $k) {
            if (array_key_exists($k, $supplied)) { $fields[$k] = (string) $supplied[$k]; }
        }
    } elseif (function_exists('wpultra_ai_has_key') && wpultra_ai_has_key()) {
        $translated = wpultra_atrans_ai_translate([
            'title'   => (string) $post->post_title,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
        ], $target_lang);
        if (is_wp_error($translated)) { return $translated; }
        $method = 'ai';
        $fields = $translated;
    }

    // 1) Create + link the translation shell (Elementor-safe meta + terms).
    $dup = wpultra_i18n_duplicate_to_language($post_id, $target_lang, $overwrite);
    if (is_wp_error($dup)) { return $dup; }
    $translation_id = (int) $dup['post_id'];

    // 2) Write the translated strings (when we have any).
    $updated_fields = [];
    if ($fields !== [] && function_exists('wpultra_i18n_fill')) {
        $fill_in = [];
        foreach (['title', 'content', 'excerpt'] as $k) {
            if (array_key_exists($k, $fields) && trim((string) $fields[$k]) !== '') { $fill_in[$k] = (string) $fields[$k]; }
        }
        if ($fill_in !== []) {
            $res = wpultra_i18n_fill($translation_id, $fill_in);
            if (is_wp_error($res)) { return $res; }
            $updated_fields = (array) ($res['updated_fields'] ?? array_keys($fill_in));
        }
    }

    return [
        'source_id'      => $post_id,
        'translation_id' => $translation_id,
        'plugin'         => (string) ($dup['plugin'] ?? ''),
        'target_lang'    => wpultra_atrans_norm_lang($target_lang),
        'method'         => $method,
        'updated_fields' => $updated_fields,
        'edit_link'      => (string) ($dup['edit_link'] ?? ''),
    ];
}

/**
 * Server-side AI translate of {title, content, excerpt} → same shape. Protects
 * shortcodes/blocks/URLs with placeholder tokens before the call and restores
 * them after, then round-trips through the pure prompt/parse helpers.
 *
 * @return array{title:string,content:string,excerpt:string}|WP_Error
 */
function wpultra_atrans_ai_translate(array $post, string $target_lang) {
    if (!function_exists('wpultra_ai_chat')) {
        return wpultra_err('ai_unavailable', 'The shared AI helper (includes/ai/setup.php) is not loaded.');
    }

    // Protect machine-readable markup per field.
    $protected = [];
    $tokens = [];
    foreach (['title', 'content', 'excerpt'] as $k) {
        $p = wpultra_atrans_protect_tokens((string) ($post[$k] ?? ''));
        $protected[$k] = $p['text'];
        $tokens[$k] = $p['tokens'];
    }

    $msgs = wpultra_atrans_prompt($protected, $target_lang);
    $reply = wpultra_ai_chat($msgs['system'], $msgs['user'], ['json' => true, 'temperature' => 0.2]);
    if (is_wp_error($reply)) { return $reply; }

    $parsed = wpultra_atrans_parse((string) $reply);
    if (is_string($parsed)) { return wpultra_err('ai_parse_failed', $parsed); }

    // Restore protected tokens in each translated field.
    return [
        'title'   => wpultra_atrans_restore_tokens($parsed['title'], $tokens['title']),
        'content' => wpultra_atrans_restore_tokens($parsed['content'], $tokens['content']),
        'excerpt' => wpultra_atrans_restore_tokens($parsed['excerpt'], $tokens['excerpt']),
    ];
}

// ---------------------------------------------------------------------------
// Async batch: option-backed queue + cron tick (one post per tick, reschedule).
// ---------------------------------------------------------------------------

/** Read the active job blob (or null). */
function wpultra_atrans_load_job(): ?array {
    if (!function_exists('get_option')) { return null; }
    $job = get_option(WPULTRA_ATRANS_OPTION, null);
    return is_array($job) ? $job : null;
}

/** Persist the job blob. */
function wpultra_atrans_save_job(array $job): void {
    if (function_exists('update_option')) { update_option(WPULTRA_ATRANS_OPTION, $job, false); }
}

/** Schedule + loopback-kick the tick processor. */
function wpultra_atrans_kick(): void {
    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
        if (!wp_next_scheduled(WPULTRA_ATRANS_TICK_HOOK)) {
            wp_schedule_single_event(time(), WPULTRA_ATRANS_TICK_HOOK);
        }
    }
    if (function_exists('spawn_cron')) { spawn_cron(); }
}

/**
 * Start a batch job: persist the blob and kick the cron. Returns the shaped job.
 *
 * @param array<int,int> $queue
 */
function wpultra_atrans_start_job(string $target_lang, array $queue, string $source): array {
    $job = wpultra_atrans_new_job($target_lang, $queue, $source);
    if ($job['queue'] === []) {
        $job['status'] = 'done';
        $job['message'] = 'Nothing to translate — every candidate already has a translation.';
        wpultra_atrans_save_job($job);
        return wpultra_atrans_shape_job($job);
    }
    $job['status'] = 'queued';
    wpultra_atrans_save_job($job);
    wpultra_atrans_kick();
    return wpultra_atrans_shape_job($job);
}

/** Request cancellation of the active job. */
function wpultra_atrans_cancel_job(): array {
    $job = wpultra_atrans_load_job();
    if ($job === null) { return ['status' => 'none', 'message' => 'No autotranslate job to cancel.']; }
    if (!wpultra_atrans_is_active((string) ($job['status'] ?? ''))) {
        return wpultra_atrans_shape_job($job);
    }
    $job['cancel'] = true;
    wpultra_atrans_save_job($job);
    return wpultra_atrans_shape_job($job);
}

/**
 * Cron tick: translate ONE post from the active job, advance the cursor, then
 * reschedule while work remains. Bounded (one post/tick) so it never times out.
 */
function wpultra_atrans_tick(): void {
    $job = wpultra_atrans_load_job();
    if ($job === null) { return; }
    $status = (string) ($job['status'] ?? 'queued');
    if (!wpultra_atrans_is_active($status)) { return; }

    // Honour a cancellation request before doing work.
    if (!empty($job['cancel'])) {
        $job['status'] = 'cancelled';
        $job['message'] = 'Cancelled.';
        wpultra_atrans_save_job($job);
        return;
    }

    $queue  = array_values((array) ($job['queue'] ?? []));
    $cursor = (int) ($job['cursor'] ?? 0);

    if ($cursor >= count($queue)) {
        $job['status'] = 'done';
        if ($job['message'] === '') { $job['message'] = 'Batch complete.'; }
        wpultra_atrans_save_job($job);
        return;
    }

    $job['status'] = 'running';
    $post_id = (int) $queue[$cursor];
    $source  = (string) ($job['source'] ?? 'caller');
    $target  = (string) ($job['target_lang'] ?? '');

    // AI source → server-side translate; caller source → structure-only shell
    // the caller fills later via translate-post/translation-set-content.
    $supplied = [];
    $res = wpultra_atrans_translate_post($post_id, $target, $supplied, false);

    if (is_wp_error($res)) {
        $job['failed'][] = ['id' => $post_id, 'error' => $res->get_error_message()];
    } else {
        $job['done'][] = ['source_id' => $post_id, 'translation_id' => (int) $res['translation_id'], 'method' => (string) $res['method']];
    }

    $job['cursor'] = $cursor + 1;
    if ($job['cursor'] >= count($queue)) {
        $job['status'] = 'done';
        $job['message'] = 'Batch complete: ' . count($job['done']) . ' translated, ' . count($job['failed']) . ' failed.';
        wpultra_atrans_save_job($job);
        return;
    }

    wpultra_atrans_save_job($job);
    // More work remains — reschedule + kick.
    if (function_exists('wp_schedule_single_event')) {
        wp_schedule_single_event(time() + 1, WPULTRA_ATRANS_TICK_HOOK);
    }
    if (function_exists('spawn_cron')) { spawn_cron(); }
}

/**
 * Register the cron tick hook. Cheap: just an add_action. Called by the
 * controller (bootstrap-mcp) — cron fires outside the REST/abilities loop so
 * the hook must be always-on.
 */
function wpultra_atrans_boot(): void {
    if (function_exists('add_action')) {
        add_action(WPULTRA_ATRANS_TICK_HOOK, 'wpultra_atrans_tick');
    }
}
