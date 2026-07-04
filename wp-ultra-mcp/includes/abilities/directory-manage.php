<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine so this ability works regardless of the
// controller's load order (mirrors woo-bulk-edit / analytics-report).
if (!function_exists('wpultra_dir_search') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/verticals/directory.php')) {
    require_once WPULTRA_DIR . 'includes/verticals/directory.php';
}

wp_register_ability('wpultra/directory-manage', [
    'label'       => __('Directory: Manage Listings & Categories', 'wp-ultra-mcp'),
    'description' => __(
        'Run a full local business directory / listings site: a `wpultra_listing` CPT (front-end permalinks) '
        . 'with a `wpultra_listing_cat` category taxonomy, geo (map / near-me) search, front-end submission with '
        . 'moderation, and a featured/monetize hook. Pick an action: '
        . 'manage-listing (upsert a listing: name*, description, address, lat, lng, phone, website, email, hours, price_range ($..$$$$), categories[], status (pending|published|rejected); pass id to update, omit to create) — '
        . 'list (all listings, optional status filter) — get {id} (one listing) — '
        . 'search {term?, category?, price_range?, featured_only?, lat?, lng?, radius_km?}: filters published listings by name/address substring + category + price; when lat/lng are given it distance-sorts (near-me, haversine km) and radius_km pre-filters to that circle; FEATURED listings always float to the top (the paid-placement hook) — '
        . 'manage-category (upsert a category: name*, slug?, parent?) — list-categories — '
        . 'moderate {id, decision: publish|reject}: front-end submissions land as `pending` and are invisible to search until published here — '
        . 'submissions: the pending listings awaiting moderation — '
        . 'feature {id, days}: mark a listing featured until now + days (0 days = featured forever); featured listings rank first in search. This is the monetization hook — a caller MAY gate it behind a WooCommerce purchase, but this ability only sets the flag. Use the unfeature {id} action to clear it. '
        . 'Front-end submit flow: the [wpultra_directory_submit] shortcode renders a nonce-checked, rate-limited form that creates pending listings; [wpultra_directory] renders a search/list widget. '
        . 'Examples: {action:"search", term:"pizza", lat:40.7128, lng:-74.006, radius_km:5} = "pizza places within 5 km of NYC, nearest first, featured on top". '
        . '{action:"feature", id:42, days:30} = "promote listing 42 for 30 days". '
        . '{action:"moderate", id:99, decision:"publish"} = "approve a pending submission".',
        'wp-ultra-mcp'
    ),
    'category'    => 'verticals',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'  => [
                'type' => 'string',
                'enum' => ['manage-listing', 'list', 'get', 'search', 'manage-category', 'list-categories', 'moderate', 'feature', 'unfeature', 'submissions', 'delete'],
            ],
            // manage-listing / get / moderate / feature / delete
            'id'          => ['type' => 'integer'],
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'address'     => ['type' => 'string'],
            'lat'         => ['type' => 'number'],
            'lng'         => ['type' => 'number'],
            'phone'       => ['type' => 'string'],
            'website'     => ['type' => 'string'],
            'email'       => ['type' => 'string'],
            'hours'       => ['type' => 'string'],
            'price_range' => ['type' => 'string', 'enum' => ['', '$', '$$', '$$$', '$$$$']],
            'categories'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'status'      => ['type' => 'string', 'enum' => ['pending', 'published', 'rejected']],
            'featured'    => ['type' => 'boolean'],
            // search
            'term'          => ['type' => 'string'],
            'category'      => ['type' => 'string'],
            'featured_only' => ['type' => 'boolean'],
            'radius_km'     => ['type' => 'number'],
            'limit'         => ['type' => 'integer'],
            // manage-category
            'slug'   => ['type' => 'string'],
            'parent' => ['type' => 'integer'],
            // list filter
            'status_filter' => ['type' => 'string', 'enum' => ['pending', 'published', 'rejected', 'any']],
            // moderate
            'decision' => ['type' => 'string', 'enum' => ['publish', 'reject']],
            // feature
            'days' => ['type' => 'integer'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'action'     => ['type' => 'string'],
            'id'         => ['type' => 'integer'],
            'listing'    => ['type' => 'object'],
            'listings'   => ['type' => 'array'],
            'categories' => ['type' => 'array'],
            'count'      => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_directory_manage_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_directory_manage_cb(array $input) {
    if (!function_exists('wpultra_dir_search')) {
        return wpultra_err('directory_engine_missing', 'The directory engine (includes/verticals/directory.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'manage-listing': {
            $fields = ['id', 'name', 'description', 'address', 'lat', 'lng', 'phone', 'website', 'email', 'hours', 'price_range', 'categories', 'status', 'featured'];
            $listing = [];
            foreach ($fields as $f) { if (array_key_exists($f, $input)) { $listing[$f] = $input[$f]; } }
            $res = wpultra_dir_upsert($listing);
            if (is_wp_error($res)) {
                wpultra_audit_log('directory-manage', 'manage-listing failed: ' . $res->get_error_message(), false);
                return $res;
            }
            $id = (int) $res;
            $meta = wpultra_dir_load($id);
            wpultra_audit_log('directory-manage', "manage-listing id=$id", true);
            return wpultra_ok([
                'action'  => $action,
                'id'      => $id,
                'listing' => $meta !== null ? wpultra_dir_shape($meta, $id, wpultra_dir_permalink($id)) : [],
            ]);
        }

        case 'delete': {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) { return wpultra_err('missing_id', 'delete requires an id.'); }
            $res = wpultra_dir_delete($id);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('directory-manage', "delete id=$id", true);
            return wpultra_ok(['action' => $action, 'id' => $id]);
        }

        case 'get': {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) { return wpultra_err('missing_id', 'get requires an id.'); }
            $meta = wpultra_dir_load($id);
            if ($meta === null) { return wpultra_err('not_found', "Listing $id not found."); }
            return wpultra_ok([
                'action'  => $action,
                'id'      => $id,
                'listing' => wpultra_dir_shape($meta, $id, wpultra_dir_permalink($id)),
            ]);
        }

        case 'list': {
            $filter = (string) ($input['status_filter'] ?? 'any');
            $map = ['published' => ['publish'], 'pending' => ['pending'], 'rejected' => ['draft'], 'any' => ['publish', 'pending', 'draft']];
            $statuses = $map[$filter] ?? $map['any'];
            $rows = wpultra_dir_query($statuses);
            $limit = (int) ($input['limit'] ?? 200);
            if ($limit > 0 && count($rows) > $limit) { $rows = array_slice($rows, 0, $limit); }
            $listings = [];
            foreach ($rows as $l) {
                $id = (int) ($l['id'] ?? 0);
                $listings[] = wpultra_dir_shape($l, $id, wpultra_dir_permalink($id));
            }
            return wpultra_ok(['action' => $action, 'listings' => $listings, 'count' => count($listings)]);
        }

        case 'search': {
            $filters = [
                'search'        => (string) ($input['term'] ?? ''),
                'category'      => (string) ($input['category'] ?? ''),
                'price_range'   => (string) ($input['price_range'] ?? ''),
                'featured_only' => !empty($input['featured_only']),
            ];
            if (isset($input['lat'], $input['lng']) && is_numeric($input['lat']) && is_numeric($input['lng'])) {
                $filters['lat'] = (float) $input['lat'];
                $filters['lng'] = (float) $input['lng'];
                if (isset($input['radius_km']) && is_numeric($input['radius_km'])) { $filters['radius_km'] = (float) $input['radius_km']; }
            }
            $rows = wpultra_dir_search($filters);
            $limit = (int) ($input['limit'] ?? 100);
            $truncated = $limit > 0 && count($rows) > $limit;
            if ($truncated) { $rows = array_slice($rows, 0, $limit); }
            return wpultra_ok(['action' => $action, 'listings' => $rows, 'count' => count($rows), 'truncated' => $truncated]);
        }

        case 'manage-category': {
            $name = (string) ($input['name'] ?? '');
            $slug = (string) ($input['slug'] ?? '');
            $parent = (int) ($input['parent'] ?? 0);
            $res = wpultra_dir_category_upsert($name, $slug, $parent);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('directory-manage', "manage-category id=$res", true);
            return wpultra_ok(['action' => $action, 'id' => (int) $res, 'categories' => wpultra_dir_categories()]);
        }

        case 'list-categories': {
            $cats = wpultra_dir_categories();
            return wpultra_ok(['action' => $action, 'categories' => $cats, 'count' => count($cats)]);
        }

        case 'moderate': {
            $id = (int) ($input['id'] ?? 0);
            $decision = (string) ($input['decision'] ?? '');
            if ($id <= 0) { return wpultra_err('missing_id', 'moderate requires an id.'); }
            $res = wpultra_dir_moderate($id, $decision);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('directory-manage', "moderate id=$id decision=$decision", true);
            $meta = wpultra_dir_load($id);
            return wpultra_ok([
                'action'  => $action,
                'id'      => $id,
                'listing' => $meta !== null ? wpultra_dir_shape($meta, $id, wpultra_dir_permalink($id)) : [],
            ]);
        }

        case 'submissions': {
            $rows = wpultra_dir_submissions();
            return wpultra_ok(['action' => $action, 'listings' => $rows, 'count' => count($rows)]);
        }

        case 'feature': {
            $id = (int) ($input['id'] ?? 0);
            $days = (int) ($input['days'] ?? 0);
            if ($id <= 0) { return wpultra_err('missing_id', 'feature requires an id.'); }
            $res = wpultra_dir_feature($id, $days);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('directory-manage', "feature id=$id days=$days", true);
            $meta = wpultra_dir_load($id);
            return wpultra_ok([
                'action'         => $action,
                'id'             => $id,
                'featured_until' => $res['featured_until'],
                'listing'        => $meta !== null ? wpultra_dir_shape($meta, $id, wpultra_dir_permalink($id)) : [],
            ]);
        }

        case 'unfeature': {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) { return wpultra_err('missing_id', 'unfeature requires an id.'); }
            $res = wpultra_dir_unfeature($id);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('directory-manage', "unfeature id=$id", true);
            return wpultra_ok(['action' => $action, 'id' => $id]);
        }

        default:
            return wpultra_err('unknown_action', "Unknown action '$action'.");
    }
}
