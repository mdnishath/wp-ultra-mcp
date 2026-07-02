<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
// wpultra_ok()/wpultra_err() come from the real helpers.php (only pure/guarded
// functions are reached by this test — no get_option/update_option calls occur
// on the code paths exercised below).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/i18n/engine.php';

it('detects no plugin when neither WPML nor Polylang probes are true', function () {
    assert_eq('', wpultra_i18n_detect_plugin(false, false));
});

it('detects WPML when only the WPML probe is true', function () {
    assert_eq('wpml', wpultra_i18n_detect_plugin(true, false));
});

it('detects Polylang when only the Polylang probe is true', function () {
    assert_eq('polylang', wpultra_i18n_detect_plugin(false, true));
});

it('prefers Polylang when both probes are somehow true', function () {
    assert_eq('polylang', wpultra_i18n_detect_plugin(true, true));
});

it('validates a language code against the available list, case-insensitively', function () {
    assert_true(wpultra_i18n_is_valid_lang_code('fr', ['en', 'fr', 'bn']));
    assert_true(wpultra_i18n_is_valid_lang_code('FR', ['en', 'fr', 'bn']));
    assert_true(wpultra_i18n_is_valid_lang_code(' fr ', ['en', 'fr', 'bn']));
    assert_true(!wpultra_i18n_is_valid_lang_code('de', ['en', 'fr', 'bn']));
    assert_true(!wpultra_i18n_is_valid_lang_code('', ['en', 'fr']));
});

it('normalizes a mixed raw language list (strings and assoc rows) to lowercase codes', function () {
    assert_eq(['en', 'fr', 'bn'], wpultra_i18n_normalize_lang_codes(['EN', ['code' => 'fr'], ['slug' => 'BN']]));
    // de-dupes
    assert_eq(['en'], wpultra_i18n_normalize_lang_codes(['en', 'EN', ' en ']));
});

it('meta-copy filter skips edit-lock bookkeeping but keeps everything else', function () {
    assert_true(!wpultra_i18n_should_copy_meta_key('_edit_lock'));
    assert_true(!wpultra_i18n_should_copy_meta_key('_edit_last'));
    assert_true(wpultra_i18n_should_copy_meta_key('_elementor_data'));
    assert_true(wpultra_i18n_should_copy_meta_key('_elementor_css'));
    assert_true(wpultra_i18n_should_copy_meta_key('_elementor_version'));
    assert_true(wpultra_i18n_should_copy_meta_key('my_custom_field'));
    assert_true(wpultra_i18n_should_copy_meta_key('_custom_private_field'));
});

it('filters a full meta map down to copyable keys only', function () {
    $meta = [
        '_edit_lock'      => ['123:1'],
        '_edit_last'      => ['1'],
        '_elementor_data' => ['[{"id":"abc"}]'],
        'my_field'        => ['hello'],
    ];
    $filtered = wpultra_i18n_filter_translation_meta($meta);
    assert_true(!array_key_exists('_edit_lock', $filtered));
    assert_true(!array_key_exists('_edit_last', $filtered));
    assert_true(array_key_exists('_elementor_data', $filtered));
    assert_true(array_key_exists('my_field', $filtered));
    assert_eq(2, count($filtered));
});

it('shapes a raw language list into code/name/default rows', function () {
    $raw = [
        ['code' => 'en', 'name' => 'English'],
        ['code' => 'FR', 'name' => 'French'],
    ];
    $shaped = wpultra_i18n_shape_languages($raw, 'en');
    assert_eq(2, count($shaped));
    assert_eq(['code' => 'en', 'name' => 'English', 'default' => true], $shaped[0]);
    assert_eq(['code' => 'fr', 'name' => 'French', 'default' => false], $shaped[1]);
});

it('shapes per-post-type counts with computed totals', function () {
    $counts = [
        'post' => ['translated' => 3, 'untranslated' => 2],
        'page' => ['translated' => 0, 'untranslated' => 5],
    ];
    $shaped = wpultra_i18n_shape_counts($counts);
    assert_eq(2, count($shaped));
    assert_eq(['post_type' => 'post', 'translated' => 3, 'untranslated' => 2, 'total' => 5], $shaped[0]);
    assert_eq(['post_type' => 'page', 'translated' => 0, 'untranslated' => 5, 'total' => 5], $shaped[1]);
});

it('builds a translation postarr, defaulting title to the source title', function () {
    $postarr = wpultra_i18n_build_translation_postarr([
        'title'       => 'Hello',
        'content'     => 'World',
        'excerpt'     => 'Ex',
        'post_type'   => 'page',
        'post_status' => 'publish',
        'post_parent' => 5,
    ]);
    assert_eq('Hello', $postarr['post_title']);
    assert_eq('World', $postarr['post_content']);
    assert_eq('page', $postarr['post_type']);
    assert_eq('publish', $postarr['post_status']);
    assert_eq(5, $postarr['post_parent']);
});

it('builds a translation postarr using an explicit new_title override', function () {
    $postarr = wpultra_i18n_build_translation_postarr(['title' => 'Hello'], 'Bonjour');
    assert_eq('Bonjour', $postarr['post_title']);
});

it('translation postarr defaults status to draft when source status is empty', function () {
    $postarr = wpultra_i18n_build_translation_postarr(['title' => 'X']);
    assert_eq('draft', $postarr['post_status']);
});

it('wpultra_i18n_duplicate_to_language fails gracefully with no plugin active', function () {
    // wpultra_i18n_active_plugin() reads live constants/functions; in the test
    // harness neither ICL_SITEPRESS_VERSION nor pll_languages_list exist, so the
    // adapter must report unavailable rather than fatal.
    $result = wpultra_i18n_duplicate_to_language(1, 'fr', false);
    assert_wp_error($result, 'expected graceful WP_Error when no multilingual plugin is active');
    assert_eq('multilingual_unavailable', $result->get_error_code());
});

it('wpultra_i18n_status reports empty status with no plugin active', function () {
    $status = wpultra_i18n_status();
    assert_eq('', $status['active_plugin']);
    assert_eq([], $status['languages']);
    assert_eq([], $status['post_type_counts']);
});

run_tests();
