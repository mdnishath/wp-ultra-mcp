<?php
declare(strict_types=1);
if (!defined('ABSPATH') && !defined('WPULTRA_TEST')) { /* allow harness load */ }

/** PURE. Build LocalBusiness JSON-LD from a stored record. */
function wpultra_seo_build_local_jsonld(array $d): array {
    $type = (string) ($d['type'] ?? 'LocalBusiness');
    $j = ['@context' => 'https://schema.org', '@type' => $type !== '' ? $type : 'LocalBusiness'];
    if (!empty($d['name'])) { $j['name'] = (string) $d['name']; }
    if (!empty($d['url'])) { $j['url'] = (string) $d['url']; }
    if (!empty($d['phone'])) { $j['telephone'] = (string) $d['phone']; }
    if (!empty($d['price_range'])) { $j['priceRange'] = (string) $d['price_range']; }
    if (!empty($d['logo'])) { $j['logo'] = (string) $d['logo']; }
    $addr = array_filter([
        'streetAddress' => $d['street'] ?? '', 'addressLocality' => $d['city'] ?? '',
        'addressRegion' => $d['region'] ?? '', 'postalCode' => $d['postal'] ?? '', 'addressCountry' => $d['country'] ?? '',
    ]);
    if ($addr) { $j['address'] = ['@type' => 'PostalAddress'] + $addr; }
    if (!empty($d['lat']) && !empty($d['lng'])) { $j['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (string) $d['lat'], 'longitude' => (string) $d['lng']]; }
    if (!empty($d['hours']) && is_array($d['hours'])) { $j['openingHours'] = array_values(array_map('strval', $d['hours'])); }
    return $j;
}

function wpultra_seo_local_get(): array {
    $d = get_option('wpultra_seo_local', []);
    return is_array($d) ? $d : [];
}
function wpultra_seo_local_set(array $data): array {
    $allowed = ['name', 'type', 'url', 'phone', 'price_range', 'logo', 'street', 'city', 'region', 'postal', 'country', 'lat', 'lng', 'hours'];
    $clean = [];
    foreach ($allowed as $k) { if (array_key_exists($k, $data)) { $clean[$k] = $data[$k]; } }
    update_option('wpultra_seo_local', $clean);
    return $clean;
}
add_action('wp_head', 'wpultra_seo_render_local', 6);
function wpultra_seo_render_local() {
    if (!is_front_page() && !is_home()) { return; }
    $d = wpultra_seo_local_get();
    if (empty($d['name'])) { return; }
    echo "\n<script type=\"application/ld+json\">" . wp_json_encode(wpultra_seo_build_local_jsonld($d)) . "</script>\n"; // phpcs:ignore
}
