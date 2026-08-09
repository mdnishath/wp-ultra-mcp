<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/var/www/wp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';

it('normalize collapses dot-dot and slashes', function () {
    assert_eq('/var/www/wp/x', wpultra_normalize_absolute_path('/var/www/wp/a/../x'));
    assert_eq('/var/www/wp', wpultra_normalize_absolute_path('/var/www/wp/'));
    assert_eq('/a/b', wpultra_normalize_absolute_path('\\a\\b\\'));
});

it('within-directory detects containment and escape', function () {
    assert_true(wpultra_path_is_within_directory('/var/www/wp/x.php', '/var/www/wp'), 'inside');
    assert_true(wpultra_path_is_within_directory('/var/www/wp', '/var/www/wp'), 'equal');
    assert_eq(false, wpultra_path_is_within_directory('/var/www/other', '/var/www/wp'), 'sibling');
    assert_eq(false, wpultra_path_is_within_directory('/etc/passwd', '/var/www/wp'), 'escape');
});

it('within-directory is case-insensitive for Windows-style paths only', function () {
    // realpath() can report C:\ while ABSPATH says c:/ — same dir on NTFS.
    assert_true(wpultra_path_is_within_directory('C:\\xampp\\htdocs\\wp\\x.php', 'c:/xampp/htdocs/wp'), 'drive case');
    assert_true(wpultra_path_is_within_directory('C:/Xampp/Htdocs/WP', 'c:/xampp/htdocs/wp'), 'segment case');
    assert_eq(false, wpultra_path_is_within_directory('C:/xampp/other', 'c:/xampp/htdocs/wp'), 'win sibling');
    assert_true(wpultra_path_is_within_directory('//srv/share/wp/x.php', '//SRV/share/wp'), 'unc case');
    // POSIX paths stay case-SENSITIVE (ext4 etc. really are).
    assert_eq(false, wpultra_path_is_within_directory('/var/www/WP/x.php', '/var/www/wp'), 'posix stays sensitive');
});

it('require_confirm gates on confirm:true', function () {
    assert_eq(null, wpultra_require_confirm(['confirm' => true], 'Deleting X is permanent.'));
    $e = wpultra_require_confirm(['confirm' => false], 'Deleting X is permanent.');
    assert_wp_error($e);
    assert_eq('confirm_required', $e->get_error_code());
    // Missing key = not confirmed.
    assert_wp_error(wpultra_require_confirm([], 'Deleting X is permanent.'));
    // Truthy-but-not-true (e.g. "1") must NOT satisfy the gate.
    assert_wp_error(wpultra_require_confirm(['confirm' => '1'], 'X'));
    assert_wp_error(wpultra_require_confirm(['confirm' => 1], 'X'));
});

it('require_confirm preserves custom code and appends hint only when absent', function () {
    $e = wpultra_require_confirm(['confirm' => false], 'Bulk payout.', 'unconfirmed');
    assert_eq('unconfirmed', $e->get_error_code());
    assert_contains('Re-run with confirm: true.', $e->get_error_message()); // appended
    $e2 = wpultra_require_confirm(['confirm' => false], 'Already says confirm: true here.');
    assert_eq('Already says confirm: true here.', $e2->get_error_message()); // not doubled
});

it('paginate: no params returns whole list up to default, with meta', function () {
    $items = range(1, 10);
    [$page, $meta] = wpultra_paginate($items, [], 500);
    assert_eq($items, $page);
    assert_eq(10, $meta['total']);
    assert_eq(10, $meta['returned']);
});

it('paginate: limit + offset slice correctly', function () {
    $items = range(1, 10);
    [$page, $meta] = wpultra_paginate($items, ['limit' => 3, 'offset' => 4]);
    assert_eq([5, 6, 7], $page);
    assert_eq(10, $meta['total']);
    assert_eq(3, $meta['returned']);
    // offset past the end returns empty but keeps total.
    [$p2] = wpultra_paginate($items, ['limit' => 5, 'offset' => 50]);
    assert_eq([], $p2);
    // zero/negative limit falls back to the default page size.
    [$p3] = wpultra_paginate($items, ['limit' => 0], 4);
    assert_eq([1, 2, 3, 4], $p3);
});

it('identifier validation', function () {
    assert_true(wpultra_is_valid_identifier('wp_posts'), 'ok');
    assert_eq(false, wpultra_is_valid_identifier('posts; DROP'), 'inject');
});

it('classify query verb and destructive flag', function () {
    assert_eq(['verb' => 'SELECT', 'destructive' => false], wpultra_classify_query('  SELECT * FROM wp_posts '));
    assert_eq(false, wpultra_classify_query('INSERT INTO wp_x VALUES (1)')['destructive']);
    assert_eq(false, wpultra_classify_query('SHOW TABLES')['destructive']);
    assert_eq(['verb' => 'DELETE', 'destructive' => true], wpultra_classify_query('DELETE FROM wp_posts'));
    // A WHERE clause no longer exempts DELETE/UPDATE — `WHERE 1=1` is a trivial bypass.
    assert_eq(true, wpultra_classify_query('delete from wp_posts where ID=1')['destructive']);
    assert_eq(true, wpultra_classify_query('UPDATE wp_posts SET x=1 WHERE ID=1')['destructive']);
    assert_eq(true, wpultra_classify_query('DROP TABLE wp_x')['destructive']);
    assert_eq(true, wpultra_classify_query('TRUNCATE wp_x')['destructive']);
    assert_eq(true, wpultra_classify_query('GRANT ALL ON *.* TO x')['destructive']);
    assert_eq(true, wpultra_classify_query('WITH t AS (SELECT 1) DELETE FROM wp_x')['destructive']);
    // A leading comment must not hide the verb, and file-writing SELECTs need confirmation.
    assert_eq(true, wpultra_classify_query('/*x*/DELETE FROM wp_x')['destructive'], 'comment-hidden delete');
    assert_eq(true, wpultra_classify_query("SELECT * INTO OUTFILE '/tmp/x.php' FROM wp_users")['destructive'], 'outfile');
    assert_eq(true, wpultra_classify_query('SELECT LOAD_FILE("/etc/passwd")')['destructive'], 'load_file');
    assert_eq(false, wpultra_classify_query('SELECT * FROM wp_posts')['destructive'], 'plain select safe');
});

it('sandbox detection', function () {
    assert_true(wpultra_path_requires_sandbox('/a/b/functions.php'), 'php');
    assert_true(wpultra_path_requires_sandbox('/a/.htaccess'), 'htaccess');
    assert_eq(false, wpultra_path_requires_sandbox('/a/style.css'), 'css');
    // Bypass vectors that the naive str_ends_with('.php') check missed.
    assert_true(wpultra_path_requires_sandbox('/a/shell.phtml'), 'phtml');
    assert_true(wpultra_path_requires_sandbox('/a/shell.php5'), 'php5');
    assert_true(wpultra_path_requires_sandbox('/a/shell.PHP'), 'uppercase');
    assert_true(wpultra_path_requires_sandbox('/a/shell.php.'), 'trailing dot');
    assert_true(wpultra_path_requires_sandbox('/a/shell.php '), 'trailing space');
    assert_true(wpultra_path_requires_sandbox('/a/.user.ini'), 'user.ini');
});

it('wp-cli unsafe command detection', function () {
    assert_eq('eval', wpultra_wp_cli_unsafe_command(['eval', 'echo 1;']), 'eval');
    assert_eq('shell', wpultra_wp_cli_unsafe_command(['shell']), 'shell');
    assert_eq('db query', wpultra_wp_cli_unsafe_command(['db', 'query', 'DROP TABLE x']), 'db query');
    assert_eq('config set', wpultra_wp_cli_unsafe_command(['config', 'set', 'DB_HOST', 'x']), 'config set');
    // flags before the command must be skipped
    assert_eq('eval', wpultra_wp_cli_unsafe_command(['--path=/x', 'eval', 'code']), 'flag then eval');
    // safe commands return empty
    assert_eq('', wpultra_wp_cli_unsafe_command(['plugin', 'list']), 'plugin list safe');
    assert_eq('', wpultra_wp_cli_unsafe_command(['cache', 'flush']), 'cache flush safe');
    assert_eq('', wpultra_wp_cli_unsafe_command(['db', 'size']), 'db size safe');
});

run_tests();
