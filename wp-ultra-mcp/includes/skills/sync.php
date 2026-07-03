<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Skill marketplace sync: import community skill .md docs from any public
 * GitHub repo folder. Mirrors the GitHub-fetch conventions proven in
 * includes/system/updater.php (UA header, rate-limit awareness) and reuses
 * the existing skill CPT write path (wpultra_skill_write, skill-write.php)
 * so imported skills are indistinguishable from ones written by hand.
 */

require_once WPULTRA_DIR . 'includes/skills/sources.php';

const WPULTRA_SKILLSYNC_MAX_BYTES = 65536; // 64KB cap per remote file

/** Pure: basename of a filename without a trailing .md extension. */
function wpultra_skillsync_slug(string $filename): string {
    $base = basename(str_replace('\\', '/', trim($filename)));
    if (preg_match('/^(.*)\.md$/i', $base, $m)) { $base = $m[1]; }
    return sanitize_title($base);
}

/**
 * Pure: sanity-check a candidate skill markdown document before it is
 * persisted. Returns true when acceptable, or a human-readable error string.
 * Rules: non-empty, <= WPULTRA_SKILLSYNC_MAX_BYTES, no raw PHP tag, and must
 * start with either a '---' front-matter block or a '#' heading.
 */
function wpultra_skillsync_validate_doc(string $md): bool|string {
    $trimmed = trim($md);
    if ($trimmed === '') { return 'Document is empty.'; }
    if (strlen($md) > WPULTRA_SKILLSYNC_MAX_BYTES) {
        return 'Document exceeds the ' . WPULTRA_SKILLSYNC_MAX_BYTES . '-byte cap.';
    }
    if (stripos($md, '<?php') !== false) {
        return 'Document contains a PHP open tag; refusing to import executable code as a skill.';
    }
    if (!str_starts_with($trimmed, '---') && !str_starts_with($trimmed, '#')) {
        return "Document must start with '---' front matter or a '#' heading.";
    }
    return true;
}

/**
 * Pure: plan which remote files to import vs skip, given the local slugs
 * that already exist. $only restricts to specific slugs when non-empty.
 * Returns ['to_import' => [...remote entries...], 'to_skip' => [...{slug,status,reason}]].
 */
function wpultra_skillsync_plan(array $remote, array $local_slugs, array $only = [], bool $overwrite = false): array {
    $local_set = array_fill_keys($local_slugs, true);
    $only_set  = $only ? array_fill_keys($only, true) : null;
    $to_import = [];
    $to_skip   = [];
    foreach ($remote as $entry) {
        $slug = (string) ($entry['slug'] ?? '');
        if ($only_set !== null && !isset($only_set[$slug])) {
            $to_skip[] = ['slug' => $slug, 'status' => 'not_in_only', 'reason' => 'Not in only_slugs filter.'];
            continue;
        }
        $exists = isset($local_set[$slug]);
        if ($exists && !$overwrite) {
            $to_skip[] = ['slug' => $slug, 'status' => 'exists', 'reason' => "Skill '$slug' already exists; pass overwrite:true to replace."];
            continue;
        }
        $to_import[] = $entry;
    }
    return ['to_import' => $to_import, 'to_skip' => $to_skip];
}

/** Local skill slugs currently persisted (built-in + user CPT), for preview/plan comparison. */
function wpultra_skillsync_local_slugs(): array {
    return array_keys(wpultra_skill_all());
}

/**
 * List the .md files in a GitHub repo folder via the Contents API.
 * @return array|WP_Error list of ['name','slug','download_url','sha','size']
 */
function wpultra_skillsync_list_remote(string $repo, string $path = '', string $branch = 'main') {
    $repo = trim($repo, '/');
    if (!preg_match('#^[\w.\-]+/[\w.\-]+$#', $repo)) {
        return wpultra_err('bad_repo', "repo must look like 'owner/name', got '$repo'.");
    }
    $path = trim($path, '/');
    $url = 'https://api.github.com/repos/' . $repo . '/contents/' . ($path !== '' ? rawurlencode_path($path) : '');
    $url = add_query_arg('ref', rawurlencode($branch !== '' ? $branch : 'main'), $url);
    $ua = 'wp-ultra-mcp/' . (defined('WPULTRA_VERSION') ? WPULTRA_VERSION : '0');
    $resp = wp_remote_get($url, [
        'timeout' => 15,
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => $ua],
    ]);
    if (is_wp_error($resp)) { return $resp; }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code === 403 || $code === 429) {
        return wpultra_err('rate_limited', 'GitHub API rate limit hit (unauthenticated requests are capped). Wait a while and retry, or narrow the folder path.');
    }
    if ($code === 404) {
        return wpultra_err('not_found', "Repo/path/branch not found: $repo" . ($path !== '' ? "/$path" : '') . "@$branch");
    }
    if ($code !== 200) {
        return wpultra_err('github_error', "GitHub Contents API returned HTTP $code.");
    }
    $json = json_decode($body, true);
    if (!is_array($json)) { return wpultra_err('bad_response', 'Could not parse the GitHub Contents API response.'); }
    // A single-file path returns an object, not a list — normalize to a list either way.
    if (isset($json['name']) && !isset($json[0])) { $json = [$json]; }
    $out = [];
    foreach ($json as $item) {
        if (!is_array($item)) { continue; }
        $name = (string) ($item['name'] ?? '');
        if ($name === '' || !preg_match('/\.md$/i', $name)) { continue; }
        if (($item['type'] ?? '') !== 'file') { continue; }
        $out[] = [
            'name'         => $name,
            'slug'         => wpultra_skillsync_slug($name),
            'download_url' => (string) ($item['download_url'] ?? ''),
            'sha'          => (string) ($item['sha'] ?? ''),
            'size'         => (int) ($item['size'] ?? 0),
        ];
    }
    return $out;
}

/** rawurlencode each path segment but keep the '/' separators intact. */
function rawurlencode_path(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Preview: remote listing compared against local skill slugs.
 * @return array|WP_Error ['repo','path','branch','files' => [{name,slug,size,status:new|exists}]]
 */
function wpultra_skillsync_preview(string $repo, string $path = '', string $branch = 'main') {
    $remote = wpultra_skillsync_list_remote($repo, $path, $branch);
    if (is_wp_error($remote)) { return $remote; }
    $local_set = array_fill_keys(wpultra_skillsync_local_slugs(), true);
    $files = [];
    foreach ($remote as $entry) {
        $files[] = [
            'name'   => $entry['name'],
            'slug'   => $entry['slug'],
            'size'   => $entry['size'],
            'status' => isset($local_set[$entry['slug']]) ? 'exists' : 'new',
        ];
    }
    return ['repo' => $repo, 'path' => $path, 'branch' => $branch, 'files' => $files];
}

/**
 * Import: fetch each planned remote .md file (raw, capped at
 * WPULTRA_SKILLSYNC_MAX_BYTES), validate it, and persist via the existing
 * skill write path (wpultra_skill_write). Existing slugs are skipped unless
 * $overwrite is true. $only_slugs restricts the import to specific slugs.
 * @return array|WP_Error {imported:[{slug,post_id}], skipped:[{slug,status,reason}], errors:[{slug,error}]}
 */
function wpultra_skillsync_import(string $repo, string $path = '', string $branch = 'main', array $only_slugs = [], bool $overwrite = false) {
    $remote = wpultra_skillsync_list_remote($repo, $path, $branch);
    if (is_wp_error($remote)) { return $remote; }
    $plan = wpultra_skillsync_plan($remote, wpultra_skillsync_local_slugs(), $only_slugs, $overwrite);

    $imported = [];
    $errors   = [];
    $ua = 'wp-ultra-mcp/' . (defined('WPULTRA_VERSION') ? WPULTRA_VERSION : '0');

    foreach ($plan['to_import'] as $entry) {
        $slug = $entry['slug'];
        $download_url = (string) ($entry['download_url'] ?? '');
        if ($download_url === '') {
            $errors[] = ['slug' => $slug, 'error' => 'No download_url present for this file.'];
            continue;
        }
        $resp = wp_remote_get($download_url, ['timeout' => 15, 'headers' => ['User-Agent' => $ua]]);
        if (is_wp_error($resp)) {
            $errors[] = ['slug' => $slug, 'error' => $resp->get_error_message()];
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code === 403 || $code === 429) {
            $errors[] = ['slug' => $slug, 'error' => 'Rate-limited while downloading; retry later.'];
            continue;
        }
        if ($code !== 200) {
            $errors[] = ['slug' => $slug, 'error' => "Download failed with HTTP $code."];
            continue;
        }
        $body = wp_remote_retrieve_body($resp);
        if (strlen($body) > WPULTRA_SKILLSYNC_MAX_BYTES) {
            $errors[] = ['slug' => $slug, 'error' => 'Remote file exceeds the ' . WPULTRA_SKILLSYNC_MAX_BYTES . '-byte cap.'];
            continue;
        }
        $valid = wpultra_skillsync_validate_doc($body);
        if ($valid !== true) {
            $errors[] = ['slug' => $slug, 'error' => $valid];
            continue;
        }
        $parsed = wpultra_skill_parse_frontmatter($body);
        $result = wpultra_skill_write([
            'slug'           => $slug,
            'description'    => $parsed['description'] !== '' ? $parsed['description'] : ('Imported from ' . $repo . '/' . $entry['name']),
            'body'           => $parsed['body'] !== '' ? $parsed['body'] : $body,
            'enable_prompt'  => $parsed['enable_prompt'],
            'enable_agentic' => $parsed['enable_agentic'],
            'on_conflict'    => 'replace',
        ]);
        if (is_wp_error($result)) {
            $errors[] = ['slug' => $slug, 'error' => $result->get_error_message()];
            continue;
        }
        $imported[] = ['slug' => $slug, 'post_id' => (int) ($result['post_id'] ?? 0)];
    }

    return ['imported' => $imported, 'skipped' => $plan['to_skip'], 'errors' => $errors];
}
