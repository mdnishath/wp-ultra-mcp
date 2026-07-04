<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Lead capture + CRM-lite engine (roadmap A3).
 *
 * Storage: private CPT `wpultra_lead` (public false, show_ui false).
 *   post_title       = lead name (or email when no name)
 *   meta _wpultra_lead        = {email, name, phone, source, stage, value, tags[], notes[], created_at, updated_at}
 *   meta _wpultra_lead_email  = lowercase email mirror for fast meta_query dedupe/search
 *
 * Pipeline stages come from option `wpultra_leads_stages` (array of slugs),
 * defaulting to wpultra_leads_default_stages().
 *
 * Form auto-capture: wpultra_leads_boot() (called by the controller) registers
 * the CPT on init and — ONLY when option `wpultra_leads_capture` == '1' —
 * hooks CF7 / WPForms / Gravity Forms / Fluent Forms submissions. Handlers
 * swallow ALL exceptions so a lead-capture bug can never break a form submit.
 *
 * PURE functions first (prefix wpultra_leads_, no WP calls — unit-tested by
 * tests/leads.test.php); thin WordPress wrappers after.
 */

if (!defined('WPULTRA_LEADS_CPT')) { define('WPULTRA_LEADS_CPT', 'wpultra_lead'); }

/* =====================================================================
 * PURE core — no WordPress calls (harness-loadable).
 * ===================================================================== */

/** Default pipeline stage slugs, in funnel order. Pure. */
function wpultra_leads_default_stages(): array {
    return ['new', 'contacted', 'qualified', 'won', 'lost'];
}

/** Lowercase helper that survives missing mbstring. Pure. */
function wpultra_leads_lc(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
}

/** Length-cap helper that survives missing mbstring. Pure. */
function wpultra_leads_trim_len(string $s, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/** Strict-ish email shape check (full-string match). Pure. */
function wpultra_leads_email_valid(string $email): bool {
    return (bool) preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email);
}

/**
 * Normalize a raw form-submission payload into a flat key=>string map.
 * Scalars are cast, first-level scalar array members are joined with ', ',
 * anything else is dropped. Capped at $max_keys keys / $max_len chars each
 * so a hostile form can't bloat the lead store. Pure.
 */
function wpultra_leads_flatten_fields(array $raw, int $max_keys = 40, int $max_len = 500): array {
    $out = [];
    foreach ($raw as $k => $v) {
        if (count($out) >= $max_keys) { break; }
        if (is_array($v)) {
            $parts = [];
            foreach ($v as $vv) { if (is_scalar($vv)) { $parts[] = (string) $vv; } }
            $v = implode(', ', $parts);
        } elseif (is_scalar($v) || $v === null) {
            $v = (string) $v;
        } else {
            continue; // objects/resources dropped
        }
        $out[(string) $k] = wpultra_leads_trim_len(trim($v), $max_len);
    }
    return $out;
}

/**
 * Field-mapping heuristics: given a flat key=>value map from any form plugin,
 * find {email, name, phone}. Email: value under a key containing email/e-mail
 * that matches the email regex, else the first value anywhere matching it.
 * Name: first non-empty value under a key containing "name" (not an email
 * key) — unicode/Bengali values pass through untouched. Phone: first key
 * containing phone/tel. Missing pieces come back as ''. Pure.
 */
function wpultra_leads_extract(array $fields): array {
    $email = '';
    $name  = '';
    $phone = '';
    $re = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    // Pass 1: keys that look like an email field (covers email, e-mail, your-email…).
    foreach ($fields as $k => $v) {
        $lk = wpultra_leads_lc((string) $k);
        $v  = trim((string) $v);
        if ($v === '') { continue; }
        if ((str_contains($lk, 'email') || str_contains($lk, 'e-mail')) && preg_match($re, $v, $m)) {
            $email = $m[0];
            break;
        }
    }
    // Pass 2: first value anywhere that contains an email.
    if ($email === '') {
        foreach ($fields as $v) {
            $v = trim((string) $v);
            if ($v !== '' && preg_match($re, $v, $m)) { $email = $m[0]; break; }
        }
    }

    foreach ($fields as $k => $v) {
        $lk = wpultra_leads_lc((string) $k);
        $v  = trim((string) $v);
        if ($v === '') { continue; }
        if ($name === '' && str_contains($lk, 'name') && !str_contains($lk, 'email')) { $name = $v; }
        if ($phone === '' && (str_contains($lk, 'phone') || str_contains($lk, 'tel'))) { $phone = $v; }
        if ($name !== '' && $phone !== '') { break; }
    }

    return ['email' => $email, 'name' => $name, 'phone' => $phone];
}

/**
 * Validate lead input against the configured stage list.
 * Rules: at least one of name/email; email shape when present; stage (when
 * present) must be a configured slug; value (when present) numeric >= 0.
 * Returns true, or an error string. Pure.
 */
function wpultra_leads_validate(array $in, array $stages) {
    $email = trim((string) ($in['email'] ?? ''));
    $name  = trim((string) ($in['name'] ?? ''));
    if ($email === '' && $name === '') {
        return 'Provide at least a name or an email.';
    }
    if ($email !== '' && !wpultra_leads_email_valid($email)) {
        return "Invalid email format: $email";
    }
    if (array_key_exists('stage', $in)) {
        $stage = (string) $in['stage'];
        if (!in_array($stage, $stages, true)) {
            return "Unknown stage '$stage'. Configured stages: " . implode(', ', $stages);
        }
    }
    if (array_key_exists('value', $in) && $in['value'] !== null && $in['value'] !== '') {
        if (!is_numeric($in['value'])) { return 'value must be numeric.'; }
        if ((float) $in['value'] < 0) { return 'value must be >= 0.'; }
    }
    return true;
}

/**
 * Append a note {at, text} to a notes list (newest LAST — chronological).
 * When the list exceeds $cap the OLDEST notes are dropped. Pure.
 */
function wpultra_leads_note_push(array $notes, string $text, int $now, int $cap = 100): array {
    $notes   = array_values($notes);
    $notes[] = ['at' => $now, 'text' => $text];
    if ($cap > 0 && count($notes) > $cap) { $notes = array_slice($notes, -$cap); }
    return $notes;
}

/** Normalize a tags input into a deduped list of trimmed strings (cap 30 tags / 50 chars). Pure. */
function wpultra_leads_normalize_tags($tags): array {
    if (!is_array($tags)) { return []; }
    $out = [];
    foreach ($tags as $t) {
        if (!is_scalar($t)) { continue; }
        $t = wpultra_leads_trim_len(trim((string) $t), 50);
        if ($t === '' || in_array($t, $out, true)) { continue; }
        $out[] = $t;
        if (count($out) >= 30) { break; }
    }
    return $out;
}

/**
 * One CSV cell, RFC-4180 escaped. Arrays (tags) join with '|'. Spreadsheet
 * formula-injection guard: cells starting with = + - @ get a leading '. Pure.
 */
function wpultra_leads_csv_cell($v): string {
    if (is_array($v)) { $v = implode('|', array_map('strval', $v)); }
    $s = (string) $v;
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) { $s = "'" . $s; }
    if (preg_match('/[",\r\n]/', $s)) { $s = '"' . str_replace('"', '""', $s) . '"'; }
    return $s;
}

/**
 * RFC-4180 CSV (CRLF line endings) with a fixed header row:
 * id,name,email,phone,stage,value,source,tags,created_at,notes_count
 * Each row is a map keyed by those columns (missing keys become ''). Pure.
 */
function wpultra_leads_csv(array $rows): string {
    $cols  = ['id', 'name', 'email', 'phone', 'stage', 'value', 'source', 'tags', 'created_at', 'notes_count'];
    $lines = [implode(',', $cols)];
    foreach ($rows as $r) {
        if (!is_array($r)) { continue; }
        $cells = [];
        foreach ($cols as $c) { $cells[] = wpultra_leads_csv_cell($r[$c] ?? ''); }
        $lines[] = implode(',', $cells);
    }
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Pipeline overview from a list of lead meta blobs: per-stage count + value,
 * plus totals. Leads whose stage is not in the configured list land in an
 * 'other' bucket (only present when non-empty). Values rounded to 2dp. Pure.
 */
function wpultra_leads_stats(array $leads_meta_list, array $stages): array {
    $per = [];
    foreach ($stages as $s) { $per[(string) $s] = ['count' => 0, 'value' => 0.0]; }
    $other = ['count' => 0, 'value' => 0.0];
    $tc = 0;
    $tv = 0.0;
    foreach ($leads_meta_list as $m) {
        if (!is_array($m)) { continue; }
        $stage = (string) ($m['stage'] ?? '');
        $val   = is_numeric($m['value'] ?? null) ? (float) $m['value'] : 0.0;
        $tc++;
        $tv += $val;
        if (isset($per[$stage])) {
            $per[$stage]['count']++;
            $per[$stage]['value'] += $val;
        } else {
            $other['count']++;
            $other['value'] += $val;
        }
    }
    foreach ($per as &$p) { $p['value'] = round($p['value'], 2); }
    unset($p);
    $out = ['stages' => $per, 'total_count' => $tc, 'total_value' => round($tv, 2)];
    if ($other['count'] > 0) {
        $other['value'] = round($other['value'], 2);
        $out['other'] = $other;
    }
    return $out;
}

/**
 * Canonical output shape for one lead. List mode ($full=false) trims notes to
 * the last 5 (newest last); get mode ($full=true) returns them all.
 * notes_count always reflects the full stored count. Pure.
 */
function wpultra_leads_shape(array $meta, int $id, bool $full = false): array {
    $notes = is_array($meta['notes'] ?? null) ? array_values($meta['notes']) : [];
    return [
        'id'          => $id,
        'name'        => (string) ($meta['name'] ?? ''),
        'email'       => (string) ($meta['email'] ?? ''),
        'phone'       => (string) ($meta['phone'] ?? ''),
        'stage'       => (string) ($meta['stage'] ?? ''),
        'value'       => is_numeric($meta['value'] ?? null) ? (float) $meta['value'] : 0.0,
        'source'      => (string) ($meta['source'] ?? ''),
        'tags'        => is_array($meta['tags'] ?? null) ? array_values(array_map('strval', $meta['tags'])) : [],
        'notes'       => $full ? $notes : array_slice($notes, -5),
        'notes_count' => count($notes),
        'created_at'  => (int) ($meta['created_at'] ?? 0),
        'updated_at'  => (int) ($meta['updated_at'] ?? 0),
    ];
}

/** Fresh meta blob for a brand-new lead. Email lowercased for dedupe. Pure. */
function wpultra_leads_new_meta(string $email, string $name, string $phone, string $source, int $now, string $stage = 'new'): array {
    return [
        'email'      => strtolower(trim($email)),
        'name'       => trim($name),
        'phone'      => trim($phone),
        'source'     => $source,
        'stage'      => $stage,
        'value'      => 0.0,
        'tags'       => [],
        'notes'      => [],
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

/**
 * Filter a list of {id, meta} items: exact stage, source PREFIX match
 * (so 'form:' matches every form capture), case-insensitive search substring
 * against name/email. Order preserved. Pure.
 */
function wpultra_leads_filter(array $items, array $filters): array {
    $stage  = trim((string) ($filters['stage'] ?? ''));
    $search = wpultra_leads_lc(trim((string) ($filters['search'] ?? '')));
    $source = trim((string) ($filters['source'] ?? ''));
    $out = [];
    foreach ($items as $it) {
        $m = is_array($it['meta'] ?? null) ? $it['meta'] : [];
        if ($stage !== '' && (string) ($m['stage'] ?? '') !== $stage) { continue; }
        if ($source !== '' && !str_starts_with((string) ($m['source'] ?? ''), $source)) { continue; }
        if ($search !== '') {
            $hay = wpultra_leads_lc((string) ($m['name'] ?? '') . ' ' . (string) ($m['email'] ?? ''));
            if (!str_contains($hay, $search)) { continue; }
        }
        $out[] = $it;
    }
    return $out;
}

/* =====================================================================
 * WordPress wrappers — CPT, persistence, dedupe, capture runtime.
 * ===================================================================== */

function wpultra_leads_register_cpt(): void {
    register_post_type(WPULTRA_LEADS_CPT, [
        'public'   => false,
        'show_ui'  => false,
        'show_in_rest' => false,
        'supports' => ['title'],
        'rewrite'  => false,
        'labels'   => ['name' => 'WP-Ultra Leads'],
    ]);
}

/** Configured stage slugs (option wpultra_leads_stages), falling back to the defaults. */
function wpultra_leads_stages(): array {
    $opt = function_exists('get_option') ? get_option('wpultra_leads_stages') : null;
    if (is_array($opt)) {
        $st = [];
        foreach ($opt as $s) {
            $s = strtolower(trim((string) $s));
            if ($s !== '' && !in_array($s, $st, true)) { $st[] = $s; }
        }
        if ($st !== []) { return $st; }
    }
    return wpultra_leads_default_stages();
}

/** Load a lead's meta blob. Null when the id is not a lead. */
function wpultra_leads_load(int $id): ?array {
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_LEADS_CPT) { return null; }
    $meta = get_post_meta($id, '_wpultra_lead', true);
    return is_array($meta) ? $meta : [];
}

/** Persist meta blob + email mirror; sync post_title (name, else email). */
function wpultra_leads_save(int $id, array $meta): void {
    update_post_meta($id, '_wpultra_lead', $meta);
    update_post_meta($id, '_wpultra_lead_email', strtolower(trim((string) ($meta['email'] ?? ''))));
    $name  = trim((string) ($meta['name'] ?? ''));
    $title = $name !== '' ? $name : (string) ($meta['email'] ?? '');
    $post  = get_post($id);
    if ($post && $post->post_title !== $title) {
        wp_update_post(['ID' => $id, 'post_title' => wp_slash($title)]);
    }
}

/** Find a lead id by email (via the _wpultra_lead_email mirror). 0 when none. */
function wpultra_leads_find_by_email(string $email): int {
    $email = strtolower(trim($email));
    if ($email === '' || !function_exists('get_posts')) { return 0; }
    $ids = get_posts([
        'post_type'        => WPULTRA_LEADS_CPT,
        'post_status'      => 'any',
        'numberposts'      => 1,
        'fields'           => 'ids',
        'meta_key'         => '_wpultra_lead_email',
        'meta_value'       => $email,
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);
    return !empty($ids) ? (int) $ids[0] : 0;
}

/** Insert a new lead post from a meta blob. @return int|WP_Error */
function wpultra_leads_insert(array $meta) {
    $name  = trim((string) ($meta['name'] ?? ''));
    $title = $name !== '' ? $name : (string) ($meta['email'] ?? '');
    $id = wp_insert_post([
        'post_type'   => WPULTRA_LEADS_CPT,
        'post_status' => 'publish',
        'post_title'  => wp_slash($title),
    ], true);
    if (is_wp_error($id)) { return $id; }
    wpultra_leads_save((int) $id, $meta);
    return (int) $id;
}

/**
 * Upsert by email (form capture path). Existing lead (deduped via the email
 * mirror) gets a "Form submission (source)" note + updated_at (name/phone
 * back-filled only when previously empty); a new lead starts at the first
 * configured stage with the given source. @return int|WP_Error lead id.
 */
function wpultra_leads_upsert(array $lead, string $source) {
    $email = strtolower(trim((string) ($lead['email'] ?? '')));
    if ($email === '') { return wpultra_err('missing_email', 'Lead upsert requires an email.'); }
    $now = time();

    $existing = wpultra_leads_find_by_email($email);
    if ($existing > 0) {
        $meta = wpultra_leads_load($existing);
        if ($meta === null) { $meta = []; }
        if (trim((string) ($meta['name'] ?? '')) === '' && trim((string) ($lead['name'] ?? '')) !== '') {
            $meta['name'] = trim((string) $lead['name']);
        }
        if (trim((string) ($meta['phone'] ?? '')) === '' && trim((string) ($lead['phone'] ?? '')) !== '') {
            $meta['phone'] = trim((string) $lead['phone']);
        }
        $meta['email'] = $email; // keep mirror consistent
        $meta['notes'] = wpultra_leads_note_push(
            is_array($meta['notes'] ?? null) ? $meta['notes'] : [],
            "Form submission ($source)",
            $now
        );
        $meta['updated_at'] = $now;
        wpultra_leads_save($existing, $meta);
        return $existing;
    }

    $stages = wpultra_leads_stages();
    $meta = wpultra_leads_new_meta(
        $email,
        (string) ($lead['name'] ?? ''),
        (string) ($lead['phone'] ?? ''),
        $source,
        $now,
        (string) ($stages[0] ?? 'new')
    );
    return wpultra_leads_insert($meta);
}

/**
 * Fetch newest-first leads and apply the pure filter. Scans up to $scan
 * posts (default 1000 — also the export cap) then filters in PHP because
 * stage/source live inside the serialized blob. Returns [{id, meta}, ...].
 */
function wpultra_leads_query(array $filters, int $scan = 1000): array {
    if (!function_exists('get_posts')) { return []; }
    $ids = get_posts([
        'post_type'        => WPULTRA_LEADS_CPT,
        'post_status'      => 'any',
        'numberposts'      => max(1, $scan),
        'orderby'          => 'date',
        'order'            => 'DESC',
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);
    $items = [];
    foreach ((array) $ids as $id) {
        $meta = wpultra_leads_load((int) $id);
        if ($meta !== null) { $items[] = ['id' => (int) $id, 'meta' => $meta]; }
    }
    return wpultra_leads_filter($items, $filters);
}

/** Which supported form plugins are currently active (for capture-config reporting). */
function wpultra_leads_detected_form_plugins(): array {
    return [
        'cf7'          => class_exists('WPCF7'),
        'wpforms'      => function_exists('wpforms'),
        'gravityforms' => class_exists('GFForms'),
        'fluentforms'  => function_exists('wpFluentForm') || defined('FLUENTFORM'),
    ];
}

/* ------------------------------------------------------------------ *
 * Form auto-capture handlers — every handler swallows ALL throwables:
 * a lead-capture bug must never break a visitor's form submission.
 * ------------------------------------------------------------------ */

/** Shared tail: extract {email,name,phone} from a flat map and upsert when an email was found. */
function wpultra_leads_capture_submit(array $fields, string $source): void {
    $x = wpultra_leads_extract($fields);
    if ($x['email'] === '') { return; } // no email — nothing to dedupe on, skip
    wpultra_leads_upsert(['email' => $x['email'], 'name' => $x['name'], 'phone' => $x['phone']], $source);
}

/** Contact Form 7: wpcf7_mail_sent($form). */
function wpultra_leads_capture_cf7($form): void {
    try {
        $data = [];
        if (class_exists('WPCF7_Submission')) {
            $sub = WPCF7_Submission::get_instance();
            if ($sub && method_exists($sub, 'get_posted_data')) {
                $data = (array) $sub->get_posted_data();
            }
        }
        $fid = (is_object($form) && method_exists($form, 'id')) ? (string) $form->id() : '0';
        wpultra_leads_capture_submit(wpultra_leads_flatten_fields($data), 'form:cf7:' . $fid);
    } catch (\Throwable $e) { /* never break a form submit */ }
}

/** WPForms: wpforms_process_complete($fields, $entry, $form_data). */
function wpultra_leads_capture_wpforms($fields, $entry, $form_data): void {
    try {
        $map = [];
        foreach ((array) $fields as $f) {
            if (!is_array($f)) { continue; }
            $k = trim((string) ($f['name'] ?? ''));
            if ($k === '') { $k = 'field_' . (string) ($f['id'] ?? count($map)); }
            $map[$k] = $f['value'] ?? '';
        }
        $fd  = (array) $form_data;
        $fid = (string) ($fd['id'] ?? '0');
        wpultra_leads_capture_submit(wpultra_leads_flatten_fields($map), 'form:wpforms:' . $fid);
    } catch (\Throwable $e) { /* never break a form submit */ }
}

/** Gravity Forms: gform_after_submission($entry, $form). */
function wpultra_leads_capture_gravity($entry, $form): void {
    try {
        $entry = (array) $entry;
        $fa    = (array) $form;
        $map   = [];
        foreach ((array) ($fa['fields'] ?? []) as $field) {
            $fid   = is_object($field) ? ($field->id ?? null) : (is_array($field) ? ($field['id'] ?? null) : null);
            $label = is_object($field) ? (string) ($field->label ?? '') : (is_array($field) ? (string) ($field['label'] ?? '') : '');
            if ($fid === null) { continue; }
            $val = (string) ($entry[(string) $fid] ?? '');
            if ($val === '') {
                // Composite fields (e.g. Name) store sub-inputs at "1.3", "1.6" …
                $parts = [];
                foreach ($entry as $k => $v) {
                    if (str_starts_with((string) $k, $fid . '.') && trim((string) $v) !== '') { $parts[] = (string) $v; }
                }
                $val = implode(' ', $parts);
            }
            $map[$label !== '' ? $label : ('field_' . $fid)] = $val;
        }
        if ($map === []) { $map = $entry; }
        $formId = (string) ($fa['id'] ?? '0');
        wpultra_leads_capture_submit(wpultra_leads_flatten_fields($map), 'form:gravityforms:' . $formId);
    } catch (\Throwable $e) { /* never break a form submit */ }
}

/** Fluent Forms: fluentform/submission_inserted($entryId, $formData, $form). */
function wpultra_leads_capture_fluent($entryId, $formData, $form): void {
    try {
        $fa  = (array) $form;
        $fid = is_object($form) ? (string) ($form->id ?? '0') : (string) ($fa['id'] ?? '0');
        wpultra_leads_capture_submit(wpultra_leads_flatten_fields((array) $formData), 'form:fluentforms:' . $fid);
    } catch (\Throwable $e) { /* never break a form submit */ }
}

/* ------------------------------------------------------------------ *
 * Boot — the controller calls this from the always-on runtime. Cheap:
 * CPT registration on init + capture hooks only when opted in.
 * ------------------------------------------------------------------ */

function wpultra_leads_boot(): void {
    if (!function_exists('add_action')) { return; }

    if (function_exists('did_action') && did_action('init')) {
        if (function_exists('register_post_type')) { wpultra_leads_register_cpt(); }
    } else {
        add_action('init', 'wpultra_leads_register_cpt');
    }

    $capture = function_exists('get_option') ? get_option('wpultra_leads_capture') : '';
    if ($capture !== '1') { return; }

    add_action('wpcf7_mail_sent', 'wpultra_leads_capture_cf7', 10, 1);
    add_action('wpforms_process_complete', 'wpultra_leads_capture_wpforms', 10, 3);
    add_action('gform_after_submission', 'wpultra_leads_capture_gravity', 10, 2);
    add_action('fluentform/submission_inserted', 'wpultra_leads_capture_fluent', 10, 3);
}
