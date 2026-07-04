<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * wpultra/backup-schedule — scheduled + off-site backups (Roadmap C3).
 *
 * One action-dispatched ability, backed by includes/system/backup-schedule.php,
 * which REUSES the full-site backup engine (includes/system/backup.php) for the
 * actual DB-dump + zip. Adds a WP-Cron job, local retention, and an off-site push
 * to S3 (AWS SigV4) or Dropbox. Secrets are masked in every response.
 */

// Defensively load the engine so the ability degrades to a clear error, not a fatal.
if (!function_exists('wpultra_bksched_get_config')) {
    $__bksched_engine = __DIR__ . '/../system/backup-schedule.php';
    if (is_file($__bksched_engine)) { require_once $__bksched_engine; }
}

/** Dispatch a backup-schedule ability call. @return array|WP_Error */
function wpultra_backup_schedule_execute(array $input) {
    if (!function_exists('wpultra_bksched_get_config')) {
        return wpultra_err('bksched_engine_unavailable', 'The backup-schedule engine (includes/system/backup-schedule.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'config':
            $current = wpultra_bksched_get_config();
            $patch   = [];
            foreach (['enabled', 'recurrence', 'parts', 'retention', 'max_push_mb', 'destination'] as $k) {
                if (array_key_exists($k, $input)) { $patch[$k] = $input[$k]; }
            }
            $merged = wpultra_bksched_validate_config($current, $patch);
            if (is_wp_error($merged)) { return $merged; }

            // Preserve run bookkeeping across a config merge.
            $merged['last_run']    = $current['last_run']    ?? null;
            $merged['last_status'] = $current['last_status'] ?? null;
            $merged['history']     = $current['history']     ?? [];

            wpultra_bksched_save_config($merged);
            wpultra_audit_log('backup-schedule', 'config enabled=' . (($merged['enabled'] ?? false) ? '1' : '0') . ' dest=' . ($merged['destination']['type'] ?? 'none'), true);

            return wpultra_ok([
                'config'    => wpultra_bksched_shape_config($merged),
                'scheduled' => ($merged['enabled'] ?? false) === true,
            ]);

        case 'status':
            $cfg = wpultra_bksched_get_config();
            $next = function_exists('wp_next_scheduled') ? wp_next_scheduled(WPULTRA_BKSCHED_HOOK) : false;
            return wpultra_ok([
                'config'      => wpultra_bksched_shape_config($cfg),
                'enabled'     => ($cfg['enabled'] ?? false) === true,
                'next_run'    => $next ? gmdate('c', (int) $next) : null,
                'last_run'    => $cfg['last_run'] ?? null,
                'last_status' => $cfg['last_status'] ?? null,
            ]);

        case 'history':
            $cfg = wpultra_bksched_get_config();
            return wpultra_ok([
                'history' => array_values((array) ($cfg['history'] ?? [])),
                'count'   => count((array) ($cfg['history'] ?? [])),
            ]);

        case 'run-now':
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('run_unconfirmed', 'run-now creates a real backup (and may push it off-site). Re-run with confirm: true.');
            }
            if (!function_exists('wpultra_bksched_run_scheduled')) {
                return wpultra_err('bksched_engine_unavailable', 'The scheduled-run handler is not available.');
            }
            $res = wpultra_bksched_run_scheduled();
            return wpultra_ok([
                'name'   => $res['name'] ?? '',
                'status' => $res['status'] ?? '',
                'bytes'  => (int) ($res['bytes'] ?? 0),
                'pushed' => $res['pushed'] ?? 'no',
            ]);

        case 'test-destination':
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('test_unconfirmed', 'test-destination uploads a tiny object to your destination to verify credentials. Re-run with confirm: true.');
            }
            $cfg = wpultra_bksched_get_config();
            $res = wpultra_bksched_test_destination($cfg);
            // Never leak secrets — the engine result is already secret-free.
            return wpultra_ok($res);

        case 'prune-now':
            $res = wpultra_bksched_prune_now();
            return wpultra_ok($res);

        default:
            return wpultra_err('bad_action', "Unknown action '$action'. Use one of: config, status, history, run-now, test-destination, prune-now.");
    }
}

wp_register_ability('wpultra/backup-schedule', [
    'label'       => __('Scheduled + Off-site Backups', 'wp-ultra-mcp'),
    'description' => __('Schedule recurring full-site backups via WP-Cron, keep only the newest N locally (retention), and optionally push each backup off-site to Amazon S3 (AWS Signature V4) or Dropbox. REUSES the full-site backup engine (db.sql.gz + files.zip under uploads/wpultra-backups/). Parts default to db-only (files can be huge — opt in explicitly). actions: `config` (merge+validate settings — enabled, recurrence [daily|weekly], parts {db,files}, retention >=1, max_push_mb, destination {type: none|s3|dropbox, config}; reversible, no confirm), `status` (current config + next/last run; secrets masked), `history` (recent run log, cap 30), `run-now` {confirm:true} (runs the whole routine immediately — creates a REAL backup + applies retention + pushes off-site), `test-destination` {confirm:true} (uploads a tiny object to verify credentials, reports success/failure WITHOUT leaking the secret), `prune-now` (apply retention now, no new backup). CREDENTIALS CAVEAT: destination creds are stored in the wpultra_bksched option — use a DEDICATED, SCOPED credential (an IAM user limited to s3:PutObject on one bucket/prefix, or a scoped Dropbox token), NEVER root keys. Secrets are masked (first2••••last2) in every response and never read back. Google Drive is NOT supported (heavy OAuth refresh flow) — use S3 or Dropbox. S3 config: {bucket, region, access_key, secret_key, prefix?}. Dropbox config: {access_token}. Examples: {action:"config", enabled:true, recurrence:"daily", retention:7, destination:{type:"s3", config:{bucket:"my-backups", region:"us-east-1", access_key:"AKIA...", secret_key:"...", prefix:"site1"}}} then {action:"test-destination", confirm:true} then {action:"run-now", confirm:true}. CAVEAT: WP-Cron only fires on site traffic — low-traffic sites should point a real system cron at wp-cron.php; the push loads the artifact into memory, so honor max_push_mb on large sites.', 'wp-ultra-mcp'),
    'category'    => 'system',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'action'      => ['type' => 'string', 'enum' => ['config', 'status', 'history', 'run-now', 'test-destination', 'prune-now']],
            'enabled'     => ['type' => 'boolean', 'description' => 'config: turn the scheduled backup on/off.'],
            'recurrence'  => ['type' => 'string', 'enum' => ['daily', 'weekly'], 'description' => 'config: cron recurrence (default daily).'],
            'parts'       => [
                'type'       => 'object',
                'properties' => [
                    'db'    => ['type' => 'boolean', 'description' => 'Include the database dump (default true).'],
                    'files' => ['type' => 'boolean', 'description' => 'Include the wp-content file tree (default false — can be large).'],
                ],
                'description' => 'config: which parts to back up.',
            ],
            'retention'   => ['type' => 'integer', 'minimum' => 1, 'description' => 'config: keep the newest N local backups (default 5).'],
            'max_push_mb' => ['type' => 'integer', 'minimum' => 0, 'description' => 'config: skip the off-site push when the file exceeds this many MB (0 = unlimited; default 512).'],
            'destination' => [
                'type'       => 'object',
                'properties' => [
                    'type'   => ['type' => 'string', 'enum' => ['none', 's3', 'dropbox'], 'description' => 'Off-site destination.'],
                    'config' => [
                        'type'       => 'object',
                        'properties' => [
                            'bucket'       => ['type' => 'string', 'description' => 's3: bucket name.'],
                            'region'       => ['type' => 'string', 'description' => 's3: region, e.g. us-east-1.'],
                            'access_key'   => ['type' => 'string', 'description' => 's3: IAM access key id.'],
                            'secret_key'   => ['type' => 'string', 'description' => 's3: IAM secret access key.'],
                            'prefix'       => ['type' => 'string', 'description' => 's3: optional key prefix.'],
                            'access_token' => ['type' => 'string', 'description' => 'dropbox: scoped access token.'],
                        ],
                        'description' => 'Per-destination credentials/config.',
                    ],
                ],
                'description' => 'config: off-site push target.',
            ],
            'confirm'     => ['type' => 'boolean', 'description' => 'Required (true) for run-now and test-destination.'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'config'      => ['type' => 'object'],
            'scheduled'   => ['type' => 'boolean'],
            'enabled'     => ['type' => 'boolean'],
            'next_run'    => ['type' => ['string', 'integer', 'null']],
            'last_run'    => ['type' => ['string', 'integer', 'null']],
            'last_status' => ['type' => ['string', 'null']],
            'history'     => ['type' => 'array'],
            'count'       => ['type' => 'integer'],
            'name'        => ['type' => 'string'],
            'status'      => ['type' => 'string'],
            'bytes'       => ['type' => 'integer'],
            'pushed'      => ['type' => 'string'],
            'ok'          => ['type' => 'boolean'],
            'type'        => ['type' => 'string'],
            'deleted'     => ['type' => 'array'],
            'kept'        => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_backup_schedule_execute',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
