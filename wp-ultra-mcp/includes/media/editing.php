<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Media editing engine: resize/crop/rotate/flip/quality/convert via WP_Image_Editor,
 * plus bulk alt-text helpers. Reuses wpultra_media_shape() / wpultra_media_require_admin()
 * from includes/media/engine.php (loaded alongside this file by the bootstrap).
 */

/** Whitelist of convert-target formats and their mime types. Pure. */
function wpultra_media_edit_format_mimes(): array {
    return [
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];
}

/**
 * Pure: validate an ops array before any file I/O happens.
 * Returns true when every op is well-formed and in a supported op set, otherwise a
 * human-readable string describing the first problem found (unknown op, missing/bad
 * params, out-of-whitelist convert format, out-of-range quality, etc.).
 *
 * @param mixed $ops
 * @return true|string
 */
function wpultra_media_edit_validate_ops($ops) {
    if (!is_array($ops)) { return 'operations must be an array.'; }
    if ($ops === []) { return 'operations must contain at least one operation.'; }
    if (!array_is_list($ops)) { return 'operations must be a sequential (list) array.'; }

    $known = ['resize', 'crop', 'rotate', 'flip', 'quality', 'convert'];

    foreach ($ops as $i => $op) {
        if (!is_array($op)) { return "operations[$i] must be an object."; }
        $name = $op['op'] ?? null;
        if (!is_string($name) || $name === '') { return "operations[$i].op is required."; }
        if (!in_array($name, $known, true)) { return "operations[$i]: unknown op '$name'."; }

        switch ($name) {
            case 'resize':
                $w = $op['width'] ?? null;
                $h = $op['height'] ?? null;
                if ($w === null && $h === null) { return "operations[$i] (resize): at least one of width/height is required."; }
                foreach (['width' => $w, 'height' => $h] as $k => $v) {
                    if ($v !== null && (!is_int($v) || $v <= 0)) { return "operations[$i] (resize): $k must be a positive integer."; }
                }
                if (isset($op['crop']) && !is_bool($op['crop'])) { return "operations[$i] (resize): crop must be a boolean."; }
                break;

            case 'crop':
                foreach (['x', 'y', 'width', 'height'] as $k) {
                    if (!isset($op[$k])) { return "operations[$i] (crop): $k is required."; }
                    $v = $op[$k];
                    if (!is_int($v)) { return "operations[$i] (crop): $k must be an integer."; }
                    if (($k === 'width' || $k === 'height') && $v <= 0) { return "operations[$i] (crop): $k must be a positive integer."; }
                    if (($k === 'x' || $k === 'y') && $v < 0) { return "operations[$i] (crop): $k must be >= 0."; }
                }
                break;

            case 'rotate':
                if (!isset($op['degrees'])) { return "operations[$i] (rotate): degrees is required."; }
                $d = $op['degrees'];
                if (!is_int($d) && !is_float($d)) { return "operations[$i] (rotate): degrees must be numeric."; }
                break;

            case 'flip':
                $h2 = $op['horizontal'] ?? false;
                $v2 = $op['vertical'] ?? false;
                if (!is_bool($h2) || !is_bool($v2)) { return "operations[$i] (flip): horizontal/vertical must be booleans."; }
                if ($h2 === false && $v2 === false) { return "operations[$i] (flip): at least one of horizontal/vertical must be true."; }
                break;

            case 'quality':
                if (!isset($op['value'])) { return "operations[$i] (quality): value is required."; }
                $q = $op['value'];
                if (!is_int($q)) { return "operations[$i] (quality): value must be an integer."; }
                if ($q < 1 || $q > 100) { return "operations[$i] (quality): value must be between 1 and 100 (clamping info: values outside this range are rejected, not silently clamped)."; }
                break;

            case 'convert':
                $fmt = $op['format'] ?? null;
                if (!is_string($fmt) || $fmt === '') { return "operations[$i] (convert): format is required."; }
                if (!array_key_exists($fmt, wpultra_media_edit_format_mimes())) {
                    return "operations[$i] (convert): format must be one of: " . implode(', ', array_keys(wpultra_media_edit_format_mimes())) . '.';
                }
                break;
        }
    }

    return true;
}

/**
 * Pure: build the new basename for an edited copy.
 * $ext_or_empty: pass a new extension (without dot) to force a swap (e.g. from `convert`),
 * or '' to keep the original file's extension.
 * Result style: 'photo-edited-2.webp'.
 */
function wpultra_media_edit_suffix(string $filename, string $ext_or_empty, int $n): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $orig_ext = pathinfo($filename, PATHINFO_EXTENSION);
    $ext = $ext_or_empty !== '' ? $ext_or_empty : $orig_ext;
    $ext = strtolower($ext);
    $suffix = $n > 1 ? "-edited-$n" : '-edited';
    return $ext !== '' ? "{$base}{$suffix}.{$ext}" : "{$base}{$suffix}";
}

/**
 * Pure-ish: find the next available "-edited[-N]" basename in $existing_files (a list of
 * basenames already in the target directory), starting at N=1 ("-edited"), then 2, 3, ...
 * Kept separate from wpultra_media_edit_suffix() (which is a pure formatter) so the I/O-free
 * collision search stays independently testable.
 */
function wpultra_media_edit_next_suffix(string $filename, string $ext_or_empty, array $existing_files): string {
    $n = 1;
    while (true) {
        $candidate = $n === 1
            ? wpultra_media_edit_suffix($filename, $ext_or_empty, 0) // n=0/1 both mean plain "-edited"
            : wpultra_media_edit_suffix($filename, $ext_or_empty, $n);
        if (!in_array($candidate, $existing_files, true)) { return $candidate; }
        $n++;
    }
}

/**
 * Apply one already-validated op to a WP_Image_Editor instance.
 *
 * @return true|WP_Error
 */
function wpultra_media_edit_apply_one($editor, array $op) {
    $name = (string) $op['op'];
    switch ($name) {
        case 'resize':
            $w = isset($op['width']) ? (int) $op['width'] : null;
            $h = isset($op['height']) ? (int) $op['height'] : null;
            $crop = (bool) ($op['crop'] ?? false);
            return $editor->resize($w, $h, $crop);

        case 'crop':
            return $editor->crop((int) $op['x'], (int) $op['y'], (int) $op['width'], (int) $op['height']);

        case 'rotate':
            return $editor->rotate((float) $op['degrees']);

        case 'flip':
            return $editor->flip((bool) ($op['horizontal'] ?? false), (bool) ($op['vertical'] ?? false));

        case 'quality':
            return $editor->set_quality((int) $op['value']);

        case 'convert':
            // Format conversion is applied at save() time via the mime_type argument;
            // nothing to do against the editor instance itself here.
            return true;

        default:
            return wpultra_err('unknown_op', "Unknown op '$name'.");
    }
}

/**
 * Apply a validated list of ops to attachment $id, in order, and save either as a new
 * attachment (overwrite=false, default) or in place (overwrite=true).
 *
 * @return array|WP_Error
 */
function wpultra_media_edit_apply(int $id, array $ops, bool $overwrite = false) {
    if (get_post_type($id) !== 'attachment') { return wpultra_err('not_found', "No attachment with id $id."); }

    $valid = wpultra_media_edit_validate_ops($ops);
    if ($valid !== true) { return wpultra_err('bad_ops', $valid); }

    $file = get_attached_file($id);
    if (!$file || !file_exists($file)) { return wpultra_err('file_missing', "Attachment $id has no file on disk."); }

    wpultra_media_require_admin();
    if (!function_exists('wp_get_image_editor')) { return wpultra_err('media_unavailable', 'WordPress image editor helpers unavailable.'); }

    $editor = wp_get_image_editor($file);
    if (is_wp_error($editor)) { return $editor; }

    // Track the requested convert format (if any) — applied at save() time.
    $convert_format = null;
    foreach ($ops as $op) {
        if (($op['op'] ?? '') === 'convert') { $convert_format = (string) $op['format']; }
    }

    foreach ($ops as $op) {
        $result = wpultra_media_edit_apply_one($editor, $op);
        if (is_wp_error($result)) { return $result; }
    }

    $mime = $convert_format !== null ? (wpultra_media_edit_format_mimes()[$convert_format] ?? null) : null;
    $new_ext = $convert_format !== null ? $convert_format : '';

    if ($overwrite) {
        $saved = $mime ? $editor->save($file, $mime) : $editor->save($file);
        if (is_wp_error($saved)) { return $saved; }

        // If convert changed the extension, the saved file lives at a new path;
        // update the attached file + basic post fields to point at it.
        $new_path = (string) ($saved['path'] ?? $file);
        if ($new_path !== $file) {
            update_attached_file($id, $new_path);
            if ($mime) { wp_update_post(wp_slash(['ID' => $id, 'post_mime_type' => $mime])); }
            // Best-effort cleanup of the old file when the path actually changed.
            if (file_exists($file) && $file !== $new_path) { @unlink($file); }
        }

        if (function_exists('wp_generate_attachment_metadata')) {
            $meta = wp_generate_attachment_metadata($id, $new_path);
            wp_update_attachment_metadata($id, $meta);
        }

        return wpultra_media_shape($id);
    }

    // Save as a new attachment alongside the original, with a unique "-edited[-N]" name.
    $upload_dir = wp_upload_dir();
    $dir = trailingslashit((string) ($upload_dir['path'] ?? dirname($file)));
    $orig_basename = basename($file);

    $existing = [];
    if (is_dir($dir)) {
        $scanned = @scandir($dir);
        if (is_array($scanned)) { $existing = $scanned; }
    }
    $new_basename = wpultra_media_edit_next_suffix($orig_basename, $new_ext, $existing);
    $new_path = $dir . $new_basename;

    $saved = $mime ? $editor->save($new_path, $mime) : $editor->save($new_path);
    if (is_wp_error($saved)) { return $saved; }

    $saved_path = (string) ($saved['path'] ?? $new_path);
    $filetype = function_exists('wp_check_filetype') ? wp_check_filetype($saved_path) : ['type' => $mime];
    $orig_title = get_the_title($id);
    $attach = [
        'post_mime_type' => (string) ($filetype['type'] ?? $mime ?? get_post_mime_type($id)),
        'post_title'      => sanitize_text_field($orig_title !== '' ? $orig_title . ' (edited)' : pathinfo($saved_path, PATHINFO_FILENAME)),
        'post_status'    => 'inherit',
        'post_parent'    => (int) (get_post($id)->post_parent ?? 0),
    ];
    $new_id = wp_insert_attachment(wp_slash($attach), $saved_path, (int) $attach['post_parent'], true);
    if (is_wp_error($new_id)) { return $new_id; }

    if (function_exists('wp_generate_attachment_metadata')) {
        wp_update_attachment_metadata((int) $new_id, wp_generate_attachment_metadata((int) $new_id, $saved_path));
    }

    // Reuse original alt text on the new attachment.
    $orig_alt = get_post_meta($id, '_wp_attachment_image_alt', true);
    if ($orig_alt !== '') { update_post_meta((int) $new_id, '_wp_attachment_image_alt', (string) $orig_alt); }

    return wpultra_media_shape((int) $new_id);
}

/**
 * Find up to $limit image attachments whose alt text is empty/missing.
 *
 * @return array<int, array{id:int,url:string,title:string}>
 */
function wpultra_media_alt_missing(int $limit): array {
    $limit = max(1, min(100, $limit));
    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => $limit,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            'relation' => 'OR',
            ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
            ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
        ],
    ];
    $query = new WP_Query($args);
    return array_map(static function ($id) {
        $id = (int) $id;
        return [
            'id'    => $id,
            'url'   => (string) wp_get_attachment_url($id),
            'title' => get_the_title($id),
        ];
    }, $query->posts);
}

/**
 * Bulk-set alt text from an id => alt map. Returns counts + per-id results.
 *
 * @param array<int|string, string> $map
 */
function wpultra_media_alt_set(array $map): array {
    $updated = 0;
    $skipped = 0;
    $results = [];
    foreach ($map as $id => $alt) {
        $id = (int) $id;
        if ($id <= 0 || get_post_type($id) !== 'attachment') {
            $skipped++;
            $results[] = ['id' => $id, 'updated' => false, 'reason' => 'not_found'];
            continue;
        }
        update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $alt));
        $updated++;
        $results[] = ['id' => $id, 'updated' => true];
    }
    return ['updated' => $updated, 'skipped' => $skipped, 'items' => $results];
}
