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

/** The experiment-state string Elementor treats as "active" (constant if loaded, literal otherwise). */
function wpultra_el_atomic_active_state(): string {
    return class_exists('\\Elementor\\Core\\Experiments\\Manager')
        ? \Elementor\Core\Experiments\Manager::STATE_ACTIVE
        : 'active';
}

/**
 * Persist the "Editor V4 / atomic elements" experiment as active. Returns true if the option is
 * now stored as active. NOTE: Elementor resolves experiment state once at boot, so a flip made
 * mid-request only takes effect on the NEXT request — callers must check wpultra_el_atomic_active()
 * separately for the current request. Every Elementor v4 ability needs this experiment on.
 */
function wpultra_el_atomic_enable(): bool {
    if (!function_exists('update_option')) { return false; }
    $state = wpultra_el_atomic_active_state();
    try {
        update_option('elementor_experiment-e_atomic_elements', $state);
    } catch (\Throwable $e) {
        return false;
    }
    return get_option('elementor_experiment-e_atomic_elements') === $state;
}

function wpultra_el_require_atomic() {
    if (!wpultra_el_active()) {
        return wpultra_err('elementor_missing', 'Elementor is not installed/active on this site.');
    }
    if (wpultra_el_atomic_active()) { return true; }
    // Atomic widgets are a hard requirement for every v4 ability the caller is invoking, so
    // auto-enable the experiment. Elementor only reads the new state on the next request, so if
    // it did not take effect this request, tell the caller to re-run rather than implying failure.
    $persisted = wpultra_el_atomic_enable();
    if (wpultra_el_atomic_active()) { return true; }
    if ($persisted) {
        return wpultra_err('atomic_enabling', 'Elementor v4 atomic elements has just been enabled for you — re-run this action (Elementor applies the experiment change on the next request).');
    }
    return wpultra_err('atomic_inactive', 'Elementor v4 atomic elements are not active and could not be auto-enabled. Enable the "Editor V4 / atomic elements" experiment in Elementor > Settings > Features.');
}
