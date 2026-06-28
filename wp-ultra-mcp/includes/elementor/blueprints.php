<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/** Built-in structural section skeletons. Every node id is the placeholder 'bp' (reid replaces). */
function wpultra_el_blueprints(): array {
    $fx = fn(array $children) => ['id' => 'bp', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => $children];
    $h  = fn(string $t, string $tag = 'h2') => ['id' => 'bp', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['tag' => $tag, 'title' => $t], 'elements' => []];
    $pr = fn(string $t) => ['id' => 'bp', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => ['paragraph' => $t], 'elements' => []];
    $bt = fn(string $t) => ['id' => 'bp', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => ['text' => $t], 'elements' => []];
    return [
        'navbar' => [
            'description' => 'Top navigation bar: brand heading, link group, and a call-to-action button.',
            'summary' => 'e-flexbox row > [heading(brand), flexbox(3 paragraphs), button]',
            'tree' => [$fx([
                $h('Brand', 'h3'),
                $fx([$pr('Home'), $pr('About'), $pr('Contact')]),
                $bt('Get Started'),
            ])],
        ],
        'hero' => [
            'description' => 'Hero section: headline, supporting subhead, and a CTA button.',
            'summary' => 'e-flexbox column > [heading(h1), paragraph, button]',
            'tree' => [$fx([
                $h('Your headline goes here', 'h1'),
                $pr('A short supporting sentence that explains the value.'),
                $bt('Get Started'),
            ])],
        ],
        'feature-grid' => [
            'description' => 'Three-column feature grid, each column a heading + description.',
            'summary' => 'e-flexbox row > 3x [ flexbox column > [heading, paragraph] ]',
            'tree' => [$fx([
                $fx([$h('Feature one'), $pr('Describe the first feature here.')]),
                $fx([$h('Feature two'), $pr('Describe the second feature here.')]),
                $fx([$h('Feature three'), $pr('Describe the third feature here.')]),
            ])],
        ],
        'cta' => [
            'description' => 'Call-to-action band: a heading and a button.',
            'summary' => 'e-flexbox > [heading, button]',
            'tree' => [$fx([
                $h('Ready to get started?'),
                $bt('Sign up'),
            ])],
        ],
        'footer' => [
            'description' => 'Footer with three link columns.',
            'summary' => 'e-flexbox row > 3x [ flexbox column > [heading, 2 paragraphs] ]',
            'tree' => [$fx([
                $fx([$h('Product', 'h4'), $pr('Features'), $pr('Pricing')]),
                $fx([$h('Company', 'h4'), $pr('About'), $pr('Careers')]),
                $fx([$h('Legal', 'h4'), $pr('Privacy'), $pr('Terms')]),
            ])],
        ],
    ];
}

/** Replace every node id in $tree with a fresh unique id, avoiding ids in $existing and ids assigned so far. */
function wpultra_el_blueprint_reid(array $tree, array $existing = []): array {
    $seed = $existing;
    $walk = function (array $nodes) use (&$walk, &$seed): array {
        $out = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) { continue; }
            $id = wpultra_el_new_id($seed);
            $n['id'] = $id;
            $seed[] = ['id' => $id, 'elType' => 'marker', 'elements' => []]; // reserve so later ids differ
            if (!empty($n['elements']) && is_array($n['elements'])) {
                $n['elements'] = $walk($n['elements']);
            }
            $out[] = $n;
        }
        return $out;
    };
    return $walk($tree);
}
