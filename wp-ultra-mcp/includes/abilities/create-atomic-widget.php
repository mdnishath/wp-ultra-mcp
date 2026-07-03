<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/create-atomic-widget', [
    'label'       => __('Create Atomic Widget', 'wp-ultra-mcp'),
    'description' => __('Generate a real custom Elementor v4 ATOMIC widget from a declarative spec — PHP class + Twig template (+ optional stylesheet) written under wp-content/wpultra-widgets/ and registered on the next request as element type "wpu-<name>". Props (max 30): {name (snake_case), type: string|textarea|html|number|boolean|select|image|link, label?, default?, options? (select)}. Optional `twig` replaces the generated template — inside it: scalars as {{ settings.foo }}, image as {{ settings.img.src }} (check {% if settings.img.id %} for unset — src always falls back to a placeholder), link as {{ settings.cta.href }}/{{ settings.cta.target }}; the ROOT element must keep the generated classes line AND carry data-interaction-id="{{ interaction_id }}" (without it, elementor-render-check reports the widget as dropped and interactions cannot bind). Optional `css` is enqueued once per page; scope selectors to .wpu-<name>. NO PHP or <script> is accepted — code is generated only from this plugin\'s own template, and a widget file that fatals is auto-quarantined (see list-atomic-widgets status) instead of white-screening the site. After creating: verify with elementor-list-widgets (atomic_only), then place via elementor-add-element with widget type "wpu-<name>".', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'name'      => ['type' => 'string', 'description' => 'kebab-case slug, e.g. "price-card".'],
            'title'     => ['type' => 'string'],
            'icon'      => ['type' => 'string', 'description' => 'eicon class, default eicon-code.'],
            'props'     => ['type' => 'array'],
            'twig'      => ['type' => 'string'],
            'css'       => ['type' => 'string'],
            'overwrite' => ['type' => 'boolean', 'description' => 'Regenerate an existing widget (destroys prior files).'],
        ],
        'required'             => ['name', 'title', 'props'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'        => ['type' => 'boolean'],
            'name'           => ['type' => 'string'],
            'element_type'   => ['type' => 'string'],
            'files'          => ['type' => 'array'],
            'suggested_name' => ['type' => 'string'],
            'note'           => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_create_atomic_widget_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_create_atomic_widget_cb(array $input) {
    if (!class_exists('\\Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Atomic_Widget_Base')) {
        return wpultra_err('atomic_unavailable', 'Elementor with the atomic-widgets (v4) runtime is required to host generated widgets.');
    }
    $name = (string) ($input['name'] ?? '');
    if (!wpultra_widget_valid_name($name)) {
        return wpultra_err('bad_name', 'name must be kebab-case (letter first, letters/digits/dashes, ≤41 chars).');
    }
    $valid = wpultra_widget_validate_props($input['props'] ?? null);
    if ($valid !== true) { return wpultra_err('bad_props', (string) $valid); }
    $props = array_values((array) $input['props']);

    $exists = is_dir(wpultra_widgets_dir() . '/' . $name);
    if ($exists && ($input['overwrite'] ?? false) !== true) {
        $suggest = wpultra_widget_suggest_name($name, static fn($n) => is_dir(wpultra_widgets_dir() . '/' . $n));
        return wpultra_err('widget_exists', "Widget '$name' already exists. Pass overwrite:true to regenerate, or use a new name (suggested: $suggest).", ['suggested_name' => $suggest]);
    }

    $meta = [
        'name'  => $name,
        'title' => (string) ($input['title'] ?? $name),
        'icon'  => (string) ($input['icon'] ?? 'eicon-code'),
        'class' => wpultra_widget_class_name($name),
    ];
    $twig = trim((string) ($input['twig'] ?? ''));
    if ($twig === '') { $twig = wpultra_widget_default_twig($name, $props); }
    if (stripos($twig, '<script') !== false) { return wpultra_err('twig_script', 'The Twig template may not contain <script> tags.'); }
    if (stripos($twig, '<?') !== false) { return wpultra_err('twig_php', 'The Twig template may not contain PHP.'); }
    $css = (string) ($input['css'] ?? '');

    $class_php = wpultra_widget_build_class($meta, $props);
    $res = wpultra_widget_write($name, $class_php, $twig, $css);
    if (is_wp_error($res)) { return $res; }

    wpultra_audit_log('create-atomic-widget', ($exists ? 'regenerated' : 'created') . " widget wpu-$name (" . count($props) . ' props)', true);
    return wpultra_ok([
        'name'         => $name,
        'element_type' => "wpu-$name",
        'files'        => $res['files'],
        'note'         => 'Registered on the NEXT request. Verify via elementor-list-widgets, then place with elementor-add-element widget type "wpu-' . $name . '".',
    ]);
}
