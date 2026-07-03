<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * wpultra/site-backup — full-site backup + restore (Roadmap #22).
 *
 * One ability, action-dispatched, backed by the engine in includes/system/backup.php.
 * Actions: create | list | restore | delete.
 */

/** Dispatch a site-backup ability call to the backup engine. @return array|WP_Error */
function wpultra_site_backup_execute(array $input) {
    if (!function_exists('wpultra_backup_create')) {
        return wpultra_err('backup_engine_unavailable', 'The backup engine (includes/system/backup.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');
    $name   = (string) ($input['name'] ?? '');

    switch ($action) {
        case 'create':
            $opts = ['skip_uploads' => ($input['skip_uploads'] ?? false) === true];
            return wpultra_backup_create($name, $opts);

        case 'list':
            return wpultra_backup_list();

        case 'restore':
            $parts = array_values(array_filter(array_map('strval', (array) ($input['parts'] ?? []))));
            return wpultra_backup_restore($name, $parts, ($input['confirm'] ?? false) === true);

        case 'delete':
            return wpultra_backup_delete($name, ($input['confirm'] ?? false) === true);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use one of: create, list, restore, delete.");
    }
}

wp_register_ability('wpultra/site-backup', [
    'label'       => __('Full-Site Backup', 'wp-ultra-mcp'),
    'description' => __('Full-site backup + restore: database and wp-content files bundled under uploads/wpultra-backups/<name>/ (db.sql.gz + files.zip, protected with index.php + .htaccess deny). actions: `create` (name required; optional skip_uploads to omit the uploads tree; excludes backup/snapshot/export/cache/node_modules dirs; @set_time_limit(300), 50k-file cap), `list`, `restore` (name required; optional parts[] subset of ["db","files"], default both; requires confirm:true — WARNING: restoring files overwrites wp-content including plugins mid-request), `delete` (name required; requires confirm:true).', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['create', 'list', 'restore', 'delete']],
            'name'         => ['type' => 'string', 'description' => 'Backup name ([a-z0-9-]). Required for create/restore/delete.'],
            'skip_uploads' => ['type' => 'boolean', 'description' => 'create: omit the wp-content/uploads tree from files.zip.'],
            'parts'        => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['db', 'files']], 'description' => 'restore: which parts to restore (default both).'],
            'confirm'      => ['type' => 'boolean', 'description' => 'Required (true) for restore and delete.'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'name'        => ['type' => 'string'],
            'path'        => ['type' => 'string'],
            'db_bytes'    => ['type' => 'integer'],
            'files_bytes' => ['type' => 'integer'],
            'file_count'  => ['type' => 'integer'],
            'backups'     => ['type' => 'array'],
            'count'       => ['type' => 'integer'],
            'results'     => ['type' => 'object'],
            'all_ok'      => ['type' => 'boolean'],
            'deleted'     => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_site_backup_execute',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
