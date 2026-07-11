<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/fonts.php';

/* ------------------------------------------------------------------ *
 * wpultra_fonts_face_format
 * ------------------------------------------------------------------ */

it('face_format: woff2 -> woff2', function () {
    assert_eq('woff2', wpultra_fonts_face_format('woff2'));
});

it('face_format: woff -> woff', function () {
    assert_eq('woff', wpultra_fonts_face_format('woff'));
});

it('face_format: ttf -> truetype', function () {
    assert_eq('truetype', wpultra_fonts_face_format('ttf'));
});

it('face_format: otf -> opentype', function () {
    assert_eq('opentype', wpultra_fonts_face_format('otf'));
});

it('face_format: unknown extension -> null', function () {
    assert_eq(null, wpultra_fonts_face_format('php'));
});

it('face_format: is case-insensitive and tolerates a leading dot', function () {
    assert_eq('woff2', wpultra_fonts_face_format('.WOFF2'));
});

/* ------------------------------------------------------------------ *
 * wpultra_fonts_validate_ext
 * ------------------------------------------------------------------ */

it('validate_ext: accepts woff2/woff/ttf/otf', function () {
    foreach (['a.woff2', 'b.woff', 'c.ttf', 'd.otf'] as $f) {
        assert_true(wpultra_fonts_validate_ext($f) === true, "expected '$f' to validate");
    }
});

it('validate_ext: rejects a disguised .php upload', function () {
    assert_wp_error(wpultra_fonts_validate_ext('shell.php'));
});

it('validate_ext: rejects a disguised .exe upload', function () {
    assert_wp_error(wpultra_fonts_validate_ext('virus.exe'));
});

it('validate_ext: rejects a double-extension bypass (real ext must be last)', function () {
    assert_wp_error(wpultra_fonts_validate_ext('font.woff2.php'));
});

it('validate_ext: rejects a file with no extension', function () {
    assert_wp_error(wpultra_fonts_validate_ext('noextension'));
});

it('validate_ext: is case-insensitive on the extension', function () {
    assert_true(wpultra_fonts_validate_ext('FONT.WOFF2') === true);
});

/* ------------------------------------------------------------------ *
 * wpultra_fonts_fontface_css
 * ------------------------------------------------------------------ */

it('fontface_css: builds one block for a single-face font', function () {
    $css = wpultra_fonts_fontface_css([
        'family' => 'Acme Sans',
        'faces'  => [
            ['weight' => 400, 'style' => 'normal', 'url' => 'https://example.com/fonts/acme-400.woff2', 'format' => 'woff2'],
        ],
    ]);
    assert_true(str_contains($css, "font-family: 'Acme Sans';"), 'expected family declaration');
    assert_true(str_contains($css, "src: url('https://example.com/fonts/acme-400.woff2') format('woff2');"), 'expected src declaration');
    assert_true(str_contains($css, 'font-weight: 400;'), 'expected weight');
    assert_true(str_contains($css, 'font-style: normal;'), 'expected style');
    assert_true(str_contains($css, 'font-display: swap;'), 'expected font-display: swap');
    assert_eq(1, substr_count($css, '@font-face'));
});

it('fontface_css: emits one @font-face block per face for a multi-weight family', function () {
    $css = wpultra_fonts_fontface_css([
        'family' => 'Acme Sans',
        'faces'  => [
            ['weight' => 400, 'style' => 'normal', 'url' => 'https://example.com/a-400.woff2', 'format' => 'woff2'],
            ['weight' => 700, 'style' => 'normal', 'url' => 'https://example.com/a-700.woff2', 'format' => 'woff2'],
            ['weight' => 400, 'style' => 'italic', 'url' => 'https://example.com/a-400i.woff2', 'format' => 'woff2'],
        ],
    ]);
    assert_eq(3, substr_count($css, '@font-face'));
    assert_true(str_contains($css, 'font-weight: 700;'), 'expected the 700 weight block');
    assert_true(str_contains($css, 'font-style: italic;'), 'expected the italic style block');
});

it('fontface_css: defaults weight to 400 and style to normal when omitted', function () {
    $css = wpultra_fonts_fontface_css([
        'family' => 'Plain',
        'faces'  => [['url' => 'https://example.com/plain.woff2', 'format' => 'woff2']],
    ]);
    assert_true(str_contains($css, 'font-weight: 400;'));
    assert_true(str_contains($css, 'font-style: normal;'));
});

it('fontface_css: returns empty string when family is blank', function () {
    assert_eq('', wpultra_fonts_fontface_css(['family' => '', 'faces' => [['url' => 'https://x/y.woff2']]]));
});

it('fontface_css: returns empty string when there are no faces', function () {
    assert_eq('', wpultra_fonts_fontface_css(['family' => 'Acme', 'faces' => []]));
});

it('fontface_css: skips a face with no url', function () {
    $css = wpultra_fonts_fontface_css(['family' => 'Acme', 'faces' => [['weight' => 400, 'url' => '']]]);
    assert_eq('', $css);
});

it('fontface_css: strips angle brackets from family/url to prevent breaking out of the <style> tag', function () {
    $css = wpultra_fonts_fontface_css([
        'family' => "Evil</style><script>alert(1)</script>",
        'faces'  => [['url' => 'https://x/y.woff2</style>', 'format' => 'woff2']],
    ]);
    assert_true(!str_contains($css, '<'), 'expected no angle brackets in generated CSS');
    assert_true(!str_contains($css, '>'), 'expected no angle brackets in generated CSS');
});

it('fontface_css: a family ending in a backslash cannot break out of the quoted CSS string', function () {
    // If backslash isn't escaped FIRST (before the quote), a trailing "\" turns the
    // closing "\'" into an escaped literal quote, so the CSS string never terminates
    // and whatever follows becomes live CSS/rule content instead of an inert value.
    $css = wpultra_fonts_fontface_css([
        'family' => 'Evil\\',
        'faces'  => [['url' => 'https://example.com/f.woff2', 'format' => 'woff2']],
    ]);
    assert_true(str_contains($css, "font-family: 'Evil\\\\';"), 'expected the trailing backslash to be escaped so the quote closes properly');
    // The rest of the block must still be present as normal, inert declarations —
    // i.e. the family value did not swallow/corrupt the rest of the rule.
    assert_true(str_contains($css, 'font-display: swap;'), 'expected the rest of the @font-face block to render normally');
    assert_eq(1, substr_count($css, '@font-face'));
});

it('fontface_css: a url containing \';} cannot inject a new CSS rule', function () {
    $css = wpultra_fonts_fontface_css([
        'family' => 'Acme',
        'faces'  => [['url' => "https://x/y.woff2';} body{background:url(x)", 'format' => 'woff2']],
    ]);
    // The injected sequence must come back escaped (quote backslash-escaped), never as
    // a literal closing-quote-plus-brace that would terminate the src: value early.
    assert_true(!str_contains($css, "woff2';}"), 'expected the quote to be escaped, not left able to close the CSS string early');
    assert_eq(1, substr_count($css, '@font-face'), 'expected the malicious url to stay inside a single @font-face block');
});

/* ------------------------------------------------------------------ *
 * wpultra_fonts_parse_google_css
 * ------------------------------------------------------------------ */

$GLOBALS['__google_css_fixture'] = <<<CSS
/* latin */
@font-face {
  font-family: 'Example Sans';
  font-style: normal;
  font-weight: 300;
  font-display: swap;
  src: local('Example Sans Light'), url(https://fonts.gstatic.com/s/examplesans/v1/light.woff2) format('woff2');
}
/* latin */
@font-face {
  font-family: 'Example Sans';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url(https://fonts.gstatic.com/s/examplesans/v1/regular.woff2) format('woff2');
}
/* latin */
@font-face {
  font-family: 'Example Sans';
  font-style: italic;
  font-weight: 700;
  font-display: swap;
  src: url(https://fonts.gstatic.com/s/examplesans/v1/bold-italic.woff2) format('woff2');
}
CSS;

it('parse_google_css: extracts the shared family name', function () {
    $parsed = wpultra_fonts_parse_google_css($GLOBALS['__google_css_fixture']);
    assert_eq('Example Sans', $parsed['family']);
});

it('parse_google_css: extracts one face per @font-face block', function () {
    $parsed = wpultra_fonts_parse_google_css($GLOBALS['__google_css_fixture']);
    assert_eq(3, count($parsed['faces']));
});

it('parse_google_css: captures weight/style/url/format per face', function () {
    $parsed = wpultra_fonts_parse_google_css($GLOBALS['__google_css_fixture']);
    $bold_italic = null;
    foreach ($parsed['faces'] as $face) {
        if ($face['weight'] === 700) { $bold_italic = $face; }
    }
    assert_true($bold_italic !== null, 'expected to find the 700-weight face');
    assert_eq('italic', $bold_italic['style']);
    assert_eq('https://fonts.gstatic.com/s/examplesans/v1/bold-italic.woff2', $bold_italic['url']);
    assert_eq('woff2', $bold_italic['format']);
});

it('parse_google_css: prefers the real font url over a local() fallback', function () {
    $parsed = wpultra_fonts_parse_google_css($GLOBALS['__google_css_fixture']);
    $light = $parsed['faces'][0];
    assert_eq(300, $light['weight']);
    assert_eq('https://fonts.gstatic.com/s/examplesans/v1/light.woff2', $light['url']);
});

it('parse_google_css: returns an empty faces list for garbage input', function () {
    $parsed = wpultra_fonts_parse_google_css('not any css at all');
    assert_eq('', $parsed['family']);
    assert_eq([], $parsed['faces']);
});

it('parse_google_css: returns an empty faces list for an empty string', function () {
    $parsed = wpultra_fonts_parse_google_css('');
    assert_eq([], $parsed['faces']);
});

it('parse_google_css: infers format from the url extension when format() is absent', function () {
    $css = "@font-face { font-family: 'NoFormat'; font-weight: 400; font-style: normal; src: url(https://fonts.gstatic.com/s/nf/v1/regular.ttf); }";
    $parsed = wpultra_fonts_parse_google_css($css);
    assert_eq(1, count($parsed['faces']));
    assert_eq('truetype', $parsed['faces'][0]['format']);
});

/* ------------------------------------------------------------------ *
 * wpultra_fonts_google_css_url (pure API-URL builder)
 * ------------------------------------------------------------------ */

it('google_css_url: builds a css2 API url with encoded family + sorted weights', function () {
    $url = wpultra_fonts_google_css_url('Example Sans', [700, 400]);
    assert_true(str_starts_with($url, 'https://fonts.googleapis.com/css2?family=Example+Sans'), 'expected encoded family');
    assert_true(str_contains($url, 'wght@400;700'), 'expected sorted weights');
    assert_true(str_contains($url, '&display=swap'), 'expected display=swap');
});

it('google_css_url: defaults to weight 400 when none supplied', function () {
    $url = wpultra_fonts_google_css_url('Solo', []);
    assert_true(str_contains($url, 'wght@400'));
});

run_tests();
