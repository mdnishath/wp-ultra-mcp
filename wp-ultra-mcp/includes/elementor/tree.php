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

function wpultra_el_walk(array &$elements, string $id, callable $fn): bool {
    foreach ($elements as $i => &$n) {
        if (($n['id'] ?? null) === $id) { $fn($n, $elements, $i); return true; }
        if (!empty($n['elements']) && is_array($n['elements'])) {
            if (wpultra_el_walk($n['elements'], $id, $fn)) { return true; }
        }
    }
    return false;
}

function wpultra_el_find(array $elements, string $id): ?array {
    $found = null;
    wpultra_el_walk($elements, $id, function ($node) use (&$found) { $found = $node; });
    return $found;
}

function wpultra_el_insert(array $elements, ?string $parentId, int $pos, array $node) {
    if ($parentId === null || $parentId === '') {
        $pos = max(0, min($pos, count($elements)));
        array_splice($elements, $pos, 0, [$node]);
        return $elements;
    }
    $done = wpultra_el_walk($elements, $parentId, function (&$parent) use ($node, $pos) {
        if (!isset($parent['elements']) || !is_array($parent['elements'])) { $parent['elements'] = []; }
        $p = max(0, min($pos, count($parent['elements'])));
        array_splice($parent['elements'], $p, 0, [$node]);
    });
    return $done ? $elements : wpultra_err('parent_not_found', "No element with id '$parentId'.");
}

function wpultra_el_remove(array $elements, string $id) {
    $done = wpultra_el_walk($elements, $id, function (&$n, &$siblings, $i) { array_splice($siblings, $i, 1); });
    return $done ? $elements : wpultra_err('element_not_found', "No element with id '$id'.");
}

function wpultra_el_move(array $elements, string $id, ?string $toParentId, int $pos) {
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
