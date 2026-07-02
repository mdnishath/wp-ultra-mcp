<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Pure recursive deep-merge: $over wins on scalar conflicts; associative arrays merge
 * key-by-key; list arrays (sequential int keys starting at 0) are replaced wholesale
 * by $over (theme.json settings/styles use associative shape almost everywhere, but
 * some leaves — e.g. custom font-family "src" — are lists, and merging lists by index
 * would silently splice unrelated entries together). Testable without WordPress.
 */
function wpultra_fse_deep_merge(array $base, array $over): array {
    $is_list = static function (array $arr): bool {
        if ($arr === []) { return true; }
        return array_keys($arr) === range(0, count($arr) - 1);
    };
    // Empty arrays carry no shape information — treat them as "nothing to change/add"
    // rather than triggering the list-replacement rule below.
    if ($over === []) { return $base; }
    if ($base === []) { return $over; }
    // If either side is a non-empty plain list, don't attempt a key-wise merge (that would
    // splice sequential indices from two unrelated lists, or bolt list indices onto an
    // associative array) — the override list/value simply wins wholesale.
    if ($is_list($over) || $is_list($base)) {
        return $over;
    }
    $result = $base;
    foreach ($over as $key => $value) {
        if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
            $result[$key] = wpultra_fse_deep_merge($result[$key], $value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}

/** True when the site is running a block theme with FSE support available. Guarded. */
function wpultra_fse_block_theme_available(): bool {
    return function_exists('wp_is_block_theme') && wp_is_block_theme();
}

/** True when the theme.json resolver class we depend on exists. Guarded. */
function wpultra_fse_resolver_available(): bool {
    return class_exists('WP_Theme_JSON_Resolver');
}

/**
 * Read theme.json data for the requested layer. Returns array data or WP_Error.
 * $layer one of merged|theme|user.
 */
function wpultra_fse_theme_json_get(string $layer = 'merged') {
    if (!wpultra_fse_resolver_available()) {
        return wpultra_err('fse_unavailable', 'WP_Theme_JSON_Resolver is unavailable on this WordPress version.');
    }
    try {
        if ($layer === 'theme') {
            if (!method_exists('WP_Theme_JSON_Resolver', 'get_theme_data')) {
                return wpultra_err('fse_unavailable', 'Theme layer resolver method unavailable.');
            }
            $theme_json = WP_Theme_JSON_Resolver::get_theme_data();
            if (!is_object($theme_json) || !method_exists($theme_json, 'get_raw_data')) {
                return wpultra_err('fse_unavailable', 'Theme layer data unavailable.');
            }
            return ['layer' => 'theme', 'data' => $theme_json->get_raw_data()];
        }
        if ($layer === 'user') {
            if (!method_exists('WP_Theme_JSON_Resolver', 'get_user_data')) {
                return wpultra_err('fse_unavailable', 'User layer resolver method unavailable.');
            }
            $user_json = WP_Theme_JSON_Resolver::get_user_data();
            if (!is_object($user_json) || !method_exists($user_json, 'get_raw_data')) {
                return wpultra_err('fse_unavailable', 'User layer data unavailable.');
            }
            return ['layer' => 'user', 'data' => $user_json->get_raw_data()];
        }
        // merged (default)
        if (!method_exists('WP_Theme_JSON_Resolver', 'get_merged_data')) {
            return wpultra_err('fse_unavailable', 'Merged data resolver method unavailable.');
        }
        $merged = WP_Theme_JSON_Resolver::get_merged_data();
        if (!is_object($merged) || !method_exists($merged, 'get_raw_data')) {
            return wpultra_err('fse_unavailable', 'Merged theme.json data unavailable.');
        }
        $result = ['layer' => 'merged', 'data' => $merged->get_raw_data()];
        if (method_exists('WP_Theme_JSON_Resolver', 'get_user_data')) {
            $user_json = WP_Theme_JSON_Resolver::get_user_data();
            if (is_object($user_json) && method_exists($user_json, 'get_raw_data')) {
                $result['user'] = $user_json->get_raw_data();
            }
        }
        return $result;
    } catch (\Throwable $e) {
        return wpultra_err('fse_unavailable', 'Failed to read theme.json data: ' . $e->getMessage());
    }
}

/**
 * Deep-merge and persist $settings/$styles into the user global-styles CPT.
 * When $merge is false the provided sections replace the existing ones outright.
 */
function wpultra_fse_theme_json_set(array $settings, array $styles, bool $merge = true) {
    if (!wpultra_fse_resolver_available()) {
        return wpultra_err('fse_unavailable', 'WP_Theme_JSON_Resolver is unavailable on this WordPress version.');
    }
    if (!method_exists('WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id')) {
        return wpultra_err('fse_unavailable', 'User global styles post id resolver unavailable.');
    }
    if (!function_exists('get_post') || !function_exists('wp_update_post')) {
        return wpultra_err('fse_unavailable', 'Required post functions unavailable.');
    }
    try {
        $post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        if (!$post_id) {
            return wpultra_err('fse_unavailable', 'Could not resolve or create the global styles post.');
        }
        $post = get_post($post_id);
        if (!$post) {
            return wpultra_err('fse_unavailable', "Global styles post $post_id not found.");
        }
        $existing = [];
        $raw = (string) $post->post_content;
        // Snapshot the raw user global-styles JSON for undo before merging/writing.
        if (function_exists('wpultra_undo_capture')) {
            wpultra_undo_capture('theme_json', (string) $post_id, $raw, 'Global styles (theme.json) change');
        }
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) { $existing = $decoded; }
        }
        if (!isset($existing['version'])) {
            $existing['version'] = 2;
        }

        $new_settings = $settings;
        $new_styles = $styles;
        if ($merge) {
            $existing_settings = is_array($existing['settings'] ?? null) ? $existing['settings'] : [];
            $existing_styles = is_array($existing['styles'] ?? null) ? $existing['styles'] : [];
            $new_settings = wpultra_fse_deep_merge($existing_settings, $settings);
            $new_styles = wpultra_fse_deep_merge($existing_styles, $styles);
        }

        if ($settings !== []) { $existing['settings'] = $new_settings; }
        if ($styles !== []) { $existing['styles'] = $new_styles; }

        $encoded = function_exists('wp_json_encode') ? wp_json_encode($existing) : json_encode($existing);
        if ($encoded === false || $encoded === null) {
            return wpultra_err('encode_failed', 'Failed to encode global styles JSON.');
        }

        $res = wp_update_post([
            'ID'           => $post_id,
            'post_content' => function_exists('wp_slash') ? wp_slash($encoded) : $encoded,
        ], true);
        if (is_wp_error($res)) { return $res; }

        if (class_exists('WP_Theme_JSON_Resolver') && method_exists('WP_Theme_JSON_Resolver', 'clean_cached_data')) {
            WP_Theme_JSON_Resolver::clean_cached_data();
        }

        return ['post_id' => (int) $post_id, 'settings' => $existing['settings'] ?? [], 'styles' => $existing['styles'] ?? []];
    } catch (\Throwable $e) {
        return wpultra_err('fse_unavailable', 'Failed to write theme.json user data: ' . $e->getMessage());
    }
}

/** List block templates/template-parts. $type one of wp_template|wp_template_part. */
function wpultra_fse_template_list(string $type = 'wp_template') {
    if (!wpultra_fse_block_theme_available()) {
        return wpultra_err('fse_unavailable', 'Active theme is not a block theme.');
    }
    if (!function_exists('get_block_templates')) {
        return wpultra_err('fse_unavailable', 'get_block_templates() unavailable.');
    }
    try {
        $templates = get_block_templates([], $type);
        $out = [];
        foreach ((array) $templates as $tpl) {
            $out[] = [
                'slug'     => (string) ($tpl->slug ?? ''),
                'title'    => (string) ($tpl->title ?? ''),
                'source'   => (string) ($tpl->source ?? ''),
                'modified' => (string) ($tpl->modified ?? ''),
            ];
        }
        return ['templates' => $out];
    } catch (\Throwable $e) {
        return wpultra_err('fse_unavailable', 'Failed to list templates: ' . $e->getMessage());
    }
}

/** Fetch a single template/template-part by slug. */
function wpultra_fse_template_get(string $slug, string $type = 'wp_template') {
    if (!wpultra_fse_block_theme_available()) {
        return wpultra_err('fse_unavailable', 'Active theme is not a block theme.');
    }
    if (!function_exists('get_block_template')) {
        return wpultra_err('fse_unavailable', 'get_block_template() unavailable.');
    }
    try {
        $stylesheet = function_exists('get_stylesheet') ? get_stylesheet() : '';
        $id = $stylesheet !== '' ? "$stylesheet//$slug" : $slug;
        $tpl = get_block_template($id, $type);
        if (!$tpl) {
            return wpultra_err('template_not_found', "Template '$slug' not found.");
        }
        return [
            'slug'    => (string) ($tpl->slug ?? ''),
            'title'   => (string) ($tpl->title ?? ''),
            'source'  => (string) ($tpl->source ?? ''),
            'content' => (string) ($tpl->content ?? ''),
        ];
    } catch (\Throwable $e) {
        return wpultra_err('fse_unavailable', 'Failed to load template: ' . $e->getMessage());
    }
}

/** Create or update a custom template/template-part CPT with the given content/title. */
function wpultra_fse_template_upsert(string $slug, string $type, string $content, string $title, string $area = '') {
    if (!wpultra_fse_block_theme_available()) {
        return wpultra_err('fse_unavailable', 'Active theme is not a block theme.');
    }
    if (!function_exists('wp_insert_post') || !function_exists('get_page_by_path')) {
        return wpultra_err('fse_unavailable', 'Required post functions unavailable.');
    }
    try {
        $existing = get_page_by_path($slug, OBJECT, $type);
        $postarr = [
            'post_name'    => function_exists('sanitize_title') ? sanitize_title($slug) : $slug,
            'post_title'   => $title !== '' ? $title : $slug,
            'post_content' => function_exists('wp_slash') ? wp_slash($content) : $content,
            'post_status'  => 'publish',
            'post_type'    => $type,
        ];
        if ($existing) {
            $postarr['ID'] = (int) $existing->ID;
        }
        $id = wp_insert_post($postarr, true);
        if (is_wp_error($id)) { return $id; }
        $id = (int) $id;

        // WP resolves a custom template/part only when it carries the wp_theme term naming the
        // active stylesheet (and, for parts, a wp_template_part_area term). Without these the CPT
        // is a silent no-op: it never renders, never appears in list, and get returns not_found.
        if (function_exists('wp_set_post_terms') && function_exists('taxonomy_exists') && function_exists('get_stylesheet') && taxonomy_exists('wp_theme')) {
            wp_set_post_terms($id, [get_stylesheet()], 'wp_theme');
        }
        if ($type === 'wp_template_part' && function_exists('wp_set_post_terms') && function_exists('taxonomy_exists') && taxonomy_exists('wp_template_part_area')) {
            $area = $area !== '' ? $area : 'uncategorized';
            wp_set_post_terms($id, [$area], 'wp_template_part_area');
        }

        return ['post_id' => $id, 'slug' => $slug, 'type' => $type, 'created' => !$existing];
    } catch (\Throwable $e) {
        return wpultra_err('fse_unavailable', 'Failed to upsert template: ' . $e->getMessage());
    }
}

/** Delete a custom template/template-part CPT by slug. */
function wpultra_fse_template_delete(string $slug, string $type) {
    if (!wpultra_fse_block_theme_available()) {
        return wpultra_err('fse_unavailable', 'Active theme is not a block theme.');
    }
    if (!function_exists('get_page_by_path') || !function_exists('wp_delete_post')) {
        return wpultra_err('fse_unavailable', 'Required post functions unavailable.');
    }
    try {
        $existing = get_page_by_path($slug, OBJECT, $type);
        if (!$existing) {
            return wpultra_err('template_not_found', "Template '$slug' not found.");
        }
        $res = wp_delete_post((int) $existing->ID, true);
        if (!$res) {
            return wpultra_err('delete_failed', "Failed to delete template '$slug'.");
        }
        return ['slug' => $slug, 'type' => $type, 'deleted' => true];
    } catch (\Throwable $e) {
        return wpultra_err('fse_unavailable', 'Failed to delete template: ' . $e->getMessage());
    }
}

/** Reset a customized template/template-part back to its theme-shipped version. */
function wpultra_fse_template_reset(string $slug, string $type) {
    // Resetting == deleting the customization CPT; WP then falls back to the theme file.
    return wpultra_fse_template_delete($slug, $type);
}

/** Read the current custom CSS (classic + block themes). */
function wpultra_fse_custom_css_get() {
    if (!function_exists('wp_get_custom_css')) {
        return wpultra_err('fse_unavailable', 'wp_get_custom_css() unavailable.');
    }
    try {
        $css = (string) wp_get_custom_css();
        return ['css' => $css, 'length' => strlen($css)];
    } catch (\Throwable $e) {
        return wpultra_err('fse_unavailable', 'Failed to read custom CSS: ' . $e->getMessage());
    }
}

/** Set (replace) or append custom CSS. */
function wpultra_fse_custom_css_set(string $css, bool $append = false) {
    if (!function_exists('wp_update_custom_css_post')) {
        return wpultra_err('fse_unavailable', 'wp_update_custom_css_post() unavailable.');
    }
    try {
        $current = function_exists('wp_get_custom_css') ? (string) wp_get_custom_css() : '';
        if (function_exists('wpultra_undo_capture')) {
            wpultra_undo_capture('custom_css', 'custom_css', $current, 'Custom CSS ' . ($append ? 'append' : 'replace'));
        }
        $final = $css;
        if ($append) {
            $final = rtrim($current) !== '' ? rtrim($current) . "\n" . $css : $css;
        }
        $res = wp_update_custom_css_post($final);
        if (is_wp_error($res)) { return $res; }
        return ['css' => $final, 'length' => strlen($final)];
    } catch (\Throwable $e) {
        return wpultra_err('fse_unavailable', 'Failed to write custom CSS: ' . $e->getMessage());
    }
}
