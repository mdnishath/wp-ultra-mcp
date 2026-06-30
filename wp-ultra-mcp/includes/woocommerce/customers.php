<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_woo_customer_row($c): array {
    return [
        'id'          => $c->get_id(),
        'email'       => $c->get_email(),
        'name'        => trim($c->get_first_name() . ' ' . $c->get_last_name()),
        'orders'      => wc_get_customer_order_count($c->get_id()),
        'total_spent' => wc_get_customer_total_spent($c->get_id()),
    ];
}

function wpultra_woo_customer_full($c): array {
    return array_merge(wpultra_woo_customer_row($c), [
        'first_name' => $c->get_first_name(),
        'last_name'  => $c->get_last_name(),
        'username'   => $c->get_username(),
        'billing'    => $c->get_billing(),
        'shipping'   => $c->get_shipping(),
        'date_created' => $c->get_date_created() ? $c->get_date_created()->date('c') : null,
    ]);
}

function wpultra_woo_list_customers(array $args): array {
    $q = [
        'role'   => 'customer',
        'number' => isset($args['per_page']) ? (int) $args['per_page'] : 20,
        'paged'  => isset($args['page']) ? max(1, (int) $args['page']) : 1,
        'fields' => 'ID',
    ];
    if (!empty($args['search'])) { $q['search'] = '*' . $args['search'] . '*'; $q['search_columns'] = ['user_email', 'display_name']; }
    $ids = get_users($q);
    $rows = [];
    foreach ($ids as $uid) {
        $c = new WC_Customer((int) $uid);
        if ($c->get_id()) { $rows[] = wpultra_woo_customer_row($c); }
    }
    return ['count' => count($rows), 'customers' => $rows];
}

function wpultra_woo_get_customer(int $id) {
    if ($id <= 0) { return wpultra_err('customer_not_found', 'No customer id given.'); }
    $c = new WC_Customer($id);
    if (!$c->get_id()) { return wpultra_err('customer_not_found', "No customer with id $id."); }
    return wpultra_woo_customer_full($c);
}

function wpultra_woo_upsert_customer(array $input) {
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    unset($input['id']);
    $validated = wpultra_woo_validate_customer($input);
    $clean = $validated['clean'];

    if ($id) {
        $c = new WC_Customer($id);
        if (!$c->get_id()) { return wpultra_err('customer_not_found', "No customer with id $id."); }
    } else {
        if (empty($clean['email'])) { return wpultra_err('email_required', 'Creating a customer requires a valid email.'); }
        $c = new WC_Customer();
    }

    $setters = [
        'email' => 'set_email', 'first_name' => 'set_first_name', 'last_name' => 'set_last_name',
        'username' => 'set_username', 'password' => 'set_password', 'role' => 'set_role',
    ];
    foreach ($setters as $field => $method) {
        if (array_key_exists($field, $clean) && method_exists($c, $method)) {
            try { $c->{$method}($clean[$field]); } catch (\Throwable $e) { $validated['rejected'][] = ['field' => $field, 'reason' => 'setter_error']; }
        }
    }
    if (!empty($clean['billing']) && is_array($clean['billing']))   { $c->set_billing($clean['billing']); }
    if (!empty($clean['shipping']) && is_array($clean['shipping'])) { $c->set_shipping($clean['shipping']); }

    $newId = $c->save();
    if (!$newId) { return wpultra_err('customer_save_failed', 'WC_Customer save() returned 0.'); }
    return ['id' => (int) $newId, 'rejected' => $validated['rejected']];
}
