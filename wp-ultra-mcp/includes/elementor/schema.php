<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Compact a Prop_Type object to {type, enum?, default?}. */
function wpultra_el_compact_prop($prop): array {
    $out = [];
    try { $out['type'] = (string) call_user_func([get_class($prop), 'get_key']); } catch (\Throwable $e) { $out['type'] = 'unknown'; }
    try { $enum = $prop->get_setting('enum'); if (is_array($enum) && $enum) { $out['enum'] = array_values($enum); } } catch (\Throwable $e) {}
    try { $def = $prop->get_default(); if ($def !== null) { $out['default'] = $def; } } catch (\Throwable $e) {}
    return $out;
}

function wpultra_el_widget_schema(string $widgetType) {
    if (!wpultra_el_active()) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    $w = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widgetType);
    if (!$w) { return wpultra_err('unknown_widget', "No widget type '$widgetType'."); }
    $is_atomic = $w instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base;
    if ($is_atomic) {
        $schema = call_user_func([get_class($w), 'get_props_schema']);
        $props = [];
        foreach ($schema as $key => $prop) {
            if (is_object($prop)) { $props[$key] = wpultra_el_compact_prop($prop); }
        }
        return ['widgetType' => $widgetType, 'is_atomic' => true, 'props' => $props];
    }
    $controls = [];
    foreach ((array) $w->get_controls() as $name => $c) {
        $entry = ['name' => $name, 'type' => $c['type'] ?? '', 'default' => $c['default'] ?? null];
        if (!empty($c['options'])) { $entry['options'] = $c['options']; }
        $controls[] = $entry;
    }
    return ['widgetType' => $widgetType, 'is_atomic' => false, 'controls' => $controls];
}

function wpultra_el_list_widgets(array $filter = []): array {
    if (!wpultra_el_active()) { return []; }
    $atomic_only = !empty($filter['atomic_only']);
    $out = [];
    foreach (\Elementor\Plugin::$instance->widgets_manager->get_widget_types() as $name => $w) {
        $is_atomic = $w instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base;
        if ($atomic_only && !$is_atomic) { continue; }
        $out[] = [
            'name' => (string) $name,
            'title' => method_exists($w, 'get_title') ? (string) $w->get_title() : (string) $name,
            'is_atomic' => $is_atomic,
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

function wpultra_el_style_schema(): array {
    if (!class_exists('\\Elementor\\Modules\\AtomicWidgets\\Styles\\Style_Schema')) { return []; }
    $schema = \Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get();
    $out = [];
    foreach ($schema as $cssProp => $prop) {
        if (is_object($prop)) { $out[$cssProp] = wpultra_el_compact_prop($prop); }
    }
    return $out;
}
