<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Forms adapter domain — detection + driver resolution.
 *
 * Mirrors includes/fields/ : every adapter degrades gracefully when its plugin is
 * absent (function/class probes only, never fatal). Per-adapter pure mapper/flattener
 * functions live in includes/forms/adapters/{cf7,wpforms,gravity,fluent}.php.
 */

/**
 * Detect each supported form plugin and its version.
 * Value is the version string when installed, or null when absent.
 * @return array<string,?string>  keys: cf7, wpforms, gravity, fluent
 */
function wpultra_forms_detect(): array {
    $out = [
        'cf7'     => null,
        'wpforms' => null,
        'gravity' => null,
        'fluent'  => null,
    ];
    // Contact Form 7
    if (defined('WPCF7_VERSION')) {
        $out['cf7'] = (string) WPCF7_VERSION;
    } elseif (defined('WPCF7_PLUGIN') || class_exists('WPCF7')) {
        $out['cf7'] = '';
    }
    // WPForms (Lite or Pro)
    if (defined('WPFORMS_VERSION')) {
        $out['wpforms'] = (string) WPFORMS_VERSION;
    } elseif (function_exists('wpforms') || class_exists('WPForms\\WPForms')) {
        $out['wpforms'] = '';
    }
    // Gravity Forms
    if (class_exists('GFForms') && defined('GF_MIN_WP_VERSION')) {
        $out['gravity'] = defined('GFForms::VERSION') ? (string) GFForms::$version : '';
    } elseif (class_exists('GFForms')) {
        $out['gravity'] = property_exists('GFForms', 'version') ? (string) GFForms::$version : '';
    }
    // Fluent Forms
    if (defined('FLUENTFORM_VERSION')) {
        $out['fluent'] = (string) FLUENTFORM_VERSION;
    } elseif (defined('FLUENTFORM') || function_exists('wpFluentForm')) {
        $out['fluent'] = '';
    }
    return $out;
}

/** True when Flamingo is present (CF7's entry store). Pure probe. */
function wpultra_forms_flamingo_active(): bool {
    return defined('FLAMINGO_VERSION') || class_exists('Flamingo_Inbound_Message');
}

/**
 * Canonical resolution order when no explicit plugin is chosen.
 * @return array<int,string>
 */
function wpultra_forms_order(): array {
    return ['cf7', 'wpforms', 'gravity', 'fluent'];
}

/** All plugin keys this domain knows about. Pure. */
function wpultra_forms_known_plugins(): array {
    return ['cf7', 'wpforms', 'gravity', 'fluent'];
}

/**
 * Resolve which driver to use. Pure over the detection map so it is unit-testable:
 * pass an explicit key (validated against the known set) or fall back to the first
 * detected plugin in canonical order.
 *
 * @param string             $explicit  '' for auto, or one of the known plugin keys
 * @param array<string,?string>|null $detected  detection map; defaults to live wpultra_forms_detect()
 * @return string|WP_Error   the chosen plugin key, or WP_Error when none usable
 */
function wpultra_forms_driver(string $explicit = '', ?array $detected = null) {
    if ($detected === null) { $detected = wpultra_forms_detect(); }
    if ($explicit !== '') {
        if (!in_array($explicit, wpultra_forms_known_plugins(), true)) {
            return wpultra_forms_err('forms_unknown_plugin', "Unknown form plugin '{$explicit}'. Known: cf7, wpforms, gravity, fluent.");
        }
        if (($detected[$explicit] ?? null) === null) {
            return wpultra_forms_err('forms_unavailable', "Form plugin '{$explicit}' is not active on this site.");
        }
        return $explicit;
    }
    foreach (wpultra_forms_order() as $key) {
        if (($detected[$key] ?? null) !== null) { return $key; }
    }
    return wpultra_forms_err('forms_unavailable', 'No supported form plugin (Contact Form 7, WPForms, Gravity Forms, Fluent Forms) is active.');
}

/**
 * WP_Error factory that works both under WordPress (wpultra_err) and under the bare
 * test harness (which loads WP_Error but not helpers.php). Keeps this file requirable
 * standalone without fataling.
 */
function wpultra_forms_err(string $code, string $message): WP_Error {
    if (function_exists('wpultra_err')) { return wpultra_err($code, $message); }
    return new WP_Error($code, $message);
}

/** Orientation summary for the form-status ability. */
function wpultra_forms_status(): array {
    $detected = wpultra_forms_detect();
    $plugins  = [];
    foreach (wpultra_forms_known_plugins() as $key) {
        $version   = $detected[$key];
        $installed = $version !== null;
        $entry = [
            'plugin'             => $key,
            'label'              => wpultra_forms_plugin_label($key),
            'installed'          => $installed,
            'version'            => $installed ? $version : null,
            'entries_supported'  => wpultra_forms_entries_supported($key, $detected),
            'form_count'         => $installed ? wpultra_forms_count($key) : 0,
        ];
        if ($key === 'cf7') { $entry['flamingo'] = wpultra_forms_flamingo_active(); }
        $plugins[] = $entry;
    }
    return [
        'plugins'      => $plugins,
        'active_count' => count(array_filter($detected, static fn($v) => $v !== null)),
    ];
}

/** Human label for a plugin key. Pure. */
function wpultra_forms_plugin_label(string $key): string {
    return match ($key) {
        'cf7'     => 'Contact Form 7',
        'wpforms' => 'WPForms',
        'gravity' => 'Gravity Forms',
        'fluent'  => 'Fluent Forms',
        default   => $key,
    };
}

/**
 * Whether a plugin exposes stored entries this domain can read. Pure over the
 * detection map. CF7 stores none unless Flamingo is present; WPForms needs Pro
 * (wpforms_entries table) — but presence of the table is a runtime check done in
 * the adapter, so here we optimistically report true and let the adapter degrade.
 */
function wpultra_forms_entries_supported(string $key, array $detected): bool {
    switch ($key) {
        case 'cf7':
            return wpultra_forms_flamingo_active();
        case 'wpforms':
            return ($detected['wpforms'] ?? null) !== null;
        case 'gravity':
            return ($detected['gravity'] ?? null) !== null;
        case 'fluent':
            return ($detected['fluent'] ?? null) !== null;
    }
    return false;
}

/** Live form count for an installed plugin (thin WP-calling dispatcher). */
function wpultra_forms_count(string $key): int {
    $fn = "wpultra_forms_{$key}_count";
    return function_exists($fn) ? (int) $fn() : 0;
}

/**
 * Case-insensitive substring match of a search term against a flattened entry's field
 * values (shape {id,date,fields{}}). Shared by every adapter's get-entries filter. Pure.
 */
function wpultra_forms_entry_matches(array $entry, string $search): bool {
    if ($search === '') { return true; }
    $lower  = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
    $needle = $lower($search);
    foreach ((array) ($entry['fields'] ?? []) as $value) {
        $hay = is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value;
        if (str_contains($lower($hay), $needle)) { return true; }
    }
    return false;
}
