<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once __DIR__ . '/../system/fonts.php';

wp_register_ability('wpultra/manage-fonts', [
    'label'       => __('Manage Fonts', 'wp-ultra-mcp'),
    'description' => __(
        'Upload font files and register @font-face declarations so custom fonts are usable by family name '
        . 'anywhere on the site (Elementor, block editor, theme CSS) — before this ability only token font NAMES '
        . 'existed with no actual @font-face backing them. actions: '
        . '"list" (default, read-only) — every registered font + its faces + a preview of the generated @font-face CSS. '
        . '"add-upload" (confirm-gated) — `family` + `faces[]`, each face given EITHER `file_url` (fetched) OR `base64` '
        . '(decoded), plus optional `weight` (default 400), `style` (default normal), and `format` (woff2/woff/ttf/otf — '
        . 'inferred from file_url\'s extension when omitted). Every face is validated against a strict font-extension '
        . 'whitelist and size-capped before being stored under uploads/wpultra-fonts/; any failure rolls back files '
        . 'already written for that call. '
        . '"add-google-selfhost" (confirm-gated) — `family` + `weights[]` builds the Google Fonts CSS2 API URL, fetches '
        . 'Google\'s CSS, parses out the real font-file URLs, and downloads + self-hosts each one (GDPR-friendly: the '
        . 'visitor\'s browser never talks to Google). Best-effort per face; fails only if none could be secured. '
        . '"delete" (confirm-gated) — `id` removes the stored font files plus the registry entry. '
        . 'Registered fonts are automatically re-rendered as a <style id="wpultra-fonts"> block on every front-end '
        . 'page via wp_head, so no further wiring is needed to start using a family name once registered.',
        'wp-ultra-mcp'
    ),
    'category'    => 'content',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list', 'add-upload', 'add-google-selfhost', 'delete'], 'default' => 'list'],
            'id'      => ['type' => 'string'],
            'family'  => ['type' => 'string'],
            'weights' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'faces'   => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'weight'   => ['type' => 'integer'],
                        'style'    => ['type' => 'string'],
                        'file_url' => ['type' => 'string'],
                        'base64'   => ['type' => 'string'],
                        'format'   => ['type' => 'string', 'enum' => ['woff2', 'woff', 'ttf', 'otf']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'fonts'       => ['type' => 'array'],
            'css_preview' => ['type' => 'string'],
            'font'        => ['type' => 'object'],
            'deleted'     => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_manage_fonts_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_manage_fonts_cb(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $result = wpultra_fonts_list();
        return wpultra_ok(['fonts' => $result['fonts'], 'css_preview' => $result['css_preview']]);
    }

    $confirm = ($input['confirm'] ?? false) === true;
    if (!$confirm) {
        return wpultra_err('confirm_required', "Action '$action' writes files and/or changes the font registry. Re-run with confirm:true.");
    }

    if ($action === 'add-upload') {
        $family = (string) ($input['family'] ?? '');
        $faces  = is_array($input['faces'] ?? null) ? $input['faces'] : [];
        $result = wpultra_fonts_add_upload($family, $faces);
        if (is_wp_error($result)) {
            wpultra_audit_log('manage-fonts', "add-upload '$family' failed: " . $result->get_error_message(), false);
            return $result;
        }
        wpultra_audit_log('manage-fonts', "add-upload '$family' (" . count($result['faces']) . ' faces)', true);
        return wpultra_ok(['font' => $result]);
    }

    if ($action === 'add-google-selfhost') {
        $family  = (string) ($input['family'] ?? '');
        $weights = is_array($input['weights'] ?? null) ? $input['weights'] : [];
        $result = wpultra_fonts_add_google_selfhost($family, $weights);
        if (is_wp_error($result)) {
            wpultra_audit_log('manage-fonts', "add-google-selfhost '$family' failed: " . $result->get_error_message(), false);
            return $result;
        }
        wpultra_audit_log('manage-fonts', "add-google-selfhost '$family' (" . count($result['faces']) . ' faces)', true);
        return wpultra_ok(['font' => $result]);
    }

    if ($action === 'delete') {
        $id = (string) ($input['id'] ?? '');
        if ($id === '') { return wpultra_err('missing_id', 'id is required for delete.'); }
        $result = wpultra_fonts_delete($id);
        if (is_wp_error($result)) {
            wpultra_audit_log('manage-fonts', "delete '$id' failed: " . $result->get_error_message(), false);
            return $result;
        }
        wpultra_audit_log('manage-fonts', "delete '$id'", true);
        return wpultra_ok($result);
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
