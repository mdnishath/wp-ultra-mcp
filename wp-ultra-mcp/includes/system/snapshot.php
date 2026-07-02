<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Site-snapshot engine: one-call orientation summary for the AI (site info, active theme,
 * plugins, content/user/menu counts, and detected ecosystem plugins). Pure logic is kept in
 * thin, testable functions; anything that touches WordPress globals lives in a wrapper that
 * calls out to the pure fn.
 */

/** All sections `include[]` may name; used as the default (i.e. "all"). Pure. */
function wpultra_snapshot_all_sections(): array {
    return ['plugins', 'themes', 'content', 'users', 'menus', 'elementor', 'woocommerce', 'seo', 'fields'];
}

/**
 * Normalize the `include` input into the effective section list. Pure.
 * Unknown values are dropped; empty/omitted means "all sections".
 */
function wpultra_snapshot_resolve_sections($include): array {
    $all = wpultra_snapshot_all_sections();
    if (!is_array($include) || count($include) === 0) { return $all; }
    $out = [];
    foreach ($include as $s) {
        $s = (string) $s;
        if (in_array($s, $all, true) && !in_array($s, $out, true)) { $out[] = $s; }
    }
    return count($out) > 0 ? $out : $all;
}

/**
 * Pure probe-map evaluator. Each probe is one of:
 *   ['class' => 'Some\\Class']
 *   ['function' => 'some_function']
 *   ['constant' => 'SOME_CONSTANT']
 * A probe may optionally carry a 'label' used as the result key instead of the probe value.
 * Detection uses class_exists()/function_exists()/defined() — safe to call at any time,
 * never touches the database or triggers side effects.
 *
 * @param array<int,array{class?:string,function?:string,constant?:string,label?:string}> $probes
 * @return array<string,bool> map of label => detected
 */
function wpultra_snapshot_detect(array $probes): array {
    $out = [];
    foreach ($probes as $probe) {
        if (!is_array($probe)) { continue; }
        $detected = false;
        $key = '';
        if (isset($probe['class']) && $probe['class'] !== '') {
            $key = $probe['label'] ?? (string) $probe['class'];
            $detected = class_exists((string) $probe['class']);
        } elseif (isset($probe['function']) && $probe['function'] !== '') {
            $key = $probe['label'] ?? (string) $probe['function'];
            $detected = function_exists((string) $probe['function']);
        } elseif (isset($probe['constant']) && $probe['constant'] !== '') {
            $key = $probe['label'] ?? (string) $probe['constant'];
            $detected = defined((string) $probe['constant']);
        } else {
            continue;
        }
        if ($key === '') { continue; }
        $out[$key] = $detected;
    }
    return $out;
}

/** Probe map for page-builder / SEO / field / form ecosystem plugins. Pure. */
function wpultra_snapshot_ecosystem_probes(): array {
    return [
        ['label' => 'elementor', 'constant' => 'ELEMENTOR_VERSION'],
        ['label' => 'bricks', 'constant' => 'BRICKS_VERSION'],
        ['label' => 'yoast', 'constant' => 'WPSEO_VERSION'],
        ['label' => 'rankmath', 'constant' => 'RANK_MATH_VERSION'],
        ['label' => 'acf', 'class' => 'ACF'],
        ['label' => 'metabox', 'function' => 'rwmb_meta'],
        ['label' => 'pods', 'constant' => 'PODS_VERSION'],
        ['label' => 'woocommerce', 'constant' => 'WC_VERSION'],
        ['label' => 'cf7', 'class' => 'WPCF7'],
        ['label' => 'wpforms', 'constant' => 'WPFORMS_VERSION'],
        ['label' => 'gravityforms', 'class' => 'GFForms'],
        ['label' => 'fluentforms', 'constant' => 'FLUENTFORM_VERSION'],
        ['label' => 'wpml', 'constant' => 'ICL_SITEPRESS_VERSION'],
        ['label' => 'polylang', 'function' => 'pll_languages_list'],
    ];
}

/** @return array Site identity block. */
function wpultra_snapshot_site(): array {
    return [
        'name'                => (string) get_bloginfo('name'),
        'url'                 => (string) home_url('/'),
        'wp_version'          => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '',
        'php_version'         => PHP_VERSION,
        'locale'              => function_exists('get_locale') ? (string) get_locale() : '',
        'timezone'            => (string) wp_timezone_string(),
        'permalink_structure' => (string) get_option('permalink_structure'),
        'is_multisite'        => (bool) is_multisite(),
    ];
}

/** @return array Active theme (+parent when it's a child theme). */
function wpultra_snapshot_theme(): array {
    $theme = wp_get_theme();
    $out = [
        'stylesheet' => (string) $theme->get_stylesheet(),
        'name'       => (string) $theme->get('Name'),
        'version'    => (string) $theme->get('Version'),
    ];
    $parent = $theme->parent();
    if ($parent) {
        $out['parent'] = ['stylesheet' => (string) $parent->get_stylesheet(), 'name' => (string) $parent->get('Name')];
    }
    return $out;
}

/** @return array Plugins summary: active list (name/version) + inactive count. */
function wpultra_snapshot_plugins(): array {
    if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
    $active_paths = (array) get_option('active_plugins', []);
    $active = [];
    $inactive_count = 0;
    foreach (get_plugins() as $file => $data) {
        if (in_array($file, $active_paths, true)) {
            $active[] = ['plugin' => $file, 'name' => $data['Name'] ?? $file, 'version' => $data['Version'] ?? ''];
        } else {
            $inactive_count++;
        }
    }
    return ['active' => $active, 'active_count' => count($active), 'inactive_count' => $inactive_count];
}

/** @return array Per public post_type counts + taxonomy term counts. */
function wpultra_snapshot_content(): array {
    $post_types = [];
    foreach (get_post_types(['public' => true], 'names') as $pt) {
        $counts = wp_count_posts($pt);
        $total = 0;
        $by_status = [];
        if (is_object($counts)) {
            foreach (get_object_vars($counts) as $status => $n) {
                $n = (int) $n;
                if ($n > 0) { $by_status[$status] = $n; }
                $total += $n;
            }
        }
        $post_types[$pt] = ['total' => $total, 'by_status' => $by_status];
    }
    $taxonomies = [];
    foreach (get_taxonomies(['public' => true], 'names') as $tax) {
        $terms = wp_count_terms(['taxonomy' => $tax, 'hide_empty' => false]);
        $taxonomies[$tax] = is_wp_error($terms) ? 0 : (int) $terms;
    }
    return ['post_types' => $post_types, 'taxonomies' => $taxonomies];
}

/** @return array Users per role + total. */
function wpultra_snapshot_users(): array {
    $counts = count_users();
    return [
        'total'     => (int) ($counts['total_users'] ?? 0),
        'per_role'  => (array) ($counts['avail_roles'] ?? []),
    ];
}

/** @return array Menus (name, item count) + assigned theme locations. */
function wpultra_snapshot_menus(): array {
    $menus = [];
    foreach (wp_get_nav_menus() as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        $menus[] = ['id' => (int) $menu->term_id, 'name' => (string) $menu->name, 'item_count' => is_array($items) ? count($items) : 0];
    }
    $locations = get_theme_mod('nav_menu_locations');
    return ['menus' => $menus, 'locations' => is_array($locations) ? $locations : []];
}

/** @return array Detected ecosystem plugins (page builders/SEO/fields/forms/i18n). */
function wpultra_snapshot_ecosystem(): array {
    return wpultra_snapshot_detect(wpultra_snapshot_ecosystem_probes());
}

/**
 * Assemble the full snapshot for the requested sections. WP-calling dispatcher; each branch
 * defers to a small section-specific function above so behavior stays testable in isolation.
 * @return array
 */
function wpultra_snapshot_build(array $sections): array {
    $out = [];
    foreach ($sections as $section) {
        switch ($section) {
            case 'themes':
                $out['theme'] = wpultra_snapshot_theme();
                break;
            case 'plugins':
                $out['plugins'] = wpultra_snapshot_plugins();
                break;
            case 'content':
                $out['content'] = wpultra_snapshot_content();
                break;
            case 'users':
                $out['users'] = wpultra_snapshot_users();
                break;
            case 'menus':
                $out['menus'] = wpultra_snapshot_menus();
                break;
            case 'elementor':
            case 'woocommerce':
            case 'seo':
            case 'fields':
                // These four all fold into one ecosystem detection map (computed once, reused).
                if (!isset($out['ecosystem'])) { $out['ecosystem'] = wpultra_snapshot_ecosystem(); }
                break;
        }
    }
    $out['site'] = wpultra_snapshot_site();
    return $out;
}
