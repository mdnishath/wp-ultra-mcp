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
    return $custom !== '' ? $custom : $title;
}

add_action('wp_head', 'wpultra_seo_render_head', 1);
function wpultra_seo_render_head(): void {
    if (!wpultra_seo_native_active() || !is_singular()) { return; }
    $id = get_queried_object_id();
    $m = wpultra_seo_get_meta($id);
    $out = '';
    if ($m['description'] !== '') { $out .= '<meta name="description" content="' . esc_attr($m['description']) . '">' . "\n"; }
    if ($m['canonical'] !== '') { $out .= '<link rel="canonical" href="' . esc_url($m['canonical']) . '">' . "\n"; }
    $robots = [];
    if (!empty($m['robots_noindex'])) { $robots[] = 'noindex'; }
    if (!empty($m['robots_nofollow'])) { $robots[] = 'nofollow'; }
    if ($robots) { $out .= '<meta name="robots" content="' . esc_attr(implode(',', $robots)) . '">' . "\n"; }
    $ogTitle = $m['og_title'] !== '' ? $m['og_title'] : ($m['title'] !== '' ? $m['title'] : get_the_title($id));
    $ogDesc = $m['og_description'] !== '' ? $m['og_description'] : $m['description'];
    $out .= '<meta property="og:title" content="' . esc_attr($ogTitle) . '">' . "\n";
    if ($ogDesc !== '') { $out .= '<meta property="og:description" content="' . esc_attr($ogDesc) . '">' . "\n"; }
    if ($m['og_image'] !== '') { $out .= '<meta property="og:image" content="' . esc_url($m['og_image']) . '">' . "\n"; }
    echo "<!-- WP-Ultra-MCP SEO -->\n" . $out . "<!-- /WP-Ultra-MCP SEO -->\n"; // phpcs:ignore
}
