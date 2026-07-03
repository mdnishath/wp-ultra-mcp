<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Beaver Builder adapter: postmeta `_fl_builder_data` is a FLAT map of node
 * id => {node, type (row|column-group|column|module), parent (null=top),
 * position, settings (object incl. `type` for modules)}. Pure helpers build a
 * nested tree for reading and validate flat input for writing.
 */

/** Pure: normalize one raw node (object|array) to an array row. */
function wpultra_bb_node_row($node): array {
    $n = is_object($node) ? get_object_vars($node) : (array) $node;
    $settings = $n['settings'] ?? [];
    $settings = is_object($settings) ? get_object_vars($settings) : (array) $settings;
    return [
        'node'     => (string) ($n['node'] ?? ''),
        'type'     => (string) ($n['type'] ?? ''),
        'parent'   => isset($n['parent']) && $n['parent'] !== null && $n['parent'] !== '' ? (string) $n['parent'] : null,
        'position' => (int) ($n['position'] ?? 0),
        'settings' => $settings,
    ];
}

/** Pure: flat map → nested tree ordered by position. */
function wpultra_bb_tree(array $flat): array {
    $rows = [];
    foreach ($flat as $key => $node) {
        $row = wpultra_bb_node_row($node);
        if ($row['node'] === '') { $row['node'] = (string) $key; }
        $rows[$row['node']] = $row + ['children' => []];
    }
    $roots = [];
    foreach ($rows as $id => &$row) {
        $module_type = (string) ($row['settings']['type'] ?? '');
        $row['compact'] = array_filter([
            'node'   => $id,
            'type'   => $row['type'],
            'module' => $row['type'] === 'module' ? $module_type : null,
        ]);
        if ($row['parent'] !== null && isset($rows[$row['parent']])) {
            $rows[$row['parent']]['children'][] = $id;
        } else {
            $roots[] = $id;
        }
    }
    unset($row);
    $build = function (array $ids) use (&$build, $rows): array {
        $nodes = array_map(static fn($id) => $rows[$id], $ids);
        usort($nodes, static fn($a, $b) => $a['position'] <=> $b['position']);
        $out = [];
        foreach ($nodes as $n) {
            $item = $n['compact'];
            if ($n['children'] !== []) { $item['children'] = $build($n['children']); }
            $out[] = $item;
        }
        return $out;
    };
    return $build($roots);
}

/** Pure: validate a flat node map for writing. @return true|string */
function wpultra_bb_validate($flat) {
    if (!is_array($flat) || $flat === []) { return 'nodes must be a non-empty map/array of Beaver Builder nodes.'; }
    $ids = [];
    $rows = [];
    foreach ($flat as $key => $node) {
        $row = wpultra_bb_node_row($node);
        if ($row['node'] === '') { $row['node'] = (string) $key; }
        if ($row['node'] === '') { return 'Every node needs a node id.'; }
        if (isset($ids[$row['node']])) { return "Duplicate node id '{$row['node']}'."; }
        if (!in_array($row['type'], ['row', 'column-group', 'column', 'module'], true)) {
            return "Node '{$row['node']}': type '{$row['type']}' must be row|column-group|column|module.";
        }
        if ($row['type'] === 'module' && (string) ($row['settings']['type'] ?? '') === '') {
            return "Module node '{$row['node']}' needs settings.type (the module slug, e.g. rich-text).";
        }
        $ids[$row['node']] = true;
        $rows[] = $row;
    }
    foreach ($rows as $row) {
        if ($row['parent'] !== null && !isset($ids[$row['parent']])) {
            return "Node '{$row['node']}' references missing parent '{$row['parent']}'.";
        }
    }
    return true;
}

/** Pure: normalize flat input into the id-keyed, object-settings shape BB stores. */
function wpultra_bb_normalize_for_storage(array $flat): array {
    $out = [];
    foreach ($flat as $key => $node) {
        $row = wpultra_bb_node_row($node);
        if ($row['node'] === '') { $row['node'] = (string) $key; }
        $out[$row['node']] = (object) [
            'node'     => $row['node'],
            'type'     => $row['type'],
            'parent'   => $row['parent'],
            'position' => $row['position'],
            'settings' => json_decode((string) wp_json_encode($row['settings'])), // deep object cast
        ];
    }
    return $out;
}

/* Thin WP wrappers. */

/** @return array|WP_Error */
function wpultra_bb_get(int $post_id) {
    if (!get_post($post_id)) { return wpultra_err('not_found', "No post $post_id."); }
    $data = get_post_meta($post_id, '_fl_builder_data', true);
    $flat = is_array($data) ? $data : [];
    return [
        'post_id'         => $post_id,
        'builder_enabled' => (bool) get_post_meta($post_id, '_fl_builder_enabled', true),
        'node_count'      => count($flat),
        'elements'        => wpultra_bb_tree($flat),
    ];
}

/** @return array|WP_Error */
function wpultra_bb_set(int $post_id, $nodes) {
    $v = wpultra_bb_validate($nodes);
    if ($v !== true) { return wpultra_err('beaver_invalid', (string) $v); }
    $storage = wpultra_bb_normalize_for_storage((array) $nodes);
    update_post_meta($post_id, '_fl_builder_data', $storage);
    update_post_meta($post_id, '_fl_builder_draft', $storage);
    update_post_meta($post_id, '_fl_builder_enabled', 1);
    try {
        if (class_exists('FLBuilderModel') && method_exists('FLBuilderModel', 'delete_asset_cache')) {
            \FLBuilderModel::update_post_data('post_id', $post_id);
            \FLBuilderModel::delete_asset_cache($post_id);
        }
    } catch (\Throwable $e) {
        // cache rebuilds lazily
    }
    return ['post_id' => $post_id, 'nodes' => count($storage)];
}

/** Registered BB modules (best-effort). */
function wpultra_bb_elements(): array {
    try {
        if (class_exists('FLBuilderModel') && method_exists('FLBuilderModel', 'get_enabled_modules')) {
            return array_map(static fn($n) => ['name' => (string) $n, 'label' => (string) $n], (array) \FLBuilderModel::get_enabled_modules());
        }
    } catch (\Throwable $e) {
        // fall through
    }
    return array_map(static fn($n) => ['name' => $n, 'label' => $n], [
        'rich-text', 'photo', 'button', 'heading', 'html', 'video', 'separator', 'callout', 'cta',
    ]);
}
