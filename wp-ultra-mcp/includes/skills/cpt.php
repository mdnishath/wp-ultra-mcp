<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
add_action('init', function () {
    register_post_type('wpultra_skill', [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt', 'revisions'], 'rewrite' => false,
    ]);
});
