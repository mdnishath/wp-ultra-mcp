<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/system/envinfo.php';

/* ------------------------------------------------------------------ *
 * wpultra_env_ini_bytes
 * ------------------------------------------------------------------ */

it('ini_bytes: megabytes shorthand', function () {
    assert_eq(268435456, wpultra_env_ini_bytes('256M'));
});

it('ini_bytes: gigabytes shorthand', function () {
    assert_eq(1073741824, wpultra_env_ini_bytes('1G'));
});

it('ini_bytes: kilobytes shorthand', function () {
    assert_eq(524288, wpultra_env_ini_bytes('512k'));
});

it('ini_bytes: lowercase suffix accepted', function () {
    assert_eq(268435456, wpultra_env_ini_bytes('256m'));
});

it('ini_bytes: -1 means unlimited', function () {
    assert_eq(-1, wpultra_env_ini_bytes('-1'));
});

it('ini_bytes: plain number is bytes as-is', function () {
    assert_eq(8388608, wpultra_env_ini_bytes('8388608'));
});

it('ini_bytes: empty string is invalid -> 0', function () {
    assert_eq(0, wpultra_env_ini_bytes(''));
});

it('ini_bytes: garbage string is invalid -> 0', function () {
    assert_eq(0, wpultra_env_ini_bytes('abc'));
});

it('ini_bytes: other negative values are invalid -> 0', function () {
    assert_eq(0, wpultra_env_ini_bytes('-2'));
});

it('ini_bytes: trims surrounding whitespace', function () {
    assert_eq(268435456, wpultra_env_ini_bytes('  256M  '));
});

/* ------------------------------------------------------------------ *
 * wpultra_env_error_reporting_label
 * ------------------------------------------------------------------ */

it('error_reporting_label: 0 is None', function () {
    assert_eq('None (0)', wpultra_env_error_reporting_label(0));
});

it('error_reporting_label: E_ALL is recognized', function () {
    assert_eq('E_ALL', wpultra_env_error_reporting_label(E_ALL));
});

it('error_reporting_label: single flag decodes by name', function () {
    assert_eq('E_ERROR', wpultra_env_error_reporting_label(E_ERROR));
});

/* ------------------------------------------------------------------ *
 * wpultra_env_warnings — rule-by-rule
 * ------------------------------------------------------------------ */

function envinfo_test_clean_facts(): array {
    return [
        'memory_limit_bytes'        => 512 * 1024 * 1024,
        'max_execution_time'        => 120,
        'upload_max_filesize_bytes' => 64 * 1024 * 1024,
        'post_max_size_bytes'       => 64 * 1024 * 1024,
        'extensions'                => ['curl' => true, 'gd' => true, 'mbstring' => true, 'openssl' => true],
        'disk_free_bytes'           => 50 * 1024 * 1024 * 1024,
        'disk_total_bytes'          => 100 * 1024 * 1024 * 1024,
        'php_version'               => '8.2.30',
        'opcache_enabled'           => true,
    ];
}

it('warnings: a fully healthy host produces zero warnings', function () {
    assert_eq([], wpultra_env_warnings(envinfo_test_clean_facts()));
});

it('warnings: memory_limit below 256M warns', function () {
    $facts = envinfo_test_clean_facts();
    $facts['memory_limit_bytes'] = 128 * 1024 * 1024;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(in_array('low_memory_limit', $ids, true));
});

it('warnings: memory_limit unlimited (-1) does not warn', function () {
    $facts = envinfo_test_clean_facts();
    $facts['memory_limit_bytes'] = -1;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('low_memory_limit', $ids, true));
});

it('warnings: memory_limit exactly 256M does not warn', function () {
    $facts = envinfo_test_clean_facts();
    $facts['memory_limit_bytes'] = 256 * 1024 * 1024;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('low_memory_limit', $ids, true));
});

it('warnings: max_execution_time below 60 and nonzero warns', function () {
    $facts = envinfo_test_clean_facts();
    $facts['max_execution_time'] = 30;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(in_array('low_max_execution_time', $ids, true));
});

it('warnings: max_execution_time of 0 (unlimited) does not warn', function () {
    $facts = envinfo_test_clean_facts();
    $facts['max_execution_time'] = 0;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('low_max_execution_time', $ids, true));
});

it('warnings: max_execution_time of 60 does not warn', function () {
    $facts = envinfo_test_clean_facts();
    $facts['max_execution_time'] = 60;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('low_max_execution_time', $ids, true));
});

it('warnings: upload_max_filesize below 8M warns', function () {
    $facts = envinfo_test_clean_facts();
    $facts['upload_max_filesize_bytes'] = 2 * 1024 * 1024;
    $facts['post_max_size_bytes'] = 2 * 1024 * 1024;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(in_array('low_upload_max_filesize', $ids, true));
});

it('warnings: upload_max_filesize of 8M does not warn', function () {
    $facts = envinfo_test_clean_facts();
    $facts['upload_max_filesize_bytes'] = 8 * 1024 * 1024;
    $facts['post_max_size_bytes'] = 8 * 1024 * 1024;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('low_upload_max_filesize', $ids, true));
});

it('warnings: post_max_size smaller than upload_max_filesize warns', function () {
    $facts = envinfo_test_clean_facts();
    $facts['upload_max_filesize_bytes'] = 64 * 1024 * 1024;
    $facts['post_max_size_bytes'] = 16 * 1024 * 1024;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(in_array('post_max_lt_upload_max', $ids, true));
});

it('warnings: post_max_size equal to upload_max_filesize does not warn', function () {
    $facts = envinfo_test_clean_facts();
    $facts['upload_max_filesize_bytes'] = 64 * 1024 * 1024;
    $facts['post_max_size_bytes'] = 64 * 1024 * 1024;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('post_max_lt_upload_max', $ids, true));
});

it('warnings: missing curl/gd/mbstring/openssl each warn individually', function () {
    $facts = envinfo_test_clean_facts();
    $facts['extensions'] = ['curl' => false, 'gd' => false, 'mbstring' => false, 'openssl' => false];
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(in_array('missing_ext_curl', $ids, true));
    assert_true(in_array('missing_ext_gd', $ids, true));
    assert_true(in_array('missing_ext_mbstring', $ids, true));
    assert_true(in_array('missing_ext_openssl', $ids, true));
});

it('warnings: present extensions produce no missing_ext warnings', function () {
    $facts = envinfo_test_clean_facts();
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    foreach (['missing_ext_curl', 'missing_ext_gd', 'missing_ext_mbstring', 'missing_ext_openssl'] as $id) {
        assert_true(!in_array($id, $ids, true), "$id should not fire");
    }
});

it('warnings: disk free below 5 percent warns', function () {
    $facts = envinfo_test_clean_facts();
    $facts['disk_total_bytes'] = 100 * 1024 * 1024 * 1024;
    $facts['disk_free_bytes']  = 2 * 1024 * 1024 * 1024; // 2%
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(in_array('low_disk_space', $ids, true));
});

it('warnings: disk free below 500MB warns even if percent is fine', function () {
    $facts = envinfo_test_clean_facts();
    $facts['disk_total_bytes'] = 100 * 1024 * 1024; // tiny total so pct is high
    $facts['disk_free_bytes']  = 50 * 1024 * 1024;  // 50% but only 50MB absolute
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(in_array('low_disk_space', $ids, true));
});

it('warnings: healthy disk (plenty pct and absolute) does not warn', function () {
    $facts = envinfo_test_clean_facts();
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('low_disk_space', $ids, true));
});

it('warnings: missing disk facts (null) do not warn', function () {
    $facts = envinfo_test_clean_facts();
    $facts['disk_free_bytes'] = null;
    $facts['disk_total_bytes'] = null;
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('low_disk_space', $ids, true));
});

it('warnings: PHP below 8.0 warns as outdated', function () {
    $facts = envinfo_test_clean_facts();
    $facts['php_version'] = '7.4.33';
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(in_array('outdated_php', $ids, true));
});

it('warnings: PHP 8.0+ does not warn as outdated', function () {
    $facts = envinfo_test_clean_facts();
    $facts['php_version'] = '8.0.0';
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('outdated_php', $ids, true));
});

it('warnings: opcache disabled produces an info-level note', function () {
    $facts = envinfo_test_clean_facts();
    $facts['opcache_enabled'] = false;
    $warnings = wpultra_env_warnings($facts);
    $note = null;
    foreach ($warnings as $w) { if ($w['id'] === 'opcache_disabled') { $note = $w; } }
    assert_true($note !== null, 'expected opcache_disabled warning');
    assert_eq('info', $note['severity']);
});

it('warnings: opcache enabled produces no note', function () {
    $facts = envinfo_test_clean_facts();
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('opcache_disabled', $ids, true));
});

it('warnings: every warning has id, severity, and message keys', function () {
    $facts = envinfo_test_clean_facts();
    $facts['memory_limit_bytes'] = 64 * 1024 * 1024;
    $facts['php_version'] = '7.2.0';
    foreach (wpultra_env_warnings($facts) as $w) {
        assert_true(array_key_exists('id', $w));
        assert_true(array_key_exists('severity', $w));
        assert_true(array_key_exists('message', $w));
        assert_true(in_array($w['severity'], ['warn', 'info'], true), 'severity must be warn or info');
    }
});

it('warnings: multiple rules can fire together', function () {
    $facts = envinfo_test_clean_facts();
    $facts['memory_limit_bytes'] = 64 * 1024 * 1024;
    $facts['max_execution_time'] = 10;
    $facts['php_version'] = '7.4.0';
    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(in_array('low_memory_limit', $ids, true));
    assert_true(in_array('low_max_execution_time', $ids, true));
    assert_true(in_array('outdated_php', $ids, true));
});

/* ------------------------------------------------------------------ *
 * wpultra_env_build_facts -> wpultra_env_warnings — unavailable ini reads
 * ------------------------------------------------------------------ */

it('build_facts: unavailable memory_limit/upload/post_max ini reads do not trigger false low warnings', function () {
    $php = [
        'version' => '8.2.30',
        'ini'     => [
            // Simulates a locked-down host where ini_get() returned false
            // for these directives, so wpultra_env_ini() degraded them to
            // null (see wpultra_env_php_section()).
            'memory_limit'        => null,
            'max_execution_time'  => null,
            'upload_max_filesize' => null,
            'post_max_size'       => null,
        ],
    ];
    $extensions = ['curl' => true, 'gd' => true, 'mbstring' => true, 'openssl' => true];
    $opcache = ['enabled' => true];
    $server = ['disk_free' => null, 'disk_total' => null];

    $facts = wpultra_env_build_facts($php, $extensions, $opcache, $server);

    assert_eq(-1, $facts['memory_limit_bytes']);
    assert_eq(-1, $facts['upload_max_filesize_bytes']);
    assert_eq(-1, $facts['post_max_size_bytes']);

    $ids = array_column(wpultra_env_warnings($facts), 'id');
    assert_true(!in_array('low_memory_limit', $ids, true), 'unavailable memory_limit must not read as 0 bytes');
    assert_true(!in_array('low_upload_max_filesize', $ids, true), 'unavailable upload_max_filesize must not read as 0 bytes');
    assert_true(!in_array('post_max_lt_upload_max', $ids, true), 'unavailable post_max_size/upload_max_filesize must not read as 0 bytes');
});

run_tests();
