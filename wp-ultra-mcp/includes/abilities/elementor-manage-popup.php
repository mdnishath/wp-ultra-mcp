<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-manage-popup', [
    'label'       => __('Elementor Pro: Manage Popup', 'wp-ultra-mcp'),
    'description' => __('Configure Elementor Pro popups. actions: list (popups + conditions + current triggers); get-display (a popup\'s raw display settings); set-display — friendly trigger options {on_click:true, page_load:<delay s>, scroll:<percent>, exit_intent:true, inactivity:<s>, show_times:<n>, show_after_sessions:<n>} become Pro\'s native trigger/timing settings, and optional conditions (same strings as elementor-manage-library) control WHERE it can appear. Create the popup itself first via elementor-manage-library (type: popup) and fill it with elementor-set-content.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'get-display', 'set-display']],
            'popup_id' => ['type' => 'integer'],
            'triggers' => ['type' => 'object'],
            'conditions' => ['type' => 'array'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'popups'   => ['type' => 'array'],
            'popup_id' => ['type' => 'integer'],
            'display'  => ['type' => 'object'],
            'conditions' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_manage_popup_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_elementor_manage_popup_cb(array $input) {
    $pro = wpultra_epro_require();
    if (is_wp_error($pro)) { return $pro; }
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $rows = [];
        foreach (wpultra_epro_templates('popup', 100) as $p) {
            $rows[] = $p + [
                'conditions' => (array) get_post_meta($p['id'], '_elementor_conditions', true),
                'display'    => (array) get_post_meta($p['id'], '_elementor_popup_display_settings', true),
            ];
        }
        return wpultra_ok(['popups' => $rows]);
    }

    $pid = (int) ($input['popup_id'] ?? 0);
    if (get_post_meta($pid, '_elementor_template_type', true) !== 'popup') {
        return wpultra_err('not_found', "No popup template with id $pid (create one via elementor-manage-library type: popup).");
    }

    if ($action === 'get-display') {
        return wpultra_ok([
            'popup_id'   => $pid,
            'display'    => (array) get_post_meta($pid, '_elementor_popup_display_settings', true),
            'conditions' => (array) get_post_meta($pid, '_elementor_conditions', true),
        ]);
    }

    if ($action === 'set-display') {
        $display = wpultra_epro_build_popup_display((array) ($input['triggers'] ?? []));
        if ($display['triggers'] === []) {
            return wpultra_err('no_triggers', 'Provide at least one trigger: on_click, page_load, scroll, exit_intent, or inactivity.');
        }
        update_post_meta($pid, '_elementor_popup_display_settings', $display);
        $conds = null;
        if (isset($input['conditions'])) {
            $conds = array_values(array_map('strval', (array) $input['conditions']));
            foreach ($conds as $c) {
                $v = wpultra_epro_validate_condition($c);
                if ($v !== true) { return wpultra_err('bad_condition', (string) $v); }
            }
            update_post_meta($pid, '_elementor_conditions', $conds);
            wpultra_epro_flush_conditions();
        }
        wpultra_audit_log('elementor-manage-popup', "popup $pid display set (" . implode(',', array_keys($display['triggers'])) . ')', true);
        return wpultra_ok(['popup_id' => $pid, 'display' => $display, 'conditions' => $conds ?? (array) get_post_meta($pid, '_elementor_conditions', true)]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
