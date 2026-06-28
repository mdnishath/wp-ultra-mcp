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

run_tests();
