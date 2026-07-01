<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-quick-setup', [
    'label'       => __('SEO: Quick Setup', 'wp-ultra-mcp'),
    'description' => __('Apply a Google-recommended baseline: enable the XML sitemap, ensure the site is not discouraging search engines, and return a prioritized checklist of what the AI should do next (fill meta, set focus keywords, add internal links). Idempotent.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'applied' => ['type' => 'array'], 'recommendations' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_quick_setup_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
]);

function wpultra_seo_quick_setup_cb(array $input) {
    $applied = [];
    // 1. Ensure search engines are allowed to index the site.
    if (!get_option('blog_public')) { update_option('blog_public', 1); $applied[] = 'Enabled search-engine indexing (blog_public).'; }
    else { $applied[] = 'Search-engine indexing already enabled.'; }
    // 2. Enable the sitemap.
    if (function_exists('wpultra_seo_set_sitemap')) { $sm = wpultra_seo_set_sitemap(true); $applied[] = 'Sitemap enabled: ' . ($sm['url'] ?? ''); }
    $mode = function_exists('wpultra_seo_mode') ? wpultra_seo_mode() : 'native';
    $recommendations = [
        'Run seo-site-audit to find posts missing titles/descriptions and thin content.',
        'Use seo-bulk-set-meta with a title template (e.g. "%title% %sep% %sitename%") to fill missing SEO titles.',
        'Set a focus keyword per key page and use seo-analyze-page / seo-optimize-content to improve it.',
        'Use seo-suggest-internal-links + seo-insert-internal-link to build internal links and fix orphan pages (seo-link-audit).',
        'Add structured data with seo-manage-schema, and LocalBusiness data with seo-manage-local-business if local.',
    ];
    if ($mode !== 'native') { $recommendations[] = "SEO plugin active ($mode): title templates + breadcrumbs + org schema are configured in the {$mode} plugin's own settings."; }
    wpultra_audit_log('seo-quick-setup', 'baseline applied', true);
    return wpultra_ok(['applied' => $applied, 'recommendations' => $recommendations]);
}
