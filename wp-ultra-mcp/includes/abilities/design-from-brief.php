<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine + shared AI helper regardless of load order.
if (!function_exists('wpultra_dfb_build') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/ai/designbrief.php')) {
    require_once WPULTRA_DIR . 'includes/ai/designbrief.php';
}
if (!function_exists('wpultra_ai_has_key') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/ai/setup.php')) {
    require_once WPULTRA_DIR . 'includes/ai/setup.php';
}

wp_register_ability('wpultra/design-from-brief', [
    'label'       => __('AI: Design a Site from a Brief', 'wp-ultra-mcp'),
    'description' => __(
        'Describe a whole site in one brief → AI builds the pages, nav menu, and theme color/font tokens. '
        . 'FLOW: brief (natural language) OR a structured `plan` → a validated SITE PLAN → BUILD. '
        . 'The site plan shape is: {site:{name,tagline}, tokens:{colors:[{slug,name,hex}], fonts:[{slug,name,family}]}, '
        . 'pages:[{slug,title,sections:[{type:hero|features|cta|text|contact, heading?,subheading?,body?, '
        . 'items?:[{title,text}], button?:{label,url}}]}], menu:[{title, page_slug|url}]}. '
        . 'ACTIONS: '
        . 'plan {brief | plan} → returns the validated site plan WITHOUT building anything (always safe, no confirm needed). '
        . 'With a `plan` it validates + echoes it; with a `brief` it asks the server AI to generate the plan (needs an OpenAI key). '
        . 'preview-blocks {page} → returns the Gutenberg block markup for one page object (pure, no writes) so you can eyeball it. '
        . 'build {plan | brief, dry_run?, confirm?} → dry_run defaults to TRUE and returns the build plan-of-record '
        . '(which pages would be created/updated, menu items, whether tokens would apply — writes NOTHING). '
        . 'Pass dry_run:false AND confirm:true to actually create the pages, build the nav menu, and write theme tokens. '
        . 'BUILD DETAILS: pages are created/updated (matched by slug — no duplicates) as published WordPress pages with core '
        . 'Gutenberg block content; the menu is created and assigned to the theme\'s primary menu location; color/font tokens '
        . 'are written into block-theme global styles (theme.json). On a NON-block theme the tokens are recorded to an option '
        . 'and returned so you can apply them elsewhere (e.g. Elementor). '
        . 'MODES: plan-mode ALWAYS works (the calling AI writes the plan itself and passes it as `plan`); brief-mode needs a '
        . 'configured server-side OpenAI key. '
        . 'EXAMPLE: {action:"build", plan:{site:{name:"Joe\'s Plumbing"}, pages:[{slug:"home",title:"Home",sections:['
        . '{type:"hero",heading:"Fast, Friendly Plumbing",subheading:"24/7 emergency service",button:{label:"Call Now",url:"tel:5551234"}}]}], '
        . 'menu:[{title:"Home",page_slug:"home"}]}, dry_run:false, confirm:true}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'ai',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['plan', 'build', 'preview-blocks']],
            'brief'   => ['type' => 'string'],
            'plan'    => [
                'type'       => 'object',
                'properties' => [
                    'site'   => [
                        'type'       => 'object',
                        'properties' => [
                            'name'    => ['type' => 'string'],
                            'tagline' => ['type' => 'string'],
                        ],
                    ],
                    'tokens' => [
                        'type'       => 'object',
                        'properties' => [
                            'colors' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'slug' => ['type' => 'string'],
                                        'name' => ['type' => 'string'],
                                        'hex'  => ['type' => 'string'],
                                    ],
                                ],
                            ],
                            'fonts' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'slug'   => ['type' => 'string'],
                                        'name'   => ['type' => 'string'],
                                        'family' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'pages' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'slug'     => ['type' => 'string'],
                                'title'    => ['type' => 'string'],
                                'sections' => [
                                    'type'  => 'array',
                                    'items' => [
                                        'type'       => 'object',
                                        'properties' => [
                                            'type'       => ['type' => 'string', 'enum' => ['hero', 'features', 'cta', 'text', 'contact']],
                                            'heading'    => ['type' => 'string'],
                                            'subheading' => ['type' => 'string'],
                                            'body'       => ['type' => 'string'],
                                            'items'      => [
                                                'type'  => 'array',
                                                'items' => [
                                                    'type'       => 'object',
                                                    'properties' => [
                                                        'title' => ['type' => 'string'],
                                                        'text'  => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                            'button' => [
                                                'type'       => 'object',
                                                'properties' => [
                                                    'label' => ['type' => 'string'],
                                                    'url'   => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'menu' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'title'     => ['type' => 'string'],
                                'page_slug' => ['type' => 'string'],
                                'url'       => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'page'    => [
                'type'       => 'object',
                'properties' => [
                    'slug'     => ['type' => 'string'],
                    'title'    => ['type' => 'string'],
                    'sections' => ['type' => 'array'],
                ],
            ],
            'dry_run' => ['type' => 'boolean', 'default' => true],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
            'plan'    => ['type' => 'object'],
            'build'   => ['type' => 'object'],
            'content' => ['type' => 'string'],
            'dry_run' => ['type' => 'boolean'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_design_from_brief_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_design_from_brief_cb(array $input) {
    if (!function_exists('wpultra_dfb_build')) {
        return wpultra_err('engine_missing', 'The design-from-brief engine (includes/ai/designbrief.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');
    if ($action === '') { return wpultra_err('missing_action', 'action is required (plan|build|preview-blocks).'); }

    $brief = (string) ($input['brief'] ?? '');
    $plan_in = is_array($input['plan'] ?? null) ? $input['plan'] : null;

    switch ($action) {
        /* --------- plan: validate/generate, never build --------- */
        case 'plan': {
            $plan = wpultra_dfb_resolve_plan($plan_in, $brief);
            if (is_wp_error($plan)) {
                wpultra_audit_log('design-from-brief', 'plan failed: ' . $plan->get_error_message(), false);
                return $plan;
            }
            wpultra_audit_log('design-from-brief', 'plan: ' . count($plan['pages'] ?? []) . ' pages', true);
            return wpultra_ok(['action' => 'plan', 'plan' => $plan]);
        }

        /* --------- preview-blocks: pure block markup for one page --------- */
        case 'preview-blocks': {
            $page = is_array($input['page'] ?? null) ? $input['page'] : null;
            if ($page === null || $page === []) {
                return wpultra_err('missing_page', 'preview-blocks requires a `page` object {slug,title,sections}.');
            }
            $content = wpultra_dfb_page_content($page);
            return wpultra_ok([
                'action'  => 'preview-blocks',
                'content' => $content,
                'blocks'  => substr_count($content, '<!-- wp:'),
            ]);
        }

        /* --------- build: dry-run by default, confirm to write --------- */
        case 'build': {
            $plan = wpultra_dfb_resolve_plan($plan_in, $brief);
            if (is_wp_error($plan)) {
                wpultra_audit_log('design-from-brief', 'build resolve failed: ' . $plan->get_error_message(), false);
                return $plan;
            }
            $dry_run = array_key_exists('dry_run', $input) ? ($input['dry_run'] === true) : true;
            if (!$dry_run && ($input['confirm'] ?? false) !== true) {
                return wpultra_err('build_unconfirmed', 'Live build creates pages, a menu, and writes theme tokens. Re-run with dry_run:false and confirm:true.');
            }
            $build = wpultra_dfb_build($plan, $dry_run);
            if (is_wp_error($build)) {
                wpultra_audit_log('design-from-brief', 'build failed: ' . $build->get_error_message(), false);
                return $build;
            }
            $page_count = count($build['pages'] ?? []);
            wpultra_audit_log('design-from-brief', ($dry_run ? 'dry-run' : 'live') . " build: $page_count pages", true);
            return wpultra_ok([
                'action'  => 'build',
                'dry_run' => $dry_run,
                'build'   => $build,
            ]);
        }

        default:
            return wpultra_err('unknown_action', "Unknown action '$action' (plan|build|preview-blocks).");
    }
}
