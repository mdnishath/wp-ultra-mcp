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
        // custom atomic widget generator (Wave 18)
        'create-atomic-widget', 'list-atomic-widgets', 'delete-atomic-widget',
        // one-call page cloner (Wave 19)
        'elementor-clone-url',
        // Elementor Pro surface (Wave 20)
        'elementor-pro-status', 'elementor-manage-library', 'elementor-manage-popup', 'elementor-form-submissions',
        // Divi / Beaver / Oxygen adapter foundation (Wave 21)
        'pagebuilder-status', 'pagebuilder-get-content', 'pagebuilder-set-content', 'pagebuilder-list-elements',
        // Bricks deep (Wave 22)
        'bricks-get-element-schema', 'bricks-validate', 'bricks-add-element', 'bricks-edit-element',
        'bricks-delete-element', 'bricks-move-element', 'bricks-manage-global-class', 'bricks-insert-blueprint',
        // JetEngine (Wave 23)
        'jetengine-status', 'jetengine-manage-cpt', 'jetengine-manage-taxonomy', 'jetengine-manage-meta-box',
        // Content & Fields completion (Wave 24: roadmap #13-#17)
        'field-manage-rows', 'media-generate', 'media-edit-image', 'media-bulk-alt',
        'translation-set-content', 'content-calendar',
        // Woo tier (Wave 25: roadmap #18-#21)
        'woo-export-products', 'woo-import-products', 'woo-manage-subscription',
        'woo-manage-booking', 'woo-insights', 'woo-manage-email',
        // Site Ops+ (Wave 26: roadmap #22-#26)
        'site-backup', 'staging-clone', 'multisite-manage', 'manage-server-rules', 'activity-log',
        // Marketing (Wave 27: roadmap #27-#31)
        'form-forward', 'social-autopost', 'newsletter-status', 'newsletter-subscribe',
        'analytics-report', 'seo-indexnow', 'seo-404-log',
        // AI-Native (Wave 28: roadmap #32-#35)
        'skill-sync', 'site-brain', 'error-reports', 'usage-stats',
        // Tier S high-demand (Wave 29: roadmap-2 S1-S4)
        'content-plan', 'content-generate', 'security-harden', 'security-scan',
        'optimize-database', 'optimize-images', 'optimize-cache', 'woo-bulk-edit',
        // Growth & Money (Wave 30: roadmap-2 A1-A5)
        'email-campaign', 'ab-test', 'lead-manage', 'popup-campaign', 'affiliate-manage',
        // Store Power (Wave 31: roadmap-2 B1-B6)
        'woo-pricing-rules', 'woo-fulfillment', 'woo-review-engine', 'woo-wishlist', 'woo-loyalty', 'woo-currency',
        // Site Safety & Health (Wave 32: roadmap-2 C1-C4)
        'health-monitor', 'link-fixer', 'backup-schedule', 'firewall-manage',
        // AI-Native Moat (Wave 33: roadmap-2 F1-F6)
        'ai-chatbot', 'agent-run', 'visual-diff', 'nl-analytics', 'seo-autopilot', 'design-from-brief',
        // Ops & Compliance (Wave 34: roadmap-2 G1-G5)
        'gdpr-tools', 'site-migrate', 'roles-manage', 'scheduled-reports', 'white-label',
        // Content & SEO Reach (Wave 35: roadmap-2 D1-D6)
        'schema-generate', 'autotranslate', 'content-freshness', 'link-optimizer', 'feed-import', 'social-scheduler',
        // Business Verticals (Wave 36: roadmap-2 E1-E6)
        'booking-manage', 'membership-manage', 'lms-manage', 'events-manage', 'directory-manage', 'donations-manage',
        // Headless foundation (Wave H1: roadmap-3 H1.1-H1.6)
        'headless-status', 'headless-setup', 'graphql-introspect', 'graphql-query', 'headless-expose', 'headless-rest-bundle',
        // Headless frontend loop (Wave H2: roadmap-3 H2.1-H2.4)
        'headless-scaffold', 'headless-preview', 'headless-auth', 'headless-revalidate',
        // Headless showcase (Wave H3: roadmap-3 H3.1-H3.5)
        'headless-build-site', 'headless-woo', 'headless-seo', 'headless-deploy', 'graphql-persisted-queries',
        // Bug Fixer core (Wave 37: roadmap-4 BF1)
        'debug-mode', 'conflict-bisect', 'fix-permalinks', 'repair-database', 'php-env-info', 'safe-mode-manage',
        // Bug Fixer reach (Wave 38: roadmap-4 BF2) — undo-coverage (BF2.6) adds no ability, only engine wiring
        'auto-recover', 'query-profiler', 'rest-probe', 'js-error-log', 'plugin-checksum-verify',
        // Pixel-Perfect core (Wave 39: roadmap-4 PP1)
        'elementor-style-variant', 'element-custom-css', 'pixel-diff', 'inspect-element',
    ];
}

/** Map of category slug => the ability file slugs it owns. Mirrors each file's declared category. */
function wpultra_ability_category_map(): array {
    return [
        'filesystem'     => ['read-file', 'write-file', 'edit-file', 'delete-file', 'list-directory'],
        'code-execution' => ['run-wp-cli', 'execute-php'],
        'database'       => ['execute-wp-query', 'search-replace', 'db-snapshot', 'repair-database'],
        'diagnostics'    => ['read-debug-log', 'self-test', 'site-health', 'security-audit', 'performance-audit', 'render-page', 'list-registry', 'activity-log', 'analytics-report', 'error-reports', 'usage-stats', 'security-harden', 'security-scan', 'health-monitor', 'firewall-manage', 'scheduled-reports', 'debug-mode', 'conflict-bisect', 'fix-permalinks', 'php-env-info', 'safe-mode-manage', 'auto-recover', 'query-profiler', 'rest-probe', 'js-error-log', 'plugin-checksum-verify'],
        'memory'         => ['memory-save', 'memory-get', 'memory-list', 'memory-delete', 'site-brain'],
        'content'        => [
            'create-post', 'update-post', 'delete-post', 'media-upload', 'content-restore',
            'list-posts', 'get-post', 'search-content', 'duplicate-post',
            'manage-term', 'register-cpt', 'register-taxonomy', 'manage-menu',
            'media-list', 'media-get', 'media-update', 'media-delete', 'manage-comment',
            'media-generate', 'media-edit-image', 'media-bulk-alt', 'content-calendar',
            'content-plan', 'content-generate', 'optimize-images',
            'content-freshness', 'feed-import',
        ],
        'users'          => ['manage-user', 'list-users', 'roles-manage'],
        'system'         => [
            'manage-plugin-theme', 'option-get', 'option-set', 'site-snapshot',
            'export-content', 'import-content', 'manage-cron', 'maintenance-mode',
            'send-email', 'purge-cache', 'self-update',
            'site-backup', 'staging-clone', 'multisite-manage', 'manage-server-rules',
            'optimize-database', 'optimize-cache', 'backup-schedule',
            'site-migrate', 'white-label',
        ],
        'fse'            => ['theme-json-get', 'theme-json-set', 'manage-template', 'custom-css'],
        'forms'          => ['form-status', 'form-list', 'form-get-entries', 'form-create'],
        'bricks'         => [
            'bricks-status', 'bricks-list-elements', 'bricks-get-content', 'bricks-set-content',
            'bricks-get-element-schema', 'bricks-validate', 'bricks-add-element', 'bricks-edit-element',
            'bricks-delete-element', 'bricks-move-element', 'bricks-manage-global-class', 'bricks-insert-blueprint',
        ],
        'builders'       => ['pagebuilder-status', 'pagebuilder-get-content', 'pagebuilder-set-content', 'pagebuilder-list-elements'],
        'jetengine'      => ['jetengine-status', 'jetengine-manage-cpt', 'jetengine-manage-taxonomy', 'jetengine-manage-meta-box'],
        'multilingual'   => ['translation-status', 'duplicate-to-language', 'translation-set-content', 'autotranslate'],
        'jobs'           => ['job-start', 'job-status', 'job-list', 'job-cancel'],
        'undo'           => ['undo-list', 'undo-restore', 'undo-last'],
        'playbooks'      => ['playbook-run', 'playbook-save', 'playbook-list', 'playbook-delete'],
        'triggers'       => ['trigger-create', 'trigger-list', 'trigger-delete', 'trigger-log', 'form-forward', 'social-autopost'],
        'newsletter'     => ['newsletter-status', 'newsletter-subscribe'],
        'marketing'      => ['email-campaign', 'ab-test', 'lead-manage', 'popup-campaign', 'affiliate-manage', 'social-scheduler'],
        'ai'             => ['ai-chatbot', 'agent-run', 'visual-diff', 'nl-analytics', 'design-from-brief', 'pixel-diff'],
        'compliance'     => ['gdpr-tools'],
        'verticals'      => ['booking-manage', 'membership-manage', 'lms-manage', 'events-manage', 'directory-manage', 'donations-manage'],
        'headless'       => ['headless-status', 'headless-setup', 'graphql-introspect', 'graphql-query', 'headless-expose', 'headless-rest-bundle', 'headless-scaffold', 'headless-preview', 'headless-auth', 'headless-revalidate', 'headless-build-site', 'headless-woo', 'headless-seo', 'headless-deploy', 'graphql-persisted-queries'],
        'access'         => ['manage-access'],
        'skills'         => ['skill-get', 'skill-write', 'skill-edit', 'skill-delete', 'skill-sync'],
        'custom'         => ['ability-write', 'ability-get', 'ability-delete'],
        'elementor'      => [
            'elementor-list-widgets', 'elementor-get-widget-schema', 'elementor-get-style-schema', 'elementor-get-content', 'elementor-validate', 'elementor-render-check',
            'elementor-set-content', 'elementor-add-element', 'elementor-edit-element', 'elementor-delete-element', 'elementor-move-element',
            'elementor-get-design-system', 'elementor-list-dynamic-tags',
            'elementor-manage-global-colors', 'elementor-manage-variables', 'elementor-apply-design-tokens',
            'elementor-list-global-classes', 'elementor-upsert-global-class', 'elementor-apply-class', 'elementor-set-interaction',
            'elementor-list-blueprints', 'elementor-insert-blueprint',
            'create-atomic-widget', 'list-atomic-widgets', 'delete-atomic-widget',
            'elementor-clone-url',
            'elementor-pro-status', 'elementor-manage-library', 'elementor-manage-popup', 'elementor-form-submissions',
            'elementor-style-variant', 'element-custom-css', 'inspect-element',
        ],
        'gutenberg' => [
            'gutenberg-get-content', 'gutenberg-list-blocks', 'gutenberg-get-block-schema',
            'gutenberg-insert-block', 'gutenberg-update-block', 'gutenberg-delete-block', 'gutenberg-move-block',
            'gutenberg-list-patterns', 'gutenberg-insert-pattern', 'gutenberg-manage-reusable-block',
        ],
        'woocommerce' => ['woo-store-status', 'woo-list-products', 'woo-get-product', 'woo-upsert-product', 'woo-delete-product', 'woo-manage-variation', 'woo-manage-product-category', 'woo-manage-attribute', 'woo-list-orders', 'woo-get-order', 'woo-create-order', 'woo-update-order', 'woo-refund-order', 'woo-list-customers', 'woo-get-customer', 'woo-upsert-customer', 'woo-manage-coupon', 'woo-get-settings', 'woo-update-settings', 'woo-manage-review', 'woo-get-reports', 'woo-insert-product-block', 'woo-manage-shipping-zone', 'woo-manage-tax-rate', 'woo-manage-payment-gateway', 'woo-export-products', 'woo-import-products', 'woo-manage-subscription', 'woo-manage-booking', 'woo-insights', 'woo-manage-email', 'woo-bulk-edit', 'woo-pricing-rules', 'woo-fulfillment', 'woo-review-engine', 'woo-wishlist', 'woo-loyalty', 'woo-currency'],
        'seo' => ['seo-status', 'seo-get-meta', 'seo-set-meta', 'seo-analyze-page', 'seo-suggest-internal-links', 'seo-insert-internal-link', 'seo-link-audit', 'seo-keyword-research', 'seo-content-gap', 'seo-competitor-analysis', 'seo-optimize-content', 'seo-manage-sitemap', 'seo-manage-robots', 'seo-manage-redirects', 'seo-manage-schema', 'seo-manage-local-business', 'seo-site-audit', 'seo-bulk-set-meta', 'seo-quick-setup', 'seo-indexnow', 'seo-404-log', 'link-fixer', 'seo-autopilot', 'schema-generate', 'link-optimizer'],
        'fields' => ['field-status', 'field-read-values', 'field-write-values', 'field-list-groups', 'field-get-group', 'acf-define-field-group', 'metabox-define-field-group', 'pods-define-fields', 'field-manage-rows'],
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
        'builders' => 'Divi / Beaver Builder / Oxygen page-builder content.',
        'jetengine' => 'JetEngine: CPTs, taxonomies, meta boxes, relations, listings.',
        'multilingual' => 'Translations via WPML or Polylang.',
        'jobs' => 'Background job runner for long operations (bulk, audits, search-replace).',
        'undo' => 'Universal undo — snapshots before option/CSS/theme.json/term changes.',
        'playbooks' => 'Multi-step playbooks that chain many abilities into one run.',
        'triggers' => 'Event triggers — webhook / auto-playbook / log on WordPress events.',
        'access' => 'Access control — per-role ability grants and per-minute rate limits.',
        'newsletter' => 'Newsletter subscribers via MailPoet or Mailchimp for WP.',
        'marketing' => 'Growth tools — email campaigns, A/B tests, leads CRM, popups, affiliate tracking.',
        'ai' => 'AI-native — RAG chatbot, agent loop, visual diff, NL analytics, design-from-brief.',
        'compliance' => 'Compliance — GDPR cookie consent, data export/erase, privacy tools.',
        'verticals' => 'Business verticals — booking, membership, LMS, events, directory, donations.',
        'headless' => 'Headless WordPress — WPGraphQL backend, schema introspection, frontend scaffold, preview, revalidation.',
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
        foreach (['setup', 'schema', 'tree', 'engine', 'coerce', 'design', 'classes', 'validate', 'blueprints', 'widgets', 'clone', 'pro', 'variants', 'customcss', 'inspect'] as $elf) {
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
        foreach (['setup', 'schema', 'products', 'orders', 'customers', 'coupons', 'settings', 'reports', 'bridge', 'shipping', 'csv', 'extensions', 'insights', 'emails', 'bulk', 'pricing', 'fulfillment', 'reviews-engine', 'wishlist', 'loyalty', 'currency'] as $wcf) {
            $wcp = WPULTRA_DIR . 'includes/woocommerce/' . $wcf . '.php';
            if (is_readable($wcp)) { require_once $wcp; }
        }
    }
    if (!in_array('seo', $disabled, true)) {
        foreach (['setup', 'meta', 'head', 'analyze', 'links', 'research', 'technical', 'local', 'audit', 'monitor', 'schema-gen', 'linkgraph'] as $sf) {
            $sp = WPULTRA_DIR . 'includes/seo/' . $sf . '.php';
            if (is_readable($sp)) { require_once $sp; }
        }
    }
    if (!in_array('fields', $disabled, true)) {
        foreach (['setup', 'values', 'driver', 'groups', 'complex', 'adapters/acf', 'adapters/metabox', 'adapters/pods'] as $ff) {
            $fp = WPULTRA_DIR . 'includes/fields/' . $ff . '.php';
            if (is_readable($fp)) { require_once $fp; }
        }
    }
    // Power-feature engines (media/users/system) — loaded when their category is enabled.
    if (!in_array('content', $disabled, true)) {
        foreach (['media/engine', 'media/generate', 'media/editing', 'content/engine', 'content/structure', 'content/comments', 'content/calendar', 'content/pipeline', 'content/freshness', 'content/feed-import'] as $cf) {
            $cp = WPULTRA_DIR . 'includes/' . $cf . '.php';
            if (is_readable($cp)) { require_once $cp; }
        }
    }
    if (!in_array('users', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/users/engine.php')) {
        require_once WPULTRA_DIR . 'includes/users/engine.php';
    }
    if (!in_array('system', $disabled, true)) {
        foreach (['system/engine', 'system/options', 'system/snapshot', 'system/siteops', 'system/devtools', 'system/updater', 'system/backup', 'system/staging', 'system/network', 'system/rules', 'system/optimize', 'system/backup-schedule', 'system/migration', 'system/whitelabel'] as $sf2) {
            $sp2 = WPULTRA_DIR . 'includes/' . $sf2 . '.php';
            if (is_readable($sp2)) { require_once $sp2; }
        }
    }
    if (!in_array('users', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/system/roles.php')) {
        require_once WPULTRA_DIR . 'includes/system/roles.php';
    }
    if (!in_array('compliance', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/compliance/gdpr.php')) {
        require_once WPULTRA_DIR . 'includes/compliance/gdpr.php';
    }
    if (!in_array('database', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/system/siteops.php')) {
        require_once WPULTRA_DIR . 'includes/system/siteops.php'; // search-replace + db-snapshot engine
        // repair-database engine (reuses siteops db-snapshot before any repair).
        if (is_readable(WPULTRA_DIR . 'includes/system/dbrepair.php')) { require_once WPULTRA_DIR . 'includes/system/dbrepair.php'; }
    }
    if (!in_array('diagnostics', $disabled, true)) {
        foreach (['system/audits', 'system/devtools', 'system/siteops', 'system/activity', 'system/analytics', 'system/errors', 'system/usage', 'system/security', 'system/rules', 'system/health', 'system/firewall', 'system/reports', 'system/engine', 'system/debugmode', 'system/bisect', 'system/permalinks', 'system/envinfo', 'sandbox/manage', 'system/autorecover', 'system/queryprofiler', 'system/restprobe', 'system/jserrors', 'system/pluginchecksum'] as $df) {
            $dp = WPULTRA_DIR . 'includes/' . $df . '.php';
            if (is_readable($dp)) { require_once $dp; }
        }
    }
    if (!in_array('seo', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/system/linkfix.php')) {
        require_once WPULTRA_DIR . 'includes/system/linkfix.php';
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
    if (!in_array('bricks', $disabled, true)) {
        foreach (['engine', 'ops'] as $bf) {
            $bp = WPULTRA_DIR . 'includes/bricks/' . $bf . '.php';
            if (is_readable($bp)) { require_once $bp; }
        }
    }
    if (!in_array('builders', $disabled, true)) {
        foreach (['setup', 'adapters/divi', 'adapters/beaver', 'adapters/oxygen'] as $pbf) {
            $pbp = WPULTRA_DIR . 'includes/builders/' . $pbf . '.php';
            if (is_readable($pbp)) { require_once $pbp; }
        }
    }
    if (!in_array('jetengine', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/jetengine/engine.php')) {
        require_once WPULTRA_DIR . 'includes/jetengine/engine.php';
    }
    if (!in_array('newsletter', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/newsletter/engine.php')) {
        require_once WPULTRA_DIR . 'includes/newsletter/engine.php';
    }
    if (!in_array('multilingual', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/i18n/engine.php')) {
        require_once WPULTRA_DIR . 'includes/i18n/engine.php';
        if (is_readable(WPULTRA_DIR . 'includes/i18n/autotranslate.php')) { require_once WPULTRA_DIR . 'includes/i18n/autotranslate.php'; }
    }
    if (!in_array('marketing', $disabled, true)) {
        foreach (['campaigns', 'ab', 'leads', 'popups', 'affiliates', 'track', 'social-scheduler'] as $mkf) {
            $mkp = WPULTRA_DIR . 'includes/marketing/' . $mkf . '.php';
            if (is_readable($mkp)) { require_once $mkp; }
        }
    }
    if (!in_array('verticals', $disabled, true)) {
        foreach (['booking', 'membership', 'lms', 'events', 'directory', 'donations'] as $vf) {
            $vp = WPULTRA_DIR . 'includes/verticals/' . $vf . '.php';
            if (is_readable($vp)) { require_once $vp; }
        }
    }
    if (!in_array('headless', $disabled, true)) {
        foreach (['setup', 'introspect', 'query', 'expose', 'rest', 'scaffold', 'preview', 'auth', 'revalidate', 'build', 'woo', 'seo', 'deploy', 'persisted'] as $hf) {
            $hp = WPULTRA_DIR . 'includes/headless/' . $hf . '.php';
            if (is_readable($hp)) { require_once $hp; }
        }
    }
    // AI-native engines (Group F): shared setup first, then per-feature engines.
    // seopilot lives under the 'seo' category; the rest under 'ai'. Load setup
    // whenever either category is enabled so the shared helpers exist.
    if (!in_array('ai', $disabled, true) || !in_array('seo', $disabled, true)) {
        if (is_readable(WPULTRA_DIR . 'includes/ai/setup.php')) { require_once WPULTRA_DIR . 'includes/ai/setup.php'; }
    }
    if (!in_array('ai', $disabled, true)) {
        foreach (['kb', 'agent', 'visualdiff', 'nlquery', 'designbrief', 'pixeldiff'] as $aif) {
            $aip = WPULTRA_DIR . 'includes/ai/' . $aif . '.php';
            if (is_readable($aip)) { require_once $aip; }
        }
    }
    if (!in_array('seo', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/ai/seopilot.php')) {
        require_once WPULTRA_DIR . 'includes/ai/seopilot.php';
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
        foreach (['system/brain', 'system/snapshot'] as $bf2) {
            $bp2 = WPULTRA_DIR . 'includes/' . $bf2 . '.php';
            if (is_readable($bp2)) { require_once $bp2; }
        }
    }
    // Skills subsystem (CPT + catalog + per-skill prompts) registers its own abilities/prompts.
    if (!in_array('skills', $disabled, true) && is_readable(WPULTRA_DIR . 'includes/skills/cpt.php')) {
        require_once WPULTRA_DIR . 'includes/skills/cpt.php';
        require_once WPULTRA_DIR . 'includes/skills/sources.php';
        require_once WPULTRA_DIR . 'includes/skills/catalog.php';
        require_once WPULTRA_DIR . 'includes/skills/prompts.php';
        if (is_readable(WPULTRA_DIR . 'includes/skills/sync.php')) { require_once WPULTRA_DIR . 'includes/skills/sync.php'; }
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
    // schema-gen loaded too so the wp_head JSON-LD renderer can use the rich
    // Product/Recipe/Event/… builders for schema-generate's persisted schemas.
    foreach (['setup', 'meta', 'head', 'analyze', 'links', 'research', 'technical', 'local', 'audit', 'monitor', 'schema-gen'] as $sf) {
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
 * Register the ops & compliance runtimes on EVERY request: GDPR consent banner
 * (front-end), scheduled-reports cron, white-label admin rebrand + client-mode,
 * roles/migration (no runtime hooks — persisted state). All boots try/catch.
 */
function wpultra_load_ops_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    $disabled = wpultra_disabled_categories();
    if (!in_array('compliance', $disabled, true)) {
        $gp = WPULTRA_DIR . 'includes/compliance/gdpr.php';
        if (is_readable($gp)) { require_once $gp; if (function_exists('wpultra_gdpr_boot')) { try { wpultra_gdpr_boot(); } catch (\Throwable $e) {} } }
    }
    if (!in_array('diagnostics', $disabled, true)) {
        $rp = WPULTRA_DIR . 'includes/system/reports.php';
        if (is_readable($rp)) { require_once $rp; if (function_exists('wpultra_reports_boot')) { try { wpultra_reports_boot(); } catch (\Throwable $e) {} } }
    }
    if (!in_array('system', $disabled, true)) {
        foreach (['whitelabel' => 'wpultra_wlabel_boot', 'migration' => 'wpultra_migrate_boot'] as $file => $boot) {
            $fp = WPULTRA_DIR . 'includes/system/' . $file . '.php';
            if (is_readable($fp)) { require_once $fp; if (function_exists($boot)) { try { $boot(); } catch (\Throwable $e) {} } }
        }
    }
    if (!in_array('users', $disabled, true)) {
        $rl = WPULTRA_DIR . 'includes/system/roles.php';
        if (is_readable($rl)) { require_once $rl; if (function_exists('wpultra_roles_boot')) { try { wpultra_roles_boot(); } catch (\Throwable $e) {} } }
    }
}

/**
 * Register the AI-native runtimes on EVERY request: the RAG chatbot widget +
 * its public /chat REST endpoint, agent-loop cron, SEO-autopilot cron, and any
 * design/analytics shortcodes. All fire outside the REST/abilities loop.
 */
function wpultra_load_ai_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    $disabled = wpultra_disabled_categories();
    if (!in_array('ai', $disabled, true) || !in_array('seo', $disabled, true)) {
        if (is_readable(WPULTRA_DIR . 'includes/ai/setup.php')) { require_once WPULTRA_DIR . 'includes/ai/setup.php'; }
    }
    if (!in_array('ai', $disabled, true)) {
        foreach (['kb', 'agent', 'visualdiff', 'nlquery', 'designbrief', 'pixeldiff'] as $aif) {
            $aip = WPULTRA_DIR . 'includes/ai/' . $aif . '.php';
            if (is_readable($aip)) { require_once $aip; }
        }
        foreach (['wpultra_kb_boot', 'wpultra_agent_boot', 'wpultra_vdiff_boot', 'wpultra_nlq_boot', 'wpultra_dfb_boot'] as $boot) {
            if (function_exists($boot)) { try { $boot(); } catch (\Throwable $e) { /* an AI hook must never take the site down */ } }
        }
        // The RAG chatbot's public chat endpoint (widget beacons from cached pages can't auth).
        if (function_exists('wpultra_kb_register_routes')) { add_action('rest_api_init', 'wpultra_kb_register_routes'); }
    }
    if (!in_array('seo', $disabled, true)) {
        $sp = WPULTRA_DIR . 'includes/ai/seopilot.php';
        if (is_readable($sp)) { require_once $sp; if (function_exists('wpultra_seopilot_boot')) { try { wpultra_seopilot_boot(); } catch (\Throwable $e) {} } }
    }
}

/**
 * Register the site-safety runtimes on EVERY request: firewall (evaluates +
 * blocks the current request immediately when enabled), health-monitor cron,
 * link-fixer scheduled crawl, off-site backup schedule. Firewall is loaded
 * FIRST and boots first so enforcement happens as early as possible.
 */
function wpultra_load_safety_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    $disabled = wpultra_disabled_categories();
    // Firewall + health monitor live under diagnostics; link-fixer under seo;
    // backup schedule under system. Load each engine only when its category is on.
    if (!in_array('diagnostics', $disabled, true)) {
        foreach (['system/firewall', 'system/health'] as $sf) {
            $sp = WPULTRA_DIR . 'includes/' . $sf . '.php';
            if (is_readable($sp)) { require_once $sp; }
        }
        // Firewall boots FIRST — registers an init-prio-1 evaluator that may
        // block-and-die the request when mode is log/block; that is the point.
        if (function_exists('wpultra_firewall_boot')) {
            try { wpultra_firewall_boot(); } catch (\Throwable $e) { /* firewall fails OPEN — never take the site down */ }
        }
        if (function_exists('wpultra_health_boot')) {
            try { wpultra_health_boot(); } catch (\Throwable $e) {}
        }
    }
    if (!in_array('seo', $disabled, true)) {
        $lf = WPULTRA_DIR . 'includes/system/linkfix.php';
        if (is_readable($lf)) { require_once $lf; if (function_exists('wpultra_linkfix_boot')) { try { wpultra_linkfix_boot(); } catch (\Throwable $e) {} } }
    }
    if (!in_array('system', $disabled, true)) {
        $bs = WPULTRA_DIR . 'includes/system/backup-schedule.php';
        if (is_readable($bs)) { require_once $bs; if (function_exists('wpultra_bksched_boot')) { try { wpultra_bksched_boot(); } catch (\Throwable $e) {} } }
    }
}

/**
 * Register the store-power runtimes on EVERY request: dynamic-pricing cart
 * hooks, shipped status + email tracking info, review-photo/Q&A shortcodes,
 * wishlist + back-in-stock front-end, loyalty point earning, currency switch.
 */
function wpultra_load_woopower_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('woocommerce', wpultra_disabled_categories(), true)) { return; }
    foreach (['pricing', 'fulfillment', 'reviews-engine', 'wishlist', 'loyalty', 'currency'] as $wpf) {
        $wpp = WPULTRA_DIR . 'includes/woocommerce/' . $wpf . '.php';
        if (is_readable($wpp)) { require_once $wpp; }
    }
    foreach (['wpultra_pricing_boot', 'wpultra_fulfill_boot', 'wpultra_reviews_engine_boot', 'wpultra_wishlist_boot', 'wpultra_loyalty_boot', 'wpultra_currency_boot'] as $boot) {
        if (function_exists($boot)) {
            try { $boot(); } catch (\Throwable $e) { /* a broken store hook must never take the site down */ }
        }
    }
}

/**
 * Register the business-verticals runtimes on EVERY request: booking, membership,
 * LMS, events, directory, donations. Their engines register CPTs on init (needed
 * on the front-end + cron, outside the REST/abilities loop) and wire crons
 * (booking reminders, donation recurring) + enforcement filters (membership
 * paywall). All boots are cheap + try/catch-wrapped (a vertical must never take
 * the site down).
 */
function wpultra_load_verticals_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('verticals', wpultra_disabled_categories(), true)) { return; }
    foreach (['booking', 'membership', 'lms', 'events', 'directory', 'donations'] as $vf) {
        $vp = WPULTRA_DIR . 'includes/verticals/' . $vf . '.php';
        if (is_readable($vp)) { require_once $vp; }
    }
    foreach (['wpultra_booking_boot', 'wpultra_member_boot', 'wpultra_lms_boot', 'wpultra_event_boot', 'wpultra_dir_boot', 'wpultra_donate_boot'] as $boot) {
        if (function_exists($boot)) { try { $boot(); } catch (\Throwable $e) { /* a broken vertical must never take the site down */ } }
    }
}

/**
 * Register the content-reach cron runtimes on EVERY request: autotranslate
 * batch tick (multilingual) and RSS feed-import poll (content). Their cron
 * handlers fire outside the REST/abilities loop, so the engines + boots must
 * always be present. Both boots are cheap + no-op until a job/feed is active.
 */
function wpultra_load_contentreach_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    $disabled = wpultra_disabled_categories();
    if (!in_array('multilingual', $disabled, true)) {
        foreach (['i18n/engine', 'i18n/autotranslate'] as $af) {
            $ap = WPULTRA_DIR . 'includes/' . $af . '.php';
            if (is_readable($ap)) { require_once $ap; }
        }
        if (function_exists('wpultra_atrans_boot')) { try { wpultra_atrans_boot(); } catch (\Throwable $e) {} }
    }
    if (!in_array('content', $disabled, true)) {
        $fp = WPULTRA_DIR . 'includes/content/feed-import.php';
        if (is_readable($fp)) { require_once $fp; if (function_exists('wpultra_feed_boot')) { try { wpultra_feed_boot(); } catch (\Throwable $e) {} } }
    }
}

/**
 * Register the marketing runtimes on EVERY request: campaign cron sender,
 * A/B assignment + title/content filters, lead form-capture, popup renderer,
 * affiliate ?ref= cookie + Woo order attribution, and the shared /track REST
 * endpoint. All fire outside the REST/abilities loop.
 */
function wpultra_load_marketing_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('marketing', wpultra_disabled_categories(), true)) { return; }
    foreach (['campaigns', 'ab', 'leads', 'popups', 'affiliates', 'track'] as $mkf) {
        $mkp = WPULTRA_DIR . 'includes/marketing/' . $mkf . '.php';
        if (is_readable($mkp)) { require_once $mkp; }
    }
    // social-scheduler (Group D6) is a marketing-category engine with a cron tick.
    $ss = WPULTRA_DIR . 'includes/marketing/social-scheduler.php';
    if (is_readable($ss)) { require_once $ss; }
    foreach (['wpultra_campaigns_boot', 'wpultra_ab_boot', 'wpultra_leads_boot', 'wpultra_popups_boot', 'wpultra_affiliates_boot', 'wpultra_social_boot'] as $boot) {
        if (function_exists($boot)) {
            try { $boot(); } catch (\Throwable $e) { /* a broken marketing hook must never take the site down */ }
        }
    }
    if (function_exists('wpultra_track_register_routes')) {
        add_action('rest_api_init', 'wpultra_track_register_routes');
    }
}

/**
 * Register the monitor runtimes on EVERY request: login tracking (activity),
 * fatal-error reports (self-healing v2), the 404 monitor + IndexNow auto-ping.
 * All fire outside the REST/abilities loop, so they must always be present.
 */
function wpultra_load_monitors_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    $disabled = wpultra_disabled_categories();
    if (!in_array('diagnostics', $disabled, true)) {
        foreach (['system/activity', 'system/errors', 'system/jserrors'] as $mf) {
            $mp = WPULTRA_DIR . 'includes/' . $mf . '.php';
            if (is_readable($mp)) { require_once $mp; }
        }
        if (function_exists('wpultra_activity_boot')) { wpultra_activity_boot(); }
        if (function_exists('wpultra_errors_boot')) { wpultra_errors_boot(); }
        // js-error-log (BF2.4): enqueue the front-end error beacon snippet + register the beacon route.
        if (function_exists('wpultra_jserrors_boot')) { wpultra_jserrors_boot(); }
        $sp2 = WPULTRA_DIR . 'includes/system/security.php';
        if (is_readable($sp2)) { require_once $sp2; if (function_exists('wpultra_security_boot')) { wpultra_security_boot(); } }
    }
    if (!in_array('system', $disabled, true)) {
        $op = WPULTRA_DIR . 'includes/system/optimize.php';
        if (is_readable($op)) { require_once $op; if (function_exists('wpultra_optimize_lazyload_filter') && get_option('wpultra_perf_lazyload')) { add_filter('wp_get_attachment_image_attributes', 'wpultra_optimize_lazyload_filter'); } }
    }
    if (!in_array('seo', $disabled, true)) {
        // monitor.php self-registers its hooks (transition_post_status auto-ping
        // + template_redirect 404 logger) at file level — requiring it is enough.
        $sp = WPULTRA_DIR . 'includes/seo/monitor.php';
        if (is_readable($sp)) { require_once $sp; }
    }
}

/**
 * Register generated custom atomic widgets with Elementor on EVERY request —
 * the editor and front-end render paths run outside the REST/abilities loop.
 * Crash-guarded: a widget file that fatals is quarantined and skipped next time.
 */
function wpultra_load_widgets_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('elementor', wpultra_disabled_categories(), true)) { return; }
    $wf = WPULTRA_DIR . 'includes/elementor/widgets.php';
    if (!is_readable($wf)) { return; }
    require_once $wf;
    register_shutdown_function('wpultra_widgets_shutdown_check');
    add_action('elementor/widgets/register', 'wpultra_widgets_register_all');
    add_action('wp_enqueue_scripts', 'wpultra_widgets_enqueue_styles');
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
 * Register the headless runtime on EVERY request: the JWT-secret filter must be
 * present when WPGraphQL-JWT parses tokens on front-end /graphql requests, and
 * the CORS headers attach to GraphQL responses outside the REST/abilities loop.
 */
function wpultra_load_headless_runtime(): void {
    if (!wpultra_is_enabled()) { return; }
    if (in_array('headless', wpultra_disabled_categories(), true)) { return; }
    foreach (['setup', 'expose', 'rest', 'preview', 'revalidate', 'seo'] as $hf) {
        $hp = WPULTRA_DIR . 'includes/headless/' . $hf . '.php';
        if (is_readable($hp)) { require_once $hp; }
    }
    if (function_exists('wpultra_headless_boot')) {
        try { wpultra_headless_boot(); } catch (\Throwable $e) { /* a headless hook must never take the site down */ }
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
