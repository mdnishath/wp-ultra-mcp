<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_woo_coupon_full(WC_Coupon $c): array {
    return [
        'id'            => $c->get_id(),
        'code'          => $c->get_code(),
        'discount_type' => $c->get_discount_type(),
        'amount'        => $c->get_amount(),
        'description'   => $c->get_description(),
        'free_shipping' => $c->get_free_shipping(),
        'date_expires'  => $c->get_date_expires() ? $c->get_date_expires()->date('Y-m-d') : null,
        'minimum_amount' => $c->get_minimum_amount(),
        'maximum_amount' => $c->get_maximum_amount(),
        'usage_limit'   => $c->get_usage_limit(),
        'usage_count'   => $c->get_usage_count(),
        'product_ids'   => $c->get_product_ids(),
        'excluded_product_ids' => $c->get_excluded_product_ids(),
        'individual_use' => $c->get_individual_use(),
    ];
}

function wpultra_woo_manage_coupon(array $input) {
    $action = (string) ($input['action'] ?? 'list');

    if ($action === 'list') {
        $ids = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => (int) ($input['per_page'] ?? 50), 'fields' => 'ids']);
        $rows = [];
        foreach ($ids as $pid) { $c = new WC_Coupon((int) $pid); $rows[] = ['id' => $c->get_id(), 'code' => $c->get_code(), 'discount_type' => $c->get_discount_type(), 'amount' => $c->get_amount()]; }
        return ['count' => count($rows), 'coupons' => $rows];
    }

    $idOrCode = $input['id'] ?? ($input['code'] ?? '');
    if ($action === 'get') {
        $c = new WC_Coupon($idOrCode);
        if (!$c->get_id()) { return wpultra_err('coupon_not_found', 'No such coupon.'); }
        return wpultra_woo_coupon_full($c);
    }
    if ($action === 'delete') {
        $c = new WC_Coupon($idOrCode);
        if (!$c->get_id()) { return wpultra_err('coupon_not_found', 'No such coupon.'); }
        $cid = $c->get_id();
        $c->delete(!empty($input['force']));
        return ['id' => $cid, 'deleted' => true];
    }

    // create or update
    $c = ($action === 'update') ? new WC_Coupon($idOrCode) : new WC_Coupon();
    if ($action === 'update' && !$c->get_id()) { return wpultra_err('coupon_not_found', 'No such coupon to update.'); }
    if ($action === 'create') {
        $code = (string) ($input['code'] ?? '');
        if ($code === '') { return wpultra_err('code_required', 'Creating a coupon requires a code.'); }
        $c->set_code($code);
    }
    if (isset($input['discount_type'])) { $c->set_discount_type((string) $input['discount_type']); }
    if (isset($input['amount'])) {
        $amount = (string) $input['amount'];
        // Percent coupons are a 0..100 range; clamp so amount=500 can't mean 500% off.
        if ($c->get_discount_type() === 'percent') {
            $amount = (string) max(0, min(100, (float) $amount));
        }
        $c->set_amount($amount);
    }
    if (isset($input['description']))   { $c->set_description((string) $input['description']); }
    if (isset($input['free_shipping'])) { $c->set_free_shipping((bool) $input['free_shipping']); }
    if (isset($input['date_expires']))  { $c->set_date_expires((string) $input['date_expires']); }
    if (isset($input['minimum_amount'])) { $c->set_minimum_amount((string) $input['minimum_amount']); }
    if (isset($input['maximum_amount'])) { $c->set_maximum_amount((string) $input['maximum_amount']); }
    if (isset($input['usage_limit']))   { $c->set_usage_limit((int) $input['usage_limit']); }
    if (isset($input['individual_use'])) { $c->set_individual_use((bool) $input['individual_use']); }
    if (isset($input['product_ids']) && is_array($input['product_ids'])) { $c->set_product_ids(array_map('intval', $input['product_ids'])); }
    if (isset($input['excluded_product_ids']) && is_array($input['excluded_product_ids'])) { $c->set_excluded_product_ids(array_map('intval', $input['excluded_product_ids'])); }
    $id = $c->save();
    if (!$id) { return wpultra_err('coupon_save_failed', 'save() returned 0.'); }
    return ['id' => (int) $id, 'code' => $c->get_code()];
}
