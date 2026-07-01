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
        $out[$name] = get_field($name, $acf_id, $format);
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
    foreach ($atomic as $name => $value) {
        update_field($name, $value, $acf_id);
        // update_field returns false when the value is unchanged; not an error, so report ok.
        $res[$name] = ['status' => 'ok'];
    }
    foreach ($complex as $name => $wrap) {
        update_field($name, $wrap['value'], $acf_id);
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
