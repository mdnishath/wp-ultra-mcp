<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Gravity Forms adapter.
 *
 * Gravity exposes a full API (GFAPI). Forms are arrays with a `fields` list of field
 * objects; entries come from GFAPI::get_entries() as arrays keyed by field id. Everything
 * degrades gracefully when GFAPI is absent.
 */

/* ------------------------------------------------------------------ *
 * PURE: unified fields[] -> Gravity form-object structure
 * ------------------------------------------------------------------ */

/** Map the unified field type to a Gravity field type. Pure. */
function wpultra_forms_gravity_type(string $type): string {
    return match ($type) {
        'email'    => 'email',
        'textarea' => 'textarea',
        'select'   => 'select',
        'checkbox' => 'checkbox',
        'radio'    => 'radio',
        'number'   => 'number',
        'date'     => 'date',
        'file'     => 'fileupload',
        default    => 'text',
    };
}

/**
 * Build ONE Gravity field definition. Pure.
 * @return array<string,mixed>
 */
function wpultra_forms_gravity_field(array $field, int $id): array {
    $type  = (string) ($field['type'] ?? 'text');
    $label = (string) ($field['label'] ?? ('Field ' . $id));
    $out = [
        'id'         => $id,
        'type'       => wpultra_forms_gravity_type($type),
        'label'      => $label,
        'isRequired' => !empty($field['required']),
    ];
    if (in_array($type, ['select', 'checkbox', 'radio'], true)) {
        $choices = [];
        $inputs  = [];
        $ci = 1;
        foreach ((array) ($field['options'] ?? []) as $opt) {
            $choices[] = ['text' => (string) $opt, 'value' => (string) $opt];
            // Gravity checkboxes need an inputs[] entry per choice (id.index).
            if ($type === 'checkbox') { $inputs[] = ['id' => $id . '.' . $ci, 'label' => (string) $opt]; }
            $ci++;
        }
        if ($choices === []) { $choices[] = ['text' => 'First Choice', 'value' => 'First Choice']; }
        $out['choices'] = $choices;
        if ($type === 'checkbox') { $out['inputs'] = $inputs; }
    }
    return $out;
}

/**
 * Build the full Gravity form object (what GFAPI::add_form() accepts) from unified fields[].
 * @param array<int,array> $fields
 * @return array<string,mixed>
 */
function wpultra_forms_gravity_form(string $title, array $fields): array {
    $gf_fields = [];
    $id = 1;
    foreach ($fields as $field) {
        if (!is_array($field)) { continue; }
        $gf_fields[] = wpultra_forms_gravity_field($field, $id);
        $id++;
    }
    return [
        'title'       => $title,
        'description' => '',
        'labelPlacement' => 'top_label',
        'button'      => ['type' => 'text', 'text' => 'Submit'],
        'fields'      => $gf_fields,
    ];
}

/* ------------------------------------------------------------------ *
 * PURE: Gravity entry -> flat field map
 * ------------------------------------------------------------------ */

/**
 * Flatten one Gravity entry. Gravity entries are arrays keyed by field id (and id.index
 * for composite fields) plus meta keys like 'id','date_created'. We build a label map
 * from the form definition so keys are human-readable.
 * @param array<string,mixed> $entry  a GFAPI entry array
 * @param array<int,array>    $form_fields  the form's fields[] (for id->label mapping)
 * @return array{id:int,date:string,fields:array<string,mixed>}
 */
function wpultra_forms_gravity_flatten_entry(array $entry, array $form_fields = []): array {
    $labels = [];
    foreach ($form_fields as $f) {
        if (!is_array($f)) { continue; }
        $fid = (string) ($f['id'] ?? '');
        if ($fid !== '') { $labels[$fid] = (string) ($f['label'] ?? $fid); }
    }
    $fields = [];
    foreach ($entry as $key => $value) {
        // Skip Gravity meta keys (non-numeric, e.g. 'id', 'date_created', 'form_id').
        if (!is_numeric((string) $key)) { continue; }
        if ($value === '' || $value === null) { continue; }
        // Composite keys like '3.2' belong to field 3.
        $base  = (string) (int) $key;
        $label = $labels[$key] ?? ($labels[$base] ?? $key);
        if (isset($fields[$label]) && $fields[$label] !== '') {
            $fields[$label] .= ', ' . (is_array($value) ? implode(', ', $value) : (string) $value);
        } else {
            $fields[$label] = is_array($value) ? implode(', ', $value) : $value;
        }
    }
    return [
        'id'     => (int) ($entry['id'] ?? 0),
        'date'   => (string) ($entry['date_created'] ?? ''),
        'fields' => $fields,
    ];
}

/* ------------------------------------------------------------------ *
 * THIN WP-calling functions
 * ------------------------------------------------------------------ */

/** @return int */
function wpultra_forms_gravity_count(): int {
    if (!class_exists('GFAPI')) { return 0; }
    $forms = GFAPI::get_forms();
    return is_array($forms) ? count($forms) : 0;
}

/** @return array<int,array> */
function wpultra_forms_gravity_list(): array {
    if (!class_exists('GFAPI')) { return []; }
    $out = [];
    foreach ((array) GFAPI::get_forms() as $form) {
        $id = (int) ($form['id'] ?? 0);
        $count = GFAPI::count_entries($id);
        $out[] = [
            'id'                => $id,
            'title'             => (string) ($form['title'] ?? ''),
            'plugin'            => 'gravity',
            'shortcode'         => sprintf('[gravityform id="%d" title="false" description="false"]', $id),
            'entries_count'     => is_wp_error($count) ? null : (int) $count,
            'entries_supported' => true,
        ];
    }
    return $out;
}

/**
 * @return array|WP_Error
 */
function wpultra_forms_gravity_get_entries(int $form_id, int $per_page, int $page, string $search) {
    if (!class_exists('GFAPI')) {
        return wpultra_forms_err('forms_unavailable', 'Gravity Forms is not active.');
    }
    $paging = ['offset' => max(0, ($page - 1)) * $per_page, 'page_size' => $per_page];
    $search_criteria = [];
    if ($search !== '') { $search_criteria['search'] = $search; }
    $entries = GFAPI::get_entries($form_id, $search_criteria, null, $paging);
    if (is_wp_error($entries)) { return $entries; }
    $form = GFAPI::get_form($form_id);
    $form_fields = is_array($form) ? (array) ($form['fields'] ?? []) : [];
    // Normalize GF field objects to arrays for the pure flattener.
    $ff = [];
    foreach ($form_fields as $f) { $ff[] = is_object($f) ? (array) $f : (array) $f; }
    $out = [];
    foreach ((array) $entries as $entry) {
        $out[] = wpultra_forms_gravity_flatten_entry((array) $entry, $ff);
    }
    return $out;
}

/**
 * Create a Gravity form via GFAPI::add_form(). Uses the pure form builder.
 * @return array|WP_Error
 */
function wpultra_forms_gravity_create(string $title, array $fields) {
    if (!class_exists('GFAPI')) {
        return wpultra_forms_err('forms_unavailable', 'Gravity Forms is not active.');
    }
    $form = wpultra_forms_gravity_form($title, $fields);
    $result = GFAPI::add_form($form);
    if (is_wp_error($result)) { return $result; }
    $id = (int) $result;
    return [
        'id'        => $id,
        'title'     => $title,
        'plugin'    => 'gravity',
        'shortcode' => sprintf('[gravityform id="%d" title="false" description="false"]', $id),
    ];
}
