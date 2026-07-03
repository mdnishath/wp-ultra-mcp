<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Page-builder adapter domain (Divi / Beaver Builder / Oxygen) — the same
 * unified-adapter pattern as fields/ and forms/. One ability surface,
 * per-builder adapters, graceful degradation when a builder is absent.
 * (Bricks predates this domain and keeps its own bricks-* abilities.)
 *
 * Storage models:
 *   divi   — shortcode tree in post_content ([et_pb_section > row > column >
 *            modules]) + `_et_pb_use_builder` = 'on'
 *   beaver — flat node map in postmeta `_fl_builder_data` (node id => {node,
 *            type, parent, position, settings}) + `_fl_builder_enabled` = 1
 *   oxygen — JSON tree in postmeta `ct_builder_json` (Oxygen 4+) or shortcode
 *            string in `ct_builder_shortcodes` (3.x)
 */

/** Pure-ish: builder => [installed, version] probes. */
function wpultra_builders_detect(): array {
    return [
        'divi' => [
            'installed' => defined('ET_BUILDER_VERSION') || defined('ET_CORE_VERSION') || function_exists('et_setup_theme'),
            'version'   => defined('ET_BUILDER_VERSION') ? (string) ET_BUILDER_VERSION : (defined('ET_CORE_VERSION') ? (string) ET_CORE_VERSION : ''),
        ],
        'beaver' => [
            'installed' => class_exists('FLBuilder') || defined('FL_BUILDER_VERSION'),
            'version'   => defined('FL_BUILDER_VERSION') ? (string) FL_BUILDER_VERSION : '',
        ],
        'oxygen' => [
            'installed' => defined('CT_VERSION') || class_exists('CT_Component'),
            'version'   => defined('CT_VERSION') ? (string) CT_VERSION : '',
        ],
    ];
}

/** Pure: resolve the driver — explicit wins (must be installed), else the single installed one. @return string|WP_Error-shaped string error via caller */
function wpultra_builders_driver(string $explicit, array $detected) {
    $known = array_keys($detected);
    if ($explicit !== '') {
        if (!in_array($explicit, $known, true)) { return "Unknown builder '$explicit'. Use: " . implode(', ', $known) . '.'; }
        if (empty($detected[$explicit]['installed'])) { return "Builder '$explicit' is not installed on this site."; }
        return $explicit;
    }
    $installed = array_keys(array_filter($detected, static fn($d) => !empty($d['installed'])));
    if ($installed === []) { return 'No supported page builder (Divi / Beaver Builder / Oxygen) is installed. For Bricks use the bricks-* abilities; for Elementor use the elementor-* abilities.'; }
    if (count($installed) > 1) { return 'Multiple builders installed (' . implode(', ', $installed) . ') — pass builder explicitly.'; }
    return $installed[0];
}
