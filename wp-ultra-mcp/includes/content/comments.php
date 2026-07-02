<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Comments engine: list/get/moderate/reply/update. Shaping is pure-testable (no DB in the shaper itself). */

/**
 * Pure: shape a comment-like data array (as returned by get_comment(..., ARRAY_A) or a fixture)
 * into the compact output the ability returns. No WordPress calls — testable with a plain array.
 */
function wpultra_comment_shape_data(array $c): array {
    $status_map = ['1' => 'approved', '0' => 'unapproved', 'spam' => 'spam', 'trash' => 'trash'];
    $raw_status = (string) ($c['comment_approved'] ?? '0');
    return [
        'id'           => (int) ($c['comment_ID'] ?? 0),
        'post_id'      => (int) ($c['comment_post_ID'] ?? 0),
        'author'       => (string) ($c['comment_author'] ?? ''),
        'author_email' => (string) ($c['comment_author_email'] ?? ''),
        'content'      => (string) ($c['comment_content'] ?? ''),
        'status'       => $status_map[$raw_status] ?? $raw_status,
        'parent'       => (int) ($c['comment_parent'] ?? 0),
        'date'         => (string) ($c['comment_date'] ?? ''),
    ];
}

/** Shape a live WP_Comment/comment id into the compact output. Thin WP-calling wrapper. */
function wpultra_comment_shape(int $id): array {
    $c = get_comment($id, ARRAY_A);
    if (!$c) { return ['id' => $id]; }
    return wpultra_comment_shape_data($c);
}

/** @return array|WP_Error */
function wpultra_comments_list(array $q) {
    $per_page = max(1, min(100, (int) ($q['per_page'] ?? 20)));
    $page     = max(1, (int) ($q['page'] ?? 1));
    $args = ['number' => $per_page, 'paged' => $page, 'status' => 'all'];
    if (!empty($q['post_id'])) { $args['post_id'] = (int) $q['post_id']; }
    if (!empty($q['status']))  { $args['status'] = (string) $q['status']; }

    $comments = get_comments($args);
    $rows = array_map(
        static fn($c) => wpultra_comment_shape_data(is_array($c) ? $c : (array) $c->to_array()),
        is_array($comments) ? $comments : []
    );
    $total_args = $args;
    unset($total_args['number'], $total_args['paged']);
    $total_args['count'] = true;
    $total = (int) get_comments($total_args);

    return ['comments' => $rows, 'total' => $total, 'pages' => (int) ceil(max(1, $total) / $per_page)];
}

/** @return array|WP_Error */
function wpultra_comments_get(int $id) {
    $c = get_comment($id, ARRAY_A);
    if (!$c) { return wpultra_err('not_found', "No comment with id $id."); }
    return wpultra_comment_shape_data($c);
}

/** @return array|WP_Error */
function wpultra_comments_set_status(int $id, string $status) {
    if (!get_comment($id)) { return wpultra_err('not_found', "No comment with id $id."); }
    $map = ['approve' => 'approve', 'unapprove' => 'hold', 'spam' => 'spam', 'trash' => 'trash'];
    $wp_status = $map[$status] ?? '';
    if ($wp_status === '') { return wpultra_err('bad_status', "Unknown status action '$status'."); }
    $ok = wp_set_comment_status($id, $wp_status);
    if (!$ok) { return wpultra_err('status_failed', "Could not set comment $id to '$status'."); }
    return wpultra_comment_shape($id);
}

/** @return array|WP_Error */
function wpultra_comments_delete(int $id, bool $force) {
    if (!get_comment($id)) { return wpultra_err('not_found', "No comment with id $id."); }
    $ok = wp_delete_comment($id, $force);
    if (!$ok) { return wpultra_err('delete_failed', "Could not delete comment $id."); }
    return ['id' => $id, 'deleted' => true];
}

/** @return array|WP_Error */
function wpultra_comments_reply(int $post_id, int $parent_id, string $content) {
    if (!get_post($post_id)) { return wpultra_err('not_found', "No post with id $post_id."); }
    if (trim($content) === '') { return wpultra_err('missing_content', 'content is required for a reply.'); }
    $data = [
        'comment_post_ID'      => $post_id,
        'comment_content'      => $content,
        'comment_parent'       => $parent_id,
        'comment_approved'     => 1,
        'user_id'              => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        'comment_author'       => function_exists('wp_get_current_user') ? wp_get_current_user()->display_name : '',
        'comment_author_email' => function_exists('wp_get_current_user') ? wp_get_current_user()->user_email : '',
    ];
    $id = wp_insert_comment(wp_slash($data));
    if (!$id) { return wpultra_err('insert_failed', 'Could not insert reply comment.'); }
    return wpultra_comment_shape((int) $id);
}

/** @return array|WP_Error */
function wpultra_comments_update(int $id, string $content) {
    if (!get_comment($id)) { return wpultra_err('not_found', "No comment with id $id."); }
    if (trim($content) === '') { return wpultra_err('missing_content', 'content is required for update.'); }
    $ok = wp_update_comment(wp_slash(['comment_ID' => $id, 'comment_content' => $content]));
    if ($ok === false) { return wpultra_err('update_failed', "Could not update comment $id."); }
    return wpultra_comment_shape($id);
}
