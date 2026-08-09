<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Ninja Forms (v3) adapter.
 *
 * Ninja Forms stores forms in `{$wpdb->prefix}nf3_forms` and fields in
 * `{$wpdb->prefix}nf3_fields` (parent_id = form id) with per-field settings in
 * `{$wpdb->prefix}nf3_field_meta` (parent_id = field id, `key`/`value` rows —
 * `key` is a reserved word, always backticked). Submissions are `nf_sub` posts
 * whose postmeta maps `_field_<field_id>` => value plus `_form_id`.
 * Creation goes through the official model factory
 * (Ninja_Forms()->form()->get() → update_settings() → save()) so NF's own
 * caches stay coherent. Everything degrades gracefully when NF is absent.
 */

/* ------------------------------------------------------------------ *
 * PURE: unified fields[] -> Ninja field settings
 * ------------------------------------------------------------------ */

/** Map the unified field type to a Ninja Forms field type. Pure. */
function wpultra_forms_ninja_type(string $type): string {
    return match ($type) {
        'email'    => 'email',
        'textarea' => 'textarea',
        'select'   => 'listselect',
        'checkbox' => 'listcheckbox',
        'radio'    => 'listradio',
        'number'   => 'number',
        'date'     => 'date',
        'file'     => 'file_upload',
        default    => 'textbox',
    };
}

/** Derive a Ninja field key (letters/digits/underscore). Pure. */
function wpultra_forms_ninja_key(string $label, int $index): string {
    $slug = strtolower($label);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim((string) $slug, '_');
    if ($slug === '') { $slug = 'field'; }
    return $slug . '_' . $index;
}

/**
 * Build ONE Ninja field's settings array (what update_settings() receives). Pure.
 * @return array<string,mixed>
 */
function wpultra_forms_ninja_field(array $field, int $index): array {
    $type  = (string) ($field['type'] ?? 'text');
    $label = (string) ($field['label'] ?? ('Field ' . $index));
    $out = [
        'type'     => wpultra_forms_ninja_type($type),
        'label'    => $label,
        'key'      => wpultra_forms_ninja_key($label, $index),
        'order'    => $index,
        'required' => empty($field['required']) ? 0 : 1,
        'label_pos'=> 'above',
    ];
    if (in_array($type, ['select', 'checkbox', 'radio'], true)) {
        $options = [];
        $order = 0;
        foreach ((array) ($field['options'] ?? []) as $opt) {
            $options[] = ['label' => (string) $opt, 'value' => (string) $opt, 'calc' => '', 'selected' => 0, 'order' => $order++];
        }
        $out['options'] = $options;
    }
    return $out;
}

/**
 * Build the full ordered field-settings list including the submit button. Pure.
 * @param array<int,array> $fields
 * @return array<int,array<string,mixed>>
 */
function wpultra_forms_ninja_fields(array $fields): array {
    $out = [];
    $index = 1;
    foreach ($fields as $field) {
        if (!is_array($field)) { continue; }
        $out[] = wpultra_forms_ninja_field($field, $index);
        $index++;
    }
    $out[] = ['type' => 'submit', 'label' => 'Submit', 'key' => 'submit_' . $index, 'order' => $index, 'processing_label' => 'Processing'];
    return $out;
}

/* ------------------------------------------------------------------ *
 * PURE: nf_sub postmeta -> flat field map
 * ------------------------------------------------------------------ */

/**
 * Flatten one Ninja submission. $meta is the sub post's meta as key => value
 * (already single-valued); $field_map is field_id => ['key'=>..,'label'=>..].
 * @return array{id:int,date:string,fields:array<string,mixed>}
 */
function wpultra_forms_ninja_flatten_entry(int $sub_id, string $date, array $meta, array $field_map): array {
    $fields = [];
    foreach ($meta as $mk => $value) {
        if (!is_string($mk) || !preg_match('/^_field_(\d+)$/', $mk, $m)) { continue; }
        $fid  = (int) $m[1];
        $name = (string) ($field_map[$fid]['key'] ?? ('field_' . $fid));
        // Multi-value fields (checkbox lists) arrive serialized/JSON — flatten to a comma list.
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (is_array($decoded)) {
            $flat = [];
            array_walk_recursive($decoded, static function ($v) use (&$flat) { if ($v !== '' && $v !== null) { $flat[] = (string) $v; } });
            $fields[$name] = implode(', ', $flat);
        } else {
            $fields[$name] = $value;
        }
    }
    return ['id' => $sub_id, 'date' => $date, 'fields' => $fields];
}

/* ------------------------------------------------------------------ *
 * THIN WP-calling functions
 * ------------------------------------------------------------------ */

/** True when the Ninja forms table exists. */
function wpultra_forms_ninja_has_table(): bool {
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) { return false; }
    $table = $wpdb->prefix . 'nf3_forms';
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

/** @return int */
function wpultra_forms_ninja_count(): int {
    global $wpdb;
    if (!wpultra_forms_ninja_has_table()) { return 0; }
    $table = $wpdb->prefix . 'nf3_forms';
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
}

/**
 * field_id => ['key'=>..,'label'=>..] for one form. NF 3.4+ stores label/key
 * as columns on nf3_fields (the authoritative source on modern installs, and
 * the model factory does not always populate nf3_field_meta); fall back to the
 * meta table for pre-3.4 rows whose columns are empty.
 */
function wpultra_forms_ninja_field_map(int $form_id): array {
    global $wpdb;
    $fields_t = $wpdb->prefix . 'nf3_fields';
    $meta_t   = $wpdb->prefix . 'nf3_field_meta';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, `label`, `key` FROM {$fields_t} WHERE parent_id = %d", $form_id), ARRAY_A);
    $map = [];
    $missing = [];
    foreach ((array) $rows as $r) {
        $fid = (int) $r['id'];
        $map[$fid] = ['key' => (string) ($r['key'] ?? ''), 'label' => (string) ($r['label'] ?? '')];
        if ($map[$fid]['key'] === '') { $missing[] = $fid; }
    }
    if ($missing !== []) {
        $in = implode(',', array_map('intval', $missing));
        $meta = $wpdb->get_results("SELECT parent_id, `key`, `value` FROM {$meta_t} WHERE parent_id IN ({$in}) AND `key` IN ('key', 'label')", ARRAY_A);
        foreach ((array) $meta as $r) {
            $map[(int) $r['parent_id']][(string) $r['key']] = (string) $r['value'];
        }
    }
    return $map;
}

/** @return array<int,array> */
function wpultra_forms_ninja_list(): array {
    global $wpdb;
    if (!wpultra_forms_ninja_has_table()) { return []; }
    $forms_t = $wpdb->prefix . 'nf3_forms';
    $rows = $wpdb->get_results("SELECT id, title FROM {$forms_t}", ARRAY_A);
    $out = [];
    foreach ((array) $rows as $r) {
        $id = (int) $r['id'];
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_form_id' WHERE p.post_type = 'nf_sub' AND p.post_status = 'publish' AND m.meta_value = %s",
            (string) $id
        ));
        $out[] = [
            'id'                => $id,
            'title'             => (string) $r['title'],
            'plugin'            => 'ninja',
            'shortcode'         => sprintf('[ninja_form id=%d]', $id),
            'entries_count'     => $count,
            'entries_supported' => true,
        ];
    }
    return $out;
}

/**
 * @return array|WP_Error
 */
function wpultra_forms_ninja_get_entries(int $form_id, int $per_page, int $page, string $search) {
    if (!wpultra_forms_ninja_has_table()) {
        return wpultra_forms_err('forms_unavailable', 'Ninja Forms is not active.');
    }
    $field_map = wpultra_forms_ninja_field_map($form_id);
    $subs = get_posts([
        'post_type'      => 'nf_sub',
        'post_status'    => 'publish',
        'posts_per_page' => 500, // search filters post-query; window matches the other adapters' scan cap
        'orderby'        => 'ID',
        'order'          => 'DESC',
        'meta_key'       => '_form_id',
        'meta_value'     => (string) $form_id,
    ]);
    $out = [];
    foreach ($subs as $sub) {
        $meta = [];
        foreach ((array) get_post_meta($sub->ID) as $mk => $vals) {
            $meta[$mk] = is_array($vals) ? (string) ($vals[0] ?? '') : (string) $vals;
        }
        $entry = wpultra_forms_ninja_flatten_entry((int) $sub->ID, (string) $sub->post_date, $meta, $field_map);
        if (!wpultra_forms_entry_matches($entry, $search)) { continue; }
        $out[] = $entry;
    }
    $offset = max(0, ($page - 1)) * $per_page;
    return array_slice($out, $offset, $per_page);
}

/**
 * Create a Ninja form through the official model factory so NF's form cache
 * stays coherent. Uses the pure fields builder.
 * @return array|WP_Error
 */
function wpultra_forms_ninja_create(string $title, array $fields) {
    if (!function_exists('Ninja_Forms')) {
        return wpultra_forms_err('forms_unavailable', 'Ninja Forms is not active.');
    }
    try {
        $form = Ninja_Forms()->form()->get();
        $form->update_settings([
            'title'             => $title,
            'created_at'        => current_time('mysql'),
            'default_label_pos' => 'above',
        ]);
        $form->save();
        $form_id = (int) $form->get_id();
        if ($form_id <= 0) {
            return wpultra_forms_err('forms_create_failed', 'Ninja Forms did not return a new form id.');
        }
        foreach (wpultra_forms_ninja_fields($fields) as $settings) {
            $field = Ninja_Forms()->form($form_id)->field()->get();
            $field->update_settings($settings);
            $field->save();
        }
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'forms-ninja'); }
        return wpultra_forms_err('forms_create_failed', 'Ninja Forms create failed: ' . $e->getMessage());
    }
    return [
        'id'        => $form_id,
        'title'     => $title,
        'plugin'    => 'ninja',
        'shortcode' => sprintf('[ninja_form id=%d]', $form_id),
    ];
}
