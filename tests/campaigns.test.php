<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_campaigns/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/marketing/campaigns.php';

/* ============================================================
 * clean_emails — validate / lowercase / dedupe / reindex.
 * ============================================================ */

it('clean_emails lowercases, trims, dedupes and reindexes', function () {
    $in = ['  Alice@Example.COM ', 'bob@example.com', 'alice@example.com', 'bob@example.com'];
    assert_eq(['alice@example.com', 'bob@example.com'], wpultra_campaign_clean_emails($in));
});

it('clean_emails drops non-email garbage and non-strings', function () {
    $in = ['not-an-email', '', 'a@b', 'has space@x.com', 'x@y.co', null, 42, ['nested'], 'multi@dots.example.org'];
    assert_eq(['x@y.co', 'multi@dots.example.org'], wpultra_campaign_clean_emails($in));
});

it('clean_emails returns [] for an empty or all-invalid list', function () {
    assert_eq([], wpultra_campaign_clean_emails([]));
    assert_eq([], wpultra_campaign_clean_emails(['nope', '@@', 'a@']));
});

/* ============================================================
 * next_batch — queue slicing.
 * ============================================================ */

it('next_batch returns the slice at the cursor, reindexed', function () {
    $q = ['a@x.co', 'b@x.co', 'c@x.co', 'd@x.co', 'e@x.co'];
    assert_eq(['a@x.co', 'b@x.co'], wpultra_campaign_next_batch($q, 0, 2));
    assert_eq(['c@x.co', 'd@x.co'], wpultra_campaign_next_batch($q, 2, 2));
    assert_eq(['e@x.co'], wpultra_campaign_next_batch($q, 4, 2), 'final short batch');
});

it('next_batch handles cursor past the end and defensive args', function () {
    $q = ['a@x.co', 'b@x.co'];
    assert_eq([], wpultra_campaign_next_batch($q, 2, 20), 'cursor at end');
    assert_eq([], wpultra_campaign_next_batch($q, 99, 20), 'cursor past end');
    assert_eq(['a@x.co', 'b@x.co'], wpultra_campaign_next_batch($q, -5, 20), 'negative cursor clamps to 0');
    assert_eq(['a@x.co'], wpultra_campaign_next_batch($q, 0, 0), 'batch_size < 1 clamps to 1');
});

/* ============================================================
 * progress — counts + pct.
 * ============================================================ */

it('progress reports totals, remaining and cursor-based pct', function () {
    $meta = ['queue' => ['a@x.co', 'b@x.co', 'c@x.co', 'd@x.co'], 'cursor' => 3, 'sent_count' => 2, 'fail_count' => 1];
    assert_eq(
        ['total' => 4, 'sent' => 2, 'failed' => 1, 'remaining' => 1, 'pct' => 75],
        wpultra_campaign_progress($meta)
    );
});

it('progress on an empty queue is all-zero with pct 0 (no div-by-zero)', function () {
    assert_eq(
        ['total' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0, 'pct' => 0],
        wpultra_campaign_progress([])
    );
});

it('progress caps pct at 100 even if the cursor overshoots', function () {
    $p = wpultra_campaign_progress(['queue' => ['a@x.co'], 'cursor' => 5, 'sent_count' => 1, 'fail_count' => 0]);
    assert_eq(100, $p['pct']);
    assert_eq(0, $p['remaining']);
});

/* ============================================================
 * validate_input — presence, shape, clamps, injected $now.
 * ============================================================ */

const T_NOW = 1_800_000_000;

function valid_base(): array {
    return [
        'name'       => 'July promo',
        'subject'    => 'July deals',
        'body_html'  => '<h1>Hi</h1>',
        'recipients' => ['source' => 'emails', 'emails' => ['a@x.co']],
    ];
}

it('validate_input accepts a complete valid payload', function () {
    assert_eq(true, wpultra_campaign_validate_input(valid_base(), T_NOW));
});

it('validate_input requires name, subject and body_html', function () {
    foreach (['name', 'subject', 'body_html'] as $field) {
        $in = valid_base();
        $in[$field] = '   ';
        $res = wpultra_campaign_validate_input($in, T_NOW);
        assert_true(is_string($res), "$field missing yields error string");
        assert_contains($field, $res);
    }
});

it('validate_input rejects a missing or malformed recipients spec', function () {
    $in = valid_base();
    unset($in['recipients']);
    assert_true(is_string(wpultra_campaign_validate_input($in, T_NOW)), 'missing recipients');

    $in = valid_base();
    $in['recipients'] = ['source' => 'pigeons'];
    $res = wpultra_campaign_validate_input($in, T_NOW);
    assert_true(is_string($res), 'unknown source');
    assert_contains('users, emails, newsletter', $res);
});

it('validate_input rejects an emails source with no valid addresses', function () {
    $in = valid_base();
    $in['recipients'] = ['source' => 'emails', 'emails' => ['bogus', '']];
    $res = wpultra_campaign_validate_input($in, T_NOW);
    assert_true(is_string($res));
    assert_contains('valid email', $res);
});

it('validate_input accepts users and newsletter sources without an email list', function () {
    $in = valid_base();
    $in['recipients'] = ['source' => 'users', 'role' => 'subscriber'];
    assert_eq(true, wpultra_campaign_validate_input($in, T_NOW));
    $in['recipients'] = ['source' => 'newsletter'];
    assert_eq(true, wpultra_campaign_validate_input($in, T_NOW));
});

it('validate_input enforces the batch_size 1..100 window', function () {
    foreach ([0, 101, -3] as $bad) {
        $in = valid_base();
        $in['batch_size'] = $bad;
        $res = wpultra_campaign_validate_input($in, T_NOW);
        assert_true(is_string($res), "batch_size $bad rejected");
        assert_contains('between 1 and 100', $res);
    }
    $in = valid_base();
    $in['batch_size'] = 100;
    assert_eq(true, wpultra_campaign_validate_input($in, T_NOW));
    $in['batch_size'] = 'many';
    assert_true(is_string(wpultra_campaign_validate_input($in, T_NOW)), 'non-numeric batch_size rejected');
});

it('validate_input checks send_at against the injected $now', function () {
    $in = valid_base();
    $in['send_at'] = T_NOW + 60;
    assert_eq(true, wpultra_campaign_validate_input($in, T_NOW), 'future ok');
    $in['send_at'] = T_NOW;
    assert_true(is_string(wpultra_campaign_validate_input($in, T_NOW)), 'now rejected');
    $in['send_at'] = T_NOW - 1;
    $res = wpultra_campaign_validate_input($in, T_NOW);
    assert_true(is_string($res), 'past rejected');
    assert_contains('future', $res);
});

/* ============================================================
 * clamp_batch_size.
 * ============================================================ */

it('clamp_batch_size clamps into 1..100 and defaults non-numerics', function () {
    assert_eq(1, wpultra_campaign_clamp_batch_size(0));
    assert_eq(1, wpultra_campaign_clamp_batch_size(-7));
    assert_eq(100, wpultra_campaign_clamp_batch_size(500));
    assert_eq(50, wpultra_campaign_clamp_batch_size(50));
    assert_eq(20, wpultra_campaign_clamp_batch_size('abc'));
    assert_eq(20, wpultra_campaign_clamp_batch_size(null));
    assert_eq(7, wpultra_campaign_clamp_batch_size('7'));
});

/* ============================================================
 * parse_send_at — unix passthrough + site-local string.
 * ============================================================ */

it('parse_send_at passes unix timestamps through (int and numeric string)', function () {
    assert_eq(1_800_000_000, wpultra_campaign_parse_send_at(1_800_000_000));
    assert_eq(1_800_000_000, wpultra_campaign_parse_send_at('1800000000'));
});

it('parse_send_at converts a Y-m-d H:i site-local string using the injected offset', function () {
    // 2026-07-04 09:00 at UTC+0 == 1783328400? Compute expected via gmmktime for safety.
    $expected_utc = gmmktime(9, 0, 0, 7, 4, 2026);
    assert_eq($expected_utc, wpultra_campaign_parse_send_at('2026-07-04 09:00', 0), 'offset 0');
    // Site at UTC+6 (Dhaka): local 09:00 is 03:00 UTC → expected minus 6h.
    assert_eq($expected_utc - 6 * 3600, wpultra_campaign_parse_send_at('2026-07-04 09:00', 6 * 3600), 'offset +6h');
    // Seconds variant + T separator.
    assert_eq($expected_utc + 30, wpultra_campaign_parse_send_at('2026-07-04T09:00:30', 0));
});

it('parse_send_at returns false for garbage', function () {
    assert_eq(false, wpultra_campaign_parse_send_at('tomorrow morning'));
    assert_eq(false, wpultra_campaign_parse_send_at(''));
    assert_eq(false, wpultra_campaign_parse_send_at(null));
    assert_eq(false, wpultra_campaign_parse_send_at('2026-07-04'), 'date without time rejected');
});

/* ============================================================
 * shape — safe output (no queue dump).
 * ============================================================ */

it('shape returns counts + first-3 previews, never the full queue', function () {
    $meta = array_merge(wpultra_campaign_default_meta(), [
        'subject'         => 'Hello',
        'status'          => 'sending',
        'batch_size'      => 10,
        'recipients_spec' => ['source' => 'emails', 'emails' => ['a@x.co', 'b@x.co', 'c@x.co', 'd@x.co', 'e@x.co']],
        'queue'           => ['a@x.co', 'b@x.co', 'c@x.co', 'd@x.co', 'e@x.co'],
        'cursor'          => 2,
        'sent_count'      => 2,
        'fail_count'      => 0,
        'started_at'      => 1_800_000_000,
    ]);
    $s = wpultra_campaign_shape($meta, 12, 'July promo');

    assert_eq(12, $s['id']);
    assert_eq('July promo', $s['name']);
    assert_eq('Hello', $s['subject']);
    assert_eq('sending', $s['status']);
    assert_eq(10, $s['batch_size']);
    assert_eq(1_800_000_000, $s['started_at']);
    assert_true(!array_key_exists('queue', $s), 'full queue never dumped');
    assert_eq(['a@x.co', 'b@x.co', 'c@x.co'], $s['queue_preview'], 'first 3 only');
    assert_eq(5, $s['recipients_spec']['email_count']);
    assert_eq(['a@x.co', 'b@x.co', 'c@x.co'], $s['recipients_spec']['emails_preview']);
    assert_eq(['total' => 5, 'sent' => 2, 'failed' => 0, 'remaining' => 3, 'pct' => 40], $s['progress']);
});

it('shape of a fresh default meta is a sane draft', function () {
    $s = wpultra_campaign_shape(wpultra_campaign_default_meta(), 3, 'New');
    assert_eq('draft', $s['status']);
    assert_eq(20, $s['batch_size']);
    assert_eq(null, $s['scheduled_at']);
    assert_eq(null, $s['finished_at']);
    assert_eq('', $s['last_error']);
    assert_eq([], $s['queue_preview']);
    assert_eq(0, $s['progress']['total']);
});

it('shape includes the users role in recipients_spec without inventing email fields', function () {
    $meta = array_merge(wpultra_campaign_default_meta(), [
        'recipients_spec' => ['source' => 'users', 'role' => 'subscriber'],
    ]);
    $s = wpultra_campaign_shape($meta);
    assert_eq(['source' => 'users', 'role' => 'subscriber'], $s['recipients_spec']);
});

/* ============================================================
 * misc pure helpers + engine error factory.
 * ============================================================ */

it('status_options and source_options list the documented sets', function () {
    assert_eq(['draft', 'scheduled', 'sending', 'sent', 'cancelled'], wpultra_campaign_status_options());
    assert_eq(['users', 'emails', 'newsletter'], wpultra_campaign_source_options());
});

it('default_meta carries every documented key', function () {
    $meta = wpultra_campaign_default_meta();
    foreach (['subject', 'status', 'recipients_spec', 'queue', 'cursor', 'sent_count', 'fail_count', 'batch_size', 'scheduled_at', 'started_at', 'finished_at', 'last_error'] as $k) {
        assert_true(array_key_exists($k, $meta), "default meta has $k");
    }
    assert_eq('draft', $meta['status']);
    assert_eq(20, $meta['batch_size']);
});

it('campaign_err yields a WP_Error with the given code', function () {
    $e = wpultra_campaign_err('empty_queue', 'Nothing to send.');
    assert_wp_error($e);
    assert_eq('empty_queue', $e->get_error_code());
    assert_eq('Nothing to send.', $e->get_error_message());
});

run_tests();
