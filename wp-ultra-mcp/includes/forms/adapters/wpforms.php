<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * WPForms adapter.
 *
 * WPForms stores each form as a `wpforms` CPT whose post_content is a JSON blob:
 *   { "fields": { "0": {id,type,label,required,choices...}, ... }, "field_id": N, ... }
 * Entries live in the custom `{$wpdb->prefix}wpforms_entries` table and exist ONLY in
 * WPForms Pro. Lite has no entry store. Everything degrades when WPForms is absent.
 */

/* ------------------------------------------------------------------ *
 * PURE: unified fields[] -> WPForms field-JSON structure
 * ------------------------------------------------------------------ */

/** Map the unified field type to a WPForms field type. Pure. */
function wpultra_forms_wpforms_type(string $type): string {
    return match ($type) {
        'email'    => 'email',
        'textarea' => 'textarea',
        'select'   => 'select',
        'checkbox' => 'checkbox',
        'radio'    => 'radio',
        'number'   => 'number',
        'date'     => 'date-time',
        'file'     => 'file-upload',
        default    => 'text',
    };
}

/**
 * Build ONE WPForms field definition (the value shape used inside form_data['fields']).
 * @return array<string,mixed>
 */
function wpultra_forms_wpforms_field(array $field, int $id): array {
    $type  = (string) ($field['type'] ?? 'text');
    $label = (string) ($field['label'] ?? ('Field ' . $id));
    $out = [
        'id'       => (string) $id,
        'type'     => wpultra_forms_wpforms_type($type),
        'label'    => $label,
        'required' => !empty($field['required']) ? '1' : '',
        'size'     => 'medium',
    ];
    if (in_array($type, ['select', 'checkbox', 'radio'], true)) {
        $choices = [];
        $ci = 1;
        foreach ((array) ($field['options'] ?? []) as $opt) {
            $choices[(string) $ci] = ['label' => (string) $opt, 'value' => ''];
            $ci++;
        }
        if ($choices === []) { $choices['1'] = ['label' => 'Option 1', 'value' => '']; }
        $out['choices'] = $choices;
    }
    return $out;
}

/**
 * Build the full WPForms form_data['fields'] map (id-keyed) from unified fields[].
 * @param array<int,array> $fields
 * @return array<string,array>
 */
function wpultra_forms_wpforms_fields(array $fields): array {
    $out = [];
    $id  = 1;
    foreach ($fields as $field) {
        if (!is_array($field)) { continue; }
        $out[(string) $id] = wpultra_forms_wpforms_field($field, $id);
        $id++;
    }
    return $out;
}

/**
 * Build the complete WPForms form_data payload (what gets JSON-encoded into post_content).
 * @return array<string,mixed>
 */
function wpultra_forms_wpforms_form_data(string $title, array $fields): array {
    $field_map = wpultra_forms_wpforms_fields($fields);
    return [
        'field_id' => count($field_map) + 1,
        'fields'   => $field_map,
        'settings' => [
            'form_title'      => $title,
            'submit_text'     => 'Submit',
            'antispam_v3'     => '1',
        ],
    ];
}

/* ------------------------------------------------------------------ *
 * PURE: wpforms_entries row -> flat field map
 * ------------------------------------------------------------------ */

/**
 * Flatten one WPForms entry. The `fields` column is a JSON string of
 *   { "1": {"name":"Name","value":"Bob","id":"1",...}, ... }
 * @param array<string,mixed> $row  a DB row: ['entry_id'=>..,'fields'=>json,'date'=>..]
 * @return array{id:int,date:string,fields:array<string,mixed>}
 */
function wpultra_forms_wpforms_flatten_entry(array $row): array {
    $raw = $row['fields'] ?? '';
    $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
    if (!is_array($decoded)) { $decoded = []; }
    $fields = [];
    foreach ($decoded as $key => $f) {
        if (is_array($f)) {
            $name = (string) ($f['name'] ?? $key);
            $val  = $f['value'] ?? '';
            $fields[$name !== '' ? $name : (string) $key] = is_array($val) ? implode(', ', array_map('strval', $val)) : $val;
        } else {
            $fields[(string) $key] = $f;
        }
    }
    return [
        'id'     => (int) ($row['entry_id'] ?? $row['id'] ?? 0),
        'date'   => (string) ($row['date'] ?? ''),
        'fields' => $fields,
    ];
}

/* ------------------------------------------------------------------ *
 * THIN WP-calling functions
 * ------------------------------------------------------------------ */

/** True when the Pro entries table exists. */
function wpultra_forms_wpforms_has_entries_table(): bool {
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) { return false; }
    $table = $wpdb->prefix . 'wpforms_entries';
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    return $found === $table;
}

/** @return int */
function wpultra_forms_wpforms_count(): int {
    if (function_exists('wp_count_posts') && function_exists('post_type_exists') && post_type_exists('wpforms')) {
        $c = wp_count_posts('wpforms');
        return (int) ($c->publish ?? 0);
    }
    return 0;
}

/** @return array<int,array> */
function wpultra_forms_wpforms_list(): array {
    if (!function_exists('get_posts')) { return []; }
    $forms = get_posts(['post_type' => 'wpforms', 'posts_per_page' => -1, 'post_status' => 'publish']);
    $has_entries = wpultra_forms_wpforms_has_entries_table();
    $out = [];
    foreach ((array) $forms as $f) {
        $id = (int) $f->ID;
        $out[] = [
            'id'                => $id,
            'title'             => (string) $f->post_title,
            'plugin'            => 'wpforms',
            'shortcode'         => sprintf('[wpforms id="%d"]', $id),
            'entries_count'     => $has_entries ? wpultra_forms_wpforms_entry_count($id) : null,
            'entries_supported' => $has_entries,
        ];
    }
    return $out;
}

/** Count Pro entries for a form. */
function wpultra_forms_wpforms_entry_count(int $form_id): ?int {
    global $wpdb;
    if (!wpultra_forms_wpforms_has_entries_table()) { return null; }
    $table = $wpdb->prefix . 'wpforms_entries';
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id));
}

/**
 * @return array|WP_Error
 */
function wpultra_forms_wpforms_get_entries(int $form_id, int $per_page, int $page, string $search) {
    global $wpdb;
    if (!wpultra_forms_wpforms_has_entries_table()) {
        return wpultra_forms_err('forms_entries_unavailable', 'WPForms entries require WPForms Pro (the wpforms_entries table is absent — Lite stores no entries).');
    }
    $table  = $wpdb->prefix . 'wpforms_entries';
    $offset = max(0, ($page - 1)) * $per_page;
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT entry_id, fields, date FROM {$table} WHERE form_id = %d ORDER BY entry_id DESC LIMIT %d OFFSET %d", $form_id, $per_page, $offset),
        ARRAY_A
    );
    $out = [];
    foreach ((array) $rows as $row) {
        $flat = wpultra_forms_wpforms_flatten_entry((array) $row);
        if ($search !== '' && !wpultra_forms_entry_matches($flat, $search)) { continue; }
        $out[] = $flat;
    }
    return $out;
}

/**
 * Create a WPForms form from unified fields[]. Uses the pure form_data builder.
 * @return array|WP_Error
 */
function wpultra_forms_wpforms_create(string $title, array $fields) {
    if (!function_exists('wp_insert_post')) {
        return wpultra_forms_err('forms_unavailable', 'WordPress not loaded.');
    }
    if (function_exists('post_type_exists') && !post_type_exists('wpforms')) {
        return wpultra_forms_err('forms_unavailable', 'WPForms is not active.');
    }
    $form_data = wpultra_forms_wpforms_form_data($title, $fields);
    $post_id = wp_insert_post([
        'post_title'   => $title,
        'post_status'  => 'publish',
        'post_type'    => 'wpforms',
        'post_content' => function_exists('wpforms_encode') ? wpforms_encode($form_data) : (string) wp_json_encode($form_data),
    ], true);
    if (is_wp_error($post_id)) { return $post_id; }
    // WPForms keys form_data by the post id.
    $form_data['id'] = (int) $post_id;
    if (function_exists('wp_update_post')) {
        wp_update_post([
            'ID'           => (int) $post_id,
            'post_content' => function_exists('wpforms_encode') ? wpforms_encode($form_data) : (string) wp_json_encode($form_data),
        ]);
    }
    return [
        'id'        => (int) $post_id,
        'title'     => $title,
        'plugin'    => 'wpforms',
        'shortcode' => sprintf('[wpforms id="%d"]', (int) $post_id),
    ];
}
