<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/element-custom-css', [
    'label'       => __('Elementor: Element Custom CSS', 'wp-ultra-mcp'),
    'description' => __("Get, set, or remove raw per-element Custom CSS. When Elementor Pro is active the CSS is written verbatim into the element's Pro Custom CSS setting (write it using Elementor's own `selector` placeholder for the element root, e.g. `selector { color: red }`). Without Pro, the same `selector`-based CSS is accepted and routed into the site-wide Additional CSS store inside a clearly marked, idempotent block scoped to this post+element, with `selector` rewritten to a concrete attribute selector targeting this element. The response's `path` field reports which store was used (pro|free). set/remove require confirm:true.", 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['get', 'set', 'remove'], 'default' => 'get'],
            'post_id'    => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'css'        => ['type' => 'string'],
            'confirm'    => ['type' => 'boolean'],
        ],
        'required'             => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'path'        => ['type' => 'string'],
            'post_id'     => ['type' => 'integer'],
            'element_id'  => ['type' => 'string'],
            'css'         => ['type' => 'string'],
            'exists'      => ['type' => 'boolean'],
            'removed'     => ['type' => 'boolean'],
            'selector'    => ['type' => 'string'],
            'setting_key' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_element_custom_css_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_element_custom_css_cb(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    $elid = (string) ($input['element_id'] ?? '');
    $action = (string) ($input['action'] ?? 'get');

    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    if ($elid === '') { return wpultra_err('bad_input', 'element_id is required.'); }
    if (!in_array($action, ['get', 'set', 'remove'], true)) {
        return wpultra_err('bad_action', "Unknown action '$action'. Use get, set, or remove.");
    }

    // element_id is interpolated verbatim into the free-path marker comment and CSS selector
    // (customcss.php: wpultra_elcss_marker()/wpultra_elcss_concrete_selector()), so it must be
    // charset-validated before it reaches either of those — otherwise a crafted id could break
    // out of the selector's attribute value or corrupt the idempotent marker block boundaries.
    $id_check = wpultra_elcss_validate_element_id($elid);
    if (is_wp_error($id_check)) { return $id_check; }

    $data = wpultra_el_raw($post_id);
    $node = wpultra_el_find($data, $elid);
    if ($node === null) { return wpultra_err('element_not_found', "No element with id '$elid' on post $post_id."); }

    if ($action === 'get') {
        return wpultra_elcss_get($post_id, $elid, $node);
    }

    if (($input['confirm'] ?? false) !== true) {
        $verb = $action === 'set' ? 'Setting' : 'Removing';
        return wpultra_err('confirm_required', "$verb custom CSS is destructive. Re-run with confirm:true.");
    }

    if ($action === 'set') {
        if (!array_key_exists('css', $input)) {
            return wpultra_err('missing_css', "action:'set' requires a css value. Pass an empty string explicitly to clear the CSS.");
        }
        $css = (string) $input['css'];
        return wpultra_elcss_set($post_id, $elid, $css, $data, $node);
    }

    // remove
    return wpultra_elcss_remove($post_id, $elid, $data, $node);
}
