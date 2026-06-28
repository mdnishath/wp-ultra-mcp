<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Walk the element tree, validate each node via $validator, and aggregate a per-node report.
 * $validator(array $node): ['valid'=>bool, 'errors'=>string[], 'settings'=>array].
 * Returns ['ok'=>bool,'nodes'=>[],'summary'=>['total'=>int,'invalid'=>int],'normalized_tree'=>array].
 */
function wpultra_el_validate_tree(array $elements, ?callable $validator = null, int $depth = 0): array {
    if ($validator === null) { $validator = 'wpultra_el_validate_node'; }
    $nodes = [];
    $invalid = 0;
    $normalized = [];
    if ($depth > 100) {
        return [
            'ok'              => false,
            'nodes'           => [['id' => '', 'elType' => '', 'widgetType' => null, 'valid' => false, 'errors' => ['max nesting depth (100) exceeded — subtree not validated']]],
            'summary'         => ['total' => 1, 'invalid' => 1],
            'normalized_tree' => $elements,
        ];
    }
    foreach ($elements as $n) {
        if (!is_array($n)) { $normalized[] = $n; continue; }
        $res = $validator($n);
        $valid = (bool) ($res['valid'] ?? true);
        if (!$valid) { $invalid++; }
        $nodes[] = [
            'id'         => (string) ($n['id'] ?? ''),
            'elType'     => (string) ($n['elType'] ?? ''),
            'widgetType' => isset($n['widgetType']) ? (string) $n['widgetType'] : null,
            'valid'      => $valid,
            'errors'     => array_values((array) ($res['errors'] ?? [])),
        ];
        $n['settings'] = is_array($res['settings'] ?? null) ? $res['settings'] : ($n['settings'] ?? []);
        if (!empty($n['elements']) && is_array($n['elements'])) {
            $child = wpultra_el_validate_tree($n['elements'], $validator, $depth + 1);
            $nodes = array_merge($nodes, $child['nodes']);
            $invalid += $child['summary']['invalid'];
            $n['elements'] = $child['normalized_tree'];
        }
        $normalized[] = $n;
    }
    return [
        'ok'              => $invalid === 0,
        'nodes'           => $nodes,
        'summary'         => ['total' => count($nodes), 'invalid' => $invalid],
        'normalized_tree' => $normalized,
    ];
}

function wpultra_el_collect_ids(array $elements, int $depth = 0): array {
    if ($depth > 100) { return []; }
    $ids = [];
    foreach ($elements as $n) {
        if (!is_array($n)) { continue; }
        if (!empty($n['id'])) { $ids[] = (string) $n['id']; }
        if (!empty($n['elements']) && is_array($n['elements'])) {
            $ids = array_merge($ids, wpultra_el_collect_ids($n['elements'], $depth + 1));
        }
    }
    return $ids;
}

/** Scan rendered Elementor HTML for data-id / data-interaction-id markers; report which expected ids are present/dropped. */
function wpultra_el_render_digest(string $html, array $expectedIds): array {
    $present = [];
    // Classic elements use data-id; atomic widgets use data-interaction-id.
    foreach (['/data-id="([a-z0-9]+)"/i', '/data-interaction-id="([a-z0-9]+)"/i'] as $pattern) {
        if (preg_match_all($pattern, $html, $m)) {
            $present = array_merge($present, $m[1]);
        }
    }
    $present = array_values(array_unique($present));
    $dropped = array_values(array_diff(array_map('strval', $expectedIds), $present));
    // rendered_count tallies unique id-like attribute values from both data-id and data-interaction-id — approximate element-render tally.
    return ['rendered_count' => count($present), 'present_ids' => $present, 'dropped_ids' => $dropped];
}

/** Resolve the atomic widget/element type object for a node, or null if not atomic/unknown. */
function wpultra_el_atomic_type_object(array $node) {
    if (!function_exists('wpultra_el_active') || !wpultra_el_active()) { return null; }
    $elType = (string) ($node['elType'] ?? '');
    try {
        if ($elType === 'widget') {
            $wt = (string) ($node['widgetType'] ?? '');
            if ($wt === '') { return null; }
            $obj = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($wt);
        } else {
            if ($elType === '') { return null; }
            $obj = \Elementor\Plugin::$instance->elements_manager->get_element_types($elType);
        }
    } catch (\Throwable $e) {
        return null;
    }
    if (!$obj) { return null; }
    if ($obj instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base) { return $obj; }
    $elementBase = '\\Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Atomic_Element_Base';
    if (class_exists($elementBase) && $obj instanceof $elementBase) { return $obj; }
    return null;
}

/** Default per-node validator: scalar-wrap + Props_Parser + unknown-key check. Non-atomic nodes pass through. */
function wpultra_el_validate_node(array $node): array {
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    $obj = wpultra_el_atomic_type_object($node);
    if ($obj === null) {
        return ['valid' => true, 'errors' => [], 'settings' => $settings];
    }
    try {
        $schema = call_user_func([get_class($obj), 'get_props_schema']);
        $compact = [];
        foreach ($schema as $k => $prop) {
            if (is_object($prop)) { $compact[$k] = wpultra_el_compact_prop($prop); }
        }
        $wrapped = wpultra_el_wrap_settings($settings, $compact);
        // Reject keys that are not declared in the schema — Props_Parser silently ignores them.
        // Underscore-prefixed keys (e.g. _element_id, __globals__, __dynamic__) are Elementor
        // system/meta keys that live in settings but are never declared in get_props_schema().
        // Excluding them here prevents false-rejecting legitimate round-tripped writes where the
        // caller reads a stored element (which already carries system keys) and writes it back.
        $allUnknown = array_diff(array_keys($settings), array_keys($compact));
        $unknown    = array_values(array_filter($allUnknown, fn($k) => $k[0] !== '_'));
        if ($unknown !== []) {
            $errs = array_map(fn($k) => "$k: unknown_prop", $unknown);
            return ['valid' => false, 'errors' => $errs, 'settings' => $wrapped];
        }
        $result = \Elementor\Modules\AtomicWidgets\Parsers\Props_Parser::make($schema)->parse($wrapped);
        if (!$result->is_valid()) {
            $errs = array_values(array_filter(array_map('trim', explode("\n", (string) $result->errors()->to_string()))));
            return ['valid' => false, 'errors' => $errs ?: ['settings failed Elementor validation'], 'settings' => $wrapped];
        }
        return ['valid' => true, 'errors' => [], 'settings' => $result->unwrap()];
    } catch (\Throwable $e) {
        return ['valid' => false, 'errors' => ['validation error: ' . $e->getMessage()], 'settings' => $settings];
    }
}

/** Render the post's Elementor content server-side and report what actually rendered. */
function wpultra_el_render_check(int $post_id) {
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $expected = wpultra_el_collect_ids(function_exists('wpultra_el_raw') ? wpultra_el_raw($post_id) : []);
    $html = '';
    try {
        if (class_exists('\\Elementor\\Plugin')) {
            $frontend = \Elementor\Plugin::$instance->frontend;
            if (method_exists($frontend, 'get_builder_content_for_display')) {
                $html = (string) $frontend->get_builder_content_for_display($post_id);
            } elseif (method_exists($frontend, 'get_builder_content')) {
                $html = (string) $frontend->get_builder_content($post_id, true);
            }
        }
    } catch (\Throwable $e) {
        return wpultra_err('render_failed', 'Elementor render failed: ' . $e->getMessage());
    }
    $digest = wpultra_el_render_digest($html, $expected);
    $css = function_exists('get_post_meta') ? get_post_meta($post_id, '_elementor_css', true) : '';
    $preview = function_exists('get_permalink') ? (string) get_permalink($post_id) : '';
    return wpultra_ok([
        'post_id'        => $post_id,
        'preview_url'    => $preview,
        'expected_count' => count($expected),
        'rendered_count' => $digest['rendered_count'],
        'dropped_ids'    => $digest['dropped_ids'],
        'css_generated'  => !empty($css),
    ]);
}
