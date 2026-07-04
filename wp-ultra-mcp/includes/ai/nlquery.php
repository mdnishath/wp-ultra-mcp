<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * F4 · Natural-language analytics engine.
 *
 * SAFETY MODEL (critical): the model NEVER emits raw SQL. A natural-language
 * question is mapped to a validated STRUCTURED INTENT — a whitelisted report id
 * plus typed params — and the ONLY thing that ever touches the DB is a
 * hand-written, parameterized, read-only report query built server-side from
 * that intent. Two mapping paths:
 *   (a) the calling AI (Claude) supplies {report, params} directly — always
 *       available, needs no API key (the `run` action);
 *   (b) when an OpenAI key is set, a raw `question` string is mapped to an intent
 *       via wpultra_ai_chat(json) constrained to the report catalog (`ask`).
 * Either way every report is SELECT-only and HPOS-safe for WooCommerce.
 *
 * Layout: PURE helpers first (prefix wpultra_nlq_, no WP/WC calls, unit-tested),
 * then guarded WP/WC report wrappers (wpultra_nlq_run_<id>).
 */

/* ============================================================================
 * Runtime contract — cheap no-op boot (this domain is ability-driven).
 * ========================================================================== */
if (!function_exists('wpultra_nlq_boot')) {
    function wpultra_nlq_boot(): void { /* ability-driven; nothing to wire at runtime */ }
}

/* ============================================================================
 * PURE — report catalog.
 * ========================================================================== */

/**
 * PURE. The whitelisted report catalog: report id => spec. Each spec has a
 * label, a human description, needs_woo flag, and params_spec (param name =>
 * {type, required?, default?, min?, max?}). This is the ONLY set of queries the
 * feature can ever run — the AI picks from this, it cannot invent a query.
 *
 * @return array<string,array>
 */
function wpultra_nlq_reports(): array {
    $date = static fn(bool $req = false, string $def = ''): array =>
        ['type' => 'date', 'required' => $req] + ($def !== '' ? ['default' => $def] : []);
    $limit = ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 100];

    return [
        'top_products' => [
            'label'       => 'Top products',
            'description' => 'Best-selling products by quantity and revenue in a date range.',
            'needs_woo'   => true,
            'params_spec' => [
                'date_from' => $date(false, '30d'),
                'date_to'   => $date(false, 'today'),
                'limit'     => $limit,
            ],
        ],
        'sales_summary' => [
            'label'       => 'Sales summary',
            'description' => 'Order count, gross, net and average order value for a date range.',
            'needs_woo'   => true,
            'params_spec' => [
                'date_from' => $date(false, '30d'),
                'date_to'   => $date(false, 'today'),
            ],
        ],
        'sales_by_day' => [
            'label'       => 'Sales by day',
            'description' => 'Daily revenue and order-count series across a date range.',
            'needs_woo'   => true,
            'params_spec' => [
                'date_from' => $date(false, '30d'),
                'date_to'   => $date(false, 'today'),
            ],
        ],
        'top_customers' => [
            'label'       => 'Top customers',
            'description' => 'Customers ranked by total spend in a date range.',
            'needs_woo'   => true,
            'params_spec' => [
                'date_from' => $date(false, '30d'),
                'date_to'   => $date(false, 'today'),
                'limit'     => $limit,
            ],
        ],
        'low_stock' => [
            'label'       => 'Low stock',
            'description' => 'Products at or below a stock-quantity threshold.',
            'needs_woo'   => true,
            'params_spec' => [
                'threshold' => ['type' => 'int', 'default' => 5, 'min' => 0, 'max' => 100000],
                'limit'     => $limit,
            ],
        ],
        'new_users' => [
            'label'       => 'New users',
            'description' => 'User registration count and list within a date range.',
            'needs_woo'   => false,
            'params_spec' => [
                'date_from' => $date(false, '30d'),
                'date_to'   => $date(false, 'today'),
                'limit'     => $limit,
            ],
        ],
        'post_counts' => [
            'label'       => 'Post counts',
            'description' => 'Content counts grouped by status, optionally filtered by post type.',
            'needs_woo'   => false,
            'params_spec' => [
                'post_type' => ['type' => 'string', 'default' => 'post'],
                'status'    => ['type' => 'string', 'default' => ''],
            ],
        ],
        'top_content' => [
            'label'       => 'Top content',
            'description' => 'Most-commented recent posts.',
            'needs_woo'   => false,
            'params_spec' => [
                'limit' => $limit,
            ],
        ],
    ];
}

/** PURE. The list of valid report ids. */
function wpultra_nlq_report_ids(): array {
    return array_keys(wpultra_nlq_reports());
}

/* ============================================================================
 * PURE — relative-date resolver.
 * ========================================================================== */

/**
 * PURE. Resolve a relative or explicit date expression to a Y-m-d string, or ''
 * when it cannot be understood. $now is a unix timestamp (inject for testing).
 *
 * Supported: 'today', 'yesterday', 'Nd' (N days ago, e.g. '7d', '30d'),
 * 'this-month' (1st of the current month), 'last-month' (1st of the previous
 * month), and an explicit 'YYYY-MM-DD'. Anything else → ''.
 */
function wpultra_nlq_resolve_date(string $expr, int $now): string {
    $expr = strtolower(trim($expr));
    if ($expr === '') { return ''; }

    if ($expr === 'today')     { return gmdate('Y-m-d', $now); }
    if ($expr === 'yesterday') { return gmdate('Y-m-d', $now - 86400); }

    // 'Nd' — N days ago.
    if (preg_match('/^(\d{1,5})d$/', $expr, $m)) {
        return gmdate('Y-m-d', $now - ((int) $m[1]) * 86400);
    }

    if ($expr === 'this-month') {
        return gmdate('Y-m-01', $now);
    }
    if ($expr === 'last-month') {
        $y = (int) gmdate('Y', $now);
        $mo = (int) gmdate('n', $now);
        $mo -= 1;
        if ($mo < 1) { $mo = 12; $y -= 1; }
        return sprintf('%04d-%02d-01', $y, $mo);
    }

    // Explicit YYYY-MM-DD (validated as a real calendar date).
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expr, $m)) {
        if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
    }
    return '';
}

/* ============================================================================
 * PURE — intent validation + param normalization.
 * ========================================================================== */

/**
 * PURE. Validate a structured intent against the catalog. Returns true, or a
 * human error string. Checks: report exists, params is a map, every required
 * param is present, and provided params carry the right primitive type.
 *
 * @param array $intent  {report: string, params?: array}
 * @param array $catalog result of wpultra_nlq_reports()
 * @return true|string
 */
function wpultra_nlq_validate_intent(array $intent, array $catalog) {
    $report = isset($intent['report']) && is_string($intent['report']) ? $intent['report'] : '';
    if ($report === '') { return 'Missing "report".'; }
    if (!isset($catalog[$report])) {
        return "Unknown report \"$report\". Known reports: " . implode(', ', array_keys($catalog)) . '.';
    }
    $spec = $catalog[$report]['params_spec'] ?? [];
    $params = $intent['params'] ?? [];
    if (!is_array($params)) { return '"params" must be an object.'; }

    foreach ($spec as $name => $pspec) {
        $required = !empty($pspec['required']);
        $has = array_key_exists($name, $params);
        if ($required && !$has) { return "Missing required param \"$name\" for report \"$report\"."; }
        if (!$has) { continue; }
        $err = wpultra_nlq_check_param_type($name, $params[$name], (string) ($pspec['type'] ?? 'string'));
        if ($err !== '') { return $err; }
    }
    return true;
}

/**
 * PURE. Type-check a single raw param value against a spec type
 * (int|number|string|date). Returns '' when acceptable, else an error string.
 * Numeric strings are accepted for int/number (they coerce cleanly later).
 */
function wpultra_nlq_check_param_type(string $name, $value, string $type): string {
    switch ($type) {
        case 'int':
            if (is_int($value)) { return ''; }
            if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) { return ''; }
            return "Param \"$name\" must be an integer.";
        case 'number':
            if (is_int($value) || is_float($value)) { return ''; }
            if (is_string($value) && is_numeric(trim($value))) { return ''; }
            return "Param \"$name\" must be a number.";
        case 'date':
            if (!is_string($value)) { return "Param \"$name\" must be a date string."; }
            return '';
        case 'string':
        default:
            if (is_string($value) || is_int($value) || is_float($value)) { return ''; }
            return "Param \"$name\" must be a string.";
    }
}

/**
 * PURE. Coerce + default raw params for a report into a clean typed array.
 * - date params: resolved via wpultra_nlq_resolve_date; unresolvable → default;
 *   an inverted [date_from, date_to] range is swapped so from <= to.
 * - int params: cast + clamped to [min, max] when present.
 * - string params: cast to string, trimmed.
 * $now (unix ts) is injected so tests are deterministic.
 *
 * @param string $report the report id (used for range-swap semantics)
 * @param array  $raw    caller-supplied params
 * @param array  $spec   the report's params_spec
 * @return array normalized params
 */
function wpultra_nlq_normalize_params(string $report, array $raw, array $spec, int $now = 0): array {
    if ($now === 0) { $now = time(); }
    $out = [];

    foreach ($spec as $name => $pspec) {
        $type = (string) ($pspec['type'] ?? 'string');
        $has  = array_key_exists($name, $raw);

        switch ($type) {
            case 'date':
                $def = isset($pspec['default']) ? (string) $pspec['default'] : '';
                $expr = $has ? (string) $raw[$name] : $def;
                $resolved = wpultra_nlq_resolve_date($expr, $now);
                if ($resolved === '' && $def !== '') {
                    $resolved = wpultra_nlq_resolve_date($def, $now);
                }
                $out[$name] = $resolved; // may be '' when no default and unresolvable
                break;

            case 'int':
                $def = (int) ($pspec['default'] ?? 0);
                $val = $has ? (int) $raw[$name] : $def;
                if (isset($pspec['min'])) { $val = max((int) $pspec['min'], $val); }
                if (isset($pspec['max'])) { $val = min((int) $pspec['max'], $val); }
                $out[$name] = $val;
                break;

            case 'number':
                $def = (float) ($pspec['default'] ?? 0);
                $val = $has ? (float) $raw[$name] : $def;
                if (isset($pspec['min'])) { $val = max((float) $pspec['min'], $val); }
                if (isset($pspec['max'])) { $val = min((float) $pspec['max'], $val); }
                $out[$name] = $val;
                break;

            case 'string':
            default:
                $def = (string) ($pspec['default'] ?? '');
                $out[$name] = $has ? trim((string) $raw[$name]) : $def;
                break;
        }
    }

    // Inverted date range → swap so from <= to (only when both are set).
    if (isset($out['date_from'], $out['date_to']) && $out['date_from'] !== '' && $out['date_to'] !== ''
        && $out['date_from'] > $out['date_to']) {
        $tmp = $out['date_from'];
        $out['date_from'] = $out['date_to'];
        $out['date_to'] = $tmp;
    }

    return $out;
}

/* ============================================================================
 * PURE — NL → intent mapping (prompt + parse).
 * ========================================================================== */

/**
 * PURE. Build the {system, user} messages that ask an LLM to map a raw question
 * to a JSON intent CONSTRAINED to the catalog. The catalog ids and their params
 * are embedded so the model can only choose a whitelisted report.
 *
 * @return array{system:string,user:string}
 */
function wpultra_nlq_intent_prompt(string $question, array $catalog): array {
    $lines = [];
    foreach ($catalog as $id => $spec) {
        $params = [];
        foreach (($spec['params_spec'] ?? []) as $pname => $pspec) {
            $params[] = $pname . ':' . (string) ($pspec['type'] ?? 'string');
        }
        $lines[] = "- $id — " . (string) ($spec['description'] ?? '')
            . ' [params: ' . ($params ? implode(', ', $params) : 'none') . ']';
    }
    $catalog_text = implode("\n", $lines);

    $system = "You translate a natural-language analytics question into a STRUCTURED INTENT. "
        . "You MUST choose exactly one report id from the catalog below and provide its params. "
        . "Never invent SQL or a report id that is not listed. "
        . "Dates may be relative expressions the server understands: today, yesterday, Nd (e.g. 7d, 30d), this-month, last-month, or YYYY-MM-DD. "
        . "Respond with a single JSON object ONLY: {\"report\": \"<id>\", \"params\": { ... }}.\n\n"
        . "Report catalog:\n" . $catalog_text;

    $user = "Question: " . trim($question) . "\n\nReturn the JSON intent.";

    return ['system' => $system, 'user' => $user];
}

/**
 * PURE. Parse an LLM response into an intent array {report, params}. Tolerates a
 * fenced ```json block or surrounding prose by extracting the first {...} object.
 * Returns the intent array, or a human error string.
 *
 * @return array|string
 */
function wpultra_nlq_parse_intent(string $ai_json) {
    $text = trim($ai_json);
    if ($text === '') { return 'Empty AI response.'; }

    // Strip a fenced code block if present.
    if (preg_match('/```(?:json)?\s*(.+?)```/is', $text, $m)) {
        $text = trim($m[1]);
    }

    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        // Fall back: grab the first balanced-looking {...} slice.
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        }
    }
    if (!is_array($decoded)) { return 'AI response was not valid JSON.'; }

    $report = isset($decoded['report']) && is_string($decoded['report']) ? $decoded['report'] : '';
    if ($report === '') { return 'AI response is missing a "report" id.'; }
    $params = isset($decoded['params']) && is_array($decoded['params']) ? $decoded['params'] : [];

    return ['report' => $report, 'params' => $params];
}

/* ============================================================================
 * PURE — answer formatting.
 * ========================================================================== */

/**
 * PURE. Turn a report result into a short human sentence. Currency-agnostic:
 * pass $cur (e.g. '$', '৳', '') to prefix money values. Empty rows → a "no data"
 * sentence. Falls back to a generic row-count sentence for unknown reports.
 *
 * @param array  $result {columns:[], rows:[[...]], summary?:array}
 * @param string $report report id
 * @param string $cur    currency prefix (default '')
 */
function wpultra_nlq_format_answer(array $result, string $report, string $cur = ''): string {
    $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
    $n = count($rows);
    $money = static fn($v): string => $cur . number_format((float) $v, 2);

    if ($n === 0) {
        return 'No data for this report in the given range.';
    }

    switch ($report) {
        case 'top_products':
            // rows: [name, qty, revenue]
            $top = $rows[0];
            return sprintf(
                'Top seller: %s (%d sold, %s). %d product%s in range.',
                (string) ($top[0] ?? ''), (int) ($top[1] ?? 0), $money($top[2] ?? 0),
                $n, $n === 1 ? '' : 's'
            );

        case 'sales_summary':
            $s = isset($result['summary']) && is_array($result['summary']) ? $result['summary'] : [];
            return sprintf(
                '%d orders, %s gross, %s net, %s average order value.',
                (int) ($s['orders'] ?? 0), $money($s['gross'] ?? 0),
                $money($s['net'] ?? 0), $money($s['avg'] ?? 0)
            );

        case 'sales_by_day':
            $total = 0.0;
            foreach ($rows as $r) { $total += (float) ($r[1] ?? 0); }
            return sprintf('%d day%s, %s total revenue.', $n, $n === 1 ? '' : 's', $money($total));

        case 'top_customers':
            $top = $rows[0];
            return sprintf('Top customer: %s (%s spent). %d customer%s in range.',
                (string) ($top[0] ?? ''), $money($top[1] ?? 0), $n, $n === 1 ? '' : 's');

        case 'low_stock':
            return sprintf('%d product%s at or below the stock threshold.', $n, $n === 1 ? '' : 's');

        case 'new_users':
            return sprintf('%d new user%s in range.', $n, $n === 1 ? '' : 's');

        case 'post_counts':
            $total = 0;
            foreach ($rows as $r) { $total += (int) ($r[1] ?? 0); }
            return sprintf('%d item%s across %d status group%s.', $total, $total === 1 ? '' : 's', $n, $n === 1 ? '' : 's');

        case 'top_content':
            $top = $rows[0];
            return sprintf('Most-commented: %s (%d comments). %d post%s listed.',
                (string) ($top[0] ?? ''), (int) ($top[1] ?? 0), $n, $n === 1 ? '' : 's');

        default:
            return sprintf('%d row%s.', $n, $n === 1 ? '' : 's');
    }
}

/* ============================================================================
 * PURE — the top-level structured pipeline (validate → normalize).
 * ========================================================================== */

/**
 * PURE. Given a report id + raw params, validate against the catalog then
 * normalize. Returns {report, params} on success, or a human error string.
 * (The DB run happens in the guarded wrappers below.)
 *
 * @return array{report:string,params:array}|string
 */
function wpultra_nlq_prepare(string $report, array $raw_params, int $now = 0) {
    $catalog = wpultra_nlq_reports();
    $intent = ['report' => $report, 'params' => $raw_params];
    $valid = wpultra_nlq_validate_intent($intent, $catalog);
    if ($valid !== true) { return $valid; }
    $spec = $catalog[$report]['params_spec'] ?? [];
    $params = wpultra_nlq_normalize_params($report, $raw_params, $spec, $now);
    return ['report' => $report, 'params' => $params];
}

/* ============================================================================
 * GUARDED — WP/WC report runners. Each returns {columns, rows, summary?} or a
 * WP_Error. All read-only; Woo reports use wc_get_orders (HPOS-safe), never
 * get_posts('shop_order').
 * ========================================================================== */

/** True when WooCommerce is active (mirrors the rest of the plugin). */
function wpultra_nlq_woo_active(): bool {
    if (function_exists('wpultra_woo_active')) { return (bool) wpultra_woo_active(); }
    return class_exists('WooCommerce') || function_exists('WC');
}

/** Fetch completed/processing paid orders in [date_from, date_to] (inclusive). HPOS-safe. */
function wpultra_nlq_fetch_orders(string $date_from, string $date_to): array {
    if (!function_exists('wc_get_orders')) { return []; }
    $after  = ($date_from !== '' ? $date_from : '1970-01-01') . ' 00:00:00';
    $before = ($date_to !== '' ? $date_to : gmdate('Y-m-d')) . ' 23:59:59';
    $args = [
        'limit'        => -1,
        'type'         => 'shop_order',
        'status'       => ['wc-completed', 'wc-processing', 'wc-on-hold'],
        'date_created' => $after . '...' . $before,
        'return'       => 'objects',
    ];
    $orders = wc_get_orders($args);
    return is_array($orders) ? $orders : [];
}

function wpultra_nlq_run_top_products(array $params) {
    if (!wpultra_nlq_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $limit = (int) ($params['limit'] ?? 10);
    $orders = wpultra_nlq_fetch_orders((string) ($params['date_from'] ?? ''), (string) ($params['date_to'] ?? ''));

    $agg = []; // pid => [name, qty, revenue]
    foreach ($orders as $order) {
        if (!is_object($order) || !method_exists($order, 'get_items')) { continue; }
        foreach ($order->get_items() as $item) {
            $pid = (int) $item->get_product_id();
            $name = (string) $item->get_name();
            $qty = (int) $item->get_quantity();
            $rev = (float) $item->get_total();
            if (!isset($agg[$pid])) { $agg[$pid] = [$name, 0, 0.0]; }
            $agg[$pid][1] += $qty;
            $agg[$pid][2] += $rev;
        }
    }
    usort($agg, static fn($a, $b) => $b[1] <=> $a[1]);
    $rows = array_slice(array_values($agg), 0, $limit);

    return ['columns' => ['product', 'qty', 'revenue'], 'rows' => $rows];
}

function wpultra_nlq_run_sales_summary(array $params) {
    if (!wpultra_nlq_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $orders = wpultra_nlq_fetch_orders((string) ($params['date_from'] ?? ''), (string) ($params['date_to'] ?? ''));

    $count = 0; $gross = 0.0; $net = 0.0;
    foreach ($orders as $order) {
        if (!is_object($order) || !method_exists($order, 'get_total')) { continue; }
        $count++;
        $total = (float) $order->get_total();
        $gross += $total;
        $tax = method_exists($order, 'get_total_tax') ? (float) $order->get_total_tax() : 0.0;
        $shipping = method_exists($order, 'get_shipping_total') ? (float) $order->get_shipping_total() : 0.0;
        $net += ($total - $tax - $shipping);
    }
    $avg = $count > 0 ? $gross / $count : 0.0;
    $summary = ['orders' => $count, 'gross' => round($gross, 2), 'net' => round($net, 2), 'avg' => round($avg, 2)];

    return [
        'columns' => ['metric', 'value'],
        'rows'    => [
            ['orders', $count],
            ['gross', round($gross, 2)],
            ['net', round($net, 2)],
            ['avg_order_value', round($avg, 2)],
        ],
        'summary' => $summary,
    ];
}

function wpultra_nlq_run_sales_by_day(array $params) {
    if (!wpultra_nlq_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $orders = wpultra_nlq_fetch_orders((string) ($params['date_from'] ?? ''), (string) ($params['date_to'] ?? ''));

    $by_day = []; // Y-m-d => [revenue, count]
    foreach ($orders as $order) {
        if (!is_object($order) || !method_exists($order, 'get_date_created')) { continue; }
        $dt = $order->get_date_created();
        $day = $dt ? $dt->date('Y-m-d') : gmdate('Y-m-d');
        if (!isset($by_day[$day])) { $by_day[$day] = [0.0, 0]; }
        $by_day[$day][0] += (float) $order->get_total();
        $by_day[$day][1] += 1;
    }
    ksort($by_day);
    $rows = [];
    foreach ($by_day as $day => $vals) {
        $rows[] = [$day, round($vals[0], 2), $vals[1]];
    }
    return ['columns' => ['day', 'revenue', 'orders'], 'rows' => $rows];
}

function wpultra_nlq_run_top_customers(array $params) {
    if (!wpultra_nlq_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $limit = (int) ($params['limit'] ?? 10);
    $orders = wpultra_nlq_fetch_orders((string) ($params['date_from'] ?? ''), (string) ($params['date_to'] ?? ''));

    $agg = []; // key => [label, spend]
    foreach ($orders as $order) {
        if (!is_object($order) || !method_exists($order, 'get_total')) { continue; }
        $cid = (int) $order->get_customer_id();
        $email = method_exists($order, 'get_billing_email') ? (string) $order->get_billing_email() : '';
        $key = $cid > 0 ? "u$cid" : ('e' . strtolower($email));
        $label = '';
        if (method_exists($order, 'get_formatted_billing_full_name')) {
            $label = trim((string) $order->get_formatted_billing_full_name());
        }
        if ($label === '') { $label = $email !== '' ? $email : ($cid > 0 ? "customer #$cid" : 'guest'); }
        if (!isset($agg[$key])) { $agg[$key] = [$label, 0.0]; }
        $agg[$key][1] += (float) $order->get_total();
    }
    usort($agg, static fn($a, $b) => $b[1] <=> $a[1]);
    $rows = array_map(static fn($r) => [$r[0], round($r[1], 2)], array_slice(array_values($agg), 0, $limit));

    return ['columns' => ['customer', 'spend'], 'rows' => $rows];
}

function wpultra_nlq_run_low_stock(array $params) {
    if (!wpultra_nlq_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    if (!function_exists('wc_get_products')) { return wpultra_err('wc_api_missing', 'wc_get_products() unavailable.'); }
    $threshold = (int) ($params['threshold'] ?? 5);
    $limit = (int) ($params['limit'] ?? 10);

    $products = wc_get_products([
        'limit'          => max($limit * 5, 100),
        'status'         => 'publish',
        'stock_status'   => '',
        'manage_stock'   => true,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'return'         => 'objects',
    ]);
    $rows = [];
    foreach ((is_array($products) ? $products : []) as $p) {
        if (!is_object($p) || !method_exists($p, 'get_stock_quantity')) { continue; }
        $qty = $p->get_stock_quantity();
        if ($qty === null) { continue; }
        $qty = (int) $qty;
        if ($qty <= $threshold) {
            $rows[] = [(string) $p->get_name(), $qty, (string) $p->get_sku()];
        }
    }
    usort($rows, static fn($a, $b) => $a[1] <=> $b[1]);
    $rows = array_slice($rows, 0, $limit);

    return ['columns' => ['product', 'stock', 'sku'], 'rows' => $rows];
}

function wpultra_nlq_run_new_users(array $params) {
    if (!function_exists('get_users')) { return wpultra_err('wp_api_missing', 'get_users() unavailable.'); }
    $limit = (int) ($params['limit'] ?? 10);
    $from = (string) ($params['date_from'] ?? '');
    $to   = (string) ($params['date_to'] ?? '');

    $date_query = [];
    if ($from !== '') { $date_query['after'] = $from . ' 00:00:00'; }
    if ($to !== '')   { $date_query['before'] = $to . ' 23:59:59'; }
    if ($date_query) { $date_query['inclusive'] = true; }

    $args = ['number' => -1, 'fields' => ['ID', 'user_login', 'user_email', 'user_registered']];
    if ($date_query) { $args['date_query'] = [$date_query]; }

    $users = get_users($args);
    $count = is_array($users) ? count($users) : 0;
    $rows = [];
    foreach (array_slice(is_array($users) ? $users : [], 0, $limit) as $u) {
        $rows[] = [(string) ($u->user_login ?? ''), (string) ($u->user_email ?? ''), (string) ($u->user_registered ?? '')];
    }
    return ['columns' => ['login', 'email', 'registered'], 'rows' => $rows, 'summary' => ['count' => $count]];
}

function wpultra_nlq_run_post_counts(array $params) {
    if (!function_exists('wp_count_posts')) { return wpultra_err('wp_api_missing', 'wp_count_posts() unavailable.'); }
    $post_type = (string) ($params['post_type'] ?? 'post');
    if ($post_type === '') { $post_type = 'post'; }
    $filter_status = (string) ($params['status'] ?? '');

    $counts = wp_count_posts($post_type);
    $rows = [];
    if (is_object($counts)) {
        foreach (get_object_vars($counts) as $status => $n) {
            if ($filter_status !== '' && $status !== $filter_status) { continue; }
            if ((int) $n === 0) { continue; }
            $rows[] = [(string) $status, (int) $n];
        }
    }
    usort($rows, static fn($a, $b) => $b[1] <=> $a[1]);
    return ['columns' => ['status', 'count'], 'rows' => $rows];
}

function wpultra_nlq_run_top_content(array $params) {
    if (!function_exists('get_posts')) { return wpultra_err('wp_api_missing', 'get_posts() unavailable.'); }
    $limit = (int) ($params['limit'] ?? 10);
    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'comment_count',
        'order'          => 'DESC',
    ]);
    $rows = [];
    foreach ((is_array($posts) ? $posts : []) as $p) {
        $rows[] = [(string) ($p->post_title ?? ''), (int) ($p->comment_count ?? 0), (int) ($p->ID ?? 0)];
    }
    return ['columns' => ['title', 'comments', 'id'], 'rows' => $rows];
}

/**
 * GUARDED. Dispatch a validated+normalized intent to its report runner.
 * Returns {columns, rows, summary?} or a WP_Error. Report id must already have
 * passed wpultra_nlq_prepare().
 *
 * @return array|WP_Error
 */
function wpultra_nlq_run(string $report, array $params) {
    $fn = 'wpultra_nlq_run_' . $report;
    if (!function_exists($fn)) {
        return wpultra_err('unknown_report', "No runner for report \"$report\".");
    }
    return $fn($params);
}
