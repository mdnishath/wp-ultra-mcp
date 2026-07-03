<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-form-submissions', [
    'label'       => __('Elementor Pro: Form Submissions', 'wp-ultra-mcp'),
    'description' => __('Read Elementor Pro form submissions (the e_submissions tables). actions: forms (distinct forms with submission/unread counts); list (filter by form_name / post_id / status / unread, paginated, each row includes the flattened field values); get (one submission: full fields + meta); mark-read (submission_id); delete (submission_id, confirm).', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'        => ['type' => 'string', 'enum' => ['forms', 'list', 'get', 'mark-read', 'delete']],
            'form_name'     => ['type' => 'string'],
            'post_id'       => ['type' => 'integer'],
            'status'        => ['type' => 'string'],
            'unread'        => ['type' => 'boolean'],
            'per_page'      => ['type' => 'integer'],
            'page'          => ['type' => 'integer'],
            'submission_id' => ['type' => 'integer'],
            'confirm'       => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'forms'       => ['type' => 'array'],
            'submissions' => ['type' => 'array'],
            'submission'  => ['type' => 'object'],
            'updated'     => ['type' => 'boolean'],
            'deleted'     => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_form_submissions_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_form_submissions_cb(array $input) {
    $pro = wpultra_epro_require();
    if (is_wp_error($pro)) { return $pro; }
    global $wpdb;
    $t = $wpdb->prefix . 'e_submissions';
    $tv = $wpdb->prefix . 'e_submissions_values';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) {
        return wpultra_err('submissions_unavailable', 'The Elementor Pro submissions tables do not exist on this site.');
    }
    $action = (string) ($input['action'] ?? 'forms');

    if ($action === 'forms') {
        return wpultra_ok(['forms' => wpultra_epro_forms()]);
    }

    if ($action === 'list') {
        $q = wpultra_epro_submissions_sql($t, $input);
        $rows = (array) $wpdb->get_results($wpdb->prepare($q['sql'], ...$q['args']), ARRAY_A);
        foreach ($rows as &$row) {
            $vals = (array) $wpdb->get_results($wpdb->prepare("SELECT `key`, `value` FROM {$tv} WHERE submission_id = %d", (int) $row['id']), ARRAY_A);
            $row['fields'] = wpultra_epro_flatten_values($vals);
        }
        unset($row);
        return wpultra_ok(['submissions' => $rows]);
    }

    $sid = (int) ($input['submission_id'] ?? 0);
    if ($sid <= 0) { return wpultra_err('missing_submission_id', 'submission_id is required.'); }
    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $sid), ARRAY_A);
    if (!$sub) { return wpultra_err('not_found', "No submission $sid."); }

    if ($action === 'get') {
        unset($sub['user_ip'], $sub['user_agent']); // no need to round-trip PII beyond the fields
        $vals = (array) $wpdb->get_results($wpdb->prepare("SELECT `key`, `value` FROM {$tv} WHERE submission_id = %d", $sid), ARRAY_A);
        $sub['fields'] = wpultra_epro_flatten_values($vals);
        return wpultra_ok(['submission' => $sub]);
    }

    if ($action === 'mark-read') {
        $ok = $wpdb->update($t, ['is_read' => 1], ['id' => $sid]) !== false;
        return wpultra_ok(['updated' => $ok]);
    }

    if ($action === 'delete') {
        if (($input['confirm'] ?? false) !== true) {
            return wpultra_err('confirm_required', 'Deleting a submission requires confirm: true.');
        }
        $wpdb->delete($tv, ['submission_id' => $sid]);
        $ok = $wpdb->delete($t, ['id' => $sid]) !== false;
        wpultra_audit_log('elementor-form-submissions', "deleted submission $sid", $ok);
        return wpultra_ok(['deleted' => $ok]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
