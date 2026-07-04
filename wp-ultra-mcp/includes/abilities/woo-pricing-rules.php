<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/woocommerce/pricing.php — require it
// defensively so this ability works regardless of load order (mirrors
// woo-bulk-edit leaning on its engine file).
if (!function_exists('wpultra_pricing_validate') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/woocommerce/pricing.php')) {
    require_once WPULTRA_DIR . 'includes/woocommerce/pricing.php';
}

wp_register_ability('wpultra/woo-pricing-rules', [
    'label'       => __('WooCommerce: Dynamic Pricing & Discount Rules', 'wp-ultra-mcp'),
    'description' => __(
        'Manage dynamic pricing rules: quantity tiers, BOGO deals, cart-total discounts, and role-based pricing — applied live to the cart. '
        . 'actions: create | update | get | list | delete (confirm-gated) | enable | disable | preview. '
        . 'Rule types + config: '
        . 'tiered_qty {tiers:[{min_qty, discount_pct}]} — per-line quantity tiers, the highest min_qty <= cart qty wins (e.g. buy 3+ get 10% off, 10+ get 20%). '
        . 'bogo {buy_qty, get_qty, discount_pct} — for every COMPLETE (buy_qty+get_qty) group in a line\'s quantity, get_qty units get discount_pct off (100 = free); incomplete groups get nothing. Applied as one negative cart fee named after the rule. '
        . 'cart_discount {min_total, discount_pct | amount} — when the cart item subtotal reaches min_total, discount the subtotal by a percent OR a flat amount (exactly one; flat amount capped at the subtotal). Also a negative fee. '
        . 'role_price {role, discount_pct} — percent off for logged-in users with that role (e.g. "wholesale"). '
        . 'scope (all types): {products: "all" | [ids], categories?: [slugs]} — categories, when given, are an ADDITIONAL constraint (product must also be in one of them). '
        . 'IMPORTANT: item-level rules (tiered_qty, role_price) do NOT stack — the single best (largest) percentage per cart item wins. bogo and cart_discount apply as separate negative fees on top. '
        . 'Rules are created DISABLED by default (pass enabled:true or run enable); enabling/disabling takes effect on the next page load. '
        . 'Examples: {action:"create", name:"Bulk tea discount", type:"tiered_qty", scope:{products:"all", categories:["tea"]}, config:{tiers:[{min_qty:3, discount_pct:10},{min_qty:10, discount_pct:20}]}, enabled:true} · '
        . '{action:"create", name:"Buy 2 Get 1 Free", type:"bogo", scope:{products:[42]}, config:{buy_qty:2, get_qty:1, discount_pct:100}} · '
        . '{action:"create", name:"Spend 5000 save 5%", type:"cart_discount", scope:{products:"all"}, config:{min_total:5000, discount_pct:5}} · '
        . '{action:"create", name:"Wholesale 15%", type:"role_price", scope:{products:"all"}, config:{role:"wholesale", discount_pct:15}}. '
        . 'preview = safe dry-run: {action:"preview", cart:[{product_id:42, qty:3}], role:"wholesale"} — real prices and category slugs are looked up per product; pass an explicit price (and optional categories) per line for hypothetical carts. Returns per-line before/after totals, the fees that would be added, and cart totals {before, discount, after} — no store changes. '
        . 'delete requires confirm:true.',
        'wp-ultra-mcp'
    ),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['create', 'update', 'get', 'list', 'delete', 'enable', 'disable', 'preview'],
            ],
            'id'      => ['type' => 'string', 'description' => 'Rule id (pr-xxxxxx) — required for update/get/delete/enable/disable.'],
            'name'    => ['type' => 'string'],
            'type'    => ['type' => 'string', 'enum' => ['tiered_qty', 'bogo', 'cart_discount', 'role_price']],
            'enabled' => ['type' => 'boolean', 'description' => 'create only — start enabled (default false).'],
            'scope'   => [
                'type'       => 'object',
                'properties' => [
                    'products'   => ['type' => ['array', 'string'], 'items' => ['type' => 'integer'], 'description' => "'all' or an array of product IDs."],
                    'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional additional constraint: product_cat slugs.'],
                ],
                'additionalProperties' => false,
            ],
            'config'  => [
                'type'       => 'object',
                'properties' => [
                    'tiers'        => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'min_qty'      => ['type' => 'integer'],
                                'discount_pct' => ['type' => 'number'],
                            ],
                        ],
                    ],
                    'buy_qty'      => ['type' => 'integer'],
                    'get_qty'      => ['type' => 'integer'],
                    'discount_pct' => ['type' => 'number'],
                    'min_total'    => ['type' => 'number'],
                    'amount'       => ['type' => 'number'],
                    'role'         => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'cart'    => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'qty'        => ['type' => 'integer'],
                        'price'      => ['type' => 'number', 'description' => 'Optional explicit unit price (for hypothetical lines); real store price used when omitted.'],
                        'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
            'role'    => ['type' => 'string', 'description' => 'preview only — simulate this user role (defaults to the current user\'s role).'],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'rule'    => ['type' => 'object'],
            'rules'   => ['type' => 'array'],
            'count'   => ['type' => 'integer'],
            'preview' => ['type' => 'object'],
            'deleted' => ['type' => 'string'],
            'note'    => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_pricing_rules_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_woo_pricing_rules_cb(array $input) {
    if (!function_exists('wpultra_woo_active') || !wpultra_woo_active()) {
        return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.');
    }
    if (!function_exists('wpultra_pricing_validate')) {
        return wpultra_err('pricing_engine_missing', 'The pricing engine (includes/woocommerce/pricing.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');
    $rules = wpultra_pricing_get_rules();

    switch ($action) {
        case 'create': {
            $rule = [
                'name'   => (string) ($input['name'] ?? ''),
                'type'   => (string) ($input['type'] ?? ''),
                'scope'  => is_array($input['scope'] ?? null) ? $input['scope'] : ['products' => 'all'],
                'config' => is_array($input['config'] ?? null) ? $input['config'] : [],
            ];
            $valid = wpultra_pricing_validate($rule);
            if ($valid !== true) { return wpultra_err('invalid_rule', (string) $valid); }

            do { $id = wpultra_pricing_new_id(); } while (isset($rules[$id]));
            $rule['id']         = $id;
            $rule['enabled']    = ($input['enabled'] ?? false) === true;
            $rule['created_at'] = gmdate('Y-m-d H:i:s');
            $rules[$id] = $rule;
            wpultra_pricing_save_rules($rules);
            wpultra_audit_log('woo-pricing-rules', "create $id type={$rule['type']} name={$rule['name']} enabled=" . ($rule['enabled'] ? '1' : '0'), true);
            return wpultra_ok([
                'rule' => $rule,
                'note' => $rule['enabled']
                    ? 'Created enabled — live on the next page load.'
                    : "Created disabled. Run {action:'enable', id:'$id'} to activate.",
            ]);
        }

        case 'update': {
            $id = (string) ($input['id'] ?? '');
            if ($id === '' || !isset($rules[$id]) || !is_array($rules[$id])) {
                return wpultra_err('rule_not_found', "No pricing rule with id '$id'. Use {action:'list'} to see rules.");
            }
            $rule = $rules[$id];
            if (array_key_exists('name', $input))   { $rule['name'] = (string) $input['name']; }
            if (array_key_exists('type', $input))   { $rule['type'] = (string) $input['type']; }
            if (array_key_exists('scope', $input))  { $rule['scope'] = is_array($input['scope']) ? $input['scope'] : $rule['scope']; }
            if (array_key_exists('config', $input)) { $rule['config'] = is_array($input['config']) ? $input['config'] : $rule['config']; }

            $valid = wpultra_pricing_validate($rule);
            if ($valid !== true) { return wpultra_err('invalid_rule', (string) $valid); }

            $rules[$id] = $rule;
            wpultra_pricing_save_rules($rules);
            wpultra_audit_log('woo-pricing-rules', "update $id type={$rule['type']}", true);
            return wpultra_ok(['rule' => $rule, 'note' => 'Updated — changes apply on the next page load.']);
        }

        case 'get': {
            $id = (string) ($input['id'] ?? '');
            if ($id === '' || !isset($rules[$id]) || !is_array($rules[$id])) {
                return wpultra_err('rule_not_found', "No pricing rule with id '$id'. Use {action:'list'} to see rules.");
            }
            return wpultra_ok(['rule' => $rules[$id]]);
        }

        case 'list': {
            $out = [];
            foreach ($rules as $rule) {
                if (is_array($rule)) { $out[] = wpultra_pricing_summarize($rule); }
            }
            return wpultra_ok(['rules' => $out, 'count' => count($out)]);
        }

        case 'enable':
        case 'disable': {
            $id = (string) ($input['id'] ?? '');
            if ($id === '' || !isset($rules[$id]) || !is_array($rules[$id])) {
                return wpultra_err('rule_not_found', "No pricing rule with id '$id'. Use {action:'list'} to see rules.");
            }
            $rules[$id]['enabled'] = ($action === 'enable');
            wpultra_pricing_save_rules($rules);
            wpultra_audit_log('woo-pricing-rules', "$action $id", true);
            return wpultra_ok([
                'rule' => $rules[$id],
                'note' => ucfirst($action) . 'd — takes effect on the next page load.',
            ]);
        }

        case 'delete': {
            $id = (string) ($input['id'] ?? '');
            if ($id === '' || !isset($rules[$id])) {
                return wpultra_err('rule_not_found', "No pricing rule with id '$id'. Use {action:'list'} to see rules.");
            }
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('pricing_delete_unconfirmed', "Deleting rule '$id' is destructive. Re-run with confirm:true.");
            }
            unset($rules[$id]);
            wpultra_pricing_save_rules($rules);
            wpultra_audit_log('woo-pricing-rules', "delete $id", true);
            return wpultra_ok(['deleted' => $id, 'note' => 'Rule deleted — stops applying on the next page load.']);
        }

        case 'preview': {
            $cart = is_array($input['cart'] ?? null) ? $input['cart'] : [];
            if ($cart === []) {
                return wpultra_err('preview_empty_cart', 'preview requires cart: a non-empty array of {product_id, qty, price?} lines.');
            }
            $role = isset($input['role']) && is_string($input['role']) && $input['role'] !== ''
                ? $input['role']
                : wpultra_pricing_current_role();
            $result = wpultra_pricing_preview_wp($cart, $role);
            if (is_wp_error($result)) { return $result; }
            return wpultra_ok(['preview' => $result, 'note' => 'Dry-run only — nothing was changed.']);
        }
    }

    return wpultra_err('unknown_action', "Unknown action '$action'. Use create|update|get|list|delete|enable|disable|preview.");
}
