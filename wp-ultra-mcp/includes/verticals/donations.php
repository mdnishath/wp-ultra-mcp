<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Donations / crowdfunding engine (roadmap E6).
 *
 * Storage: two private CPTs (public false, show_ui false).
 *   `wpultra_campaign_fund`  = a fundraising campaign.
 *       post_title   = campaign title
 *       post_content = campaign story (long copy)
 *       meta _wpultra_fund = {goal_amount, currency, raised (cached sum),
 *                             donor_count (cached distinct), deadline (unix|null),
 *                             status: active|completed|closed, cover_image}
 *   `wpultra_donation`       = a single donation record.
 *       post_title   = auto "Donation #<id>"
 *       meta _wpultra_donation = {campaign_id, donor:{name,email}, amount,
 *                                 currency, recurring: none|monthly|yearly,
 *                                 status: pending|completed|refunded|failed,
 *                                 gateway_ref, next_charge (unix|null),
 *                                 created (unix), anonymous (bool)}
 *
 * PAYMENT MODEL — READ THIS (honest, no magic):
 *   This engine RECORDS campaigns, donations, progress, and the recurring
 *   SCHEDULE. It does NOT process cards and never stores card data. Money moves
 *   one of two ways, both external to this file:
 *     1. WooCommerce bridge (guarded by wpultra_woo_active): a donation can be
 *        fulfilled as a Woo order; the Woo order/webhook marks it completed.
 *     2. External gateway webhook: the gateway completes a donation via the
 *        mark-donation path, supplying a gateway_ref.
 *   Recurring donations: the cron records the NEXT expected installment (a fresh
 *   pending donation) and advances next_charge. The GATEWAY performs the actual
 *   recurring charge; the webhook then marks each installment completed. We only
 *   keep the schedule + records.
 *
 * PURE functions first (prefix wpultra_donate_, no WP calls — unit-tested by
 * tests/donations.test.php); thin WordPress wrappers after (guarded).
 */

// NOTE: WP post-type names are capped at 20 chars — 'wpultra_campaign_fund'
// is 21 and register_post_type() silently rejects it. Use 'wpultra_fund' (12),
// which also avoids colliding with marketing's 'wpultra_campaign'.
if (!defined('WPULTRA_FUND_CPT'))       { define('WPULTRA_FUND_CPT', 'wpultra_fund'); }
if (!defined('WPULTRA_DONATION_CPT'))   { define('WPULTRA_DONATION_CPT', 'wpultra_donation'); }
if (!defined('WPULTRA_FUND_META'))      { define('WPULTRA_FUND_META', '_wpultra_fund'); }
if (!defined('WPULTRA_DONATION_META'))  { define('WPULTRA_DONATION_META', '_wpultra_donation'); }
if (!defined('WPULTRA_DONATE_CRON'))    { define('WPULTRA_DONATE_CRON', 'wpultra_donate_recurring_cron'); }

// Fixed recurring intervals (documented approximation — see wpultra_donate_next_charge).
if (!defined('WPULTRA_DONATE_MONTH_SECS')) { define('WPULTRA_DONATE_MONTH_SECS', 30 * 86400); }
if (!defined('WPULTRA_DONATE_YEAR_SECS'))  { define('WPULTRA_DONATE_YEAR_SECS', 365 * 86400); }

/* =====================================================================
 * PURE core — no WordPress calls (harness-loadable).
 * ===================================================================== */

/** Allowed recurring cadences. Pure. */
function wpultra_donate_recurring_modes(): array {
    return ['none', 'monthly', 'yearly'];
}

/** Allowed donation statuses. Pure. */
function wpultra_donate_statuses(): array {
    return ['pending', 'completed', 'refunded', 'failed'];
}

/** Allowed campaign statuses. Pure. */
function wpultra_donate_campaign_statuses(): array {
    return ['active', 'completed', 'closed'];
}

/** 3-letter ISO-ish currency code check (upper-cased). Pure. */
function wpultra_donate_currency_valid(string $c): bool {
    return (bool) preg_match('/^[A-Za-z]{3}$/', trim($c));
}

/** Strict-ish email shape check (full-string match). Pure. */
function wpultra_donate_email_valid(string $email): bool {
    return (bool) preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', trim($email));
}

/**
 * Progress rollup for a campaign. Pure.
 * goal <= 0 -> pct 0 (no divide-by-zero). pct clamped 0..100, 1dp.
 * @return array{pct:float, remaining:float, reached:bool}
 */
function wpultra_donate_progress(float $raised, float $goal): array {
    if ($raised < 0) { $raised = 0.0; }
    if ($goal <= 0) {
        return ['pct' => 0.0, 'remaining' => 0.0, 'reached' => false];
    }
    $pct = ($raised / $goal) * 100;
    if ($pct < 0) { $pct = 0.0; }
    if ($pct > 100) { $pct = 100.0; }
    $remaining = $goal - $raised;
    if ($remaining < 0) { $remaining = 0.0; }
    return [
        'pct'       => round($pct, 1),
        'remaining' => round($remaining, 2),
        'reached'   => $raised >= $goal,
    ];
}

/**
 * Sum only COMPLETED donations. Ignores pending/refunded/failed. Pure.
 * donor_count = distinct (lowercased) donor emails among completed donations;
 * a completed donation with no email still counts toward `count` but not toward
 * donor_count.
 * @return array{raised:float, donor_count:int, count:int}
 */
function wpultra_donate_sum(array $donations): array {
    $raised = 0.0;
    $count  = 0;
    $emails = [];
    foreach ($donations as $d) {
        if (!is_array($d)) { continue; }
        if ((string) ($d['status'] ?? '') !== 'completed') { continue; }
        $raised += (float) ($d['amount'] ?? 0);
        $count++;
        $email = strtolower(trim((string) ($d['donor']['email'] ?? '')));
        if ($email !== '') { $emails[$email] = true; }
    }
    return [
        'raised'      => round($raised, 2),
        'donor_count' => count($emails),
        'count'       => $count,
    ];
}

/**
 * Validate a donation input. Returns true or an error string. Pure.
 * Rules: amount numeric > 0; email valid; recurring in enum (default none);
 * currency 3-letter.
 */
function wpultra_donate_validate(array $d) {
    if (!isset($d['amount']) || !is_numeric($d['amount']) || (float) $d['amount'] <= 0) {
        return 'amount must be a number greater than 0.';
    }
    $email = (string) ($d['donor']['email'] ?? $d['email'] ?? '');
    if (!wpultra_donate_email_valid($email)) {
        return "Invalid donor email: $email";
    }
    $recurring = (string) ($d['recurring'] ?? 'none');
    if (!in_array($recurring, wpultra_donate_recurring_modes(), true)) {
        return "Unknown recurring '$recurring'. Allowed: " . implode(', ', wpultra_donate_recurring_modes());
    }
    $currency = (string) ($d['currency'] ?? 'USD');
    if (!wpultra_donate_currency_valid($currency)) {
        return "Invalid currency '$currency' (expected a 3-letter code).";
    }
    return true;
}

/**
 * Validate a campaign input. Returns true or an error string. Pure.
 * Rules: goal_amount numeric > 0; currency 3-letter; status in enum (default
 * active); deadline null OR a unix timestamp (int-ish, > 0).
 */
function wpultra_donate_validate_campaign(array $c) {
    if (!isset($c['goal_amount']) || !is_numeric($c['goal_amount']) || (float) $c['goal_amount'] <= 0) {
        return 'goal_amount must be a number greater than 0.';
    }
    $currency = (string) ($c['currency'] ?? 'USD');
    if (!wpultra_donate_currency_valid($currency)) {
        return "Invalid currency '$currency' (expected a 3-letter code).";
    }
    $status = (string) ($c['status'] ?? 'active');
    if (!in_array($status, wpultra_donate_campaign_statuses(), true)) {
        return "Unknown status '$status'. Allowed: " . implode(', ', wpultra_donate_campaign_statuses());
    }
    if (array_key_exists('deadline', $c) && $c['deadline'] !== null && $c['deadline'] !== '') {
        if (!is_numeric($c['deadline']) || (int) $c['deadline'] <= 0) {
            return 'deadline must be a unix timestamp or null.';
        }
    }
    return true;
}

/**
 * Next expected charge timestamp for a recurring donation. Pure.
 * 'none' -> null. monthly/yearly add a fixed 30d / 365d window.
 *
 * NOTE: we use a FIXED-window approximation (30d month, 365d year) rather than
 * calendar month math on purpose — it keeps this helper pure, deterministic,
 * and trivially testable, and the gateway (which does the real charge) owns the
 * authoritative billing date anyway. This is a SCHEDULE hint, not a bill.
 * The returned timestamp is the first window strictly after $from that is also
 * > $now (so a badly-lagged cron catches up one step rather than skipping).
 *
 * @return int|null
 */
function wpultra_donate_next_charge(string $recurring, int $from, int $now): ?int {
    $step = match ($recurring) {
        'monthly' => WPULTRA_DONATE_MONTH_SECS,
        'yearly'  => WPULTRA_DONATE_YEAR_SECS,
        default   => 0,
    };
    if ($step <= 0) { return null; }
    $next = $from + $step;
    // Advance one whole step if the computed date already slipped past now.
    if ($next <= $now) {
        $next = $now + $step;
    }
    return $next;
}

/**
 * From a list of donation records, pick those whose next recurring installment
 * is DUE: recurring != none, status completed, next_charge set and <= now. Pure.
 * (The cron uses this to record the next pending installment.)
 * @return array<int,array> the due donation records (order preserved)
 */
function wpultra_donate_recurring_due(array $donations, int $now): array {
    $out = [];
    foreach ($donations as $d) {
        if (!is_array($d)) { continue; }
        $recurring = (string) ($d['recurring'] ?? 'none');
        if ($recurring === 'none' || !in_array($recurring, wpultra_donate_recurring_modes(), true)) { continue; }
        if ((string) ($d['status'] ?? '') !== 'completed') { continue; }
        $next = $d['next_charge'] ?? null;
        if ($next === null || $next === '' || !is_numeric($next)) { continue; }
        if ((int) $next <= $now) { $out[] = $d; }
    }
    return $out;
}

/** True when a campaign's deadline has passed. null/missing deadline -> never expired. Pure. */
function wpultra_donate_is_expired(array $campaign, int $now): bool {
    $deadline = $campaign['deadline'] ?? ($campaign['meta']['deadline'] ?? null);
    if ($deadline === null || $deadline === '' || !is_numeric($deadline)) { return false; }
    return (int) $deadline <= $now;
}

/**
 * Effective campaign status. Pure.
 * An explicitly 'closed' campaign stays closed. Otherwise: goal reached ->
 * 'completed'; deadline passed -> 'closed'; else 'active'.
 */
function wpultra_donate_status(array $campaign, int $now): string {
    $explicit = (string) ($campaign['status'] ?? ($campaign['meta']['status'] ?? 'active'));
    if ($explicit === 'closed') { return 'closed'; }

    $raised = (float) ($campaign['raised'] ?? ($campaign['meta']['raised'] ?? 0));
    $goal   = (float) ($campaign['goal_amount'] ?? ($campaign['meta']['goal_amount'] ?? 0));
    if ($goal > 0 && $raised >= $goal) { return 'completed'; }

    if (wpultra_donate_is_expired($campaign, $now)) { return 'closed'; }
    return 'active';
}

/** HTML-escape helper that works with or without WordPress. Pure. */
function wpultra_donate_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Suggested donation tiers derived from a goal. Pure.
 * Returns a small ascending list of nice round amounts (min 3, max 6). Anchored
 * to a "typical gift" ~= goal/50 rounded to a nice number, then a spread of
 * multiples plus a round base. Always at least [10, 25, 50] for tiny/zero goals.
 * @return array<int,float>
 */
function wpultra_donate_tiers_suggest(float $goal): array {
    if ($goal <= 0) { return [10.0, 25.0, 50.0]; }

    // A "nice" base unit near goal/50, snapped to 5 / 10 / 25 / 50 / 100 ... families.
    $unit = $goal / 50;
    $nice = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000];
    $base = 5.0;
    foreach ($nice as $n) {
        if ($n <= $unit) { $base = (float) $n; } else { break; }
    }
    // Build a spread of multipliers, keep only those under the goal, dedupe.
    $mults = [1, 2, 5, 10, 20];
    $tiers = [];
    foreach ($mults as $m) {
        $t = round($base * $m, 2);
        if ($t > 0 && $t < $goal && !in_array($t, $tiers, true)) { $tiers[] = $t; }
    }
    // Ensure a sensible floor of options.
    foreach ([10.0, 25.0, 50.0] as $floor) {
        if ($floor < $goal && !in_array($floor, $tiers, true)) { $tiers[] = $floor; }
    }
    sort($tiers);
    if (count($tiers) > 6) { $tiers = array_slice($tiers, 0, 6); }
    if (count($tiers) < 3) { $tiers = [10.0, 25.0, 50.0]; }
    return array_values(array_map('floatval', $tiers));
}

/**
 * Print-ready donation receipt (fully escaped). Pure.
 * Shows donor (or "Anonymous"), amount+currency, campaign title, date, a
 * gateway reference when present, and a tax-note line. Returns an HTML fragment.
 */
function wpultra_donate_receipt_html(array $donation, array $campaign): string {
    $anon     = (bool) ($donation['anonymous'] ?? false);
    $name     = trim((string) ($donation['donor']['name'] ?? ''));
    $donorTxt = ($anon || $name === '') ? 'Anonymous donor' : $name;

    $amount   = number_format((float) ($donation['amount'] ?? 0), 2);
    $currency = strtoupper(trim((string) ($donation['currency'] ?? 'USD')));
    $created  = (int) ($donation['created'] ?? 0);
    $dateTxt  = $created > 0 ? gmdate('Y-m-d', $created) : gmdate('Y-m-d');
    $ref      = trim((string) ($donation['gateway_ref'] ?? ''));
    $title    = trim((string) ($campaign['title'] ?? $campaign['name'] ?? 'Campaign'));

    $rows  = "<h2>Donation Receipt</h2>\n";
    $rows .= '<p><strong>Donor:</strong> ' . wpultra_donate_esc($donorTxt) . "</p>\n";
    $rows .= '<p><strong>Campaign:</strong> ' . wpultra_donate_esc($title) . "</p>\n";
    $rows .= '<p><strong>Amount:</strong> ' . wpultra_donate_esc($currency . ' ' . $amount) . "</p>\n";
    $rows .= '<p><strong>Date:</strong> ' . wpultra_donate_esc($dateTxt) . "</p>\n";
    if ($ref !== '') {
        $rows .= '<p><strong>Reference:</strong> ' . wpultra_donate_esc($ref) . "</p>\n";
    }
    $rows .= '<p class="tax-note"><em>Please retain this receipt for your records. '
        . 'This donation may be tax-deductible depending on your jurisdiction and the '
        . "organization's tax status; consult a tax professional.</em></p>\n";
    return '<div class="wpultra-donation-receipt">' . "\n" . $rows . '</div>';
}

/**
 * Canonical output shape for one campaign (list/get). Merges cached raised/
 * donor_count + a live progress rollup. Pure.
 */
function wpultra_donate_campaign_shape(array $meta, int $id, string $title, string $story = ''): array {
    $goal   = (float) ($meta['goal_amount'] ?? 0);
    $raised = (float) ($meta['raised'] ?? 0);
    $prog   = wpultra_donate_progress($raised, $goal);
    return [
        'id'          => $id,
        'title'       => $title,
        'story'       => $story,
        'goal_amount' => $goal,
        'currency'    => strtoupper((string) ($meta['currency'] ?? 'USD')),
        'raised'      => round($raised, 2),
        'donor_count' => (int) ($meta['donor_count'] ?? 0),
        'deadline'    => isset($meta['deadline']) && is_numeric($meta['deadline']) ? (int) $meta['deadline'] : null,
        'status'      => (string) ($meta['status'] ?? 'active'),
        'cover_image' => (string) ($meta['cover_image'] ?? ''),
        'progress'    => $prog,
    ];
}

/** Canonical output shape for one donation record. Pure. */
function wpultra_donate_donation_shape(array $meta, int $id): array {
    $anon = (bool) ($meta['anonymous'] ?? false);
    $name = (string) ($meta['donor']['name'] ?? '');
    return [
        'id'          => $id,
        'campaign_id' => (int) ($meta['campaign_id'] ?? 0),
        'donor'       => [
            'name'  => $anon ? '' : $name,
            'email' => (string) ($meta['donor']['email'] ?? ''),
        ],
        'amount'      => round((float) ($meta['amount'] ?? 0), 2),
        'currency'    => strtoupper((string) ($meta['currency'] ?? 'USD')),
        'recurring'   => (string) ($meta['recurring'] ?? 'none'),
        'status'      => (string) ($meta['status'] ?? 'pending'),
        'gateway_ref' => (string) ($meta['gateway_ref'] ?? ''),
        'next_charge' => isset($meta['next_charge']) && is_numeric($meta['next_charge']) ? (int) $meta['next_charge'] : null,
        'created'     => (int) ($meta['created'] ?? 0),
        'anonymous'   => $anon,
    ];
}

/**
 * Fresh donation meta blob. Pure.
 * next_charge is computed for recurring donations (from $now).
 */
function wpultra_donate_new_donation_meta(int $campaign_id, array $donor, float $amount, string $currency, string $recurring, int $now, bool $anonymous = false, string $status = 'pending'): array {
    $recurring = in_array($recurring, wpultra_donate_recurring_modes(), true) ? $recurring : 'none';
    return [
        'campaign_id' => $campaign_id,
        'donor'       => [
            'name'  => trim((string) ($donor['name'] ?? '')),
            'email' => strtolower(trim((string) ($donor['email'] ?? ''))),
        ],
        'amount'      => round($amount, 2),
        'currency'    => strtoupper(trim($currency) !== '' ? trim($currency) : 'USD'),
        'recurring'   => $recurring,
        'status'      => in_array($status, wpultra_donate_statuses(), true) ? $status : 'pending',
        'gateway_ref' => '',
        'next_charge' => wpultra_donate_next_charge($recurring, $now, $now),
        'created'     => $now,
        'anonymous'   => $anonymous,
    ];
}

/* =====================================================================
 * WordPress wrappers — CPTs, persistence, progress recompute, cron.
 * All guarded so the engine stays harness-loadable.
 * ===================================================================== */

function wpultra_donate_register_cpts(): void {
    if (!function_exists('register_post_type')) { return; }
    $args = [
        'public'       => false,
        'show_ui'      => false,
        'show_in_rest' => false,
        'rewrite'      => false,
    ];
    register_post_type(WPULTRA_FUND_CPT, array_merge($args, ['supports' => ['title', 'editor']]));
    register_post_type(WPULTRA_DONATION_CPT, array_merge($args, ['supports' => ['title']]));
}

/** Load a campaign as a shaped array, or null when id is not a fund campaign. */
function wpultra_donate_load_campaign(int $id): ?array {
    if (!function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_FUND_CPT) { return null; }
    $meta = function_exists('get_post_meta') ? get_post_meta($id, WPULTRA_FUND_META, true) : [];
    if (!is_array($meta)) { $meta = []; }
    return wpultra_donate_campaign_shape($meta, $id, (string) $post->post_title, (string) $post->post_content);
}

/** Raw meta blob for a campaign (for internal recompute). Empty array when missing. */
function wpultra_donate_campaign_meta(int $id): array {
    $meta = function_exists('get_post_meta') ? get_post_meta($id, WPULTRA_FUND_META, true) : [];
    return is_array($meta) ? $meta : [];
}

/** Persist a campaign meta blob. */
function wpultra_donate_save_campaign_meta(int $id, array $meta): void {
    if (function_exists('update_post_meta')) { update_post_meta($id, WPULTRA_FUND_META, $meta); }
}

/**
 * Upsert a campaign. $in: {id?, title, story?, goal_amount, currency?, deadline?,
 * status?, cover_image?}. @return int|WP_Error campaign id.
 */
function wpultra_donate_upsert_campaign(array $in) {
    $valid = wpultra_donate_validate_campaign($in);
    if ($valid !== true) { return wpultra_err('invalid_campaign', (string) $valid); }
    if (!function_exists('wp_insert_post')) { return wpultra_err('wp_unavailable', 'WordPress is not loaded.'); }

    $id    = (int) ($in['id'] ?? 0);
    $title = trim((string) ($in['title'] ?? ''));
    $story = (string) ($in['story'] ?? '');

    if ($id > 0) {
        $existing = wpultra_donate_load_campaign($id);
        if ($existing === null) { return wpultra_err('not_found', "No campaign with id $id."); }
        $meta = wpultra_donate_campaign_meta($id);
        $postArr = ['ID' => $id];
        if ($title !== '') { $postArr['post_title'] = wp_slash($title); }
        if (array_key_exists('story', $in)) { $postArr['post_content'] = wp_slash($story); }
        if (count($postArr) > 1) { wp_update_post($postArr); }
    } else {
        if ($title === '') { return wpultra_err('missing_title', 'A new campaign requires a title.'); }
        $id = wp_insert_post([
            'post_type'    => WPULTRA_FUND_CPT,
            'post_status'  => 'publish',
            'post_title'   => wp_slash($title),
            'post_content' => wp_slash($story),
        ], true);
        if (is_wp_error($id)) { return $id; }
        $id   = (int) $id;
        $meta = ['raised' => 0.0, 'donor_count' => 0];
    }

    $meta['goal_amount'] = (float) $in['goal_amount'];
    $meta['currency']    = strtoupper((string) ($in['currency'] ?? ($meta['currency'] ?? 'USD')));
    $meta['status']      = (string) ($in['status'] ?? ($meta['status'] ?? 'active'));
    if (array_key_exists('deadline', $in)) {
        $meta['deadline'] = ($in['deadline'] === null || $in['deadline'] === '') ? null : (int) $in['deadline'];
    }
    if (array_key_exists('cover_image', $in)) { $meta['cover_image'] = (string) $in['cover_image']; }
    if (!isset($meta['raised']))      { $meta['raised'] = 0.0; }
    if (!isset($meta['donor_count'])) { $meta['donor_count'] = 0; }

    wpultra_donate_save_campaign_meta($id, $meta);
    return $id;
}

/** Load all donation meta blobs for a campaign (newest-first, scan-capped). */
function wpultra_donate_campaign_donations(int $campaign_id, int $scan = 2000): array {
    if (!function_exists('get_posts')) { return []; }
    $ids = get_posts([
        'post_type'        => WPULTRA_DONATION_CPT,
        'post_status'      => 'any',
        'numberposts'      => max(1, $scan),
        'orderby'          => 'date',
        'order'            => 'DESC',
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
        'meta_key'         => '_wpultra_donation_campaign',
        'meta_value'       => (string) $campaign_id,
    ]);
    $out = [];
    foreach ((array) $ids as $id) {
        $meta = get_post_meta((int) $id, WPULTRA_DONATION_META, true);
        if (is_array($meta)) { $out[] = ['id' => (int) $id, 'meta' => $meta]; }
    }
    return $out;
}

/** Recompute a campaign's cached raised/donor_count from its COMPLETED donations. */
function wpultra_donate_recompute_campaign(int $campaign_id): array {
    $items = wpultra_donate_campaign_donations($campaign_id);
    $blobs = array_map(static fn($it) => $it['meta'], $items);
    $sum   = wpultra_donate_sum($blobs);
    $meta  = wpultra_donate_campaign_meta($campaign_id);
    $meta['raised']      = $sum['raised'];
    $meta['donor_count'] = $sum['donor_count'];
    wpultra_donate_save_campaign_meta($campaign_id, $meta);
    return $sum;
}

/**
 * Record a donation. Creates a wpultra_donation post; when status is completed
 * the campaign's cached raised/donor_count are recomputed.
 * $in: {campaign_id, donor:{name,email}, amount, currency?, recurring?,
 *       anonymous?, status? (default pending), gateway_ref?}.
 * @return int|WP_Error donation id.
 */
function wpultra_donate_record(array $in) {
    $valid = wpultra_donate_validate($in);
    if ($valid !== true) { return wpultra_err('invalid_donation', (string) $valid); }
    if (!function_exists('wp_insert_post')) { return wpultra_err('wp_unavailable', 'WordPress is not loaded.'); }

    $campaign_id = (int) ($in['campaign_id'] ?? 0);
    if (wpultra_donate_load_campaign($campaign_id) === null) {
        return wpultra_err('not_found', "No campaign with id $campaign_id.");
    }

    $now  = function_exists('current_time') ? (int) current_time('timestamp', true) : time();
    $meta = wpultra_donate_new_donation_meta(
        $campaign_id,
        (array) ($in['donor'] ?? ['name' => $in['name'] ?? '', 'email' => $in['email'] ?? '']),
        (float) $in['amount'],
        (string) ($in['currency'] ?? 'USD'),
        (string) ($in['recurring'] ?? 'none'),
        $now,
        (bool) ($in['anonymous'] ?? false),
        (string) ($in['status'] ?? 'pending')
    );
    if (isset($in['gateway_ref'])) { $meta['gateway_ref'] = (string) $in['gateway_ref']; }

    $id = wp_insert_post([
        'post_type'   => WPULTRA_DONATION_CPT,
        'post_status' => 'publish',
        'post_title'  => 'Donation',
    ], true);
    if (is_wp_error($id)) { return $id; }
    $id = (int) $id;

    if (function_exists('wp_update_post')) {
        wp_update_post(['ID' => $id, 'post_title' => 'Donation #' . $id]);
    }
    update_post_meta($id, WPULTRA_DONATION_META, $meta);
    update_post_meta($id, '_wpultra_donation_campaign', (string) $campaign_id);

    if ($meta['status'] === 'completed') { wpultra_donate_recompute_campaign($campaign_id); }
    return $id;
}

/**
 * Mark a donation (webhook / manual completion path). Sets status, optionally a
 * gateway_ref, and recomputes the campaign cache. @return array|WP_Error.
 */
function wpultra_donate_mark(int $id, string $status, string $gateway_ref = '') {
    if (!in_array($status, wpultra_donate_statuses(), true)) {
        return wpultra_err('bad_status', "Unknown status '$status'.");
    }
    if (!function_exists('get_post_meta')) { return wpultra_err('wp_unavailable', 'WordPress is not loaded.'); }
    $meta = get_post_meta($id, WPULTRA_DONATION_META, true);
    if (!is_array($meta)) { return wpultra_err('not_found', "No donation with id $id."); }

    $meta['status'] = $status;
    if ($gateway_ref !== '') { $meta['gateway_ref'] = $gateway_ref; }
    update_post_meta($id, WPULTRA_DONATION_META, $meta);

    $campaign_id = (int) ($meta['campaign_id'] ?? 0);
    if ($campaign_id > 0) { wpultra_donate_recompute_campaign($campaign_id); }
    return wpultra_donate_donation_shape($meta, $id);
}

/** Refund a donation (status -> refunded, recompute campaign). @return array|WP_Error. */
function wpultra_donate_refund(int $id) {
    return wpultra_donate_mark($id, 'refunded');
}

/**
 * Recurring cron: across all campaigns, find due recurring installments and
 * record the NEXT one as a fresh pending donation (advancing next_charge on the
 * source record so it isn't picked again next tick). The GATEWAY performs the
 * real charge; a webhook then marks each new pending installment completed.
 * @return array{recorded:int, ids:array<int,int>}
 */
function wpultra_donate_run_recurring(?int $now = null): array {
    if (!function_exists('get_posts')) { return ['recorded' => 0, 'ids' => []]; }
    $now = $now ?? (function_exists('current_time') ? (int) current_time('timestamp', true) : time());

    $donationIds = get_posts([
        'post_type'        => WPULTRA_DONATION_CPT,
        'post_status'      => 'any',
        'numberposts'      => 500,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);

    $blobs = [];
    foreach ((array) $donationIds as $did) {
        $m = get_post_meta((int) $did, WPULTRA_DONATION_META, true);
        if (is_array($m)) { $m['__id'] = (int) $did; $blobs[] = $m; }
    }

    $due = wpultra_donate_recurring_due($blobs, $now);
    $recorded = [];
    foreach ($due as $src) {
        $newId = wpultra_donate_record([
            'campaign_id' => (int) ($src['campaign_id'] ?? 0),
            'donor'       => (array) ($src['donor'] ?? []),
            'amount'      => (float) ($src['amount'] ?? 0),
            'currency'    => (string) ($src['currency'] ?? 'USD'),
            'recurring'   => (string) ($src['recurring'] ?? 'none'),
            'anonymous'   => (bool) ($src['anonymous'] ?? false),
            'status'      => 'pending', // gateway charges; webhook completes it
        ]);
        if (!is_wp_error($newId)) {
            $recorded[] = (int) $newId;
            // Advance the SOURCE record's next_charge so it isn't due again next tick.
            $srcId = (int) ($src['__id'] ?? 0);
            if ($srcId > 0) {
                $srcMeta = get_post_meta($srcId, WPULTRA_DONATION_META, true);
                if (is_array($srcMeta)) {
                    $srcMeta['next_charge'] = wpultra_donate_next_charge((string) ($srcMeta['recurring'] ?? 'none'), $now, $now);
                    update_post_meta($srcId, WPULTRA_DONATION_META, $srcMeta);
                }
            }
        }
    }
    return ['recorded' => count($recorded), 'ids' => $recorded];
}

/* ------------------------------------------------------------------ *
 * Shortcode — [wpultra_donation_form campaign="<id>"]: progress bar +
 * a donate button. The button targets the campaign; wiring it to a real
 * gateway (Woo product or external checkout) is the site's job.
 * ------------------------------------------------------------------ */

function wpultra_donate_form_shortcode($atts): string {
    $atts = shortcode_atts(['campaign' => '0'], (array) $atts, 'wpultra_donation_form');
    $id   = (int) $atts['campaign'];
    $c    = wpultra_donate_load_campaign($id);
    if ($c === null) {
        return '<div class="wpultra-donation-form wpultra-donation-missing">Campaign not found.</div>';
    }
    $prog     = $c['progress'];
    $pct      = (float) $prog['pct'];
    $currency = wpultra_donate_esc((string) $c['currency']);
    $raised   = wpultra_donate_esc(number_format((float) $c['raised'], 2));
    $goal     = wpultra_donate_esc(number_format((float) $c['goal_amount'], 2));
    $title    = wpultra_donate_esc((string) $c['title']);
    $tiers    = wpultra_donate_tiers_suggest((float) $c['goal_amount']);

    $buttons = '';
    foreach ($tiers as $t) {
        $buttons .= '<button type="button" class="wpultra-donate-tier" data-amount="' . wpultra_donate_esc((string) $t) . '">'
            . $currency . ' ' . wpultra_donate_esc(number_format($t, 0)) . '</button> ';
    }

    $out  = '<div class="wpultra-donation-form" data-campaign="' . $id . '">' . "\n";
    $out .= '<h3>' . $title . '</h3>' . "\n";
    $out .= '<div class="wpultra-progress" role="progressbar" aria-valuenow="' . wpultra_donate_esc((string) $pct)
        . '" aria-valuemin="0" aria-valuemax="100" style="background:#eee;border-radius:6px;overflow:hidden;">'
        . '<div class="wpultra-progress-bar" style="width:' . wpultra_donate_esc((string) $pct)
        . '%;background:#2ea44f;height:16px;"></div></div>' . "\n";
    $out .= '<p class="wpultra-progress-label">' . $currency . ' ' . $raised . ' raised of ' . $currency . ' ' . $goal
        . ' (' . wpultra_donate_esc((string) $pct) . '%)</p>' . "\n";
    $out .= '<div class="wpultra-donate-tiers">' . $buttons . '</div>' . "\n";
    $out .= '<button type="button" class="wpultra-donate-button">Donate</button>' . "\n";
    $out .= '</div>';
    return $out;
}

/* ------------------------------------------------------------------ *
 * Boot — controller calls this from the always-on runtime. Cheap:
 * register CPTs on init + the shortcode + the recurring cron hook.
 * ------------------------------------------------------------------ */

function wpultra_donate_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    if (!function_exists('add_action')) { return; }

    if (function_exists('did_action') && did_action('init')) {
        wpultra_donate_register_cpts();
    } else {
        add_action('init', 'wpultra_donate_register_cpts');
    }

    if (function_exists('add_shortcode')) {
        add_shortcode('wpultra_donation_form', 'wpultra_donate_form_shortcode');
    }

    add_action(WPULTRA_DONATE_CRON, 'wpultra_donate_run_recurring');
    // Schedule a daily recurring tick if not already queued (best-effort).
    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
        if (!wp_next_scheduled(WPULTRA_DONATE_CRON)) {
            wp_schedule_event(time() + 3600, 'daily', WPULTRA_DONATE_CRON);
        }
    }
}
