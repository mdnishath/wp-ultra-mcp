<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/**
 * Site-wide internal-link graph optimizer (D4).
 *
 * PURE core (wpultra_lgraph_*): given a list of post descriptors, build a link
 * graph, surface orphans / dead-ends / hubs / over-linked nodes, and match
 * link opportunities (source -> target) by keyword overlap. WP wrappers below
 * (guarded) crawl real posts and orchestrate the SAFE single-post inserts in
 * includes/seo/links.php.
 */

/* ============================================================
 * Tokenization helpers (PURE).
 * ============================================================ */

if (!function_exists('wpultra_lgraph_stopwords')) {
    function wpultra_lgraph_stopwords(): array {
        static $sw = null;
        if ($sw !== null) { return $sw; }
        $sw = array_fill_keys([
            'a', 'an', 'the', 'and', 'or', 'but', 'if', 'of', 'to', 'in', 'on',
            'at', 'by', 'for', 'with', 'as', 'is', 'are', 'was', 'were', 'be',
            'been', 'being', 'it', 'its', 'this', 'that', 'these', 'those',
            'from', 'up', 'out', 'so', 'than', 'then', 'too', 'very', 'can',
            'will', 'just', 'not', 'no', 'do', 'does', 'did', 'has', 'have',
            'had', 'you', 'your', 'we', 'our', 'they', 'their', 'he', 'she',
            'his', 'her', 'i', 'me', 'my', 'us', 'them', 'about', 'how', 'what',
            'when', 'where', 'which', 'who', 'why', 'all', 'any', 'more', 'most',
            'some', 'such', 'only', 'own', 'same', 'other', 'into', 'over',
        ], true);
        return $sw;
    }
}

/** PURE. Lowercase, strip punctuation, drop stopwords + very-short tokens. */
function wpultra_lgraph_tokenize(string $text): array {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
    $stop = wpultra_lgraph_stopwords();
    $out = [];
    foreach (preg_split('/\s+/', trim($text)) ?: [] as $tok) {
        if ($tok === '' || strlen($tok) < 3) { continue; }
        if (isset($stop[$tok])) { continue; }
        $out[$tok] = true;
    }
    return array_keys($out);
}

/** PURE. Jaccard overlap of two token lists (stopwords already assumed removed here; we normalize + drop them again defensively). Identical -> 1.0, disjoint -> 0.0. */
function wpultra_lgraph_token_overlap(array $a, array $b): float {
    $norm = static function (array $list): array {
        $stop = wpultra_lgraph_stopwords();
        $set = [];
        foreach ($list as $t) {
            $t = strtolower(trim((string) $t));
            if ($t === '' || isset($stop[$t])) { continue; }
            $set[$t] = true;
        }
        return $set;
    };
    $sa = $norm($a);
    $sb = $norm($b);
    if (!$sa && !$sb) { return 0.0; }
    $inter = count(array_intersect_key($sa, $sb));
    $union = count($sa + $sb);
    return $union === 0 ? 0.0 : $inter / $union;
}

/* ============================================================
 * Graph builder + metrics (PURE — the heart).
 * ============================================================ */

/**
 * PURE. Build the internal-link graph.
 *
 * @param array $posts List of {id, url?, title?, slug?, outbound_internal:[target_ids], keywords?:[]}.
 * @return array {nodes: {id => {inbound, outbound, title}}, edges: [[from,to]]}
 */
function wpultra_lgraph_build(array $posts): array {
    $nodes = [];
    $known = [];
    foreach ($posts as $p) {
        $id = (int) ($p['id'] ?? 0);
        if ($id <= 0) { continue; }
        $known[$id] = true;
        $nodes[$id] = [
            'inbound'  => 0,
            'outbound' => 0,
            'title'    => (string) ($p['title'] ?? ''),
        ];
    }
    $edges = [];
    $seen = [];
    foreach ($posts as $p) {
        $from = (int) ($p['id'] ?? 0);
        if ($from <= 0 || !isset($nodes[$from])) { continue; }
        foreach ((array) ($p['outbound_internal'] ?? []) as $rawTo) {
            $to = (int) $rawTo;
            // Ignore self-links, links to unknown nodes, and duplicate edges.
            if ($to <= 0 || $to === $from || !isset($known[$to])) { continue; }
            $key = $from . '>' . $to;
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $edges[] = [$from, $to];
            $nodes[$from]['outbound']++;
            $nodes[$to]['inbound']++;
        }
    }
    return ['nodes' => $nodes, 'edges' => $edges];
}

/** PURE. Nodes with 0 inbound internal links. */
function wpultra_lgraph_orphans(array $graph): array {
    $out = [];
    foreach (($graph['nodes'] ?? []) as $id => $n) {
        if ((int) ($n['inbound'] ?? 0) === 0) {
            $out[] = ['id' => (int) $id, 'title' => (string) ($n['title'] ?? ''), 'inbound' => 0];
        }
    }
    return $out;
}

/** PURE. Nodes with 0 outbound internal links. */
function wpultra_lgraph_dead_ends(array $graph): array {
    $out = [];
    foreach (($graph['nodes'] ?? []) as $id => $n) {
        if ((int) ($n['outbound'] ?? 0) === 0) {
            $out[] = ['id' => (int) $id, 'title' => (string) ($n['title'] ?? ''), 'outbound' => 0];
        }
    }
    return $out;
}

/** PURE. Nodes ranked by inbound (authority proxy), highest first. Ties broken by id asc for stability. */
function wpultra_lgraph_hubs(array $graph): array {
    $out = [];
    foreach (($graph['nodes'] ?? []) as $id => $n) {
        $out[] = [
            'id'      => (int) $id,
            'title'   => (string) ($n['title'] ?? ''),
            'inbound' => (int) ($n['inbound'] ?? 0),
        ];
    }
    usort($out, static function ($a, $b) {
        return $b['inbound'] <=> $a['inbound'] ?: $a['id'] <=> $b['id'];
    });
    return $out;
}

/** PURE. Nodes whose outbound count exceeds $max (link dilution). */
function wpultra_lgraph_over_linked(array $graph, int $max): array {
    $out = [];
    foreach (($graph['nodes'] ?? []) as $id => $n) {
        $ob = (int) ($n['outbound'] ?? 0);
        if ($ob > $max) {
            $out[] = ['id' => (int) $id, 'title' => (string) ($n['title'] ?? ''), 'outbound' => $ob];
        }
    }
    usort($out, static function ($a, $b) {
        return $b['outbound'] <=> $a['outbound'] ?: $a['id'] <=> $b['id'];
    });
    return $out;
}

/* ============================================================
 * Opportunity matching (PURE) — the site-wide value.
 * ============================================================ */

/**
 * PURE. For each orphan / thin-inbound target, find the best SOURCE posts to
 * link FROM, by keyword+title overlap, excluding sources that already link to
 * the target and self-links.
 *
 * @param array $posts     Post descriptors (same shape as build()) — keywords[] used for source topic.
 * @param array $graph     Output of wpultra_lgraph_build().
 * @param int   $per_post  Max source suggestions per target.
 * @return array [{source_id, source_title, target_id, target_title, anchor_suggestion, score}]
 */
function wpultra_lgraph_suggest_links(array $posts, array $graph, int $per_post): array {
    $per_post = max(1, $per_post);
    $nodes = $graph['nodes'] ?? [];

    // Index posts by id + precompute source token bags (keywords + title).
    $byId = [];
    $tokens = [];
    foreach ($posts as $p) {
        $id = (int) ($p['id'] ?? 0);
        if ($id <= 0) { continue; }
        $byId[$id] = $p;
        $bag = wpultra_lgraph_tokenize(
            implode(' ', array_map('strval', (array) ($p['keywords'] ?? []))) . ' ' . (string) ($p['title'] ?? '')
        );
        $tokens[$id] = $bag;
    }

    // Existing edge set for fast "already links" lookup.
    $linked = [];
    foreach (($graph['edges'] ?? []) as $e) {
        $linked[((int) $e[0]) . '>' . ((int) $e[1])] = true;
    }

    // Targets = orphans (0 inbound). Thin-inbound could be added; orphans are the priority.
    $suggestions = [];
    foreach ($nodes as $tid => $tn) {
        $tid = (int) $tid;
        if ((int) ($tn['inbound'] ?? 0) !== 0) { continue; }
        $tpost = $byId[$tid] ?? [];
        $targetTitle = (string) ($tn['title'] ?? ($tpost['title'] ?? ''));
        $targetBag = wpultra_lgraph_tokenize(
            $targetTitle . ' ' . implode(' ', array_map('strval', (array) ($tpost['keywords'] ?? [])))
        );

        $cands = [];
        foreach ($byId as $sid => $sp) {
            if ($sid === $tid) { continue; }                       // no self-link
            if (isset($linked[$sid . '>' . $tid])) { continue; }   // already links
            $score = wpultra_lgraph_token_overlap($tokens[$sid], $targetBag);
            if ($score <= 0.0) { continue; }
            $cands[] = [
                'source_id'         => (int) $sid,
                'source_title'      => (string) ($sp['title'] ?? ''),
                'target_id'         => $tid,
                'target_title'      => $targetTitle,
                'anchor_suggestion' => $targetTitle,
                'score'             => round($score, 4),
            ];
        }
        usort($cands, static function ($a, $b) {
            return $b['score'] <=> $a['score'] ?: $a['source_id'] <=> $b['source_id'];
        });
        foreach (array_slice($cands, 0, $per_post) as $c) { $suggestions[] = $c; }
    }
    return $suggestions;
}

/* ============================================================
 * Link-health report (PURE).
 * ============================================================ */

/** PURE. Site link-health snapshot. */
function wpultra_lgraph_report(array $graph): array {
    $nodes = $graph['nodes'] ?? [];
    $edges = $graph['edges'] ?? [];
    $total = count($nodes);
    $orphans = wpultra_lgraph_orphans($graph);
    $deadEnds = wpultra_lgraph_dead_ends($graph);
    $hubs = wpultra_lgraph_hubs($graph);
    $overLinked = wpultra_lgraph_over_linked($graph, WPULTRA_LGRAPH_OVERLINK_MAX);
    $totalOutbound = 0;
    foreach ($nodes as $n) { $totalOutbound += (int) ($n['outbound'] ?? 0); }
    return [
        'total_posts' => $total,
        'total_links' => count($edges),
        'orphans'     => count($orphans),
        'dead_ends'   => count($deadEnds),
        'avg_outbound' => $total > 0 ? round($totalOutbound / $total, 2) : 0.0,
        'top_hubs'    => array_slice($hubs, 0, 10),
        'over_linked' => count($overLinked),
    ];
}

if (!defined('WPULTRA_LGRAPH_OVERLINK_MAX')) { define('WPULTRA_LGRAPH_OVERLINK_MAX', 20); }
if (!defined('WPULTRA_LGRAPH_SCAN_MAX')) { define('WPULTRA_LGRAPH_SCAN_MAX', 500); }
if (!defined('WPULTRA_LGRAPH_APPLY_MAX')) { define('WPULTRA_LGRAPH_APPLY_MAX', 50); }

/* ============================================================
 * WP wrappers (guarded — never run under the pure test harness).
 * ============================================================ */

/** Runtime contract entrypoint. Ability-driven; nothing to boot. */
function wpultra_lgraph_boot(): void { /* no-op */ }

if (defined('ABSPATH')) {

    /**
     * Crawl real published posts into the pure-builder descriptor shape:
     * {id, url, title, slug, outbound_internal:[ids], keywords:[]}.
     */
    function wpultra_lgraph_crawl(array $post_types, int $limit): array {
        $capped = min(max(1, $limit), WPULTRA_LGRAPH_SCAN_MAX);
        $ids = get_posts([
            'post_type'      => $post_types ?: ['post', 'page'],
            'post_status'    => 'publish',
            'posts_per_page' => $capped,
            'fields'         => 'ids',
        ]);
        $ids = array_map('intval', $ids);
        $known = array_fill_keys($ids, true);
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        $posts = [];
        foreach ($ids as $id) {
            $content = (string) get_post_field('post_content', $id);
            $out = [];
            if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $m)) {
                foreach ($m[1] as $href) {
                    $host = wp_parse_url($href, PHP_URL_HOST);
                    if ($host && $host !== $home) { continue; }
                    $isInternal = ($host === $home) || ($host === null && isset($href[0]) && $href[0] === '/');
                    if (!$isInternal) { continue; }
                    $target = url_to_postid($href);
                    if ($target && isset($known[$target])) { $out[] = (int) $target; }
                }
            }
            $posts[] = [
                'id'                => $id,
                'url'               => get_permalink($id),
                'title'             => get_the_title($id),
                'slug'              => get_post_field('post_name', $id),
                'outbound_internal' => array_values(array_unique($out)),
                'keywords'          => function_exists('wpultra_seo_post_keywords')
                    ? wpultra_seo_post_keywords($id)
                    : wpultra_lgraph_tokenize(get_the_title($id)),
            ];
        }
        return $posts;
    }
}
