<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The complex-field engine lives beside the fields domain. Load it here so this ability is
// self-contained regardless of the fields engine load order.
$__wpultra_complex = WPULTRA_DIR . 'includes/fields/complex.php';
if (is_readable($__wpultra_complex)) { require_once $__wpultra_complex; }
unset($__wpultra_complex);

wp_register_ability('wpultra/field-manage-rows', [
    'label'       => __('Manage Field Rows (Repeater / Flexible / Group)', 'wp-ultra-mcp'),
    'description' => __('Nested read-write for ACF Pro / Secure Custom Fields (SCF) complex fields on a post: repeater, flexible-content, and group. SCF is the free wp.org ACF fork that ships these three types. The field DEFINITION must already exist — create it first with wpultra/acf-define-field-group. Actions: get (read all rows + detected kind: repeater|flexible|group); set (replace ALL rows via rows[], needs confirm); add (insert one row[] at index, or append when index omitted; repeater/flexible only); update (patch the row at index — merge:true keeps other sub-values, merge:false replaces the row; for a group, index is ignored and the group sub-values are patched); delete (remove the row at index, needs confirm; repeater/flexible only). Flexible-content rows must include an acf_fc_layout key naming the layout. Field may be given by name or field key. Writes go through update_field() so ACF hooks fire.', 'wp-ultra-mcp'),
    'category'    => 'fields',
    'input_schema'  => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'field'   => ['type' => 'string', 'description' => 'Field name or field key.'],
            'action'  => ['type' => 'string', 'enum' => ['get', 'set', 'add', 'update', 'delete']],
            'rows'    => ['type' => 'array', 'description' => 'Full replacement rows for action=set (list of row objects).'],
            'row'     => ['type' => 'object', 'description' => 'One row payload for add/update (for a group, the sub-values).'],
            'index'   => ['type' => 'integer', 'description' => '0-based row index for add (insert position), update, delete.'],
            'merge'   => ['type' => 'boolean', 'default' => true, 'description' => 'update: merge into the existing row (true) or replace it wholesale (false).'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required true for destructive actions set and delete.'],
        ],
        'required' => ['post_id', 'field', 'action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'post_id' => ['type' => 'integer'],
            'field'   => ['type' => 'string'],
            'action'  => ['type' => 'string'],
            'kind'    => ['type' => 'string'],
            'rows'    => ['type' => 'array'],
            'count'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_field_manage_rows',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_field_manage_rows(array $input) {
    if (!function_exists('wpultra_fields_rows_get')) {
        return wpultra_err('engine_missing', 'The complex-field engine (fields/complex.php) is not loaded.');
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    $field   = trim((string) ($input['field'] ?? ''));
    $action  = (string) ($input['action'] ?? '');
    if ($post_id <= 0)  { return wpultra_err('post_id_required', 'A positive post_id is required.'); }
    if ($field === '')  { return wpultra_err('field_required', 'field (name or key) is required.'); }
    if (!in_array($action, ['get', 'set', 'add', 'update', 'delete'], true)) {
        return wpultra_err('action_invalid', "action must be one of get|set|add|update|delete.");
    }

    $post = get_post($post_id);
    if (!$post) { return wpultra_err('not_found', "No post with id {$post_id}."); }
    if (in_array($post->post_type, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "Post {$post_id} is a plugin-internal '{$post->post_type}'; edit it via its dedicated ability.");
    }

    // ---- READ ----
    if ($action === 'get') {
        $res = wpultra_fields_rows_get($post_id, $field);
        if (is_wp_error($res)) { return $res; }
        $rows = $res['rows'];
        return wpultra_ok([
            'post_id' => $post_id,
            'field'   => $field,
            'action'  => 'get',
            'kind'    => $res['kind'],
            'rows'    => $rows,
            'count'   => is_array($rows) && array_is_list($rows) ? count($rows) : ($rows === [] ? 0 : 1),
        ]);
    }

    // ---- MUTATIONS ----
    $merge = array_key_exists('merge', $input) ? (bool) $input['merge'] : true;

    switch ($action) {
        case 'set':
            if (empty($input['confirm'])) {
                return wpultra_err('confirm_required', "action=set replaces ALL rows of '{$field}'; pass confirm:true to proceed.");
            }
            if (!array_key_exists('rows', $input) || !is_array($input['rows'])) {
                return wpultra_err('rows_required', 'action=set requires rows[] (the full replacement set).');
            }
            $res = wpultra_fields_rows_set($post_id, $field, $input['rows']);
            break;

        case 'add':
            if (!array_key_exists('row', $input) || !is_array($input['row'])) {
                return wpultra_err('row_required', 'action=add requires row (the row object to insert).');
            }
            $at = array_key_exists('index', $input) ? (int) $input['index'] : null;
            $res = wpultra_fields_rows_add($post_id, $field, $input['row'], $at);
            break;

        case 'update':
            if (!array_key_exists('row', $input) || !is_array($input['row'])) {
                return wpultra_err('row_required', 'action=update requires row (the sub-values to write).');
            }
            $index = array_key_exists('index', $input) ? (int) $input['index'] : 0;
            $res = wpultra_fields_rows_update($post_id, $field, $index, $input['row'], $merge);
            break;

        case 'delete':
            if (empty($input['confirm'])) {
                return wpultra_err('confirm_required', "action=delete removes a row from '{$field}'; pass confirm:true to proceed.");
            }
            if (!array_key_exists('index', $input)) {
                return wpultra_err('index_required', 'action=delete requires index (the 0-based row to remove).');
            }
            $res = wpultra_fields_rows_delete($post_id, $field, (int) $input['index']);
            break;

        default:
            return wpultra_err('action_invalid', 'Unhandled action.');
    }

    if (is_wp_error($res)) {
        wpultra_audit_log('field-manage-rows', "action={$action} post={$post_id} field={$field} FAILED: " . $res->get_error_code(), false);
        return $res;
    }

    wpultra_audit_log('field-manage-rows', "action={$action} post={$post_id} field={$field} count=" . ($res['count'] ?? '?'));
    return wpultra_ok(array_merge(['post_id' => $post_id, 'field' => $field, 'action' => $action], $res));
}
