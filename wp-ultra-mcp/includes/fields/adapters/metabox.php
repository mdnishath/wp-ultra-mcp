<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Map canonical target type to Meta Box object_type. */
function wpultra_fields_mb_object_type(string $type): string {
    return match ($type) {
        'user'    => 'user',
        'term'    => 'term',
        'options' => 'setting',
        default   => 'post',
    };
}

/** @return array<string,mixed> */
function wpultra_fields_mb_read(array $target, ?array $fields, bool $format): array {
    $ot  = wpultra_fields_mb_object_type($target['type']);
    $oid = $target['type'] === 'options' ? (string) $target['id'] : (int) $target['id'];
    $args = ['object_type' => $ot];
    $out = [];
    if ($fields === null) {
        // Collect field ids registered on this object type via the MB filter.
        $groups = apply_filters('rwmb_meta_boxes', []);
        $ids = [];
        foreach ((array) $groups as $g) {
            foreach ((array) ($g['fields'] ?? []) as $f) {
                if (!empty($f['id'])) { $ids[] = (string) $f['id']; }
            }
        }
        $fields = array_values(array_unique($ids));
    }
    foreach ($fields as $fid) {
        $out[$fid] = rwmb_meta($fid, $args, $oid);
    }
    return $out;
}
