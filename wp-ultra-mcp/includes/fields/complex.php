<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * ACF Pro / SCF complex-field engine — nested read/write of repeater, flexible-content
 * and group fields.
 *
 * STORAGE FACTS (how ACF/SCF persist these in postmeta — documented for reference; the
 * engine ops below prefer the ACF API and only fall back to erroring when it is absent):
 *   - REPEATER: meta `{field}` holds the ROW COUNT (int). Each sub-value lives at
 *     `{field}_{index}_{subfield}` (0-based index). Field-key references are mirrored at
 *     `_{field}` and `_{field}_{index}_{subfield}`.
 *   - GROUP: sub-values live at `{field}_{subfield}` (no index; a group is a single row).
 *   - FLEXIBLE CONTENT: like a repeater, but `{field}` holds a serialized array of the
 *     per-row layout names and every returned row carries an `acf_fc_layout` key naming its
 *     layout. update_field() writes each row's layout from that key.
 *
 * The ACF API (get_field / update_field) is the compatible path across ACF Pro AND Secure
 * Custom Fields (SCF — the wp.org ACF fork that ships repeater/flexible/group for FREE and
 * defines class ACF + the same function surface). We code to the API, degrading with a clear
 * error when function_exists('get_field') is false, rather than poking postmeta directly.
 */

// ---------------------------------------------------------------------------
// PURE functions (no WordPress) — the unit-tested core.
// ---------------------------------------------------------------------------

/**
 * Detect the ACF complex-field KIND from a loaded value.
 *   - list of assoc-arrays, any row containing 'acf_fc_layout'  → 'flexible'
 *   - list of assoc-arrays (rows), none with a layout marker    → 'repeater'
 *   - plain (non-list) assoc-array                              → 'group'
 *   - anything else (scalar, empty, list of scalars)            → 'scalar'
 *
 * @param mixed $value
 */
function wpultra_fields_rows_detect_kind($value): string {
    if (!is_array($value) || $value === []) { return 'scalar'; }
    if (array_is_list($value)) {
        // A list is only a rows structure if every element is an assoc-array (a row).
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) { return 'scalar'; }
        }
        foreach ($value as $row) {
            if (array_key_exists('acf_fc_layout', $row)) { return 'flexible'; }
        }
        return 'repeater';
    }
    // Non-list array = associative = a single group value.
    return 'group';
}

/**
 * Pure row-mutation engine shared by add / update / delete / replace.
 * Operates on a plain rows array (list of assoc rows) and returns the new rows array.
 * Bounds are CLAMPED for add (insert position), but out-of-range update/delete THROW so
 * callers surface a clear error instead of silently mutating the wrong row.
 *
 * @param array<int,array<string,mixed>> $rows   current rows (list of assoc rows)
 * @param string                         $op      one of add|update|delete|replace
 * @param int|null                       $index   target index for add/update/delete
 * @param array<string,mixed>|null       $row     row payload for add/update/replace
 * @param bool                           $merge   update: merge into existing row vs. replace it
 * @return array<int,array<string,mixed>>
 * @throws InvalidArgumentException on bad op / out-of-range index / bad payload
 */
function wpultra_fields_rows_splice(array $rows, string $op, ?int $index, ?array $row, bool $merge): array {
    // Re-index defensively so we always work on a clean 0-based list.
    $rows = array_values($rows);
    $count = count($rows);

    switch ($op) {
        case 'replace':
            if ($row === null) {
                throw new InvalidArgumentException("replace requires 'rows' (the full replacement set)");
            }
            // For replace, $row is the full new rows array (a list). Re-index it.
            return array_values($row);

        case 'add':
            if ($row === null) {
                throw new InvalidArgumentException("add requires 'row'");
            }
            // Clamp insert position into [0, count]. Null / negative → append.
            if ($index === null) {
                $at = $count;
            } else {
                $at = $index;
                if ($at < 0) { $at = 0; }
                if ($at > $count) { $at = $count; }
            }
            array_splice($rows, $at, 0, [$row]);
            return array_values($rows);

        case 'update':
            if ($index === null) {
                throw new InvalidArgumentException("update requires 'index'");
            }
            if ($row === null) {
                throw new InvalidArgumentException("update requires 'row'");
            }
            if ($index < 0 || $index >= $count) {
                throw new InvalidArgumentException("index {$index} out of range (have {$count} row(s))");
            }
            if ($merge) {
                $rows[$index] = array_merge($rows[$index], $row);
            } else {
                $rows[$index] = $row;
            }
            return array_values($rows);

        case 'delete':
            if ($index === null) {
                throw new InvalidArgumentException("delete requires 'index'");
            }
            if ($index < 0 || $index >= $count) {
                throw new InvalidArgumentException("index {$index} out of range (have {$count} row(s))");
            }
            array_splice($rows, $index, 1);
            return array_values($rows);
    }
    throw new InvalidArgumentException("unknown op '{$op}' (use add|update|delete|replace)");
}

// ---------------------------------------------------------------------------
// WordPress-facing engine ops. All go through the ACF/SCF API; degrade with
// wpultra_err('acf_unavailable', ...) when it is not loaded.
// ---------------------------------------------------------------------------

/** True when the ACF/SCF read+write API surface is present. */
function wpultra_fields_rows_api_ready(): bool {
    return function_exists('get_field') && function_exists('update_field');
}

/** Shared guard: returns WP_Error when the API is missing, else null. */
function wpultra_fields_rows_guard() {
    if (!wpultra_fields_rows_api_ready()) {
        return wpultra_err('acf_unavailable', 'ACF / Secure Custom Fields (get_field/update_field) is not active on this site.');
    }
    return null;
}

/**
 * Read the current complex value for a field and classify it.
 * Returns ['kind' => repeater|flexible|group|scalar, 'rows' => array] where for group the
 * 'rows' is the assoc value and for repeater/flexible it is the list of rows.
 *
 * @return array{kind:string,rows:array}|WP_Error
 */
function wpultra_fields_rows_get(int $post_id, string $field) {
    $guard = wpultra_fields_rows_guard();
    if ($guard !== null) { return $guard; }
    // Raw (unformatted) load: repeater/flex/group come back as arrays of stored sub-values,
    // which is exactly what we need to round-trip through update_field().
    $value = get_field($field, $post_id, false);
    if ($value === null || $value === false) {
        // No value yet (or unknown field). Treat as an empty rows set so add can seed it.
        return ['kind' => 'scalar', 'rows' => []];
    }
    if (!is_array($value)) {
        return wpultra_err('not_complex', "Field '{$field}' on post {$post_id} is a scalar, not a repeater/flexible/group.", ['value_type' => gettype($value)]);
    }
    return ['kind' => wpultra_fields_rows_detect_kind($value), 'rows' => $value];
}

/**
 * Replace ALL rows of a repeater/flexible field (or the whole group value) in one write.
 * @param array $rows  full replacement rows (list) or group assoc
 * @return array{updated:bool,count:int}|WP_Error
 */
function wpultra_fields_rows_set(int $post_id, string $field, array $rows) {
    $guard = wpultra_fields_rows_guard();
    if ($guard !== null) { return $guard; }
    // update_field returns false when the stored value is byte-identical (a no-op), which is
    // NOT an error — report success either way.
    update_field($field, $rows, $post_id);
    return ['updated' => true, 'count' => array_is_list($rows) ? count($rows) : 1];
}

/**
 * Insert one row at $at (null/out-of-range → clamped/appended). Read-modify-write.
 * Only valid for repeater/flexible fields (a group is a single row — use update).
 * @return array{updated:bool,count:int,index:int}|WP_Error
 */
function wpultra_fields_rows_add(int $post_id, string $field, array $row, ?int $at) {
    $current = wpultra_fields_rows_get($post_id, $field);
    if (is_wp_error($current)) { return $current; }
    if ($current['kind'] === 'group') {
        return wpultra_err('not_addable', "Field '{$field}' is a group (single row); use action=update to change its sub-values.");
    }
    try {
        $new = wpultra_fields_rows_splice($current['rows'], 'add', $at, $row, false);
    } catch (\InvalidArgumentException $e) {
        return wpultra_err('rows_invalid', $e->getMessage());
    }
    update_field($field, $new, $post_id);
    $index = ($at === null) ? count($new) - 1 : max(0, min($at, count($new) - 1));
    return ['updated' => true, 'count' => count($new), 'index' => $index];
}

/**
 * Patch a single row at $index. $merge=true keeps the row's other sub-values, false replaces
 * the whole row. For a group field, $index is ignored and the group assoc is patched.
 * @return array{updated:bool,count:int}|WP_Error
 */
function wpultra_fields_rows_update(int $post_id, string $field, int $index, array $row, bool $merge) {
    $current = wpultra_fields_rows_get($post_id, $field);
    if (is_wp_error($current)) { return $current; }
    if ($current['kind'] === 'group') {
        $group = is_array($current['rows']) ? $current['rows'] : [];
        $new = $merge ? array_merge($group, $row) : $row;
        update_field($field, $new, $post_id);
        return ['updated' => true, 'count' => 1];
    }
    try {
        $new = wpultra_fields_rows_splice($current['rows'], 'update', $index, $row, $merge);
    } catch (\InvalidArgumentException $e) {
        return wpultra_err('rows_invalid', $e->getMessage());
    }
    update_field($field, $new, $post_id);
    return ['updated' => true, 'count' => count($new)];
}

/**
 * Delete the row at $index from a repeater/flexible field.
 * @return array{updated:bool,count:int}|WP_Error
 */
function wpultra_fields_rows_delete(int $post_id, string $field, int $index) {
    $current = wpultra_fields_rows_get($post_id, $field);
    if (is_wp_error($current)) { return $current; }
    if ($current['kind'] === 'group') {
        return wpultra_err('not_deletable', "Field '{$field}' is a group (single row) — a row cannot be deleted from it.");
    }
    try {
        $new = wpultra_fields_rows_splice($current['rows'], 'delete', $index, null, false);
    } catch (\InvalidArgumentException $e) {
        return wpultra_err('rows_invalid', $e->getMessage());
    }
    update_field($field, $new, $post_id);
    return ['updated' => true, 'count' => count($new)];
}
