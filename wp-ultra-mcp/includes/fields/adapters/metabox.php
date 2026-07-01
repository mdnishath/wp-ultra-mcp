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

/** @return array<string,array{status:string,error?:string}> */
function wpultra_fields_mb_write(array $target, array $atomic, array $complex): array {
    $ot  = wpultra_fields_mb_object_type($target['type']);
    $oid = $target['type'] === 'options' ? (string) $target['id'] : (int) $target['id'];
    $res = [];
    $all = $atomic;
    foreach ($complex as $name => $wrap) { $all[$name] = $wrap['value']; }
    foreach ($all as $fid => $value) {
        if ($ot === 'post' && function_exists('rwmb_set_meta')) {
            rwmb_set_meta((int) $oid, $fid, $value);
            $res[$fid] = ['status' => 'ok'];
        } else {
            // user/term/setting or MB not offering a setter: write metadata directly.
            $meta_type = $ot === 'setting' ? null : $ot;
            if ($meta_type) { update_metadata($meta_type, (int) $oid, $fid, $value); }
            else { update_option($fid, $value); }
            $res[$fid] = ['status' => 'ok'];
        }
    }
    return $res;
}

/** @return array<int,array> */
function wpultra_fields_metabox_list_groups(): array {
    $out = [];
    $seen = [];
    foreach (wpultra_fields_mb_stored_groups() as $g) {
        if (!is_array($g)) { continue; }
        $entry = wpultra_fields_mb_group_entry($g);
        $seen[$entry['key']] = true;
        $out[] = $entry;
    }
    foreach ((array) apply_filters('rwmb_meta_boxes', []) as $g) {
        if (!is_array($g)) { continue; }
        $entry = wpultra_fields_mb_group_entry($g);
        if (isset($seen[$entry['key']])) { continue; } // don't double-count our own filter output
        $out[] = $entry;
    }
    return $out;
}

/** @return array|WP_Error */
function wpultra_fields_metabox_get_group(string $key) {
    foreach (wpultra_fields_mb_stored_groups() as $g) {
        if (is_array($g) && (string) ($g['id'] ?? '') === $key) {
            $fields = [];
            foreach ((array) ($g['fields'] ?? []) as $f) {
                $fields[] = ['key' => $f['id'] ?? '', 'name' => $f['id'] ?? '', 'label' => $f['name'] ?? '', 'type' => $f['type'] ?? ''];
            }
            return ['key' => $key, 'title' => $g['title'] ?? $key, 'provider' => 'metabox', 'fields' => $fields, 'location' => $g['post_types'] ?? null];
        }
    }
    foreach ((array) apply_filters('rwmb_meta_boxes', []) as $g) {
        if (is_array($g) && (string) ($g['id'] ?? ($g['title'] ?? '')) === $key) {
            $fields = [];
            foreach ((array) ($g['fields'] ?? []) as $f) {
                $fields[] = ['key' => $f['id'] ?? '', 'name' => $f['id'] ?? '', 'label' => $f['name'] ?? '', 'type' => $f['type'] ?? ''];
            }
            return ['key' => $key, 'title' => $g['title'] ?? $key, 'provider' => 'metabox', 'fields' => $fields, 'location' => $g['post_types'] ?? null];
        }
    }
    return new WP_Error('group_not_found', "Meta Box field group not found: {$key}");
}
