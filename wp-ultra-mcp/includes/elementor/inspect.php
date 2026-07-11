<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/*
 * inspect-element engine (Roadmap-4 Wave PP1, task PP1.5): CSS readback without a browser.
 *
 * Resolves an Elementor element's RESOLVED declared styles — its own settings/atomic props +
 * applied global classes' variants' props + referenced kit variables resolved to concrete values —
 * and flags token-driven vs hardcoded values (the "why does it look almost right" signal).
 *
 * Pure functions (unit-tested in tests/elementor-inspect.test.php, no WP calls):
 *   wpultra_elinspect_flatten_props, wpultra_elinspect_resolve_token, wpultra_elinspect_count.
 * Everything below that section is thin WP-touching orchestration guarded by function_exists().
 */

/** `$$type` values that mark a prop as a reference to a kit Variable rather than a literal value. */
const WPULTRA_ELINSPECT_TOKEN_TYPES = ['global-color-variable', 'global-font-variable', 'global-size-variable'];

/** True when an atomic prop's `$$type` marks it as a token (kit Variable) reference. */
function wpultra_elinspect_is_token_type(string $type): bool {
    return in_array($type, WPULTRA_ELINSPECT_TOKEN_TYPES, true);
}

/**
 * A prop's `value` is a "nested compound" (background, dimensions, ...) worth recursing into only
 * when it is a non-empty, string-keyed array where every entry is itself a `{$$type, ...}` node.
 * A plain list (e.g. `classes` => ['e-gc-x','e-gc-y']) or a scalar-leaf compound (`size` =>
 * {size,unit}) does not qualify and is treated as a single hardcoded leaf instead.
 */
function wpultra_elinspect_is_nested_map($value): bool {
    if (!is_array($value) || $value === []) { return false; }
    foreach ($value as $k => $v) {
        if (!is_string($k)) { return false; }
        if (!is_array($v) || !array_key_exists('$$type', $v)) { return false; }
    }
    return true;
}

/**
 * Walk an atomic props map (an element's own `settings`, or a global class variant's `props`) and
 * flatten it into a list of `{prop, value, is_token, token_id}` leaves.
 *
 * - `{$$type: 'global-color-variable'|'global-font-variable'|'global-size-variable', value: 'e-gv-x'}`
 *   is a TOKEN: `is_token = true`, `token_id = 'e-gv-x'`, `value` is that same id.
 * - Any other `{$$type, value}` shape is HARDCODED: `is_token = false`, `value` = the scalar/array
 *   as stored (a compound whose sub-entries are not themselves `$$type` nodes, e.g. `{size,unit}`,
 *   is kept intact rather than decomposed further).
 * - A nested compound (every `value` entry is itself a `{$$type,...}` node, e.g. background,
 *   dimensions) is recursed into, extending `prop` with a dotted path (`background.color`,
 *   `padding.top`).
 * - A malformed entry (not an array, or no `$$type`) falls through as a hardcoded leaf carrying
 *   the raw value untouched — inspection must never fatal on unexpected shapes.
 *
 * @param array  $props  prop-name => atomic-node map.
 * @param string $prefix internal recursion prefix; callers pass ''.
 */
function wpultra_elinspect_flatten_props(array $props, string $prefix = ''): array {
    $out = [];
    foreach ($props as $key => $node) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (!is_array($node)) {
            $out[] = ['prop' => $path, 'value' => $node, 'is_token' => false, 'token_id' => null];
            continue;
        }

        $type = isset($node['$$type']) && is_string($node['$$type']) ? $node['$$type'] : '';
        $value = array_key_exists('value', $node) ? $node['value'] : null;

        if ($type !== '' && wpultra_elinspect_is_token_type($type)) {
            $token_id = is_string($value) ? $value : null;
            $out[] = ['prop' => $path, 'value' => $value, 'is_token' => true, 'token_id' => $token_id];
            continue;
        }

        if (wpultra_elinspect_is_nested_map($value)) {
            $out = array_merge($out, wpultra_elinspect_flatten_props($value, $path));
            continue;
        }

        $out[] = ['prop' => $path, 'value' => $value, 'is_token' => false, 'token_id' => null];
    }
    return $out;
}

/**
 * Look up a `e-gv-...` token id in a `{token_id => concrete scalar value}` index. Returns null on a
 * miss, an empty token id, or when the indexed value is not a scalar (defensive — never fatals).
 */
function wpultra_elinspect_resolve_token(string $token_id, array $var_index): ?string {
    if ($token_id === '' || !array_key_exists($token_id, $var_index)) { return null; }
    $v = $var_index[$token_id];
    return is_scalar($v) ? (string) $v : null;
}

/** Tally token vs hardcoded entries from a flattened prop list — the token-consistency signal. */
function wpultra_elinspect_count(array $flattened): array {
    $hardcoded = 0;
    $token = 0;
    foreach ($flattened as $item) {
        if (!empty($item['is_token'])) { $token++; } else { $hardcoded++; }
    }
    return ['hardcoded_count' => $hardcoded, 'token_count' => $token];
}

/* ------------------------------------------------------------------ *
 * WP-touching orchestration below. Thin; every Elementor/WP call is guarded by function_exists()
 * or wrapped in try/catch so a missing engine/experiment degrades gracefully instead of fataling.
 * ------------------------------------------------------------------ */

/**
 * Normalize whatever shape `wpultra_el_variables_list()` returns into two flat indices:
 * `values` ({token_id => concrete scalar value}, the shape `wpultra_elinspect_resolve_token`
 * expects) and `types` ({token_id => variable type string}, best-effort, for `variables_used`).
 * Tolerates a few plausible Elementor Variables_Service record shapes without assuming one.
 *
 * NOTE (unverified guess, not a fixed contract): this key-fallback parsing (`id`/`_id`, nested
 * `value`/`color`/`size`) has not been confirmed against a real Elementor Variables_Service
 * response — it is a best-effort shape guess. If a real response uses a shape this function
 * doesn't recognize, every item is silently skipped and `values`/`types` come back empty rather
 * than throwing. `wpultra_elinspect_run()` below surfaces that specific failure mode via a
 * `notes` entry ("variable resolution unavailable...") rather than degrading silently.
 */
function wpultra_elinspect_index_variables(array $variables_list): array {
    $values = [];
    $types = [];
    foreach ($variables_list as $k => $item) {
        if (!is_array($item)) { continue; }
        $id = (string) ($item['id'] ?? $item['_id'] ?? (is_string($k) ? $k : ''));
        if ($id === '') { continue; }
        $val = $item['value'] ?? null;
        if (is_array($val)) { $val = $val['value'] ?? $val['color'] ?? $val['size'] ?? null; }
        if (is_scalar($val)) { $values[$id] = $val; }
        $type = $item['type'] ?? $item['variable_type'] ?? null;
        if (is_string($type) && $type !== '') { $types[$id] = $type; }
    }
    return ['values' => $values, 'types' => $types];
}

/** Re-key a flattened prop list into the `{prop => {value, source, is_token, token_id?, resolved?}}` output shape. */
function wpultra_elinspect_props_to_output(array $flattened, string $source, array $var_index, bool $resolve): array {
    $out = [];
    foreach ($flattened as $item) {
        $entry = ['value' => $item['value'], 'source' => $source, 'is_token' => $item['is_token']];
        if ($item['is_token']) {
            $entry['token_id'] = $item['token_id'];
            if ($resolve && $item['token_id'] !== null) {
                $resolved = wpultra_elinspect_resolve_token($item['token_id'], $var_index);
                if ($resolved !== null) { $entry['resolved'] = $resolved; }
            }
        }
        $out[$item['prop']] = $entry;
    }
    return $out;
}

/** Load a global class's stored record (id/label/variants) via the Global Classes repo, or null if unavailable/missing. */
function wpultra_elinspect_load_class(string $class_id): ?array {
    if (!function_exists('wpultra_el_gc_repo')) { return null; }
    $repo = wpultra_el_gc_repo();
    if (!$repo) { return null; }
    try {
        $items = $repo->all()->get_items()->all();
    } catch (\Throwable $e) { return null; }
    return isset($items[$class_id]) && is_array($items[$class_id]) ? $items[$class_id] : null;
}

/**
 * Best-effort read of the compiled local Elementor CSS file for a post (the path
 * elementor-render-check reports on, per validate.php: uploads/elementor/css/local-<post>-frontend-desktop.css).
 * Returns null (never an error) when Elementor/uploads are unavailable or the file does not exist —
 * the generated CSS is a nice-to-have excerpt, not a requirement.
 */
function wpultra_elinspect_read_compiled_css(int $post_id, int $max_len = 4000): ?string {
    if (!function_exists('wp_upload_dir')) { return null; }
    try {
        $dir = wp_upload_dir();
        $base = rtrim((string) ($dir['basedir'] ?? ''), '/\\');
        if ($base === '') { return null; }
        $path = $base . '/elementor/css/local-' . $post_id . '-frontend-desktop.css';
        if (!is_readable($path)) { return null; }
        $css = (string) file_get_contents($path);
        return $css === '' ? null : substr($css, 0, $max_len);
    } catch (\Throwable $e) { return null; }
}

/**
 * Full read-only inspection for one element: own settings + applied global classes' variants +
 * kit variable refs resolved to concrete values, with a token-vs-hardcoded tally. Returns
 * wpultra_ok(...) on success or a WP_Error (wpultra_err) on bad input / missing element.
 */
function wpultra_elinspect_run(int $post_id, string $element_id, bool $resolve_variables) {
    if ($post_id <= 0 || !function_exists('get_post') || !get_post($post_id)) {
        return wpultra_err('bad_post', 'Valid post_id required.');
    }
    if ($element_id === '') {
        return wpultra_err('bad_input', 'element_id is required.');
    }
    if (!function_exists('wpultra_el_raw') || !function_exists('wpultra_el_find')) {
        return wpultra_err('engine_unavailable', 'The Elementor tree engine is not loaded.');
    }

    $data = wpultra_el_raw($post_id);
    $node = wpultra_el_find($data, $element_id);
    if ($node === null) { return wpultra_err('element_not_found', "No element '$element_id'."); }

    $notes = [];

    $var_index = [];
    $var_types = [];
    if ($resolve_variables && function_exists('wpultra_el_variables_list')) {
        $list = wpultra_el_variables_list();
        if (!is_wp_error($list) && is_array($list)) {
            $indexed = wpultra_elinspect_index_variables($list);
            $var_index = $indexed['values'];
            $var_types = $indexed['types'];
        }
    }

    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    $own_flat = wpultra_elinspect_flatten_props($settings);
    // Cast to object: an associative map that may legitimately be empty (no own settings) must
    // serialize as JSON `{}` per the `own_settings: object` schema, never `[]` (see the same
    // (object)-at-return-site convention in field-read-values.php:58, register-cpt.php:50).
    $own_settings = (object) wpultra_elinspect_props_to_output($own_flat, 'settings', $var_index, $resolve_variables);

    $class_ids = [];
    if (isset($settings['classes']['value']) && is_array($settings['classes']['value'])) {
        $class_ids = array_values(array_filter($settings['classes']['value'], 'is_string'));
    }

    $applied_classes = [];
    $all_flat = $own_flat;
    foreach ($class_ids as $cid) {
        $record = wpultra_elinspect_load_class($cid);
        if ($record === null) { continue; }
        $variants_out = [];
        foreach ((array) ($record['variants'] ?? []) as $variant) {
            if (!is_array($variant)) { continue; }
            $props = is_array($variant['props'] ?? null) ? $variant['props'] : [];
            $flat = wpultra_elinspect_flatten_props($props);
            $all_flat = array_merge($all_flat, $flat);
            $variants_out[] = [
                'meta'  => is_array($variant['meta'] ?? null) ? $variant['meta'] : ['state' => null, 'breakpoint' => null],
                // Cast to object: a variant with zero props must serialize as `{}`, not `[]` —
                // same empty-map convention as $own_settings above.
                'props' => (object) wpultra_elinspect_props_to_output($flat, 'class', $var_index, $resolve_variables),
            ];
        }
        $applied_classes[] = ['id' => $cid, 'label' => (string) ($record['label'] ?? $cid), 'variants' => $variants_out];
    }

    // wpultra_elinspect_index_variables() is a best-effort guess at wpultra_el_variables_list()'s
    // record shape (not verified against a live Elementor Variables_Service response — see its
    // docblock above). If resolution was requested and there are token props to resolve, but the
    // index came back empty, that guess may have silently failed to parse a real (differently
    // shaped) response — surface it instead of degrading silently to unresolved tokens.
    $has_token_props = false;
    foreach ($all_flat as $item) {
        if (!empty($item['is_token'])) { $has_token_props = true; break; }
    }
    if ($resolve_variables && $has_token_props && $var_index === []) {
        $notes[] = 'variable resolution unavailable — could not read kit variables.';
    }

    $variables_used = [];
    $seen = [];
    foreach ($all_flat as $item) {
        if (!$item['is_token'] || $item['token_id'] === null || isset($seen[$item['token_id']])) { continue; }
        $seen[$item['token_id']] = true;
        $resolved = $resolve_variables ? wpultra_elinspect_resolve_token($item['token_id'], $var_index) : null;
        $variables_used[] = [
            'id'             => $item['token_id'],
            'type'           => $var_types[$item['token_id']] ?? null,
            'resolved_value' => $resolved,
        ];
    }

    $element = [
        'id'   => (string) ($node['id'] ?? $element_id),
        'type' => (string) ($node['elType'] ?? ''),
    ];
    if (!empty($node['widgetType'])) { $element['widgetType'] = (string) $node['widgetType']; }
    foreach ($own_flat as $item) {
        if ($item['prop'] === 'tag' && is_string($item['value'])) { $element['tag'] = $item['value']; break; }
    }

    $result = [
        'element'         => $element,
        'own_settings'    => $own_settings,
        'applied_classes' => $applied_classes,
        'variables_used'  => $variables_used,
        'flags'           => wpultra_elinspect_count($all_flat),
    ];

    $css = wpultra_elinspect_read_compiled_css($post_id);
    if ($css !== null) {
        $result['compiled_css_excerpt'] = $css;
    } else {
        $notes[] = 'No compiled local CSS file found for this post (Elementor may not have regenerated it yet); compiled_css_excerpt omitted.';
    }

    if ($notes !== []) {
        $result['notes'] = $notes;
    }

    return wpultra_ok($result);
}
