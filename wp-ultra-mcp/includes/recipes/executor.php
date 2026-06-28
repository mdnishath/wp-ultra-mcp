<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

function wpultra_recipe_subst_scalar(string $tpl, array $input): string {
    return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($input) {
        return array_key_exists($m[1], $input) ? (string) $input[$m[1]] : '';
    }, $tpl);
}

function wpultra_recipe_subst_array(array $tpl, array $input): array {
    $out = [];
    foreach ($tpl as $el) { $out[] = is_string($el) ? wpultra_recipe_subst_scalar($el, $input) : $el; }
    return $out;
}

/**
 * Run-types the recipe engine may execute. Operators can lock down the code-execution
 * primitives an AI can mint (php/sql/wp-cli) while keeping declarative recipes, via the
 * WPULTRA_RECIPE_RUN_TYPES constant (comma list, e.g. 'http') or the filter. Default: all.
 */
function wpultra_recipe_allowed_run_types(): array {
    $all = ['wp-cli', 'sql', 'php', 'http'];
    $allowed = $all;
    if (defined('WPULTRA_RECIPE_RUN_TYPES')) {
        $allowed = array_map('trim', explode(',', strtolower((string) WPULTRA_RECIPE_RUN_TYPES)));
    }
    $allowed = apply_filters('wpultra_recipe_allowed_run_types', $allowed);
    $allowed = is_array($allowed) ? $allowed : $all;
    return array_values(array_intersect($all, $allowed));
}

function wpultra_recipe_execute(array $parsed, array $input) {
    $input = (array) $input;
    foreach ((array) ($parsed['input'] ?? []) as $key => $def) {
        $def = (array) $def;
        if (!empty($def['required']) && !array_key_exists($key, $input)) {
            return wpultra_err('recipe_missing_input', "Required input '$key' is missing.");
        }
    }
    $run = (string) ($parsed['run'] ?? '');
    if (!in_array($run, wpultra_recipe_allowed_run_types(), true)) {
        return wpultra_err('recipe_run_disabled', "Recipe run type '$run' is disabled on this site (see WPULTRA_RECIPE_RUN_TYPES).");
    }
    $recipe = (array) ($parsed['recipe'] ?? []);

    if ($run === 'wp-cli') {
        $args = wpultra_recipe_subst_array((array) ($recipe['command'] ?? []), $input);
        return wpultra_run_wp_cli(['args' => $args]);
    }
    if ($run === 'sql') {
        $params = wpultra_recipe_subst_array((array) ($recipe['params'] ?? []), $input);
        return wpultra_execute_wp_query([
            'sql' => (string) ($recipe['query'] ?? ''),
            'params' => $params,
            'confirm' => ($input['confirm'] ?? false) === true,
        ]);
    }
    if ($run === 'php') {
        $code = wpultra_recipe_subst_scalar((string) ($recipe['code'] ?? ''), $input);
        return wpultra_execute_php(['code' => $code]);
    }
    if ($run === 'http') {
        $url = wpultra_recipe_subst_scalar((string) ($recipe['url'] ?? ''), $input);
        $method = strtoupper((string) ($recipe['method'] ?? 'GET'));
        // wp_safe_remote_request applies WP's SSRF protections (rejects private/loopback/
        // link-local hosts unless explicitly allowed), unlike the unguarded wp_remote_request.
        $resp = wp_safe_remote_request($url, ['method' => $method, 'timeout' => 20]);
        wpultra_audit_log('recipe-http', $method . ' ' . $url, !is_wp_error($resp));
        if (is_wp_error($resp)) { return $resp; }
        return wpultra_ok(['status' => wp_remote_retrieve_response_code($resp), 'body' => wp_remote_retrieve_body($resp)]);
    }
    return wpultra_err('recipe_bad_run', "Unknown run type: $run");
}
