<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * wpultra/site-migrate — full host → host site migration (Roadmap G2).
 *
 * One ability, action-dispatched, backed by the engine in includes/system/migration.php
 * (which itself reuses the backup packager in backup.php and the serialized-safe
 * search-replace engine in siteops.php).
 *
 * Actions: export | check | import | list | delete-package.
 */

// Defensively load the engine (bootstrap normally requires it in the system group).
if (!function_exists('wpultra_migrate_package')) {
    $__mig = __DIR__ . '/../system/migration.php';
    if (is_readable($__mig)) { require_once $__mig; }
}

/** Dispatch a site-migrate ability call to the migration engine. @return array|WP_Error */
function wpultra_site_migrate_execute(array $input) {
    if (!function_exists('wpultra_migrate_package')) {
        return wpultra_err('migrate_engine_unavailable', 'The migration engine (includes/system/migration.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'export':
            return wpultra_migrate_package([
                'name'            => (string) ($input['package'] ?? ''),
                'include_uploads' => ($input['include_uploads'] ?? true) !== false,
            ]);

        case 'check':
            return wpultra_migrate_check([
                'package'  => (string) ($input['package'] ?? ''),
                'manifest' => is_array($input['manifest'] ?? null) ? $input['manifest'] : null,
            ]);

        case 'import':
            return wpultra_migrate_import([
                'package' => (string) ($input['package'] ?? ''),
                'dry_run' => array_key_exists('dry_run', $input) ? ($input['dry_run'] === true) : true,
                'confirm' => ($input['confirm'] ?? false) === true,
            ]);

        case 'list':
            return wpultra_migrate_list();

        case 'delete-package':
            return wpultra_migrate_delete((string) ($input['package'] ?? ''), ($input['confirm'] ?? false) === true);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use one of: export, check, import, list, delete-package.");
    }
}

wp_register_ability('wpultra/site-migrate', [
    'label'       => __('Site Migration (host → host)', 'wp-ultra-mcp'),
    'description' => __(
        'Move a whole WordPress site from one host to another: package → transfer → check → import, with serialized-safe URL rewriting. '
        . 'WORKFLOW: (1) On the OLD host run action:export — this builds a portable package under wp-content/uploads/wpultra-backups/<package>/ containing db.sql.gz + files.zip (full wp-content) + manifest.json (which records the source home_url, siteurl, abspath, WordPress & PHP versions, table prefix, active plugins and theme). '
        . '(2) Move that entire package directory to the NEW host at the same uploads/wpultra-backups/<package>/ path (SFTP/rsync — this ability does not transfer files across hosts for you). '
        . '(3) On the NEW host run action:check — a safe, read-only readiness report comparing the source manifest to this site (PHP downgrade → blocker, WordPress major mismatch → warn, differing table prefix → warn+note, plugins missing here → warn) plus a preview of the URL search-replace pairs that import will apply. '
        . '(4) On the NEW host run action:import with dry_run:true to preview the restore + URL-rewrite plan, then dry_run:false and confirm:true to actually restore the DB and files (via the backup engine) and rewrite the source URLs to this site\'s URLs across posts/postmeta/options/comments using the serialized-data-safe search-replace engine (http + https + protocol-relative //host variants, trailing-slash-safe; no-op when the URLs already match). '
        . 'ACTIONS: `export` (optional package name [a-z0-9-]; optional include_uploads:false to omit the media library on huge sites — you then migrate uploads separately); `check` (package OR an inline manifest object — no writes); `import` (package required; dry_run defaults true; live import requires dry_run:false AND confirm:true and is REFUSED if the readiness check has a blocker); `list`; `delete-package` (package + confirm:true). '
        . 'CAVEATS: import OVERWRITES this site\'s database and wp-content (including this plugin, mid-request) — always run check first and keep an independent backup. If the source table prefix differs, update the destination wp-config.php $table_prefix after import or the site won\'t find its tables. Large sites can exceed PHP time/memory limits during zip/restore (@set_time_limit is raised but a hard host cap still applies); use include_uploads:false and migrate media out-of-band for very large media libraries. Path/abspath differences are recorded but NOT auto-rewritten (URL rewriting is; on-disk absolute paths in the DB are rare and left for manual review). '
        . 'EXAMPLES: {action:"export"} then move the dir; {action:"check",package:"migrate-20260704-101500"}; {action:"import",package:"migrate-20260704-101500",dry_run:true}; then {action:"import",package:"migrate-20260704-101500",dry_run:false,confirm:true}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'          => ['type' => 'string', 'enum' => ['export', 'check', 'import', 'list', 'delete-package']],
            'package'         => ['type' => 'string', 'description' => 'Package name ([a-z0-9-]). export: optional (auto-named migrate-<date> if omitted). Required for import and delete-package; optional for check.'],
            'include_uploads' => ['type' => 'boolean', 'description' => 'export: default true. Set false to omit wp-content/uploads (the media library) from the package on very large sites.'],
            'manifest'        => ['type' => 'object', 'description' => 'check: an inline source manifest object (as returned by export) to compare against this site, instead of reading a package\'s manifest.json.'],
            'dry_run'         => ['type' => 'boolean', 'description' => 'import: default true. true previews the restore + URL-rewrite plan without changing anything; false performs the live import (also requires confirm:true).'],
            'confirm'         => ['type' => 'boolean', 'description' => 'Required (true) for a live import (with dry_run:false) and for delete-package.'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'action'        => ['type' => 'string'],
            'package'       => ['type' => 'string'],
            'path'          => ['type' => 'string'],
            'manifest'      => ['type' => 'object'],
            'manifest_path' => ['type' => 'string'],
            'db_bytes'      => ['type' => 'integer'],
            'files_bytes'   => ['type' => 'integer'],
            'file_count'    => ['type' => 'integer'],
            'download_note' => ['type' => 'string'],
            'findings'      => ['type' => 'array'],
            'has_blocker'   => ['type' => 'boolean'],
            'url_pairs'     => ['type' => 'array'],
            'plan'          => ['type' => 'object'],
            'restore'       => ['type' => 'object'],
            'url_rewrite'   => ['type' => 'array'],
            'packages'      => ['type' => 'array'],
            'count'         => ['type' => 'integer'],
            'deleted'       => ['type' => 'boolean'],
            'dry_run'       => ['type' => 'boolean'],
            'warning'       => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_site_migrate_execute',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
