<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine — the ai engine-file loader in bootstrap-mcp.php
// may not list visualdiff.php, so load it regardless of order (mirrors how
// woo-bulk-edit leans on its engine file).
if (!function_exists('wpultra_vdiff_fingerprint') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/ai/visualdiff.php')) {
    require_once WPULTRA_DIR . 'includes/ai/visualdiff.php';
}

wp_register_ability('wpultra/visual-diff', [
    'label'       => __('Visual Diff / Regression Guard', 'wp-ultra-mcp'),
    'description' => __(
        'Catch breakage around a change by comparing a URL BEFORE and AFTER. '
        . 'HONEST SCOPE: this plugin ships NO headless browser, so the "visual diff" here is a SERVER-SIDE rendered-HTML FINGERPRINT of a URL — it fetches the page and records: http status, byte size, visible-text length + hash, <title>, all H1/H2 headings, image count + first 100 <img> srcs, link/script/form counts, a DOM tag-skeleton hash (structural outline), and any rendered PHP-error markers (Fatal error / Warning: / stack traces / "critical error on this website" / DB-connection errors). '
        . 'It then compares two fingerprints structurally. This catches STRUCTURAL / CONTENT / ASSET regressions — a section vanished, a heading changed, images got dropped, a PHP error is now rendered on the page, the page 500s, a huge size swing — NOT pixel-perfect rendering. That is genuinely useful and honest. '
        . 'Severity: new error markers => CRITICAL; non-2xx status, missing title/h1, image count dropped >30%, or DOM skeleton changed => MAJOR; visible-text change / small size delta / H2 change => MINOR; identical => none. '
        . 'For a TRUE pixel diff, YOU (the calling AI, e.g. Claude Code, which can drive a browser) capture before/after screenshots and pass before_image_url / after_image_url — the server records them in the report and flags them for you to eyeball; the server does the structural diff, you do the visual eyeball. '
        . 'ACTIONS: '
        . 'baseline {url|urls, label?, before_image_url?} = capture the "before" fingerprint(s) and STORE them (up to 50 URLs). '
        . 'compare {url, after_image_url?} = snapshot the URL now and diff it against its stored baseline; errors if no baseline exists. '
        . 'guarded-change {urls} = convenience that captures baselines for several URLs and returns the recommended workflow token (a label) to use after your change. '
        . 'snapshot {url} = fingerprint the URL right now WITHOUT storing (inspection only). '
        . 'list = show stored baselines. clear {url?} = delete one or all baselines. '
        . 'WORKFLOW: (1) baseline the URLs you are about to affect, (2) make your change via other abilities, (3) compare the same URLs and review severity. '
        . 'Examples: {action:"baseline", urls:["https://site.test/","https://site.test/shop"], label:"before-theme-swap"} then after your edit {action:"compare", url:"https://site.test/"}. '
        . 'All actions are read-ish (snapshots fetch URLs over HTTP); only clear removes stored state.',
        'wp-ultra-mcp'
    ),
    'category'    => 'ai',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'           => ['type' => 'string', 'enum' => ['snapshot', 'baseline', 'compare', 'guarded-change', 'list', 'clear']],
            'url'              => ['type' => 'string'],
            'urls'             => ['type' => 'array', 'items' => ['type' => 'string']],
            'label'            => ['type' => 'string'],
            'before'           => ['type' => 'object', 'additionalProperties' => true],
            'after'            => ['type' => 'object', 'additionalProperties' => true],
            'before_image_url' => ['type' => 'string'],
            'after_image_url'  => ['type' => 'string'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'action'    => ['type' => 'string'],
            'result'    => ['type' => 'object'],
            'results'   => ['type' => 'array'],
            'baselines' => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_visual_diff_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_visual_diff_cb(array $input) {
    if (!function_exists('wpultra_vdiff_fingerprint')) {
        return wpultra_err('vdiff_engine_missing', 'The visual-diff engine (includes/ai/visualdiff.php) is not loaded.');
    }

    $action = strtolower(trim((string) ($input['action'] ?? '')));

    // Optional client-captured screenshot URLs — recorded for the AI to eyeball.
    $images = [];
    if (!empty($input['before_image_url'])) { $images['before_image_url'] = (string) $input['before_image_url']; }
    if (!empty($input['after_image_url']))  { $images['after_image_url']  = (string) $input['after_image_url']; }

    switch ($action) {
        case 'snapshot': {
            $url = trim((string) ($input['url'] ?? ''));
            if ($url === '') { return wpultra_err('missing_url', 'snapshot requires a url.'); }
            $snap = wpultra_vdiff_snapshot($url);
            if (is_wp_error($snap)) { return $snap; }
            wpultra_audit_log('visual-diff', "snapshot {$url} status={$snap['http_status']}", true);
            $result = $snap;
            if ($images) { $result['client_images'] = $images; }
            return wpultra_ok(['action' => 'snapshot', 'result' => $result]);
        }

        case 'baseline':
        case 'guarded-change': {
            $urls = wpultra_vdiff_input_urls($input);
            if (empty($urls)) { return wpultra_err('missing_urls', $action . ' requires url or urls.'); }
            $label = (string) ($input['label'] ?? '');
            $results = [];
            foreach ($urls as $u) {
                $rec = wpultra_vdiff_capture_baseline($u, $label);
                if (is_wp_error($rec)) {
                    $results[] = ['url' => $u, 'captured' => false, 'error' => $rec->get_error_message()];
                    continue;
                }
                $row = ['url' => $u, 'captured' => true, 'captured_at' => $rec['captured_at'], 'label' => $rec['label']];
                if ($images) { $row['client_images'] = $images; }
                $results[] = $row;
            }
            $ok = (int) count(array_filter($results, static fn($r) => $r['captured'] ?? false));
            wpultra_audit_log('visual-diff', "baseline captured={$ok}/" . count($results) . ($label ? " label={$label}" : ''), $ok > 0);

            $out = ['action' => $action, 'results' => $results];
            if ($action === 'guarded-change') {
                $out['workflow'] = 'Baselines captured. Now make your change via other abilities, then call this ability with '
                    . '{action:"compare", url:<each url>} to diff against these baselines and review severity.';
                if ($label !== '') { $out['token'] = $label; }
            }
            return wpultra_ok($out);
        }

        case 'compare': {
            $url = trim((string) ($input['url'] ?? ''));
            if ($url === '') { return wpultra_err('missing_url', 'compare requires a url.'); }
            $baselines = wpultra_vdiff_get_baselines();
            if (!isset($baselines[$url]['fingerprint'])) {
                return wpultra_err('no_baseline', "No baseline stored for {$url}. Run action:baseline for this URL first.");
            }
            $snap = wpultra_vdiff_snapshot($url);
            if (is_wp_error($snap)) { return $snap; }

            $diff = wpultra_vdiff_compare($baselines[$url]['fingerprint'], $snap['fingerprint']);
            $result = [
                'url'          => $url,
                'baseline_at'  => $baselines[$url]['captured_at'] ?? '',
                'compared_at'  => $snap['fetched_at'],
                'changed'      => $diff['changed'],
                'severity'     => $diff['severity'],
                'diffs'        => $diff['diffs'],
                'new_errors'   => $diff['new_errors'],
            ];
            if ($images) {
                $result['client_images'] = $images;
                $result['client_note'] = 'before_image_url / after_image_url were recorded — YOU should visually compare them; '
                    . 'the server only diffed structure.';
            }
            wpultra_audit_log('visual-diff', "compare {$url} severity={$diff['severity']} changed=" . ($diff['changed'] ? '1' : '0'), true);
            return wpultra_ok(['action' => 'compare', 'result' => $result]);
        }

        case 'list': {
            $baselines = wpultra_vdiff_get_baselines();
            $rows = [];
            foreach ($baselines as $u => $b) {
                $fp = $b['fingerprint'] ?? [];
                $rows[] = [
                    'url'         => $u,
                    'captured_at' => $b['captured_at'] ?? '',
                    'label'       => $b['label'] ?? '',
                    'title'       => $fp['title'] ?? '',
                    'status'      => $fp['status'] ?? 0,
                    'byte_size'   => $fp['byte_size'] ?? 0,
                ];
            }
            return wpultra_ok(['action' => 'list', 'baselines' => $rows]);
        }

        case 'clear': {
            $url = trim((string) ($input['url'] ?? ''));
            $baselines = wpultra_vdiff_get_baselines();
            if ($url !== '') {
                $existed = isset($baselines[$url]);
                unset($baselines[$url]);
                wpultra_vdiff_save_baselines($baselines);
                wpultra_audit_log('visual-diff', "clear {$url}", true);
                return wpultra_ok(['action' => 'clear', 'result' => ['cleared' => $existed ? 1 : 0, 'url' => $url]]);
            }
            $n = count($baselines);
            wpultra_vdiff_save_baselines([]);
            wpultra_audit_log('visual-diff', "clear all ({$n})", true);
            return wpultra_ok(['action' => 'clear', 'result' => ['cleared' => $n]]);
        }

        default:
            return wpultra_err('invalid_action', 'action must be one of: snapshot, baseline, compare, guarded-change, list, clear.');
    }
}

/** Collect the url/urls inputs into a deduped, trimmed list. */
function wpultra_vdiff_input_urls(array $input): array {
    $urls = [];
    if (!empty($input['url'])) { $urls[] = (string) $input['url']; }
    if (!empty($input['urls']) && is_array($input['urls'])) {
        foreach ($input['urls'] as $u) { $urls[] = (string) $u; }
    }
    $urls = array_values(array_unique(array_filter(array_map('trim', $urls), static fn($u) => $u !== '')));
    return $urls;
}
