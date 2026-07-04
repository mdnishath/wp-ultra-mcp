<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Directory / business-listings vertical (roadmap E5).
 *
 * A drop-in local-directory engine: a listing CPT with front-end URLs, a
 * category taxonomy, geo (map / near-me) search, front-end submission with
 * moderation, and a featured/monetize hook.
 *
 * Storage:
 *   CPT `wpultra_listing`  — public true (listings get front-end permalinks),
 *                            show_ui false (managed via this ability, not wp-admin).
 *     post_title   = listing name
 *     post_content = description
 *     post_status  = pending (front-end submissions) | publish | draft (rejected)
 *   taxonomy `wpultra_listing_cat` — listing categories.
 *   meta `_wpultra_listing` = {
 *     address, lat, lng, phone, website, email, hours, price_range,
 *     featured: bool, featured_until: unix|null, status: pending|published|rejected,
 *     submitted_by, created_at, updated_at
 *   }
 *
 * MONETIZATION MODEL — a listing can be "featured" (featured=true) until a unix
 * timestamp (featured_until; null = never expires). Featured listings float to
 * the top of search results (wpultra_dir_rank). The `feature` ability action sets
 * that flag for N days — the natural paid-placement hook. If WooCommerce is
 * present this COULD be wired to a paid product purchase, but the engine itself
 * only sets the flag; payment integration is left to the caller (documented, guarded).
 *
 * FRONT-END SUBMIT / MODERATION FLOW — the [wpultra_directory_submit] shortcode
 * renders a form; a submission (nonce-checked, rate-limited) creates a `pending`
 * listing via wpultra_dir_sanitize_submission(). It is invisible in normal search
 * until an admin moderates it (pending -> published | rejected).
 *
 * PURE functions first (prefix wpultra_dir_, no WordPress calls — harness-loadable,
 * unit-tested by tests/directory.test.php); thin WordPress wrappers after.
 */

if (!defined('WPULTRA_DIR_CPT')) { define('WPULTRA_DIR_CPT', 'wpultra_listing'); }
if (!defined('WPULTRA_DIR_TAX')) { define('WPULTRA_DIR_TAX', 'wpultra_listing_cat'); }

/* =====================================================================
 * PURE core — no WordPress calls (harness-loadable).
 * ===================================================================== */

/** Valid listing statuses. Pure. */
function wpultra_dir_statuses(): array {
    return ['pending', 'published', 'rejected'];
}

/** Valid price_range tokens (empty allowed = unset). Pure. */
function wpultra_dir_price_ranges(): array {
    return ['$', '$$', '$$$', '$$$$'];
}

/** Lowercase helper that survives missing mbstring. Pure. */
function wpultra_dir_lc(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
}

/** Length-cap helper that survives missing mbstring. Pure. */
function wpultra_dir_trim_len(string $s, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/** Strict-ish email shape check (full-string match). Pure. */
function wpultra_dir_email_valid(string $email): bool {
    return (bool) preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email);
}

/** Loose website/URL shape check (http/https + host). Pure. */
function wpultra_dir_website_valid(string $url): bool {
    return (bool) preg_match('~^https?://[^\s/$.?\#].[^\s]*$~i', $url);
}

/** Loose phone shape: digits, spaces, +-(). and at least 5 digits. Pure. */
function wpultra_dir_phone_valid(string $phone): bool {
    if (!preg_match('/^[0-9 +().\-]+$/', $phone)) { return false; }
    return preg_match_all('/\d/', $phone) >= 5;
}

/**
 * PURE. Great-circle distance in kilometres between two lat/lng coordinates
 * (haversine formula). The map / near-me core. Symmetric; same point -> 0.0.
 */
function wpultra_dir_haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earth = 6371.0088; // mean earth radius, km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2) ** 2);
    $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    return $earth * $c;
}

/** PURE. True when a listing has coords AND both are numeric in valid range. */
function wpultra_dir_has_coords(array $listing): bool {
    if (!array_key_exists('lat', $listing) || !array_key_exists('lng', $listing)) { return false; }
    $lat = $listing['lat'];
    $lng = $listing['lng'];
    if ($lat === null || $lng === null || $lat === '' || $lng === '') { return false; }
    if (!is_numeric($lat) || !is_numeric($lng)) { return false; }
    return wpultra_dir_latlng_in_range((float) $lat, (float) $lng);
}

/** PURE. True when lat in [-90,90] and lng in [-180,180]. */
function wpultra_dir_latlng_in_range(float $lat, float $lng): bool {
    return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
}

/**
 * PURE. Is a listing within $radius_km of ($lat,$lng)? A listing without valid
 * coords is never within radius (false). Edge (exactly on the radius) counts as inside.
 */
function wpultra_dir_within_radius(array $listing, float $lat, float $lng, float $radius_km): bool {
    if (!wpultra_dir_has_coords($listing)) { return false; }
    $d = wpultra_dir_haversine((float) $listing['lat'], (float) $listing['lng'], $lat, $lng);
    return $d <= $radius_km;
}

/**
 * PURE. Attach a `distance_km` (rounded 3dp) to each listing and return the list
 * sorted by distance ascending. Listings without valid coords get distance null
 * and are placed LAST, preserving their relative order. Non-mutating.
 */
function wpultra_dir_sort_by_distance(array $listings, float $lat, float $lng): array {
    $indexed = [];
    $i = 0;
    foreach ($listings as $l) {
        if (!is_array($l)) { continue; }
        if (wpultra_dir_has_coords($l)) {
            $d = wpultra_dir_haversine((float) $l['lat'], (float) $l['lng'], $lat, $lng);
            $l['distance_km'] = round($d, 3);
        } else {
            $l['distance_km'] = null;
        }
        $indexed[] = ['i' => $i++, 'l' => $l];
    }
    usort($indexed, function ($a, $b) {
        $da = $a['l']['distance_km'];
        $db = $b['l']['distance_km'];
        // null (no coords) sorts last; keep stable order among equals via original index.
        if ($da === null && $db === null) { return $a['i'] <=> $b['i']; }
        if ($da === null) { return 1; }
        if ($db === null) { return -1; }
        if ($da === $db) { return $a['i'] <=> $b['i']; }
        return $da < $db ? -1 : 1;
    });
    return array_map(static fn($x) => $x['l'], $indexed);
}

/**
 * PURE. Does a listing carry category $cat? Matches against a `category` scalar
 * or a `categories` array (both slug-style, case-insensitive). Pure.
 */
function wpultra_dir_in_category(array $listing, string $cat): bool {
    $cat = wpultra_dir_lc(trim($cat));
    if ($cat === '') { return true; }
    $cats = [];
    if (isset($listing['category']) && is_scalar($listing['category'])) {
        $cats[] = wpultra_dir_lc((string) $listing['category']);
    }
    if (isset($listing['categories']) && is_array($listing['categories'])) {
        foreach ($listing['categories'] as $c) {
            if (is_scalar($c)) { $cats[] = wpultra_dir_lc((string) $c); }
        }
    }
    return in_array($cat, $cats, true);
}

/**
 * PURE. Filter a list of listings by:
 *   category      — exact (slug, case-insensitive) match against category/categories
 *   price_range   — exact token match ($, $$, ...)
 *   search        — case-insensitive substring against name/title + address
 *   featured_only — keep only listings flagged featured (raw flag, no expiry check)
 * All filters are AND-combined; absent/empty filters are no-ops. Order preserved. Pure.
 */
function wpultra_dir_filter(array $listings, array $filters): array {
    $cat    = wpultra_dir_lc(trim((string) ($filters['category'] ?? '')));
    $price  = trim((string) ($filters['price_range'] ?? ''));
    $search = wpultra_dir_lc(trim((string) ($filters['search'] ?? '')));
    $featOnly = !empty($filters['featured_only']);

    $out = [];
    foreach ($listings as $l) {
        if (!is_array($l)) { continue; }
        if ($cat !== '' && !wpultra_dir_in_category($l, $cat)) { continue; }
        if ($price !== '' && (string) ($l['price_range'] ?? '') !== $price) { continue; }
        if ($featOnly && empty($l['featured'])) { continue; }
        if ($search !== '') {
            $name = (string) ($l['name'] ?? ($l['title'] ?? ''));
            $hay  = wpultra_dir_lc($name . ' ' . (string) ($l['address'] ?? ''));
            if (!str_contains($hay, $search)) { continue; }
        }
        $out[] = $l;
    }
    return $out;
}

/**
 * PURE. Is a listing featured RIGHT NOW ($now = unix)? Featured AND
 * (featured_until is null/absent OR strictly in the future). A past
 * featured_until means the paid placement has lapsed -> false. Pure.
 */
function wpultra_dir_is_featured(array $listing, int $now): bool {
    if (empty($listing['featured'])) { return false; }
    $until = $listing['featured_until'] ?? null;
    if ($until === null || $until === '') { return true; }
    if (!is_numeric($until)) { return false; }
    return (int) $until > $now;
}

/**
 * PURE. Rank listings for display: currently-featured listings float to the top
 * (the monetization hook), the rest keep their incoming order. Within each group
 * the incoming order is preserved (stable) — so a caller can pre-sort by distance
 * or date and rank() only lifts featured items above the pack. Non-mutating.
 */
function wpultra_dir_rank(array $listings, int $now): array {
    $featured = [];
    $normal   = [];
    foreach ($listings as $l) {
        if (!is_array($l)) { continue; }
        if (wpultra_dir_is_featured($l, $now)) { $featured[] = $l; }
        else { $normal[] = $l; }
    }
    return array_merge($featured, $normal);
}

/**
 * PURE. Validate a listing meta/input array. Returns true, or an error string.
 * Rules:
 *   - name required (non-empty after trim)
 *   - lat/lng, when either is given, must both be present, numeric, in range
 *   - email (when present) must be a valid shape
 *   - website (when present) must be http(s) URL
 *   - phone (when present) must be phone-shaped
 *   - status (when present) must be one of the enum
 *   - price_range (when present) must be one of the tokens
 * Pure.
 */
function wpultra_dir_validate(array $listing) {
    $name = trim((string) ($listing['name'] ?? ($listing['title'] ?? '')));
    if ($name === '') { return 'Listing name is required.'; }

    $hasLat = array_key_exists('lat', $listing) && $listing['lat'] !== null && $listing['lat'] !== '';
    $hasLng = array_key_exists('lng', $listing) && $listing['lng'] !== null && $listing['lng'] !== '';
    if ($hasLat || $hasLng) {
        if (!$hasLat || !$hasLng) { return 'Both lat and lng are required when either is given.'; }
        if (!is_numeric($listing['lat']) || !is_numeric($listing['lng'])) { return 'lat/lng must be numeric.'; }
        if (!wpultra_dir_latlng_in_range((float) $listing['lat'], (float) $listing['lng'])) {
            return 'lat must be within [-90,90] and lng within [-180,180].';
        }
    }

    $email = trim((string) ($listing['email'] ?? ''));
    if ($email !== '' && !wpultra_dir_email_valid($email)) { return "Invalid email format: $email"; }

    $website = trim((string) ($listing['website'] ?? ''));
    if ($website !== '' && !wpultra_dir_website_valid($website)) { return "Invalid website URL: $website"; }

    $phone = trim((string) ($listing['phone'] ?? ''));
    if ($phone !== '' && !wpultra_dir_phone_valid($phone)) { return "Invalid phone number: $phone"; }

    if (array_key_exists('status', $listing) && $listing['status'] !== null && $listing['status'] !== '') {
        if (!in_array((string) $listing['status'], wpultra_dir_statuses(), true)) {
            return "Unknown status '{$listing['status']}'. Allowed: " . implode(', ', wpultra_dir_statuses());
        }
    }

    if (array_key_exists('price_range', $listing) && $listing['price_range'] !== null && $listing['price_range'] !== '') {
        if (!in_array((string) $listing['price_range'], wpultra_dir_price_ranges(), true)) {
            return "Unknown price_range '{$listing['price_range']}'. Allowed: " . implode(', ', wpultra_dir_price_ranges());
        }
    }

    return true;
}

/**
 * PURE. Coerce a raw front-end-submitted listing into a safe, bounded field set.
 * Strips unknown keys, casts/clamps types and lengths, drops out-of-range coords
 * and malformed price_range. The submit form runs its output through here before
 * persisting. Always yields status=pending and featured=false (front-end can never
 * self-feature or self-publish). Pure.
 *
 * @return array{name,description,address,lat,lng,phone,website,email,hours,price_range,categories,featured:false,featured_until:null,status:'pending'}
 */
function wpultra_dir_sanitize_submission(array $raw): array {
    $str = static function ($v, int $len): string {
        if (is_array($v) || is_object($v)) { return ''; }
        return wpultra_dir_trim_len(trim((string) $v), $len);
    };

    $out = [
        'name'        => $str($raw['name'] ?? ($raw['title'] ?? ''), 200),
        'description' => $str($raw['description'] ?? ($raw['content'] ?? ''), 5000),
        'address'     => $str($raw['address'] ?? '', 300),
        'phone'       => $str($raw['phone'] ?? '', 60),
        'website'     => $str($raw['website'] ?? '', 300),
        'email'       => wpultra_dir_lc($str($raw['email'] ?? '', 200)),
        'hours'       => $str($raw['hours'] ?? '', 500),
        'lat'         => null,
        'lng'         => null,
        'price_range' => '',
        'categories'  => [],
        'featured'       => false,
        'featured_until' => null,
        'status'         => 'pending',
    ];

    // Coords: keep only when both valid & in range.
    $lat = $raw['lat'] ?? null;
    $lng = $raw['lng'] ?? null;
    if (is_numeric($lat) && is_numeric($lng) && wpultra_dir_latlng_in_range((float) $lat, (float) $lng)) {
        $out['lat'] = (float) $lat;
        $out['lng'] = (float) $lng;
    }

    // price_range: keep only a known token.
    $pr = trim((string) ($raw['price_range'] ?? ''));
    if (in_array($pr, wpultra_dir_price_ranges(), true)) { $out['price_range'] = $pr; }

    // categories: array of slug-ish strings (cap 10 / 60 chars each), deduped.
    $rawCats = $raw['categories'] ?? ($raw['category'] ?? []);
    if (is_scalar($rawCats)) { $rawCats = [$rawCats]; }
    if (is_array($rawCats)) {
        foreach ($rawCats as $c) {
            if (!is_scalar($c)) { continue; }
            $c = wpultra_dir_trim_len(trim((string) $c), 60);
            if ($c === '' || in_array($c, $out['categories'], true)) { continue; }
            $out['categories'][] = $c;
            if (count($out['categories']) >= 10) { break; }
        }
    }

    return $out;
}

/**
 * PURE. Canonical output shape for one listing (meta blob + id + optional
 * distance). Used by list/search/get results. Pure.
 */
function wpultra_dir_shape(array $meta, int $id, string $permalink = ''): array {
    return [
        'id'             => $id,
        'name'           => (string) ($meta['name'] ?? ''),
        'address'        => (string) ($meta['address'] ?? ''),
        'lat'            => isset($meta['lat']) && is_numeric($meta['lat']) ? (float) $meta['lat'] : null,
        'lng'            => isset($meta['lng']) && is_numeric($meta['lng']) ? (float) $meta['lng'] : null,
        'phone'          => (string) ($meta['phone'] ?? ''),
        'website'        => (string) ($meta['website'] ?? ''),
        'email'          => (string) ($meta['email'] ?? ''),
        'hours'          => (string) ($meta['hours'] ?? ''),
        'price_range'    => (string) ($meta['price_range'] ?? ''),
        'categories'     => is_array($meta['categories'] ?? null) ? array_values(array_map('strval', $meta['categories'])) : [],
        'featured'       => !empty($meta['featured']),
        'featured_until' => isset($meta['featured_until']) && is_numeric($meta['featured_until']) ? (int) $meta['featured_until'] : null,
        'status'         => (string) ($meta['status'] ?? 'published'),
        'submitted_by'   => (int) ($meta['submitted_by'] ?? 0),
        'permalink'      => $permalink,
        'created_at'     => (int) ($meta['created_at'] ?? 0),
        'updated_at'     => (int) ($meta['updated_at'] ?? 0),
    ];
}

/* =====================================================================
 * WordPress wrappers — CPT, taxonomy, CRUD, search, submit, moderate, feature.
 * Every WP call is function_exists-guarded so the file stays harness-loadable.
 * ===================================================================== */

/** Register the listing CPT (public -> front-end permalinks; show_ui false). */
function wpultra_dir_register_cpt(): void {
    if (!function_exists('register_post_type')) { return; }
    register_post_type(WPULTRA_DIR_CPT, [
        'public'       => true,
        'show_ui'      => false,
        'show_in_rest' => false,
        'has_archive'  => true,
        'supports'     => ['title', 'editor', 'thumbnail'],
        'rewrite'      => ['slug' => 'listing'],
        'labels'       => ['name' => 'Directory Listings', 'singular_name' => 'Listing'],
    ]);
}

/** Register the listing-category taxonomy. */
function wpultra_dir_register_taxonomy(): void {
    if (!function_exists('register_taxonomy')) { return; }
    register_taxonomy(WPULTRA_DIR_TAX, WPULTRA_DIR_CPT, [
        'public'       => true,
        'show_ui'      => false,
        'show_in_rest' => false,
        'hierarchical' => true,
        'rewrite'      => ['slug' => 'listing-category'],
        'labels'       => ['name' => 'Listing Categories', 'singular_name' => 'Listing Category'],
    ]);
}

/** Map a listing meta status to a WP post_status. */
function wpultra_dir_status_to_post_status(string $status): string {
    switch ($status) {
        case 'published': return 'publish';
        case 'rejected':  return 'draft';
        case 'pending':
        default:          return 'pending';
    }
}

/** Load a listing's meta blob. Null when the id is not a listing. */
function wpultra_dir_load(int $id): ?array {
    if (!function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_DIR_CPT) { return null; }
    $meta = get_post_meta($id, '_wpultra_listing', true);
    $meta = is_array($meta) ? $meta : [];
    // Back-fill display fields from the post object.
    if (!isset($meta['name']) || $meta['name'] === '') { $meta['name'] = (string) $post->post_title; }
    if (!isset($meta['status'])) {
        $meta['status'] = $post->post_status === 'publish' ? 'published'
            : ($post->post_status === 'pending' ? 'pending' : 'rejected');
    }
    if (!isset($meta['categories'])) {
        $meta['categories'] = wpultra_dir_get_listing_categories($id);
    }
    return $meta;
}

/** Return a listing's category slugs. */
function wpultra_dir_get_listing_categories(int $id): array {
    if (!function_exists('wp_get_object_terms')) { return []; }
    $terms = wp_get_object_terms($id, WPULTRA_DIR_TAX, ['fields' => 'slugs']);
    return is_array($terms) ? array_values(array_map('strval', $terms)) : [];
}

/** Persist meta blob + sync post_title / post_status / categories. */
function wpultra_dir_save(int $id, array $meta): void {
    if (!function_exists('update_post_meta')) { return; }
    update_post_meta($id, '_wpultra_listing', $meta);
    if (function_exists('get_post') && function_exists('wp_update_post')) {
        $post = get_post($id);
        $title = trim((string) ($meta['name'] ?? ''));
        $newStatus = wpultra_dir_status_to_post_status((string) ($meta['status'] ?? 'pending'));
        $update = ['ID' => $id];
        $dirty = false;
        if ($title !== '' && $post && $post->post_title !== $title) {
            $update['post_title'] = function_exists('wp_slash') ? wp_slash($title) : $title;
            $dirty = true;
        }
        if (array_key_exists('description', $meta) && $post && $post->post_content !== (string) $meta['description']) {
            $update['post_content'] = function_exists('wp_slash') ? wp_slash((string) $meta['description']) : (string) $meta['description'];
            $dirty = true;
        }
        if ($post && $post->post_status !== $newStatus) {
            $update['post_status'] = $newStatus;
            $dirty = true;
        }
        if ($dirty) { wp_update_post($update); }
    }
    // Sync categories onto the taxonomy.
    if (isset($meta['categories']) && is_array($meta['categories']) && function_exists('wp_set_object_terms')) {
        $slugs = array_values(array_filter(array_map('strval', $meta['categories'])));
        wp_set_object_terms($id, $slugs, WPULTRA_DIR_TAX, false);
    }
}

/**
 * Upsert a listing. $input is a raw listing array; validated first. When
 * $input['id'] is set an existing listing is updated (merged), else a new one is
 * created. @return int|WP_Error listing id.
 */
function wpultra_dir_upsert(array $input) {
    $valid = wpultra_dir_validate($input);
    if ($valid !== true) { return wpultra_err('invalid_listing', (string) $valid); }
    if (!function_exists('wp_insert_post')) {
        return wpultra_err('wp_unavailable', 'WordPress post functions are unavailable.');
    }
    $now = function_exists('time') ? time() : 0;
    $id  = (int) ($input['id'] ?? 0);

    if ($id > 0) {
        $meta = wpultra_dir_load($id);
        if ($meta === null) { return wpultra_err('not_found', "Listing $id not found."); }
    } else {
        $meta = [
            'featured' => false, 'featured_until' => null,
            'status' => 'published', 'submitted_by' => 0, 'created_at' => $now,
        ];
    }

    // Merge only provided keys.
    foreach (['name', 'description', 'address', 'lat', 'lng', 'phone', 'website', 'email', 'hours', 'price_range', 'status', 'categories', 'submitted_by'] as $k) {
        if (array_key_exists($k, $input)) { $meta[$k] = $input[$k]; }
    }
    if (array_key_exists('featured', $input)) { $meta['featured'] = (bool) $input['featured']; }
    if (array_key_exists('featured_until', $input)) {
        $meta['featured_until'] = ($input['featured_until'] === null || $input['featured_until'] === '')
            ? null : (int) $input['featured_until'];
    }
    $meta['updated_at'] = $now;

    if ($id > 0) {
        wpultra_dir_save($id, $meta);
        return $id;
    }

    $newId = wp_insert_post([
        'post_type'    => WPULTRA_DIR_CPT,
        'post_status'  => wpultra_dir_status_to_post_status((string) ($meta['status'] ?? 'published')),
        'post_title'   => function_exists('wp_slash') ? wp_slash((string) ($meta['name'] ?? '')) : (string) ($meta['name'] ?? ''),
        'post_content' => function_exists('wp_slash') ? wp_slash((string) ($meta['description'] ?? '')) : (string) ($meta['description'] ?? ''),
    ], true);
    if (is_wp_error($newId)) { return $newId; }
    wpultra_dir_save((int) $newId, $meta);
    return (int) $newId;
}

/** Delete a listing. @return true|WP_Error */
function wpultra_dir_delete(int $id) {
    if (wpultra_dir_load($id) === null) { return wpultra_err('not_found', "Listing $id not found."); }
    if (!function_exists('wp_delete_post')) { return wpultra_err('wp_unavailable', 'wp_delete_post unavailable.'); }
    $res = wp_delete_post($id, true);
    return $res ? true : wpultra_err('delete_failed', "Could not delete listing $id.");
}

/**
 * Query listings (newest-first, scan-capped) as [{id, meta}, ...] for a given
 * post_status set, then hand off to the pure filter/sort/rank. Returns raw
 * {id,meta} items; the ability shapes them.
 */
function wpultra_dir_query(array $statuses = ['publish'], int $scan = 500): array {
    if (!function_exists('get_posts')) { return []; }
    $ids = get_posts([
        'post_type'        => WPULTRA_DIR_CPT,
        'post_status'      => $statuses,
        'numberposts'      => max(1, $scan),
        'orderby'          => 'date',
        'order'            => 'DESC',
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);
    $items = [];
    foreach ((array) $ids as $id) {
        $meta = wpultra_dir_load((int) $id);
        if ($meta !== null) {
            $meta['id'] = (int) $id;
            $items[] = $meta;
        }
    }
    return $items;
}

/**
 * The full search pipeline over published listings: pure filter -> optional geo
 * distance sort (when lat/lng given) -> featured rank. Returns shaped rows.
 */
function wpultra_dir_search(array $filters): array {
    $now = function_exists('time') ? time() : 0;
    $rows = wpultra_dir_query(['publish']);

    // radius pre-filter (near-me) when coords + radius supplied.
    $hasGeo = isset($filters['lat'], $filters['lng']) && is_numeric($filters['lat']) && is_numeric($filters['lng']);
    if ($hasGeo && isset($filters['radius_km']) && is_numeric($filters['radius_km']) && (float) $filters['radius_km'] > 0) {
        $lat = (float) $filters['lat']; $lng = (float) $filters['lng']; $r = (float) $filters['radius_km'];
        $rows = array_values(array_filter($rows, static fn($l) => wpultra_dir_within_radius($l, $lat, $lng, $r)));
    }

    $rows = wpultra_dir_filter($rows, $filters);

    if ($hasGeo) {
        $rows = wpultra_dir_sort_by_distance($rows, (float) $filters['lat'], (float) $filters['lng']);
    }
    $rows = wpultra_dir_rank($rows, $now);

    $out = [];
    foreach ($rows as $l) {
        $id = (int) ($l['id'] ?? 0);
        $shaped = wpultra_dir_shape($l, $id, wpultra_dir_permalink($id));
        if (array_key_exists('distance_km', $l)) { $shaped['distance_km'] = $l['distance_km']; }
        $out[] = $shaped;
    }
    return $out;
}

/** A listing's front-end permalink (empty when WP unavailable). */
function wpultra_dir_permalink(int $id): string {
    if ($id <= 0 || !function_exists('get_permalink')) { return ''; }
    $url = get_permalink($id);
    return is_string($url) ? $url : '';
}

/* -------------------- category (taxonomy) management -------------------- */

/** Create/ensure a listing category term. @return int|WP_Error term_id. */
function wpultra_dir_category_upsert(string $name, string $slug = '', int $parent = 0) {
    $name = trim($name);
    if ($name === '') { return wpultra_err('missing_name', 'Category name is required.'); }
    if (!function_exists('wp_insert_term') || !function_exists('term_exists')) {
        return wpultra_err('wp_unavailable', 'Taxonomy functions unavailable.');
    }
    $args = [];
    if ($slug !== '') { $args['slug'] = $slug; }
    if ($parent > 0) { $args['parent'] = $parent; }
    $existing = term_exists($slug !== '' ? $slug : $name, WPULTRA_DIR_TAX);
    if (is_array($existing) && isset($existing['term_id'])) {
        return (int) $existing['term_id'];
    }
    $res = wp_insert_term($name, WPULTRA_DIR_TAX, $args);
    if (is_wp_error($res)) { return $res; }
    return (int) ($res['term_id'] ?? 0);
}

/** Delete a listing category term by id. @return true|WP_Error */
function wpultra_dir_category_delete(int $term_id) {
    if (!function_exists('wp_delete_term')) { return wpultra_err('wp_unavailable', 'wp_delete_term unavailable.'); }
    $res = wp_delete_term($term_id, WPULTRA_DIR_TAX);
    if (is_wp_error($res)) { return $res; }
    return $res ? true : wpultra_err('delete_failed', "Could not delete category $term_id.");
}

/** List all listing categories: [{id, name, slug, count, parent}, ...]. */
function wpultra_dir_categories(): array {
    if (!function_exists('get_terms')) { return []; }
    $terms = get_terms(['taxonomy' => WPULTRA_DIR_TAX, 'hide_empty' => false]);
    if (is_wp_error($terms) || !is_array($terms)) { return []; }
    $out = [];
    foreach ($terms as $t) {
        $out[] = [
            'id'     => (int) ($t->term_id ?? 0),
            'name'   => (string) ($t->name ?? ''),
            'slug'   => (string) ($t->slug ?? ''),
            'count'  => (int) ($t->count ?? 0),
            'parent' => (int) ($t->parent ?? 0),
        ];
    }
    return $out;
}

/* -------------------- moderation -------------------- */

/**
 * Moderate a pending listing: 'publish' -> status published; 'reject' -> rejected.
 * @return true|WP_Error
 */
function wpultra_dir_moderate(int $id, string $decision) {
    $meta = wpultra_dir_load($id);
    if ($meta === null) { return wpultra_err('not_found', "Listing $id not found."); }
    if ($decision === 'publish') { $meta['status'] = 'published'; }
    elseif ($decision === 'reject') { $meta['status'] = 'rejected'; }
    else { return wpultra_err('bad_decision', "decision must be 'publish' or 'reject'."); }
    $meta['updated_at'] = function_exists('time') ? time() : 0;
    wpultra_dir_save($id, $meta);
    return true;
}

/** Pending listings awaiting moderation, shaped. */
function wpultra_dir_submissions(): array {
    $rows = wpultra_dir_query(['pending']);
    $out = [];
    foreach ($rows as $l) {
        $id = (int) ($l['id'] ?? 0);
        $out[] = wpultra_dir_shape($l, $id, wpultra_dir_permalink($id));
    }
    return $out;
}

/* -------------------- feature (monetize hook) -------------------- */

/**
 * Feature a listing for $days days (featured_until = now + days*86400; 0 days =
 * featured forever, featured_until null). The monetization hook — a caller may
 * gate this behind a WooCommerce purchase, but the engine only sets the flag.
 * @return array|WP_Error {featured, featured_until}
 */
function wpultra_dir_feature(int $id, int $days) {
    $meta = wpultra_dir_load($id);
    if ($meta === null) { return wpultra_err('not_found', "Listing $id not found."); }
    $now = function_exists('time') ? time() : 0;
    $meta['featured'] = true;
    $meta['featured_until'] = $days > 0 ? $now + ($days * 86400) : null;
    $meta['updated_at'] = $now;
    wpultra_dir_save($id, $meta);
    return ['featured' => true, 'featured_until' => $meta['featured_until']];
}

/** Un-feature a listing. @return true|WP_Error */
function wpultra_dir_unfeature(int $id) {
    $meta = wpultra_dir_load($id);
    if ($meta === null) { return wpultra_err('not_found', "Listing $id not found."); }
    $meta['featured'] = false;
    $meta['featured_until'] = null;
    $meta['updated_at'] = function_exists('time') ? time() : 0;
    wpultra_dir_save($id, $meta);
    return true;
}

/* -------------------- front-end submit + list shortcodes -------------------- */

/**
 * Front-end submission handler for the [wpultra_directory_submit] form. Verifies
 * the nonce, applies a per-IP rate limit, sanitizes the payload and creates a
 * `pending` listing. Returns a status string for the form to echo.
 */
function wpultra_dir_handle_submit(): string {
    if (($_POST['wpultra_dir_submit'] ?? '') === '') { return ''; }
    // Nonce.
    if (function_exists('wp_verify_nonce')) {
        $nonce = (string) ($_POST['wpultra_dir_nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'wpultra_dir_submit')) {
            return 'error:invalid_nonce';
        }
    }
    // Rate limit: max 3 submissions / 10 min per IP.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : 'anon';
    $key = 'wpultra_dir_rl_' . md5((string) $ip);
    if (function_exists('get_transient') && function_exists('set_transient')) {
        $count = (int) get_transient($key);
        if ($count >= 3) { return 'error:rate_limited'; }
        set_transient($key, $count + 1, 600);
    }

    $clean = wpultra_dir_sanitize_submission(wpultra_dir_wp_unslash_deep($_POST));
    $valid = wpultra_dir_validate($clean);
    if ($valid !== true) { return 'error:' . $valid; }

    $clean['status'] = 'pending';
    $clean['featured'] = false;
    $clean['featured_until'] = null;
    $clean['submitted_by'] = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $res = wpultra_dir_upsert($clean);
    if (is_wp_error($res)) { return 'error:' . $res->get_error_message(); }
    return 'ok:' . (int) $res;
}

/** wp_unslash a whole array if available, else return as-is. */
function wpultra_dir_wp_unslash_deep($data) {
    if (function_exists('wp_unslash')) { return wp_unslash($data); }
    return $data;
}

/** [wpultra_directory] — a search/list widget (renders results server-side). */
function wpultra_dir_shortcode_list($atts = []): string {
    $atts = is_array($atts) ? $atts : [];
    $filters = [
        'search'      => isset($_GET['dir_q']) ? (string) $_GET['dir_q'] : (string) ($atts['search'] ?? ''),
        'category'    => isset($_GET['dir_cat']) ? (string) $_GET['dir_cat'] : (string) ($atts['category'] ?? ''),
        'price_range' => (string) ($atts['price_range'] ?? ''),
    ];
    if (isset($_GET['dir_lat'], $_GET['dir_lng']) && is_numeric($_GET['dir_lat']) && is_numeric($_GET['dir_lng'])) {
        $filters['lat'] = (float) $_GET['dir_lat'];
        $filters['lng'] = (float) $_GET['dir_lng'];
        if (isset($_GET['dir_radius']) && is_numeric($_GET['dir_radius'])) { $filters['radius_km'] = (float) $_GET['dir_radius']; }
    }
    $esc = function_exists('esc_html') ? 'esc_html' : static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $rows = wpultra_dir_search($filters);
    $html = '<div class="wpultra-directory">';
    if (empty($rows)) {
        $html .= '<p>' . $esc('No listings found.') . '</p>';
    } else {
        $html .= '<ul class="wpultra-directory-list">';
        foreach ($rows as $r) {
            $badge = !empty($r['featured']) ? ' <span class="wpultra-featured">★ Featured</span>' : '';
            $dist  = isset($r['distance_km']) && $r['distance_km'] !== null ? ' <span class="wpultra-dist">(' . $esc((string) $r['distance_km']) . ' km)</span>' : '';
            $link  = $r['permalink'] !== '' ? $r['permalink'] : '#';
            $html .= '<li><a href="' . $esc($link) . '">' . $esc($r['name']) . '</a>' . $badge . $dist;
            if ($r['address'] !== '') { $html .= '<br><small>' . $esc($r['address']) . '</small>'; }
            $html .= '</li>';
        }
        $html .= '</ul>';
    }
    $html .= '</div>';
    return $html;
}

/** [wpultra_directory_submit] — front-end submit form + handler. */
function wpultra_dir_shortcode_submit($atts = []): string {
    $notice = wpultra_dir_handle_submit();
    $esc = function_exists('esc_attr') ? 'esc_attr' : static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $nonceField = function_exists('wp_nonce_field') ? wp_nonce_field('wpultra_dir_submit', 'wpultra_dir_nonce', true, false) : '';
    $msg = '';
    if (str_starts_with($notice, 'ok:')) { $msg = '<p class="wpultra-ok">Thanks! Your listing was submitted and is awaiting review.</p>'; }
    elseif (str_starts_with($notice, 'error:')) { $msg = '<p class="wpultra-error">' . htmlspecialchars(substr($notice, 6), ENT_QUOTES) . '</p>'; }

    $html  = '<div class="wpultra-directory-submit">' . $msg;
    $html .= '<form method="post">' . $nonceField;
    $html .= '<p><label>Name*<br><input type="text" name="name" required maxlength="200"></label></p>';
    $html .= '<p><label>Address<br><input type="text" name="address" maxlength="300"></label></p>';
    $html .= '<p><label>Phone<br><input type="text" name="phone" maxlength="60"></label></p>';
    $html .= '<p><label>Website<br><input type="url" name="website" maxlength="300"></label></p>';
    $html .= '<p><label>Email<br><input type="email" name="email" maxlength="200"></label></p>';
    $html .= '<p><label>Description<br><textarea name="description" maxlength="5000"></textarea></label></p>';
    $html .= '<p><button type="submit" name="wpultra_dir_submit" value="1">Submit listing</button></p>';
    $html .= '</form></div>';
    return $html;
}

/* -------------------- boot -------------------- */

/**
 * Runtime contract: the controller calls this from the always-on runtime. Cheap:
 * registers the CPT + taxonomy on init and the two shortcodes.
 */
function wpultra_dir_boot(): void {
    if (!function_exists('add_action')) { return; }

    $register = static function (): void {
        wpultra_dir_register_cpt();
        wpultra_dir_register_taxonomy();
    };
    if (function_exists('did_action') && did_action('init')) {
        $register();
    } else {
        add_action('init', $register);
    }

    if (function_exists('add_shortcode')) {
        add_shortcode('wpultra_directory', 'wpultra_dir_shortcode_list');
        add_shortcode('wpultra_directory_submit', 'wpultra_dir_shortcode_submit');
    }
}
