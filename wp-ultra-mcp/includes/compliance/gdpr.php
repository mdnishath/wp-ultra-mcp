<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * GDPR / cookie-consent + privacy tools engine (Roadmap G1).
 *
 * Three families:
 *
 *  CONSENT BANNER — a namespaced, dismissible front-end cookie banner rendered
 *  from a single option (wpultra_gdpr). Accept/Decline writes a cookie
 *  (default 'wpultra_consent') holding a category->bool map; 'necessary' is
 *  always true. A JS helper window.wpultraConsent(cat) lets other scripts gate
 *  themselves. The renderer is wired to wp_footer by wpultra_gdpr_boot() when
 *  the banner is enabled (one cheap option read on every request).
 *
 *  DATA EXPORT / ERASE — thin ORCHESTRATION over WordPress core privacy tools.
 *  We do NOT reimplement WP's exporter/eraser registry: export runs every
 *  callback registered on the 'wp_privacy_personal_data_exporters' filter and
 *  aggregates their data; erase runs 'wp_privacy_personal_data_erasers'. We can
 *  also mint the official async request post via wp_create_user_request().
 *
 *  PRIVACY AUDIT — a pure checklist evaluating a context array (privacy page,
 *  ssl, banner, exporter/eraser counts, comment-cookie opt-in) into findings.
 *
 * The PURE functions (prefix wpultra_gdpr_, no WordPress calls) are the testable
 * core: consent_cookie / parse_consent / banner_html / privacy_checklist /
 * default_config / merge_banner_config / is_valid_email.
 */

/* =====================================================================
 * PURE — config defaults + validation.
 * ===================================================================== */

/** PURE. The category ids the banner understands. 'necessary' is always-on. */
function wpultra_gdpr_categories(): array {
    return ['necessary', 'analytics', 'marketing'];
}

/** PURE. Allowed banner positions. */
function wpultra_gdpr_positions(): array {
    return ['bottom', 'top'];
}

/** PURE. The default wpultra_gdpr option shape. */
function wpultra_gdpr_default_config(): array {
    return [
        'banner' => [
            'enabled'       => false,
            'position'      => 'bottom',
            'message'       => 'We use cookies to improve your experience. Choose which categories you allow.',
            'accept_label'  => 'Accept all',
            'decline_label' => 'Decline',
            'policy_url'    => '',
            'categories'    => ['necessary', 'analytics', 'marketing'],
            'theme'         => ['bg' => '#1e1e2e', 'text' => '#ffffff', 'accent' => '#4f46e5'],
        ],
        'cookie_name' => 'wpultra_consent',
        'cookie_days' => 180,
    ];
}

/** PURE. Simple RFC-ish email validation (no WP calls). */
function wpultra_gdpr_is_valid_email(string $email): bool {
    $email = trim($email);
    if ($email === '' || strlen($email) > 254) { return false; }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * PURE. Merge a caller-supplied banner patch onto an existing config, validating
 * and clamping each field. Unknown keys are ignored; enums fall back to the
 * previous (or default) value. Returns the full merged config array.
 *
 * @param array $current the current full config (or [] to start from defaults)
 * @param array $patch   caller banner fields, e.g. {enabled, position, message,
 *                        accept_label, decline_label, policy_url, categories, theme}
 * @param array $top     top-level patch: {cookie_name, cookie_days}
 */
function wpultra_gdpr_merge_config(array $current, array $patch, array $top = []): array {
    $defaults = wpultra_gdpr_default_config();
    $cfg = $current === [] ? $defaults : array_replace_recursive($defaults, $current);
    $b = is_array($cfg['banner'] ?? null) ? $cfg['banner'] : $defaults['banner'];

    if (array_key_exists('enabled', $patch)) {
        $b['enabled'] = (bool) $patch['enabled'];
    }
    if (isset($patch['position'])) {
        $pos = (string) $patch['position'];
        $b['position'] = in_array($pos, wpultra_gdpr_positions(), true) ? $pos : $b['position'];
    }
    foreach (['message' => 'message', 'accept_label' => 'accept_label', 'decline_label' => 'decline_label'] as $k => $dst) {
        if (isset($patch[$k])) {
            $v = trim((string) $patch[$k]);
            if ($v !== '') { $b[$dst] = $v; }
        }
    }
    if (array_key_exists('policy_url', $patch)) {
        $b['policy_url'] = trim((string) $patch['policy_url']);
    }
    if (isset($patch['categories']) && is_array($patch['categories'])) {
        $cats = [];
        foreach ($patch['categories'] as $c) {
            $c = (string) $c;
            if (in_array($c, wpultra_gdpr_categories(), true) && !in_array($c, $cats, true)) { $cats[] = $c; }
        }
        // 'necessary' is mandatory and always first.
        $cats = array_values(array_unique(array_merge(['necessary'], $cats)));
        $b['categories'] = $cats;
    }
    if (isset($patch['theme']) && is_array($patch['theme'])) {
        $theme = is_array($b['theme'] ?? null) ? $b['theme'] : $defaults['banner']['theme'];
        foreach (['bg', 'text', 'accent'] as $tk) {
            if (isset($patch['theme'][$tk])) {
                $col = trim((string) $patch['theme'][$tk]);
                if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $col)) { $theme[$tk] = $col; }
            }
        }
        $b['theme'] = $theme;
    }
    $cfg['banner'] = $b;

    if (isset($top['cookie_name'])) {
        $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $top['cookie_name']);
        if ($name !== '') { $cfg['cookie_name'] = $name; }
    }
    if (isset($top['cookie_days'])) {
        $days = (int) $top['cookie_days'];
        $cfg['cookie_name'] = $cfg['cookie_name'] ?? $defaults['cookie_name'];
        $cfg['cookie_days'] = max(1, min(3650, $days));
    }
    if (!isset($cfg['cookie_name']) || $cfg['cookie_name'] === '') { $cfg['cookie_name'] = $defaults['cookie_name']; }
    if (!isset($cfg['cookie_days'])) { $cfg['cookie_days'] = $defaults['cookie_days']; }

    return $cfg;
}

/* =====================================================================
 * PURE — consent cookie build / parse.
 * ===================================================================== */

/**
 * PURE. Build the consent-cookie string value: a JSON category->bool map.
 * 'necessary' is always true. When $accept_all every listed category is true;
 * otherwise only 'necessary' is true (a Decline) unless the caller pre-set
 * choices via the $categories map form (see below).
 *
 * $categories may be either:
 *   - a flat list of category ids (e.g. ['necessary','analytics']) — then
 *     $accept_all decides: true => all true, false => only necessary true; OR
 *   - an assoc map id=>bool of explicit per-category choices (selective consent).
 *
 * @param array $categories list of ids OR id=>bool map
 * @param bool  $accept_all when true (and $categories is a flat list) → all true
 */
function wpultra_gdpr_consent_cookie(array $categories, bool $accept_all): string {
    $known = wpultra_gdpr_categories();
    $map = [];

    // Detect the assoc (explicit-choice) form: any string key.
    $is_assoc = false;
    foreach (array_keys($categories) as $k) { if (is_string($k)) { $is_assoc = true; break; } }

    if ($is_assoc) {
        foreach ($known as $c) {
            $map[$c] = array_key_exists($c, $categories) ? (bool) $categories[$c] : false;
        }
    } else {
        $list = array_map('strval', $categories);
        foreach ($known as $c) {
            $listed = in_array($c, $list, true);
            $map[$c] = $accept_all ? $listed : false;
        }
    }
    // necessary is non-negotiable.
    $map['necessary'] = true;

    return (string) wp_json_encode($map);
}

/**
 * PURE. Parse a raw consent-cookie value back into a category->bool map.
 * Garbage / malformed input yields necessary-only. 'necessary' is forced true.
 *
 * @return array<string,bool>
 */
function wpultra_gdpr_parse_consent(string $raw): array {
    $known = wpultra_gdpr_categories();
    $out = [];
    foreach ($known as $c) { $out[$c] = false; }
    $out['necessary'] = true;

    $raw = trim($raw);
    if ($raw === '') { return $out; }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) { return $out; }
    foreach ($known as $c) {
        if (array_key_exists($c, $decoded)) {
            $v = $decoded[$c];
            $out[$c] = ($v === true || $v === 1 || $v === '1' || $v === 'true');
        }
    }
    $out['necessary'] = true;
    return $out;
}

/* =====================================================================
 * PURE — escape helpers (fall back to htmlspecialchars in tests).
 * ===================================================================== */

/** PURE. HTML-escape (WP esc_html when present, else htmlspecialchars). */
function wpultra_gdpr_esc_html(string $s): string {
    if (function_exists('esc_html')) { return (string) esc_html($s); }
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** PURE. Attribute-escape. */
function wpultra_gdpr_esc_attr(string $s): string {
    if (function_exists('esc_attr')) { return (string) esc_attr($s); }
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** PURE. URL-escape: allow http/https/relative, drop anything else (no javascript:). */
function wpultra_gdpr_esc_url(string $url): string {
    $url = trim($url);
    if ($url === '') { return ''; }
    if (function_exists('esc_url')) { return (string) esc_url($url); }
    // Fallback: reject dangerous schemes, then attribute-escape.
    if (preg_match('#^\s*(javascript|data|vbscript)\s*:#i', $url)) { return ''; }
    if (!preg_match('#^(https?://|/|\#)#i', $url)) { return ''; }
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

/* =====================================================================
 * PURE — banner HTML (fully escaped, self-contained).
 * ===================================================================== */

/**
 * PURE. Render the consent-banner markup for a given full config. Everything is
 * escaped: hostile message/labels/url cannot break out. Namespaced .wpultra-cc-*
 * classes, inline CSS + one vanilla-JS block; category config passed to JS via
 * wp_json_encode. The JS reads/writes the cookie and exposes window.wpultraConsent.
 *
 * @param array $cfg full config (see wpultra_gdpr_default_config)
 */
function wpultra_gdpr_banner_html(array $cfg): string {
    $defaults = wpultra_gdpr_default_config();
    $b = is_array($cfg['banner'] ?? null) ? array_replace($defaults['banner'], $cfg['banner']) : $defaults['banner'];
    $theme = is_array($b['theme'] ?? null) ? array_replace($defaults['banner']['theme'], $b['theme']) : $defaults['banner']['theme'];

    $position = in_array((string) $b['position'], wpultra_gdpr_positions(), true) ? (string) $b['position'] : 'bottom';
    $pos_class = 'wpultra-cc--' . $position;

    $message  = wpultra_gdpr_esc_html((string) $b['message']);
    $accept   = wpultra_gdpr_esc_html((string) $b['accept_label']);
    $decline  = wpultra_gdpr_esc_html((string) $b['decline_label']);
    $policy   = wpultra_gdpr_esc_url((string) ($b['policy_url'] ?? ''));

    // Theme colors are validated on save, but re-validate defensively for CSS injection.
    $bg     = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $theme['bg']) ? (string) $theme['bg'] : '#1e1e2e';
    $text   = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $theme['text']) ? (string) $theme['text'] : '#ffffff';
    $accent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $theme['accent']) ? (string) $theme['accent'] : '#4f46e5';

    $cats = is_array($b['categories'] ?? null) ? array_values(array_filter($b['categories'], static fn($c) => in_array((string) $c, wpultra_gdpr_categories(), true))) : ['necessary'];
    if (!in_array('necessary', $cats, true)) { array_unshift($cats, 'necessary'); }

    $cookie_name = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($cfg['cookie_name'] ?? 'wpultra_consent'));
    if ($cookie_name === '') { $cookie_name = 'wpultra_consent'; }
    $cookie_days = max(1, (int) ($cfg['cookie_days'] ?? 180));

    $js_config = (string) wp_json_encode([
        'cookie'     => $cookie_name,
        'days'       => $cookie_days,
        'categories' => array_values(array_unique($cats)),
    ]);
    // Guard against </script> injection inside the inline JSON.
    $js_config = str_replace(['<', '>', '&'], ['<', '>', '&'], $js_config);

    // Per-category toggle rows (necessary is disabled+checked).
    $toggles = '';
    foreach ($cats as $c) {
        $c = (string) $c;
        $label = wpultra_gdpr_esc_html(ucfirst($c));
        $disabled = $c === 'necessary' ? ' disabled checked' : '';
        $cattr = wpultra_gdpr_esc_attr($c);
        $toggles .= '<label class="wpultra-cc-cat"><input type="checkbox" class="wpultra-cc-toggle" value="' . $cattr . '"' . $disabled . '> ' . $label . '</label>';
    }

    $policy_link = $policy !== ''
        ? ' <a class="wpultra-cc-policy" href="' . $policy . '" target="_blank" rel="noopener noreferrer">Privacy policy</a>'
        : '';

    $css = '.wpultra-cc{position:fixed;left:0;right:0;z-index:99999;background:' . $bg . ';color:' . $text
        . ';padding:16px 20px;font:14px/1.5 system-ui,-apple-system,sans-serif;box-shadow:0 -2px 12px rgba(0,0,0,.25);display:none}'
        . '.wpultra-cc--bottom{bottom:0}.wpultra-cc--top{top:0}'
        . '.wpultra-cc.is-visible{display:block}'
        . '.wpultra-cc-inner{max-width:1100px;margin:0 auto;display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between}'
        . '.wpultra-cc-msg{flex:1 1 320px;margin:0}'
        . '.wpultra-cc-policy{color:' . $accent . ';text-decoration:underline}'
        . '.wpultra-cc-cats{display:flex;flex-wrap:wrap;gap:12px;flex:1 1 100%}'
        . '.wpultra-cc-cat{display:inline-flex;align-items:center;gap:4px;opacity:.9}'
        . '.wpultra-cc-actions{display:flex;gap:8px;flex:0 0 auto}'
        . '.wpultra-cc-btn{cursor:pointer;border:0;border-radius:6px;padding:8px 16px;font-weight:600}'
        . '.wpultra-cc-accept{background:' . $accent . ';color:#fff}'
        . '.wpultra-cc-decline{background:transparent;color:' . $text . ';border:1px solid ' . $text . '}';

    $html  = '<style id="wpultra-cc-style">' . $css . '</style>';
    $html .= '<div id="wpultra-cc" class="wpultra-cc ' . $pos_class . '" role="dialog" aria-live="polite" aria-label="Cookie consent">';
    $html .= '<div class="wpultra-cc-inner">';
    $html .= '<p class="wpultra-cc-msg">' . $message . $policy_link . '</p>';
    $html .= '<div class="wpultra-cc-cats">' . $toggles . '</div>';
    $html .= '<div class="wpultra-cc-actions">';
    $html .= '<button type="button" class="wpultra-cc-btn wpultra-cc-decline" id="wpultra-cc-decline">' . $decline . '</button>';
    $html .= '<button type="button" class="wpultra-cc-btn wpultra-cc-accept" id="wpultra-cc-accept">' . $accept . '</button>';
    $html .= '</div></div></div>';

    $js = '(function(){'
        . 'var C=' . $js_config . ';'
        . 'function read(n){var m=document.cookie.match(new RegExp("(?:^|; )"+n.replace(/([.$?*|{}()\\[\\]\\\\\\/\\+^])/g,"\\\\$1")+"=([^;]*)"));return m?decodeURIComponent(m[1]):"";}'
        . 'function write(n,v,d){var e=new Date(Date.now()+d*864e5).toUTCString();document.cookie=n+"="+encodeURIComponent(v)+";expires="+e+";path=/;SameSite=Lax";}'
        . 'function parse(raw){var o={necessary:true,analytics:false,marketing:false};try{var p=JSON.parse(raw);for(var k in o){if(p&&typeof p[k]!=="undefined")o[k]=!!p[k];}}catch(e){}o.necessary=true;return o;}'
        . 'window.wpultraConsent=function(cat){var o=parse(read(C.cookie));return cat?!!o[cat]:o;};'
        . 'var el=document.getElementById("wpultra-cc");if(!el)return;'
        . 'var existing=read(C.cookie);'
        . 'var state=parse(existing);'
        . 'var toggles=el.querySelectorAll(".wpultra-cc-toggle");'
        . 'if(existing){for(var i=0;i<toggles.length;i++){var t=toggles[i];if(t.value!=="necessary")t.checked=!!state[t.value];}}'
        . 'else{el.classList.add("is-visible");}'
        . 'function save(all){var m={};for(var i=0;i<C.categories.length;i++){var c=C.categories[i];m[c]=(c==="necessary")?true:(all?true:false);}'
        . 'if(!all){for(var j=0;j<toggles.length;j++){var t=toggles[j];m[t.value]=(t.value==="necessary")?true:!!t.checked;}}'
        . 'm.necessary=true;write(C.cookie,JSON.stringify(m),C.days);el.classList.remove("is-visible");'
        . 'try{document.dispatchEvent(new CustomEvent("wpultra-consent",{detail:m}));}catch(e){}}'
        . 'var a=document.getElementById("wpultra-cc-accept");if(a)a.addEventListener("click",function(){save(true);});'
        . 'var d=document.getElementById("wpultra-cc-decline");if(d)d.addEventListener("click",function(){save(false);});'
        . '})();';

    $html .= '<script id="wpultra-cc-js">' . $js . '</script>';
    return $html;
}

/* =====================================================================
 * PURE — privacy audit checklist.
 * ===================================================================== */

/**
 * PURE. Evaluate a privacy context into a list of findings. Each finding:
 *   {check, status: 'ok'|'warn', detail}.
 *
 * $ctx keys (all optional; missing → treated as the failing value):
 *   has_privacy_page   bool — a page assigned as the privacy policy page
 *   ssl                bool — site served over https
 *   banner_enabled     bool — the consent banner is on
 *   exporters_count    int  — registered personal-data exporters
 *   erasers_count      int  — registered personal-data erasers
 *   comment_cookies_optin bool — the comment-form cookie opt-in checkbox shows
 *
 * @return array<int,array{check:string,status:string,detail:string}>
 */
function wpultra_gdpr_privacy_checklist(array $ctx): array {
    $findings = [];

    $has_page = !empty($ctx['has_privacy_page']);
    $findings[] = [
        'check'  => 'privacy_policy_page',
        'status' => $has_page ? 'ok' : 'warn',
        'detail' => $has_page
            ? 'A privacy policy page is assigned.'
            : 'No privacy policy page is assigned (Settings > Privacy). GDPR requires a published privacy policy.',
    ];

    $ssl = !empty($ctx['ssl']);
    $findings[] = [
        'check'  => 'ssl',
        'status' => $ssl ? 'ok' : 'warn',
        'detail' => $ssl
            ? 'The site is served over HTTPS.'
            : 'The site is not served over HTTPS; personal data submitted through forms is transmitted in the clear.',
    ];

    $banner = !empty($ctx['banner_enabled']);
    $findings[] = [
        'check'  => 'consent_banner',
        'status' => $banner ? 'ok' : 'warn',
        'detail' => $banner
            ? 'The cookie-consent banner is enabled.'
            : 'The cookie-consent banner is disabled; visitors are not asked to consent to non-essential cookies.',
    ];

    $exporters = (int) ($ctx['exporters_count'] ?? 0);
    $findings[] = [
        'check'  => 'data_exporters',
        'status' => $exporters > 0 ? 'ok' : 'warn',
        'detail' => $exporters > 0
            ? "$exporters personal-data exporter(s) registered (data-access requests can be fulfilled)."
            : 'No personal-data exporters are registered; data-access (portability) requests cannot be auto-fulfilled.',
    ];

    $erasers = (int) ($ctx['erasers_count'] ?? 0);
    $findings[] = [
        'check'  => 'data_erasers',
        'status' => $erasers > 0 ? 'ok' : 'warn',
        'detail' => $erasers > 0
            ? "$erasers personal-data eraser(s) registered (right-to-erasure requests can be fulfilled)."
            : 'No personal-data erasers are registered; right-to-erasure requests cannot be auto-fulfilled.',
    ];

    $optin = !empty($ctx['comment_cookies_optin']);
    $findings[] = [
        'check'  => 'comment_cookies_optin',
        'status' => $optin ? 'ok' : 'warn',
        'detail' => $optin
            ? 'The comment form shows a cookie opt-in checkbox.'
            : 'The comment-form cookie opt-in checkbox is not shown; commenter cookies are set without consent.',
    ];

    return $findings;
}

/** PURE. Summarize a checklist: {total, ok, warn}. */
function wpultra_gdpr_checklist_summary(array $findings): array {
    $ok = 0; $warn = 0;
    foreach ($findings as $f) {
        $s = is_array($f) ? (string) ($f['status'] ?? '') : '';
        if ($s === 'ok') { $ok++; } elseif ($s === 'warn') { $warn++; }
    }
    return ['total' => count($findings), 'ok' => $ok, 'warn' => $warn];
}

/* =====================================================================
 * WordPress-touching wrappers — config option read/write.
 * ===================================================================== */

const WPULTRA_GDPR_OPTION = 'wpultra_gdpr';

/** Read the persisted GDPR config, merged over defaults. WP-touching. */
function wpultra_gdpr_get_config(): array {
    $raw = function_exists('get_option') ? get_option(WPULTRA_GDPR_OPTION, []) : [];
    $raw = is_array($raw) ? $raw : [];
    return wpultra_gdpr_merge_config($raw, [], []);
}

/** Persist the GDPR config. WP-touching. */
function wpultra_gdpr_save_config(array $cfg): void {
    if (function_exists('update_option')) { update_option(WPULTRA_GDPR_OPTION, $cfg, true); }
}

/* =====================================================================
 * WordPress-touching wrappers — data export / erase (orchestrate WP core).
 * ===================================================================== */

/**
 * Run every registered personal-data exporter for an email and aggregate the
 * exported data groups. Read-only. Orchestrates WP core — does not reimplement
 * the registry.
 *
 * @return array|WP_Error {email, groups:[...], group_count, item_count}
 */
function wpultra_gdpr_export_personal_data(string $email) {
    if (!wpultra_gdpr_is_valid_email($email)) {
        return wpultra_err('invalid_email', "Not a valid email address: $email");
    }
    if (!function_exists('apply_filters')) {
        return wpultra_err('wp_unavailable', 'WordPress is not loaded; cannot run exporters.');
    }
    $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
    if (!is_array($exporters)) { $exporters = []; }

    $groups = [];
    $item_count = 0;
    foreach ($exporters as $slug => $exporter) {
        if (!is_array($exporter) || empty($exporter['callback']) || !is_callable($exporter['callback'])) { continue; }
        $page = 1;
        $group_label = (string) ($exporter['exporter_friendly_name'] ?? (is_string($slug) ? $slug : 'export'));
        $collected = [];
        // Walk pages until done or a sane cap (avoid a runaway exporter).
        do {
            $response = call_user_func($exporter['callback'], $email, $page);
            if (!is_array($response)) { break; }
            $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
            foreach ($data as $item) {
                $collected[] = $item;
                $item_count++;
            }
            $done = !isset($response['done']) || $response['done'] === true;
            $page++;
        } while (!$done && $page <= 50);

        if ($collected !== []) {
            $groups[] = [
                'exporter'    => is_string($slug) ? $slug : (string) $group_label,
                'group_label' => $group_label,
                'items'       => $collected,
            ];
        }
    }

    return [
        'email'       => $email,
        'groups'      => $groups,
        'group_count' => count($groups),
        'item_count'  => $item_count,
    ];
}

/**
 * Run every registered personal-data eraser for an email and aggregate the
 * results. IRREVERSIBLE — the caller must confirm. Orchestrates WP core.
 *
 * @return array|WP_Error {email, items_removed, items_retained, messages[], eraser_count}
 */
function wpultra_gdpr_erase_personal_data(string $email, bool $confirm) {
    if (!wpultra_gdpr_is_valid_email($email)) {
        return wpultra_err('invalid_email', "Not a valid email address: $email");
    }
    if (!$confirm) {
        return wpultra_err('unconfirmed', 'Erasing personal data is irreversible. Re-run with confirm: true.');
    }
    if (!function_exists('apply_filters')) {
        return wpultra_err('wp_unavailable', 'WordPress is not loaded; cannot run erasers.');
    }
    $erasers = apply_filters('wp_privacy_personal_data_erasers', []);
    if (!is_array($erasers)) { $erasers = []; }

    $items_removed = false;
    $items_retained = false;
    $messages = [];
    $eraser_count = 0;
    foreach ($erasers as $slug => $eraser) {
        if (!is_array($eraser) || empty($eraser['callback']) || !is_callable($eraser['callback'])) { continue; }
        $eraser_count++;
        $page = 1;
        do {
            $response = call_user_func($eraser['callback'], $email, $page);
            if (!is_array($response)) { break; }
            if (!empty($response['items_removed'])) { $items_removed = true; }
            if (!empty($response['items_retained'])) { $items_retained = true; }
            if (isset($response['messages']) && is_array($response['messages'])) {
                foreach ($response['messages'] as $m) { $messages[] = (string) $m; }
            }
            $done = !isset($response['done']) || $response['done'] === true;
            $page++;
        } while (!$done && $page <= 50);
    }

    return [
        'email'          => $email,
        'items_removed'  => $items_removed,
        'items_retained' => $items_retained,
        'messages'       => array_values(array_unique($messages)),
        'eraser_count'   => $eraser_count,
    ];
}

/**
 * Create the official WP async privacy request (an export or erase request post
 * of type user_request), which drives WP's confirm-email + admin-approval flow.
 *
 * @param string $email
 * @param string $type 'export'|'erase'
 * @return array|WP_Error {request_id, email, action}
 */
function wpultra_gdpr_create_request(string $email, string $type) {
    if (!wpultra_gdpr_is_valid_email($email)) {
        return wpultra_err('invalid_email', "Not a valid email address: $email");
    }
    $action = $type === 'erase' ? 'remove_personal_data' : 'export_personal_data';
    if (!function_exists('wp_create_user_request')) {
        return wpultra_err('wp_unavailable', 'wp_create_user_request() is unavailable (WordPress < 4.9.6 or not loaded).');
    }
    $request_id = wp_create_user_request($email, $action);
    if (is_wp_error($request_id)) { return $request_id; }
    // Best-effort: send the confirmation email so the flow can proceed.
    if (function_exists('wp_send_user_request')) { @wp_send_user_request($request_id); }
    return [
        'request_id' => (int) $request_id,
        'email'      => $email,
        'action'     => $action,
    ];
}

/**
 * List recent privacy-request posts (post type user_request). Read-only.
 *
 * @param int $limit
 * @return array<int,array>
 */
function wpultra_gdpr_list_requests(int $limit = 20): array {
    if (!function_exists('get_posts')) { return []; }
    $limit = max(1, min(200, $limit));
    $posts = get_posts([
        'post_type'      => 'user_request',
        'post_status'    => 'any',
        'posts_per_page' => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);
    $out = [];
    foreach ((array) $posts as $p) {
        if (!is_object($p)) { continue; }
        $out[] = [
            'id'      => (int) ($p->ID ?? 0),
            'email'   => (string) ($p->post_title ?? ''),
            'action'  => (string) ($p->post_name ?? ''),   // export_personal_data | remove_personal_data
            'status'  => (string) ($p->post_status ?? ''), // request-pending|request-confirmed|request-completed|request-failed
            'created' => (string) ($p->post_date_gmt ?? ''),
        ];
    }
    return $out;
}

/**
 * Count registered exporters/erasers (for the audit). WP-touching.
 * @return array{exporters:int, erasers:int}
 */
function wpultra_gdpr_privacy_tool_counts(): array {
    $ex = function_exists('apply_filters') ? apply_filters('wp_privacy_personal_data_exporters', []) : [];
    $er = function_exists('apply_filters') ? apply_filters('wp_privacy_personal_data_erasers', []) : [];
    return [
        'exporters' => is_array($ex) ? count($ex) : 0,
        'erasers'   => is_array($er) ? count($er) : 0,
    ];
}

/**
 * Build the privacy-audit context from live WordPress state and evaluate the
 * pure checklist. WP-touching wrapper.
 *
 * @return array{findings:array, summary:array}
 */
function wpultra_gdpr_privacy_audit(): array {
    $cfg = wpultra_gdpr_get_config();
    $counts = wpultra_gdpr_privacy_tool_counts();

    $has_page = false;
    if (function_exists('get_option')) {
        $has_page = (int) get_option('wp_page_for_privacy_policy', 0) > 0;
    }
    $ssl = function_exists('is_ssl') ? (bool) is_ssl() : false;
    // WP shows the comment cookie opt-in checkbox when show_comments_cookies_opt_in is on.
    $optin = function_exists('get_option') ? ((string) get_option('show_comments_cookies_opt_in', '') === '1') : false;

    $ctx = [
        'has_privacy_page'      => $has_page,
        'ssl'                   => $ssl,
        'banner_enabled'        => !empty($cfg['banner']['enabled']),
        'exporters_count'       => $counts['exporters'],
        'erasers_count'         => $counts['erasers'],
        'comment_cookies_optin' => $optin,
    ];
    $findings = wpultra_gdpr_privacy_checklist($ctx);
    return [
        'findings' => $findings,
        'summary'  => wpultra_gdpr_checklist_summary($findings),
        'context'  => $ctx,
    ];
}

/* =====================================================================
 * Always-on runtime — controller calls wpultra_gdpr_boot() on plugins_loaded.
 * ===================================================================== */

/**
 * Boot: when the banner is enabled, hook the footer renderer. One cheap option
 * read per request. The controller wires this on plugins_loaded — this file
 * only defines it (it does NOT hook plugins_loaded itself).
 */
function wpultra_gdpr_boot(): void {
    if (!function_exists('get_option')) { return; }
    $cfg = wpultra_gdpr_get_config();
    if (empty($cfg['banner']['enabled'])) { return; }
    if (!function_exists('add_action')) { return; }
    add_action('wp_footer', 'wpultra_gdpr_render_banner', 20);
}

/** Runtime: echo the consent banner in the footer. WP-touching. */
function wpultra_gdpr_render_banner(): void {
    // Do not show inside the admin.
    if (function_exists('is_admin') && is_admin()) { return; }
    $cfg = wpultra_gdpr_get_config();
    if (empty($cfg['banner']['enabled'])) { return; }
    echo wpultra_gdpr_banner_html($cfg);
}
