<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Reuse the global-classes repo/id/active-check helpers from classes.php rather than
// duplicating them. Guarded so requiring this file twice (or after classes.php has
// already been loaded by the bootstrap loader) never redeclares anything.
if (!function_exists('wpultra_el_gc_repo')) {
    require_once __DIR__ . '/classes.php';
}

/** The friendly state names this ability accepts (besides the implicit base state). Pure. */
function wpultra_el_variant_valid_states(): array {
    return ['normal', 'hover', 'focus', 'active'];
}

/**
 * Normalize a friendly state name to Elementor's meta.state value.
 * 'normal' (or empty) -> null (the base state). Anything else is lower-cased/trimmed and
 * passed through as-is. Pure — does NOT reject unknown input; callers validate against
 * wpultra_el_variant_valid_states() first (see wpultra_el_variant_meta()).
 */
function wpultra_el_variant_state_norm(string $state): ?string {
    $s = strtolower(trim($state));
    return ($s === '' || $s === 'normal') ? null : $s;
}

/**
 * Normalize a friendly breakpoint name to Elementor's meta.breakpoint value.
 * 'desktop' (or empty) -> null (the base breakpoint). Any other value must be one of the
 * site's currently active breakpoint keys (e.g. mobile, tablet, mobile_extra, ...);
 * unknown values are rejected with a WP_Error listing the valid options. Pure.
 *
 * @param array $active_breakpoint_keys Elementor's currently active breakpoint keys.
 * @return string|null|WP_Error
 */
function wpultra_el_variant_breakpoint_norm(string $breakpoint, array $active_breakpoint_keys) {
    $bp = strtolower(trim($breakpoint));
    if ($bp === '' || $bp === 'desktop') { return null; }
    if (in_array($bp, $active_breakpoint_keys, true)) { return $bp; }
    $valid = array_merge(['desktop'], array_values($active_breakpoint_keys));
    return wpultra_err('invalid_breakpoint', "Unknown breakpoint '$breakpoint'. Valid options: " . implode(', ', $valid));
}

/**
 * Resolve friendly breakpoint+state names into a variant meta array {state, breakpoint},
 * validating both against their known/active option sets. 'desktop'+'normal' (or empty
 * strings, which default to those) resolve to the base meta {null, null}. Pure.
 *
 * @return array{state:?string,breakpoint:?string}|WP_Error
 */
function wpultra_el_variant_meta(string $breakpoint_friendly, string $state_friendly, array $active_breakpoint_keys) {
    $state_raw = strtolower(trim($state_friendly));
    if ($state_raw === '') { $state_raw = 'normal'; }
    if (!in_array($state_raw, wpultra_el_variant_valid_states(), true)) {
        return wpultra_err('invalid_state', "Unknown state '$state_friendly'. Valid states: " . implode(', ', wpultra_el_variant_valid_states()));
    }

    $breakpoint = wpultra_el_variant_breakpoint_norm($breakpoint_friendly, $active_breakpoint_keys);
    if (is_wp_error($breakpoint)) { return $breakpoint; }

    return ['state' => wpultra_el_variant_state_norm($state_raw), 'breakpoint' => $breakpoint];
}

/**
 * The pure variant-array merge — the heart of this ability. Given a global class's
 * existing `variants` array, find the entry whose meta deep-equals $meta ({state,
 * breakpoint}, both nullable) and either update its props in place, append a new variant
 * if none matches, or (when $remove is true) delete the matching one.
 *
 * The base variant ({state: null, breakpoint: null}) is never removed by this function —
 * a remove request targeting it is a silent no-op here. Ability-level callers should
 * refuse that request up front with a clear user-facing error (see
 * wpultra_el_variant_upsert()); this is a defensive second line, not the primary guard.
 *
 * Never touches any variant other than the one matching $meta. Pure.
 */
function wpultra_el_variant_merge(array $variants, array $meta, array $props, bool $remove): array {
    $target_state = $meta['state'] ?? null;
    $target_bp = $meta['breakpoint'] ?? null;
    $is_base = ($target_state === null && $target_bp === null);

    $idx = null;
    foreach ($variants as $i => $v) {
        $vmeta = is_array($v['meta'] ?? null) ? $v['meta'] : [];
        $vstate = $vmeta['state'] ?? null;
        $vbp = $vmeta['breakpoint'] ?? null;
        if ($vstate === $target_state && $vbp === $target_bp) { $idx = $i; break; }
    }

    if ($remove) {
        if ($is_base || $idx === null) { return array_values($variants); }
        $out = $variants;
        array_splice($out, $idx, 1);
        return array_values($out);
    }

    if ($idx !== null) {
        $variants[$idx]['props'] = $props;
        return array_values($variants);
    }

    $variants[] = ['meta' => ['state' => $target_state, 'breakpoint' => $target_bp], 'props' => $props];
    return array_values($variants);
}

/** Elementor's currently active breakpoint keys (mobile, tablet, mobile_extra, ...), or [] if unavailable. */
function wpultra_el_active_breakpoint_keys(): array {
    if (!class_exists('\\Elementor\\Plugin')) { return []; }
    try {
        $instance = \Elementor\Plugin::$instance;
        if (!isset($instance->breakpoints)) { return []; }
        $active = $instance->breakpoints->get_active_breakpoints();
        return array_keys((array) $active);
    } catch (\Throwable $e) { return []; }
}

/**
 * Create-or-update a global class's variant list. Loads the class (or starts a new one
 * when $id is null), merges {$meta,$props} into its `variants` array via
 * wpultra_el_variant_merge(), writes the class repo back, and clears Elementor's CSS
 * cache so the change actually renders.
 *
 * @return array|WP_Error
 */
function wpultra_el_variant_upsert(?string $id, string $label, array $meta, array $props, bool $remove) {
    if (!wpultra_el_classes_active()) {
        return wpultra_err('classes_inactive', 'The Elementor "e_classes" experiment is not active. Call this ability with enable=true, or enable it in Elementor > Settings > Features.');
    }
    $repo = wpultra_el_gc_repo();
    if (!$repo) { return wpultra_err('classes_unavailable', 'Could not load the Global Classes repository.'); }

    $is_base = (($meta['state'] ?? null) === null && ($meta['breakpoint'] ?? null) === null);
    if ($remove && $is_base) {
        return wpultra_err('base_remove_refused', 'Refusing to remove the base variant (breakpoint: desktop, state: normal); update its props instead of removing it.');
    }

    try {
        $all = $repo->all();
        $items = $all->get_items()->all();
        $order = $all->get_order()->all();

        if ($id === null || $id === '') {
            if ($remove) { return wpultra_err('missing_class_id', 'class_id is required when remove is true.'); }
            $cid = wpultra_el_gc_id();
            $items[$cid] = [
                'id' => $cid,
                'label' => $label !== '' ? $label : $cid,
                'type' => 'class',
                'variants' => wpultra_el_variant_merge([], $meta, $props, false),
            ];
            if (!in_array($cid, $order, true)) { $order[] = $cid; }
        } else {
            $cid = $id;
            if (!isset($items[$cid])) {
                return wpultra_err('class_not_found', "No global class '$cid'. Omit class_id to create a new one, or check elementor-list-global-classes.");
            }
            $existing = is_array($items[$cid]['variants'] ?? null) ? $items[$cid]['variants'] : [];
            $items[$cid]['variants'] = wpultra_el_variant_merge($existing, $meta, $props, $remove);
            if ($label !== '') { $items[$cid]['label'] = $label; }
            if (!in_array($cid, $order, true)) { $order[] = $cid; }
        }

        $repo->put($items, $order);
        // Regenerate front-end CSS so the new/updated variant actually renders.
        if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            try { \Elementor\Plugin::$instance->files_manager->clear_cache(); } catch (\Throwable $e) {}
        }
        return wpultra_ok([
            'id' => $cid,
            'label' => $items[$cid]['label'],
            'variants' => $items[$cid]['variants'],
        ]);
    } catch (\Throwable $e) {
        return wpultra_err('variant_upsert_failed', $e->getMessage());
    }
}
