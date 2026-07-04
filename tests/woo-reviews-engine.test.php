<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_rvx/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/reviews-engine.php';

/* ============================================================
 * wpultra_rvx_clean_photo_ids — sanitize photo id lists.
 * ============================================================ */

it('clean_photo_ids drops non-positive and non-numeric values', function () {
    assert_eq([3, 7], wpultra_rvx_clean_photo_ids([0, -4, 3, 'abc', 7, null]));
});

it('clean_photo_ids dedupes (first occurrence wins) and reindexes', function () {
    assert_eq([5, 9, 2], wpultra_rvx_clean_photo_ids([5, 9, 5, 2, 9]));
});

it('clean_photo_ids caps at 5 by default', function () {
    assert_eq([1, 2, 3, 4, 5], wpultra_rvx_clean_photo_ids([1, 2, 3, 4, 5, 6, 7, 8]));
});

it('clean_photo_ids casts numeric strings and honors a custom cap', function () {
    assert_eq([10, 11], wpultra_rvx_clean_photo_ids(['10', '11', '12'], 2));
});

it('clean_photo_ids returns empty for an empty or all-junk list', function () {
    assert_eq([], wpultra_rvx_clean_photo_ids([]));
    assert_eq([], wpultra_rvx_clean_photo_ids([0, -1, 'x', [], null]));
});

/* ============================================================
 * wpultra_rvx_filter_candidates — review-request candidate filtering.
 * ============================================================ */

function rvx_order(int $id, string $email, array $pids, bool $requested = false): array {
    return ['order_id' => $id, 'email' => $email, 'name' => "Customer $id", 'requested' => $requested, 'product_ids' => $pids];
}

it('filter_candidates drops orders already flagged requested', function () {
    $orders = [rvx_order(1, 'a@x.com', [10]), rvx_order(2, 'b@x.com', [20], true)];
    $out = wpultra_rvx_filter_candidates($orders, []);
    assert_eq(1, count($out));
    assert_eq(1, $out[0]['order_id']);
});

it('filter_candidates strips already-reviewed products from an order', function () {
    $orders = [rvx_order(1, 'a@x.com', [10, 20, 30])];
    $out = wpultra_rvx_filter_candidates($orders, ['a@x.com|20']);
    assert_eq(1, count($out));
    assert_eq([10, 30], $out[0]['product_ids']);
});

it('filter_candidates drops an order whose every product was reviewed', function () {
    $orders = [rvx_order(1, 'a@x.com', [10, 20]), rvx_order(2, 'b@x.com', [10])];
    $out = wpultra_rvx_filter_candidates($orders, ['a@x.com|10', 'a@x.com|20']);
    assert_eq(1, count($out));
    assert_eq(2, $out[0]['order_id']);
});

it('filter_candidates matches reviewed pairs case-insensitively on email', function () {
    $orders = [rvx_order(1, 'Alice@X.com', [10])];
    $out = wpultra_rvx_filter_candidates($orders, ['alice@x.com|10']);
    assert_eq([], $out);
});

it('filter_candidates only strips the matching customer, not everyone who bought the product', function () {
    $orders = [rvx_order(1, 'a@x.com', [10]), rvx_order(2, 'b@x.com', [10])];
    $out = wpultra_rvx_filter_candidates($orders, ['a@x.com|10']);
    assert_eq(1, count($out));
    assert_eq('b@x.com', $out[0]['email']);
});

it('filter_candidates drops orders with an empty email and dedupes product ids', function () {
    $orders = [rvx_order(1, '  ', [10]), rvx_order(2, 'b@x.com', [10, 10, 0, -3, 20])];
    $out = wpultra_rvx_filter_candidates($orders, []);
    assert_eq(1, count($out));
    assert_eq([10, 20], $out[0]['product_ids']);
});

it('filter_candidates passes through name and order_id untouched', function () {
    $out = wpultra_rvx_filter_candidates([rvx_order(42, 'c@x.com', [7])], []);
    assert_eq(42, $out[0]['order_id']);
    assert_eq('Customer 42', $out[0]['name']);
});

/* ============================================================
 * wpultra_rvx_request_html — email template escaping.
 * ============================================================ */

it('request_html escapes an XSS product name', function () {
    $html = wpultra_rvx_request_html([
        'name'     => 'Jane',
        'products' => [['name' => '<script>alert(1)</script>', 'url' => 'https://x.com/p/1#reviews']],
    ]);
    assert_true(strpos($html, '<script>') === false, 'raw script tag must not survive');
    assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
});

it('request_html escapes the customer name and a quote-injection url', function () {
    $html = wpultra_rvx_request_html([
        'name'     => '<b>Jane</b>',
        'products' => [['name' => 'Mug', 'url' => 'https://x.com/p/1" onmouseover="evil()']],
    ]);
    assert_true(strpos($html, '<b>Jane</b>') === false, 'raw bold tag must not survive');
    assert_contains('&lt;b&gt;Jane&lt;/b&gt;', $html);
    assert_true(strpos($html, '" onmouseover="') === false, 'attribute breakout must be escaped');
    assert_contains('&quot; onmouseover=&quot;', $html);
});

it('request_html links each product to its review anchor', function () {
    $html = wpultra_rvx_request_html([
        'name'     => 'Jane',
        'products' => [
            ['name' => 'Mug', 'url' => 'https://x.com/p/mug#reviews'],
            ['name' => 'Cap', 'url' => 'https://x.com/p/cap#reviews'],
        ],
    ]);
    assert_contains('<a href="https://x.com/p/mug#reviews">Mug</a>', $html);
    assert_contains('<a href="https://x.com/p/cap#reviews">Cap</a>', $html);
});

it('request_html handles a missing name and escapes the site name', function () {
    $html = wpultra_rvx_request_html(['products' => [['name' => 'Mug']], 'site' => 'A & B <Shop>']);
    assert_contains('<p>Hi,</p>', $html);
    assert_contains('A &amp; B &lt;Shop&gt;', $html);
});

/* ============================================================
 * wpultra_rvx_qa_tree — Q&A nesting + moderation filtering.
 * ============================================================ */

function rvx_row(int $id, int $parent, string $type, $approved, string $content = ''): array {
    return [
        'id' => $id, 'parent' => $parent, 'type' => $type,
        'content' => $content !== '' ? $content : "$type $id",
        'author' => "author$id", 'date' => '2026-07-01 00:00:00', 'approved' => $approved,
    ];
}

it('qa_tree nests answers under their parent question', function () {
    $flat = [
        rvx_row(1, 0, 'wpultra_question', true, 'Does it fit?'),
        rvx_row(2, 1, 'wpultra_answer', true, 'Yes it does.'),
        rvx_row(3, 1, 'wpultra_answer', true, 'Perfectly.'),
    ];
    $tree = wpultra_rvx_qa_tree($flat);
    assert_eq(1, count($tree));
    assert_eq('Does it fit?', $tree[0]['question']);
    assert_eq(2, count($tree[0]['answers']));
    assert_eq('Yes it does.', $tree[0]['answers'][0]['answer']);
    assert_eq('Perfectly.', $tree[0]['answers'][1]['answer']);
});

it('qa_tree drops orphan answers (parent missing from the set)', function () {
    $flat = [
        rvx_row(1, 0, 'wpultra_question', true),
        rvx_row(9, 999, 'wpultra_answer', true), // parent never existed
    ];
    $tree = wpultra_rvx_qa_tree($flat);
    assert_eq(1, count($tree));
    assert_eq([], $tree[0]['answers']);
});

it('qa_tree excludes pending questions by default (and their answers become orphans)', function () {
    $flat = [
        rvx_row(1, 0, 'wpultra_question', false, 'Pending Q'),
        rvx_row(2, 1, 'wpultra_answer', true, 'Answer to pending'),
        rvx_row(3, 0, 'wpultra_question', true, 'Live Q'),
    ];
    $tree = wpultra_rvx_qa_tree($flat);
    assert_eq(1, count($tree));
    assert_eq('Live Q', $tree[0]['question']);
});

it('qa_tree include_pending surfaces pending questions and answers with status pending', function () {
    $flat = [
        rvx_row(1, 0, 'wpultra_question', false, 'Pending Q'),
        rvx_row(2, 1, 'wpultra_answer', false, 'Pending A'),
    ];
    $tree = wpultra_rvx_qa_tree($flat, true);
    assert_eq(1, count($tree));
    assert_eq('pending', $tree[0]['status']);
    assert_eq(1, count($tree[0]['answers']));
    assert_eq('pending', $tree[0]['answers'][0]['status']);
});

it('qa_tree excludes a pending answer under an approved question unless include_pending', function () {
    $flat = [
        rvx_row(1, 0, 'wpultra_question', true),
        rvx_row(2, 1, 'wpultra_answer', false),
    ];
    assert_eq([], wpultra_rvx_qa_tree($flat)[0]['answers']);
    assert_eq(1, count(wpultra_rvx_qa_tree($flat, true)[0]['answers']));
});

it('qa_tree accepts string/int approved flags and bare type names', function () {
    $flat = [
        rvx_row(1, 0, 'question', '1'),
        rvx_row(2, 1, 'answer', 1),
    ];
    $tree = wpultra_rvx_qa_tree($flat);
    assert_eq(1, count($tree));
    assert_eq('approved', $tree[0]['status']);
    assert_eq(1, count($tree[0]['answers']));
});

it('qa_tree preserves question input order and ignores junk rows', function () {
    $flat = [
        'junk',
        rvx_row(5, 0, 'wpultra_question', true, 'First'),
        rvx_row(3, 0, 'wpultra_question', true, 'Second'),
    ];
    $tree = wpultra_rvx_qa_tree($flat);
    assert_eq(['First', 'Second'], [$tree[0]['question'], $tree[1]['question']]);
});

/* ============================================================
 * wpultra_rvx_qa_render_html — shortcode output escaping.
 * ============================================================ */

it('qa_render_html escapes question, answer, and author content', function () {
    $tree = wpultra_rvx_qa_tree([
        rvx_row(1, 0, 'wpultra_question', true, '<img src=x onerror=alert(1)>'),
        ['id' => 2, 'parent' => 1, 'type' => 'wpultra_answer', 'content' => '<script>x</script>', 'author' => '<i>Store</i>', 'date' => '', 'approved' => true],
    ]);
    $html = wpultra_rvx_qa_render_html($tree);
    assert_true(strpos($html, '<img') === false, 'raw img must not survive');
    assert_true(strpos($html, '<script>') === false, 'raw script must not survive');
    assert_contains('&lt;img src=x onerror=alert(1)&gt;', $html);
    assert_contains('&lt;i&gt;Store&lt;/i&gt;', $html);
});

/* ============================================================
 * wpultra_rvx_stats — aggregate math.
 * ============================================================ */

it('stats averages ratings to 2 decimal places', function () {
    $s = wpultra_rvx_stats([
        ['rating' => 5, 'verified' => true, 'has_photos' => false],
        ['rating' => 4, 'verified' => false, 'has_photos' => true],
        ['rating' => 4, 'verified' => true, 'has_photos' => true],
    ]);
    assert_eq(3, $s['review_count']);
    assert_eq(4.33, $s['avg_rating']);
    assert_eq(2, $s['verified_count']);
    assert_eq(2, $s['photo_review_count']);
});

it('stats returns zeros (no division by zero) for an empty list', function () {
    $s = wpultra_rvx_stats([]);
    assert_eq(0, $s['review_count']);
    assert_eq(0.0, $s['avg_rating']);
    assert_eq(0, $s['verified_count']);
    assert_eq(0, $s['photo_review_count']);
});

it('stats tolerates junk rows and missing keys', function () {
    $s = wpultra_rvx_stats(['junk', ['verified' => 1], ['rating' => 5]]);
    assert_eq(2, $s['review_count']);
    assert_eq(2.5, $s['avg_rating']);
    assert_eq(1, $s['verified_count']);
});

/* ============================================================
 * wpultra_rvx_clean_question — public-safe Q&A text.
 * ============================================================ */

it('clean_question strips tags and trims', function () {
    assert_eq('Is this dishwasher safe?', wpultra_rvx_clean_question('  <b>Is this</b> dishwasher <a href="x">safe?</a>  '));
});

it('clean_question caps at 1000 characters by default', function () {
    $q = wpultra_rvx_clean_question(str_repeat('a', 1500));
    assert_eq(1000, strlen($q));
});

it('clean_question returns empty when the input was only tags/whitespace', function () {
    assert_eq('', wpultra_rvx_clean_question('  <br><hr>  '));
});

/* ============================================================
 * wpultra_rvx_clamp_days — request window clamping.
 * ============================================================ */

it('clamp_days defaults invalid input to 7 and caps at 90', function () {
    assert_eq(7, wpultra_rvx_clamp_days(0));
    assert_eq(7, wpultra_rvx_clamp_days(-3));
    assert_eq(14, wpultra_rvx_clamp_days(14));
    assert_eq(90, wpultra_rvx_clamp_days(365));
});

run_tests();
