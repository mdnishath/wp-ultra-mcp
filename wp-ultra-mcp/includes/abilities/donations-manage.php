<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine so this ability works regardless of the
// bootstrap load order (mirrors how woo-bulk-edit leans on its engine file).
if (!function_exists('wpultra_donate_progress') && defined('WPULTRA_DIR')
    && is_readable(WPULTRA_DIR . 'includes/verticals/donations.php')) {
    require_once WPULTRA_DIR . 'includes/verticals/donations.php';
}

wp_register_ability('wpultra/donations-manage', [
    'label'       => __('Donations / Crowdfunding Manager', 'wp-ultra-mcp'),
    'description' => __(
        'Run fundraising campaigns with goals, progress tracking, one-time and recurring donations, and donor records. '
        . 'CAMPAIGNS: a campaign has a title, a story (post_content), a goal_amount + currency, an optional deadline (unix), '
        . 'a status (active|completed|closed), and cached raised/donor_count rolled up from its COMPLETED donations. '
        . 'DONATIONS: each donation records a donor {name,email}, amount, currency, recurring cadence (none|monthly|yearly), '
        . 'status (pending|completed|refunded|failed), an optional gateway_ref, a next_charge date (recurring only), and an anonymous flag. '
        . 'PAYMENT MODEL (honest): this tool RECORDS campaigns, donations, progress, and the recurring SCHEDULE — it does NOT process '
        . 'cards and never stores card data. Money moves externally, two ways: (1) a WooCommerce order/webhook marks a donation completed, '
        . 'or (2) an external gateway webhook completes it via mark-donation with a gateway_ref. For recurring gifts, a daily cron records '
        . 'the NEXT expected installment as a fresh pending donation and advances next_charge; the GATEWAY performs the real charge and the '
        . 'webhook then marks each installment completed. '
        . 'ACTIONS: '
        . 'manage-campaign {id?, title, story?, goal_amount, currency?, deadline?, status?, cover_image?} = upsert a campaign (id present -> update); '
        . 'list-campaigns = all campaigns with progress; '
        . 'get-campaign {campaign_id} = one campaign + progress; '
        . 'record-donation {campaign_id, donor:{name,email}, amount, currency?, recurring?, anonymous?, status?, confirm:true} = create a REAL donation record (confirm-gated); '
        . 'mark-donation {id, status, gateway_ref?} = the webhook/manual completion path (e.g. pending -> completed); '
        . 'list-donations {campaign_id, status?} = donations for a campaign, optionally filtered by status; '
        . 'refund {id} = mark a donation refunded (recomputes the campaign total); '
        . 'progress {campaign_id} = the pure progress rollup (raised/goal/pct/remaining/donors); '
        . 'recurring-due = run the recurring cron now (records due installments as pending). '
        . 'Examples: {action:"manage-campaign", title:"Rebuild the shelter", goal_amount:5000, currency:"USD"}; '
        . '{action:"record-donation", campaign_id:12, donor:{name:"Rahim", email:"rahim@example.com"}, amount:50, recurring:"monthly", confirm:true}; '
        . '{action:"mark-donation", id:34, status:"completed", gateway_ref:"pi_abc123"}; '
        . '{action:"progress", campaign_id:12}.',
        'wp-ultra-mcp'
    ),
    'category'     => 'verticals',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    'manage-campaign', 'list-campaigns', 'get-campaign',
                    'record-donation', 'mark-donation', 'list-donations',
                    'refund', 'progress', 'recurring-due',
                ],
            ],
            'id'          => ['type' => 'integer'],
            'campaign_id' => ['type' => 'integer'],
            'title'       => ['type' => 'string'],
            'story'       => ['type' => 'string'],
            'goal_amount' => ['type' => 'number'],
            'currency'    => ['type' => 'string'],
            'deadline'    => ['type' => 'integer'],
            'status'      => ['type' => 'string'],
            'cover_image' => ['type' => 'string'],
            'donor'       => [
                'type'       => 'object',
                'properties' => [
                    'name'  => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ],
            ],
            'amount'      => ['type' => 'number'],
            'recurring'   => ['type' => 'string', 'enum' => ['none', 'monthly', 'yearly']],
            'anonymous'   => ['type' => 'boolean'],
            'gateway_ref' => ['type' => 'string'],
            'confirm'     => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_donations_manage_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_donations_manage_cb(array $input) {
    if (!function_exists('wpultra_donate_progress')) {
        return wpultra_err('donations_engine_missing', 'The donations engine (includes/verticals/donations.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'manage-campaign': {
            $res = wpultra_donate_upsert_campaign([
                'id'          => (int) ($input['id'] ?? 0),
                'title'       => (string) ($input['title'] ?? ''),
                'story'       => (string) ($input['story'] ?? ''),
                'goal_amount' => $input['goal_amount'] ?? null,
                'currency'    => (string) ($input['currency'] ?? 'USD'),
            ] + (array_key_exists('deadline', $input) ? ['deadline' => $input['deadline']] : [])
              + (array_key_exists('status', $input) ? ['status' => (string) $input['status']] : [])
              + (array_key_exists('cover_image', $input) ? ['cover_image' => (string) $input['cover_image']] : []));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('donations-manage', "manage-campaign id=$res");
            return wpultra_ok(['campaign' => wpultra_donate_load_campaign((int) $res)]);
        }

        case 'list-campaigns': {
            if (!function_exists('get_posts')) { return wpultra_err('wp_unavailable', 'WordPress is not loaded.'); }
            $ids = get_posts([
                'post_type'        => WPULTRA_FUND_CPT,
                'post_status'      => 'any',
                'numberposts'      => 200,
                'orderby'          => 'date',
                'order'            => 'DESC',
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => true,
            ]);
            $out = [];
            foreach ((array) $ids as $id) {
                $c = wpultra_donate_load_campaign((int) $id);
                if ($c !== null) { $out[] = $c; }
            }
            return wpultra_ok(['campaigns' => $out, 'count' => count($out)]);
        }

        case 'get-campaign': {
            $c = wpultra_donate_load_campaign((int) ($input['campaign_id'] ?? 0));
            if ($c === null) { return wpultra_err('not_found', 'Campaign not found.'); }
            return wpultra_ok(['campaign' => $c]);
        }

        case 'progress': {
            $c = wpultra_donate_load_campaign((int) ($input['campaign_id'] ?? 0));
            if ($c === null) { return wpultra_err('not_found', 'Campaign not found.'); }
            return wpultra_ok([
                'campaign_id' => $c['id'],
                'raised'      => $c['raised'],
                'goal_amount' => $c['goal_amount'],
                'donor_count' => $c['donor_count'],
                'progress'    => $c['progress'],
            ]);
        }

        case 'record-donation': {
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('donation_unconfirmed', 'Recording a donation is a real write. Re-run with confirm:true.');
            }
            $res = wpultra_donate_record([
                'campaign_id' => (int) ($input['campaign_id'] ?? 0),
                'donor'       => (array) ($input['donor'] ?? []),
                'amount'      => $input['amount'] ?? null,
                'currency'    => (string) ($input['currency'] ?? 'USD'),
                'recurring'   => (string) ($input['recurring'] ?? 'none'),
                'anonymous'   => (bool) ($input['anonymous'] ?? false),
                'status'      => (string) ($input['status'] ?? 'pending'),
                'gateway_ref' => (string) ($input['gateway_ref'] ?? ''),
            ]);
            if (is_wp_error($res)) {
                wpultra_audit_log('donations-manage', 'record-donation failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('donations-manage', "record-donation id=$res");
            return wpultra_ok(['donation_id' => (int) $res]);
        }

        case 'mark-donation': {
            $res = wpultra_donate_mark(
                (int) ($input['id'] ?? 0),
                (string) ($input['status'] ?? ''),
                (string) ($input['gateway_ref'] ?? '')
            );
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('donations-manage', 'mark-donation id=' . (int) ($input['id'] ?? 0) . ' -> ' . (string) ($input['status'] ?? ''));
            return wpultra_ok(['donation' => $res]);
        }

        case 'refund': {
            $res = wpultra_donate_refund((int) ($input['id'] ?? 0));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('donations-manage', 'refund id=' . (int) ($input['id'] ?? 0));
            return wpultra_ok(['donation' => $res]);
        }

        case 'list-donations': {
            $campaign_id = (int) ($input['campaign_id'] ?? 0);
            if ($campaign_id <= 0) { return wpultra_err('missing_campaign', 'campaign_id is required.'); }
            $filterStatus = isset($input['status']) ? (string) $input['status'] : '';
            $items = wpultra_donate_campaign_donations($campaign_id);
            $out = [];
            foreach ($items as $it) {
                $shape = wpultra_donate_donation_shape($it['meta'], $it['id']);
                if ($filterStatus !== '' && $shape['status'] !== $filterStatus) { continue; }
                $out[] = $shape;
            }
            return wpultra_ok(['donations' => $out, 'count' => count($out)]);
        }

        case 'recurring-due': {
            $res = wpultra_donate_run_recurring();
            wpultra_audit_log('donations-manage', "recurring-due recorded={$res['recorded']}");
            return wpultra_ok(['recorded' => $res['recorded'], 'ids' => $res['ids']]);
        }

        default:
            return wpultra_err('unknown_action', "Unknown action '$action'.");
    }
}
