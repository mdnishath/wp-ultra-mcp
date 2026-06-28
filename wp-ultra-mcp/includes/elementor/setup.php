<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_el_active(): bool {
    return class_exists('\\Elementor\\Plugin');
}

function wpultra_el_atomic_active(): bool {
    if (!wpultra_el_active()) { return false; }
    try {
        $p = \Elementor\Plugin::$instance;
        return isset($p->experiments) && $p->experiments->is_feature_active('e_atomic_elements');
    } catch (\Throwable $e) {
        return false;
    }
}

function wpultra_el_status(): array {
    return [
        'elementor' => wpultra_el_active(),
        'version'   => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
        'atomic'    => wpultra_el_atomic_active(),
    ];
}

/** Generate a 7-char element id, regenerating if it collides with one already in $tree. */
function wpultra_el_new_id(array $tree = []): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $id = '';
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $id = '';
        for ($i = 0; $i < 7; $i++) { $id .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        if ($tree === [] || !function_exists('wpultra_el_find') || wpultra_el_find($tree, $id) === null) { return $id; }
    }
    return $id;
}

function wpultra_el_require_atomic() {
    if (!wpultra_el_active()) {
        return wpultra_err('elementor_missing', 'Elementor is not installed/active on this site.');
    }
    if (!wpultra_el_atomic_active()) {
        return wpultra_err('atomic_inactive', 'Elementor v4 atomic elements are not active. Enable the "Editor V4 / atomic elements" experiment in Elementor > Settings > Features.');
    }
    return true;
}
