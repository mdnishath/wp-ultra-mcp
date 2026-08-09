<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * TranslatePress / Weglot adapters for the multilingual domain.
 *
 * These two plugins translate IN PLACE (no duplicate translation posts like
 * WPML/Polylang), so duplicate-to-language does not apply to them:
 * - TranslatePress keeps per-language string dictionaries in
 *   `{$prefix}trp_dictionary_<default>_<lang>` tables (columns: id, original,
 *   translated, status 0=untranslated/1=machine/2=human). We can report
 *   coverage AND write human translations for strings TRP has already
 *   discovered (a string enters the dictionary the first time its page renders).
 * - Weglot translates through its cloud API; translations live remotely, so
 *   only detection + configured languages are readable locally.
 *
 * Mirrors the domain's adapter pattern: pure shaping/naming helpers first,
 * thin WP-calling wrappers at the bottom, everything degrading gracefully.
 */

/** WP_Error factory that works under WP and the bare test harness. */
function wpultra_i18nx_err(string $code, string $message) {
    if (function_exists('wpultra_err')) { return wpultra_err($code, $message); }
    return new WP_Error($code, $message);
}

/* ------------------------------------------------------------------ *
 * PURE
 * ------------------------------------------------------------------ */

/** TRP dictionary table for a language pair. Pure. e.g. (wp_, en_US, bn_BD) -> wp_trp_dictionary_en_us_bn_bd */
function wpultra_i18n_trp_table(string $prefix, string $default_lang, string $lang): string {
    $norm = static fn(string $c): string => strtolower(str_replace('-', '_', trim($c)));
    return $prefix . 'trp_dictionary_' . $norm($default_lang) . '_' . $norm($lang);
}

/**
 * Extract {default, languages[]} from the trp_settings option shape. Pure.
 * @param array $settings the raw trp_settings option
 * @return array{default:string,languages:array<int,string>}
 */
function wpultra_i18n_trp_extract_languages(array $settings): array {
    $default = (string) ($settings['default-language'] ?? '');
    $langs = [];
    foreach ((array) ($settings['translation-languages'] ?? []) as $code) {
        $code = (string) $code;
        if ($code !== '' && $code !== $default && !in_array($code, $langs, true)) { $langs[] = $code; }
    }
    return ['default' => $default, 'languages' => $langs];
}

/**
 * Extract {original, destinations[]} from the weglot-settings option shape
 * (array or JSON string; v3 nests under 'custom_settings'/'languages'). Pure.
 * @param mixed $raw
 * @return array{original:string,destinations:array<int,string>}
 */
function wpultra_i18n_weglot_extract_languages($raw): array {
    $settings = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($settings)) { return ['original' => '', 'destinations' => []]; }
    $original = (string) ($settings['language_from'] ?? ($settings['original_language'] ?? ''));
    $dests = [];
    foreach ((array) ($settings['languages'] ?? ($settings['destination_language'] ?? [])) as $item) {
        $code = '';
        if (is_string($item)) {
            $code = $item;
        } elseif (is_array($item)) {
            $code = (string) ($item['language_to'] ?? ($item['code'] ?? ''));
        }
        if ($code !== '' && !in_array($code, $dests, true)) { $dests[] = $code; }
    }
    return ['original' => $original, 'destinations' => $dests];
}

/* ------------------------------------------------------------------ *
 * THIN WP-calling wrappers
 * ------------------------------------------------------------------ */

/** Live probe: is TranslatePress active? */
function wpultra_i18n_trp_active(): bool {
    return class_exists('TRP_Translate_Press') || defined('TRP_PLUGIN_VERSION');
}

/** Live probe: is Weglot active? */
function wpultra_i18n_weglot_active(): bool {
    return defined('WEGLOT_VERSION') || function_exists('weglot_get_service');
}

/**
 * TranslatePress status: configured languages + per-language dictionary coverage.
 * @return array{installed:bool,default?:string,languages?:array}
 */
function wpultra_i18n_trp_status(): array {
    if (!wpultra_i18n_trp_active()) { return ['installed' => false]; }
    global $wpdb;
    $settings = (array) get_option('trp_settings', []);
    $pair = wpultra_i18n_trp_extract_languages($settings);
    $langs = [];
    foreach ($pair['languages'] as $code) {
        $row = ['code' => $code, 'strings_total' => 0, 'strings_translated' => 0];
        if (isset($wpdb) && is_object($wpdb)) {
            $table = wpultra_i18n_trp_table((string) $wpdb->prefix, $pair['default'], $code);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                $row['strings_total']      = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
                $row['strings_translated'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status > 0 AND translated IS NOT NULL AND translated != ''");
            }
        }
        $langs[] = $row;
    }
    return ['installed' => true, 'default' => $pair['default'], 'languages' => $langs];
}

/**
 * Weglot status: original + destination languages (translations live in
 * Weglot's cloud, so no local coverage counts exist).
 * @return array{installed:bool,original?:string,destinations?:array}
 */
function wpultra_i18n_weglot_status(): array {
    if (!wpultra_i18n_weglot_active()) { return ['installed' => false]; }
    $original = '';
    $dests = [];
    try {
        if (function_exists('weglot_get_original_language')) { $original = (string) weglot_get_original_language(); }
        if (function_exists('weglot_get_destination_languages')) {
            foreach ((array) weglot_get_destination_languages() as $d) {
                $code = is_array($d) ? (string) ($d['language_to'] ?? '') : (is_object($d) && method_exists($d, 'getLanguageTo') ? (string) $d->getLanguageTo() : (string) $d);
                if ($code !== '' && !in_array($code, $dests, true)) { $dests[] = $code; }
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'i18n-weglot'); }
    }
    if ($original === '' && $dests === []) {
        $pair = wpultra_i18n_weglot_extract_languages(get_option('weglot-settings', []));
        $original = $pair['original'];
        $dests = $pair['destinations'];
    }
    return ['installed' => true, 'original' => $original, 'destinations' => $dests, 'note' => 'Weglot translates via its cloud API; translations are managed in the Weglot dashboard, not locally.'];
}

/** Combined third-party block for translation-status. */
function wpultra_i18n_third_party_status(): array {
    return [
        'translatepress' => wpultra_i18n_trp_status(),
        'weglot'         => wpultra_i18n_weglot_status(),
    ];
}

/**
 * Write human translations into TRP's dictionary for strings it has ALREADY
 * discovered (TRP discovers a string the first time its page renders in that
 * language). Only updates existing rows — inserting undiscovered originals
 * would bypass TRP's original_id bookkeeping and be silently ignored.
 *
 * @param array<int,array{original:string,translated:string,language:string}> $items
 * @return array|WP_Error {updated:int, results:[{original, language, updated|error}]}
 */
function wpultra_i18n_trp_set_strings(array $items) {
    if (!wpultra_i18n_trp_active()) {
        return wpultra_i18nx_err('multilingual_unavailable', 'TranslatePress is not active.');
    }
    global $wpdb;
    $settings = (array) get_option('trp_settings', []);
    $pair = wpultra_i18n_trp_extract_languages($settings);
    if ($pair['default'] === '' || $pair['languages'] === []) {
        return wpultra_i18nx_err('multilingual_unavailable', 'TranslatePress has no translation languages configured.');
    }
    $updated = 0;
    $results = [];
    foreach ($items as $item) {
        if (!is_array($item)) { continue; }
        $original   = (string) ($item['original'] ?? '');
        $translated = (string) ($item['translated'] ?? '');
        $language   = (string) ($item['language'] ?? '');
        $res = ['original' => $original, 'language' => $language];
        if ($original === '' || $translated === '' || $language === '') {
            $res['error'] = 'original, translated, and language are all required.';
            $results[] = $res;
            continue;
        }
        if (!in_array($language, $pair['languages'], true)) {
            $res['error'] = "'{$language}' is not a configured TranslatePress language. Available: " . implode(', ', $pair['languages']);
            $results[] = $res;
            continue;
        }
        $table = wpultra_i18n_trp_table((string) $wpdb->prefix, $pair['default'], $language);
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            $res['error'] = "Dictionary table for '{$language}' does not exist yet — visit any page in that language once so TranslatePress creates it.";
            $results[] = $res;
            continue;
        }
        $n = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET translated = %s, status = 2 WHERE original = %s",
            $translated,
            $original
        ));
        if ($n === false) {
            $res['error'] = 'Dictionary update failed: ' . $wpdb->last_error;
        } elseif ((int) $n === 0) {
            $res['error'] = 'Original string not found in the dictionary — TranslatePress only knows strings whose page has rendered at least once in that language.';
        } else {
            $res['updated'] = (int) $n;
            $updated += (int) $n;
        }
        $results[] = $res;
    }
    return ['updated' => $updated, 'results' => $results];
}
