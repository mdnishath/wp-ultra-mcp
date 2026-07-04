<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Membership / paywall engine (Roadmap E2).
 *
 * Levels, drip content, restriction rules, member dashboard — a lightweight,
 * dependency-free membership layer that gates front-end content behind a paywall.
 *
 * Design follows the plugin's pure-core / thin-WP-wrapper split:
 *
 *  - PURE (prefix wpultra_member_): id minting, rule matching, THE ACCESS
 *    DECISION (can_access), teaser rendering, expiry/status math, and validation.
 *    All unit-testable with zero WordPress. The access decision is a SECURITY
 *    CONTROL, so it is fully pure and exhaustively tested.
 *
 *  - WP WRAPPERS (guarded by function_exists / is-WP checks): level CRUD, rule
 *    CRUD, member assignment (user_meta), the enforcement filter, and dashboard
 *    data.
 *
 * ACCESS-CONTROL GUARANTEES (see wpultra_member_can_access):
 *   1. No membership at all  -> denied (no_membership).
 *   2. Membership expired    -> denied (expired).
 *   3. Rule requires a level the member does not hold -> denied (wrong_level).
 *   4. Drip window not yet elapsed -> denied (dripping) with an unlock_at unix ts.
 *   5. Otherwise -> allowed (ok).
 *   The enforcement filter ALWAYS bypasses for a site admin and for the post's
 *   own author — they can never be locked out of their own content.
 *
 * STORAGE:
 *   option user_meta   wpultra_member        {level_id, since, expires, status}
 *   option             wpultra_member_levels {id => {id,name,price,period,description}}
 *   option             wpultra_member_rules  [ {id,match,require_level,drip_days,teaser_words} ]
 */

// ===========================================================================
// PURE: constants + id minting
// ===========================================================================

/** Seconds in a day. Pure. */
function wpultra_member_day(): int { return 86400; }

/** Valid billing periods. Pure. */
function wpultra_member_periods(): array { return ['month', 'year', 'lifetime']; }

/**
 * PURE. Mint a level/rule id like 'lvl-3f9a2c'. $rand is an injectable source of
 * randomness (e.g. 'random_bytes' or a deterministic test double) taking an int
 * byte-count and returning that many bytes.
 */
function wpultra_member_new_id(callable $rand): string {
    $bytes = (string) $rand(3);
    $hex = bin2hex($bytes);
    // Pad/truncate defensively so a short/long source still yields a stable 6-hex id.
    $hex = substr(str_pad($hex, 6, '0'), 0, 6);
    return 'lvl-' . $hex;
}

// ===========================================================================
// PURE: rule matching
// ===========================================================================

/**
 * PURE. Does $rule's match clause apply to a post?
 *
 * $post_ctx = {id:int, categories:int[]|string[], post_type:string}
 * $rule['match'] may specify any of: post_ids[], categories[], post_types[].
 * The rule matches when EVERY dimension it specifies matches (AND across
 * dimensions; OR within a dimension's list). A match clause with no dimensions
 * matches nothing (a rule must target something to restrict it).
 */
function wpultra_member_rule_matches(array $rule, array $post_ctx): bool {
    $match = isset($rule['match']) && is_array($rule['match']) ? $rule['match'] : [];

    $post_ids   = array_map('strval', (array) ($match['post_ids'] ?? []));
    $categories = array_map('strval', (array) ($match['categories'] ?? []));
    $post_types = array_map('strval', (array) ($match['post_types'] ?? []));

    // No dimension specified at all -> the rule targets nothing.
    if ($post_ids === [] && $categories === [] && $post_types === []) {
        return false;
    }

    $ctx_id   = (string) ($post_ctx['id'] ?? '');
    $ctx_cats = array_map('strval', (array) ($post_ctx['categories'] ?? []));
    $ctx_type = (string) ($post_ctx['post_type'] ?? '');

    if ($post_ids !== [] && !in_array($ctx_id, $post_ids, true)) {
        return false;
    }
    if ($categories !== [] && array_intersect($categories, $ctx_cats) === []) {
        return false;
    }
    if ($post_types !== [] && !in_array($ctx_type, $post_types, true)) {
        return false;
    }
    return true;
}

// ===========================================================================
// PURE: expiry + status
// ===========================================================================

/**
 * PURE. Is this membership expired at $now? A null/absent `expires` means the
 * membership never expires (lifetime). expires strictly less than now = expired.
 */
function wpultra_member_is_expired(array $member, int $now): bool {
    $expires = $member['expires'] ?? null;
    if ($expires === null || $expires === '') { return false; }
    return ((int) $expires) < $now;
}

/**
 * PURE. Effective status at $now: 'active' | 'expired' | 'cancelled'.
 * A stored status of 'cancelled' wins. Otherwise expiry decides.
 */
function wpultra_member_status(array $member, int $now): string {
    $stored = (string) ($member['status'] ?? '');
    if ($stored === 'cancelled') { return 'cancelled'; }
    if (wpultra_member_is_expired($member, $now)) { return 'expired'; }
    return 'active';
}

// ===========================================================================
// PURE: THE ACCESS DECISION (security control)
// ===========================================================================

/**
 * PURE. The core access decision for one member against one matched rule.
 *
 * @param array $member Empty array = "no member" (not logged in / no membership).
 *                      Otherwise {level_id, since, expires?, status?}.
 * @param array $rule   {require_level: level_id|'any', drip_days:int}.
 * @param int   $now    Current unix time.
 *
 * @return array {allowed:bool, reason:string, unlock_at?:int}
 *   reason: no_membership | expired | wrong_level | dripping | ok
 *
 * Order of checks (fail-closed): no member -> expired/cancelled -> wrong level
 * -> drip window -> allowed. `unlock_at` is only present for the dripping reason.
 */
function wpultra_member_can_access(array $member, array $rule, int $now): array {
    // 1. No membership record at all.
    if ($member === [] || empty($member['level_id'])) {
        return ['allowed' => false, 'reason' => 'no_membership'];
    }

    // 2. Expired or explicitly cancelled -> treat as no live access.
    $status = wpultra_member_status($member, $now);
    if ($status !== 'active') {
        return ['allowed' => false, 'reason' => 'expired'];
    }

    // 3. Level gate.
    $require = (string) ($rule['require_level'] ?? 'any');
    if ($require !== 'any' && (string) $member['level_id'] !== $require) {
        return ['allowed' => false, 'reason' => 'wrong_level'];
    }

    // 4. Drip: content unlocks `drip_days` after the member joined.
    $drip_days = (int) ($rule['drip_days'] ?? 0);
    if ($drip_days > 0) {
        $since = (int) ($member['since'] ?? 0);
        $unlock_at = $since + ($drip_days * wpultra_member_day());
        if ($now < $unlock_at) {
            return ['allowed' => false, 'reason' => 'dripping', 'unlock_at' => $unlock_at];
        }
    }

    // 5. Access granted.
    return ['allowed' => true, 'reason' => 'ok'];
}

/** PURE. Human-readable one-liner for a can_access reason. */
function wpultra_member_reason_text(string $reason): string {
    switch ($reason) {
        case 'no_membership': return 'This content requires an active membership.';
        case 'expired':       return 'Your membership has expired.';
        case 'wrong_level':   return 'This content requires a higher membership level.';
        case 'dripping':      return 'This content will unlock later in your membership.';
        case 'ok':            return 'Access granted.';
        case 'not_logged_in': return 'Please log in to view this content.';
        default:              return 'Access denied.';
    }
}

// ===========================================================================
// PURE: teaser
// ===========================================================================

/**
 * PURE. Build a paywall teaser: the first $words words of $content as PLAIN
 * TEXT (tags stripped so we never cut mid-tag), an ellipsis when truncated, and
 * an HTML paywall marker div so a theme can style it.
 *
 * HTML-safe by construction: the teaser body is escaped text, never raw markup.
 */
function wpultra_member_teaser(string $content, int $words): string {
    $words = max(0, $words);

    // Strip shortcodes, Gutenberg block comments, and HTML tags -> plain text.
    $text = preg_replace('/<!--\s*\/?wp:.*?-->/us', ' ', $content);
    $text = preg_replace('/\[[^\]]*\]/', ' ', (string) $text);
    $text = strip_tags((string) $text);
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

    $teaser_body = '';
    if ($words > 0 && $text !== '') {
        $tokens = preg_split('/\s+/u', $text) ?: [];
        $take = array_slice($tokens, 0, $words);
        $teaser_body = implode(' ', $take);
        if (count($tokens) > $words) {
            $teaser_body .= ' …';
        }
    }

    $escaped = function_exists('esc_html') ? esc_html($teaser_body) : htmlspecialchars($teaser_body, ENT_QUOTES);

    $out = '';
    if ($escaped !== '') {
        $out .= '<div class="wpultra-member-teaser">' . $escaped . '</div>';
    }
    $out .= '<div class="wpultra-member-paywall" data-wpultra-paywall="1">'
        . '<p>' . (function_exists('esc_html') ? esc_html(wpultra_member_reason_text('no_membership')) : 'This content requires an active membership.') . '</p>'
        . '</div>';
    return $out;
}

// ===========================================================================
// PURE: validation
// ===========================================================================

/**
 * PURE. Validate a level record. Returns true or a human-readable error string.
 * Required: id (non-empty), name (non-empty), period in the allowed set.
 * price must be a non-negative number.
 */
function wpultra_member_validate_level(array $lvl) {
    $id = (string) ($lvl['id'] ?? '');
    if ($id === '') { return 'level id is required.'; }
    $name = trim((string) ($lvl['name'] ?? ''));
    if ($name === '') { return 'level name is required.'; }
    $period = (string) ($lvl['period'] ?? '');
    if (!in_array($period, wpultra_member_periods(), true)) {
        return "period must be one of: " . implode(', ', wpultra_member_periods()) . '.';
    }
    if (array_key_exists('price', $lvl)) {
        if (!is_numeric($lvl['price']) || (float) $lvl['price'] < 0) {
            return 'price must be a non-negative number.';
        }
    }
    return true;
}

/**
 * PURE. Validate a restriction rule against the set of known level ids.
 * Returns true or a human-readable error string.
 * require_level must be 'any' or a known level id. drip_days/teaser_words must
 * be non-negative ints. The match clause must target at least one dimension.
 */
function wpultra_member_validate_rule(array $rule, array $level_ids) {
    $id = (string) ($rule['id'] ?? '');
    if ($id === '') { return 'rule id is required.'; }

    $match = isset($rule['match']) && is_array($rule['match']) ? $rule['match'] : [];
    $has_dim = !empty($match['post_ids']) || !empty($match['categories']) || !empty($match['post_types']);
    if (!$has_dim) {
        return 'rule match must specify at least one of: post_ids, categories, post_types.';
    }

    $require = (string) ($rule['require_level'] ?? 'any');
    if ($require !== 'any' && !in_array($require, $level_ids, true)) {
        return "require_level '$require' is not a known level id.";
    }

    if (array_key_exists('drip_days', $rule)) {
        if (!is_numeric($rule['drip_days']) || (int) $rule['drip_days'] < 0) {
            return 'drip_days must be a non-negative integer.';
        }
    }
    if (array_key_exists('teaser_words', $rule)) {
        if (!is_numeric($rule['teaser_words']) || (int) $rule['teaser_words'] < 0) {
            return 'teaser_words must be a non-negative integer.';
        }
    }
    return true;
}

/**
 * PURE. Normalize a rule to its canonical stored shape. Unknown keys dropped,
 * numeric fields coerced, match dimensions cleaned to string/int lists.
 */
function wpultra_member_normalize_rule(array $rule): array {
    $match = isset($rule['match']) && is_array($rule['match']) ? $rule['match'] : [];
    $clean_match = [];
    if (!empty($match['post_ids'])) {
        $clean_match['post_ids'] = array_values(array_map('intval', (array) $match['post_ids']));
    }
    if (!empty($match['categories'])) {
        $clean_match['categories'] = array_values(array_map('strval', (array) $match['categories']));
    }
    if (!empty($match['post_types'])) {
        $clean_match['post_types'] = array_values(array_map('strval', (array) $match['post_types']));
    }
    return [
        'id'            => (string) ($rule['id'] ?? ''),
        'match'         => $clean_match,
        'require_level' => (string) ($rule['require_level'] ?? 'any'),
        'drip_days'     => max(0, (int) ($rule['drip_days'] ?? 0)),
        'teaser_words'  => max(0, (int) ($rule['teaser_words'] ?? 55)),
    ];
}

/**
 * PURE. Normalize a level to its canonical stored shape.
 */
function wpultra_member_normalize_level(array $lvl): array {
    return [
        'id'          => (string) ($lvl['id'] ?? ''),
        'name'        => trim((string) ($lvl['name'] ?? '')),
        'price'       => isset($lvl['price']) && is_numeric($lvl['price']) ? (float) $lvl['price'] : 0.0,
        'period'      => in_array(($lvl['period'] ?? ''), wpultra_member_periods(), true) ? (string) $lvl['period'] : 'month',
        'description' => (string) ($lvl['description'] ?? ''),
    ];
}

// ===========================================================================
// WP WRAPPERS: option accessors
// ===========================================================================

function wpultra_member_levels_option(): string { return 'wpultra_member_levels'; }
function wpultra_member_rules_option(): string { return 'wpultra_member_rules'; }
function wpultra_member_user_meta_key(): string { return 'wpultra_member'; }

/** All levels, keyed by id. WP wrapper. */
function wpultra_member_get_levels(): array {
    if (!function_exists('get_option')) { return []; }
    $levels = get_option(wpultra_member_levels_option(), []);
    return is_array($levels) ? $levels : [];
}

/** Persist the full levels map. WP wrapper. */
function wpultra_member_save_levels(array $levels): void {
    if (function_exists('update_option')) {
        update_option(wpultra_member_levels_option(), $levels, true);
    }
}

/** All restriction rules (a list). WP wrapper. */
function wpultra_member_get_rules(): array {
    if (!function_exists('get_option')) { return []; }
    $rules = get_option(wpultra_member_rules_option(), []);
    return is_array($rules) ? array_values($rules) : [];
}

/** Persist the full rules list. WP wrapper. */
function wpultra_member_save_rules(array $rules): void {
    if (function_exists('update_option')) {
        update_option(wpultra_member_rules_option(), array_values($rules), true);
    }
}

// ===========================================================================
// WP WRAPPERS: level CRUD
// ===========================================================================

/**
 * Upsert a level. Mints an id when absent. Returns the stored level or WP_Error.
 * WP wrapper.
 */
function wpultra_member_upsert_level(array $lvl) {
    $levels = wpultra_member_get_levels();
    if (empty($lvl['id'])) {
        $rand = function (int $n): string {
            return function_exists('random_bytes') ? random_bytes($n) : substr(md5((string) mt_rand()), 0, $n);
        };
        $lvl['id'] = wpultra_member_new_id($rand);
    }
    $valid = wpultra_member_validate_level($lvl);
    if ($valid !== true) { return wpultra_err('invalid_level', (string) $valid); }

    $normalized = wpultra_member_normalize_level($lvl);
    $levels[$normalized['id']] = $normalized;
    wpultra_member_save_levels($levels);
    return $normalized;
}

/** Delete a level by id. Returns true if it existed. WP wrapper. */
function wpultra_member_delete_level(string $id): bool {
    $levels = wpultra_member_get_levels();
    if (!isset($levels[$id])) { return false; }
    unset($levels[$id]);
    wpultra_member_save_levels($levels);
    return true;
}

// ===========================================================================
// WP WRAPPERS: rule CRUD
// ===========================================================================

/**
 * Upsert a rule (matched by id). Mints an id when absent. Returns the stored
 * rule or WP_Error. WP wrapper.
 */
function wpultra_member_upsert_rule(array $rule) {
    $rules = wpultra_member_get_rules();
    if (empty($rule['id'])) {
        $rand = function (int $n): string {
            return function_exists('random_bytes') ? random_bytes($n) : substr(md5((string) mt_rand()), 0, $n);
        };
        // Rule ids reuse the same minting but with a 'rule-' semantic prefix swap.
        $rule['id'] = 'rule-' . substr(wpultra_member_new_id($rand), 4);
    }
    $level_ids = array_keys(wpultra_member_get_levels());
    $valid = wpultra_member_validate_rule($rule, $level_ids);
    if ($valid !== true) { return wpultra_err('invalid_rule', (string) $valid); }

    $normalized = wpultra_member_normalize_rule($rule);
    $found = false;
    foreach ($rules as $i => $r) {
        if ((string) ($r['id'] ?? '') === $normalized['id']) { $rules[$i] = $normalized; $found = true; break; }
    }
    if (!$found) { $rules[] = $normalized; }
    wpultra_member_save_rules($rules);
    return $normalized;
}

/** Delete a rule by id. Returns true if it existed. WP wrapper. */
function wpultra_member_delete_rule(string $id): bool {
    $rules = wpultra_member_get_rules();
    $out = [];
    $removed = false;
    foreach ($rules as $r) {
        if ((string) ($r['id'] ?? '') === $id) { $removed = true; continue; }
        $out[] = $r;
    }
    if ($removed) { wpultra_member_save_rules($out); }
    return $removed;
}

// ===========================================================================
// WP WRAPPERS: member assignment (user_meta)
// ===========================================================================

/** Load a user's membership record ([] when none). WP wrapper. */
function wpultra_member_get(int $user_id): array {
    if (!function_exists('get_user_meta')) { return []; }
    $m = get_user_meta($user_id, wpultra_member_user_meta_key(), true);
    return is_array($m) ? $m : [];
}

/**
 * Assign (or update) a user's membership. Validates the level exists.
 * Returns the stored membership or WP_Error. WP wrapper.
 *
 * @param int      $user_id
 * @param string   $level_id
 * @param int|null $expires  Unix ts, or null for a non-expiring membership.
 */
function wpultra_member_assign(int $user_id, string $level_id, ?int $expires = null) {
    $levels = wpultra_member_get_levels();
    if (!isset($levels[$level_id])) {
        return wpultra_err('unknown_level', "Level '$level_id' does not exist.");
    }
    $existing = wpultra_member_get($user_id);
    $since = (int) ($existing['since'] ?? 0);
    if ($since <= 0) { $since = function_exists('time') ? time() : 0; }

    $member = [
        'level_id' => $level_id,
        'since'    => $since,
        'expires'  => $expires,
        'status'   => 'active',
    ];
    if (function_exists('update_user_meta')) {
        update_user_meta($user_id, wpultra_member_user_meta_key(), $member);
    }
    return $member;
}

/** Remove a user's membership (mark cancelled + drop the meta). WP wrapper. */
function wpultra_member_remove(int $user_id): bool {
    if (!function_exists('delete_user_meta')) { return false; }
    delete_user_meta($user_id, wpultra_member_user_meta_key());
    return true;
}

// ===========================================================================
// WP WRAPPERS: enforcement
// ===========================================================================

/**
 * Build a post context for rule matching from a post object/id. WP wrapper.
 * @return array {id, categories, post_type}
 */
function wpultra_member_post_ctx(int $post_id): array {
    $post_type = function_exists('get_post_type') ? (string) get_post_type($post_id) : 'post';
    $cats = [];
    if (function_exists('wp_get_post_categories')) {
        $cats = wp_get_post_categories($post_id, ['fields' => 'ids']);
        if (!is_array($cats)) { $cats = []; }
    }
    return [
        'id'         => $post_id,
        'categories' => array_map('intval', $cats),
        'post_type'  => $post_type,
    ];
}

/**
 * Find the FIRST rule that matches a post context ([] when none). WP wrapper.
 */
function wpultra_member_matching_rule(array $post_ctx): array {
    foreach (wpultra_member_get_rules() as $rule) {
        if (wpultra_member_rule_matches($rule, $post_ctx)) { return $rule; }
    }
    return [];
}

/**
 * Should the CURRENT viewer bypass enforcement for this post? Admins and the
 * post's own author always see full content. WP wrapper.
 */
function wpultra_member_viewer_bypasses(int $post_id): bool {
    if (function_exists('current_user_can') && current_user_can('manage_options')) { return true; }
    if (function_exists('get_post_field') && function_exists('get_current_user_id')) {
        $author = (int) get_post_field('post_author', $post_id);
        if ($author > 0 && $author === (int) get_current_user_id()) { return true; }
    }
    return false;
}

/**
 * Would a SPECIFIC user (by id) bypass enforcement for this post? Used by the
 * check-access dry run so a caller can test any user, not just the current one.
 * A user with manage_options, or the post's author, bypasses. WP wrapper.
 */
function wpultra_member_viewer_bypasses_user(int $user_id, int $post_id): bool {
    if ($user_id <= 0) { return false; }
    if (function_exists('user_can') && user_can($user_id, 'manage_options')) { return true; }
    if (function_exists('get_post_field')) {
        $author = (int) get_post_field('post_author', $post_id);
        if ($author > 0 && $author === $user_id) { return true; }
    }
    return false;
}

/**
 * the_content filter: enforce restriction rules. Replaces protected content with
 * a teaser + paywall for viewers who fail the access decision. WP wrapper.
 *
 * ALWAYS bypasses for admins and the post author. Non-logged-in viewers are
 * treated as having no membership.
 */
function wpultra_member_filter_content(string $content): string {
    // Only gate the main singular post view; leave archives/excerpts alone.
    if (function_exists('is_singular') && !is_singular()) { return $content; }
    if (function_exists('in_the_loop') && !in_the_loop()) { return $content; }
    if (function_exists('is_main_query') && !is_main_query()) { return $content; }

    $post_id = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
    if ($post_id <= 0) { return $content; }

    if (wpultra_member_viewer_bypasses($post_id)) { return $content; }

    $ctx = wpultra_member_post_ctx($post_id);
    $rule = wpultra_member_matching_rule($ctx);
    if ($rule === []) { return $content; } // no rule covers this post -> public

    $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $member = $user_id > 0 ? wpultra_member_get($user_id) : [];
    $now = function_exists('time') ? time() : 0;

    $decision = wpultra_member_can_access($member, $rule, $now);
    if ($decision['allowed']) { return $content; }

    $words = (int) ($rule['teaser_words'] ?? 55);
    return wpultra_member_teaser($content, $words);
}

/**
 * template_redirect enforcement: for a directly-requested protected singular
 * post whose viewer is denied, we keep them on the page (so the teaser filter
 * renders) but expose the decision via a header for debuggability. This is
 * deliberately NON-redirecting to avoid open-redirect / loop risks — the
 * the_content filter is the actual enforcement. WP wrapper.
 */
function wpultra_member_template_redirect(): void {
    if (!function_exists('is_singular') || !is_singular()) { return; }
    $post_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
    if ($post_id <= 0) { return; }
    if (wpultra_member_viewer_bypasses($post_id)) { return; }

    $ctx = wpultra_member_post_ctx($post_id);
    $rule = wpultra_member_matching_rule($ctx);
    if ($rule === []) { return; }

    $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $member = $user_id > 0 ? wpultra_member_get($user_id) : [];
    $now = function_exists('time') ? time() : 0;
    $decision = wpultra_member_can_access($member, $rule, $now);

    if (!$decision['allowed'] && function_exists('header') && !headers_sent()) {
        header('X-WPUltra-Paywall: ' . $decision['reason']);
    }
}

// ===========================================================================
// WP WRAPPERS: dashboard
// ===========================================================================

/**
 * Member dashboard data: their level, expiry, status, and the list of rules
 * (with per-rule access decisions) so a member can see what they can access.
 * WP wrapper.
 */
function wpultra_member_dashboard(int $user_id): array {
    $member = wpultra_member_get($user_id);
    $now = function_exists('time') ? time() : 0;
    $levels = wpultra_member_get_levels();
    $level_id = (string) ($member['level_id'] ?? '');

    $accessible = [];
    $locked = [];
    foreach (wpultra_member_get_rules() as $rule) {
        $decision = wpultra_member_can_access($member, $rule, $now);
        $entry = [
            'rule_id'       => (string) ($rule['id'] ?? ''),
            'require_level' => (string) ($rule['require_level'] ?? 'any'),
            'reason'        => $decision['reason'],
        ];
        if (isset($decision['unlock_at'])) { $entry['unlock_at'] = $decision['unlock_at']; }
        if ($decision['allowed']) { $accessible[] = $entry; } else { $locked[] = $entry; }
    }

    return [
        'user_id'    => $user_id,
        'has_member' => $member !== [] && $level_id !== '',
        'level'      => $level_id !== '' && isset($levels[$level_id]) ? $levels[$level_id] : null,
        'level_id'   => $level_id,
        'since'      => (int) ($member['since'] ?? 0),
        'expires'    => $member['expires'] ?? null,
        'status'     => $member !== [] ? wpultra_member_status($member, $now) : 'none',
        'accessible' => $accessible,
        'locked'     => $locked,
    ];
}

// ===========================================================================
// RUNTIME CONTRACT: boot
// ===========================================================================

/**
 * Runtime boot. The controller calls this on plugins_loaded. We only wire the
 * front-end enforcement hooks when at least one level exists — a single cheap
 * autoloaded option read, never a query. With zero levels the site behaves
 * exactly as if this engine were not loaded.
 */
function wpultra_member_boot(): void {
    if (!function_exists('add_filter') && !function_exists('add_action')) { return; }

    $levels = wpultra_member_get_levels();
    if ($levels === []) { return; } // dormant until membership is configured

    if (function_exists('add_filter')) {
        add_filter('the_content', 'wpultra_member_filter_content', 8);
    }
    if (function_exists('add_action')) {
        add_action('template_redirect', 'wpultra_member_template_redirect');
    }
}
