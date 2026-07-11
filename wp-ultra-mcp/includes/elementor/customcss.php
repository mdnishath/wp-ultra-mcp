<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Per-element raw Custom CSS (Roadmap-4 PP1.3).
 *
 * Pro path: Elementor Pro exposes a per-element "Custom CSS" control whose value is stored
 * verbatim in the element's `settings.custom_css` (classic convention; confirmed by reading the
 * widget schema when available — see wpultra_elcss_pro_setting_key()). We reuse
 * wpultra_el_merge_settings()/wpultra_el_write() (elementor/tree.php + engine.php) to persist it.
 *
 * Free path (no Pro): there is no per-element CSS field, so the raw CSS is routed into the
 * site-wide Additional CSS store (wp-ultra-mcp/includes/fse/engine.php:
 * wpultra_fse_custom_css_get()/wpultra_fse_custom_css_set()) inside an idempotent, clearly
 * marked block keyed by post+element id — see wpultra_elcss_marker() for the exact comment
 * markers used to bound that block.
 * The user's CSS is written using Elementor's own `selector` placeholder convention (as if it
 * were the Pro custom-css field); we rewrite that token to a concrete selector before storing it
 * in the free path so the rule actually targets this one element.
 *
 * Renderer note: tests/elementor-validate.test.php's render_digest fixture shows this codebase's
 * Elementor output marks every element with a SHARED `class="elementor-element"` plus a
 * `data-id="<id>"` attribute. Real Elementor core additionally emits a unique per-id class,
 * `elementor-element-<id>`, on the same wrapper. The free-path concrete selector targets BOTH
 * forms (comma-separated) so it scopes correctly regardless of which convention rendered the
 * markup: `.elementor-element-<id>, .elementor-element[data-id="<id>"]` (see
 * wpultra_elcss_concrete_selector()).
 *
 * element_id charset: Elementor's own ids are 6-8 char lowercase-alphanumeric, but nothing
 * elsewhere in this codebase guarantees that shape by the time it reaches this file. Because
 * element_id is interpolated verbatim into both the marker comment and the concrete selector
 * below, callers MUST validate it with wpultra_elcss_validate_element_id() before it reaches
 * wpultra_elcss_marker() or wpultra_elcss_concrete_selector() — an id containing `"` would break
 * out of the selector's attribute value, and one containing the comment-close sequence
 * (asterisk-slash) would prematurely close the marker comment and corrupt the idempotent block
 * boundaries.
 */

// Cross-category dependency: the free-path sink lives in the fse engine, which may load in a
// later bootstrap phase than the elementor category. Require it directly rather than assuming
// load order (do not edit fse/engine.php itself).
if (is_readable(__DIR__ . '/../fse/engine.php')) {
    require_once __DIR__ . '/../fse/engine.php';
}

/* ------------------------------------------------------------------ *
 * PURE functions (unit-tested — no WordPress calls).
 * ------------------------------------------------------------------ */

/** Maximum accepted length for a single per-element Custom CSS payload. */
function wpultra_elcss_default_cap(): int {
    return 20000;
}

/**
 * Sanitize user-authored CSS that will be rendered into a <style> block (Pro classic control)
 * or a stylesheet (free-path Additional CSS). Pure.
 *
 * - Rejects payloads longer than $cap (checked against the raw input, before cleanup).
 * - Strips literal `</style>` / `</script>` close tags (case-insensitive, tolerant of stray
 *   whitespace/attributes before the `>`), since a <style> block is parsed by the browser as
 *   HTML "RAWTEXT" and ends at the first literal "</style" regardless of surrounding CSS syntax.
 * - After that cleanup, any remaining literal '<' means the payload still carries a tag opener
 *   (e.g. a bare `<script>`, `<img onerror=...>`, or a mangled attempt to sneak one past the
 *   strip above) — valid CSS never needs a literal '<', so this is rejected as a breakout
 *   attempt. '>' alone is NOT rejected: it is common, legitimate CSS (the child combinator,
 *   e.g. `selector > .child`).
 *
 * @return string|WP_Error
 */
function wpultra_elcss_sanitize(string $css, int $cap = 20000) {
    if (strlen($css) > $cap) {
        return wpultra_err('css_too_long', "Custom CSS exceeds the maximum length of {$cap} characters.");
    }
    $clean = preg_replace('#</\s*(style|script)\b[^>]*>#i', '', $css);
    if (!is_string($clean)) { $clean = $css; }
    if (strpos($clean, '<') !== false) {
        return wpultra_err('unsafe_css', 'Custom CSS may not contain a literal "<" character (possible HTML/script breakout attempt).');
    }
    return $clean;
}

/**
 * Validate that an element_id is safe to interpolate verbatim into the marker comment
 * (wpultra_elcss_marker()) and the concrete CSS selector (wpultra_elcss_concrete_selector()).
 * Elementor's own element ids are 6-8 char lowercase alphanumeric, but nothing elsewhere in this
 * codebase guarantees that shape by the time an id reaches this file, so callers MUST run it
 * through this validator first. Only `[A-Za-z0-9_-]+` is accepted (letters, digits, underscore,
 * hyphen — a superset of Elementor's own id charset that also tolerates custom/legacy ids using
 * `_`/`-`). Anything else — quotes, the comment-close sequence (asterisk-slash), angle brackets,
 * whitespace, or an empty string — is rejected, since it could otherwise break out of the
 * selector's attribute value or prematurely close the marker comment. Pure.
 *
 * @return true|WP_Error
 */
function wpultra_elcss_validate_element_id(string $id) {
    if ($id === '' || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
        return wpultra_err('bad_element_id', 'element_id must be a non-empty string containing only letters, digits, underscore, or hyphen.');
    }
    return true;
}

/**
 * Build the idempotent marker pair used to locate/insert/remove a single element's free-path
 * CSS block inside the site-wide Additional CSS blob. Pure.
 *
 * SECURITY: $elid is interpolated verbatim into the marker comment — callers MUST validate it
 * with wpultra_elcss_validate_element_id() first, otherwise an id containing the comment-close
 * sequence (asterisk-slash) could prematurely close the comment and corrupt the idempotent block
 * boundaries.
 *
 * @return array{start:string,end:string}
 */
function wpultra_elcss_marker(int $post, string $elid): array {
    $key = $post . ':' . $elid;
    return [
        'start' => "/* wpultra-el-css:{$key} START */",
        'end'   => "/* wpultra-el-css:{$key} END */",
    ];
}

/**
 * Idempotently insert or replace a marker-delimited block inside $additional_css. If a block
 * already exists between $marker_start and $marker_end it is replaced in place (preserving
 * everything else in the stylesheet); otherwise the new block is appended. Pure.
 */
function wpultra_elcss_upsert_block(string $additional_css, string $marker_start, string $marker_end, string $body): string {
    $new_block = $marker_start . "\n" . rtrim($body) . "\n" . $marker_end;
    $pattern = '#' . preg_quote($marker_start, '#') . '.*?' . preg_quote($marker_end, '#') . '#s';
    if (preg_match($pattern, $additional_css) === 1) {
        $replaced = preg_replace($pattern, $new_block, $additional_css, 1);
        return is_string($replaced) ? $replaced : $additional_css;
    }
    $prefix = rtrim($additional_css);
    return ($prefix === '' ? '' : $prefix . "\n\n") . $new_block . "\n";
}

/**
 * Remove a marker-delimited block (and its markers) from $additional_css, leaving the rest of
 * the stylesheet untouched. A no-op (returns the input, only whitespace-normalized) when the
 * marker pair is not present. Pure.
 */
function wpultra_elcss_remove_block(string $additional_css, string $marker_start, string $marker_end): string {
    $pattern = '#(?:\n{1,2})?' . preg_quote($marker_start, '#') . '.*?' . preg_quote($marker_end, '#') . '#s';
    $out = preg_replace($pattern, '', $additional_css);
    if (!is_string($out)) { return $additional_css; }
    $out = rtrim($out);
    return $out === '' ? '' : $out . "\n";
}

/**
 * Return the CSS body currently stored inside a marker-delimited block, or '' when absent. Pure.
 */
function wpultra_elcss_extract_block(string $additional_css, string $marker_start, string $marker_end): string {
    $pattern = '#' . preg_quote($marker_start, '#') . '\s*(.*?)\s*' . preg_quote($marker_end, '#') . '#s';
    if (preg_match($pattern, $additional_css, $m) === 1) { return $m[1]; }
    return '';
}

/**
 * Replace every whole-word occurrence of Elementor's `selector` placeholder token with a
 * concrete CSS selector. Mirrors Elementor's own convention: the user writes CSS as if `selector`
 * were the element root (optionally combined with descendants/pseudo-classes/comma lists), and
 * every occurrence across the whole payload — not just one rule head — gets rewritten. Pure.
 */
function wpultra_elcss_rewrite_selector(string $css, string $concrete_selector): string {
    $out = preg_replace('/\bselector\b/', $concrete_selector, $css);
    return is_string($out) ? $out : $css;
}

/**
 * Build the free-path concrete selector for one element. This codebase's Elementor renderer
 * marks every element with a shared `class="elementor-element"` plus a `data-id="<id>"`
 * attribute (see tests/elementor-validate.test.php's render_digest fixture); real Elementor core
 * additionally emits a unique `.elementor-element-<id>` class on the same wrapper. Targeting both
 * forms (comma-separated) keeps the selector correct across Elementor versions/renderers. Pure.
 *
 * SECURITY: $element_id is interpolated verbatim here — callers MUST validate it with
 * wpultra_elcss_validate_element_id() first (enforced in the ability callback before this is
 * reached), otherwise a crafted id could break out of the selector's attribute value.
 */
function wpultra_elcss_concrete_selector(string $element_id): string {
    return '.elementor-element-' . $element_id . ', .elementor-element[data-id="' . $element_id . '"]';
}

/**
 * Decide which settings key holds Pro's per-element Custom CSS for a widget. Classic Elementor
 * Pro convention is `custom_css`; when an atomic widget schema is available and declares a
 * dedicated CSS prop we prefer that key instead. Pure (schema is passed in, not fetched here).
 */
function wpultra_elcss_pro_setting_key(?array $widget_schema): string {
    if (is_array($widget_schema) && !empty($widget_schema['props']) && is_array($widget_schema['props'])) {
        foreach (['custom_css', 'css'] as $candidate) {
            if (array_key_exists($candidate, $widget_schema['props'])) { return $candidate; }
        }
    }
    return 'custom_css';
}

/* ------------------------------------------------------------------ *
 * Thin WP-touching wrappers — delegate to the pure functions above plus the
 * reused elementor/fse engines. Guarded with function_exists so this file can
 * be required standalone (e.g. by unit tests) without those engines loaded.
 * ------------------------------------------------------------------ */

/** Best-effort widget schema lookup for a node; null when unavailable/not a widget. */
function wpultra_elcss_widget_schema_safe(array $node): ?array {
    if (($node['elType'] ?? '') !== 'widget' || empty($node['widgetType'])) { return null; }
    if (!function_exists('wpultra_el_widget_schema') || !function_exists('wpultra_el_active') || !wpultra_el_active()) { return null; }
    try {
        $schema = wpultra_el_widget_schema((string) $node['widgetType']);
        return is_array($schema) ? $schema : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** True when Elementor Pro (the per-element Custom CSS control) is available. */
function wpultra_elcss_pro_path_active(): bool {
    return function_exists('wpultra_epro_active') && wpultra_epro_active();
}

/** Read current per-element CSS from whichever store (Pro setting or free-path marker block). */
function wpultra_elcss_get(int $post_id, string $elid, array $node) {
    if (wpultra_elcss_pro_path_active()) {
        $schema = wpultra_elcss_widget_schema_safe($node);
        $key = wpultra_elcss_pro_setting_key($schema);
        $css = (string) ($node['settings'][$key] ?? '');
        return wpultra_ok(['path' => 'pro', 'post_id' => $post_id, 'element_id' => $elid, 'css' => $css, 'exists' => $css !== '', 'setting_key' => $key]);
    }
    if (!function_exists('wpultra_fse_custom_css_get')) {
        return wpultra_err('fse_unavailable', 'Custom CSS store unavailable (fse engine not loaded).');
    }
    $current = wpultra_fse_custom_css_get();
    if (is_wp_error($current)) { return $current; }
    $blob = (string) ($current['css'] ?? '');
    $m = wpultra_elcss_marker($post_id, $elid);
    $body = wpultra_elcss_extract_block($blob, $m['start'], $m['end']);
    return wpultra_ok(['path' => 'free', 'post_id' => $post_id, 'element_id' => $elid, 'css' => $body, 'exists' => $body !== '']);
}

/** Write (confirm-gated by the caller) per-element CSS via the Pro setting or the free-path marker block. */
function wpultra_elcss_set(int $post_id, string $elid, string $css, array $data, array $node) {
    $sanitized = wpultra_elcss_sanitize($css, wpultra_elcss_default_cap());
    if (is_wp_error($sanitized)) { return $sanitized; }

    if (wpultra_elcss_pro_path_active()) {
        if (!function_exists('wpultra_el_merge_settings') || !function_exists('wpultra_el_write')) {
            return wpultra_err('elementor_engine_unavailable', 'Elementor tree engine functions are unavailable.');
        }
        $schema = wpultra_elcss_widget_schema_safe($node);
        $key = wpultra_elcss_pro_setting_key($schema);
        $updated = wpultra_el_merge_settings($data, $elid, [$key => $sanitized], false);
        if (is_wp_error($updated)) { return $updated; }
        $w = wpultra_el_write($post_id, $updated);
        if (is_wp_error($w)) { return $w; }
        if (function_exists('wpultra_audit_log')) {
            wpultra_audit_log('element-custom-css', "set PRO post={$post_id} el={$elid} setting={$key} len=" . strlen($sanitized), true);
        }
        return wpultra_ok(['path' => 'pro', 'post_id' => $post_id, 'element_id' => $elid, 'css' => $sanitized, 'setting_key' => $key]);
    }

    if (!function_exists('wpultra_fse_custom_css_get') || !function_exists('wpultra_fse_custom_css_set')) {
        return wpultra_err('fse_unavailable', 'Custom CSS store unavailable (fse engine not loaded).');
    }
    $current = wpultra_fse_custom_css_get();
    if (is_wp_error($current)) { return $current; }
    $blob = (string) ($current['css'] ?? '');
    $m = wpultra_elcss_marker($post_id, $elid);
    $selector = wpultra_elcss_concrete_selector($elid);
    $rewritten = wpultra_elcss_rewrite_selector($sanitized, $selector);
    $new_blob = wpultra_elcss_upsert_block($blob, $m['start'], $m['end'], $rewritten);
    $res = wpultra_fse_custom_css_set($new_blob, false);
    if (is_wp_error($res)) { return $res; }
    if (function_exists('wpultra_audit_log')) {
        wpultra_audit_log('element-custom-css', "set FREE post={$post_id} el={$elid} len=" . strlen($rewritten), true);
    }
    return wpultra_ok(['path' => 'free', 'post_id' => $post_id, 'element_id' => $elid, 'css' => $rewritten, 'selector' => $selector]);
}

/** Remove (confirm-gated by the caller) per-element CSS from the Pro setting or the free-path marker block. */
function wpultra_elcss_remove(int $post_id, string $elid, array $data, array $node) {
    if (wpultra_elcss_pro_path_active()) {
        if (!function_exists('wpultra_el_merge_settings') || !function_exists('wpultra_el_write')) {
            return wpultra_err('elementor_engine_unavailable', 'Elementor tree engine functions are unavailable.');
        }
        $schema = wpultra_elcss_widget_schema_safe($node);
        $key = wpultra_elcss_pro_setting_key($schema);
        $updated = wpultra_el_merge_settings($data, $elid, [$key => ''], false);
        if (is_wp_error($updated)) { return $updated; }
        $w = wpultra_el_write($post_id, $updated);
        if (is_wp_error($w)) { return $w; }
        if (function_exists('wpultra_audit_log')) {
            wpultra_audit_log('element-custom-css', "remove PRO post={$post_id} el={$elid} setting={$key}", true);
        }
        return wpultra_ok(['path' => 'pro', 'post_id' => $post_id, 'element_id' => $elid, 'removed' => true]);
    }

    if (!function_exists('wpultra_fse_custom_css_get') || !function_exists('wpultra_fse_custom_css_set')) {
        return wpultra_err('fse_unavailable', 'Custom CSS store unavailable (fse engine not loaded).');
    }
    $current = wpultra_fse_custom_css_get();
    if (is_wp_error($current)) { return $current; }
    $blob = (string) ($current['css'] ?? '');
    $m = wpultra_elcss_marker($post_id, $elid);
    $new_blob = wpultra_elcss_remove_block($blob, $m['start'], $m['end']);
    $res = wpultra_fse_custom_css_set($new_blob, false);
    if (is_wp_error($res)) { return $res; }
    if (function_exists('wpultra_audit_log')) {
        wpultra_audit_log('element-custom-css', "remove FREE post={$post_id} el={$elid}", true);
    }
    return wpultra_ok(['path' => 'free', 'post_id' => $post_id, 'element_id' => $elid, 'removed' => true]);
}
