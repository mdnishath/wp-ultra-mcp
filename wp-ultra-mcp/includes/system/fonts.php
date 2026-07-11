<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Font manager engine: upload font files -> persisted @font-face registry, plus a
 * Google-Fonts self-host helper (fetch Google's CSS2 output, download the actual
 * font files, and serve them from this site — no client requests to Google, so no
 * third-party-cookie/GDPR exposure).
 *
 * Storage: option `wpultra_fonts` = list of
 *   {id, family, source: 'upload'|'google-selfhost', faces: [{weight, style, url, format}]}
 * Font files live under wp-content/uploads/wpultra-fonts/.
 *
 * Runtime contract: wpultra_fonts_boot() is cheap + idempotent; it hooks wp_head
 * to print the generated @font-face CSS in a <style id="wpultra-fonts"> block so
 * registered fonts are usable by family name anywhere (Elementor, block editor,
 * theme CSS, Additional CSS) without re-declaring @font-face themselves. See the
 * PP2-A report for the recommended controller wiring.
 */

// ===========================================================================
// PURE FUNCTIONS (unit-tested in tests/fonts.test.php — no WordPress calls)
// ===========================================================================

/** Font-file extensions this ability accepts. Anything else is rejected outright. */
function wpultra_fonts_allowed_exts(): array {
    return ['woff2', 'woff', 'ttf', 'otf'];
}

/** Pure: map a font file extension to its CSS `src: url(...) format('...')` keyword. Null when unknown. */
function wpultra_fonts_face_format(string $ext): ?string {
    $ext = strtolower(ltrim(trim($ext), '.'));
    switch ($ext) {
        case 'woff2': return 'woff2';
        case 'woff':  return 'woff';
        case 'ttf':   return 'truetype';
        case 'otf':   return 'opentype';
        default:      return null;
    }
}

/**
 * Pure: validate a filename's extension against the font whitelist (woff2/woff/ttf/otf).
 * Rejects anything else — including a disguised upload like "shell.php", a
 * double-extension bypass like "font.woff2.php" (the LAST extension is what's
 * checked), or a file with no extension at all.
 *
 * @return true|WP_Error
 */
function wpultra_fonts_validate_ext(string $filename) {
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, wpultra_fonts_allowed_exts(), true)) {
        return wpultra_err(
            'bad_font_ext',
            "'" . $filename . "' is not an allowed font file. Allowed types: " . implode(', ', wpultra_fonts_allowed_exts()) . '.'
        );
    }
    return true;
}

/**
 * Pure: build the @font-face CSS block(s) for one font registry entry.
 * $font shape: {family, faces: [{weight?, style?, url, format?}]}.
 * Missing weight/style default to 400/normal. Always emits font-display: swap.
 * Strips '<'/'>' from every interpolated field so a malicious family/url value
 * can never break out of the <style id="wpultra-fonts"> block it's rendered in.
 */
function wpultra_fonts_fontface_css(array $font): string {
    $strip = static fn($s): string => str_replace(['<', '>'], '', (string) $s);
    // Escape backslash FIRST, then single quote — escaping the quote alone would let a
    // value ending in a backslash (e.g. "Evil\") turn the closing "\'" into an escaped
    // literal quote in CSS, so the string never terminates and the value breaks out of
    // the generated @font-face rule.
    $css_quote = static fn($s): string => str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $s);

    $family = $strip(trim((string) ($font['family'] ?? '')));
    $faces  = is_array($font['faces'] ?? null) ? $font['faces'] : [];
    if ($family === '' || $faces === []) { return ''; }

    $css = '';
    foreach ($faces as $face) {
        if (!is_array($face)) { continue; }
        $url = $strip(trim((string) ($face['url'] ?? '')));
        if ($url === '') { continue; }
        $format = $strip((string) ($face['format'] ?? ''));

        $weight_raw = $face['weight'] ?? 400;
        $weight = is_numeric($weight_raw) ? (string) (int) $weight_raw : preg_replace('/[^A-Za-z]/', '', (string) $weight_raw);
        if ($weight === '') { $weight = '400'; }

        $style = preg_replace('/[^A-Za-z]/', '', $strip(trim((string) ($face['style'] ?? ''))));
        if ($style === '') { $style = 'normal'; }

        $src = "url('" . $css_quote($url) . "')";
        if ($format !== '') { $src .= " format('" . $css_quote($format) . "')"; }

        $css .= "@font-face {\n";
        $css .= "  font-family: '" . $css_quote($family) . "';\n";
        $css .= "  src: $src;\n";
        $css .= "  font-weight: $weight;\n";
        $css .= "  font-style: $style;\n";
        $css .= "  font-display: swap;\n";
        $css .= "}\n";
    }
    return $css;
}

/**
 * Pure: parse Google Fonts CSS2-API output into {family, faces:[{weight,style,url,format}]}.
 * For each @font-face block, extracts font-family (first non-empty match wins — a
 * single css2 request always shares one family across every weight/style block),
 * font-weight, font-style, and the real font-file URL + format from its src
 * declaration (preferring the first http(s) url() over any local() fallback).
 * Blocks without a usable http(s) src are skipped. Empty/garbage input yields
 * {family: '', faces: []}.
 */
function wpultra_fonts_parse_google_css(string $css): array {
    $family = '';
    $faces = [];

    if (!preg_match_all('/@font-face\s*\{([^}]*)\}/is', $css, $blocks)) {
        return ['family' => $family, 'faces' => $faces];
    }

    foreach ($blocks[1] as $block) {
        if ($family === '' && preg_match('/font-family\s*:\s*[\'"]?([^;\'"}]+)[\'"]?\s*;/i', $block, $m)) {
            $family = trim($m[1]);
        }

        // src: can list multiple sources (local() fallbacks, etc.) — the first http(s)
        // url() is the actual downloadable font file; skip the block without one.
        if (!preg_match('/url\(\s*[\'"]?(https?:\/\/[^\'")\s]+)[\'"]?\s*\)(?:\s*format\(\s*[\'"]?([a-z0-9]+)[\'"]?\s*\))?/i', $block, $m)) {
            continue;
        }
        $url = $m[1];
        $format = isset($m[2]) ? strtolower($m[2]) : '';
        if ($format === '') {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $format = (string) (wpultra_fonts_face_format($ext) ?? '');
        }

        $weight = 400;
        if (preg_match('/font-weight\s*:\s*([0-9]+)/i', $block, $m)) { $weight = (int) $m[1]; }
        $style = 'normal';
        if (preg_match('/font-style\s*:\s*([a-z]+)/i', $block, $m)) { $style = strtolower($m[1]); }

        $faces[] = ['weight' => $weight, 'style' => $style, 'url' => $url, 'format' => $format];
    }

    return ['family' => $family, 'faces' => $faces];
}

/** Pure: build the Google Fonts CSS2 API URL for a family + list of weights (defaults to [400]). */
function wpultra_fonts_google_css_url(string $family, array $weights): string {
    $weights = array_values(array_unique(array_map('intval', $weights === [] ? [400] : $weights)));
    sort($weights);
    $encoded_family = str_replace('%20', '+', rawurlencode(trim($family)));
    return 'https://fonts.googleapis.com/css2?family=' . $encoded_family . ':wght@' . implode(';', $weights) . '&display=swap';
}

/**
 * Pure: resolve the font-file extension a face upload should be validated/stored
 * as — explicit `format` wins, otherwise it's inferred from `file_url`'s path.
 * Null when neither is usable.
 */
function wpultra_fonts_face_source_ext(array $face): ?string {
    if (!empty($face['format']) && is_string($face['format'])) {
        return strtolower(ltrim(trim($face['format']), '.'));
    }
    if (!empty($face['file_url']) && is_string($face['file_url'])) {
        $path = (string) parse_url($face['file_url'], PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : null;
    }
    return null;
}

// ===========================================================================
// WP WRAPPERS (guarded) — storage, fetch, actions
// ===========================================================================

/** Hard cap on a single font-face file, upload or Google download alike (~5MB). */
function wpultra_fonts_max_bytes(): int {
    return 5 * 1024 * 1024;
}

/** @return array{dir:string,url:string}|WP_Error the uploads subdir fonts live in, created on demand. */
function wpultra_fonts_storage_dir() {
    if (!function_exists('wp_upload_dir')) {
        return wpultra_err('uploads_unavailable', 'wp_upload_dir is not available.');
    }
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) { return wpultra_err('uploads_error', (string) $upload['error']); }
    $base_dir = trailingslashit((string) ($upload['basedir'] ?? ''));
    $base_url = trailingslashit((string) ($upload['baseurl'] ?? ''));
    if ($base_dir === '/') { return wpultra_err('uploads_error', 'Could not resolve the uploads base directory.'); }

    $dir = $base_dir . 'wpultra-fonts/';
    $url = $base_url . 'wpultra-fonts/';
    if (!is_dir($dir)) {
        $made = function_exists('wp_mkdir_p') ? wp_mkdir_p($dir) : @mkdir($dir, 0755, true);
        if (!$made) { return wpultra_err('mkdir_failed', "Could not create uploads subdir: $dir"); }
    }
    if (!is_writable($dir)) { return wpultra_err('not_writable', "Uploads dir not writable: $dir"); }
    return ['dir' => $dir, 'url' => $url];
}

function wpultra_fonts_options_get(): array {
    $fonts = get_option('wpultra_fonts', []);
    return is_array($fonts) ? $fonts : [];
}

function wpultra_fonts_options_save(array $fonts): void {
    update_option('wpultra_fonts', $fonts, false);
}

function wpultra_fonts_new_id(): string {
    return 'font_' . substr(md5(uniqid((string) wp_rand(), true)), 0, 12);
}

/** Fetch one face's raw bytes from file_url (wp_remote_get, size-capped) or base64. @return string|WP_Error */
function wpultra_fonts_face_bytes(array $face) {
    if (!empty($face['file_url'])) {
        $url = (string) $face['file_url'];
        if (!preg_match('#^https?://#i', $url)) { return wpultra_err('bad_url', 'file_url must be http(s).'); }
        if (!function_exists('wp_remote_get')) { return wpultra_err('http_unavailable', 'wp_remote_get is not available.'); }
        $resp = wp_remote_get($url, [
            'timeout'     => 20,
            'redirection' => 3,
            'sslverify'   => true,
            'user-agent'  => 'wp-ultra-mcp/manage-fonts',
        ]);
        if (is_wp_error($resp)) { return wpultra_err('fetch_failed', 'Could not fetch font file: ' . $resp->get_error_message()); }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) { return wpultra_err('fetch_failed', "Font fetch returned HTTP $code for $url"); }
        $body = (string) wp_remote_retrieve_body($resp);
        if ($body === '') { return wpultra_err('empty_response', "Empty response fetching $url"); }
        if (strlen($body) > wpultra_fonts_max_bytes()) {
            return wpultra_err('font_too_large', "Font file at $url exceeds the " . (int) (wpultra_fonts_max_bytes() / (1024 * 1024)) . 'MB cap.');
        }
        return $body;
    }
    if (!empty($face['base64'])) {
        $bin = base64_decode((string) $face['base64'], true);
        if ($bin === false || $bin === '') { return wpultra_err('bad_base64', 'base64 is not valid font data.'); }
        if (strlen($bin) > wpultra_fonts_max_bytes()) {
            return wpultra_err('font_too_large', 'Decoded font data exceeds the ' . (int) (wpultra_fonts_max_bytes() / (1024 * 1024)) . 'MB cap.');
        }
        return $bin;
    }
    return wpultra_err('missing_source', 'Each face needs file_url or base64.');
}

/** Best-effort cleanup helper: unlink any files already written before returning an error. */
function wpultra_fonts_cleanup_and_err(array $paths, string $code, string $message) {
    foreach ($paths as $p) { @unlink($p); }
    return wpultra_err($code, $message);
}

/** A filesystem-safe slug for building font-file basenames. Guards sanitize_title's absence in odd contexts. */
function wpultra_fonts_slug(string $s): string {
    if (function_exists('sanitize_title')) { return (string) sanitize_title($s); }
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-');
}

/**
 * add-upload: fetch/decode each face, validate its extension against the font
 * whitelist, store it under wpultra-fonts/, and persist the registry entry.
 * Any failure rolls back files already written for this call.
 *
 * @return array|WP_Error
 */
function wpultra_fonts_add_upload(string $family, array $faces) {
    $family = trim($family);
    if ($family === '') { return wpultra_err('missing_family', 'family is required.'); }
    if ($faces === []) { return wpultra_err('missing_faces', 'At least one face is required.'); }

    $storage = wpultra_fonts_storage_dir();
    if (is_wp_error($storage)) { return $storage; }

    $stored_faces = [];
    $written = [];
    foreach ($faces as $i => $face) {
        if (!is_array($face)) { return wpultra_fonts_cleanup_and_err($written, 'bad_face', "Face #$i is not an object."); }

        $ext = wpultra_fonts_face_source_ext($face);
        if ($ext === null) {
            return wpultra_fonts_cleanup_and_err($written, 'missing_format', "Face #$i needs a format or a file_url with a recognizable extension.");
        }
        $valid = wpultra_fonts_validate_ext('face.' . $ext);
        if (is_wp_error($valid)) {
            return wpultra_fonts_cleanup_and_err($written, $valid->get_error_code(), $valid->get_error_message());
        }

        $bytes = wpultra_fonts_face_bytes($face);
        if (is_wp_error($bytes)) {
            return wpultra_fonts_cleanup_and_err($written, $bytes->get_error_code(), $bytes->get_error_message());
        }

        $weight = (int) ($face['weight'] ?? 400);
        $style  = trim((string) ($face['style'] ?? 'normal')) ?: 'normal';
        // Slugify $style the same way $family is slugified before it's used as a
        // filename component — it's otherwise only trim()'d, so an odd value could
        // land verbatim in an on-disk path.
        $style_slug = wpultra_fonts_slug($style) ?: 'normal';
        $basename = wpultra_fonts_slug($family) . '-' . $weight . '-' . $style_slug . '-' . substr(md5($bytes), 0, 8) . '.' . $ext;
        $path = $storage['dir'] . $basename;

        if (@file_put_contents($path, $bytes) === false) {
            return wpultra_fonts_cleanup_and_err($written, 'write_failed', "Could not write font file: $basename");
        }
        $written[] = $path;
        $stored_faces[] = [
            'weight' => $weight,
            'style'  => $style,
            'url'    => $storage['url'] . $basename,
            'format' => wpultra_fonts_face_format($ext),
        ];
    }

    $fonts = wpultra_fonts_options_get();
    $entry = ['id' => wpultra_fonts_new_id(), 'family' => $family, 'source' => 'upload', 'faces' => $stored_faces];
    $fonts[] = $entry;
    wpultra_fonts_options_save($fonts);
    return $entry;
}

/**
 * add-google-selfhost: fetch Google's CSS2 output for $family/$weights, parse out
 * the real font-file URLs, download each, and self-host under wpultra-fonts/.
 * A face Google served but that we couldn't download is skipped (best-effort);
 * the call only fails outright when NONE of the faces could be secured.
 *
 * @return array|WP_Error
 */
function wpultra_fonts_add_google_selfhost(string $family, array $weights) {
    $family = trim($family);
    if ($family === '') { return wpultra_err('missing_family', 'family is required.'); }
    if (!function_exists('wp_remote_get')) { return wpultra_err('http_unavailable', 'wp_remote_get is not available.'); }

    $api_url = wpultra_fonts_google_css_url($family, $weights);
    $resp = wp_remote_get($api_url, [
        'timeout'    => 20,
        // Google only serves woff2 to UAs it recognizes as modern browsers.
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
    ]);
    if (is_wp_error($resp)) {
        return wpultra_err('fetch_failed', "Could not reach Google Fonts: " . $resp->get_error_message() . ' — try again later, or use add-upload to host the font files directly.');
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return wpultra_err('fetch_failed', "Google Fonts returned HTTP $code for '$family'. Check the family name spelling.");
    }
    $css = (string) wp_remote_retrieve_body($resp);
    $parsed = wpultra_fonts_parse_google_css($css);
    if ($parsed['faces'] === []) {
        return wpultra_err('parse_failed', "Could not find any font files in Google's CSS for '$family'.");
    }

    $storage = wpultra_fonts_storage_dir();
    if (is_wp_error($storage)) { return $storage; }

    $resolved_family = $parsed['family'] !== '' ? $parsed['family'] : $family;
    $stored_faces = [];
    $written = [];
    foreach ($parsed['faces'] as $face) {
        $ext = $face['format'] === 'truetype' ? 'ttf' : ($face['format'] === 'opentype' ? 'otf' : $face['format']);
        if (wpultra_fonts_face_format((string) $ext) === null) { continue; }

        $body = wpultra_fonts_face_bytes(['file_url' => $face['url']]);
        if (is_wp_error($body)) { continue; } // best-effort: a single unreachable face shouldn't fail the whole family

        $style_slug = wpultra_fonts_slug((string) $face['style']) ?: 'normal';
        $basename = wpultra_fonts_slug($resolved_family) . '-' . $face['weight'] . '-' . $style_slug . '-' . substr(md5($body), 0, 8) . '.' . $ext;
        $path = $storage['dir'] . $basename;
        if (@file_put_contents($path, $body) === false) { continue; }

        $written[] = $path;
        $stored_faces[] = ['weight' => $face['weight'], 'style' => $face['style'], 'url' => $storage['url'] . $basename, 'format' => $face['format']];
    }

    if ($stored_faces === []) {
        return wpultra_err('download_failed', "Found font files in Google's CSS for '$family' but couldn't download any of them.");
    }

    $fonts = wpultra_fonts_options_get();
    $entry = ['id' => wpultra_fonts_new_id(), 'family' => $resolved_family, 'source' => 'google-selfhost', 'faces' => $stored_faces];
    $fonts[] = $entry;
    wpultra_fonts_options_save($fonts);
    return $entry;
}

/**
 * delete: remove a registered font's stored files (only files under our own
 * uploads subdir are ever unlinked) and drop its registry entry.
 *
 * @return array|WP_Error
 */
function wpultra_fonts_delete(string $id) {
    $fonts = wpultra_fonts_options_get();
    $idx = null;
    foreach ($fonts as $i => $f) {
        if (is_array($f) && (string) ($f['id'] ?? '') === $id) { $idx = $i; break; }
    }
    if ($idx === null) { return wpultra_err('not_found', "No font with id '$id'."); }

    $entry = $fonts[$idx];
    $storage = wpultra_fonts_storage_dir();
    if (!is_wp_error($storage)) {
        foreach ((array) ($entry['faces'] ?? []) as $face) {
            $url = is_array($face) ? (string) ($face['url'] ?? '') : '';
            if ($url === '' || strpos($url, $storage['url']) !== 0) { continue; }
            $path = $storage['dir'] . basename($url);
            if (is_file($path)) { @unlink($path); }
        }
    }
    array_splice($fonts, $idx, 1);
    wpultra_fonts_options_save($fonts);
    return ['id' => $id, 'deleted' => true];
}

/** list: registered fonts + a preview of the combined @font-face CSS they generate. */
function wpultra_fonts_list(): array {
    $fonts = wpultra_fonts_options_get();
    $css = '';
    foreach ($fonts as $f) { $css .= wpultra_fonts_fontface_css(is_array($f) ? $f : []); }
    return ['fonts' => $fonts, 'css_preview' => $css];
}

// ===========================================================================
// Runtime boot contract
// ===========================================================================

/** Print the combined @font-face CSS for every registered font in <head>. Cheap no-op with 0 fonts. */
function wpultra_fonts_render_head(): void {
    $fonts = wpultra_fonts_options_get();
    if ($fonts === []) { return; }
    $css = '';
    foreach ($fonts as $f) { $css .= wpultra_fonts_fontface_css(is_array($f) ? $f : []); }
    if (trim($css) === '') { return; }
    echo '<style id="wpultra-fonts">' . "\n" . $css . '</style>' . "\n";
}

/**
 * Always-on runtime boot: hook wp_head to render the @font-face CSS block.
 * Cheap + idempotent — get_option() only runs when wp_head actually fires, and
 * add_action on an already-registered callback+priority is a safe no-op.
 *
 * Controller wiring: call this from wpultra_load_contentreach_runtime() in
 * bootstrap-mcp.php (the existing always-on runtime loader gated on the
 * 'content' category), the same way it already calls wpultra_feed_boot() —
 * see the PP2-A report for the exact snippet.
 */
function wpultra_fonts_boot(): void {
    if (!function_exists('add_action')) { return; }
    add_action('wp_head', 'wpultra_fonts_render_head', 8);
}
