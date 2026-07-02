<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/manage-comment', [
    'label'       => __('Manage Comments', 'wp-ultra-mcp'),
    'description' => __('List, read, and moderate WordPress comments. actions: `list` (post_id?, status?, per_page/page), `get` (comment_id), `approve`/`unapprove`/`spam`/`trash` (comment_id), `delete` (comment_id, confirm), `reply` (post_id, comment_id as parent, content), `update` (comment_id, content).', 'wp-ultra-mcp'),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['list', 'get', 'approve', 'unapprove', 'spam', 'trash', 'delete', 'reply', 'update'], 'default' => 'list'],
            'comment_id' => ['type' => 'integer'],
            'post_id'    => ['type' => 'integer'],
            'status'     => ['type' => 'string'],
            'content'    => ['type' => 'string'],
            'per_page'   => ['type' => 'integer', 'default' => 20],
            'page'       => ['type' => 'integer', 'default' => 1],
            'force'      => ['type' => 'boolean'],
            'confirm'    => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_comment',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_manage_comment(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    $comment_id = (int) ($input['comment_id'] ?? 0);

    switch ($action) {
        case 'list':
            $res = wpultra_comments_list($input);
            break;
        case 'get':
            if ($comment_id <= 0) { return wpultra_err('missing_comment_id', 'comment_id is required.'); }
            $res = wpultra_comments_get($comment_id);
            break;
        case 'approve':
        case 'unapprove':
        case 'spam':
        case 'trash':
            if ($comment_id <= 0) { return wpultra_err('missing_comment_id', 'comment_id is required.'); }
            $res = wpultra_comments_set_status($comment_id, $action);
            break;
        case 'delete':
            if ($comment_id <= 0) { return wpultra_err('missing_comment_id', 'comment_id is required.'); }
            if (($input['confirm'] ?? false) !== true) { return wpultra_err('confirm_required', 'Deleting a comment requires confirm: true.'); }
            $res = wpultra_comments_delete($comment_id, ($input['force'] ?? false) === true);
            break;
        case 'reply':
            $post_id = (int) ($input['post_id'] ?? 0);
            if ($post_id <= 0) { return wpultra_err('missing_post_id', 'post_id is required for reply.'); }
            $res = wpultra_comments_reply($post_id, $comment_id, (string) ($input['content'] ?? ''));
            break;
        case 'update':
            if ($comment_id <= 0) { return wpultra_err('missing_comment_id', 'comment_id is required.'); }
            $res = wpultra_comments_update($comment_id, (string) ($input['content'] ?? ''));
            break;
        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }

    if (is_wp_error($res)) { return $res; }
    if (!in_array($action, ['list', 'get'], true)) {
        wpultra_audit_log('manage-comment', "$action id=" . (string) ($res['id'] ?? $comment_id), true);
    }
    return wpultra_ok($res);
}
