<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Convert a canonical target to ACF's polymorphic post_id string. */
function wpultra_fields_acf_target(array $target): string {
    switch ($target['type']) {
        case 'user':    return 'user_' . (int) $target['id'];
        case 'term':    return 'term_' . (int) $target['id'];
        case 'options': return $target['id'] === '' ? 'options' : (string) $target['id'];
        default:        return (string) (int) $target['id']; // post
    }
}

/**
 * @param array|null $fields  field names/keys, or null for "all fields on target"
 * @return array<string,mixed>
 */
function wpultra_fields_acf_read(array $target, ?array $fields, bool $format): array {
    $acf_id = wpultra_fields_acf_target($target);
    $out = [];
    if ($fields === null) {
        // get_field_objects returns all fields whose location applies to the target.
        $objs = function_exists('get_field_objects') ? get_field_objects($acf_id, $format) : [];
        if (is_array($objs)) {
            foreach ($objs as $name => $obj) { $out[$name] = $obj['value'] ?? null; }
        }
        return $out;
    }
    foreach ($fields as $name) {
        $out[$name] = function_exists('get_field') ? get_field($name, $acf_id, $format) : null;
    }
    return $out;
}

/**
 * @param array<string,mixed> $atomic
 * @param array<string,array{value:mixed,mode:string}> $complex
 * @return array<string,array{status:string,error?:string}>
 */
function wpultra_fields_acf_write(array $target, array $atomic, array $complex): array {
    $acf_id = wpultra_fields_acf_target($target);
    $res = [];
    $have = function_exists('update_field');
    foreach ($atomic as $name => $value) {
        if ($have) { update_field($name, $value, $acf_id); }
        // update_field returns false when the value is unchanged; not an error, so report ok.
        $res[$name] = ['status' => 'ok'];
    }
    foreach ($complex as $name => $wrap) {
        if ($have) { update_field($name, $wrap['value'], $acf_id); }
        $res[$name] = ['status' => 'ok'];
    }
    return $res;
}

/** @return array<int,array> */
function wpultra_fields_acf_list_groups(): array {
    if (!function_exists('acf_get_field_groups')) { return []; }
    $out = [];
    foreach (acf_get_field_groups() as $g) {
        $fields = function_exists('acf_get_fields') ? (acf_get_fields($g) ?: []) : [];
        $out[] = [
            'key'         => (string) ($g['key'] ?? ''),
            'title'       => (string) ($g['title'] ?? ''),
            'provider'    => 'acf',
            'field_count' => count($fields),
            'location'    => $g['location'] ?? null,
        ];
    }
    return $out;
}

/** @return array|WP_Error */
function wpultra_fields_acf_get_group(string $key) {
    if (!function_exists('acf_get_field_group')) { return new WP_Error('group_not_found', 'ACF not available'); }
    $g = acf_get_field_group($key);
    if (!$g) { return new WP_Error('group_not_found', "ACF field group not found: {$key}"); }
    $fields = function_exists('acf_get_fields') ? (acf_get_fields($g) ?: []) : [];
    $slim = [];
    foreach ($fields as $f) {
        $slim[] = ['key' => $f['key'] ?? '', 'name' => $f['name'] ?? '', 'label' => $f['label'] ?? '', 'type' => $f['type'] ?? ''];
    }
    return ['key' => $g['key'] ?? $key, 'title' => $g['title'] ?? '', 'provider' => 'acf', 'fields' => $slim, 'location' => $g['location'] ?? null];
}

/**
 * Recursively scan a fields[] tree for a Pro-only field type, descending into a group's
 * sub_fields and a flexible_content layout's sub_fields.
 * @return string|null  the offending type, or null if none found
 */
function wpultra_fields_acf_find_pro_type(array $fields, array $pro_types): ?string {
    foreach ($fields as $f) {
        if (!is_array($f)) { continue; }
        $type = (string) ($f['type'] ?? '');
        if (in_array($type, $pro_types, true)) { return $type; }
        // group / clone / repeater nest their children under sub_fields.
        if (!empty($f['sub_fields']) && is_array($f['sub_fields'])) {
            $nested = wpultra_fields_acf_find_pro_type($f['sub_fields'], $pro_types);
            if ($nested !== null) { return $nested; }
        }
        // flexible_content nests sub_fields under each layout.
        if (!empty($f['layouts']) && is_array($f['layouts'])) {
            foreach ($f['layouts'] as $layout) {
                if (is_array($layout) && !empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                    $nested = wpultra_fields_acf_find_pro_type($layout['sub_fields'], $pro_types);
                    if ($nested !== null) { return $nested; }
                }
            }
        }
    }
    return null;
}

/**
 * Create/update/delete an ACF field group from a native-export payload.
 * @return array|WP_Error
 */
function wpultra_fields_acf_define_group(array $payload, string $mode) {
    if (!function_exists('acf_import_field_group')) { return new WP_Error('acf_unavailable', 'ACF is not active'); }
    if ($mode === 'delete') {
        $key = (string) ($payload['key'] ?? '');
        if ($key === '') { return new WP_Error('key_required', 'delete requires payload.key'); }
        $g = acf_get_field_group($key);
        if (!$g) { return new WP_Error('group_not_found', "ACF group not found: {$key}"); }
        // acf_delete_field_group() only removes DB-backed groups by numeric post ID; a
        // local-JSON/PHP-registered group has ID=0 and passing that deletes nothing (or the
        // wrong post) while still reporting success. Refuse it explicitly.
        $gid = (int) ($g['ID'] ?? 0);
        if ($gid <= 0) {
            return new WP_Error('group_not_deletable', "ACF group '{$key}' is registered via local JSON/PHP (no DB row) and cannot be deleted from the database. Remove its acf-json file or PHP registration instead.");
        }
        $deleted = acf_delete_field_group($gid);
        if ($deleted === false) {
            return new WP_Error('acf_delete_failed', "Could not delete ACF field group '{$key}'.");
        }
        return ['key' => $key, 'id' => $gid, 'mode' => 'delete'];
    }
    if (empty($payload['title'])) { return new WP_Error('title_required', 'payload.title is required'); }
    // Reject ACF-Pro-only field types on the free edition (they silently drop otherwise).
    $edition = (class_exists('acf_pro') || defined('ACF_PRO')) ? 'pro' : 'free';
    if ($edition === 'free') {
        // NB: 'group' is FREE (since ACF 5.6) and must NOT be here. Pro types can also hide
        // inside a free group's sub_fields or a flexible_content layout, so recurse.
        $pro_types = ['repeater', 'flexible_content', 'gallery', 'clone'];
        $bad = wpultra_fields_acf_find_pro_type((array) ($payload['fields'] ?? []), $pro_types);
        if ($bad !== null) {
            return new WP_Error('pro_field_type', "Field type '{$bad}' requires ACF Pro (this site runs ACF free).");
        }
    }
    if (empty($payload['key'])) { $payload['key'] = 'group_' . substr(md5($payload['title'] . wp_rand()), 0, 13); }
    if (!isset($payload['location'])) { $payload['location'] = []; }
    $result = acf_import_field_group($payload); // returns the imported group array (with ID)
    if (!is_array($result)) { return new WP_Error('acf_import_failed', 'acf_import_field_group did not return a group'); }
    return ['key' => (string) ($result['key'] ?? $payload['key']), 'id' => (int) ($result['ID'] ?? 0), 'mode' => $mode];
}
