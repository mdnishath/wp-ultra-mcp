<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Walk the element tree, validate each node via $validator, and aggregate a per-node report.
 * $validator(array $node): ['valid'=>bool, 'errors'=>string[], 'settings'=>array].
 * Returns ['ok'=>bool,'nodes'=>[],'summary'=>['total'=>int,'invalid'=>int],'normalized_tree'=>array].
 */
function wpultra_el_validate_tree(array $elements, ?callable $validator = null, int $depth = 0): array {
    if ($validator === null) { $validator = 'wpultra_el_validate_node'; }
    $nodes = [];
    $invalid = 0;
    $normalized = [];
    if ($depth > 100) { return ['ok' => true, 'nodes' => [], 'summary' => ['total' => 0, 'invalid' => 0], 'normalized_tree' => $elements]; }
    foreach ($elements as $n) {
        if (!is_array($n)) { $normalized[] = $n; continue; }
        $res = $validator($n);
        $valid = (bool) ($res['valid'] ?? true);
        if (!$valid) { $invalid++; }
        $nodes[] = [
            'id'         => (string) ($n['id'] ?? ''),
            'elType'     => (string) ($n['elType'] ?? ''),
            'widgetType' => isset($n['widgetType']) ? (string) $n['widgetType'] : null,
            'valid'      => $valid,
            'errors'     => array_values((array) ($res['errors'] ?? [])),
        ];
        $n['settings'] = is_array($res['settings'] ?? null) ? $res['settings'] : ($n['settings'] ?? []);
        if (!empty($n['elements']) && is_array($n['elements'])) {
            $child = wpultra_el_validate_tree($n['elements'], $validator, $depth + 1);
            $nodes = array_merge($nodes, $child['nodes']);
            $invalid += $child['summary']['invalid'];
            $n['elements'] = $child['normalized_tree'];
        }
        $normalized[] = $n;
    }
    return [
        'ok'              => $invalid === 0,
        'nodes'           => $nodes,
        'summary'         => ['total' => count($nodes), 'invalid' => $invalid],
        'normalized_tree' => $normalized,
    ];
}

function wpultra_el_collect_ids(array $elements, int $depth = 0): array {
    if ($depth > 100) { return []; }
    $ids = [];
    foreach ($elements as $n) {
        if (!is_array($n)) { continue; }
        if (!empty($n['id'])) { $ids[] = (string) $n['id']; }
        if (!empty($n['elements']) && is_array($n['elements'])) {
            $ids = array_merge($ids, wpultra_el_collect_ids($n['elements'], $depth + 1));
        }
    }
    return $ids;
}

/** Scan rendered Elementor HTML for data-id markers; report which expected ids are present/dropped. */
function wpultra_el_render_digest(string $html, array $expectedIds): array {
    $present = [];
    if (preg_match_all('/data-id="([a-z0-9]+)"/i', $html, $m)) {
        $present = array_values(array_unique($m[1]));
    }
    $dropped = array_values(array_diff(array_map('strval', $expectedIds), $present));
    return ['rendered_count' => count($present), 'present_ids' => $present, 'dropped_ids' => $dropped];
}
