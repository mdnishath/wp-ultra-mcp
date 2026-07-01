<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** True when WE own SEO output (no Yoast/Rank Math active). */
function wpultra_seo_native_active(): bool {
    return function_exists('wpultra_seo_mode') && wpultra_seo_mode() === 'native';
}

add_filter('pre_get_document_title', 'wpultra_seo_filter_title', 20);
function wpultra_seo_filter_title($title) {
    if (!wpultra_seo_native_active() || !is_singular()) { return $title; }
    $custom = (string) get_post_meta(get_queried_object_id(), '_wpultra_seo_title', true);
    // Core echoes the pre_get_document_title value into <title> WITHOUT escaping, so strip
    // tags/entities here to prevent a stored '</title><script>' from breaking out.
    return $custom !== '' ? esc_html(wp_strip_all_tags($custom)) : $title;
}

add_action('wp_head', 'wpultra_seo_render_head', 1);
function wpultra_seo_render_head(): void {
    if (!wpultra_seo_native_active() || !is_singular()) { return; }
    $id = get_queried_object_id();
    $m = wpultra_seo_get_meta($id);
    $out = '';
    if ($m['description'] !== '') { $out .= '<meta name="description" content="' . esc_attr($m['description']) . '">' . "\n"; }
    if ($m['canonical'] !== '') {
        // We emit our own canonical; stop WP core's rel_canonical (wp_head @10) so the page
        // doesn't end up with two conflicting <link rel="canonical"> tags.
        remove_action('wp_head', 'rel_canonical');
        $out .= '<link rel="canonical" href="' . esc_url($m['canonical']) . '">' . "\n";
    }
    $robots = [];
    if (!empty($m['robots_noindex'])) { $robots[] = 'noindex'; }
    if (!empty($m['robots_nofollow'])) { $robots[] = 'nofollow'; }
    if ($robots) { $out .= '<meta name="robots" content="' . esc_attr(implode(',', $robots)) . '">' . "\n"; }
    $title = $m['title'] !== '' ? $m['title'] : get_the_title($id);
    $ogTitle = $m['og_title'] !== '' ? $m['og_title'] : $title;
    $ogDesc = $m['og_description'] !== '' ? $m['og_description'] : $m['description'];
    $out .= '<meta property="og:title" content="' . esc_attr($ogTitle) . '">' . "\n";
    if ($ogDesc !== '') { $out .= '<meta property="og:description" content="' . esc_attr($ogDesc) . '">' . "\n"; }
    if ($m['og_image'] !== '') { $out .= '<meta property="og:image" content="' . esc_url($m['og_image']) . '">' . "\n"; }
    $out .= '<meta property="og:type" content="article">' . "\n";
    $permalink = get_permalink($id);
    if ($permalink) { $out .= '<meta property="og:url" content="' . esc_url($permalink) . '">' . "\n"; }
    // Twitter card: fall back to OG / title / description when the twitter_* fields are empty.
    $twTitle = $m['twitter_title'] !== '' ? $m['twitter_title'] : $ogTitle;
    $twDesc = $m['twitter_description'] !== '' ? $m['twitter_description'] : $ogDesc;
    $out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    $out .= '<meta name="twitter:title" content="' . esc_attr($twTitle) . '">' . "\n";
    if ($twDesc !== '') { $out .= '<meta name="twitter:description" content="' . esc_attr($twDesc) . '">' . "\n"; }
    echo "<!-- WP-Ultra-MCP SEO -->\n" . $out . "<!-- /WP-Ultra-MCP SEO -->\n"; // phpcs:ignore
}
