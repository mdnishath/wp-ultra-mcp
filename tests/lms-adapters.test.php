<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/verticals/lms-adapters.php';

/* ---------------- driver resolution (pure over a detection map) ---------------- */

it('lmsx driver honours explicit choice when installed', function () {
    $detected = ['learndash' => '4.10', 'lifterlms' => null, 'tutor' => null];
    assert_eq('learndash', wpultra_lmsx_driver('learndash', $detected));
});

it('lmsx driver errors when explicit plugin is not installed', function () {
    $detected = ['learndash' => null, 'lifterlms' => null, 'tutor' => null];
    $err = wpultra_lmsx_driver('tutor', $detected);
    assert_wp_error($err);
    assert_eq('lms_plugin_unavailable', $err->get_error_code());
});

it('lmsx driver errors on an unknown plugin key', function () {
    $err = wpultra_lmsx_driver('moodle', ['learndash' => '4.10']);
    assert_eq('lms_unknown_plugin', $err->get_error_code());
});

it('lmsx driver auto-picks first detected in canonical order', function () {
    $detected = ['learndash' => null, 'lifterlms' => '7.5', 'tutor' => '2.7'];
    assert_eq('lifterlms', wpultra_lmsx_driver('', $detected));
});

it('lmsx driver errors when nothing is installed', function () {
    $err = wpultra_lmsx_driver('', ['learndash' => null, 'lifterlms' => null, 'tutor' => null]);
    assert_eq('lms_plugin_unavailable', $err->get_error_code());
});

it('lmsx detection never fatals with no plugins present and returns all keys null', function () {
    $d = wpultra_lmsx_detect();
    assert_eq(['learndash', 'lifterlms', 'tutor'], array_keys($d));
    assert_eq(null, $d['learndash']);
    assert_eq(null, $d['tutor']);
});

/* ---------------- shaping ---------------- */

it('lmsx course post types map per plugin', function () {
    assert_eq('sfwd-courses', wpultra_lmsx_course_post_type('learndash'));
    assert_eq('course', wpultra_lmsx_course_post_type('lifterlms'));
    assert_eq('courses', wpultra_lmsx_course_post_type('tutor'));
    assert_eq('', wpultra_lmsx_course_post_type('builtin'));
});

it('lmsx course shape casts and tags the plugin', function () {
    $c = wpultra_lmsx_shape_course(['ID' => '7', 'post_title' => 'PHP 101', 'post_status' => 'publish'], 'lifterlms');
    assert_eq(7, $c['id']);
    assert_eq('PHP 101', $c['name']);
    assert_eq('lifterlms', $c['plugin']);
});

it('lmsx progress shape clamps pct and derives complete', function () {
    $p = wpultra_lmsx_shape_progress(66.7, 2, 3, 'tutor');
    assert_eq(67, $p['pct']);
    assert_eq(false, $p['complete']);
    assert_eq(2, $p['lessons_done']);
    $done = wpultra_lmsx_shape_progress(100.0, 3, 3, 'learndash');
    assert_true($done['complete']);
    // out-of-range input clamps
    assert_eq(100, wpultra_lmsx_shape_progress(250, 9, 3, 'x')['pct']);
    assert_eq(0, wpultra_lmsx_shape_progress(-5, -1, -1, 'x')['pct']);
    assert_eq(0, wpultra_lmsx_shape_progress(-5, -1, -1, 'x')['lessons_done']);
});

it('lmsx status rows cover every known plugin with installed=false when absent', function () {
    $rows = wpultra_lmsx_status();
    assert_eq(3, count($rows));
    assert_eq('LearnDash', $rows[0]['label']);
    assert_eq(false, $rows[0]['installed']);
    assert_eq('Tutor LMS', $rows[2]['label']);
});

run_tests();
