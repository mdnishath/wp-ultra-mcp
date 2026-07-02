<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

// ---- Additional WP stubs needed by structure.php's pure fns (list/create/etc. paths are not
// exercised here — only the pure builders/validators/shape/tree fns are pure-testable per the
// wave-8/9/10/11 constraint). We still need these defined so requiring the file never fatals.
if (!function_exists('sanitize_title')) { function sanitize_title($t) { return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $t)); } }
if (!function_exists('taxonomy_exists')) { function taxonomy_exists($t) { return true; } }
if (!function_exists('post_type_exists')) { function post_type_exists($t) { return false; } }
if (!function_exists('register_post_type')) { function register_post_type($slug, $args) { return true; } }
if (!function_exists('register_taxonomy')) { function register_taxonomy($slug, $obj, $args) { return true; } }
if (!function_exists('get_option')) { function get_option($k, $default = false) { return $GLOBALS['__opts'][$k] ?? $default; } }
if (!function_exists('update_option')) { function update_option($k, $v, $autoload = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('get_term')) { function get_term($id, $tax = '') { return (object) ['term_id' => $id, 'taxonomy' => $tax]; } }
if (!function_exists('get_terms')) { function get_terms($args) { return []; } }
if (!function_exists('wp_insert_term')) { function wp_insert_term($name, $tax, $args = []) { return ['term_id' => 1, 'term_taxonomy_id' => 1]; } }
if (!function_exists('wp_update_term')) { function wp_update_term($id, $tax, $args) { return ['term_id' => $id]; } }
if (!function_exists('wp_delete_term')) { function wp_delete_term($id, $tax) { return true; } }
if (!function_exists('update_term_meta')) { function update_term_meta($id, $k, $v) { return true; } }
if (!function_exists('wp_get_nav_menus')) { function wp_get_nav_menus() { return []; } }
if (!function_exists('wp_get_nav_menu_items')) { function wp_get_nav_menu_items($id) { return []; } }
if (!function_exists('wp_get_nav_menu_object')) { function wp_get_nav_menu_object($name) { return false; } }
if (!function_exists('wp_create_nav_menu')) { function wp_create_nav_menu($name) { return 1; } }
if (!function_exists('wp_delete_nav_menu')) { function wp_delete_nav_menu($id) { return true; } }
if (!function_exists('wp_update_nav_menu_item')) { function wp_update_nav_menu_item($menu_id, $item_id, $args) { return 5; } }
if (!function_exists('wp_delete_post')) { function wp_delete_post($id, $force = false) { return true; } }
if (!function_exists('get_theme_mod')) { function get_theme_mod($k, $default = false) { return $GLOBALS['__theme_mods'][$k] ?? $default; } }
if (!function_exists('set_theme_mod')) { function set_theme_mod($k, $v) { $GLOBALS['__theme_mods'][$k] = $v; return true; } }

require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/content/structure.php';

// ---------------------------------------------------------------------------
// Term shaping
// ---------------------------------------------------------------------------

it('shapes a term from an array', function () {
    $t = wpultra_structure_shape_term([
        'term_id' => 5, 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category',
        'parent' => 0, 'description' => 'd', 'count' => 3,
    ]);
    assert_eq(5, $t['id']);
    assert_eq('News', $t['name']);
    assert_eq('news', $t['slug']);
    assert_eq(3, $t['count']);
});

it('shapes a term from an object', function () {
    $obj = (object) ['term_id' => 7, 'name' => 'Tips', 'slug' => 'tips', 'taxonomy' => 'post_tag', 'parent' => 0, 'description' => '', 'count' => 0];
    $t = wpultra_structure_shape_term($obj);
    assert_eq(7, $t['id']);
    assert_eq('post_tag', $t['taxonomy']);
});

// ---------------------------------------------------------------------------
// CPT / taxonomy arg builders
// ---------------------------------------------------------------------------

it('builds CPT args with defaults', function () {
    $args = wpultra_structure_build_cpt_args(['slug' => 'book', 'singular' => 'Book', 'plural' => 'Books']);
    assert_eq('Books', $args['label']);
    assert_eq(['title', 'editor', 'thumbnail'], $args['supports']);
    assert_true($args['public']);
    assert_true($args['show_in_rest'], 'show_in_rest must always be forced true');
    assert_true($args['has_archive'], 'has_archive defaults to public value');
    assert_true(!$args['hierarchical']);
    assert_eq('book', $args['rewrite']['slug']);
});

it('builds CPT args honoring overrides', function () {
    $args = wpultra_structure_build_cpt_args([
        'slug' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies',
        'public' => false, 'supports' => ['title'], 'has_archive' => true,
        'hierarchical' => true, 'menu_icon' => 'dashicons-video-alt', 'taxonomies' => ['genre'],
    ]);
    assert_true(!$args['public']);
    assert_eq(['title'], $args['supports']);
    assert_true($args['has_archive']);
    assert_true($args['hierarchical']);
    assert_eq('dashicons-video-alt', $args['menu_icon']);
    assert_eq(['genre'], $args['taxonomies']);
    assert_true($args['show_in_rest'], 'show_in_rest forced true even when public=false');
});

it('builds taxonomy args with defaults and forced show_in_rest', function () {
    $args = wpultra_structure_build_taxonomy_args(['slug' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres']);
    assert_eq('Genres', $args['label']);
    assert_true($args['public']);
    assert_true($args['show_in_rest']);
    assert_true(!$args['hierarchical']);
});

it('builds hierarchical taxonomy args', function () {
    $args = wpultra_structure_build_taxonomy_args(['slug' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'hierarchical' => true]);
    assert_true($args['hierarchical']);
});

// ---------------------------------------------------------------------------
// Registration validation
// ---------------------------------------------------------------------------

it('rejects invalid slug on registration', function () {
    $r = wpultra_structure_validate_registration(['slug' => 'bad slug!', 'singular' => 'X', 'plural' => 'Xs']);
    assert_wp_error($r);
    assert_eq('invalid_slug', $r->get_error_code());
});

it('rejects reserved post type slugs', function () {
    $r = wpultra_structure_validate_registration(['slug' => 'wpultra_memory', 'singular' => 'X', 'plural' => 'Xs']);
    assert_wp_error($r);
    assert_eq('reserved_post_type', $r->get_error_code());
});

it('rejects missing labels', function () {
    $r = wpultra_structure_validate_registration(['slug' => 'book', 'singular' => '', 'plural' => '']);
    assert_wp_error($r);
    assert_eq('missing_labels', $r->get_error_code());
});

it('requires object_types for taxonomy registration', function () {
    $r = wpultra_structure_validate_registration(['slug' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres'], true);
    assert_wp_error($r);
    assert_eq('missing_object_types', $r->get_error_code());
});

it('accepts a valid CPT registration request', function () {
    $r = wpultra_structure_validate_registration(['slug' => 'book', 'singular' => 'Book', 'plural' => 'Books']);
    assert_true($r === true);
});

it('accepts a valid taxonomy registration request with object_types', function () {
    $r = wpultra_structure_validate_registration(
        ['slug' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'object_types' => ['book']],
        true
    );
    assert_true($r === true);
});

// ---------------------------------------------------------------------------
// Persisted registration (uses get_option/update_option stubs above)
// ---------------------------------------------------------------------------

it('persists a CPT definition to the option and registers it', function () {
    $GLOBALS['__opts'] = [];
    $r = wpultra_structure_register_cpt_persist(['slug' => 'book', 'singular' => 'Book', 'plural' => 'Books']);
    assert_true(!is_wp_error($r));
    assert_eq('book', $r['slug']);
    $stored = get_option('wpultra_registered_cpts', []);
    assert_true(isset($stored['book']), 'CPT def should be stored under its slug');
});

it('persists a taxonomy definition with object_types', function () {
    $GLOBALS['__opts'] = [];
    $r = wpultra_structure_register_taxonomy_persist(['slug' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'object_types' => ['book']]);
    assert_true(!is_wp_error($r));
    $stored = get_option('wpultra_registered_taxonomies', []);
    assert_true(isset($stored['genre']));
    assert_eq(['book'], $stored['genre']['object_types']);
});

it('register_persisted loader replays stored CPT/taxonomy defs without fataling', function () {
    $GLOBALS['__opts'] = [
        'wpultra_registered_cpts'       => ['book' => ['label' => 'Books']],
        'wpultra_registered_taxonomies' => ['genre' => ['args' => ['label' => 'Genres'], 'object_types' => ['book']]],
    ];
    wpultra_structure_register_persisted(); // must not throw/fatal
    assert_true(true);
});

// ---------------------------------------------------------------------------
// Menu item shaping + tree building (pure)
// ---------------------------------------------------------------------------

it('shapes a menu item from an array', function () {
    $i = wpultra_structure_shape_menu_item(['ID' => 3, 'title' => 'Home', 'url' => '/', 'menu_item_parent' => 0, 'menu_order' => 1, 'object' => 'page', 'object_id' => 10]);
    assert_eq(3, $i['id']);
    assert_eq('Home', $i['title']);
    assert_eq(0, $i['parent']);
    assert_eq(1, $i['position']);
    assert_eq('page', $i['object']);
});

it('builds a nested menu tree ordered by position', function () {
    $items = [
        ['id' => 1, 'title' => 'Home', 'url' => '/', 'parent' => 0, 'position' => 1, 'object' => '', 'object_id' => 0, 'type' => 'custom'],
        ['id' => 2, 'title' => 'About', 'url' => '/about', 'parent' => 0, 'position' => 2, 'object' => '', 'object_id' => 0, 'type' => 'custom'],
        ['id' => 3, 'title' => 'Team', 'url' => '/about/team', 'parent' => 2, 'position' => 1, 'object' => '', 'object_id' => 0, 'type' => 'custom'],
        ['id' => 4, 'title' => 'History', 'url' => '/about/history', 'parent' => 2, 'position' => 2, 'object' => '', 'object_id' => 0, 'type' => 'custom'],
    ];
    $tree = wpultra_structure_build_menu_tree($items);
    assert_eq(2, count($tree));
    assert_eq('Home', $tree[0]['title']);
    assert_eq('About', $tree[1]['title']);
    assert_eq(2, count($tree[1]['children']));
    assert_eq('Team', $tree[1]['children'][0]['title']);
    assert_eq('History', $tree[1]['children'][1]['title']);
});

it('treats an orphaned parent reference as a root item', function () {
    $items = [
        ['id' => 1, 'title' => 'Orphan', 'url' => '/o', 'parent' => 999, 'position' => 1, 'object' => '', 'object_id' => 0, 'type' => 'custom'],
    ];
    $tree = wpultra_structure_build_menu_tree($items);
    assert_eq(1, count($tree));
    assert_eq('Orphan', $tree[0]['title']);
});

it('builds wp_update_nav_menu_item args from a unified item (custom link)', function () {
    $args = wpultra_structure_menu_item_args(['title' => 'Docs', 'url' => 'https://x.test']);
    assert_eq('Docs', $args['menu-item-title']);
    assert_eq('https://x.test', $args['menu-item-url']);
    assert_eq('publish', $args['menu-item-status']);
});

it('builds wp_update_nav_menu_item args for a post link', function () {
    $args = wpultra_structure_menu_item_args(['title' => 'Blog', 'object_id' => 42, 'object_type' => 'post', 'object' => 'page']);
    assert_eq(42, $args['menu-item-object-id']);
    assert_eq('post_type', $args['menu-item-type']);
    assert_eq('page', $args['menu-item-object']);
});

it('builds wp_update_nav_menu_item args for a term link', function () {
    $args = wpultra_structure_menu_item_args(['title' => 'News', 'object_id' => 9, 'object_type' => 'term', 'object' => 'category']);
    assert_eq('taxonomy', $args['menu-item-type']);
});

run_tests();
