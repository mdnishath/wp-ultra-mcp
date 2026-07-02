<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Structure engine: terms, custom-post-type / taxonomy registration builders, and
 * nav-menu tree shaping. Pure argument builders and shape fns are kept free of
 * WordPress calls so they're unit-testable; anything that touches the DB or global
 * WP state lives in a thin wrapper around them.
 */

// ---------------------------------------------------------------------------
// Terms
// ---------------------------------------------------------------------------

/** Shape a WP_Term-like array/object for output. Pure (accepts array or object with the same fields). */
function wpultra_structure_shape_term($term): array {
    $t = is_object($term) ? (array) $term : $term;
    return [
        'id'          => (int) ($t['term_id'] ?? 0),
        'name'        => (string) ($t['name'] ?? ''),
        'slug'        => (string) ($t['slug'] ?? ''),
        'taxonomy'    => (string) ($t['taxonomy'] ?? ''),
        'parent'      => (int) ($t['parent'] ?? 0),
        'description' => (string) ($t['description'] ?? ''),
        'count'       => (int) ($t['count'] ?? 0),
    ];
}

/** @return array|WP_Error */
function wpultra_structure_term_list(string $taxonomy, array $args) {
    if (!taxonomy_exists($taxonomy)) { return wpultra_err('unknown_taxonomy', "Unknown taxonomy '$taxonomy'."); }
    $query = [
        'taxonomy'   => $taxonomy,
        'hide_empty' => !empty($args['hide_empty']),
    ];
    if (!empty($args['search'])) { $query['search'] = (string) $args['search']; }
    $terms = get_terms($query);
    if (is_wp_error($terms)) { return $terms; }
    $out = [];
    foreach ((array) $terms as $term) { $out[] = wpultra_structure_shape_term($term); }
    return ['terms' => $out];
}

/** @return array|WP_Error */
function wpultra_structure_term_create(string $taxonomy, array $args) {
    if (!taxonomy_exists($taxonomy)) { return wpultra_err('unknown_taxonomy', "Unknown taxonomy '$taxonomy'."); }
    $name = trim((string) ($args['name'] ?? ''));
    if ($name === '') { return wpultra_err('missing_name', 'name is required to create a term.'); }
    $termargs = [];
    if (!empty($args['slug'])) { $termargs['slug'] = sanitize_title((string) $args['slug']); }
    if (isset($args['parent'])) { $termargs['parent'] = (int) $args['parent']; }
    if (isset($args['description'])) { $termargs['description'] = (string) $args['description']; }
    $result = wp_insert_term($name, $taxonomy, $termargs);
    if (is_wp_error($result)) { return $result; }
    $id = (int) $result['term_id'];
    if (!empty($args['meta']) && is_array($args['meta'])) {
        foreach ($args['meta'] as $k => $v) { update_term_meta($id, (string) $k, $v); }
    }
    $term = get_term($id, $taxonomy);
    if (is_wp_error($term)) { return $term; }
    return ['term' => wpultra_structure_shape_term($term)];
}

/** @return array|WP_Error */
function wpultra_structure_term_update(string $taxonomy, int $term_id, array $args) {
    if (!taxonomy_exists($taxonomy)) { return wpultra_err('unknown_taxonomy', "Unknown taxonomy '$taxonomy'."); }
    $existing = get_term($term_id, $taxonomy);
    if (!$existing || is_wp_error($existing)) { return wpultra_err('not_found', "No term $term_id in taxonomy '$taxonomy'."); }
    $termargs = [];
    if (isset($args['name']) && trim((string) $args['name']) !== '') { $termargs['name'] = (string) $args['name']; }
    if (isset($args['slug'])) { $termargs['slug'] = sanitize_title((string) $args['slug']); }
    if (isset($args['parent'])) { $termargs['parent'] = (int) $args['parent']; }
    if (isset($args['description'])) { $termargs['description'] = (string) $args['description']; }
    if ($termargs) {
        $result = wp_update_term($term_id, $taxonomy, $termargs);
        if (is_wp_error($result)) { return $result; }
    }
    if (!empty($args['meta']) && is_array($args['meta'])) {
        foreach ($args['meta'] as $k => $v) { update_term_meta($term_id, (string) $k, $v); }
    }
    $term = get_term($term_id, $taxonomy);
    if (is_wp_error($term)) { return $term; }
    return ['term' => wpultra_structure_shape_term($term)];
}

/** @return array|WP_Error */
function wpultra_structure_term_delete(string $taxonomy, int $term_id) {
    if (!taxonomy_exists($taxonomy)) { return wpultra_err('unknown_taxonomy', "Unknown taxonomy '$taxonomy'."); }
    $existing = get_term($term_id, $taxonomy);
    if (!$existing || is_wp_error($existing)) { return wpultra_err('not_found', "No term $term_id in taxonomy '$taxonomy'."); }
    $result = wp_delete_term($term_id, $taxonomy);
    if (is_wp_error($result)) { return $result; }
    if ($result === false || $result === 0) { return wpultra_err('delete_failed', "Could not delete term $term_id."); }
    return ['term_id' => $term_id, 'deleted' => true];
}

// ---------------------------------------------------------------------------
// CPT / taxonomy argument builders (pure — no WordPress calls)
// ---------------------------------------------------------------------------

/**
 * Build register_post_type() args from unified ability input. Pure.
 * $in keys: slug, singular, plural, public, supports[], has_archive, hierarchical, menu_icon, taxonomies[].
 */
function wpultra_structure_build_cpt_args(array $in): array {
    $singular = (string) ($in['singular'] ?? '');
    $plural   = (string) ($in['plural'] ?? $singular);
    $public   = array_key_exists('public', $in) ? (bool) $in['public'] : true;
    $supports = isset($in['supports']) && is_array($in['supports']) && $in['supports']
        ? array_values(array_map('strval', $in['supports']))
        : ['title', 'editor', 'thumbnail'];

    $args = [
        'label'    => $plural,
        'labels'   => [
            'name'          => $plural,
            'singular_name' => $singular,
            'add_new_item'  => "Add New $singular",
            'edit_item'     => "Edit $singular",
            'new_item'      => "New $singular",
            'view_item'     => "View $singular",
            'search_items'  => "Search $plural",
            'not_found'     => "No " . strtolower($plural) . " found",
            'all_items'     => "All $plural",
        ],
        'public'       => $public,
        'show_ui'      => $public,
        'show_in_menu' => $public,
        'supports'     => $supports,
        'has_archive'  => array_key_exists('has_archive', $in) ? (bool) $in['has_archive'] : $public,
        'hierarchical' => !empty($in['hierarchical']),
        'show_in_rest' => true, // forced true per spec
        'rewrite'      => ['slug' => (string) ($in['slug'] ?? '')],
    ];
    if (!empty($in['menu_icon'])) { $args['menu_icon'] = (string) $in['menu_icon']; }
    if (!empty($in['taxonomies']) && is_array($in['taxonomies'])) {
        $args['taxonomies'] = array_values(array_map('strval', $in['taxonomies']));
    }
    return $args;
}

/**
 * Build register_taxonomy() args from unified ability input. Pure.
 * $in keys: slug, singular, plural, public, hierarchical, object_types[].
 */
function wpultra_structure_build_taxonomy_args(array $in): array {
    $singular = (string) ($in['singular'] ?? '');
    $plural   = (string) ($in['plural'] ?? $singular);
    $public   = array_key_exists('public', $in) ? (bool) $in['public'] : true;

    $args = [
        'label'  => $plural,
        'labels' => [
            'name'          => $plural,
            'singular_name' => $singular,
            'search_items'  => "Search $plural",
            'all_items'     => "All $plural",
            'edit_item'     => "Edit $singular",
            'update_item'   => "Update $singular",
            'add_new_item'  => "Add New $singular",
            'new_item_name' => "New $singular Name",
            'menu_name'     => $plural,
        ],
        'public'            => $public,
        'show_ui'           => $public,
        'show_admin_column' => $public,
        'hierarchical'      => !empty($in['hierarchical']),
        'show_in_rest'      => true, // forced true per spec
        'rewrite'           => ['slug' => (string) ($in['slug'] ?? '')],
    ];
    return $args;
}

/** Pure: validate + normalize a CPT/taxonomy registration request's identifier and object_types. */
function wpultra_structure_validate_registration(array $in, bool $require_object_types = false) {
    $slug = (string) ($in['slug'] ?? '');
    if ($slug === '' || !wpultra_is_valid_identifier($slug)) {
        return wpultra_err('invalid_slug', 'slug is required and must match [A-Za-z0-9_]+.');
    }
    if (in_array($slug, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "'$slug' is reserved by the plugin and cannot be registered.");
    }
    if (trim((string) ($in['singular'] ?? '')) === '' || trim((string) ($in['plural'] ?? '')) === '') {
        return wpultra_err('missing_labels', 'singular and plural labels are required.');
    }
    if ($require_object_types) {
        $obj = $in['object_types'] ?? [];
        if (!is_array($obj) || !$obj) {
            return wpultra_err('missing_object_types', 'object_types is required and must be a non-empty array.');
        }
    }
    return true;
}

// ---------------------------------------------------------------------------
// Persisted registration (option-backed; controller hooks the loader to `init`)
// ---------------------------------------------------------------------------

function wpultra_structure_cpt_option(): string { return 'wpultra_registered_cpts'; }
function wpultra_structure_taxonomy_option(): string { return 'wpultra_registered_taxonomies'; }

/** @return array|WP_Error */
function wpultra_structure_register_cpt_persist(array $in) {
    $valid = wpultra_structure_validate_registration($in, false);
    if (is_wp_error($valid)) { return $valid; }
    $slug = (string) $in['slug'];
    $args = wpultra_structure_build_cpt_args($in);
    $defs = get_option(wpultra_structure_cpt_option(), []);
    if (!is_array($defs)) { $defs = []; }
    $defs[$slug] = $args;
    update_option(wpultra_structure_cpt_option(), $defs, false);
    if (function_exists('post_type_exists') && !post_type_exists($slug)) {
        register_post_type($slug, $args);
    }
    return ['slug' => $slug, 'args' => $args];
}

/** @return array|WP_Error */
function wpultra_structure_register_taxonomy_persist(array $in) {
    $valid = wpultra_structure_validate_registration($in, true);
    if (is_wp_error($valid)) { return $valid; }
    $slug = (string) $in['slug'];
    $object_types = array_values(array_map('strval', (array) $in['object_types']));
    $args = wpultra_structure_build_taxonomy_args($in);
    $defs = get_option(wpultra_structure_taxonomy_option(), []);
    if (!is_array($defs)) { $defs = []; }
    $defs[$slug] = ['args' => $args, 'object_types' => $object_types];
    update_option(wpultra_structure_taxonomy_option(), $defs, false);
    if (function_exists('taxonomy_exists') && !taxonomy_exists($slug)) {
        register_taxonomy($slug, $object_types, $args);
    }
    return ['slug' => $slug, 'object_types' => $object_types, 'args' => $args];
}

/**
 * Loader: reads the persisted CPT/taxonomy definitions and (re-)registers them.
 * The controller hooks this to `init` (and to `plugins_loaded` for the file load)
 * so custom types survive across requests, not just the request that created them.
 */
function wpultra_structure_register_persisted(): void {
    $cpts = get_option(wpultra_structure_cpt_option(), []);
    if (is_array($cpts)) {
        foreach ($cpts as $slug => $args) {
            if (!is_string($slug) || $slug === '' || !is_array($args)) { continue; }
            if (function_exists('post_type_exists') && post_type_exists($slug)) { continue; }
            register_post_type($slug, $args);
        }
    }
    $taxes = get_option(wpultra_structure_taxonomy_option(), []);
    if (is_array($taxes)) {
        foreach ($taxes as $slug => $def) {
            if (!is_string($slug) || $slug === '' || !is_array($def)) { continue; }
            if (function_exists('taxonomy_exists') && taxonomy_exists($slug)) { continue; }
            $args = (array) ($def['args'] ?? []);
            $object_types = (array) ($def['object_types'] ?? []);
            register_taxonomy($slug, $object_types, $args);
        }
    }
}

// ---------------------------------------------------------------------------
// Menus
// ---------------------------------------------------------------------------

/** Shape a nav menu item (WP_Post-like with menu-item meta already flattened) for output. Pure. */
function wpultra_structure_shape_menu_item($item): array {
    $i = is_object($item) ? (array) $item : $item;
    return [
        'id'        => (int) ($i['ID'] ?? $i['id'] ?? 0),
        'title'     => (string) ($i['title'] ?? ''),
        'url'       => (string) ($i['url'] ?? ''),
        'parent'    => (int) ($i['menu_item_parent'] ?? $i['parent'] ?? 0),
        'position'  => (int) ($i['menu_order'] ?? $i['position'] ?? 0),
        'object'    => (string) ($i['object'] ?? ''),
        'object_id' => (int) ($i['object_id'] ?? 0),
        'type'      => (string) ($i['type'] ?? ($i['object'] ?? 'custom')),
    ];
}

/**
 * Build a nested tree from a flat list of shaped menu items (each with id/parent/position).
 * Pure — no WordPress calls. Children are sorted by position; root items (parent === 0) at top.
 */
function wpultra_structure_build_menu_tree(array $items): array {
    $byId = [];
    foreach ($items as $item) {
        $shaped = isset($item['id']) && array_key_exists('parent', $item) ? $item : wpultra_structure_shape_menu_item($item);
        $shaped['children'] = [];
        $byId[$shaped['id']] = $shaped;
    }
    $roots = [];
    foreach ($byId as $id => &$node) {
        $parent = $node['parent'];
        if ($parent !== 0 && isset($byId[$parent])) {
            $byId[$parent]['children'][] = &$node;
        } else {
            $roots[] = &$node;
        }
    }
    unset($node);
    $sorter = function (&$list) use (&$sorter): void {
        usort($list, fn($a, $b) => $a['position'] <=> $b['position']);
        foreach ($list as &$n) { if ($n['children']) { $sorter($n['children']); } }
        unset($n);
    };
    $sorter($roots);
    return $roots;
}

/** @return array|WP_Error */
function wpultra_structure_menu_list() {
    $menus = wp_get_nav_menus();
    if (is_wp_error($menus)) { return $menus; }
    $out = [];
    foreach ((array) $menus as $menu) {
        $m = is_object($menu) ? (array) $menu : $menu;
        $out[] = ['id' => (int) ($m['term_id'] ?? 0), 'name' => (string) ($m['name'] ?? ''), 'slug' => (string) ($m['slug'] ?? '')];
    }
    $locations = get_theme_mod('nav_menu_locations');
    return ['menus' => $out, 'locations' => is_array($locations) ? $locations : []];
}

/** Resolve a menu identifier (numeric id or name) to a term_id, or WP_Error. */
function wpultra_structure_resolve_menu($menu) {
    if (is_numeric($menu)) {
        $term = get_term((int) $menu, 'nav_menu');
        if ($term && !is_wp_error($term)) { return (int) $term->term_id; }
    }
    $found = wp_get_nav_menu_object((string) $menu);
    if ($found && !is_wp_error($found)) { return (int) $found->term_id; }
    return wpultra_err('menu_not_found', "No menu matches '" . (string) $menu . "'.");
}

/** @return array|WP_Error */
function wpultra_structure_menu_get($menu) {
    $menu_id = wpultra_structure_resolve_menu($menu);
    if (is_wp_error($menu_id)) { return $menu_id; }
    $items = wp_get_nav_menu_items($menu_id);
    if ($items === false) { $items = []; }
    $shaped = [];
    foreach ((array) $items as $item) { $shaped[] = wpultra_structure_shape_menu_item($item); }
    return ['menu_id' => $menu_id, 'items' => wpultra_structure_build_menu_tree($shaped)];
}

/** @return array|WP_Error */
function wpultra_structure_menu_create(string $name) {
    $name = trim($name);
    if ($name === '') { return wpultra_err('missing_name', 'menu name is required.'); }
    $id = wp_create_nav_menu($name);
    if (is_wp_error($id)) { return $id; }
    return ['menu_id' => (int) $id, 'name' => $name];
}

/** @return array|WP_Error */
function wpultra_structure_menu_delete($menu) {
    $menu_id = wpultra_structure_resolve_menu($menu);
    if (is_wp_error($menu_id)) { return $menu_id; }
    $result = wp_delete_nav_menu($menu_id);
    if (is_wp_error($result)) { return $result; }
    if (!$result) { return wpultra_err('delete_failed', "Could not delete menu $menu_id."); }
    return ['menu_id' => $menu_id, 'deleted' => true];
}

/** @return array|WP_Error */
function wpultra_structure_menu_add_item($menu, array $item) {
    $menu_id = wpultra_structure_resolve_menu($menu);
    if (is_wp_error($menu_id)) { return $menu_id; }
    $args = wpultra_structure_menu_item_args($item);
    $item_id = wp_update_nav_menu_item($menu_id, 0, $args);
    if (is_wp_error($item_id)) { return $item_id; }
    return ['menu_id' => $menu_id, 'item_id' => (int) $item_id];
}

/** @return array|WP_Error */
function wpultra_structure_menu_update_item($menu, int $item_id, array $item) {
    $menu_id = wpultra_structure_resolve_menu($menu);
    if (is_wp_error($menu_id)) { return $menu_id; }
    $args = wpultra_structure_menu_item_args($item, true);
    $result = wp_update_nav_menu_item($menu_id, $item_id, $args);
    if (is_wp_error($result)) { return $result; }
    return ['menu_id' => $menu_id, 'item_id' => $item_id];
}

/** @return array|WP_Error */
function wpultra_structure_menu_remove_item(int $item_id) {
    $result = wp_delete_post($item_id, true);
    if (!$result) { return wpultra_err('delete_failed', "Could not remove menu item $item_id."); }
    return ['item_id' => $item_id, 'deleted' => true];
}

/** @return array|WP_Error */
function wpultra_structure_menu_assign_location($menu, string $location) {
    $menu_id = wpultra_structure_resolve_menu($menu);
    if (is_wp_error($menu_id)) { return $menu_id; }
    $locations = get_theme_mod('nav_menu_locations');
    if (!is_array($locations)) { $locations = []; }
    $locations[$location] = $menu_id;
    set_theme_mod('nav_menu_locations', $locations);
    return ['menu_id' => $menu_id, 'location' => $location];
}

/**
 * Build wp_update_nav_menu_item() args from a unified `item` object. Pure-ish (no WP calls beyond
 * caller passing pre-fetched data); kept simple: only maps fields, no defaults lookups against DB.
 */
function wpultra_structure_menu_item_args(array $item, bool $partial = false): array {
    $args = ['menu-item-status' => 'publish'];
    if (isset($item['title'])) { $args['menu-item-title'] = (string) $item['title']; }
    if (isset($item['url'])) { $args['menu-item-url'] = (string) $item['url']; }
    if (isset($item['object_id'])) { $args['menu-item-object-id'] = (int) $item['object_id']; }
    if (isset($item['object_type'])) {
        $args['menu-item-type'] = (string) $item['object_type'] === 'term' ? 'taxonomy' : 'post_type';
        $args['menu-item-object'] = (string) ($item['object'] ?? '');
    }
    if (isset($item['parent_item'])) { $args['menu-item-parent-id'] = (int) $item['parent_item']; }
    if (isset($item['position'])) { $args['menu-item-position'] = (int) $item['position']; }
    if (!$partial && !isset($item['url']) && !isset($item['object_id'])) {
        $args['menu-item-type'] = 'custom';
    }
    return $args;
}
