<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit(); }

function wpultra_mcp_adapter_available(): bool {
    return class_exists(WPULTRA_MCP_ADAPTER_CLASS);
}

/** Single source of truth for which ability files to load. Later waves append here. */
function wpultra_ability_files(): array {
    return [
        // filesystem
        'read-file', 'write-file', 'edit-file', 'delete-file', 'list-directory',
        // code & system
        'run-wp-cli', 'execute-php',
        // database + diagnostics
        'execute-wp-query', 'read-debug-log', 'self-test',
        // memory (Wave 1, Task 13)
        'memory-save', 'memory-get', 'memory-list', 'memory-delete',
        // wp content (Wave 1, Task 14)
        'create-post', 'update-post', 'delete-post',
        // skills
        'skill-get', 'skill-write', 'skill-edit', 'skill-delete',
        // recipe management (Wave 1.5, Task 5)
        'ability-write', 'ability-get', 'ability-delete',
        // elementor read abilities (Wave 2, Task 6) + reliability (Phase A)
        'elementor-list-widgets', 'elementor-get-widget-schema', 'elementor-get-style-schema', 'elementor-get-content', 'elementor-validate', 'elementor-render-check',
        // elementor mutation abilities (Wave 2, Task 7)
        'elementor-set-content', 'elementor-add-element', 'elementor-edit-element', 'elementor-delete-element', 'elementor-move-element',
        // elementor design read abilities (Wave 3, Task 2)
        'elementor-get-design-system', 'elementor-list-dynamic-tags',
        // elementor design write abilities (Wave 3, Task 3) + design tokens (Phase B)
        'elementor-manage-global-colors', 'elementor-manage-variables', 'elementor-apply-design-tokens',
        // elementor global-class + interaction abilities (Wave 3.5, Task 2)
        'elementor-list-global-classes', 'elementor-upsert-global-class', 'elementor-apply-class', 'elementor-set-interaction',
        // gutenberg read abilities (Wave 4a)
        'gutenberg-get-content', 'gutenberg-list-blocks', 'gutenberg-get-block-schema',
        // gutenberg write abilities (Wave 4a)
        'gutenberg-insert-block', 'gutenberg-update-block', 'gutenberg-delete-block', 'gutenberg-move-block',
        // gutenberg patterns (Wave 4b)
        'gutenberg-list-patterns', 'gutenberg-insert-pattern', 'gutenberg-manage-reusable-block',
        // elementor blueprints (Phase B2)
        'elementor-list-blueprints', 'elementor-insert-blueprint',
        // woocommerce (Wave 6, Plan 1)
        'woo-store-status', 'woo-list-products', 'woo-get-product', 'woo-upsert-product',
        'woo-delete-product', 'woo-manage-variation',
        'woo-manage-product-category', 'woo-manage-attribute',
        'woo-list-orders', 'woo-get-order', 'woo-create-order', 'woo-update-order',
        'woo-refund-order',
        'woo-list-customers', 'woo-get-customer', 'woo-upsert-customer',
        'woo-manage-coupon',
        'woo-get-settings', 'woo-update-settings',
        'woo-manage-review',
        'woo-get-reports',
        'woo-insert-product-block',
        // seo (Wave 7, Plan 1)
        'seo-status', 'seo-get-meta', 'seo-set-meta', 'seo-analyze-page',
        // seo (Wave 7, Plan 2)
        'seo-suggest-internal-links', 'seo-insert-internal-link', 'seo-link-audit',
        'seo-keyword-research', 'seo-content-gap',
        'seo-competitor-analysis', 'seo-optimize-content',
        // seo (Wave 7, Plan 3)
        'seo-manage-sitemap', 'seo-manage-robots', 'seo-manage-redirects', 'seo-manage-schema',
        'seo-manage-local-business',
        // seo (Wave 7, Plan 4)
        'seo-site-audit', 'seo-bulk-set-meta', 'seo-quick-setup',
        // fields (Wave 5, Plan 1)
        'field-status', 'field-read-values', 'field-write-values',
        // fields (Wave 5, Plan 2)
        'field-list-groups', 'field-get-group', 'acf-define-field-group', 'metabox-define-field-group', 'pods-define-fields',
        // media, users, system, content undo (Wave 8: power features)
        'media-upload', 'manage-user', 'manage-plugin-theme', 'content-restore',
        // content core (Wave 8, Tier 1)
        'list-posts', 'get-post', 'search-content', 'duplicate-post',
        'manage-term', 'register-cpt', 'register-taxonomy', 'manage-menu',
        'media-list', 'media-get', 'media-update', 'media-delete',
        'manage-comment', 'option-get', 'option-set', 'list-users', 'site-snapshot',
        // site ops + FSE (Wave 9)
        'export-content', 'import-content', 'manage-cron', 'search-replace',
        'maintenance-mode', 'site-health', 'db-snapshot',
        'theme-json-get', 'theme-json-set', 'manage-template', 'custom-css',
        // forms + audits (Wave 10)
        'form-status', 'form-list', 'form-get-entries', 'form-create',
        'security-audit', 'performance-audit',
        // ecosystem (Wave 11)
        'bricks-status', 'bricks-list-elements', 'bricks-get-content', 'bricks-set-content',
        'translation-status', 'duplicate-to-language',
        'woo-manage-shipping-zone', 'woo-manage-tax-rate', 'woo-manage-payment-gateway',
        'send-email', 'render-page', 'list-registry', 'purge-cache',
        // platform (Wave 12)
        'self-update',
        // async jobs (Wave 13)
        'job-start', 'job-status', 'job-list', 'job-cancel',
        // universal undo (Wave 14)
        'undo-list', 'undo-restore', 'undo-last',
        // playbooks — multi-step ability chaining (Wave 15)
        'playbook-run', 'playbook-save', 'playbook-list', 'playbook-delete',
        // event triggers / webhooks (Wave 16)
        'trigger-create', 'trigger-list', 'trigger-delete', 'trigger-log',
        // access control — per-role grants + rate limits (Wave 17)
        'manage-access',
    ];
}

/** Map of category slug => the ability file slugs it owns. Mirrors each file's declared category. */
function wpultra_ability_category_map(): array {
    return [
        'filesystem'     => ['read-file', 'write-file', 'edit-file', 'delete-file', 'list-directory'],
        'code-execution' => ['run-wp-cli', 'execute-php'],
        'database'       => ['execute-wp-query', 'search-replace', 'db-snapshot'],
        'diagnostics'    => ['read-debug-log', 'self-test', 'site-health', 'security-audit', 'performance-audit', 'render-page', 'list-registry'],
        'memory'         => ['memory-save', 'memory-get', 'memory-list', 'memory-delete'],
        'content'        => [
            'create-post', 'update-post', 'delete-post', 'media-upload', 'content-restore',
            'list-posts', 'get-post', 'search-content', 'duplicate-post',
            'manage-term', 'register-cpt', 'register-taxonomy', 'manage-menu',
            'media-list', 'media-get', 'media-update', 'media-delete', 'manage-comment',
        ],
        'users'          => ['manage-user', 'list-users'],
        'system'         => [
            'manage-plugin-theme', 'option-get', 'option-set', 'site-snapshot',
            'export-content', 'import-content', 'manage-cron', 'maintenance-mode',
            'send-email', 'purge-cache', 'self-update',
        ],
        'fse'            => ['theme-json-get', 'theme-json-set', 'manage-template', 'custom-css'],
        'forms'          => ['form-status', 'form-list', 'form-get-entries', 'form-create'],
        'bricks'         => ['bricks-status', 'bricks-list-elements', 'bricks-get-content', 'bricks-set-content'],
        'multilingual'   => ['translation-status', 'duplicate-to-language'],
        'jobs'           => ['job-start', 'job-status', 'job-list', 'job-cancel'],
        'undo'           => ['undo-list', 'undo-restore', 'undo-last'],
        'playbooks'      => ['playbook-run', 'playbook-save', 'playbook-list', 'playbook-delete'],
        'triggers'       => ['trigger-create', 'trigger-list', 'trigger-delete', 'trigger-log'],
        'access'         => ['manage-access'],
        'skills'         => ['skill-get', 'skill-write', 'skill-edit', 'skill-delete'],
        'custom'         => ['ability-write', 'ability-get', 'ability-delete'],
        'elementor'      => [
            'elementor-list-widgets', 'elementor-get-widget-schema', 'elementor-get-style-schema', 'elementor-get-content', 'elementor-validate', 'elementor-render-check',
            'elementor-set-content', 'elementor-add-element', 'elementor-edit-element', 'elementor-delete-element', 'elementor-move-element',
            'elementor-get-design-system', 'elementor-list-dynamic-tags',
            'elementor-manage-global-colors', 'elementor-manage-variables', 'elementor-apply-design-tokens',
            'elementor-list-global-classes', 'elementor-upsert-global-class', 'elementor-apply-class', 'elementor-set-interaction',
            'elementor-list-blueprints', 'elementor-insert-blueprint',
        ],
        'gutenberg' => [
            'gutenberg-get-content', 'gutenberg-list-blocks', 'gutenberg-get-block-schema',
            'gutenberg-insert-block', 'gutenberg-update-block', 'gutenberg-delete-block', 'gutenberg-move-block',
            'gutenberg-list-patterns', 'gutenberg-insert-pattern', 'gutenberg-manage-reusable-block',
        ],
        'woocommerce' => ['woo-store-status', 'woo-list-products', 'woo-get-product', 'woo-upsert-product', 'woo-delete-product', 'woo-manage-variation', 'woo-manage-product-category', 'woo-manage-attribute', 'woo-list-orders', 'woo-get-order', 'woo-create-order', 'woo-update-order', 'woo-refund-order', 'woo-list-customers', 'woo-get-customer', 'woo-upsert-customer', 'woo-manage-coupon', 'woo-get-settings', 'woo-update-settings', 'woo-manage-review', 'woo-get-reports', 'woo-insert-product-block', 'woo-manage-shipping-zone', 'woo-manage-tax-rate', 'woo-manage-payment-gateway'],
        'seo' => ['seo-status', 'seo-get-meta', 'seo-set-meta', 'seo-analyze-page', 'seo-suggest-internal-links', 'seo-insert-internal-link', 'seo-link-audit', 'seo-keyword-research', 'seo-content-gap', 'seo-competitor-analysis', 'seo-optimize-content', 'seo-manage-sitemap', 'seo-manage-robots', 'seo-manage-redirects', 'seo-manage-schema', 'seo-manage-local-business', 'seo-site-audit', 'seo-bulk-set-meta', 'seo-quick-setup'],
        'fields' => ['field-status', 'field-read-values', 'field-write-values', 'field-list-groups', 'field-get-group', 'acf-define-field-group', 'metabox-define-field-group', 'pods-define-fields'],
    ];
}

/** Reverse lookup: which category a given ability file belongs to ('' if unknown). */
function wpultra_file_category(string $file): string {
    foreach (wpultra_ability_category_map() as $cat => $files) {
        if (in_array($file, $files, true)) { return $cat; }
    }
    return '';
}

/** Categories the operator has switched off (whole groups of abilities never load). */
function wpultra_disabled_categories(): array {
    $v = function_exists('get_option') ? get_option('wpultra_disabled_categories', []) : [];
    return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
}

function wpultra_category_enabled(string $cat): bool {
    return !in_array($cat, wpultra_disabled_categories(), true);
}

function wpultra_register_categories(): void {
    if (!function_exists('wp_register_ability_category')) { return; }
    $cats = [
        'filesystem' => 'Filesystem read/write within the site.',
        'code-execution' => 'Run WP-CLI and PHP.',
        'database' => 'Direct parameterized SQL.',
        'diagnostics' => 'Logs and self-healing.',
        'elementor' => 'Elementor v4 schema-driven layout engine.',
        'gutenberg' => 'Gutenberg block content.',
        'woocommerce' => 'WooCommerce store: products, orders, customers, settings.',
        'seo' => 'SEO: on-page meta, internal links, technical + local SEO (Yoast/Rank Math/native).',
        'fields' => 'Custom fields & content model via ACF, Meta Box, or Pods.',
        'fse' => 'Block-theme design: theme.json global styles, templates, custom CSS.',
        'forms' => 'Forms via CF7, WPForms, Gravity Forms, or Fluent Forms.',
        'bricks' => 'Bricks builder page content.',
        'multilingual' => 'Translations via WPML or Polylang.',
        'jobs' => 'Background job runner for long operations (bulk, audits, search-replace).',
        'undo' => 'Universal undo — snapshots before option/CSS/theme.json/term changes.',
        'playbooks' => 'Multi-step playbooks that chain many abilities into one run.',
        'triggers' => 'Event triggers — webhook / auto-playbook / log on WordPress events.',
        'access' => 'Access control — per-role ability grants and per-minute rate limits.',
        'skills' => 'Reusable AI skill documents.',
        'memory'  => 'Persistent cross-session memory.',
        'content' => 'WordPress posts, pages, CPTs, media library, and revision restore.',
        'users'   => 'WordPress user accounts, roles, and meta.',
        'system'  => 'Plugin and theme install/activate/update.',
        'custom'  => 'User-defined declarative abilities.',
    ];
    foreach ($cats as $slug => $desc) {
        wp_register_ability_category($slug, ['label' => $slug, 'description' => __($desc, 'wp-ultra-mcp')]);
    }
}

function wpultra_load_abilities(): void {
    if (!wpultra_is_enabled()) { return; }
    $disabled = wpultra_disabled_categories();
    // Load the undo engine before any mutation engine so wpultra_undo_capture()
    // exists when option-set / custom-css / theme.json / term-update run. Capture
    // is a no-op when the 'undo' category is disabled (checked inside the helper).
    if (is_readable(WPULTRA_DIR . 'includes/undo/engine.php')) {
        require_once WPULTRA_DIR . 'includes/undo/engine.php';
    }
    // Load the access engine early (before permission callbacks fire) so the
    // relaxed baseline + per-ability rate/role gate are in effect. With an empty
    // policy this is a no-op (admin-only, unlimited).
    if (!in_array('access', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/access/engine.php')) {
        require_once WPULTRA_DIR . 'includes/access/engine.php';
        wpultra_access_register_gate();
    }
    // Load the Elementor engine (only if the elementor category is enabled) so ability
    // callbacks can reference its functions.
    if (!in_array('elementor', $disabled, true)) {
        foreach (['setup', 'schema', 'tree', 'engine', 'coerce', 'design', 'classes', 'validate', 'blueprints'] as $elf) {
            $elp = WPULTRA_DIR . 'includes/elementor/' . $elf . '.php';
            if (is_readable($elp)) { require_once $elp; }
        }
    }
    if (!in_array('gutenberg', $disabled, true)) {
        foreach (['tree', 'engine', 'registry', 'patterns'] as $gbf) {
            $gbp = WPULTRA_DIR . 'includes/gutenberg/' . $gbf . '.php';
            if (is_readable($gbp)) { require_once $gbp; }
        }
    }
    if (!in_array('woocommerce', $disabled, true)) {
        foreach (['setup', 'schema', 'products', 'orders', 'customers', 'coupons', 'settings', 'reports', 'bridge', 'shipping'] as $wcf) {
            $wcp = WPULTRA_DIR . 'includes/woocommerce/' . $wcf . '.php';
            if (is_readable($wcp)) { require_once $wcp; }
        }
    }
    if (!in_array('seo', $disabled, true)) {
        foreach (['setup', 'meta', 'head', 'analyze', 'links', 'research', 'technical', 'local', 'audit'] as $sf) {
            $sp = WPULTRA_DIR . 'includes/seo/' . $sf . '.php';
            if (is_readable($sp)) { require_once $sp; }
        }
    }
    if (!in_array('fields', $disabled, true)) {
        foreach (['setup', 'values', 'driver', 'groups', 'adapters/acf', 'adapters/metabox', 'adapters/pods'] as $ff) {
            $fp = WPULTRA_DIR . 'includes/fields/' . $ff . '.php';
            if (is_readable($fp)) { require_once $fp; }
        }
    }
    // Power-feature engines (media/users/system) — loaded when their category is enabled.
    if (!in_array('content', $disabled, true)) {
        foreach (['media/engine', 'content/engine', 'content/structure', 'content/comments'] as $cf) {
            $cp = WPULTRA_DIR . 'includes/' . $cf . '.php';
            if (is_readable($cp)) { require_once $cp; }
        }
    }
    if (!in_array('users', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/users/engine.php')) {
        require_once WPULTRA_DIR . 'includes/users/engine.php';
    }
    if (!in_array('system', $disabled, true)) {
        foreach (['system/engine', 'system/options', 'system/snapshot', 'system/siteops', 'system/devtools', 'system/updater'] as $sf2) {
            $sp2 = WPULTRA_DIR . 'includes/' . $sf2 . '.php';
            if (is_readable($sp2)) { require_once $sp2; }
        }
    }
    if (!in_array('database', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/system/siteops.php')) {
        require_once WPULTRA_DIR . 'includes/system/siteops.php'; // search-replace + db-snapshot engine
    }
    if (!in_array('diagnostics', $disabled, true)) {
        foreach (['system/audits', 'system/devtools', 'system/siteops'] as $df) {
            $dp = WPULTRA_DIR . 'includes/' . $df . '.php';
            if (is_readable($dp)) { require_once $dp; }
        }
    }
    if (!in_array('fse', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/fse/engine.php')) {
        require_once WPULTRA_DIR . 'includes/fse/engine.php';
    }
    if (!in_array('forms', $disabled, true)) {
        foreach (['setup', 'adapters/cf7', 'adapters/wpforms', 'adapters/gravity', 'adapters/fluent'] as $fmf) {
            $fmp = WPULTRA_DIR . 'includes/forms/' . $fmf . '.php';
            if (is_readable($fmp)) { require_once $fmp; }
        }
    }
    if (!in_array('bricks', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/bricks/engine.php')) {
        require_once WPULTRA_DIR . 'includes/bricks/engine.php';
    }
    if (!in_array('multilingual', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/i18n/engine.php')) {
        require_once WPULTRA_DIR . 'includes/i18n/engine.php';
    }
    if (!in_array('jobs', $disabled, true)) {
        // Jobs handlers reuse the siteops (search-replace) + seo (audit) engines.
        foreach (['jobs/engine', 'jobs/handlers', 'system/siteops', 'seo/setup', 'seo/meta', 'seo/analyze', 'seo/links', 'seo/audit'] as $jf) {
            $jp = WPULTRA_DIR . 'includes/' . $jf . '.php';
            if (is_readable($jp)) { require_once $jp; }
        }
    }
    if (!in_array('playbooks', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/playbooks/engine.php')) {
        require_once WPULTRA_DIR . 'includes/playbooks/engine.php';
        // Register the saved-playbook CPT so playbook-save/list/load can query it.
        if (function_exists('did_action') && did_action('init')) { wpultra_playbook_register_cpt(); }
        else { add_action('init', 'wpultra_playbook_register_cpt'); }
    }
    if (!in_array('triggers', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/triggers/engine.php')) {
        require_once WPULTRA_DIR . 'includes/triggers/engine.php';
    }
    foreach (wpultra_ability_files() as $file) {
        if (in_array(wpultra_file_category($file), $disabled, true)) { continue; }
        $path = WPULTRA_DIR . 'includes/abilities/' . $file . '.php';
        if (is_readable($path)) { require_once $path; }
    }
    if (!in_array('memory', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/memory/cpt.php')) {
        require_once WPULTRA_DIR . 'includes/memory/cpt.php';
    }
    // Skills subsystem (CPT + catalog + per-skill prompts) registers its own abilities/prompts.
    if (!in_array('skills', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/skills/cpt.php')) {
        require_once WPULTRA_DIR . 'includes/skills/cpt.php';
        require_once WPULTRA_DIR . 'includes/skills/sources.php';
        require_once WPULTRA_DIR . 'includes/skills/catalog.php';
        require_once WPULTRA_DIR . 'includes/skills/prompts.php';
    }
    if (!in_array('custom', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/recipes/cpt.php')) {
        require_once WPULTRA_DIR . 'includes/recipes/cpt.php';
        add_action('wp_abilities_api_init', 'wpultra_recipe_register_all', 600);
    }
}

function wpultra_apply_ability_policy(): void {
    if (!function_exists('wp_unregister_ability')) { return; }
    $rules = get_option('wpultra_ability_rules', []);
    if (!is_array($rules)) { return; }
    foreach ($rules as $name => $rule) {
        if (is_array($rule) && !empty($rule['disabled'])) {
            wp_unregister_ability((string) $name);
        }
    }
}

/**
 * Load SEO engine files on every WordPress front-end (and admin) request so that
 * head.php can register its wp_head / pre_get_document_title hooks.
 * The abilities registry fires only on REST API requests, so we need a separate
 * loader for the front-end rendering path.
 */
function wpultra_load_seo_frontend(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('seo', wpultra_disabled_categories(), true)) { return; }
    foreach (['setup', 'meta', 'head', 'analyze', 'links', 'research', 'technical', 'local', 'audit'] as $sf) {
        $sp = WPULTRA_DIR . 'includes/seo/' . $sf . '.php';
        if (is_readable($sp)) { require_once $sp; }
    }
}

/**
 * Load the fields engine on every request (front-end + admin) so the Meta Box
 * rwmb_meta_boxes filter registers persisted groups; the ability engine-loop only
 * runs on REST calls, so persisted MB groups need this separate always-on hook.
 */
function wpultra_load_fields_frontend(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('fields', wpultra_disabled_categories(), true)) { return; }
    foreach (['setup', 'values', 'driver', 'groups'] as $ff) {
        $fp = WPULTRA_DIR . 'includes/fields/' . $ff . '.php';
        if (is_readable($fp)) { require_once $fp; }
    }
    if (function_exists('wpultra_fields_mb_register_groups')) {
        add_filter('rwmb_meta_boxes', 'wpultra_fields_mb_register_groups');
    }
}

/**
 * Register event-trigger hooks + the async dispatcher on EVERY request. The WP
 * events (publish, order, comment, form) and the dispatch cron both fire outside
 * the REST/abilities loop, so the hooks must always be present. Loads the
 * playbook engine too, since a trigger's playbook action runs on dispatch.
 */
function wpultra_load_triggers_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('triggers', wpultra_disabled_categories(), true)) { return; }
    foreach (['triggers/engine', 'playbooks/engine'] as $tf) {
        $tp = WPULTRA_DIR . 'includes/' . $tf . '.php';
        if (is_readable($tp)) { require_once $tp; }
    }
    if (function_exists('wpultra_triggers_boot_runtime')) { wpultra_triggers_boot_runtime(); }
}

/**
 * Register the background-job runtime on EVERY request. WP-Cron fires the
 * tick on a plain wp-cron.php request (no REST / abilities loop), so the
 * `wpultra_jobs_tick` action + the job CPT must exist unconditionally.
 */
function wpultra_load_jobs_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('jobs', wpultra_disabled_categories(), true)) { return; }
    foreach (['jobs/engine', 'jobs/handlers', 'system/siteops', 'seo/setup', 'seo/meta', 'seo/analyze', 'seo/links', 'seo/audit'] as $jf) {
        $jp = WPULTRA_DIR . 'includes/' . $jf . '.php';
        if (is_readable($jp)) { require_once $jp; }
    }
    if (function_exists('wpultra_jobs_boot_runtime')) { wpultra_jobs_boot_runtime(); }
}

/**
 * Load the self-updater on admin requests so WP core's Plugins page shows
 * GitHub releases as native plugin updates (update_plugins transient filter).
 */
function wpultra_load_updater_admin(): void {
    if (!is_admin()) { return; }
    $up = WPULTRA_DIR . 'includes/system/updater.php';
    if (is_readable($up)) { require_once $up; }
    if (function_exists('wpultra_updater_inject_transient')) {
        add_filter('pre_set_site_transient_update_plugins', 'wpultra_updater_inject_transient');
        add_filter('site_transient_update_plugins', 'wpultra_updater_inject_transient');
    }
}

/**
 * Register persisted AI-defined CPTs/taxonomies (options wpultra_registered_cpts /
 * wpultra_registered_taxonomies) on every request, mirroring the SEO/fields frontend loaders.
 */
function wpultra_load_structure_frontend(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('content', wpultra_disabled_categories(), true)) { return; }
    $sp = WPULTRA_DIR . 'includes/content/structure.php';
    if (is_readable($sp)) { require_once $sp; }
    if (function_exists('wpultra_structure_register_persisted')) {
        wpultra_structure_register_persisted();
    }
}

function wpultra_boot(): void {
    if (!wpultra_mcp_adapter_available()) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>WP-Ultra-MCP: bundled MCP Adapter failed to load. Install the release build (with vendor/).</p></div>';
        });
        return;
    }

    // Brand the adapter's default server as "wpultra".
    add_filter('mcp_adapter_default_server_config', function ($config) {
        if (is_array($config)) {
            $config['server_id'] = 'wpultra';
            $config['server_route'] = 'wpultra';
            $config['server_name'] = 'WP-Ultra-MCP';
        }
        return $config;
    });

    if (!wpultra_is_enabled()) { return; }

    add_action('wp_abilities_api_categories_init', 'wpultra_register_categories');
    add_action('wp_abilities_api_init', 'wpultra_load_abilities');
    add_action('wp_abilities_api_init', 'wpultra_apply_ability_policy', PHP_INT_MAX);

    \WP\MCP\Core\McpAdapter::instance();
}
