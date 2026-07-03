<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Sugar over trigger-create for roadmap #31: auto-share new posts via a webhook
 * automation (Zapier / Make / Buffer scenario) — no per-network social API keys
 * ever stored in WordPress. Builds a post_published -> webhook trigger whose body
 * is a template shaped for a "post to social" automation (text / title / url /
 * image), optionally filtered to one post type.
 */

/** Pure: the default body template used when the caller doesn't supply one. */
function wpultra_social_autopost_default_template(): array {
    return [
        'text'  => '{data.title} {data.permalink}',
        'title' => '{data.title}',
        'url'   => '{data.permalink}',
        'image' => '{data.featured_image}',
    ];
}

wp_register_ability('wpultra/social-autopost', [
    'label'       => __('Auto-Post New Posts to Social (via Webhook)', 'wp-ultra-mcp'),
    'description' => __('Auto-share new posts via a webhook automation (Zapier / Make / Buffer) — no per-network API keys stored in WP. Sugar over an event trigger. action:create registers a post_published -> webhook trigger POSTing a social-ready body (text/title/url/image) built from the post title, permalink and featured image; optionally filter to one post_type and override the template. action:list shows only these auto-post triggers; action:delete removes one by id. Delivery is async so it never blocks publishing.', 'wp-ultra-mcp'),
    'category'    => 'triggers',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['list', 'create', 'delete']],
            'url'       => ['type' => 'string', 'description' => 'create: webhook of your Zapier / Buffer / Make scenario.'],
            'secret'    => ['type' => 'string', 'description' => 'create: optional HMAC signing secret.'],
            'post_type' => ['type' => 'string', 'description' => 'create: only auto-post this post type (e.g. post).'],
            'template'  => ['type' => 'object', 'description' => 'create: optional body reshape — defaults to {text,title,url,image} from the post. Tokens: {data.title}, {data.permalink}, {data.featured_image}, {data.excerpt}.'],
            'label'     => ['type' => 'string'],
            'id'        => ['type' => 'integer', 'description' => 'delete: trigger id.'],
            'confirm'   => ['type' => 'boolean', 'description' => 'delete: must be true to remove.'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'id'       => ['type' => 'integer'],
            'triggers' => ['type' => 'array'],
            'changed'  => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_social_autopost_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_social_autopost_cb(array $input) {
    $action = (string) ($input['action'] ?? '');

    if ($action === 'list') {
        $rows = [];
        foreach (wpultra_triggers_load() as $t) {
            if ((string) ($t['event'] ?? '') !== 'post_published') { continue; }
            if ((string) ($t['action_type'] ?? '') !== 'webhook') { continue; }
            $shape = wpultra_triggers_shape($t);
            $shape['filter'] = isset($t['filter']) && is_array($t['filter']) ? $t['filter'] : [];
            $shape['has_template'] = isset($t['template']) && is_array($t['template']) && $t['template'] !== [];
            $rows[] = $shape;
        }
        return wpultra_ok(['triggers' => array_values($rows)]);
    }

    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) { return wpultra_err('missing_id', 'id is required to delete.'); }
        if (empty($input['confirm'])) { return wpultra_err('confirm_required', 'pass confirm:true to delete trigger #' . $id . '.'); }
        $changed = wpultra_triggers_delete($id);
        if ($changed) { wpultra_audit_log('social-autopost', "deleted social-autopost trigger #$id", true); }
        return wpultra_ok(['id' => $id, 'changed' => $changed]);
    }

    if ($action === 'create') {
        $url = (string) ($input['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) { return wpultra_err('invalid_url', 'create requires an http(s) url.'); }

        $filter = [];
        if (isset($input['post_type']) && (string) $input['post_type'] !== '') { $filter['post_type'] = (string) $input['post_type']; }

        $template = (isset($input['template']) && is_array($input['template']) && $input['template'] !== [])
            ? $input['template']
            : wpultra_social_autopost_default_template();

        $label = (string) ($input['label'] ?? '');
        if ($label === '') {
            $label = 'Social auto-post -> ' . preg_replace('#^https?://#i', '', $url);
        }

        $def = [
            'event'       => 'post_published',
            'action_type' => 'webhook',
            'url'         => $url,
            'template'    => $template,
            'label'       => $label,
        ];
        if (isset($input['secret']) && (string) $input['secret'] !== '') { $def['secret'] = (string) $input['secret']; }
        if ($filter !== []) { $def['filter'] = $filter; }

        $valid = wpultra_triggers_validate($def);
        if ($valid !== true) { return wpultra_err('invalid_trigger', (string) $valid); }

        $id = wpultra_triggers_create($def);
        wpultra_audit_log('social-autopost', "social-autopost trigger #$id -> $url", true);
        return wpultra_ok(['id' => $id]);
    }

    return wpultra_err('bad_action', "action must be one of: list, create, delete.");
}
