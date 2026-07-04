<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/marketing/popups.php — require it defensively
// so this ability works regardless of the controller's engine-loader ordering
// (mirrors woo-bulk-edit's defensive require of its engine).
if (!function_exists('wpultra_popup_defaults') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/marketing/popups.php')) {
    require_once WPULTRA_DIR . 'includes/marketing/popups.php';
}

wp_register_ability('wpultra/popup-campaign', [
    'label'       => __('Popup / Optin Campaigns', 'wp-ultra-mcp'),
    'description' => __(
        'Manage front-end popup / optin campaigns with exit-intent, scroll, or timed triggers, optional A/B variant testing, and impression/conversion stats. '
        . 'actions: create (name + html required — created DISABLED; run enable explicitly to go live), update (partial: any of name, html, variant_b_html, trigger, scroll_pct, delay_s, pages, frequency_days), get (full record + conversion rates), list (up to 50 campaigns with enabled/trigger/impressions/conversions summary), enable, disable, delete (requires confirm:true), stats (per-variant conversion rates + winner-so-far; pass id for one campaign or omit for all). '
        . 'triggers: "time" shows after delay_s seconds (0-300, default 5); "scroll" shows once the visitor scrolls scroll_pct percent of the page (1-100, default 50); "exit-intent" shows when the mouse leaves the viewport top (desktop). '
        . 'pages targeting: "all" (default), "home" (front page), or an array of post IDs. frequency_days (0-365, default 7) caps re-display per visitor via localStorage after dismiss/convert; 0 = show every visit. '
        . 'A/B testing: set variant_b_html to a non-empty HTML string and each pageview is split 50/50 server-side between variant A (html) and B; per-variant impressions/conversions accumulate via the public /wpultra/v1/track beacon endpoint, and stats reports each variant\'s conversion rate pct plus the winner so far. Set variant_b_html to "" to turn the split off. '
        . 'Popup HTML may contain an optin form or CTA links/buttons — ANY click on an <a>, <button>, or [type=submit] inside the popup, or a form submit inside it, counts as a conversion (then the popup closes and the frequency cap starts). HTML is filtered through wp_kses_post on save. '
        . 'Examples: {action:"create", name:"Newsletter exit popup", html:"<h3>Wait!</h3><p>Get 10% off</p><a href=\"/signup\">Sign up</a>", trigger:"exit-intent"} then {action:"enable", id:123}. '
        . '{action:"update", id:123, variant_b_html:"<h3>Before you go</h3><a href=\"/signup\">Get the discount</a>"} starts an A/B test. '
        . '{action:"stats", id:123} → per-variant rates + winner. {action:"delete", id:123, confirm:true} removes it.',
        'wp-ultra-mcp'
    ),
    'category'    => 'marketing',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['create', 'update', 'get', 'list', 'delete', 'enable', 'disable', 'stats'],
            ],
            'id'             => ['type' => 'integer', 'description' => 'Popup campaign ID (required for update/get/delete/enable/disable; optional for stats).'],
            'name'           => ['type' => 'string', 'description' => 'Campaign name (create required; update optional).'],
            'html'           => ['type' => 'string', 'description' => 'Variant A popup HTML — may include a form or CTA links (create required; update optional).'],
            'variant_b_html' => ['type' => 'string', 'description' => 'Variant B HTML for a 50/50 A/B split; empty string disables the split.'],
            'trigger'        => ['type' => 'string', 'enum' => ['exit-intent', 'scroll', 'time']],
            'scroll_pct'     => ['type' => 'integer', 'description' => 'Scroll trigger threshold percent, 1-100 (default 50).'],
            'delay_s'        => ['type' => 'integer', 'description' => 'Time trigger delay in seconds, 0-300 (default 5).'],
            'pages'          => [
                'type'        => ['string', 'array'],
                'items'       => ['type' => 'integer'],
                'description' => "Targeting: 'all', 'home', or an array of post IDs.",
            ],
            'frequency_days' => ['type' => 'integer', 'description' => 'Days before re-showing to a visitor who dismissed/converted, 0-365 (default 7; 0 = every visit).'],
            'confirm'        => ['type' => 'boolean', 'description' => 'Required true for delete.'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'popup'   => ['type' => 'object'],
            'popups'  => ['type' => 'array'],
            'stats'   => ['type' => ['object', 'array']],
            'deleted' => ['type' => 'boolean'],
            'count'   => ['type' => 'integer'],
            'note'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_popup_campaign_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_popup_campaign_cb(array $input) {
    if (!function_exists('wpultra_popup_defaults')) {
        return wpultra_err('popup_engine_missing', 'The popup engine (includes/marketing/popups.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');
    $id     = (int) ($input['id'] ?? 0);

    switch ($action) {
        case 'create': {
            $popup = wpultra_popup_create($input);
            if (is_wp_error($popup)) {
                wpultra_audit_log('popup-campaign', 'create failed: ' . $popup->get_error_message(), false);
                return $popup;
            }
            wpultra_audit_log('popup-campaign', "create id={$popup['id']} trigger={$popup['trigger']}", true);
            return wpultra_ok([
                'popup' => $popup,
                'note'  => 'Created DISABLED. Run {action:"enable", id:' . $popup['id'] . '} to go live.',
            ]);
        }

        case 'update': {
            if ($id <= 0) { return wpultra_err('missing_id', 'id is required for update.'); }
            $popup = wpultra_popup_update($id, $input);
            if (is_wp_error($popup)) {
                wpultra_audit_log('popup-campaign', "update id=$id failed: " . $popup->get_error_message(), false);
                return $popup;
            }
            wpultra_audit_log('popup-campaign', "update id=$id", true);
            return wpultra_ok(['popup' => $popup]);
        }

        case 'get': {
            if ($id <= 0) { return wpultra_err('missing_id', 'id is required for get.'); }
            $popup = wpultra_popup_load($id);
            if ($popup === null) { return wpultra_err('popup_not_found', "No popup campaign with id $id."); }
            return wpultra_ok([
                'popup' => $popup,
                'stats' => wpultra_popup_rates((array) $popup['stats']),
            ]);
        }

        case 'list': {
            $popups = wpultra_popup_list(50);
            return wpultra_ok(['popups' => $popups, 'count' => count($popups)]);
        }

        case 'enable':
        case 'disable': {
            if ($id <= 0) { return wpultra_err('missing_id', "id is required for $action."); }
            $popup = wpultra_popup_set_enabled($id, $action === 'enable');
            if (is_wp_error($popup)) {
                wpultra_audit_log('popup-campaign', "$action id=$id failed: " . $popup->get_error_message(), false);
                return $popup;
            }
            wpultra_audit_log('popup-campaign', "$action id=$id", true);
            return wpultra_ok(['popup' => $popup]);
        }

        case 'delete': {
            if ($id <= 0) { return wpultra_err('missing_id', 'id is required for delete.'); }
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('unconfirmed', 'Deleting a popup campaign is permanent (stats included). Re-run with confirm: true.');
            }
            $res = wpultra_popup_delete($id);
            if (is_wp_error($res)) {
                wpultra_audit_log('popup-campaign', "delete id=$id failed: " . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('popup-campaign', "delete id=$id", true);
            return wpultra_ok(['deleted' => true]);
        }

        case 'stats': {
            if ($id > 0) {
                $popup = wpultra_popup_load($id);
                if ($popup === null) { return wpultra_err('popup_not_found', "No popup campaign with id $id."); }
                return wpultra_ok([
                    'stats' => array_merge(
                        ['id' => $popup['id'], 'name' => $popup['name'], 'enabled' => (bool) $popup['enabled']],
                        wpultra_popup_rates((array) $popup['stats'])
                    ),
                ]);
            }
            $out = [];
            foreach (wpultra_popup_list(50) as $summary) {
                $popup = wpultra_popup_load((int) $summary['id']);
                if ($popup === null) { continue; }
                $out[] = array_merge(
                    ['id' => $popup['id'], 'name' => $popup['name'], 'enabled' => (bool) $popup['enabled']],
                    wpultra_popup_rates((array) $popup['stats'])
                );
            }
            return wpultra_ok(['stats' => $out, 'count' => count($out)]);
        }
    }

    return wpultra_err('unknown_action', "Unknown action '$action'. Known: create, update, get, list, delete, enable, disable, stats.");
}
