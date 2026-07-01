<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_compact_tree(array $elements, int $depth = 0): array {
    $out = [];
    foreach ($elements as $n) {
        if (!is_array($n)) { continue; }
        $e = ['id' => $n['id'] ?? '', 'elType' => $n['elType'] ?? ''];
        if (!empty($n['widgetType'])) { $e['widgetType'] = $n['widgetType']; }
        $children = is_array($n['elements'] ?? null) ? $n['elements'] : [];
        $e['children'] = $depth < 12 ? wpultra_el_compact_tree($children, $depth + 1) : [];
        $out[] = $e;
    }
    return $out;
}

function wpultra_el_walk(array &$elements, string $id, callable $fn, int $depth = 0): bool {
    if ($depth > 100) { return false; } // guard against pathologically deep / cyclic data
    foreach ($elements as $i => &$n) {
        if (!is_array($n)) { continue; }
        if (($n['id'] ?? null) === $id) { $fn($n, $elements, $i); return true; }
        if (!empty($n['elements']) && is_array($n['elements'])) {
            if (wpultra_el_walk($n['elements'], $id, $fn, $depth + 1)) { return true; }
        }
    }
    return false;
}

function wpultra_el_find(array $elements, string $id): ?array {
    $found = null;
    wpultra_el_walk($elements, $id, function ($node) use (&$found) { $found = $node; });
    return $found;
}

/** Locate a node and report its parent id ('' at root) and index. Pure, depth-guarded. */
function wpultra_el_locate(array $elements, string $id, string $parentId = '', int $depth = 0): ?array {
    if ($depth > 100) { return null; }
    foreach ($elements as $i => $n) {
        if (!is_array($n)) { continue; }
        if (($n['id'] ?? null) === $id) { return ['parent_id' => $parentId, 'index' => $i, 'node' => $n]; }
        if (!empty($n['elements']) && is_array($n['elements'])) {
            $hit = wpultra_el_locate($n['elements'], $id, (string) ($n['id'] ?? ''), $depth + 1);
            if ($hit !== null) { return $hit; }
        }
    }
    return null;
}

/** elTypes that legitimately hold children. A `widget` elType is a leaf unless it is one of these. */
function wpultra_el_is_container_type(string $elType): bool {
    return in_array($elType, ['e-flexbox', 'e-div-block', 'container', 'section', 'column', 'inner-section'], true);
}

function wpultra_el_insert(array $elements, ?string $parentId, int $pos, array $node) {
    if ($parentId === null || $parentId === '') {
        $pos = max(0, min($pos, count($elements)));
        array_splice($elements, $pos, 0, [$node]);
        return $elements;
    }
    $rejected = null;
    $done = wpultra_el_walk($elements, $parentId, function (&$parent) use ($node, $pos, &$rejected) {
        $pElType = (string) ($parent['elType'] ?? '');
        // A leaf widget cannot hold children: fabricating an `elements` array on it produces a
        // subtree Elementor silently drops at render. Reject unless it's a known container type.
        if ($pElType === 'widget' && !wpultra_el_is_container_type($pElType)) {
            $rejected = true;
            return;
        }
        if (!isset($parent['elements']) || !is_array($parent['elements'])) { $parent['elements'] = []; }
        $p = max(0, min($pos, count($parent['elements'])));
        array_splice($parent['elements'], $p, 0, [$node]);
    });
    if ($rejected) { return wpultra_err('parent_not_container', "Element '$parentId' is a leaf widget and cannot contain children."); }
    return $done ? $elements : wpultra_err('parent_not_found', "No element with id '$parentId'.");
}

function wpultra_el_remove(array $elements, string $id) {
    $done = wpultra_el_walk($elements, $id, function (&$n, &$siblings, $i) { array_splice($siblings, $i, 1); });
    return $done ? $elements : wpultra_err('element_not_found', "No element with id '$id'.");
}

function wpultra_el_move(array $elements, string $id, ?string $toParentId, int $pos) {
    // `pos` is the desired FINAL index. Removing then re-inserting at `pos` already yields
    // that final index in every case (including same-parent forward moves), because
    // wpultra_el_insert clamps against the post-removal sibling count.
    $node = wpultra_el_find($elements, $id);
    if ($node === null) { return wpultra_err('element_not_found', "No element with id '$id'."); }
    $removed = wpultra_el_remove($elements, $id);
    if (is_wp_error($removed)) { return $removed; }
    return wpultra_el_insert($removed, $toParentId, $pos, $node);
}

function wpultra_el_merge_settings(array $elements, string $id, array $settings, bool $deep) {
    $done = wpultra_el_walk($elements, $id, function (&$n) use ($settings, $deep) {
        $cur = is_array($n['settings'] ?? null) ? $n['settings'] : [];
        $n['settings'] = $deep ? array_replace_recursive($cur, $settings) : array_merge($cur, $settings);
    });
    return $done ? $elements : wpultra_err('element_not_found', "No element with id '$id'.");
}
