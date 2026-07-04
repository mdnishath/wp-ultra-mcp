<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/graphql-introspect', [
    'label'       => __('GraphQL: Introspect Schema', 'wp-ultra-mcp'),
    'description' => __('Read the live WPGraphQL schema, filterable so responses stay small. modes: `summary` (root types + type counts), `types` (type names, filter with search/kind), `type` (full detail for one named type: fields, args, enum values), `queries` / `mutations` (root fields with args + return types, filter with search). Read the schema BEFORE writing queries — this is how the AI learns exactly what this site exposes.', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'mode'   => ['type' => 'string', 'enum' => ['summary', 'types', 'type', 'queries', 'mutations'], 'default' => 'summary'],
            'type'   => ['type' => 'string', 'description' => 'Type name for mode:type, e.g. "Post".'],
            'search' => ['type' => 'string', 'description' => 'Case-insensitive name filter for types/queries/mutations.'],
            'kind'   => ['type' => 'string', 'enum' => ['OBJECT', 'INTERFACE', 'ENUM', 'INPUT_OBJECT', 'SCALAR', 'UNION'], 'description' => 'Kind filter for mode:types.'],
            'limit'  => ['type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 1000],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'mode'    => ['type' => 'string'],
            'summary' => ['type' => 'object'],
            'types'   => ['type' => 'array'],
            'type'    => ['type' => 'object'],
            'fields'  => ['type' => 'array'],
            'total'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_graphql_introspect_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_graphql_introspect_cb(array $input) {
    $schema = wpultra_headless_run_introspection();
    if (is_wp_error($schema)) { return $schema; }

    $mode   = (string) ($input['mode'] ?? 'summary');
    $search = (string) ($input['search'] ?? '');
    $limit  = max(1, min(1000, (int) ($input['limit'] ?? 100)));
    $types  = (array) ($schema['types'] ?? []);

    switch ($mode) {
        case 'summary':
            return wpultra_ok(['mode' => $mode, 'summary' => wpultra_headless_schema_summary($schema)]);

        case 'types':
            $filtered = wpultra_headless_filter_types($types, $search, (string) ($input['kind'] ?? ''));
            $names = array_map(static fn(array $t): array => [
                'name' => (string) ($t['name'] ?? ''),
                'kind' => (string) ($t['kind'] ?? ''),
            ], $filtered);
            return wpultra_ok(['mode' => $mode, 'total' => count($names), 'types' => array_slice($names, 0, $limit)]);

        case 'type':
            $want = (string) ($input['type'] ?? '');
            if ($want === '') { return wpultra_err('missing_type', 'mode:type needs a type name, e.g. type:"Post".'); }
            foreach ($types as $t) {
                if (strcasecmp((string) ($t['name'] ?? ''), $want) === 0) {
                    return wpultra_ok(['mode' => $mode, 'type' => wpultra_headless_shape_type((array) $t)]);
                }
            }
            return wpultra_err('type_not_found', "Type '$want' is not in the schema. Use mode:types with search to find the right name.");

        case 'queries':
        case 'mutations':
            $rootName = (string) ($schema[$mode === 'queries' ? 'queryType' : 'mutationType']['name'] ?? '');
            if ($rootName === '') { return wpultra_err('no_root', "The schema has no $mode root type."); }
            foreach ($types as $t) {
                if ((string) ($t['name'] ?? '') === $rootName) {
                    $fields = wpultra_headless_shape_root_fields((array) ($t['fields'] ?? []), $search);
                    return wpultra_ok(['mode' => $mode, 'total' => count($fields), 'fields' => array_slice($fields, 0, $limit)]);
                }
            }
            return wpultra_err('no_root', "Root type '$rootName' not found in the schema types.");
    }
    return wpultra_err('bad_mode', "Unknown mode '$mode'.");
}
