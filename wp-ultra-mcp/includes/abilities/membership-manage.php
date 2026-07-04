<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/verticals/membership.php. Require it
// defensively so this ability works regardless of load order (mirrors how
// woo-bulk-edit leans on its engine file).
if (!function_exists('wpultra_member_can_access') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/verticals/membership.php')) {
    require_once WPULTRA_DIR . 'includes/verticals/membership.php';
}

wp_register_ability('wpultra/membership-manage', [
    'label'       => __('Membership / Paywall Manager', 'wp-ultra-mcp'),
    'description' => __(
        'Run a lightweight membership + paywall layer: define levels, restrict content behind them, drip content over time, assign members, and inspect exactly why any user is allowed or denied.'
        . ' RESTRICTION MODEL: a restriction RULE targets content by post_ids, categories (term ids), and/or post_types (a rule matches when EVERY targeted dimension matches). Each rule sets require_level (a level id, or "any" for any active member), drip_days (content unlocks N days after the member joined), and teaser_words (how many words of the post to show non-members before the paywall).'
        . ' A MEMBER holds one LEVEL {name, price, period: month|year|lifetime} with a join date and an optional expiry. ADMIN BYPASS: site admins and the post\'s own author ALWAYS see full content — they can never be paywalled out.'
        . ' actions: '
        . 'manage-level {id?, name, price?, period, description?} -> upsert a level (mints an id when omitted). '
        . 'list-levels -> all levels. '
        . 'manage-rule {id?, match:{post_ids?, categories?, post_types?}, require_level?, drip_days?, teaser_words?} -> upsert a restriction rule. '
        . 'list-rules -> all rules. '
        . 'assign-member {user_id, level_id, expires?} -> grant a user a membership (expires is a unix timestamp; omit for non-expiring). '
        . 'remove-member {user_id, confirm:true} -> revoke a user\'s membership (confirm-gated). '
        . 'member-status {user_id} -> a user\'s level, join date, expiry and effective status (active|expired|cancelled|none). '
        . 'check-access {user_id, post_id} -> DRY RUN the access decision for a user against a post: returns {matched_rule, allowed, reason (no_membership|expired|wrong_level|dripping|ok), unlock_at?} — the trust-builder that shows EXACTLY why access is granted or denied. '
        . 'dashboard {user_id} -> a member\'s level, expiry, status, and the lists of accessible vs. locked rules. '
        . 'delete-level {id, confirm:true} / delete-rule {id, confirm:true} -> remove a level or rule (confirm-gated).'
        . ' Examples: {action:"manage-level", name:"Gold", price:9.99, period:"month"} then {action:"manage-rule", match:{categories:[12]}, require_level:"lvl-abc123", drip_days:7, teaser_words:60} = "posts in category 12 are Gold-only and drip 7 days after joining, showing a 60-word teaser to everyone else". {action:"check-access", user_id:5, post_id:42} = "would user 5 see post 42, and why?".',
        'wp-ultra-mcp'
    ),
    'category'    => 'verticals',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => [
                'manage-level', 'list-levels', 'delete-level',
                'manage-rule', 'list-rules', 'delete-rule',
                'assign-member', 'remove-member', 'member-status',
                'check-access', 'dashboard',
            ]],
            // level fields
            'id'          => ['type' => 'string'],
            'name'        => ['type' => 'string'],
            'price'       => ['type' => 'number'],
            'period'      => ['type' => 'string', 'enum' => ['month', 'year', 'lifetime']],
            'description' => ['type' => 'string'],
            // rule fields
            'match' => [
                'type'       => 'object',
                'properties' => [
                    'post_ids'   => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'categories' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'post_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'additionalProperties' => false,
            ],
            'require_level' => ['type' => 'string'],
            'drip_days'     => ['type' => 'integer'],
            'teaser_words'  => ['type' => 'integer'],
            // member fields
            'user_id' => ['type' => 'integer'],
            'level_id' => ['type' => 'string'],
            'expires'  => ['type' => 'integer'],
            'post_id'  => ['type' => 'integer'],
            'confirm'  => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'levels'    => ['type' => 'array'],
            'rules'     => ['type' => 'array'],
            'level'     => ['type' => 'object'],
            'rule'      => ['type' => 'object'],
            'member'    => ['type' => 'object'],
            'access'    => ['type' => 'object'],
            'dashboard' => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_membership_manage_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_membership_manage_cb(array $input) {
    if (!function_exists('wpultra_member_can_access')) {
        return wpultra_err('membership_engine_missing', 'The membership engine (includes/verticals/membership.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {

        case 'list-levels':
            return wpultra_ok(['levels' => array_values(wpultra_member_get_levels())]);

        case 'list-rules':
            return wpultra_ok(['rules' => wpultra_member_get_rules()]);

        case 'manage-level': {
            $lvl = [
                'id'          => (string) ($input['id'] ?? ''),
                'name'        => (string) ($input['name'] ?? ''),
                'price'       => $input['price'] ?? 0,
                'period'      => (string) ($input['period'] ?? 'month'),
                'description' => (string) ($input['description'] ?? ''),
            ];
            $res = wpultra_member_upsert_level($lvl);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('membership-manage', "upsert level {$res['id']} ({$res['name']})", true);
            return wpultra_ok(['level' => $res, 'levels' => array_values(wpultra_member_get_levels())]);
        }

        case 'delete-level': {
            $id = (string) ($input['id'] ?? '');
            if ($id === '') { return wpultra_err('missing_id', 'id is required.'); }
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('unconfirmed', "Deleting level '$id' is destructive. Re-run with confirm:true.");
            }
            $ok = wpultra_member_delete_level($id);
            wpultra_audit_log('membership-manage', "delete level $id", $ok);
            return wpultra_ok(['deleted' => $ok, 'levels' => array_values(wpultra_member_get_levels())]);
        }

        case 'manage-rule': {
            $rule = [
                'id'            => (string) ($input['id'] ?? ''),
                'match'         => is_array($input['match'] ?? null) ? $input['match'] : [],
                'require_level' => (string) ($input['require_level'] ?? 'any'),
                'drip_days'     => (int) ($input['drip_days'] ?? 0),
                'teaser_words'  => array_key_exists('teaser_words', $input) ? (int) $input['teaser_words'] : 55,
            ];
            $res = wpultra_member_upsert_rule($rule);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('membership-manage', "upsert rule {$res['id']}", true);
            return wpultra_ok(['rule' => $res, 'rules' => wpultra_member_get_rules()]);
        }

        case 'delete-rule': {
            $id = (string) ($input['id'] ?? '');
            if ($id === '') { return wpultra_err('missing_id', 'id is required.'); }
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('unconfirmed', "Deleting rule '$id' is destructive. Re-run with confirm:true.");
            }
            $ok = wpultra_member_delete_rule($id);
            wpultra_audit_log('membership-manage', "delete rule $id", $ok);
            return wpultra_ok(['deleted' => $ok, 'rules' => wpultra_member_get_rules()]);
        }

        case 'assign-member': {
            $user_id = (int) ($input['user_id'] ?? 0);
            $level_id = (string) ($input['level_id'] ?? '');
            if ($user_id <= 0) { return wpultra_err('missing_user', 'user_id is required.'); }
            if ($level_id === '') { return wpultra_err('missing_level', 'level_id is required.'); }
            $expires = array_key_exists('expires', $input) && $input['expires'] !== null
                ? (int) $input['expires'] : null;
            $res = wpultra_member_assign($user_id, $level_id, $expires);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('membership-manage', "assign user $user_id -> $level_id", true);
            return wpultra_ok(['member' => $res]);
        }

        case 'remove-member': {
            $user_id = (int) ($input['user_id'] ?? 0);
            if ($user_id <= 0) { return wpultra_err('missing_user', 'user_id is required.'); }
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('unconfirmed', "Removing user $user_id's membership is destructive. Re-run with confirm:true.");
            }
            $ok = wpultra_member_remove($user_id);
            wpultra_audit_log('membership-manage', "remove member $user_id", $ok);
            return wpultra_ok(['removed' => $ok]);
        }

        case 'member-status': {
            $user_id = (int) ($input['user_id'] ?? 0);
            if ($user_id <= 0) { return wpultra_err('missing_user', 'user_id is required.'); }
            $member = wpultra_member_get($user_id);
            $now = function_exists('time') ? time() : 0;
            return wpultra_ok(['member' => [
                'user_id'  => $user_id,
                'level_id' => (string) ($member['level_id'] ?? ''),
                'since'    => (int) ($member['since'] ?? 0),
                'expires'  => $member['expires'] ?? null,
                'status'   => $member !== [] ? wpultra_member_status($member, $now) : 'none',
            ]]);
        }

        case 'check-access': {
            $user_id = (int) ($input['user_id'] ?? 0);
            $post_id = (int) ($input['post_id'] ?? 0);
            if ($post_id <= 0) { return wpultra_err('missing_post', 'post_id is required.'); }
            $ctx = wpultra_member_post_ctx($post_id);
            $rule = wpultra_member_matching_rule($ctx);
            $now = function_exists('time') ? time() : 0;

            if ($rule === []) {
                return wpultra_ok(['access' => [
                    'post_id'      => $post_id,
                    'matched_rule' => null,
                    'allowed'      => true,
                    'reason'       => 'no_rule',
                    'note'         => 'No restriction rule covers this post — it is public.',
                ]]);
            }

            $bypass = $user_id > 0 && wpultra_member_viewer_bypasses_user($user_id, $post_id);
            $member = $user_id > 0 ? wpultra_member_get($user_id) : [];
            $decision = wpultra_member_can_access($member, $rule, $now);

            $access = [
                'post_id'      => $post_id,
                'user_id'      => $user_id,
                'matched_rule' => (string) ($rule['id'] ?? ''),
                'require_level'=> (string) ($rule['require_level'] ?? 'any'),
                'allowed'      => $bypass ? true : $decision['allowed'],
                'reason'       => $bypass ? 'admin_or_author_bypass' : $decision['reason'],
            ];
            if (isset($decision['unlock_at'])) { $access['unlock_at'] = $decision['unlock_at']; }
            return wpultra_ok(['access' => $access]);
        }

        case 'dashboard': {
            $user_id = (int) ($input['user_id'] ?? 0);
            if ($user_id <= 0) { return wpultra_err('missing_user', 'user_id is required.'); }
            return wpultra_ok(['dashboard' => wpultra_member_dashboard($user_id)]);
        }
    }

    return wpultra_err('bad_action', "Unknown action '$action'.");
}
