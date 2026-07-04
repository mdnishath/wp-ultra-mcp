<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_fulfillment/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/woocommerce/fulfillment.php';

/* ============================================================
 * esc helper
 * ============================================================ */

it('esc escapes HTML special chars including quotes', function () {
    assert_eq('&lt;b&gt;&amp;&quot;&#039;', wpultra_fulfill_esc('<b>&"\''));
});

/* ============================================================
 * carriers + tracking URLs
 * ============================================================ */

it('carriers map contains all documented carriers', function () {
    $carriers = wpultra_fulfill_carriers();
    foreach (['ups', 'fedex', 'dhl', 'usps', 'royal-mail', 'tnt', 'aramex', 'pathao', 'steadfast', 'redx', 'custom'] as $c) {
        assert_true(isset($carriers[$c]), "carrier $c present");
        assert_true(isset($carriers[$c]['label']), "carrier $c has label");
        assert_true(array_key_exists('url_template', $carriers[$c]), "carrier $c has url_template");
    }
});

it('every non-custom carrier template contains the {number} placeholder', function () {
    foreach (wpultra_fulfill_carriers() as $slug => $def) {
        if ($slug === 'custom') { continue; }
        assert_contains('{number}', $def['url_template'], "template for $slug");
    }
});

it('tracking_url resolves each known carrier with the number substituted', function () {
    foreach (wpultra_fulfill_carriers() as $slug => $def) {
        if ($slug === 'custom') { continue; }
        $url = wpultra_fulfill_tracking_url($slug, 'TRACK123');
        assert_contains('TRACK123', $url, "url for $slug contains number");
        assert_true(str_starts_with($url, 'https://'), "url for $slug is https");
        assert_true(!str_contains($url, '{number}'), "placeholder replaced for $slug");
    }
});

it('tracking_url rawurlencodes the number', function () {
    $url = wpultra_fulfill_tracking_url('ups', 'AB 1/2&x');
    assert_contains('AB%201%2F2%26x', $url);
});

it('tracking_url custom uses the caller-supplied template', function () {
    $url = wpultra_fulfill_tracking_url('custom', 'XYZ9', 'https://courier.example/t/{number}?src=wp');
    assert_eq('https://courier.example/t/XYZ9?src=wp', $url);
});

it('tracking_url custom without {number} in the template returns empty', function () {
    assert_eq('', wpultra_fulfill_tracking_url('custom', 'XYZ9', 'https://courier.example/t/'));
    assert_eq('', wpultra_fulfill_tracking_url('custom', 'XYZ9', ''));
});

it('tracking_url unknown carrier returns empty', function () {
    assert_eq('', wpultra_fulfill_tracking_url('carrier-pigeon', 'X1'));
    assert_eq('', wpultra_fulfill_tracking_url('', 'X1'));
});

it('tracking_url empty/whitespace number returns empty', function () {
    assert_eq('', wpultra_fulfill_tracking_url('ups', ''));
    assert_eq('', wpultra_fulfill_tracking_url('ups', '   '));
});

/* ============================================================
 * status workflow — transitions matrix
 * ============================================================ */

it('allowed_transitions matches the documented workflow map', function () {
    assert_eq([
        'pending'    => ['processing', 'cancelled', 'on-hold'],
        'on-hold'    => ['processing', 'cancelled'],
        'processing' => ['shipped', 'completed', 'cancelled', 'refunded'],
        'shipped'    => ['completed', 'refunded'],
        'completed'  => ['refunded'],
    ], wpultra_fulfill_allowed_transitions());
});

it('can_transition allows the happy fulfillment path', function () {
    assert_true(wpultra_fulfill_can_transition('pending', 'processing'));
    assert_true(wpultra_fulfill_can_transition('processing', 'shipped'));
    assert_true(wpultra_fulfill_can_transition('shipped', 'completed'));
    assert_true(wpultra_fulfill_can_transition('completed', 'refunded'));
    assert_true(wpultra_fulfill_can_transition('on-hold', 'processing'));
});

it('can_transition blocks skips and backwards moves', function () {
    assert_eq(false, wpultra_fulfill_can_transition('pending', 'completed'));
    assert_eq(false, wpultra_fulfill_can_transition('pending', 'shipped'));
    assert_eq(false, wpultra_fulfill_can_transition('shipped', 'processing'));
    assert_eq(false, wpultra_fulfill_can_transition('completed', 'shipped'));
    assert_eq(false, wpultra_fulfill_can_transition('on-hold', 'shipped'));
});

it('can_transition strips wc- prefixes on both sides', function () {
    assert_true(wpultra_fulfill_can_transition('wc-processing', 'wc-shipped'));
    assert_true(wpultra_fulfill_can_transition('wc-pending', 'on-hold'));
    assert_true(wpultra_fulfill_can_transition('shipped', 'wc-refunded'));
});

it('can_transition returns false for unknown statuses', function () {
    assert_eq(false, wpultra_fulfill_can_transition('bogus', 'processing'));
    assert_eq(false, wpultra_fulfill_can_transition('processing', 'bogus'));
    assert_eq(false, wpultra_fulfill_can_transition('refunded', 'completed'), 'refunded is terminal');
    assert_eq(false, wpultra_fulfill_can_transition('cancelled', 'processing'), 'cancelled is terminal');
    assert_eq(false, wpultra_fulfill_can_transition('', ''));
});

it('can_transition returns false for a no-op same-status move', function () {
    assert_eq(false, wpultra_fulfill_can_transition('processing', 'processing'));
});

/* ============================================================
 * wc_order_statuses insertion
 * ============================================================ */

it('insert_shipped_status places wc-shipped directly after wc-processing', function () {
    $in = ['wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-completed' => 'Completed'];
    $out = wpultra_fulfill_insert_shipped_status($in);
    assert_eq(['wc-pending', 'wc-processing', 'wc-shipped', 'wc-completed'], array_keys($out));
    assert_eq('Shipped', $out['wc-shipped']);
});

it('insert_shipped_status appends when wc-processing is absent and is idempotent', function () {
    $out = wpultra_fulfill_insert_shipped_status(['wc-pending' => 'Pending payment']);
    assert_eq(['wc-pending', 'wc-shipped'], array_keys($out));

    // Already present → unchanged (no duplicate / no move).
    $again = wpultra_fulfill_insert_shipped_status($out);
    assert_eq($out, $again);
});

/* ============================================================
 * packing slip HTML
 * ============================================================ */

function fulfillment_test_slip_data(array $overrides = []): array {
    $data = [
        'store' => ['name' => 'Ultra Store', 'address' => "1 Main St\nDhaka 1207"],
        'order' => [
            'number'                 => '1042',
            'date'                   => '2026-07-03',
            'shipping_name'          => 'Jane Doe',
            'shipping_address_lines' => ['42 Test Ave', 'Springfield 12345', 'US'],
            'billing_email'          => 'jane@example.com',
            'items'                  => [
                ['name' => 'Blue Widget', 'sku' => 'BW-001', 'qty' => 3],
                ['name' => 'Red Gadget', 'sku' => 'RG-777', 'qty' => 1],
            ],
            'customer_note'          => 'Leave at the back door',
            'tracking'               => ['carrier' => 'dhl', 'number' => 'JD0146'],
        ],
    ];
    foreach ($overrides as $k => $v) { $data['order'][$k] = $v; }
    return $data;
}

it('packing slip contains store name, order number, ship-to and date', function () {
    $html = wpultra_fulfill_packing_slip_html(fulfillment_test_slip_data());
    assert_contains('Ultra Store', $html);
    assert_contains('#1042', $html);
    assert_contains('2026-07-03', $html);
    assert_contains('Jane Doe', $html);
    assert_contains('42 Test Ave', $html);
    assert_contains('PACKING SLIP', $html);
});

it('packing slip includes item names, SKUs and quantities but no prices', function () {
    $html = wpultra_fulfill_packing_slip_html(fulfillment_test_slip_data());
    assert_contains('Blue Widget', $html);
    assert_contains('BW-001', $html);
    assert_contains('RG-777', $html);
    assert_contains('SKU', $html);
    assert_contains('Qty', $html);
    // Total qty = 3 + 1 = 4.
    assert_contains('Total items', $html);
    assert_contains('>4<', $html);
    assert_true(!str_contains($html, 'Price'), 'a packing slip is a pick list — no prices');
});

it('packing slip escapes an XSS item name', function () {
    $html = wpultra_fulfill_packing_slip_html(fulfillment_test_slip_data([
        'items' => [['name' => '<script>alert(1)</script>', 'sku' => '"><img src=x>', 'qty' => 2]],
    ]));
    assert_true(!str_contains($html, '<script>'), 'raw script tag must not appear');
    assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    assert_contains('&quot;&gt;&lt;img src=x&gt;', $html);
});

it('packing slip escapes store name, note and address', function () {
    $data = fulfillment_test_slip_data(['customer_note' => '<b>ring twice</b>']);
    $data['store']['name'] = 'Shop <script>x</script>';
    $html = wpultra_fulfill_packing_slip_html($data);
    assert_true(!str_contains($html, '<script>x</script>'));
    assert_contains('Shop &lt;script&gt;x&lt;/script&gt;', $html);
    assert_contains('&lt;b&gt;ring twice&lt;/b&gt;', $html);
});

it('packing slip shows tracking when set and omits the block when null', function () {
    $with = wpultra_fulfill_packing_slip_html(fulfillment_test_slip_data());
    assert_contains('Tracking:', $with);
    assert_contains('JD0146', $with);

    $without = wpultra_fulfill_packing_slip_html(fulfillment_test_slip_data(['tracking' => null]));
    assert_true(!str_contains($without, 'Tracking:'), 'no tracking block when unset');
});

it('packing slip includes the customer note and omits the box when empty', function () {
    $with = wpultra_fulfill_packing_slip_html(fulfillment_test_slip_data());
    assert_contains('Customer note:', $with);
    assert_contains('Leave at the back door', $with);

    $without = wpultra_fulfill_packing_slip_html(fulfillment_test_slip_data(['customer_note' => '']));
    assert_true(!str_contains($without, 'Customer note:'));
});

it('bulk packing slips render one section per order with a page-break marker', function () {
    $a = fulfillment_test_slip_data(['number' => '2001']);
    $b = fulfillment_test_slip_data(['number' => '2002']);
    $html = wpultra_fulfill_packing_slips_html([$a, $b]);
    assert_contains('#2001', $html);
    assert_contains('#2002', $html);
    assert_contains('page-break-after: always', $html);
    assert_eq(2, substr_count($html, 'class="wpultra-slip"'), 'two slip sections');
    // Standalone print-ready document.
    assert_contains('<!DOCTYPE html>', $html);
    assert_contains('</body></html>', $html);
});

it('single packing slip is a complete standalone HTML document', function () {
    $html = wpultra_fulfill_packing_slip_html(fulfillment_test_slip_data());
    assert_contains('<!DOCTYPE html>', $html);
    assert_contains('<meta charset="utf-8">', $html);
    assert_eq(1, substr_count($html, 'class="wpultra-slip"'));
});

/* ============================================================
 * notify email template
 * ============================================================ */

it('notify_subject includes the order number', function () {
    assert_eq('Your order #1042 has shipped', wpultra_fulfill_notify_subject('1042'));
});

it('notify_html contains order number, carrier, tracking number and link', function () {
    $html = wpultra_fulfill_notify_html([
        'order_number'  => '1042',
        'carrier'       => 'dhl',
        'carrier_label' => 'DHL',
        'number'        => 'JD0146',
        'url'           => 'https://www.dhl.com/en/express/tracking.html?AWB=JD0146',
        'store_name'    => 'Ultra Store',
    ]);
    assert_contains('#1042', $html);
    assert_contains('DHL', $html);
    assert_contains('JD0146', $html);
    assert_contains('href="https://www.dhl.com/en/express/tracking.html?AWB=JD0146"', $html);
    assert_contains('Track your package', $html);
    assert_contains('Ultra Store', $html);
});

it('notify_html escapes hostile values', function () {
    $html = wpultra_fulfill_notify_html([
        'order_number'  => '10"42',
        'carrier'       => 'custom',
        'carrier_label' => '<script>evil()</script>',
        'number'        => '<img src=x onerror=1>',
        'url'           => '',
        'store_name'    => 'A&B "Shop"',
    ]);
    assert_true(!str_contains($html, '<script>'), 'no raw script');
    assert_true(!str_contains($html, '<img src=x'), 'no raw img injection');
    assert_contains('&lt;script&gt;evil()&lt;/script&gt;', $html);
    assert_contains('&lt;img src=x onerror=1&gt;', $html);
    assert_contains('A&amp;B &quot;Shop&quot;', $html);
});

it('notify_html omits the tracking link for empty or non-http urls', function () {
    $no_url = wpultra_fulfill_notify_html(['order_number' => '1', 'carrier' => 'custom', 'number' => 'X', 'url' => '', 'store_name' => 'S']);
    assert_true(!str_contains($no_url, 'Track your package'));
    assert_true(!str_contains($no_url, '<a href'));

    $js_url = wpultra_fulfill_notify_html(['order_number' => '1', 'carrier' => 'custom', 'number' => 'X', 'url' => 'javascript:alert(1)', 'store_name' => 'S']);
    assert_true(!str_contains($js_url, 'javascript:alert'), 'javascript: url must not render as a link');
});

run_tests();
