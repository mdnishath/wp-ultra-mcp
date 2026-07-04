<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensive: make sure the engine is loaded even if the bootstrap order changes.
if (!function_exists('wpultra_lf_scan_links')) {
    $wpultra_lf_engine = dirname(__DIR__) . '/system/linkfix.php';
    if (is_file($wpultra_lf_engine)) { require_once $wpultra_lf_engine; }
    unset($wpultra_lf_engine);
}

wp_register_ability('wpultra/link-fixer', [
    'label'       => __('Link Fixer (broken links + redirects)', 'wp-ultra-mcp'),
    'description' => __('Find and fix broken links + 404s. Actions: "scan-links" — crawl published posts/pages (offset-paged, default limit 100 per call, max 500; pass the returned next_offset to continue) and report broken links; internal links are resolved without HTTP (post/page/attachment/term lookup + existing redirect rules), external links are HEAD-checked only when check_external is true (max 20 HTTP checks per call). "suggest" — read the built-in 404 monitor log, aggregate hits per path, and fuzzy-match each 404 path against all published post/page slugs to propose redirect targets (score 0-100, only >= 55 returned, best + up to 3 alternatives). "apply-redirects" — write 301 rules into the SAME redirect store as seo-manage-redirects (loop-guarded; use that ability to list/delete later); redirects is an array of {from: "/old-path/", to: "/new-path/" | "https://url" | post_id}; requires confirm: true. "fix-in-content" — replace every exact occurrence of old_url with new_url inside published post/page content (prepared LIKE match, per-post replace, default limit 100 posts, max 500); requires confirm: true. Typical workflow: 1) {"action":"scan-links"} and/or {"action":"suggest"}; 2) review; 3) {"action":"apply-redirects","confirm":true,"redirects":[{"from":"/old-post/","to":"/new-post/"}]}; 4) optionally {"action":"fix-in-content","confirm":true,"old_url":"https://site.com/old-post/","new_url":"https://site.com/new-post/"} so content links stop bouncing through the redirect.', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'         => ['type' => 'string', 'enum' => ['scan-links', 'suggest', 'apply-redirects', 'fix-in-content']],
            'offset'         => ['type' => 'integer', 'description' => 'scan-links: continuation cursor from a previous call\'s next_offset (default 0).'],
            'limit'          => ['type' => 'integer', 'description' => 'scan-links: posts per call (default 100, max 500). fix-in-content: max posts to update (default 100, max 500).'],
            'check_external' => ['type' => 'boolean', 'description' => 'scan-links: also HEAD-check external links (max 20 per call). Default false.'],
            'redirects'      => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'Source path starting with "/", e.g. "/old-page/".'],
                        'to'   => ['type' => ['string', 'integer'], 'description' => 'Target: a post ID, a path starting with "/", or an absolute http(s) URL.'],
                    ],
                    'required' => ['from', 'to'],
                ],
                'description' => 'apply-redirects: the 301 rules to add.',
            ],
            'old_url'        => ['type' => 'string', 'description' => 'fix-in-content: exact URL string to replace.'],
            'new_url'        => ['type' => 'string', 'description' => 'fix-in-content: replacement URL.'],
            'confirm'        => ['type' => 'boolean', 'description' => 'Required true for apply-redirects and fix-in-content (both write).'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'broken'       => ['type' => 'array'],
            'next_offset'  => ['type' => ['integer', 'null']],
            'suggestions'  => ['type' => 'array'],
            'results'      => ['type' => 'array'],
            'applied'      => ['type' => 'integer'],
            'posts_updated'=> ['type' => 'integer'],
            'replacements' => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_link_fixer_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_link_fixer_ability(array $input) {
    $action = (string) ($input['action'] ?? '');

    if ($action === 'scan-links') {
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $limit  = isset($input['limit']) ? (int) $input['limit'] : 100;
        $ext    = !empty($input['check_external']);
        $res    = wpultra_lf_scan_links($offset, $limit, $ext);
        wpultra_audit_log('link-fixer', sprintf('scan-links offset=%d scanned=%d broken=%d', $offset, $res['scanned_posts'], count($res['broken'])), true);
        return wpultra_ok($res);
    }

    if ($action === 'suggest') {
        $res = wpultra_lf_suggest();
        wpultra_audit_log('link-fixer', 'suggest suggestions=' . count($res['suggestions']), true);
        return wpultra_ok($res);
    }

    if ($action === 'apply-redirects') {
        $rows = array_values((array) ($input['redirects'] ?? []));
        if (!$rows) { return wpultra_err('missing_redirects', 'apply-redirects requires a non-empty redirects array of {from, to}.'); }
        if (empty($input['confirm'])) {
            return wpultra_err('confirm_required', 'apply-redirects writes 301 redirect rules to the live site — pass confirm: true to proceed.');
        }
        $res = wpultra_lf_apply_redirects($rows);
        wpultra_audit_log('link-fixer', sprintf('apply-redirects applied=%d/%d', $res['applied'], count($rows)), $res['applied'] > 0 || !$rows);
        return wpultra_ok($res);
    }

    if ($action === 'fix-in-content') {
        $old = trim((string) ($input['old_url'] ?? ''));
        $new = trim((string) ($input['new_url'] ?? ''));
        if ($old === '' || $new === '') { return wpultra_err('missing_urls', 'fix-in-content requires old_url and new_url.'); }
        if ($old === $new) { return wpultra_err('same_url', 'old_url and new_url are identical — nothing to replace.'); }
        if (empty($input['confirm'])) {
            return wpultra_err('confirm_required', 'fix-in-content rewrites published post content — pass confirm: true to proceed.');
        }
        $limit = isset($input['limit']) ? (int) $input['limit'] : 100;
        $res = wpultra_lf_fix_in_content($old, $new, $limit);
        wpultra_audit_log('link-fixer', sprintf('fix-in-content "%s" -> "%s" posts=%d repl=%d', $old, $new, $res['posts_updated'], $res['replacements']), true);
        return wpultra_ok($res);
    }

    return wpultra_err('invalid_action', 'action must be one of: scan-links, suggest, apply-redirects, fix-in-content.');
}
