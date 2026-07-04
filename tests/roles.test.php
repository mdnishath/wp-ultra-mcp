<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_roles/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/roles.php';

/* ============================================================
 * valid_slug matrix
 * ============================================================ */

it('valid_slug accepts lowercase alnum with _ and -', function () {
    assert_true(wpultra_roles_valid_slug('editor'));
    assert_true(wpultra_roles_valid_slug('shop_manager'));
    assert_true(wpultra_roles_valid_slug('shop-manager'));
    assert_true(wpultra_roles_valid_slug('role_2'));
    assert_true(wpultra_roles_valid_slug('a'));
    assert_true(wpultra_roles_valid_slug('0role'));
});

it('valid_slug rejects bad shapes', function () {
    assert_true(!wpultra_roles_valid_slug(''), 'empty');
    assert_true(!wpultra_roles_valid_slug('Editor'), 'uppercase');
    assert_true(!wpultra_roles_valid_slug('_leading'), 'leading underscore');
    assert_true(!wpultra_roles_valid_slug('-leading'), 'leading dash');
    assert_true(!wpultra_roles_valid_slug('has space'), 'space');
    assert_true(!wpultra_roles_valid_slug('bad!'), 'punct');
    assert_true(!wpultra_roles_valid_slug(str_repeat('a', 65)), 'too long');
});

/* ============================================================
 * valid_cap matrix
 * ============================================================ */

it('valid_cap accepts wp cap keys', function () {
    assert_true(wpultra_roles_valid_cap('manage_options'));
    assert_true(wpultra_roles_valid_cap('edit_posts'));
    assert_true(wpultra_roles_valid_cap('manage_woocommerce'));
    assert_true(wpultra_roles_valid_cap('read'));
});

it('valid_cap rejects bad cap names', function () {
    assert_true(!wpultra_roles_valid_cap(''), 'empty');
    assert_true(!wpultra_roles_valid_cap('edit posts'), 'space');
    assert_true(!wpultra_roles_valid_cap('edit-posts'), 'dash');
    assert_true(!wpultra_roles_valid_cap('9lives'), 'leading digit');
    assert_true(!wpultra_roles_valid_cap('bad!cap'), 'punct');
});

/* ============================================================
 * is_protected
 * ============================================================ */

it('is_protected true for core roles', function () {
    foreach (['administrator', 'editor', 'author', 'contributor', 'subscriber'] as $slug) {
        assert_true(wpultra_roles_is_protected($slug), "$slug protected");
    }
    assert_true(wpultra_roles_is_protected('ADMINISTRATOR'), 'case-insensitive');
});

it('is_protected false for custom roles', function () {
    assert_true(!wpultra_roles_is_protected('shop_manager'));
    assert_true(!wpultra_roles_is_protected('custom_role'));
    assert_true(!wpultra_roles_is_protected(''));
});

/* ============================================================
 * normalize_caps
 * ============================================================ */

it('normalize_caps handles list and map forms', function () {
    assert_eq(['read' => true, 'edit_posts' => true], wpultra_roles_normalize_caps(['read', 'edit_posts']));
    assert_eq(['read' => true, 'edit_posts' => false], wpultra_roles_normalize_caps(['read' => 1, 'edit_posts' => 0]));
    assert_eq([], wpultra_roles_normalize_caps([]));
});

/* ============================================================
 * guard_admin_caps
 * ============================================================ */

it('guard_admin_caps blocks stripping manage_options from administrator', function () {
    $map = [
        'administrator' => ['capabilities' => ['manage_options' => true]],
        'editor'        => ['capabilities' => ['edit_posts' => true]],
    ];
    $res = wpultra_roles_guard_admin_caps('administrator', ['edit_posts' => true], $map);
    assert_true(is_string($res), 'returns refusal string');
    assert_contains('manage_options', $res);
});

it('guard_admin_caps allows editing a custom role', function () {
    $map = [
        'administrator' => ['capabilities' => ['manage_options' => true]],
        'custom'        => ['capabilities' => ['edit_posts' => true]],
    ];
    $res = wpultra_roles_guard_admin_caps('custom', ['read' => true], $map);
    assert_true($res === true, 'custom edit ok');
});

it('guard_admin_caps blocks an edit that leaves zero admin roles', function () {
    // Only administrator has manage_options; stripping it (via update) leaves none.
    $map = ['administrator' => ['capabilities' => ['manage_options' => true]]];
    // Simulate updating administrator itself to no caps — rule 1 catches it.
    $res = wpultra_roles_guard_admin_caps('administrator', [], $map);
    assert_true(is_string($res), 'blocked');
});

it('guard_admin_caps blocks stripping manage_options from the sole admin custom role', function () {
    // A site where a custom role is the ONLY manage_options holder.
    $map = ['superadmin' => ['capabilities' => ['manage_options' => true]]];
    $res = wpultra_roles_guard_admin_caps('superadmin', ['read' => true], $map);
    assert_true(is_string($res), 'zero-admin blocked');
    assert_contains('manage_options', $res);
});

it('guard_admin_caps allows editing sole admin role while keeping manage_options', function () {
    $map = ['administrator' => ['capabilities' => ['manage_options' => true]]];
    $res = wpultra_roles_guard_admin_caps('administrator', ['manage_options' => true, 'read' => true], $map);
    assert_true($res === true, 'kept manage_options ok');
});

it('guard_admin_caps allows creating a new non-admin role', function () {
    $map = ['administrator' => ['capabilities' => ['manage_options' => true]]];
    $res = wpultra_roles_guard_admin_caps('brand_new', ['read' => true], $map);
    assert_true($res === true);
});

/* ============================================================
 * caps_are_admin
 * ============================================================ */

it('caps_are_admin detects manage_options', function () {
    assert_true(wpultra_roles_caps_are_admin(['manage_options' => true]));
    assert_true(wpultra_roles_caps_are_admin(['manage_options']));
    assert_true(!wpultra_roles_caps_are_admin(['edit_posts' => true]));
    assert_true(!wpultra_roles_caps_are_admin(['manage_options' => false]));
});

/* ============================================================
 * cap_diff
 * ============================================================ */

it('cap_diff reports added and removed', function () {
    $before = ['read' => true, 'edit_posts' => true];
    $after  = ['read' => true, 'upload_files' => true];
    $diff = wpultra_roles_cap_diff($before, $after);
    assert_eq(['upload_files'], $diff['added']);
    assert_eq(['edit_posts'], $diff['removed']);
});

it('cap_diff treats false as removed', function () {
    $diff = wpultra_roles_cap_diff(['edit_posts' => true], ['edit_posts' => false]);
    assert_eq([], $diff['added']);
    assert_eq(['edit_posts'], $diff['removed']);
});

it('cap_diff no-change yields empty', function () {
    $diff = wpultra_roles_cap_diff(['read' => true], ['read' => true]);
    assert_eq([], $diff['added']);
    assert_eq([], $diff['removed']);
});

it('cap_diff accepts list form', function () {
    $diff = wpultra_roles_cap_diff(['read'], ['read', 'edit_posts']);
    assert_eq(['edit_posts'], $diff['added']);
    assert_eq([], $diff['removed']);
});

/* ============================================================
 * cap_catalog
 * ============================================================ */

it('cap_catalog has non-empty groups', function () {
    $cat = wpultra_roles_cap_catalog();
    assert_true(count($cat) >= 5, 'several groups');
    foreach ($cat as $group => $caps) {
        assert_true(is_array($caps) && count($caps) > 0, "group $group non-empty");
    }
});

it('cap_catalog contains manage_options in core group', function () {
    $cat = wpultra_roles_cap_catalog();
    assert_true(isset($cat['core']), 'core group present');
    assert_true(in_array('manage_options', $cat['core'], true), 'manage_options present');
});

it('cap_catalog has a woocommerce group with manage_woocommerce', function () {
    $cat = wpultra_roles_cap_catalog();
    assert_true(isset($cat['woocommerce']), 'woo group present');
    assert_true(in_array('manage_woocommerce', $cat['woocommerce'], true), 'manage_woocommerce present');
});

it('cap_catalog covers content, media and users groups', function () {
    $cat = wpultra_roles_cap_catalog();
    assert_true(in_array('edit_posts', $cat['content'], true), 'edit_posts');
    assert_true(in_array('publish_posts', $cat['content'], true), 'publish_posts');
    assert_true(in_array('upload_files', $cat['media'], true), 'upload_files');
    assert_true(in_array('list_users', $cat['users'], true), 'list_users');
    assert_true(in_array('promote_users', $cat['users'], true), 'promote_users');
});

/* ============================================================
 * group_caps
 * ============================================================ */

it('group_caps buckets caps by catalog and marks unknown as other', function () {
    $grouped = wpultra_roles_group_caps(['manage_options' => true, 'edit_posts' => true, 'my_custom_cap' => true, 'ignored' => false]);
    assert_true(in_array('manage_options', $grouped['core'], true), 'core');
    assert_true(in_array('edit_posts', $grouped['content'], true), 'content');
    assert_true(in_array('my_custom_cap', $grouped['other'], true), 'other');
    // false caps excluded entirely.
    $flat = array_merge(...array_values($grouped));
    assert_true(!in_array('ignored', $flat, true), 'false cap excluded');
});

/* ============================================================
 * core_slugs
 * ============================================================ */

it('core_slugs lists the five wp roles', function () {
    $slugs = wpultra_roles_core_slugs();
    assert_eq(5, count($slugs));
    assert_true(in_array('administrator', $slugs, true));
    assert_true(in_array('subscriber', $slugs, true));
});

run_tests();
