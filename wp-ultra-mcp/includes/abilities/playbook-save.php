<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/playbook-save', [
    'label'       => __('Save Playbook', 'wp-ultra-mcp'),
    'description' => __('Create or replace a named, reusable playbook. Provide a `slug` and a `document` (JSON with name/description/inputs/steps, or a ```json fenced markdown block). Run it later with wpultra/playbook-run {slug}. The document is validated before saving.', 'wp-ultra-mcp'),
    'category'    => 'playbooks',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'slug'     => ['type' => 'string'],
            'document' => ['type' => 'string'],
        ],
        'required'             => ['slug', 'document'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'slug'    => ['type' => 'string'],
            'steps'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_playbook_save_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_playbook_save_cb(array $input) {
    $slug = sanitize_title((string) ($input['slug'] ?? ''));
    if ($slug === '') { return wpultra_err('bad_slug', 'A valid slug is required.'); }
    $doc = (string) ($input['document'] ?? '');

    $parsed = wpultra_playbook_parse($doc);
    if ($parsed === null) { return wpultra_err('bad_document', 'document must be JSON (or a ```json block) containing a steps array.'); }
    $valid = wpultra_playbook_validate_steps($parsed['steps']);
    if ($valid !== true) { return wpultra_err('invalid_playbook', (string) $valid); }

    $id = wpultra_playbook_save($slug, $doc, $parsed['description']);
    if ($id === 0) { return wpultra_err('save_failed', 'Could not save the playbook.'); }
    wpultra_audit_log('playbook-save', "saved playbook $slug (" . count($parsed['steps']) . ' steps)', true);
    return wpultra_ok(['slug' => $slug, 'steps' => count($parsed['steps'])]);
}
