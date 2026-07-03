<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Divi adapter: post_content holds a nested [et_pb_*] shortcode tree. The pure
 * parser tokenizes open/close/self-closing et_pb shortcodes into a tree of
 * {type, attrs, content?, children[]}; the serializer round-trips it back.
 */

/** Pure: parse a key="value" attribute string. */
function wpultra_divi_parse_attrs(string $raw): array {
    $attrs = [];
    if (preg_match_all('/([a-zA-Z0-9_\-]+)="((?:[^"\\\\]|\\\\.)*)"/s', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $pair) { $attrs[$pair[1]] = $pair[2]; }
    }
    return $attrs;
}

/**
 * Pure: tokenize + build the et_pb shortcode tree.
 * Leaf modules keep the raw HTML between open/close as 'content'.
 * @return array|string tree or error string
 */
function wpultra_divi_parse(string $content) {
    if (!preg_match_all('#\[(/?)(et_pb_[a-z0-9_]+)((?:\s+[a-zA-Z0-9_\-]+="(?:[^"\\\\]|\\\\.)*")*)\s*(/?)\]#s', $content, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $root = ['children' => []];
    $stack = [&$root];
    $count = count($m[0]);
    for ($i = 0; $i < $count; $i++) {
        $is_close = $m[1][$i][0] === '/';
        $tag = $m[2][$i][0];
        $self_close = $m[4][$i][0] === '/';
        $token_start = (int) $m[0][$i][1];
        $token_end = $token_start + strlen($m[0][$i][0]);

        if ($is_close) {
            $top = count($stack) - 1;
            if ($top < 1 || $stack[$top]['type'] !== $tag) { return "Unbalanced Divi shortcodes: unexpected [/$tag]."; }
            // Capture leaf text content (only when the node has no children).
            if ($stack[$top]['children'] === [] && isset($stack[$top]['_open_end'])) {
                $inner = substr($content, $stack[$top]['_open_end'], $token_start - $stack[$top]['_open_end']);
                $inner = trim($inner);
                if ($inner !== '') { $stack[$top]['content'] = $inner; }
            }
            unset($stack[$top]['_open_end']);
            array_pop($stack);
            continue;
        }

        $node = ['type' => $tag, 'attrs' => wpultra_divi_parse_attrs($m[3][$i][0]), 'children' => []];
        $top = count($stack) - 1;
        if ($self_close) {
            $stack[$top]['children'][] = $node;
            continue;
        }
        $node['_open_end'] = $token_end;
        $stack[$top]['children'][] = $node;
        $stack[] = &$stack[$top]['children'][count($stack[$top]['children']) - 1];
    }
    if (count($stack) !== 1) {
        return 'Unbalanced Divi shortcodes: [' . $stack[count($stack) - 1]['type'] . '] is never closed.';
    }
    return $root['children'];
}

/** Pure: serialize a tree back into Divi shortcode markup. */
function wpultra_divi_serialize(array $tree, int $depth = 0): string {
    $out = [];
    foreach ($tree as $node) {
        $type = (string) ($node['type'] ?? '');
        if ($type === '' || !preg_match('/^et_pb_[a-z0-9_]+$/', $type)) { continue; }
        $attrs = '';
        foreach ((array) ($node['attrs'] ?? []) as $k => $v) {
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', (string) $k)) { continue; }
            $attrs .= ' ' . $k . '="' . str_replace('"', '%22', (string) $v) . '"';
        }
        $children = (array) ($node['children'] ?? []);
        $content = (string) ($node['content'] ?? '');
        if ($children === [] && $content === '') {
            $out[] = "[$type$attrs][/$type]";
        } else {
            $inner = $children !== [] ? wpultra_divi_serialize($children, $depth + 1) : $content;
            $out[] = "[$type$attrs]$inner" . "[/$type]";
        }
    }
    return implode('', $out);
}

/** Pure: validate a Divi tree — types well-formed, sane depth. @return true|string */
function wpultra_divi_validate($tree, int $depth = 0) {
    if ($depth > 10) { return 'Divi tree too deep (max 10).'; }
    if (!is_array($tree)) { return 'Divi tree must be an array of nodes.'; }
    foreach ($tree as $i => $node) {
        if (!is_array($node)) { return "Divi node $i must be an object."; }
        $type = (string) ($node['type'] ?? '');
        if (!preg_match('/^et_pb_[a-z0-9_]+$/', $type)) { return "Divi node $i: type '$type' must match et_pb_*."; }
        $v = wpultra_divi_validate((array) ($node['children'] ?? []), $depth + 1);
        if ($v !== true) { return $v; }
    }
    return true;
}

/** Compact shape for get-content. */
function wpultra_divi_compact(array $tree): array {
    $out = [];
    foreach ($tree as $node) {
        $row = ['type' => (string) ($node['type'] ?? ''), 'attrs' => (object) ($node['attrs'] ?? [])];
        if (($node['content'] ?? '') !== '') { $row['content'] = (string) $node['content']; }
        if (!empty($node['children'])) { $row['children'] = wpultra_divi_compact((array) $node['children']); }
        $out[] = $row;
    }
    return $out;
}

/* Thin WP wrappers. */

/** @return array|WP_Error */
function wpultra_divi_get(int $post_id) {
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('not_found', "No post $post_id."); }
    $enabled = get_post_meta($post_id, '_et_pb_use_builder', true) === 'on';
    $tree = wpultra_divi_parse((string) $post->post_content);
    if (is_string($tree)) { return wpultra_err('divi_parse_failed', $tree); }
    return ['post_id' => $post_id, 'builder_enabled' => $enabled, 'elements' => wpultra_divi_compact($tree)];
}

/** @return array|WP_Error */
function wpultra_divi_set(int $post_id, $elements) {
    // Accept a tree (serialized by us) or a raw shortcode string (parsed to validate balance).
    if (is_string($elements)) {
        $parsed = wpultra_divi_parse($elements);
        if (is_string($parsed)) { return wpultra_err('divi_invalid', $parsed); }
        $markup = $elements;
        $count = count($parsed);
    } else {
        $v = wpultra_divi_validate($elements);
        if ($v !== true) { return wpultra_err('divi_invalid', (string) $v); }
        $markup = wpultra_divi_serialize((array) $elements);
        $count = count((array) $elements);
    }
    $r = wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($markup)], true);
    if (is_wp_error($r)) { return $r; }
    update_post_meta($post_id, '_et_pb_use_builder', 'on');
    update_post_meta($post_id, '_et_pb_old_content', '');
    return ['post_id' => $post_id, 'sections' => $count, 'bytes' => strlen($markup)];
}

/** Registered Divi modules (best-effort). */
function wpultra_divi_elements(): array {
    try {
        if (class_exists('\\ET_Builder_Element') && method_exists('\\ET_Builder_Element', 'get_modules')) {
            $mods = \ET_Builder_Element::get_modules();
            $out = [];
            foreach ((array) $mods as $slug => $mod) {
                $out[] = ['name' => (string) $slug, 'label' => (string) ($mod->name ?? $slug)];
            }
            return $out;
        }
    } catch (\Throwable $e) {
        // fall through to the static core list
    }
    return array_map(static fn($n) => ['name' => $n, 'label' => $n], [
        'et_pb_section', 'et_pb_row', 'et_pb_column', 'et_pb_text', 'et_pb_image', 'et_pb_button',
        'et_pb_blurb', 'et_pb_cta', 'et_pb_divider', 'et_pb_code', 'et_pb_video', 'et_pb_slider',
    ]);
}
