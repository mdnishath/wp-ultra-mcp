<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_gb_load(int $post_id) {
    $post = get_post($post_id);
    if (!$post) { return new WP_Error('post_not_found', "Post $post_id not found."); }
    return ['post' => $post, 'blocks' => parse_blocks((string) $post->post_content)];
}

function wpultra_gb_save(int $post_id, array $blocks) {
    $post_type = get_post_type($post_id);
    if ($post_type !== false && in_array($post_type, wpultra_reserved_post_types(), true)) {
        return new WP_Error('reserved_post_type', "Post $post_id is a plugin-internal post type; edit it via its dedicated ability.");
    }
    // serialize_block_attributes() emits \u00xx escapes; wp_update_post unslashes, so slash
    // first or every block attribute containing & < > " (e.g. URLs with query strings) corrupts.
    $content = serialize_blocks($blocks);
    $res = wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($content)], true);
    if (is_wp_error($res)) { return $res; }
    $reloaded = wpultra_gb_load($post_id);
    if (is_wp_error($reloaded)) { return $reloaded; }
    return wpultra_gb_compact_tree($reloaded['blocks']);
}

function wpultra_gb_tree(int $post_id) {
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    return wpultra_gb_compact_tree($loaded['blocks']);
}
