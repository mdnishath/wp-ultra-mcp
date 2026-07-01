<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

if (!function_exists('wpultra_seo_strlen')) {
    /** PURE. UTF-8-aware character count. Uses mb_strlen when available, else counts code
     *  points so non-Latin (Bengali/CJK) titles aren't over-counted as raw bytes. */
    function wpultra_seo_strlen(string $s): int {
        if (function_exists('mb_strlen')) { return (int) wpultra_seo_strlen($s); }
        return (int) preg_match_all('/./us', $s, $__m);
    }
}

function wpultra_seo_fields(): array {
    return ['title', 'description', 'focus_keyword', 'canonical', 'robots_noindex', 'robots_nofollow', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description'];
}

function wpultra_seo_bool_fields(): array { return ['robots_noindex', 'robots_nofollow']; }

function wpultra_seo_coerce_bool($v): bool {
    if (is_bool($v)) { return $v; }
    if (is_int($v)) { return $v !== 0; }
    return in_array(strtolower(trim((string) $v)), ['1', 'yes', 'true', 'on'], true);
}

function wpultra_seo_validate_meta(array $input): array {
    $fields = wpultra_seo_fields();
    $bools = wpultra_seo_bool_fields();
    $clean = [];
    $rejected = [];
    $warnings = [];
    foreach ($input as $k => $v) {
        if (!in_array($k, $fields, true)) { $rejected[] = ['field' => $k, 'reason' => 'unknown_field']; continue; }
        if (in_array($k, $bools, true)) { $clean[$k] = wpultra_seo_coerce_bool($v); continue; }
        $clean[$k] = (string) $v;
    }
    if (isset($clean['title']) && wpultra_seo_strlen($clean['title']) > 60) {
        $warnings[] = ['field' => 'title', 'note' => 'Title over 60 chars may be truncated in search results.'];
    }
    if (isset($clean['description'])) {
        $len = wpultra_seo_strlen($clean['description']);
        if ($len > 0 && $len < 120) { $warnings[] = ['field' => 'description', 'note' => 'Meta description under 120 chars; aim for 120–160.']; }
        if ($len > 160) { $warnings[] = ['field' => 'description', 'note' => 'Meta description over 160 chars may be truncated.']; }
    }
    return ['clean' => $clean, 'rejected' => $rejected, 'warnings' => $warnings];
}

/** Flat string-field key map per mode. robots_* are handled specially in get/set. */
function wpultra_seo_keymap(string $mode): array {
    if ($mode === 'yoast') {
        return [
            'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc',
            'focus_keyword' => '_yoast_wpseo_focuskw', 'canonical' => '_yoast_wpseo_canonical',
            'og_title' => '_yoast_wpseo_opengraph-title', 'og_description' => '_yoast_wpseo_opengraph-description',
            'og_image' => '_yoast_wpseo_opengraph-image', 'twitter_title' => '_yoast_wpseo_twitter-title',
            'twitter_description' => '_yoast_wpseo_twitter-description',
        ];
    }
    if ($mode === 'rankmath') {
        return [
            'title' => 'rank_math_title', 'description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword', 'canonical' => 'rank_math_canonical_url',
            'og_title' => 'rank_math_facebook_title', 'og_description' => 'rank_math_facebook_description',
            'og_image' => 'rank_math_facebook_image', 'twitter_title' => 'rank_math_twitter_title',
            'twitter_description' => 'rank_math_twitter_description',
        ];
    }
    return [
        'title' => '_wpultra_seo_title', 'description' => '_wpultra_seo_desc',
        'focus_keyword' => '_wpultra_seo_focuskw', 'canonical' => '_wpultra_seo_canonical',
        'og_title' => '_wpultra_seo_og_title', 'og_description' => '_wpultra_seo_og_desc',
        'og_image' => '_wpultra_seo_og_image', 'twitter_title' => '_wpultra_seo_tw_title',
        'twitter_description' => '_wpultra_seo_tw_desc',
    ];
}

function wpultra_seo_get_meta(int $post_id): array {
    $mode = wpultra_seo_mode();
    $map = wpultra_seo_keymap($mode);
    $out = [];
    foreach ($map as $field => $key) { $out[$field] = (string) get_post_meta($post_id, $key, true); }
    // robots (special per mode)
    if ($mode === 'rankmath') {
        $robots = get_post_meta($post_id, 'rank_math_robots', true);
        $robots = is_array($robots) ? $robots : [];
        $out['robots_noindex'] = in_array('noindex', $robots, true);
        $out['robots_nofollow'] = in_array('nofollow', $robots, true);
    } elseif ($mode === 'yoast') {
        $out['robots_noindex'] = (get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1');
        $out['robots_nofollow'] = (get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true) === '1');
    } else {
        $out['robots_noindex'] = ((string) get_post_meta($post_id, '_wpultra_seo_noindex', true) === '1');
        $out['robots_nofollow'] = ((string) get_post_meta($post_id, '_wpultra_seo_nofollow', true) === '1');
    }
    $out['mode'] = $mode;
    return $out;
}

function wpultra_seo_set_meta(int $post_id, array $fields) {
    if (!get_post($post_id)) { return wpultra_err('post_not_found', "No post with id $post_id."); }
    $v = wpultra_seo_validate_meta($fields);
    $mode = wpultra_seo_mode();
    $map = wpultra_seo_keymap($mode);
    foreach ($v['clean'] as $field => $val) {
        // update_post_meta() runs wp_unslash on the stored value; slash first so literal
        // backslashes / quotes in titles & descriptions round-trip intact.
        if (isset($map[$field])) { update_post_meta($post_id, $map[$field], wp_slash($val)); continue; }
        // robots specials. When a flag is turned OFF we DELETE the meta so the post follows the
        // SEO plugin's site defaults, rather than writing an "explicitly index/follow" value.
        if ($field === 'robots_noindex') {
            if ($mode === 'rankmath') { wpultra_seo_rankmath_robots($post_id, 'noindex', (bool) $val); }
            elseif ($mode === 'yoast') {
                if ($val) { update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', '1'); }
                else { delete_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex'); }
            } else {
                if ($val) { update_post_meta($post_id, '_wpultra_seo_noindex', '1'); }
                else { delete_post_meta($post_id, '_wpultra_seo_noindex'); }
            }
        } elseif ($field === 'robots_nofollow') {
            if ($mode === 'rankmath') { wpultra_seo_rankmath_robots($post_id, 'nofollow', (bool) $val); }
            elseif ($mode === 'yoast') {
                if ($val) { update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', '1'); }
                else { delete_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow'); }
            } else {
                if ($val) { update_post_meta($post_id, '_wpultra_seo_nofollow', '1'); }
                else { delete_post_meta($post_id, '_wpultra_seo_nofollow'); }
            }
        }
    }
    return ['post_id' => $post_id, 'rejected' => $v['rejected'], 'warnings' => $v['warnings']];
}

/** Toggle a value inside Rank Math's array-form robots meta. */
function wpultra_seo_rankmath_robots(int $post_id, string $flag, bool $on): void {
    $robots = get_post_meta($post_id, 'rank_math_robots', true);
    $robots = is_array($robots) ? $robots : [];
    $robots = array_values(array_filter($robots, function ($r) use ($flag) { return $r !== $flag; }));
    if ($on) { $robots[] = $flag; }
    update_post_meta($post_id, 'rank_math_robots', $robots);
}
