<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Non-null top-level blocks of a parsed pattern/content string. Pure (modulo parse_blocks). */
function wpultra_gb_pattern_blocks(string $content): array {
    $out = [];
    foreach (parse_blocks($content) as $b) {
        if (($b['blockName'] ?? null) !== null) { $out[] = $b; }
    }
    return $out;
}

function wpultra_gb_list_patterns(string $search = '', string $category = ''): array {
    if (!class_exists('WP_Block_Patterns_Registry')) { return []; }
    $all = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
    $search = strtolower(trim($search));
    $out = [];
    foreach ($all as $p) {
        $name = (string) ($p['name'] ?? '');
        $title = (string) ($p['title'] ?? '');
        $cats = array_values((array) ($p['categories'] ?? []));
        if ($category !== '' && !in_array($category, $cats, true)) { continue; }
        if ($search !== '' && strpos(strtolower($name . ' ' . $title), $search) === false) { continue; }
        $out[] = ['name' => $name, 'title' => $title, 'categories' => $cats, 'description' => (string) ($p['description'] ?? '')];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

function wpultra_gb_get_pattern(string $name) {
    if (!class_exists('WP_Block_Patterns_Registry')) { return wpultra_err('patterns_unavailable', 'Block patterns registry unavailable.'); }
    $reg = \WP_Block_Patterns_Registry::get_instance();
    if (!$reg->is_registered($name)) { return wpultra_err('pattern_not_found', "No registered pattern '$name'."); }
    return $reg->get_registered($name);
}

function wpultra_gb_reusable_list(string $search = ''): array {
    $args = ['post_type' => 'wp_block', 'post_status' => 'publish', 'numberposts' => 200];
    if ($search !== '') { $args['s'] = $search; }
    $out = [];
    foreach (get_posts($args) as $p) {
        $out[] = ['id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'modified' => $p->post_modified_gmt];
    }
    return $out;
}

function wpultra_gb_reusable_get(int $id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== 'wp_block') { return wpultra_err('reusable_not_found', "No reusable block with id $id."); }
    return ['id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content];
}

function wpultra_gb_reusable_save(array $args) {
    $title = (string) ($args['title'] ?? '');
    $id = (int) ($args['id'] ?? 0);
    if ($id > 0) {
        $existing = get_post($id);
        if (!$existing || $existing->post_type !== 'wp_block') { return wpultra_err('reusable_not_found', "No reusable block with id $id to update."); }
        $data = ['ID' => $id];
        if ($title !== '') { $data['post_title'] = $title; }
        if (array_key_exists('content', $args)) { $data['post_content'] = (string) $args['content']; }
        if (count($data) === 1) { return wpultra_err('nothing_to_update', 'Provide title or content to update.'); }
        // Slash so block-attribute \u00xx escapes / backslashes survive wp_update_post's unslash.
        $res = wp_update_post(wp_slash($data), true);
    } else {
        if ($title === '') { return wpultra_err('missing_title', 'title is required to create a reusable block.'); }
        $res = wp_insert_post(wp_slash(['post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => (string) ($args['content'] ?? '')]), true);
    }
    if (is_wp_error($res)) { return $res; }
    $pid = (int) $res;
    $p = get_post($pid);
    if (!$p) { return wpultra_err('reusable_save_failed', "Could not read reusable block $pid after save."); }
    return ['id' => $pid, 'title' => $p->post_title];
}

/* ------------------------------------------------------------------ *
 * Custom (user-defined) block patterns. Stored in option
 * wpultra_block_patterns and registered on `init` so they show up in the
 * inserter and in gutenberg-list-patterns / gutenberg-insert-pattern.
 * ------------------------------------------------------------------ */

const WPULTRA_BLOCK_PATTERNS_OPTION = 'wpultra_block_patterns';

/** PURE: normalize a pattern name to `wpultra/<slug>`. */
function wpultra_gb_pattern_normalize_name(string $name): string {
    $name = strtolower(trim($name));
    if ($name === '') { return ''; }
    if (strpos($name, '/') === false) { $name = 'wpultra/' . $name; }
    // Only [a-z0-9-] in each namespace segment.
    [$ns, $slug] = array_pad(explode('/', $name, 2), 2, '');
    $ns = preg_replace('/[^a-z0-9-]/', '', $ns);
    $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
    return ($ns !== '' && $slug !== '') ? "$ns/$slug" : '';
}

/** @return array all stored custom patterns. */
function wpultra_gb_custom_patterns_all(): array {
    $v = get_option(WPULTRA_BLOCK_PATTERNS_OPTION, []);
    return is_array($v) ? $v : [];
}

/** Register every stored custom pattern. Hooked on init. */
function wpultra_register_custom_block_patterns(): void {
    if (!function_exists('register_block_pattern')) { return; }
    foreach (wpultra_gb_custom_patterns_all() as $name => $p) {
        if (!is_array($p) || empty($p['content'])) { continue; }
        // Re-registering the same name would warn; unregister first if present.
        if (function_exists('unregister_block_pattern') && \WP_Block_Patterns_Registry::get_instance()->is_registered((string) $name)) {
            unregister_block_pattern((string) $name);
        }
        register_block_pattern((string) $name, [
            'title'       => (string) ($p['title'] ?? $name),
            'content'     => (string) $p['content'],
            'categories'  => array_values(array_map('strval', (array) ($p['categories'] ?? []))),
            'description' => (string) ($p['description'] ?? ''),
        ]);
    }
}

/** Create or update a custom pattern. @return array|WP_Error */
function wpultra_gb_pattern_save(array $input) {
    $name = wpultra_gb_pattern_normalize_name((string) ($input['name'] ?? ''));
    if ($name === '') { return wpultra_err('bad_name', 'name is required (letters, digits, dashes; namespaced as wpultra/<slug>).'); }
    $content = (string) ($input['content'] ?? '');
    if (trim($content) === '') { return wpultra_err('missing_content', 'content (block markup) is required.'); }
    $all = wpultra_gb_custom_patterns_all();
    $existed = isset($all[$name]);
    $all[$name] = [
        'title'       => (string) ($input['title'] ?? $name),
        'content'     => $content,
        'categories'  => array_values(array_map('strval', (array) ($input['categories'] ?? []))),
        'description' => (string) ($input['description'] ?? ''),
    ];
    update_option(WPULTRA_BLOCK_PATTERNS_OPTION, $all, false);
    wpultra_register_custom_block_patterns(); // reflect immediately this request
    return ['name' => $name, 'saved' => true, 'updated' => $existed];
}

/** Delete a custom pattern. @return array|WP_Error */
function wpultra_gb_pattern_delete(string $name) {
    $name = wpultra_gb_pattern_normalize_name($name);
    $all = wpultra_gb_custom_patterns_all();
    if (!isset($all[$name])) { return wpultra_err('not_found', "No custom pattern '$name'."); }
    unset($all[$name]);
    update_option(WPULTRA_BLOCK_PATTERNS_OPTION, $all, false);
    if (function_exists('unregister_block_pattern') && \WP_Block_Patterns_Registry::get_instance()->is_registered($name)) {
        unregister_block_pattern($name);
    }
    return ['name' => $name, 'deleted' => true];
}

/** Ability dispatcher. @return array|WP_Error */
function wpultra_register_block_pattern_cb(array $input) {
    $action = (string) ($input['action'] ?? 'list');
    switch ($action) {
        case 'list':
            $rows = [];
            foreach (wpultra_gb_custom_patterns_all() as $name => $p) {
                $rows[] = [
                    'name'       => (string) $name,
                    'title'      => (string) ($p['title'] ?? ''),
                    'categories' => (array) ($p['categories'] ?? []),
                    'bytes'      => strlen((string) ($p['content'] ?? '')),
                ];
            }
            return wpultra_ok(['patterns' => $rows, 'count' => count($rows)]);
        case 'save':
            $res = wpultra_gb_pattern_save($input);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('register-block-pattern', 'save ' . $res['name'], true);
            return wpultra_ok($res);
        case 'delete':
            if ($e = wpultra_require_confirm($input, 'Deleting a custom block pattern removes it from the inserter.')) { return $e; }
            $res = wpultra_gb_pattern_delete((string) ($input['name'] ?? ''));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('register-block-pattern', 'delete ' . $res['name'], true);
            return wpultra_ok($res);
        default:
            return wpultra_err('bad_action', "Unknown action '$action'.");
    }
}
