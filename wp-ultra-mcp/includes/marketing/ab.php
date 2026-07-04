<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * A/B testing engine (Roadmap-2 A2): headline/CTA variants + conversion
 * tracking + auto-winner.
 *
 * Storage: single autoloaded option `wpultra_ab_tests` = array keyed by test
 * id ('ab-' + 6 lowercase alnum). Test shape:
 *   { id, name, status: draft|running|completed, post_id, kind: title|content,
 *     variants: [{key, title?, find?, replace?, control?}],
 *     goal: {type:'click', selector} | {type:'visit', url_contains},
 *     min_samples, auto_apply, stats: {<key>:{views,conversions}},
 *     winner, applied, created_at, completed_at }
 *
 * Kinds:
 *  - 'title'   — each variant carries a full replacement post title, swapped
 *                at render via the 'the_title' filter.
 *  - 'content' — each variant carries find+replace applied to post_content at
 *                render via 'the_content'. This covers CTA button text swaps
 *                ("Get a quote" -> "Book now") AND hero image swaps (find the
 *                current image URL, replace with the variant URL).
 *  A variant with control:true (conventionally key 'a') changes nothing — it
 *  is the baseline the challengers are measured against.
 *
 * Runtime (wpultra_ab_boot, called by the controller on plugins_loaded):
 *  - one autoloaded option read; bails immediately when no running tests.
 *  - template_redirect: sticky uniform-random assignment cookie
 *    wpultra_ab_<id> (30 days, path /) + server-side VIEW count on the tested
 *    post's singular page, debounced per session by wpultra_ab_v_<id> (1 day).
 *  - the_title / the_content filters apply the assigned variant.
 *  - wp_footer: goal JS beacons a conversion to POST wpultra/v1/track
 *    (route owned by marketing/track.php, which dispatches kind 'ab' to
 *    wpultra_ab_handle_track). Guarded once per browser via localStorage.
 *
 * Winner: two-proportion pooled z-test between the top two variants by
 * conversion rate; a winner is declared when every variant has
 * views >= min_samples AND z >= 1.64 (one-sided ~90% confidence). With
 * auto_apply:true the winning variant is written to the post the moment the
 * test becomes significant (guarded to fire once).
 *
 * PURE functions first (no WordPress calls — unit-tested in tests/ab.test.php),
 * WP wrappers after.
 */

/* =====================================================================
 * PURE core.
 * ===================================================================== */

/**
 * PURE. Generate a test id: 'ab-' + 6 lowercase alphanumerics.
 * $rand(int $min, int $max): int — injectable for tests; defaults to random_int.
 */
function wpultra_ab_new_id(?callable $rand = null): string {
    $rand    = $rand ?? 'random_int';
    $charset = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $id      = 'ab-';
    for ($i = 0; $i < 6; $i++) {
        $id .= $charset[(int) $rand(0, 35)];
    }
    return $id;
}

/** PURE. Allowed test kinds. */
function wpultra_ab_kinds(): array { return ['title', 'content']; }

/**
 * PURE. Validate a test structure. Returns true, or an error message string.
 * Checks: kind, post_id, >=2 variants with unique cookie-safe keys, per-kind
 * variant payloads (control variants are exempt), goal shape, min_samples.
 */
function wpultra_ab_validate(array $test) {
    if (trim((string) ($test['name'] ?? '')) === '') { return 'name is required'; }
    if ((int) ($test['post_id'] ?? 0) <= 0) { return 'post_id must be a positive integer'; }
    $kind = (string) ($test['kind'] ?? '');
    if (!in_array($kind, wpultra_ab_kinds(), true)) { return "kind must be one of: " . implode(', ', wpultra_ab_kinds()); }

    $variants = $test['variants'] ?? null;
    if (!is_array($variants) || count($variants) < 2) { return 'at least 2 variants are required'; }
    $seen = [];
    foreach ($variants as $v) {
        if (!is_array($v)) { return 'each variant must be an object'; }
        $key = (string) ($v['key'] ?? '');
        if (!preg_match('/^[a-z0-9_-]{1,20}$/', $key)) { return "variant key '$key' is invalid (lowercase alnum/_/-, max 20 chars)"; }
        if (isset($seen[$key])) { return "duplicate variant key '$key'"; }
        $seen[$key] = true;
        if (!empty($v['control'])) { continue; } // control changes nothing — no payload needed
        if ($kind === 'title' && trim((string) ($v['title'] ?? '')) === '') {
            return "variant '$key' needs a non-empty title (kind title), or set control:true";
        }
        if ($kind === 'content' && trim((string) ($v['find'] ?? '')) === '') {
            return "variant '$key' needs a non-empty find string (kind content), or set control:true";
        }
    }

    $goal = $test['goal'] ?? null;
    if (!is_array($goal)) { return 'goal is required'; }
    $type = (string) ($goal['type'] ?? '');
    if ($type === 'click') {
        if (trim((string) ($goal['selector'] ?? '')) === '') { return 'goal type click requires a non-empty selector'; }
    } elseif ($type === 'visit') {
        if (trim((string) ($goal['url_contains'] ?? '')) === '') { return 'goal type visit requires a non-empty url_contains'; }
    } else {
        return "goal.type must be 'click' or 'visit'";
    }

    if (isset($test['min_samples']) && (!is_numeric($test['min_samples']) || (int) $test['min_samples'] < 1)) {
        return 'min_samples must be a positive integer';
    }
    return true;
}

/**
 * PURE. Fill defaults + clamp: status draft, min_samples clamped to
 * [10, 1000000] (default 100), auto_apply bool, zeroed stats buckets per
 * variant, winner/applied/completed_at null-ish, created_at ($now injectable).
 */
function wpultra_ab_normalize(array $test, ?string $now = null): array {
    $test['status']      = (string) ($test['status'] ?? 'draft');
    $test['post_id']     = (int) ($test['post_id'] ?? 0);
    $test['auto_apply']  = !empty($test['auto_apply']);
    $min                 = isset($test['min_samples']) ? (int) $test['min_samples'] : 100;
    $test['min_samples'] = max(10, min(1000000, $min));
    $test['winner']      = isset($test['winner']) && is_string($test['winner']) ? $test['winner'] : null;
    $test['applied']     = !empty($test['applied']);
    $test['created_at']  = (string) ($test['created_at'] ?? ($now ?? gmdate('Y-m-d H:i:s')));
    $test['completed_at'] = $test['completed_at'] ?? null;
    $stats = is_array($test['stats'] ?? null) ? $test['stats'] : [];
    foreach ((array) ($test['variants'] ?? []) as $v) {
        $key = (string) ($v['key'] ?? '');
        if ($key === '') { continue; }
        $stats[$key] = [
            'views'       => (int) ($stats[$key]['views'] ?? 0),
            'conversions' => (int) ($stats[$key]['conversions'] ?? 0),
        ];
    }
    $test['stats'] = $stats;
    return $test;
}

/**
 * PURE. Pick a variant key uniformly at random.
 * $rand(int $min, int $max): int (inclusive bounds), e.g. random_int.
 */
function wpultra_ab_pick_variant(array $keys, callable $rand): string {
    $keys = array_values($keys);
    if ($keys === []) { return ''; }
    $idx = (int) $rand(0, count($keys) - 1);
    if ($idx < 0 || $idx >= count($keys)) { $idx = 0; }
    return (string) $keys[$idx];
}

/** PURE. Find a variant by key. Returns the variant array or null. */
function wpultra_ab_variant_for(array $test, ?string $key): ?array {
    if ($key === null || $key === '') { return null; }
    foreach ((array) ($test['variants'] ?? []) as $v) {
        if (is_array($v) && (string) ($v['key'] ?? '') === $key) { return $v; }
    }
    return null;
}

/**
 * PURE. Apply a content-kind variant's find/replace to a content blob.
 * No-op safe: control variants, empty find, empty replace, and find===replace
 * all return the content unchanged (empty replace means "unchanged", not
 * "delete" — swap to different text instead of deleting).
 */
function wpultra_ab_apply_content(string $content, array $variant): string {
    if (!empty($variant['control'])) { return $content; }
    $find    = (string) ($variant['find'] ?? '');
    $replace = (string) ($variant['replace'] ?? '');
    if ($find === '' || $replace === '' || $find === $replace) { return $content; }
    return str_replace($find, $replace, $content);
}

/**
 * PURE. Increment a stats metric ('views'|'conversions') for a variant.
 * Initializes a missing bucket. Unknown metrics are ignored.
 */
function wpultra_ab_stats_add(array $test, string $variant, string $metric): array {
    if (!in_array($metric, ['views', 'conversions'], true) || $variant === '') { return $test; }
    if (!is_array($test['stats'] ?? null)) { $test['stats'] = []; }
    if (!is_array($test['stats'][$variant] ?? null)) { $test['stats'][$variant] = ['views' => 0, 'conversions' => 0]; }
    $test['stats'][$variant][$metric] = (int) ($test['stats'][$variant][$metric] ?? 0) + 1;
    return $test;
}

/**
 * PURE. Two-proportion pooled z-test between the TOP TWO variants by
 * conversion rate. Returns ['z' => float, 'leader' => ?string, 'runner_up' => ?string].
 * Div-by-zero guarded: zero views, or a pooled rate of exactly 0 or 1
 * (denominator 0), yield z = 0.0.
 */
function wpultra_ab_z(array $test): array {
    $rows = [];
    foreach ((array) ($test['variants'] ?? []) as $v) {
        $key = (string) ($v['key'] ?? '');
        if ($key === '') { continue; }
        $s = $test['stats'][$key] ?? [];
        $n = (int) ($s['views'] ?? 0);
        $c = (int) ($s['conversions'] ?? 0);
        $rows[] = ['key' => $key, 'n' => $n, 'c' => $c, 'rate' => $n > 0 ? $c / $n : 0.0];
    }
    if (count($rows) < 2) { return ['z' => 0.0, 'leader' => null, 'runner_up' => null]; }
    usort($rows, function ($a, $b) { return $b['rate'] <=> $a['rate']; });
    [$one, $two] = [$rows[0], $rows[1]];
    if ($one['n'] < 1 || $two['n'] < 1) { return ['z' => 0.0, 'leader' => $one['key'], 'runner_up' => $two['key']]; }
    $pooled = ($one['c'] + $two['c']) / ($one['n'] + $two['n']);
    $denom  = sqrt($pooled * (1 - $pooled) * (1 / $one['n'] + 1 / $two['n']));
    $z      = $denom > 0 ? ($one['rate'] - $two['rate']) / $denom : 0.0;
    return ['z' => $z, 'leader' => $one['key'], 'runner_up' => $two['key']];
}

/**
 * PURE. Declare a winner, or null. Requires EVERY variant to have
 * views >= min_samples AND the leader to beat the runner-up on a pooled
 * two-proportion z-test at z >= 1.64 (one-sided ~90% confidence).
 */
function wpultra_ab_winner(array $test): ?string {
    $min = max(1, (int) ($test['min_samples'] ?? 100));
    $variants = (array) ($test['variants'] ?? []);
    if (count($variants) < 2) { return null; }
    foreach ($variants as $v) {
        $key = (string) ($v['key'] ?? '');
        if ((int) ($test['stats'][$key]['views'] ?? 0) < $min) { return null; }
    }
    $zt = wpultra_ab_z($test);
    return ($zt['leader'] !== null && $zt['z'] >= 1.64) ? $zt['leader'] : null;
}

/**
 * PURE. Shape a test for output: adds a 'computed' block with per-variant
 * views/conversions/rate, the z-score between the top two, significance at
 * z>=1.64, the current leader, and the projected winner (null until the
 * min-samples + significance bar is met).
 */
function wpultra_ab_shape(array $test): array {
    $rows = [];
    foreach ((array) ($test['variants'] ?? []) as $v) {
        $key = (string) ($v['key'] ?? '');
        $s = $test['stats'][$key] ?? [];
        $n = (int) ($s['views'] ?? 0);
        $c = (int) ($s['conversions'] ?? 0);
        $rows[] = [
            'key'         => $key,
            'control'     => !empty($v['control']),
            'views'       => $n,
            'conversions' => $c,
            'rate'        => $n > 0 ? round($c / $n, 4) : 0.0,
        ];
    }
    $zt = wpultra_ab_z($test);
    $test['computed'] = [
        'variants'         => $rows,
        'z'                => round($zt['z'], 4),
        'significant'      => $zt['z'] >= 1.64,
        'leader'           => $zt['leader'],
        'projected_winner' => wpultra_ab_winner($test),
    ];
    return $test;
}

/* =====================================================================
 * WP wrappers — storage.
 * ===================================================================== */

/** All tests, keyed by id. One autoloaded option read (WP caches it). */
function wpultra_ab_get_tests(): array {
    $tests = function_exists('get_option') ? get_option('wpultra_ab_tests', []) : [];
    return is_array($tests) ? $tests : [];
}

function wpultra_ab_save_tests(array $tests): void {
    if (function_exists('update_option')) { update_option('wpultra_ab_tests', $tests, true); }
}

function wpultra_ab_get_test(string $id): ?array {
    $tests = wpultra_ab_get_tests();
    return isset($tests[$id]) && is_array($tests[$id]) ? $tests[$id] : null;
}

function wpultra_ab_save_test(array $test): void {
    $tests = wpultra_ab_get_tests();
    $tests[(string) $test['id']] = $test;
    wpultra_ab_save_tests($tests);
}

/** Running tests only, keyed by id. */
function wpultra_ab_running_tests(): array {
    return array_filter(wpultra_ab_get_tests(), function ($t) {
        return is_array($t) && (($t['status'] ?? '') === 'running');
    });
}

/* =====================================================================
 * WP wrappers — always-on runtime.
 * ===================================================================== */

/**
 * Register front-end hooks. Called by the controller on plugins_loaded.
 * CHEAP: one autoloaded option read; bails immediately when no running tests.
 */
function wpultra_ab_boot(): void {
    if (!function_exists('add_action')) { return; }
    if (wpultra_ab_running_tests() === []) { return; }
    add_action('template_redirect', 'wpultra_ab_template_redirect');
    add_filter('the_title', 'wpultra_ab_filter_title', 10, 2);
    add_filter('the_content', 'wpultra_ab_filter_content');
    add_action('wp_footer', 'wpultra_ab_footer_js');
}

/** The variant key assigned to this browser for $test (cookie), or null. */
function wpultra_ab_assigned_variant(array $test): ?string {
    $key = (string) ($_COOKIE['wpultra_ab_' . (string) ($test['id'] ?? '')] ?? '');
    return wpultra_ab_variant_for($test, $key) !== null ? $key : null;
}

/**
 * template_redirect: assign a variant cookie when absent (uniform random,
 * 30 days, path /) and count a server-side VIEW for the assigned variant on
 * the tested post's singular page — debounced by a 1-day session cookie so
 * refreshes don't inflate views.
 */
function wpultra_ab_template_redirect(): void {
    if (function_exists('is_admin') && is_admin()) { return; }
    $running = wpultra_ab_running_tests();
    if ($running === []) { return; }
    $all = wpultra_ab_get_tests();
    $dirty = false;

    foreach ($running as $id => $test) {
        $id = (string) $id;
        $assigned = wpultra_ab_assigned_variant($test);
        if ($assigned === null) {
            $keys = [];
            foreach ((array) ($test['variants'] ?? []) as $v) { $keys[] = (string) ($v['key'] ?? ''); }
            $assigned = wpultra_ab_pick_variant(array_filter($keys), 'random_int');
            if ($assigned === '') { continue; }
            $_COOKIE['wpultra_ab_' . $id] = $assigned; // visible to later filters in THIS request
            if (!headers_sent()) { setcookie('wpultra_ab_' . $id, $assigned, time() + 30 * 86400, '/'); }
        }

        $on_post = function_exists('is_singular') && is_singular()
            && function_exists('get_queried_object_id')
            && (int) get_queried_object_id() === (int) ($test['post_id'] ?? 0);
        if ($on_post && empty($_COOKIE['wpultra_ab_v_' . $id])) {
            $all[$id] = wpultra_ab_stats_add($all[$id], $assigned, 'views');
            $dirty = true;
            $_COOKIE['wpultra_ab_v_' . $id] = '1';
            if (!headers_sent()) { setcookie('wpultra_ab_v_' . $id, '1', time() + 86400, '/'); }
        }
    }

    if ($dirty) { wpultra_ab_save_tests($all); }
}

/** the_title filter: swap the title on the tested post for title-kind tests. */
function wpultra_ab_filter_title($title, $post_id = 0) {
    foreach (wpultra_ab_running_tests() as $test) {
        if (($test['kind'] ?? '') !== 'title' || (int) ($test['post_id'] ?? 0) !== (int) $post_id) { continue; }
        $v = wpultra_ab_variant_for($test, wpultra_ab_assigned_variant($test));
        if ($v !== null && empty($v['control']) && trim((string) ($v['title'] ?? '')) !== '') {
            return (string) $v['title'];
        }
    }
    return $title;
}

/** the_content filter: apply find/replace on the tested post for content-kind tests. */
function wpultra_ab_filter_content($content) {
    $pid = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
    if ($pid <= 0 || !is_string($content)) { return $content; }
    foreach (wpultra_ab_running_tests() as $test) {
        if (($test['kind'] ?? '') !== 'content' || (int) ($test['post_id'] ?? 0) !== $pid) { continue; }
        $v = wpultra_ab_variant_for($test, wpultra_ab_assigned_variant($test));
        if ($v !== null) { $content = wpultra_ab_apply_content($content, $v); }
    }
    return $content;
}

/**
 * wp_footer: emit the goal-tracking JS only when relevant.
 *  - click goals: only on the tested post's singular page (where the variant renders).
 *  - visit goals: on any front-end page, but only for browsers holding an
 *    assignment cookie (checked server-side).
 * Conversions beacon once per browser (localStorage guard) to
 * POST wpultra/v1/track {kind:'ab', event:'conversion', id, variant}.
 */
function wpultra_ab_footer_js(): void {
    $cfg = [];
    foreach (wpultra_ab_running_tests() as $test) {
        $assigned = wpultra_ab_assigned_variant($test);
        if ($assigned === null) { continue; } // no assignment cookie -> nothing to attribute
        $goal = is_array($test['goal'] ?? null) ? $test['goal'] : [];
        $type = (string) ($goal['type'] ?? '');
        if ($type === 'click') {
            $on_post = function_exists('is_singular') && is_singular()
                && function_exists('get_queried_object_id')
                && (int) get_queried_object_id() === (int) ($test['post_id'] ?? 0);
            if (!$on_post) { continue; }
            $cfg[] = ['id' => (string) $test['id'], 'variant' => $assigned, 'goal' => 'click', 'selector' => (string) ($goal['selector'] ?? '')];
        } elseif ($type === 'visit') {
            $cfg[] = ['id' => (string) $test['id'], 'variant' => $assigned, 'goal' => 'visit', 'contains' => (string) ($goal['url_contains'] ?? '')];
        }
    }
    if ($cfg === [] || !function_exists('rest_url')) { return; }
    $json = wp_json_encode(['url' => rest_url('wpultra/v1/track'), 'tests' => $cfg]);
    if (!is_string($json)) { return; }
    // Config is emitted via wp_json_encode into a JSON parse — no user-controlled markup.
    echo '<script id="wpultra-ab-goals">(function(){'
        . 'var C=' . $json . ';'
        . 'function fired(i){try{return localStorage.getItem("wpultra_ab_c_"+i)==="1"}catch(e){return false}}'
        . 'function mark(i){try{localStorage.setItem("wpultra_ab_c_"+i,"1")}catch(e){}}'
        . 'function send(t){if(fired(t.id))return;mark(t.id);'
        . 'var b=JSON.stringify({kind:"ab",event:"conversion",id:t.id,variant:t.variant});'
        . 'if(navigator.sendBeacon){try{navigator.sendBeacon(C.url,new Blob([b],{type:"application/json"}));return}catch(e){}}'
        . 'try{fetch(C.url,{method:"POST",headers:{"Content-Type":"application/json"},body:b,keepalive:true,credentials:"omit"})}catch(e){}}'
        . 'C.tests.forEach(function(t){'
        . 'if(t.goal==="click"){document.addEventListener("click",function(e){try{if(e.target&&e.target.closest&&e.target.closest(t.selector)){send(t)}}catch(x){}},true);}'
        . 'else if(t.goal==="visit"){if(window.location.href.indexOf(t.contains)!==-1){send(t)}}'
        . '});'
        . '})();</script>';
}

/* =====================================================================
 * WP wrappers — conversion intake + auto-winner.
 * ===================================================================== */

/**
 * Handle a beacon payload dispatched by marketing/track.php (kind 'ab').
 * Payload is hostile: {event, id, variant}. Only event 'conversion' is
 * accepted (views are counted server-side). Validates the test exists, is
 * running, and the variant key is real; increments; then runs the
 * auto-winner check.
 */
function wpultra_ab_handle_track(array $payload): bool {
    if ((string) ($payload['event'] ?? '') !== 'conversion') { return false; }
    $id      = (string) ($payload['id'] ?? '');
    $variant = (string) ($payload['variant'] ?? '');
    $tests   = wpultra_ab_get_tests();
    if ($id === '' || !isset($tests[$id]) || !is_array($tests[$id])) { return false; }
    $test = $tests[$id];
    if (($test['status'] ?? '') !== 'running') { return false; }
    if (wpultra_ab_variant_for($test, $variant) === null) { return false; }

    $test = wpultra_ab_stats_add($test, $variant, 'conversions');
    $test = wpultra_ab_maybe_finish($test);
    $tests[$id] = $test;
    wpultra_ab_save_tests($tests);
    return true;
}

/**
 * Auto-winner check (guarded to fire once): when no winner is recorded yet
 * and wpultra_ab_winner() declares one, record it; with auto_apply:true also
 * write the winning variant to the post and complete the test.
 */
function wpultra_ab_maybe_finish(array $test): array {
    if (!empty($test['winner']) || !empty($test['applied'])) { return $test; } // once only
    $w = wpultra_ab_winner($test);
    if ($w === null) { return $test; }
    $test['winner'] = $w;
    if (!empty($test['auto_apply'])) {
        $res = wpultra_ab_apply_winner_to_post($test, $w);
        $test['applied']      = !empty($res['applied']);
        $test['status']       = 'completed';
        $test['completed_at'] = gmdate('Y-m-d H:i:s');
        if (function_exists('wpultra_audit_log')) {
            wpultra_audit_log('ab-test', "auto-winner '{$w}' for {$test['id']} — {$res['note']}", !empty($res['applied']));
        }
    }
    return $test;
}

/**
 * Permanently write the winning variant to the post.
 *  - kind title:   wp_update_post post_title.
 *  - kind content: apply the variant's find/replace to post_content and save.
 *  - control winner: post is already the winner — nothing to write.
 * Returns ['applied' => bool, 'note' => string].
 */
function wpultra_ab_apply_winner_to_post(array $test, string $key): array {
    $v = wpultra_ab_variant_for($test, $key);
    if ($v === null) { return ['applied' => false, 'note' => "variant '$key' not found"]; }
    if (!empty($v['control'])) { return ['applied' => true, 'note' => 'control variant won — post left unchanged']; }
    if (!function_exists('get_post') || !function_exists('wp_update_post')) {
        return ['applied' => false, 'note' => 'WordPress post functions unavailable'];
    }
    $post = get_post((int) ($test['post_id'] ?? 0));
    if (!$post) { return ['applied' => false, 'note' => 'post ' . (int) ($test['post_id'] ?? 0) . ' not found']; }

    if (($test['kind'] ?? '') === 'title') {
        $title = trim((string) ($v['title'] ?? ''));
        if ($title === '') { return ['applied' => true, 'note' => 'winning variant has no title — post unchanged']; }
        $r = wp_update_post(['ID' => (int) $post->ID, 'post_title' => $title], true);
        if (function_exists('is_wp_error') && is_wp_error($r)) { return ['applied' => false, 'note' => $r->get_error_message()]; }
        return ['applied' => true, 'note' => 'post title updated'];
    }

    $new = wpultra_ab_apply_content((string) $post->post_content, $v);
    if ($new === (string) $post->post_content) {
        return ['applied' => true, 'note' => 'no content change (find string absent or no-op variant)'];
    }
    $r = wp_update_post(['ID' => (int) $post->ID, 'post_content' => $new], true);
    if (function_exists('is_wp_error') && is_wp_error($r)) { return ['applied' => false, 'note' => $r->get_error_message()]; }
    return ['applied' => true, 'note' => 'post content updated'];
}
