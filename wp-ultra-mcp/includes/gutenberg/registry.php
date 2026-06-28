<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_gb_is_registered(string $name): bool {
    return \WP_Block_Type_Registry::get_instance()->is_registered($name);
}

function wpultra_gb_list_block_types(string $search = '', string $category = ''): array {
    $all = \WP_Block_Type_Registry::get_instance()->get_all_registered();
    $search = strtolower(trim($search));
    $out = [];
    foreach ($all as $name => $bt) {
        $title = (string) ($bt->title ?? '');
        if ($category !== '' && (string) ($bt->category ?? '') !== $category) { continue; }
        if ($search !== '' && strpos(strtolower($name . ' ' . $title), $search) === false) { continue; }
        $out[] = [
            'name'     => (string) $name,
            'title'    => $title,
            'category' => (string) ($bt->category ?? ''),
            'parent'   => is_array($bt->parent ?? null) ? $bt->parent : [],
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

function wpultra_gb_block_schema(string $name) {
    $bt = \WP_Block_Type_Registry::get_instance()->get_registered($name);
    if (!$bt) { return new WP_Error('block_type_not_found', "Block type '$name' is not registered."); }
    return [
        'name'        => (string) $bt->name,
        'title'       => (string) ($bt->title ?? ''),
        'category'    => (string) ($bt->category ?? ''),
        'description' => (string) ($bt->description ?? ''),
        'attributes'  => is_array($bt->attributes ?? null) ? $bt->attributes : [],
        'supports'    => is_array($bt->supports ?? null) ? $bt->supports : [],
        'parent'      => is_array($bt->parent ?? null) ? $bt->parent : [],
    ];
}
