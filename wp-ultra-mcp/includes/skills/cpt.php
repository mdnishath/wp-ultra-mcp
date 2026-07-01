<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
function wpultra_register_skill_cpt(): void {
    register_post_type('wpultra_skill', [
        'public' => false, 'show_ui' => false, 'show_in_rest' => false,
        'supports' => ['title', 'editor', 'excerpt', 'revisions'], 'rewrite' => false,
    ]);
}
// This file also loads on wp_abilities_api_init (after `init` has fired on REST requests),
// so register immediately when init already ran; otherwise hook it normally.
if (function_exists('did_action') && did_action('init')) { wpultra_register_skill_cpt(); }
else { add_action('init', 'wpultra_register_skill_cpt'); }
