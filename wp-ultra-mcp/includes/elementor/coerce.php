<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_already_wrapped($v): bool {
    return is_array($v) && array_key_exists('$$type', $v) && array_key_exists('value', $v);
}

function wpultra_el_wrap_value($scalar, string $type): array {
    return ['$$type' => $type, 'value' => $scalar];
}

function wpultra_el_wrap_settings(array $settings, array $compactSchema): array {
    $scalarTypes = ['string', 'number', 'boolean', 'color', 'url', 'html', 'html-v2'];
    $out = [];
    foreach ($settings as $key => $val) {
        if (!isset($compactSchema[$key]) || wpultra_el_already_wrapped($val) || is_array($val)) {
            $out[$key] = $val;
            continue;
        }
        $type = (string) ($compactSchema[$key]['type'] ?? '');
        if (in_array($type, $scalarTypes, true)) {
            $out[$key] = wpultra_el_wrap_value($val, $type);
        } elseif ($type === 'union') {
            // Many atomic props (tag, text, …) are unions whose value is stored wrapped.
            $def = $compactSchema[$key]['default'] ?? null;
            if (!is_array($def) || !isset($def['$$type'])) {
                $out[$key] = $val;
                continue;
            }
            $defType  = (string) $def['$$type'];
            $defValue = $def['value'] ?? null;
            if (!is_array($defValue)) {
                // Simple union (e.g. tag → {$$type:"string", value:"h2"}): wrap scalar directly.
                $out[$key] = wpultra_el_wrap_value($val, $defType);
            } else {
                // Complex union (e.g. html-v3 → {$$type:"html-v3", value:{content:{…}, children:[]}}).
                // Substitute the scalar into the innermost string slot of the default value shape.
                $out[$key] = wpultra_el_wrap_value(
                    wpultra_el_inject_scalar_into_shape($defValue, $val),
                    $defType
                );
            }
        } else {
            $out[$key] = $val;
        }
    }
    return $out;
}

/**
 * Walk the default-value shape and replace the first leaf {$$type:"string", value:...}
 * with {$$type:"string", value:$scalar}. All other leaves are kept as-is.
 * Returns the modified shape array.
 */
function wpultra_el_inject_scalar_into_shape(array $shape, $scalar): array {
    $result = $shape;
    wpultra_el_inject_scalar_ref($result, $scalar);
    return $result;
}

/**
 * Recurse into $shape by reference, injecting $scalar into the first string leaf found.
 * Returns true once a string slot has been filled. Unlike a naive first-child return, this
 * CONTINUES scanning later siblings when an earlier plain-array child has no string leaf, so a
 * complex union default whose string slot lives in a later branch still receives the scalar.
 */
function wpultra_el_inject_scalar_ref(array &$shape, $scalar): bool {
    foreach ($shape as $k => &$v) {
        if (is_array($v) && isset($v['$$type']) && $v['$$type'] === 'string') {
            $v = ['$$type' => 'string', 'value' => (string) $scalar];
            return true; // inject only into the first string slot
        }
        if (is_array($v) && !isset($v['$$type'])) {
            if (wpultra_el_inject_scalar_ref($v, $scalar)) { return true; }
            // no string leaf in this branch — keep scanning siblings
        }
    }
    return false;
}

function wpultra_el_validate_settings(string $widgetType, array $settings) {
    if (!wpultra_el_active()) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    $w = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widgetType);
    if (!$w || !($w instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base)) {
        // Non-atomic or unknown: accept settings as-is (no atomic schema to validate).
        return ['ok' => true, 'settings' => $settings];
    }
    try {
        $schema = call_user_func([get_class($w), 'get_props_schema']);
        $result = \Elementor\Modules\AtomicWidgets\Parsers\Props_Parser::make($schema)->parse($settings);
        if (!$result->is_valid()) {
            $compact = wpultra_el_widget_schema($widgetType);
            return wpultra_err('invalid_settings', 'Settings failed Elementor validation: ' . $result->errors()->to_string(), is_array($compact) ? $compact : null);
        }
        return ['ok' => true, 'settings' => $result->unwrap()];
    } catch (\Throwable $e) {
        return wpultra_err('validate_failed', 'Elementor validation error: ' . $e->getMessage());
    }
}
