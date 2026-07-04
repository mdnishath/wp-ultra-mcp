<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * F4 · Natural-language analytics ability.
 *
 * Defensively require the engine + the shared AI helper so this ability works
 * regardless of the bootstrap load order.
 */
if (defined('WPULTRA_DIR')) {
    if (!function_exists('wpultra_nlq_reports') && is_readable(WPULTRA_DIR . 'includes/ai/nlquery.php')) {
        require_once WPULTRA_DIR . 'includes/ai/nlquery.php';
    }
    if (!function_exists('wpultra_ai_has_key') && is_readable(WPULTRA_DIR . 'includes/ai/setup.php')) {
        require_once WPULTRA_DIR . 'includes/ai/setup.php';
    }
}

wp_register_ability('wpultra/nl-analytics', [
    'label'       => __('AI: Natural-language analytics', 'wp-ultra-mcp'),
    'description' => __(
        'Answer analytics questions about the site and store SAFELY. The model NEVER writes raw SQL — a question is mapped to a validated STRUCTURED INTENT (a whitelisted report id + typed params) and only hand-written, parameterized, read-only report queries ever touch the database. WooCommerce reports are HPOS-safe (wc_get_orders / wc_get_products). '
        . 'Actions: '
        . 'list-reports — return the report catalog (call this first so you know what is available); '
        . 'run {report, params} — the ALWAYS-AVAILABLE structured path (NO API key needed): you (the calling AI) pick a report id from the catalog and supply its params, and the server validates + runs it; '
        . 'ask {question} — map a raw natural-language question to an intent then run it (needs a server-side OpenAI key; without a key it returns the catalog and asks you to call `run` with {report, params} instead); '
        . 'resolve-date {expr} — utility: resolve a relative date expression (today, yesterday, 7d, 30d, this-month, last-month, YYYY-MM-DD) to Y-m-d. '
        . 'Reports: top_products (best sellers by qty/revenue in a date range), sales_summary (order count, gross, net, avg order value), sales_by_day (daily revenue series), top_customers (by spend), low_stock (products at/below a threshold), new_users (registrations in a range), post_counts (content counts by status), top_content (most-commented posts). '
        . 'Date params (date_from, date_to) accept relative expressions; an inverted range is auto-swapped. limit is clamped 1..100. '
        . 'Examples: {action:"run", report:"top_products", params:{date_from:"last-month", date_to:"today", limit:5}} = "top 5 sellers since last month". {action:"run", report:"low_stock", params:{threshold:3}}. {action:"ask", question:"top sellers last month"} (with a key). '
        . 'Every response includes {report, params, columns, rows, summary?, answer} — answer is a short human sentence.',
        'wp-ultra-mcp'
    ),
    'category'    => 'ai',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['ask', 'run', 'list-reports', 'resolve-date'], 'default' => 'run'],
            'question' => ['type' => 'string'],
            'report'   => ['type' => 'string'],
            'params'   => ['type' => 'object'],
            'expr'     => ['type' => 'string'],
            'currency' => ['type' => 'string'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'report'  => ['type' => 'string'],
            'params'  => ['type' => 'object'],
            'columns' => ['type' => 'array'],
            'rows'    => ['type' => 'array'],
            'summary' => ['type' => 'object'],
            'answer'  => ['type' => 'string'],
            'reports' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_nl_analytics_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_nl_analytics_cb(array $input) {
    if (!function_exists('wpultra_nlq_reports')) {
        return wpultra_err('nlq_engine_missing', 'The analytics engine (includes/ai/nlquery.php) is not loaded.');
    }

    $action = is_string($input['action'] ?? null) ? $input['action'] : 'run';
    $currency = is_string($input['currency'] ?? null) ? $input['currency'] : '';

    switch ($action) {
        case 'list-reports':
            return wpultra_ok(['reports' => wpultra_nl_analytics_catalog_public()]);

        case 'resolve-date':
            $expr = (string) ($input['expr'] ?? '');
            $resolved = wpultra_nlq_resolve_date($expr, time());
            if ($resolved === '') {
                return wpultra_err('bad_date_expr', "Could not resolve date expression: \"$expr\".");
            }
            return wpultra_ok(['expr' => $expr, 'date' => $resolved]);

        case 'ask':
            return wpultra_nl_analytics_ask($input, $currency);

        case 'run':
        default:
            $report = (string) ($input['report'] ?? '');
            $params = is_array($input['params'] ?? null) ? $input['params'] : [];
            if ($report === '') {
                return wpultra_err('missing_report', 'run requires a "report" id. Call action:"list-reports" to see the catalog.');
            }
            return wpultra_nl_analytics_execute($report, $params, $currency);
    }
}

/** A JSON-friendly view of the catalog for list-reports. */
function wpultra_nl_analytics_catalog_public(): array {
    $out = [];
    foreach (wpultra_nlq_reports() as $id => $spec) {
        $params = [];
        foreach (($spec['params_spec'] ?? []) as $pname => $pspec) {
            $entry = ['type' => (string) ($pspec['type'] ?? 'string'), 'required' => !empty($pspec['required'])];
            if (array_key_exists('default', $pspec)) { $entry['default'] = $pspec['default']; }
            if (isset($pspec['min'])) { $entry['min'] = $pspec['min']; }
            if (isset($pspec['max'])) { $entry['max'] = $pspec['max']; }
            $params[$pname] = $entry;
        }
        $out[] = [
            'report'      => $id,
            'label'       => (string) ($spec['label'] ?? $id),
            'description' => (string) ($spec['description'] ?? ''),
            'needs_woo'   => !empty($spec['needs_woo']),
            'params'      => $params,
        ];
    }
    return $out;
}

/** Map a raw question → intent (needs a key), then run it. */
function wpultra_nl_analytics_ask(array $input, string $currency) {
    $question = trim((string) ($input['question'] ?? ''));
    if ($question === '') {
        return wpultra_err('missing_question', 'ask requires a "question".');
    }
    if (!function_exists('wpultra_ai_has_key') || !wpultra_ai_has_key()) {
        // No server-side key — instruct the caller to pre-map and use `run`.
        return wpultra_err(
            'no_api_key',
            'No server-side OpenAI key is configured, so a raw question cannot be mapped here. '
            . 'Map the question to a report yourself and call action:"run" with {report, params}. '
            . 'Call action:"list-reports" to see the catalog.',
            ['reports' => wpultra_nl_analytics_catalog_public()]
        );
    }

    $catalog = wpultra_nlq_reports();
    $prompt = wpultra_nlq_intent_prompt($question, $catalog);
    $ai = wpultra_ai_chat($prompt['system'], $prompt['user'], ['json' => true, 'temperature' => 0.0]);
    if (is_wp_error($ai)) { return $ai; }

    $intent = wpultra_nlq_parse_intent((string) $ai);
    if (is_string($intent)) {
        return wpultra_err('intent_parse_failed', $intent);
    }
    return wpultra_nl_analytics_execute((string) $intent['report'], (array) ($intent['params'] ?? []), $currency);
}

/** Validate + normalize + run a structured intent, and format the answer. */
function wpultra_nl_analytics_execute(string $report, array $raw_params, string $currency) {
    $prepared = wpultra_nlq_prepare($report, $raw_params, time());
    if (is_string($prepared)) {
        return wpultra_err('invalid_intent', $prepared);
    }
    $report = $prepared['report'];
    $params = $prepared['params'];

    $result = wpultra_nlq_run($report, $params);
    if (is_wp_error($result)) {
        wpultra_audit_log('nl-analytics', "report=$report failed: " . $result->get_error_message(), false);
        return $result;
    }

    $answer = wpultra_nlq_format_answer($result, $report, $currency);
    $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
    wpultra_audit_log('nl-analytics', "report=$report rows=" . count($rows), true);

    $out = [
        'report'  => $report,
        'params'  => $params,
        'columns' => $result['columns'] ?? [],
        'rows'    => $rows,
        'answer'  => $answer,
    ];
    if (isset($result['summary']) && is_array($result['summary'])) { $out['summary'] = $result['summary']; }

    return wpultra_ok($out);
}
