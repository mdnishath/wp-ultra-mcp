<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/execute-wp-query', [
    'label'       => __('Execute WP Query', 'wp-ultra-mcp'),
    'description' => __('Execute a SQL query via $wpdb.', 'wp-ultra-mcp'),
    'category'    => 'database',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'sql'     => ['type' => 'string'],
            'params'  => ['type' => 'array'],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['sql'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'verb'          => ['type' => 'string'],
            'rows'          => ['type' => 'array'],
            'row_count'     => ['type' => 'integer'],
            'rows_affected' => ['type' => 'integer'],
            'insert_id'     => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_execute_wp_query',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_execute_wp_query(array $input) {
    global $wpdb;
    $sql = (string) ($input['sql'] ?? '');
    if ($sql === '') { return wpultra_err('empty_sql', 'sql is required.'); }
    $params = array_values((array) ($input['params'] ?? []));
    $confirm = ($input['confirm'] ?? false) === true;
    $class = wpultra_classify_query($sql);
    if ($class['destructive'] && !$confirm) {
        return wpultra_err('destructive_unconfirmed', 'This query is destructive. Re-run with confirm: true to proceed.');
    }
    $prepared = $params === [] ? $sql : $wpdb->prepare($sql, $params);
    if ($class['verb'] === 'SELECT') {
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        return wpultra_ok(['verb' => $class['verb'], 'rows' => $rows, 'row_count' => count($rows)]);
    }
    $affected = $wpdb->query($prepared);
    wpultra_audit_log('execute-wp-query', $sql, $affected !== false);
    return wpultra_ok(['verb' => $class['verb'], 'rows_affected' => (int) $affected, 'insert_id' => (int) $wpdb->insert_id]);
}
