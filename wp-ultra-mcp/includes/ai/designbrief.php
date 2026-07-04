<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * F6 — AI design-from-brief engine.
 *
 * Describe a whole site in one natural-language brief → the calling AI (or a
 * server-side OpenAI key) turns it into a structured SITE PLAN, which this
 * engine validates and then BUILDS: WordPress pages (Gutenberg block content),
 * a nav menu, and theme color/font tokens (block-theme global styles).
 *
 * MODEL: the calling AI (Claude via MCP) is the primary designer. The default
 * flow is that Claude writes the site plan itself and passes it as `plan` —
 * that path never needs an API key. `brief` mode (server generates the plan)
 * is the optional fallback that requires a configured OpenAI key.
 *
 * SAFETY: everything is plan-first + dry-run. The `build` step defaults to
 * dry_run (returns the plan-of-record — what WOULD be created) and only writes
 * when explicitly given dry_run:false + confirm:true.
 *
 * SITE PLAN shape (the pure contract):
 *   {
 *     site:   {name, tagline?},
 *     tokens: {colors: [{slug, name, hex}], fonts: [{slug, name, family}]},
 *     pages:  [{slug, title, sections: [
 *                {type: hero|features|cta|text|contact,
 *                 heading?, subheading?, body?,
 *                 items?: [{title, text}],
 *                 button?: {label, url}}
 *              ]}],
 *     menu:   [{title, page_slug|url}]
 *   }
 *
 * PURE functions are prefixed wpultra_dfb_ and take/return plain values only
 * (no WP calls) so the whole plan → block-markup pipeline is unit-testable in
 * the harness. WP wrappers (build) come after and are guarded.
 */

/* ============================================================================
 * PURE HELPERS
 * ========================================================================== */

/** Valid section types. Pure. */
function wpultra_dfb_section_types(): array {
    return ['hero', 'features', 'cta', 'text', 'contact'];
}

/** Sanitize an arbitrary string to a lowercase a-z0-9- slug. Pure. */
function wpultra_dfb_slug(string $s): string {
    $s = strtolower(trim($s));
    // Transliterate a few common accented chars cheaply (pure, no intl).
    $s = strtr($s, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ]);
    // Drop apostrophes/quotes entirely (mirrors WP sanitize_title: "Joe's" → "joes")
    // rather than turning them into a separator ("joe-s").
    $s = str_replace(['\'', '’', '`', '"'], '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    return $s;
}

/** Pure HTML-escape (no WP dependency) — for embedding text in block markup. */
function wpultra_dfb_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Pure escape for a URL rendered into an href. Strips control/whitespace, escapes. */
function wpultra_dfb_esc_url(string $url): string {
    $url = trim($url);
    // Drop anything that could break out of the attribute or inject a scheme newline.
    $url = preg_replace('/[\x00-\x1f\x7f"\'<>` ]+/', '', $url);
    return htmlspecialchars((string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** True when $hex is a valid #RGB or #RRGGBB color. Pure. */
function wpultra_dfb_is_hex(string $hex): bool {
    return (bool) preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($hex));
}

/**
 * PURE. Validate a site plan against the contract. Returns true, or an error
 * STRING describing the first problem found.
 *
 * @param array $plan
 * @return true|string
 */
function wpultra_dfb_validate_plan(array $plan) {
    // site.name
    $site = $plan['site'] ?? null;
    if (!is_array($site) || trim((string) ($site['name'] ?? '')) === '') {
        return 'site.name is required (a non-empty string).';
    }

    // tokens (optional, but when present each entry must be well-formed)
    $tokens = $plan['tokens'] ?? [];
    if ($tokens !== null && !is_array($tokens)) {
        return 'tokens must be an object when present.';
    }
    $colors = is_array($tokens['colors'] ?? null) ? $tokens['colors'] : [];
    foreach ($colors as $i => $c) {
        if (!is_array($c)) { return "tokens.colors[$i] must be an object."; }
        if (trim((string) ($c['slug'] ?? '')) === '') { return "tokens.colors[$i].slug is required."; }
        if (!wpultra_dfb_is_hex((string) ($c['hex'] ?? ''))) {
            return "tokens.colors[$i].hex must be a #RGB or #RRGGBB color (got '" . (string) ($c['hex'] ?? '') . "').";
        }
    }
    $fonts = is_array($tokens['fonts'] ?? null) ? $tokens['fonts'] : [];
    foreach ($fonts as $i => $f) {
        if (!is_array($f)) { return "tokens.fonts[$i] must be an object."; }
        if (trim((string) ($f['slug'] ?? '')) === '') { return "tokens.fonts[$i].slug is required."; }
        if (trim((string) ($f['family'] ?? '')) === '') { return "tokens.fonts[$i].family is required."; }
    }

    // pages — at least one, each with slug + title + valid sections
    $pages = $plan['pages'] ?? null;
    if (!is_array($pages) || $pages === []) {
        return 'pages must be a non-empty array.';
    }
    $valid_types = wpultra_dfb_section_types();
    $page_slugs = [];
    foreach ($pages as $pi => $page) {
        if (!is_array($page)) { return "pages[$pi] must be an object."; }
        $slug = trim((string) ($page['slug'] ?? ''));
        $title = trim((string) ($page['title'] ?? ''));
        if ($slug === '') { return "pages[$pi].slug is required."; }
        if ($title === '') { return "pages[$pi].title is required."; }
        $page_slugs[$slug] = true;
        $sections = $page['sections'] ?? [];
        if (!is_array($sections)) { return "pages[$pi].sections must be an array."; }
        foreach ($sections as $si => $sec) {
            if (!is_array($sec)) { return "pages[$pi].sections[$si] must be an object."; }
            $type = (string) ($sec['type'] ?? '');
            if (!in_array($type, $valid_types, true)) {
                return "pages[$pi].sections[$si].type '$type' is invalid (allowed: " . implode('|', $valid_types) . ').';
            }
        }
    }

    // menu (optional) — each item references a known page slug OR an absolute url
    $menu = $plan['menu'] ?? [];
    if ($menu !== null && !is_array($menu)) {
        return 'menu must be an array when present.';
    }
    foreach ((array) $menu as $mi => $item) {
        if (!is_array($item)) { return "menu[$mi] must be an object."; }
        if (trim((string) ($item['title'] ?? '')) === '') { return "menu[$mi].title is required."; }
        $page_slug = trim((string) ($item['page_slug'] ?? ''));
        $url = trim((string) ($item['url'] ?? ''));
        if ($page_slug === '' && $url === '') {
            return "menu[$mi] must reference a page_slug or an absolute url.";
        }
        if ($page_slug !== '' && !isset($page_slugs[$page_slug])) {
            return "menu[$mi].page_slug '$page_slug' does not match any page in the plan.";
        }
        if ($page_slug === '' && $url !== '' && !preg_match('#^https?://#i', $url)) {
            return "menu[$mi].url must be an absolute http(s) url (or use page_slug instead).";
        }
    }

    return true;
}

/**
 * PURE. Build the {system, user} prompt pair asking wpultra_ai_chat for a JSON
 * site plan in EXACTLY the contract shape from a natural-language brief.
 *
 * @return array{system:string,user:string}
 */
function wpultra_dfb_plan_prompt(string $brief): array {
    $types = implode('|', wpultra_dfb_section_types());
    $system =
        "You are a website architect. Turn the user's brief into a COMPLETE website plan as a single JSON object. "
        . "Output ONLY valid JSON — no markdown, no prose, no code fences.\n\n"
        . "The JSON MUST have this exact shape:\n"
        . "{\n"
        . "  \"site\":   {\"name\": string, \"tagline\": string},\n"
        . "  \"tokens\": {\"colors\": [{\"slug\": string, \"name\": string, \"hex\": \"#rrggbb\"}], "
        . "\"fonts\": [{\"slug\": string, \"name\": string, \"family\": string}]},\n"
        . "  \"pages\":  [{\"slug\": string, \"title\": string, \"sections\": ["
        . "{\"type\": \"$types\", \"heading\": string, \"subheading\": string, \"body\": string, "
        . "\"items\": [{\"title\": string, \"text\": string}], \"button\": {\"label\": string, \"url\": string}}]}],\n"
        . "  \"menu\":   [{\"title\": string, \"page_slug\": string}]\n"
        . "}\n\n"
        . "RULES:\n"
        . "- Section \"type\" MUST be one of: $types. Do not invent other types.\n"
        . "- LIMITS: at most 6 pages; at most 6 sections per page; at most 6 colors and 4 fonts.\n"
        . "- Every page needs a url-safe lowercase-hyphenated \"slug\" and a human \"title\".\n"
        . "- A home page should come first and use slug \"home\".\n"
        . "- Colors MUST be #rrggbb hex. Only reference page_slug values that exist in \"pages\".\n"
        . "- hero: big heading + subheading + a button. features: heading + items[] (title/text). "
        . "cta: heading + button. text: heading + body. contact: heading + body (contact details).";
    $user = "Brief:\n" . $brief . "\n\nReturn the JSON site plan now.";
    return ['system' => $system, 'user' => $user];
}

/**
 * PURE. Parse an AI JSON response into a plan array. Tolerates a ```json fenced
 * block and leading/trailing prose. Returns the decoded array, or an error
 * STRING when nothing parseable is found.
 *
 * @return array|string
 */
function wpultra_dfb_parse_plan(string $ai_json) {
    $raw = trim($ai_json);
    if ($raw === '') { return 'AI returned an empty response.'; }

    // Strip a ```json ... ``` (or plain ```) fence if present.
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $raw, $m)) {
        $raw = trim($m[1]);
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) { return $decoded; }

    // Fall back to the first balanced-looking {...} span.
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $slice = substr($raw, $start, $end - $start + 1);
        $decoded = json_decode($slice, true);
        if (is_array($decoded)) { return $decoded; }
    }

    return 'AI response was not valid JSON.';
}

/* ============================================================================
 * PURE — Gutenberg block markup emitters (core blocks only)
 * ========================================================================== */

/** Heading block markup. Pure. $level 1-6. */
function wpultra_dfb_block_heading(string $text, int $level = 2): string {
    if ($text === '') { return ''; }
    $level = max(1, min(6, $level));
    $t = wpultra_dfb_esc($text);
    $attr = $level === 2 ? '' : ' {"level":' . $level . '}';
    return "<!-- wp:heading$attr -->\n<h$level class=\"wp-block-heading\">$t</h$level>\n<!-- /wp:heading -->";
}

/** Paragraph block markup. Pure. */
function wpultra_dfb_block_paragraph(string $text): string {
    if ($text === '') { return ''; }
    $t = wpultra_dfb_esc($text);
    return "<!-- wp:paragraph -->\n<p>$t</p>\n<!-- /wp:paragraph -->";
}

/** A single buttons/button block from a {label, url} pair. Pure. */
function wpultra_dfb_block_button(array $button): string {
    $label = trim((string) ($button['label'] ?? ''));
    if ($label === '') { return ''; }
    $url = wpultra_dfb_esc_url((string) ($button['url'] ?? '#'));
    if ($url === '') { $url = '#'; }
    $l = wpultra_dfb_esc($label);
    return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">\n"
        . "<!-- wp:button -->\n<div class=\"wp-block-button\">"
        . "<a class=\"wp-block-button__link wp-element-button\" href=\"$url\">$l</a></div>\n"
        . "<!-- /wp:button -->\n</div>\n<!-- /wp:buttons -->";
}

/**
 * A feature grid as a wp:columns of wp:column, each holding a heading + paragraph.
 * $items = [{title, text}, ...]. Pure.
 */
function wpultra_dfb_block_feature_grid(array $items): string {
    $cols = [];
    foreach ($items as $item) {
        if (!is_array($item)) { continue; }
        $title = trim((string) ($item['title'] ?? ''));
        $text = trim((string) ($item['text'] ?? ''));
        if ($title === '' && $text === '') { continue; }
        $inner = '';
        if ($title !== '') { $inner .= wpultra_dfb_block_heading($title, 3) . "\n"; }
        if ($text !== '') { $inner .= wpultra_dfb_block_paragraph($text) . "\n"; }
        $cols[] = "<!-- wp:column -->\n<div class=\"wp-block-column\">\n" . rtrim($inner) . "\n</div>\n<!-- /wp:column -->";
    }
    if ($cols === []) { return ''; }
    return "<!-- wp:columns -->\n<div class=\"wp-block-columns\">\n" . implode("\n", $cols) . "\n</div>\n<!-- /wp:columns -->";
}

/**
 * PURE. Render ONE section to Gutenberg block markup, wrapped in a wp:group.
 * Core blocks only. All text is escaped.
 */
function wpultra_dfb_section_blocks(array $section): string {
    $type = (string) ($section['type'] ?? 'text');
    $heading = trim((string) ($section['heading'] ?? ''));
    $subheading = trim((string) ($section['subheading'] ?? ''));
    $body = trim((string) ($section['body'] ?? ''));
    $items = is_array($section['items'] ?? null) ? $section['items'] : [];
    $button = is_array($section['button'] ?? null) ? $section['button'] : [];

    $parts = [];
    switch ($type) {
        case 'hero':
            if ($heading !== '') { $parts[] = wpultra_dfb_block_heading($heading, 1); }
            if ($subheading !== '') { $parts[] = wpultra_dfb_block_paragraph($subheading); }
            if ($body !== '') { $parts[] = wpultra_dfb_block_paragraph($body); }
            if ($button !== []) { $parts[] = wpultra_dfb_block_button($button); }
            break;

        case 'features':
            if ($heading !== '') { $parts[] = wpultra_dfb_block_heading($heading, 2); }
            if ($subheading !== '') { $parts[] = wpultra_dfb_block_paragraph($subheading); }
            $grid = wpultra_dfb_block_feature_grid($items);
            if ($grid !== '') { $parts[] = $grid; }
            break;

        case 'cta':
            if ($heading !== '') { $parts[] = wpultra_dfb_block_heading($heading, 2); }
            if ($subheading !== '') { $parts[] = wpultra_dfb_block_paragraph($subheading); }
            if ($body !== '') { $parts[] = wpultra_dfb_block_paragraph($body); }
            if ($button !== []) { $parts[] = wpultra_dfb_block_button($button); }
            break;

        case 'contact':
            if ($heading !== '') { $parts[] = wpultra_dfb_block_heading($heading, 2); }
            if ($subheading !== '') { $parts[] = wpultra_dfb_block_paragraph($subheading); }
            if ($body !== '') { $parts[] = wpultra_dfb_block_paragraph($body); }
            if ($button !== []) { $parts[] = wpultra_dfb_block_button($button); }
            break;

        case 'text':
        default:
            if ($heading !== '') { $parts[] = wpultra_dfb_block_heading($heading, 2); }
            if ($subheading !== '') { $parts[] = wpultra_dfb_block_paragraph($subheading); }
            if ($body !== '') { $parts[] = wpultra_dfb_block_paragraph($body); }
            break;
    }

    $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
    if ($parts === []) {
        // A section with no usable content still produces a valid (empty) group.
        return "<!-- wp:group {\"className\":\"wpultra-dfb-$type\"} -->\n"
            . "<div class=\"wp-block-group wpultra-dfb-$type\"></div>\n<!-- /wp:group -->";
    }
    $cls = 'wpultra-dfb-' . wpultra_dfb_slug($type);
    $inner = implode("\n\n", $parts);
    return "<!-- wp:group {\"className\":\"$cls\"} -->\n"
        . "<div class=\"wp-block-group $cls\">\n" . $inner . "\n</div>\n<!-- /wp:group -->";
}

/** PURE. Concatenate a page's sections into full block markup. */
function wpultra_dfb_page_content(array $page): string {
    $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
    $blocks = [];
    foreach ($sections as $section) {
        if (!is_array($section)) { continue; }
        $markup = wpultra_dfb_section_blocks($section);
        if ($markup !== '') { $blocks[] = $markup; }
    }
    return implode("\n\n", $blocks);
}

/* ============================================================================
 * PURE — token → theme.json settings translation
 * ========================================================================== */

/**
 * PURE. Translate plan tokens into a theme.json `settings` fragment
 * ({color:{palette:[...]}, typography:{fontFamilies:[...]}}). Ready to feed
 * wpultra_fse_theme_json_set(). Returns [] when there are no tokens.
 */
function wpultra_dfb_tokens_to_settings(array $tokens): array {
    $settings = [];
    $palette = [];
    foreach (is_array($tokens['colors'] ?? null) ? $tokens['colors'] : [] as $c) {
        if (!is_array($c)) { continue; }
        $slug = wpultra_dfb_slug((string) ($c['slug'] ?? ''));
        $hex = (string) ($c['hex'] ?? '');
        if ($slug === '' || !wpultra_dfb_is_hex($hex)) { continue; }
        $palette[] = [
            'slug'  => $slug,
            'name'  => (string) ($c['name'] ?? $slug),
            'color' => $hex,
        ];
    }
    if ($palette !== []) { $settings['color'] = ['palette' => $palette]; }

    $families = [];
    foreach (is_array($tokens['fonts'] ?? null) ? $tokens['fonts'] : [] as $f) {
        if (!is_array($f)) { continue; }
        $slug = wpultra_dfb_slug((string) ($f['slug'] ?? ''));
        $family = trim((string) ($f['family'] ?? ''));
        if ($slug === '' || $family === '') { continue; }
        $families[] = [
            'slug'       => $slug,
            'name'       => (string) ($f['name'] ?? $slug),
            'fontFamily' => $family,
        ];
    }
    if ($families !== []) { $settings['typography'] = ['fontFamilies' => $families]; }

    return $settings;
}

/* ============================================================================
 * WP WRAPPERS — build the site (guarded; only run inside WordPress)
 * ========================================================================== */

/**
 * Generate a site plan from a brief via the server-side AI key. Returns the
 * plan array or a WP_Error. Requires includes/ai/setup.php + a configured key.
 */
function wpultra_dfb_generate_plan(string $brief) {
    if (!function_exists('wpultra_ai_chat')) {
        return wpultra_err('ai_helper_missing', 'The shared AI helper (includes/ai/setup.php) is not loaded.');
    }
    if (!function_exists('wpultra_ai_has_key') || !wpultra_ai_has_key()) {
        return wpultra_err('no_api_key', 'brief-mode needs a server-side OpenAI key. Either set one, or supply a structured `plan` yourself (the calling AI can write it directly).');
    }
    $prompt = wpultra_dfb_plan_prompt($brief);
    $resp = wpultra_ai_chat($prompt['system'], $prompt['user'], ['json' => true, 'temperature' => 0.4, 'max_tokens' => 3000]);
    if (is_wp_error($resp)) { return $resp; }
    $plan = wpultra_dfb_parse_plan((string) $resp);
    if (is_string($plan)) { return wpultra_err('plan_parse_failed', $plan); }
    return $plan;
}

/**
 * Resolve a plan from either a caller-supplied structured `plan` or a `brief`.
 * Validates it. Returns the validated plan array, or a WP_Error.
 */
function wpultra_dfb_resolve_plan(?array $plan, string $brief) {
    if (is_array($plan) && $plan !== []) {
        $valid = wpultra_dfb_validate_plan($plan);
        if ($valid !== true) { return wpultra_err('invalid_plan', (string) $valid); }
        return $plan;
    }
    if (trim($brief) === '') {
        return wpultra_err('missing_input', 'Provide either a structured `plan` or a natural-language `brief`.');
    }
    $generated = wpultra_dfb_generate_plan($brief);
    if (is_wp_error($generated)) { return $generated; }
    $valid = wpultra_dfb_validate_plan($generated);
    if ($valid !== true) { return wpultra_err('invalid_generated_plan', 'The AI-generated plan was malformed: ' . (string) $valid); }
    return $generated;
}

/**
 * BUILD the site from a validated plan.
 *
 * dry_run:true  → computes everything, writes NOTHING, returns the plan-of-record
 *                 (each page marked action:would-create/would-update).
 * dry_run:false → creates/updates pages (matched by slug), builds the nav menu +
 *                 items, and writes theme tokens into block-theme global styles.
 *
 * @return array|WP_Error {pages, menu, tokens_applied, dry_run}
 */
function wpultra_dfb_build(array $plan, bool $dry_run = true) {
    $valid = wpultra_dfb_validate_plan($plan);
    if ($valid !== true) { return wpultra_err('invalid_plan', (string) $valid); }

    $pages_in = is_array($plan['pages'] ?? null) ? $plan['pages'] : [];
    $menu_in = is_array($plan['menu'] ?? null) ? $plan['menu'] : [];
    $tokens_in = is_array($plan['tokens'] ?? null) ? $plan['tokens'] : [];
    $site_name = (string) ($plan['site']['name'] ?? 'Site');

    $result_pages = [];
    $slug_to_id = [];

    /* ---- Pages ---- */
    foreach ($pages_in as $page) {
        if (!is_array($page)) { continue; }
        $slug = wpultra_dfb_slug((string) ($page['slug'] ?? ''));
        $title = (string) ($page['title'] ?? $slug);
        $content = wpultra_dfb_page_content($page);

        $existing_id = 0;
        if (function_exists('get_page_by_path')) {
            $existing = get_page_by_path($slug, OBJECT, 'page');
            if ($existing && isset($existing->ID)) { $existing_id = (int) $existing->ID; }
        }

        if ($dry_run) {
            $result_pages[] = [
                'slug'   => $slug,
                'title'  => $title,
                'action' => $existing_id ? 'would-update' : 'would-create',
                'blocks' => substr_count($content, '<!-- wp:'),
            ];
            continue;
        }

        $postarr = [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ];
        if ($existing_id) { $postarr['ID'] = $existing_id; }
        // wp_insert_post unslashes — slash first so block-markup backslashes/JSON survive.
        $id = wp_insert_post(function_exists('wp_slash') ? wp_slash($postarr) : $postarr, true);
        if (is_wp_error($id)) {
            $result_pages[] = ['slug' => $slug, 'title' => $title, 'action' => 'failed', 'error' => $id->get_error_message()];
            continue;
        }
        $id = (int) $id;
        $slug_to_id[$slug] = $id;
        $result_pages[] = [
            'slug'   => $slug,
            'id'     => $id,
            'title'  => $title,
            'action' => $existing_id ? 'update' : 'create',
            'blocks' => substr_count($content, '<!-- wp:'),
        ];
    }

    /* ---- Menu ---- */
    $menu_result = ['name' => '', 'items' => [], 'assigned_location' => null];
    if ($menu_in !== []) {
        $menu_name = $site_name . ' Menu';
        if ($dry_run) {
            $items = [];
            foreach ($menu_in as $item) {
                if (!is_array($item)) { continue; }
                $items[] = [
                    'title'  => (string) ($item['title'] ?? ''),
                    'target' => trim((string) ($item['page_slug'] ?? '')) !== ''
                        ? 'page:' . wpultra_dfb_slug((string) $item['page_slug'])
                        : 'url:' . (string) ($item['url'] ?? ''),
                    'action' => 'would-create',
                ];
            }
            $menu_result = ['name' => $menu_name, 'items' => $items, 'assigned_location' => null, 'action' => 'would-create'];
        } elseif (function_exists('wp_create_nav_menu') && function_exists('wp_update_nav_menu_item')) {
            // Reuse an existing menu of the same name if present (idempotent-ish).
            $menu_id = 0;
            if (function_exists('wp_get_nav_menu_object')) {
                $obj = wp_get_nav_menu_object($menu_name);
                if ($obj && isset($obj->term_id)) { $menu_id = (int) $obj->term_id; }
            }
            if (!$menu_id) {
                $menu_id = wp_create_nav_menu($menu_name);
            }
            if (is_wp_error($menu_id)) {
                $menu_result = ['name' => $menu_name, 'error' => $menu_id->get_error_message(), 'items' => []];
            } else {
                $menu_id = (int) $menu_id;
                $built_items = [];
                foreach ($menu_in as $item) {
                    if (!is_array($item)) { continue; }
                    $title = (string) ($item['title'] ?? '');
                    if (trim($title) === '') { continue; }
                    $page_slug = wpultra_dfb_slug((string) ($item['page_slug'] ?? ''));
                    $menu_item = ['menu-item-title' => $title, 'menu-item-status' => 'publish'];
                    if ($page_slug !== '' && isset($slug_to_id[$page_slug])) {
                        $menu_item['menu-item-object'] = 'page';
                        $menu_item['menu-item-object-id'] = $slug_to_id[$page_slug];
                        $menu_item['menu-item-type'] = 'post_type';
                    } else {
                        $menu_item['menu-item-url'] = (string) ($item['url'] ?? '#');
                        $menu_item['menu-item-type'] = 'custom';
                    }
                    $mi_id = wp_update_nav_menu_item($menu_id, 0, $menu_item);
                    $built_items[] = [
                        'title' => $title,
                        'id'    => is_wp_error($mi_id) ? 0 : (int) $mi_id,
                        'error' => is_wp_error($mi_id) ? $mi_id->get_error_message() : null,
                    ];
                }
                // Assign to the theme's primary menu location when one exists.
                $assigned = wpultra_dfb_assign_menu_location($menu_id);
                $menu_result = [
                    'name'              => $menu_name,
                    'id'                => $menu_id,
                    'items'             => $built_items,
                    'assigned_location' => $assigned,
                    'action'            => 'create',
                ];
            }
        } else {
            $menu_result = ['name' => $menu_name, 'error' => 'Nav-menu functions unavailable.', 'items' => []];
        }
    }

    /* ---- Theme tokens ---- */
    $settings = wpultra_dfb_tokens_to_settings($tokens_in);
    $tokens_applied = false;
    $tokens_note = '';
    if ($settings !== []) {
        $is_block_theme = function_exists('wpultra_fse_block_theme_available') && wpultra_fse_block_theme_available();
        if ($dry_run) {
            $tokens_note = $is_block_theme
                ? 'would write palette/typography into block-theme global styles'
                : 'not a block theme — tokens would be recorded to an option and returned for the caller to apply (e.g. via Elementor tokens)';
        } elseif ($is_block_theme && function_exists('wpultra_fse_theme_json_set')) {
            $res = wpultra_fse_theme_json_set($settings, [], true);
            if (is_wp_error($res)) {
                $tokens_note = 'theme.json write failed: ' . $res->get_error_message();
            } else {
                $tokens_applied = true;
                $tokens_note = 'wrote palette/typography into block-theme global styles';
            }
        } else {
            // Not a block theme: record the tokens for the caller to apply elsewhere.
            if (function_exists('update_option')) {
                update_option('wpultra_dfb_tokens', $settings, false);
            }
            $tokens_note = 'not a block theme — tokens recorded to option wpultra_dfb_tokens for the caller to apply (e.g. Elementor)';
        }
    }

    return [
        'dry_run'        => $dry_run,
        'pages'          => $result_pages,
        'menu'           => $menu_result,
        'tokens_applied' => $tokens_applied,
        'tokens'         => $settings,
        'tokens_note'    => $tokens_note,
    ];
}

/**
 * Assign a nav menu to the theme's primary/first menu location. Best-effort.
 * Returns the location slug it assigned to, or null.
 */
function wpultra_dfb_assign_menu_location(int $menu_id): ?string {
    if (!function_exists('get_registered_nav_menus') || !function_exists('get_nav_menu_locations') || !function_exists('set_theme_mod')) {
        return null;
    }
    $locations = get_registered_nav_menus();
    if (!is_array($locations) || $locations === []) { return null; }
    // Prefer a location whose slug hints "primary"/"main"; else the first.
    $target = '';
    foreach (array_keys($locations) as $loc) {
        if (preg_match('/primary|main|header/i', (string) $loc)) { $target = (string) $loc; break; }
    }
    if ($target === '') { $target = (string) array_key_first($locations); }
    $current = get_nav_menu_locations();
    if (!is_array($current)) { $current = []; }
    $current[$target] = $menu_id;
    set_theme_mod('nav_menu_locations', $current);
    return $target;
}

/**
 * Runtime contract: called by the controller at boot. Ability-driven feature,
 * so there is nothing to schedule or register here — keep it cheap.
 */
function wpultra_dfb_boot(): void {
    // No-op: design-from-brief runs entirely through its ability.
}
