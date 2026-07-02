<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/form-get-entries', [
    'label'       => __('Get Form Entries', 'wp-ultra-mcp'),
    'description' => __('Reads submitted entries for a form, with each entry\'s fields flattened to a label=>value map. `plugin` selects the source (auto-detected otherwise). Supports `per_page`/`page` and `search`. Returns an error when the plugin stores no entries (CF7 without Flamingo, WPForms Lite). For CF7 the results are scoped to the requested form via Flamingo\'s "contact-form-<id>" channel term when present; if that channel term is missing, all Flamingo inbound entries are returned as a fallback.', 'wp-ultra-mcp'),
    'category'    => 'forms',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'form_id'  => ['type' => 'integer'],
            'plugin'   => ['type' => 'string', 'enum' => ['cf7', 'wpforms', 'gravity', 'fluent']],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
            'search'   => ['type' => 'string'],
        ],
        'required'             => ['form_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'entries' => ['type' => 'array'],
            'plugin'  => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_form_get_entries',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_form_get_entries(array $input) {
    $form_id = (int) ($input['form_id'] ?? 0);
    if ($form_id <= 0) { return wpultra_err('missing_form_id', 'form_id is required.'); }
    $explicit = isset($input['plugin']) ? (string) $input['plugin'] : '';
    $driver   = wpultra_forms_driver($explicit);
    if (is_wp_error($driver)) { return $driver; }

    $per_page = max(1, min(200, (int) ($input['per_page'] ?? 20)));
    $page     = max(1, (int) ($input['page'] ?? 1));
    $search   = (string) ($input['search'] ?? '');

    $fn = "wpultra_forms_{$driver}_get_entries";
    if (!function_exists($fn)) {
        return wpultra_err('forms_unavailable', "No entry reader for plugin '{$driver}'.");
    }
    $entries = $fn($form_id, $per_page, $page, $search);
    if (is_wp_error($entries)) { return $entries; }
    return wpultra_ok(['entries' => array_values((array) $entries), 'plugin' => $driver]);
}
