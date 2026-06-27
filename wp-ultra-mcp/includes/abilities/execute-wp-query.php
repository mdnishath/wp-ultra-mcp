<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

if (function_exists('wp_register_ability')) {
    wp_register_ability('execute-wp-query', [
        'slug'        => 'execute-wp-query',
        'category'    => 'database',
        'description' => 'Execute a SQL query via $wpdb.',
        'input'       => [
            'sql'     => ['type' => 'string', 'required' => true],
            'params'  => ['type' => 'array'],
            'confirm' => ['type' => 'boolean'],
        ],
        'output'      => [
            'success'       => ['type' => 'boolean'],
            'verb'          => ['type' => 'string'],
            'rows'          => ['type' => 'array'],
            'row_count'     => ['type' => 'integer'],
            'rows_affected' => ['type' => 'integer'],
            'insert_id'     => ['type' => 'integer'],
        ],
        'meta'        => ['destructive' => true],
        'callback'    => 'wpultra_execute_wp_query',
    ]);
}

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
    return wpultra_ok(['verb' => $class['verb'], 'rows_affected' => (int) $affected, 'insert_id' => (int) $wpdb->insert_id]);
}
