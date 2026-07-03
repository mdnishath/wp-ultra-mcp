<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/media/engine.php';
require __DIR__ . '/../wp-ultra-mcp/includes/media/generate.php';

// ---- source resolution matrix ----

it('resolve_source: url wins even if other sources are present', function () {
    $r = wpultra_media_gen_resolve_source(['url' => 'https://x.test/a.png', 'data_base64' => 'abc', 'prompt' => 'a cat'], true);
    assert_eq('url', $r['mode']);
});

it('resolve_source: base64 wins over prompt when no url', function () {
    $r = wpultra_media_gen_resolve_source(['data_base64' => 'abc', 'prompt' => 'a cat'], true);
    assert_eq('base64', $r['mode']);
});

it('resolve_source: prompt + key => api', function () {
    $r = wpultra_media_gen_resolve_source(['prompt' => 'a cat riding a bike'], true);
    assert_eq('api', $r['mode']);
});

it('resolve_source: prompt without key => error explaining both options', function () {
    $r = wpultra_media_gen_resolve_source(['prompt' => 'a cat riding a bike'], false);
    assert_eq('error', $r['mode']);
    assert_eq('no_api_key', $r['error']['code']);
    assert_contains('wpultra_openai_api_key', $r['error']['message']);
    assert_contains('url', $r['error']['message']);
});

it('resolve_source: nothing provided => error', function () {
    $r = wpultra_media_gen_resolve_source([], true);
    assert_eq('error', $r['mode']);
    assert_eq('missing_source', $r['error']['code']);
});

it('resolve_source: empty-string values are treated as absent', function () {
    $r = wpultra_media_gen_resolve_source(['url' => '', 'data_base64' => '', 'prompt' => ''], true);
    assert_eq('error', $r['mode']);
});

// ---- API request builder ----

it('build_api_request: endpoint, model, default size', function () {
    $req = wpultra_media_gen_build_api_request('a red fox', '', '');
    assert_eq('https://api.openai.com/v1/images/generations', $req['url']);
    assert_eq('gpt-image-1', $req['body']['model']);
    assert_eq('a red fox', $req['body']['prompt']);
    assert_eq('1024x1024', $req['body']['size']);
    assert_eq(1, $req['body']['n']);
});

it('build_api_request: custom model + valid non-default size pass through', function () {
    $req = wpultra_media_gen_build_api_request('a mountain', '1536x1024', 'gpt-image-1-mini');
    assert_eq('gpt-image-1-mini', $req['body']['model']);
    assert_eq('1536x1024', $req['body']['size']);
});

it('build_api_request: "auto" is a valid size', function () {
    $req = wpultra_media_gen_build_api_request('a mountain', 'auto', '');
    assert_eq('auto', $req['body']['size']);
});

it('build_api_request: rejects a junk size', function () {
    $req = wpultra_media_gen_build_api_request('a mountain', '999x999', '');
    assert_true(isset($req['error']), 'expected error key for invalid size');
    assert_eq('bad_size', $req['error']['code']);
});

it('build_api_request: rejects an empty prompt', function () {
    $req = wpultra_media_gen_build_api_request('   ', '1024x1024', '');
    assert_true(isset($req['error']), 'expected error key for empty prompt');
    assert_eq('missing_prompt', $req['error']['code']);
});

it('allowed sizes list matches the schema', function () {
    assert_eq(['1024x1024', '1536x1024', '1024x1536', 'auto'], wpultra_media_gen_allowed_sizes());
});

run_tests();
