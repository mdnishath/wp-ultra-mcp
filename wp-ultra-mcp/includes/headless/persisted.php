<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — persisted/allowlisted GraphQL queries (Roadmap-3, H3.5).
 *
 * Rides WPGraphQL Smart Cache's document store: saved documents are
 * `graphql_document` posts whose terms carry the alias (queryId), the
 * allow/deny grant, and a per-query HTTP max-age. In "allow-only" lock mode
 * the endpoint refuses arbitrary queries — production gets cached,
 * pre-approved queries only.
 */

/** Sanitize a query alias (queryId). Explicit alias wins; else slugified name. Pure. */
function wpultra_headless_pq_alias(string $alias, string $name): string {
    $pick = $alias !== '' ? $alias : $name;
    $pick = strtolower(trim($pick));
    $pick = (string) preg_replace('/\s+/', '-', $pick);
    $pick = (string) preg_replace('/[^a-z0-9_-]/', '', $pick);
    return trim($pick, '-');
}

/**
 * Validate + normalize save-input. Pure.
 * @return array{query:string,name:string,alias:string,grant:string,max_age:int,operation:string}|string
 */
function wpultra_headless_pq_validate(array $input) {
    $query = trim((string) ($input['query'] ?? ''));
    if ($query === '') { return 'Provide the GraphQL document in `query`.'; }
    $name  = trim((string) ($input['name'] ?? ''));
    $alias = wpultra_headless_pq_alias((string) ($input['alias'] ?? ''), $name);
    if ($alias === '') { return 'Provide a `name` or `alias` — it becomes the queryId the frontend requests.'; }
    $grant = (string) ($input['grant'] ?? 'default');
    if (!in_array($grant, ['allow', 'deny', 'default'], true)) {
        return "grant must be allow, deny, or default (got '$grant').";
    }
    $max_age = max(0, (int) ($input['max_age'] ?? 0));
    $operation = function_exists('wpultra_headless_operation_type') ? wpultra_headless_operation_type($query) : '';
    return [
        'query'     => $query,
        'name'      => $name !== '' ? $name : $alias,
        'alias'     => $alias,
        'grant'     => $grant,
        'max_age'   => $max_age,
        'operation' => $operation,
    ];
}

/** True when Smart Cache's document store is available. */
function wpultra_headless_pq_available(): bool {
    return post_type_exists('graphql_document') && taxonomy_exists('graphql_query_alias');
}

/** Find a saved document post by alias term. @return int 0 when absent */
function wpultra_headless_pq_find(string $alias): int {
    $q = get_posts([
        'post_type'      => 'graphql_document',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'tax_query'      => [['taxonomy' => 'graphql_query_alias', 'field' => 'name', 'terms' => $alias]],
    ]);
    return $q ? (int) $q[0] : 0;
}

/** Save (insert or update-by-alias) a persisted document. @return array|WP_Error */
function wpultra_headless_pq_save(array $v) {
    $existing = wpultra_headless_pq_find($v['alias']);
    $postarr = [
        'post_type'    => 'graphql_document',
        'post_status'  => 'publish',
        'post_title'   => $v['name'],
        'post_content' => $v['query'],
    ];
    if ($existing > 0) { $postarr['ID'] = $existing; }
    $id = wp_insert_post($postarr, true);
    if (is_wp_error($id)) { return $id; }
    // Two alias terms: the friendly queryId the frontend requests, and the
    // normalized sha256 hash — Smart Cache's allow-only validator looks the
    // document up BY THE HASH, so lock mode fails without it.
    $aliases = [$v['alias']];
    if (class_exists('\\WPGraphQL\\SmartCache\\Utils')) {
        try {
            $hash = (string) \WPGraphQL\SmartCache\Utils::generateHash($v['query']);
            if ($hash !== '') { $aliases[] = $hash; }
        } catch (\Throwable $e) { /* hash alias is an optimization for lock mode; the friendly alias still works */ }
    }
    wp_set_object_terms((int) $id, $aliases, 'graphql_query_alias', false);
    if (taxonomy_exists('graphql_document_grant')) {
        wp_set_object_terms((int) $id, $v['grant'] === 'default' ? [] : [$v['grant']], 'graphql_document_grant', false);
    }
    if (taxonomy_exists('graphql_document_http_maxage')) {
        wp_set_object_terms((int) $id, $v['max_age'] > 0 ? [(string) $v['max_age']] : [], 'graphql_document_http_maxage', false);
    }
    return ['id' => (int) $id, 'alias' => $v['alias'], 'updated' => $existing > 0];
}

/** Shape one saved document for listing. */
function wpultra_headless_pq_shape(WP_Post $post): array {
    $terms = static function (string $tax) use ($post): array {
        if (!taxonomy_exists($tax)) { return []; }
        $t = get_the_terms($post->ID, $tax);
        return is_array($t) ? array_values(array_map(static fn($x): string => (string) $x->name, $t)) : [];
    };
    $grant = $terms('graphql_document_grant');
    $ages  = $terms('graphql_document_http_maxage');
    return [
        'id'      => (int) $post->ID,
        'name'    => (string) $post->post_title,
        'aliases' => $terms('graphql_query_alias'),
        'grant'   => $grant !== [] ? $grant[0] : 'default',
        'max_age' => $ages !== [] ? (int) $ages[0] : 0,
        'query'   => (string) $post->post_content,
    ];
}

/** Current lock state: 'allow_only' | 'public' (Smart Cache grant_mode setting). */
function wpultra_headless_pq_lock_state(): string {
    $section = get_option('graphql_persisted_queries_section', []);
    $mode = is_array($section) ? (string) ($section['grant_mode'] ?? 'public') : 'public';
    return $mode === 'only_allowed' ? 'allow_only' : 'public';
}

/** Set lock mode: true = only allowlisted documents may execute. */
function wpultra_headless_pq_set_lock(bool $lock): void {
    $section = get_option('graphql_persisted_queries_section', []);
    if (!is_array($section)) { $section = []; }
    $section['grant_mode'] = $lock ? 'only_allowed' : 'public';
    update_option('graphql_persisted_queries_section', $section);
}
