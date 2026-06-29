<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Non-null top-level blocks of a parsed pattern/content string. Pure (modulo parse_blocks). */
function wpultra_gb_pattern_blocks(string $content): array {
    $out = [];
    foreach (parse_blocks($content) as $b) {
        if (($b['blockName'] ?? null) !== null) { $out[] = $b; }
    }
    return $out;
}

function wpultra_gb_list_patterns(string $search = '', string $category = ''): array {
    if (!class_exists('WP_Block_Patterns_Registry')) { return []; }
    $all = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
    $search = strtolower(trim($search));
    $out = [];
    foreach ($all as $p) {
        $name = (string) ($p['name'] ?? '');
        $title = (string) ($p['title'] ?? '');
        $cats = array_values((array) ($p['categories'] ?? []));
        if ($category !== '' && !in_array($category, $cats, true)) { continue; }
        if ($search !== '' && strpos(strtolower($name . ' ' . $title), $search) === false) { continue; }
        $out[] = ['name' => $name, 'title' => $title, 'categories' => $cats, 'description' => (string) ($p['description'] ?? '')];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

function wpultra_gb_get_pattern(string $name) {
    if (!class_exists('WP_Block_Patterns_Registry')) { return wpultra_err('patterns_unavailable', 'Block patterns registry unavailable.'); }
    $reg = \WP_Block_Patterns_Registry::get_instance();
    if (!$reg->is_registered($name)) { return wpultra_err('pattern_not_found', "No registered pattern '$name'."); }
    return $reg->get_registered($name);
}

function wpultra_gb_reusable_list(string $search = ''): array {
    $args = ['post_type' => 'wp_block', 'post_status' => 'publish', 'numberposts' => 200];
    if ($search !== '') { $args['s'] = $search; }
    $out = [];
    foreach (get_posts($args) as $p) {
        $out[] = ['id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'modified' => $p->post_modified_gmt];
    }
    return $out;
}

function wpultra_gb_reusable_get(int $id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== 'wp_block') { return wpultra_err('reusable_not_found', "No reusable block with id $id."); }
    return ['id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content];
}

function wpultra_gb_reusable_save(array $args) {
    $title = (string) ($args['title'] ?? '');
    $id = (int) ($args['id'] ?? 0);
    if ($id > 0) {
        $existing = get_post($id);
        if (!$existing || $existing->post_type !== 'wp_block') { return wpultra_err('reusable_not_found', "No reusable block with id $id to update."); }
        $data = ['ID' => $id];
        if ($title !== '') { $data['post_title'] = $title; }
        if (array_key_exists('content', $args)) { $data['post_content'] = (string) $args['content']; }
        $res = wp_update_post($data, true);
    } else {
        if ($title === '') { return wpultra_err('missing_title', 'title is required to create a reusable block.'); }
        $res = wp_insert_post(['post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => (string) ($args['content'] ?? '')], true);
    }
    if (is_wp_error($res)) { return $res; }
    $pid = (int) $res;
    $p = get_post($pid);
    return ['id' => $pid, 'title' => $p ? $p->post_title : $title];
}
