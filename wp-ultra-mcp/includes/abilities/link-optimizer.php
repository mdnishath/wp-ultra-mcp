<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine + the single-post link primitives it orchestrates.
require_once __DIR__ . '/../seo/linkgraph.php';
if (file_exists(__DIR__ . '/../seo/links.php')) { require_once __DIR__ . '/../seo/links.php'; }

wp_register_ability('wpultra/link-optimizer', [
    'label'       => __('SEO: Site-wide Internal-Link Optimizer', 'wp-ultra-mcp'),
    'description' => __(
        'Build a whole-site internal-link graph and act on it. The graph model: each published post is a node; every internal <a> link between two posts is a directed edge, so inbound = link equity received and outbound = equity distributed. From that graph the optimizer surfaces (1) orphans — posts with zero inbound internal links, invisible to link equity; (2) dead-ends — posts with zero outbound links; (3) hubs — posts ranked by inbound, an authority proxy; (4) over-linked posts — outbound above a threshold, diluting each link. The contextual-insert flow: for each orphan it matches the best SOURCE posts to link FROM by keyword/title (Jaccard) overlap, skipping sources that already link to it and self-links, and proposes an anchor from the target title — telling you "link post 12 from post 45 with anchor X". '
        . 'Actions: build-graph {scope} returns the graph + a link-health report; orphans returns orphan / dead-end / over-linked lists; suggest {scope, per_post} returns ranked source->target opportunities (read-only); apply {suggestions:[{source_id, anchor, target_url}], confirm:true} inserts each link via the SAFE single-post inserter (confirm-gated, capped 50/run, per-row results); report returns just the link-health snapshot. '
        . 'Examples: {"action":"report","scope":{"post_types":["post","page"],"limit":300}} · {"action":"suggest","scope":{"limit":200},"per_post":2} · {"action":"apply","suggestions":[{"source_id":45,"anchor":"beginner guide","target_url":"https://site.test/beginner-guide/"}],"confirm":true}.',
        'wp-ultra-mcp'
    ),
    'category'     => 'seo',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['build-graph', 'orphans', 'suggest', 'apply', 'report']],
            'scope'        => [
                'type'       => 'object',
                'properties' => [
                    'post_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'limit'      => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
            'per_post'     => ['type' => 'integer'],
            'max_outbound' => ['type' => 'integer'],
            'suggestions'  => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'source_id'  => ['type' => 'integer'],
                        'anchor'     => ['type' => 'string'],
                        'target_url' => ['type' => 'string'],
                    ],
                    'required'             => ['source_id', 'anchor', 'target_url'],
                    'additionalProperties' => false,
                ],
            ],
            'confirm'      => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_link_optimizer_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_link_optimizer_cb(array $input) {
    $action = (string) ($input['action'] ?? '');
    $scope  = (array) ($input['scope'] ?? []);
    $types  = array_values(array_filter(array_map('strval', (array) ($scope['post_types'] ?? []))));
    $limit  = isset($scope['limit']) ? (int) $scope['limit'] : 200;

    switch ($action) {
        case 'build-graph': {
            $posts = wpultra_lgraph_crawl($types, $limit);
            $graph = wpultra_lgraph_build($posts);
            wpultra_audit_log('link-optimizer', 'build-graph: ' . count($graph['nodes']) . ' nodes, ' . count($graph['edges']) . ' edges');
            return wpultra_ok([
                'action' => $action,
                'graph'  => $graph,
                'report' => wpultra_lgraph_report($graph),
            ]);
        }

        case 'orphans': {
            $posts = wpultra_lgraph_crawl($types, $limit);
            $graph = wpultra_lgraph_build($posts);
            $max   = isset($input['max_outbound']) ? (int) $input['max_outbound'] : WPULTRA_LGRAPH_OVERLINK_MAX;
            wpultra_audit_log('link-optimizer', 'orphans scan over ' . count($graph['nodes']) . ' nodes');
            return wpultra_ok([
                'action'      => $action,
                'orphans'     => wpultra_lgraph_orphans($graph),
                'dead_ends'   => wpultra_lgraph_dead_ends($graph),
                'over_linked' => wpultra_lgraph_over_linked($graph, $max),
            ]);
        }

        case 'suggest': {
            $posts    = wpultra_lgraph_crawl($types, $limit);
            $graph    = wpultra_lgraph_build($posts);
            $perPost  = isset($input['per_post']) ? (int) $input['per_post'] : 3;
            $sugg     = wpultra_lgraph_suggest_links($posts, $graph, $perPost);
            wpultra_audit_log('link-optimizer', 'suggest: ' . count($sugg) . ' opportunities');
            return wpultra_ok([
                'action'      => $action,
                'suggestions' => $sugg,
                'count'       => count($sugg),
            ]);
        }

        case 'report': {
            $posts = wpultra_lgraph_crawl($types, $limit);
            $graph = wpultra_lgraph_build($posts);
            return wpultra_ok([
                'action' => $action,
                'report' => wpultra_lgraph_report($graph),
            ]);
        }

        case 'apply': {
            $rows = (array) ($input['suggestions'] ?? []);
            if (!$rows) { return wpultra_err('no_suggestions', 'Provide a non-empty suggestions[] to apply.'); }
            if (empty($input['confirm'])) {
                return wpultra_err('confirm_required', 'This mutates post content. Re-send with "confirm": true. ' . count($rows) . ' link insert(s) queued (cap ' . WPULTRA_LGRAPH_APPLY_MAX . '/run).');
            }
            if (!function_exists('wpultra_seo_insert_link')) {
                return wpultra_err('inserter_unavailable', 'The single-post link inserter (includes/seo/links.php) is not loaded.');
            }
            $rows = array_slice($rows, 0, WPULTRA_LGRAPH_APPLY_MAX);
            $results = [];
            $inserted = 0;
            foreach ($rows as $row) {
                $sid    = (int) ($row['source_id'] ?? 0);
                $anchor = (string) ($row['anchor'] ?? '');
                $url    = (string) ($row['target_url'] ?? '');
                if ($sid <= 0 || $anchor === '' || $url === '') {
                    $results[] = ['source_id' => $sid, 'inserted' => false, 'error' => 'invalid_row'];
                    continue;
                }
                $r = wpultra_seo_insert_link($sid, $anchor, $url);
                if (is_wp_error($r)) {
                    $results[] = ['source_id' => $sid, 'inserted' => false, 'error' => $r->get_error_code()];
                    continue;
                }
                if (!empty($r['inserted'])) { $inserted++; }
                $results[] = $r;
            }
            wpultra_audit_log('link-optimizer', "apply: $inserted inserted of " . count($rows) . ' row(s)');
            return wpultra_ok([
                'action'   => $action,
                'inserted' => $inserted,
                'attempted' => count($rows),
                'results'  => $results,
            ]);
        }

        default:
            return wpultra_err('bad_action', 'Unknown action "' . $action . '". Use build-graph|orphans|suggest|apply|report.');
    }
}
