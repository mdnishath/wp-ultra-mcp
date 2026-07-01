<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

if (!function_exists('wpultra_seo_strlen')) {
    /** PURE. UTF-8-aware character count (mb_strlen when available, else code-point count). */
    function wpultra_seo_strlen(string $s): int {
        if (function_exists('mb_strlen')) { return (int) wpultra_seo_strlen($s); }
        return (int) preg_match_all('/./us', $s, $__m);
    }
}

if (!function_exists('wpultra_seo_word_count')) {
    /** PURE. Unicode-aware word count (str_word_count returns 0 for Bengali/CJK). */
    function wpultra_seo_word_count(string $text): int {
        return (int) preg_match_all('/\S+/u', $text, $__m);
    }
}

/** PURE. Classify a post's SEO issues from its extracted data. */
function wpultra_seo_audit_post(array $d): array {
    $issues = [];
    $add = function (string $code, string $sev, string $msg) use (&$issues) { $issues[] = ['code' => $code, 'severity' => $sev, 'message' => $msg]; };
    $title = (string) ($d['seo_title'] ?? '');
    if ($title === '') { $add('missing_seo_title', 'high', 'No SEO title set.'); }
    elseif (wpultra_seo_strlen($title) > 60) { $add('title_too_long', 'low', 'SEO title over 60 chars.'); }
    $desc = (string) ($d['seo_desc'] ?? '');
    if ($desc === '') { $add('missing_meta_description', 'high', 'No meta description set.'); }
    elseif (wpultra_seo_strlen($desc) < 120) { $add('meta_description_too_short', 'medium', 'Meta description under 120 chars.'); }
    elseif (wpultra_seo_strlen($desc) > 160) { $add('meta_description_too_long', 'low', 'Meta description over 160 chars.'); }
    if ((string) ($d['focus_keyword'] ?? '') === '') { $add('missing_focus_keyword', 'medium', 'No focus keyword set.'); }
    if ((int) ($d['word_count'] ?? 0) < 300) { $add('thin_content', 'medium', 'Content under 300 words.'); }
    if ((int) ($d['images_missing_alt'] ?? 0) > 0) { $add('missing_image_alt', 'medium', ((int) $d['images_missing_alt']) . ' image(s) missing alt text.'); }
    if (!empty($d['noindex'])) { $add('noindex_set', 'high', 'Post is set to noindex (excluded from search).'); }
    return $issues;
}

/** PURE. Expand %key% tokens in a template. */
function wpultra_seo_expand_template(string $tpl, array $tokens): string {
    foreach ($tokens as $k => $v) { $tpl = str_replace('%' . $k . '%', (string) $v, $tpl); }
    return $tpl;
}

function wpultra_seo_audit_extract(int $post_id): array {
    $meta = function_exists('wpultra_seo_get_meta') ? wpultra_seo_get_meta($post_id) : [];
    $ex = function_exists('wpultra_seo_extract_post') ? wpultra_seo_extract_post($post_id) : [];
    return [
        'seo_title' => (string) ($meta['title'] ?? ''),
        'seo_desc' => (string) ($meta['description'] ?? ''),
        'focus_keyword' => (string) ($meta['focus_keyword'] ?? ''),
        'noindex' => !empty($meta['robots_noindex']),
        'word_count' => wpultra_seo_word_count((string) ($ex['body_text'] ?? '')),
        'images_missing_alt' => (int) ($ex['images_missing_alt'] ?? 0),
    ];
}

/** Hard cap on how many posts a single bulk scan will walk, so a huge site can't OOM/timeout. */
if (!defined('WPULTRA_SEO_SCAN_MAX')) { define('WPULTRA_SEO_SCAN_MAX', 500); }

function wpultra_seo_site_audit(int $limit): array {
    $capped = min(max(1, $limit), WPULTRA_SEO_SCAN_MAX);
    $truncated = $limit > WPULTRA_SEO_SCAN_MAX;
    $ids = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => $capped, 'fields' => 'ids']);
    // Prime the meta cache in one query; each audit_extract() calls get_post_meta repeatedly.
    if ($ids && function_exists('update_meta_cache')) { update_meta_cache('post', array_map('intval', $ids)); }
    $byCode = [];
    $rows = [];
    $titles = [];
    foreach ($ids as $id) {
        $data = wpultra_seo_audit_extract((int) $id);
        $issues = wpultra_seo_audit_post($data);
        foreach ($issues as $i) { $byCode[$i['code']] = ($byCode[$i['code']] ?? 0) + 1; }
        if ($issues) { $rows[] = ['post_id' => (int) $id, 'title' => get_the_title($id), 'issues' => $issues]; }
        $t = strtolower(trim(get_the_title($id)));
        if ($t !== '') { $titles[$t][] = (int) $id; }
    }
    $duplicates = [];
    foreach ($titles as $t => $group) { if (count($group) > 1) { $duplicates[] = ['title' => $t, 'post_ids' => $group]; } }
    $orphans = function_exists('wpultra_seo_link_audit') ? (wpultra_seo_link_audit($capped)['orphans'] ?? []) : [];
    return ['scanned' => count($ids), 'truncated' => $truncated, 'issue_counts' => $byCode, 'duplicate_titles' => $duplicates, 'orphans' => $orphans, 'posts' => $rows];
}

function wpultra_seo_bulk_set_meta(array $input): array {
    $filter = (string) ($input['filter'] ?? 'missing_title'); // missing_title | missing_description | all
    $limit = isset($input['limit']) ? (int) $input['limit'] : 50;
    $capped = min(max(1, $limit), WPULTRA_SEO_SCAN_MAX);
    $truncated = $limit > WPULTRA_SEO_SCAN_MAX;
    $dry = !array_key_exists('dry_run', $input) ? true : (bool) $input['dry_run'];
    if (!empty($input['apply'])) { $dry = false; }
    $mode = function_exists('wpultra_seo_mode') ? wpultra_seo_mode() : 'native';
    $map = function_exists('wpultra_seo_keymap') ? wpultra_seo_keymap($mode) : [];
    $args = ['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => $capped, 'fields' => 'ids'];
    // Push the "missing" filter into the query as a NOT EXISTS on the relevant meta key, so the
    // limit selects among posts that actually lack the field (instead of filtering the newest N
    // after the fact and matching almost nothing).
    $missKey = '';
    if ($filter === 'missing_title') { $missKey = (string) ($map['title'] ?? ''); }
    elseif ($filter === 'missing_description') { $missKey = (string) ($map['description'] ?? ''); }
    if ($missKey !== '') {
        $args['meta_query'] = [['key' => $missKey, 'compare' => 'NOT EXISTS']];
    }
    $ids = get_posts($args);
    // A meta value can exist but be an empty string (NOT EXISTS misses those), so still verify
    // in the loop; the query just ensures the limit lands on plausibly-missing posts.
    if ($ids && function_exists('update_meta_cache')) { update_meta_cache('post', array_map('intval', $ids)); }
    $sitename = get_bloginfo('name');
    $applied = [];
    $skipped = 0;
    foreach ($ids as $id) {
        $meta = wpultra_seo_get_meta((int) $id);
        $wantTitle = isset($input['title_template']);
        $wantDesc = isset($input['description_template']);
        $wantNoindex = array_key_exists('noindex', $input);
        $matches = ($filter === 'all')
            || ($filter === 'missing_title' && (string) ($meta['title'] ?? '') === '')
            || ($filter === 'missing_description' && (string) ($meta['description'] ?? '') === '');
        if (!$matches) { $skipped++; continue; }
        $fields = [];
        $tokens = ['title' => get_the_title($id), 'sitename' => $sitename, 'sep' => (string) ($input['sep'] ?? '|')];
        if ($wantTitle) { $fields['title'] = wpultra_seo_expand_template((string) $input['title_template'], $tokens); }
        if ($wantDesc) { $fields['description'] = wpultra_seo_expand_template((string) $input['description_template'], $tokens); }
        if ($wantNoindex) { $fields['robots_noindex'] = (bool) $input['noindex']; }
        if (!$fields) { $skipped++; continue; }
        if (!$dry) { wpultra_seo_set_meta((int) $id, $fields); }
        $applied[] = ['post_id' => (int) $id, 'changes' => $fields];
    }
    return ['dry_run' => $dry, 'truncated' => $truncated, 'applied' => $applied, 'applied_count' => count($applied), 'skipped' => $skipped];
}
