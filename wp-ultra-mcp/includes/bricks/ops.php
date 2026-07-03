<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Bricks deep ops: element-level mutations on the FLAT structure Bricks stores
 * (every element carries BOTH a `parent` pointer and a `children` id list —
 * all ops must keep the two views consistent), plus global classes and
 * structural blueprints. Mirrors the Elementor reliability arc.
 */

const WPULTRA_BRICKS_CLASSES_OPTION = 'bricks_global_classes';

/* ------------------------------------------------------------------ *
 * PURE: ids, indexing, consistency.
 * ------------------------------------------------------------------ */

/** Pure-ish: fresh 6-char Bricks-style id avoiding $taken. */
function wpultra_bricks_new_id(array $taken = []): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $id = '';
        for ($i = 0; $i < 6; $i++) { $id .= $alphabet[random_int(0, 35)]; }
    } while (isset($taken[$id]) || in_array($id, $taken, true));
    return $id;
}

/** Pure: id => array-index map. */
function wpultra_bricks_index(array $elements): array {
    $map = [];
    foreach ($elements as $i => $el) {
        $id = (string) ($el['id'] ?? '');
        if ($id !== '') { $map[$id] = $i; }
    }
    return $map;
}

/** Pure: is $needle inside the subtree rooted at $root_id (incl. itself)? */
function wpultra_bricks_in_subtree(array $elements, string $root_id, string $needle): bool {
    if ($root_id === $needle) { return true; }
    $idx = wpultra_bricks_index($elements);
    $queue = [(string) $root_id];
    $seen = [];
    while ($queue) {
        $cur = array_shift($queue);
        if (isset($seen[$cur])) { continue; }
        $seen[$cur] = true;
        if (!isset($idx[$cur])) { continue; }
        foreach ((array) ($elements[$idx[$cur]]['children'] ?? []) as $child) {
            if ((string) $child === $needle) { return true; }
            $queue[] = (string) $child;
        }
    }
    return false;
}

/**
 * Pure: DEEP consistency check beyond the foundation validator: parent pointers
 * and children lists must agree in both directions. @return true|string
 */
function wpultra_bricks_consistency(array $elements) {
    $idx = wpultra_bricks_index($elements);
    foreach ($elements as $el) {
        $id = (string) ($el['id'] ?? '');
        $parent = (string) ($el['parent'] ?? '0');
        if ($parent !== '0' && $parent !== '') {
            if (!isset($idx[$parent])) { return "Element $id points at missing parent $parent."; }
            $pkids = array_map('strval', (array) ($elements[$idx[$parent]]['children'] ?? []));
            if (!in_array($id, $pkids, true)) { return "Element $id has parent $parent but is missing from its children list."; }
        }
        foreach ((array) ($el['children'] ?? []) as $child) {
            $child = (string) $child;
            if (!isset($idx[$child])) { return "Element $id lists missing child $child."; }
            $cparent = (string) ($elements[$idx[$child]]['parent'] ?? '0');
            if ($cparent !== $id) { return "Element $child is listed as a child of $id but its parent field says '$cparent'."; }
        }
    }
    return true;
}

/* ------------------------------------------------------------------ *
 * PURE: tree mutations (each returns the new flat array or an error string).
 * ------------------------------------------------------------------ */

/** Pure: insert $node under $parent_id ('0' = root) at $pos. Node id must be fresh. */
function wpultra_bricks_op_insert(array $elements, array $node, string $parent_id, int $pos) {
    $idx = wpultra_bricks_index($elements);
    $id = (string) ($node['id'] ?? '');
    if ($id === '' || isset($idx[$id])) { return 'Insert node needs a fresh unique id.'; }
    if ((string) ($node['name'] ?? '') === '') { return 'Insert node needs a name (element type).'; }
    $node['parent'] = $parent_id === '' ? '0' : $parent_id;
    $node['children'] = array_map('strval', (array) ($node['children'] ?? []));
    $node['settings'] = (array) ($node['settings'] ?? []);

    if ($node['parent'] !== '0') {
        if (!isset($idx[$node['parent']])) { return "Parent {$node['parent']} not found."; }
        $kids = array_map('strval', (array) ($elements[$idx[$node['parent']]]['children'] ?? []));
        $pos = max(0, min($pos, count($kids)));
        array_splice($kids, $pos, 0, [$id]);
        $elements[$idx[$node['parent']]]['children'] = $kids;
        $elements[] = $node;
        return $elements;
    }
    // Root insert: splice into the flat array at the position of the pos-th root.
    $root_positions = [];
    foreach ($elements as $i => $el) {
        $p = (string) ($el['parent'] ?? '0');
        if ($p === '0' || $p === '') { $root_positions[] = $i; }
    }
    $pos = max(0, min($pos, count($root_positions)));
    $at = $pos < count($root_positions) ? $root_positions[$pos] : count($elements);
    array_splice($elements, $at, 0, [$node]);
    return $elements;
}

/** Pure: deep-merge settings into element $id. */
function wpultra_bricks_op_edit(array $elements, string $id, array $settings, bool $deep = true) {
    $idx = wpultra_bricks_index($elements);
    if (!isset($idx[$id])) { return "Element $id not found."; }
    $cur = (array) ($elements[$idx[$id]]['settings'] ?? []);
    $merge = function (array $base, array $over) use (&$merge, $deep): array {
        foreach ($over as $k => $v) {
            if ($deep && is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v)) {
                $base[$k] = $merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    };
    $elements[$idx[$id]]['settings'] = $merge($cur, $settings);
    return $elements;
}

/** Pure: delete element $id + its whole subtree; fix the parent's children list. */
function wpultra_bricks_op_delete(array $elements, string $id) {
    $idx = wpultra_bricks_index($elements);
    if (!isset($idx[$id])) { return "Element $id not found."; }
    $doomed = [];
    $queue = [$id];
    while ($queue) {
        $cur = array_shift($queue);
        if (isset($doomed[$cur]) || !isset($idx[$cur])) { continue; }
        $doomed[$cur] = true;
        foreach ((array) ($elements[$idx[$cur]]['children'] ?? []) as $c) { $queue[] = (string) $c; }
    }
    $parent = (string) ($elements[$idx[$id]]['parent'] ?? '0');
    $out = [];
    foreach ($elements as $el) {
        $eid = (string) ($el['id'] ?? '');
        if (isset($doomed[$eid])) { continue; }
        if ($eid === $parent) {
            $el['children'] = array_values(array_filter(array_map('strval', (array) ($el['children'] ?? [])), static fn($c) => $c !== $id));
        }
        $out[] = $el;
    }
    return $out;
}

/** Pure: move $id under $to_parent ('0' = root) at $pos. Cycle-guarded. */
function wpultra_bricks_op_move(array $elements, string $id, string $to_parent, int $pos) {
    $idx = wpultra_bricks_index($elements);
    if (!isset($idx[$id])) { return "Element $id not found."; }
    $to_parent = $to_parent === '' ? '0' : $to_parent;
    if ($to_parent !== '0') {
        if (!isset($idx[$to_parent])) { return "Target parent $to_parent not found."; }
        if (wpultra_bricks_in_subtree($elements, $id, $to_parent)) { return "Cannot move $id into its own subtree."; }
    }
    // Detach from the old parent's children list.
    $old_parent = (string) ($elements[$idx[$id]]['parent'] ?? '0');
    if ($old_parent !== '0' && isset($idx[$old_parent])) {
        $elements[$idx[$old_parent]]['children'] = array_values(array_filter(
            array_map('strval', (array) ($elements[$idx[$old_parent]]['children'] ?? [])),
            static fn($c) => $c !== $id
        ));
    }
    $elements[$idx[$id]]['parent'] = $to_parent;
    if ($to_parent !== '0') {
        $kids = array_map('strval', (array) ($elements[$idx[$to_parent]]['children'] ?? []));
        $kids = array_values(array_filter($kids, static fn($c) => $c !== $id));
        $pos = max(0, min($pos, count($kids)));
        array_splice($kids, $pos, 0, [$id]);
        $elements[$idx[$to_parent]]['children'] = $kids;
    }
    return $elements;
}

/* ------------------------------------------------------------------ *
 * PURE: blueprints (flat skeletons with 'bp*' placeholder ids → re-id).
 * ------------------------------------------------------------------ */

/** Pure: builder for one flat element row. */
function wpultra_bricks_el(string $id, string $name, string $parent, array $children = [], array $settings = []): array {
    return ['id' => $id, 'name' => $name, 'parent' => $parent, 'children' => $children, 'settings' => $settings];
}

/** Pure: the built-in structural skeletons (flat Bricks format). */
function wpultra_bricks_blueprints(): array {
    $e = 'wpultra_bricks_el';
    return [
        'hero' => [
            'description' => 'Hero: section > container > heading(h1) + text + button.',
            'elements' => [
                $e('bp1', 'section', '0', ['bp2']),
                $e('bp2', 'container', 'bp1', ['bp3', 'bp4', 'bp5']),
                $e('bp3', 'heading', 'bp2', [], ['text' => 'Your headline goes here', 'tag' => 'h1']),
                $e('bp4', 'text-basic', 'bp2', [], ['text' => 'A short supporting sentence that explains the value.']),
                $e('bp5', 'button', 'bp2', [], ['text' => 'Get Started']),
            ],
        ],
        'navbar' => [
            'description' => 'Nav: section > container(row) > heading(brand) + nav links block + button.',
            'elements' => [
                $e('bp1', 'section', '0', ['bp2']),
                $e('bp2', 'container', 'bp1', ['bp3', 'bp4', 'bp5'], ['_direction' => 'row', '_alignItems' => 'center', '_justifyContent' => 'space-between']),
                $e('bp3', 'heading', 'bp2', [], ['text' => 'Brand', 'tag' => 'h3']),
                $e('bp4', 'block', 'bp2', ['bp6', 'bp7'], ['_direction' => 'row']),
                $e('bp6', 'text-basic', 'bp4', [], ['text' => 'Home']),
                $e('bp7', 'text-basic', 'bp4', [], ['text' => 'About']),
                $e('bp5', 'button', 'bp2', [], ['text' => 'Get Started']),
            ],
        ],
        'feature-grid' => [
            'description' => 'Three feature columns: section > container(row) > 3x block(heading+text).',
            'elements' => [
                $e('bp1', 'section', '0', ['bp2']),
                $e('bp2', 'container', 'bp1', ['bp3', 'bp4', 'bp5'], ['_direction' => 'row']),
                $e('bp3', 'block', 'bp2', ['bp6', 'bp7']),
                $e('bp6', 'heading', 'bp3', [], ['text' => 'Feature one', 'tag' => 'h3']),
                $e('bp7', 'text-basic', 'bp3', [], ['text' => 'Describe the first feature.']),
                $e('bp4', 'block', 'bp2', ['bp8', 'bp9']),
                $e('bp8', 'heading', 'bp4', [], ['text' => 'Feature two', 'tag' => 'h3']),
                $e('bp9', 'text-basic', 'bp4', [], ['text' => 'Describe the second feature.']),
                $e('bp5', 'block', 'bp2', ['bpa', 'bpb']),
                $e('bpa', 'heading', 'bp5', [], ['text' => 'Feature three', 'tag' => 'h3']),
                $e('bpb', 'text-basic', 'bp5', [], ['text' => 'Describe the third feature.']),
            ],
        ],
        'cta' => [
            'description' => 'CTA band: section > container > heading + button.',
            'elements' => [
                $e('bp1', 'section', '0', ['bp2']),
                $e('bp2', 'container', 'bp1', ['bp3', 'bp4']),
                $e('bp3', 'heading', 'bp2', [], ['text' => 'Ready to get started?']),
                $e('bp4', 'button', 'bp2', [], ['text' => 'Sign up']),
            ],
        ],
        'footer' => [
            'description' => 'Footer: section > container(row) > 3x block(heading + 2 links).',
            'elements' => [
                $e('bp1', 'section', '0', ['bp2']),
                $e('bp2', 'container', 'bp1', ['bp3', 'bp4', 'bp5'], ['_direction' => 'row']),
                $e('bp3', 'block', 'bp2', ['bp6', 'bp7']),
                $e('bp6', 'heading', 'bp3', [], ['text' => 'Product', 'tag' => 'h4']),
                $e('bp7', 'text-basic', 'bp3', [], ['text' => 'Features']),
                $e('bp4', 'block', 'bp2', ['bp8', 'bp9']),
                $e('bp8', 'heading', 'bp4', [], ['text' => 'Company', 'tag' => 'h4']),
                $e('bp9', 'text-basic', 'bp4', [], ['text' => 'About']),
                $e('bp5', 'block', 'bp2', ['bpa', 'bpb']),
                $e('bpa', 'heading', 'bp5', [], ['text' => 'Legal', 'tag' => 'h4']),
                $e('bpb', 'text-basic', 'bp5', [], ['text' => 'Privacy']),
            ],
        ],
    ];
}

/** Pure-ish: re-id a blueprint's flat elements collision-free vs $existing ids. */
function wpultra_bricks_blueprint_reid(array $elements, array $existing_ids = []): array {
    $taken = array_fill_keys(array_map('strval', $existing_ids), true);
    $map = [];
    foreach ($elements as $el) {
        $old = (string) ($el['id'] ?? '');
        $new = wpultra_bricks_new_id($taken);
        $taken[$new] = true;
        $map[$old] = $new;
    }
    $out = [];
    foreach ($elements as $el) {
        $el['id'] = $map[(string) $el['id']];
        $p = (string) ($el['parent'] ?? '0');
        $el['parent'] = isset($map[$p]) ? $map[$p] : '0';
        $el['children'] = array_map(static fn($c) => $map[(string) $c] ?? (string) $c, (array) ($el['children'] ?? []));
        $out[] = $el;
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Global classes + schema introspection (thin WP wrappers).
 * ------------------------------------------------------------------ */

function wpultra_bricks_classes(): array {
    $v = get_option(WPULTRA_BRICKS_CLASSES_OPTION, []);
    return is_array($v) ? array_values($v) : [];
}

/** @return array|WP_Error {id} */
function wpultra_bricks_class_upsert(string $name, array $settings, ?string $id = null) {
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9 _\-]{0,60}$/', $name)) {
        return wpultra_err('bad_class_name', 'Class name must start with a letter (letters/digits/space/dash/underscore).');
    }
    $classes = wpultra_bricks_classes();
    $found = false;
    foreach ($classes as &$c) {
        if ($id !== null && (string) ($c['id'] ?? '') === $id) {
            $c['name'] = $name;
            $c['settings'] = $settings;
            $found = true;
            break;
        }
    }
    unset($c);
    if (!$found) {
        $id = $id ?? wpultra_bricks_new_id(array_map(static fn($c) => (string) ($c['id'] ?? ''), $classes));
        $classes[] = ['id' => $id, 'name' => $name, 'settings' => $settings];
    }
    update_option(WPULTRA_BRICKS_CLASSES_OPTION, $classes, false);
    return ['id' => (string) $id];
}

function wpultra_bricks_class_delete(string $id): bool {
    $classes = wpultra_bricks_classes();
    $before = count($classes);
    $classes = array_values(array_filter($classes, static fn($c) => (string) ($c['id'] ?? '') !== $id));
    if (count($classes) === $before) { return false; }
    update_option(WPULTRA_BRICKS_CLASSES_OPTION, $classes, false);
    return true;
}

/**
 * Shared mutation runner: load a post's flat elements, apply a pure op,
 * verify foundation validity + deep consistency, write back.
 * @param callable $op fn(array $elements): array|string
 * @return array|WP_Error {elements: compact tree, count}
 */
function wpultra_bricks_mutate(int $post_id, callable $op) {
    $post = get_post($post_id);
    if (!$post) { return wpultra_err('not_found', "No post $post_id."); }
    if (in_array($post->post_type, wpultra_reserved_post_types(), true)) {
        return wpultra_err('reserved_post_type', 'Refusing to write a plugin-internal post.');
    }
    $elements = wpultra_bricks_raw($post_id);
    $result = $op($elements);
    if (is_string($result)) { return wpultra_err('bricks_op_failed', $result); }
    $report = wpultra_bricks_validate_tree($result);
    if (!($report['ok'] ?? false)) {
        return wpultra_err('bricks_invalid', 'Resulting tree failed validation.', ['errors' => $report['errors'] ?? $report]);
    }
    $consistent = wpultra_bricks_consistency($result);
    if ($consistent !== true) { return wpultra_err('bricks_inconsistent', (string) $consistent); }
    $w = wpultra_bricks_write($post_id, $result);
    if (is_wp_error($w)) { return $w; }
    return ['count' => count($result), 'elements' => wpultra_bricks_build_tree($result)];
}

/**
 * Best-effort control-schema introspection for one element from Bricks' own
 * registry (needs a live Bricks install). @return array|WP_Error
 */
function wpultra_bricks_element_schema(string $name) {
    if (!class_exists('\\Bricks\\Elements')) {
        return wpultra_err('bricks_unavailable', 'Bricks is not active — element schemas can only be introspected on a live Bricks install.');
    }
    try {
        $registry = \Bricks\Elements::$elements ?? [];
        if (!isset($registry[$name])) { return wpultra_err('unknown_element', "No Bricks element '$name' registered."); }
        $file = $registry[$name]['file'] ?? null;
        $class = $registry[$name]['class'] ?? null;
        if ($file && is_readable($file)) { require_once $file; }
        if (!$class || !class_exists($class)) { return wpultra_err('schema_unavailable', "Element '$name' has no loadable class."); }
        $inst = new $class();
        foreach (['set_control_groups', 'set_controls'] as $m) {
            if (method_exists($inst, $m)) { try { $inst->{$m}(); } catch (\Throwable $e) {} }
        }
        $controls = [];
        foreach ((array) ($inst->controls ?? []) as $key => $c) {
            $controls[(string) $key] = array_filter([
                'type'    => (string) (($c['type'] ?? '')),
                'label'   => (string) (($c['label'] ?? '')),
                'default' => $c['default'] ?? null,
                'options' => isset($c['options']) && is_array($c['options']) ? array_keys($c['options']) : null,
            ], static fn($v) => $v !== null && $v !== '');
        }
        return ['element' => $name, 'label' => (string) ($registry[$name]['label'] ?? $name), 'controls' => $controls];
    } catch (\Throwable $e) {
        return wpultra_err('schema_unavailable', 'Introspection failed: ' . $e->getMessage());
    }
}
