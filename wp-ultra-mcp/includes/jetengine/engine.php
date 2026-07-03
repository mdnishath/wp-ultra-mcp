<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * JetEngine adapter — CPTs, taxonomies, meta boxes, relations, listings.
 * Storage verified on a live JetEngine 3.4.6 install:
 *   CPTs        — table {prefix}jet_post_types (id, slug, status publish|built-in,
 *                 labels/args/meta_fields = PHP-serialized arrays)
 *   Taxonomies  — table {prefix}jet_taxonomies (+ object_type serialized array)
 *   Meta boxes  — option `jet_engine_meta_boxes` (array of {id:'meta-N', args, meta_fields})
 *   Relations   — jet_engine()->relations Manager (read via get_active_relations)
 *   Listings    — CPT `jet-engine` posts
 * Registration happens at plugin boot from the tables, so writes apply on the
 * NEXT request (same pattern as Elementor experiments).
 */

function wpultra_je_active(): bool {
    return defined('JET_ENGINE_VERSION') || function_exists('jet_engine');
}

function wpultra_je_version(): string {
    if (defined('JET_ENGINE_VERSION')) { return (string) JET_ENGINE_VERSION; }
    try {
        if (function_exists('jet_engine') && method_exists(jet_engine(), 'get_version')) { return (string) jet_engine()->get_version(); }
    } catch (\Throwable $e) {}
    return '';
}

/** @return array|WP_Error */
function wpultra_je_require() {
    if (!wpultra_je_active()) { return wpultra_err('jetengine_unavailable', 'JetEngine is not active on this site.'); }
    return ['version' => wpultra_je_version()];
}

/* ------------------------------------------------------------------ *
 * PURE: labels/args/meta-field builders + validation.
 * ------------------------------------------------------------------ */

/** Pure: JetEngine-style labels array from singular/plural. */
function wpultra_je_build_labels(string $singular, string $plural): array {
    return [
        'name'          => $plural,
        'singular_name' => $singular,
        'menu_name'     => $plural,
        'name_admin_bar' => $singular,
        'add_new'       => "Add New $singular",
        'add_new_item'  => "Add New $singular",
        'new_item'      => "New $singular",
        'edit_item'     => "Edit $singular",
        'view_item'     => "View $singular",
        'all_items'     => "All $plural",
        'search_items'  => "Search $plural",
        'not_found'     => "No $plural found",
    ];
}

/** Pure: default CPT args in the shape JetEngine rows carry (overridable). */
function wpultra_je_default_cpt_args(array $over = []): array {
    return array_merge([
        'public'              => true,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_nav_menus'   => true,
        'show_in_rest'        => true,
        'has_archive'         => true,
        'hierarchical'        => false,
        'rewrite'             => true,
        'supports'            => ['title', 'editor', 'thumbnail'],
        'menu_icon'           => 'dashicons-admin-post',
        'capability_type'     => 'post',
        'admin_columns'       => [],
        'admin_filters'       => [],
    ], $over);
}

/** JetEngine meta-field types the validator accepts. */
function wpultra_je_field_types(): array {
    return ['text', 'textarea', 'wysiwyg', 'number', 'date', 'time', 'datetime-local', 'switcher', 'checkbox', 'radio', 'select', 'media', 'gallery', 'posts', 'colorpicker', 'iconpicker', 'repeater', 'html'];
}

/**
 * Pure: normalize + validate a meta_fields array into JetEngine's field shape.
 * @return array|string normalized fields or error
 */
function wpultra_je_normalize_fields($fields) {
    if ($fields === null) { return []; }
    if (!is_array($fields)) { return 'meta_fields must be an array.'; }
    $out = [];
    $seen = [];
    foreach ($fields as $i => $f) {
        if (!is_array($f)) { return "Field $i must be an object."; }
        $name = (string) ($f['name'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_\-]*$/', $name)) { return "Field $i: name '$name' must be lowercase snake/kebab."; }
        if (isset($seen[$name])) { return "Field $i: duplicate name '$name'."; }
        $seen[$name] = true;
        $type = (string) ($f['type'] ?? 'text');
        if (!in_array($type, wpultra_je_field_types(), true)) {
            return "Field $i: type '$type' must be one of: " . implode(', ', wpultra_je_field_types()) . '.';
        }
        $out[] = array_merge([
            'title'       => (string) ($f['title'] ?? ucwords(str_replace(['_', '-'], ' ', $name))),
            'name'        => $name,
            'object_type' => 'field',
            'type'        => $type,
            'width'       => (string) ($f['width'] ?? '100%'),
            'options'     => is_array($f['options'] ?? null) ? $f['options'] : [],
        ], array_diff_key($f, array_fill_keys(['title', 'name', 'object_type', 'type', 'width', 'options'], 1)));
    }
    return $out;
}

/** Pure: shape a DB row (already unserialized) for output. */
function wpultra_je_shape_row(array $row, bool $full = false): array {
    $labels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
    $out = [
        'id'       => (int) ($row['id'] ?? 0),
        'slug'     => (string) ($row['slug'] ?? ''),
        'status'   => (string) ($row['status'] ?? ''),
        'name'     => (string) ($labels['name'] ?? $row['slug'] ?? ''),
        'fields'   => array_values(array_map(static fn($f) => (string) ($f['name'] ?? ''), is_array($row['meta_fields'] ?? null) ? $row['meta_fields'] : [])),
    ];
    if (isset($row['object_type'])) { $out['object_type'] = $row['object_type']; }
    if ($full) {
        $out['labels'] = $labels;
        $out['args'] = is_array($row['args'] ?? null) ? $row['args'] : [];
        $out['meta_fields'] = is_array($row['meta_fields'] ?? null) ? $row['meta_fields'] : [];
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * DB wrappers (jet_post_types / jet_taxonomies).
 * ------------------------------------------------------------------ */

function wpultra_je_table(string $which): string {
    global $wpdb;
    return $wpdb->prefix . ($which === 'tax' ? 'jet_taxonomies' : 'jet_post_types');
}

function wpultra_je_rows(string $which): array {
    global $wpdb;
    $t = wpultra_je_table($which);
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) { return []; }
    $rows = (array) $wpdb->get_results("SELECT * FROM {$t}", ARRAY_A);
    foreach ($rows as &$r) {
        foreach (['labels', 'args', 'meta_fields', 'object_type'] as $col) {
            if (isset($r[$col])) { $r[$col] = maybe_unserialize($r[$col]); }
        }
    }
    unset($r);
    return $rows;
}

/** @return array|null */
function wpultra_je_row(string $which, string $slug): ?array {
    foreach (wpultra_je_rows($which) as $r) {
        if ((string) ($r['slug'] ?? '') === $slug) { return $r; }
    }
    return null;
}

/** Insert or update a row. @return int|WP_Error row id */
function wpultra_je_row_write(string $which, array $data, ?int $id = null) {
    global $wpdb;
    $t = wpultra_je_table($which);
    $row = [];
    foreach ($data as $k => $v) {
        $row[$k] = is_array($v) ? serialize($v) : $v;
    }
    if ($id !== null) {
        $ok = $wpdb->update($t, $row, ['id' => $id]);
        if ($ok === false) { return wpultra_err('je_db_error', 'Update failed: ' . $wpdb->last_error); }
        return $id;
    }
    $ok = $wpdb->insert($t, $row);
    if ($ok === false) { return wpultra_err('je_db_error', 'Insert failed: ' . $wpdb->last_error); }
    return (int) $wpdb->insert_id;
}

/** @return bool */
function wpultra_je_row_delete(string $which, int $id): bool {
    global $wpdb;
    return (bool) $wpdb->delete(wpultra_je_table($which), ['id' => $id]);
}

/* ------------------------------------------------------------------ *
 * Meta boxes (option jet_engine_meta_boxes).
 * ------------------------------------------------------------------ */

function wpultra_je_meta_boxes(): array {
    $v = get_option('jet_engine_meta_boxes', []);
    return is_array($v) ? array_values($v) : [];
}

/** Pure: next meta-box id ('meta-N'). */
function wpultra_je_next_meta_box_id(array $boxes): string {
    $max = 0;
    foreach ($boxes as $b) {
        if (preg_match('/^meta-(\d+)$/', (string) ($b['id'] ?? ''), $m)) { $max = max($max, (int) $m[1]); }
    }
    return 'meta-' . ($max + 1);
}

function wpultra_je_meta_boxes_save(array $boxes): void {
    update_option('jet_engine_meta_boxes', array_values($boxes));
}

/* ------------------------------------------------------------------ *
 * Relations + listings (read-only).
 * ------------------------------------------------------------------ */

function wpultra_je_relations(): array {
    try {
        if (function_exists('jet_engine') && isset(jet_engine()->relations) && method_exists(jet_engine()->relations, 'get_active_relations')) {
            $out = [];
            foreach ((array) jet_engine()->relations->get_active_relations() as $id => $rel) {
                $args = is_object($rel) && method_exists($rel, 'get_args') ? (array) $rel->get_args() : [];
                $out[] = [
                    'id'   => (string) $id,
                    'name' => (string) ($args['name'] ?? $id),
                    'from' => (string) ($args['parent_object'] ?? ''),
                    'to'   => (string) ($args['child_object'] ?? ''),
                    'type' => (string) ($args['type'] ?? ''),
                ];
            }
            return $out;
        }
    } catch (\Throwable $e) {}
    return [];
}

function wpultra_je_listings(): array {
    $posts = get_posts(['post_type' => 'jet-engine', 'post_status' => 'any', 'numberposts' => 100]);
    $out = [];
    foreach ($posts as $p) {
        $settings = get_post_meta($p->ID, '_listing_data', true);
        $out[] = [
            'id'     => (int) $p->ID,
            'title'  => (string) $p->post_title,
            'status' => (string) $p->post_status,
            'source' => is_array($settings) ? (string) ($settings['source'] ?? '') : '',
        ];
    }
    return $out;
}
