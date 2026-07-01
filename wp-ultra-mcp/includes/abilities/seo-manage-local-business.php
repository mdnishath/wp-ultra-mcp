<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/seo-manage-local-business', [
    'label'       => __('SEO: Manage Local Business', 'wp-ultra-mcp'),
    'description' => __('Read or set LocalBusiness structured data (NAP, geo, hours, price range) rendered as JSON-LD on the home page. action: get|set. Fields: name, type, url, phone, price_range, logo, street, city, region, postal, country, lat, lng, hours[].', 'wp-ultra-mcp'),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'set']],
            'name' => ['type' => 'string'], 'type' => ['type' => 'string'], 'url' => ['type' => 'string'], 'phone' => ['type' => 'string'],
            'price_range' => ['type' => 'string'], 'logo' => ['type' => 'string'], 'street' => ['type' => 'string'], 'city' => ['type' => 'string'],
            'region' => ['type' => 'string'], 'postal' => ['type' => 'string'], 'country' => ['type' => 'string'], 'lat' => ['type' => 'string'], 'lng' => ['type' => 'string'], 'hours' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'local' => ['type' => 'object'], 'preview' => ['type' => 'object']], 'required' => ['success']],
    'execute_callback'    => 'wpultra_seo_manage_local_business_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_manage_local_business_cb(array $input) {
    $action = (string) ($input['action'] ?? 'get');
    if ($action === 'set') {
        $data = $input;
        unset($data['action']);
        $clean = wpultra_seo_local_set($data);
        wpultra_audit_log('seo-manage-local-business', 'set', true);
        return wpultra_ok(['local' => $clean, 'preview' => wpultra_seo_build_local_jsonld($clean)]);
    }
    $d = wpultra_seo_local_get();
    return wpultra_ok(['local' => $d, 'preview' => $d ? wpultra_seo_build_local_jsonld($d) : []]);
}
