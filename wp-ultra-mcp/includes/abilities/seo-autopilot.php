<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively load the engine + shared AI helper so this ability works even if
// bootstrap order changes.
if (!function_exists('wpultra_seopilot_run')) {
    require_once __DIR__ . '/../ai/seopilot.php';
}
if (!function_exists('wpultra_ai_has_key')) {
    require_once __DIR__ . '/../ai/setup.php';
}

wp_register_ability('wpultra/seo-autopilot', [
    'label'       => __('SEO: AI Auto-Pilot', 'wp-ultra-mcp'),
    'description' => __(
        'Scheduled, hands-off SEO maintenance. Runs a pipeline over published posts: AUDIT (find posts with missing/short/long titles or thin descriptions, no internal links, no schema) -> FIX META (generate an SEO title <=60 chars and meta description <=155 chars with AI when an OpenAI key is set, otherwise a deterministic fallback from the post title/excerpt) -> add an INTERNAL LINK to a related post -> ensure Article JSON-LD SCHEMA. '
        . 'DRY-RUN FIRST for safety: run and the scheduled cron both PREVIEW by default and write nothing. To apply live edits you must pass dry_run:false AND confirm:true. '
        . 'actions: run (execute the pipeline once, returns a per-post before/after report + summary), preview-post (post_id; show exactly what the pilot WOULD do to one post, always dry, no confirm), config (enable/disable, recurrence daily|weekly, scope.post_types, scope.limit_per_run, which steps run, dry_run_default; reconciles the cron schedule), status, history. '
        . 'Examples: {"action":"preview-post","post_id":42} to build trust; {"action":"run"} for a safe dry preview of the whole batch; {"action":"run","dry_run":false,"confirm":true,"limit":10} to apply; {"action":"config","enabled":true,"recurrence":"weekly","steps":{"schema":false}}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'     => ['type' => 'string', 'enum' => ['run', 'config', 'status', 'history', 'preview-post']],
            'dry_run'    => ['type' => 'boolean'],
            'confirm'    => ['type' => 'boolean'],
            'post_id'    => ['type' => 'integer'],
            'limit'      => ['type' => 'integer'],
            'post_types' => ['type' => 'array', 'items' => ['type' => 'string']],
            'recurrence' => ['type' => 'string', 'enum' => ['daily', 'weekly']],
            'enabled'    => ['type' => 'boolean'],
            'dry_run_default' => ['type' => 'boolean'],
            'steps'      => [
                'type' => 'object',
                'properties' => [
                    'fix_meta'       => ['type' => 'boolean'],
                    'internal_links' => ['type' => 'boolean'],
                    'schema'         => ['type' => 'boolean'],
                ],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
            'dry_run' => ['type' => 'boolean'],
            'summary' => ['type' => 'object'],
            'actions' => ['type' => 'array'],
            'config'  => ['type' => 'object'],
            'history' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_seo_autopilot_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_seo_autopilot_cb(array $input) {
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'run':
            $cfg = wpultra_seopilot_config();
            $dry = !array_key_exists('dry_run', $input) ? true : (bool) $input['dry_run'];
            if (!$dry && empty($input['confirm'])) {
                return wpultra_err('confirm_required', 'Live writes (dry_run:false) require confirm:true. Preview first with a dry run or {"action":"preview-post"}.');
            }
            // Per-call overrides on top of the stored config.
            $opts = $cfg;
            if (isset($input['limit'])) { $opts['scope']['limit_per_run'] = (int) $input['limit']; }
            if (isset($input['post_types']) && is_array($input['post_types'])) {
                $types = array_values(array_filter(array_map('strval', $input['post_types']), fn($t) => $t !== ''));
                if ($types) { $opts['scope']['post_types'] = $types; }
            }
            if (isset($input['steps']) && is_array($input['steps'])) {
                $opts['steps'] = array_merge($opts['steps'], array_map('boolval', $input['steps']));
            }
            $report = wpultra_seopilot_run($opts, $dry);
            wpultra_seopilot_record_run($report);
            if (!$dry) {
                $s = $report['summary'];
                wpultra_audit_log('seo-autopilot', 'applied meta=' . ($s['meta_fixed'] ?? 0) . ' links=' . ($s['links_added'] ?? 0) . ' schema=' . ($s['schema_added'] ?? 0), true);
            }
            return wpultra_ok([
                'action'  => 'run',
                'dry_run' => $report['dry_run'],
                'targets' => $report['targets'],
                'summary' => $report['summary'],
                'actions' => $report['actions'],
            ]);

        case 'preview-post':
            $pid = (int) ($input['post_id'] ?? 0);
            if ($pid <= 0) { return wpultra_err('post_id_required', 'preview-post needs a positive post_id.'); }
            $res = wpultra_seopilot_preview_post($pid);
            if (function_exists('is_wp_error') && is_wp_error($res)) { return $res; }
            return wpultra_ok(array_merge(['action' => 'preview-post', 'dry_run' => true], $res));

        case 'config':
            $cfg = wpultra_seopilot_config();
            if (array_key_exists('enabled', $input))         { $cfg['enabled'] = (bool) $input['enabled']; }
            if (array_key_exists('recurrence', $input))      { $cfg['recurrence'] = (string) $input['recurrence']; }
            if (array_key_exists('dry_run_default', $input)) { $cfg['dry_run_default'] = (bool) $input['dry_run_default']; }
            if (isset($input['limit']))                      { $cfg['scope']['limit_per_run'] = (int) $input['limit']; }
            if (isset($input['post_types']) && is_array($input['post_types'])) {
                $cfg['scope']['post_types'] = array_values(array_filter(array_map('strval', $input['post_types']), fn($t) => $t !== ''));
            }
            if (isset($input['steps']) && is_array($input['steps'])) {
                $cfg['steps'] = array_merge($cfg['steps'], array_map('boolval', $input['steps']));
            }
            $saved = wpultra_seopilot_save_config($cfg);
            // Reconcile the cron schedule against the new config.
            if (function_exists('wp_clear_scheduled_hook')) { wp_clear_scheduled_hook(WPULTRA_SEOPILOT_EVENT); }
            if (function_exists('update_option')) { update_option(WPULTRA_SEOPILOT_SCHED_MARKER, '', false); }
            wpultra_seopilot_boot_reconcile();
            wpultra_audit_log('seo-autopilot', 'config ' . ($saved['enabled'] ? 'enabled' : 'disabled') . '/' . $saved['recurrence'], true);
            return wpultra_ok(['action' => 'config', 'config' => wpultra_seopilot_public_config($saved)]);

        case 'status':
            $cfg = wpultra_seopilot_config();
            return wpultra_ok([
                'action'     => 'status',
                'config'     => wpultra_seopilot_public_config($cfg),
                'last_run'   => (int) $cfg['last_run'],
                'next_run'   => function_exists('wp_next_scheduled') ? (int) (wp_next_scheduled(WPULTRA_SEOPILOT_EVENT) ?: 0) : 0,
                'has_ai_key' => function_exists('wpultra_ai_has_key') ? wpultra_ai_has_key() : false,
                'last_summary' => $cfg['last_report']['summary'] ?? [],
            ]);

        case 'history':
            $cfg = wpultra_seopilot_config();
            return wpultra_ok(['action' => 'history', 'history' => array_values($cfg['history'])]);

        default:
            return wpultra_err('unknown_action', "Unknown action '$action'. Use run|config|status|history|preview-post.");
    }
}

/** Shape a config for output (drop the bulky last_report/history blobs). */
function wpultra_seopilot_public_config(array $cfg): array {
    return [
        'enabled'         => (bool) $cfg['enabled'],
        'recurrence'      => (string) $cfg['recurrence'],
        'scope'           => $cfg['scope'],
        'steps'           => $cfg['steps'],
        'dry_run_default' => (bool) $cfg['dry_run_default'],
    ];
}

/** Re-run the boot schedule reconciliation after a config change. */
function wpultra_seopilot_boot_reconcile(): void {
    // boot() is static-guarded (idempotent within a request); re-running its
    // schedule-reconcile logic is safe because we cleared the marker above.
    if (function_exists('wpultra_seopilot_config')
        && function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
        $cfg = wpultra_seopilot_config();
        $desired = !empty($cfg['enabled']) ? wpultra_seopilot_interval((string) $cfg['recurrence']) : '';
        if ($desired === '') { return; }
        if (!wp_next_scheduled(WPULTRA_SEOPILOT_EVENT)) {
            wp_schedule_event(time() + 120, $desired, WPULTRA_SEOPILOT_EVENT);
        }
        if (function_exists('update_option')) { update_option(WPULTRA_SEOPILOT_SCHED_MARKER, $desired, false); }
    }
}
