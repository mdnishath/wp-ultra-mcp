<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Newsletter adapter domain — detection + driver resolution + subscribe/list bridge
 * for MailPoet and MC4WP (Mailchimp for WP).
 *
 * Mirrors includes/forms/setup.php: every adapter degrades gracefully when its plugin is
 * absent (function/class probes only, never fatal). All plugin API calls are wrapped in
 * try/catch since MailPoet's API throws \MailPoet\API\MP\v1\APIException on bad input.
 */

/**
 * Detect each supported newsletter plugin and its version.
 * Value is the version string when installed ('' when the plugin is present but the
 * version constant isn't defined), or null when absent.
 * @return array<string,?string>  keys: mailpoet, mc4wp
 */
function wpultra_news_detect(): array {
    $out = [
        'mailpoet' => null,
        'mc4wp'    => null,
    ];
    // MailPoet
    if (defined('MAILPOET_VERSION')) {
        $out['mailpoet'] = (string) MAILPOET_VERSION;
    } elseif (class_exists('\\MailPoet\\API\\API')) {
        $out['mailpoet'] = '';
    }
    // MC4WP (Mailchimp for WP)
    if (defined('MC4WP_VERSION')) {
        $out['mc4wp'] = (string) MC4WP_VERSION;
    } elseif (function_exists('mc4wp') || class_exists('MC4WP_MailChimp')) {
        $out['mc4wp'] = '';
    }
    return $out;
}

/** All plugin keys this domain knows about. Pure. */
function wpultra_news_known_plugins(): array {
    return ['mailpoet', 'mc4wp'];
}

/** Canonical resolution order when no explicit plugin is chosen. Pure. */
function wpultra_news_order(): array {
    return ['mailpoet', 'mc4wp'];
}

/** Human label for a plugin key. Pure. */
function wpultra_news_plugin_label(string $key): string {
    return match ($key) {
        'mailpoet' => 'MailPoet',
        'mc4wp'    => 'MC4WP (Mailchimp for WordPress)',
        default    => $key,
    };
}

/**
 * Resolve which driver to use. Pure over the detection map so it is unit-testable:
 * pass an explicit key (validated against the known set) or fall back to the single
 * detected plugin when there is exactly one, in canonical order when there are more.
 *
 * @param string                      $explicit  '' for auto, or one of the known plugin keys
 * @param array<string,?string>|null  $detected  detection map; defaults to live wpultra_news_detect()
 * @return string|WP_Error   the chosen plugin key, or WP_Error when none usable
 */
function wpultra_news_driver(string $explicit = '', ?array $detected = null) {
    if ($detected === null) { $detected = wpultra_news_detect(); }
    if ($explicit !== '') {
        if (!in_array($explicit, wpultra_news_known_plugins(), true)) {
            return wpultra_news_err('news_unknown_plugin', "Unknown newsletter plugin '{$explicit}'. Known: mailpoet, mc4wp.");
        }
        if (($detected[$explicit] ?? null) === null) {
            return wpultra_news_err('news_unavailable', "Newsletter plugin '{$explicit}' is not active on this site.");
        }
        return $explicit;
    }
    foreach (wpultra_news_order() as $key) {
        if (($detected[$key] ?? null) !== null) { return $key; }
    }
    return wpultra_news_err('news_unavailable', 'No supported newsletter plugin (MailPoet, MC4WP) is active.');
}

/**
 * WP_Error factory that works both under WordPress (wpultra_err) and under the bare
 * test harness (which loads WP_Error but not helpers.php). Keeps this file requirable
 * standalone without fataling.
 */
function wpultra_news_err(string $code, string $message): WP_Error {
    if (function_exists('wpultra_err')) { return wpultra_err($code, $message); }
    return new WP_Error($code, $message);
}

/**
 * Pure email validator wrapper (filter_var based, no WP dependency) so the pure test
 * suite can exercise it without loading WordPress's is_email().
 */
function wpultra_news_valid_email(string $email): bool {
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Shape a raw list fixture (adapter-specific shape) into the unified {id,name,subscriber_count?}
 * form used by both the ability output and the pure test suite. Pure — no WP calls.
 * @param array<string,mixed> $raw
 */
function wpultra_news_shape_list(array $raw): array {
    $id   = $raw['id'] ?? ($raw['list_id'] ?? '');
    $name = (string) ($raw['name'] ?? '');
    $out  = [
        'id'   => is_int($id) ? $id : (string) $id,
        'name' => $name,
    ];
    if (isset($raw['subscriber_count']) || isset($raw['subscribers_count'])) {
        $out['subscriber_count'] = (int) ($raw['subscriber_count'] ?? $raw['subscribers_count']);
    }
    return $out;
}

/** Orientation summary for the newsletter-status ability. Live (calls into WP + plugin APIs). */
function wpultra_news_status(): array {
    $detected = wpultra_news_detect();
    $plugins  = [];
    foreach (wpultra_news_known_plugins() as $key) {
        $version   = $detected[$key];
        $installed = $version !== null;
        $entry = [
            'plugin'    => $key,
            'label'     => wpultra_news_plugin_label($key),
            'installed' => $installed,
            'version'   => $installed ? $version : null,
            'lists'     => $installed ? wpultra_news_lists($key) : [],
        ];
        $plugins[] = $entry;
    }
    return [
        'plugins'      => $plugins,
        'active_count' => count(array_filter($detected, static fn($v) => $v !== null)),
    ];
}

/**
 * Live: fetch the mailing lists for one driver. Returns [] on any failure (best-effort,
 * used for orientation only) rather than propagating a WP_Error into the status summary.
 */
function wpultra_news_lists(string $driver): array {
    try {
        if ($driver === 'mailpoet') {
            if (!class_exists('\\MailPoet\\API\\API')) { return []; }
            $api  = \MailPoet\API\API::MP('v1');
            $raw  = $api->getLists();
            return array_map('wpultra_news_shape_list', (array) $raw);
        }
        if ($driver === 'mc4wp') {
            if (!function_exists('mc4wp')) { return []; }
            $api = mc4wp('api');
            if (!$api || !method_exists($api, 'get_lists')) { return []; }
            $raw = $api->get_lists();
            $out = [];
            foreach ((array) $raw as $list) {
                $arr = is_object($list) ? (array) $list : (array) $list;
                $out[] = wpultra_news_shape_list($arr);
            }
            return $out;
        }
    } catch (\Throwable $e) {
        return [];
    }
    return [];
}

/**
 * Live: subscribe an email to a driver, with optional first_name/last_name/list_ids.
 * Validates the email first (pure check) so bad input never reaches the plugin API.
 * @return array|WP_Error
 */
function wpultra_news_subscribe(string $driver, string $email, array $opts = []) {
    $email = trim($email);
    if (!wpultra_news_valid_email($email)) {
        return wpultra_news_err('news_bad_email', 'A valid email is required.');
    }
    $first_name = (string) ($opts['first_name'] ?? '');
    $last_name  = (string) ($opts['last_name'] ?? '');
    $list_ids   = array_values((array) ($opts['list_ids'] ?? []));

    try {
        if ($driver === 'mailpoet') {
            if (!class_exists('\\MailPoet\\API\\API')) {
                return wpultra_news_err('news_unavailable', 'MailPoet is not active.');
            }
            $api = \MailPoet\API\API::MP('v1');
            $subscriber = ['email' => $email];
            if ($first_name !== '') { $subscriber['first_name'] = $first_name; }
            if ($last_name !== '')  { $subscriber['last_name']  = $last_name; }
            $result = $api->addSubscriber($subscriber, $list_ids);
            return [
                'plugin'  => 'mailpoet',
                'email'   => $email,
                'id'      => $result['id'] ?? null,
                'status'  => $result['status'] ?? null,
                'raw'     => $result,
            ];
        }
        if ($driver === 'mc4wp') {
            if (!function_exists('mc4wp')) {
                return wpultra_news_err('news_unavailable', 'MC4WP is not active.');
            }
            $api = mc4wp('api');
            if (!$api) {
                return wpultra_news_err('news_unavailable', 'MC4WP API client is unavailable (check connected Mailchimp account).');
            }
            if ($list_ids === []) {
                return wpultra_news_err('news_missing_list', 'MC4WP requires at least one list_ids entry.');
            }
            $merge_fields = [];
            if ($first_name !== '') { $merge_fields['FNAME'] = $first_name; }
            if ($last_name !== '')  { $merge_fields['LNAME'] = $last_name; }

            $results = [];
            foreach ($list_ids as $list_id) {
                if (method_exists($api, 'list_subscribe')) {
                    // Signature varies across MC4WP versions: list_subscribe($list_id, $email, array $merge_fields = [], ...).
                    $results[$list_id] = $api->list_subscribe($list_id, $email, $merge_fields);
                } else {
                    return wpultra_news_err('news_unsupported', 'This MC4WP version does not expose list_subscribe().');
                }
            }
            return [
                'plugin'  => 'mc4wp',
                'email'   => $email,
                'lists'   => $list_ids,
                'results' => $results,
            ];
        }
    } catch (\Throwable $e) {
        return wpultra_news_err('news_exception', 'Subscribe failed: ' . $e->getMessage());
    }
    return wpultra_news_err('news_unknown_plugin', "Unknown newsletter plugin '{$driver}'.");
}

/**
 * Live: best-effort subscriber lookup. MailPoet only — MC4WP's API does not expose a
 * documented single-subscriber getter, so it returns an 'unsupported' note instead of
 * guessing at an undocumented endpoint.
 * @return array|WP_Error
 */
function wpultra_news_subscriber_get(string $driver, string $email) {
    $email = trim($email);
    if (!wpultra_news_valid_email($email)) {
        return wpultra_news_err('news_bad_email', 'A valid email is required.');
    }
    try {
        if ($driver === 'mailpoet') {
            if (!class_exists('\\MailPoet\\API\\API')) {
                return wpultra_news_err('news_unavailable', 'MailPoet is not active.');
            }
            $api = \MailPoet\API\API::MP('v1');
            $result = $api->getSubscriber($email);
            return [
                'plugin'  => 'mailpoet',
                'found'   => true,
                'email'   => $email,
                'raw'     => $result,
            ];
        }
        if ($driver === 'mc4wp') {
            return [
                'plugin'    => 'mc4wp',
                'found'     => null,
                'email'     => $email,
                'note'      => 'MC4WP does not expose a documented single-subscriber lookup API; use MailPoet or check Mailchimp directly.',
            ];
        }
    } catch (\Throwable $e) {
        // MailPoet's getSubscriber throws APIException (or similar) when the email isn't found.
        return [
            'plugin' => $driver,
            'found'  => false,
            'email'  => $email,
            'error'  => $e->getMessage(),
        ];
    }
    return wpultra_news_err('news_unknown_plugin', "Unknown newsletter plugin '{$driver}'.");
}
