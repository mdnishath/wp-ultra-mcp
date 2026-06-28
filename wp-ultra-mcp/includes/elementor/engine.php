<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_raw(int $post_id): array {
    $raw = get_post_meta($post_id, '_elementor_data', true);
    if (empty($raw)) { return []; }
    $data = is_string($raw) ? json_decode($raw, true) : $raw;
    return is_array($data) ? $data : [];
}

function wpultra_el_read(int $post_id, array $opts = []) {
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    $data = wpultra_el_raw($post_id);
    if (!empty($opts['element_id'])) {
        $node = wpultra_el_find($data, (string) $opts['element_id']);
        if ($node === null) { return wpultra_err('element_not_found', "No element '{$opts['element_id']}'."); }
        return wpultra_ok(['post_id' => $post_id, 'element' => $node]);
    }
    if (!empty($opts['full'])) { return wpultra_ok(['post_id' => $post_id, 'elements' => $data]); }
    return wpultra_ok(['post_id' => $post_id, 'elements' => wpultra_el_compact_tree($data)]);
}

function wpultra_el_write(int $post_id, array $elements) {
    if ($post_id <= 0 || !get_post($post_id)) { return wpultra_err('bad_post', 'Valid post_id required.'); }
    // Atomic-safe: write meta directly (Document::save strips atomic widgets).
    update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elements)));
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '4.0.0');
    // Full CSS invalidation.
    try {
        if (class_exists('\\Elementor\\Plugin')) {
            $p = \Elementor\Plugin::$instance;
            if (isset($p->files_manager)) { $p->files_manager->clear_cache(); }
        }
        delete_post_meta($post_id, '_elementor_css');
        do_action('elementor/atomic-widgets/styles/clear');
        clean_post_cache($post_id);
    } catch (\Throwable $e) { /* cache clear is best-effort */ }
    return wpultra_ok(['post_id' => $post_id, 'top_level_count' => count($elements)]);
}
