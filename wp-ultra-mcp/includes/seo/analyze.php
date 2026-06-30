<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

function wpultra_seo_score(array $d): array {
    $kw = strtolower(trim((string) ($d['focus_keyword'] ?? '')));
    $has = function ($hay) use ($kw) { return $kw !== '' && strpos(strtolower((string) $hay), $kw) !== false; };
    $checks = [];
    $add = function (string $id, string $status, string $msg) use (&$checks) { $checks[] = ['id' => $id, 'status' => $status, 'message' => $msg]; };

    $add('keyword_set', $kw !== '' ? 'pass' : 'warn', $kw !== '' ? "Focus keyword: \"$kw\"." : 'No focus keyword set.');
    $add('keyword_in_title', $has($d['title'] ?? '') ? 'pass' : 'fail', 'Focus keyword in the SEO title.');
    $add('keyword_in_h1', $has($d['h1'] ?? '') ? 'pass' : 'warn', 'Focus keyword in the H1.');
    $add('keyword_in_first_paragraph', $has($d['first_paragraph'] ?? '') ? 'pass' : 'warn', 'Focus keyword in the opening paragraph.');
    $add('keyword_in_slug', $kw !== '' && strpos((string) ($d['slug'] ?? ''), str_replace(' ', '-', $kw)) !== false ? 'pass' : 'warn', 'Focus keyword in the URL slug.');

    $titleLen = strlen((string) ($d['title'] ?? ''));
    $add('title_length', ($titleLen > 0 && $titleLen <= 60) ? 'pass' : ($titleLen === 0 ? 'fail' : 'warn'), "SEO title length ($titleLen) ≤ 60.");
    $descLen = strlen((string) ($d['meta_description'] ?? ''));
    $add('has_meta_description', $descLen > 0 ? 'pass' : 'fail', 'Meta description is set.');
    $add('meta_description_length', ($descLen >= 120 && $descLen <= 160) ? 'pass' : ($descLen === 0 ? 'fail' : 'warn'), "Meta description length ($descLen) in 120–160.");

    $words = str_word_count(strip_tags((string) ($d['body_text'] ?? '')));
    $add('content_length', $words >= 300 ? 'pass' : 'warn', "Content word count ($words) ≥ 300.");

    // keyword density
    $density = 0.0;
    if ($kw !== '' && $words > 0) { $density = round((substr_count(strtolower((string) $d['body_text']), $kw) * (1 + substr_count($kw, ' ')) / max(1, $words)) * 100, 2); }
    $add('keyword_density', ($density >= 0.5 && $density <= 3.0) ? 'pass' : 'warn', "Keyword density ($density%) within 0.5–3%.");

    $add('has_internal_links', (int) ($d['internal_links'] ?? 0) >= 1 ? 'pass' : 'warn', 'At least one internal link.');
    $add('has_external_links', (int) ($d['external_links'] ?? 0) >= 1 ? 'pass' : 'warn', 'At least one outbound link.');
    $imgMissing = (int) ($d['images_missing_alt'] ?? 0);
    $add('images_have_alt', $imgMissing === 0 ? 'pass' : 'fail', $imgMissing === 0 ? 'All images have alt text.' : "$imgMissing image(s) missing alt text.");

    $weights = ['fail' => 0, 'warn' => 0.5, 'pass' => 1];
    $total = count($checks);
    $sum = 0.0;
    foreach ($checks as $c) { $sum += $weights[$c['status']]; }
    $score = $total > 0 ? (int) round(($sum / $total) * 100) : 0;
    $recs = [];
    foreach ($checks as $c) { if ($c['status'] !== 'pass') { $recs[] = $c['message']; } }
    return ['score' => $score, 'checks' => $checks, 'recommendations' => $recs];
}

function wpultra_seo_extract_post(int $post_id): array {
    $post = get_post($post_id);
    if (!$post) { return []; }
    $meta = function_exists('wpultra_seo_get_meta') ? wpultra_seo_get_meta($post_id) : [];
    $content = (string) $post->post_content;
    $text = trim(strip_tags($content));
    $firstPara = '';
    if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $content, $mm)) { $firstPara = trim(strip_tags($mm[1])); }
    if ($firstPara === '') { $firstPara = substr($text, 0, 200); }
    $h1 = '';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $content, $h)) { $h1 = trim(strip_tags($h[1])); }
    if ($h1 === '') { $h1 = get_the_title($post_id); }
    $internal = 0; $external = 0; $home = wp_parse_url(home_url(), PHP_URL_HOST);
    if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $links)) {
        foreach ($links[1] as $href) {
            $host = wp_parse_url($href, PHP_URL_HOST);
            if (!$host || $host === $home) { $internal++; } else { $external++; }
        }
    }
    $imgTotal = preg_match_all('/<img\s/i', $content, $x);
    $imgNoAlt = preg_match_all('/<img\s(?:(?!alt=)[^>])*?>/i', $content, $y);
    return [
        'title' => $meta['title'] ?? get_the_title($post_id),
        'meta_description' => $meta['description'] ?? '',
        'focus_keyword' => $meta['focus_keyword'] ?? '',
        'h1' => $h1, 'first_paragraph' => $firstPara, 'body_text' => $text,
        'slug' => $post->post_name, 'internal_links' => $internal, 'external_links' => $external,
        'images_total' => (int) $imgTotal, 'images_missing_alt' => (int) $imgNoAlt,
    ];
}
