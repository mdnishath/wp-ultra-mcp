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
function wpultra_fields_metabox_read(array $target, ?array $fields, bool $format): array {
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
    $have = function_exists('rwmb_meta');
    foreach ($fields as $fid) {
        // Detection may pass on RWMB_VER alone, but rwmb_meta() is a plugin function; guard it.
        $out[$fid] = $have ? rwmb_meta($fid, $args, $oid) : get_metadata($ot === 'setting' ? 'post' : $ot, (int) $oid, $fid, true);
    }
    return $out;
}

/** @return array<string,array{status:string,error?:string}> */
function wpultra_fields_metabox_write(array $target, array $atomic, array $complex): array {
    $ot  = wpultra_fields_mb_object_type($target['type']);
    $oid = $target['type'] === 'options' ? (string) $target['id'] : (int) $target['id'];
    $res = [];
    $all = $atomic;
    foreach ($complex as $name => $wrap) { $all[$name] = $wrap['value']; }
    // Meta Box settings pages store ALL fields inside one option array keyed by the
    // settings-page option_name ($oid) — collect the writes and persist once.
    $setting_bag = null;
    if ($ot === 'setting') { $setting_bag = get_option((string) $oid, []); if (!is_array($setting_bag)) { $setting_bag = []; } }
    $have_set = function_exists('rwmb_set_meta');
    foreach ($all as $fid => $value) {
        if ($ot === 'setting') {
            $setting_bag[$fid] = $value;
        } elseif ($have_set) {
            // post/user/term: rwmb_set_meta handles MB multiple/clone fields (which need
            // several meta rows) correctly; update_metadata would flatten them into one
            // serialized row. Term meta additionally needs the MB Term Meta extension.
            rwmb_set_meta((int) $oid, $fid, $value, ['object_type' => $ot]);
        } else {
            // No Meta Box helper available: write metadata directly (single-row fallback).
            update_metadata($ot, (int) $oid, $fid, $value);
        }
        $res[$fid] = ['status' => 'ok'];
    }
    if ($ot === 'setting') { update_option((string) $oid, $setting_bag); }
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
        // list_groups keys a group by id ?? title (via mb_group_entry); match the same way
        // here so a title-keyed group is fetchable.
        if (is_array($g) && (string) ($g['id'] ?? ($g['title'] ?? '')) === $key) {
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
