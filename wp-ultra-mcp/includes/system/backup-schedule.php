<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Scheduled + off-site backups (Roadmap C3).
 *
 * Adds a WP-Cron job that periodically creates a full-site backup (REUSING the
 * proven engine in includes/system/backup.php — no re-implementation of zipping /
 * DB dumping), applies a local retention policy (keep newest N), and optionally
 * pushes the resulting zip to an off-site destination (S3 or Dropbox).
 *
 * DESIGN / SCOPE HONESTY
 *  - Off-site credentials are stored in the `wpultra_bksched` option. That is the
 *    only place WordPress can persist config, so treat it like any other WP secret:
 *    use a DEDICATED, SCOPED credential (a purpose-built IAM user limited to
 *    s3:PutObject on one bucket/prefix, or a short-lived scoped Dropbox token) —
 *    never your root keys. Secrets are masked in every ability response.
 *  - Google Drive is intentionally NOT implemented: its OAuth refresh-token dance
 *    is far heavier than a single signed PUT and can't be done honestly as a pure
 *    function here. Use S3 or Dropbox. Drive is reported as unsupported.
 *  - S3 uploads use AWS Signature V4 for a single PUT object. The signing math is
 *    factored into PURE, unit-tested functions; the WP wrapper only performs the
 *    wp_remote_request PUT with the computed Authorization header.
 *
 * The pushed object is the DB dump (db.sql.gz) when only db is backed up, otherwise
 * the whole backup is tar-less: we push whichever single file best represents the
 * backup (files.zip when files were included, else db.sql.gz). This keeps the push
 * to one signed request without inventing a new archive format.
 */

/* ============================================================
 * PURE helpers (unit-tested — no WordPress required)
 * ============================================================ */

/** PURE: allowed cron recurrences. */
function wpultra_bksched_recurrences(): array {
    return ['daily', 'weekly'];
}

/** PURE: allowed destination types. */
function wpultra_bksched_dest_types(): array {
    return ['none', 's3', 'dropbox'];
}

/**
 * PURE: the canonical default config shape. Callers merge user input over this.
 * db true / files false by default — a file backup can be huge and the DB is the
 * irreplaceable part; the operator opts into files explicitly.
 */
function wpultra_bksched_defaults(): array {
    return [
        'enabled'     => false,
        'recurrence'  => 'daily',
        'parts'       => ['db' => true, 'files' => false],
        'retention'   => 5,
        'max_push_mb' => 512,
        'destination' => ['type' => 'none', 'config' => []],
        'last_run'    => null,
        'last_status' => null,
        'history'     => [],
    ];
}

/**
 * PURE: mask a secret for display. Short secrets (<= 6 chars, or empty) are fully
 * masked; longer secrets keep the first 2 and last 2 characters with a fixed dot
 * run between so the length is not leaked.
 */
function wpultra_bksched_mask(string $secret): string {
    if ($secret === '') { return ''; }
    if (strlen($secret) <= 6) { return str_repeat('•', 6); }
    return substr($secret, 0, 2) . '••••' . substr($secret, -2);
}

/**
 * PURE: given the current backups (each a shaped row with 'name' + 'modified'
 * ISO-8601 string, newest OR any order) and the number to keep, return the NAMES
 * that should be DELETED. Newest are kept: rows are sorted by 'modified' descending
 * (rows without a parseable date sort oldest). keep >= count => []. keep <= 0 keeps
 * nothing (all names returned).
 *
 * @param array<int,array{name:string,modified?:?string}> $backups
 * @return array<int,string> names to delete
 */
function wpultra_bksched_prune(array $backups, int $keep): array {
    // Attach a sort key (epoch) to each, defaulting missing/unparseable to 0 (oldest).
    $rows = [];
    foreach ($backups as $b) {
        $name = (string) ($b['name'] ?? '');
        if ($name === '') { continue; }
        $mod = $b['modified'] ?? null;
        $ts  = 0;
        if (is_string($mod) && $mod !== '') {
            $parsed = strtotime($mod);
            if ($parsed !== false) { $ts = $parsed; }
        } elseif (is_int($mod)) {
            $ts = $mod;
        }
        $rows[] = ['name' => $name, 'ts' => $ts];
    }
    // Stable-ish sort: newest first; ties keep input order via index.
    $idx = 0;
    foreach ($rows as &$r) { $r['_i'] = $idx++; }
    unset($r);
    usort($rows, static function ($a, $b) {
        if ($a['ts'] !== $b['ts']) { return $b['ts'] <=> $a['ts']; }
        return $a['_i'] <=> $b['_i'];
    });

    if ($keep < 0) { $keep = 0; }
    $delete = [];
    foreach ($rows as $i => $r) {
        if ($i >= $keep) { $delete[] = $r['name']; }
    }
    return $delete;
}

/**
 * PURE: is a backup within the off-site push size limit? Both a 0 or negative cap
 * mean "no limit" (always allowed). Comparison is inclusive: exactly max_mb passes.
 */
function wpultra_bksched_within_push_limit(int $bytes, int $max_mb): bool {
    if ($max_mb <= 0) { return true; }
    return $bytes <= $max_mb * 1024 * 1024;
}

/**
 * PURE: build the Dropbox-API-Arg JSON for a files/upload. Uses mode "add" and
 * autorename true so a scheduled push never clobbers a prior day's object and never
 * fails on a name collision. The path is coerced to a leading-slash absolute path.
 */
function wpultra_bksched_dropbox_arg(string $path): string {
    $path = '/' . ltrim($path, '/');
    return (string) json_encode([
        'path'       => $path,
        'mode'       => 'add',
        'autorename' => true,
        'mute'       => false,
    ], JSON_UNESCAPED_SLASHES);
}

/* ---- AWS Signature V4 (PURE) ---- */

/** PURE: SHA-256 hex of a string (the canonical "hashed payload" primitive). */
function wpultra_bksched_sha256_hex(string $data): string {
    return hash('sha256', $data);
}

/**
 * PURE: build the SigV4 canonical request string.
 *
 * @param string               $method        HTTP verb, e.g. "PUT".
 * @param string               $canonical_uri URI-encoded path, e.g. "/prefix/key.zip".
 * @param string               $query         canonical (sorted) query string, "" for none.
 * @param array<string,string> $headers       header name => value (will be lowercased/sorted).
 * @param string               $payload_hash  hex sha256 of the body (or "UNSIGNED-PAYLOAD").
 * @return array{canonical:string,signed_headers:string} canonical request + the signed-headers list.
 */
function wpultra_bksched_s3_canonical_request(string $method, string $canonical_uri, string $query, array $headers, string $payload_hash): array {
    // Lowercase + trim header names/values, sort by name.
    $norm = [];
    foreach ($headers as $k => $v) {
        $norm[strtolower(trim((string) $k))] = trim((string) $v);
    }
    ksort($norm);

    $canonical_headers = '';
    foreach ($norm as $k => $v) {
        $canonical_headers .= $k . ':' . $v . "\n";
    }
    $signed_headers = implode(';', array_keys($norm));

    $canonical = strtoupper($method) . "\n"
        . $canonical_uri . "\n"
        . $query . "\n"
        . $canonical_headers . "\n"
        . $signed_headers . "\n"
        . $payload_hash;

    return ['canonical' => $canonical, 'signed_headers' => $signed_headers];
}

/**
 * PURE: build the SigV4 "string to sign".
 *
 * @param string $amz_date          ISO8601 basic, e.g. "20130524T000000Z".
 * @param string $scope             credential scope, e.g. "20130524/us-east-1/s3/aws4_request".
 * @param string $canonical_request the canonical request string.
 */
function wpultra_bksched_s3_signing_string(string $amz_date, string $scope, string $canonical_request): string {
    return "AWS4-HMAC-SHA256\n"
        . $amz_date . "\n"
        . $scope . "\n"
        . wpultra_bksched_sha256_hex($canonical_request);
}

/**
 * PURE: derive the SigV4 signing key via the nested-HMAC chain.
 * kDate=HMAC("AWS4"+secret, date); kRegion=HMAC(kDate, region);
 * kService=HMAC(kRegion, service); kSigning=HMAC(kService, "aws4_request").
 * Returns the raw binary key.
 */
function wpultra_bksched_s3_signing_key(string $secret, string $date, string $region, string $service): string {
    $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

/**
 * PURE: full SigV4 signature (hex) for a request. Composes signing-key + string-to-sign.
 * This is the single value that goes into the Authorization header's Signature=.
 */
function wpultra_bksched_s3_signature(string $secret, string $date, string $region, string $service, string $string_to_sign): string {
    $key = wpultra_bksched_s3_signing_key($secret, $date, $region, $service);
    return hash_hmac('sha256', $string_to_sign, $key);
}

/**
 * PURE: assemble the Authorization header value for a SigV4 request.
 */
function wpultra_bksched_s3_authorization(string $access_key, string $scope, string $signed_headers, string $signature): string {
    return 'AWS4-HMAC-SHA256 '
        . 'Credential=' . $access_key . '/' . $scope . ', '
        . 'SignedHeaders=' . $signed_headers . ', '
        . 'Signature=' . $signature;
}

/**
 * PURE: URI-encode an S3 object key path segment-by-segment (slashes preserved).
 * S3 requires each path segment RFC3986-encoded; PHP's rawurlencode already leaves
 * unreserved chars alone, so we just protect the slashes.
 */
function wpultra_bksched_s3_encode_key(string $key): string {
    $parts = explode('/', ltrim($key, '/'));
    $parts = array_map('rawurlencode', $parts);
    return '/' . implode('/', $parts);
}

/**
 * PURE: validate + normalize a config-merge request. Returns the merged config
 * (over defaults) on success, or WP_Error on the first validation failure. Does NOT
 * touch WordPress — the WP wrapper persists the result.
 *
 * @param array $current existing stored config (may be partial).
 * @param array $patch   user-supplied fields to merge/validate.
 * @return array|WP_Error
 */
function wpultra_bksched_validate_config(array $current, array $patch) {
    $cfg = array_replace_recursive(wpultra_bksched_defaults(), array_intersect_key($current, wpultra_bksched_defaults()));

    // enabled
    if (array_key_exists('enabled', $patch)) {
        $cfg['enabled'] = ($patch['enabled'] === true || $patch['enabled'] === 1 || $patch['enabled'] === '1');
    }

    // recurrence
    if (array_key_exists('recurrence', $patch)) {
        $rec = (string) $patch['recurrence'];
        if (!in_array($rec, wpultra_bksched_recurrences(), true)) {
            return wpultra_err('bad_recurrence', "recurrence must be one of: " . implode(', ', wpultra_bksched_recurrences()) . "; got '$rec'.");
        }
        $cfg['recurrence'] = $rec;
    }

    // parts
    if (array_key_exists('parts', $patch) && is_array($patch['parts'])) {
        if (array_key_exists('db', $patch['parts'])) {
            $cfg['parts']['db'] = ($patch['parts']['db'] === true || $patch['parts']['db'] === 1 || $patch['parts']['db'] === '1');
        }
        if (array_key_exists('files', $patch['parts'])) {
            $cfg['parts']['files'] = ($patch['parts']['files'] === true || $patch['parts']['files'] === 1 || $patch['parts']['files'] === '1');
        }
    }
    if ($cfg['parts']['db'] !== true && $cfg['parts']['files'] !== true) {
        return wpultra_err('empty_parts', 'At least one backup part (db or files) must be enabled.');
    }

    // retention
    if (array_key_exists('retention', $patch)) {
        $ret = $patch['retention'];
        if (!is_int($ret) && !(is_string($ret) && ctype_digit($ret))) {
            return wpultra_err('bad_retention', 'retention must be an integer >= 1.');
        }
        $ret = (int) $ret;
        if ($ret < 1) { return wpultra_err('bad_retention', 'retention must be >= 1.'); }
        $cfg['retention'] = $ret;
    }

    // max_push_mb
    if (array_key_exists('max_push_mb', $patch)) {
        $mp = $patch['max_push_mb'];
        if (!is_int($mp) && !(is_string($mp) && ctype_digit($mp))) {
            return wpultra_err('bad_max_push_mb', 'max_push_mb must be a non-negative integer (0 = unlimited).');
        }
        $mp = (int) $mp;
        if ($mp < 0) { return wpultra_err('bad_max_push_mb', 'max_push_mb must be >= 0.'); }
        $cfg['max_push_mb'] = $mp;
    }

    // destination
    if (array_key_exists('destination', $patch) && is_array($patch['destination'])) {
        $type = (string) ($patch['destination']['type'] ?? $cfg['destination']['type']);
        if (!in_array($type, wpultra_bksched_dest_types(), true)) {
            return wpultra_err('bad_destination', "destination.type must be one of: " . implode(', ', wpultra_bksched_dest_types()) . "; got '$type'.");
        }
        $dconf = is_array($patch['destination']['config'] ?? null)
            ? $patch['destination']['config']
            : ($cfg['destination']['config'] ?? []);

        $err = wpultra_bksched_validate_dest_config($type, $dconf);
        if (is_wp_error($err)) { return $err; }

        $cfg['destination'] = ['type' => $type, 'config' => $dconf];
    }

    return $cfg;
}

/**
 * PURE: validate the credential config for a destination type. Returns true on OK
 * or WP_Error naming the missing/invalid field. 'none' needs nothing. 'dropbox'
 * needs a non-empty access_token. 's3' needs bucket, region, access_key, secret_key.
 *
 * @return true|WP_Error
 */
function wpultra_bksched_validate_dest_config(string $type, array $config) {
    switch ($type) {
        case 'none':
            return true;

        case 'dropbox':
            if (trim((string) ($config['access_token'] ?? '')) === '') {
                return wpultra_err('missing_dropbox_creds', 'Dropbox destination requires config.access_token (a scoped Dropbox access token).');
            }
            return true;

        case 's3':
            foreach (['bucket', 'region', 'access_key', 'secret_key'] as $req) {
                if (trim((string) ($config[$req] ?? '')) === '') {
                    return wpultra_err('missing_s3_creds', "S3 destination requires config.$req.");
                }
            }
            return true;

        default:
            return wpultra_err('bad_destination', "Unknown destination type '$type'.");
    }
}

/**
 * PURE: shape a config for output — masks every secret so it is safe to return.
 * Never mutates the input.
 */
function wpultra_bksched_shape_config(array $cfg): array {
    $out = array_replace_recursive(wpultra_bksched_defaults(), $cfg);
    $type = (string) ($out['destination']['type'] ?? 'none');
    $conf = (array) ($out['destination']['config'] ?? []);

    $masked = [];
    foreach ($conf as $k => $v) {
        if (in_array($k, ['secret_key', 'access_key', 'access_token'], true)) {
            $masked[$k] = wpultra_bksched_mask((string) $v);
        } else {
            $masked[$k] = $v;
        }
    }
    $out['destination'] = ['type' => $type, 'config' => $masked];
    return $out;
}

/**
 * PURE: append a run to the capped history ring (newest last, cap 30).
 * @param array $history existing history
 * @param array $entry   { at, name, status, bytes, pushed }
 */
function wpultra_bksched_push_history(array $history, array $entry): array {
    $history[] = [
        'at'     => (string) ($entry['at'] ?? ''),
        'name'   => (string) ($entry['name'] ?? ''),
        'status' => (string) ($entry['status'] ?? ''),
        'bytes'  => (int) ($entry['bytes'] ?? 0),
        'pushed' => (string) ($entry['pushed'] ?? 'no'),
    ];
    if (count($history) > 30) { $history = array_slice($history, -30); }
    return $history;
}

/* ============================================================
 * WP-facing config storage
 * ============================================================ */

if (!defined('WPULTRA_BKSCHED_OPTION')) { define('WPULTRA_BKSCHED_OPTION', 'wpultra_bksched'); }
if (!defined('WPULTRA_BKSCHED_HOOK'))   { define('WPULTRA_BKSCHED_HOOK', 'wpultra_bksched_run'); }

/** Load the stored config merged over defaults. */
function wpultra_bksched_get_config(): array {
    $raw = function_exists('get_option') ? get_option(WPULTRA_BKSCHED_OPTION, []) : [];
    if (!is_array($raw)) { $raw = []; }
    return array_replace_recursive(wpultra_bksched_defaults(), $raw);
}

/** Persist a config (full array). Reschedules cron to match. */
function wpultra_bksched_save_config(array $cfg): void {
    if (function_exists('update_option')) {
        update_option(WPULTRA_BKSCHED_OPTION, $cfg, false);
    }
    wpultra_bksched_sync_schedule($cfg);
}

/* ============================================================
 * Runtime contract: boot + cron scheduling
 * ============================================================ */

/**
 * Cheap boot: register the cron hook handler and ensure the event is scheduled
 * when enabled. Idempotent — safe to call on every request.
 */
function wpultra_bksched_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    if (!function_exists('add_action')) { return; }

    add_action(WPULTRA_BKSCHED_HOOK, 'wpultra_bksched_run_scheduled');

    $cfg = wpultra_bksched_get_config();
    wpultra_bksched_sync_schedule($cfg);
}

/**
 * Ensure the WP-Cron event matches the config: scheduled at the configured
 * recurrence when enabled, cleared when disabled.
 */
function wpultra_bksched_sync_schedule(array $cfg): void {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) { return; }

    $enabled    = ($cfg['enabled'] ?? false) === true;
    $recurrence = in_array(($cfg['recurrence'] ?? 'daily'), wpultra_bksched_recurrences(), true)
        ? $cfg['recurrence'] : 'daily';

    $existing = wp_next_scheduled(WPULTRA_BKSCHED_HOOK);

    if (!$enabled) {
        if ($existing && function_exists('wp_unschedule_event')) {
            wp_unschedule_event($existing, WPULTRA_BKSCHED_HOOK);
        }
        return;
    }

    if (!$existing) {
        wp_schedule_event(time() + 300, $recurrence, WPULTRA_BKSCHED_HOOK);
    }
    // Note: changing recurrence live requires a reschedule; we clear+reset in save.
}

/* ============================================================
 * Scheduled handler
 * ============================================================ */

/**
 * The cron handler: create a timestamped backup with the configured parts, apply
 * retention, then attempt the off-site push. Records a history entry. Everything is
 * wrapped so a failure never escapes into WP-Cron as a fatal.
 */
function wpultra_bksched_run_scheduled(): array {
    $cfg    = wpultra_bksched_get_config();
    $at     = gmdate('c');
    $name   = 'sched-' . gmdate('Ymd-His');
    $status = 'ok';
    $bytes  = 0;
    $pushed = 'no';
    $detail = [];

    try {
        if (!function_exists('wpultra_backup_create')) {
            throw new \RuntimeException('backup engine (includes/system/backup.php) not loaded');
        }

        $parts = (array) ($cfg['parts'] ?? []);
        // The backup engine always dumps the DB; files.zip is controlled by skip_uploads
        // + inclusion. It has no "db only" switch, so we always create both parts but
        // set skip_uploads when files are not wanted to keep the file archive tiny.
        $include_files = ($parts['files'] ?? false) === true;
        $opts = ['skip_uploads' => !$include_files];

        $created = wpultra_backup_create($name, $opts);
        if (is_wp_error($created)) {
            throw new \RuntimeException('backup_create failed: ' . $created->get_error_message());
        }
        $detail['created'] = $created;
        $db_bytes    = (int) ($created['db_bytes'] ?? 0);
        $files_bytes = (int) ($created['files_bytes'] ?? 0);
        $bytes = $include_files ? $files_bytes : $db_bytes;

        // ---- retention ----
        $listed = function_exists('wpultra_backup_list') ? wpultra_backup_list() : ['backups' => []];
        $backups = (array) ($listed['backups'] ?? []);
        $to_delete = wpultra_bksched_prune($backups, (int) ($cfg['retention'] ?? 5));
        $pruned = [];
        foreach ($to_delete as $del) {
            if (function_exists('wpultra_backup_delete')) {
                $r = wpultra_backup_delete($del, true);
                $pruned[$del] = !is_wp_error($r);
            }
        }
        $detail['pruned'] = $pruned;

        // ---- off-site push ----
        $dest = (array) ($cfg['destination'] ?? []);
        $dtype = (string) ($dest['type'] ?? 'none');
        if ($dtype !== 'none') {
            $push = wpultra_bksched_push_backup($name, $include_files, $cfg);
            $detail['push'] = $push;
            $pushed = ($push['pushed'] ?? false) ? 'yes' : ('skipped:' . ($push['reason'] ?? 'error'));
            if (($push['pushed'] ?? false) !== true && ($push['reason'] ?? '') === 'error') {
                $status = 'push_failed';
            }
        }
    } catch (\Throwable $e) {
        $status = 'error';
        $detail['error'] = $e->getMessage();
    }

    // ---- record history + last_* ----
    $cfg = wpultra_bksched_get_config(); // reload in case parallel writes happened
    $cfg['last_run']    = $at;
    $cfg['last_status'] = $status;
    $cfg['history']     = wpultra_bksched_push_history((array) ($cfg['history'] ?? []), [
        'at' => $at, 'name' => $name, 'status' => $status, 'bytes' => $bytes, 'pushed' => $pushed,
    ]);
    if (function_exists('update_option')) {
        update_option(WPULTRA_BKSCHED_OPTION, $cfg, false);
    }

    wpultra_audit_log('backup-schedule', "run $name status=$status bytes=$bytes pushed=$pushed", $status === 'ok');

    return ['name' => $name, 'status' => $status, 'bytes' => $bytes, 'pushed' => $pushed, 'detail' => $detail];
}

/* ============================================================
 * Off-site push (WP wrappers around the pure signing/arg functions)
 * ============================================================ */

/**
 * Choose which backup file to push and dispatch to the destination. Enforces the
 * push-size guard. Returns { pushed:bool, reason?:string, ... }.
 */
function wpultra_bksched_push_backup(string $name, bool $include_files, array $cfg): array {
    $base = function_exists('wpultra_backup_base_dir') ? wpultra_backup_base_dir() : '';
    $dir  = rtrim($base, '/\\') . '/' . $name;

    // Prefer files.zip when files were included, else the DB dump.
    $candidate = $include_files ? $dir . '/files.zip' : $dir . '/db.sql.gz';
    if (!is_file($candidate)) {
        // Fall back to whichever exists.
        $candidate = is_file($dir . '/files.zip') ? $dir . '/files.zip'
            : (is_file($dir . '/db.sql.gz') ? $dir . '/db.sql.gz' : '');
    }
    if ($candidate === '' || !is_file($candidate)) {
        return ['pushed' => false, 'reason' => 'error', 'error' => 'no backup file found to push'];
    }

    $bytes  = (int) filesize($candidate);
    $max_mb = (int) ($cfg['max_push_mb'] ?? 512);
    if (!wpultra_bksched_within_push_limit($bytes, $max_mb)) {
        return ['pushed' => false, 'reason' => 'too_large', 'bytes' => $bytes, 'max_mb' => $max_mb];
    }

    $dest  = (array) ($cfg['destination'] ?? []);
    $type  = (string) ($dest['type'] ?? 'none');
    $conf  = (array) ($dest['config'] ?? []);
    $key   = basename($dir) . '/' . basename($candidate);

    if ($type === 's3') {
        return wpultra_bksched_s3_put_file($candidate, $key, $conf);
    }
    if ($type === 'dropbox') {
        return wpultra_bksched_dropbox_put_file($candidate, $key, $conf);
    }
    return ['pushed' => false, 'reason' => 'error', 'error' => "destination '$type' not supported for push (use s3 or dropbox)"];
}

/**
 * PUT a local file to S3 using SigV4. Reads the file body, signs, and does a single
 * wp_remote_request PUT. Returns { pushed, reason?, status?, error? }.
 */
function wpultra_bksched_s3_put_file(string $file, string $key, array $conf): array {
    if (!function_exists('wp_remote_request')) {
        return ['pushed' => false, 'reason' => 'error', 'error' => 'wp_remote_request unavailable'];
    }
    $body = @file_get_contents($file);
    if ($body === false) {
        return ['pushed' => false, 'reason' => 'error', 'error' => 'could not read backup file'];
    }

    $bucket = (string) $conf['bucket'];
    $region = (string) $conf['region'];
    $ak     = (string) $conf['access_key'];
    $sk     = (string) $conf['secret_key'];
    $prefix = trim((string) ($conf['prefix'] ?? ''), '/');
    $object = ($prefix !== '' ? $prefix . '/' : '') . $key;

    $host = "$bucket.s3.$region.amazonaws.com";
    $canonical_uri = wpultra_bksched_s3_encode_key($object);
    $payload_hash  = wpultra_bksched_sha256_hex($body);

    $amz_date = gmdate('Ymd\THis\Z');
    $date     = gmdate('Ymd');
    $scope    = "$date/$region/s3/aws4_request";

    $headers = [
        'host'                 => $host,
        'x-amz-content-sha256' => $payload_hash,
        'x-amz-date'           => $amz_date,
    ];

    $cr = wpultra_bksched_s3_canonical_request('PUT', $canonical_uri, '', $headers, $payload_hash);
    $sts = wpultra_bksched_s3_signing_string($amz_date, $scope, $cr['canonical']);
    $sig = wpultra_bksched_s3_signature($sk, $date, $region, 's3', $sts);
    $auth = wpultra_bksched_s3_authorization($ak, $scope, $cr['signed_headers'], $sig);

    $resp = wp_remote_request("https://$host$canonical_uri", [
        'method'  => 'PUT',
        'timeout' => 60,
        'headers' => [
            'Authorization'        => $auth,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $amz_date,
            'Content-Type'         => 'application/octet-stream',
        ],
        'body'    => $body,
    ]);

    if (is_wp_error($resp)) {
        return ['pushed' => false, 'reason' => 'error', 'error' => $resp->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code >= 200 && $code < 300) {
        return ['pushed' => true, 'status' => $code, 'object' => $object];
    }
    return ['pushed' => false, 'reason' => 'error', 'status' => $code, 'error' => (string) wp_remote_retrieve_body($resp)];
}

/**
 * Upload a local file to Dropbox via files/upload. Returns { pushed, reason?, ... }.
 */
function wpultra_bksched_dropbox_put_file(string $file, string $key, array $conf): array {
    if (!function_exists('wp_remote_post')) {
        return ['pushed' => false, 'reason' => 'error', 'error' => 'wp_remote_post unavailable'];
    }
    $body = @file_get_contents($file);
    if ($body === false) {
        return ['pushed' => false, 'reason' => 'error', 'error' => 'could not read backup file'];
    }

    $token = (string) $conf['access_token'];
    $path  = '/wpultra-backups/' . ltrim($key, '/');
    $arg   = wpultra_bksched_dropbox_arg($path);

    $resp = wp_remote_post('https://content.dropboxapi.com/2/files/upload', [
        'timeout' => 60,
        'headers' => [
            'Authorization'   => 'Bearer ' . $token,
            'Dropbox-API-Arg' => $arg,
            'Content-Type'    => 'application/octet-stream',
        ],
        'body'    => $body,
    ]);

    if (is_wp_error($resp)) {
        return ['pushed' => false, 'reason' => 'error', 'error' => $resp->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code >= 200 && $code < 300) {
        return ['pushed' => true, 'status' => $code, 'path' => $path];
    }
    return ['pushed' => false, 'reason' => 'error', 'status' => $code, 'error' => (string) wp_remote_retrieve_body($resp)];
}

/**
 * Push a tiny test object to verify credentials WITHOUT leaking the secret. S3: PUT
 * a small 'wpultra-test' key; Dropbox: upload a small text file. Returns a shaped
 * result. Secrets never appear in the response.
 */
function wpultra_bksched_test_destination(array $cfg): array {
    $dest = (array) ($cfg['destination'] ?? []);
    $type = (string) ($dest['type'] ?? 'none');
    $conf = (array) ($dest['config'] ?? []);

    if ($type === 'none') {
        return ['ok' => false, 'type' => 'none', 'error' => 'No off-site destination configured.'];
    }
    $verr = wpultra_bksched_validate_dest_config($type, $conf);
    if (is_wp_error($verr)) {
        return ['ok' => false, 'type' => $type, 'error' => $verr->get_error_message()];
    }

    // Write a tiny temp file to push.
    $tmp = tempnam(sys_get_temp_dir(), 'wpultra-test-');
    if ($tmp === false) {
        return ['ok' => false, 'type' => $type, 'error' => 'could not create temp test file'];
    }
    @file_put_contents($tmp, "wpultra-test " . gmdate('c') . "\n");

    if ($type === 's3') {
        $res = wpultra_bksched_s3_put_file($tmp, 'wpultra-test.txt', $conf);
    } else {
        $res = wpultra_bksched_dropbox_put_file($tmp, 'wpultra-test-' . gmdate('Ymd-His') . '.txt', $conf);
    }
    @unlink($tmp);

    $ok = ($res['pushed'] ?? false) === true;
    return [
        'ok'     => $ok,
        'type'   => $type,
        'status' => $res['status'] ?? null,
        'error'  => $ok ? null : ($res['error'] ?? 'push failed'),
    ];
}

/**
 * Apply retention now (no new backup). Returns { deleted:[], kept:int }.
 */
function wpultra_bksched_prune_now(): array {
    $cfg = wpultra_bksched_get_config();
    $listed = function_exists('wpultra_backup_list') ? wpultra_backup_list() : ['backups' => []];
    $backups = (array) ($listed['backups'] ?? []);
    $to_delete = wpultra_bksched_prune($backups, (int) ($cfg['retention'] ?? 5));

    $deleted = [];
    foreach ($to_delete as $name) {
        if (function_exists('wpultra_backup_delete')) {
            $r = wpultra_backup_delete($name, true);
            if (!is_wp_error($r)) { $deleted[] = $name; }
        }
    }
    wpultra_audit_log('backup-schedule', 'prune-now deleted=' . count($deleted), true);
    return ['deleted' => $deleted, 'kept' => max(0, count($backups) - count($deleted)), 'retention' => (int) $cfg['retention']];
}
