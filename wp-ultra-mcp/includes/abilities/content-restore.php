<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/content-restore', [
    'label'       => __('Restore Content Revision', 'wp-ultra-mcp'),
    'description' => __('Undo/rollback a post or page using WordPress revisions. actions: `list` (post_id → available revisions, newest first) and `restore` (revision_id, or post_id to restore its most recent revision). Restoring first snapshots the current state as a new revision so it is itself reversible.', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['list', 'restore'], 'default' => 'list'],
            'post_id'     => ['type' => 'integer'],
            'revision_id' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => ['success' => ['type' => 'boolean']],
        'required'   => ['success'],
    ],
    'execute_callback'    => 'wpultra_content_restore',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_content_restore(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $post_id = (int) ($input['post_id'] ?? 0);
        if (!get_post($post_id)) { return wpultra_err('not_found', "No post with id $post_id."); }
        $revs = wp_get_post_revisions($post_id, ['posts_per_page' => 25]);
        $rows = [];
        foreach ($revs as $r) {
            $rows[] = ['revision_id' => $r->ID, 'date' => $r->post_modified_gmt, 'author' => (int) $r->post_author, 'title' => $r->post_title];
        }
        return wpultra_ok(['post_id' => $post_id, 'revisions' => $rows, 'count' => count($rows)]);
    }

    if ($action === 'restore') {
        $rev_id = (int) ($input['revision_id'] ?? 0);
        if ($rev_id <= 0) {
            // Fall back to the most recent revision of the given post.
            $post_id = (int) ($input['post_id'] ?? 0);
            if (!get_post($post_id)) { return wpultra_err('missing_target', 'Provide revision_id or a valid post_id.'); }
            $revs = wp_get_post_revisions($post_id, ['posts_per_page' => 1]);
            $latest = $revs ? array_values($revs)[0] : null;
            if (!$latest) { return wpultra_err('no_revisions', "Post $post_id has no revisions to restore."); }
            $rev_id = (int) $latest->ID;
        }
        $rev = wp_get_post_revision($rev_id);
        if (!$rev) { return wpultra_err('bad_revision', "No revision with id $rev_id."); }
        $parent = (int) $rev->post_parent;
        if (in_array(get_post_type($parent), wpultra_reserved_post_types(), true)) {
            return wpultra_err('reserved_post_type', 'Refusing to restore a plugin-internal post.');
        }
        // Snapshot the current state first so the restore itself can be undone.
        wp_save_post_revision($parent);
        $restored = wp_restore_post_revision($rev_id);
        if (!$restored) { return wpultra_err('restore_failed', "Could not restore revision $rev_id."); }
        wpultra_audit_log('content-restore', "post $parent <- revision $rev_id", true);
        return wpultra_ok(['post_id' => $parent, 'restored_from' => $rev_id]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
