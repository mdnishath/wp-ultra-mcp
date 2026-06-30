<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Which SEO plugin is driving meta: yoast | rankmath | native. */
function wpultra_seo_mode(): string {
    if (defined('WPSEO_VERSION')) { return 'yoast'; }
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper')) { return 'rankmath'; }
    return 'native';
}

function wpultra_seo_plugin_version(): string {
    if (defined('WPSEO_VERSION')) { return (string) WPSEO_VERSION; }
    if (defined('RANK_MATH_VERSION')) { return (string) RANK_MATH_VERSION; }
    return '';
}

function wpultra_seo_status(): array {
    $mode = wpultra_seo_mode();
    $counts = wp_count_posts('post');
    $published = (int) ($counts->publish ?? 0);
    return [
        'mode'            => $mode,
        'plugin_version'  => wpultra_seo_plugin_version(),
        'sitemap_enabled' => (bool) get_option('blog_public', 1),
        'site_name'       => get_bloginfo('name'),
        'home_url'        => home_url('/'),
        'posts_published' => $published,
    ];
}
