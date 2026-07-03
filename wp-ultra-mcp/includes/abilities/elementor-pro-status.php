<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-pro-status', [
    'label'       => __('Elementor Pro: Status', 'wp-ultra-mcp'),
    'description' => __('Report the Elementor Pro surface: active + version, theme-builder template counts by type (header/footer/single/archive/popup/loop-item/...), popups with their display conditions, and Pro form totals (forms seen, submissions, unread). Start here before using elementor-manage-library / elementor-manage-popup / elementor-form-submissions.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'active'      => ['type' => 'boolean'],
            'version'     => ['type' => 'string'],
            'templates'   => ['type' => 'object'],
            'popups'      => ['type' => 'array'],
            'forms'       => ['type' => 'array'],
            'submissions' => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_pro_status_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_elementor_pro_status_cb(array $input) {
    if (!wpultra_epro_active()) {
        return wpultra_ok(['active' => false, 'version' => '', 'templates' => [], 'popups' => [], 'forms' => [], 'submissions' => []]);
    }
    $by_type = [];
    foreach (wpultra_epro_templates('', 500) as $t) {
        $by_type[$t['type']] = ($by_type[$t['type']] ?? 0) + 1;
    }
    $popups = [];
    foreach (wpultra_epro_templates('popup', 50) as $p) {
        $popups[] = $p + ['conditions' => (array) get_post_meta($p['id'], '_elementor_conditions', true)];
    }
    global $wpdb;
    $t = $wpdb->prefix . 'e_submissions';
    $has = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
    return wpultra_ok([
        'active'      => true,
        'version'     => (string) ELEMENTOR_PRO_VERSION,
        'templates'   => $by_type,
        'popups'      => $popups,
        'forms'       => wpultra_epro_forms(),
        'submissions' => $has ? [
            'total'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
            'unread' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE is_read = 0"),
        ] : ['total' => 0, 'unread' => 0],
    ]);
}
