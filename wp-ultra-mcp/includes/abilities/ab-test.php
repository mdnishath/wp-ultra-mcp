<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/marketing/ab.php — require it defensively so
// this ability works regardless of load order (mirrors woo-bulk-edit).
if (!function_exists('wpultra_ab_validate') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/marketing/ab.php')) {
    require_once WPULTRA_DIR . 'includes/marketing/ab.php';
}

wp_register_ability('wpultra/ab-test', [
    'label'       => __('A/B Test', 'wp-ultra-mcp'),
    'description' => __(
        'Run A/B tests on a post/page: headline (title) variants or content find/replace variants (CTA button text, hero image URL swaps), with front-end conversion tracking and an automatic statistical winner. '
        . 'Actions: '
        . 'create (fields: name, post_id, kind "title"|"content", variants[], goal, optional min_samples default 100, optional auto_apply) — creates a DRAFT test and returns its generated id. '
        . 'Variants: each {key: short lowercase id like "a"/"b", ...payload}. kind "title": payload is {title: "full replacement post title"}. kind "content": payload is {find: "exact text/URL in post_content", replace: "variant text/URL"} — this covers CTA text swaps AND hero image swaps (find the current image URL, replace with the variant URL). Mark the baseline variant {key:"a", control:true} — it changes nothing. '
        . 'Goal: {type:"click", selector:".btn-cta"} (CSS selector, fires on the tested page) or {type:"visit", url_contains:"/thank-you"} (fires when an assigned visitor lands on a matching URL anywhere on the site). '
        . 'start (id) — verifies the post exists and sets status running: visitors get a sticky random variant via cookie (30 days), views are counted server-side on the tested page (debounced 1/day/visitor), conversions beacon to the wpultra/v1/track endpoint (once per browser). '
        . 'stop (id) — sets status completed and records the computed winner WITHOUT applying it to the post. '
        . 'get (id) / list — returns test(s) shaped with computed per-variant rates, the z-score between the top two variants, significance (z>=1.64, ~90% one-sided) and the projected winner (declared only when EVERY variant has views >= min_samples AND z>=1.64). '
        . 'apply-winner (id, confirm:true, optional variant to force a specific key) — permanently writes the winning variant to the post (wp_update_post title, or find/replace on post_content) and completes the test. '
        . 'With auto_apply:true on the test, the winner is applied automatically the moment significance is reached (guarded to fire once). '
        . 'record (id, variant, metric "view"|"conversion") — manual counter increment for testing a running test end-to-end without real traffic; a recorded conversion also runs the auto-winner check. '
        . 'delete (id, confirm:true) — removes a test (refused while running; stop it first). '
        . 'Examples: {action:"create", name:"Plumber hero CTA", post_id:12, kind:"content", variants:[{key:"a",control:true},{key:"b",find:"Get a Quote",replace:"Book Your Free Inspection"}], goal:{type:"click", selector:".hero .elementor-button"}, min_samples:100, auto_apply:false} then {action:"start", id:"ab-x7k2m9"}. '
        . '{action:"create", name:"Headline test", post_id:12, kind:"title", variants:[{key:"a",control:true},{key:"b",title:"24/7 Emergency Plumber — Call Now"}], goal:{type:"visit", url_contains:"/contact"}}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'marketing',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['create', 'start', 'stop', 'get', 'list', 'delete', 'apply-winner', 'record'],
            ],
            'id'      => ['type' => 'string', 'description' => 'Test id (ab-xxxxxx) — required for every action except create and list.'],
            'name'    => ['type' => 'string'],
            'post_id' => ['type' => 'integer'],
            'kind'    => ['type' => 'string', 'enum' => ['title', 'content']],
            'variants' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'key'     => ['type' => 'string'],
                        'title'   => ['type' => 'string'],
                        'find'    => ['type' => 'string'],
                        'replace' => ['type' => 'string'],
                        'control' => ['type' => 'boolean'],
                    ],
                    'required' => ['key'],
                ],
            ],
            'goal' => [
                'type'       => 'object',
                'properties' => [
                    'type'         => ['type' => 'string', 'enum' => ['click', 'visit']],
                    'selector'     => ['type' => 'string'],
                    'url_contains' => ['type' => 'string'],
                ],
            ],
            'min_samples' => ['type' => 'integer'],
            'auto_apply'  => ['type' => 'boolean'],
            'variant'     => ['type' => 'string', 'description' => 'Variant key — for record, or to force apply-winner onto a specific variant.'],
            'metric'      => ['type' => 'string', 'enum' => ['view', 'conversion']],
            'confirm'     => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'test'    => ['type' => 'object'],
            'tests'   => ['type' => 'array'],
            'deleted' => ['type' => 'boolean'],
            'applied' => ['type' => 'boolean'],
            'note'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_ab_test_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_ab_test_ability(array $input) {
    if (!function_exists('wpultra_ab_validate')) {
        return wpultra_err('engine_missing', 'A/B engine (includes/marketing/ab.php) is not loaded.');
    }
    $action = (string) ($input['action'] ?? '');

    if ($action === 'list') {
        return wpultra_ok(['tests' => array_values(array_map('wpultra_ab_shape', wpultra_ab_get_tests()))]);
    }

    if ($action === 'create') {
        $test = [
            'name'        => trim((string) ($input['name'] ?? '')),
            'post_id'     => (int) ($input['post_id'] ?? 0),
            'kind'        => (string) ($input['kind'] ?? ''),
            'variants'    => is_array($input['variants'] ?? null) ? array_values($input['variants']) : [],
            'goal'        => is_array($input['goal'] ?? null) ? $input['goal'] : null,
            'auto_apply'  => !empty($input['auto_apply']),
        ];
        if (isset($input['min_samples'])) { $test['min_samples'] = (int) $input['min_samples']; }
        $valid = wpultra_ab_validate($test);
        if ($valid !== true) {
            return wpultra_err('invalid_test', (string) $valid);
        }
        $tests = wpultra_ab_get_tests();
        do { $id = wpultra_ab_new_id(); } while (isset($tests[$id]));
        $test['id'] = $id;
        $test = wpultra_ab_normalize($test);
        $tests[$id] = $test;
        wpultra_ab_save_tests($tests);
        wpultra_audit_log('ab-test', "create $id kind={$test['kind']} post={$test['post_id']} name={$test['name']}", true);
        return wpultra_ok(['test' => wpultra_ab_shape($test), 'note' => "Draft created. Run {action:'start', id:'$id'} to go live."]);
    }

    // All remaining actions target an existing test.
    $id   = (string) ($input['id'] ?? '');
    $test = $id !== '' ? wpultra_ab_get_test($id) : null;
    if ($test === null) {
        return wpultra_err('not_found', $id === '' ? 'id is required for this action.' : "No A/B test with id '$id'. Use action:list.");
    }

    switch ($action) {
        case 'get':
            return wpultra_ok(['test' => wpultra_ab_shape($test)]);

        case 'start':
            if (($test['status'] ?? '') === 'running') {
                return wpultra_ok(['test' => wpultra_ab_shape($test), 'note' => 'Already running.']);
            }
            $post = function_exists('get_post') ? get_post((int) $test['post_id']) : null;
            if (!$post) {
                return wpultra_err('post_not_found', "Post {$test['post_id']} does not exist — fix post_id before starting.");
            }
            $test['status'] = 'running';
            $test['completed_at'] = null;
            wpultra_ab_save_test($test);
            wpultra_audit_log('ab-test', "start $id post={$test['post_id']}", true);
            return wpultra_ok(['test' => wpultra_ab_shape($test), 'note' => 'Running. Visitors are now being split; conversions beacon to wpultra/v1/track.']);

        case 'stop':
            if (($test['status'] ?? '') !== 'running') {
                return wpultra_err('not_running', "Test '$id' is not running (status: {$test['status']}).");
            }
            $test['status']       = 'completed';
            $test['completed_at'] = gmdate('Y-m-d H:i:s');
            if (empty($test['winner'])) { $test['winner'] = wpultra_ab_winner($test); }
            wpultra_ab_save_test($test);
            wpultra_audit_log('ab-test', "stop $id winner=" . ((string) ($test['winner'] ?? '') ?: 'none'), true);
            return wpultra_ok([
                'test' => wpultra_ab_shape($test),
                'note' => $test['winner']
                    ? "Winner '{$test['winner']}' recorded but NOT applied — use action:apply-winner with confirm:true."
                    : 'No statistically significant winner (need every variant at min_samples views and z>=1.64).',
            ]);

        case 'delete':
            if (($test['status'] ?? '') === 'running') {
                return wpultra_err('running', "Test '$id' is running — stop it before deleting.");
            }
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('unconfirmed', "Deleting '$id' discards its stats. Re-run with confirm: true.");
            }
            $tests = wpultra_ab_get_tests();
            unset($tests[$id]);
            wpultra_ab_save_tests($tests);
            wpultra_audit_log('ab-test', "delete $id", true);
            return wpultra_ok(['deleted' => true]);

        case 'apply-winner':
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('unconfirmed', 'apply-winner permanently rewrites the post. Re-run with confirm: true.');
            }
            $key = (string) ($input['variant'] ?? '');
            if ($key === '') { $key = (string) ($test['winner'] ?? ''); }
            if ($key === '') { $key = (string) (wpultra_ab_winner($test) ?? ''); }
            if ($key === '') {
                return wpultra_err('no_winner', 'No winner yet (need every variant at min_samples views and z>=1.64). Pass variant:"<key>" to force one.');
            }
            if (wpultra_ab_variant_for($test, $key) === null) {
                return wpultra_err('bad_variant', "Variant '$key' does not exist on test '$id'.");
            }
            $res = wpultra_ab_apply_winner_to_post($test, $key);
            if (empty($res['applied'])) {
                wpultra_audit_log('ab-test', "apply-winner $id variant=$key FAILED: {$res['note']}", false);
                return wpultra_err('apply_failed', $res['note']);
            }
            $test['winner']       = $key;
            $test['applied']      = true;
            $test['status']       = 'completed';
            $test['completed_at'] = gmdate('Y-m-d H:i:s');
            wpultra_ab_save_test($test);
            wpultra_audit_log('ab-test', "apply-winner $id variant=$key — {$res['note']}", true);
            return wpultra_ok(['test' => wpultra_ab_shape($test), 'applied' => true, 'note' => $res['note']]);

        case 'record':
            if (($test['status'] ?? '') !== 'running') {
                return wpultra_err('not_running', "record only works on a running test (status: {$test['status']}). Start it first.");
            }
            $variant = (string) ($input['variant'] ?? '');
            $metric  = (string) ($input['metric'] ?? '');
            if ($variant === '' || $metric === '') {
                return wpultra_err('missing_fields', 'record requires variant and metric ("view"|"conversion").');
            }
            if (wpultra_ab_variant_for($test, $variant) === null) {
                return wpultra_err('bad_variant', "Variant '$variant' does not exist on test '$id'.");
            }
            if ($metric === 'view' || $metric === 'views') {
                $test = wpultra_ab_stats_add($test, $variant, 'views');
            } elseif ($metric === 'conversion' || $metric === 'conversions') {
                $test = wpultra_ab_stats_add($test, $variant, 'conversions');
                $test = wpultra_ab_maybe_finish($test); // same auto-winner path as a real beacon
            } else {
                return wpultra_err('bad_metric', "metric must be 'view' or 'conversion'.");
            }
            wpultra_ab_save_test($test);
            return wpultra_ok(['test' => wpultra_ab_shape($test)]);
    }

    return wpultra_err('unknown_action', "Unknown action '$action'. Known: create, start, stop, get, list, delete, apply-winner, record.");
}
