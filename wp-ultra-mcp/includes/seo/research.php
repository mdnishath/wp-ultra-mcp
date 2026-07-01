<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

function wpultra_seo_keyword_gaps(array $candidates, array $site_index): array {
    $covered = [];
    $gaps = [];
    foreach ($candidates as $kwRaw) {
        $kw = strtolower(trim((string) $kwRaw));
        if ($kw === '') { continue; }
        $hitId = 0;
        foreach ($site_index as $row) {
            $fk = strtolower(trim((string) ($row['focus_keyword'] ?? '')));
            $titleLc = (string) ($row['title_lc'] ?? strtolower((string) ($row['title'] ?? '')));
            if (($fk !== '' && $fk === $kw) || strpos($titleLc, $kw) !== false) { $hitId = (int) $row['post_id']; break; }
        }
        if ($hitId) { $covered[] = ['keyword' => $kwRaw, 'post_id' => $hitId]; }
        else { $gaps[] = $kwRaw; }
    }
    return ['covered' => $covered, 'gaps' => $gaps];
}

function wpultra_seo_competitor_compare(array $ours, array $theirs): array {
    $lc = function ($arr) { return array_map('strtolower', array_map('strval', $arr)); };
    $ourHead = $lc($ours['headings'] ?? []);
    $ourKw = $lc($ours['keywords'] ?? []);
    $missingHeadings = [];
    foreach (($theirs['headings'] ?? []) as $h) { if (!in_array(strtolower((string) $h), $ourHead, true)) { $missingHeadings[] = $h; } }
    $missingKeywords = [];
    foreach (($theirs['keywords'] ?? []) as $k) { if (!in_array(strtolower((string) $k), $ourKw, true)) { $missingKeywords[] = $k; } }
    $delta = (int) ($ours['word_count'] ?? 0) - (int) ($theirs['word_count'] ?? 0);
    $recs = [];
    if ($delta < -200) { $recs[] = 'Competitor content is substantially longer; consider expanding by ~' . abs($delta) . ' words.'; }
    foreach ($missingHeadings as $h) { $recs[] = "Add a section covering \"$h\"."; }
    foreach ($missingKeywords as $k) { $recs[] = "Cover the keyword/term \"$k\"."; }
    return ['missing_headings' => $missingHeadings, 'missing_keywords' => $missingKeywords, 'word_count_delta' => $delta, 'recommendations' => $recs];
}

/** WP helper: build the site keyword index for keyword-gap. */
function wpultra_seo_site_index(int $limit = 200): array {
    $ids = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => max(1, $limit), 'fields' => 'ids']);
    $idx = [];
    foreach ($ids as $id) {
        $fk = function_exists('wpultra_seo_get_meta') ? (string) (wpultra_seo_get_meta((int) $id)['focus_keyword'] ?? '') : '';
        $title = get_the_title($id);
        $idx[] = ['post_id' => (int) $id, 'title' => $title, 'focus_keyword' => $fk, 'title_lc' => strtolower($title)];
    }
    return $idx;
}
