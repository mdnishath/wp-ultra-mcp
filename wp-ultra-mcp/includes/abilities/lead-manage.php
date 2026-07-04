<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/marketing/leads.php — require it defensively
// so this ability works regardless of bootstrap load order (mirrors woo-bulk-edit).
if (!function_exists('wpultra_leads_default_stages') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/marketing/leads.php')) {
    require_once WPULTRA_DIR . 'includes/marketing/leads.php';
}

wp_register_ability('wpultra/lead-manage', [
    'label'       => __('Leads: Capture + CRM-lite', 'wp-ultra-mcp'),
    'description' => __(
        'Lightweight CRM on a private wpultra_lead CPT: capture leads from forms, move them through pipeline stages, attach notes, export CSV. '
        . 'Actions: create {name?, email?, phone?, stage?, value?, tags?, source?, note?} (at least a name or an email; email is deduped — creating a second lead with the same email is blocked), '
        . 'get {id} (full record incl. all notes), '
        . 'list {stage?, search?, source?, limit?} (newest first; search matches name/email substring case-insensitively; source is a PREFIX match, e.g. "form:" = all form captures, "form:cf7" = only CF7; limit default 50 cap 200; notes trimmed to the last 5 per lead), '
        . 'update {id, name?, phone?, value?, tags?, source?, email?} (email change re-checks dedupe and is blocked when another lead owns it), '
        . 'set-stage {id, stage} (stage must be one of the configured pipeline slugs; logs an automatic note), '
        . 'add-note {id, text} (notes are chronological, newest last, capped at 100 — oldest dropped), '
        . 'delete {id, confirm:true} (permanent), '
        . 'export {stage?, search?, source?} (RFC-4180 CSV with header id,name,email,phone,stage,value,source,tags,created_at,notes_count; spreadsheet formula-injection guarded; cap 1000 rows), '
        . 'stats (pipeline overview: per-stage lead count + total value, grand totals), '
        . 'capture-config {enable?, stages?} (enable:true/false toggles automatic form capture via option wpultra_leads_capture — when enabled, submissions from Contact Form 7, WPForms, Gravity Forms and Fluent Forms are auto-captured (deduped by email; repeat submits add a note instead of a duplicate); stages replaces the pipeline stage list option wpultra_leads_stages; with no params it just reports current config + which form plugins are active). '
        . 'Pipeline stages default to: new, contacted, qualified, won, lost. value is a currency-agnostic float (deal size). '
        . 'Examples: {action:"create", name:"Rahim Uddin", email:"rahim@example.com", stage:"new", value:5000, tags:["plumber","dhaka"]} · '
        . '{action:"list", stage:"qualified", search:"rahim"} · {action:"set-stage", id:12, stage:"won"} · '
        . '{action:"export", source:"form:"} · {action:"capture-config", enable:true}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'marketing',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['create', 'get', 'list', 'update', 'set-stage', 'add-note', 'delete', 'export', 'stats', 'capture-config'],
            ],
            'id'     => ['type' => 'integer'],
            'name'   => ['type' => 'string'],
            'email'  => ['type' => 'string'],
            'phone'  => ['type' => 'string'],
            'stage'  => ['type' => 'string'],
            'value'  => ['type' => 'number'],
            'tags'   => ['type' => 'array', 'items' => ['type' => 'string']],
            'source' => ['type' => 'string'],
            'note'   => ['type' => 'string'],
            'text'   => ['type' => 'string'],
            'search' => ['type' => 'string'],
            'limit'  => ['type' => 'integer'],
            'enable' => ['type' => 'boolean'],
            'stages' => ['type' => 'array', 'items' => ['type' => 'string']],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'         => ['type' => 'boolean'],
            'lead'            => ['type' => 'object'],
            'leads'           => ['type' => 'array'],
            'count'           => ['type' => 'integer'],
            'csv'             => ['type' => 'string'],
            'stats'           => ['type' => 'object'],
            'stages'          => ['type' => 'array'],
            'capture_enabled' => ['type' => 'boolean'],
            'detected'        => ['type' => 'object'],
            'deleted'         => ['type' => 'boolean'],
            'note'            => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_lead_manage_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_lead_manage_ability(array $input) {
    if (!function_exists('wpultra_leads_default_stages')) {
        return wpultra_err('leads_engine_missing', 'The leads engine (includes/marketing/leads.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');
    $stages = wpultra_leads_stages();

    switch ($action) {
        case 'create':
            return wpultra_lead_action_create($input, $stages);
        case 'get':
            return wpultra_lead_action_get($input);
        case 'list':
        case 'export':
            return wpultra_lead_action_list_export($input, $action === 'export');
        case 'update':
            return wpultra_lead_action_update($input, $stages);
        case 'set-stage':
            return wpultra_lead_action_set_stage($input, $stages);
        case 'add-note':
            return wpultra_lead_action_add_note($input);
        case 'delete':
            return wpultra_lead_action_delete($input);
        case 'stats':
            $metas = array_map(static fn($it) => $it['meta'], wpultra_leads_query([], 1000));
            return wpultra_ok(['stats' => wpultra_leads_stats($metas, $stages), 'stages' => $stages]);
        case 'capture-config':
            return wpultra_lead_action_capture_config($input);
        default:
            return wpultra_err('unknown_action', "Unknown action '$action'. Known: create, get, list, update, set-stage, add-note, delete, export, stats, capture-config.");
    }
}

/** Resolve + load a lead by id. @return array{0:int,1:array}|WP_Error */
function wpultra_lead_require(array $input) {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) { return wpultra_err('missing_id', 'id is required for this action.'); }
    $meta = wpultra_leads_load($id);
    if ($meta === null) { return wpultra_err('lead_not_found', "No lead with id $id."); }
    return [$id, $meta];
}

/** @return array|WP_Error */
function wpultra_lead_action_create(array $input, array $stages) {
    $email = trim((string) ($input['email'] ?? ''));
    $name  = trim((string) ($input['name'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));

    $candidate = ['email' => $email, 'name' => $name];
    if (array_key_exists('stage', $input)) { $candidate['stage'] = (string) $input['stage']; }
    if (array_key_exists('value', $input)) { $candidate['value'] = $input['value']; }
    $valid = wpultra_leads_validate($candidate, $stages);
    if ($valid !== true) { return wpultra_err('invalid_lead', (string) $valid); }

    if ($email !== '') {
        $dupe = wpultra_leads_find_by_email($email);
        if ($dupe > 0) {
            return wpultra_err('duplicate_email', "A lead with email $email already exists (id $dupe). Use update/add-note on it instead.", ['id' => $dupe]);
        }
    }

    $now   = time();
    $stage = (string) ($input['stage'] ?? ($stages[0] ?? 'new'));
    $meta  = wpultra_leads_new_meta($email, $name, $phone, trim((string) ($input['source'] ?? 'manual')) ?: 'manual', $now, $stage);
    if (array_key_exists('value', $input) && is_numeric($input['value'])) { $meta['value'] = (float) $input['value']; }
    $meta['tags'] = wpultra_leads_normalize_tags($input['tags'] ?? []);
    $note = trim((string) ($input['note'] ?? ''));
    if ($note !== '') { $meta['notes'] = wpultra_leads_note_push($meta['notes'], wpultra_leads_trim_len($note, 2000), $now); }

    $id = wpultra_leads_insert($meta);
    if (is_wp_error($id)) {
        wpultra_audit_log('lead-manage', 'create failed: ' . $id->get_error_message(), false);
        return $id;
    }
    wpultra_audit_log('lead-manage', "create id=$id email=$email stage=$stage", true);
    return wpultra_ok(['lead' => wpultra_leads_shape($meta, $id, true)]);
}

/** @return array|WP_Error */
function wpultra_lead_action_get(array $input) {
    $r = wpultra_lead_require($input);
    if (is_wp_error($r)) { return $r; }
    [$id, $meta] = $r;
    return wpultra_ok(['lead' => wpultra_leads_shape($meta, $id, true)]);
}

/** list + export share the same filter pipeline. @return array|WP_Error */
function wpultra_lead_action_list_export(array $input, bool $export) {
    $filters = [
        'stage'  => (string) ($input['stage'] ?? ''),
        'search' => (string) ($input['search'] ?? ''),
        'source' => (string) ($input['source'] ?? ''),
    ];
    if ($filters['stage'] !== '') {
        // Filtering on a stage nothing can have is almost always a typo — surface it.
        $stages = wpultra_leads_stages();
        if (!in_array($filters['stage'], $stages, true)) {
            return wpultra_err('unknown_stage', "Unknown stage '{$filters['stage']}'. Configured stages: " . implode(', ', $stages));
        }
    }

    $cap   = $export ? 1000 : 200;
    $limit = (int) ($input['limit'] ?? ($export ? 1000 : 50));
    $limit = max(1, min($cap, $limit));

    $items = array_slice(wpultra_leads_query($filters, 1000), 0, $limit);

    if (!$export) {
        $leads = [];
        foreach ($items as $it) { $leads[] = wpultra_leads_shape($it['meta'], $it['id'], false); }
        return wpultra_ok(['leads' => $leads, 'count' => count($leads)]);
    }

    $rows = [];
    foreach ($items as $it) {
        $s = wpultra_leads_shape($it['meta'], $it['id'], false);
        $rows[] = [
            'id'          => $s['id'],
            'name'        => $s['name'],
            'email'       => $s['email'],
            'phone'       => $s['phone'],
            'stage'       => $s['stage'],
            'value'       => $s['value'],
            'source'      => $s['source'],
            'tags'        => $s['tags'],
            'created_at'  => $s['created_at'] > 0 ? gmdate('Y-m-d H:i:s', $s['created_at']) : '',
            'notes_count' => $s['notes_count'],
        ];
    }
    wpultra_audit_log('lead-manage', 'export rows=' . count($rows), true);
    return wpultra_ok(['csv' => wpultra_leads_csv($rows), 'count' => count($rows)]);
}

/** @return array|WP_Error */
function wpultra_lead_action_update(array $input, array $stages) {
    $r = wpultra_lead_require($input);
    if (is_wp_error($r)) { return $r; }
    [$id, $meta] = $r;

    $changed = [];
    foreach (['name', 'phone', 'source'] as $k) {
        if (array_key_exists($k, $input)) { $meta[$k] = trim((string) $input[$k]); $changed[] = $k; }
    }
    if (array_key_exists('value', $input)) { $meta['value'] = $input['value']; $changed[] = 'value'; }
    if (array_key_exists('tags', $input)) { $meta['tags'] = wpultra_leads_normalize_tags($input['tags']); $changed[] = 'tags'; }

    if (array_key_exists('email', $input)) {
        $new_email = strtolower(trim((string) $input['email']));
        $current   = strtolower(trim((string) ($meta['email'] ?? '')));
        if ($new_email !== $current) {
            if ($new_email !== '') {
                $owner = wpultra_leads_find_by_email($new_email);
                if ($owner > 0 && $owner !== $id) {
                    return wpultra_err('email_taken', "Another lead (id $owner) already owns $new_email.", ['id' => $owner]);
                }
            }
            $meta['email'] = $new_email;
            $changed[] = 'email';
        }
    }

    if ($changed === []) { return wpultra_err('nothing_to_update', 'Provide at least one of: name, email, phone, value, tags, source.'); }

    $valid = wpultra_leads_validate(['email' => $meta['email'] ?? '', 'name' => $meta['name'] ?? '', 'value' => $meta['value'] ?? 0], $stages);
    if ($valid !== true) { return wpultra_err('invalid_lead', (string) $valid); }
    $meta['value'] = is_numeric($meta['value'] ?? null) ? (float) $meta['value'] : 0.0;

    $meta['updated_at'] = time();
    wpultra_leads_save($id, $meta);
    wpultra_audit_log('lead-manage', "update id=$id fields=" . implode(',', $changed), true);
    return wpultra_ok(['lead' => wpultra_leads_shape($meta, $id, true)]);
}

/** @return array|WP_Error */
function wpultra_lead_action_set_stage(array $input, array $stages) {
    $r = wpultra_lead_require($input);
    if (is_wp_error($r)) { return $r; }
    [$id, $meta] = $r;

    $stage = trim((string) ($input['stage'] ?? ''));
    if (!in_array($stage, $stages, true)) {
        return wpultra_err('unknown_stage', "Unknown stage '$stage'. Configured stages: " . implode(', ', $stages));
    }
    $old = (string) ($meta['stage'] ?? '');
    if ($old === $stage) {
        return wpultra_ok(['lead' => wpultra_leads_shape($meta, $id, true), 'note' => "Already in stage '$stage'."]);
    }
    $now = time();
    $meta['stage'] = $stage;
    $meta['notes'] = wpultra_leads_note_push(
        is_array($meta['notes'] ?? null) ? $meta['notes'] : [],
        "Stage: $old -> $stage",
        $now
    );
    $meta['updated_at'] = $now;
    wpultra_leads_save($id, $meta);
    wpultra_audit_log('lead-manage', "set-stage id=$id $old->$stage", true);
    return wpultra_ok(['lead' => wpultra_leads_shape($meta, $id, true)]);
}

/** @return array|WP_Error */
function wpultra_lead_action_add_note(array $input) {
    $r = wpultra_lead_require($input);
    if (is_wp_error($r)) { return $r; }
    [$id, $meta] = $r;

    $text = trim((string) ($input['text'] ?? ''));
    if ($text === '') { return wpultra_err('missing_text', 'text is required for add-note.'); }
    $now = time();
    $meta['notes'] = wpultra_leads_note_push(
        is_array($meta['notes'] ?? null) ? $meta['notes'] : [],
        wpultra_leads_trim_len($text, 2000),
        $now
    );
    $meta['updated_at'] = $now;
    wpultra_leads_save($id, $meta);
    wpultra_audit_log('lead-manage', "add-note id=$id", true);
    return wpultra_ok(['lead' => wpultra_leads_shape($meta, $id, true)]);
}

/** @return array|WP_Error */
function wpultra_lead_action_delete(array $input) {
    $r = wpultra_lead_require($input);
    if (is_wp_error($r)) { return $r; }
    [$id, $meta] = $r;

    if (($input['confirm'] ?? false) !== true) {
        return wpultra_err('delete_unconfirmed', "Deleting lead $id is permanent. Re-run with confirm: true.");
    }
    $res = wp_delete_post($id, true);
    $ok  = (bool) $res;
    wpultra_audit_log('lead-manage', "delete id=$id email=" . (string) ($meta['email'] ?? ''), $ok);
    if (!$ok) { return wpultra_err('delete_failed', "Could not delete lead $id."); }
    return wpultra_ok(['deleted' => true, 'count' => 1]);
}

/** @return array|WP_Error */
function wpultra_lead_action_capture_config(array $input) {
    $changed = [];

    if (array_key_exists('enable', $input)) {
        $enable = $input['enable'] === true;
        update_option('wpultra_leads_capture', $enable ? '1' : '0', false);
        $changed[] = 'capture=' . ($enable ? 'on' : 'off');
    }

    if (array_key_exists('stages', $input)) {
        $st = [];
        foreach ((array) $input['stages'] as $s) {
            $s = strtolower(trim((string) $s));
            $s = (string) preg_replace('/[^a-z0-9_\-]/', '', $s);
            if ($s !== '' && !in_array($s, $st, true)) { $st[] = $s; }
        }
        if ($st === []) { return wpultra_err('invalid_stages', 'stages must contain at least one non-empty slug (a-z, 0-9, -, _).'); }
        update_option('wpultra_leads_stages', $st, false);
        $changed[] = 'stages=' . implode(',', $st);
    }

    if ($changed !== []) {
        wpultra_audit_log('lead-manage', 'capture-config ' . implode(' ', $changed), true);
    }

    $enabled = get_option('wpultra_leads_capture') === '1';
    $note = '';
    if (in_array('capture=on', $changed, true)) {
        $note = 'Form-capture hooks register at boot — capture starts from the next page load.';
    }
    return wpultra_ok([
        'capture_enabled' => $enabled,
        'detected'        => wpultra_leads_detected_form_plugins(),
        'stages'          => wpultra_leads_stages(),
        'note'            => $note,
    ]);
}
