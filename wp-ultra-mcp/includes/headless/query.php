<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — GraphQL query execution (Roadmap-3, H1.4).
 *
 * Executes documents through WPGraphQL's server-side graphql() function (no
 * HTTP round-trip, runs as the authenticated MCP user). The operation-type
 * detector is pure so the mutation confirm-gate is unit-testable.
 */

/**
 * Classify a GraphQL document: 'query' | 'mutation' | 'subscription' | ''.
 * Pure. Strings and comments are stripped first so "mutation" inside a search
 * argument or a # comment can't fool the gate; keywords only count at top level
 * (brace depth 0). Any top-level mutation makes the whole document a mutation.
 */
function wpultra_headless_operation_type(string $query): string {
    // Block strings, then regular strings, then # comments.
    $q = (string) preg_replace('/"""(?:[^"]|"(?!""))*"""/s', '""', $query);
    $q = (string) preg_replace('/"(?:\\\\.|[^"\\\\])*"/s', '""', $q);
    $q = (string) preg_replace('/#[^\r\n]*/', '', $q);

    $depth = 0;
    $keywords = [];
    $has_toplevel_brace = false;
    $len = strlen($q);
    $i = 0;
    while ($i < $len) {
        $ch = $q[$i];
        if ($ch === '{') {
            if ($depth === 0) { $has_toplevel_brace = true; }
            $depth++; $i++; continue;
        }
        if ($ch === '}') { $depth = max(0, $depth - 1); $i++; continue; }
        if ($depth === 0 && (ctype_alpha($ch) || $ch === '_')) {
            $j = $i;
            while ($j < $len && (ctype_alnum($q[$j]) || $q[$j] === '_')) { $j++; }
            $keywords[] = substr($q, $i, $j - $i);
            $i = $j; continue;
        }
        $i++;
    }
    if (in_array('mutation', $keywords, true)) { return 'mutation'; }
    if (in_array('subscription', $keywords, true)) { return 'subscription'; }
    if (in_array('query', $keywords, true) || $has_toplevel_brace) { return 'query'; }
    return '';
}

/**
 * Run a GraphQL document through WPGraphQL's server-side executor.
 * @param array<string,mixed> $variables
 * @return array|WP_Error  ['data' => ..., 'errors' => [...]] (errors key only when present)
 */
function wpultra_headless_run_graphql(string $query, array $variables = [], string $operation_name = '') {
    if (!function_exists('graphql')) {
        return wpultra_err('graphql_missing', 'WPGraphQL is not active — run headless-setup first.');
    }
    $req = ['query' => $query];
    if ($variables !== []) { $req['variables'] = $variables; }
    if ($operation_name !== '') { $req['operation_name'] = $operation_name; }
    try {
        $res = graphql($req);
    } catch (\Throwable $e) {
        return wpultra_err('graphql_exception', 'GraphQL execution threw: ' . $e->getMessage());
    }
    if (!is_array($res)) { return wpultra_err('graphql_failed', 'GraphQL returned a non-array result.'); }
    return $res;
}
