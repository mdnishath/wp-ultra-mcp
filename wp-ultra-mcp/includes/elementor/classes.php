<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_gc_id(): string {
    return 'e-gc-' . substr(bin2hex(random_bytes(8)), 0, 7);
}

function wpultra_el_fade_interaction(string $trigger, string $effect, string $type, int $duration): array {
    return [
        'version' => 1,
        'items' => [[
            '$$type' => 'interaction-item',
            'value' => [
                'interaction_id' => ['$$type' => 'string', 'value' => 'temp-' . bin2hex(random_bytes(4))],
                'trigger' => ['$$type' => 'string', 'value' => $trigger],
                'animation' => ['$$type' => 'animation-preset-props', 'value' => [
                    'effect' => ['$$type' => 'string', 'value' => $effect],
                    'type' => ['$$type' => 'string', 'value' => $type],
                    'direction' => ['$$type' => 'string', 'value' => ''],
                    'timing_config' => ['$$type' => 'timing-config', 'value' => [
                        'duration' => ['$$type' => 'size', 'value' => ['size' => $duration, 'unit' => 'ms']],
                        'delay' => ['$$type' => 'size', 'value' => ['size' => 0, 'unit' => 'ms']],
                    ]],
                ]],
                'breakpoints' => ['$$type' => 'interaction-breakpoints', 'value' => [
                    'excluded' => ['$$type' => 'excluded-breakpoints', 'value' => []],
                ]],
            ],
        ]],
    ];
}

function wpultra_el_classes_active(): bool {
    if (!class_exists('\\Elementor\\Plugin')) { return false; }
    try { return \Elementor\Plugin::$instance->experiments->is_feature_active('e_classes'); } catch (\Throwable $e) { return false; }
}

function wpultra_el_interactions_active(): bool {
    if (!class_exists('\\Elementor\\Plugin')) { return false; }
    try { return \Elementor\Plugin::$instance->experiments->is_feature_active('e_interactions'); } catch (\Throwable $e) { return false; }
}

function wpultra_el_classes_enable() {
    if (!class_exists('\\Elementor\\Core\\Experiments\\Manager')) { return wpultra_err('elementor_missing', 'Elementor is not active.'); }
    update_option('elementor_experiment-e_classes', \Elementor\Core\Experiments\Manager::STATE_ACTIVE);
    return wpultra_ok(['e_classes' => wpultra_el_classes_active(), 'note' => 'Reload may be required for the change to take full effect.']);
}

function wpultra_el_gc_repo() {
    if (!wpultra_el_classes_active() || !class_exists('\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository')) { return null; }
    $kit = (class_exists('\\Elementor\\Plugin')) ? \Elementor\Plugin::$instance->kits_manager->get_active_kit() : null;
    if (!$kit) { return null; }
    try { return \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make($kit); } catch (\Throwable $e) { return null; }
}

function wpultra_el_gc_list() {
    if (!wpultra_el_classes_active()) { return wpultra_err('classes_inactive', 'The Elementor "e_classes" experiment is not active. Call elementor-upsert-global-class with enable=true, or enable it in Elementor > Settings > Features.'); }
    $repo = wpultra_el_gc_repo();
    if (!$repo) { return wpultra_err('classes_unavailable', 'Could not load the Global Classes repository.'); }
    try {
        $all = $repo->all();
        $items = $all->get_items()->all();
        $order = $all->get_order()->all();
        $out = [];
        foreach ($order as $id) {
            if (isset($items[$id])) { $out[] = ['id' => $id, 'label' => $items[$id]['label'] ?? $id]; }
        }
        return $out;
    } catch (\Throwable $e) { return wpultra_err('classes_error', $e->getMessage()); }
}

function wpultra_el_gc_upsert(string $label, array $props, ?string $id = null) {
    if (!wpultra_el_classes_active()) { return wpultra_err('classes_inactive', 'The Elementor "e_classes" experiment is not active.'); }
    $repo = wpultra_el_gc_repo();
    if (!$repo) { return wpultra_err('classes_unavailable', 'Could not load the Global Classes repository.'); }
    try {
        $all = $repo->all();
        $items = $all->get_items()->all();
        $order = $all->get_order()->all();
        $cid = $id ?: wpultra_el_gc_id();
        $items[$cid] = [
            'id' => $cid,
            'label' => $label !== '' ? $label : $cid,
            'type' => 'class',
            'variants' => [[
                'meta' => ['state' => null, 'breakpoint' => null],
                'props' => $props,
            ]],
        ];
        if (!in_array($cid, $order, true)) { $order[] = $cid; }
        $repo->put($items, $order);
        // Regenerate front-end CSS so the new/updated class actually renders.
        if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            try { \Elementor\Plugin::$instance->files_manager->clear_cache(); } catch (\Throwable $e) {}
        }
        return wpultra_ok(['id' => $cid, 'label' => $items[$cid]['label']]);
    } catch (\Throwable $e) { return wpultra_err('classes_upsert_failed', $e->getMessage()); }
}

function wpultra_el_apply_class(int $post_id, string $element_id, string $class_id, bool $remove = false) {
    $data = wpultra_el_raw($post_id);
    $node = wpultra_el_find($data, $element_id);
    if ($node === null) { return wpultra_err('element_not_found', "No element '$element_id'."); }
    $cur = [];
    if (isset($node['settings']['classes']['value']) && is_array($node['settings']['classes']['value'])) {
        $cur = $node['settings']['classes']['value'];
    }
    if ($remove) { $cur = array_values(array_filter($cur, fn($c) => $c !== $class_id)); }
    elseif (!in_array($class_id, $cur, true)) { $cur[] = $class_id; }
    $merged = wpultra_el_merge_settings($data, $element_id, ['classes' => ['$$type' => 'classes', 'value' => $cur]], false);
    if (is_wp_error($merged)) { return $merged; }
    $w = wpultra_el_write($post_id, $merged);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $element_id, 'classes' => $cur]);
}

function wpultra_el_set_interaction(int $post_id, string $element_id, array $interactions) {
    if (!wpultra_el_interactions_active()) { return wpultra_err('interactions_inactive', 'The Elementor "e_interactions" experiment is not active.'); }
    $data = wpultra_el_raw($post_id);
    $done = wpultra_el_walk($data, $element_id, function (&$node) use ($interactions) {
        $node['interactions'] = wp_json_encode($interactions);
    });
    if (!$done) { return wpultra_err('element_not_found', "No element '$element_id'."); }
    $w = wpultra_el_write($post_id, $data);
    if (is_wp_error($w)) { return $w; }
    return wpultra_ok(['post_id' => $post_id, 'element_id' => $element_id]);
}
