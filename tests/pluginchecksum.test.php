<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/pluginchecksum.php';

/* ------------------------------------------------------------------ *
 * wpultra_pluginck_slug_from_basename
 * ------------------------------------------------------------------ */

it('slug_from_basename: directory-style plugin returns the folder name', function () {
    assert_eq('akismet', wpultra_pluginck_slug_from_basename('akismet/akismet.php'));
});

it('slug_from_basename: single-file plugin returns the file name without .php', function () {
    assert_eq('hello', wpultra_pluginck_slug_from_basename('hello.php'));
});

it('slug_from_basename: nested-looking basename still uses the first segment', function () {
    assert_eq('foo', wpultra_pluginck_slug_from_basename('foo/bar/foo.php'));
});

it('slug_from_basename: backslash separator (Windows) is normalized', function () {
    assert_eq('foo', wpultra_pluginck_slug_from_basename('foo\\foo.php'));
});

it('slug_from_basename: empty input returns empty string', function () {
    assert_eq('', wpultra_pluginck_slug_from_basename(''));
});

/* ------------------------------------------------------------------ *
 * wpultra_pluginck_manifest_url
 * ------------------------------------------------------------------ */

it('manifest_url builds the wp.org plugin-checksums URL', function () {
    assert_eq(
        'https://downloads.wordpress.org/plugin-checksums/akismet/5.3.json',
        wpultra_pluginck_manifest_url('akismet', '5.3')
    );
});

it('manifest_url url-encodes unusual slug/version characters', function () {
    assert_eq(
        'https://downloads.wordpress.org/plugin-checksums/my%20slug/1.0%2Bfix.json',
        wpultra_pluginck_manifest_url('my slug', '1.0+fix')
    );
});

/* ------------------------------------------------------------------ *
 * wpultra_pluginck_parse_manifest
 * ------------------------------------------------------------------ */

it('parse_manifest extracts path=>md5 map from the API JSON shape', function () {
    $json = json_encode(['files' => [
        'readme.txt'   => ['md5' => 'aaa111', 'sha256' => 'deadbeef'],
        'foo/foo.php'  => ['md5' => 'bbb222', 'sha256' => 'cafefeed'],
    ]]);
    $out = wpultra_pluginck_parse_manifest($json);
    assert_eq(['readme.txt' => 'aaa111', 'foo/foo.php' => 'bbb222'], $out);
});

it('parse_manifest tolerates entries missing sha256', function () {
    $json = json_encode(['files' => [
        'readme.txt' => ['md5' => 'aaa111'],
    ]]);
    $out = wpultra_pluginck_parse_manifest($json);
    assert_eq(['readme.txt' => 'aaa111'], $out);
});

it('parse_manifest returns WP_Error on malformed JSON', function () {
    $out = wpultra_pluginck_parse_manifest('{not valid json');
    assert_wp_error($out, 'expected WP_Error for malformed JSON');
});

it('parse_manifest returns an empty array for an empty files list', function () {
    $json = json_encode(['files' => []]);
    assert_eq([], wpultra_pluginck_parse_manifest($json));
});

it('parse_manifest returns an empty array when the files key is absent', function () {
    $json = json_encode(['other' => 'x']);
    assert_eq([], wpultra_pluginck_parse_manifest($json));
});

/* ------------------------------------------------------------------ *
 * wpultra_pluginck_compare
 * ------------------------------------------------------------------ */

it('compare: clean match reports ok with no findings', function () {
    $manifest = ['a.php' => 'md5a', 'b.php' => 'md5b'];
    $ondisk   = ['a.php' => 'md5a', 'b.php' => 'md5b'];
    $result = wpultra_pluginck_compare($manifest, $ondisk);
    assert_eq(['ok' => true, 'modified' => [], 'missing' => [], 'extra' => []], $result);
});

it('compare: a changed file is reported as modified', function () {
    $manifest = ['a.php' => 'md5a', 'b.php' => 'md5b'];
    $ondisk   = ['a.php' => 'md5a', 'b.php' => 'TAMPERED'];
    $result = wpultra_pluginck_compare($manifest, $ondisk);
    assert_eq(false, $result['ok']);
    assert_eq(['b.php'], $result['modified']);
    assert_eq([], $result['missing']);
    assert_eq([], $result['extra']);
});

it('compare: a manifest file absent on disk is reported as missing', function () {
    $manifest = ['a.php' => 'md5a', 'b.php' => 'md5b'];
    $ondisk   = ['a.php' => 'md5a'];
    $result = wpultra_pluginck_compare($manifest, $ondisk);
    assert_eq(false, $result['ok']);
    assert_eq([], $result['modified']);
    assert_eq(['b.php'], $result['missing']);
    assert_eq([], $result['extra']);
});

it('compare: an on-disk file not in the manifest is reported as extra', function () {
    $manifest = ['a.php' => 'md5a'];
    $ondisk   = ['a.php' => 'md5a', 'sneaky.php' => 'md5x'];
    $result = wpultra_pluginck_compare($manifest, $ondisk);
    assert_eq(false, $result['ok']);
    assert_eq([], $result['modified']);
    assert_eq([], $result['missing']);
    assert_eq(['sneaky.php'], $result['extra']);
});

it('compare: modified + missing + extra can all be reported together', function () {
    $manifest = ['a.php' => 'md5a', 'b.php' => 'md5b', 'c.php' => 'md5c'];
    $ondisk   = ['a.php' => 'md5a', 'b.php' => 'TAMPERED', 'sneaky.php' => 'md5x'];
    $result = wpultra_pluginck_compare($manifest, $ondisk);
    assert_eq(false, $result['ok']);
    assert_eq(['b.php'], $result['modified']);
    assert_eq(['c.php'], $result['missing']);
    assert_eq(['sneaky.php'], $result['extra']);
});

it('compare: empty manifest and empty on-disk map is ok', function () {
    $result = wpultra_pluginck_compare([], []);
    assert_eq(['ok' => true, 'modified' => [], 'missing' => [], 'extra' => []], $result);
});

run_tests();
