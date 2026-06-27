<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
add_action('init', function () {
    register_post_type('wpultra_memory', [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt', 'revisions'], 'rewrite' => false,
    ]);
});
function wpultra_memory_shape(WP_Post $p): array {
    return [
        'id' => $p->ID, 'name' => $p->post_title, 'description' => $p->post_excerpt,
        'type' => (string) get_post_meta($p->ID, '_wpultra_memory_type', true),
        'updated_at' => $p->post_modified_gmt,
    ];
}
