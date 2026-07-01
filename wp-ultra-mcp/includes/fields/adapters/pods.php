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
    $is_options = $target['type'] === 'options';
    $id  = $is_options ? null : (int) $target['id'];
    $pod = ($pod_name !== '') ? pods($pod_name, $id) : false;
    $out = [];
    // Settings pods have NO row, so ->exists() is always false; do NOT gate them on it.
    // pods($name)->field() reads the option-backed value directly in Pods 3.x.
    if (!$pod || (!$is_options && !$pod->exists())) {
        // Non-settings edge cases: fall back to post meta for post targets.
        if ($target['type'] === 'post' && $fields) {
            foreach ($fields as $name) { $out[$name] = get_post_meta((int) $target['id'], $name, true); }
        }
        return $out;
    }
    if ($fields === null) {
        $data = $pod->export();
        return is_array($data) ? $data : [];
    }
    // Only ad-hoc WP-object wrappers (built-in Pods with no Pods definition) return single
    // non-schema meta as a 1-element list that we want to flatten. For a DEFINED pod a
    // 1-element list is a genuine single-item multi/relationship value — flattening it there
    // destabilizes read-modify-write, so we leave it intact.
    $is_defined = method_exists($pod, 'is_defined') ? (bool) $pod->is_defined() : false;
    foreach ($fields as $name) {
        $val = $format ? $pod->display($name) : $pod->field($name);
        if (!$is_defined && is_array($val) && count($val) === 1 && array_key_exists(0, $val)) {
            $val = $val[0];
        }
        $out[$name] = $val;
    }
    return $out;
}

/** @return array<string,array{status:string,error?:string}> */
function wpultra_fields_pods_write(array $target, array $atomic, array $complex): array {
    $pod_name = wpultra_fields_pods_name($target);
    $is_options = $target['type'] === 'options';
    $id  = $is_options ? null : (int) $target['id'];
    $pod = ($pod_name !== '') ? pods($pod_name, $id) : false;
    $pod_defined = ($pod && method_exists($pod, 'is_defined')) ? (bool) $pod->is_defined() : false;
    $res = [];
    $all = $atomic;
    foreach ($complex as $name => $wrap) { $all[$name] = $wrap['value']; }
    foreach ($all as $name => $value) {
        // Settings pods have NO row, so ->exists() is false; save against the defined pod
        // WITHOUT the exists() gate — pods($name)->save() writes the option-backed value in
        // Pods 3.x. (For non-options we still require exists() below.)
        if ($is_options && $pod_defined) {
            $pod->save($name, $value);
            $res[$name] = ['status' => 'ok'];
        // is_defined() (not exists()): exists() is true for any real post row even when
        // the Pod itself is only an ad-hoc WP-object wrapper (no Pods definition to save
        // against), which makes $pod->save() fatal via Pods' internal load_pod() lookup.
        } elseif ($pod && !$is_options && $pod->exists() && $pod_defined) {
            $pod->save($name, $value);
            $res[$name] = ['status' => 'ok'];
        } elseif ($target['type'] === 'post') {
            update_post_meta((int) $target['id'], $name, $value); // fallback until a Pod is registered (Plan 3)
            $res[$name] = ['status' => 'ok'];
        } elseif ($is_options) {
            $res[$name] = ['status' => 'error', 'error' => "no Pods settings pod registered for options group '{$pod_name}'"];
        } else {
            $res[$name] = ['status' => 'error', 'error' => 'no Pod registered for target'];
        }
    }
    return $res;
}

/** @return array<int,array> */
function wpultra_fields_pods_list_groups(): array {
    if (!function_exists('pods_api')) { return []; }
    $out = [];
    $pods = pods_api()->load_pods();
    foreach ((array) $pods as $p) {
        $name = is_array($p) ? ($p['name'] ?? '') : (is_object($p) ? ($p->pod ?? ($p->name ?? '')) : '');
        if ($name === '') { continue; }
        $fields = pods_api()->load_fields(['pod' => $name]);
        $out[] = ['key' => (string) $name, 'title' => (string) $name, 'provider' => 'pods', 'field_count' => is_array($fields) ? count($fields) : 0, 'location' => is_array($p) ? ($p['type'] ?? null) : null];
    }
    return $out;
}

/** @return array|WP_Error */
function wpultra_fields_pods_get_group(string $key) {
    if (!function_exists('pods_api')) { return new WP_Error('group_not_found', 'Pods not available'); }
    $pod = pods_api()->load_pod(['name' => $key]);
    if (!$pod) { return new WP_Error('group_not_found', "Pod not found: {$key}"); }
    $fields = pods_api()->load_fields(['pod' => $key]);
    $slim = [];
    foreach ((array) $fields as $f) {
        $fn = is_array($f) ? ($f['name'] ?? '') : (is_object($f) ? ($f->name ?? '') : '');
        $ft = is_array($f) ? ($f['type'] ?? '') : (is_object($f) ? ($f->type ?? '') : '');
        $slim[] = ['key' => (string) $fn, 'name' => (string) $fn, 'label' => (string) $fn, 'type' => (string) $ft];
    }
    return ['key' => $key, 'title' => $key, 'provider' => 'pods', 'fields' => $slim, 'location' => is_array($pod) ? ($pod['type'] ?? null) : null];
}

/**
 * Create/extend a Pod + its fields (mode create/update) or delete a field (mode delete).
 * payload: { pod: string, pod_type?: 'post_type'|'taxonomy'|'user'..., fields?: [{name,type,label?}], delete_field?: string }
 * @return array|WP_Error
 */
function wpultra_fields_pods_define(array $payload, string $mode) {
    if (!function_exists('pods_api')) { return new WP_Error('pods_unavailable', 'Pods is not active'); }
    $pod = (string) ($payload['pod'] ?? '');
    if ($pod === '') { return new WP_Error('pod_required', 'payload.pod is required'); }
    $api = pods_api();
    // PodsAPI signals errors by THROWING (pods_error), not by returning WP_Error, so every
    // call must be wrapped or a bad payload becomes an unstructured HTTP 500.
    try {
        if ($mode === 'delete') {
            $field = (string) ($payload['delete_field'] ?? '');
            if ($field === '') { return new WP_Error('field_required', 'delete requires payload.delete_field'); }
            $ok = $api->delete_field(['pod' => $pod, 'name' => $field]);
            if ($ok === false) { return new WP_Error('pods_delete_failed', "Could not delete field '{$field}' on pod '{$pod}'."); }
            return ['pod' => $pod, 'fields' => [], 'mode' => 'delete'];
        }
        // Ensure the pod exists (create it if a pod_type is given and it's absent).
        $existing = $api->load_pod(['name' => $pod]);
        if (!$existing) {
            $pod_type = (string) ($payload['pod_type'] ?? '');
            if ($pod_type === '') { return new WP_Error('pod_not_found', "Pod '{$pod}' does not exist; pass pod_type to create it."); }
            $saved = $api->save_pod(['name' => $pod, 'type' => $pod_type]);
            if (is_wp_error($saved)) { return $saved; }
        }
        $added = [];
        foreach ((array) ($payload['fields'] ?? []) as $f) {
            $name = (string) ($f['name'] ?? '');
            $type = (string) ($f['type'] ?? 'text');
            if ($name === '') { continue; }
            $res = $api->save_field(['pod' => $pod, 'name' => $name, 'type' => $type, 'label' => $f['label'] ?? $name]);
            if (is_wp_error($res)) { return new WP_Error('pods_field_failed', "Field '{$name}' failed; added so far: " . implode(', ', $added)); }
            $added[] = $name;
        }
        return ['pod' => $pod, 'fields' => $added, 'mode' => $mode];
    } catch (\Throwable $e) {
        return new WP_Error('pods_api_error', $e->getMessage());
    }
}
