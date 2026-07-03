<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-indexnow', [
    'label'       => __('SEO: IndexNow', 'wp-ultra-mcp'),
    'description' => __('Ping search engines via the IndexNow protocol. action: status (key + auto-ping state) | submit (push urls, default = latest 10 published permalinks) | auto-on | auto-off (toggle auto-ping on publish).', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['status', 'submit', 'auto-on', 'auto-off']], 'urls' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['action'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'key' => ['type' => 'string'], 'keyLocation' => ['type' => 'string'], 'auto' => ['type' => 'boolean'], 'submitted' => ['type' => 'integer'], 'rejected' => ['type' => 'array']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_indexnow_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

/** Default submit target when the caller doesn't pass urls: latest 10 published permalinks. */
function wpultra_seo_indexnow_default_urls(): array {
    if (!function_exists('get_posts')) { return []; }
    $posts = get_posts(['post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 10, 'orderby' => 'date', 'order' => 'DESC']);
    $urls = [];
    foreach ($posts as $p) {
        $link = get_permalink($p);
        if ($link) { $urls[] = $link; }
    }
    return $urls;
}

function wpultra_seo_indexnow_cb(array $input) {
    $action = (string) ($input['action'] ?? 'status');
    $key = wpultra_indexnow_key();
    $keyLocation = function_exists('home_url') ? home_url('/' . $key . '.txt') : '';
    $auto = (bool) get_option('wpultra_indexnow_auto', false);

    if ($action === 'auto-on') {
        update_option('wpultra_indexnow_auto', true);
        wpultra_audit_log('seo-indexnow', 'auto-on', true);
        return wpultra_ok(['key' => $key, 'keyLocation' => $keyLocation, 'auto' => true]);
    }
    if ($action === 'auto-off') {
        update_option('wpultra_indexnow_auto', false);
        wpultra_audit_log('seo-indexnow', 'auto-off', true);
        return wpultra_ok(['key' => $key, 'keyLocation' => $keyLocation, 'auto' => false]);
    }
    if ($action === 'submit') {
        $urls = is_array($input['urls'] ?? null) && $input['urls'] ? $input['urls'] : wpultra_seo_indexnow_default_urls();
        if (!$urls) { return wpultra_err('no_urls', 'No urls provided and no published posts found to default to.'); }
        $res = wpultra_indexnow_submit($urls);
        if (is_wp_error($res)) {
            wpultra_audit_log('seo-indexnow', 'submit failed: ' . $res->get_error_message(), false);
            return $res;
        }
        wpultra_audit_log('seo-indexnow', 'submit ' . $res['submitted'] . ' urls', true);
        return wpultra_ok([
            'key'         => $res['key'],
            'keyLocation' => $res['keyLocation'],
            'submitted'   => $res['submitted'],
            'rejected'    => $res['rejected'],
            'auto'        => $auto,
        ]);
    }

    // status
    return wpultra_ok(['key' => $key, 'keyLocation' => $keyLocation, 'auto' => $auto]);
}
