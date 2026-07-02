<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Fluent Forms adapter.
 *
 * Fluent Forms stores forms in the `{$wpdb->prefix}fluentform_forms` table; the field
 * layout is a JSON string in the `form_fields` column shaped:
 *   { "fields": [ {element,attributes{name},settings{label,validation_rules...}}, ... ] }
 * Submissions live in `{$wpdb->prefix}fluentform_submissions` (`response` = JSON of
 * name=>value). Everything degrades gracefully when Fluent Forms is absent.
 */

/* ------------------------------------------------------------------ *
 * PURE: unified fields[] -> Fluent field-JSON structure
 * ------------------------------------------------------------------ */

/** Map the unified field type to a Fluent Forms element name. Pure. */
function wpultra_forms_fluent_element(string $type): string {
    return match ($type) {
        'email'    => 'input_email',
        'textarea' => 'textarea',
        'select'   => 'select',
        'checkbox' => 'input_checkbox',
        'radio'    => 'input_radio',
        'number'   => 'input_number',
        'date'     => 'input_date',
        'file'     => 'input_file',
        default    => 'input_text',
    };
}

/**
 * Build ONE Fluent field definition. Pure.
 * @return array<string,mixed>
 */
function wpultra_forms_fluent_field(array $field, int $index): array {
    $type    = (string) ($field['type'] ?? 'text');
    $label   = (string) ($field['label'] ?? ('Field ' . $index));
    $name    = wpultra_forms_fluent_name($label, $index);
    $element = wpultra_forms_fluent_element($type);
    $out = [
        'element'    => $element,
        'attributes' => [
            'name'     => $name,
            'type'     => in_array($type, ['email', 'number', 'date'], true) ? $type : 'text',
            'required' => !empty($field['required']),
        ],
        'settings'   => [
            'label'            => $label,
            'validation_rules' => [
                'required' => ['value' => !empty($field['required']), 'message' => $label . ' is required.'],
            ],
        ],
    ];
    if (in_array($type, ['select', 'checkbox', 'radio'], true)) {
        $options = [];
        foreach ((array) ($field['options'] ?? []) as $opt) {
            $options[] = ['label' => (string) $opt, 'value' => (string) $opt];
        }
        $out['settings']['advanced_options'] = $options;
    }
    return $out;
}

/**
 * Build the full Fluent `form_fields` structure (decoded form; caller JSON-encodes).
 * @param array<int,array> $fields
 * @return array<string,mixed>
 */
function wpultra_forms_fluent_fields(array $fields): array {
    $ff = [];
    $index = 1;
    foreach ($fields as $field) {
        if (!is_array($field)) { continue; }
        $ff[] = wpultra_forms_fluent_field($field, $index);
        $index++;
    }
    // Fluent appends a submit button as a separate container element.
    $ff[] = [
        'element'    => 'button',
        'attributes' => ['type' => 'submit'],
        'settings'   => ['button_ui' => ['text' => 'Submit']],
    ];
    return ['fields' => $ff, 'submitButton' => ['element' => 'button', 'settings' => ['button_ui' => ['text' => 'Submit']]]];
}

/** Derive a Fluent field name (letters/digits/underscore). Pure. */
function wpultra_forms_fluent_name(string $label, int $index): string {
    $slug = strtolower($label);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim((string) $slug, '_');
    if ($slug === '') { $slug = 'field'; }
    return $slug . '_' . $index;
}

/* ------------------------------------------------------------------ *
 * PURE: fluentform_submissions row -> flat field map
 * ------------------------------------------------------------------ */

/**
 * Flatten one Fluent submission. The `response` column is a JSON string of name=>value.
 * @param array<string,mixed> $row  ['id'=>..,'response'=>json,'created_at'=>..]
 * @return array{id:int,date:string,fields:array<string,mixed>}
 */
function wpultra_forms_fluent_flatten_entry(array $row): array {
    $raw = $row['response'] ?? '';
    $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
    if (!is_array($decoded)) { $decoded = []; }
    $fields = [];
    foreach ($decoded as $name => $value) {
        if (is_array($value)) {
            // Nested (name/address groups) — flatten to a comma list of scalar leaves.
            $flat = [];
            array_walk_recursive($value, static function ($v) use (&$flat) { if ($v !== '' && $v !== null) { $flat[] = (string) $v; } });
            $fields[(string) $name] = implode(', ', $flat);
        } else {
            $fields[(string) $name] = $value;
        }
    }
    return [
        'id'     => (int) ($row['id'] ?? 0),
        'date'   => (string) ($row['created_at'] ?? ''),
        'fields' => $fields,
    ];
}

/* ------------------------------------------------------------------ *
 * PURE: entry-listing SQL builder (testable in isolation)
 * ------------------------------------------------------------------ */

/**
 * Build the SELECT for a page of submissions. Pushes a non-empty search into SQL as a
 * LIKE on the `response` JSON column so pagination stays correct (search is applied
 * BEFORE the LIMIT/OFFSET, not after). Pure string builder — the caller binds params
 * via $wpdb->prepare, so the placeholders (%d/%s) are emitted in argument order:
 * form_id, [search], per_page, offset.
 */
function wpultra_forms_fluent_entries_sql(string $subs_t, bool $has_search): string {
    $where = 'WHERE form_id = %d';
    if ($has_search) { $where .= ' AND response LIKE %s'; }
    return "SELECT id, response, created_at FROM {$subs_t} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
}

/* ------------------------------------------------------------------ *
 * THIN WP-calling functions
 * ------------------------------------------------------------------ */

/** True when the Fluent forms table exists. */
function wpultra_forms_fluent_has_table(): bool {
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) { return false; }
    $table = $wpdb->prefix . 'fluentform_forms';
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

/** @return int */
function wpultra_forms_fluent_count(): int {
    global $wpdb;
    if (!wpultra_forms_fluent_has_table()) { return 0; }
    $table = $wpdb->prefix . 'fluentform_forms';
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
}

/** @return array<int,array> */
function wpultra_forms_fluent_list(): array {
    global $wpdb;
    if (!wpultra_forms_fluent_has_table()) { return []; }
    $forms_t = $wpdb->prefix . 'fluentform_forms';
    $subs_t  = $wpdb->prefix . 'fluentform_submissions';
    $rows = $wpdb->get_results("SELECT id, title FROM {$forms_t}", ARRAY_A);
    $out = [];
    foreach ((array) $rows as $r) {
        $id = (int) $r['id'];
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$subs_t} WHERE form_id = %d", $id));
        $out[] = [
            'id'                => $id,
            'title'             => (string) $r['title'],
            'plugin'            => 'fluent',
            'shortcode'         => sprintf('[fluentform id="%d"]', $id),
            'entries_count'     => $count,
            'entries_supported' => true,
        ];
    }
    return $out;
}

/**
 * @return array|WP_Error
 */
function wpultra_forms_fluent_get_entries(int $form_id, int $per_page, int $page, string $search) {
    global $wpdb;
    if (!wpultra_forms_fluent_has_table()) {
        return wpultra_forms_err('forms_unavailable', 'Fluent Forms is not active.');
    }
    $subs_t = $wpdb->prefix . 'fluentform_submissions';
    $offset = max(0, ($page - 1)) * $per_page;
    $sql    = wpultra_forms_fluent_entries_sql($subs_t, $search !== '');
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $prepared = $wpdb->prepare($sql, $form_id, $like, $per_page, $offset);
    } else {
        $prepared = $wpdb->prepare($sql, $form_id, $per_page, $offset);
    }
    $rows = $wpdb->get_results($prepared, ARRAY_A);
    $out = [];
    foreach ((array) $rows as $row) {
        $out[] = wpultra_forms_fluent_flatten_entry((array) $row);
    }
    return $out;
}

/**
 * Create a Fluent form. Prefers the Fluent API model when available, else inserts the
 * row directly. Uses the pure fields builder.
 * @return array|WP_Error
 */
function wpultra_forms_fluent_create(string $title, array $fields) {
    global $wpdb;
    if (!wpultra_forms_fluent_has_table()) {
        return wpultra_forms_err('forms_unavailable', 'Fluent Forms is not active.');
    }
    $form_fields = wpultra_forms_fluent_fields($fields);
    $forms_t = $wpdb->prefix . 'fluentform_forms';
    $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
    $ok = $wpdb->insert($forms_t, [
        'title'       => $title,
        'form_fields' => (string) wp_json_encode($form_fields),
        'has_payment' => 0,
        'type'        => 'form',
        'status'      => 'published',
        'created_by'  => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);
    if ($ok === false) { return wpultra_forms_err('forms_create_failed', 'Fluent Forms could not insert the new form.'); }
    $id = (int) $wpdb->insert_id;
    return [
        'id'        => $id,
        'title'     => $title,
        'plugin'    => 'fluent',
        'shortcode' => sprintf('[fluentform id="%d"]', $id),
    ];
}
