<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
// Only the pure functions (wpultra_i18n_replace_in_json / wpultra_i18n_fill_validate) are
// exercised below — no get_post()/wp_update_post()/update_post_meta() calls occur, so no
// further WP stubs are needed beyond what harness.php already provides.
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/i18n/engine.php';

// ---------------------------------------------------------------------------
// wpultra_i18n_replace_in_json
// ---------------------------------------------------------------------------

it('replaces plain text inside a JSON string', function () {
    $json = '[{"id":"a1","settings":{"title":"Hello World"}}]';
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [['find' => 'Hello World', 'replace' => 'Bonjour Monde']], $count);
    assert_eq(1, $count);
    assert_contains('"title":"Bonjour Monde"', $out);
    assert_true(!str_contains($out, 'Hello World'));
    // Must remain valid JSON.
    assert_true(json_decode($out, true) !== null);
});

it('replaces Bengali (multi-byte) text inside a JSON string', function () {
    $source = 'স্বাগতম বন্ধু';
    $target = 'আমরা আপনাকে স্বাগত জানাই';
    $json = json_encode(['id' => 'a1', 'settings' => ['title' => $source]]);
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [['find' => $source, 'replace' => $target]], $count);
    assert_eq(1, $count);
    $decoded = json_decode($out, true);
    assert_eq($target, $decoded['settings']['title']);
});

it('replaces emoji text inside a JSON string', function () {
    $source = 'Great job 🎉👍';
    $target = 'Bien joué 🎉👍';
    $json = json_encode(['settings' => ['title' => $source]]);
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [['find' => $source, 'replace' => $target]], $count);
    assert_eq(1, $count);
    $decoded = json_decode($out, true);
    assert_eq($target, $decoded['settings']['title']);
});

it('replaces text containing double quotes without breaking JSON structure', function () {
    $source = 'She said "hello" to me';
    $target = 'Elle a dit "bonjour" a moi';
    $json = json_encode(['settings' => ['title' => $source]]);
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [['find' => $source, 'replace' => $target]], $count);
    assert_eq(1, $count);
    $decoded = json_decode($out, true);
    assert_true($decoded !== null, 'output must remain parseable JSON');
    assert_eq($target, $decoded['settings']['title']);
});

it('replaces text containing backslashes (e.g. Windows-style paths) without breaking JSON', function () {
    $source = 'Path: C:\\Users\\demo';
    $target = 'Chemin: C:\\Users\\demo\\fr';
    $json = json_encode(['settings' => ['note' => $source]]);
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [['find' => $source, 'replace' => $target]], $count);
    assert_eq(1, $count);
    $decoded = json_decode($out, true);
    assert_true($decoded !== null, 'output must remain parseable JSON');
    assert_eq($target, $decoded['settings']['note']);
});

it('applies multiple find/replace pairs in order and tallies the total count', function () {
    $json = json_encode(['a' => 'Hello', 'b' => 'World', 'c' => 'Hello']);
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [
        ['find' => 'Hello', 'replace' => 'Bonjour'],
        ['find' => 'World', 'replace' => 'Monde'],
    ], $count);
    // 'Hello' occurs twice (a and c), 'World' once => 3 total replacements.
    assert_eq(3, $count);
    $decoded = json_decode($out, true);
    assert_eq('Bonjour', $decoded['a']);
    assert_eq('Monde', $decoded['b']);
    assert_eq('Bonjour', $decoded['c']);
});

it('counts each occurrence of a repeated find string within one pair', function () {
    $json = json_encode(['x' => 'cat cat cat']);
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [['find' => 'cat', 'replace' => 'dog']], $count);
    assert_eq(3, $count);
    $decoded = json_decode($out, true);
    assert_eq('dog dog dog', $decoded['x']);
});

it('leaves the JSON unchanged and reports zero when no pair matches', function () {
    $json = json_encode(['a' => 'Hello World']);
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [['find' => 'Nonexistent Text', 'replace' => 'X']], $count);
    assert_eq(0, $count);
    assert_eq($json, $out);
});

it('skips pairs with an empty find string rather than matching everything', function () {
    $json = json_encode(['a' => 'Hello']);
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [['find' => '', 'replace' => 'X']], $count);
    assert_eq(0, $count);
    assert_eq($json, $out);
});

it('handles an empty pairs list as a no-op', function () {
    $json = json_encode(['a' => 'Hello']);
    $count = 0;
    $out = wpultra_i18n_replace_in_json($json, [], $count);
    assert_eq(0, $count);
    assert_eq($json, $out);
});

// ---------------------------------------------------------------------------
// wpultra_i18n_fill_validate
// ---------------------------------------------------------------------------

it('fill_validate rejects an entirely empty input', function () {
    $result = wpultra_i18n_fill_validate([]);
    assert_true($result !== true, 'expected an error string for empty input');
    assert_true(is_string($result));
});

it('fill_validate rejects blank-only title/content/excerpt with nothing else', function () {
    $result = wpultra_i18n_fill_validate(['title' => '  ', 'content' => '']);
    assert_true($result !== true);
});

it('fill_validate accepts a title-only input', function () {
    assert_eq(true, wpultra_i18n_fill_validate(['title' => 'Bonjour']));
});

it('fill_validate accepts a content-only input', function () {
    assert_eq(true, wpultra_i18n_fill_validate(['content' => 'Le contenu']));
});

it('fill_validate accepts an excerpt-only input', function () {
    assert_eq(true, wpultra_i18n_fill_validate(['excerpt' => 'Un extrait']));
});

it('fill_validate accepts a meta-only input', function () {
    assert_eq(true, wpultra_i18n_fill_validate(['meta' => ['subtitle' => 'Sous-titre']]));
});

it('fill_validate rejects a non-array meta value', function () {
    $result = wpultra_i18n_fill_validate(['meta' => 'not-an-array']);
    assert_true($result !== true);
    assert_true(is_string($result));
});

it('fill_validate accepts a well-formed elementor_texts-only input', function () {
    $result = wpultra_i18n_fill_validate([
        'elementor_texts' => [
            ['find' => 'Hello', 'replace' => 'Bonjour'],
        ],
    ]);
    assert_eq(true, $result);
});

it('fill_validate rejects elementor_texts that is not an array', function () {
    $result = wpultra_i18n_fill_validate(['elementor_texts' => 'oops']);
    assert_true($result !== true);
});

it('fill_validate rejects an elementor_texts entry missing find', function () {
    $result = wpultra_i18n_fill_validate([
        'elementor_texts' => [['replace' => 'Bonjour']],
    ]);
    assert_true($result !== true);
    assert_contains('find', (string) $result);
});

it('fill_validate rejects an elementor_texts entry with an empty find', function () {
    $result = wpultra_i18n_fill_validate([
        'elementor_texts' => [['find' => '  ', 'replace' => 'Bonjour']],
    ]);
    assert_true($result !== true);
});

it('fill_validate rejects an elementor_texts entry missing replace', function () {
    $result = wpultra_i18n_fill_validate([
        'elementor_texts' => [['find' => 'Hello']],
    ]);
    assert_true($result !== true);
    assert_contains('replace', (string) $result);
});

it('fill_validate accepts an elementor_texts entry whose replace is an empty string (deletion)', function () {
    $result = wpultra_i18n_fill_validate([
        'elementor_texts' => [['find' => 'Hello', 'replace' => '']],
    ]);
    assert_eq(true, $result);
});

it('fill_validate rejects a non-array elementor_texts entry', function () {
    $result = wpultra_i18n_fill_validate([
        'elementor_texts' => ['just a string'],
    ]);
    assert_true($result !== true);
});

it('fill_validate accepts a combination of title, meta, and elementor_texts', function () {
    $result = wpultra_i18n_fill_validate([
        'title'           => 'Bonjour',
        'meta'            => ['subtitle' => 'Sous-titre'],
        'elementor_texts' => [['find' => 'Hi', 'replace' => 'Salut']],
    ]);
    assert_eq(true, $result);
});

run_tests();
