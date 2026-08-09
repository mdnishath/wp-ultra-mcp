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

/**
 * BF2.6 (undo-coverage extension): the original four types plus file edits and
 * plugin/theme toggles. Kept SEPARATE from wpultra_undo_supported_types() so
 * that function's existing return value — and any caller/test relying on it
 * being exactly the original four-type list — is untouched; wpultra_undo_capture()
 * gates on this superset instead.
 */
function wpultra_undo_extended_types(): array {
    return array_merge(wpultra_undo_supported_types(), ['file', 'active_plugins', 'active_theme', 'post']);
}

/**
 * F1.1: builder content lives in postmeta, which WP revisions do NOT cover — so
 * an Elementor/Bricks edit (or an update-post) is otherwise irreversible through
 * undo. These are the meta keys captured/restored alongside the core post fields.
 */
function wpultra_undo_post_meta_keys(): array {
    return [
        // Elementor
        '_elementor_data', '_elementor_page_settings', '_elementor_edit_mode',
        '_elementor_version', '_elementor_template_type',
        // Bricks
        '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2', '_bricks_page_settings',
    ];
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

/**
 * Pure (BF2.6): decide what a 'file' restore should do given the captured
 * before-value. ABSENT (the file did not exist before the mutation) means the
 * restore should delete it; any string means rewrite the file to that content.
 * @param mixed $before
 */
function wpultra_undo_file_restore_plan($before): array {
    if ($before === WPULTRA_UNDO_ABSENT) { return ['op' => 'delete']; }
    return ['op' => 'rewrite', 'contents' => (string) $before];
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
        if (!in_array($type, wpultra_undo_extended_types(), true)) { return 0; }
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

/**
 * F1.1: snapshot a post's before-state (core fields + builder postmeta) prior to
 * a content mutation. Convenience wrapper over wpultra_undo_capture(). Returns
 * the entry id (0 on skip). Never throws — capture must not break the write.
 */
function wpultra_undo_capture_post(int $post_id, string $label = ''): int {
    try {
        if ($post_id <= 0 || !function_exists('get_post')) { return 0; }
        $post = get_post($post_id);
        if (!$post) { return 0; }
        $meta = [];
        foreach (wpultra_undo_post_meta_keys() as $k) {
            $v = get_post_meta($post_id, $k, true);
            if ($v !== '' && $v !== false) { $meta[$k] = $v; }
        }
        $before = [
            'fields' => [
                'post_title'   => (string) $post->post_title,
                'post_content' => (string) $post->post_content,
                'post_excerpt' => (string) $post->post_excerpt,
                'post_status'  => (string) $post->post_status,
            ],
            'meta' => $meta,
        ];
        return wpultra_undo_capture('post', (string) $post_id, $before, $label !== '' ? $label : "post:$post_id");
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'undo-capture-post'); }
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
        case 'file':           $res = wpultra_undo_restore_file($entry);           break;
        case 'active_plugins': $res = wpultra_undo_restore_active_plugins($entry); break;
        case 'active_theme':   $res = wpultra_undo_restore_active_theme($entry);   break;
        case 'post':           $res = wpultra_undo_restore_post($entry);           break;
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

/* ------------------------------------------------------------------ *
 * BF2.6 (undo-coverage extension): file edits + plugin/theme toggles.
 * ------------------------------------------------------------------ */

/**
 * Restore a captured file snapshot: rewrite it to the prior contents, or
 * delete it if it did not exist before the mutation (wpultra_undo_file_restore_plan).
 * Re-validates the target is still inside the plugin's filesystem jail before
 * touching anything — this reuses the same containment check the fs abilities
 * apply in wpultra_resolve_path() (wpultra_filesystem_base_dir() +
 * wpultra_path_is_within_directory()), so a snapshot can never be used to
 * write/delete outside the allowed base directory even if the stored option
 * were tampered with.
 * @return array|WP_Error
 */
function wpultra_undo_restore_file(array $entry) {
    $path = (string) ($entry['target'] ?? '');
    if ($path === '') { return wpultra_err('bad_snapshot', 'file snapshot has no target path.'); }
    if (function_exists('wpultra_filesystem_base_dir') && function_exists('wpultra_path_is_within_directory')) {
        $base = wpultra_filesystem_base_dir();
        if (!wpultra_path_is_within_directory($path, $base)) {
            return wpultra_err('path_outside_base', "Refusing to restore a path outside the allowed base directory: $path");
        }
    }
    $before = $entry['before'] ?? WPULTRA_UNDO_ABSENT;
    $plan = wpultra_undo_file_restore_plan($before);
    if ($plan['op'] === 'delete') {
        if (is_file($path) && !@unlink($path)) { return wpultra_err('delete_failed', "Could not delete: $path"); }
        return ['path' => $path, 'action' => 'deleted'];
    }
    $dir = dirname($path);
    if (!is_dir($dir) && function_exists('wp_mkdir_p')) { wp_mkdir_p($dir); }
    if (@file_put_contents($path, $plan['contents']) === false) { return wpultra_err('write_failed', "Could not write: $path"); }
    return ['path' => $path, 'action' => 'reverted'];
}

/**
 * Restore the `active_plugins` option DIRECTLY (no activate_plugin()/
 * deactivate_plugins() calls, so no (de)activation hooks re-fire on undo) —
 * matches conflict-bisect's own silent plugin-toggle style
 * (wpultra_bisect_silent_set_active_plugins() in includes/system/bisect.php).
 * @return array|WP_Error
 */
function wpultra_undo_restore_active_plugins(array $entry) {
    $before = $entry['before'] ?? null;
    if (!is_array($before)) { return wpultra_err('bad_snapshot', 'active_plugins snapshot is not an array.'); }
    $plugins = array_values(array_map('strval', $before));
    if (function_exists('update_option')) { update_option('active_plugins', $plugins); }
    return ['option' => 'active_plugins', 'action' => 'reverted', 'count' => count($plugins)];
}

/**
 * F1.1: restore a post's captured fields + builder postmeta. Reverts the core
 * fields via wp_update_post and re-writes each captured meta key (re-slashed to
 * survive the write path, matching how the builders store their data). Clears
 * Elementor's per-post CSS cache so the reverted data re-renders.
 * @return array|WP_Error
 */
function wpultra_undo_restore_post(array $entry) {
    $post_id = (int) ($entry['target'] ?? 0);
    if ($post_id <= 0 || !function_exists('wp_update_post')) { return wpultra_err('bad_snapshot', 'post snapshot has no valid target.'); }
    if (!get_post($post_id)) { return wpultra_err('post_gone', "Post $post_id no longer exists — cannot revert it."); }

    $before = (array) ($entry['before'] ?? []);
    $fields = (array) ($before['fields'] ?? []);
    $update = ['ID' => $post_id];
    foreach (['post_title', 'post_content', 'post_excerpt', 'post_status'] as $f) {
        if (array_key_exists($f, $fields)) {
            // Content/title/excerpt go through wp_slash so backslashes survive.
            $update[$f] = in_array($f, ['post_status'], true) ? (string) $fields[$f] : wp_slash((string) $fields[$f]);
        }
    }
    $res = wp_update_post($update, true);
    if (is_wp_error($res)) { return $res; }

    $restored_meta = [];
    foreach ((array) ($before['meta'] ?? []) as $k => $v) {
        // Re-slash so JSON/serialized builder payloads round-trip through the meta API.
        update_post_meta($post_id, (string) $k, wp_slash($v));
        $restored_meta[] = (string) $k;
    }
    // Nudge Elementor to rebuild the post's CSS from the reverted data.
    if (in_array('_elementor_data', $restored_meta, true)) {
        if (function_exists('delete_post_meta')) { delete_post_meta($post_id, '_elementor_css'); }
    }
    return ['post_id' => $post_id, 'action' => 'reverted', 'meta_keys' => $restored_meta];
}

/**
 * Restore the active theme by writing the `template`/`stylesheet` options
 * DIRECTLY — same silent, no-hooks style as wpultra_undo_restore_active_plugins()
 * and conflict-bisect's wpultra_bisect_silent_set_theme(), avoiding a
 * switch_theme() side effect (widget/menu remapping, `switch_theme` action)
 * firing purely to undo a prior activate-theme.
 * @return array|WP_Error
 */
function wpultra_undo_restore_active_theme(array $entry) {
    $before = (array) ($entry['before'] ?? []);
    $stylesheet = (string) ($before['stylesheet'] ?? '');
    $template   = (string) ($before['template'] ?? $stylesheet);
    if ($stylesheet === '') { return wpultra_err('bad_snapshot', 'active_theme snapshot has no stylesheet.'); }
    if (function_exists('update_option')) {
        update_option('template', $template);
        update_option('stylesheet', $stylesheet);
    }
    return ['stylesheet' => $stylesheet, 'template' => $template, 'action' => 'reverted'];
}
