<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/media/editing.php';

// ---- wpultra_media_edit_validate_ops: happy paths ----

it('validate_ops accepts a valid resize op (width only)', function () {
    assert_true(wpultra_media_edit_validate_ops([['op' => 'resize', 'width' => 100]]) === true);
});

it('validate_ops accepts a valid resize op (width+height+crop)', function () {
    assert_true(wpultra_media_edit_validate_ops([['op' => 'resize', 'width' => 100, 'height' => 50, 'crop' => true]]) === true);
});

it('validate_ops accepts a valid crop op', function () {
    assert_true(wpultra_media_edit_validate_ops([['op' => 'crop', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]]) === true);
});

it('validate_ops accepts a valid rotate op', function () {
    assert_true(wpultra_media_edit_validate_ops([['op' => 'rotate', 'degrees' => 90]]) === true);
    assert_true(wpultra_media_edit_validate_ops([['op' => 'rotate', 'degrees' => -45.5]]) === true);
});

it('validate_ops accepts a valid flip op', function () {
    assert_true(wpultra_media_edit_validate_ops([['op' => 'flip', 'horizontal' => true]]) === true);
    assert_true(wpultra_media_edit_validate_ops([['op' => 'flip', 'vertical' => true]]) === true);
    assert_true(wpultra_media_edit_validate_ops([['op' => 'flip', 'horizontal' => true, 'vertical' => true]]) === true);
});

it('validate_ops accepts a valid quality op', function () {
    assert_true(wpultra_media_edit_validate_ops([['op' => 'quality', 'value' => 1]]) === true);
    assert_true(wpultra_media_edit_validate_ops([['op' => 'quality', 'value' => 100]]) === true);
    assert_true(wpultra_media_edit_validate_ops([['op' => 'quality', 'value' => 82]]) === true);
});

it('validate_ops accepts a valid convert op for each whitelisted format', function () {
    foreach (['jpeg', 'png', 'webp'] as $fmt) {
        assert_true(wpultra_media_edit_validate_ops([['op' => 'convert', 'format' => $fmt]]) === true, "format=$fmt");
    }
});

it('validate_ops accepts multiple ops and preserves order semantics (no reordering happens)', function () {
    $ops = [
        ['op' => 'rotate', 'degrees' => 90],
        ['op' => 'resize', 'width' => 200],
        ['op' => 'quality', 'value' => 80],
        ['op' => 'convert', 'format' => 'webp'],
    ];
    assert_true(wpultra_media_edit_validate_ops($ops) === true);
    // order preserved: the array itself is untouched by validation.
    assert_eq('rotate', $ops[0]['op']);
    assert_eq('resize', $ops[1]['op']);
    assert_eq('quality', $ops[2]['op']);
    assert_eq('convert', $ops[3]['op']);
});

// ---- wpultra_media_edit_validate_ops: bad paths ----

it('validate_ops rejects a non-array', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops('nope')));
});

it('validate_ops rejects an empty array', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([])));
});

it('validate_ops rejects a non-list (associative) array', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops(['op' => 'resize', 'width' => 10])));
});

it('validate_ops rejects an unknown op', function () {
    $res = wpultra_media_edit_validate_ops([['op' => 'sharpen']]);
    assert_true(is_string($res));
    assert_contains('unknown op', $res);
});

it('validate_ops rejects an op missing its "op" key', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['width' => 10]])));
});

it('validate_ops rejects resize with neither width nor height', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'resize']])));
});

it('validate_ops rejects resize with a non-integer width', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'resize', 'width' => 'big']])));
});

it('validate_ops rejects resize with a zero/negative width', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'resize', 'width' => 0]])));
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'resize', 'width' => -5]])));
});

it('validate_ops rejects resize with a non-boolean crop', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'resize', 'width' => 10, 'crop' => 'yes']])));
});

it('validate_ops rejects crop missing any required param', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'crop', 'x' => 0, 'y' => 0, 'width' => 10]])));
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'crop']])));
});

it('validate_ops rejects crop with negative x/y or non-positive width/height', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'crop', 'x' => -1, 'y' => 0, 'width' => 10, 'height' => 10]])));
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'crop', 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 10]])));
});

it('validate_ops rejects rotate missing degrees or non-numeric degrees', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'rotate']])));
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'rotate', 'degrees' => 'ninety']])));
});

it('validate_ops rejects flip with neither horizontal nor vertical true', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'flip']])));
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'flip', 'horizontal' => false, 'vertical' => false]])));
});

it('validate_ops rejects flip with non-boolean params', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'flip', 'horizontal' => 'true']])));
});

it('validate_ops rejects quality missing value or non-integer value', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'quality']])));
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'quality', 'value' => '80']])));
});

it('validate_ops rejects quality out of 1-100 range (no silent clamping)', function () {
    $low = wpultra_media_edit_validate_ops([['op' => 'quality', 'value' => 0]]);
    $high = wpultra_media_edit_validate_ops([['op' => 'quality', 'value' => 101]]);
    assert_true(is_string($low));
    assert_true(is_string($high));
    assert_contains('between 1 and 100', $low);
});

it('validate_ops rejects convert missing format', function () {
    assert_true(is_string(wpultra_media_edit_validate_ops([['op' => 'convert']])));
});

it('validate_ops rejects convert with a format outside the whitelist', function () {
    $res = wpultra_media_edit_validate_ops([['op' => 'convert', 'format' => 'gif']]);
    assert_true(is_string($res));
    assert_contains('jpeg', $res);
    assert_contains('png', $res);
    assert_contains('webp', $res);
});

it('validate_ops reports the first bad op in a multi-op list (fails fast)', function () {
    $ops = [
        ['op' => 'resize', 'width' => 10],
        ['op' => 'crop', 'x' => 0, 'y' => 0, 'width' => -1, 'height' => 10],
        ['op' => 'quality', 'value' => 999],
    ];
    $res = wpultra_media_edit_validate_ops($ops);
    assert_true(is_string($res));
    assert_contains('operations[1]', $res);
});

// ---- wpultra_media_edit_suffix ----

it('suffix builder keeps original extension when none supplied', function () {
    assert_eq('photo-edited.jpg', wpultra_media_edit_suffix('photo.jpg', '', 1));
});

it('suffix builder increments N for n>1', function () {
    assert_eq('photo-edited.jpg', wpultra_media_edit_suffix('photo.jpg', '', 1));
    assert_eq('photo-edited-2.jpg', wpultra_media_edit_suffix('photo.jpg', '', 2));
    assert_eq('photo-edited-3.jpg', wpultra_media_edit_suffix('photo.jpg', '', 3));
});

it('suffix builder swaps extension on convert', function () {
    assert_eq('photo-edited.webp', wpultra_media_edit_suffix('photo.jpg', 'webp', 1));
    assert_eq('photo-edited-2.webp', wpultra_media_edit_suffix('photo.jpg', 'webp', 2));
    assert_eq('photo-edited.png', wpultra_media_edit_suffix('photo.jpeg', 'png', 1));
});

it('suffix builder handles a filename with no extension', function () {
    assert_eq('photo-edited', wpultra_media_edit_suffix('photo', '', 1));
});

it('suffix builder handles a filename with dots in the basename', function () {
    assert_eq('my.photo-edited.jpg', wpultra_media_edit_suffix('my.photo.jpg', '', 1));
});

// ---- wpultra_media_edit_next_suffix (collision search) ----

it('next_suffix picks the first free "-edited" name when none exist', function () {
    assert_eq('photo-edited.jpg', wpultra_media_edit_next_suffix('photo.jpg', '', ['photo.jpg']));
});

it('next_suffix increments past existing "-edited"/"-edited-N" collisions', function () {
    $existing = ['photo.jpg', 'photo-edited.jpg', 'photo-edited-2.jpg'];
    assert_eq('photo-edited-3.jpg', wpultra_media_edit_next_suffix('photo.jpg', '', $existing));
});

it('next_suffix uses the converted extension for collision checks', function () {
    $existing = ['photo.jpg', 'photo-edited.webp'];
    assert_eq('photo-edited-2.webp', wpultra_media_edit_next_suffix('photo.jpg', 'webp', $existing));
});

// ---- wpultra_media_edit_format_mimes ----

it('format_mimes exposes exactly the jpeg/png/webp whitelist', function () {
    assert_eq(['jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'], wpultra_media_edit_format_mimes());
});

run_tests();
