<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Multilingual adapter engine (WPML / Polylang). Mirrors the includes/fields/
 * adapter pattern: pure detection + pure validators/filters, thin WP-calling
 * wrappers at the bottom. Every ability degrades gracefully when neither
 * plugin is active — never fatal.
 */

// ---------------------------------------------------------------------------
// Pure: detection
// ---------------------------------------------------------------------------

/**
 * Which multilingual plugin (if any) is active. Pure given the two probe
 * booleans so it's fully unit-testable without touching real WP/plugin state.
 * @return string 'wpml'|'polylang'|''
 */
function wpultra_i18n_detect_plugin(bool $wpml_active, bool $polylang_active): string {
    if ($polylang_active) { return 'polylang'; } // Polylang exposes the richer, simpler API; prefer it when both are somehow active.
    if ($wpml_active) { return 'wpml'; }
    return '';
}

/** Live probe: is WPML's SitePress core active? Reads only a constant — safe anywhere. */
function wpultra_i18n_wpml_active(): bool {
    return defined('ICL_SITEPRESS_VERSION');
}

/** Live probe: is Polylang active? Reads only function existence — safe anywhere. */
function wpultra_i18n_polylang_active(): bool {
    return function_exists('pll_languages_list');
}

/** Live: resolve which plugin is active right now. */
function wpultra_i18n_active_plugin(): string {
    return wpultra_i18n_detect_plugin(wpultra_i18n_wpml_active(), wpultra_i18n_polylang_active());
}

// ---------------------------------------------------------------------------
// Pure: language-code validation
// ---------------------------------------------------------------------------

/**
 * Pure: is $code present in the provided list of available language codes?
 * Case-insensitive, trims whitespace. $available is typically the list of
 * codes returned by the active plugin (e.g. ['en','fr','bn']).
 */
function wpultra_i18n_is_valid_lang_code(string $code, array $available): bool {
    $code = strtolower(trim($code));
    if ($code === '') { return false; }
    foreach ($available as $c) {
        if (strtolower(trim((string) $c)) === $code) { return true; }
    }
    return false;
}

/**
 * Pure: normalize a raw language list (mixed shapes from either plugin) into
 * a flat, de-duplicated array of lowercase code strings. Accepts either a
 * flat list of strings or a list of assoc arrays containing a 'code'/'slug' key.
 * @param array<int,mixed> $raw
 * @return array<int,string>
 */
function wpultra_i18n_normalize_lang_codes(array $raw): array {
    $out = [];
    foreach ($raw as $item) {
        if (is_string($item)) {
            $code = strtolower(trim($item));
        } elseif (is_array($item)) {
            $code = strtolower(trim((string) ($item['code'] ?? $item['slug'] ?? $item['language_code'] ?? '')));
        } else {
            continue;
        }
        if ($code !== '' && !in_array($code, $out, true)) { $out[] = $code; }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Pure: meta-copy filter (the test's core)
// ---------------------------------------------------------------------------

/**
 * Pure: decide whether a single meta key should be copied onto a duplicated
 * translation post. WordPress-managed edit-lock bookkeeping is always
 * skipped (it's meaningless / actively wrong on a fresh post); everything
 * else — including Elementor's `_elementor_*` keys and any custom field
 * plugin keys — is kept so the translation starts as a faithful copy.
 */
function wpultra_i18n_should_copy_meta_key(string $key): bool {
    $skip = ['_edit_lock', '_edit_last'];
    return !in_array($key, $skip, true);
}

/**
 * Pure: filter a full meta_key => value[] map (as returned by get_post_meta())
 * down to the keys that should be copied to a translation. Does not touch
 * values — callers still need to maybe_unserialize()/wp_slash() each value
 * when writing (Elementor `_elementor_data` JSON-string-safe), same as
 * duplicate-post's engine.
 * @param array<string,mixed> $meta
 * @return array<string,mixed>
 */
function wpultra_i18n_filter_translation_meta(array $meta): array {
    $out = [];
    foreach ($meta as $key => $value) {
        if (wpultra_i18n_should_copy_meta_key((string) $key)) { $out[$key] = $value; }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Pure: shaping helpers
// ---------------------------------------------------------------------------

/**
 * Pure: build the languages[] output rows (code, name, default flag) from a
 * plugin-agnostic raw list. $raw items may be strings (code only) or assoc
 * arrays with code/name/is_default-ish keys; $default_code names the site's
 * default language code (may be '').
 * @param array<int,mixed> $raw
 * @return array<int,array{code:string,name:string,default:bool}>
 */
function wpultra_i18n_shape_languages(array $raw, string $default_code): array {
    $default_code = strtolower(trim($default_code));
    $out = [];
    foreach ($raw as $item) {
        if (is_string($item)) {
            $code = strtolower(trim($item));
            $name = $item;
        } elseif (is_array($item)) {
            $code = strtolower(trim((string) ($item['code'] ?? $item['slug'] ?? $item['language_code'] ?? '')));
            $name = (string) ($item['name'] ?? $item['native_name'] ?? $item['translated_name'] ?? $code);
        } else {
            continue;
        }
        if ($code === '') { continue; }
        $out[] = ['code' => $code, 'name' => $name, 'default' => ($code === $default_code)];
    }
    return $out;
}

/**
 * Pure: turn a flat per-post_type translated/untranslated tally into the
 * output shape for translation-status. $counts: post_type => ['translated'=>int,'untranslated'=>int].
 * @param array<string,array{translated:int,untranslated:int}> $counts
 * @return array<int,array{post_type:string,translated:int,untranslated:int,total:int}>
 */
function wpultra_i18n_shape_counts(array $counts): array {
    $out = [];
    foreach ($counts as $post_type => $c) {
        $translated = (int) ($c['translated'] ?? 0);
        $untranslated = (int) ($c['untranslated'] ?? 0);
        $out[] = [
            'post_type'    => (string) $post_type,
            'translated'   => $translated,
            'untranslated' => $untranslated,
            'total'        => $translated + $untranslated,
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Pure: postarr / meta building for duplicate-to-language (mirrors
// wpultra_content_build_duplicate_postarr in includes/content/engine.php)
// ---------------------------------------------------------------------------

/**
 * Pure: build the wp_insert_post()-ready postarr for a translation copy.
 * Title/status carry over unchanged (translation tools expect a matching
 * shell the translator then edits) unless $new_title is given.
 * @param array $source Expected keys: title, content, excerpt, post_type,
 *                       post_parent (or parent), menu_order, comment_status,
 *                       ping_status, post_author (or author), post_status.
 */
function wpultra_i18n_build_translation_postarr(array $source, string $new_title = ''): array {
    $title = trim($new_title) !== '' ? $new_title : (string) ($source['title'] ?? '');
    return [
        'post_title'     => $title,
        'post_content'   => (string) ($source['content'] ?? ''),
        'post_excerpt'   => (string) ($source['excerpt'] ?? ''),
        'post_status'    => (string) ($source['post_status'] ?? 'draft'),
        'post_type'      => (string) ($source['post_type'] ?? 'post'),
        'post_parent'    => (int) ($source['post_parent'] ?? $source['parent'] ?? 0),
        'menu_order'     => (int) ($source['menu_order'] ?? 0),
        'comment_status' => (string) ($source['comment_status'] ?? 'closed'),
        'ping_status'    => (string) ($source['ping_status'] ?? 'closed'),
    ];
}

// ---------------------------------------------------------------------------
// Thin wrappers: the only functions below call WordPress / plugin APIs directly.
// ---------------------------------------------------------------------------

/** @return array{active_plugin:string,languages:array,post_type_counts:array} */
function wpultra_i18n_status(): array {
    $plugin = wpultra_i18n_active_plugin();
    if ($plugin === '') {
        return ['active_plugin' => '', 'languages' => [], 'post_type_counts' => []];
    }

    if ($plugin === 'polylang') {
        $raw_langs = function_exists('pll_languages_list') ? (array) pll_languages_list(['fields' => '']) : [];
        // pll_languages_list(['fields'=>'']) returns PLL_Language objects; normalize defensively.
        $langs = [];
        $default_code = function_exists('pll_default_language') ? (string) pll_default_language() : '';
        foreach ($raw_langs as $l) {
            if (is_object($l)) {
                $langs[] = [
                    'code' => (string) ($l->slug ?? ''),
                    'name' => (string) ($l->name ?? $l->slug ?? ''),
                ];
            } elseif (is_string($l)) {
                $langs[] = ['code' => $l, 'name' => $l];
            }
        }
        $languages = wpultra_i18n_shape_languages($langs, $default_code);

        $counts = [];
        $post_types = get_post_types(['public' => true], 'names');
        foreach ((array) $post_types as $pt) {
            if (!function_exists('pll_is_translated_post_type') || !pll_is_translated_post_type($pt)) { continue; }
            $translated = 0;
            $untranslated = 0;
            foreach ($languages as $lang) {
                $q = new WP_Query([
                    'post_type'      => $pt,
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'no_found_rows'  => false,
                    'post_status'    => 'any',
                    'lang'           => $lang['code'],
                ]);
                $translated += (int) $q->found_posts;
            }
            $all = new WP_Query(['post_type' => $pt, 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => false, 'post_status' => 'any']);
            $total = (int) $all->found_posts;
            $untranslated = max(0, $total - $translated);
            $counts[$pt] = ['translated' => $translated, 'untranslated' => $untranslated];
        }

        return [
            'active_plugin'    => 'polylang',
            'languages'        => $languages,
            'post_type_counts' => wpultra_i18n_shape_counts($counts),
        ];
    }

    // WPML: keep counts simple — query the icl_translations table directly.
    if ($plugin === 'wpml') {
        global $wpdb;
        $languages = [];
        $default_code = '';
        if (function_exists('wpml_get_active_languages_filter')) {
            $active = (array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
            $default_code = function_exists('apply_filters') ? (string) apply_filters('wpml_default_language', '') : '';
            foreach ($active as $code => $info) {
                $languages[] = ['code' => (string) $code, 'name' => (string) ($info['native_name'] ?? $info['translated_name'] ?? $code)];
            }
        }
        $languages = wpultra_i18n_shape_languages($languages, $default_code);

        $counts = [];
        if (isset($wpdb) && is_object($wpdb) && property_exists($wpdb, 'prefix')) {
            $post_types = get_post_types(['public' => true], 'names');
            $table = $wpdb->prefix . 'icl_translations';
            foreach ((array) $post_types as $pt) {
                $translated = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT trid) FROM {$table} WHERE element_type = %s",
                    'post_' . $pt
                ));
                $total_q = new WP_Query(['post_type' => $pt, 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => false, 'post_status' => 'any']);
                $total = (int) $total_q->found_posts;
                $counts[$pt] = ['translated' => $translated, 'untranslated' => max(0, $total - $translated)];
            }
        }

        return [
            'active_plugin'    => 'wpml',
            'languages'        => $languages,
            'post_type_counts' => wpultra_i18n_shape_counts($counts),
        ];
    }

    return ['active_plugin' => '', 'languages' => [], 'post_type_counts' => []];
}

/**
 * Copy $post_id's meta (filtered via wpultra_i18n_filter_translation_meta) onto
 * $new_id, wp_slash-ing each value so serialized/JSON strings (Elementor
 * `_elementor_data`) survive the add_post_meta() unslash round trip.
 */
function wpultra_i18n_copy_meta(int $post_id, int $new_id): void {
    $meta = get_post_meta($post_id);
    $flat = [];
    foreach ((array) $meta as $key => $values) {
        foreach ((array) $values as $v) { $flat[] = [$key, $v]; }
    }
    $keep_keys = array_keys(wpultra_i18n_filter_translation_meta(array_fill_keys(array_column($flat, 0), null)));
    // Clear any existing copies of the keys we're about to re-copy so an in-place overwrite
    // (updating an existing translation) doesn't accumulate duplicate meta rows. add_post_meta
    // below then writes a fresh copy — matching the fresh-post case.
    foreach ($keep_keys as $key) { delete_post_meta($new_id, $key); }
    foreach ($flat as [$key, $value]) {
        if (!in_array($key, $keep_keys, true)) { continue; }
        $value = maybe_unserialize($value);
        add_post_meta($new_id, $key, wp_slash($value));
    }
}

/** @return array|WP_Error */
function wpultra_i18n_duplicate_to_language(int $post_id, string $target_lang, bool $overwrite) {
    $plugin = wpultra_i18n_active_plugin();
    if ($plugin === '') {
        return wpultra_err('multilingual_unavailable', 'No multilingual plugin (WPML or Polylang) is active. Install/activate one first.');
    }

    $post = get_post($post_id, ARRAY_A);
    if (!$post) { return wpultra_err('not_found', "No post with id $post_id."); }
    if (in_array((string) $post['post_type'], wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "Post $post_id is a plugin-internal '{$post['post_type']}'; it cannot be translated via this ability.");
    }

    if ($plugin === 'polylang') {
        if (!function_exists('pll_languages_list') || !function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
            return wpultra_err('multilingual_unavailable', 'Polylang is active but required functions (pll_set_post_language/pll_save_post_translations) are missing.');
        }
        $available = wpultra_i18n_normalize_lang_codes((array) pll_languages_list(['fields' => 'slug']));
        if (!wpultra_i18n_is_valid_lang_code($target_lang, $available)) {
            return wpultra_err('invalid_language', "'$target_lang' is not a configured Polylang language. Available: " . implode(', ', $available));
        }

        $existing_translations = function_exists('pll_get_post_translations') ? (array) pll_get_post_translations($post_id) : [];
        $source_lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language($post_id) : '';

        if (!$overwrite && isset($existing_translations[$target_lang]) && (int) $existing_translations[$target_lang] > 0) {
            return wpultra_err('translation_exists', "A translation for '$target_lang' already exists (post " . (int) $existing_translations[$target_lang] . "). Pass overwrite:true to replace it.");
        }

        $postarr = wpultra_i18n_build_translation_postarr([
            'title'          => $post['post_title'],
            'content'        => $post['post_content'],
            'excerpt'        => $post['post_excerpt'],
            'post_type'      => $post['post_type'],
            'post_status'    => $post['post_status'],
            'post_parent'    => $post['post_parent'],
            'menu_order'     => $post['menu_order'],
            'comment_status' => $post['comment_status'],
            'ping_status'    => $post['ping_status'],
        ]);

        // Overwrite: update the EXISTING translation in place instead of creating a fresh post and
        // re-pointing the map (which orphans the previous translation as a stray draft).
        $existing_id = ($overwrite && isset($existing_translations[$target_lang])) ? (int) $existing_translations[$target_lang] : 0;
        if ($existing_id > 0 && get_post($existing_id)) {
            $postarr['ID'] = $existing_id;
            $new_id = wp_update_post(wp_slash($postarr), true);
        } else {
            $new_id = wp_insert_post(wp_slash($postarr), true);
        }
        if (is_wp_error($new_id)) { return $new_id; }
        $new_id = (int) $new_id;

        wpultra_i18n_copy_meta($post_id, $new_id);

        $taxonomies = get_object_taxonomies((string) $post['post_type']);
        foreach ((array) $taxonomies as $tax) {
            $terms = wp_get_post_terms($post_id, $tax, ['fields' => 'slugs']);
            if (is_wp_error($terms) || empty($terms)) { continue; }
            wp_set_post_terms($new_id, $terms, $tax);
        }

        pll_set_post_language($new_id, $target_lang);
        $translations = $existing_translations;
        if ($source_lang !== '' && !isset($translations[$source_lang])) { $translations[$source_lang] = $post_id; }
        $translations[$target_lang] = $new_id;
        pll_save_post_translations($translations);

        return [
            'post_id'     => $new_id,
            'plugin'      => 'polylang',
            'target_lang' => $target_lang,
            'edit_link'   => (string) get_edit_post_link($new_id, 'raw'),
        ];
    }

    // WPML path.
    $has_sitepress = function_exists('icl_object_id') || class_exists('SitePress') || function_exists('wpml_object_id_filter');
    if (!$has_sitepress) {
        return wpultra_err('multilingual_unavailable', 'WPML core (SitePress) API not detected. Ensure WPML Multilingual CMS is active.');
    }
    if (!function_exists('apply_filters')) {
        return wpultra_err('multilingual_unavailable', 'WPML filter API unavailable.');
    }

    $active_langs = (array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
    $available = wpultra_i18n_normalize_lang_codes(array_keys($active_langs));
    if ($available !== [] && !wpultra_i18n_is_valid_lang_code($target_lang, $available)) {
        return wpultra_err('invalid_language', "'$target_lang' is not a configured WPML language. Available: " . implode(', ', $available));
    }

    $element_type = (string) apply_filters('wpml_element_type', $post['post_type']);
    $trid = apply_filters('wpml_element_trid', null, $post_id, $element_type);
    if ($trid === null) {
        return wpultra_err('multilingual_unavailable', 'Could not resolve WPML translation group (trid) for this post; the wpml_element_trid filter is unavailable.');
    }

    $existing = (array) apply_filters('wpml_get_element_translations', null, $trid, $element_type);
    if (!$overwrite && isset($existing[$target_lang]) && !empty($existing[$target_lang]->element_id)) {
        return wpultra_err('translation_exists', "A translation for '$target_lang' already exists. Pass overwrite:true to replace it.");
    }

    $postarr = wpultra_i18n_build_translation_postarr([
        'title'          => $post['post_title'],
        'content'        => $post['post_content'],
        'excerpt'        => $post['post_excerpt'],
        'post_type'      => $post['post_type'],
        'post_status'    => $post['post_status'],
        'post_parent'    => $post['post_parent'],
        'menu_order'     => $post['menu_order'],
        'comment_status' => $post['comment_status'],
        'ping_status'    => $post['ping_status'],
    ]);

    // Overwrite: update the EXISTING translation in place instead of creating a fresh post and
    // re-registering the language details (which would orphan the previous translation).
    $existing_id = ($overwrite && isset($existing[$target_lang]) && !empty($existing[$target_lang]->element_id))
        ? (int) $existing[$target_lang]->element_id : 0;
    if ($existing_id > 0 && get_post($existing_id)) {
        $postarr['ID'] = $existing_id;
        $new_id = wp_update_post(wp_slash($postarr), true);
    } else {
        $new_id = wp_insert_post(wp_slash($postarr), true);
    }
    if (is_wp_error($new_id)) { return $new_id; }
    $new_id = (int) $new_id;

    wpultra_i18n_copy_meta($post_id, $new_id);

    $taxonomies = get_object_taxonomies((string) $post['post_type']);
    foreach ((array) $taxonomies as $tax) {
        $terms = wp_get_post_terms($post_id, $tax, ['fields' => 'slugs']);
        if (is_wp_error($terms) || empty($terms)) { continue; }
        wp_set_post_terms($new_id, $terms, $tax);
    }

    if (function_exists('do_action')) {
        do_action('wpml_set_element_language_details', [
            'element_id'    => $new_id,
            'element_type'  => $element_type,
            'trid'          => $trid,
            'language_code' => $target_lang,
            'source_language_code' => null,
        ]);
    }

    return [
        'post_id'     => $new_id,
        'plugin'      => 'wpml',
        'target_lang' => $target_lang,
        'edit_link'   => (string) get_edit_post_link($new_id, 'raw'),
    ];
}
