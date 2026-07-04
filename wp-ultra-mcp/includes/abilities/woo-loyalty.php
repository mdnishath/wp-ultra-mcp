<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/woocommerce/loyalty.php — require it
// defensively so this ability works regardless of bootstrap load order
// (mirrors woo-bulk-edit's defensive engine require).
if (!function_exists('wpultra_loyalty_earn') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/woocommerce/loyalty.php')) {
    require_once WPULTRA_DIR . 'includes/woocommerce/loyalty.php';
}

wp_register_ability('wpultra/woo-loyalty', [
    'label'       => __('WooCommerce: Points, Loyalty & Gift Cards', 'wp-ultra-mcp'),
    'description' => __(
        'Customer loyalty points and gift-card coupons. EARN/REDEEM MODEL: logged-in customers earn floor(order_total x earn_rate) '
        . 'points when an order reaches the award_on status (completed by default; guests earn NOTHING — there is no user account to '
        . 'credit). A refunded order claws back exactly the points it awarded (once, balance floored at 0). Points are stored per user '
        . '(balance + a capped 200-entry ledger of {at, delta, reason, ref}). Redeeming converts points into a SINGLE-USE fixed_cart '
        . 'coupon (code pts-xxxxxxxx) worth points x redeem_rate, individual_use, email-locked to that customer. GIFT CARDS are '
        . 'single-use gift VOUCHERS (code gift-xxxxxxxx, fixed_cart, usage_limit 1): the full amount is consumed on first use — this is '
        . 'NOT a partial-redemption balance card. '
        . 'ACTIONS: '
        . 'config — read the config with no other keys, or update it: {enabled, earn_rate (points per 1 unit of currency, default 1), '
        . 'redeem_rate (currency value of 1 point, default 0.01), min_redeem (default 100), award_on (completed|processing)}. Reversible, '
        . 'no confirm needed; earning hooks arm on the NEXT request after enabling. '
        . 'balance {user_id} — {points, value (at current redeem_rate), ledger (last 20, newest last)}. '
        . 'adjust {user_id, delta, reason?} — manual grant/deduct (balance floored at 0); a NEGATIVE delta requires confirm:true. '
        . 'redeem {user_id, points} — validates points >= min_redeem, <= balance, whole number; subtracts points and creates the '
        . 'email-locked coupon (points are restored automatically if coupon creation fails). '
        . 'gift-card-create {amount, recipient_email?, note?, expires_days?, send?, confirm?} — creates the voucher; email_restrictions '
        . 'lock it to recipient_email when given; send:true + confirm:true emails the recipient a formatted gift-card email (real email '
        . 'to a real person — hence confirm). '
        . 'gift-card-list — all gift-card coupons: [{code, amount, used, recipient, expires}]. '
        . 'earn-preview {order_total} — dry-run: how many points an order of that total would earn under the current config. '
        . 'EXAMPLES: {action:"config", enabled:true, earn_rate:1, redeem_rate:0.01, min_redeem:100} = "1 point per 1 BDT spent, 100 pts = 1 BDT". '
        . '{action:"redeem", user_id:5, points:500} = "turn 500 of user 5\'s points into a 5.00 coupon only they can use". '
        . '{action:"gift-card-create", amount:1000, recipient_email:"friend@example.com", note:"Happy birthday!", expires_days:90, send:true, confirm:true}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['config', 'balance', 'adjust', 'redeem', 'gift-card-create', 'gift-card-list', 'earn-preview'],
            ],
            // config
            'enabled'     => ['type' => 'boolean'],
            'earn_rate'   => ['type' => 'number'],
            'redeem_rate' => ['type' => 'number'],
            'min_redeem'  => ['type' => 'integer'],
            'award_on'    => ['type' => 'string', 'enum' => ['completed', 'processing']],
            // balance / adjust / redeem
            'user_id' => ['type' => 'integer'],
            'points'  => ['type' => 'integer'],
            'delta'   => ['type' => 'integer'],
            'reason'  => ['type' => 'string'],
            // gift cards
            'amount'          => ['type' => 'number'],
            'recipient_email' => ['type' => 'string'],
            'note'            => ['type' => 'string'],
            'expires_days'    => ['type' => 'integer'],
            'send'            => ['type' => 'boolean'],
            'limit'           => ['type' => 'integer'],
            // earn-preview
            'order_total' => ['type' => 'number'],
            // confirmation for destructive-ish paths
            'confirm' => ['type' => 'boolean'],
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
            'points'  => ['type' => 'integer'],
            'value'   => ['type' => 'number'],
            'balance' => ['type' => 'integer'],
            'ledger'  => ['type' => 'array'],
            'coupon'  => ['type' => 'object'],
            'cards'   => ['type' => 'array'],
            'note'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_loyalty_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_woo_loyalty_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    if (!function_exists('wpultra_loyalty_earn')) {
        return wpultra_err('loyalty_engine_missing', 'The loyalty engine (includes/woocommerce/loyalty.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {

        case 'config': {
            $patch = [];
            foreach (['enabled', 'earn_rate', 'redeem_rate', 'min_redeem', 'award_on'] as $k) {
                if (array_key_exists($k, $input)) { $patch[$k] = $input[$k]; }
            }
            if (empty($patch)) {
                return wpultra_ok(['action' => 'config', 'config' => wpultra_loyalty_config()]);
            }
            $valid = wpultra_loyalty_validate_config($patch);
            if ($valid !== true) { return wpultra_err('invalid_config', (string) $valid); }
            $cfg = wpultra_loyalty_merge_config(wpultra_loyalty_config(), $patch);
            wpultra_loyalty_save_config($cfg);
            wpultra_audit_log('woo-loyalty', 'config updated: ' . wp_json_encode($patch), true);
            return wpultra_ok([
                'action' => 'config',
                'config' => $cfg,
                'note'   => 'Earning hooks (re-)arm on the next request; redeem/gift-card actions work immediately.',
            ]);
        }

        case 'balance': {
            $user_id = (int) ($input['user_id'] ?? 0);
            if ($user_id <= 0) { return wpultra_err('missing_user_id', 'balance requires user_id.'); }
            if (!get_userdata($user_id)) { return wpultra_err('user_not_found', "No user with id $user_id."); }
            $cfg = wpultra_loyalty_config();
            $points = wpultra_loyalty_get_balance($user_id);
            $ledger = wpultra_loyalty_get_ledger($user_id);
            return wpultra_ok([
                'action'  => 'balance',
                'points'  => $points,
                'value'   => wpultra_loyalty_redeem_value($points, (float) $cfg['redeem_rate']),
                'ledger'  => array_slice($ledger, -20),
            ]);
        }

        case 'adjust': {
            $user_id = (int) ($input['user_id'] ?? 0);
            if ($user_id <= 0) { return wpultra_err('missing_user_id', 'adjust requires user_id.'); }
            if (!get_userdata($user_id)) { return wpultra_err('user_not_found', "No user with id $user_id."); }
            if (!isset($input['delta']) || !is_numeric($input['delta']) || (int) $input['delta'] != (float) $input['delta']) {
                return wpultra_err('invalid_delta', 'delta must be an integer.');
            }
            $delta = (int) $input['delta'];
            if ($delta === 0) { return wpultra_err('invalid_delta', 'delta must not be 0.'); }
            if ($delta < 0 && ($input['confirm'] ?? false) !== true) {
                return wpultra_err('adjust_unconfirmed', 'Deducting points is destructive. Re-run with confirm:true.');
            }
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reason === '') { $reason = 'manual'; }
            $new = wpultra_loyalty_add_points($user_id, $delta, $reason, 'adjust:' . get_current_user_id());
            wpultra_audit_log('woo-loyalty', "adjust user=$user_id delta=$delta reason=$reason balance=$new", true);
            return wpultra_ok(['action' => 'adjust', 'balance' => $new]);
        }

        case 'redeem': {
            $user_id = (int) ($input['user_id'] ?? 0);
            if ($user_id <= 0) { return wpultra_err('missing_user_id', 'redeem requires user_id.'); }
            if (!array_key_exists('points', $input)) { return wpultra_err('missing_points', 'redeem requires points.'); }
            $res = wpultra_loyalty_redeem($user_id, $input['points']);
            if (is_wp_error($res)) {
                wpultra_audit_log('woo-loyalty', "redeem failed user=$user_id: " . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('woo-loyalty', "redeem user=$user_id points={$res['points_spent']} coupon={$res['code']} value={$res['value']}", true);
            return wpultra_ok([
                'action'  => 'redeem',
                'coupon'  => $res,
                'balance' => $res['balance'],
            ]);
        }

        case 'gift-card-create': {
            $send = ($input['send'] ?? false) === true;
            $recipient = trim((string) ($input['recipient_email'] ?? ''));
            if ($send && $recipient === '') {
                return wpultra_err('missing_recipient', 'send:true requires recipient_email.');
            }
            if ($send && ($input['confirm'] ?? false) !== true) {
                return wpultra_err('send_unconfirmed', 'send:true emails a real recipient. Re-run with confirm:true.');
            }
            $card = wpultra_loyalty_gift_create([
                'amount'          => $input['amount'] ?? 0,
                'recipient_email' => $recipient,
                'note'            => (string) ($input['note'] ?? ''),
                'expires_days'    => (int) ($input['expires_days'] ?? 0),
            ]);
            if (is_wp_error($card)) {
                wpultra_audit_log('woo-loyalty', 'gift-card-create failed: ' . $card->get_error_message(), false);
                return $card;
            }
            $note = 'Single-use gift voucher created (consumed in full on first use — not a balance card).';
            if ($send) {
                $sent = wpultra_loyalty_gift_send($recipient, $card);
                $note .= is_wp_error($sent)
                    ? ' Email FAILED: ' . $sent->get_error_message()
                    : " Gift-card email sent to $recipient.";
            }
            wpultra_audit_log('woo-loyalty', "gift-card-create code={$card['code']} amount={$card['amount']}" . ($send ? " sent-to=$recipient" : ''), true);
            return wpultra_ok(['action' => 'gift-card-create', 'coupon' => $card, 'note' => $note]);
        }

        case 'gift-card-list': {
            $cards = wpultra_loyalty_gift_list((int) ($input['limit'] ?? 100));
            return wpultra_ok(['action' => 'gift-card-list', 'cards' => $cards]);
        }

        case 'earn-preview': {
            if (!isset($input['order_total']) || !is_numeric($input['order_total'])) {
                return wpultra_err('missing_order_total', 'earn-preview requires a numeric order_total.');
            }
            $cfg = wpultra_loyalty_config();
            $points = wpultra_loyalty_earn((float) $input['order_total'], (float) $cfg['earn_rate']);
            return wpultra_ok([
                'action' => 'earn-preview',
                'points' => $points,
                'config' => $cfg,
                'note'   => empty($cfg['enabled'])
                    ? 'NOTE: loyalty is currently DISABLED — this is what WOULD be earned if enabled.'
                    : 'Points a logged-in customer earns when the order reaches ' . $cfg['award_on'] . ' (guests earn nothing).',
            ]);
        }

        default:
            return wpultra_err('unknown_action', "Unknown action: $action. Use config|balance|adjust|redeem|gift-card-create|gift-card-list|earn-preview.");
    }
}
