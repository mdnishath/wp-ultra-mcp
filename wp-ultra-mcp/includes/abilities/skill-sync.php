<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once WPULTRA_DIR . 'includes/skills/sync.php';

wp_register_ability('wpultra/skill-sync', [
    'label'       => __('Skill Marketplace Sync', 'wp-ultra-mcp'),
    'description' => __('Import community skill .md docs from any public GitHub repo folder. action: preview (list remote .md files + new/exists status vs local skills) or import (fetch + validate + write via the skill store; existing slugs skipped unless overwrite:true). Always preview first. repo must be \'owner/name\'; path is an optional subfolder; branch defaults to main. only[] restricts import to specific slugs.', 'wp-ultra-mcp'),
    'category'    => 'skills',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['preview', 'import']],
            'repo'      => ['type' => 'string'],
            'path'      => ['type' => 'string'],
            'branch'    => ['type' => 'string'],
            'only'      => ['type' => 'array', 'items' => ['type' => 'string']],
            'overwrite' => ['type' => 'boolean'],
            'confirm'   => ['type' => 'boolean'],
        ],
        'required'             => ['action', 'repo'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'repo'     => ['type' => 'string'],
            'path'     => ['type' => 'string'],
            'branch'   => ['type' => 'string'],
            'files'    => ['type' => 'array'],
            'imported' => ['type' => 'array'],
            'skipped'  => ['type' => 'array'],
            'errors'   => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_skill_sync_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_skill_sync_cb(array $input) {
    $action = (string) ($input['action'] ?? '');
    $repo   = trim((string) ($input['repo'] ?? ''));
    if ($repo === '') { return wpultra_err('bad_repo', 'repo is required, e.g. "owner/name".'); }
    $path   = (string) ($input['path'] ?? '');
    $branch = (string) ($input['branch'] ?? 'main');
    if ($branch === '') { $branch = 'main'; }

    if ($action === 'preview') {
        $result = wpultra_skillsync_preview($repo, $path, $branch);
        if (is_wp_error($result)) { return $result; }
        return wpultra_ok($result);
    }

    if ($action === 'import') {
        $overwrite = (bool) ($input['overwrite'] ?? false);
        if ($overwrite && ($input['confirm'] ?? false) !== true) {
            return wpultra_err('confirm_required', 'Importing with overwrite:true requires confirm:true (it can replace existing skills).');
        }
        $only = [];
        foreach ((array) ($input['only'] ?? []) as $s) {
            $s = sanitize_title((string) $s);
            if ($s !== '') { $only[] = $s; }
        }
        $result = wpultra_skillsync_import($repo, $path, $branch, $only, $overwrite);
        if (is_wp_error($result)) {
            wpultra_audit_log('skill-sync', "import failed from $repo/$path@$branch: " . $result->get_error_message(), false);
            return $result;
        }
        $summary = sprintf(
            'import from %s/%s@%s: %d imported, %d skipped, %d errors',
            $repo, $path, $branch,
            count($result['imported']), count($result['skipped']), count($result['errors'])
        );
        wpultra_audit_log('skill-sync', $summary, empty($result['errors']));
        return wpultra_ok(array_merge(['repo' => $repo, 'path' => $path, 'branch' => $branch], $result));
    }

    return wpultra_err('bad_action', "action must be 'preview' or 'import', got '$action'.");
}
