<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively load the engines this ability orchestrates. bootstrap-mcp requires
// them in the normal boot, but keep the ability self-contained so it never
// fatals if loaded out of order.
if (!function_exists('wpultra_atrans_pick_targets') && defined('WPULTRA_DIR')) {
    $__eng = WPULTRA_DIR . 'includes/i18n/autotranslate.php';
    if (is_readable($__eng)) { require_once $__eng; }
}
if (!function_exists('wpultra_i18n_duplicate_to_language') && defined('WPULTRA_DIR')) {
    $__i18n = WPULTRA_DIR . 'includes/i18n/engine.php';
    if (is_readable($__i18n)) { require_once $__i18n; }
}
if (!function_exists('wpultra_ai_has_key') && defined('WPULTRA_DIR')) {
    $__ai = WPULTRA_DIR . 'includes/ai/setup.php';
    if (is_readable($__ai)) { require_once $__ai; }
}

wp_register_ability('wpultra/autotranslate', [
    'label'       => __('Autotranslate Site', 'wp-ultra-mcp'),
    'description' => __(
        'Translate every post, page and product into another language in ONE command, orchestrating your active multilingual plugin (WPML or Polylang). For each source post it creates AND links a translation in the target language (Elementor-safe meta + taxonomy terms carried over via duplicate-to-language), then writes the translated title/content/excerpt into it. '
        . 'Two honest translation paths: (1) CALLER-SUPPLIED — you (the calling AI) are an excellent translator, so for translate-post you may pass the translated {title, content, excerpt} directly and they are written into the linked copy (always available, no API key needed); (2) SERVER-SIDE AI — when an OpenAI key is configured, the ability translates each post itself via the shared AI helper, preserving HTML tags, Gutenberg block comments (<!-- wp:… -->), shortcodes [ … ] and URLs untouched (belt-and-suspenders: markup is token-protected before translation and restored after). '
        . 'Actions: '
        . 'status → active multilingual plugin, available languages, whether server-side AI is available. '
        . 'translate-post {post_id, target_lang, [title/content/excerpt]} → create the linked translation and write it; confirm-gated because it creates content. If title/content/excerpt are supplied they are used verbatim; otherwise the server-side AI translates when a key is set, else a structure-only linked shell is created for you to fill later via translation-set-content. '
        . 'translate-batch {scope|post_ids, target_lang, confirm:true} → build the queue (dropping posts that already have a target-language translation) and start an async background job that translates ONE post per WP-Cron tick. With an OpenAI key the job auto-translates; without one it creates the linked shells (structure-only) for you to fill, and you can instead loop translate-post yourself with your own translations. '
        . 'batch-status → progress of the running/last job (processed, remaining, percent, done[], failed[]). '
        . 'batch-cancel → request cancellation of the running job. '
        . 'Requires an active multilingual plugin — call status first. WPML/Polylang linkage is handled by duplicate-to-language; this ability never re-implements it.',
        'wp-ultra-mcp'
    ),
    'category'    => 'multilingual',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type'        => 'string',
                'enum'        => ['status', 'translate-post', 'translate-batch', 'batch-status', 'batch-cancel'],
                'description' => 'What to do. Default: status.',
            ],
            'post_id'     => ['type' => 'integer', 'description' => 'Source post for translate-post.'],
            'post_ids'    => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => 'Explicit source post IDs for translate-batch (alternative to scope).',
            ],
            'scope' => [
                'type'        => 'object',
                'description' => 'Batch selection for translate-batch when post_ids is not given.',
                'properties'  => [
                    'post_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Post types to include. Default: post, page (+ product when WooCommerce is active).'],
                    'limit'      => ['type' => 'integer', 'description' => 'Cap the number of posts queued.'],
                ],
                'additionalProperties' => false,
            ],
            'target_lang' => ['type' => 'string', 'description' => 'Target language code (e.g. fr, bn, de) — must be a language configured in WPML/Polylang.'],
            'title'       => ['type' => 'string', 'description' => 'translate-post: caller-supplied translated title.'],
            'content'     => ['type' => 'string', 'description' => 'translate-post: caller-supplied translated content (preserve HTML/blocks/shortcodes).'],
            'excerpt'     => ['type' => 'string', 'description' => 'translate-post: caller-supplied translated excerpt.'],
            'overwrite'   => ['type' => 'boolean', 'description' => 'translate-post: replace an existing target-language translation instead of erroring.'],
            'confirm'     => ['type' => 'boolean', 'description' => 'Required true for translate-post and translate-batch — they create content.'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'action'        => ['type' => 'string'],
            'active_plugin' => ['type' => 'string'],
            'languages'     => ['type' => 'array'],
            'ai_available'  => ['type' => 'boolean'],
            'job'           => ['type' => 'object'],
            'source_id'     => ['type' => 'integer'],
            'translation_id'=> ['type' => 'integer'],
            'method'        => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_autotranslate',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_autotranslate(array $input) {
    $action = trim((string) ($input['action'] ?? 'status')) ?: 'status';

    if (!function_exists('wpultra_atrans_pick_targets')) {
        return wpultra_err('engine_missing', 'The autotranslate engine could not be loaded.');
    }

    switch ($action) {
        case 'status':
            $status = wpultra_atrans_status();
            return wpultra_ok(array_merge(['action' => 'status'], $status));

        case 'translate-post':
            return wpultra_atrans_action_translate_post($input);

        case 'translate-batch':
            return wpultra_atrans_action_translate_batch($input);

        case 'batch-status':
            $job = wpultra_atrans_load_job();
            if ($job === null) { return wpultra_ok(['action' => 'batch-status', 'job' => ['status' => 'none']]); }
            return wpultra_ok(['action' => 'batch-status', 'job' => wpultra_atrans_shape_job($job)]);

        case 'batch-cancel':
            $shaped = wpultra_atrans_cancel_job();
            wpultra_audit_log('autotranslate', 'batch cancellation requested', true);
            return wpultra_ok(['action' => 'batch-cancel', 'job' => $shaped]);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use status|translate-post|translate-batch|batch-status|batch-cancel.");
    }
}

/** translate-post handler. */
function wpultra_atrans_action_translate_post(array $input) {
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) { return wpultra_err('missing_post_id', 'post_id is required for translate-post.'); }
    $target_lang = trim((string) ($input['target_lang'] ?? ''));
    if ($target_lang === '') { return wpultra_err('missing_target_lang', 'target_lang is required.'); }

    if (empty($input['confirm'])) {
        return wpultra_err('confirm_required', 'translate-post creates a linked translation post. Pass confirm:true to proceed.');
    }

    $plugin = function_exists('wpultra_i18n_active_plugin') ? wpultra_i18n_active_plugin() : '';
    if ($plugin === '') {
        return wpultra_err('multilingual_unavailable', 'No multilingual plugin (WPML or Polylang) is active. Call status first.');
    }

    $supplied = [];
    foreach (['title', 'content', 'excerpt'] as $k) {
        if (array_key_exists($k, $input)) { $supplied[$k] = (string) $input[$k]; }
    }
    $overwrite = !empty($input['overwrite']);

    $res = wpultra_atrans_translate_post($post_id, $target_lang, $supplied, $overwrite);
    if (is_wp_error($res)) {
        wpultra_audit_log('autotranslate', "translate-post $post_id → '$target_lang' failed: " . $res->get_error_message(), false);
        return $res;
    }
    wpultra_audit_log('autotranslate', "translated post $post_id → '$target_lang' as {$res['translation_id']} ({$res['method']})", true);
    return wpultra_ok(array_merge(['action' => 'translate-post'], $res));
}

/** translate-batch handler. */
function wpultra_atrans_action_translate_batch(array $input) {
    $target_lang = trim((string) ($input['target_lang'] ?? ''));
    if ($target_lang === '') { return wpultra_err('missing_target_lang', 'target_lang is required.'); }

    if (empty($input['confirm'])) {
        return wpultra_err('confirm_required', 'translate-batch starts a background job that creates linked translations across many posts. Pass confirm:true to proceed.');
    }

    $plugin = function_exists('wpultra_i18n_active_plugin') ? wpultra_i18n_active_plugin() : '';
    if ($plugin === '') {
        return wpultra_err('multilingual_unavailable', 'No multilingual plugin (WPML or Polylang) is active. Call status first.');
    }

    // Don't clobber an in-flight job.
    $existing = wpultra_atrans_load_job();
    if ($existing !== null && wpultra_atrans_is_active((string) ($existing['status'] ?? ''))) {
        return wpultra_err('job_running', 'An autotranslate batch is already running. Use batch-status to watch it, or batch-cancel to stop it first.');
    }

    // Build the candidate rows.
    if (!empty($input['post_ids']) && is_array($input['post_ids'])) {
        $rows = [];
        foreach ($input['post_ids'] as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) { continue; }
            $rows[] = ['id' => $pid, 'type' => (string) (get_post_type($pid) ?: ''), 'lang' => wpultra_atrans_post_lang($pid, $plugin)];
        }
        $scope = [];
    } else {
        $scope = is_array($input['scope'] ?? null) ? $input['scope'] : [];
        $rows = wpultra_atrans_collect_sources($scope);
    }

    if ($rows === []) {
        return wpultra_err('no_sources', 'No candidate posts found for the given scope/post_ids.');
    }

    // Drop posts already translated into the target language.
    $ids = array_map(static fn($r) => (int) $r['id'], $rows);
    $already = wpultra_atrans_already_translated($ids, $target_lang, $plugin);

    $opts = [];
    if (!empty($scope['post_types']) && is_array($scope['post_types'])) { $opts['post_types'] = $scope['post_types']; }
    if (!empty($scope['limit'])) { $opts['limit'] = (int) $scope['limit']; }

    $queue = wpultra_atrans_pick_targets($rows, $target_lang, $already, $opts);

    $source = (function_exists('wpultra_ai_has_key') && wpultra_ai_has_key()) ? 'ai' : 'caller';
    $shaped = wpultra_atrans_start_job($target_lang, $queue, $source);

    $note = $source === 'ai'
        ? 'Server-side AI will translate each post automatically.'
        : 'No OpenAI key set: the job creates structure-only linked translations for you to fill (via translate-post with your own translations, or translation-set-content). Alternatively, loop translate-post yourself with translated title/content/excerpt.';
    $shaped['note'] = $note;

    wpultra_audit_log('autotranslate', "started batch → '$target_lang': " . count($queue) . ' post(s), source=' . $source, true);
    return wpultra_ok(['action' => 'translate-batch', 'job' => $shaped]);
}
