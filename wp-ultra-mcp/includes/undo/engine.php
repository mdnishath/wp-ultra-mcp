<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Universal undo. Reversible mutations (option-set, custom-css, theme.json,
 * term update) snapshot their BEFORE-state into a capped ring buffer before
 * writing; `undo-restore` / `undo-last` reapply it. This extends the
 * post-revision `content-restore` to targets WordPress has no revisions for.
 *
 * Storage: a single non-autoloaded option `wpultra_undo_stack` holding up to
 * WPULTRA_UNDO_CAP entries, newest first. Each entry:
 *   {id, type, target, label, before, created}
 */

const WPULTRA_UNDO_OPTION = 'wpultra_undo_stack';
const WPULTRA_UNDO_CAP    = 50;
const WPULTRA_UNDO_ABSENT = '__wpultra_absent__'; // sentinel: target did not exist before

/* ------------------------------------------------------------------ *
 * PURE helpers — no WordPress.
 * ------------------------------------------------------------------ */

/** Types the restorer knows how to reapply. */
function wpultra_undo_supported_types(): array {
    return ['option', 'custom_css', 'theme_json', 'term'];
}

/** Pure: next id = (max existing id) + 1. */
function wpultra_undo_next_id(array $stack): int {
    $max = 0;
    foreach ($stack as $e) { $max = max($max, (int) ($e['id'] ?? 0)); }
    return $max + 1;
}

/** Pure: prepend an entry (newest first) and cap the stack length. */
function wpultra_undo_push(array $stack, array $entry, int $cap = WPULTRA_UNDO_CAP): array {
    array_unshift($stack, $entry);
    if (count($stack) > $cap) { $stack = array_slice($stack, 0, $cap); }
    return array_values($stack);
}

/** Pure: find an entry by id, or null. */
function wpultra_undo_find(array $stack, int $id): ?array {
    foreach ($stack as $e) { if ((int) ($e['id'] ?? 0) === $id) { return $e; } }
    return null;
}

/** Pure: remove an entry by id. */
function wpultra_undo_remove(array $stack, int $id): array {
    return array_values(array_filter($stack, static fn($e) => (int) ($e['id'] ?? 0) !== $id));
}

/** Pure: compact shape for listing — omits the (possibly large) before-payload. */
function wpultra_undo_shape(array $entry): array {
    return [
        'id'      => (int) ($entry['id'] ?? 0),
        'type'    => (string) ($entry['type'] ?? ''),
        'target'  => (string) ($entry['target'] ?? ''),
        'label'   => (string) ($entry['label'] ?? ''),
        'created' => (string) ($entry['created'] ?? ''),
    ];
}

/** Pure: build a new snapshot entry. */
function wpultra_undo_make_entry(int $id, string $type, string $target, $before, string $label, string $created): array {
    return ['id' => $id, 'type' => $type, 'target' => $target, 'before' => $before, 'label' => $label, 'created' => $created];
}

/* ------------------------------------------------------------------ *
 * Store (thin WordPress wrappers).
 * ------------------------------------------------------------------ */

function wpultra_undo_load_stack(): array {
    $v = function_exists('get_option') ? get_option(WPULTRA_UNDO_OPTION, []) : [];
    return is_array($v) ? $v : [];
}

function wpultra_undo_save_stack(array $stack): void {
    if (function_exists('update_option')) { update_option(WPULTRA_UNDO_OPTION, $stack, false); }
}

/**
 * Snapshot a target's before-state. Called from mutation engines; never allowed
 * to break the mutation, so all failures are swallowed. Returns the entry id (0
 * on skip/failure). Honours the `undo` category toggle.
 * @param mixed $before
 */
function wpultra_undo_capture(string $type, string $target, $before, string $label = ''): int {
    try {
        if (!in_array($type, wpultra_undo_supported_types(), true)) { return 0; }
        if (function_exists('wpultra_category_enabled') && !wpultra_category_enabled('undo')) { return 0; }
        $stack = wpultra_undo_load_stack();
        $id = wpultra_undo_next_id($stack);
        $created = function_exists('current_time') ? (string) current_time('mysql', true) : '';
        $entry = wpultra_undo_make_entry($id, $type, $target, $before, $label !== '' ? $label : "$type:$target", $created);
        wpultra_undo_save_stack(wpultra_undo_push($stack, $entry));
        return $id;
    } catch (\Throwable $e) {
        return 0;
    }
}

/* ------------------------------------------------------------------ *
 * Restore dispatch.
 * ------------------------------------------------------------------ */

/** Restore one snapshot by id, then drop it from the stack. @return array|WP_Error */
function wpultra_undo_restore(int $id) {
    $stack = wpultra_undo_load_stack();
    $entry = wpultra_undo_find($stack, $id);
    if ($entry === null) { return wpultra_err('not_found', "No undo snapshot with id $id."); }

    $type = (string) ($entry['type'] ?? '');
    switch ($type) {
        case 'option':     $res = wpultra_undo_restore_option($entry);     break;
        case 'custom_css': $res = wpultra_undo_restore_custom_css($entry); break;
        case 'theme_json': $res = wpultra_undo_restore_theme_json($entry); break;
        case 'term':       $res = wpultra_undo_restore_term($entry);       break;
        default:           return wpultra_err('unsupported_type', "Cannot restore snapshot type '$type'.");
    }
    if (is_wp_error($res)) { return $res; }

    wpultra_undo_save_stack(wpultra_undo_remove($stack, $id));
    wpultra_audit_log('undo-restore', "restored #$id ($type:" . ($entry['target'] ?? '') . ')', true);
    return ['restored' => true, 'id' => $id, 'type' => $type, 'target' => (string) ($entry['target'] ?? ''), 'detail' => $res];
}

/** @return array|WP_Error */
function wpultra_undo_restore_option(array $entry) {
    $name = (string) ($entry['target'] ?? '');
    if ($name === '') { return wpultra_err('bad_snapshot', 'Option snapshot has no target.'); }
    $before = $entry['before'] ?? WPULTRA_UNDO_ABSENT;
    if ($before === WPULTRA_UNDO_ABSENT) {
        if (function_exists('delete_option')) { delete_option($name); }
        return ['option' => $name, 'action' => 'deleted'];
    }
    if (function_exists('update_option')) { update_option($name, $before); }
    return ['option' => $name, 'action' => 'reverted'];
}

/** @return array|WP_Error */
function wpultra_undo_restore_custom_css(array $entry) {
    if (!function_exists('wp_update_custom_css_post')) { return wpultra_err('fse_unavailable', 'wp_update_custom_css_post() unavailable.'); }
    $res = wp_update_custom_css_post((string) ($entry['before'] ?? ''));
    if (is_wp_error($res)) { return $res; }
    return ['action' => 'reverted', 'length' => strlen((string) ($entry['before'] ?? ''))];
}

/** @return array|WP_Error */
function wpultra_undo_restore_theme_json(array $entry) {
    $post_id = (int) ($entry['target'] ?? 0);
    if ($post_id <= 0 || !function_exists('wp_update_post')) { return wpultra_err('bad_snapshot', 'theme_json snapshot has no valid post target.'); }
    $res = wp_update_post(['ID' => $post_id, 'post_content' => wp_slash((string) ($entry['before'] ?? ''))], true);
    if (is_wp_error($res)) { return $res; }
    if (function_exists('WP_Theme_JSON_Resolver') || class_exists('WP_Theme_JSON_Resolver')) {
        if (method_exists('WP_Theme_JSON_Resolver', 'clean_cached_data')) { \WP_Theme_JSON_Resolver::clean_cached_data(); }
    }
    return ['action' => 'reverted', 'global_styles_post' => $post_id];
}

/** @return array|WP_Error */
function wpultra_undo_restore_term(array $entry) {
    $before = (array) ($entry['before'] ?? []);
    $term_id  = (int) ($before['term_id'] ?? 0);
    $taxonomy = (string) ($before['taxonomy'] ?? '');
    if ($term_id <= 0 || $taxonomy === '' || !function_exists('wp_update_term')) {
        return wpultra_err('bad_snapshot', 'term snapshot is missing term_id/taxonomy.');
    }
    if (!get_term($term_id, $taxonomy)) { return wpultra_err('term_gone', "Term $term_id no longer exists — cannot revert its fields."); }
    $res = wp_update_term($term_id, $taxonomy, [
        'name'        => (string) ($before['name'] ?? ''),
        'slug'        => (string) ($before['slug'] ?? ''),
        'parent'      => (int) ($before['parent'] ?? 0),
        'description' => (string) ($before['description'] ?? ''),
    ]);
    if (is_wp_error($res)) { return $res; }
    return ['action' => 'reverted', 'term_id' => $term_id];
}
