<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/** Pure: build a WooCommerce shortcode from a display spec. */
function wpultra_woo_build_shortcode(string $display, array $p): string {
    $limit = (int) ($p['limit'] ?? 4);
    $cols  = (int) ($p['columns'] ?? 4);
    switch ($display) {
        case 'single':
            return '[product_page id="' . (int) ($p['id'] ?? 0) . '"]';
        case 'add_to_cart':
            return '[add_to_cart id="' . (int) ($p['id'] ?? 0) . '"]';
        case 'categories':
            return '[product_categories number="' . $limit . '" columns="' . $cols . '" parent="0"]';
        case 'sale':
            return '[products limit="' . $limit . '" columns="' . $cols . '" on_sale="true"]';
        case 'featured':
            return '[products limit="' . $limit . '" columns="' . $cols . '" visibility="featured"]';
        case 'best_selling':
            return '[products limit="' . $limit . '" columns="' . $cols . '" best_selling="true"]';
        case 'grid':
        default:
            $cat = '';
            if (!empty($p['category'])) { $cat = ' category="' . preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $p['category'])) . '"'; }
            return '[products limit="' . $limit . '" columns="' . $cols . '"' . $cat . ']';
    }
}

function wpultra_woo_insert_product_block(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if (!$post_id || !get_post($post_id)) { return wpultra_err('post_not_found', "No post with id $post_id."); }
    $builder = (string) ($input['builder'] ?? 'gutenberg');
    $display = (string) ($input['display'] ?? 'grid');
    $shortcode = wpultra_woo_build_shortcode($display, (array) ($input['params'] ?? []));

    if ($builder === 'elementor') {
        if (!function_exists('wpultra_el_raw')) { return wpultra_err('elementor_unavailable', 'Elementor engine not loaded.'); }
        $elements = wpultra_el_raw($post_id);
        $sid = wpultra_el_new_id($elements);
        $cid = wpultra_el_new_id($elements);
        $wid = wpultra_el_new_id($elements);
        $node = [
            'id' => $sid, 'elType' => 'section', 'settings' => (object) [], 'elements' => [
                ['id' => $cid, 'elType' => 'column', 'settings' => ['_column_size' => 100, '_inline_size' => null], 'elements' => [
                    ['id' => $wid, 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => ['shortcode' => $shortcode], 'elements' => []],
                ]],
            ],
        ];
        $elements[] = $node;
        $res = wpultra_el_write($post_id, $elements);
        if (is_wp_error($res)) { return $res; }
        return ['post_id' => $post_id, 'builder' => 'elementor', 'shortcode' => $shortcode];
    }

    // gutenberg (default)
    if (!function_exists('wpultra_gb_load')) { return wpultra_err('gutenberg_unavailable', 'Gutenberg engine not loaded.'); }
    $loaded = wpultra_gb_load($post_id);
    if (is_wp_error($loaded)) { return $loaded; }
    $block = ['blockName' => 'core/shortcode', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $shortcode, 'innerContent' => [$shortcode]];
    $path = wpultra_gb_str_to_path((string) ($input['parent_path'] ?? ''));
    $pos = isset($input['position']) ? (int) $input['position'] : PHP_INT_MAX;
    $updated = wpultra_gb_insert($loaded['blocks'], $path, $pos, $block);
    if (is_wp_error($updated)) { return $updated; }
    $tree = wpultra_gb_save($post_id, $updated);
    if (is_wp_error($tree)) { return $tree; }
    return ['post_id' => $post_id, 'builder' => 'gutenberg', 'shortcode' => $shortcode];
}
