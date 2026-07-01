<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** The persisted Meta Box groups map (id => MB group config array). */
function wpultra_fields_mb_stored_groups(): array {
    $v = get_option('wpultra_mb_groups', []);
    return is_array($v) ? $v : [];
}

/** Normalize a raw MB group config to a list entry. Pure. */
function wpultra_fields_mb_group_entry(array $g): array {
    $fields = isset($g['fields']) && is_array($g['fields']) ? $g['fields'] : [];
    return [
        'key'         => (string) ($g['id'] ?? ($g['title'] ?? '')),
        'title'       => (string) ($g['title'] ?? ($g['id'] ?? '')),
        'provider'    => 'metabox',
        'field_count' => count($fields),
        'location'    => $g['post_types'] ?? ($g['taxonomies'] ?? null),
    ];
}

/** Upsert or delete a Meta Box group in the persisted option. @return array|WP_Error */
function wpultra_fields_mb_save_group(array $config, string $mode) {
    $groups = wpultra_fields_mb_stored_groups();
    $id = (string) ($config['id'] ?? '');
    if ($id === '' || !preg_match('/^[a-z0-9_]+$/i', $id)) {
        return new WP_Error('id_invalid', 'config.id is required and must match [a-z0-9_]+');
    }
    if ($mode === 'delete') {
        unset($groups[$id]);
        update_option('wpultra_mb_groups', $groups, false);
        return ['id' => $id, 'mode' => 'delete', 'count' => count($groups)];
    }
    if (empty($config['title'])) { return new WP_Error('title_required', 'config.title is required'); }
    if (empty($config['fields']) || !is_array($config['fields'])) { return new WP_Error('fields_required', 'config.fields[] is required'); }
    // Minimal shape guard: each field needs id + type.
    foreach ($config['fields'] as $f) {
        if (empty($f['id']) || empty($f['type'])) { return new WP_Error('field_invalid', 'each field needs id and type'); }
    }
    if (empty($config['post_types'])) { $config['post_types'] = ['post']; }
    $groups[$id] = $config;
    update_option('wpultra_mb_groups', $groups, false);
    return ['id' => $id, 'mode' => 'upsert', 'count' => count($groups)];
}

/** rwmb_meta_boxes filter callback: register all stored groups. */
function wpultra_fields_mb_register_groups(array $mb): array {
    foreach (wpultra_fields_mb_stored_groups() as $g) {
        if (is_array($g) && !empty($g['fields'])) { $mb[] = $g; }
    }
    return $mb;
}
