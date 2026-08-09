<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Classic widgets + sidebars engine. Non-block themes expose widget areas that
 * had zero coverage before. Data model:
 *   - option `sidebars_widgets`: map of sidebar id => [widget id, ...], plus the
 *     special `wp_inactive_widgets` bucket.
 *   - option `widget_<id_base>`: instance settings keyed by numeric index
 *     (a widget id is `<id_base>-<index>`, e.g. `text-2`).
 */

/** PURE: split a widget id like `nav_menu-3` into [id_base, index]. */
function wpultra_widget_split_id(string $widget_id): array {
    if (preg_match('/^(.+)-(\d+)$/', $widget_id, $m)) { return [$m[1], (int) $m[2]]; }
    return [$widget_id, 0];
}

/** PURE: given a sidebars_widgets map, move $widget_id to $target at $pos. Returns the new map. */
function wpultra_widget_move_in_map(array $map, string $widget_id, string $target, int $pos): array {
    foreach ($map as $sid => $ids) {
        if (!is_array($ids)) { continue; }
        $map[$sid] = array_values(array_filter($ids, static fn($w) => $w !== $widget_id));
    }
    if (!isset($map[$target]) || !is_array($map[$target])) { $map[$target] = []; }
    $ids = $map[$target];
    $pos = $pos < 0 ? count($ids) : min($pos, count($ids));
    array_splice($ids, $pos, 0, [$widget_id]);
    $map[$target] = $ids;
    return $map;
}

/** List registered sidebars with the widgets currently in each. @return array */
function wpultra_widgets_list(): array {
    global $wp_registered_sidebars;
    $assignments = function_exists('wp_get_sidebars_widgets') ? wp_get_sidebars_widgets() : (array) get_option('sidebars_widgets', []);
    $sidebars = [];
    $registered = is_array($wp_registered_sidebars) ? $wp_registered_sidebars : [];
    // Include registered sidebars + any assignment bucket (e.g. wp_inactive_widgets).
    $ids = array_unique(array_merge(array_keys($registered), array_keys($assignments)));
    foreach ($ids as $sid) {
        if ($sid === 'array_version') { continue; }
        $widgets = [];
        foreach ((array) ($assignments[$sid] ?? []) as $wid) {
            [$base, $idx] = wpultra_widget_split_id((string) $wid);
            $instances = (array) get_option('widget_' . $base, []);
            $widgets[] = [
                'id'       => (string) $wid,
                'id_base'  => $base,
                'settings' => is_array($instances[$idx] ?? null) ? $instances[$idx] : null,
            ];
        }
        $reg = $registered[$sid] ?? null;
        $sidebars[] = [
            'id'          => (string) $sid,
            'name'        => is_array($reg) ? (string) ($reg['name'] ?? $sid) : $sid,
            'description' => is_array($reg) ? (string) ($reg['description'] ?? '') : '',
            'registered'  => is_array($reg),
            'widgets'     => $widgets,
        ];
    }
    return ['sidebars' => $sidebars, 'count' => count($sidebars)];
}

/** List available widget types (registered widgets). @return array */
function wpultra_widgets_types(): array {
    global $wp_registered_widgets, $wp_widget_factory;
    $types = [];
    // Prefer the factory (unique id_bases); fall back to registered instances.
    if (isset($wp_widget_factory) && is_object($wp_widget_factory) && !empty($wp_widget_factory->widgets)) {
        foreach ($wp_widget_factory->widgets as $obj) {
            $types[] = ['id_base' => (string) ($obj->id_base ?? ''), 'name' => (string) ($obj->name ?? '')];
        }
    } elseif (is_array($wp_registered_widgets)) {
        foreach ($wp_registered_widgets as $w) {
            $base = isset($w['callback'][0]->id_base) ? (string) $w['callback'][0]->id_base : '';
            if ($base !== '') { $types[$base] = ['id_base' => $base, 'name' => (string) ($w['name'] ?? $base)]; }
        }
        $types = array_values($types);
    }
    return ['widget_types' => $types, 'count' => count($types)];
}

/** Persist a new sidebars_widgets map. */
function wpultra_widgets_save_map(array $map): void {
    if (function_exists('wp_set_sidebars_widgets')) { wp_set_sidebars_widgets($map); }
    else { update_option('sidebars_widgets', $map); }
}

/** Move a widget to a target sidebar/position. @return array|WP_Error */
function wpultra_widgets_move(string $widget_id, string $target, int $pos) {
    if ($widget_id === '' || $target === '') { return wpultra_err('missing_args', 'widget_id and target sidebar are required.'); }
    $map = function_exists('wp_get_sidebars_widgets') ? wp_get_sidebars_widgets() : (array) get_option('sidebars_widgets', []);
    $found = false;
    foreach ($map as $ids) { if (is_array($ids) && in_array($widget_id, $ids, true)) { $found = true; break; } }
    if (!$found) { return wpultra_err('widget_not_found', "Widget '$widget_id' is not placed in any sidebar."); }
    wpultra_widgets_save_map(wpultra_widget_move_in_map($map, $widget_id, $target, $pos));
    return ['widget_id' => $widget_id, 'moved_to' => $target, 'position' => $pos];
}

/** Remove a widget: soft (to wp_inactive_widgets) or hard (delete the instance). @return array|WP_Error */
function wpultra_widgets_remove(string $widget_id, bool $delete_instance) {
    if ($widget_id === '') { return wpultra_err('missing_args', 'widget_id is required.'); }
    $map = function_exists('wp_get_sidebars_widgets') ? wp_get_sidebars_widgets() : (array) get_option('sidebars_widgets', []);
    if ($delete_instance) {
        foreach ($map as $sid => $ids) {
            if (is_array($ids)) { $map[$sid] = array_values(array_filter($ids, static fn($w) => $w !== $widget_id)); }
        }
        wpultra_widgets_save_map($map);
        [$base, $idx] = wpultra_widget_split_id($widget_id);
        $instances = (array) get_option('widget_' . $base, []);
        unset($instances[$idx]);
        update_option('widget_' . $base, $instances);
        return ['widget_id' => $widget_id, 'deleted' => true];
    }
    // Soft: park in the inactive bucket so its settings survive.
    wpultra_widgets_save_map(wpultra_widget_move_in_map($map, $widget_id, 'wp_inactive_widgets', -1));
    return ['widget_id' => $widget_id, 'deactivated' => true];
}

/** Ability dispatcher. @return array|WP_Error */
function wpultra_manage_widgets(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    switch ($action) {
        case 'list':
            return wpultra_ok(wpultra_widgets_list());
        case 'list-types':
            return wpultra_ok(wpultra_widgets_types());
        case 'move':
            $res = wpultra_widgets_move((string) ($input['widget_id'] ?? ''), (string) ($input['target'] ?? ''), (int) ($input['position'] ?? -1));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('manage-widgets', "move {$input['widget_id']} → {$input['target']}", true);
            return wpultra_ok($res);
        case 'remove':
            $hard = ($input['delete_instance'] ?? false) === true;
            if ($hard && ($e = wpultra_require_confirm($input, 'delete_instance permanently discards the widget and its settings.'))) { return $e; }
            $res = wpultra_widgets_remove((string) ($input['widget_id'] ?? ''), $hard);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('manage-widgets', ($hard ? 'delete ' : 'deactivate ') . ($input['widget_id'] ?? ''), true);
            return wpultra_ok($res);
        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }
}
