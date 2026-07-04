<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — REST fallback bundle (Roadmap-3, H1.6).
 *
 * For teams that skip GraphQL: public, stable-shaped REST routes under
 * wpultra/headless/v1 for the things core REST doesn't expose cleanly —
 * nav menus (+items), site settings, theme tokens, and public custom fields.
 * Off by default; the headless-rest-bundle ability toggles it.
 */

/**
 * Shape the stored REST-bundle option. Pure. Disabled by default; every route
 * defaults ON once enabled (individual routes can be switched off).
 * @param mixed $raw
 * @return array{enabled:bool,routes:array{menus:bool,settings:bool,tokens:bool,fields:bool}}
 */
function wpultra_headless_rest_shape_config($raw): array {
    $routes = ['menus' => true, 'settings' => true, 'tokens' => true, 'fields' => true];
    $enabled = false;
    if (is_array($raw)) {
        $enabled = !empty($raw['enabled']);
        foreach (array_keys($routes) as $r) {
            if (isset($raw['routes'][$r])) { $routes[$r] = (bool) $raw['routes'][$r]; }
        }
    }
    return ['enabled' => $enabled, 'routes' => $routes];
}

/** The live REST-bundle config. */
function wpultra_headless_rest_config(): array {
    return wpultra_headless_rest_shape_config(function_exists('get_option') ? get_option('wpultra_headless_rest', []) : []);
}

/**
 * Flatten nav-menu-item rows into the documented stable shape. Pure over
 * arrays (wp_get_nav_menu_items objects are cast before calling).
 * @param array<int,array<string,mixed>> $rows
 */
function wpultra_headless_shape_menu_items(array $rows): array {
    return array_values(array_map(static fn(array $r): array => [
        'id'      => (int) ($r['ID'] ?? 0),
        'parent'  => (int) ($r['menu_item_parent'] ?? 0),
        'order'   => (int) ($r['menu_order'] ?? 0),
        'label'   => (string) ($r['title'] ?? ''),
        'url'     => (string) ($r['url'] ?? ''),
        'target'  => (string) ($r['target'] ?? ''),
        'classes' => array_values(array_filter(array_map('strval', (array) ($r['classes'] ?? [])), static fn(string $c): bool => $c !== '')),
    ], $rows));
}

/**
 * Filter a raw get_post_meta() map down to public fields. Pure. Underscore
 * (internal) keys are dropped, single-value arrays flattened, serialized
 * values decoded (objects forbidden — data only).
 * @param array<string,array<int,mixed>> $meta
 */
function wpultra_headless_public_meta(array $meta): array {
    $out = [];
    foreach ($meta as $key => $values) {
        if ($key === '' || $key[0] === '_') { continue; }
        $values = array_map(static function ($v) {
            if (is_string($v)) {
                $u = @unserialize($v, ['allowed_classes' => false]);
                if ($u !== false || $v === 'b:0;') { return $u; }
            }
            return $v;
        }, (array) $values);
        $out[$key] = count($values) === 1 ? $values[0] : array_values($values);
    }
    return $out;
}

/** Register the public REST routes (called on rest_api_init when enabled). */
function wpultra_headless_rest_register_routes(): void {
    $cfg = wpultra_headless_rest_config();
    if (!$cfg['enabled']) { return; }
    $ns = 'wpultra/headless/v1';

    if ($cfg['routes']['menus']) {
        register_rest_route($ns, '/menus', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => static function () {
                $menus = [];
                foreach (wp_get_nav_menus() as $menu) {
                    $items = wp_get_nav_menu_items($menu->term_id) ?: [];
                    $menus[] = [
                        'id'        => (int) $menu->term_id,
                        'slug'      => (string) $menu->slug,
                        'name'      => (string) $menu->name,
                        'locations' => array_keys(array_filter(get_nav_menu_locations(), static fn($id) => (int) $id === (int) $menu->term_id)),
                        'items'     => wpultra_headless_shape_menu_items(array_map(static fn($i): array => (array) $i, $items)),
                    ];
                }
                return ['menus' => $menus];
            },
        ]);
    }
    if ($cfg['routes']['settings']) {
        register_rest_route($ns, '/settings', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => static function () {
                return ['settings' => [
                    'title'          => (string) get_option('blogname', ''),
                    'description'    => (string) get_option('blogdescription', ''),
                    'url'            => (string) home_url(),
                    'language'       => (string) get_locale(),
                    'timezone'       => (string) (get_option('timezone_string') ?: get_option('gmt_offset', '0')),
                    'posts_per_page' => (int) get_option('posts_per_page', 10),
                    'site_icon'      => (string) (function_exists('get_site_icon_url') ? get_site_icon_url() : ''),
                ]];
            },
        ]);
    }
    if ($cfg['routes']['tokens']) {
        register_rest_route($ns, '/tokens', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => static function () {
                $settings = function_exists('wp_get_global_settings') ? (array) wp_get_global_settings() : [];
                return ['tokens' => wpultra_headless_shape_tokens($settings)];
            },
        ]);
    }
    if ($cfg['routes']['fields']) {
        register_rest_route($ns, '/fields/(?P<id>\d+)', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'args'    => ['id' => ['validate_callback' => static fn($v): bool => is_numeric($v)]],
            'callback' => static function ($request) {
                $id   = (int) $request['id'];
                $post = get_post($id);
                if (!$post || $post->post_status !== 'publish' || !empty($post->post_password)) {
                    return new WP_Error('not_found', 'No public post with that id.', ['status' => 404]);
                }
                // ACF formats values (image arrays, repeaters); otherwise fall back to public raw meta.
                $fields = function_exists('get_fields') ? (get_fields($id) ?: []) : wpultra_headless_public_meta((array) get_post_meta($id));
                return ['id' => $id, 'fields' => $fields];
            },
        ]);
    }
}
