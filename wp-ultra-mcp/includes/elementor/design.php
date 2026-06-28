<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_is_hex_color(string $c): bool {
    return (bool) preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c);
}

function wpultra_el_slug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    return $s !== '' ? $s : 'item';
}

function wpultra_el_active_kit() {
    if (!class_exists('\\Elementor\\Plugin')) { return null; }
    try { return \Elementor\Plugin::$instance->kits_manager->get_active_kit(); } catch (\Throwable $e) { return null; }
}

function wpultra_el_get_design_system() {
    $kit = wpultra_el_active_kit();
    if (!$kit) { return wpultra_err('elementor_missing', 'Elementor is not active / no active kit.'); }
    $colors = [
        'system' => (array) $kit->get_settings('system_colors'),
        'custom' => (array) $kit->get_settings('custom_colors'),
    ];
    $typo = [
        'system' => (array) $kit->get_settings('system_typography'),
        'custom' => (array) $kit->get_settings('custom_typography'),
    ];
    $vars = ['active' => wpultra_el_variables_active(), 'items' => []];
    if ($vars['active']) {
        $list = wpultra_el_variables_list();
        $vars['items'] = is_wp_error($list) ? [] : $list;
    }
    return wpultra_ok(['colors' => $colors, 'typography' => $typo, 'variables' => $vars]);
}

function wpultra_el_set_global_colors(array $colors, string $target = 'custom') {
    $kit = wpultra_el_active_kit();
    if (!$kit) { return wpultra_err('elementor_missing', 'Elementor is not active / no active kit.'); }
    $key = $target === 'system' ? 'system_colors' : 'custom_colors';
    $current = (array) $kit->get_settings($key);
    $byId = [];
    foreach ($current as $row) { if (isset($row['_id'])) { $byId[$row['_id']] = $row; } }
    foreach ($colors as $c) {
        $hex = (string) ($c['color'] ?? '');
        if (!wpultra_el_is_hex_color($hex)) { return wpultra_err('bad_color', "Invalid hex color: '$hex'."); }
        $title = (string) ($c['title'] ?? 'Color');
        $id = (string) ($c['id'] ?? '') ?: wpultra_el_slug($title);
        $byId[$id] = ['_id' => $id, 'title' => $title, 'color' => $hex];
    }
    $list = array_values($byId);
    try {
        $kit->update_settings([$key => $list]);
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    } catch (\Throwable $e) {
        return wpultra_err('kit_write_failed', 'Could not write kit settings: ' . $e->getMessage());
    }
    return wpultra_ok([$key => $list]);
}

function wpultra_el_variables_active(): bool {
    if (!class_exists('\\Elementor\\Plugin')) { return false; }
    try {
        return \Elementor\Plugin::$instance->experiments->is_feature_active('e_variables')
            && class_exists('\\Elementor\\Modules\\Variables\\Services\\Variables_Service');
    } catch (\Throwable $e) { return false; }
}

function wpultra_el_variables_service() {
    $kit = wpultra_el_active_kit();
    if (!$kit) { return null; }
    try {
        $repo = new \Elementor\Modules\Variables\Storage\Variables_Repository($kit);
        return new \Elementor\Modules\Variables\Services\Variables_Service($repo, new \Elementor\Modules\Variables\Services\Batch_Operations\Batch_Processor());
    } catch (\Throwable $e) { return null; }
}

function wpultra_el_variables_list() {
    if (!wpultra_el_variables_active()) { return wpultra_err('variables_inactive', 'The Elementor "e_variables" experiment is not active.'); }
    $svc = wpultra_el_variables_service();
    if (!$svc) { return wpultra_err('variables_unavailable', 'Could not load the Variables service.'); }
    try { return (array) $svc->get_variables_list(); } catch (\Throwable $e) { return wpultra_err('variables_error', $e->getMessage()); }
}

function wpultra_el_variables_create(string $type, string $label, $value) {
    if (!wpultra_el_variables_active()) { return wpultra_err('variables_inactive', 'The Elementor "e_variables" experiment is not active.'); }
    $types = ['global-color-variable', 'global-font-variable', 'global-size-variable'];
    if (!in_array($type, $types, true)) { return wpultra_err('bad_variable_type', 'type must be one of: ' . implode(', ', $types)); }
    $svc = wpultra_el_variables_service();
    if (!$svc) { return wpultra_err('variables_unavailable', 'Could not load the Variables service.'); }
    try {
        $res = $svc->create(['type' => $type, 'label' => $label, 'value' => $value]);
        return wpultra_ok(['variable' => $res['variable'] ?? $res]);
    } catch (\Throwable $e) { return wpultra_err('variables_create_failed', $e->getMessage()); }
}

function wpultra_el_list_dynamic_tags(): array {
    if (!class_exists('\\Elementor\\Plugin')) { return []; }
    try {
        $cfg = \Elementor\Plugin::$instance->dynamic_tags->get_tags_config();
    } catch (\Throwable $e) { return []; }
    $out = [];
    foreach ((array) $cfg as $slug => $t) {
        $out[] = [
            'name' => (string) ($t['name'] ?? $slug),
            'title' => (string) ($t['title'] ?? $slug),
            'group' => $t['group'] ?? '',
            'categories' => array_values((array) ($t['categories'] ?? [])),
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/**
 * Map a client design brief to a flat list of variable-create instructions (pure, no Elementor).
 * Returns ['plan'=>[{family,type,title,value}], 'errors'=>string[]]. Invalid items are skipped + described.
 */
function wpultra_el_build_token_plan(array $brief): array {
    $map = [
        'colors' => ['family' => 'color', 'type' => 'global-color-variable'],
        'fonts'  => ['family' => 'font',  'type' => 'global-font-variable'],
        'sizes'  => ['family' => 'size',  'type' => 'global-size-variable'],
    ];
    $plan = [];
    $errors = [];
    foreach ($map as $key => $meta) {
        if (!array_key_exists($key, $brief) || $brief[$key] === null) { continue; }
        if (!is_array($brief[$key])) { $errors[] = "$key must be an array."; continue; }
        foreach ($brief[$key] as $i => $item) {
            if (!is_array($item)) { $errors[] = "$key[$i] must be an object."; continue; }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') { $errors[] = "$key item #$i needs a non-empty title."; continue; }
            if ($key === 'colors') {
                $hex = (string) ($item['hex'] ?? '');
                if (!wpultra_el_is_hex_color($hex)) { $errors[] = "color '$title' has invalid hex '$hex'."; continue; }
                $value = $hex;
            } elseif ($key === 'fonts') {
                $value = trim((string) ($item['family'] ?? ''));
                if ($value === '') { $errors[] = "font '$title' needs a family."; continue; }
            } else { // sizes
                if (!isset($item['size']) || !is_numeric($item['size'])) { $errors[] = "size '$title' needs a numeric size."; continue; }
                $unit = trim((string) ($item['unit'] ?? 'px'));
                if ($unit === '') { $unit = 'px'; }
                $value = (string) (0 + $item['size']) . $unit;
            }
            $plan[] = ['family' => $meta['family'], 'type' => $meta['type'], 'title' => $title, 'value' => $value];
        }
    }
    return ['plan' => $plan, 'errors' => $errors];
}

/**
 * Persist the Elementor "e_variables" experiment as active (the Variables token system needs it).
 * Returns true if the option now reads active. Mirrors wpultra_el_atomic_enable(); same boot-time
 * caching caveat — a mid-request flip only takes effect on the next request.
 */
function wpultra_el_variables_enable(): bool {
    if (!function_exists('update_option')) { return false; }
    $state = class_exists('\\Elementor\\Core\\Experiments\\Manager')
        ? \Elementor\Core\Experiments\Manager::STATE_ACTIVE
        : 'active';
    try {
        update_option('elementor_experiment-e_variables', $state);
    } catch (\Throwable $e) {
        return false;
    }
    return get_option('elementor_experiment-e_variables') === $state;
}
