<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/**
 * Per-email whitelisted settings keys (persisted in option 'woocommerce_<id>_settings').
 * Only these keys may be written by wpultra_woo_email_update().
 */
function wpultra_woo_email_whitelist(): array {
    return ['enabled', 'subject', 'heading', 'additional_content', 'recipient', 'email_type'];
}

/** Global email design/branding option keys (persisted individually via update_option). */
function wpultra_woo_email_globals_whitelist(): array {
    return [
        'woocommerce_email_from_name',
        'woocommerce_email_from_address',
        'woocommerce_email_header_image',
        'woocommerce_email_footer_text',
        'woocommerce_email_base_color',
        'woocommerce_email_background_color',
        'woocommerce_email_body_background_color',
        'woocommerce_email_text_color',
    ];
}

/** Global keys whose value must be a hex color (#rgb or #rrggbb). */
function wpultra_woo_email_color_keys(): array {
    return [
        'woocommerce_email_base_color',
        'woocommerce_email_background_color',
        'woocommerce_email_body_background_color',
        'woocommerce_email_text_color',
    ];
}

/** Email ids that are addressed to the store admin (vs. the customer) — recipient is editable. */
function wpultra_woo_email_admin_ids(): array {
    return [
        'new_order', 'cancelled_order', 'failed_order',
    ];
}

/**
 * Pure: split $in (assoc array of settings keys => values) into accepted/rejected
 * against the per-email whitelist. Does not validate values, only key membership.
 * Returns ['accepted' => [...], 'rejected' => [...]].
 */
function wpultra_woo_email_whitelist_filter(array $in): array {
    $whitelist = wpultra_woo_email_whitelist();
    $accepted = [];
    $rejected = [];
    foreach ($in as $key => $val) {
        if (!in_array($key, $whitelist, true)) {
            $rejected[] = ['key' => (string) $key, 'reason' => 'not_whitelisted'];
            continue;
        }
        $accepted[$key] = $val;
    }
    return ['accepted' => $accepted, 'rejected' => $rejected];
}

/** Pure: true if $s looks like a syntactically valid single email address. */
function wpultra_woo_email_is_valid_address(string $s): bool {
    $s = trim($s);
    if ($s === '') { return false; }
    return (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $s);
}

/** Pure: parse a comma-separated recipient list; true only if every entry is a valid address. */
function wpultra_woo_email_is_valid_recipient_list(string $s): bool {
    $s = trim($s);
    if ($s === '') { return false; }
    $parts = array_map('trim', explode(',', $s));
    foreach ($parts as $p) {
        if ($p === '' || !wpultra_woo_email_is_valid_address($p)) { return false; }
    }
    return true;
}

/** Pure: true if $s is a #rgb or #rrggbb hex color. */
function wpultra_woo_email_is_hex_color(string $s): bool {
    return (bool) preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($s));
}

/**
 * Pure: validate a per-email settings map (already whitelist-filtered).
 * Returns true on success, or a string reason on the first failure.
 */
function wpultra_woo_email_validate(array $in): mixed {
    if (array_key_exists('enabled', $in)) {
        $v = $in['enabled'];
        $ok = ($v === 'yes' || $v === 'no' || is_bool($v));
        if (!$ok) { return 'invalid_enabled'; }
    }
    if (array_key_exists('email_type', $in)) {
        if (!in_array($in['email_type'], ['plain', 'html', 'multipart'], true)) {
            return 'invalid_email_type';
        }
    }
    if (array_key_exists('recipient', $in)) {
        $r = $in['recipient'];
        if (!is_string($r) || !wpultra_woo_email_is_valid_recipient_list($r)) {
            return 'invalid_recipient';
        }
    }
    foreach (['subject', 'heading', 'additional_content'] as $k) {
        if (array_key_exists($k, $in) && !is_string($in[$k])) { return "invalid_$k"; }
    }
    return true;
}

/**
 * Pure: validate a globals settings map (already whitelist-filtered).
 * Returns true on success, or a string reason on the first failure.
 */
function wpultra_woo_email_globals_validate(array $in): mixed {
    $color_keys = wpultra_woo_email_color_keys();
    foreach ($in as $key => $val) {
        if (in_array($key, $color_keys, true)) {
            if (!is_string($val) || !wpultra_woo_email_is_hex_color($val)) { return "invalid_color:$key"; }
            continue;
        }
        if ($key === 'woocommerce_email_from_address') {
            if (!is_string($val) || !wpultra_woo_email_is_valid_address($val)) { return 'invalid_from_address'; }
            continue;
        }
        if (!is_string($val)) { return "invalid_type:$key"; }
    }
    return true;
}

/** Normalize an 'enabled' value to WooCommerce's yes/no string form. */
function wpultra_woo_email_coerce_enabled($v): string {
    if (is_bool($v)) { return $v ? 'yes' : 'no'; }
    return ((string) $v === 'yes') ? 'yes' : 'no';
}

/** List all registered transactional emails with their id/title/enabled/subject/recipient. */
function wpultra_woo_email_list(): array {
    $rows = [];
    if (!function_exists('WC') || !WC()->mailer()) { return $rows; }
    $admin_ids = wpultra_woo_email_admin_ids();
    foreach (WC()->mailer()->get_emails() as $email) {
        $row = [
            'id'      => $email->id,
            'title'   => $email->get_title(),
            'enabled' => ($email->is_enabled() ? true : false),
            'subject' => $email->get_option('subject', $email->get_default_subject()),
        ];
        if (in_array($email->id, $admin_ids, true)) {
            $row['recipient'] = $email->get_option('recipient', get_option('admin_email'));
        }
        $rows[] = $row;
    }
    return $rows;
}

/** Get one email's full editable settings + WooCommerce's own defaults, or null if not found. */
function wpultra_woo_email_get(string $id): ?array {
    if (!function_exists('WC') || !WC()->mailer()) { return null; }
    foreach (WC()->mailer()->get_emails() as $email) {
        if ($email->id !== $id) { continue; }
        $is_admin = in_array($email->id, wpultra_woo_email_admin_ids(), true);
        $out = [
            'id'                 => $email->id,
            'title'              => $email->get_title(),
            'description'        => method_exists($email, 'get_description') ? $email->get_description() : '',
            'enabled'            => $email->get_option('enabled', 'no'),
            'subject'            => $email->get_option('subject', $email->get_default_subject()),
            'heading'            => $email->get_option('heading', method_exists($email, 'get_default_heading') ? $email->get_default_heading() : ''),
            'additional_content' => $email->get_option('additional_content', ''),
            'email_type'         => $email->get_option('email_type', 'html'),
            'defaults'           => [
                'subject' => method_exists($email, 'get_default_subject') ? $email->get_default_subject() : '',
                'heading' => method_exists($email, 'get_default_heading') ? $email->get_default_heading() : '',
            ],
        ];
        if ($is_admin) {
            $out['recipient'] = $email->get_option('recipient', get_option('admin_email'));
        }
        return $out;
    }
    return null;
}

/**
 * Update one email's settings: merge whitelisted keys into option 'woocommerce_<id>_settings'.
 * Returns ['updated' => [...], 'rejected' => [...]] or a WP_Error if the email id is unknown.
 */
function wpultra_woo_email_update(string $id, array $settings) {
    if (!function_exists('WC') || !WC()->mailer()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    $found = null;
    foreach (WC()->mailer()->get_emails() as $email) {
        if ($email->id === $id) { $found = $email; break; }
    }
    if (!$found) { return wpultra_err('email_not_found', "No transactional email with id '$id'."); }

    $is_admin = in_array($id, wpultra_woo_email_admin_ids(), true);
    $filtered = wpultra_woo_email_whitelist_filter($settings);
    $accepted = $filtered['accepted'];
    $rejected = $filtered['rejected'];

    // recipient is only editable for admin-facing emails.
    if (array_key_exists('recipient', $accepted) && !$is_admin) {
        unset($accepted['recipient']);
        $rejected[] = ['key' => 'recipient', 'reason' => 'not_editable_for_customer_email'];
    }

    $check = wpultra_woo_email_validate($accepted);
    if ($check !== true) { return wpultra_err('invalid_settings', "Validation failed: $check"); }

    if (array_key_exists('enabled', $accepted)) {
        $accepted['enabled'] = wpultra_woo_email_coerce_enabled($accepted['enabled']);
    }

    $opt_key = 'woocommerce_' . $id . '_settings';
    $existing = get_option($opt_key, []);
    if (!is_array($existing)) { $existing = []; }
    $merged = array_merge($existing, $accepted);
    update_option($opt_key, $merged);

    return ['updated' => $accepted, 'rejected' => $rejected];
}

/** Read current global email design/branding settings + their whitelist. */
function wpultra_woo_email_globals_get(): array {
    $out = [];
    foreach (wpultra_woo_email_globals_whitelist() as $key) {
        $out[$key] = get_option($key, '');
    }
    return $out;
}

/**
 * Update global email design/branding settings: only whitelisted keys, colors hex-validated.
 * Returns ['updated' => [...], 'rejected' => [...]].
 */
function wpultra_woo_email_globals_update(array $settings): array {
    $whitelist = wpultra_woo_email_globals_whitelist();
    $accepted = [];
    $rejected = [];
    foreach ($settings as $key => $val) {
        if (!in_array($key, $whitelist, true)) {
            $rejected[] = ['key' => (string) $key, 'reason' => 'not_whitelisted'];
            continue;
        }
        $accepted[$key] = $val;
    }

    $check = wpultra_woo_email_globals_validate($accepted);
    if ($check !== true) {
        // Pull the offending key out of "invalid_color:<key>" / "invalid_type:<key>" and reject just that one,
        // then re-validate the remainder so unrelated keys still get applied.
        $reason = (string) $check;
        $bad_key = strpos($reason, ':') !== false ? substr($reason, strpos($reason, ':') + 1) : '';
        $updated = [];
        foreach ($accepted as $k => $v) {
            if ($k === $bad_key) { $rejected[] = ['key' => $k, 'reason' => explode(':', $reason)[0]]; continue; }
            $updated[$k] = $v;
        }
        $accepted = $updated;
        $recheck = wpultra_woo_email_globals_validate($accepted);
        if ($recheck !== true) { return ['updated' => [], 'rejected' => $rejected]; }
    }

    foreach ($accepted as $key => $val) {
        update_option($key, $val);
    }
    return ['updated' => $accepted, 'rejected' => $rejected];
}
