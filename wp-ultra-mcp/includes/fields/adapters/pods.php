<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Resolve the Pod name for a target (post→post_type, term→taxonomy, user→'user', options→id). */
function wpultra_fields_pods_name(array $target): string {
    switch ($target['type']) {
        case 'user':    return 'user';
        case 'term':    $t = get_term((int) $target['id']); return ($t && !is_wp_error($t)) ? $t->taxonomy : '';
        case 'options': return (string) $target['id'];
        default:        return (string) get_post_type((int) $target['id']);
    }
}

/** @return array<string,mixed> */
function wpultra_fields_pods_read(array $target, ?array $fields, bool $format): array {
    $pod_name = wpultra_fields_pods_name($target);
    $id  = $target['type'] === 'options' ? null : (int) $target['id'];
    $pod = ($pod_name !== '') ? pods($pod_name, $id) : false;
    $out = [];
    if (!$pod || !$pod->exists()) {
        // Options pods and edge cases: fall back to post meta for post targets.
        if ($target['type'] === 'post' && $fields) {
            foreach ($fields as $name) { $out[$name] = get_post_meta((int) $target['id'], $name, true); }
        }
        return $out;
    }
    if ($fields === null) {
        $data = $pod->export();
        return is_array($data) ? $data : [];
    }
    foreach ($fields as $name) {
        $val = $format ? $pod->display($name) : $pod->field($name);
        // Pods returns single non-schema meta on auto-wrapped built-in Pods as a
        // 1-element list; collapse to scalar for cross-provider parity with ACF/MB.
        // Leave multi-element / assoc arrays (real multi-value or repeatable fields) intact.
        if (is_array($val) && count($val) === 1 && array_key_exists(0, $val)) { $val = $val[0]; }
        $out[$name] = $val;
    }
    return $out;
}
