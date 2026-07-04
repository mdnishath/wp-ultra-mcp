<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-expose', [
    'label'       => __('Headless: Expose to GraphQL', 'wp-ultra-mcp'),
    'description' => __('Put plugin-created content into the GraphQL schema. actions: `list` (every post type + taxonomy with its GraphQL exposure state), `expose` (add show_in_graphql + camelCase type names for the given slugs — works for WP-Ultra CPTs, JetEngine, or any plugin\'s types), `unexpose` (remove). GraphQL names are derived from the slug when not given (wpultra_booking → wpultraBooking/wpultraBookings). Also registers a `themeTokens` root query (theme colors + font sizes) automatically. Schema changes apply from the next request.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'expose', 'unexpose'], 'default' => 'list'],
            'kind'   => ['type' => 'string', 'enum' => ['post_types', 'taxonomies'], 'description' => 'Which bucket expose/unexpose targets.'],
            'items'  => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'slug'   => ['type' => 'string'],
                    'single' => ['type' => 'string', 'description' => 'GraphQL single name (derived from slug when omitted).'],
                    'plural' => ['type' => 'string', 'description' => 'GraphQL plural name (derived when omitted).'],
                ],
                'required' => ['slug'],
                'additionalProperties' => false,
            ], 'description' => 'For action:expose.'],
            'slugs'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For action:unexpose.'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'post_types' => ['type' => 'array'],
            'taxonomies' => ['type' => 'array'],
            'config'     => ['type' => 'object'],
            'note'       => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_expose_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_expose_cb(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    $config = wpultra_headless_expose_config();

    if ($action === 'list') {
        $shape = static function (array $objects, string $kind) use ($config): array {
            $rows = [];
            foreach ($objects as $slug => $obj) {
                $in_graphql = !empty($obj->show_in_graphql);
                $rows[] = [
                    'slug'       => (string) $slug,
                    'label'      => (string) ($obj->label ?? $slug),
                    'in_graphql' => $in_graphql,
                    'graphql_name' => $in_graphql ? (string) ($obj->graphql_single_name ?? '') : '',
                    'configured' => isset($config[$kind][$slug]),
                    '_builtin'   => !empty($obj->_builtin),
                ];
            }
            return $rows;
        };
        return wpultra_ok([
            'post_types' => $shape(get_post_types([], 'objects'), 'post_types'),
            'taxonomies' => $shape(get_taxonomies([], 'objects'), 'taxonomies'),
            'config'     => $config,
        ]);
    }

    $kind = (string) ($input['kind'] ?? '');
    if (!in_array($kind, ['post_types', 'taxonomies'], true)) {
        return wpultra_err('missing_kind', "action:$action needs kind: post_types or taxonomies.");
    }

    if ($action === 'expose') {
        $items = array_values(array_filter((array) ($input['items'] ?? []), 'is_array'));
        if ($items === []) { return wpultra_err('missing_items', 'action:expose needs items:[{slug, single?, plural?}].'); }
        $registered = $kind === 'post_types' ? get_post_types() : get_taxonomies();
        foreach ($items as $item) {
            $slug = (string) ($item['slug'] ?? '');
            if (!isset($registered[$slug])) {
                return wpultra_err('unknown_slug', "'$slug' is not a registered " . ($kind === 'post_types' ? 'post type' : 'taxonomy') . '. Use action:list to see what exists.');
            }
        }
        $config = wpultra_headless_expose_merge($config, $kind, $items);
        update_option('wpultra_headless_expose', $config, false);
        return wpultra_ok(['config' => $config, 'note' => 'Exposed. The GraphQL schema includes these types from the next request — verify with graphql-introspect.']);
    }

    if ($action === 'unexpose') {
        $slugs = array_values(array_filter((array) ($input['slugs'] ?? []), 'is_string'));
        if ($slugs === []) { return wpultra_err('missing_slugs', 'action:unexpose needs slugs:[...].'); }
        $config = wpultra_headless_expose_remove($config, $kind, $slugs);
        update_option('wpultra_headless_expose', $config, false);
        return wpultra_ok(['config' => $config, 'note' => 'Removed from the schema as of the next request.']);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
