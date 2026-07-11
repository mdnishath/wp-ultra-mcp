<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Pixel diff engine (roadmap PP1.4).
 *
 * Server-side PIXEL comparison of two screenshots. The calling AI (e.g. Claude
 * Code, which can drive a browser and capture screenshots) supplies the pixels
 * — a URL or a base64 data string for each of image A ("before") and image B
 * ("after") — and this engine does the math: per-pixel channel deltas, a
 * mismatch percentage, the bounding box of changed pixels, and (optionally) a
 * PNG diff-heatmap saved to the uploads dir. GD ships with WordPress by
 * default, so the GD path is the always-available primary; Imagick is used
 * opportunistically only where noted.
 *
 * The PURE core (prefix wpultra_pxdiff_, no WordPress/GD calls) is unit-tested
 * in tests/pixeldiff.test.php. WP/GD-touching wrappers come after and are
 * guarded (function_exists checks) so the file loads standalone in the test
 * harness without GD or WordPress present.
 */

/* =====================================================================
 * PURE core — per-pixel math, summarization, bbox growth, input decoding.
 * ===================================================================== */

/**
 * Max absolute per-channel delta between two pixels. Each pixel is an array of
 * channel values — either indexed [r,g,b] or keyed ['r'=>..,'g'=>..,'b'=>..];
 * both shapes work since we only iterate $pa's own keys. Pure.
 */
function wpultra_pxdiff_channel_diff(array $pa, array $pb): int {
    $max = 0;
    foreach ($pa as $key => $value) {
        $delta = abs((int) $value - (int) ($pb[$key] ?? 0));
        if ($delta > $max) { $max = $delta; }
    }
    return $max;
}

/**
 * A pixel pair is "different" when the max per-channel delta exceeds
 * (strictly greater than) $tolerance. A delta exactly equal to tolerance is
 * still considered a match. Pure.
 */
function wpultra_pxdiff_is_different(array $pa, array $pb, int $tolerance): bool {
    return wpultra_pxdiff_channel_diff($pa, $pb) > $tolerance;
}

/**
 * Summarize a completed pixel walk into the reportable shape: mismatch_pct
 * (divide-by-zero guarded to 0.0), the pass-through counts, and a coarse
 * human-readable verdict. Pure.
 *
 * Verdict rules (checked in order):
 *   - dimensions didn't match at all                -> dimension_mismatch
 *   - zero different pixels                         -> pixel_perfect
 *   - mismatch_pct <= 0.1                            -> near_identical
 *   - mismatch_pct <= 5                              -> minor_diff
 *   - otherwise                                      -> major_diff
 */
function wpultra_pxdiff_summarize(int $different, int $compared, int $max_delta, bool $dim_match): array {
    $pct = $compared > 0 ? round(($different / $compared) * 100, 4) : 0.0;

    if (!$dim_match) {
        $verdict = 'dimension_mismatch';
    } elseif ($different === 0) {
        $verdict = 'pixel_perfect';
    } elseif ($pct <= 0.1) {
        $verdict = 'near_identical';
    } elseif ($pct <= 5) {
        $verdict = 'minor_diff';
    } else {
        $verdict = 'major_diff';
    }

    return [
        'mismatch_pct'       => $pct,
        'different_pixels'   => $different,
        'compared_pixels'    => $compared,
        'max_channel_delta'  => $max_delta,
        'dimension_match'    => $dim_match,
        'verdict'            => $verdict,
    ];
}

/**
 * Grow a bounding box (['x','y','w','h']) to include point ($x,$y). Pass null
 * as $bbox to start a fresh 1x1 box at the point. Pure.
 */
function wpultra_pxdiff_bbox_update(?array $bbox, int $x, int $y): array {
    if ($bbox === null) {
        return ['x' => $x, 'y' => $y, 'w' => 1, 'h' => 1];
    }
    $x0 = min($bbox['x'], $x);
    $y0 = min($bbox['y'], $y);
    $x1 = max($bbox['x'] + $bbox['w'] - 1, $x);
    $y1 = max($bbox['y'] + $bbox['h'] - 1, $y);
    return ['x' => $x0, 'y' => $y0, 'w' => $x1 - $x0 + 1, 'h' => $y1 - $y0 + 1];
}

/** True when $s consists only of base64 alphabet characters (+padding), pure. */
function wpultra_pxdiff_looks_like_base64(string $s): bool {
    if ($s === '' || strlen($s) % 4 !== 0) { return false; }
    return (bool) preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $s);
}

/**
 * Classify a single image input string as either a fetchable URL or a base64
 * payload, stripping a `data:<mime>;base64,` prefix when present. Does NOT
 * decode to bytes / touch GD — that happens in the WP-dependent loader below.
 * Returns ['kind'=>'url'|'base64', 'payload'=>string] or a WP_Error. Pure
 * (aside from constructing the WP_Error stub/class, which has no side effects).
 *
 * @return array|WP_Error
 */
function wpultra_pxdiff_decode_input(string $s) {
    $s = trim($s);
    if ($s === '') {
        return wpultra_err('empty_image_input', 'Image input is empty.');
    }

    if (preg_match('/^data:[^;,]*;base64,(.*)$/is', $s, $m)) {
        $payload = trim($m[1]);
        if (!wpultra_pxdiff_looks_like_base64($payload)) {
            return wpultra_err('invalid_base64', 'The base64 payload after the data: URI prefix is not valid base64.');
        }
        return ['kind' => 'base64', 'payload' => $payload];
    }

    if (preg_match('#^https?://#i', $s)) {
        return ['kind' => 'url', 'payload' => $s];
    }

    if (wpultra_pxdiff_looks_like_base64($s)) {
        return ['kind' => 'base64', 'payload' => $s];
    }

    return wpultra_err('invalid_image_input', 'Image input must be an http(s) URL or a base64 / data: URI string.');
}

/* =====================================================================
 * WP/GD wrappers — fetch, decode, compare, heatmap. Guarded for the
 * standalone test harness (GD/WordPress functions are only called, never
 * invoked at require-time, so this file loads fine without either).
 * ===================================================================== */

/** Hard cap on downloaded/decoded image size (~15MB), per the brief. */
function wpultra_pxdiff_max_bytes(): int {
    return 15 * 1024 * 1024;
}

/** Fetch an image URL via wp_remote_get (timeout 15s) with a size cap. Returns raw bytes or WP_Error. */
function wpultra_pxdiff_fetch_url(string $url) {
    if (!function_exists('wp_http_validate_url') || !wp_http_validate_url($url)) {
        return wpultra_err('invalid_url', "Invalid image URL: $url");
    }
    if (!function_exists('wp_remote_get')) {
        return wpultra_err('http_unavailable', 'wp_remote_get is not available.');
    }

    $resp = wp_remote_get($url, [
        'timeout'     => 15,
        'redirection' => 3,
        'sslverify'   => true,
        'user-agent'  => 'wp-ultra-mcp/pixel-diff',
    ]);
    if (is_wp_error($resp)) {
        return wpultra_err('fetch_failed', 'Could not fetch image: ' . $resp->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return wpultra_err('fetch_failed', "Image fetch returned HTTP $code for $url");
    }
    $body = (string) wp_remote_retrieve_body($resp);
    if ($body === '') {
        return wpultra_err('empty_response', "Empty response fetching $url");
    }
    if (strlen($body) > wpultra_pxdiff_max_bytes()) {
        return wpultra_err('image_too_large', "Image at $url exceeds the 15MB cap.");
    }
    return $body;
}

/** Resolve one image input (URL or base64/data-uri) to raw image bytes. Returns string|WP_Error. */
function wpultra_pxdiff_load_bytes(string $s) {
    $decoded = wpultra_pxdiff_decode_input($s);
    if (is_wp_error($decoded)) { return $decoded; }

    if ($decoded['kind'] === 'url') {
        return wpultra_pxdiff_fetch_url($decoded['payload']);
    }

    $bin = base64_decode($decoded['payload'], true);
    if ($bin === false || $bin === '') {
        return wpultra_err('invalid_base64', 'Could not decode base64 image data.');
    }
    if (strlen($bin) > wpultra_pxdiff_max_bytes()) {
        return wpultra_err('image_too_large', 'Decoded image data exceeds the 15MB cap.');
    }
    return $bin;
}

/**
 * Save a GD image resource/object as a PNG under wp-content/uploads/wpultra-pixeldiff/.
 * Returns its public URL, or WP_Error when uploads isn't writable (caller should
 * degrade gracefully — skip the heatmap, keep the numeric report).
 *
 * @param resource|\GdImage $image
 * @return string|WP_Error
 */
function wpultra_pxdiff_save_heatmap($image) {
    if (!function_exists('wp_upload_dir')) {
        return wpultra_err('uploads_unavailable', 'wp_upload_dir is not available.');
    }
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) {
        return wpultra_err('uploads_error', (string) $upload['error']);
    }
    $base_dir = trailingslashit((string) ($upload['basedir'] ?? ''));
    $base_url = trailingslashit((string) ($upload['baseurl'] ?? ''));
    if ($base_dir === '/') {
        return wpultra_err('uploads_error', 'Could not resolve the uploads base directory.');
    }

    $dir = $base_dir . 'wpultra-pixeldiff/';
    if (!is_dir($dir)) {
        $made = function_exists('wp_mkdir_p') ? wp_mkdir_p($dir) : @mkdir($dir, 0755, true);
        if (!$made) { return wpultra_err('mkdir_failed', "Could not create uploads subdir: $dir"); }
    }
    if (!is_writable($dir)) {
        return wpultra_err('not_writable', "Uploads dir not writable: $dir");
    }

    $hash = substr(md5(uniqid('pxdiff', true)), 0, 16);
    $filename = "diff-{$hash}.png";
    $path = $dir . $filename;

    if (!imagepng($image, $path)) {
        return wpultra_err('save_failed', "Could not write heatmap PNG to $path");
    }
    return $base_url . 'wpultra-pixeldiff/' . $filename;
}

/**
 * Decide the rectangle (relative to each image's own origin) to compare, given
 * the two images' dimensions and an optional {x,y,w,h} region. Pure geometry —
 * split out from wpultra_pxdiff_compare() so the clamping logic is testable
 * without GD, even though the brief only requires the smaller set of pure fns;
 * this one has no WordPress/GD dependency either.
 *
 * Returns ['ox','oy','w','h'] where ox/oy is the shared top-left offset applied
 * to BOTH images (region case) or 0/0 (overlap case), and w/h is the compared
 * size (already clamped to fit inside both images).
 */
function wpultra_pxdiff_compare_rect(int $wa, int $ha, int $wb, int $hb, ?array $region): array {
    if (is_array($region) && isset($region['x'], $region['y'], $region['w'], $region['h'])) {
        $ox = max(0, (int) $region['x']);
        $oy = max(0, (int) $region['y']);
        $rw = max(1, (int) $region['w']);
        $rh = max(1, (int) $region['h']);
        $w = max(0, min($rw, $wa - $ox, $wb - $ox));
        $h = max(0, min($rh, $ha - $oy, $hb - $oy));
        return ['ox' => $ox, 'oy' => $oy, 'w' => $w, 'h' => $h];
    }
    return ['ox' => 0, 'oy' => 0, 'w' => max(0, min($wa, $wb)), 'h' => max(0, min($ha, $hb))];
}

/**
 * Pixel budget above which we sample on a stride instead of walking every
 * pixel, so a huge screenshot pair can't blow the request time limit.
 */
function wpultra_pxdiff_max_walked_pixels(): int {
    return (int) (function_exists('apply_filters') ? apply_filters('wpultra_pxdiff_max_walked_pixels', 2000000) : 2000000);
}

/** Sampling stride for a $w x $h compare area given a pixel budget. Pure. 1 = every pixel. */
function wpultra_pxdiff_stride_for(int $w, int $h, int $budget): int {
    $total = $w * $h;
    if ($total <= $budget || $budget <= 0) { return 1; }
    return (int) max(1, ceil(sqrt($total / $budget)));
}

/**
 * Full GD-backed comparison. $input is the ability's raw input array:
 *   { a, b, tolerance?, save_heatmap?, region? }
 * Returns the report array or WP_Error. WordPress + GD dependent.
 *
 * @return array|WP_Error
 */
function wpultra_pxdiff_compare(array $input) {
    if (!function_exists('imagecreatefromstring')) {
        return wpultra_err('gd_missing', 'The GD extension is not available on this server.');
    }

    $a_raw = (string) ($input['a'] ?? '');
    $b_raw = (string) ($input['b'] ?? '');
    if (trim($a_raw) === '') { return wpultra_err('missing_a', 'Input "a" (image A) is required.'); }
    if (trim($b_raw) === '') { return wpultra_err('missing_b', 'Input "b" (image B) is required.'); }

    $tolerance = max(0, min(255, (int) ($input['tolerance'] ?? 10)));
    $save_heatmap = array_key_exists('save_heatmap', $input) ? (bool) $input['save_heatmap'] : true;
    $region = is_array($input['region'] ?? null) ? $input['region'] : null;

    $bytes_a = wpultra_pxdiff_load_bytes($a_raw);
    if (is_wp_error($bytes_a)) { return $bytes_a; }
    $bytes_b = wpultra_pxdiff_load_bytes($b_raw);
    if (is_wp_error($bytes_b)) { return $bytes_b; }

    $img_a = @imagecreatefromstring($bytes_a);
    if ($img_a === false) { return wpultra_err('decode_failed_a', 'Could not decode image A (unsupported or corrupt format).'); }
    $img_b = @imagecreatefromstring($bytes_b);
    if ($img_b === false) {
        imagedestroy($img_a);
        return wpultra_err('decode_failed_b', 'Could not decode image B (unsupported or corrupt format).');
    }

    $wa = imagesx($img_a);
    $ha = imagesy($img_a);
    $wb = imagesx($img_b);
    $hb = imagesy($img_b);
    $dimension_match = ($wa === $wb && $ha === $hb);

    $rect = wpultra_pxdiff_compare_rect($wa, $ha, $wb, $hb, $region);
    $ox = $rect['ox']; $oy = $rect['oy']; $cw = $rect['w']; $ch = $rect['h'];

    if ($cw <= 0 || $ch <= 0) {
        imagedestroy($img_a);
        imagedestroy($img_b);
        return wpultra_err('no_overlap', 'No overlapping pixels to compare (check the region or the image dimensions).');
    }

    $stride = wpultra_pxdiff_stride_for($cw, $ch, wpultra_pxdiff_max_walked_pixels());

    $compared = 0;
    $different = 0;
    $max_delta = 0;
    $bbox = null;

    $heatmap = $save_heatmap ? imagecreatetruecolor($cw, $ch) : null;
    $hm_red = $heatmap ? imagecolorallocate($heatmap, 220, 30, 30) : null;

    for ($y = 0; $y < $ch; $y += $stride) {
        for ($x = 0; $x < $cw; $x += $stride) {
            $rgb_a = imagecolorat($img_a, $ox + $x, $oy + $y);
            $rgb_b = imagecolorat($img_b, $ox + $x, $oy + $y);
            $ca = imagecolorsforindex($img_a, $rgb_a);
            $cb = imagecolorsforindex($img_b, $rgb_b);
            $pa = [$ca['red'], $ca['green'], $ca['blue']];
            $pb = [$cb['red'], $cb['green'], $cb['blue']];

            $delta = wpultra_pxdiff_channel_diff($pa, $pb);
            if ($delta > $max_delta) { $max_delta = $delta; }
            $compared++;
            $is_diff = $delta > $tolerance;

            if ($is_diff) {
                $different++;
                $bbox = wpultra_pxdiff_bbox_update($bbox, $x, $y);
            }

            if ($heatmap) {
                if ($is_diff) {
                    imagesetpixel($heatmap, $x, $y, $hm_red);
                } else {
                    // Dimmed grayscale of image A as the backdrop for the highlighted diffs.
                    $gray = (int) round((($ca['red'] + $ca['green'] + $ca['blue']) / 3) * 0.5);
                    imagesetpixel($heatmap, $x, $y, imagecolorallocate($heatmap, $gray, $gray, $gray));
                }
            }
        }
    }

    imagedestroy($img_a);
    imagedestroy($img_b);

    $summary = wpultra_pxdiff_summarize($different, $compared, $max_delta, $dimension_match);

    $result = [
        'width'                  => $cw,
        'height'                 => $ch,
        'compared_pixels'        => $compared,
        'different_pixels'       => $different,
        'mismatch_pct'           => $summary['mismatch_pct'],
        'max_channel_delta'      => $max_delta,
        'dimension_match'        => $dimension_match,
        'verdict'                => $summary['verdict'],
        'bounding_box_of_changes' => $bbox,
        'sample_stride'          => $stride,
        'source_dimensions'      => [
            'a' => ['width' => $wa, 'height' => $ha],
            'b' => ['width' => $wb, 'height' => $hb],
        ],
    ];

    if (!$dimension_match) {
        $result['dimension_mismatch_note'] = "Image A is {$wa}x{$ha}, image B is {$wb}x{$hb}. Compared only the "
            . "overlapping {$cw}x{$ch} region — a size mismatch is itself a pixel-perfect failure.";
    }
    if ($stride > 1) {
        $result['sampling_note'] = "Compare area is large; sampled every {$stride}th pixel (stride={$stride}) to bound runtime.";
    }

    if ($heatmap) {
        $saved = wpultra_pxdiff_save_heatmap($heatmap);
        imagedestroy($heatmap);
        if (is_wp_error($saved)) {
            $result['heatmap_note'] = 'Heatmap not saved: ' . $saved->get_error_message();
        } else {
            $result['heatmap_url'] = $saved;
        }
    }

    return $result;
}
