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
    // Real sitemap state (provider + enabled) from the module, not blog_public (which is the
    // "discourage search engines" setting and unrelated to whether a sitemap is served).
    $sitemap = function_exists('wpultra_seo_sitemap_state')
        ? wpultra_seo_sitemap_state()
        : ['provider' => $mode === 'native' ? 'wp-core' : $mode, 'enabled' => true];
    return [
        'mode'                   => $mode,
        'plugin_version'         => wpultra_seo_plugin_version(),
        'sitemap_enabled'        => (bool) ($sitemap['enabled'] ?? true),
        'sitemap_provider'       => (string) ($sitemap['provider'] ?? ''),
        'search_engines_allowed' => (bool) get_option('blog_public', 1),
        'site_name'              => get_bloginfo('name'),
        'home_url'               => home_url('/'),
        'posts_published'        => $published,
    ];
}
