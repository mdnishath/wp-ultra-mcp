<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine so this ability works regardless of the
// bootstrap loader's engine-file list (mirrors woo-bulk-edit / security-harden).
if (!function_exists('wpultra_gdpr_merge_config') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/compliance/gdpr.php')) {
    require_once WPULTRA_DIR . 'includes/compliance/gdpr.php';
}

wp_register_ability('wpultra/gdpr-tools', [
    'label'       => __('GDPR & Cookie Consent Tools', 'wp-ultra-mcp'),
    'description' => __(
        'GDPR / privacy compliance toolkit: configure the front-end cookie-consent banner and orchestrate WordPress core data-subject requests (export / erase) plus a privacy audit. '
        . 'Pick an action:'
        . ' (1) configure-banner — enable/disable and style the consent banner. Fields (all optional, merged onto the current config): enabled(bool), position(bottom|top), message, accept_label, decline_label, policy_url, categories[] (any of necessary|analytics|marketing — necessary is always forced on), theme{bg,text,accent} (hex colors), cookie_name (default wpultra_consent), cookie_days (default 180). Reversible, no confirm needed. The banner renders in wp_footer with namespaced .wpultra-cc-* markup; Accept/Decline writes a JSON category->bool cookie and exposes window.wpultraConsent(category) so other scripts can gate themselves.'
        . ' (2) banner-status — return the current banner config (read-only).'
        . ' (3) export-data {email} — run every registered wp_privacy_personal_data_exporters callback for that email and aggregate the exported data groups (read-only; needs plugins that register exporters to return anything).'
        . ' (4) erase-data {email, confirm:true} — run every registered wp_privacy_personal_data_erasers callback for that email (IRREVERSIBLE — requires confirm:true). Returns items_removed / items_retained / messages.'
        . ' (5) create-request {email, type: export|erase} — mint the OFFICIAL WordPress async request (a user_request post) which drives the confirm-email + admin-approval flow via wp_create_user_request(); preferred for a compliant paper trail.'
        . ' (6) privacy-audit — evaluate a privacy checklist (privacy policy page assigned, HTTPS, banner on, exporter/eraser counts, comment-cookie opt-in) into ok/warn findings.'
        . ' (7) list-requests {limit?} — list recent user_request posts with status (request-pending|request-confirmed|request-completed|request-failed). '
        . 'Examples: {action:"configure-banner", enabled:true, position:"bottom", categories:["necessary","analytics"], theme:{accent:"#0ea5e9"}} = turn the banner on with analytics opt-in. '
        . '{action:"export-data", email:"jane@example.com"} = gather everything the registered exporters know about jane. '
        . '{action:"create-request", email:"jane@example.com", type:"erase"} = start the official erasure flow. '
        . '{action:"privacy-audit"} = compliance health check.',
        'wp-ultra-mcp'
    ),
    'category'    => 'compliance',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['configure-banner', 'banner-status', 'export-data', 'erase-data', 'create-request', 'privacy-audit', 'list-requests'],
            ],
            'email'   => ['type' => 'string'],
            'type'    => ['type' => 'string', 'enum' => ['export', 'erase']],
            'confirm' => ['type' => 'boolean'],
            'limit'   => ['type' => 'integer'],
            // configure-banner fields:
            'enabled'       => ['type' => 'boolean'],
            'position'      => ['type' => 'string', 'enum' => ['bottom', 'top']],
            'message'       => ['type' => 'string'],
            'accept_label'  => ['type' => 'string'],
            'decline_label' => ['type' => 'string'],
            'policy_url'    => ['type' => 'string'],
            'categories'    => [
                'type'  => 'array',
                'items' => ['type' => 'string', 'enum' => ['necessary', 'analytics', 'marketing']],
            ],
            'theme' => [
                'type'       => 'object',
                'properties' => [
                    'bg'     => ['type' => 'string'],
                    'text'   => ['type' => 'string'],
                    'accent' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'cookie_name' => ['type' => 'string'],
            'cookie_days' => ['type' => 'integer'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
            'config'  => ['type' => 'object'],
            'result'  => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_gdpr_tools_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_gdpr_tools_cb(array $input) {
    if (!function_exists('wpultra_gdpr_merge_config')) {
        return wpultra_err('gdpr_engine_missing', 'The GDPR engine (includes/compliance/gdpr.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');
    if ($action === '') {
        return wpultra_err('missing_action', 'Provide an action: configure-banner|banner-status|export-data|erase-data|create-request|privacy-audit|list-requests.');
    }

    switch ($action) {
        case 'configure-banner': {
            $current = wpultra_gdpr_get_config();
            $patch = [];
            foreach (['enabled', 'position', 'message', 'accept_label', 'decline_label', 'policy_url', 'categories', 'theme'] as $k) {
                if (array_key_exists($k, $input)) { $patch[$k] = $input[$k]; }
            }
            $top = [];
            foreach (['cookie_name', 'cookie_days'] as $k) {
                if (array_key_exists($k, $input)) { $top[$k] = $input[$k]; }
            }
            $merged = wpultra_gdpr_merge_config($current, $patch, $top);
            wpultra_gdpr_save_config($merged);
            wpultra_audit_log('gdpr-tools', 'configure-banner enabled=' . ($merged['banner']['enabled'] ? '1' : '0') . ' position=' . $merged['banner']['position'], true);
            return wpultra_ok(['action' => $action, 'config' => $merged]);
        }

        case 'banner-status': {
            return wpultra_ok(['action' => $action, 'config' => wpultra_gdpr_get_config()]);
        }

        case 'export-data': {
            $email = (string) ($input['email'] ?? '');
            $res = wpultra_gdpr_export_personal_data($email);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('gdpr-tools', "export-data email=$email groups={$res['group_count']} items={$res['item_count']}", true);
            return wpultra_ok(['action' => $action, 'result' => $res]);
        }

        case 'erase-data': {
            $email = (string) ($input['email'] ?? '');
            $confirm = ($input['confirm'] ?? false) === true;
            $res = wpultra_gdpr_erase_personal_data($email, $confirm);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('gdpr-tools', "erase-data email=$email removed=" . ($res['items_removed'] ? '1' : '0') . ' retained=' . ($res['items_retained'] ? '1' : '0'), true);
            return wpultra_ok(['action' => $action, 'result' => $res]);
        }

        case 'create-request': {
            $email = (string) ($input['email'] ?? '');
            $type = (string) ($input['type'] ?? '');
            if (!in_array($type, ['export', 'erase'], true)) {
                return wpultra_err('invalid_type', "create-request requires type: export|erase (got '$type').");
            }
            $res = wpultra_gdpr_create_request($email, $type);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('gdpr-tools', "create-request email=$email type=$type id={$res['request_id']}", true);
            return wpultra_ok(['action' => $action, 'result' => $res]);
        }

        case 'privacy-audit': {
            $res = wpultra_gdpr_privacy_audit();
            return wpultra_ok(['action' => $action, 'result' => $res]);
        }

        case 'list-requests': {
            $limit = isset($input['limit']) ? (int) $input['limit'] : 20;
            $requests = wpultra_gdpr_list_requests($limit);
            return wpultra_ok(['action' => $action, 'result' => ['requests' => $requests, 'count' => count($requests)]]);
        }
    }

    return wpultra_err('unknown_action', "Unknown action '$action'.");
}
