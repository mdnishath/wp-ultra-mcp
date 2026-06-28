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
        'execute-wp-query', 'read-debug-log',
        // memory (Wave 1, Task 13)
        'memory-save', 'memory-get', 'memory-list', 'memory-delete',
        // wp content (Wave 1, Task 14)
        'create-post', 'update-post', 'delete-post',
        // skills
        'skill-get', 'skill-write', 'skill-edit', 'skill-delete',
        // recipe management (Wave 1.5, Task 5)
        'ability-write', 'ability-get', 'ability-delete',
        // elementor read abilities (Wave 2, Task 6)
        'elementor-list-widgets', 'elementor-get-widget-schema', 'elementor-get-style-schema', 'elementor-get-content',
        // elementor mutation abilities (Wave 2, Task 7)
        'elementor-set-content', 'elementor-add-element', 'elementor-edit-element', 'elementor-delete-element', 'elementor-move-element',
        // elementor design read abilities (Wave 3, Task 2)
        'elementor-get-design-system', 'elementor-list-dynamic-tags',
        // elementor design write abilities (Wave 3, Task 3)
        'elementor-manage-global-colors', 'elementor-manage-variables',
    ];
    // NOTE: gutenberg-*, bricks-*, and field-plugin abilities are added by later waves.
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
        'skills' => 'Reusable AI skill documents.',
        'memory'  => 'Persistent cross-session memory.',
        'content' => 'WordPress posts, pages, and CPTs.',
        'custom'  => 'User-defined declarative abilities.',
    ];
    foreach ($cats as $slug => $desc) {
        wp_register_ability_category($slug, ['label' => $slug, 'description' => __($desc, 'wp-ultra-mcp')]);
    }
}

function wpultra_load_abilities(): void {
    if (!wpultra_is_enabled()) { return; }
    // Load the Elementor engine so ability callbacks can reference its functions.
    foreach (['setup', 'schema', 'tree', 'engine', 'coerce', 'design'] as $elf) {
        $elp = WPULTRA_DIR . 'includes/elementor/' . $elf . '.php';
        if (is_readable($elp)) { require_once $elp; }
    }
    foreach (wpultra_ability_files() as $file) {
        $path = WPULTRA_DIR . 'includes/abilities/' . $file . '.php';
        if (is_readable($path)) { require_once $path; }
    }
    if (is_readable(WPULTRA_DIR . 'includes/memory/cpt.php')) { require_once WPULTRA_DIR . 'includes/memory/cpt.php'; }
    // Skills subsystem (CPT + catalog + per-skill prompts) registers its own abilities/prompts.
    if (is_readable(WPULTRA_DIR . 'includes/skills/cpt.php')) {
        require_once WPULTRA_DIR . 'includes/skills/cpt.php';
        require_once WPULTRA_DIR . 'includes/skills/sources.php';
        require_once WPULTRA_DIR . 'includes/skills/catalog.php';
        require_once WPULTRA_DIR . 'includes/skills/prompts.php';
    }
    if (is_readable(WPULTRA_DIR . 'includes/recipes/cpt.php')) {
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
