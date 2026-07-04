<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Shared AI helpers for the AI-native domain (Group F).
 *
 * One place for: the OpenAI key (option `wpultra_openai_api_key` or constant
 * WPULTRA_OPENAI_API_KEY — the same source media-generate uses), a generic
 * chat-completions call, an embeddings call, and pure vector math. Every
 * Group-F engine reuses these so nothing re-implements HTTP or key handling.
 *
 * Design: the CALLING AI (Claude via MCP) is the primary intelligence — these
 * server-side OpenAI calls are an OPTIONAL fallback for features that must run
 * unattended (scheduled RAG indexing, cron autopilots). When no key is set the
 * features degrade to deterministic/keyword behaviour, never a fatal.
 */

/** The configured OpenAI API key ('' when none). Option first, then constant. */
function wpultra_ai_api_key(): string {
    $key = function_exists('get_option') ? (string) get_option('wpultra_openai_api_key', '') : '';
    if ($key === '' && defined('WPULTRA_OPENAI_API_KEY')) { $key = (string) constant('WPULTRA_OPENAI_API_KEY'); }
    return trim($key);
}

/** True when a server-side OpenAI key is available. */
function wpultra_ai_has_key(): bool {
    return wpultra_ai_api_key() !== '';
}

/** Default chat + embedding models (filterable). */
function wpultra_ai_chat_model(): string {
    $m = function_exists('apply_filters') ? apply_filters('wpultra_ai_chat_model', 'gpt-4o-mini') : 'gpt-4o-mini';
    return is_string($m) && $m !== '' ? $m : 'gpt-4o-mini';
}
function wpultra_ai_embed_model(): string {
    $m = function_exists('apply_filters') ? apply_filters('wpultra_ai_embed_model', 'text-embedding-3-small') : 'text-embedding-3-small';
    return is_string($m) && $m !== '' ? $m : 'text-embedding-3-small';
}

/**
 * Call OpenAI chat completions. Returns the assistant message string, or a
 * WP_Error. $opts: {model?, temperature?, max_tokens?, json? (bool → response
 * format json_object)}.
 *
 * @return string|WP_Error
 */
function wpultra_ai_chat(string $system, string $user, array $opts = []) {
    $key = wpultra_ai_api_key();
    if ($key === '') {
        return wpultra_err('no_api_key', "No OpenAI API key configured. Set the 'wpultra_openai_api_key' option or WPULTRA_OPENAI_API_KEY constant to use server-side AI, or have the calling AI supply the content directly.");
    }
    if (!function_exists('wp_remote_post')) {
        return wpultra_err('http_unavailable', 'wp_remote_post() unavailable.');
    }

    $body = [
        'model'       => (string) ($opts['model'] ?? wpultra_ai_chat_model()),
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'temperature' => isset($opts['temperature']) ? (float) $opts['temperature'] : 0.3,
    ];
    if (isset($opts['max_tokens'])) { $body['max_tokens'] = (int) $opts['max_tokens']; }
    if (!empty($opts['json'])) { $body['response_format'] = ['type' => 'json_object']; }

    $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 60,
        'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode($body),
    ]);
    if (is_wp_error($resp)) { return wpultra_err('openai_unreachable', $resp->get_error_message()); }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $data = json_decode((string) wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        return wpultra_err('openai_error', "OpenAI chat call failed (HTTP $code): $msg");
    }
    $content = is_array($data) ? ($data['choices'][0]['message']['content'] ?? null) : null;
    if (!is_string($content)) { return wpultra_err('openai_bad_response', 'OpenAI returned no message content.'); }
    return $content;
}

/**
 * Embed a list of texts. Returns a list of float-vectors aligned to $texts, or
 * a WP_Error. Batches are the caller's responsibility (OpenAI accepts arrays).
 *
 * @param array<int,string> $texts
 * @return array<int,array<int,float>>|WP_Error
 */
function wpultra_ai_embed(array $texts) {
    $key = wpultra_ai_api_key();
    if ($key === '') {
        return wpultra_err('no_api_key', "No OpenAI API key configured; server-side embeddings unavailable (the knowledge base falls back to keyword search).");
    }
    if (!function_exists('wp_remote_post')) {
        return wpultra_err('http_unavailable', 'wp_remote_post() unavailable.');
    }
    $input = array_values(array_map('strval', $texts));
    if ($input === []) { return []; }

    $resp = wp_remote_post('https://api.openai.com/v1/embeddings', [
        'timeout' => 60,
        'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['model' => wpultra_ai_embed_model(), 'input' => $input]),
    ]);
    if (is_wp_error($resp)) { return wpultra_err('openai_unreachable', $resp->get_error_message()); }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $data = json_decode((string) wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        return wpultra_err('openai_error', "OpenAI embeddings call failed (HTTP $code): $msg");
    }
    $rows = is_array($data) && isset($data['data']) && is_array($data['data']) ? $data['data'] : null;
    if ($rows === null) { return wpultra_err('openai_bad_response', 'OpenAI returned no embedding data.'); }
    // Preserve request order via the returned index.
    $out = [];
    foreach ($rows as $row) {
        $idx = (int) ($row['index'] ?? count($out));
        $out[$idx] = array_map('floatval', (array) ($row['embedding'] ?? []));
    }
    ksort($out);
    return array_values($out);
}

/**
 * PURE. Cosine similarity of two equal-length float vectors. Returns 0.0 on
 * length mismatch or a zero-magnitude vector (safe default = "unrelated").
 */
function wpultra_ai_cosine(array $a, array $b): float {
    $n = count($a);
    if ($n === 0 || $n !== count($b)) { return 0.0; }
    $dot = 0.0; $ma = 0.0; $mb = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = (float) $a[$i]; $y = (float) $b[$i];
        $dot += $x * $y; $ma += $x * $x; $mb += $y * $y;
    }
    if ($ma <= 0.0 || $mb <= 0.0) { return 0.0; }
    return $dot / (sqrt($ma) * sqrt($mb));
}
