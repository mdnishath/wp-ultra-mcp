<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/*
 * AI content pipeline (Roadmap-2 S1).
 *
 * The AI CLIENT does all language generation. These functions give it STRUCTURE and
 * orchestration only — turning a keyword into an outline skeleton, a content spec into
 * valid Gutenberg markup, and a finished spec into a draft post + SEO meta + a
 * "next steps" checklist. NOTHING here calls an LLM server-side.
 */

// ---------------------------------------------------------------------------
// PURE helpers (unit-tested; no WordPress runtime required)
// ---------------------------------------------------------------------------

if (!function_exists('wpultra_pipeline_strlen')) {
    /** PURE. Codepoint-safe length; falls back to a regex count when mbstring is absent. */
    function wpultra_pipeline_strlen(string $s): int {
        return function_exists('mb_strlen') ? (int) mb_strlen($s) : (int) preg_match_all('/./us', $s, $__m);
    }
}

if (!function_exists('wpultra_pipeline_title_case')) {
    /** PURE. Title-case a keyword phrase, keeping short filler words lower unless first. */
    function wpultra_pipeline_title_case(string $s): string {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '') { return ''; }
        $small = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'nor', 'of', 'on', 'or', 'the', 'to', 'vs', 'via', 'with'];
        $words = explode(' ', $s);
        $out = [];
        foreach ($words as $i => $w) {
            $lower = function_exists('mb_strtolower') ? mb_strtolower($w, 'UTF-8') : strtolower($w);
            if ($i > 0 && in_array($lower, $small, true)) { $out[] = $lower; continue; }
            // Uppercase first codepoint only, keep the tail as authored.
            if (function_exists('mb_substr')) {
                $first = mb_substr($w, 0, 1, 'UTF-8');
                $rest = mb_substr($w, 1, null, 'UTF-8');
                $out[] = (function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first)) . $rest;
            } else {
                $out[] = ucfirst($w);
            }
        }
        return implode(' ', $out);
    }
}

/**
 * PURE. Deterministic outline skeleton from a keyword. The AI fills in the actual copy.
 * Sections scale between a floor of 3 and a ceiling of 8; the spine is always
 * Introduction … Conclusion with keyword-shaped H2 hints in between.
 */
function wpultra_pipeline_outline_scaffold(string $keyword, int $sections): array {
    $kw = trim(preg_replace('/\s+/u', ' ', $keyword));
    $tc = wpultra_pipeline_title_case($kw);
    if ($kw === '') { $tc = 'Untitled'; }
    $n = max(3, min(8, $sections));

    // Candidate middle sections in priority order (Introduction/Conclusion are fixed ends).
    $middle_pool = [
        ['heading' => "What Is $tc?",            'hint' => "Define $kw plainly and why it matters."],
        ['heading' => "Benefits of $tc",          'hint' => "List the concrete upsides / outcomes."],
        ['heading' => "How to Get Started With $tc", 'hint' => "Give an actionable step-by-step."],
        ['heading' => "Best Practices for $tc",   'hint' => "Share expert tips and common pitfalls."],
        ['heading' => "$tc Examples",             'hint' => "Show real, illustrative examples."],
        ['heading' => "Frequently Asked Questions", 'hint' => "Answer 2–4 common questions about $kw."],
    ];

    $middle_count = $n - 2; // reserve Introduction + Conclusion
    $middle = array_slice($middle_pool, 0, $middle_count);
    // If more sections requested than the pool covers, pad with numbered deep-dive headings.
    for ($i = count($middle); $i < $middle_count; $i++) {
        $middle[] = ['heading' => "$tc: Deep Dive " . ($i + 1), 'hint' => "Expand on a further aspect of $kw."];
    }

    $out_sections = [];
    $out_sections[] = ['heading' => 'Introduction', 'hint' => "Hook the reader and introduce $kw."];
    foreach ($middle as $m) { $out_sections[] = $m; }
    $out_sections[] = ['heading' => 'Conclusion', 'hint' => "Summarise and give a clear call to action."];

    return [
        'title_suggestions' => [
            "$tc: A Complete Guide",
            "The Ultimate Guide to $tc",
            "$tc Explained: Everything You Need to Know",
        ],
        'sections'  => $out_sections,
        'meta_hint' => "Write a 120–160 char meta description that includes \"$kw\" and a benefit.",
    ];
}

/**
 * PURE. Turn a simple ordered content spec into valid, serialized Gutenberg block markup.
 * Spec items: {type: heading|paragraph|list|image, level?, text?, items?, image_id?}.
 * Text is HTML-escaped; unknown types are skipped. Returns a markup string.
 */
function wpultra_pipeline_build_gutenberg(array $blocks): string {
    $esc = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $out = [];
    foreach ($blocks as $b) {
        if (!is_array($b)) { continue; }
        $type = (string) ($b['type'] ?? '');
        switch ($type) {
            case 'heading':
                $level = (int) ($b['level'] ?? 2);
                if ($level < 1 || $level > 6) { $level = 2; }
                $text = $esc((string) ($b['text'] ?? ''));
                // core/heading defaults to H2; only H1/H3-H6 carry an explicit level attr.
                $attrs = $level === 2 ? '' : ' {"level":' . $level . '}';
                $out[] = "<!-- wp:heading$attrs -->\n<h$level>$text</h$level>\n<!-- /wp:heading -->";
                break;
            case 'paragraph':
                $text = $esc((string) ($b['text'] ?? ''));
                $out[] = "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
                break;
            case 'list':
                $items = is_array($b['items'] ?? null) ? $b['items'] : [];
                $ordered = !empty($b['ordered']);
                $tag = $ordered ? 'ol' : 'ul';
                $attrs = $ordered ? ' {"ordered":true}' : '';
                $li = [];
                foreach ($items as $it) {
                    $li[] = "<!-- wp:list-item -->\n<li>" . $esc((string) $it) . "</li>\n<!-- /wp:list-item -->";
                }
                $inner = implode("\n", $li);
                $out[] = "<!-- wp:list$attrs -->\n<$tag>\n$inner\n</$tag>\n<!-- /wp:list -->";
                break;
            case 'image':
                $id = (int) ($b['image_id'] ?? 0);
                $alt = $esc((string) ($b['text'] ?? ''));
                if ($id > 0) {
                    $attrs = ' {"id":' . $id . ',"sizeSlug":"large"}';
                    $img = '<img src="" alt="' . $alt . '" class="wp-image-' . $id . '"/>';
                } else {
                    $attrs = '';
                    $img = '<img src="" alt="' . $alt . '"/>';
                }
                $out[] = "<!-- wp:image$attrs -->\n<figure class=\"wp-block-image\">$img</figure>\n<!-- /wp:image -->";
                break;
            default:
                // Unknown block type — skip rather than emit invalid markup.
                break;
        }
    }
    return implode("\n\n", $out);
}

/**
 * PURE. Codepoint-safe readability stats. Sentence split on . ! ? and their common
 * non-Latin equivalents (Bengali danda ।, CJK 。！？). Words are Unicode \w runs, so
 * Bengali/CJK text is counted, not dropped.
 */
function wpultra_pipeline_readability(string $text): array {
    // Strip HTML/block markup so stats reflect prose, not tags.
    $plain = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/<[^>]*>|<!--.*?-->/us', ' ', $text)));
    $words = 0;
    // Include \p{M} (combining marks) so Bengali/Devanagari matras stay attached to their
    // base letter instead of splitting one word into several tokens.
    if ($plain !== '' && preg_match_all('/[\p{L}\p{N}\p{M}]+/u', $plain, $wm)) { $words = count($wm[0]); }
    $sentences = 0;
    if ($plain !== '' && preg_match_all('/[.!?।。！？]+/u', $plain, $sm)) { $sentences = count($sm[0]); }
    // A block of prose with no terminal punctuation is still one sentence.
    if ($sentences === 0 && $words > 0) { $sentences = 1; }
    $avg = $sentences > 0 ? round($words / $sentences, 1) : 0.0;
    // ~200 wpm reading speed; always at least 1 minute for non-empty text.
    $reading = $words > 0 ? max(1, (int) ceil($words / 200)) : 0;
    return [
        'words'            => $words,
        'sentences'        => $sentences,
        'avg_sentence_len' => $avg,
        'reading_time_min' => $reading,
    ];
}

/**
 * PURE. Validate a create-draft input array. Returns true, or a human-readable error string.
 * Keeps this cheap so the ability layer can reject bad specs before touching WordPress.
 */
function wpultra_pipeline_validate_draft(array $in) {
    $title = (string) ($in['title'] ?? '');
    if (trim($title) === '') { return 'title is required.'; }
    if (!isset($in['blocks']) || !is_array($in['blocks']) || $in['blocks'] === []) {
        return 'blocks must be a non-empty array.';
    }
    $allowed = ['heading', 'paragraph', 'list', 'image'];
    foreach ($in['blocks'] as $i => $b) {
        if (!is_array($b)) { return "blocks[$i] must be an object."; }
        $type = (string) ($b['type'] ?? '');
        if (!in_array($type, $allowed, true)) {
            return "blocks[$i].type '" . $type . "' is invalid (allowed: " . implode(', ', $allowed) . ').';
        }
        if ($type === 'heading') {
            if (trim((string) ($b['text'] ?? '')) === '') { return "blocks[$i] (heading) requires text."; }
            $lvl = (int) ($b['level'] ?? 2);
            if ($lvl < 1 || $lvl > 6) { return "blocks[$i].level must be 1–6."; }
        } elseif ($type === 'paragraph') {
            if (trim((string) ($b['text'] ?? '')) === '') { return "blocks[$i] (paragraph) requires text."; }
        } elseif ($type === 'list') {
            if (!isset($b['items']) || !is_array($b['items']) || $b['items'] === []) {
                return "blocks[$i] (list) requires a non-empty items array.";
            }
        } elseif ($type === 'image') {
            if ((int) ($b['image_id'] ?? 0) <= 0) { return "blocks[$i] (image) requires a positive image_id."; }
        }
    }
    $status = (string) ($in['status'] ?? 'draft');
    if (!in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
        return "status '" . $status . "' is invalid.";
    }
    return true;
}

// ---------------------------------------------------------------------------
// WordPress-facing orchestration (runtime only)
// ---------------------------------------------------------------------------

/** PURE. The "next steps" checklist returned after a draft is created. */
function wpultra_pipeline_next_steps(int $post_id): array {
    return [
        "Add a featured image with wpultra/media-generate {post_id: $post_id, set_featured: true, prompt|url|data_base64}.",
        "Add internal links with wpultra/seo-insert-internal-link (see wpultra/content-plan for suggested targets).",
        "Review readability; tighten any section over ~20 words/sentence.",
        "Publish with wpultra/update-post {post_id: $post_id, status: 'publish'} when ready.",
    ];
}

/**
 * Assemble a content spec into a draft post (+ optional SEO meta) and return the post_id,
 * readability stats, and a next-steps checklist. WordPress runtime required.
 */
function wpultra_pipeline_create_draft(array $in) {
    $valid = wpultra_pipeline_validate_draft($in);
    if ($valid !== true) { return wpultra_err('invalid_draft', (string) $valid); }

    $markup = wpultra_pipeline_build_gutenberg((array) $in['blocks']);
    $status = (string) ($in['status'] ?? 'draft');

    $postarr = [
        'post_title'   => (string) $in['title'],
        'post_content' => $markup,
        'post_status'  => $status,
        'post_type'    => 'post',
    ];
    if (!empty($in['excerpt'])) { $postarr['post_excerpt'] = (string) $in['excerpt']; }
    // wp_insert_post() unslashes; slash first so block-comment JSON survives intact.
    $id = wp_insert_post(wp_slash($postarr), true);
    if (is_wp_error($id)) { return $id; }
    $id = (int) $id;

    // Terms
    if (!empty($in['category_ids']) && is_array($in['category_ids'])) {
        wp_set_post_terms($id, array_map('intval', $in['category_ids']), 'category');
    }
    if (!empty($in['tag_names']) && is_array($in['tag_names'])) {
        wp_set_post_terms($id, array_map('strval', $in['tag_names']), 'post_tag');
    }

    // SEO meta — prefer the plugin's validated driver; fall back to native meta.
    $seo_warnings = [];
    $seo = [];
    if (!empty($in['focus_keyword'])) { $seo['focus_keyword'] = (string) $in['focus_keyword']; }
    if (!empty($in['meta_description'])) { $seo['description'] = (string) $in['meta_description']; }
    if (!empty($in['seo_title'])) { $seo['title'] = (string) $in['seo_title']; }
    if ($seo !== []) {
        if (function_exists('wpultra_seo_set_meta')) {
            $res = wpultra_seo_set_meta($id, $seo);
            if (!is_wp_error($res) && !empty($res['warnings'])) { $seo_warnings = $res['warnings']; }
        } elseif (function_exists('update_post_meta')) {
            $native = [
                'focus_keyword' => '_wpultra_seo_focuskw',
                'description'   => '_wpultra_seo_desc',
                'title'         => '_wpultra_seo_title',
            ];
            foreach ($seo as $k => $v) {
                if (isset($native[$k])) { update_post_meta($id, $native[$k], wp_slash((string) $v)); }
            }
        }
    }

    return wpultra_ok([
        'post_id'      => $id,
        'status'       => $status,
        'permalink'    => function_exists('get_permalink') ? (string) get_permalink($id) : '',
        'edit_url'     => function_exists('get_edit_post_link') ? (string) get_edit_post_link($id, 'raw') : '',
        'readability'  => wpultra_pipeline_readability($markup),
        'seo_warnings' => $seo_warnings,
        'next_steps'   => wpultra_pipeline_next_steps($id),
    ]);
}
