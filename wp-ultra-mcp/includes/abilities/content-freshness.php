<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively load the engine + shared AI helper this ability depends on.
if (!function_exists('wpultra_fresh_score')) {
    require_once __DIR__ . '/../content/freshness.php';
}
if (!function_exists('wpultra_ai_has_key')) {
    $__ai_setup = __DIR__ . '/../ai/setup.php';
    if (is_file($__ai_setup)) { require_once $__ai_setup; }
}

wp_register_ability('wpultra/content-freshness', [
    'label'       => __('Content Freshness', 'wp-ultra-mcp'),
    'description' => __(
        'Find stale and thin content, then suggest and apply safe refreshes. Scores each post on two axes: '
        . 'STALENESS (days since it was last modified — over 2 years is very stale, over 1 year stale, over 6 months aging, under 3 months fresh; '
        . 'stale posts that still get traffic rank higher) and THINNESS (word count under 300 is very thin, under 600 thin; also penalises no images, no internal links, and a missing meta description). '
        . 'Priority combines both, with an extra boost for posts older than a year that are also thin. '
        . 'Actions: "audit" scans the library and returns a ranked report with summary counts (read-only); '
        . '"score-post" returns one post\'s freshness + thinness + reasons + to-do list; '
        . '"suggest" returns a deterministic refresh checklist plus, when an OpenAI key is configured, concrete AI rewrite ideas (a better title, a refreshed intro, sections to add) — read-only, no writes; '
        . '"apply" updates a post with caller-SUPPLIED refreshed title/content and bumps the modified date (confirm-gated); '
        . '"touch" only bumps the modified date as a lightweight freshness signal (confirm-gated). '
        . 'IMPORTANT: apply never invents or auto-rewrites live content — the caller (or AI) supplies the refreshed text and approves it. '
        . 'Examples: audit all posts and pages; score post 42; suggest a refresh for post 42; apply an approved rewrite to post 42; touch post 42 to re-signal freshness.',
        'wp-ultra-mcp'
    ),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['audit', 'score-post', 'suggest', 'apply', 'touch'], 'default' => 'audit'],
            'post_id' => ['type' => 'integer'],
            'scope'   => [
                'type'       => 'object',
                'properties' => [
                    'post_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'limit'      => ['type' => 'integer', 'default' => 50],
                ],
            ],
            'title'   => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'confirm' => ['type' => 'boolean', 'default' => false],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'report'      => ['type' => 'object'],
            'score'       => ['type' => 'object'],
            'suggestions' => ['type' => 'array'],
            'ai'          => ['type' => 'object'],
            'result'      => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_content_freshness',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_content_freshness(array $input) {
    if (!function_exists('wpultra_fresh_score')) {
        require_once __DIR__ . '/../content/freshness.php';
    }
    $action = (string) ($input['action'] ?? 'audit');

    // ---- audit: scan + ranked report (read-only) ----
    if ($action === 'audit') {
        $scope = is_array($input['scope'] ?? null) ? $input['scope'] : [];
        $report = wpultra_fresh_audit($scope);
        if (is_wp_error($report)) { return $report; }
        wpultra_audit_log('content-freshness', sprintf(
            'audit scanned %d posts (%d need attention)',
            (int) ($report['summary']['total'] ?? 0),
            (int) ($report['summary']['needs_attention'] ?? 0)
        ), true);
        return wpultra_ok(['report' => $report]);
    }

    // score-post / suggest / apply / touch all need a post_id.
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) { return wpultra_err('missing_post_id', 'post_id is required for this action.'); }

    // ---- score-post: one post's freshness + reasons + to-do (read-only) ----
    if ($action === 'score-post' || $action === 'suggest') {
        $metrics = wpultra_fresh_post_metrics($post_id);
        if (is_wp_error($metrics)) { return $metrics; }
        $now = function_exists('current_time') ? (int) current_time('timestamp', true) : time();
        $score = wpultra_fresh_score($metrics, $now);
        $todo = wpultra_fresh_suggest_actions($score);

        if ($action === 'score-post') {
            return wpultra_ok(['score' => $score, 'suggestions' => $todo, 'metrics' => $metrics]);
        }

        // ---- suggest: deterministic to-do + optional AI rewrite ideas ----
        $ai = ['available' => false];
        if (function_exists('wpultra_ai_has_key') && wpultra_ai_has_key()) {
            $post = [
                'title'   => function_exists('get_the_title') ? (string) get_the_title($post_id) : '',
                'excerpt' => function_exists('get_the_excerpt') ? (string) get_the_excerpt($post_id) : '',
            ];
            $prompt = wpultra_fresh_ai_prompt($post, $score);
            $resp = wpultra_ai_chat($prompt['system'], $prompt['user'], ['json' => true]);
            if (is_wp_error($resp)) {
                $ai = ['available' => true, 'error' => $resp->get_error_message()];
            } else {
                $ai = array_merge(['available' => true], wpultra_fresh_parse_suggestions($resp));
            }
        }
        return wpultra_ok(['score' => $score, 'suggestions' => $todo, 'ai' => $ai]);
    }

    // ---- apply: write caller-supplied refresh + bump modified (confirm-gated) ----
    if ($action === 'apply') {
        $confirm = !empty($input['confirm']);
        $fields = [];
        if (array_key_exists('title', $input)) { $fields['title'] = (string) $input['title']; }
        if (array_key_exists('content', $input)) { $fields['content'] = (string) $input['content']; }
        $result = wpultra_fresh_apply($post_id, $fields, $confirm);
        if (is_wp_error($result)) {
            wpultra_audit_log('content-freshness', "apply on post $post_id failed", false);
            return $result;
        }
        wpultra_audit_log('content-freshness', 'apply on post ' . $post_id . ' (' . implode(',', $result['changed']) . ')', true);
        return wpultra_ok(['result' => $result]);
    }

    // ---- touch: bump modified only (confirm-gated) ----
    if ($action === 'touch') {
        $confirm = !empty($input['confirm']);
        $result = wpultra_fresh_touch($post_id, $confirm);
        if (is_wp_error($result)) {
            wpultra_audit_log('content-freshness', "touch on post $post_id failed", false);
            return $result;
        }
        wpultra_audit_log('content-freshness', "touch on post $post_id", true);
        return wpultra_ok(['result' => $result]);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
