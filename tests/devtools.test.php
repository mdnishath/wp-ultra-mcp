<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_devtools_' . uniqid() . '/'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) { $GLOBALS['__ab'][$n] = $a; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = true) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('current_time')) { function current_time($t, $g = false) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $len = null) { return substr($s, $start, $len ?? strlen($s)); } }
if (!function_exists('wp_mail')) { function wp_mail($to, $subject, $body, $headers = []) { return $GLOBALS['__wp_mail_result'] ?? true; } }
if (!function_exists('remove_action')) { function remove_action(...$a) { return true; } }
if (!function_exists('has_action')) { function has_action($hook) { return false; } }
if (!function_exists('wp_remote_get')) { function wp_remote_get($url, $args = []) { return $GLOBALS['__remote_response'] ?? ['response' => ['code' => 200], 'body' => '']; } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return (int) ($r['response']['code'] ?? 0); } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return (string) ($r['body'] ?? ''); } }
if (!function_exists('get_post')) { function get_post($id) { return $GLOBALS['__posts'][$id] ?? null; } }
if (!function_exists('get_permalink')) { function get_permalink($id) { return $GLOBALS['__permalinks'][$id] ?? ''; } }

// Requiring devtools.php under the harness must never fatal — this is the load-bearing assertion.
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/devtools.php';

// ---- extract_title / count_tag / fatal-marker detector (pure HTML probes) ----

it('extract_title pulls trimmed, whitespace-collapsed title text', function () {
    assert_eq('Hello World', wpultra_devtools_extract_title('<html><head><title>  Hello   World  </title></head></html>'));
    assert_eq('', wpultra_devtools_extract_title('<html><head></head></html>'), 'no title tag');
    assert_eq('A & B', wpultra_devtools_extract_title('<title>A &amp; B</title>'), 'entity decode');
});

it('count_tag counts opening tags case-insensitively, ignoring attributes', function () {
    assert_eq(2, wpultra_devtools_count_tag('<h1>a</h1><H1 class="x">b</h1>', 'h1'));
    assert_eq(0, wpultra_devtools_count_tag('<h2>a</h2>', 'h1'));
    assert_eq(0, wpultra_devtools_count_tag('<h1>a</h1>', 'not a tag'), 'invalid tag name rejected');
});

it('detect_fatal finds known PHP/WP fatal markers, none when clean', function () {
    assert_eq(['Fatal error'], wpultra_devtools_detect_fatal('...Fatal error: foo in bar.php...'));
    assert_eq([], wpultra_devtools_detect_fatal('<html>all good</html>'));
    assert_true(in_array('There has been a critical error', wpultra_devtools_detect_fatal('There has been a critical error on this website.'), true));
});

it('render_report assembles the full probe payload from html + meta', function () {
    $html = '<html><head><title>My Page</title></head><body><h1>A</h1><h1>B</h1></body></html>';
    $r = wpultra_devtools_render_report($html, ['status' => 200, 'load_ms' => 12.5, 'url' => 'https://x.test/']);
    assert_eq('https://x.test/', $r['url']);
    assert_eq(200, $r['http_status']);
    assert_eq(12.5, $r['load_ms']);
    assert_eq('My Page', $r['title']);
    assert_eq(2, $r['h1_count']);
    assert_eq(strlen($html), $r['body_length']);
    assert_true($r['fatal_detected'] === false);
});

it('render_report flags fatal markers', function () {
    $html = '<html><body>Fatal error: oops</body></html>';
    $r = wpultra_devtools_render_report($html, ['status' => 500, 'load_ms' => 1, 'url' => 'https://x.test/']);
    assert_true($r['fatal_detected']);
    assert_true(in_array('Fatal error', $r['fatal_markers'], true));
});

// ---- registry shapers (fixture arrays -> compact descriptors) ----

it('shape_post_types compacts a name=>data map', function () {
    $out = wpultra_devtools_shape_post_types([
        'post' => ['label' => 'Posts', 'public' => true],
        'wpultra_memory' => ['label' => 'Memory', 'public' => false],
    ]);
    assert_eq(2, count($out));
    assert_eq('post', $out[0]['name']);
    assert_eq('Posts', $out[0]['label']);
    assert_true($out[0]['public']);
    assert_true(!$out[1]['public']);
});

it('shape_taxonomies compacts a name=>data map with object_types', function () {
    $out = wpultra_devtools_shape_taxonomies([
        'category' => ['label' => 'Categories', 'public' => true, 'object_type' => ['post']],
    ]);
    assert_eq(1, count($out));
    assert_eq('category', $out[0]['name']);
    assert_eq(['post'], $out[0]['object_types']);
});

it('shape_shortcodes returns a sorted unique tag list', function () {
    $out = wpultra_devtools_shape_shortcodes(['gallery' => '1', 'audio' => '1', 'gallery' => '2']);
    assert_eq(['audio', 'gallery'], $out);
});

it('shape_roles compacts role data with a capability count', function () {
    $out = wpultra_devtools_shape_roles([
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true, 'edit_posts' => true, 'nope' => false]],
    ]);
    assert_eq(1, count($out));
    assert_eq('administrator', $out[0]['slug']);
    assert_eq('Administrator', $out[0]['name']);
    assert_eq(2, $out[0]['capability_count']);
});

it('shape_image_sizes compacts a name=>dims map', function () {
    $out = wpultra_devtools_shape_image_sizes([
        'thumbnail' => ['width' => 150, 'height' => 150, 'crop' => true],
        'large'     => ['width' => 1024, 'height' => 1024, 'crop' => false],
    ]);
    assert_eq(2, count($out));
    assert_eq(150, $out[0]['width']);
    assert_true($out[0]['crop']);
    assert_true(!$out[1]['crop']);
});

it('shape_rest_routes flattens route=>defs into route+methods', function () {
    $out = wpultra_devtools_shape_rest_routes([
        '/wp/v2/posts' => [
            ['methods' => ['GET' => true, 'POST' => true]],
            ['methods' => ['GET' => true]],
        ],
    ]);
    assert_eq(1, count($out));
    assert_eq('/wp/v2/posts', $out[0]['route']);
    sort($out[0]['methods']);
    assert_eq(['GET', 'POST'], $out[0]['methods']);
});

it('shape_hook_callbacks orders by priority and describes callables', function () {
    $out = wpultra_devtools_shape_hook_callbacks([
        20 => ['cb1' => ['function' => 'late_fn', 'accepted_args' => 1]],
        10 => ['cb2' => ['function' => 'early_fn', 'accepted_args' => 2]],
    ]);
    assert_eq(2, count($out));
    assert_eq(10, $out[0]['priority']);
    assert_eq('early_fn', $out[0]['callback']);
    assert_eq(2, $out[0]['accepted_args']);
    assert_eq(20, $out[1]['priority']);
    assert_eq('late_fn', $out[1]['callback']);
});

it('describe_callback renders strings, Class::method arrays, and closures', function () {
    assert_eq('my_func', wpultra_devtools_describe_callback('my_func'));
    assert_eq('WP_Widget::render', wpultra_devtools_describe_callback(['WP_Widget', 'render']));
    assert_eq('{closure}', wpultra_devtools_describe_callback(function () {}));
});

// ---- purge-cache probe map (pure availability + planning) ----

it('purge_probes returns the documented layer set', function () {
    $ids = array_column(wpultra_devtools_purge_probes(), 'id');
    sort($ids);
    assert_eq(['autoptimize', 'elementor', 'litespeed', 'object_cache', 'w3tc', 'wp_rocket', 'wp_super_cache'], $ids);
});

it('probe_available: function-type probes only fire when the function exists', function () {
    $probe = ['type' => 'function', 'target' => 'rocket_clean_domain'];
    $checkers = ['function_exists' => fn($f) => $f === 'rocket_clean_domain'];
    assert_true(wpultra_devtools_probe_available($probe, $checkers));
    assert_true(!wpultra_devtools_probe_available($probe, ['function_exists' => fn($f) => false]));
});

it('probe_available: action-type probes fire only when the hook has a callback', function () {
    $probe = ['type' => 'action', 'target' => 'litespeed_purge_all'];
    assert_true(wpultra_devtools_probe_available($probe, ['action_exists' => fn($h) => true]));
    assert_true(!wpultra_devtools_probe_available($probe, ['action_exists' => fn($h) => false]));
});

it('probe_available: class_method probes check only the class portion before "::"', function () {
    $probe = ['type' => 'class_method', 'target' => 'autoptimizeCache::clearall'];
    assert_true(wpultra_devtools_probe_available($probe, ['class_exists' => fn($c) => $c === 'autoptimizeCache']));
});

it('probe_available: always-type probes (object cache) are never skipped', function () {
    assert_true(wpultra_devtools_probe_available(['type' => 'always', 'target' => 'wp_cache_flush'], []));
});

it('plan_purge partitions probes into purged vs skipped using injected checkers', function () {
    $probes = [
        ['id' => 'wp_rocket', 'type' => 'function', 'target' => 'rocket_clean_domain'],
        ['id' => 'litespeed', 'type' => 'action', 'target' => 'litespeed_purge_all'],
        ['id' => 'object_cache', 'type' => 'always', 'target' => 'wp_cache_flush'],
    ];
    $checkers = [
        'function_exists' => fn($f) => $f === 'rocket_clean_domain',
        'action_exists'   => fn($h) => false,
    ];
    $plan = wpultra_devtools_plan_purge($probes, $checkers);
    assert_eq(['wp_rocket', 'object_cache'], $plan['purged']);
    assert_eq(['litespeed'], $plan['skipped']);
});

// ---- email validation ----

it('is_valid_email accepts well-formed addresses and rejects junk', function () {
    assert_true(wpultra_devtools_is_valid_email('a@b.com'));
    assert_true(!wpultra_devtools_is_valid_email('not-an-email'));
    assert_true(!wpultra_devtools_is_valid_email(''));
});

// ---- WP-calling wrapper smoke tests (stubs above keep these fatal-free) ----

it('send_email rejects an invalid recipient before calling wp_mail', function () {
    $r = wpultra_devtools_send_email('nope', 'Subject', 'Body', false);
    assert_wp_error($r);
    assert_eq('invalid_email', $r->get_error_code());
});

it('send_email succeeds against the wp_mail stub and reports smtp_detected', function () {
    $GLOBALS['__wp_mail_result'] = true;
    $r = wpultra_devtools_send_email('a@b.com', 'Hi', 'Body', false);
    assert_true($r['success']);
    assert_true($r['sent']);
    assert_eq('a@b.com', $r['to']);
    assert_true(is_array($r['smtp_detected']));
});

it('send_email surfaces failure when wp_mail() returns false', function () {
    $GLOBALS['__wp_mail_result'] = false;
    $r = wpultra_devtools_send_email('a@b.com', 'Hi', 'Body', false);
    assert_wp_error($r);
    assert_eq('send_failed', $r->get_error_code());
    $GLOBALS['__wp_mail_result'] = true;
});

it('render_page requires either url or post_id', function () {
    $r = wpultra_devtools_render_page([]);
    assert_wp_error($r);
    assert_eq('missing_target', $r->get_error_code());
});

it('render_page fetches an explicit url via the wp_remote_get stub', function () {
    $GLOBALS['__remote_response'] = ['response' => ['code' => 200], 'body' => '<title>Stub Page</title>'];
    $r = wpultra_devtools_render_page(['url' => 'https://x.test/']);
    assert_true($r['success']);
    assert_eq(200, $r['http_status']);
    assert_eq('Stub Page', $r['title']);
});

it('render_page resolves a post_id to a permalink then fetches it', function () {
    $GLOBALS['__posts'][42] = (object) ['ID' => 42];
    $GLOBALS['__permalinks'][42] = 'https://x.test/post-42/';
    $GLOBALS['__remote_response'] = ['response' => ['code' => 200], 'body' => '<title>Post 42</title>'];
    $r = wpultra_devtools_render_page(['post_id' => 42]);
    assert_true($r['success']);
    assert_eq('https://x.test/post-42/', $r['url']);
    assert_eq('Post 42', $r['title']);
});

it('render_page errors cleanly for an unknown post_id', function () {
    $r = wpultra_devtools_render_page(['post_id' => 9999]);
    assert_wp_error($r);
    assert_eq('bad_post', $r->get_error_code());
});

it('list_registry rejects an unknown "what" value', function () {
    $r = wpultra_devtools_list_registry(['what' => 'bogus']);
    assert_wp_error($r);
    assert_eq('unknown_registry', $r->get_error_code());
});

it('list_registry shortcodes reads the global $shortcode_tags map', function () {
    global $shortcode_tags;
    $shortcode_tags = ['gallery' => '__return_true', 'caption' => '__return_true'];
    $r = wpultra_devtools_list_registry(['what' => 'shortcodes']);
    assert_true($r['success']);
    assert_eq(['caption', 'gallery'], $r['items']);
});

it('purge_cache reports purged vs skipped without fataling (object cache always purges)', function () {
    $r = wpultra_devtools_purge_cache();
    assert_true($r['success']);
    assert_true(in_array('object_cache', $r['purged'], true));
    assert_true(is_array($r['skipped']));
});

run_tests();
