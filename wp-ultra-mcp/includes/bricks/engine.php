<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Bricks builder foundation — pure, testable engine functions plus thin WP-calling wrappers.
 * Content lives in postmeta '_bricks_page_content_2' as a FLAT array of elements:
 *   { id, name, parent, children: [ids...], settings }
 * (unlike Elementor's nested `elements` arrays, Bricks elements reference their parent by id
 * and list their own children ids — the tree must be reconstructed from these flat refs.)
 */

/** True when the Bricks theme/plugin is active on this site. */
function wpultra_bricks_active(): bool {
    if (defined('BRICKS_VERSION')) { return true; }
    if (function_exists('get_template')) {
        $tpl = (string) get_template();
        if ($tpl === 'bricks') { return true; }
    }
    if (function_exists('wp_get_theme')) {
        try {
            $theme = wp_get_theme();
            if (is_object($theme) && method_exists($theme, 'get') && (string) $theme->get('Name') === 'Bricks') { return true; }
        } catch (\Throwable $e) { /* ignore — best-effort detection only */ }
    }
    return false;
}

/** Installed Bricks version, or null when unknown/absent. */
function wpultra_bricks_version(): ?string {
    return defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : null;
}

/**
 * Post types Bricks editing is enabled for, read from the `bricks_global_settings` option's
 * `postTypes` key. Pure given the option array (testable without WordPress).
 * @param array $global_settings the raw option value (already fetched by the caller)
 */
function wpultra_bricks_enabled_post_types(array $global_settings): array {
    $pt = $global_settings['postTypes'] ?? null;
    if (!is_array($pt)) { return []; }
    return array_values(array_filter(array_map('strval', $pt), fn($v) => $v !== ''));
}

/** Status payload: installed/active, version, per-post-type enabled list. Thin WP wrapper. */
function wpultra_bricks_status(): array {
    $active = wpultra_bricks_active();
    $settings = [];
    if ($active && function_exists('get_option')) {
        $raw = get_option('bricks_global_settings', []);
        if (is_array($raw)) { $settings = $raw; }
    }
    return [
        'active'       => $active,
        'version'      => wpultra_bricks_version(),
        'post_types'   => $active ? wpultra_bricks_enabled_post_types($settings) : [],
    ];
}

/**
 * Shape the registered-elements list from \Bricks\Elements::$elements (name => class or config).
 * Pure given the raw registry array — testable with a fixture without the real class.
 * Each entry in $registry may be either a class name (string) or an array with 'label'/'category'.
 */
function wpultra_bricks_shape_elements(array $registry): array {
    $out = [];
    foreach ($registry as $name => $def) {
        $label = '';
        $category = '';
        if (is_array($def)) {
            $label = (string) ($def['label'] ?? $def['name'] ?? '');
            $category = (string) ($def['category'] ?? '');
        } elseif (is_string($def) && class_exists($def)) {
            try {
                $instance = new $def(null);
                if (method_exists($instance, 'get_label')) { $label = (string) $instance->get_label(); }
                if (method_exists($instance, 'get_category')) { $category = (string) $instance->get_category(); }
            } catch (\Throwable $e) { /* best-effort label/category only */ }
        }
        $out[] = [
            'name'     => (string) $name,
            'label'    => $label,
            'category' => $category,
        ];
    }
    return $out;
}

/** Registered Bricks element names + label/category when available. Thin WP wrapper. */
function wpultra_bricks_list_elements(): array {
    if (!class_exists('\\Bricks\\Elements')) { return []; }
    try {
        $registry = \Bricks\Elements::$elements ?? [];
        return wpultra_bricks_shape_elements(is_array($registry) ? $registry : []);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Build a nested compact tree from Bricks' flat element array. Pure, depth-guarded.
 * Each element: ['id'=>..,'name'=>..,'parent'=>id-or-'0'/'',children'=>[ids],'settings'=>[...]].
 * Root elements have parent === '' or '0' or 0 or missing.
 * Output nodes: {id, name, children:[...]} — mirrors elementor-get-content's compact-tree UX.
 */
function wpultra_bricks_build_tree(array $elements, int $max_depth = 100): array {
    $byId = [];
    foreach ($elements as $el) {
        if (!is_array($el) || !isset($el['id'])) { continue; }
        $byId[(string) $el['id']] = $el;
    }

    $isRoot = static function ($parent): bool {
        return $parent === null || $parent === '' || $parent === '0' || $parent === 0;
    };

    $build = function (array $el, int $depth) use (&$build, &$byId, $max_depth): array {
        $node = ['id' => (string) ($el['id'] ?? ''), 'name' => (string) ($el['name'] ?? '')];
        $childIds = is_array($el['children'] ?? null) ? $el['children'] : [];
        $children = [];
        if ($depth < $max_depth) {
            foreach ($childIds as $cid) {
                $cid = (string) $cid;
                if (isset($byId[$cid])) { $children[] = $build($byId[$cid], $depth + 1); }
            }
        }
        $node['children'] = $children;
        return $node;
    };

    $out = [];
    foreach ($elements as $el) {
        if (!is_array($el) || !isset($el['id'])) { continue; }
        if ($isRoot($el['parent'] ?? null)) { $out[] = $build($el, 0); }
    }
    return $out;
}

/**
 * Find a single element (raw, flat form) by id in the flat elements array. Pure.
 */
function wpultra_bricks_find(array $elements, string $id): ?array {
    foreach ($elements as $el) {
        if (is_array($el) && (string) ($el['id'] ?? '') === $id) { return $el; }
    }
    return null;
}

/**
 * Validate a flat Bricks elements array for set-content: every element must have id+name,
 * and every element's `parent` (when non-root) must reference an id that exists in the array.
 * Pure, testable — the test file's core.
 * @return array{ok:bool, errors:array<int,string>, count:int}
 */
function wpultra_bricks_validate_tree(array $elements): array {
    $errors = [];
    $ids = [];
    foreach ($elements as $i => $el) {
        if (!is_array($el)) { $errors[] = "Element at index $i is not an object."; continue; }
        $id = (string) ($el['id'] ?? '');
        if ($id === '') { $errors[] = "Element at index $i is missing an 'id'."; continue; }
        if (isset($ids[$id])) { $errors[] = "Duplicate element id '$id'."; continue; }
        $ids[$id] = true;
    }
    foreach ($elements as $i => $el) {
        if (!is_array($el)) { continue; }
        $id = (string) ($el['id'] ?? "index $i");
        if (!isset($el['name']) || (string) $el['name'] === '') {
            $errors[] = "Element '$id' is missing a 'name'.";
        }
        $parent = $el['parent'] ?? null;
        $isRoot = ($parent === null || $parent === '' || $parent === '0' || $parent === 0);
        if (!$isRoot) {
            $pid = (string) $parent;
            if (!isset($ids[$pid])) {
                $errors[] = "Element '$id' references parent '$pid' which does not exist.";
            }
        }
    }
    return ['ok' => empty($errors), 'errors' => $errors, 'count' => count($elements)];
}

/** Raw flat elements array from postmeta, or [] when absent/malformed. Thin WP wrapper. */
function wpultra_bricks_raw(int $post_id): array {
    $raw = get_post_meta($post_id, '_bricks_page_content_2', true);
    if (empty($raw)) { return []; }
    $data = is_string($raw) ? json_decode($raw, true) : $raw;
    return is_array($data) ? $data : [];
}

/**
 * Read Bricks content for a post: compact tree, or a single element drill-down.
 * @return array|WP_Error
 */
function wpultra_bricks_read(int $post_id, array $opts = []) {
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $elements = wpultra_bricks_raw($post_id);
    if (isset($opts['element_id']) && (string) $opts['element_id'] !== '') {
        $node = wpultra_bricks_find($elements, (string) $opts['element_id']);
        if ($node === null) { return wpultra_err('element_not_found', "No element '{$opts['element_id']}'."); }
        return wpultra_ok(['post_id' => $post_id, 'element' => $node]);
    }
    return wpultra_ok(['post_id' => $post_id, 'elements' => wpultra_bricks_build_tree($elements)]);
}

/**
 * Write a flat Bricks elements array to postmeta, set editor mode, clear cache when possible.
 * @return array|WP_Error
 */
function wpultra_bricks_write(int $post_id, array $elements) {
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $json = wp_json_encode($elements);
    if (!is_string($json)) { return wpultra_err('encode_failed', 'Element array could not be JSON-encoded; write aborted to avoid wiping the page.'); }
    update_post_meta($post_id, '_bricks_page_content_2', wp_slash($json));
    update_post_meta($post_id, '_bricks_editor_mode', 'bricks');
    try {
        if (class_exists('\\Bricks\\Assets') && method_exists('\\Bricks\\Assets', 'clear_cache')) {
            \Bricks\Assets::clear_cache();
        }
        clean_post_cache($post_id);
    } catch (\Throwable $e) { /* cache clear is best-effort */ }
    return wpultra_ok(['post_id' => $post_id, 'element_count' => count($elements)]);
}
