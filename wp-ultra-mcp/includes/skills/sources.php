<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }
require_once __DIR__ . '/parser.php';

/** Return all skills (built-in + user CPT) as ['slug'=>['name','description','body','enable_prompt','enable_agentic','source']]. */
function wpultra_skill_all(): array {
    $skills = [];
    foreach (glob(__DIR__ . '/built-in/*.md') ?: [] as $file) {
        $slug = basename($file, '.md');
        $parsed = wpultra_skill_parse_frontmatter((string) file_get_contents($file));
        $skills[$slug] = $parsed + ['source' => 'built-in', 'slug' => $slug];
    }
    $posts = get_posts(['post_type' => 'wpultra_skill', 'post_status' => 'publish', 'numberposts' => 200]);
    foreach ($posts as $p) {
        $skills[$p->post_name] = [
            'name' => $p->post_name, 'description' => $p->post_excerpt, 'body' => $p->post_content,
            'enable_prompt' => get_post_meta($p->ID, '_enable_prompt', true) !== '0',
            'enable_agentic' => get_post_meta($p->ID, '_enable_agentic', true) !== '0',
            'source' => 'user-cpt', 'slug' => $p->post_name, 'post_id' => $p->ID,
        ];
    }
    return $skills;
}
