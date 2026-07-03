<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Page cloner: productizes the proven manual clone workflow. The AI perceives a
 * reference (URL or screenshot) on the CLIENT side and passes a structured
 * BRIEF — design tokens + an ordered list of sections with real content — and
 * this engine does the whole build in one call: create/clear the page, mint
 * design-token variables, compose blueprint skeletons, fill their text in
 * document order, style sections via global classes, validate strictly, write
 * atomically, and render-check.
 *
 * URL mode is a best-effort server-side seeder for STATIC sites: it fetches the
 * HTML and derives a rough brief (headings, paragraphs, buttons, palette,
 * fonts). JS-rendered sites defeat wp_remote_get — the AI should look at the
 * page itself and supply the brief instead (the ability description says so).
 */

/* ------------------------------------------------------------------ *
 * PURE: brief normalization + section composition + content fill.
 * ------------------------------------------------------------------ */

/** Section types the composer knows. 'custom' builds from the section's own content counts. */
function wpultra_clone_section_types(): array {
    return ['navbar', 'hero', 'feature-grid', 'cta', 'footer', 'custom'];
}

/** Pure: normalize a raw brief into the canonical shape. @return array|string error */
function wpultra_clone_normalize_brief($brief) {
    if (!is_array($brief)) { return 'brief must be an object.'; }
    $sections = $brief['sections'] ?? null;
    if (!is_array($sections) || $sections === []) { return 'brief.sections must be a non-empty array.'; }
    if (count($sections) > 20) { return 'A clone brief may have at most 20 sections.'; }
    $norm = ['tokens' => [], 'sections' => []];
    $t = $brief['tokens'] ?? [];
    if (is_array($t)) {
        foreach (['colors', 'fonts', 'sizes'] as $k) {
            $norm['tokens'][$k] = array_values(array_filter((array) ($t[$k] ?? []), 'is_array'));
        }
    }
    foreach ($sections as $i => $s) {
        if (!is_array($s)) { return "Section $i must be an object."; }
        $type = (string) ($s['type'] ?? 'custom');
        if (!in_array($type, wpultra_clone_section_types(), true)) {
            return "Section $i: unknown type '$type'. Use: " . implode(', ', wpultra_clone_section_types()) . '.';
        }
        $norm['sections'][] = [
            'type'       => $type,
            'heading'    => (string) ($s['heading'] ?? ''),
            'subheading' => (string) ($s['subheading'] ?? ''),
            'paragraphs' => array_values(array_map('strval', (array) ($s['paragraphs'] ?? []))),
            'buttons'    => array_values(array_map('strval', (array) ($s['buttons'] ?? []))),
            'items'      => array_values(array_filter((array) ($s['items'] ?? []), 'is_array')),
            'background' => (string) ($s['background'] ?? ''),
            'text_color' => (string) ($s['text_color'] ?? ''),
        ];
    }
    return $norm;
}

/**
 * Pure: compose a section skeleton tree (placeholder 'bp' ids) for a normalized
 * section. Blueprint types adapt to the section's own content counts where it
 * matters (feature-grid/footer columns from items); 'custom' assembles a column
 * flexbox of heading/subheading/paragraphs/buttons.
 */
function wpultra_clone_compose_section(array $s): array {
    $fx = static fn(array $children) => ['id' => 'bp', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => $children];
    $h  = static fn(string $t, string $tag = 'h2') => ['id' => 'bp', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['tag' => $tag, 'title' => $t], 'elements' => []];
    $pr = static fn(string $t) => ['id' => 'bp', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => ['paragraph' => $t], 'elements' => []];
    $bt = static fn(string $t) => ['id' => 'bp', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => ['text' => $t], 'elements' => []];

    $type = $s['type'];
    if ($type === 'navbar') {
        $links = $s['paragraphs'] !== [] ? $s['paragraphs'] : ['Home', 'About', 'Contact'];
        return $fx([
            $h($s['heading'] !== '' ? $s['heading'] : 'Brand', 'h3'),
            $fx(array_map($pr, array_slice($links, 0, 8))),
            $bt($s['buttons'][0] ?? 'Get Started'),
        ]);
    }
    if ($type === 'hero') {
        $kids = [$h($s['heading'] !== '' ? $s['heading'] : 'Your headline goes here', 'h1')];
        if ($s['subheading'] !== '') { $kids[] = $pr($s['subheading']); }
        foreach (array_slice($s['paragraphs'], 0, 2) as $p) { $kids[] = $pr($p); }
        foreach (array_slice($s['buttons'], 0, 2) as $b) { $kids[] = $bt($b); }
        return $fx($kids);
    }
    if ($type === 'feature-grid' || $type === 'footer') {
        $items = $s['items'];
        if ($items === []) { // derive columns from paragraphs when no explicit items
            foreach (array_slice($s['paragraphs'], 0, 4) as $j => $p) {
                $items[] = ['heading' => 'Feature ' . ($j + 1), 'paragraph' => $p];
            }
        }
        if ($items === []) { $items = [['heading' => 'Feature one', 'paragraph' => 'Describe it here.']]; }
        $cols = [];
        foreach (array_slice($items, 0, 6) as $it) {
            $col = [$h((string) (($it['heading'] ?? '') !== '' ? $it['heading'] : 'Feature'), $type === 'footer' ? 'h4' : 'h3')];
            foreach (array_slice(array_values(array_filter([(string) ($it['paragraph'] ?? '')] + (array) ($it['paragraphs'] ?? []), 'strlen')), 0, 4) as $p) {
                $col[] = $pr($p);
            }
            $cols[] = $fx($col);
        }
        $wrap = $fx($cols);
        if ($s['heading'] !== '' && $type === 'feature-grid') {
            return $fx([$h($s['heading']), $wrap]);
        }
        return $wrap;
    }
    if ($type === 'cta') {
        $kids = [$h($s['heading'] !== '' ? $s['heading'] : 'Ready to get started?')];
        foreach (array_slice($s['paragraphs'], 0, 1) as $p) { $kids[] = $pr($p); }
        $kids[] = $bt($s['buttons'][0] ?? 'Sign up');
        return $fx($kids);
    }
    // custom
    $kids = [];
    if ($s['heading'] !== '') { $kids[] = $h($s['heading']); }
    if ($s['subheading'] !== '') { $kids[] = $pr($s['subheading']); }
    foreach (array_slice($s['paragraphs'], 0, 10) as $p) { $kids[] = $pr($p); }
    foreach (array_slice($s['buttons'], 0, 3) as $b) { $kids[] = $bt($b); }
    if ($kids === []) { $kids[] = $pr('Content'); }
    return $fx($kids);
}

/** Pure: class props for a section's background/text color (v4 style shapes). */
function wpultra_clone_section_class_props(string $background, string $text_color): array {
    $props = [];
    if ($background !== '') {
        $props['background'] = ['$$type' => 'background', 'value' => ['color' => ['$$type' => 'color', 'value' => $background]]];
    }
    if ($text_color !== '') {
        $props['color'] = ['$$type' => 'color', 'value' => $text_color];
    }
    return $props;
}

/* ------------------------------------------------------------------ *
 * PURE: best-effort static-HTML brief extraction (URL mode seeder).
 * ------------------------------------------------------------------ */

/** Pure: code-point-aware strlen that survives PHP builds without mbstring. */
function wpultra_clone_strlen(string $s): int {
    if (function_exists('mb_strlen')) { return (int) mb_strlen($s); }
    return preg_match_all('/./us', $s) ?: strlen($s);
}

/** Pure: strip tags/scripts and collapse whitespace. */
function wpultra_clone_text(string $html): string {
    $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}

/** Pure: all inner texts of a tag (h1, h2, p, button, a), trimmed, deduped, non-empty. */
function wpultra_clone_tag_texts(string $html, string $tag, int $max = 20): array {
    if (!preg_match_all("#<{$tag}\b[^>]*>(.*?)</{$tag}>#is", $html, $m)) { return []; }
    $out = [];
    foreach ($m[1] as $inner) {
        $t = wpultra_clone_text($inner);
        if ($t !== '' && wpultra_clone_strlen($t) <= 300 && !in_array($t, $out, true)) { $out[] = $t; }
        if (count($out) >= $max) { break; }
    }
    return $out;
}

/** Pure: hex colors seen in the markup/css, most frequent first. */
function wpultra_clone_palette(string $html, int $max = 6): array {
    if (!preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $html, $m)) { return []; }
    $freq = [];
    foreach ($m[0] as $hex) {
        $hex = strtolower($hex);
        $freq[$hex] = ($freq[$hex] ?? 0) + 1;
    }
    arsort($freq);
    return array_slice(array_keys($freq), 0, $max);
}

/** Pure: font families from google-fonts links + font-family declarations. */
function wpultra_clone_fonts(string $html, int $max = 3): array {
    $fonts = [];
    if (preg_match_all('#fonts\.googleapis\.com/css2?\?[^"\']*family=([^"&\':]+)#i', $html, $m)) {
        foreach ($m[1] as $fam) { $fonts[] = str_replace('+', ' ', urldecode($fam)); }
    }
    if (preg_match_all('/font-family\s*:\s*[\'"]?([A-Za-z0-9 \-]{3,40})[\'"]?\s*[,;]/', $html, $m2)) {
        foreach ($m2[1] as $fam) {
            $fam = trim($fam);
            if (!in_array(strtolower($fam), ['inherit', 'sans-serif', 'serif', 'monospace', 'system-ui', 'arial', 'helvetica'], true)) { $fonts[] = $fam; }
        }
    }
    return array_slice(array_values(array_unique($fonts)), 0, $max);
}

/**
 * Pure: derive a rough brief from static HTML. Returns [brief, notes]. The
 * heuristic maps h1 → hero, h2 groups → sections; buttons/links feed CTAs.
 */
function wpultra_clone_extract_brief(string $html, string $url = ''): array {
    $notes = [];
    $body_len = wpultra_clone_strlen(wpultra_clone_text($html));
    if ($body_len < 200) {
        $notes[] = "Extracted body text is only {$body_len} chars — the page is likely JS-rendered; the derived brief will be thin. Prefer looking at the page yourself and passing a full brief.";
    }
    $h1 = wpultra_clone_tag_texts($html, 'h1', 2);
    $h2 = wpultra_clone_tag_texts($html, 'h2', 8);
    $h3 = wpultra_clone_tag_texts($html, 'h3', 12);
    $ps = wpultra_clone_tag_texts($html, 'p', 20);
    $buttons = array_slice(array_values(array_unique(array_merge(
        wpultra_clone_tag_texts($html, 'button', 6),
        array_values(array_filter(wpultra_clone_tag_texts($html, 'a', 30), static fn($t) => wpultra_clone_strlen($t) <= 28))
    ))), 0, 8);

    $sections = [];
    $sections[] = [
        'type' => 'navbar',
        'heading' => wpultra_clone_tag_texts($html, 'title', 1)[0] ?? ($h1[0] ?? 'Brand'),
        'paragraphs' => array_slice($buttons, 0, 4),
        'buttons' => array_slice($buttons, 4, 1),
    ];
    $sections[] = [
        'type' => 'hero',
        'heading' => $h1[0] ?? ($h2[0] ?? 'Welcome'),
        'subheading' => $ps[0] ?? '',
        'buttons' => array_slice($buttons, 0, 1),
    ];
    if ($h3 !== [] || count($h2) > 1) {
        $items = [];
        $cols = $h3 !== [] ? $h3 : array_slice($h2, 1);
        foreach (array_slice($cols, 0, 3) as $j => $head) {
            $items[] = ['heading' => $head, 'paragraph' => $ps[$j + 1] ?? ''];
        }
        $sections[] = ['type' => 'feature-grid', 'heading' => count($h2) > 1 ? $h2[1] : '', 'items' => $items];
    }
    $sections[] = ['type' => 'cta', 'heading' => $h2[0] ?? 'Ready to get started?', 'buttons' => array_slice($buttons, 0, 1)];

    $brief = [
        'tokens' => [
            'colors' => array_map(static fn($hex, $i) => ['role' => 'color-' . ($i + 1), 'title' => 'Color ' . ($i + 1), 'hex' => $hex], $p = wpultra_clone_palette($html), array_keys($p)),
            'fonts'  => array_map(static fn($f, $i) => ['role' => 'font-' . ($i + 1), 'title' => $f, 'family' => $f], $f2 = wpultra_clone_fonts($html), array_keys($f2)),
            'sizes'  => [],
        ],
        'sections' => $sections,
    ];
    if ($url !== '') { $notes[] = "Brief auto-extracted from $url (static HTML heuristics: h1/h2/h3/p/buttons + palette + fonts)."; }
    return [$brief, $notes];
}

/* ------------------------------------------------------------------ *
 * Build orchestrator (thin — composes the proven engines).
 * ------------------------------------------------------------------ */

/**
 * Build a whole page from a normalized brief. @return array|WP_Error
 */
function wpultra_clone_build(int $post_id, array $brief, bool $replace = true) {
    $notes = [];

    // 1. Design tokens → Elementor Variables (best-effort; failures become notes).
    $tokens_created = 0;
    $t = $brief['tokens'] ?? [];
    if (!empty($t['colors']) || !empty($t['fonts']) || !empty($t['sizes'])) {
        if (function_exists('wpultra_el_variables_enable')) { wpultra_el_variables_enable(); }
        if (function_exists('wpultra_el_build_token_plan') && function_exists('wpultra_el_variables_create')) {
            $plan = wpultra_el_build_token_plan($t);
            foreach ((array) ($plan['errors'] ?? []) as $e) { $notes[] = 'token skipped: ' . $e; }
            foreach ((array) ($plan['plan'] ?? []) as $item) {
                if (!is_array($item) || empty($item['type']) || empty($item['title'])) { continue; }
                // Elementor Variables reject labels with spaces — slug them.
                $label = function_exists('wpultra_el_slug') ? wpultra_el_slug((string) $item['title']) : strtolower(str_replace(' ', '-', (string) $item['title']));
                $r = wpultra_el_variables_create((string) $item['type'], $label, $item['value'] ?? '');
                if (is_wp_error($r)) { $notes[] = 'token skipped: ' . $label . ' (' . $r->get_error_message() . ')'; }
                else { $tokens_created++; }
            }
        }
    }

    // 2. Compose + fill all sections, re-id collision-safe, validate strictly.
    $elements = $replace ? [] : wpultra_el_raw($post_id);
    foreach ($brief['sections'] as $i => $s) {
        $tree = [wpultra_clone_compose_section($s)];
        // $elements already carries every previously added section, so reid
        // stays collision-safe across the whole build.
        $tree = wpultra_el_blueprint_reid($tree, $elements);
        $report = wpultra_el_validate_tree($tree);
        if (!$report['ok']) {
            $bad = array_values(array_filter($report['nodes'], static fn($n) => !$n['valid']));
            return wpultra_err('section_invalid', "Section $i ({$s['type']}) failed atomic validation.", ['nodes' => $bad]);
        }
        $elements[] = $report['normalized_tree'][0];
    }

    // 3. Section styling via global classes (best-effort).
    $classes_created = 0;
    if (function_exists('wpultra_el_classes_enable')) { wpultra_el_classes_enable(); }
    foreach ($brief['sections'] as $i => $s) {
        $props = wpultra_clone_section_class_props($s['background'], $s['text_color']);
        if ($props === [] || !function_exists('wpultra_el_gc_upsert')) { continue; }
        $gc = wpultra_el_gc_upsert('clone-section-' . ($i + 1), $props);
        if (is_wp_error($gc)) { $notes[] = "section $i style class skipped: " . $gc->get_error_message(); continue; }
        $gc_id = is_array($gc) ? (string) ($gc['id'] ?? '') : '';
        if ($gc_id === '') { continue; }
        $root_id = (string) ($elements[count($elements) - count($brief['sections']) + $i]['id'] ?? '');
        foreach ($elements as &$el) {
            if (($el['id'] ?? '') === $root_id) {
                $el['settings']['classes'] = ['$$type' => 'classes', 'value' => [$gc_id]];
                $classes_created++;
            }
        }
        unset($el);
    }

    // 4. Atomic write + render-check.
    $w = wpultra_el_write($post_id, $elements);
    if (is_wp_error($w)) { return $w; }
    $render = function_exists('wpultra_el_render_check') ? wpultra_el_render_check($post_id) : null;

    return [
        'post_id'         => $post_id,
        'sections_built'  => count($brief['sections']),
        'tokens_created'  => $tokens_created,
        'classes_applied' => $classes_created,
        'render'          => is_wp_error($render) ? ['error' => $render->get_error_message()] : $render,
        'preview_url'     => (string) get_permalink($post_id),
        'notes'           => $notes,
    ];
}
