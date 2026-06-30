<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/** PURE. Wrap the first occurrence of $anchor NOT already inside an <a> in a link. */
function wpultra_seo_wrap_anchor(string $content, string $anchor, string $url): array {
    if ($anchor === '' || stripos($content, $anchor) === false) { return ['content' => $content, 'inserted' => false]; }
    // Split out existing <a>...</a> regions so we only consider text outside them.
    $parts = preg_split('/(<a\b[^>]*>.*?<\/a>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $done = false;
    foreach ($parts as $seg) {
        if (!$done && stripos($seg, '<a') !== 0) {
            $pos = stripos($seg, $anchor);
            if ($pos !== false) {
                $orig = substr($seg, $pos, strlen($anchor));
                $seg = substr($seg, 0, $pos) . '<a href="' . $url . '">' . $orig . '</a>' . substr($seg, $pos + strlen($anchor));
                $done = true;
            }
        }
        $out .= $seg;
    }
    return ['content' => $out, 'inserted' => $done];
}

/** PURE. Score candidate posts by overlap with the source's keywords. */
function wpultra_seo_rank_candidates(array $source, array $candidates): array {
    $srcKw = array_map('strtolower', $source['keywords'] ?? []);
    $rows = [];
    foreach ($candidates as $c) {
        $kw = array_map('strtolower', $c['keywords'] ?? []);
        $score = count(array_intersect($srcKw, $kw));
        if ($score > 0) { $rows[] = ['id' => $c['id'], 'title' => $c['title'] ?? '', 'score' => $score]; }
    }
    usort($rows, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return $rows;
}

function wpultra_seo_post_keywords(int $post_id): array {
    $kw = [];
    if (function_exists('wpultra_seo_get_meta')) {
        $fk = (string) (wpultra_seo_get_meta($post_id)['focus_keyword'] ?? '');
        if ($fk !== '') { $kw = preg_split('/\s+/', strtolower($fk)); }
    }
    $title = strtolower(get_the_title($post_id));
    foreach (preg_split('/\s+/', $title) as $w) { if (strlen($w) >= 4) { $kw[] = $w; } }
    return array_values(array_unique(array_filter($kw)));
}

function wpultra_seo_suggest_links(int $post_id, int $limit): array {
    $source = ['keywords' => wpultra_seo_post_keywords($post_id)];
    $catIds = wp_get_post_categories($post_id);
    $tagIds = wp_get_post_tags($post_id, ['fields' => 'ids']);
    $args = ['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 30, 'post__not_in' => [$post_id], 'fields' => 'ids'];
    if ($catIds || $tagIds) { $args['tax_query'] = ['relation' => 'OR']; }
    if ($catIds) { $args['tax_query'][] = ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => $catIds]; }
    if ($tagIds) { $args['tax_query'][] = ['taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $tagIds]; }
    $ids = get_posts($args);
    $cands = [];
    foreach ($ids as $id) { $cands[] = ['id' => (int) $id, 'title' => get_the_title($id), 'terms' => [], 'keywords' => wpultra_seo_post_keywords((int) $id)]; }
    $ranked = wpultra_seo_rank_candidates($source, $cands);
    $out = [];
    foreach (array_slice($ranked, 0, max(1, $limit)) as $r) {
        $out[] = ['target_id' => $r['id'], 'target_title' => $r['title'], 'target_url' => get_permalink($r['id']), 'anchor_suggestion' => $r['title'], 'score' => $r['score']];
    }
    return $out;
}

function wpultra_seo_insert_link(int $post_id, string $anchor, string $url) {
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('post_not_found', "No post with id $post_id."); }
    if (in_array($post->post_type, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', "Post $post_id is plugin-internal; not editable here.");
    }
    $r = wpultra_seo_wrap_anchor((string) $post->post_content, $anchor, esc_url_raw($url));
    if (!$r['inserted']) { return ['post_id' => $post_id, 'inserted' => false, 'anchor' => $anchor]; }
    wp_update_post(['ID' => $post_id, 'post_content' => $r['content']]);
    return ['post_id' => $post_id, 'inserted' => true, 'anchor' => $anchor];
}

function wpultra_seo_link_audit(int $limit): array {
    $ids = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => max(1, $limit), 'fields' => 'ids']);
    $home = wp_parse_url(home_url(), PHP_URL_HOST);
    $incoming = array_fill_keys(array_map('intval', $ids), 0);
    $broken = [];
    foreach ($ids as $id) {
        $content = (string) get_post_field('post_content', $id);
        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $m)) {
            foreach ($m[1] as $href) {
                $host = wp_parse_url($href, PHP_URL_HOST);
                if ($host && $host !== $home) { continue; }
                $target = url_to_postid($href);
                if ($target && isset($incoming[$target])) { $incoming[$target]++; }
                elseif ($target === 0 && strpos($href, $home ?: 'wp-connector') !== false) { $broken[] = ['post_id' => (int) $id, 'href' => $href]; }
            }
        }
    }
    $orphans = [];
    foreach ($incoming as $pid => $count) { if ($count === 0) { $orphans[] = ['id' => $pid, 'title' => get_the_title($pid)]; } }
    return ['orphans' => $orphans, 'broken' => $broken, 'counts' => ['scanned' => count($ids), 'orphans' => count($orphans), 'broken' => count($broken)]];
}
