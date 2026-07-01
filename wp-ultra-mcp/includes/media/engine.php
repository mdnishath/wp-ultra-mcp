<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Media library engine: sideload from URL, decode from base64, read/update/delete attachments.
 * WordPress' media helpers live in wp-admin/includes, which aren't loaded on REST requests —
 * every entry point pulls them in explicitly.
 */

/** Load the wp-admin media/file/image helpers if they aren't already available. */
function wpultra_media_require_admin(): void {
    foreach (['file.php', 'media.php', 'image.php'] as $f) {
        $p = ABSPATH . 'wp-admin/includes/' . $f;
        if (is_readable($p)) { require_once $p; }
    }
}

/** Pure: derive a safe-ish base filename from a URL or supplied name, with a fallback. */
function wpultra_media_pick_filename(string $source, string $fallback = 'upload'): string {
    $source = trim($source);
    if ($source === '') { return $fallback; }
    // Strip query string / fragment, then any scheme://host, then take the last path segment.
    $path = preg_replace('/[?#].*$/', '', $source);
    $path = preg_replace('#^[a-z][a-z0-9+.-]*://[^/]+#i', '', (string) $path);
    $base = basename((string) $path);
    $base = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $base);
    $base = trim((string) $base, '-.');
    return $base !== '' ? $base : $fallback;
}

/** Apply alt/title/caption/description to an existing attachment. */
function wpultra_media_apply_meta(int $id, array $meta): void {
    if (isset($meta['alt']))   { update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $meta['alt'])); }
    $post = [];
    if (isset($meta['title']))       { $post['post_title']   = sanitize_text_field((string) $meta['title']); }
    if (isset($meta['caption']))     { $post['post_excerpt'] = (string) $meta['caption']; }
    if (isset($meta['description'])) { $post['post_content'] = (string) $meta['description']; }
    if ($post) { $post['ID'] = $id; wp_update_post(wp_slash($post)); }
}

/** Shape an attachment for output. */
function wpultra_media_shape(int $id): array {
    return [
        'id'       => $id,
        'url'      => (string) wp_get_attachment_url($id),
        'title'    => get_the_title($id),
        'mime'     => (string) get_post_mime_type($id),
        'alt'      => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        'edit_url' => (string) get_edit_post_link($id, 'raw'),
    ];
}

/** @return array|WP_Error */
function wpultra_media_sideload_url(string $url, array $meta) {
    if (!preg_match('#^https?://#i', $url)) { return wpultra_err('bad_url', 'url must be http(s).'); }
    wpultra_media_require_admin();
    if (!function_exists('media_handle_sideload')) { return wpultra_err('media_unavailable', 'WordPress media helpers unavailable.'); }
    $tmp = download_url($url);
    if (is_wp_error($tmp)) { return $tmp; }
    $name = wpultra_media_pick_filename($meta['filename'] ?? $url, 'download');
    $file = ['name' => $name, 'tmp_name' => $tmp];
    $id = media_handle_sideload($file, (int) ($meta['attach_to_post'] ?? 0), $meta['title'] ?? null);
    if (is_wp_error($id)) { if (file_exists($tmp)) { @unlink($tmp); } return $id; }
    wpultra_media_apply_meta((int) $id, $meta);
    return wpultra_media_shape((int) $id);
}

/** @return array|WP_Error */
function wpultra_media_from_base64(string $b64, array $meta) {
    $data = base64_decode($b64, true);
    if ($data === false || $data === '') { return wpultra_err('bad_base64', 'data_base64 is not valid base64.'); }
    $name = wpultra_media_pick_filename($meta['filename'] ?? 'upload.bin', 'upload.bin');
    $upload = wp_upload_bits($name, null, $data);
    if (!empty($upload['error'])) { return wpultra_err('upload_failed', (string) $upload['error']); }
    $filetype = wp_check_filetype($upload['file']);
    if (empty($filetype['type'])) { @unlink($upload['file']); return wpultra_err('disallowed_type', "File type not permitted by WordPress for '$name'."); }
    $attach = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_text_field((string) ($meta['title'] ?? pathinfo($name, PATHINFO_FILENAME))),
        'post_status'    => 'inherit',
    ];
    $id = wp_insert_attachment(wp_slash($attach), $upload['file'], (int) ($meta['attach_to_post'] ?? 0), true);
    if (is_wp_error($id)) { @unlink($upload['file']); return $id; }
    wpultra_media_require_admin();
    if (function_exists('wp_generate_attachment_metadata')) {
        wp_update_attachment_metadata((int) $id, wp_generate_attachment_metadata((int) $id, $upload['file']));
    }
    wpultra_media_apply_meta((int) $id, $meta);
    return wpultra_media_shape((int) $id);
}

/** @return array|WP_Error */
function wpultra_media_get(int $id) {
    if (get_post_type($id) !== 'attachment') { return wpultra_err('not_found', "No attachment with id $id."); }
    return wpultra_media_shape($id);
}

/** @return array|WP_Error */
function wpultra_media_update(int $id, array $meta) {
    if (get_post_type($id) !== 'attachment') { return wpultra_err('not_found', "No attachment with id $id."); }
    wpultra_media_apply_meta($id, $meta);
    return wpultra_media_shape($id);
}

/** @return array|WP_Error */
function wpultra_media_delete(int $id, bool $force) {
    if (get_post_type($id) !== 'attachment') { return wpultra_err('not_found', "No attachment with id $id."); }
    $res = wp_delete_attachment($id, $force);
    if (!$res) { return wpultra_err('delete_failed', "Could not delete attachment $id."); }
    return ['id' => $id, 'deleted' => true];
}
