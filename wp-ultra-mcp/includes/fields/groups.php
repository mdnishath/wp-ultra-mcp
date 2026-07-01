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
