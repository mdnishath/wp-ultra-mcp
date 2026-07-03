<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Elementor Pro surface: theme-builder library templates (+ display
 * conditions), popups (+ triggers/timing), and Pro form submissions (the
 * e_submissions tables). Storage layout verified against a live Pro 4.1.2
 * install: templates = `elementor_library` CPT + `_elementor_template_type`
 * meta; conditions = `_elementor_conditions` meta (['include/general', ...]);
 * popup display = `_elementor_popup_display_settings`
 * ({triggers:{...}, timing:{...}}); submissions = {prefix}e_submissions +
 * {prefix}e_submissions_values (key/value rows per submission).
 */

/* ------------------------------------------------------------------ *
 * PURE helpers.
 * ------------------------------------------------------------------ */

function wpultra_epro_active(): bool {
    return defined('ELEMENTOR_PRO_VERSION');
}

/** Template types the library manager accepts for create. */
function wpultra_epro_template_types(): array {
    return ['header', 'footer', 'single', 'single-page', 'single-post', 'archive', 'popup', 'section', 'container', 'page', 'loop-item', 'error-404'];
}

/**
 * Pure: validate a display-condition string: (include|exclude)/(general|
 * singular|archive)[/<sub>[/<id>]]. Mirrors the strings Pro stores verbatim.
 * @return true|string
 */
function wpultra_epro_validate_condition(string $c) {
    if (!preg_match('#^(include|exclude)/(general|singular|archive)(/[a-z0-9_\-]+)?(/\d+)?$#i', $c)) {
        return "Condition '$c' must look like include/general, include/singular/page/12, exclude/archive/category, ...";
    }
    return true;
}

/**
 * Pure: build the `_elementor_popup_display_settings` array from friendly
 * trigger options. Supported: on_click, page_load (delay seconds),
 * scroll (percent), exit_intent, inactivity (seconds).
 */
function wpultra_epro_build_popup_display(array $in): array {
    $triggers = [];
    if (($in['on_click'] ?? false) === true) { $triggers['click'] = 'yes'; }
    if (isset($in['page_load'])) {
        $triggers['page_load'] = 'yes';
        $triggers['page_load_delay'] = max(0, (int) $in['page_load']);
    }
    if (isset($in['scroll'])) {
        $triggers['scrolling'] = 'yes';
        $triggers['scrolling_direction'] = 'down';
        $triggers['scrolling_offset'] = max(1, min(100, (int) $in['scroll']));
    }
    if (($in['exit_intent'] ?? false) === true) { $triggers['exit_intent'] = 'yes'; }
    if (isset($in['inactivity'])) {
        $triggers['inactivity'] = 'yes';
        $triggers['inactivity_time'] = max(1, (int) $in['inactivity']);
    }
    $timing = [];
    if (isset($in['show_times'])) {
        $timing['times'] = 'yes';
        $timing['times_times'] = max(1, (int) $in['show_times']);
    }
    if (isset($in['show_after_sessions'])) {
        $timing['sessions'] = 'yes';
        $timing['sessions_sessions'] = max(1, (int) $in['show_after_sessions']);
    }
    return ['triggers' => $triggers, 'timing' => $timing];
}

/** Pure: flatten e_submissions_values rows into a key => value map. */
function wpultra_epro_flatten_values(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $k = (string) ($r['key'] ?? '');
        if ($k === '') { continue; }
        $out[$k] = (string) ($r['value'] ?? '');
    }
    return $out;
}

/**
 * Pure: build the submissions list SQL (prepared-placeholder form) + args.
 * @return array{sql:string,args:array}
 */
function wpultra_epro_submissions_sql(string $table, array $f): array {
    $where = 'WHERE 1=1';
    $args = [];
    if (($f['form_name'] ?? '') !== '') { $where .= ' AND form_name = %s'; $args[] = (string) $f['form_name']; }
    if (!empty($f['post_id']))          { $where .= ' AND post_id = %d';   $args[] = (int) $f['post_id']; }
    if (($f['status'] ?? '') !== '')    { $where .= ' AND status = %s';    $args[] = (string) $f['status']; }
    if (($f['unread'] ?? false) === true) { $where .= ' AND is_read = 0'; }
    $args[] = max(1, min(100, (int) ($f['per_page'] ?? 20)));
    $args[] = max(0, ((int) ($f['page'] ?? 1) - 1)) * max(1, min(100, (int) ($f['per_page'] ?? 20)));
    return ['sql' => "SELECT id, form_name, post_id, element_id, referer_title, status, is_read, created_at_gmt FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", 'args' => $args];
}

/** Pure: shape a library template row. */
function wpultra_epro_shape_template(array $p): array {
    return [
        'id'     => (int) ($p['ID'] ?? 0),
        'title'  => (string) ($p['title'] ?? ''),
        'type'   => (string) ($p['type'] ?? ''),
        'status' => (string) ($p['status'] ?? ''),
    ];
}

/* ------------------------------------------------------------------ *
 * Thin WP/DB wrappers.
 * ------------------------------------------------------------------ */

/** @return array|WP_Error */
function wpultra_epro_require() {
    if (!wpultra_epro_active()) {
        return wpultra_err('elementor_pro_unavailable', 'Elementor Pro is not active on this site.');
    }
    return ['version' => (string) ELEMENTOR_PRO_VERSION];
}

/** List library templates (optionally by type). */
function wpultra_epro_templates(string $type = '', int $limit = 100): array {
    global $wpdb;
    $sql = "SELECT p.ID, p.post_title AS title, p.post_status AS status, m.meta_value AS type
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_template_type'
            WHERE p.post_type = 'elementor_library' AND p.post_status IN ('publish', 'draft')";
    $args = [];
    if ($type !== '') { $sql .= ' AND m.meta_value = %s'; $args[] = $type; }
    $sql .= ' ORDER BY p.ID DESC LIMIT %d';
    $args[] = max(1, min(500, $limit));
    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    return array_map('wpultra_epro_shape_template', $rows);
}

/** Regenerate Pro's theme-builder conditions cache (best-effort). */
function wpultra_epro_flush_conditions(): void {
    try {
        if (class_exists('\\ElementorPro\\Modules\\ThemeBuilder\\Module')) {
            $mod = \ElementorPro\Modules\ThemeBuilder\Module::instance();
            if (method_exists($mod, 'get_conditions_manager')) {
                $mgr = $mod->get_conditions_manager();
                if (method_exists($mgr, 'get_cache') && method_exists($mgr->get_cache(), 'regenerate')) {
                    $mgr->get_cache()->regenerate();
                }
            }
        }
    } catch (\Throwable $e) {
        // cache regenerates lazily on the next front-end request anyway.
    }
}

/** Distinct forms seen in submissions. */
function wpultra_epro_forms(): array {
    global $wpdb;
    $t = $wpdb->prefix . 'e_submissions';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) { return []; }
    return (array) $wpdb->get_results(
        "SELECT form_name, post_id, element_id, COUNT(*) AS submissions, SUM(is_read = 0) AS unread, MAX(created_at_gmt) AS last_at
         FROM {$t} GROUP BY form_name, post_id, element_id ORDER BY last_at DESC LIMIT 100",
        ARRAY_A
    );
}
