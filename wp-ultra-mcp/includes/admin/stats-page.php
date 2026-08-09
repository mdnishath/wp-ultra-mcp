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

    <?php
}
