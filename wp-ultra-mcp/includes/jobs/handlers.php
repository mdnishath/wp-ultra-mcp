<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Built-in job handlers + the extensible type registry. A handler processes ONE
 * slice per call and returns:
 *   ['cursor'=>array, 'processed'=>int, 'total'=>int, 'done'=>bool,
 *    'result'=>mixed(optional), 'message'=>string(optional)]
 * or a WP_Error to fail the job. Future waves add types via the
 * `wpultra_jobs_handlers` filter.
 */

const WPULTRA_JOBS_SR_BATCH   = 500; // rows per search-replace tick
const WPULTRA_JOBS_SCAN_BATCH = 100; // posts per bulk-meta / audit tick

/** type => [label, handler, validate]. Filterable. */
function wpultra_jobs_handlers(): array {
    $core = [
        'search-replace' => [
            'label'    => 'Serialized-safe search-replace across DB tables',
            'handler'  => 'wpultra_jobs_h_search_replace',
            'validate' => 'wpultra_jobs_v_search_replace',
        ],
        'bulk-post-meta' => [
            'label'    => 'Set a meta value across many posts',
            'handler'  => 'wpultra_jobs_h_bulk_post_meta',
            'validate' => 'wpultra_jobs_v_bulk_post_meta',
        ],
        'site-audit' => [
            'label'    => 'Walk all posts and collect SEO audit issues',
            'handler'  => 'wpultra_jobs_h_site_audit',
            'validate' => null,
        ],
    ];
    return function_exists('apply_filters') ? (array) apply_filters('wpultra_jobs_handlers', $core) : $core;
}

/* ------------------------------------------------------------------ *
 * PURE cursor advancement — the testable core of each handler.
 * ------------------------------------------------------------------ */

/**
 * search-replace: given the current {ti, offset} and how many rows the batch
 * returned, decide the next cursor. When a table returns fewer than a full
 * batch it is exhausted → advance to the next table (offset 0). done when past
 * the last table.
 * @return array{ti:int, offset:int, done:bool}
 */
function wpultra_jobs_sr_advance(array $cursor, int $rows_in_batch, int $batch_size, int $num_tables): array {
    $ti     = (int) ($cursor['ti'] ?? 0);
    $offset = (int) ($cursor['offset'] ?? 0);
    if ($rows_in_batch >= $batch_size) {
        return ['ti' => $ti, 'offset' => $offset + $rows_in_batch, 'done' => false];
    }
    // Table exhausted → next table.
    $ti++;
    return ['ti' => $ti, 'offset' => 0, 'done' => $ti >= $num_tables];
}

/**
 * Offset-paged scan (bulk-meta, audit): advance by how many were processed.
 * done when processed < batch OR the running total reaches the known total.
 * @return array{offset:int, done:bool}
 */
function wpultra_jobs_offset_advance(int $offset, int $processed_now, int $batch_size, int $total_processed, int $total): array {
    $next = $offset + $processed_now;
    $done = ($processed_now < $batch_size) || ($total > 0 && $total_processed >= $total);
    return ['offset' => $next, 'done' => $done];
}

/* ------------------------------------------------------------------ *
 * Validators.
 * ------------------------------------------------------------------ */

/** @return true|string */
function wpultra_jobs_v_search_replace(array $p) {
    if ((string) ($p['search'] ?? '') === '') { return 'search is required.'; }
    if (!array_key_exists('replace', $p))      { return 'replace is required.'; }
    if (empty($p['confirm']))                  { return 'A live background search-replace is destructive — pass confirm: true.'; }
    return true;
}

/** @return true|string */
function wpultra_jobs_v_bulk_post_meta(array $p) {
    if (!is_array($p['set'] ?? null) || $p['set'] === []) { return 'set (a {meta_key: value} map) is required.'; }
    foreach (array_keys($p['set']) as $k) {
        if (!is_string($k) || $k === '') { return 'set keys must be non-empty meta-key strings.'; }
    }
    if (empty($p['confirm'])) { return 'Writing meta across many posts is bulk-destructive — pass confirm: true.'; }
    return true;
}

/* ------------------------------------------------------------------ *
 * Handlers (thin WP wrappers around the pure helpers + existing engines).
 * ------------------------------------------------------------------ */

/** search-replace: one 500-row batch of the current table per tick. */
function wpultra_jobs_h_search_replace(array $params, array $cursor) {
    global $wpdb;
    $search  = (string) ($params['search'] ?? '');
    $replace = (string) ($params['replace'] ?? '');
    $tables  = array_values(array_filter(array_map('strval', (array) ($params['tables'] ?? []))));
    if ($tables === []) { $tables = wpultra_siteops_sr_default_tables(); }

    // First tick: count total rows across all mapped tables (for progress).
    if (empty($cursor['counted'])) {
        $total = 0;
        foreach ($tables as $logical) {
            $spec = wpultra_siteops_sr_table_columns($logical);
            if ($spec === []) { continue; }
            $total += (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}{$logical}`");
        }
        $cursor = ['ti' => 0, 'offset' => 0, 'counted' => true, 'total' => $total, 'seen' => 0, 'matches' => 0, 'replaced' => 0];
    }

    $ti = (int) $cursor['ti'];
    // Skip tables with no column map.
    while ($ti < count($tables) && wpultra_siteops_sr_table_columns($tables[$ti]) === []) { $ti++; }
    if ($ti >= count($tables)) {
        return ['cursor' => $cursor, 'processed' => (int) $cursor['seen'], 'total' => (int) $cursor['total'], 'done' => true,
                'result' => ['matches' => (int) $cursor['matches'], 'replaced' => (int) $cursor['replaced']],
                'message' => 'Complete.'];
    }

    $logical = $tables[$ti];
    $spec    = wpultra_siteops_sr_table_columns($logical);
    $table   = $wpdb->prefix . $logical;
    $pk      = $spec['id'];
    $cols    = $spec['cols'];
    $offset  = (int) $cursor['offset'];
    $select  = "`$pk`, `" . implode('`, `', $cols) . "`";

    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT $select FROM `$table` ORDER BY `$pk` LIMIT %d OFFSET %d", WPULTRA_JOBS_SR_BATCH, $offset),
        ARRAY_A
    );
    $rows = is_array($rows) ? $rows : [];

    foreach ($rows as $row) {
        $updates = [];
        foreach ($cols as $col) {
            $raw = (string) ($row[$col] ?? '');
            if ($raw === '') { continue; }
            [$new, $hits] = wpultra_sr_replace_column($raw, $search, $replace);
            if ($hits > 0) { $cursor['matches'] += $hits; $updates[$col] = $new; }
        }
        if ($updates !== []) {
            $ok = $wpdb->update($table, $updates, [$pk => $row[$pk]]);
            if ($ok !== false) { $cursor['replaced'] += count($updates); }
        }
    }

    $cursor['seen'] = (int) $cursor['seen'] + count($rows);
    $next = wpultra_jobs_sr_advance(['ti' => $ti, 'offset' => $offset], count($rows), WPULTRA_JOBS_SR_BATCH, count($tables));
    $cursor['ti'] = $next['ti'];
    $cursor['offset'] = $next['offset'];

    return [
        'cursor'    => $cursor,
        'processed' => (int) $cursor['seen'],
        'total'     => (int) $cursor['total'],
        'done'      => $next['done'],
        'result'    => ['matches' => (int) $cursor['matches'], 'replaced' => (int) $cursor['replaced']],
        'message'   => $next['done'] ? 'Complete.' : "Scanned {$logical} rows {$offset}..",
    ];
}

/** bulk-post-meta: one page of posts per tick, set each supplied meta key. */
function wpultra_jobs_h_bulk_post_meta(array $params, array $cursor) {
    $set        = (array) ($params['set'] ?? []);
    $post_types = array_values(array_filter(array_map('strval', (array) ($params['post_type'] ?? ['post', 'page']))));
    $statuses   = array_values(array_filter(array_map('strval', (array) ($params['status'] ?? ['publish']))));
    $only_missing = !empty($params['only_missing']); // set only where the key is absent/empty

    $offset = (int) ($cursor['offset'] ?? 0);
    $updated = (int) ($cursor['updated'] ?? 0);

    $q = new WP_Query([
        'post_type'      => $post_types ?: ['post', 'page'],
        'post_status'    => $statuses ?: ['publish'],
        'posts_per_page' => WPULTRA_JOBS_SCAN_BATCH,
        'offset'         => $offset,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => false,
    ]);
    $ids = array_map('intval', (array) $q->posts);
    $total = (int) $q->found_posts;

    foreach ($ids as $id) {
        foreach ($set as $key => $val) {
            if ($only_missing && (string) get_post_meta($id, (string) $key, true) !== '') { continue; }
            update_post_meta($id, (string) $key, $val);
            $updated++;
        }
    }

    $processed_now = count($ids);
    $seen = $offset + $processed_now;
    $adv = wpultra_jobs_offset_advance($offset, $processed_now, WPULTRA_JOBS_SCAN_BATCH, $seen, $total);

    return [
        'cursor'    => ['offset' => $adv['offset'], 'updated' => $updated],
        'processed' => $seen,
        'total'     => $total,
        'done'      => $adv['done'],
        'result'    => ['meta_writes' => $updated],
        'message'   => $adv['done'] ? "Done — {$updated} meta value(s) written." : "Processed {$seen}/{$total} posts.",
    ];
}

/** site-audit: page through posts, accumulate SEO issue counts + flagged rows. */
function wpultra_jobs_h_site_audit(array $params, array $cursor) {
    $post_types = array_values(array_filter(array_map('strval', (array) ($params['post_type'] ?? ['post', 'page']))));
    $offset = (int) ($cursor['offset'] ?? 0);
    $counts = (array) ($cursor['issue_counts'] ?? []);
    $flagged = (int) ($cursor['flagged'] ?? 0);

    $q = new WP_Query([
        'post_type'      => $post_types ?: ['post', 'page'],
        'post_status'    => 'publish',
        'posts_per_page' => WPULTRA_JOBS_SCAN_BATCH,
        'offset'         => $offset,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => false,
    ]);
    $ids = array_map('intval', (array) $q->posts);
    $total = (int) $q->found_posts;
    if ($ids && function_exists('update_meta_cache')) { update_meta_cache('post', $ids); }

    foreach ($ids as $id) {
        $issues = function_exists('wpultra_seo_audit_post') && function_exists('wpultra_seo_audit_extract')
            ? wpultra_seo_audit_post(wpultra_seo_audit_extract($id))
            : [];
        if ($issues) { $flagged++; }
        foreach ($issues as $i) {
            $code = (string) ($i['code'] ?? 'unknown');
            $counts[$code] = (int) ($counts[$code] ?? 0) + 1;
        }
    }

    $processed_now = count($ids);
    $seen = $offset + $processed_now;
    $adv = wpultra_jobs_offset_advance($offset, $processed_now, WPULTRA_JOBS_SCAN_BATCH, $seen, $total);

    return [
        'cursor'    => ['offset' => $adv['offset'], 'issue_counts' => $counts, 'flagged' => $flagged],
        'processed' => $seen,
        'total'     => $total,
        'done'      => $adv['done'],
        'result'    => ['scanned' => $seen, 'flagged_posts' => $flagged, 'issue_counts' => $counts],
        'message'   => $adv['done'] ? "Audit complete — {$flagged} post(s) with issues." : "Audited {$seen}/{$total} posts.",
    ];
}
