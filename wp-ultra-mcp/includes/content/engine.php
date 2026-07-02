<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Content-read + duplicate engine: list/get/search posts and duplicate-post.
 *
 * Pure functions (query-args builder, snippet extractor, post shaper, duplicate
 * postarr builder) take/return plain arrays and never call WordPress functions
 * directly, so they're testable under the zero-dependency harness. Thin wrapper
 * functions do the actual WP_Query / get_post / wp_insert_post calls.
 */

// ---------------------------------------------------------------------------
// Pure: query-args builder for list-posts / search-content.
// ---------------------------------------------------------------------------

/**
 * Build a WP_Query-compatible args array from a sanitized input array. Pure —
 * no WordPress calls, just array shaping so it's unit-testable.
 */
function wpultra_content_build_query_args(array $input): array {
    $post_type = (string) ($input['post_type'] ?? 'post');
    if ($post_type === '') { $post_type = 'post'; }

    $per_page = (int) ($input['per_page'] ?? 20);
    if ($per_page <= 0) { $per_page = 20; }
    if ($per_page > 100) { $per_page = 100; }

    $page = (int) ($input['page'] ?? 1);
    if ($page <= 0) { $page = 1; }

    $args = [
        'post_type'      => $post_type,
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'no_found_rows'  => false,
        'post_status'    => !empty($input['status']) ? $input['status'] : ['publish', 'draft', 'pending', 'private', 'future'],
        'orderby'        => (string) ($input['orderby'] ?? 'date'),
        'order'          => strtoupper((string) ($input['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
        'ignore_sticky_posts' => true,
    ];

    if (!empty($input['search'])) { $args['s'] = (string) $input['search']; }

    if (!empty($input['meta_key'])) {
        $args['meta_key'] = (string) $input['meta_key'];
        if (array_key_exists('meta_value', $input) && $input['meta_value'] !== '') {
            $args['meta_value'] = $input['meta_value'];
        }
    }

    if (!empty($input['tax_query']) && is_array($input['tax_query'])) {
        $tq = $input['tax_query'];
        $taxonomy = (string) ($tq['taxonomy'] ?? '');
        $terms = $tq['terms'] ?? [];
        if ($taxonomy !== '' && !empty($terms)) {
            $args['tax_query'] = [[
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => array_values((array) $terms),
            ]];
        }
    }

    return $args;
}

/** Pure: total pages given total items + per_page (min 1 page). */
function wpultra_content_total_pages(int $total, int $per_page): int {
    if ($per_page <= 0) { return 1; }
    return max(1, (int) ceil($total / $per_page));
}

// ---------------------------------------------------------------------------
// Pure: post shaping for list output (never includes post_content).
// ---------------------------------------------------------------------------

/** Pure: trim an excerpt/content string to $len chars, tag-stripped, word-safe-ish. */
function wpultra_content_trim_excerpt(string $text, int $len = 160): string {
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
    if (function_exists('mb_strlen')) {
        if (mb_strlen($text) <= $len) { return $text; }
        return rtrim(mb_substr($text, 0, $len)) . '…';
    }
    if (strlen($text) <= $len) { return $text; }
    return rtrim(substr($text, 0, $len)) . '…';
}

/**
 * Pure: shape a plain post-data array (already extracted from a WP_Post/stdClass
 * by the thin wrapper) into the list-posts row format. Never includes post_content.
 *
 * Expected keys in $p: id, title, slug, status, type, date, modified, author,
 * excerpt (raw, will be trimmed), edit_link.
 */
function wpultra_content_shape_list_row(array $p): array {
    $excerpt_source = (string) ($p['excerpt'] ?? '');
    return [
        'id'         => (int) ($p['id'] ?? 0),
        'title'      => (string) ($p['title'] ?? ''),
        'slug'       => (string) ($p['slug'] ?? ''),
        'status'     => (string) ($p['status'] ?? ''),
        'type'       => (string) ($p['type'] ?? ''),
        'date'       => (string) ($p['date'] ?? ''),
        'modified'   => (string) ($p['modified'] ?? ''),
        'author'     => (int) ($p['author'] ?? 0),
        'excerpt'    => wpultra_content_trim_excerpt($excerpt_source, 160),
        'edit_link'  => (string) ($p['edit_link'] ?? ''),
    ];
}

/**
 * Pure: filter a raw meta assoc array down to output-safe meta, skipping
 * `_`-prefixed keys unless $include_private is true.
 */
function wpultra_content_filter_meta(array $meta, bool $include_private): array {
    $out = [];
    foreach ($meta as $key => $value) {
        $key = (string) $key;
        if (!$include_private && $key !== '' && $key[0] === '_') { continue; }
        $out[$key] = $value;
    }
    return $out;
}

/** Pure: group a flat list of term rows (each with 'taxonomy') by taxonomy name. */
function wpultra_content_group_terms_by_taxonomy(array $terms): array {
    $out = [];
    foreach ($terms as $t) {
        $tax = (string) ($t['taxonomy'] ?? '');
        if ($tax === '') { continue; }
        unset($t['taxonomy']);
        $out[$tax][] = $t;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Pure: search snippet extraction for search-content.
// ---------------------------------------------------------------------------

/**
 * Pure: strip tags from $content, find the first case-insensitive occurrence of
 * $query, and return a ±$radius-char window around it (word-ish trimmed, with
 * ellipses where truncated). Falls back to a leading trim when no match found.
 */
function wpultra_content_snippet(string $content, string $query, int $radius = 80): string {
    $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($content)));
    $query = trim($query);

    if ($plain === '') { return ''; }
    if ($query === '') { return wpultra_content_trim_excerpt($plain, $radius * 2);
    }

    $pos = function_exists('mb_stripos') ? mb_stripos($plain, $query) : stripos($plain, $query);
    if ($pos === false) {
        return wpultra_content_trim_excerpt($plain, $radius * 2);
    }

    $strlen = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
    $substr = function_exists('mb_substr') ? 'mb_substr' : 'substr';

    $qlen = $strlen($query);
    $total = $strlen($plain);

    $start = max(0, $pos - $radius);
    $end = min($total, $pos + $qlen + $radius);

    $snippet = $substr($plain, $start, $end - $start);
    if ($start > 0) { $snippet = '…' . ltrim((string) $snippet); }
    if ($end < $total) { $snippet = rtrim((string) $snippet) . '…'; }

    return (string) $snippet;
}

// ---------------------------------------------------------------------------
// Pure: duplicate-post postarr builder.
// ---------------------------------------------------------------------------

/**
 * Pure: build the wp_insert_post()-ready postarr for a duplicate, given the
 * source post's plain field array and duplicate options. Does not slash or
 * insert — the thin wrapper does that (and copies meta/terms separately).
 *
 * Expected $source keys: title, content, excerpt, post_type, post_parent (or
 * parent), menu_order, comment_status, ping_status, post_author (or author).
 */
function wpultra_content_build_duplicate_postarr(array $source, array $options): array {
    $new_title = isset($options['new_title']) && trim((string) $options['new_title']) !== ''
        ? (string) $options['new_title']
        : ((string) ($source['title'] ?? '')) . ' (Copy)';

    $new_status = (string) ($options['new_status'] ?? 'draft');
    if ($new_status === '') { $new_status = 'draft'; }

    $postarr = [
        'post_title'     => $new_title,
        'post_content'   => (string) ($source['content'] ?? ''),
        'post_excerpt'   => (string) ($source['excerpt'] ?? ''),
        'post_status'    => $new_status,
        'post_type'      => (string) ($source['post_type'] ?? 'post'),
        'post_parent'    => (int) ($source['post_parent'] ?? $source['parent'] ?? 0),
        'menu_order'     => (int) ($source['menu_order'] ?? 0),
        'comment_status' => (string) ($source['comment_status'] ?? 'closed'),
        'ping_status'    => (string) ($source['ping_status'] ?? 'closed'),
    ];

    $author = (int) ($source['post_author'] ?? $source['author'] ?? 0);
    if ($author > 0) { $postarr['post_author'] = $author; }

    return $postarr;
}

/**
 * Pure: decide which meta keys should be copied when duplicating. Reserved
 * WordPress-managed keys (e.g. `_wp_old_slug`) are excluded even when
 * copy_meta is requested, since they don't make sense on a fresh post.
 */
function wpultra_content_duplicate_meta_keys(array $meta_keys): array {
    $skip = ['_wp_old_slug', '_wp_old_date', '_edit_lock', '_edit_last'];
    return array_values(array_diff($meta_keys, $skip));
}

// ---------------------------------------------------------------------------
// Thin wrappers: the only functions in this file that call WordPress directly.
// ---------------------------------------------------------------------------

/** @return array|WP_Error ['posts'=>[], 'total'=>int, 'pages'=>int] */
function wpultra_content_list_posts(array $input) {
    $args = wpultra_content_build_query_args($input);
    $query = new WP_Query($args);

    $rows = [];
    foreach ($query->posts as $post) {
        $rows[] = wpultra_content_shape_list_row([
            'id'        => (int) $post->ID,
            'title'     => get_the_title($post),
            'slug'      => (string) $post->post_name,
            'status'    => (string) $post->post_status,
            'type'      => (string) $post->post_type,
            'date'      => (string) $post->post_date,
            'modified'  => (string) $post->post_modified,
            'author'    => (int) $post->post_author,
            'excerpt'   => $post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content,
            'edit_link' => (string) get_edit_post_link($post->ID, 'raw'),
        ]);
    }

    $total = (int) $query->found_posts;
    $per_page = (int) $args['posts_per_page'];

    return [
        'posts' => $rows,
        'total' => $total,
        'pages' => wpultra_content_total_pages($total, $per_page),
    ];
}

/** @return array|WP_Error */
function wpultra_content_get_post(int $id, array $fields) {
    $post = get_post($id);
    if (!$post) { return wpultra_err('not_found', "No post with id $id."); }

    $want = array_map('strval', $fields);
    $include_content = $want === [] || in_array('content', $want, true);
    $include_meta = in_array('meta', $want, true);
    $include_terms = in_array('terms', $want, true);
    $include_revisions = in_array('revisions_count', $want, true);
    $include_private_meta = !empty($fields['include_private_meta']);

    $out = [
        'id'         => (int) $post->ID,
        'title'      => get_the_title($post),
        'slug'       => (string) $post->post_name,
        'status'     => (string) $post->post_status,
        'type'       => (string) $post->post_type,
        'date'       => (string) $post->post_date,
        'modified'   => (string) $post->post_modified,
        'author'     => (int) $post->post_author,
        'excerpt'    => (string) $post->post_excerpt,
        'edit_link'  => (string) get_edit_post_link($post->ID, 'raw'),
        'permalink'  => (string) get_permalink($post->ID),
        'parent'     => (int) $post->post_parent,
    ];

    if ($include_content) { $out['content'] = (string) $post->post_content; }

    if ($include_meta) {
        $raw_meta = get_post_meta($id);
        $flat = [];
        foreach ((array) $raw_meta as $k => $v) {
            $flat[$k] = is_array($v) && count($v) === 1 ? maybe_unserialize($v[0]) : array_map('maybe_unserialize', (array) $v);
        }
        $out['meta'] = wpultra_content_filter_meta($flat, $include_private_meta);
    }

    if ($include_terms) {
        $taxonomies = get_object_taxonomies($post->post_type);
        $term_rows = [];
        foreach ((array) $taxonomies as $tax) {
            $terms = wp_get_post_terms($id, $tax);
            if (is_wp_error($terms)) { continue; }
            foreach ($terms as $t) {
                $term_rows[] = [
                    'taxonomy' => $tax,
                    'id'       => (int) $t->term_id,
                    'name'     => (string) $t->name,
                    'slug'     => (string) $t->slug,
                ];
            }
        }
        $out['terms'] = wpultra_content_group_terms_by_taxonomy($term_rows);
    }

    if ($include_revisions) {
        $revisions = wp_get_post_revisions($id);
        $out['revisions_count'] = is_array($revisions) ? count($revisions) : 0;
    }

    return $out;
}

/** @return array|WP_Error ['matches'=>[], 'total'=>int, 'pages'=>int] */
function wpultra_content_search(array $input) {
    $query_str = (string) ($input['query'] ?? '');
    if (trim($query_str) === '') { return wpultra_err('missing_query', 'query is required.'); }

    $per_page = (int) ($input['per_page'] ?? 20);
    if ($per_page <= 0) { $per_page = 20; }
    if ($per_page > 100) { $per_page = 100; }
    $page = (int) ($input['page'] ?? 1);
    if ($page <= 0) { $page = 1; }

    $post_types = !empty($input['post_types']) && is_array($input['post_types'])
        ? array_values($input['post_types'])
        : 'any';

    $args = [
        'post_type'      => $post_types,
        's'              => $query_str,
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'no_found_rows'  => false,
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
    ];

    $wp_query = new WP_Query($args);

    $matches = [];
    foreach ($wp_query->posts as $post) {
        $matches[] = [
            'id'      => (int) $post->ID,
            'title'   => get_the_title($post),
            'type'    => (string) $post->post_type,
            'status'  => (string) $post->post_status,
            'snippet' => wpultra_content_snippet((string) $post->post_content, $query_str, 80),
            'edit_link' => (string) get_edit_post_link($post->ID, 'raw'),
        ];
    }

    $total = (int) $wp_query->found_posts;

    return [
        'matches' => $matches,
        'total'   => $total,
        'pages'   => wpultra_content_total_pages($total, $per_page),
    ];
}

/** @return array|WP_Error */
function wpultra_content_duplicate_post(int $id, array $options) {
    $post = get_post($id, ARRAY_A);
    if (!$post) { return wpultra_err('not_found', "No post with id $id."); }
    if (in_array((string) $post['post_type'], wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "Post $id is a plugin-internal '{$post['post_type']}'; duplicate it via its dedicated ability.");
    }

    $postarr = wpultra_content_build_duplicate_postarr([
        'title'          => $post['post_title'],
        'content'        => $post['post_content'],
        'excerpt'        => $post['post_excerpt'],
        'post_type'      => $post['post_type'],
        'post_parent'    => $post['post_parent'],
        'menu_order'     => $post['menu_order'],
        'comment_status' => $post['comment_status'],
        'ping_status'    => $post['ping_status'],
        'post_author'    => $post['post_author'],
    ], $options);

    // wp_insert_post() unslashes internally — slash first so raw backslashes
    // (Elementor JSON, Windows paths, regex) survive the round trip.
    $new_id = wp_insert_post(wp_slash($postarr), true);
    if (is_wp_error($new_id)) { return $new_id; }
    $new_id = (int) $new_id;

    $copy_meta = ($options['copy_meta'] ?? true) !== false;
    if ($copy_meta) {
        $meta = get_post_meta($id);
        $keys = wpultra_content_duplicate_meta_keys(array_keys((array) $meta));
        foreach ($keys as $key) {
            foreach ((array) $meta[$key] as $value) {
                // _elementor_data and similar are serialized JSON strings; unserialize
                // first so we don't double-encode, then wp_slash for the unslash inside
                // add_post_meta/update_post_meta (string-safe for Elementor payloads).
                $value = maybe_unserialize($value);
                add_post_meta($new_id, $key, wp_slash($value));
            }
        }
    }

    $copy_terms = ($options['copy_terms'] ?? true) !== false;
    if ($copy_terms) {
        $taxonomies = get_object_taxonomies((string) $post['post_type']);
        foreach ((array) $taxonomies as $tax) {
            $terms = wp_get_post_terms($id, $tax, ['fields' => 'slugs']);
            if (is_wp_error($terms) || empty($terms)) { continue; }
            wp_set_post_terms($new_id, $terms, $tax);
        }
    }

    return [
        'post_id'   => $new_id,
        'title'     => $postarr['post_title'],
        'status'    => $postarr['post_status'],
        'edit_link' => (string) get_edit_post_link($new_id, 'raw'),
    ];
}
