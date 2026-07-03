<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Oxygen adapter: Oxygen 4+ stores a JSON tree in postmeta `ct_builder_json`
 * ({id, name, options{...}, children[]} with a root "ct_document" wrapper);
 * Oxygen 3.x stores a shortcode string in `ct_builder_shortcodes`. Reads
 * support both; writes target the JSON format (3.x write is refused with a
 * clear error rather than risking a wrong shortcode dialect).
 */

/** Pure: validate an Oxygen JSON node tree. @return true|string */
function wpultra_oxy_validate($node, int $depth = 0) {
    if ($depth > 12) { return 'Oxygen tree too deep (max 12).'; }
    if (!is_array($node)) { return 'Oxygen node must be an object.'; }
    $name = (string) ($node['name'] ?? '');
    if ($name === '') { return 'Every Oxygen node needs a name (e.g. ct_section, ct_headline, ct_text_block).'; }
    if (!preg_match('/^[a-z0-9_\-]+$/i', $name)) { return "Oxygen node name '$name' is not a valid component name."; }
    $ids = [];
    $walk = function ($n, $d) use (&$walk, &$ids) {
        if ($d > 12) { return 'Oxygen tree too deep (max 12).'; }
        if (isset($n['id'])) {
            $id = (string) $n['id'];
            if (isset($ids[$id])) { return "Duplicate Oxygen node id '$id'."; }
            $ids[$id] = true;
        }
        foreach ((array) ($n['children'] ?? []) as $c) {
            if (!is_array($c)) { return 'Oxygen children must be objects.'; }
            if ((string) ($c['name'] ?? '') === '') { return 'Every Oxygen node needs a name.'; }
            $r = $walk($c, $d + 1);
            if ($r !== true) { return $r; }
        }
        return true;
    };
    return $walk($node, $depth);
}

/** Pure: compact shape of an Oxygen tree for reading. */
function wpultra_oxy_compact(array $node): array {
    $row = [
        'id'   => $node['id'] ?? null,
        'name' => (string) ($node['name'] ?? ''),
    ];
    $opts = (array) ($node['options'] ?? []);
    // Surface the most useful option: original text content when present.
    $ct = $opts['original'] ?? [];
    if (is_array($ct) && isset($ct['ct_content']) && $ct['ct_content'] !== '') {
        $row['content'] = (string) $ct['ct_content'];
    }
    $children = (array) ($node['children'] ?? []);
    if ($children !== []) {
        $row['children'] = array_map(static fn($c) => wpultra_oxy_compact((array) $c), $children);
    }
    return $row;
}

/** Pure: ensure a root wrapper the way Oxygen 4 stores it. */
function wpultra_oxy_wrap_root($tree): array {
    if (is_array($tree) && (string) ($tree['name'] ?? '') === 'ct_document') { return $tree; }
    $children = is_array($tree) && array_is_list($tree) ? $tree : [$tree];
    return ['id' => 0, 'name' => 'ct_document', 'options' => ['ct_id' => 0, 'ct_parent' => 0], 'children' => $children];
}

/* Thin WP wrappers. */

/** @return array|WP_Error */
function wpultra_oxy_get(int $post_id) {
    if (!get_post($post_id)) { return wpultra_err('not_found', "No post $post_id."); }
    $json = (string) get_post_meta($post_id, 'ct_builder_json', true);
    if ($json !== '') {
        $tree = json_decode($json, true);
        if (!is_array($tree)) { return wpultra_err('oxygen_bad_json', 'ct_builder_json is not valid JSON.'); }
        return ['post_id' => $post_id, 'format' => 'json', 'elements' => [wpultra_oxy_compact($tree)]];
    }
    $sc = (string) get_post_meta($post_id, 'ct_builder_shortcodes', true);
    if ($sc !== '') {
        return ['post_id' => $post_id, 'format' => 'shortcodes', 'shortcodes' => $sc];
    }
    return ['post_id' => $post_id, 'format' => 'none', 'elements' => []];
}

/** @return array|WP_Error */
function wpultra_oxy_set(int $post_id, $tree) {
    $wrapped = wpultra_oxy_wrap_root($tree);
    $v = wpultra_oxy_validate($wrapped);
    if ($v !== true) { return wpultra_err('oxygen_invalid', (string) $v); }
    $json = (string) wp_json_encode($wrapped);
    update_post_meta($post_id, 'ct_builder_json', wp_slash($json));
    return ['post_id' => $post_id, 'format' => 'json', 'bytes' => strlen($json)];
}

/** Known Oxygen components (static — Oxygen keeps no simple registry to query). */
function wpultra_oxy_elements(): array {
    return array_map(static fn($n) => ['name' => $n, 'label' => $n], [
        'ct_section', 'ct_div_block', 'ct_headline', 'ct_text_block', 'ct_rich_text', 'ct_image',
        'ct_link_button', 'ct_video', 'ct_code_block', 'ct_columns', 'ct_column',
    ]);
}
