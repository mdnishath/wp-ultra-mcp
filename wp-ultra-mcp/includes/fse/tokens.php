<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Gutenberg/block-theme design-token minting — parity with Elementor's
 * elementor-apply-design-tokens (includes/elementor/design.php:wpultra_el_build_token_plan)
 * but targeting theme.json USER-layer `settings` (palette/fontFamilies/fontSizes/spacingSizes)
 * instead of Elementor kit Variables. Pure functions here are shared with bricks/tokens.php
 * (slugging + unique-slug) so both platforms mint identical slugs from the same brief.
 *
 * Shared brief shape (same as Elementor's):
 *   {colors:[{role,title,hex}], fonts:[{role,title,family}], sizes:[{role,title,size,unit}]}
 */

/** Pure: any role/title string -> lowercase-hyphen slug. Collapses non-alnum runs into a
 * single hyphen, trims leading/trailing hyphens, and never returns an empty string. */
function wpultra_tokens_slug(string $s): string {
    $s = strtolower(trim($s));
    $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? $s : 'token';
}

/**
 * Pure: dedupe slugs produced within a SINGLE brief pass by suffixing collisions -2, -3, ...
 * $seen is a caller-owned map (slug => true) threaded through the whole brief so repeated
 * roles/titles never overwrite one another. Re-running the SAME brief later (same role/title
 * set, in the same order) reproduces the SAME sequence of slugs — that determinism is what
 * makes the theme.json / Bricks upsert-by-slug idempotent across calls.
 */
function wpultra_tokens_unique_slug(string $base, array &$seen): string {
    $slug = $base;
    $i = 2;
    while (isset($seen[$slug])) {
        $slug = $base . '-' . $i;
        $i++;
    }
    $seen[$slug] = true;
    return $slug;
}

/** Pure: stringify a numeric size + unit without a trailing ".0" (mirrors wpultra_el_build_token_plan). */
function wpultra_tokens_size_value($size, string $unit): string {
    $unit = trim($unit) !== '' ? trim($unit) : 'px';
    return (string) (0 + $size) . $unit;
}

/**
 * Pure: hex color validator — accepts #rgb / #rrggbb / #rrggbbaa, with or without a leading
 * '#' (mirrors wpultra_el_is_hex_color in includes/elementor/design.php, extended with an
 * optional alpha channel since both theme.json and the Bricks color option accept 8-digit hex).
 * A standalone copy (rather than requiring elementor/design.php) so fse/tokens.php and
 * bricks/tokens.php don't gain a cross-category dependency just for one regex. Used to REJECT
 * garbage hex (e.g. "notacolor") instead of writing it straight into theme.json / the Bricks
 * color option — see wpultra_tokens_theme_json_patch() and wpultra_tokens_bricks_colors().
 */
function wpultra_tokens_is_hex_color(string $c): bool {
    $c = trim($c);
    if ($c === '') { return false; }
    if ($c[0] === '#') { $c = substr($c, 1); }
    return (bool) preg_match('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $c);
}

/** Pure: normalize a hex color already validated by wpultra_tokens_is_hex_color() to always
 * carry a leading '#', so entries submitted without one are still stored consistently. */
function wpultra_tokens_normalize_hex(string $c): string {
    $c = trim($c);
    return '#' . ltrim($c, '#');
}

/**
 * Pure: brief {colors,fonts,sizes} -> the partial theme.json `settings` structure to deep-merge
 * into the user global-styles layer. Slugs come from `role` when present, else `title`;
 * duplicates within the SAME brief get a numeric suffix (see wpultra_tokens_unique_slug).
 *
 * `sizes` are dual-purposed: written into BOTH settings.typography.fontSizes AND
 * settings.spacing.spacingSizes (same slug in both) since a brief "size" role — e.g.
 * "space-md" vs "heading-lg" — doesn't disambiguate which WP preset picker it belongs in;
 * exposing it in both costs nothing (unused presets are simply not referenced) and matches
 * every family the FSE engine context calls out.
 *
 * Any entry that fails validation (invalid/garbage hex, empty font family, non-numeric size)
 * is SKIPPED rather than minted, and a human-readable reason is appended to the returned
 * `dropped` list — mirrors Elementor's wpultra_el_build_token_plan() `errors` array. A brief
 * with some bad entries still mints everything valid; it's never rejected wholesale.
 *
 * @return array{}|array{settings?: array, dropped?: array<int,string>}
 */
function wpultra_tokens_theme_json_patch(array $brief): array {
    $settings = [];
    $dropped = [];

    $colors = is_array($brief['colors'] ?? null) ? $brief['colors'] : [];
    if ($colors !== []) {
        $seen = [];
        $palette = [];
        foreach ($colors as $i => $c) {
            if (!is_array($c)) { continue; }
            $hex = trim((string) ($c['hex'] ?? ''));
            $title = trim((string) ($c['title'] ?? ($c['role'] ?? '')));
            if ($title === '') { $title = "color #$i"; }
            if (!wpultra_tokens_is_hex_color($hex)) {
                $dropped[] = "color '$title' has invalid hex '$hex'.";
                continue;
            }
            $base = wpultra_tokens_slug((string) ($c['role'] ?? ($c['title'] ?? '')));
            $slug = wpultra_tokens_unique_slug($base, $seen);
            $palette[] = ['slug' => $slug, 'name' => (string) ($c['title'] ?? $slug), 'color' => wpultra_tokens_normalize_hex($hex)];
        }
        if ($palette !== []) { $settings['color']['palette'] = $palette; }
    }

    $fonts = is_array($brief['fonts'] ?? null) ? $brief['fonts'] : [];
    if ($fonts !== []) {
        $seen = [];
        $families = [];
        foreach ($fonts as $i => $f) {
            if (!is_array($f)) { continue; }
            $family = trim((string) ($f['family'] ?? ''));
            $title = trim((string) ($f['title'] ?? ($f['role'] ?? '')));
            if ($title === '') { $title = "font #$i"; }
            if ($family === '') {
                $dropped[] = "font '$title' needs a family.";
                continue;
            }
            $base = wpultra_tokens_slug((string) ($f['role'] ?? ($f['title'] ?? '')));
            $slug = wpultra_tokens_unique_slug($base, $seen);
            $families[] = ['slug' => $slug, 'name' => (string) ($f['title'] ?? $slug), 'fontFamily' => $family];
        }
        if ($families !== []) { $settings['typography']['fontFamilies'] = $families; }
    }

    $sizes = is_array($brief['sizes'] ?? null) ? $brief['sizes'] : [];
    if ($sizes !== []) {
        $seenSizes = [];
        $seenSpacing = [];
        $fontSizes = [];
        $spacingSizes = [];
        foreach ($sizes as $i => $sItem) {
            if (!is_array($sItem)) { continue; }
            $title = trim((string) ($sItem['title'] ?? ($sItem['role'] ?? '')));
            if ($title === '') { $title = "size #$i"; }
            if (!isset($sItem['size']) || !is_numeric($sItem['size'])) {
                $dropped[] = "size '$title' needs a numeric size.";
                continue;
            }
            $value = wpultra_tokens_size_value($sItem['size'], (string) ($sItem['unit'] ?? 'px'));
            $base = wpultra_tokens_slug((string) ($sItem['role'] ?? ($sItem['title'] ?? '')));
            $slugFont = wpultra_tokens_unique_slug($base, $seenSizes);
            $fontSizes[] = ['slug' => $slugFont, 'name' => (string) ($sItem['title'] ?? $slugFont), 'size' => $value];
            $slugSpacing = wpultra_tokens_unique_slug($base, $seenSpacing);
            $spacingSizes[] = ['slug' => $slugSpacing, 'name' => (string) ($sItem['title'] ?? $slugSpacing), 'size' => $value];
        }
        if ($fontSizes !== []) { $settings['typography']['fontSizes'] = $fontSizes; }
        if ($spacingSizes !== []) { $settings['spacing']['spacingSizes'] = $spacingSizes; }
    }

    if ($settings === [] && $dropped === []) { return []; }
    $result = [];
    if ($settings !== []) { $result['settings'] = $settings; }
    if ($dropped !== []) { $result['dropped'] = $dropped; }
    return $result;
}

/**
 * Pure: the `--wp--preset--{category}--{slug}` CSS custom-property names WordPress generates
 * for each preset entry in a theme.json settings patch (as produced by
 * wpultra_tokens_theme_json_patch, or the merged settings tree). Tolerates being handed either
 * the wrapper `{settings:{...}}` shape or a bare `settings` array.
 */
function wpultra_tokens_css_var_names(array $patch): array {
    $settings = is_array($patch['settings'] ?? null) ? $patch['settings'] : $patch;
    $vars = [];
    foreach ((array) ($settings['color']['palette'] ?? []) as $entry) {
        if (is_array($entry) && !empty($entry['slug'])) { $vars[] = '--wp--preset--color--' . $entry['slug']; }
    }
    foreach ((array) ($settings['typography']['fontFamilies'] ?? []) as $entry) {
        if (is_array($entry) && !empty($entry['slug'])) { $vars[] = '--wp--preset--font-family--' . $entry['slug']; }
    }
    foreach ((array) ($settings['typography']['fontSizes'] ?? []) as $entry) {
        if (is_array($entry) && !empty($entry['slug'])) { $vars[] = '--wp--preset--font-size--' . $entry['slug']; }
    }
    foreach ((array) ($settings['spacing']['spacingSizes'] ?? []) as $entry) {
        if (is_array($entry) && !empty($entry['slug'])) { $vars[] = '--wp--preset--spacing--' . $entry['slug']; }
    }
    return $vars;
}

/**
 * Pure: collect {family,slug} pairs minted by a theme.json settings patch, for the ability's
 * "created token slugs" response. Spacing.spacingSizes is intentionally NOT re-listed here —
 * it shares its slug 1:1 with typography.fontSizes (see wpultra_tokens_theme_json_patch), so
 * listing both would just report the same slug twice under a misleading second family.
 */
function wpultra_tokens_collect_slugs(array $settings): array {
    $out = [];
    foreach ((array) ($settings['color']['palette'] ?? []) as $e) {
        if (is_array($e) && !empty($e['slug'])) { $out[] = ['family' => 'color', 'slug' => (string) $e['slug']]; }
    }
    foreach ((array) ($settings['typography']['fontFamilies'] ?? []) as $e) {
        if (is_array($e) && !empty($e['slug'])) { $out[] = ['family' => 'font', 'slug' => (string) $e['slug']]; }
    }
    foreach ((array) ($settings['typography']['fontSizes'] ?? []) as $e) {
        if (is_array($e) && !empty($e['slug'])) { $out[] = ['family' => 'size', 'slug' => (string) $e['slug']]; }
    }
    return $out;
}

/**
 * Pure: upsert $incoming preset entries (each carrying a 'slug') into $existing_list by slug —
 * a matching slug is REPLACED in place (idempotent re-apply), a new slug is appended. Exists
 * because wpultra_fse_deep_merge (fse/engine.php) treats a non-empty list as "replace wholesale"
 * — preset-level idempotency-by-slug has to happen HERE, before the engine's generic deep-merge
 * ever sees the list, or re-applying a brief would wipe every OTHER token minted earlier.
 */
function wpultra_tokens_upsert_preset_list(array $existing_list, array $incoming_list): array {
    $bySlug = [];
    foreach ($existing_list as $i => $entry) {
        if (is_array($entry) && isset($entry['slug'])) { $bySlug[(string) $entry['slug']] = $i; }
    }
    $out = array_values($existing_list);
    foreach ($incoming_list as $entry) {
        if (!is_array($entry) || !isset($entry['slug'])) { continue; }
        $slug = (string) $entry['slug'];
        if (isset($bySlug[$slug]) && isset($out[$bySlug[$slug]])) {
            $out[$bySlug[$slug]] = $entry;
        } else {
            $out[] = $entry;
            $bySlug[$slug] = count($out) - 1;
        }
    }
    return array_values($out);
}

/**
 * Pure: apply wpultra_tokens_upsert_preset_list() to every preset branch present in $incoming
 * (color.palette / typography.fontFamilies / typography.fontSizes / spacing.spacingSizes),
 * leaving every other part of $existing untouched.
 */
function wpultra_tokens_upsert_settings(array $existing, array $incoming): array {
    $paths = [
        ['color', 'palette'],
        ['typography', 'fontFamilies'],
        ['typography', 'fontSizes'],
        ['spacing', 'spacingSizes'],
    ];
    $result = $existing;
    foreach ($paths as [$a, $b]) {
        if (!isset($incoming[$a][$b]) || !is_array($incoming[$a][$b])) { continue; }
        $existing_list = is_array($result[$a][$b] ?? null) ? $result[$a][$b] : [];
        $result[$a][$b] = wpultra_tokens_upsert_preset_list($existing_list, $incoming[$a][$b]);
    }
    return $result;
}

/**
 * Thin WP wrapper: translate a design brief into theme.json USER-layer `settings` additions
 * and merge them via the FSE engine, upserting preset lists by slug (idempotent re-apply).
 * Requires fse/engine.php for the resolver checks + read/write; guarded so this file can be
 * required standalone (e.g. by tests) without fatally erroring when the engine isn't loaded.
 * A brief with some invalid entries (bad hex, empty font family, non-numeric size) still mints
 * every valid entry; the invalid ones are reported back in `dropped` rather than silently lost.
 * @return array{slugs: array, css_vars: array, dropped?: array<int,string>}|WP_Error
 */
function wpultra_gutenberg_apply_tokens(array $brief) {
    if (!function_exists('wpultra_fse_resolver_available')) {
        require_once __DIR__ . '/engine.php';
    }
    if (!function_exists('wpultra_fse_resolver_available') || !wpultra_fse_resolver_available()) {
        return wpultra_err('fse_unavailable', 'WP_Theme_JSON_Resolver is unavailable on this WordPress version.');
    }

    $patch = wpultra_tokens_theme_json_patch($brief);
    $incoming_settings = $patch['settings'] ?? [];
    $dropped = $patch['dropped'] ?? [];
    if ($incoming_settings === []) {
        return wpultra_err('empty_brief', 'No valid tokens to create.', $dropped !== [] ? ['dropped' => $dropped] : '');
    }

    $current = wpultra_fse_theme_json_get('user');
    if (is_wp_error($current)) { return $current; }
    $existing_settings = is_array($current['data']['settings'] ?? null) ? $current['data']['settings'] : [];

    $merged_settings = wpultra_tokens_upsert_settings($existing_settings, $incoming_settings);

    $res = wpultra_fse_theme_json_set($merged_settings, [], true);
    if (is_wp_error($res)) { return $res; }

    $result = [
        'slugs'    => wpultra_tokens_collect_slugs($incoming_settings),
        'css_vars' => wpultra_tokens_css_var_names($patch),
    ];
    if ($dropped !== []) { $result['dropped'] = $dropped; }
    return $result;
}
