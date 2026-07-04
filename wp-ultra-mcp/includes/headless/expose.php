<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — GraphQL schema exposure (Roadmap-3, H1.5).
 *
 * WPGraphQL only shows post types / taxonomies registered with
 * `show_in_graphql` + graphql names — which plugin-created CPTs (WP-Ultra
 * verticals, register-cpt, JetEngine, third-party) almost never set. The
 * exposure config lives in the wpultra_headless_expose option; runtime filters
 * on register_post_type_args / register_taxonomy_args inject the GraphQL args
 * for configured slugs no matter WHO registers them. Theme tokens
 * (palette/font sizes) get a themeTokens root field.
 */

/**
 * Derive GraphQL single/plural type names from a slug. Pure. WPGraphQL needs
 * distinct camelCase names; explicit names always win over derivation.
 * @return array{single:string,plural:string}
 */
function wpultra_headless_graphql_names(string $slug, string $single = '', string $plural = ''): array {
    if ($single === '' || $plural === '') {
        $parts = preg_split('/[_\-\s]+/', strtolower($slug)) ?: [];
        $camel = '';
        foreach (array_values(array_filter($parts)) as $i => $p) {
            $camel .= $i === 0 ? $p : ucfirst($p);
        }
        if ($single === '') { $single = $camel; }
        if ($plural === '') {
            $plural = preg_match('/(s|x|z|ch|sh)$/', $camel) ? $camel . 'es' : $camel . 's';
        }
    }
    return ['single' => $single, 'plural' => $plural];
}

/**
 * Add exposures to the stored config. Pure over the option value.
 * @param array $current  option shape: [post_types => [slug => {single,plural}], taxonomies => [...]]
 * @param string $kind    'post_types' | 'taxonomies'
 * @param array<int,array{slug:string,single?:string,plural?:string}> $items
 */
function wpultra_headless_expose_merge(array $current, string $kind, array $items): array {
    foreach ($items as $item) {
        $slug = (string) ($item['slug'] ?? '');
        if ($slug === '') { continue; }
        $current[$kind][$slug] = wpultra_headless_graphql_names($slug, (string) ($item['single'] ?? ''), (string) ($item['plural'] ?? ''));
    }
    return $current;
}

/** Remove exposures by slug. Pure. */
function wpultra_headless_expose_remove(array $current, string $kind, array $slugs): array {
    foreach ($slugs as $slug) { unset($current[$kind][(string) $slug]); }
    return $current;
}

/**
 * Inject GraphQL registration args for a configured slug. Pure. Args the
 * registering plugin already set (its own show_in_graphql / graphql names)
 * are never clobbered.
 */
function wpultra_headless_expose_args(array $args, string $slug, string $kind, array $config): array {
    $c = $config[$kind][$slug] ?? null;
    if (!is_array($c)) { return $args; }
    if (!array_key_exists('show_in_graphql', $args)) { $args['show_in_graphql'] = true; }
    if (!array_key_exists('graphql_single_name', $args)) { $args['graphql_single_name'] = (string) ($c['single'] ?? ''); }
    if (!array_key_exists('graphql_plural_name', $args)) { $args['graphql_plural_name'] = (string) ($c['plural'] ?? ''); }
    return $args;
}

/**
 * Flatten wp_get_global_settings()-shaped data into design-token lists. Pure.
 * @return array{colors:array,fontSizes:array}
 */
function wpultra_headless_shape_tokens(array $settings): array {
    $pick = static function ($group, string $value_key): array {
        $out = [];
        foreach (['theme', 'custom', 'default'] as $origin) {
            foreach ((array) ($group[$origin] ?? []) as $row) {
                if (!is_array($row)) { continue; }
                $out[] = [
                    'id'    => (string) ($row['slug'] ?? ''),
                    'label' => (string) ($row['name'] ?? ($row['slug'] ?? '')),
                    'value' => (string) ($row[$value_key] ?? ''),
                ];
            }
            if ($out !== []) { break; } // prefer theme tokens; fall back to custom/default
        }
        return $out;
    };
    return [
        'colors'    => $pick($settings['color']['palette'] ?? [], 'color'),
        'fontSizes' => $pick($settings['typography']['fontSizes'] ?? [], 'size'),
    ];
}

/** The stored exposure config. */
function wpultra_headless_expose_config(): array {
    $v = function_exists('get_option') ? get_option('wpultra_headless_expose', []) : [];
    return is_array($v) ? $v : [];
}

/**
 * Runtime boot: the register-args filters (must run on EVERY request so the
 * types are in the schema for front-end /graphql calls) + the themeTokens
 * GraphQL field. Called from wpultra_headless_boot().
 */
function wpultra_headless_expose_boot(): void {
    add_filter('register_post_type_args', function ($args, $post_type) {
        return wpultra_headless_expose_args((array) $args, (string) $post_type, 'post_types', wpultra_headless_expose_config());
    }, 20, 2);
    add_filter('register_taxonomy_args', function ($args, $taxonomy) {
        return wpultra_headless_expose_args((array) $args, (string) $taxonomy, 'taxonomies', wpultra_headless_expose_config());
    }, 20, 2);
    // themeTokens root field: theme.json palette + font sizes, queryable by the frontend.
    add_action('graphql_register_types', function () {
        if (!function_exists('register_graphql_object_type') || !function_exists('register_graphql_field')) { return; }
        register_graphql_object_type('WPUltraToken', [
            'description' => 'A design token (color, font size) from the active theme.',
            'fields' => [
                'id'    => ['type' => 'String'],
                'label' => ['type' => 'String'],
                'value' => ['type' => 'String'],
            ],
        ]);
        register_graphql_object_type('WPUltraThemeTokens', [
            'description' => 'Design tokens of the active theme, for headless frontends to mirror the WP design system.',
            'fields' => [
                'colors'    => ['type' => ['list_of' => 'WPUltraToken']],
                'fontSizes' => ['type' => ['list_of' => 'WPUltraToken']],
            ],
        ]);
        register_graphql_field('RootQuery', 'themeTokens', [
            'type'        => 'WPUltraThemeTokens',
            'description' => 'Active theme design tokens (colors, font sizes).',
            'resolve'     => static function () {
                $settings = function_exists('wp_get_global_settings') ? (array) wp_get_global_settings() : [];
                return wpultra_headless_shape_tokens($settings);
            },
        ]);
    });
}
