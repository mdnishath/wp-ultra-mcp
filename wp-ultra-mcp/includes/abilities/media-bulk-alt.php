<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/media-bulk-alt', [
    'label'       => __('Bulk Alt Text', 'wp-ultra-mcp'),
    'description' => __('Bulk-fill missing image alt text in two steps. `action: list-missing` returns up to `limit` images (default 20, max 100) whose `_wp_attachment_image_alt` is empty — for each returned `{id, url, title}`, look at the image at `url` and write a short descriptive alt text. Then call `action: set` with `alts` as an `{id: alt}` map to save them all in one pass; the response reports per-id update counts.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list-missing', 'set']],
            'limit'  => ['type' => 'integer', 'default' => 20],
            'alts'   => ['type' => 'object'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'items'   => ['type' => 'array'],
            'updated' => ['type' => 'integer'],
            'skipped' => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_media_bulk_alt_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_media_bulk_alt_ability(array $input) {
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'list-missing':
            $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));
            $items = wpultra_media_alt_missing($limit);
            return wpultra_ok(['items' => $items, 'count' => count($items)]);

        case 'set':
            $alts = $input['alts'] ?? null;
            if (!is_array($alts) || $alts === []) { return wpultra_err('missing_alts', 'alts is required for action=set (object of id => alt).'); }
            $res = wpultra_media_alt_set($alts);
            wpultra_audit_log('media-bulk-alt', 'set updated=' . $res['updated'] . ' skipped=' . $res['skipped'], true);
            return wpultra_ok($res);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }
}
