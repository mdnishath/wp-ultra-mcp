<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/coerce.php';

it('detects wrapped values', function () {
    assert_true(wpultra_el_already_wrapped(['$$type' => 'string', 'value' => 'x']), 'wrapped');
    assert_eq(false, wpultra_el_already_wrapped('plain'), 'scalar');
    assert_eq(false, wpultra_el_already_wrapped(['value' => 'x']), 'no $$type');
});
it('wraps scalar settings per schema', function () {
    $schema = ['tag' => ['type' => 'string'], 'count' => ['type' => 'number'], 'on' => ['type' => 'boolean']];
    $out = wpultra_el_wrap_settings(['tag' => 'h1', 'count' => 3, 'on' => true], $schema);
    assert_eq(['$$type' => 'string', 'value' => 'h1'], $out['tag']);
    assert_eq(['$$type' => 'number', 'value' => 3], $out['count']);
    assert_eq(['$$type' => 'boolean', 'value' => true], $out['on']);
});
it('passes through already-wrapped + unknown-type values', function () {
    $schema = ['title' => ['type' => 'html-v3'], 'tag' => ['type' => 'string']];
    $in = ['title' => ['$$type' => 'html-v3', 'value' => ['content' => 'x']], 'tag' => ['$$type' => 'string', 'value' => 'h2']];
    $out = wpultra_el_wrap_settings($in, $schema);
    assert_eq($in['title'], $out['title']);
    assert_eq($in['tag'], $out['tag']);
});
it('leaves unknown keys untouched', function () {
    $out = wpultra_el_wrap_settings(['foo' => 'bar'], ['tag' => ['type' => 'string']]);
    assert_eq('bar', $out['foo']);
});
it('wraps a union scalar using the default $$type', function () {
    $schema = ['tag' => ['type' => 'union', 'default' => ['$$type' => 'string', 'value' => 'h2']]];
    $out = wpultra_el_wrap_settings(['tag' => 'h1'], $schema);
    assert_eq(['$$type' => 'string', 'value' => 'h1'], $out['tag']);
});
it('leaves a union scalar untouched when no wrapped default', function () {
    $out = wpultra_el_wrap_settings(['x' => 'y'], ['x' => ['type' => 'union']]);
    assert_eq('y', $out['x']);
});

run_tests();
