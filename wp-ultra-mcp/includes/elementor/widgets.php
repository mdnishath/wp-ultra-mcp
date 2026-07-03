<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Custom atomic widget generator: the AI describes a widget declaratively
 * (name, title, props, optional Twig/CSS) and this engine emits a real
 * Elementor v4 atomic widget — PHP class + Twig template + optional
 * stylesheet — under wp-content/wpultra-widgets/<name>/ and registers it on
 * elementor/widgets/register.
 *
 * Safety model: generated PHP comes ONLY from our own template (props are
 * emitted as escaped literals), never from caller-supplied PHP. The Twig body
 * is rendered by Elementor's sandboxed Twig runtime. Loading is crash-guarded:
 * a widget whose file fatals is marked crashed and skipped on the next request
 * instead of white-screening the site.
 */

const WPULTRA_WIDGETS_DIRNAME  = 'wpultra-widgets';
const WPULTRA_WIDGETS_CRASHED  = 'wpultra_widgets_crashed';
const WPULTRA_WIDGETS_LOADING  = 'wpultra_widgets_loading';

/* ------------------------------------------------------------------ *
 * PURE: naming, validation, code generation.
 * ------------------------------------------------------------------ */

/** Pure: valid widget slug — kebab-case, letter first. */
function wpultra_widget_valid_name(string $name): bool {
    return (bool) preg_match('/^[a-z][a-z0-9-]{1,40}$/', $name);
}

/** Pure: kebab-case → Pascal_Snake class fragment (before-after → Before_After). */
function wpultra_widget_class_name(string $name): string {
    return str_replace(' ', '_', ucwords(str_replace('-', ' ', $name)));
}

/** Pure: prop types this generator understands. */
function wpultra_widget_prop_types(): array {
    return ['string', 'textarea', 'html', 'number', 'boolean', 'select', 'image', 'link'];
}

/**
 * Pure: validate the props array. @return true|string
 */
function wpultra_widget_validate_props($props) {
    if (!is_array($props) || $props === []) { return 'props must be a non-empty array.'; }
    if (count($props) > 30) { return 'A widget may declare at most 30 props.'; }
    $seen = [];
    foreach ($props as $i => $p) {
        if (!is_array($p)) { return "Prop $i must be an object."; }
        $n = (string) ($p['name'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $n)) { return "Prop $i: name '$n' must be snake_case."; }
        if ($n === 'classes') { return "Prop $i: 'classes' is reserved."; }
        if (isset($seen[$n])) { return "Prop $i: duplicate name '$n'."; }
        $seen[$n] = true;
        $t = (string) ($p['type'] ?? '');
        if (!in_array($t, wpultra_widget_prop_types(), true)) {
            return "Prop $i: type '$t' must be one of: " . implode(', ', wpultra_widget_prop_types()) . '.';
        }
        if ($t === 'select' && (empty($p['options']) || !is_array($p['options']))) {
            return "Prop $i: select props require a non-empty options array of {value,label}.";
        }
    }
    return true;
}

/** Pure: single-quoted PHP string literal. */
function wpultra_widget_sq($v): string {
    return "'" . strtr((string) $v, ['\\' => '\\\\', "'" => "\\'"]) . "'";
}

/** Pure: one props_schema() entry line for a prop. */
function wpultra_widget_prop_schema_line(array $p): string {
    $n = (string) $p['name'];
    $d = $p['default'] ?? null;
    switch ((string) $p['type']) {
        case 'string':
        case 'textarea':
            return "            '$n' => String_Prop_Type::make()->default(" . wpultra_widget_sq($d ?? '') . '),';
        case 'html':
            $content = wpultra_widget_sq(($d === null || $d === '') ? 'Enter text here' : $d);
            return "            '$n' => Html_V3_Prop_Type::make()->default(['content' => String_Prop_Type::generate($content), 'children' => []]),";
        case 'number':
            return "            '$n' => Number_Prop_Type::make()->default(" . (string) (float) ($d ?? 0) . '),';
        case 'boolean':
            return "            '$n' => Boolean_Prop_Type::make()->default(" . (($d ?? false) === true ? 'true' : 'false') . '),';
        case 'select':
            $vals = array_map(static fn($o) => wpultra_widget_sq((string) ($o['value'] ?? '')), (array) $p['options']);
            $def  = wpultra_widget_sq($d ?? (string) ($p['options'][0]['value'] ?? ''));
            return "            '$n' => String_Prop_Type::make()->enum([" . implode(', ', $vals) . "])->default($def),";
        case 'image':
            $url = ($d === null || $d === '') ? 'Placeholder_Image::get_placeholder_image()' : wpultra_widget_sq($d);
            return "            '$n' => Image_Prop_Type::make()->default_url($url)->default_size('full'),";
        case 'link':
            return "            '$n' => Link_Prop_Type::make(),";
    }
    return '';
}

/** Pure: one define_atomic_controls() entry line for a prop. */
function wpultra_widget_control_line(array $p): string {
    $n = (string) $p['name'];
    $label = wpultra_widget_sq($p['label'] ?? ucwords(str_replace('_', ' ', $n)));
    $map = [
        'string' => 'Text_Control', 'textarea' => 'Textarea_Control', 'html' => 'Inline_Editing_Control',
        'number' => 'Number_Control', 'boolean' => 'Switch_Control', 'image' => 'Image_Control', 'link' => 'Link_Control',
    ];
    $t = (string) $p['type'];
    if ($t === 'select') {
        $opts = [];
        foreach ((array) $p['options'] as $o) {
            $opts[] = "['value' => " . wpultra_widget_sq((string) ($o['value'] ?? '')) . ", 'label' => " . wpultra_widget_sq((string) ($o['label'] ?? $o['value'] ?? '')) . ']';
        }
        return "                    Select_Control::bind_to('$n')->set_options([" . implode(', ', $opts) . "])->set_label($label),";
    }
    if (!isset($map[$t])) { return ''; }
    return "                    {$map[$t]}::bind_to('$n')->set_label($label),";
}

/** Pure: the use-statements block needed for a prop set. */
function wpultra_widget_use_block(array $props): string {
    $uses = [
        'use Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base;',
        'use Elementor\Modules\AtomicWidgets\Elements\Base\Has_Template;',
        'use Elementor\Modules\AtomicWidgets\Controls\Section;',
        'use Elementor\Modules\AtomicWidgets\PropTypes\Classes_Prop_Type;',
        'use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type;',
    ];
    $per_type = [
        'string'   => ['use Elementor\Modules\AtomicWidgets\Controls\Types\Text_Control;'],
        'textarea' => ['use Elementor\Modules\AtomicWidgets\Controls\Types\Textarea_Control;'],
        'html'     => ['use Elementor\Modules\AtomicWidgets\PropTypes\Html_V3_Prop_Type;', 'use Elementor\Modules\AtomicWidgets\Controls\Types\Inline_Editing_Control;'],
        'number'   => ['use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Number_Prop_Type;', 'use Elementor\Modules\AtomicWidgets\Controls\Types\Number_Control;'],
        'boolean'  => ['use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Boolean_Prop_Type;', 'use Elementor\Modules\AtomicWidgets\Controls\Types\Switch_Control;'],
        'select'   => ['use Elementor\Modules\AtomicWidgets\Controls\Types\Select_Control;'],
        'image'    => ['use Elementor\Modules\AtomicWidgets\PropTypes\Image_Prop_Type;', 'use Elementor\Modules\AtomicWidgets\Controls\Types\Image_Control;', 'use Elementor\Modules\AtomicWidgets\Utils\Image\Placeholder_Image;'],
        'link'     => ['use Elementor\Modules\AtomicWidgets\PropTypes\Link_Prop_Type;', 'use Elementor\Modules\AtomicWidgets\Controls\Types\Link_Control;'],
    ];
    foreach ($props as $p) {
        foreach ($per_type[(string) ($p['type'] ?? '')] ?? [] as $u) { $uses[] = $u; }
    }
    return implode("\n", array_values(array_unique($uses)));
}

/** Pure: default Twig snippet per prop (used when the caller supplies no template). */
function wpultra_widget_twig_snippet(array $p): string {
    $n = 'settings.' . (string) $p['name'];
    switch ((string) $p['type']) {
        case 'string':   return "    {% if $n is not empty %}<span>{{ $n }}</span>{% endif %}";
        case 'textarea': return "    {% if $n is not empty %}<p>{{ $n }}</p>{% endif %}";
        case 'html':     return "    {% if $n is not empty %}<div>{{ $n | raw }}</div>{% endif %}";
        case 'number':
        case 'select':   return "    <span>{{ $n }}</span>";
        case 'image':    return "    {% if {$n}.id %}<img src=\"{{ {$n}.src | e('full_url') }}\" alt=\"\" />{% endif %}";
        case 'link':     return "    {% if {$n}.href %}<a href=\"{{ {$n}.href }}\" target=\"{{ {$n}.target }}\">Link</a>{% endif %}";
        case 'boolean':  return '';
    }
    return '';
}

/**
 * Pure: build a default Twig template for a prop set. Root carries classes,
 * the widget's own style class, and data-interaction-id — the marker Elementor
 * uses to identify rendered elements (render-check + interactions need it).
 */
function wpultra_widget_default_twig(string $name, array $props): string {
    $lines = [
        "{% set classes = settings.classes | merge( [ 'wpu-$name' ] ) | join(' ') %}",
        '<div class="{{ classes }}" data-interaction-id="{{ interaction_id }}">',
    ];
    foreach ($props as $p) {
        $s = wpultra_widget_twig_snippet($p);
        if ($s !== '') { $lines[] = $s; }
    }
    $lines[] = '</div>';
    return implode("\n", $lines);
}

/**
 * Pure: build the full widget class PHP source.
 * @param array{name:string,title:string,icon:string,class:string} $meta
 */
function wpultra_widget_build_class(array $meta, array $props): string {
    $name  = $meta['name'];
    $title = addslashes($meta['title']);
    $icon  = addslashes($meta['icon']);
    $class = $meta['class'];
    $uses  = wpultra_widget_use_block($props);

    $schema_lines = ["            'classes' => Classes_Prop_Type::make()->default([]),"];
    $control_lines = [];
    foreach ($props as $p) {
        $s = wpultra_widget_prop_schema_line($p);
        if ($s !== '') { $schema_lines[] = $s; }
        $c = wpultra_widget_control_line($p);
        if ($c !== '') { $control_lines[] = $c; }
    }
    $schema = implode("\n", $schema_lines);
    $controls = implode("\n", $control_lines);

    return "<?php\n"
        . "// Generated by WP-Ultra-MCP create-atomic-widget. Regenerate via the ability; do not hand-edit.\n"
        . "namespace WPUltra\\Widgets;\n\n"
        . $uses . "\n\n"
        . "if (!defined('ABSPATH')) { exit; }\n\n"
        . "class {$class}_Widget extends Atomic_Widget_Base {\n"
        . "    use Has_Template;\n\n"
        . "    public static function get_element_type(): string {\n"
        . "        return 'wpu-{$name}';\n"
        . "    }\n\n"
        . "    public function get_title(): string {\n"
        . "        return '{$title}';\n"
        . "    }\n\n"
        . "    public function get_icon(): string {\n"
        . "        return '{$icon}';\n"
        . "    }\n\n"
        . "    public function get_keywords(): array {\n"
        . "        return ['wpultra', 'custom'];\n"
        . "    }\n\n"
        . "    protected static function define_props_schema(): array {\n"
        . "        return [\n{$schema}\n        ];\n"
        . "    }\n\n"
        . "    protected function define_atomic_controls(): array {\n"
        . "        return [\n"
        . "            Section::make()\n"
        . "                ->set_label('Content')\n"
        . "                ->set_items([\n{$controls}\n                ]),\n"
        . "        ];\n"
        . "    }\n\n"
        . "    protected function get_templates(): array {\n"
        . "        return [\n"
        . "            'elementor/widgets/wpu-{$name}' => __DIR__ . '/templates/{$name}.html.twig',\n"
        . "        ];\n"
        . "    }\n"
        . "}\n";
}

/** Pure: suggest a non-colliding name. */
function wpultra_widget_suggest_name(string $base, callable $exists): string {
    for ($i = 2; $i < 100; $i++) {
        if (!$exists("$base-$i")) { return "$base-$i"; }
    }
    return $base . '-x' . substr(md5($base . microtime()), 0, 6);
}

/* ------------------------------------------------------------------ *
 * Store (files under wp-content/wpultra-widgets/).
 * ------------------------------------------------------------------ */

function wpultra_widgets_dir(): string {
    return trailingslashit(WP_CONTENT_DIR) . WPULTRA_WIDGETS_DIRNAME;
}

/** Ensure base dir exists with an index.php sentinel. @return true|WP_Error */
function wpultra_widgets_ensure_dir() {
    $dir = wpultra_widgets_dir();
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return wpultra_err('mkdir_failed', "Cannot create $dir.");
    }
    if (!file_exists($dir . '/index.php')) { @file_put_contents($dir . '/index.php', "<?php // Silence.\n"); }
    return true;
}

/** All widgets on disk: name => dir. */
function wpultra_widgets_all(): array {
    $out = [];
    $base = wpultra_widgets_dir();
    if (!is_dir($base)) { return $out; }
    foreach ((array) scandir($base) as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $dir = $base . '/' . $entry;
        if (is_dir($dir) && is_readable($dir . '/widget.php')) { $out[$entry] = $dir; }
    }
    return $out;
}

/** Crashed-widget map (name => error string). */
function wpultra_widgets_crashed(): array {
    $v = get_option(WPULTRA_WIDGETS_CRASHED, []);
    return is_array($v) ? $v : [];
}

/**
 * Write the widget's files. @return array|WP_Error {files: string[]}
 */
function wpultra_widget_write(string $name, string $class_php, string $twig, string $css) {
    $ok = wpultra_widgets_ensure_dir();
    if (is_wp_error($ok)) { return $ok; }
    $dir = wpultra_widgets_dir() . '/' . $name;
    foreach ([$dir, $dir . '/templates'] as $d) {
        if (!is_dir($d) && !wp_mkdir_p($d)) { return wpultra_err('mkdir_failed', "Cannot create $d."); }
    }
    $files = [
        $dir . '/widget.php' => $class_php,
        $dir . "/templates/{$name}.html.twig" => $twig,
    ];
    if ($css !== '') {
        if (!is_dir($dir . '/assets') && !wp_mkdir_p($dir . '/assets')) { return wpultra_err('mkdir_failed', "Cannot create $dir/assets."); }
        $files[$dir . "/assets/{$name}.css"] = $css;
    }
    $written = [];
    foreach ($files as $path => $content) {
        if (@file_put_contents($path, $content) === false) {
            return wpultra_err('write_failed', "Could not write $path.", ['written' => $written]);
        }
        $written[] = $path;
    }
    // A regenerated widget gets a fresh chance even if a previous version crashed.
    $crashed = wpultra_widgets_crashed();
    if (isset($crashed[$name])) { unset($crashed[$name]); update_option(WPULTRA_WIDGETS_CRASHED, $crashed, false); }
    return ['files' => $written];
}

/** Delete a widget's directory recursively. */
function wpultra_widget_delete_files(string $name): bool {
    $dir = wpultra_widgets_dir() . '/' . $name;
    if (!is_dir($dir)) { return false; }
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
    $crashed = wpultra_widgets_crashed();
    if (isset($crashed[$name])) { unset($crashed[$name]); update_option(WPULTRA_WIDGETS_CRASHED, $crashed, false); }
    return true;
}

/* ------------------------------------------------------------------ *
 * Crash-guarded loader + registration (always-on runtime).
 * ------------------------------------------------------------------ */

/**
 * Register all generated widgets with Elementor. A widget whose file fatals is
 * recorded in the crashed map (via the loading-marker + shutdown check) and
 * skipped on subsequent requests, so one bad widget can't take the site down
 * twice.
 */
function wpultra_widgets_register_all($widgets_manager): void {
    $crashed = wpultra_widgets_crashed();
    foreach (wpultra_widgets_all() as $name => $dir) {
        if (isset($crashed[$name])) { continue; }
        update_option(WPULTRA_WIDGETS_LOADING, $name, false);
        try {
            require_once $dir . '/widget.php';
            $fqcn = 'WPUltra\\Widgets\\' . wpultra_widget_class_name($name) . '_Widget';
            if (class_exists($fqcn)) { $widgets_manager->register(new $fqcn()); }
        } catch (\Throwable $e) {
            $crashed[$name] = $e->getMessage();
            update_option(WPULTRA_WIDGETS_CRASHED, $crashed, false);
        }
        delete_option(WPULTRA_WIDGETS_LOADING);
    }
}

/** Shutdown hook: if a fatal killed the request mid-require, mark that widget crashed. */
function wpultra_widgets_shutdown_check(): void {
    $loading = get_option(WPULTRA_WIDGETS_LOADING, '');
    if (!is_string($loading) || $loading === '') { return; }
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        $crashed = wpultra_widgets_crashed();
        $crashed[$loading] = (string) $err['message'];
        update_option(WPULTRA_WIDGETS_CRASHED, $crashed, false);
    }
    delete_option(WPULTRA_WIDGETS_LOADING);
}

/** Enqueue each widget's stylesheet when it exists. */
function wpultra_widgets_enqueue_styles(): void {
    foreach (wpultra_widgets_all() as $name => $dir) {
        $css = $dir . "/assets/{$name}.css";
        if (!is_readable($css)) { continue; }
        wp_enqueue_style(
            "wpultra-widget-{$name}",
            content_url(WPULTRA_WIDGETS_DIRNAME . "/{$name}/assets/{$name}.css"),
            [],
            (string) @filemtime($css)
        );
    }
}
