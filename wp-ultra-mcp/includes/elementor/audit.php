<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/*
 * design-audit engine (Roadmap-4 Wave PP2, task PP2-B): page-wide token-consistency + off-scale
 * spacing + contrast report for an Elementor page. The "why does it look almost right?" detector,
 * one level up from inspect-element (which reads ONE element) — this walks the WHOLE tree.
 *
 * Pure functions (unit-tested in tests/design-audit.test.php, no WP calls):
 *   wpultra_audit_hex_to_rgb, wpultra_audit_relative_luminance, wpultra_audit_contrast_ratio,
 *   wpultra_audit_off_scale, wpultra_audit_tally, wpultra_audit_flatten_props,
 *   wpultra_audit_category, wpultra_audit_hex_from_value, wpultra_audit_walk_all,
 *   wpultra_audit_normalize_scale, wpultra_audit_recommendations, wpultra_audit_build_report.
 * This flattener is deliberately its OWN implementation (not a dependency on the PP1-E
 * inspect-element engine's wpultra_elinspect_flatten_props) — see the task brief.
 * Everything below the pure section is thin WP-touching orchestration guarded by function_exists().
 */

/** `$$type` values that mark an atomic prop as a reference to a kit Variable rather than a literal. */
const WPULTRA_AUDIT_TOKEN_TYPES = ['global-color-variable', 'global-font-variable', 'global-size-variable'];

/** True when an atomic prop's `$$type` marks it as a token (kit Variable) reference. */
function wpultra_audit_is_token_type(string $type): bool {
    return in_array($type, WPULTRA_AUDIT_TOKEN_TYPES, true);
}

/**
 * A prop's `value` is a "nested compound" (background, dimensions, ...) worth recursing into only
 * when it is a non-empty, string-keyed array where every entry is itself a `{$$type, ...}` node.
 */
function wpultra_audit_is_nested_map($value): bool {
    if (!is_array($value) || $value === []) { return false; }
    foreach ($value as $k => $v) {
        if (!is_string($k)) { return false; }
        if (!is_array($v) || !array_key_exists('$$type', $v)) { return false; }
    }
    return true;
}

/**
 * Classic (non-atomic) Elementor "dimensions"/slider control shape:
 * `{unit, top?, right?, bottom?, left?, size?, isLinked?}` — a single shared unit applied across
 * one or more numeric sides. True only when a `unit` key AND at least one side/size key is present.
 */
function wpultra_audit_is_dimensions_control($value): bool {
    if (!is_array($value) || !array_key_exists('unit', $value)) { return false; }
    foreach (['top', 'right', 'bottom', 'left', 'size'] as $k) {
        if (array_key_exists($k, $value)) { return true; }
    }
    return false;
}

/**
 * Walk a props map (an element's own `settings`) and flatten it into a list of
 * `{prop, value, unit, is_token, token_id}` leaves. Own independent implementation (see file
 * docblock) covering BOTH atomic props (`{$$type,value}`, incl. the `{size,unit}` Size_Prop_Type
 * shape) and classic Elementor control values (incl. the shared-unit dimensions/slider shape).
 *
 * - `{$$type: 'global-*-variable', value: 'e-gv-x'}` is a TOKEN: `is_token=true`, `token_id='e-gv-x'`.
 * - `{$$type: 'size'|..., value: {size, unit}}` (atomic Size_Prop_Type) becomes one leaf carrying
 *   `value=size` (the number) and `unit` (the unit string) separately, so spacing off-scale checks
 *   can compare the numeric part against a px scale.
 * - A nested atomic compound (background, dimensions, ...) is recursed into, extending `prop` with
 *   a dotted path (`background.color`, `padding.top`).
 * - A classic dimensions/slider control (`{unit,top,right,bottom,left,isLinked}`) emits one leaf
 *   per populated side (`prop.top`, `prop.right`, ...), each carrying the shared `unit`. Empty-string
 *   sides (unset) are skipped.
 * - A plain sequential list (e.g. `classes => ['e-gc-x']`) is kept as a single hardcoded leaf.
 * - Any other associative sub-array without a recognized shape is recursed into generically so
 *   nested classic control groups still surface their scalar leaves.
 * - A malformed/unexpected shape falls through as a hardcoded leaf carrying the raw value —
 *   auditing must never fatal on unexpected input.
 *
 * @param array  $props  prop-name => value map.
 * @param string $prefix internal recursion prefix; callers pass ''.
 */
function wpultra_audit_flatten_props(array $props, string $prefix = ''): array {
    $out = [];
    foreach ($props as $key => $node) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (!is_array($node)) {
            $out[] = ['prop' => $path, 'value' => $node, 'unit' => null, 'is_token' => false, 'token_id' => null];
            continue;
        }

        $type = isset($node['$$type']) && is_string($node['$$type']) ? $node['$$type'] : '';
        if ($type !== '') {
            $value = array_key_exists('value', $node) ? $node['value'] : null;

            if (wpultra_audit_is_token_type($type)) {
                $token_id = is_string($value) ? $value : null;
                $out[] = ['prop' => $path, 'value' => $value, 'unit' => null, 'is_token' => true, 'token_id' => $token_id];
                continue;
            }

            // Atomic Size_Prop_Type shape: {size: number, unit: string} and nothing else.
            if (is_array($value) && array_key_exists('size', $value) && array_key_exists('unit', $value) && count($value) <= 2) {
                $out[] = [
                    'prop' => $path, 'value' => $value['size'],
                    'unit' => is_string($value['unit']) ? $value['unit'] : null,
                    'is_token' => false, 'token_id' => null,
                ];
                continue;
            }

            if (wpultra_audit_is_nested_map($value)) {
                $out = array_merge($out, wpultra_audit_flatten_props($value, $path));
                continue;
            }

            $out[] = ['prop' => $path, 'value' => $value, 'unit' => null, 'is_token' => false, 'token_id' => null];
            continue;
        }

        if (wpultra_audit_is_dimensions_control($node)) {
            $unit = is_string($node['unit'] ?? null) ? $node['unit'] : null;
            foreach (['top', 'right', 'bottom', 'left', 'size'] as $side) {
                if (!array_key_exists($side, $node)) { continue; }
                $v = $node[$side];
                if ($v === '' || $v === null) { continue; } // unset side, e.g. isLinked with blank fields
                $out[] = ['prop' => $path . '.' . $side, 'value' => $v, 'unit' => $unit, 'is_token' => false, 'token_id' => null];
            }
            continue;
        }

        if ($node === [] || array_is_list($node)) {
            $out[] = ['prop' => $path, 'value' => $node, 'unit' => null, 'is_token' => false, 'token_id' => null];
            continue;
        }

        // Generic associative sub-map without a recognized shape: recurse so nested classic
        // control groups (e.g. a typography settings bag) still surface their scalar leaves.
        $out = array_merge($out, wpultra_audit_flatten_props($node, $path));
    }
    return $out;
}

/** Infer a leaf's audit category from its (dotted) prop path. Order matters: 'color' is checked
 *  before 'font'/'typography' so e.g. 'title_color' never falls through to typography. */
function wpultra_audit_category(string $prop): string {
    $p = strtolower($prop);
    if (str_contains($p, 'color')) { return 'color'; }
    if (str_contains($p, 'font') || str_contains($p, 'typography')) { return 'typography'; }
    if (str_contains($p, 'margin') || str_contains($p, 'padding') || str_contains($p, 'gap')) { return 'spacing'; }
    return 'other';
}

/** Tally token vs hardcoded leaves, overall and per category (color/typography/spacing/other). */
function wpultra_audit_tally(array $flattened_props): array {
    $buckets = [
        'color'      => ['token' => 0, 'hardcoded' => 0],
        'typography' => ['token' => 0, 'hardcoded' => 0],
        'spacing'    => ['token' => 0, 'hardcoded' => 0],
        'other'      => ['token' => 0, 'hardcoded' => 0],
    ];
    $overall = ['token' => 0, 'hardcoded' => 0];
    foreach ($flattened_props as $item) {
        if (!is_array($item)) { continue; }
        $cat = wpultra_audit_category((string) ($item['prop'] ?? ''));
        if (!empty($item['is_token'])) {
            $buckets[$cat]['token']++;
            $overall['token']++;
        } else {
            $buckets[$cat]['hardcoded']++;
            $overall['hardcoded']++;
        }
    }
    return ['overall' => $overall] + $buckets;
}

/**
 * Parse a spacing leaf's `(value, unit)` into a plain px number, or null when it cannot be
 * resolved as an absolute px length — % and `auto` (and any other non-px unit: em, rem, vh, vw...)
 * are explicitly ignored per the brief, since the allowed scale is itself expressed in raw px.
 */
function wpultra_audit_parse_px($value, ?string $unit): ?float {
    if ($unit !== null) {
        return strtolower($unit) === 'px' && is_numeric($value) ? (float) $value : null;
    }
    if (is_int($value) || is_float($value)) { return (float) $value; }
    if (is_string($value)) {
        $v = trim($value);
        if (preg_match('/^-?\d+(\.\d+)?$/', $v)) { return (float) $v; }              // bare number
        if (preg_match('/^(-?\d+(\.\d+)?)px$/i', $v, $m)) { return (float) $m[1]; }  // explicit "16px"
        return null; // "auto", "50%", "1em", "2rem", ... — ignored, not a px length
    }
    return null;
}

/**
 * Which of `$spacing_values` (each `{element_id?, prop, value, unit?}`) fall OFF the allowed px
 * scale. Non-px-resolvable entries (%, auto, em, ...) are silently skipped, not reported as
 * off-scale — they are simply outside this scale's vocabulary. Float-compared with a small
 * epsilon to tolerate values that arrived via JSON/string round-tripping.
 *
 * @return array<int, array{element_id: string, prop: string, value: float}>
 */
function wpultra_audit_off_scale(array $spacing_values, array $scale): array {
    $allowed = array_map('floatval', array_filter($scale, 'is_numeric'));
    $out = [];
    foreach ($spacing_values as $item) {
        if (!is_array($item)) { continue; }
        $unit = array_key_exists('unit', $item) && is_string($item['unit']) ? $item['unit'] : null;
        $px = wpultra_audit_parse_px($item['value'] ?? null, $unit);
        if ($px === null) { continue; }
        $on_scale = false;
        foreach ($allowed as $s) {
            if (abs($s - $px) < 0.001) { $on_scale = true; break; }
        }
        if ($on_scale) { continue; }
        $out[] = ['element_id' => (string) ($item['element_id'] ?? ''), 'prop' => (string) ($item['prop'] ?? ''), 'value' => $px];
    }
    return $out;
}

/** #rgb / #rrggbb (with or without leading '#', case-insensitive) → ['r'=>int,'g'=>int,'b'=>int] in 0-255, or null when unparseable. */
function wpultra_audit_hex_to_rgb(string $hex): ?array {
    $h = trim($hex);
    if ($h === '') { return null; }
    if ($h[0] === '#') { $h = substr($h, 1); }
    if (!preg_match('/^[0-9a-fA-F]+$/', $h)) { return null; }
    if (strlen($h) === 3) {
        return ['r' => hexdec($h[0] . $h[0]), 'g' => hexdec($h[1] . $h[1]), 'b' => hexdec($h[2] . $h[2])];
    }
    if (strlen($h) === 6) {
        return ['r' => hexdec(substr($h, 0, 2)), 'g' => hexdec(substr($h, 2, 2)), 'b' => hexdec(substr($h, 4, 2))];
    }
    return null; // #rgba/#rrggbbaa and anything else are not handled — treated as unresolvable
}

/** Best-effort normalize a raw color prop value to a canonical `#RRGGBB` hex string for dedupe/contrast use, or null if it is not (or cannot be reduced to) a hex color — e.g. rgba(...)/named CSS colors are left unresolved rather than guessed at. */
function wpultra_audit_hex_from_value($value): ?string {
    if (!is_string($value)) { return null; }
    $rgb = wpultra_audit_hex_to_rgb($value);
    if ($rgb === null) { return null; }
    return sprintf('#%02X%02X%02X', $rgb['r'], $rgb['g'], $rgb['b']);
}

/** WCAG relative luminance of an sRGB color: https://www.w3.org/TR/WCAG21/#dfn-relative-luminance */
function wpultra_audit_relative_luminance(array $rgb): float {
    $chan = static function ($c): float {
        $c = max(0.0, min(255.0, (float) $c)) / 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    $r = $chan($rgb['r'] ?? 0);
    $g = $chan($rgb['g'] ?? 0);
    $b = $chan($rgb['b'] ?? 0);
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/** WCAG contrast ratio between two colors: (L_lighter + 0.05) / (L_darker + 0.05). Order of $fg/$bg does not matter — the lighter/darker pair is resolved internally. Range: 1.0 (identical) .. 21.0 (black vs white). */
function wpultra_audit_contrast_ratio(array $fg, array $bg): float {
    $l1 = wpultra_audit_relative_luminance($fg);
    $l2 = wpultra_audit_relative_luminance($bg);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

/** Sanitize a user-supplied spacing scale to a non-empty list of floats, falling back to the plugin default when empty/entirely non-numeric. */
function wpultra_audit_normalize_scale(array $scale): array {
    $out = [];
    foreach ($scale as $v) { if (is_numeric($v)) { $out[] = (float) $v; } }
    return $out !== [] ? $out : [0.0, 4.0, 8.0, 12.0, 16.0, 24.0, 32.0, 48.0, 64.0, 96.0];
}

/** Plain-English callouts derived from the tallied numbers — a punch list, not raw data restated. */
function wpultra_audit_recommendations(float $hardcoded_pct, int $distinct_colors, array $off_scale, array $contrast_warnings): array {
    $out = [];
    if ($hardcoded_pct > 50.0) {
        $out[] = "Most styling ({$hardcoded_pct}% hardcoded) bypasses tokens — consider promoting frequently-reused values to global Variables for consistency.";
    }
    if ($distinct_colors > 5) {
        $out[] = "$distinct_colors distinct hardcoded colors are in use — consolidate into a smaller palette of global colors/variables.";
    }
    if ($off_scale !== []) {
        $out[] = count($off_scale) . ' spacing value(s) fall off the allowed design scale — snap them to the nearest allowed step.';
    }
    if ($contrast_warnings !== []) {
        $out[] = count($contrast_warnings) . ' text/background color pair(s) fail WCAG AA contrast (4.5:1) — darken the text or lighten the background.';
    }
    if ($out === []) { $out[] = 'No major consistency issues detected.'; }
    return $out;
}

/**
 * Aggregate a flat list of `{element_id, prop, value, unit, is_token, token_id}` leaves (gathered
 * across every element in the tree) into the ability's full output shape. Pure — no WP calls; the
 * caller (wpultra_audit_run) is responsible for collecting `$entries` and `$element_count` from a
 * live tree. Only the FIRST hardcoded text-color leaf and FIRST hardcoded background-color leaf per
 * element are used for that element's contrast pair (a widget legitimately setting several colors
 * of the same kind is not disambiguated further — the first of each is the representative pair).
 */
function wpultra_audit_build_report(array $entries, int $element_count, array $scale): array {
    $tally = wpultra_audit_tally($entries);
    $overall = $tally['overall'];
    $total = $overall['token'] + $overall['hardcoded'];
    $token_pct = $total > 0 ? round($overall['token'] / $total * 100, 1) : 0.0;
    $hardcoded_pct = $total > 0 ? round($overall['hardcoded'] / $total * 100, 1) : 0.0;

    $distinct_colors = [];
    $distinct_fonts = [];
    $distinct_sizes = [];
    $spacing_entries = [];
    $by_element = []; // element_id => ['fg' => ?hex, 'bg' => ?hex]

    foreach ($entries as $item) {
        if (!is_array($item)) { continue; }
        $prop = (string) ($item['prop'] ?? '');
        $is_token = !empty($item['is_token']);
        $cat = wpultra_audit_category($prop);
        $eid = (string) ($item['element_id'] ?? '');

        if ($cat === 'spacing' && !$is_token) {
            $spacing_entries[] = $item;
        }

        if ($cat === 'color' && !$is_token) {
            $hex = wpultra_audit_hex_from_value($item['value'] ?? null);
            if ($hex !== null) {
                $distinct_colors[$hex] = true;
                if (!isset($by_element[$eid])) { $by_element[$eid] = ['fg' => null, 'bg' => null]; }
                if (str_contains(strtolower($prop), 'background')) {
                    if ($by_element[$eid]['bg'] === null) { $by_element[$eid]['bg'] = $hex; }
                } elseif ($by_element[$eid]['fg'] === null) {
                    $by_element[$eid]['fg'] = $hex;
                }
            }
        }

        if ($cat === 'typography' && !$is_token) {
            $lp = strtolower($prop);
            $val = $item['value'] ?? null;
            if (str_contains($lp, 'family') && is_string($val) && trim($val) !== '') {
                $distinct_fonts[trim($val)] = true;
            }
            if (str_contains($lp, 'size') && is_scalar($val)) {
                $unit = $item['unit'] ?? null;
                $distinct_sizes[((string) $val) . (is_string($unit) ? $unit : '')] = true;
            }
        }
    }

    $off_scale = wpultra_audit_off_scale($spacing_entries, $scale);

    $contrast_warnings = [];
    foreach ($by_element as $eid => $pair) {
        if ($pair['fg'] === null || $pair['bg'] === null) { continue; }
        $fg_rgb = wpultra_audit_hex_to_rgb($pair['fg']);
        $bg_rgb = wpultra_audit_hex_to_rgb($pair['bg']);
        if ($fg_rgb === null || $bg_rgb === null) { continue; }
        $ratio = wpultra_audit_contrast_ratio($fg_rgb, $bg_rgb);
        if ($ratio < 4.5) {
            $contrast_warnings[] = ['element_id' => (string) $eid, 'fg' => $pair['fg'], 'bg' => $pair['bg'], 'ratio' => round($ratio, 2)];
        }
    }

    return [
        'summary' => [
            'elements'        => $element_count,
            'token_pct'       => $token_pct,
            'hardcoded_pct'   => $hardcoded_pct,
            'distinct_colors' => count($distinct_colors),
            'distinct_fonts'  => count($distinct_fonts),
            'distinct_sizes'  => count($distinct_sizes),
        ],
        'off_scale_spacing' => $off_scale,
        'contrast_warnings' => $contrast_warnings,
        'recommendations'   => wpultra_audit_recommendations($hardcoded_pct, count($distinct_colors), $off_scale, $contrast_warnings),
    ];
}

/** Depth-guarded pre-order visit of every node in an Elementor element tree. Pure — `$visit` is the only side-effecting part, and it is the caller's own closure. */
function wpultra_audit_walk_all(array $elements, callable $visit, int $depth = 0): void {
    if ($depth > 100) { return; } // guard against pathologically deep / cyclic data
    foreach ($elements as $n) {
        if (!is_array($n)) { continue; }
        $visit($n);
        if (!empty($n['elements']) && is_array($n['elements'])) {
            wpultra_audit_walk_all($n['elements'], $visit, $depth + 1);
        }
    }
}

/* ------------------------------------------------------------------ *
 * WP-touching orchestration below. Thin; every WP/Elementor call is guarded by function_exists()
 * so a missing engine degrades to a clear error instead of fataling.
 * ------------------------------------------------------------------ */

/**
 * Full read-only page audit: walk every element's own settings, flatten + tally + off-scale +
 * contrast, return wpultra_ok(...) on success or a WP_Error (wpultra_err) on bad input.
 */
function wpultra_audit_run(int $post_id, array $scale) {
    if ($post_id <= 0 || !function_exists('get_post') || !get_post($post_id)) {
        return wpultra_err('bad_post', 'Valid post_id required.');
    }
    if (!function_exists('wpultra_el_raw')) {
        return wpultra_err('engine_unavailable', 'The Elementor tree engine is not loaded.');
    }

    $scale = wpultra_audit_normalize_scale($scale);
    $data = wpultra_el_raw($post_id);

    $entries = [];
    $element_count = 0;
    wpultra_audit_walk_all($data, function (array $node) use (&$entries, &$element_count): void {
        $element_count++;
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        $eid = (string) ($node['id'] ?? '');
        foreach (wpultra_audit_flatten_props($settings) as $leaf) {
            $leaf['element_id'] = $eid;
            $entries[] = $leaf;
        }
    });

    return wpultra_ok(wpultra_audit_build_report($entries, $element_count, $scale));
}
