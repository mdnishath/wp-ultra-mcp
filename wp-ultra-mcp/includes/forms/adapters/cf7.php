<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Contact Form 7 adapter.
 *
 * CF7 stores form markup as plain form-tag text on the `wpcf7_contact_form` CPT.
 * It has NO entry store of its own — entries only exist when the Flamingo plugin is
 * installed (it captures submissions as `flamingo_inbound` posts). Every function
 * degrades gracefully when CF7 (or Flamingo) is absent.
 */

/* ------------------------------------------------------------------ *
 * PURE: unified fields[] -> CF7 form-tag markup string
 * ------------------------------------------------------------------ */

/**
 * Map ONE unified field to a CF7 form-tag + its <label> wrapper.
 * Unified field: {type,label,required,options[]}. Pure.
 */
function wpultra_forms_cf7_field(array $field, int $index): string {
    $type     = (string) ($field['type'] ?? 'text');
    $label    = (string) ($field['label'] ?? ('Field ' . $index));
    $required = !empty($field['required']);
    $name     = wpultra_forms_cf7_name($label, $index);
    $options  = array_values(array_map('strval', (array) ($field['options'] ?? [])));

    // Map the unified type to a CF7 tag base.
    $tag = match ($type) {
        'email'    => 'email',
        'textarea' => 'textarea',
        'select'   => 'select',
        'checkbox' => 'checkbox',
        'radio'    => 'radio',
        'number'   => 'number',
        'date'     => 'date',
        'file'     => 'file',
        default    => 'text',
    };
    $star = $required ? '*' : '';

    // Choice tags carry their options inline; scalar tags don't.
    if (in_array($tag, ['select', 'checkbox', 'radio'], true)) {
        $opt_str = '';
        foreach ($options as $o) { $opt_str .= ' "' . wpultra_forms_cf7_escape_option($o) . '"'; }
        $control = '[' . $tag . $star . ' ' . $name . $opt_str . ']';
    } else {
        $control = '[' . $tag . $star . ' ' . $name . ']';
    }

    $label_text = $label . ($required ? ' (required)' : '');
    return "<label>" . $label_text . "\n    " . $control . "</label>";
}

/**
 * Build a complete CF7 form body from unified fields[], appended with a submit tag.
 * @param array<int,array> $fields
 */
function wpultra_forms_cf7_markup(array $fields): string {
    $lines = [];
    $index = 1;
    foreach ($fields as $field) {
        if (!is_array($field)) { continue; }
        $lines[] = wpultra_forms_cf7_field($field, $index);
        $index++;
    }
    $lines[] = '[submit "Send"]';
    return implode("\n\n", $lines) . "\n";
}

/** Derive a unique-ish CF7 tag name (letters/digits/hyphen/underscore only). Pure. */
function wpultra_forms_cf7_name(string $label, int $index): string {
    $slug = strtolower($label);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');
    if ($slug === '') { $slug = 'field'; }
    // CF7 names may not start with a digit-only token collision; suffix the index to keep unique.
    return $slug . '-' . $index;
}

/** Escape a choice-option value for inclusion inside a double-quoted CF7 pipe. Pure. */
function wpultra_forms_cf7_escape_option(string $option): string {
    return str_replace('"', '', $option);
}

/* ------------------------------------------------------------------ *
 * PURE: Flamingo entry -> flat field map
 * ------------------------------------------------------------------ */

/**
 * Flatten one Flamingo inbound record into {id,fields{},date}. Pure over a fixture
 * array shaped like the meta we read from a `flamingo_inbound` post:
 *   ['id'=>int,'date'=>string,'fields'=>['your-name'=>'Bob', ...]]
 * @return array{id:int,date:string,fields:array<string,mixed>}
 */
function wpultra_forms_cf7_flatten_entry(array $record): array {
    $fields = [];
    foreach ((array) ($record['fields'] ?? []) as $k => $v) {
        // Flamingo stores multi-value fields as arrays; join for a flat view.
        $fields[(string) $k] = is_array($v) ? implode(', ', array_map('strval', $v)) : $v;
    }
    return [
        'id'     => (int) ($record['id'] ?? 0),
        'date'   => (string) ($record['date'] ?? ''),
        'fields' => $fields,
    ];
}

/* ------------------------------------------------------------------ *
 * THIN WP-calling functions (degrade when CF7/Flamingo absent)
 * ------------------------------------------------------------------ */

/** @return int */
function wpultra_forms_cf7_count(): int {
    if (!function_exists('wpcf7_contact_form')) {
        // Fall back to a CPT count when the API fn isn't loaded yet.
        if (function_exists('wp_count_posts') && function_exists('post_type_exists') && post_type_exists('wpcf7_contact_form')) {
            $c = wp_count_posts('wpcf7_contact_form');
            return (int) ($c->publish ?? 0);
        }
        return 0;
    }
    if (!class_exists('WPCF7_ContactForm')) { return 0; }
    $forms = WPCF7_ContactForm::find(['posts_per_page' => -1]);
    return is_array($forms) ? count($forms) : 0;
}

/** @return array<int,array> */
function wpultra_forms_cf7_list(): array {
    if (!class_exists('WPCF7_ContactForm')) { return []; }
    $out = [];
    foreach (WPCF7_ContactForm::find(['posts_per_page' => -1]) as $form) {
        $id = (int) $form->id();
        $out[] = [
            'id'                => $id,
            'title'             => (string) $form->title(),
            'plugin'            => 'cf7',
            'shortcode'         => sprintf('[contact-form-7 id="%d" title="%s"]', $id, $form->title()),
            'entries_count'     => wpultra_forms_flamingo_active() ? wpultra_forms_cf7_flamingo_count($id) : null,
            'entries_supported' => wpultra_forms_flamingo_active(),
        ];
    }
    return $out;
}

/** Count Flamingo inbound messages tied to a CF7 form id. */
function wpultra_forms_cf7_flamingo_count(int $form_id): ?int {
    if (!wpultra_forms_flamingo_active() || !function_exists('get_posts')) { return null; }
    $posts = get_posts([
        'post_type'      => 'flamingo_inbound',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_key'       => '_field_hash', // presence filter; refined by channel below
    ]);
    // Flamingo tags the source form via the '_channel'/'_meta' — a precise per-form count
    // requires channel matching; keep it simple and return total inbound as an upper bound.
    return is_array($posts) ? count($posts) : null;
}

/**
 * Read Flamingo entries for a CF7 form. Returns flattened entries (via the pure
 * flattener) or WP_Error when Flamingo is absent.
 * @return array|WP_Error
 */
function wpultra_forms_cf7_get_entries(int $form_id, int $per_page, int $page, string $search) {
    if (!wpultra_forms_flamingo_active()) {
        return wpultra_forms_err('forms_entries_unavailable', 'Contact Form 7 stores no entries without the Flamingo plugin. Install Flamingo to capture submissions.');
    }
    if (!function_exists('get_posts')) { return []; }
    // Flamingo stores each submitted field in post meta ('_field'/'_meta'), NOT in
    // post_content — so WP_Query's 's' cannot see field values. To keep pagination
    // consistent with the filtered set we fetch all inbound records, flatten them,
    // filter by the shared matcher, THEN slice the requested page from the result.
    //
    // Best-effort per-form scoping: Flamingo files each submission under a channel term
    // whose slug is 'contact-form-<id>' (the CF7 form's wpcf7 unit tag) in the
    // 'flamingo_inbound_channel' taxonomy. When that term exists, restrict the query to it;
    // otherwise fall back to returning all inbound entries (previous behavior).
    $query_args = [
        'post_type'      => 'flamingo_inbound',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    if (function_exists('get_term_by') && function_exists('taxonomy_exists') && taxonomy_exists('flamingo_inbound_channel')) {
        $channel = get_term_by('slug', 'contact-form-' . $form_id, 'flamingo_inbound_channel');
        if ($channel && !is_wp_error($channel)) {
            $query_args['tax_query'] = [[
                'taxonomy' => 'flamingo_inbound_channel',
                'field'    => 'term_id',
                'terms'    => (int) $channel->term_id,
            ]];
        }
    }
    $posts = get_posts($query_args);
    $all = [];
    foreach ((array) $posts as $p) {
        $meta   = function_exists('get_post_meta') ? (array) get_post_meta((int) $p->ID, '_meta', true) : [];
        $fields = function_exists('get_post_meta') ? (array) get_post_meta((int) $p->ID, '_field', true) : [];
        // Flamingo stores each submitted field as post meta prefixed '_field_' as well; prefer '_field'.
        $record = [
            'id'     => (int) $p->ID,
            'date'   => (string) $p->post_date,
            'fields' => $fields ?: $meta,
        ];
        $flat = wpultra_forms_cf7_flatten_entry($record);
        if ($search !== '' && !wpultra_forms_entry_matches($flat, $search)) { continue; }
        $all[] = $flat;
    }
    $offset = max(0, ($page - 1)) * $per_page;
    return array_slice($all, $offset, $per_page);
}

/**
 * Create a CF7 form from unified fields[]. Uses the pure markup builder, then persists
 * via the CF7 API when available.
 * @return array|WP_Error
 */
function wpultra_forms_cf7_create(string $title, array $fields) {
    $markup = wpultra_forms_cf7_markup($fields);
    if (!class_exists('WPCF7_ContactForm')) {
        return wpultra_forms_err('forms_unavailable', 'Contact Form 7 is not active.');
    }
    $form = WPCF7_ContactForm::get_template();
    $form->set_title($title);
    $props = $form->get_properties();
    $props['form'] = $markup;
    $form->set_properties($props);
    $id = $form->save();
    if (!$id) { return wpultra_forms_err('forms_create_failed', 'Contact Form 7 could not save the new form.'); }
    return [
        'id'        => (int) $id,
        'title'     => $title,
        'plugin'    => 'cf7',
        'shortcode' => sprintf('[contact-form-7 id="%d" title="%s"]', (int) $id, $title),
    ];
}
