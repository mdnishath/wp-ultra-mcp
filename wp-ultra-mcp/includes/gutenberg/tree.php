<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_gb_path_to_str(array $path): string {
    return implode('/', array_map('strval', $path));
}

/**
 * Parse a slash path string to an int array. '' is the (valid) root path [].
 * Returns null if any segment is non-integer, so a typo like "1/x/2" surfaces a
 * clear error instead of silently targeting the wrong block ([1,2]).
 */
function wpultra_gb_str_to_path(string $s): ?array {
    if ($s === '') { return []; }
    $out = [];
    foreach (explode('/', $s) as $seg) {
        if ($seg === '' || !ctype_digit($seg)) { return null; }
        $out[] = (int) $seg;
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

/** Reference to the block node at $path (not its innerBlocks). Null ref if any segment is invalid. */
function &wpultra_gb_block_ref(array &$blocks, array $path) {
    $null = null;
    $ref = &$blocks;
    $n = count($path);
    for ($d = 0; $d < $n; $d++) {
        $idx = $path[$d];
        if (!isset($ref[$idx]) || !is_array($ref[$idx])) { return $null; }
        if ($d === $n - 1) { return $ref[$idx]; }
        if (!isset($ref[$idx]['innerBlocks']) || !is_array($ref[$idx]['innerBlocks'])) { $ref[$idx]['innerBlocks'] = []; }
        $ref = &$ref[$idx]['innerBlocks'];
    }
    return $ref;
}

/**
 * Position in an innerContent array of the $childIndex-th null placeholder (serialize_block emits
 * one innerBlock per null chunk). Returns count($innerContent) to append past the last child.
 */
function wpultra_gb_innercontent_null_pos(array $innerContent, int $childIndex): int {
    $seen = 0;
    foreach ($innerContent as $ci => $chunk) {
        if ($chunk === null) {
            if ($seen === $childIndex) { return (int) $ci; }
            $seen++;
        }
    }
    return count($innerContent);
}

function wpultra_gb_insert(array $blocks, ?array $parentPath, int $pos, array $block) {
    if ($parentPath === null) { return new WP_Error('invalid_path', 'parent_path is not a valid slash-separated integer path.'); }
    if (!$parentPath) {
        $pos = max(0, min($pos, count($blocks)));
        array_splice($blocks, $pos, 0, [$block]);
        return $blocks;
    }
    $parent = &wpultra_gb_block_ref($blocks, $parentPath);
    if ($parent === null) { return new WP_Error('block_path_not_found', 'Parent path not found: ' . wpultra_gb_path_to_str($parentPath)); }
    if (!isset($parent['innerBlocks']) || !is_array($parent['innerBlocks'])) { $parent['innerBlocks'] = []; }
    if (!isset($parent['innerContent']) || !is_array($parent['innerContent'])) {
        $parent['innerContent'] = array_fill(0, count($parent['innerBlocks']), null);
    }
    $pos = max(0, min($pos, count($parent['innerBlocks'])));
    array_splice($parent['innerBlocks'], $pos, 0, [$block]);
    // Keep innerContent in lock-step: add a null placeholder so serialize_block emits the new child.
    $icPos = wpultra_gb_innercontent_null_pos($parent['innerContent'], $pos);
    array_splice($parent['innerContent'], $icPos, 0, [null]);
    return $blocks;
}

function wpultra_gb_remove(array $blocks, ?array $path) {
    if ($path === null) { return new WP_Error('invalid_path', 'path is not a valid slash-separated integer path.'); }
    $loc = wpultra_gb_locate($blocks, $path);
    if (!$loc) { return new WP_Error('block_path_not_found', 'Path not found: ' . wpultra_gb_path_to_str($path)); }
    $parentPath = $loc['parent_path'];
    $idx = $loc['index'];
    if (!$parentPath) { array_splice($blocks, $idx, 1); return $blocks; }
    $parent = &wpultra_gb_block_ref($blocks, $parentPath);
    if ($parent === null) { return new WP_Error('block_path_not_found', 'Parent path not found: ' . wpultra_gb_path_to_str($parentPath)); }
    array_splice($parent['innerBlocks'], $idx, 1);
    // Drop the matching null placeholder so no stray chunk is serialized as null.
    if (isset($parent['innerContent']) && is_array($parent['innerContent'])) {
        $icPos = wpultra_gb_innercontent_null_pos($parent['innerContent'], $idx);
        if ($icPos < count($parent['innerContent'])) { array_splice($parent['innerContent'], $icPos, 1); }
    }
    return $blocks;
}

function wpultra_gb_move(array $blocks, ?array $path, ?array $toParentPath, int $pos) {
    if ($path === null || $toParentPath === null) { return new WP_Error('invalid_path', 'path/to_parent_path is not a valid slash-separated integer path.'); }
    $loc = wpultra_gb_locate($blocks, $path);
    if (!$loc) { return new WP_Error('block_path_not_found', 'Source path not found: ' . wpultra_gb_path_to_str($path)); }
    // Refuse to move a node into itself or one of its own descendants (would orphan the subtree).
    if (array_slice($toParentPath, 0, count($path)) === $path) {
        return new WP_Error('move_into_descendant', 'Cannot move a block into itself or its own descendant.');
    }
    $node = $loc['node'];
    $removed = wpultra_gb_remove($blocks, $path);
    if ($removed instanceof WP_Error) { return $removed; }
    // Removing the source only shifts indices INSIDE the source's own parent container. The
    // destination path is affected only when that container is a prefix of it and the branch
    // taken there sits after the removed index.
    $srcParent = array_slice($path, 0, -1);
    $srcIdx    = $path[count($path) - 1];
    $adjusted  = $toParentPath;
    if (count($adjusted) > count($srcParent)
        && array_slice($adjusted, 0, count($srcParent)) === $srcParent
        && $adjusted[count($srcParent)] > $srcIdx) {
        $adjusted[count($srcParent)]--;
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
