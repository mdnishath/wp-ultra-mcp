<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Bricks design-token minting — parity with Elementor's elementor-apply-design-tokens and
 * Gutenberg's gutenberg-apply-design-tokens (fse/tokens.php), targeting Bricks' global color
 * palette + global variables instead of theme.json presets or Elementor kit Variables.
 *
 * STORAGE ASSUMPTION (flagged — could not be live-verified, see report):
 * bricks/engine.php only reads a `postTypes` key off the real, confirmed-live option
 * `bricks_global_settings`; it has no code touching a native color-palette or variables
 * store, and no Bricks install exists in this dev environment to inspect one directly (unlike
 * Elementor, which is live on the Local test site). Rather than guess a nested key inside the
 * shared `bricks_global_settings` blob (risking corrupting real Bricks settings if the guessed
 * key/shape is wrong), this mints two DEDICATED options — matching the same pattern this
 * codebase already uses for `bricks_global_classes` (a wp-ultra-managed "global X" concept,
 * see includes/bricks/ops.php WPULTRA_BRICKS_CLASSES_OPTION) — so a wrong guess is inert
 * (an extra option Bricks itself just doesn't read) rather than destructive. If a live Bricks
 * install confirms different real option names/shapes, only the two constants below need to
 * change; every function here is shape-agnostic beyond {id,name,color|value}.
 */
const WPULTRA_BRICKS_COLORS_OPTION = 'bricks_color_palette';
const WPULTRA_BRICKS_VARIABLES_OPTION = 'bricks_global_variables';

if (!function_exists('wpultra_tokens_slug')) {
    require_once __DIR__ . '/../fse/tokens.php';
}

/**
 * Pure: brief colors -> Bricks global-color entries {id,name,color}. `id` is the token's own
 * deterministic slug (NOT one of this codebase's random 6-char Bricks element ids, see
 * wpultra_bricks_new_id in bricks/ops.php) precisely so re-deriving from the SAME role/title
 * later reproduces the SAME id — that's what makes wpultra_tokens_bricks_upsert() idempotent
 * by slug instead of piling up duplicate colors on every re-run.
 *
 * Any entry whose hex fails wpultra_tokens_is_hex_color() (garbage like "notacolor", not just
 * an empty string) is SKIPPED — not minted — and a reason is appended to `dropped` instead,
 * mirroring Elementor's wpultra_el_build_token_plan() `errors` array. Parity fix: this used to
 * only check `$hex !== ''`, so an invalid hex was written straight into the color option with
 * no signal.
 * @return array{items: array<int,array{id:string,name:string,color:string}>, dropped: array<int,string>}
 */
function wpultra_tokens_bricks_colors(array $brief): array {
    $colors = is_array($brief['colors'] ?? null) ? $brief['colors'] : [];
    $out = [];
    $dropped = [];
    if ($colors === []) { return ['items' => $out, 'dropped' => $dropped]; }
    $seen = [];
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
        $out[] = ['id' => $slug, 'name' => (string) ($c['title'] ?? $slug), 'color' => wpultra_tokens_normalize_hex($hex)];
    }
    return ['items' => $out, 'dropped' => $dropped];
}

/**
 * Pure: brief fonts + sizes -> Bricks global-variable entries {id,name,value} (fonts use the
 * family string as the value; sizes use "<size><unit>", default unit px). Same slug-as-id
 * idempotency rationale as wpultra_tokens_bricks_colors(). Same skip-and-report validation
 * (empty font family, non-numeric size) as wpultra_tokens_theme_json_patch().
 * @return array{items: array<int,array{id:string,name:string,value:string}>, dropped: array<int,string>}
 */
function wpultra_tokens_bricks_variables(array $brief): array {
    $out = [];
    $dropped = [];

    $fonts = is_array($brief['fonts'] ?? null) ? $brief['fonts'] : [];
    if ($fonts !== []) {
        $seen = [];
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
            $out[] = ['id' => $slug, 'name' => (string) ($f['title'] ?? $slug), 'value' => $family];
        }
    }

    $sizes = is_array($brief['sizes'] ?? null) ? $brief['sizes'] : [];
    if ($sizes !== []) {
        $seen = [];
        foreach ($sizes as $i => $s) {
            if (!is_array($s)) { continue; }
            $title = trim((string) ($s['title'] ?? ($s['role'] ?? '')));
            if ($title === '') { $title = "size #$i"; }
            if (!isset($s['size']) || !is_numeric($s['size'])) {
                $dropped[] = "size '$title' needs a numeric size.";
                continue;
            }
            $value = wpultra_tokens_size_value($s['size'], (string) ($s['unit'] ?? 'px'));
            $base = wpultra_tokens_slug((string) ($s['role'] ?? ($s['title'] ?? '')));
            $slug = wpultra_tokens_unique_slug($base, $seen);
            $out[] = ['id' => $slug, 'name' => (string) ($s['title'] ?? $slug), 'value' => $value];
        }
    }

    return ['items' => $out, 'dropped' => $dropped];
}

/**
 * Pure: upsert $incoming entries (each carrying an 'id') into $existing by id — a matching id
 * is REPLACED in place, a new id is appended. Mirrors wpultra_tokens_upsert_preset_list()
 * (fse/tokens.php) but keyed on 'id' (Bricks' field name) instead of 'slug' (theme.json's).
 */
function wpultra_tokens_bricks_upsert(array $existing, array $incoming): array {
    $byId = [];
    foreach ($existing as $i => $e) {
        if (is_array($e) && isset($e['id'])) { $byId[(string) $e['id']] = $i; }
    }
    $out = array_values($existing);
    foreach ($incoming as $e) {
        if (!is_array($e) || !isset($e['id'])) { continue; }
        $id = (string) $e['id'];
        if (isset($byId[$id]) && isset($out[$byId[$id]])) {
            $out[$byId[$id]] = $e;
        } else {
            $out[] = $e;
            $byId[$id] = count($out) - 1;
        }
    }
    return array_values($out);
}

/** Thin WP wrapper: current Bricks global color palette (our dedicated option), or []. */
function wpultra_bricks_tokens_colors_get(): array {
    if (!function_exists('get_option')) { return []; }
    $v = get_option(WPULTRA_BRICKS_COLORS_OPTION, []);
    return is_array($v) ? array_values($v) : [];
}

/** Thin WP wrapper: persist the Bricks global color palette (our dedicated option). */
function wpultra_bricks_tokens_colors_set(array $colors): bool {
    if (!function_exists('update_option')) { return false; }
    return (bool) update_option(WPULTRA_BRICKS_COLORS_OPTION, array_values($colors), false);
}

/** Thin WP wrapper: current Bricks global variables (our dedicated option), or []. */
function wpultra_bricks_tokens_variables_get(): array {
    if (!function_exists('get_option')) { return []; }
    $v = get_option(WPULTRA_BRICKS_VARIABLES_OPTION, []);
    return is_array($v) ? array_values($v) : [];
}

/** Thin WP wrapper: persist Bricks global variables (our dedicated option). */
function wpultra_bricks_tokens_variables_set(array $vars): bool {
    if (!function_exists('update_option')) { return false; }
    return (bool) update_option(WPULTRA_BRICKS_VARIABLES_OPTION, array_values($vars), false);
}

/**
 * Thin WP wrapper: apply a design brief to Bricks' global color palette + global variables.
 * Graceful `bricks_unavailable` when Bricks isn't active (reuses wpultra_bricks_active() from
 * bricks/engine.php, the same detection bricks-status/bricks-get-content use).
 * A brief with some invalid entries (bad hex, empty font family, non-numeric size) still mints
 * every valid entry; the invalid ones are reported back in `dropped` rather than silently lost.
 * @return array{colors: array, variables: array, dropped?: array<int,string>}|WP_Error
 */
function wpultra_bricks_apply_tokens(array $brief) {
    if (!function_exists('wpultra_bricks_active')) {
        require_once __DIR__ . '/engine.php';
    }
    if (!function_exists('wpultra_bricks_active') || !wpultra_bricks_active()) {
        return wpultra_err('bricks_unavailable', 'Bricks is not installed/active on this site.');
    }

    $colors_result = wpultra_tokens_bricks_colors($brief);
    $vars_result = wpultra_tokens_bricks_variables($brief);
    $new_colors = $colors_result['items'];
    $new_vars = $vars_result['items'];
    $dropped = array_merge($colors_result['dropped'], $vars_result['dropped']);

    if ($new_colors === [] && $new_vars === []) {
        return wpultra_err('empty_brief', 'No valid tokens to create.', $dropped !== [] ? ['dropped' => $dropped] : '');
    }

    $created_colors = [];
    if ($new_colors !== []) {
        $merged = wpultra_tokens_bricks_upsert(wpultra_bricks_tokens_colors_get(), $new_colors);
        wpultra_bricks_tokens_colors_set($merged);
        $created_colors = $new_colors;
    }

    $created_vars = [];
    if ($new_vars !== []) {
        $merged_vars = wpultra_tokens_bricks_upsert(wpultra_bricks_tokens_variables_get(), $new_vars);
        wpultra_bricks_tokens_variables_set($merged_vars);
        $created_vars = $new_vars;
    }

    $result = ['colors' => $created_colors, 'variables' => $created_vars];
    if ($dropped !== []) { $result['dropped'] = $dropped; }
    return $result;
}
