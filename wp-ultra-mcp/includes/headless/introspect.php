<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — GraphQL schema introspection (Roadmap-3, H1.3).
 *
 * Runs the standard introspection query through WPGraphQL's server-side
 * graphql() executor and shapes the result into compact, filterable pieces so
 * responses stay small. All shaping functions are pure over introspection JSON.
 */

/** Render an introspection type-ref (NON_NULL/LIST nesting) as a GraphQL type string, e.g. "[Post!]!". */
function wpultra_headless_render_typeref(array $ref): string {
    $kind = (string) ($ref['kind'] ?? '');
    if ($kind === 'NON_NULL') { return wpultra_headless_render_typeref((array) ($ref['ofType'] ?? [])) . '!'; }
    if ($kind === 'LIST') { return '[' . wpultra_headless_render_typeref((array) ($ref['ofType'] ?? [])) . ']'; }
    $name = (string) ($ref['name'] ?? '');
    return $name !== '' ? $name : '?';
}

/** "name: Type" strings for an introspection args list. */
function wpultra_headless_render_args(array $args): array {
    return array_values(array_map(
        static fn(array $a): string => (string) ($a['name'] ?? '') . ': ' . wpultra_headless_render_typeref((array) ($a['type'] ?? [])),
        $args
    ));
}

/**
 * Compact one introspection type: fields/inputFields/enumValues flattened to
 * name + rendered type strings. Missing sections are simply omitted.
 */
function wpultra_headless_shape_type(array $t): array {
    $out = [
        'name'        => (string) ($t['name'] ?? ''),
        'kind'        => (string) ($t['kind'] ?? ''),
        'description' => (string) ($t['description'] ?? ''),
    ];
    if (isset($t['fields']) && is_array($t['fields'])) {
        $out['fields'] = array_values(array_map(static fn(array $f): array => [
            'name' => (string) ($f['name'] ?? ''),
            'type' => wpultra_headless_render_typeref((array) ($f['type'] ?? [])),
            'args' => wpultra_headless_render_args((array) ($f['args'] ?? [])),
        ], $t['fields']));
    }
    if (isset($t['inputFields']) && is_array($t['inputFields'])) {
        $out['inputFields'] = array_values(array_map(static fn(array $f): array => [
            'name' => (string) ($f['name'] ?? ''),
            'type' => wpultra_headless_render_typeref((array) ($f['type'] ?? [])),
        ], $t['inputFields']));
    }
    if (isset($t['enumValues']) && is_array($t['enumValues'])) {
        $out['enumValues'] = array_values(array_map(static fn(array $v): string => (string) ($v['name'] ?? ''), $t['enumValues']));
    }
    if (isset($t['interfaces']) && is_array($t['interfaces'])) {
        $ifaces = array_values(array_filter(array_map(static fn(array $i): string => (string) ($i['name'] ?? ''), $t['interfaces'])));
        if ($ifaces !== []) { $out['interfaces'] = $ifaces; }
    }
    return $out;
}

/** Drop __internal types, then narrow by case-insensitive name search and/or kind. */
function wpultra_headless_filter_types(array $types, string $search, string $kind): array {
    $out = [];
    foreach ($types as $t) {
        $name = (string) ($t['name'] ?? '');
        if ($name === '' || str_starts_with($name, '__')) { continue; }
        if ($search !== '' && stripos($name, $search) === false) { continue; }
        if ($kind !== '' && (string) ($t['kind'] ?? '') !== strtoupper($kind)) { continue; }
        $out[] = $t;
    }
    return array_values($out);
}

/** Small overview: root type names, non-internal type count, count per kind. */
function wpultra_headless_schema_summary(array $schema): array {
    $kinds = [];
    $count = 0;
    foreach ((array) ($schema['types'] ?? []) as $t) {
        $name = (string) ($t['name'] ?? '');
        if ($name === '' || str_starts_with($name, '__')) { continue; }
        $count++;
        $k = (string) ($t['kind'] ?? '');
        $kinds[$k] = ($kinds[$k] ?? 0) + 1;
    }
    return [
        'query_type'    => (string) ($schema['queryType']['name'] ?? ''),
        'mutation_type' => (string) ($schema['mutationType']['name'] ?? ''),
        'type_count'    => $count,
        'kinds'         => $kinds,
    ];
}

/** Root query/mutation fields shaped to name/return-type/args, with a name search. */
function wpultra_headless_shape_root_fields(array $fields, string $search): array {
    $out = [];
    foreach ($fields as $f) {
        $name = (string) ($f['name'] ?? '');
        if ($search !== '' && stripos($name, $search) === false) { continue; }
        $out[] = [
            'name' => $name,
            'type' => wpultra_headless_render_typeref((array) ($f['type'] ?? [])),
            'args' => wpultra_headless_render_args((array) ($f['args'] ?? [])),
        ];
    }
    return array_values($out);
}

/** The standard full introspection query (type-ref fragment nested 7 deep, spec-style). */
function wpultra_headless_introspection_query(): string {
    return <<<'GQL'
    query WPUltraIntrospection {
      __schema {
        queryType { name }
        mutationType { name }
        types {
          kind name description
          fields(includeDeprecated: false) {
            name
            args { name type { ...TypeRef } }
            type { ...TypeRef }
          }
          inputFields { name type { ...TypeRef } }
          enumValues(includeDeprecated: false) { name }
          interfaces { name }
        }
      }
    }
    fragment TypeRef on __Type {
      kind name
      ofType { kind name ofType { kind name ofType { kind name ofType { kind name ofType { kind name ofType { kind name ofType { kind name } } } } } } }
    }
    GQL;
}

/**
 * Execute the introspection query through WPGraphQL's server-side executor.
 * @return array|WP_Error  the __schema array
 */
function wpultra_headless_run_introspection() {
    if (!function_exists('graphql')) {
        return wpultra_err('graphql_missing', 'WPGraphQL is not active — run headless-setup first.');
    }
    try {
        $res = graphql(['query' => wpultra_headless_introspection_query()]);
    } catch (\Throwable $e) {
        return wpultra_err('introspection_failed', 'Introspection threw: ' . $e->getMessage());
    }
    if (!is_array($res) || !empty($res['errors'])) {
        $msg = is_array($res) ? (string) ($res['errors'][0]['message'] ?? 'unknown error') : 'non-array result';
        return wpultra_err('introspection_failed', 'Introspection failed: ' . $msg);
    }
    $schema = $res['data']['__schema'] ?? null;
    if (!is_array($schema)) { return wpultra_err('introspection_failed', 'Introspection returned no __schema.'); }
    return $schema;
}
