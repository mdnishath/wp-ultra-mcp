<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

function wpultra_woo_product_schema(): array {
    return [
        'name'               => ['type' => 'string',  'writable' => true],
        'slug'               => ['type' => 'string',  'writable' => true],
        'type'               => ['type' => 'enum', 'enum' => ['simple', 'variable', 'grouped', 'external'], 'writable' => true],
        'status'             => ['type' => 'enum', 'enum' => ['publish', 'draft', 'pending', 'private'], 'writable' => true],
        'catalog_visibility' => ['type' => 'enum', 'enum' => ['visible', 'catalog', 'search', 'hidden'], 'writable' => true],
        'featured'           => ['type' => 'bool',   'writable' => true],
        'description'        => ['type' => 'string', 'writable' => true],
        'short_description'  => ['type' => 'string', 'writable' => true],
        'sku'                => ['type' => 'string', 'writable' => true],
        'regular_price'      => ['type' => 'money',  'writable' => true],
        'sale_price'         => ['type' => 'money',  'writable' => true],
        'manage_stock'       => ['type' => 'bool',   'writable' => true],
        'stock_quantity'     => ['type' => 'int',    'writable' => true],
        'stock_status'       => ['type' => 'enum', 'enum' => ['instock', 'outofstock', 'onbackorder'], 'writable' => true],
        'backorders'         => ['type' => 'enum', 'enum' => ['no', 'notify', 'yes'], 'writable' => true],
        'weight'             => ['type' => 'string', 'writable' => true],
        'length'             => ['type' => 'string', 'writable' => true],
        'width'              => ['type' => 'string', 'writable' => true],
        'height'             => ['type' => 'string', 'writable' => true],
        'virtual'            => ['type' => 'bool',   'writable' => true],
        'downloadable'       => ['type' => 'bool',   'writable' => true],
        'category_ids'       => ['type' => 'array',  'writable' => true],
        'tag_ids'            => ['type' => 'array',  'writable' => true],
        'image_id'           => ['type' => 'int',    'writable' => true],
        'gallery_image_ids'  => ['type' => 'array',  'writable' => true],
        'menu_order'         => ['type' => 'int',    'writable' => true],
        'external_url'       => ['type' => 'string', 'writable' => true],
        'button_text'        => ['type' => 'string', 'writable' => true],
    ];
}

function wpultra_woo_coerce_money($v): ?string {
    if ($v === '' || $v === null) { return null; }
    if (!is_numeric($v)) { return null; }
    $n = 0 + $v;
    if ($n < 0) { $n = 0; } // clamp negatives to zero — prices are never negative
    return (string) $n;
}

function wpultra_woo_coerce_bool($v): bool {
    if (is_bool($v)) { return $v; }
    if (is_int($v)) { return $v !== 0; }
    $s = strtolower(trim((string) $v));
    return in_array($s, ['1', 'yes', 'true', 'on'], true);
}

function wpultra_woo_validate_product(array $input): array {
    $schema = wpultra_woo_product_schema();
    $clean = [];
    $rejected = [];
    foreach ($input as $field => $value) {
        if (!isset($schema[$field])) {
            $rejected[] = ['field' => $field, 'reason' => 'unknown_field'];
            continue;
        }
        $def = $schema[$field];
        switch ($def['type']) {
            case 'enum':
                if (!in_array($value, $def['enum'], true)) {
                    $rejected[] = ['field' => $field, 'reason' => 'invalid_enum'];
                    continue 2;
                }
                $clean[$field] = $value;
                break;
            case 'money':
                $m = wpultra_woo_coerce_money($value);
                if ($m === null && $value !== '' && $value !== null) {
                    $rejected[] = ['field' => $field, 'reason' => 'invalid_type'];
                    continue 2;
                }
                $clean[$field] = $m;
                break;
            case 'bool':
                $clean[$field] = wpultra_woo_coerce_bool($value);
                break;
            case 'int':
                $clean[$field] = (int) $value;
                break;
            case 'array':
                $clean[$field] = is_array($value) ? array_values($value) : [$value];
                break;
            default:
                $clean[$field] = (string) $value;
        }
    }
    return ['clean' => $clean, 'rejected' => $rejected];
}

function wpultra_woo_customer_schema(): array {
    return [
        'email'      => ['type' => 'email'],
        'first_name' => ['type' => 'string'],
        'last_name'  => ['type' => 'string'],
        'username'   => ['type' => 'string'],
        'password'   => ['type' => 'string'],
        'role'       => ['type' => 'string'],
        'billing'    => ['type' => 'array'],
        'shipping'   => ['type' => 'array'],
    ];
}

function wpultra_woo_validate_customer(array $input): array {
    $schema = wpultra_woo_customer_schema();
    $clean = [];
    $rejected = [];
    foreach ($input as $field => $value) {
        if (!isset($schema[$field])) { $rejected[] = ['field' => $field, 'reason' => 'unknown_field']; continue; }
        if ($schema[$field]['type'] === 'email') {
            if (!is_string($value) || strpos($value, '@') === false || strpos($value, '.') === false) {
                $rejected[] = ['field' => $field, 'reason' => 'invalid_email'];
                continue;
            }
            $clean[$field] = $value;
        } elseif ($schema[$field]['type'] === 'array') {
            $clean[$field] = is_array($value) ? $value : [];
        } else {
            $clean[$field] = (string) $value;
        }
    }
    return ['clean' => $clean, 'rejected' => $rejected];
}
