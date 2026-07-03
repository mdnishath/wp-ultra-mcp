<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Site-brain engine: fuses the site snapshot, persistent memories, recent audit
 * activity, per-ability failure stats, active triggers, and the site's own
 * minted tools (custom abilities/playbooks/widgets) into ONE structured
 * orientation object — the first thing the AI should read at the start of a
 * session on a given site.
 *
 * Pure logic (render_markdown, excerpt, hotspots) is kept dependency-free so it
 * is unit-testable under the zero-dependency harness. `build()` is the thin WP
 * dispatcher: it reuses wpultra_snapshot_*, wpultra_memory_shape, the audit log
 * option, wpultra_stats_rank, and (when present) triggers/recipes/playbooks/
 * widgets listers — every optional source is guarded with function_exists() so
 * this never fatals when a subsystem/category is disabled.
 */

const WPULTRA_BRAIN_CACHE_OPTION = 'wpultra_brain_cache';
const WPULTRA_BRAIN_CACHE_TTL    = 600; // 10 minutes

/* ------------------------------------------------------------------ *
 * PURE helpers.
 * ------------------------------------------------------------------ */

/**
 * Codepoint-safe excerpt: trims to at most $n characters (mb-aware when the
 * mbstring extension is available, else falls back to a codepoint-counting
 * regex so multi-byte scripts like Bengali are never cut mid-character), tags
 * stripped, whitespace collapsed, ellipsis appended when truncated. Pure.
 */
function wpultra_brain_excerpt(string $s, int $n): string {
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($s)));
    if ($n <= 0) { return ''; }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $n) { return $text; }
        return rtrim(mb_substr($text, 0, $n, 'UTF-8')) . '…';
    }
    // No mbstring: count codepoints via a UTF-8-aware regex rather than byte-length,
    // so a truncation point never lands inside a multi-byte character.
    if (preg_match_all('/./us', $text, $m) <= $n) { return $text; }
    $chars = $m[0];
    if (count($chars) <= $n) { return $text; }
    return rtrim(implode('', array_slice($chars, 0, $n))) . '…';
}

/**
 * Pure: rank a stats map (same shape as the option fed by wpultra_stats_bump —
 * action => {calls, fails, last_error}) and return the top-5 by fail count
 * descending, then fail_rate descending as the tiebreaker. Reuses
 * wpultra_stats_rank()'s row shape (action/calls/fails/fail_rate/last_error)
 * when available so both callers agree on the shape; falls back to an inline
 * equivalent so this file has no hard require on selftest/engine.php.
 */
function wpultra_brain_hotspots(array $stats, int $limit = 5): array {
    $rows = [];
    foreach ($stats as $action => $s) {
        if (!is_array($s)) { continue; }
        $calls = (int) ($s['calls'] ?? 0);
        $fails = (int) ($s['fails'] ?? 0);
        if ($fails <= 0) { continue; } // hotspots = actions that have actually failed
        $rows[] = [
            'action'     => (string) $action,
            'calls'      => $calls,
            'fails'      => $fails,
            'fail_rate'  => $calls > 0 ? round($fails / $calls, 3) : 0.0,
            'last_error' => (string) ($s['last_error'] ?? ''),
        ];
    }
    usort($rows, function ($a, $b) {
        return [$b['fails'], $b['fail_rate']] <=> [$a['fails'], $a['fail_rate']];
    });
    return array_slice($rows, 0, max(1, $limit));
}

/** Pure: markdown-escape a table cell (pipes would break the row). */
function wpultra_brain_md_cell(string $s): string {
    $s = str_replace(["\r\n", "\n", "\r"], ' ', $s);
    return str_replace('|', '\\|', $s);
}

/**
 * Pure: render a brain object (as produced by wpultra_brain_build(), or an
 * equivalent fixture in tests) into a compact markdown briefing. Deterministic
 * section order; never touches WordPress.
 *
 * Expected (all optional) top-level keys: site, memories[], recent_activity[],
 * failure_hotspots[], triggers[], custom{abilities[],playbooks[],widgets[]},
 * generated_at.
 */
function wpultra_brain_render_markdown(array $brain): string {
    $out = [];
    $out[] = '# Site Brain';
    $generated = (string) ($brain['generated_at'] ?? '');
    if ($generated !== '') { $out[] = '_Generated: ' . $generated . '_'; }
    $out[] = '';

    // ---- Site ----
    $site = (array) ($brain['site'] ?? []);
    if ($site !== []) {
        $out[] = '## Site';
        $name = (string) ($site['name'] ?? '');
        $url  = (string) ($site['url'] ?? '');
        $wp   = (string) ($site['wp_version'] ?? '');
        if ($name !== '' || $url !== '') { $out[] = '- **' . $name . '** — ' . $url; }
        if ($wp !== '') { $out[] = '- WordPress ' . $wp; }
        $plugins = (array) ($site['plugins'] ?? []);
        $active = (array) ($plugins['active'] ?? []);
        if ($active !== []) {
            $names = [];
            foreach ($active as $p) { $names[] = (string) ($p['name'] ?? ($p['plugin'] ?? '')); }
            $out[] = '- Active plugins (' . count($active) . '): ' . implode(', ', $names);
        }
        $out[] = '';
    }

    // ---- Memories ----
    $memories = (array) ($brain['memories'] ?? []);
    $out[] = '## Memories (' . count($memories) . ')';
    if ($memories === []) {
        $out[] = '_No saved memories yet._';
    } else {
        foreach ($memories as $m) {
            $m = (array) $m;
            $title = (string) ($m['title'] ?? '');
            $type = (string) ($m['type'] ?? '');
            $excerpt = (string) ($m['excerpt'] ?? '');
            $line = '- **' . $title . '**';
            if ($type !== '') { $line .= ' (' . $type . ')'; }
            if ($excerpt !== '') { $line .= ' — ' . $excerpt; }
            $out[] = $line;
        }
    }
    $out[] = '';

    // ---- Recent activity ----
    $activity = (array) ($brain['recent_activity'] ?? []);
    $out[] = '## Recent Activity (' . count($activity) . ')';
    if ($activity === []) {
        $out[] = '_No recorded actions yet._';
    } else {
        foreach ($activity as $a) {
            $a = (array) $a;
            $ts = (string) ($a['ts'] ?? '');
            $action = (string) ($a['action'] ?? '');
            $summary = (string) ($a['summary'] ?? '');
            $ok = ($a['ok'] ?? true) !== false;
            $mark = $ok ? 'OK' : 'FAIL';
            $out[] = "- [$mark] $ts — **$action** — $summary";
        }
    }
    $out[] = '';

    // ---- Failure hotspots ----
    $hotspots = (array) ($brain['failure_hotspots'] ?? []);
    $out[] = '## Failure Hotspots';
    if ($hotspots === []) {
        $out[] = '_No failures recorded._';
    } else {
        $out[] = '| Action | Calls | Fails | Fail rate | Last error |';
        $out[] = '|---|---|---|---|---|';
        foreach ($hotspots as $h) {
            $h = (array) $h;
            $out[] = '| ' . wpultra_brain_md_cell((string) ($h['action'] ?? '')) . ' | '
                . (int) ($h['calls'] ?? 0) . ' | '
                . (int) ($h['fails'] ?? 0) . ' | '
                . (string) ($h['fail_rate'] ?? 0) . ' | '
                . wpultra_brain_md_cell((string) ($h['last_error'] ?? '')) . ' |';
        }
    }
    $out[] = '';

    // ---- Triggers ----
    $triggers = (array) ($brain['triggers'] ?? []);
    $out[] = '## Active Triggers (' . count($triggers) . ')';
    if ($triggers === []) {
        $out[] = '_No active triggers._';
    } else {
        foreach ($triggers as $t) {
            $t = (array) $t;
            $event = (string) ($t['event'] ?? '');
            $action_type = (string) ($t['action_type'] ?? '');
            $target = (string) ($t['target'] ?? '');
            $out[] = '- `' . $event . '` → ' . $action_type . ($target !== '' ? ' (' . $target . ')' : '');
        }
    }
    $out[] = '';

    // ---- Custom tools ----
    $custom = (array) ($brain['custom'] ?? []);
    $abilities = (array) ($custom['abilities'] ?? []);
    $playbooks = (array) ($custom['playbooks'] ?? []);
    $widgets = (array) ($custom['widgets'] ?? []);
    $out[] = '## Custom Tools';
    $out[] = '- Abilities (' . count($abilities) . '): ' . ($abilities !== [] ? implode(', ', array_map('strval', $abilities)) : '_none_');
    $out[] = '- Playbooks (' . count($playbooks) . '): ' . ($playbooks !== [] ? implode(', ', array_map('strval', $playbooks)) : '_none_');
    $out[] = '- Widgets (' . count($widgets) . '): ' . ($widgets !== [] ? implode(', ', array_map('strval', $widgets)) : '_none_');

    return implode("\n", $out) . "\n";
}

/* ------------------------------------------------------------------ *
 * WP-calling assembly + cache.
 * ------------------------------------------------------------------ */

/** Shape one memory CPT post into the compact {title, type, excerpt} brain form. */
function wpultra_brain_memory_row(array $shaped): array {
    return [
        'title'   => (string) ($shaped['name'] ?? ''),
        'type'    => (string) ($shaped['type'] ?? ''),
        'excerpt' => wpultra_brain_excerpt((string) ($shaped['description'] ?? ''), 200),
    ];
}

/** Latest 20 memories (title/type/excerpt), newest-updated first. WP-calling. */
function wpultra_brain_memories(): array {
    if (!function_exists('get_posts')) { return []; }
    $posts = get_posts([
        'post_type' => 'wpultra_memory', 'post_status' => 'publish',
        'numberposts' => 20, 'orderby' => 'modified', 'order' => 'DESC',
    ]);
    $out = [];
    foreach ($posts as $p) {
        $shaped = function_exists('wpultra_memory_shape') ? wpultra_memory_shape($p) : [
            'name' => $p->post_title ?? '', 'type' => '', 'description' => $p->post_excerpt ?? '',
        ];
        $out[] = wpultra_brain_memory_row($shaped);
    }
    return $out;
}

/** Last 15 audit-log entries (newest first), shaped to {ts, action, summary, ok}. WP-calling. */
function wpultra_brain_recent_activity(): array {
    if (!function_exists('get_option')) { return []; }
    $log = get_option('wpultra_audit', []);
    if (!is_array($log)) { return []; }
    $tail = array_slice($log, -15);
    $tail = array_reverse($tail); // newest first
    $out = [];
    foreach ($tail as $e) {
        if (!is_array($e)) { continue; }
        $out[] = [
            'ts'      => (string) ($e['ts'] ?? ''),
            'action'  => (string) ($e['action'] ?? ''),
            'summary' => (string) ($e['summary'] ?? ''),
            'ok'      => ($e['ok'] ?? true) !== false,
        ];
    }
    return $out;
}

/** Top-5 failure hotspots from the ability-stats option. WP-calling. */
function wpultra_brain_failure_hotspots(): array {
    if (!function_exists('get_option')) { return []; }
    $stats = get_option('wpultra_ability_stats', []);
    if (!is_array($stats)) { return []; }
    return wpultra_brain_hotspots($stats, 5);
}

/** Active trigger shapes, when the triggers engine is loaded. WP-calling. */
function wpultra_brain_triggers(): array {
    if (!function_exists('wpultra_triggers_load') || !function_exists('wpultra_triggers_shape')) { return []; }
    $out = [];
    foreach (wpultra_triggers_load() as $t) {
        $shaped = wpultra_triggers_shape((array) $t);
        if (($shaped['enabled'] ?? true) === false) { continue; }
        $out[] = $shaped;
    }
    return $out;
}

/** {abilities, playbooks, widgets} slugs/names — each source guarded independently. WP-calling. */
function wpultra_brain_custom(): array {
    $abilities = [];
    if (function_exists('wpultra_recipe_all')) {
        foreach (wpultra_recipe_all() as $row) { $abilities[] = (string) ($row['slug'] ?? ''); }
    }
    $playbooks = [];
    if (function_exists('wpultra_playbook_list')) {
        foreach (wpultra_playbook_list() as $row) { $playbooks[] = (string) ($row['slug'] ?? ''); }
    }
    $widgets = [];
    if (function_exists('wpultra_widgets_all')) {
        $widgets = array_keys(wpultra_widgets_all());
    }
    return [
        'abilities' => array_values(array_filter($abilities, static fn($s) => $s !== '')),
        'playbooks' => array_values(array_filter($playbooks, static fn($s) => $s !== '')),
        'widgets'   => array_values(array_map('strval', $widgets)),
    ];
}

/** Read the cached brain when fresh (ts within TTL). @return array|null */
function wpultra_brain_cache_get(): ?array {
    if (!function_exists('get_option')) { return null; }
    $cache = get_option(WPULTRA_BRAIN_CACHE_OPTION, null);
    if (!is_array($cache) || !isset($cache['brain'], $cache['ts'])) { return null; }
    $ts = (int) $cache['ts'];
    if ((time() - $ts) > WPULTRA_BRAIN_CACHE_TTL) { return null; }
    return (array) $cache['brain'];
}

function wpultra_brain_cache_set(array $brain): void {
    if (!function_exists('update_option')) { return; }
    update_option(WPULTRA_BRAIN_CACHE_OPTION, ['brain' => $brain, 'ts' => time()], false);
}

/**
 * Assemble the full brain object, honoring the 10-minute cache unless
 * `$opts['refresh']` is true. Every optional source is guarded with
 * function_exists() so a disabled subsystem/category just yields an empty
 * section instead of a fatal.
 */
function wpultra_brain_build(array $opts = []): array {
    $refresh = !empty($opts['refresh']);
    if (!$refresh) {
        $cached = wpultra_brain_cache_get();
        if ($cached !== null) { return $cached; }
    }

    $site = [];
    if (function_exists('wpultra_snapshot_site')) { $site = array_merge($site, wpultra_snapshot_site()); }
    if (function_exists('wpultra_snapshot_plugins')) { $site['plugins'] = wpultra_snapshot_plugins(); }

    $brain = [
        'site'              => $site,
        'memories'          => wpultra_brain_memories(),
        'recent_activity'   => wpultra_brain_recent_activity(),
        'failure_hotspots'  => wpultra_brain_failure_hotspots(),
        'triggers'          => wpultra_brain_triggers(),
        'custom'            => wpultra_brain_custom(),
        'generated_at'      => function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
    ];

    wpultra_brain_cache_set($brain);
    return $brain;
}
