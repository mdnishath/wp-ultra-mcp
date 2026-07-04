<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine + the shared AI helper (idempotent).
$__feed_engine = __DIR__ . '/../content/feed-import.php';
if (is_readable($__feed_engine)) { require_once $__feed_engine; }
$__ai_setup = __DIR__ . '/../ai/setup.php';
if (is_readable($__ai_setup)) { require_once $__ai_setup; }

wp_register_ability('wpultra/feed-import', [
    'label'       => __('Feed Importer', 'wp-ultra-mcp'),
    'description' => __(
        'Pull external RSS/Atom feeds into WordPress as DRAFT posts. Register feed sources, preview what would import, and import on demand or on a schedule. '
        . "Actions: add-feed (register a source — fetched once to confirm it parses), list-feeds, remove-feed, preview (read-only — shows the items that would import, deduped against already-seen), import-now (creates draft posts; confirm-gated), config (update a feed's settings). "
        . 'SAFETY: imports are DRAFTS by default. Auto-publish requires an explicit post_status AND auto_import in the feed config. Dedupe is per-feed by guid/link/title+date so the same item is never imported twice. '
        . 'AI-rewrite is OPT-IN per feed (rewrite:true) and needs an OpenAI key — it paraphrases each item into original wording to avoid duplicate-content penalties; without a key items import raw with a source attribution line. '
        . 'Examples: add-feed {url:"https://example.com/feed", name:"Example News", post_status:"draft"} · preview {feed_id:"feed_ab12…", max:5} · import-now {feed_id:"feed_ab12…", confirm:true} · config {feed_id:"feed_ab12…", auto_import:true, rewrite:true, tone:"friendly"}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['add-feed', 'list-feeds', 'remove-feed', 'preview', 'import-now', 'config'], 'default' => 'list-feeds'],
            'url'         => ['type' => 'string'],
            'feed_id'     => ['type' => 'string'],
            'name'        => ['type' => 'string'],
            'post_type'   => ['type' => 'string'],
            'post_status' => ['type' => 'string', 'enum' => ['draft', 'publish', 'pending', 'private']],
            'category'    => ['type' => 'integer'],
            'author'      => ['type' => 'integer'],
            'auto_import' => ['type' => 'boolean'],
            'rewrite'     => ['type' => 'boolean'],
            'tone'        => ['type' => 'string'],
            'max'         => ['type' => 'integer'],
            'max_per_run' => ['type' => 'integer'],
            'confirm'     => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'feeds'   => ['type' => 'array'],
            'feed'    => ['type' => 'object'],
            'items'   => ['type' => 'array'],
            'created' => ['type' => 'array'],
            'total'   => ['type' => 'integer'],
            'new'     => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_ability_feed_import',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_ability_feed_import(array $input) {
    if (!function_exists('wpultra_feed_parse_xml')) {
        return wpultra_err('engine_missing', 'The feed-import engine failed to load.');
    }
    $action = (string) ($input['action'] ?? 'list-feeds');

    switch ($action) {
        case 'list-feeds':
            $feeds = [];
            foreach (wpultra_feed_get_config() as $f) {
                $feeds[] = [
                    'id'          => (string) ($f['id'] ?? ''),
                    'name'        => (string) ($f['name'] ?? ''),
                    'url'         => (string) ($f['url'] ?? ''),
                    'post_type'   => (string) ($f['post_type'] ?? 'post'),
                    'post_status' => (string) ($f['post_status'] ?? 'draft'),
                    'auto_import' => !empty($f['auto_import']),
                    'rewrite'     => !empty($f['rewrite']),
                    'last_polled' => (int) ($f['last_polled'] ?? 0),
                    'seen_count'  => is_array($f['seen'] ?? null) ? count($f['seen']) : 0,
                ];
            }
            return wpultra_ok(['feeds' => $feeds, 'total' => count($feeds)]);

        case 'add-feed':
            $url = trim((string) ($input['url'] ?? ''));
            if ($url === '') { return wpultra_err('missing_url', 'url is required to add a feed.'); }
            if (!wpultra_feed_is_valid_url($url)) { return wpultra_err('bad_feed_url', "Invalid feed URL: $url"); }
            if (function_exists('esc_url_raw')) { $url = esc_url_raw($url); }

            // Fetch once to confirm it parses.
            $probe = wpultra_feed_fetch($url);
            if (is_wp_error($probe)) { return $probe; }

            $rec = wpultra_feed_default_record($url, (string) ($input['name'] ?? ''));
            if (isset($input['post_type']))   { $rec['post_type']   = (string) $input['post_type']; }
            if (isset($input['post_status'])) { $rec['post_status'] = (string) $input['post_status']; }
            if (isset($input['category']))    { $rec['category']    = (int) $input['category']; }
            if (isset($input['author']))      { $rec['author']      = (int) $input['author']; }
            if (isset($input['auto_import'])) { $rec['auto_import'] = (bool) $input['auto_import']; }
            if (isset($input['rewrite']))     { $rec['rewrite']     = (bool) $input['rewrite']; }
            if (isset($input['tone']))        { $rec['tone']        = (string) $input['tone']; }
            if (isset($input['max_per_run'])) { $rec['max_per_run'] = max(1, (int) $input['max_per_run']); }

            $feeds = wpultra_feed_get_config();
            foreach ($feeds as $f) {
                if ((string) ($f['id'] ?? '') === $rec['id']) {
                    return wpultra_err('feed_exists', "That feed is already registered (id {$rec['id']}). Use config to update it.");
                }
            }
            $feeds[] = $rec;
            wpultra_feed_save_config($feeds);
            if (function_exists('wpultra_feed_reconcile_schedule')) { wpultra_feed_reconcile_schedule(); }
            wpultra_audit_log('feed-import', "added feed {$rec['name']} ({$url}), parsed " . count($probe) . ' items', true);
            return wpultra_ok(['feed' => $rec, 'parsed_items' => count($probe)]);

        case 'remove-feed':
            $feed_id = (string) ($input['feed_id'] ?? '');
            if ($feed_id === '') { return wpultra_err('missing_feed_id', 'feed_id is required.'); }
            $feeds = wpultra_feed_get_config();
            $before = count($feeds);
            $feeds = array_values(array_filter($feeds, static fn($f) => (string) ($f['id'] ?? '') !== $feed_id));
            if (count($feeds) === $before) { return wpultra_err('unknown_feed', "No feed with id '$feed_id'."); }
            wpultra_feed_save_config($feeds);
            if (function_exists('wpultra_feed_reconcile_schedule')) { wpultra_feed_reconcile_schedule(); }
            wpultra_audit_log('feed-import', "removed feed $feed_id", true);
            return wpultra_ok(['removed' => $feed_id]);

        case 'config':
            $feed_id = (string) ($input['feed_id'] ?? '');
            if ($feed_id === '') { return wpultra_err('missing_feed_id', 'feed_id is required.'); }
            $feeds = wpultra_feed_get_config();
            $found = false;
            foreach ($feeds as $i => $f) {
                if ((string) ($f['id'] ?? '') !== $feed_id) { continue; }
                $found = true;
                if (isset($input['name']))        { $feeds[$i]['name']        = (string) $input['name']; }
                if (isset($input['post_type']))   { $feeds[$i]['post_type']   = (string) $input['post_type']; }
                if (isset($input['post_status'])) { $feeds[$i]['post_status'] = (string) $input['post_status']; }
                if (isset($input['category']))    { $feeds[$i]['category']    = (int) $input['category']; }
                if (isset($input['author']))      { $feeds[$i]['author']      = (int) $input['author']; }
                if (isset($input['auto_import'])) { $feeds[$i]['auto_import'] = (bool) $input['auto_import']; }
                if (isset($input['rewrite']))     { $feeds[$i]['rewrite']     = (bool) $input['rewrite']; }
                if (isset($input['tone']))        { $feeds[$i]['tone']        = (string) $input['tone']; }
                if (isset($input['max_per_run'])) { $feeds[$i]['max_per_run'] = max(1, (int) $input['max_per_run']); }
                $updated = $feeds[$i];
                break;
            }
            if (!$found) { return wpultra_err('unknown_feed', "No feed with id '$feed_id'."); }
            wpultra_feed_save_config($feeds);
            if (function_exists('wpultra_feed_reconcile_schedule')) { wpultra_feed_reconcile_schedule(); }
            wpultra_audit_log('feed-import', "updated config for feed $feed_id", true);
            return wpultra_ok(['feed' => $updated ?? []]);

        case 'preview':
            $target = (string) ($input['feed_id'] ?? $input['url'] ?? '');
            if ($target === '') { return wpultra_err('missing_target', 'Provide feed_id or url to preview.'); }
            $max = (int) ($input['max'] ?? 10);
            $res = wpultra_feed_preview($target, $max);
            if (is_wp_error($res)) { return $res; }
            return wpultra_ok($res);

        case 'import-now':
            $feed_id = (string) ($input['feed_id'] ?? '');
            if ($feed_id === '') { return wpultra_err('missing_feed_id', 'feed_id is required.'); }
            if (empty($input['confirm'])) {
                return wpultra_err('confirm_required', 'import-now creates draft posts. Preview first, then pass confirm:true to import.');
            }
            $max = (int) ($input['max'] ?? 0);
            $res = wpultra_feed_import_run($feed_id, $max);
            if (is_wp_error($res)) { return $res; }
            return wpultra_ok($res);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
