<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_bksched/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/system/backup-schedule.php';

/* ============================================================
 * prune — keep newest N, return the rest.
 * ============================================================ */

it('prune keeps the N newest by modified date, returns the older ones to delete', function () {
    $backups = [
        ['name' => 'a', 'modified' => '2026-01-01T00:00:00+00:00'],
        ['name' => 'b', 'modified' => '2026-01-05T00:00:00+00:00'],
        ['name' => 'c', 'modified' => '2026-01-03T00:00:00+00:00'],
        ['name' => 'd', 'modified' => '2026-01-04T00:00:00+00:00'],
    ];
    // keep 2 newest => b (Jan5), d (Jan4); delete c (Jan3), a (Jan1).
    $del = wpultra_bksched_prune($backups, 2);
    assert_eq(['c', 'a'], $del);
});

it('prune returns [] when keep >= count', function () {
    $backups = [
        ['name' => 'a', 'modified' => '2026-01-01T00:00:00+00:00'],
        ['name' => 'b', 'modified' => '2026-01-02T00:00:00+00:00'],
    ];
    assert_eq([], wpultra_bksched_prune($backups, 2));
    assert_eq([], wpultra_bksched_prune($backups, 5));
});

it('prune with keep 0 deletes everything (newest-first order)', function () {
    $backups = [
        ['name' => 'old', 'modified' => '2026-01-01T00:00:00+00:00'],
        ['name' => 'new', 'modified' => '2026-01-09T00:00:00+00:00'],
    ];
    assert_eq(['new', 'old'], wpultra_bksched_prune($backups, 0));
});

it('prune treats a negative keep as 0', function () {
    $backups = [['name' => 'x', 'modified' => '2026-01-01T00:00:00+00:00']];
    assert_eq(['x'], wpultra_bksched_prune($backups, -3));
});

it('prune sorts rows with no/unparseable date as oldest', function () {
    $backups = [
        ['name' => 'dated', 'modified' => '2026-01-01T00:00:00+00:00'],
        ['name' => 'undated'], // no modified => epoch 0 => oldest
        ['name' => 'garbage', 'modified' => 'not-a-date'],
    ];
    // keep 1 => the dated one survives; the two undated sort oldest and are deleted.
    $del = wpultra_bksched_prune($backups, 1);
    assert_eq(2, count($del));
    assert_true(in_array('undated', $del, true) && in_array('garbage', $del, true), 'undated + garbage deleted');
    assert_true(!in_array('dated', $del, true), 'dated survives');
});

it('prune accepts an integer modified epoch', function () {
    $backups = [
        ['name' => 'a', 'modified' => 100],
        ['name' => 'b', 'modified' => 500],
        ['name' => 'c', 'modified' => 300],
    ];
    assert_eq(['c', 'a'], wpultra_bksched_prune($backups, 1)); // keep b (500)
});

it('prune skips rows with an empty name', function () {
    $backups = [
        ['name' => '', 'modified' => '2026-01-09T00:00:00+00:00'],
        ['name' => 'real', 'modified' => '2026-01-01T00:00:00+00:00'],
    ];
    assert_eq([], wpultra_bksched_prune($backups, 1));
});

/* ============================================================
 * within_push_limit — boundary.
 * ============================================================ */

it('within_push_limit is inclusive at the cap', function () {
    $mb = 1024 * 1024;
    assert_true(wpultra_bksched_within_push_limit(1 * $mb, 1), 'exactly 1MB with 1MB cap passes');
    assert_true(!wpultra_bksched_within_push_limit(1 * $mb + 1, 1), 'one byte over fails');
    assert_true(wpultra_bksched_within_push_limit(0, 1), 'empty file passes');
});

it('within_push_limit treats 0 or negative cap as unlimited', function () {
    assert_true(wpultra_bksched_within_push_limit(999999999, 0), '0 cap = unlimited');
    assert_true(wpultra_bksched_within_push_limit(999999999, -5), 'negative cap = unlimited');
});

/* ============================================================
 * mask — short fully masked, long first2/last2.
 * ============================================================ */

it('mask fully masks empty and short secrets', function () {
    assert_eq('', wpultra_bksched_mask(''));
    assert_eq('••••••', wpultra_bksched_mask('abc'));
    assert_eq('••••••', wpultra_bksched_mask('123456')); // exactly 6 => still fully masked
});

it('mask keeps first2 + last2 for a long secret and never leaks the middle', function () {
    $secret = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    $masked = wpultra_bksched_mask($secret);
    assert_eq('wJ••••EY', $masked);
    assert_true(!str_contains($masked, 'MDENG'), 'middle not leaked');
    // Fixed-width dot run — length is not leaked.
    assert_eq('AK••••YZ', wpultra_bksched_mask('AKIAABCDEFGHIJKLMNYZ'));
});

/* ============================================================
 * dropbox_arg — JSON shape.
 * ============================================================ */

it('dropbox_arg builds add/autorename JSON with a leading-slash path', function () {
    $json = wpultra_bksched_dropbox_arg('backups/site1/db.sql.gz');
    $arg = json_decode($json, true);
    assert_eq('/backups/site1/db.sql.gz', $arg['path']);
    assert_eq('add', $arg['mode']);
    assert_eq(true, $arg['autorename']);
    assert_eq(false, $arg['mute']);
    // Slashes must not be escaped (JSON_UNESCAPED_SLASHES) so headers stay clean.
    assert_true(!str_contains($json, '\\/'), 'slashes not escaped');
});

it('dropbox_arg does not double the leading slash', function () {
    $arg = json_decode(wpultra_bksched_dropbox_arg('/already/absolute.zip'), true);
    assert_eq('/already/absolute.zip', $arg['path']);
});

/* ============================================================
 * AWS Signature V4 — hand-verified against the AWS test suite.
 * ============================================================ */

// AWS SigV4 test-suite "get-vanilla":
//   GET /  host:example.amazonaws.com  x-amz-date:20150830T123600Z
//   secret wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY  region us-east-1  service service
// Published expected values from AWS docs.
$AWS_HEADERS  = ['host' => 'example.amazonaws.com', 'x-amz-date' => '20150830T123600Z'];
$AWS_PAYLOAD  = hash('sha256', '');
$AWS_SCOPE    = '20150830/us-east-1/service/aws4_request';
$AWS_SECRET   = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

it('s3 canonical request hashes to the AWS get-vanilla test vector', function () use ($AWS_HEADERS, $AWS_PAYLOAD) {
    $cr = wpultra_bksched_s3_canonical_request('GET', '/', '', $AWS_HEADERS, $AWS_PAYLOAD);
    assert_eq('host;x-amz-date', $cr['signed_headers']);
    assert_eq(
        'bb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63',
        hash('sha256', $cr['canonical']),
        'canonical request hash matches AWS get-vanilla'
    );
});

it('s3 signing key matches the AWS documented derive-key vector', function () {
    // AWS "Examples of how to derive a signing key" doc vector.
    $key = wpultra_bksched_s3_signing_key('wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', '20150830', 'us-east-1', 'iam');
    assert_eq('c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9', bin2hex($key));
});

it('s3 full signature matches the AWS get-vanilla test vector', function () use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE, $AWS_SECRET) {
    $cr  = wpultra_bksched_s3_canonical_request('GET', '/', '', $AWS_HEADERS, $AWS_PAYLOAD);
    $sts = wpultra_bksched_s3_signing_string('20150830T123600Z', $AWS_SCOPE, $cr['canonical']);
    $sig = wpultra_bksched_s3_signature($AWS_SECRET, '20150830', 'us-east-1', 'service', $sts);
    assert_eq('5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $sig);
});

it('s3 signing string has the fixed 4-line AWS4-HMAC-SHA256 shape', function () use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE) {
    $cr  = wpultra_bksched_s3_canonical_request('PUT', '/k', '', $AWS_HEADERS, $AWS_PAYLOAD);
    $sts = wpultra_bksched_s3_signing_string('20150830T123600Z', $AWS_SCOPE, $cr['canonical']);
    $lines = explode("\n", $sts);
    assert_eq(4, count($lines));
    assert_eq('AWS4-HMAC-SHA256', $lines[0]);
    assert_eq('20150830T123600Z', $lines[1]);
    assert_eq($AWS_SCOPE, $lines[2]);
    assert_eq(hash('sha256', $cr['canonical']), $lines[3]);
});

it('s3 signature is deterministic — same inputs, same output', function () use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE, $AWS_SECRET) {
    $mk = function () use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE, $AWS_SECRET) {
        $cr  = wpultra_bksched_s3_canonical_request('PUT', '/backups/x.zip', '', $AWS_HEADERS, $AWS_PAYLOAD);
        $sts = wpultra_bksched_s3_signing_string('20150830T123600Z', $AWS_SCOPE, $cr['canonical']);
        return wpultra_bksched_s3_signature($AWS_SECRET, '20150830', 'us-east-1', 's3', $sts);
    };
    assert_eq($mk(), $mk(), 'two identical runs match');
});

it('s3 signature changes when the method changes', function () use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE, $AWS_SECRET) {
    $sig = function (string $method) use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE, $AWS_SECRET) {
        $cr  = wpultra_bksched_s3_canonical_request($method, '/k', '', $AWS_HEADERS, $AWS_PAYLOAD);
        $sts = wpultra_bksched_s3_signing_string('20150830T123600Z', $AWS_SCOPE, $cr['canonical']);
        return wpultra_bksched_s3_signature($AWS_SECRET, '20150830', 'us-east-1', 's3', $sts);
    };
    assert_true($sig('PUT') !== $sig('GET'), 'method flips the signature');
});

it('s3 signature changes when the URI changes', function () use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE, $AWS_SECRET) {
    $sig = function (string $uri) use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE, $AWS_SECRET) {
        $cr  = wpultra_bksched_s3_canonical_request('PUT', $uri, '', $AWS_HEADERS, $AWS_PAYLOAD);
        $sts = wpultra_bksched_s3_signing_string('20150830T123600Z', $AWS_SCOPE, $cr['canonical']);
        return wpultra_bksched_s3_signature($AWS_SECRET, '20150830', 'us-east-1', 's3', $sts);
    };
    assert_true($sig('/a.zip') !== $sig('/b.zip'), 'uri flips the signature');
});

it('s3 signature changes when the payload hash changes', function () use ($AWS_HEADERS, $AWS_SCOPE, $AWS_SECRET) {
    $sig = function (string $payload) use ($AWS_HEADERS, $AWS_SCOPE, $AWS_SECRET) {
        $cr  = wpultra_bksched_s3_canonical_request('PUT', '/k', '', $AWS_HEADERS, $payload);
        $sts = wpultra_bksched_s3_signing_string('20150830T123600Z', $AWS_SCOPE, $cr['canonical']);
        return wpultra_bksched_s3_signature($AWS_SECRET, '20150830', 'us-east-1', 's3', $sts);
    };
    assert_true($sig(hash('sha256', 'a')) !== $sig(hash('sha256', 'b')), 'payload flips the signature');
});

it('s3 signature changes when the secret changes', function () use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE) {
    $mk = function (string $secret) use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SCOPE) {
        $cr  = wpultra_bksched_s3_canonical_request('PUT', '/k', '', $AWS_HEADERS, $AWS_PAYLOAD);
        $sts = wpultra_bksched_s3_signing_string('20150830T123600Z', $AWS_SCOPE, $cr['canonical']);
        return wpultra_bksched_s3_signature($secret, '20150830', 'us-east-1', 's3', $sts);
    };
    assert_true($mk('secretA') !== $mk('secretB'), 'secret flips the signature');
});

it('s3 signature changes when the region changes', function () use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SECRET) {
    $mk = function (string $region) use ($AWS_HEADERS, $AWS_PAYLOAD, $AWS_SECRET) {
        $scope = "20150830/$region/s3/aws4_request";
        $cr  = wpultra_bksched_s3_canonical_request('PUT', '/k', '', $AWS_HEADERS, $AWS_PAYLOAD);
        $sts = wpultra_bksched_s3_signing_string('20150830T123600Z', $scope, $cr['canonical']);
        return wpultra_bksched_s3_signature($AWS_SECRET, '20150830', $region, 's3', $sts);
    };
    assert_true($mk('us-east-1') !== $mk('eu-west-1'), 'region flips the signature');
});

it('s3 canonical request lowercases + sorts headers deterministically', function () use ($AWS_PAYLOAD) {
    $a = wpultra_bksched_s3_canonical_request('PUT', '/k', '', ['X-Amz-Date' => 'D', 'Host' => 'H'], $AWS_PAYLOAD);
    $b = wpultra_bksched_s3_canonical_request('PUT', '/k', '', ['host' => 'H', 'x-amz-date' => 'D'], $AWS_PAYLOAD);
    assert_eq($a['canonical'], $b['canonical'], 'header casing/order does not change the canonical request');
    assert_eq('host;x-amz-date', $a['signed_headers']);
});

it('s3 authorization header assembles Credential/SignedHeaders/Signature', function () {
    $auth = wpultra_bksched_s3_authorization('AKIDEXAMPLE', '20150830/us-east-1/s3/aws4_request', 'host;x-amz-date', 'deadbeef');
    assert_contains('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/s3/aws4_request', $auth);
    assert_contains('SignedHeaders=host;x-amz-date', $auth);
    assert_contains('Signature=deadbeef', $auth);
});

it('s3 encode_key encodes segments but preserves slashes + leading slash', function () {
    assert_eq('/sched-20260101/files.zip', wpultra_bksched_s3_encode_key('sched-20260101/files.zip'));
    assert_eq('/a%20b/c%2Bd.zip', wpultra_bksched_s3_encode_key('a b/c+d.zip'));
});

/* ============================================================
 * config validation matrix.
 * ============================================================ */

it('validate_config accepts a valid full patch and merges over defaults', function () {
    $cfg = wpultra_bksched_validate_config([], [
        'enabled'    => true,
        'recurrence' => 'weekly',
        'parts'      => ['db' => true, 'files' => true],
        'retention'  => 10,
        'destination'=> ['type' => 's3', 'config' => ['bucket' => 'b', 'region' => 'us-east-1', 'access_key' => 'AK', 'secret_key' => 'SK']],
    ]);
    assert_true(is_array($cfg), 'returns merged array');
    assert_eq(true, $cfg['enabled']);
    assert_eq('weekly', $cfg['recurrence']);
    assert_eq(true, $cfg['parts']['files']);
    assert_eq(10, $cfg['retention']);
    assert_eq('s3', $cfg['destination']['type']);
});

it('validate_config rejects a bad recurrence', function () {
    $r = wpultra_bksched_validate_config([], ['recurrence' => 'hourly']);
    assert_wp_error($r);
    assert_eq('bad_recurrence', $r->get_error_code());
});

it('validate_config rejects a non-integer retention', function () {
    $r = wpultra_bksched_validate_config([], ['retention' => 'lots']);
    assert_wp_error($r);
    assert_eq('bad_retention', $r->get_error_code());
});

it('validate_config rejects retention < 1', function () {
    $r = wpultra_bksched_validate_config([], ['retention' => 0]);
    assert_wp_error($r);
    assert_eq('bad_retention', $r->get_error_code());
});

it('validate_config accepts a numeric-string retention', function () {
    $cfg = wpultra_bksched_validate_config([], ['retention' => '7']);
    assert_true(is_array($cfg));
    assert_eq(7, $cfg['retention']);
});

it('validate_config rejects turning off both parts', function () {
    $r = wpultra_bksched_validate_config([], ['parts' => ['db' => false, 'files' => false]]);
    assert_wp_error($r);
    assert_eq('empty_parts', $r->get_error_code());
});

it('validate_config rejects s3 destination missing creds', function () {
    $r = wpultra_bksched_validate_config([], ['destination' => ['type' => 's3', 'config' => ['bucket' => 'b']]]);
    assert_wp_error($r);
    assert_eq('missing_s3_creds', $r->get_error_code());
});

it('validate_config rejects dropbox destination missing token', function () {
    $r = wpultra_bksched_validate_config([], ['destination' => ['type' => 'dropbox', 'config' => []]]);
    assert_wp_error($r);
    assert_eq('missing_dropbox_creds', $r->get_error_code());
});

it('validate_config rejects an unknown destination type', function () {
    $r = wpultra_bksched_validate_config([], ['destination' => ['type' => 'gdrive', 'config' => []]]);
    assert_wp_error($r);
    assert_eq('bad_destination', $r->get_error_code());
});

it('validate_config accepts destination none with no config', function () {
    $cfg = wpultra_bksched_validate_config([], ['destination' => ['type' => 'none']]);
    assert_true(is_array($cfg));
    assert_eq('none', $cfg['destination']['type']);
});

it('validate_config rejects a negative max_push_mb but accepts 0', function () {
    $bad = wpultra_bksched_validate_config([], ['max_push_mb' => -1]);
    assert_wp_error($bad);
    assert_eq('bad_max_push_mb', $bad->get_error_code());
    $ok = wpultra_bksched_validate_config([], ['max_push_mb' => 0]);
    assert_true(is_array($ok));
    assert_eq(0, $ok['max_push_mb']);
});

/* ============================================================
 * validate_dest_config direct matrix.
 * ============================================================ */

it('validate_dest_config passes valid s3 and dropbox creds, fails partial s3', function () {
    assert_true(wpultra_bksched_validate_dest_config('none', []) === true);
    assert_true(wpultra_bksched_validate_dest_config('dropbox', ['access_token' => 't']) === true);
    assert_true(wpultra_bksched_validate_dest_config('s3', ['bucket' => 'b', 'region' => 'r', 'access_key' => 'a', 'secret_key' => 's']) === true);
    $r = wpultra_bksched_validate_dest_config('s3', ['bucket' => 'b', 'region' => 'r', 'access_key' => 'a']);
    assert_wp_error($r);
    assert_eq('missing_s3_creds', $r->get_error_code());
});

/* ============================================================
 * shape_config — masks every secret, never mutates input.
 * ============================================================ */

it('shape_config masks s3 secret + access key and does not mutate the input', function () {
    $cfg = [
        'destination' => ['type' => 's3', 'config' => [
            'bucket' => 'my-bucket', 'region' => 'us-east-1',
            'access_key' => 'AKIAABCDEFGHIJKLMNYZ', 'secret_key' => 'wJalrXUtnFEMI/K7MDENGbPxRfiCYEXAMPLE',
        ]],
    ];
    $shaped = wpultra_bksched_shape_config($cfg);
    assert_eq('my-bucket', $shaped['destination']['config']['bucket'], 'non-secret preserved');
    assert_true(!str_contains($shaped['destination']['config']['secret_key'], 'MDENG'), 'secret masked');
    assert_eq('AK••••YZ', $shaped['destination']['config']['access_key']);
    // Original untouched.
    assert_eq('AKIAABCDEFGHIJKLMNYZ', $cfg['destination']['config']['access_key'], 'input not mutated');
});

it('shape_config masks the dropbox access token', function () {
    $cfg = ['destination' => ['type' => 'dropbox', 'config' => ['access_token' => 'sl.ABCDEFGHIJKLMNOPQRSTUVWXYZ']]];
    $shaped = wpultra_bksched_shape_config($cfg);
    assert_true(!str_contains($shaped['destination']['config']['access_token'], 'MNOP'), 'token masked');
    assert_eq('sl••••YZ', $shaped['destination']['config']['access_token']);
});

/* ============================================================
 * push_history — capped ring, shaped rows.
 * ============================================================ */

it('push_history appends a shaped row and caps at 30', function () {
    $hist = [];
    for ($i = 0; $i < 35; $i++) {
        $hist = wpultra_bksched_push_history($hist, ['at' => "t$i", 'name' => "n$i", 'status' => 'ok', 'bytes' => $i, 'pushed' => 'yes']);
    }
    assert_eq(30, count($hist), 'capped at 30');
    assert_eq('n34', $hist[29]['name'], 'newest kept last');
    assert_eq('n5', $hist[0]['name'], 'oldest surviving is n5');
    // Row shape.
    assert_eq('yes', $hist[29]['pushed']);
    assert_eq(34, $hist[29]['bytes']);
});

it('push_history coerces missing fields to safe defaults', function () {
    $hist = wpultra_bksched_push_history([], []);
    assert_eq('', $hist[0]['name']);
    assert_eq(0, $hist[0]['bytes']);
    assert_eq('', $hist[0]['status']);
});

/* ============================================================
 * defaults / enums sanity.
 * ============================================================ */

it('defaults have db-on files-off and a none destination', function () {
    $d = wpultra_bksched_defaults();
    assert_eq(true, $d['parts']['db']);
    assert_eq(false, $d['parts']['files']);
    assert_eq('none', $d['destination']['type']);
    assert_eq(5, $d['retention']);
    assert_eq('daily', $d['recurrence']);
});

it('enum helpers list the supported values', function () {
    assert_eq(['daily', 'weekly'], wpultra_bksched_recurrences());
    assert_eq(['none', 's3', 'dropbox'], wpultra_bksched_dest_types());
});

run_tests();
