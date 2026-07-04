<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/headless-woo', [
    'label'       => __('Headless: Woo Storefront', 'wp-ultra-mcp'),
    'description' => __('WooGraphQL storefront scaffold for a fully headless store: SSG product grid (/shop) + single product pages with an Add-to-Cart client component, and a client-side /cart page with WooCommerce session handling (woocommerce-session header persisted + replayed) and GraphQL checkout (cash-on-delivery starter). Returns the file manifest to write into the Next.js frontend (extends headless-scaffold). Requires WooCommerce + WooGraphQL (headless-setup installs it) and the frontend origin CORS-allowed (browser cart calls).', 'wp-ultra-mcp'),
    'category'    => 'headless',
    'input_schema'  => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'files'      => ['type' => 'array'],
            'file_count' => ['type' => 'integer'],
            'routes'     => ['type' => 'array'],
            'next_steps' => ['type' => 'array'],
            'warnings'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_headless_woo_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_headless_woo_cb(array $input) {
    $detected = wpultra_headless_detect();
    if (!class_exists('WooCommerce')) {
        return wpultra_err('woo_missing', 'WooCommerce is not active on this site.');
    }
    if (($detected['woographql'] ?? null) === null) {
        return wpultra_err('woographql_missing', 'WooGraphQL is not active — run headless-setup (it installs the Woo addon when WooCommerce is present).');
    }

    $perms = wpultra_headless_permalinks();
    $route = (string) apply_filters('graphql_endpoint', 'graphql');
    $ctx = [
        'endpoint'   => $perms['pretty'] ? trailingslashit(home_url()) . $route : add_query_arg('graphql', 'true', trailingslashit(home_url())),
        'site_title' => (string) get_option('blogname', ''),
        'site_url'   => home_url(),
        'name'       => '',
    ];
    $files = wpultra_headless_woo_manifest($ctx);

    $warnings = [];
    $cors = wpultra_headless_shape_cors(get_option('wpultra_headless_cors', []));
    if ($cors['origins'] === []) {
        $warnings[] = 'No CORS origins configured — the browser cart/checkout calls will fail; run headless-setup with the frontend origin.';
    }
    if (!function_exists('wc_get_payment_gateway_by_order')) { /* soft check only */ }
    $cod_enabled = false;
    try {
        $gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
        $cod_enabled = isset($gateways['cod']) && $gateways['cod']->enabled === 'yes';
    } catch (\Throwable $e) {}
    if (!$cod_enabled) {
        $warnings[] = 'The Cash-on-Delivery gateway is disabled — the starter checkout uses paymentMethod "cod"; enable it (woo-manage-payment-gateway) or swap the paymentMethod in app/cart/page.tsx.';
    }

    return wpultra_ok([
        'files'      => $files,
        'file_count' => count($files),
        'routes'     => ['/shop', '/shop/[slug]', '/cart'],
        'next_steps' => [
            'Write the files into the frontend repo (extends the headless-scaffold starter).',
            'npm run build — /shop and each product prerender from live store data.',
            'Cart + checkout run in the browser via the WooCommerce GraphQL session (test with the site\'s products).',
            'Wire headless-revalidate so product edits refresh the grid (tag: products).',
        ],
        'warnings'   => $warnings,
    ]);
}
