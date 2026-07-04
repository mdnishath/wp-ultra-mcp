<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_gdpr/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/compliance/gdpr.php';

/* ============================================================
 * consent_cookie — accept-all / selective / necessary always on.
 * ============================================================ */

it('consent_cookie accept-all sets every listed category true', function () {
    $raw = wpultra_gdpr_consent_cookie(['necessary', 'analytics', 'marketing'], true);
    $m = json_decode($raw, true);
    assert_true(is_array($m), 'valid json');
    assert_eq(true, $m['necessary']);
    assert_eq(true, $m['analytics']);
    assert_eq(true, $m['marketing']);
});

it('consent_cookie decline (accept_all false, flat list) sets only necessary true', function () {
    $raw = wpultra_gdpr_consent_cookie(['necessary', 'analytics', 'marketing'], false);
    $m = json_decode($raw, true);
    assert_eq(true, $m['necessary']);
    assert_eq(false, $m['analytics']);
    assert_eq(false, $m['marketing']);
});

it('consent_cookie honors an explicit assoc choice map (selective consent)', function () {
    $raw = wpultra_gdpr_consent_cookie(['analytics' => true, 'marketing' => false], false);
    $m = json_decode($raw, true);
    assert_eq(true, $m['necessary'], 'necessary forced on');
    assert_eq(true, $m['analytics']);
    assert_eq(false, $m['marketing']);
});

it('consent_cookie forces necessary true even when the assoc map says false', function () {
    $raw = wpultra_gdpr_consent_cookie(['necessary' => false, 'analytics' => false], false);
    $m = json_decode($raw, true);
    assert_eq(true, $m['necessary']);
});

it('consent_cookie accept-all only sets categories that were listed', function () {
    // marketing not listed → stays false even under accept_all.
    $raw = wpultra_gdpr_consent_cookie(['necessary', 'analytics'], true);
    $m = json_decode($raw, true);
    assert_eq(true, $m['analytics']);
    assert_eq(false, $m['marketing']);
});

/* ============================================================
 * parse_consent — round-trip, forced necessary, garbage.
 * ============================================================ */

it('parse_consent round-trips a built cookie', function () {
    $raw = wpultra_gdpr_consent_cookie(['necessary', 'analytics', 'marketing'], true);
    $parsed = wpultra_gdpr_parse_consent($raw);
    assert_eq(true, $parsed['necessary']);
    assert_eq(true, $parsed['analytics']);
    assert_eq(true, $parsed['marketing']);
});

it('parse_consent forces necessary true even if the raw says false', function () {
    $parsed = wpultra_gdpr_parse_consent('{"necessary":false,"analytics":true,"marketing":false}');
    assert_eq(true, $parsed['necessary']);
    assert_eq(true, $parsed['analytics']);
    assert_eq(false, $parsed['marketing']);
});

it('parse_consent on garbage yields necessary-only', function () {
    foreach (['', 'not json', '[]', 'null', '12345', '{"foo":1}'] as $raw) {
        $parsed = wpultra_gdpr_parse_consent($raw);
        assert_eq(true, $parsed['necessary'], "necessary true for: $raw");
        assert_eq(false, $parsed['analytics'], "analytics false for: $raw");
        assert_eq(false, $parsed['marketing'], "marketing false for: $raw");
    }
});

it('parse_consent coerces truthy string/int forms', function () {
    $parsed = wpultra_gdpr_parse_consent('{"analytics":"true","marketing":1}');
    assert_eq(true, $parsed['analytics']);
    assert_eq(true, $parsed['marketing']);
    // A different truthy-but-not-recognised value stays false.
    $p2 = wpultra_gdpr_parse_consent('{"analytics":"yes"}');
    assert_eq(false, $p2['analytics']);
});

/* ============================================================
 * banner_html — escaping, buttons, position class.
 * ============================================================ */

it('banner_html escapes a hostile message, labels and policy url', function () {
    $cfg = wpultra_gdpr_default_config();
    $cfg['banner']['enabled'] = true;
    $cfg['banner']['message'] = '<script>alert(1)</script>';
    $cfg['banner']['accept_label'] = '"><img src=x onerror=alert(2)>';
    $cfg['banner']['decline_label'] = '</button><b>x</b>';
    $cfg['banner']['policy_url'] = 'javascript:alert(3)';
    $html = wpultra_gdpr_banner_html($cfg);

    // The raw <script>alert(1)</script> payload must not appear verbatim in the message.
    assert_true(!str_contains($html, '<script>alert(1)</script>'), 'message script not raw');
    assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    // The <img> tag in the accept label must be escaped (angle brackets gone),
    // so it can never become a live element even though the inert text survives.
    assert_true(!str_contains($html, '<img src=x'), 'accept label img tag not raw');
    assert_contains('&lt;img src=x', $html);
    // The decline label markup must be escaped.
    assert_true(!str_contains($html, '</button><b>x</b>'), 'decline label not raw');
    // javascript: URL is stripped — no href with javascript scheme.
    assert_true(!str_contains($html, 'javascript:alert(3)'), 'js url stripped');
});

it('banner_html contains accept + decline buttons and the message', function () {
    $cfg = wpultra_gdpr_default_config();
    $cfg['banner']['accept_label'] = 'Yes please';
    $cfg['banner']['decline_label'] = 'No thanks';
    $cfg['banner']['message'] = 'Cookies help us';
    $html = wpultra_gdpr_banner_html($cfg);
    assert_contains('wpultra-cc-accept', $html);
    assert_contains('wpultra-cc-decline', $html);
    assert_contains('Yes please', $html);
    assert_contains('No thanks', $html);
    assert_contains('Cookies help us', $html);
    assert_contains('window.wpultraConsent', $html);
});

it('banner_html applies the position class', function () {
    $bottom = wpultra_gdpr_default_config();
    $bottom['banner']['position'] = 'bottom';
    assert_contains('wpultra-cc--bottom', wpultra_gdpr_banner_html($bottom));

    $top = wpultra_gdpr_default_config();
    $top['banner']['position'] = 'top';
    assert_contains('wpultra-cc--top', wpultra_gdpr_banner_html($top));
});

it('banner_html renders a valid http policy link', function () {
    $cfg = wpultra_gdpr_default_config();
    $cfg['banner']['policy_url'] = 'https://example.com/privacy';
    $html = wpultra_gdpr_banner_html($cfg);
    assert_contains('https://example.com/privacy', $html);
    assert_contains('wpultra-cc-policy', $html);
});

it('banner_html rejects a CSS-injection theme color and falls back', function () {
    $cfg = wpultra_gdpr_default_config();
    $cfg['banner']['theme']['bg'] = 'red;}body{display:none}';
    $html = wpultra_gdpr_banner_html($cfg);
    assert_true(!str_contains($html, 'body{display:none}'), 'css injection rejected');
    assert_contains('#1e1e2e', $html); // fell back to the default bg
});

/* ============================================================
 * merge_config — validation, enums, necessary always present.
 * ============================================================ */

it('merge_config toggles enabled and validates position enum', function () {
    $cfg = wpultra_gdpr_merge_config([], ['enabled' => true, 'position' => 'top']);
    assert_eq(true, $cfg['banner']['enabled']);
    assert_eq('top', $cfg['banner']['position']);
    // Invalid position falls back to the previous value (default 'bottom').
    $cfg2 = wpultra_gdpr_merge_config([], ['position' => 'sideways']);
    assert_eq('bottom', $cfg2['banner']['position']);
});

it('merge_config always keeps necessary in categories and drops unknowns', function () {
    $cfg = wpultra_gdpr_merge_config([], ['categories' => ['analytics', 'bogus']]);
    assert_true(in_array('necessary', $cfg['banner']['categories'], true), 'necessary present');
    assert_true(in_array('analytics', $cfg['banner']['categories'], true), 'analytics kept');
    assert_true(!in_array('bogus', $cfg['banner']['categories'], true), 'bogus dropped');
});

it('merge_config clamps cookie_days and sanitizes cookie_name', function () {
    $cfg = wpultra_gdpr_merge_config([], [], ['cookie_days' => 99999, 'cookie_name' => 'my consent!!']);
    assert_eq(3650, $cfg['cookie_days']);
    assert_eq('myconsent', $cfg['cookie_name']);
    $cfg2 = wpultra_gdpr_merge_config([], [], ['cookie_days' => -5]);
    assert_eq(1, $cfg2['cookie_days']);
});

it('merge_config only accepts valid hex theme colors', function () {
    $cfg = wpultra_gdpr_merge_config([], ['theme' => ['accent' => '#0ea5e9', 'bg' => 'notacolor']]);
    assert_eq('#0ea5e9', $cfg['banner']['theme']['accent']);
    // invalid bg falls back to default.
    assert_eq('#1e1e2e', $cfg['banner']['theme']['bg']);
});

/* ============================================================
 * is_valid_email.
 * ============================================================ */

it('is_valid_email accepts real addresses and rejects junk', function () {
    assert_true(wpultra_gdpr_is_valid_email('jane@example.com'));
    assert_true(wpultra_gdpr_is_valid_email('a.b+c@sub.domain.co'));
    assert_true(!wpultra_gdpr_is_valid_email(''));
    assert_true(!wpultra_gdpr_is_valid_email('not-an-email'));
    assert_true(!wpultra_gdpr_is_valid_email('jane@'));
    assert_true(!wpultra_gdpr_is_valid_email('@example.com'));
});

/* ============================================================
 * privacy_checklist — each finding ok/warn.
 * ============================================================ */

it('privacy_checklist flags everything as warn when the context is empty', function () {
    $findings = wpultra_gdpr_privacy_checklist([]);
    $byCheck = [];
    foreach ($findings as $f) { $byCheck[$f['check']] = $f['status']; }
    assert_eq('warn', $byCheck['privacy_policy_page']);
    assert_eq('warn', $byCheck['ssl']);
    assert_eq('warn', $byCheck['consent_banner']);
    assert_eq('warn', $byCheck['data_exporters']);
    assert_eq('warn', $byCheck['data_erasers']);
    assert_eq('warn', $byCheck['comment_cookies_optin']);
});

it('privacy_checklist marks each check ok when the context is fully compliant', function () {
    $findings = wpultra_gdpr_privacy_checklist([
        'has_privacy_page'      => true,
        'ssl'                   => true,
        'banner_enabled'        => true,
        'exporters_count'       => 3,
        'erasers_count'         => 2,
        'comment_cookies_optin' => true,
    ]);
    foreach ($findings as $f) {
        assert_eq('ok', $f['status'], 'ok for check ' . $f['check']);
    }
    $summary = wpultra_gdpr_checklist_summary($findings);
    assert_eq(6, $summary['total']);
    assert_eq(6, $summary['ok']);
    assert_eq(0, $summary['warn']);
});

it('privacy_checklist zero exporters/erasers warn but nonzero are ok', function () {
    $zero = wpultra_gdpr_privacy_checklist(['exporters_count' => 0, 'erasers_count' => 0]);
    $byCheck = [];
    foreach ($zero as $f) { $byCheck[$f['check']] = $f['status']; }
    assert_eq('warn', $byCheck['data_exporters']);
    assert_eq('warn', $byCheck['data_erasers']);

    $some = wpultra_gdpr_privacy_checklist(['exporters_count' => 1, 'erasers_count' => 1]);
    $byCheck2 = [];
    foreach ($some as $f) { $byCheck2[$f['check']] = $f['status']; }
    assert_eq('ok', $byCheck2['data_exporters']);
    assert_eq('ok', $byCheck2['data_erasers']);
});

it('checklist_summary counts ok and warn correctly on a mixed context', function () {
    $findings = wpultra_gdpr_privacy_checklist([
        'has_privacy_page' => true,   // ok
        'ssl'              => true,   // ok
        'banner_enabled'   => false,  // warn
        'exporters_count'  => 0,      // warn
        'erasers_count'    => 0,      // warn
        // comment_cookies_optin missing → warn
    ]);
    $summary = wpultra_gdpr_checklist_summary($findings);
    assert_eq(6, $summary['total']);
    assert_eq(2, $summary['ok']);
    assert_eq(4, $summary['warn']);
});

run_tests();
