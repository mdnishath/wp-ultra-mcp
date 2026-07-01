<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Detect each active field-plugin provider, its edition and version.
 * @return array<int,array{provider:string,edition:?string,version:string,caps:array}>
 */
function wpultra_fields_providers(): array {
    $out = [];
    // ACF (free) / ACF Pro
    if (class_exists('ACF')) {
        $edition = (class_exists('acf_pro') || defined('ACF_PRO')) ? 'pro' : 'free';
        $out[] = [
            'provider' => 'acf',
            'edition'  => $edition,
            'version'  => defined('ACF_VERSION') ? (string) ACF_VERSION : '',
            'caps'     => wpultra_fields_provider_caps('acf'),
        ];
    }
    // Meta Box core
    if (defined('RWMB_VER') || function_exists('rwmb_meta')) {
        $out[] = [
            'provider' => 'metabox',
            'edition'  => 'free',
            'version'  => defined('RWMB_VER') ? (string) RWMB_VER : '',
            'caps'     => wpultra_fields_provider_caps('metabox'),
        ];
    }
    // Pods (fully free)
    if (function_exists('pods') && defined('PODS_VERSION')) {
        $out[] = [
            'provider' => 'pods',
            'edition'  => 'free',
            'version'  => (string) PODS_VERSION,
            'caps'     => wpultra_fields_provider_caps('pods'),
        ];
    }
    return $out;
}

/**
 * Capability matrix per provider. Pure except for the ACF-Pro / MB-extension probes,
 * which read only class/function existence (safe at any time).
 * @return array{manage_cpt:bool,manage_taxonomy:bool,manage_options_page:bool,complex_types:bool,define_group_db:bool}
 */
function wpultra_fields_provider_caps(string $provider): array {
    switch ($provider) {
        case 'acf':
            $pro = class_exists('acf_pro') || defined('ACF_PRO');
            return [
                'manage_cpt'          => $pro, // ACF UI post types require Pro 6.5+
                'manage_taxonomy'     => $pro,
                'manage_options_page' => $pro,
                'complex_types'       => $pro, // repeater/flexible/gallery/clone
                'define_group_db'     => true, // free ACF stores field groups in DB (acf-field-group CPT)
            ];
        case 'metabox':
            return [
                'manage_cpt'          => class_exists('MB_Custom_Post_Type') || defined('MB_CPT_DIR'),
                'manage_taxonomy'     => class_exists('MB_Custom_Post_Type') || defined('MB_CPT_DIR'),
                'manage_options_page' => class_exists('MB_Settings_Page'),
                'complex_types'       => function_exists('rwmb_meta'), // group/cloneable need MB Group/Pro at write time
                'define_group_db'     => false, // core Meta Box registers groups via PHP filter, no DB storage
            ];
        case 'pods':
            return [
                'manage_cpt'          => true, // Pods creates CPTs in free
                'manage_taxonomy'     => true,
                'manage_options_page' => true, // Pods settings pods
                'complex_types'       => true,
                'define_group_db'     => true,
            ];
    }
    return [
        'manage_cpt' => false, 'manage_taxonomy' => false, 'manage_options_page' => false,
        'complex_types' => false, 'define_group_db' => false,
    ];
}

/** Orientation summary for the field-status ability. */
function wpultra_fields_status(): array {
    $providers = wpultra_fields_providers();
    return [
        'providers'    => $providers,
        'active_count' => count($providers),
    ];
}
