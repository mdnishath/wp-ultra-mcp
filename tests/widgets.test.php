<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', sys_get_temp_dir()); }
require __DIR__ . '/../wp-ultra-mcp/includes/elementor/widgets.php';

function widget_props_fixture(): array {
    return [
        ['name' => 'heading',  'type' => 'string',  'label' => 'Heading', 'default' => "It's live"],
        ['name' => 'body',     'type' => 'textarea'],
        ['name' => 'rich',     'type' => 'html', 'default' => 'Hello <b>world</b>'],
        ['name' => 'count',    'type' => 'number', 'default' => 3],
        ['name' => 'featured', 'type' => 'boolean', 'default' => true],
        ['name' => 'layout',   'type' => 'select', 'options' => [['value' => 'grid', 'label' => 'Grid'], ['value' => 'list', 'label' => 'List']]],
        ['name' => 'photo',    'type' => 'image'],
        ['name' => 'cta',      'type' => 'link'],
    ];
}

it('name validation + class name derivation', function () {
    assert_true(wpultra_widget_valid_name('price-card'));
    assert_true(!wpultra_widget_valid_name('PriceCard'));
    assert_true(!wpultra_widget_valid_name('9lives'));
    assert_true(!wpultra_widget_valid_name('a'));
    assert_eq('Price_Card', wpultra_widget_class_name('price-card'));
    assert_eq('Before_After', wpultra_widget_class_name('before-after'));
});

it('props validation catches bad shapes', function () {
    assert_eq(true, wpultra_widget_validate_props(widget_props_fixture()));
    assert_true(is_string(wpultra_widget_validate_props([])));
    assert_true(is_string(wpultra_widget_validate_props([['name' => 'BadName', 'type' => 'string']])));
    assert_true(is_string(wpultra_widget_validate_props([['name' => 'classes', 'type' => 'string']])));   // reserved
    assert_true(is_string(wpultra_widget_validate_props([['name' => 'x', 'type' => 'nope']])));
    assert_true(is_string(wpultra_widget_validate_props([['name' => 'x', 'type' => 'select']])));          // options required
    assert_true(is_string(wpultra_widget_validate_props([['name' => 'a', 'type' => 'string'], ['name' => 'a', 'type' => 'string']]))); // dup
});

it('schema lines wrap defaults as escaped literals', function () {
    $line = wpultra_widget_prop_schema_line(['name' => 'heading', 'type' => 'string', 'default' => "It's live"]);
    assert_contains("'heading' => String_Prop_Type::make()->default('It\\'s live')", $line);
    $sel = wpultra_widget_prop_schema_line(['name' => 'layout', 'type' => 'select', 'options' => [['value' => 'grid', 'label' => 'Grid']]]);
    assert_contains("->enum(['grid'])", $sel);
    assert_contains("->default('grid')", $sel); // falls back to first option
    $img = wpultra_widget_prop_schema_line(['name' => 'photo', 'type' => 'image']);
    assert_contains('Placeholder_Image::get_placeholder_image()', $img);
});

it('default twig includes classes merge + per-prop snippets + image id guard', function () {
    $twig = wpultra_widget_default_twig('price-card', widget_props_fixture());
    assert_contains("settings.classes | merge( [ 'wpu-price-card' ] )", $twig);
    assert_contains('data-interaction-id="{{ interaction_id }}"', $twig); // render-check + interactions marker
    assert_contains('{% if settings.photo.id %}', $twig);      // unset-image guard uses id, not src
    assert_contains('settings.cta.href', $twig);
    assert_contains('{{ settings.rich | raw }}', $twig);
});

it('generated widget class contains the atomic contract', function () {
    $meta = ['name' => 'price-card', 'title' => "Ali's Card", 'icon' => 'eicon-code', 'class' => 'Price_Card'];
    $php = wpultra_widget_build_class($meta, widget_props_fixture());
    assert_contains('namespace WPUltra\\Widgets;', $php);
    assert_contains('class Price_Card_Widget extends Atomic_Widget_Base', $php);
    assert_contains("return 'wpu-price-card';", $php);
    assert_contains("'classes' => Classes_Prop_Type::make()->default([]),", $php);
    assert_contains('Select_Control::bind_to', $php);
    assert_contains("elementor/widgets/wpu-price-card", $php);
    assert_contains("Ali\\'s Card", $php); // title escaped
});

it('generated PHP passes a REAL php -l lint for every prop type', function () {
    $meta = ['name' => 'lint-check', 'title' => 'Lint Check', 'icon' => 'eicon-code', 'class' => 'Lint_Check'];
    $php = wpultra_widget_build_class($meta, widget_props_fixture());
    $tmp = tempnam(sys_get_temp_dir(), 'wpuw') . '.php';
    file_put_contents($tmp, $php);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    assert_contains('No syntax errors detected', $out);
});

it('suggest_name walks numeric suffixes', function () {
    $taken = ['card-2' => true, 'card-3' => true];
    $s = wpultra_widget_suggest_name('card', static fn($n) => isset($taken[$n]));
    assert_eq('card-4', $s);
});

run_tests();
