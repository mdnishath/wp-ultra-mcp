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
