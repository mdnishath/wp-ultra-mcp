<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Sugar over trigger-create for roadmap #27: pipe form submissions (WITH their
 * submitted field values) to any webhook / CRM (Zapier / Make / n8n / HubSpot …),
 * optionally filtered to a single form/plugin and reshaped via a payload template.
 * Builds an ordinary form_submitted -> webhook trigger under the hood.
 */

wp_register_ability('wpultra/form-forward', [
    'label'       => __('Forward Form Submissions to a Webhook / CRM', 'wp-ultra-mcp'),
    'description' => __('Pipe form submissions (WITH field values) to any webhook or CRM (Zapier / Make / n8n / HubSpot). Sugar over an event trigger. action:create registers a form_submitted -> webhook trigger POSTing the full payload (event, site, and data.fields — the submitted field values); optionally filter to one plugin (cf7|wpforms|gravity|fluent) and/or one form id, and reshape the body with a template map of {key: "text {data.fields.Email}"} tokens. action:list shows only these form-forward triggers; action:delete removes one by id. Delivery is async so a slow CRM never blocks the form submission.', 'wp-ultra-mcp'),
    'category'    => 'triggers',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'   => ['type' => 'string', 'enum' => ['list', 'create', 'delete']],
            'url'      => ['type' => 'string', 'description' => 'create: the webhook endpoint to POST submissions to.'],
            'secret'   => ['type' => 'string', 'description' => 'create: optional HMAC signing secret (X-WPUltra-Signature).'],
            'plugin'   => ['type' => 'string', 'enum' => ['cf7', 'wpforms', 'gravity', 'fluent'], 'description' => 'create: only forward this form plugin.'],
            'form'     => ['type' => 'string', 'description' => 'create: only forward this form id.'],
            'template' => ['type' => 'object', 'description' => 'create: optional body reshape — map of key => string with {data.*}/{event}/{site} tokens. Omit to POST the full payload incl. fields.'],
            'label'    => ['type' => 'string'],
            'id'       => ['type' => 'integer', 'description' => 'delete: trigger id.'],
            'confirm'  => ['type' => 'boolean', 'description' => 'delete: must be true to remove.'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'id'       => ['type' => 'integer'],
            'triggers' => ['type' => 'array'],
            'changed'  => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_form_forward_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_form_forward_cb(array $input) {
    $action = (string) ($input['action'] ?? '');

    if ($action === 'list') {
        $rows = [];
        foreach (wpultra_triggers_load() as $t) {
            if ((string) ($t['event'] ?? '') !== 'form_submitted') { continue; }
            if ((string) ($t['action_type'] ?? '') !== 'webhook') { continue; }
            $shape = wpultra_triggers_shape($t);
            $shape['filter'] = isset($t['filter']) && is_array($t['filter']) ? $t['filter'] : [];
            $shape['has_template'] = isset($t['template']) && is_array($t['template']) && $t['template'] !== [];
            $rows[] = $shape;
        }
        return wpultra_ok(['triggers' => array_values($rows)]);
    }

    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) { return wpultra_err('missing_id', 'id is required to delete.'); }
        if (empty($input['confirm'])) { return wpultra_err('confirm_required', 'pass confirm:true to delete trigger #' . $id . '.'); }
        $changed = wpultra_triggers_delete($id);
        if ($changed) { wpultra_audit_log('form-forward', "deleted form-forward trigger #$id", true); }
        return wpultra_ok(['id' => $id, 'changed' => $changed]);
    }

    if ($action === 'create') {
        $url = (string) ($input['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) { return wpultra_err('invalid_url', 'create requires an http(s) url.'); }

        $filter = [];
        if (isset($input['plugin']) && (string) $input['plugin'] !== '') { $filter['plugin'] = (string) $input['plugin']; }
        if (isset($input['form']) && (string) $input['form'] !== '')     { $filter['form']   = (string) $input['form']; }

        $template = (isset($input['template']) && is_array($input['template'])) ? $input['template'] : null;

        $label = (string) ($input['label'] ?? '');
        if ($label === '') {
            $label = 'Form -> ' . preg_replace('#^https?://#i', '', $url);
        }

        $def = [
            'event'       => 'form_submitted',
            'action_type' => 'webhook',
            'url'         => $url,
            'label'       => $label,
        ];
        if (isset($input['secret']) && (string) $input['secret'] !== '') { $def['secret'] = (string) $input['secret']; }
        if ($filter !== []) { $def['filter'] = $filter; }
        if ($template !== null && $template !== []) { $def['template'] = $template; }

        $valid = wpultra_triggers_validate($def);
        if ($valid !== true) { return wpultra_err('invalid_trigger', (string) $valid); }

        $id = wpultra_triggers_create($def);
        wpultra_audit_log('form-forward', "form-forward trigger #$id -> $url", true);
        return wpultra_ok(['id' => $id]);
    }

    return wpultra_err('bad_action', "action must be one of: list, create, delete.");
}
