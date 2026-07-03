<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * wp-admin "Usage Stats" page (Roadmap #35 — usage analytics dashboard).
 *
 * Self-contained: registers its own submenu page + admin-post handler so it
 * can be required independently of the rest of the admin/* files. Reads the
 * same store as wpultra/self-test and wpultra/usage-stats (option
 * `wpultra_ability_stats`, written by wpultra_stats_bump() in
 * includes/selftest/engine.php) via wpultra_stats_rank(), then renders it
 * with the shared pure helpers in includes/system/usage.php.
 */

require_once WPULTRA_DIR . 'includes/system/usage.php';

add_action('admin_menu', function () {
    add_submenu_page('wpultra', 'Usage Stats', 'Usage Stats', 'manage_options', 'wpultra-stats', 'wpultra_stats_page_render');
});

/** admin-post: clear the per-ability usage stats. */
add_action('admin_post_wpultra_clear_stats', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('wpultra_clear_stats')) {
        wp_die('forbidden');
    }
    update_option('wpultra_ability_stats', [], false);
    wp_safe_redirect(admin_url('admin.php?page=wpultra-stats&cleared=1'));
    exit;
});

function wpultra_stats_page_render(): void {
    $sort = isset($_GET['sort']) ? sanitize_text_field((string) $_GET['sort']) : 'calls';
    if (!in_array($sort, ['calls', 'fails', 'fail_rate'], true)) { $sort = 'calls'; }

    $raw = get_option('wpultra_ability_stats', []);
    if (!is_array($raw)) { $raw = []; }
    $rows = function_exists('wpultra_stats_rank') ? wpultra_stats_rank($raw, 1000) : [];
    $rows = wpultra_usage_sort($rows, $sort);
    $totals = wpultra_usage_totals($rows);

    $max_calls = 0;
    foreach ($rows as $r) { $max_calls = max($max_calls, (int) ($r['calls'] ?? 0)); }

    $clear_url = wp_nonce_url(admin_url('admin-post.php?action=wpultra_clear_stats'), 'wpultra_clear_stats');
    $base_url = admin_url('admin.php?page=wpultra-stats');

    $sort_link = function (string $key) use ($base_url, $sort): string {
        $label = ucfirst(str_replace('_', ' ', $key));
        $active = $sort === $key;
        $url = esc_url(add_query_arg('sort', $key, $base_url));
        return '<a href="' . $url . '" class="wpu-sortlink' . ($active ? ' active' : '') . '">' . esc_html($label) . ($active ? ' &#9660;' : '') . '</a>';
    };
    ?>
    <div class="wrap wpu-wrap">
        <div class="wpu-head">
            <div>
                <h1 class="wpu-title"><span class="dashicons dashicons-chart-bar"></span> Usage Stats</h1>
                <p class="wpu-sub">Per-ability call volume and failure rate, tallied from every AI-driven ability invocation.</p>
            </div>
            <?php if ($rows) : ?>
                <a href="<?php echo esc_url($clear_url); ?>" class="button" onclick="return confirm('Reset all usage stats? This cannot be undone.');">Reset stats</a>
            <?php endif; ?>
        </div>

        <?php if (!empty($_GET['cleared'])) : ?>
            <div class="notice notice-success inline wpu-notice-inline"><p>Usage stats cleared.</p></div>
        <?php endif; ?>

        <?php if (!$rows) : ?>
            <div class="wpu-card" style="padding:24px;color:#646970;">No ability calls recorded yet.</div>
        <?php else : ?>
            <div class="wpu-summary-grid">
                <div class="wpu-summary-card">
                    <div class="wpu-summary-num"><?php echo esc_html((string) $totals['calls']); ?></div>
                    <div class="wpu-summary-label">Total calls</div>
                </div>
                <div class="wpu-summary-card">
                    <div class="wpu-summary-num wpu-summary-fail"><?php echo esc_html((string) $totals['fails']); ?></div>
                    <div class="wpu-summary-label">Total fails</div>
                </div>
                <div class="wpu-summary-card">
                    <div class="wpu-summary-num"><?php echo esc_html((string) $totals['abilities']); ?></div>
                    <div class="wpu-summary-label">Distinct abilities</div>
                </div>
                <div class="wpu-summary-card">
                    <div class="wpu-summary-num wpu-summary-top"><?php echo esc_html($totals['top_action'] !== '' ? $totals['top_action'] : '—'); ?></div>
                    <div class="wpu-summary-label">Top ability</div>
                </div>
            </div>

            <div class="wpu-card wpu-pad">
                <div class="wpu-chart">
                    <?php foreach ($rows as $r) :
                        $calls = (int) ($r['calls'] ?? 0);
                        $fails = (int) ($r['fails'] ?? 0);
                        $width = wpultra_usage_bar_width($calls, $max_calls);
                        $fail_width = $calls > 0 ? wpultra_usage_bar_width($fails, $calls) : 0;
                        ?>
                        <div class="wpu-chart-row">
                            <div class="wpu-chart-label" title="<?php echo esc_attr((string) $r['action']); ?>"><?php echo esc_html((string) $r['action']); ?></div>
                            <div class="wpu-chart-track">
                                <div class="wpu-chart-bar" style="width: <?php echo (int) $width; ?>%;">
                                    <?php if ($fails > 0) : ?>
                                        <div class="wpu-chart-fail" style="width: <?php echo (int) $fail_width; ?>%;"></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="wpu-chart-count"><?php echo esc_html((string) $calls); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wpu-card">
                <table class="widefat striped wpu-stats-table">
                    <thead><tr>
                        <th>Action</th>
                        <th style="width:110px;"><?php echo $sort_link('calls'); ?></th>
                        <th style="width:110px;"><?php echo $sort_link('fails'); ?></th>
                        <th style="width:130px;"><?php echo $sort_link('fail_rate'); ?></th>
                        <th>Last error</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r) :
                        $last_error = (string) ($r['last_error'] ?? '');
                        $truncated = function_exists('mb_substr') ? mb_substr($last_error, 0, 120) : substr($last_error, 0, 120);
                        $fail_rate_pct = round(((float) ($r['fail_rate'] ?? 0)) * 100, 1);
                        ?>
                        <tr>
                            <td><code><?php echo esc_html((string) $r['action']); ?></code></td>
                            <td><?php echo esc_html((string) $r['calls']); ?></td>
                            <td><?php echo esc_html((string) $r['fails']); ?></td>
                            <td><?php echo esc_html((string) $fail_rate_pct); ?>%</td>
                            <td><code class="wpu-lasterr"><?php echo esc_html($truncated !== '' ? $truncated : '—'); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <style>
        .wpu-notice-inline { margin: 0 0 16px; }

        .wpu-summary-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin:0 0 18px; }
        .wpu-summary-card { background:#fff; border:1px solid #e6e7eb; border-radius:14px; padding:16px 18px;
            box-shadow:0 6px 20px rgba(18,20,40,.06), 0 1px 3px rgba(18,20,40,.05); }
        .wpu-summary-num { font-size:24px; font-weight:700; color:#1d2327; line-height:1.3;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .wpu-summary-fail { color:#c23b3b; }
        .wpu-summary-top { font-size:15px; font-family:Consolas,Monaco,monospace; color:#6d4afe; }
        .wpu-summary-label { color:#787c82; font-size:12px; margin-top:4px; text-transform:uppercase; letter-spacing:.4px; }

        .wpu-chart { display:flex; flex-direction:column; gap:10px; }
        .wpu-chart-row { display:flex; align-items:center; gap:12px; }
        .wpu-chart-label { flex:0 0 220px; font-size:12.5px; color:#1d2327; font-family:Consolas,Monaco,monospace;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .wpu-chart-track { flex:1; background:#f0f0f4; border-radius:6px; height:16px; overflow:hidden; position:relative; }
        .wpu-chart-bar { height:100%; background:linear-gradient(135deg,#7b5cff,#5b34f2); border-radius:6px; position:relative;
            transition:width .3s ease; min-width:2px; }
        .wpu-chart-fail { position:absolute; top:0; right:0; height:100%; background:#c23b3b; }
        .wpu-chart-count { flex:0 0 50px; text-align:right; font-size:12.5px; color:#50575e; font-weight:600; }

        .wpu-stats-table { border:none; box-shadow:none; }
        .wpu-stats-table th, .wpu-stats-table td { font-size:13px; }
        .wpu-sortlink { color:#50575e; text-decoration:none; }
        .wpu-sortlink.active { color:#6d4afe; font-weight:700; }
        .wpu-sortlink:hover { color:#6d4afe; }
        .wpu-lasterr { white-space:pre-wrap; word-break:break-word; color:#c23b3b; background:#fff5f5; }

        @media (max-width: 900px) {
            .wpu-summary-grid { grid-template-columns:repeat(2,1fr); }
            .wpu-chart-label { flex-basis:120px; }
        }
    </style>
    <?php
}
