<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/graphql-persisted-queries', [
    'label'       => __('GraphQL: Persisted Queries', 'wp-ultra-mcp'),
    'description' => __('Production performance + security via WPGraphQL Smart Cache persisted queries. actions: `list` (saved documents with alias/grant/max-age), `save` (persist a document under an alias — the frontend then calls GET /graphql?queryId=<alias>, cacheable + no query parsing; grant: allow|deny|default, max_age seconds for the HTTP cache), `delete` (by alias), `lock` (confirm-gated: ONLY allowlisted documents may execute — arbitrary queries are refused), `unlock`, `status`. Requires WPGraphQL Smart Cache (headless-setup installs it).', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['status', 'list', 'save', 'delete', 'lock', 'unlock'], 'default' => 'status'],
            'query'   => ['type' => 'string', 'description' => 'The GraphQL document (for action:save).'],
            'name'    => ['type' => 'string', 'description' => 'Human name; also derives the alias when alias is omitted.'],
            'alias'   => ['type' => 'string', 'description' => 'The queryId the frontend requests.'],
            'grant'   => ['type' => 'string', 'enum' => ['allow', 'deny', 'default'], 'default' => 'default'],
            'max_age' => ['type' => 'integer', 'minimum' => 0, 'description' => 'HTTP cache max-age seconds for this query (0 = default).'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true for action:lock (it blocks every non-allowlisted query).'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'lock'      => ['type' => 'string'],
            'documents' => ['type' => 'array'],
            'saved'     => ['type' => 'object'],
            'note'      => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_graphql_pq_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_graphql_pq_cb(array $input) {
    if (!wpultra_headless_pq_available()) {
        return wpultra_err('smart_cache_missing', 'WPGraphQL Smart Cache is not active — run headless-setup (it installs it).');
    }
    $action = (string) ($input['action'] ?? 'status');

    if ($action === 'save') {
        $v = wpultra_headless_pq_validate($input);
        if (is_string($v)) { return wpultra_err('bad_input', $v); }
        $saved = wpultra_headless_pq_save($v);
        if (is_wp_error($saved)) { return $saved; }
        $endpoint = trailingslashit(home_url()) . (string) apply_filters('graphql_endpoint', 'graphql');
        $note = 'Request it with GET ' . $endpoint . '?queryId=' . $v['alias'];
        if ($v['operation'] === 'mutation') { $note .= ' — note: this document is a MUTATION; persisted mutations still execute with POST.'; }
        return wpultra_ok(['saved' => $saved, 'lock' => wpultra_headless_pq_lock_state(), 'note' => $note]);
    }

    if ($action === 'delete') {
        $alias = wpultra_headless_pq_alias((string) ($input['alias'] ?? ''), (string) ($input['name'] ?? ''));
        if ($alias === '') { return wpultra_err('missing_alias', 'Pass the alias (queryId) to delete.'); }
        $id = wpultra_headless_pq_find($alias);
        if ($id === 0) { return wpultra_err('not_found', "No persisted document with alias '$alias'."); }
        wp_delete_post($id, true);
        return wpultra_ok(['lock' => wpultra_headless_pq_lock_state(), 'note' => "Deleted '$alias' (post $id)."]);
    }

    if ($action === 'lock') {
        if (($input['confirm'] ?? false) !== true) {
            return wpultra_err('unconfirmed', 'lock refuses EVERY query that is not an allowlisted persisted document — the frontend must only use queryIds. Re-run with confirm:true.');
        }
        wpultra_headless_pq_set_lock(true);
        return wpultra_ok(['lock' => wpultra_headless_pq_lock_state(), 'note' => 'Locked: only grant:allow documents execute now.']);
    }
    if ($action === 'unlock') {
        wpultra_headless_pq_set_lock(false);
        return wpultra_ok(['lock' => wpultra_headless_pq_lock_state(), 'note' => 'Unlocked: arbitrary queries execute again.']);
    }

    // status / list
    $docs = [];
    if ($action === 'list' || $action === 'status') {
        foreach (get_posts(['post_type' => 'graphql_document', 'post_status' => 'any', 'posts_per_page' => 100]) as $p) {
            $doc = wpultra_headless_pq_shape($p);
            if ($action === 'status') { unset($doc['query']); }
            $docs[] = $doc;
        }
    }
    return wpultra_ok(['lock' => wpultra_headless_pq_lock_state(), 'documents' => $docs]);
}
