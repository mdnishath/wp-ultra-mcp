<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_gb_path_to_str(array $path): string {
    return implode('/', array_map('strval', $path));
}

function wpultra_gb_str_to_path(string $s): array {
    if ($s === '') { return []; }
    $out = [];
    foreach (explode('/', $s) as $seg) {
        if (is_numeric($seg)) { $out[] = (int) $seg; }
    }
    return $out;
}

function wpultra_gb_compact_tree(array $blocks, array $prefix = []): array {
    if (count($prefix) > 100) { return []; }
    $out = [];
    foreach ($blocks as $i => $b) {
        if (($b['blockName'] ?? null) === null) { continue; } // skip whitespace/freeform
        $path = array_merge($prefix, [$i]);
        $out[] = [
            'path'        => wpultra_gb_path_to_str($path),
            'blockName'   => (string) $b['blockName'],
            'attrs'       => (array) ($b['attrs'] ?? []),
            'innerHTML'   => (string) ($b['innerHTML'] ?? ''),
            'innerBlocks' => wpultra_gb_compact_tree((array) ($b['innerBlocks'] ?? []), $path),
        ];
    }
    return $out;
}

function wpultra_gb_locate(array $blocks, array $path): ?array {
    if (!$path) { return null; }
    $cur = $blocks;
    $parentPath = [];
    $n = count($path);
    for ($d = 0; $d < $n - 1; $d++) {
        $idx = $path[$d];
        if (!isset($cur[$idx]) || !is_array($cur[$idx])) { return null; }
        $parentPath[] = $idx;
        $cur = (array) ($cur[$idx]['innerBlocks'] ?? []);
    }
    $last = $path[$n - 1];
    if (!isset($cur[$last])) { return null; }
    return ['parent_path' => $parentPath, 'index' => $last, 'node' => $cur[$last]];
}

/** Reference to the child array at $parentPath ([]=root). Returns a null ref if any segment is invalid. */
function &wpultra_gb_ref(array &$blocks, array $parentPath) {
    $null = null;
    $ref = &$blocks;
    foreach ($parentPath as $idx) {
        if (!isset($ref[$idx]) || !is_array($ref[$idx])) { return $null; }
        if (!isset($ref[$idx]['innerBlocks']) || !is_array($ref[$idx]['innerBlocks'])) {
            $ref[$idx]['innerBlocks'] = [];
        }
        $ref = &$ref[$idx]['innerBlocks'];
    }
    return $ref;
}

function wpultra_gb_insert(array $blocks, array $parentPath, int $pos, array $block) {
    $ref = &wpultra_gb_ref($blocks, $parentPath);
    if ($ref === null) { return new WP_Error('block_path_not_found', 'Parent path not found: ' . wpultra_gb_path_to_str($parentPath)); }
    $count = count($ref);
    $pos = max(0, min($pos, $count));
    array_splice($ref, $pos, 0, [$block]);
    return $blocks;
}

function wpultra_gb_remove(array $blocks, array $path) {
    $loc = wpultra_gb_locate($blocks, $path);
    if (!$loc) { return new WP_Error('block_path_not_found', 'Path not found: ' . wpultra_gb_path_to_str($path)); }
    $ref = &wpultra_gb_ref($blocks, $loc['parent_path']);
    array_splice($ref, $loc['index'], 1);
    return $blocks;
}

function wpultra_gb_move(array $blocks, array $path, array $toParentPath, int $pos) {
    $loc = wpultra_gb_locate($blocks, $path);
    if (!$loc) { return new WP_Error('block_path_not_found', 'Source path not found: ' . wpultra_gb_path_to_str($path)); }
    $node = $loc['node'];
    $removed = wpultra_gb_remove($blocks, $path);
    if ($removed instanceof WP_Error) { return $removed; }
    // Adjust $toParentPath: removing $path may shift sibling indices at the divergence level.
    // Find the common prefix length between $path and $toParentPath.
    $srcParent = array_slice($path, 0, -1);
    $adjusted = $toParentPath;
    $commonLen = 0;
    $minLen = min(count($srcParent), count($adjusted));
    for ($i = 0; $i < $minLen; $i++) {
        if ($srcParent[$i] !== $adjusted[$i]) { break; }
        $commonLen = $i + 1;
    }
    // At the divergence index (commonLen), if the source index < target index in the same parent,
    // the target index has been shifted down by one.
    if (isset($srcParent[$commonLen]) && isset($adjusted[$commonLen]) && $srcParent[$commonLen] < $adjusted[$commonLen]) {
        $adjusted[$commonLen]--;
    } elseif (!isset($srcParent[$commonLen]) && isset($adjusted[$commonLen]) && $path[count($srcParent)] < $adjusted[$commonLen]) {
        // src and toParentPath share the same parent; src index shifts toParentPath[commonLen].
        $adjusted[$commonLen]--;
    }
    return wpultra_gb_insert($removed, $adjusted, $pos, $node);
}

function wpultra_gb_merge_attrs(array $blocks, array $path, array $attrs, bool $deep) {
    $loc = wpultra_gb_locate($blocks, $path);
    if (!$loc) { return new WP_Error('block_path_not_found', 'Path not found: ' . wpultra_gb_path_to_str($path)); }
    $ref = &wpultra_gb_ref($blocks, $loc['parent_path']);
    $idx = $loc['index'];
    $existing = (array) ($ref[$idx]['attrs'] ?? []);
    $ref[$idx]['attrs'] = $deep ? array_replace_recursive($existing, $attrs) : array_merge($existing, $attrs);
    return $blocks;
}

function wpultra_gb_normalize_block(array $in) {
    // Mode 1: raw block markup — authoritative; correct innerContent for any block (incl. containers with wrappers).
    if (!empty($in['markup'])) {
        $parsed = parse_blocks((string) $in['markup']);
        foreach ($parsed as $b) {
            if (($b['blockName'] ?? null) !== null) { return $b; }
        }
        return new WP_Error('block_invalid', 'No block found in markup.');
    }
    // Mode 2: structured. Best for leaf blocks; containers get children-only innerContent.
    $name = (string) ($in['name'] ?? '');
    if ($name === '') { return new WP_Error('block_invalid', 'Block needs a `name` or `markup`.'); }
    $innerBlocks = [];
    foreach ((array) ($in['inner_blocks'] ?? []) as $child) {
        $c = wpultra_gb_normalize_block((array) $child);
        if ($c instanceof WP_Error) { return $c; }
        $innerBlocks[] = $c;
    }
    $innerHTML = (string) ($in['inner_html'] ?? '');
    $innerContent = $innerBlocks ? array_fill(0, count($innerBlocks), null) : [$innerHTML];
    return [
        'blockName'    => $name,
        'attrs'        => (array) ($in['attributes'] ?? []),
        'innerBlocks'  => $innerBlocks,
        'innerHTML'    => $innerHTML,
        'innerContent' => $innerContent,
    ];
}
