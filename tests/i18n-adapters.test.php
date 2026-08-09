<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
require __DIR__ . '/../wp-ultra-mcp/includes/i18n/adapters.php';

/* ---------------- TranslatePress naming + settings extraction (pure) ---------------- */

it('trp table name lowercases and underscores the language pair', function () {
    assert_eq('wp_trp_dictionary_en_us_bn_bd', wpultra_i18n_trp_table('wp_', 'en_US', 'bn_BD'));
    assert_eq('site1_trp_dictionary_en_us_zh_cn', wpultra_i18n_trp_table('site1_', 'en_US', 'zh-CN'));
    assert_eq('wp_trp_dictionary_de_de_fr_fr', wpultra_i18n_trp_table('wp_', ' de_DE ', 'fr_FR'));
});

it('trp language extraction drops the default from the translation list and dedupes', function () {
    $pair = wpultra_i18n_trp_extract_languages([
        'default-language'      => 'en_US',
        'translation-languages' => ['en_US', 'bn_BD', 'fr_FR', 'bn_BD'],
    ]);
    assert_eq('en_US', $pair['default']);
    assert_eq(['bn_BD', 'fr_FR'], $pair['languages']);
});

it('trp language extraction degrades on an empty/malformed option', function () {
    $pair = wpultra_i18n_trp_extract_languages([]);
    assert_eq('', $pair['default']);
    assert_eq([], $pair['languages']);
});

/* ---------------- Weglot settings extraction (pure) ---------------- */

it('weglot extraction handles the v3 array shape with language_to entries', function () {
    $pair = wpultra_i18n_weglot_extract_languages([
        'language_from' => 'en',
        'languages'     => [['language_to' => 'bn'], ['language_to' => 'fr'], ['language_to' => 'bn']],
    ]);
    assert_eq('en', $pair['original']);
    assert_eq(['bn', 'fr'], $pair['destinations']);
});

it('weglot extraction handles a JSON string and flat string lists', function () {
    $pair = wpultra_i18n_weglot_extract_languages(json_encode([
        'original_language'    => 'en',
        'destination_language' => ['es', 'de'],
    ]));
    assert_eq('en', $pair['original']);
    assert_eq(['es', 'de'], $pair['destinations']);
});

it('weglot extraction degrades on garbage input', function () {
    assert_eq(['original' => '', 'destinations' => []], wpultra_i18n_weglot_extract_languages('not-json'));
    assert_eq(['original' => '', 'destinations' => []], wpultra_i18n_weglot_extract_languages(42));
});

/* ---------------- detection + status degrade with plugins absent ---------------- */

it('trp/weglot probes are false and statuses report installed:false with plugins absent', function () {
    assert_eq(false, wpultra_i18n_trp_active());
    assert_eq(false, wpultra_i18n_weglot_active());
    assert_eq(['installed' => false], wpultra_i18n_trp_status());
    assert_eq(['installed' => false], wpultra_i18n_weglot_status());
    $tp = wpultra_i18n_third_party_status();
    assert_eq(false, $tp['translatepress']['installed']);
    assert_eq(false, $tp['weglot']['installed']);
});

it('trp set-strings errors cleanly when TranslatePress is absent', function () {
    $err = wpultra_i18n_trp_set_strings([['original' => 'Hello', 'translated' => 'Hallo', 'language' => 'de_DE']]);
    assert_wp_error($err);
});

run_tests();
