<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * AI image generation → media library, one flow.
 *
 * Three source modes, resolved in order:
 *   1. `url` / `data_base64` passthrough — the AI client generated the image itself and just
 *      wants it uploaded (reuses wpultra_media_sideload_url / wpultra_media_from_base64).
 *   2. `prompt` + a configured OpenAI API key — this plugin calls the Images API server-side.
 *   3. `prompt` with no key — a clear error explaining both options (set the option, or
 *      generate client-side and pass url/data_base64).
 *
 * This file only adds the "get an image" step; upload/meta/featured-image application reuses
 * the existing engine fns in includes/media/engine.php.
 */

/** Allowed OpenAI image sizes (plus 'auto'). Pure. */
function wpultra_media_gen_allowed_sizes(): array {
    return ['1024x1024', '1536x1024', '1024x1536', 'auto'];
}

/** The configured OpenAI API key, if any (option, falling back to a constant). */
function wpultra_media_gen_api_key(): string {
    $key = function_exists('get_option') ? (string) get_option('wpultra_openai_api_key', '') : '';
    if ($key === '' && defined('WPULTRA_OPENAI_API_KEY')) { $key = (string) WPULTRA_OPENAI_API_KEY; }
    return trim($key);
}

/**
 * Pure: decide which source mode to use for a given input, without performing any I/O.
 *
 * @return array{mode: 'url'|'base64'|'api'|'error', error?: array{code:string,message:string}}
 */
function wpultra_media_gen_resolve_source(array $in, bool $has_key): array {
    if (!empty($in['url'])) { return ['mode' => 'url']; }
    if (!empty($in['data_base64'])) { return ['mode' => 'base64']; }
    if (!empty($in['prompt'])) {
        if ($has_key) { return ['mode' => 'api']; }
        return [
            'mode'  => 'error',
            'error' => [
                'code'    => 'no_api_key',
                'message' => "No OpenAI API key configured. Either set the 'wpultra_openai_api_key' option "
                    . "(or the WPULTRA_OPENAI_API_KEY constant) to generate server-side, or generate the "
                    . "image yourself and pass it via url or data_base64.",
            ],
        ];
    }
    return [
        'mode'  => 'error',
        'error' => ['code' => 'missing_source', 'message' => 'Provide one of: url, data_base64, or prompt.'],
    ];
}

/**
 * Pure: build the OpenAI Images API request (endpoint + body) for a text-to-image call.
 * Validates $size against the allowed set; falls back to '1024x1024' if empty, errors if junk.
 *
 * @return array{url: string, body: array}|array{error: array{code:string,message:string}}
 */
function wpultra_media_gen_build_api_request(string $prompt, string $size, string $model): array {
    $prompt = trim($prompt);
    if ($prompt === '') { return ['error' => ['code' => 'missing_prompt', 'message' => 'prompt is required.']]; }
    $size = trim($size) !== '' ? trim($size) : '1024x1024';
    if (!in_array($size, wpultra_media_gen_allowed_sizes(), true)) {
        return [
            'error' => [
                'code'    => 'bad_size',
                'message' => "Invalid size '$size'. Allowed: " . implode(', ', wpultra_media_gen_allowed_sizes()) . '.',
            ],
        ];
    }
    $model = trim($model) !== '' ? trim($model) : 'gpt-image-1';
    return [
        'url'  => 'https://api.openai.com/v1/images/generations',
        'body' => [
            'model'  => $model,
            'prompt' => $prompt,
            'size'   => $size,
            'n'      => 1,
        ],
    ];
}

/**
 * Call the OpenAI Images API server-side and return the decoded base64 image payload.
 *
 * @return string|WP_Error base64-encoded image data, or WP_Error on failure.
 */
function wpultra_media_gen_call_api(string $prompt, string $size, string $model, string $api_key) {
    $req = wpultra_media_gen_build_api_request($prompt, $size, $model);
    if (isset($req['error'])) { return wpultra_err($req['error']['code'], $req['error']['message']); }
    if (!function_exists('wp_remote_post')) { return wpultra_err('wp_unavailable', 'wp_remote_post() is unavailable.'); }

    $response = wp_remote_post($req['url'], [
        'timeout' => 120,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => function_exists('wp_json_encode') ? wp_json_encode($req['body']) : json_encode($req['body']),
    ]);
    if (is_wp_error($response)) { return $response; }

    $status = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);

    if ($status < 200 || $status >= 300) {
        $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $raw_body) : $raw_body;
        return wpultra_err('api_error', "OpenAI Images API error (HTTP $status): $msg");
    }
    $b64 = $decoded['data'][0]['b64_json'] ?? null;
    if (!is_string($b64) || $b64 === '') {
        return wpultra_err('bad_api_response', 'OpenAI Images API response did not include b64_json image data.');
    }
    return $b64;
}

/**
 * The one-flow orchestrator: resolve source → get image bytes → upload to media library →
 * apply alt/title/caption → optionally set as featured image on post_id.
 *
 * @return array|WP_Error
 */
function wpultra_media_gen_run(array $input) {
    $has_key = wpultra_media_gen_api_key() !== '';
    $resolved = wpultra_media_gen_resolve_source($input, $has_key);
    if ($resolved['mode'] === 'error') {
        return wpultra_err($resolved['error']['code'], $resolved['error']['message']);
    }

    $meta = array_intersect_key($input, array_flip(['filename', 'alt', 'title', 'caption']));
    if (!empty($input['post_id'])) { $meta['attach_to_post'] = (int) $input['post_id']; }

    switch ($resolved['mode']) {
        case 'url':
            $res = wpultra_media_sideload_url((string) $input['url'], $meta);
            break;
        case 'base64':
            $res = wpultra_media_from_base64((string) $input['data_base64'], $meta);
            break;
        case 'api':
            $b64 = wpultra_media_gen_call_api(
                (string) $input['prompt'],
                (string) ($input['size'] ?? '1024x1024'),
                (string) ($input['model'] ?? 'gpt-image-1'),
                wpultra_media_gen_api_key()
            );
            if (is_wp_error($b64)) { return $b64; }
            if (empty($meta['filename'])) { $meta['filename'] = 'ai-generated.png'; }
            $res = wpultra_media_from_base64($b64, $meta);
            break;
        default:
            return wpultra_err('bad_mode', "Unknown source mode '{$resolved['mode']}'.");
    }
    if (is_wp_error($res)) { return $res; }

    $post_id = (int) ($input['post_id'] ?? 0);
    $want_featured = array_key_exists('set_featured', $input) ? (bool) $input['set_featured'] : ($post_id > 0);
    $featured_set = false;
    if ($post_id > 0 && $want_featured) {
        if (!function_exists('get_post') || get_post($post_id) === null) {
            return wpultra_err('post_not_found', "No post with id $post_id to set the featured image on.");
        }
        if (function_exists('set_post_thumbnail')) {
            set_post_thumbnail($post_id, (int) $res['id']);
            $featured_set = true;
        }
    }

    $res['featured_set'] = $featured_set;
    $res['source_mode'] = $resolved['mode'];
    return $res;
}
