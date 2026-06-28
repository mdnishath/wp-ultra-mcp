<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-apply-design-tokens', [
    'label'       => __('Elementor: Apply Design Tokens', 'wp-ultra-mcp'),
    'description' => __('Create Elementor Variables (color/font/size) from a perceived reference\'s palette, fonts, and sizes, and return refs to use in atomic settings as {"$$type","value"}.', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'colors' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'hex' => ['type' => 'string']],
                'required' => ['title', 'hex'], 'additionalProperties' => false,
            ]],
            'fonts' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'family' => ['type' => 'string']],
                'required' => ['title', 'family'], 'additionalProperties' => false,
            ]],
            'sizes' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['role' => ['type' => 'string'], 'title' => ['type' => 'string'], 'size' => ['type' => 'number'], 'unit' => ['type' => 'string']],
                'required' => ['title', 'size'], 'additionalProperties' => false,
            ]],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'colors'  => ['type' => 'array'],
            'fonts'   => ['type' => 'array'],
            'sizes'   => ['type' => 'array'],
            'notes'   => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_apply_design_tokens',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_apply_design_tokens(array $input) {
    $brief = [
        'colors' => $input['colors'] ?? null,
        'fonts'  => $input['fonts'] ?? null,
        'sizes'  => $input['sizes'] ?? null,
    ];
    if ($brief['colors'] === null && $brief['fonts'] === null && $brief['sizes'] === null) {
        return wpultra_err('empty_brief', 'Provide at least one of colors, fonts, or sizes.');
    }
    $built = wpultra_el_build_token_plan($brief);
    if (!empty($built['errors'])) {
        return wpultra_err('bad_brief', 'Token brief has errors: ' . implode('; ', $built['errors']), ['errors' => $built['errors']]);
    }
    if ($built['plan'] === []) { return wpultra_err('empty_brief', 'No valid tokens to create.'); }

    if (!wpultra_el_variables_active()) {
        $persisted = wpultra_el_variables_enable();
        if (!wpultra_el_variables_active()) {
            return $persisted
                ? wpultra_err('variables_enabling', 'Elementor "e_variables" experiment has just been enabled for you — re-run this action (Elementor applies the change on the next request).')
                : wpultra_err('variables_inactive', 'Elementor "e_variables" experiment is not active and could not be auto-enabled. Enable it in Elementor > Settings > Features.');
        }
    }

    $famKey = ['color' => 'colors', 'font' => 'fonts', 'size' => 'sizes'];
    $out = ['colors' => [], 'fonts' => [], 'sizes' => []];
    $notes = [];
    foreach ($built['plan'] as $ins) {
        $res = wpultra_el_variables_create($ins['type'], $ins['title'], $ins['value']);
        if (is_wp_error($res)) { $notes[] = $ins['title'] . ': ' . $res->get_error_message(); continue; }
        $var = is_array($res) ? ($res['variable'] ?? $res) : $res;
        $id = is_array($var) ? (string) ($var['id'] ?? ($var['_id'] ?? '')) : '';
        if ($id === '') { $notes[] = $ins['title'] . ': created but variable id could not be extracted.'; continue; }
        $bucket = $famKey[$ins['family']] ?? null;
        if ($bucket === null) { $notes[] = $ins['title'] . ': unknown token family ' . $ins['family'] . '.'; continue; }
        $out[$bucket][] = ['title' => $ins['title'], 'id' => $id, 'ref' => ['$$type' => $ins['type'], 'value' => $id]];
    }
    $payload = ['colors' => $out['colors'], 'fonts' => $out['fonts'], 'sizes' => $out['sizes']];
    if ($notes) { $payload['notes'] = implode(' | ', $notes); }
    wpultra_audit_log('elementor-apply-design-tokens', count($built['plan']) . ' token(s); ' . count($notes) . ' failed', $notes === []);
    return wpultra_ok($payload);
}
