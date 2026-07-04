<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Product reviews + Q&A engine (roadmap B3).
 *
 * POWER layer on top of the basic woo-manage-review CRUD ability:
 *   - Review requests: find completed orders whose customers haven't reviewed
 *     what they bought and haven't been asked yet, email them a request.
 *   - Photo reviews: reviews carrying attachment ids in comment meta
 *     'wpultra_review_photos'; a comment_text filter renders thumbnails.
 *   - Q&A: questions/answers as comments on the product post
 *     (comment_type 'wpultra_question' / 'wpultra_answer').
 *   - Stats: avg rating / verified / photo / pending-question counts.
 *
 * All wpultra_rvx_* functions in the PURE section have zero WP/WC dependency
 * and are unit-tested in tests/woo-reviews-engine.test.php. Runtime wrappers
 * (orders, comments, mail) follow and are guarded so this file is
 * harness-loadable.
 *
 * HPOS-safe: order meta only via $order->get_meta()/update_meta_data()/save(),
 * orders only via wc_get_orders(). Reviews/Q&A live on products (normal posts),
 * so the plain comment API is fine there.
 */

// ---------------------------------------------------------------------------
// PURE: escaping
// ---------------------------------------------------------------------------

/** HTML-escape (ENT_QUOTES, UTF-8). Pure stand-in for esc_html/esc_attr. */
function wpultra_rvx_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// PURE: photo id sanitizing
// ---------------------------------------------------------------------------

/**
 * Sanitize a list of attachment ids for a photo review: cast to int, drop
 * non-positive values, dedupe (first occurrence wins), cap at $cap (default 5).
 * Returns a reindexed list of ints. Pure — existence/image checks happen at
 * runtime via wp_attachment_is_image().
 */
function wpultra_rvx_clean_photo_ids(array $ids, int $cap = 5): array {
    $out = [];
    foreach ($ids as $id) {
        if (is_array($id) || is_object($id)) { continue; }
        $n = (int) $id;
        if ($n <= 0) { continue; }
        if (in_array($n, $out, true)) { continue; }
        $out[] = $n;
        if (count($out) >= $cap) { break; }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// PURE: Q&A text sanitizing
// ---------------------------------------------------------------------------

/**
 * Public-safe question/answer text: strip tags, collapse leading/trailing
 * whitespace, hard-cap at $max characters (default 1000). Pure.
 */
function wpultra_rvx_clean_question(string $q, int $max = 1000): string {
    $q = trim(strip_tags($q));
    if ($max > 0 && function_exists('mb_substr')) {
        $q = mb_substr($q, 0, $max);
    } elseif ($max > 0) {
        $q = substr($q, 0, $max);
    }
    return trim($q);
}

// ---------------------------------------------------------------------------
// PURE: review-request candidate filtering
// ---------------------------------------------------------------------------

/**
 * Filter order rows down to the ones that still need a review request.
 *
 *   $orders         — [{order_id, email, name, requested: bool, product_ids: int[]}]
 *   $reviewed_pairs — list of "email|product_id" strings already reviewed
 *                     (email match is case-insensitive).
 *
 * Rules: orders already flagged `requested` are dropped; product ids the
 * customer already reviewed are stripped; an order with zero remaining
 * products is dropped. Returns the surviving rows (same shape, product_ids
 * filtered + deduped). Pure.
 */
function wpultra_rvx_filter_candidates(array $orders, array $reviewed_pairs): array {
    $reviewed = [];
    foreach ($reviewed_pairs as $pair) {
        if (!is_string($pair) && !is_numeric($pair)) { continue; }
        $reviewed[strtolower(trim((string) $pair))] = true;
    }

    $out = [];
    foreach ($orders as $o) {
        if (!is_array($o)) { continue; }
        if (!empty($o['requested'])) { continue; }
        $email = strtolower(trim((string) ($o['email'] ?? '')));
        if ($email === '') { continue; }

        $remaining = [];
        foreach ((array) ($o['product_ids'] ?? []) as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) { continue; }
            if (isset($reviewed[$email . '|' . $pid])) { continue; }
            if (in_array($pid, $remaining, true)) { continue; }
            $remaining[] = $pid;
        }
        if (empty($remaining)) { continue; }

        $o['product_ids'] = $remaining;
        $out[] = $o;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// PURE: review-request email template
// ---------------------------------------------------------------------------

/**
 * Render the review-request email body.
 *   $d = ['name' => string, 'products' => [['name' => string, 'url' => string], ...], 'site' => string]
 * Every dynamic value is HTML-escaped. Pure.
 */
function wpultra_rvx_request_html(array $d): string {
    $name = trim((string) ($d['name'] ?? ''));
    $greeting = $name !== '' ? 'Hi ' . wpultra_rvx_esc($name) . ',' : 'Hi,';

    $items = '';
    foreach ((array) ($d['products'] ?? []) as $p) {
        if (!is_array($p)) { continue; }
        $pname = wpultra_rvx_esc((string) ($p['name'] ?? ''));
        if ($pname === '') { continue; }
        $url = trim((string) ($p['url'] ?? ''));
        if ($url !== '') {
            $items .= '<li><a href="' . wpultra_rvx_esc($url) . '">' . $pname . '</a></li>';
        } else {
            $items .= '<li>' . $pname . '</li>';
        }
    }

    $site = trim((string) ($d['site'] ?? ''));
    $signoff = $site !== '' ? 'Thanks,<br>' . wpultra_rvx_esc($site) : 'Thanks!';

    return '<div style="font-family:sans-serif;max-width:600px;margin:0 auto">'
        . '<p>' . $greeting . '</p>'
        . '<p>Thanks for your recent order! We would love to hear what you think. '
        . 'Could you take a minute to review your purchase?</p>'
        . '<ul>' . $items . '</ul>'
        . '<p>Your feedback helps other shoppers and helps us improve.</p>'
        . '<p>' . $signoff . '</p>'
        . '</div>';
}

// ---------------------------------------------------------------------------
// PURE: Q&A tree shaping
// ---------------------------------------------------------------------------

/**
 * Shape flat Q&A comment rows into a nested question tree.
 *
 *   $flat — [{id, parent, type, content, author, date, approved}]
 *           type may be 'wpultra_question'/'wpultra_answer' or bare
 *           'question'/'answer'; approved may be bool, 1/0, or '1'/'0'.
 *
 * Pending (unapproved) questions AND answers are excluded unless
 * $include_pending. Answers whose parent question is absent from the final
 * set (orphans — including answers to filtered-out pending questions) are
 * dropped. Input order is preserved. Pure.
 */
function wpultra_rvx_qa_tree(array $flat, bool $include_pending = false): array {
    $is_approved = static function ($v): bool {
        return $v === true || $v === 1 || $v === '1';
    };

    // Pass 1: questions.
    $questions = [];  // id => node (by reference into $order list below)
    $order = [];      // question ids in input order
    foreach ($flat as $row) {
        if (!is_array($row)) { continue; }
        $type = (string) ($row['type'] ?? '');
        if (strpos($type, 'question') === false) { continue; }
        $approved = $is_approved($row['approved'] ?? false);
        if (!$approved && !$include_pending) { continue; }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || isset($questions[$id])) { continue; }
        $questions[$id] = [
            'id'       => $id,
            'question' => (string) ($row['content'] ?? ''),
            'author'   => (string) ($row['author'] ?? ''),
            'date'     => (string) ($row['date'] ?? ''),
            'status'   => $approved ? 'approved' : 'pending',
            'answers'  => [],
        ];
        $order[] = $id;
    }

    // Pass 2: answers — parent must be a surviving question, else orphan → drop.
    foreach ($flat as $row) {
        if (!is_array($row)) { continue; }
        $type = (string) ($row['type'] ?? '');
        if (strpos($type, 'answer') === false) { continue; }
        $approved = $is_approved($row['approved'] ?? false);
        if (!$approved && !$include_pending) { continue; }
        $parent = (int) ($row['parent'] ?? 0);
        if ($parent <= 0 || !isset($questions[$parent])) { continue; }
        $questions[$parent]['answers'][] = [
            'id'     => (int) ($row['id'] ?? 0),
            'answer' => (string) ($row['content'] ?? ''),
            'author' => (string) ($row['author'] ?? ''),
            'date'   => (string) ($row['date'] ?? ''),
            'status' => $approved ? 'approved' : 'pending',
        ];
    }

    $out = [];
    foreach ($order as $id) { $out[] = $questions[$id]; }
    return $out;
}

/** Render a Q&A tree (from wpultra_rvx_qa_tree) as escaped HTML. Pure. */
function wpultra_rvx_qa_render_html(array $tree): string {
    if (empty($tree)) {
        return '<div class="wpultra-qa wpultra-qa-empty"></div>';
    }
    $h = '<div class="wpultra-qa">';
    foreach ($tree as $q) {
        if (!is_array($q)) { continue; }
        $h .= '<div class="wpultra-qa-item">';
        $h .= '<p class="wpultra-qa-question"><strong>Q:</strong> '
            . wpultra_rvx_esc((string) ($q['question'] ?? ''))
            . ' <span class="wpultra-qa-author">&mdash; ' . wpultra_rvx_esc((string) ($q['author'] ?? '')) . '</span></p>';
        foreach ((array) ($q['answers'] ?? []) as $a) {
            if (!is_array($a)) { continue; }
            $h .= '<p class="wpultra-qa-answer"><strong>A</strong> (' . wpultra_rvx_esc((string) ($a['author'] ?? '')) . '): '
                . wpultra_rvx_esc((string) ($a['answer'] ?? '')) . '</p>';
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}

// ---------------------------------------------------------------------------
// PURE: stats math
// ---------------------------------------------------------------------------

/**
 * Aggregate review stats from rows {rating, verified, has_photos}.
 * avg_rating is rounded to 2 decimals; zero reviews → avg 0.0. Pure.
 */
function wpultra_rvx_stats(array $reviews): array {
    $count = 0;
    $sum = 0.0;
    $verified = 0;
    $photos = 0;
    foreach ($reviews as $r) {
        if (!is_array($r)) { continue; }
        $count++;
        $sum += (float) ($r['rating'] ?? 0);
        if (!empty($r['verified'])) { $verified++; }
        if (!empty($r['has_photos'])) { $photos++; }
    }
    return [
        'review_count'       => $count,
        'avg_rating'         => $count > 0 ? round($sum / $count, 2) : 0.0,
        'verified_count'     => $verified,
        'photo_review_count' => $photos,
    ];
}

// ---------------------------------------------------------------------------
// Runtime: boot (shortcode + photo filter) — called by the controller
// ---------------------------------------------------------------------------

/**
 * Boot the reviews engine's front-end hooks: the [wpultra_product_qa]
 * shortcode and a comment_text filter that appends photo thumbnails to
 * review comments carrying 'wpultra_review_photos' meta. Both are cheap;
 * the photo filter touches comment meta only for 'review'-type comments.
 */
function wpultra_reviews_engine_boot(): void {
    if (function_exists('add_shortcode')) {
        add_shortcode('wpultra_product_qa', 'wpultra_rvx_qa_shortcode');
    }
    if (function_exists('add_filter')) {
        add_filter('comment_text', 'wpultra_rvx_comment_text_photos', 10, 2);
    }
}

/** [wpultra_product_qa product_id=""] — render approved Q&A for a product. */
function wpultra_rvx_qa_shortcode($atts = []): string {
    $atts = is_array($atts) ? $atts : [];
    $pid = isset($atts['product_id']) ? (int) $atts['product_id'] : 0;
    if ($pid <= 0 && function_exists('get_the_ID')) { $pid = (int) get_the_ID(); }
    if ($pid <= 0) { return ''; }
    $tree = wpultra_rvx_qa_tree(wpultra_rvx_qa_flat_rows($pid), false);
    return wpultra_rvx_qa_render_html($tree);
}

/** comment_text filter: append photo thumbnails to photo reviews. */
function wpultra_rvx_comment_text_photos($text, $comment = null) {
    if (!is_object($comment) || (string) ($comment->comment_type ?? '') !== 'review') { return $text; }
    if (!function_exists('get_comment_meta') || !function_exists('wp_get_attachment_image')) { return $text; }
    $ids = get_comment_meta((int) $comment->comment_ID, 'wpultra_review_photos', true);
    if (!is_array($ids) || empty($ids)) { return $text; }
    $imgs = '';
    foreach (wpultra_rvx_clean_photo_ids($ids) as $id) {
        $img = wp_get_attachment_image($id, 'thumbnail'); // WP escapes attributes.
        if (is_string($img) && $img !== '') { $imgs .= $img; }
    }
    if ($imgs === '') { return $text; }
    return $text . '<div class="wpultra-review-photos">' . $imgs . '</div>';
}

// ---------------------------------------------------------------------------
// Runtime: review requests (HPOS-safe — wc_get_orders + order meta CRUD only)
// ---------------------------------------------------------------------------

/** Clamp the "older than N days" window to [1, 90]. Pure-ish helper. */
function wpultra_rvx_clamp_days($days): int {
    $n = (int) $days;
    if ($n < 1) { $n = 7; }
    if ($n > 90) { $n = 90; }
    return $n;
}

/**
 * Fetch completed orders created between 90 days ago and N days ago and map
 * them to plain candidate rows for wpultra_rvx_filter_candidates().
 */
function wpultra_rvx_order_rows(int $days): array {
    if (!function_exists('wc_get_orders')) { return []; }
    $days = wpultra_rvx_clamp_days($days);
    $now = time();
    $orders = wc_get_orders([
        'status'       => 'completed',
        'date_created' => ($now - 90 * 86400) . '...' . ($now - $days * 86400),
        'limit'        => 100,
        'orderby'      => 'date',
        'order'        => 'DESC',
    ]);

    $rows = [];
    foreach ((array) $orders as $order) {
        if (!is_object($order) || !method_exists($order, 'get_id')) { continue; }
        $pids = [];
        foreach ($order->get_items() as $item) {
            $pid = (int) $item->get_product_id();
            if ($pid > 0 && !in_array($pid, $pids, true)) { $pids[] = $pid; }
        }
        $rows[] = [
            'order_id'    => (int) $order->get_id(),
            'email'       => (string) $order->get_billing_email(),
            'name'        => trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name()),
            'requested'   => (string) $order->get_meta('_wpultra_review_requested') !== '',
            'product_ids' => $pids,
        ];
    }
    return $rows;
}

/** Build the "email|product_id" reviewed-pairs set for a list of products. */
function wpultra_rvx_reviewed_pairs(array $product_ids): array {
    if (!function_exists('get_comments') || empty($product_ids)) { return []; }
    $comments = get_comments([
        'post__in' => array_map('intval', $product_ids),
        'type'     => 'review',
        'status'   => 'all', // a held review still counts as "already reviewed"
        'number'   => 0,
    ]);
    $pairs = [];
    foreach ((array) $comments as $c) {
        $email = strtolower(trim((string) ($c->comment_author_email ?? '')));
        if ($email === '') { continue; }
        $pairs[$email . '|' . (int) $c->comment_post_ID] = true;
    }
    return array_keys($pairs);
}

/**
 * Resolve review-request candidates (cap 50):
 * [{order_id, email, name, products: [{id, name}]}]
 */
function wpultra_rvx_candidates(int $days): array {
    $rows = wpultra_rvx_order_rows($days);
    if (empty($rows)) { return []; }

    $all_pids = [];
    foreach ($rows as $r) {
        foreach ($r['product_ids'] as $pid) { $all_pids[$pid] = true; }
    }
    $cands = wpultra_rvx_filter_candidates($rows, wpultra_rvx_reviewed_pairs(array_keys($all_pids)));
    $cands = array_slice($cands, 0, 50);

    $out = [];
    foreach ($cands as $c) {
        $products = [];
        foreach ($c['product_ids'] as $pid) {
            $products[] = [
                'id'   => $pid,
                'name' => function_exists('get_the_title') ? (string) get_the_title($pid) : '',
            ];
        }
        $out[] = [
            'order_id' => (int) $c['order_id'],
            'email'    => (string) $c['email'],
            'name'     => (string) $c['name'],
            'products' => $products,
        ];
    }
    return $out;
}

/**
 * Email a review request to every current candidate and flag each order with
 * the '_wpultra_review_requested' meta guard (HPOS-safe: update_meta_data +
 * save on the order object). Returns ['candidates', 'sent', 'failed'].
 */
function wpultra_rvx_send_requests(int $days) {
    if (!function_exists('wp_mail') || !function_exists('wc_get_order')) {
        return wpultra_err('mail_unavailable', 'wp_mail / WooCommerce order API not available.');
    }
    $cands = wpultra_rvx_candidates($days);
    $site = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
    $sent = 0;
    $failed = 0;

    foreach ($cands as $c) {
        $products = [];
        foreach ($c['products'] as $p) {
            $url = function_exists('get_permalink') ? (string) get_permalink((int) $p['id']) : '';
            $products[] = [
                'name' => (string) $p['name'],
                'url'  => $url !== '' ? $url . '#reviews' : '',
            ];
        }
        $html = wpultra_rvx_request_html(['name' => $c['name'], 'products' => $products, 'site' => $site]);
        $subject = $site !== '' ? sprintf('How was your order from %s?', $site) : 'How was your order?';
        $ok = wp_mail((string) $c['email'], $subject, $html, ['Content-Type: text/html; charset=UTF-8']);

        if ($ok) {
            $order = wc_get_order((int) $c['order_id']);
            if ($order) {
                $order->update_meta_data('_wpultra_review_requested', gmdate('Y-m-d H:i:s'));
                $order->save();
            }
            $sent++;
        } else {
            $failed++;
        }
    }
    return ['candidates' => count($cands), 'sent' => $sent, 'failed' => $failed];
}

// ---------------------------------------------------------------------------
// Runtime: photo reviews
// ---------------------------------------------------------------------------

/**
 * Create a (photo) review on a product. Args: product_id, author_name,
 * author_email, rating 1..5, content, photo_ids (attachment ids, cap 5),
 * verified (request the verified badge — only granted when the email really
 * bought the product per wc_customer_bought_product).
 * Returns array or WP_Error.
 */
function wpultra_rvx_create_review(array $a) {
    if (!function_exists('wp_insert_comment')) {
        return wpultra_err('wp_unavailable', 'Comment API not available.');
    }
    $pid = (int) ($a['product_id'] ?? 0);
    if ($pid <= 0 || !function_exists('get_post') || !($post = get_post($pid)) || $post->post_type !== 'product') {
        return wpultra_err('invalid_product', 'product_id does not resolve to a product.');
    }
    $rating = (int) ($a['rating'] ?? 0);
    if ($rating < 1 || $rating > 5) {
        return wpultra_err('invalid_rating', 'rating must be an integer 1..5.');
    }
    $email = trim((string) ($a['author_email'] ?? ''));
    if ($email === '' || (function_exists('is_email') && !is_email($email))) {
        return wpultra_err('invalid_email', 'author_email must be a valid email address.');
    }
    $content = trim((string) ($a['content'] ?? ''));
    if ($content === '') {
        return wpultra_err('missing_content', 'content must not be empty.');
    }
    if (function_exists('wp_kses_post')) { $content = wp_kses_post($content); }
    $author = wpultra_rvx_clean_question((string) ($a['author_name'] ?? ''), 100);
    if ($author === '') { $author = 'Customer'; }

    // Photos: sanitize the id list (pure), then keep only real image attachments.
    $photo_ids = wpultra_rvx_clean_photo_ids((array) ($a['photo_ids'] ?? []));
    $valid_photos = [];
    if (function_exists('wp_attachment_is_image')) {
        foreach ($photo_ids as $aid) {
            if (wp_attachment_is_image($aid)) { $valid_photos[] = $aid; }
        }
    }
    $dropped = count($photo_ids) - count($valid_photos);

    $cid = wp_insert_comment([
        'comment_post_ID'      => $pid,
        'comment_author'       => $author,
        'comment_author_email' => $email,
        'comment_content'      => $content,
        'comment_type'         => 'review',
        'comment_approved'     => 1,
        'comment_parent'       => 0,
    ]);
    if (!$cid || is_wp_error($cid)) {
        return wpultra_err('insert_failed', 'wp_insert_comment failed.');
    }
    $cid = (int) $cid;

    if (function_exists('update_comment_meta')) {
        update_comment_meta($cid, 'rating', $rating); // Woo core rating key.
        if (!empty($valid_photos)) {
            update_comment_meta($cid, 'wpultra_review_photos', $valid_photos);
        }
        // Verified badge only when the buyer really bought this product.
        $is_verified = false;
        if (!empty($a['verified']) && function_exists('wc_customer_bought_product')) {
            $is_verified = (bool) wc_customer_bought_product($email, 0, $pid);
        }
        update_comment_meta($cid, 'verified', $is_verified ? 1 : 0);
    } else {
        $is_verified = false;
    }

    return [
        'review_id'      => $cid,
        'product_id'     => $pid,
        'rating'         => $rating,
        'verified'       => $is_verified,
        'photos'         => $valid_photos,
        'photos_dropped' => max(0, $dropped),
    ];
}

/**
 * List approved reviews (optionally per product / verified-only / photos-only).
 * limit default 50, cap 200. Returns shaped rows incl. photo thumbnail urls.
 */
function wpultra_rvx_list_reviews(array $a): array {
    if (!function_exists('get_comments')) { return []; }
    $limit = (int) ($a['limit'] ?? 50);
    if ($limit < 1) { $limit = 50; }
    if ($limit > 200) { $limit = 200; }

    $q = [
        'type'   => 'review',
        'status' => 'approve',
        'number' => $limit,
    ];
    $pid = (int) ($a['product_id'] ?? 0);
    if ($pid > 0) { $q['post_id'] = $pid; }
    else { $q['post_type'] = 'product'; }

    $rows = [];
    foreach ((array) get_comments($q) as $c) {
        $cid = (int) $c->comment_ID;
        $rating = 0;
        $verified = false;
        $photo_ids = [];
        if (function_exists('get_comment_meta')) {
            $rating = (int) get_comment_meta($cid, 'rating', true);
            $verified = (string) get_comment_meta($cid, 'verified', true) === '1';
            $meta_photos = get_comment_meta($cid, 'wpultra_review_photos', true);
            if (is_array($meta_photos)) { $photo_ids = wpultra_rvx_clean_photo_ids($meta_photos); }
        }
        if (!empty($a['verified_only']) && !$verified) { continue; }
        if (!empty($a['with_photos_only']) && empty($photo_ids)) { continue; }

        $photos = [];
        if (function_exists('wp_get_attachment_image_url')) {
            foreach ($photo_ids as $aid) {
                $url = wp_get_attachment_image_url($aid, 'thumbnail');
                if (is_string($url) && $url !== '') { $photos[] = ['id' => $aid, 'url' => $url]; }
            }
        }
        $rows[] = [
            'id'         => $cid,
            'product_id' => (int) $c->comment_post_ID,
            'product'    => function_exists('get_the_title') ? (string) get_the_title((int) $c->comment_post_ID) : '',
            'author'     => (string) $c->comment_author,
            'rating'     => $rating,
            'verified'   => $verified,
            'content'    => (string) $c->comment_content,
            'date'       => (string) $c->comment_date,
            'photos'     => $photos,
        ];
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// Runtime: Q&A
// ---------------------------------------------------------------------------

/**
 * Ask a question on a product (public-safe: tags stripped, capped 1000 chars,
 * lands in moderation — comment_approved 0). Returns array or WP_Error.
 */
function wpultra_rvx_qa_ask(array $a) {
    if (!function_exists('wp_insert_comment')) {
        return wpultra_err('wp_unavailable', 'Comment API not available.');
    }
    $pid = (int) ($a['product_id'] ?? 0);
    if ($pid <= 0 || !function_exists('get_post') || !($post = get_post($pid)) || $post->post_type !== 'product') {
        return wpultra_err('invalid_product', 'product_id does not resolve to a product.');
    }
    $question = wpultra_rvx_clean_question((string) ($a['question'] ?? ''));
    if ($question === '') {
        return wpultra_err('missing_question', 'question must not be empty (tags are stripped).');
    }
    $email = trim((string) ($a['author_email'] ?? ''));
    if ($email !== '' && function_exists('is_email') && !is_email($email)) {
        return wpultra_err('invalid_email', 'author_email must be a valid email address.');
    }
    $author = wpultra_rvx_clean_question((string) ($a['author_name'] ?? ''), 100);
    if ($author === '') { $author = 'Guest'; }

    $cid = wp_insert_comment([
        'comment_post_ID'      => $pid,
        'comment_author'       => $author,
        'comment_author_email' => $email,
        'comment_content'      => $question,
        'comment_type'         => 'wpultra_question',
        'comment_approved'     => 0, // pending moderation by default
        'comment_parent'       => 0,
    ]);
    if (!$cid || is_wp_error($cid)) {
        return wpultra_err('insert_failed', 'wp_insert_comment failed.');
    }
    return ['question_id' => (int) $cid, 'product_id' => $pid, 'status' => 'pending'];
}

/**
 * Answer a question (admin path via the ability — auto-approved).
 * Returns array or WP_Error.
 */
function wpultra_rvx_qa_answer(array $a) {
    if (!function_exists('wp_insert_comment') || !function_exists('get_comment')) {
        return wpultra_err('wp_unavailable', 'Comment API not available.');
    }
    $qid = (int) ($a['question_id'] ?? 0);
    $question = $qid > 0 ? get_comment($qid) : null;
    if (!$question || (string) $question->comment_type !== 'wpultra_question') {
        return wpultra_err('invalid_question', 'question_id does not resolve to a wpultra_question comment.');
    }
    $answer = wpultra_rvx_clean_question((string) ($a['answer'] ?? ''));
    if ($answer === '') {
        return wpultra_err('missing_answer', 'answer must not be empty (tags are stripped).');
    }
    $author = wpultra_rvx_clean_question((string) ($a['author_name'] ?? ''), 100);
    if ($author === '') { $author = 'Store'; }

    $cid = wp_insert_comment([
        'comment_post_ID'  => (int) $question->comment_post_ID,
        'comment_author'   => $author,
        'comment_content'  => $answer,
        'comment_type'     => 'wpultra_answer',
        'comment_approved' => 1, // created via the ability = admin answering
        'comment_parent'   => $qid,
    ]);
    if (!$cid || is_wp_error($cid)) {
        return wpultra_err('insert_failed', 'wp_insert_comment failed.');
    }
    return ['answer_id' => (int) $cid, 'question_id' => $qid, 'product_id' => (int) $question->comment_post_ID];
}

/** Fetch a product's Q&A comments as flat rows for wpultra_rvx_qa_tree(). */
function wpultra_rvx_qa_flat_rows(int $product_id): array {
    if (!function_exists('get_comments') || $product_id <= 0) { return []; }
    $comments = get_comments([
        'post_id'  => $product_id,
        'type__in' => ['wpultra_question', 'wpultra_answer'],
        'status'   => 'all',
        'number'   => 0,
        'orderby'  => 'comment_date_gmt',
        'order'    => 'ASC',
    ]);
    $rows = [];
    foreach ((array) $comments as $c) {
        $rows[] = [
            'id'       => (int) $c->comment_ID,
            'parent'   => (int) $c->comment_parent,
            'type'     => (string) $c->comment_type,
            'content'  => (string) $c->comment_content,
            'author'   => (string) $c->comment_author,
            'date'     => (string) $c->comment_date,
            'approved' => (string) $c->comment_approved === '1',
        ];
    }
    return $rows;
}

/** Moderate a Q&A comment: approve | spam | trash. Returns array or WP_Error. */
function wpultra_rvx_qa_moderate(int $id, string $decision) {
    if (!function_exists('get_comment')) {
        return wpultra_err('wp_unavailable', 'Comment API not available.');
    }
    $c = $id > 0 ? get_comment($id) : null;
    if (!$c || !in_array((string) $c->comment_type, ['wpultra_question', 'wpultra_answer'], true)) {
        return wpultra_err('invalid_qa_comment', 'id does not resolve to a wpultra_question/wpultra_answer comment.');
    }
    $ok = false;
    if ($decision === 'approve' && function_exists('wp_set_comment_status')) {
        $ok = (bool) wp_set_comment_status($id, 'approve');
    } elseif ($decision === 'spam' && function_exists('wp_spam_comment')) {
        $ok = (bool) wp_spam_comment($id);
    } elseif ($decision === 'trash' && function_exists('wp_trash_comment')) {
        $ok = (bool) wp_trash_comment($id);
    } else {
        return wpultra_err('invalid_decision', 'decision must be approve, spam, or trash.');
    }
    if (!$ok) {
        return wpultra_err('moderate_failed', "Could not $decision comment $id.");
    }
    return ['id' => $id, 'decision' => $decision, 'type' => (string) $c->comment_type];
}

// ---------------------------------------------------------------------------
// Runtime: stats
// ---------------------------------------------------------------------------

/** Live review + Q&A stats for one product (or store-wide when $product_id null/0). */
function wpultra_rvx_stats_live(int $product_id = 0): array {
    $rows = [];
    if (function_exists('get_comments')) {
        $q = ['type' => 'review', 'status' => 'approve', 'number' => 0];
        if ($product_id > 0) { $q['post_id'] = $product_id; }
        else { $q['post_type'] = 'product'; }
        foreach ((array) get_comments($q) as $c) {
            $cid = (int) $c->comment_ID;
            $photos = function_exists('get_comment_meta') ? get_comment_meta($cid, 'wpultra_review_photos', true) : [];
            $rows[] = [
                'rating'     => function_exists('get_comment_meta') ? (int) get_comment_meta($cid, 'rating', true) : 0,
                'verified'   => function_exists('get_comment_meta') && (string) get_comment_meta($cid, 'verified', true) === '1',
                'has_photos' => is_array($photos) && !empty($photos),
            ];
        }
    }
    $stats = wpultra_rvx_stats($rows);

    $pending = 0;
    if (function_exists('get_comments')) {
        $q = ['type' => 'wpultra_question', 'status' => 'hold', 'count' => true];
        if ($product_id > 0) { $q['post_id'] = $product_id; }
        else { $q['post_type'] = 'product'; }
        $pending = (int) get_comments($q);
    }
    $stats['pending_questions'] = $pending;
    $stats['scope'] = $product_id > 0 ? 'product' : 'store';
    if ($product_id > 0) { $stats['product_id'] = $product_id; }
    return $stats;
}
