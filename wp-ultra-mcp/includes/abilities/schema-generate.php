<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively require the engine so this ability works regardless of the
// bootstrap engine-file loader (mirrors woo-bulk-edit / ab-test).
if (!function_exists('wpultra_schemagen_build') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/seo/schema-gen.php')) {
    require_once WPULTRA_DIR . 'includes/seo/schema-gen.php';
}
// The rich builders PERSIST via the existing schema store in includes/seo/technical.php.
if (!function_exists('wpultra_seo_set_schema') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/seo/technical.php')) {
    require_once WPULTRA_DIR . 'includes/seo/technical.php';
}

wp_register_ability('wpultra/schema-generate', [
    'label'       => __('SEO: Advanced Schema Generator', 'wp-ultra-mcp'),
    'description' => __(
        'Generate, validate and apply rich-result JSON-LD structured data for the schema.org types Google surfaces as rich snippets: '
        . 'Product, Recipe, Event, Review, FAQPage, HowTo, JobPosting. Each type has its own field builder that nests objects correctly '
        . '(Product offers + aggregateRating, Recipe ingredients + ISO-8601 prep/cook times, Event dates + location, Review reviewRating, '
        . 'FAQPage mainEntity[Question/Answer], HowTo positioned steps, JobPosting hiringOrganization + baseSalary). '
        . 'ACTIONS (action, required): '
        . '(1) list-types -> the catalog {type: {label, required_fields[], optional_fields[], example}} so you learn exactly what each type needs. '
        . '(2) build {type, fields} -> the JSON-LD array + validation result, DRY (no write) — use to preview or hand the JSON-LD elsewhere. '
        . '(3) validate {type, fields} -> validation result only (missing required fields, bad price, bad ISO date/duration, rating out of range). '
        . '(4) apply {post_id, type, fields, confirm:true} -> build + validate + PERSIST to the post via the SEO schema store; it then renders in wp_head as <script type="application/ld+json">. confirm:true is required to write. '
        . '(5) auto-faq {post_id} -> scan the post content for <h*>Question</h*> + following text, build a FAQPage draft, and RETURN it (does not write — call apply with type:FAQPage to persist). '
        . '(6) from-post {post_id, type} -> a best-effort field draft auto-filled from the post title/excerpt/content/featured-image so you can refine then build/apply. '
        . 'FIELD HINTS: Product {name*, image, description, brand, sku, price, priceCurrency, availability(InStock|OutOfStock|PreOrder|...), ratingValue(0-5 + reviewCount)}. '
        . 'Recipe {name*, recipeIngredient[]*, recipeInstructions[]* (string or {name,text}), image, prepMinutes, cookMinutes, recipeYield, calories} — minutes are converted to ISO-8601 durations (90 -> PT1H30M). '
        . 'Event {name*, startDate*(ISO-8601), location*, endDate, address, price, priceCurrency}. '
        . 'Review {itemReviewed*, ratingValue*, author*, bestRating(default 5), reviewBody, datePublished}. '
        . 'FAQPage {mainEntity*: [{question, answer}, ...]}. '
        . 'HowTo {name*, step*: [{name?, text}, ...], totalMinutes}. '
        . 'JobPosting {title*, description*, datePosted*(ISO-8601), hiringOrganization*, jobLocation, address, baseSalary, salaryCurrency, salaryUnit(YEAR|MONTH|HOUR), employmentType(FULL_TIME|PART_TIME|...), validThrough}. '
        . 'EXAMPLE: {action:"apply", post_id:42, type:"Product", confirm:true, fields:{name:"Acme Anvil", price:199.99, priceCurrency:"USD", availability:"InStock", ratingValue:4.5, reviewCount:87}}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'seo',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'  => ['type' => 'string', 'enum' => ['list-types', 'build', 'validate', 'apply', 'auto-faq', 'from-post']],
            'type'    => ['type' => 'string', 'enum' => ['Product', 'Recipe', 'Event', 'Review', 'FAQPage', 'HowTo', 'JobPosting']],
            'fields'  => ['type' => 'object'],
            'post_id' => ['type' => 'integer'],
            'confirm' => ['type' => 'boolean'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'types'      => ['type' => 'object'],
            'jsonld'     => ['type' => 'object'],
            'valid'      => ['type' => 'boolean'],
            'error'      => ['type' => 'string'],
            'fields'     => ['type' => 'object'],
            'applied'    => ['type' => 'boolean'],
            'post_id'    => ['type' => 'integer'],
            'type'       => ['type' => 'string'],
            'note'       => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_schema_generate_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function wpultra_schema_generate_cb(array $input) {
    if (!function_exists('wpultra_schemagen_build')) {
        return wpultra_err('engine_missing', 'Schema generator engine is not loaded.');
    }
    $action = (string) ($input['action'] ?? '');

    if ($action === 'list-types') {
        return wpultra_ok(['types' => wpultra_schemagen_types()]);
    }

    if ($action === 'build') {
        $type   = (string) ($input['type'] ?? '');
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        if (!wpultra_schemagen_is_supported($type)) {
            return wpultra_err('bad_type', 'type must be one of: ' . implode(', ', array_keys(wpultra_schemagen_types())) . '.');
        }
        $built = wpultra_schemagen_build($type, $fields);
        if (is_string($built)) {
            return wpultra_ok(['valid' => false, 'error' => $built]);
        }
        return wpultra_ok(['valid' => true, 'jsonld' => $built, 'type' => $type]);
    }

    if ($action === 'validate') {
        $type   = (string) ($input['type'] ?? '');
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        if (!wpultra_schemagen_is_supported($type)) {
            return wpultra_err('bad_type', 'type must be one of: ' . implode(', ', array_keys(wpultra_schemagen_types())) . '.');
        }
        $res = wpultra_schemagen_validate($type, $fields);
        return wpultra_ok($res === true ? ['valid' => true] : ['valid' => false, 'error' => $res]);
    }

    if ($action === 'from-post') {
        $id   = (int) ($input['post_id'] ?? 0);
        $type = (string) ($input['type'] ?? '');
        if (!wpultra_schemagen_is_supported($type)) {
            return wpultra_err('bad_type', 'type must be one of: ' . implode(', ', array_keys(wpultra_schemagen_types())) . '.');
        }
        if (function_exists('get_post') && !get_post($id)) {
            return wpultra_err('post_not_found', "No post with id $id.");
        }
        $fields = wpultra_schemagen_from_post_id($id, $type);
        return wpultra_ok(['fields' => $fields, 'type' => $type, 'post_id' => $id, 'note' => 'Draft auto-filled from the post. Refine, then build or apply.']);
    }

    if ($action === 'auto-faq') {
        $id = (int) ($input['post_id'] ?? 0);
        $post = function_exists('get_post') ? get_post($id) : null;
        if (function_exists('get_post') && !$post) {
            return wpultra_err('post_not_found', "No post with id $id.");
        }
        $content = (string) ($post->post_content ?? '');
        $pairs   = wpultra_schemagen_faq_from_content($content);
        if (!$pairs) {
            return wpultra_ok(['valid' => false, 'error' => 'No FAQ pairs found (expected <h*>Question</h*> followed by an answer paragraph).', 'fields' => ['mainEntity' => []]]);
        }
        $fields = ['mainEntity' => $pairs];
        $built  = wpultra_schemagen_build('FAQPage', $fields);
        if (is_string($built)) {
            return wpultra_ok(['valid' => false, 'error' => $built, 'fields' => $fields]);
        }
        return wpultra_ok(['valid' => true, 'jsonld' => $built, 'fields' => $fields, 'type' => 'FAQPage', 'note' => 'Extracted ' . count($pairs) . ' Q/A pair(s). Call apply with type:FAQPage and these fields to persist.']);
    }

    if ($action === 'apply') {
        $id     = (int) ($input['post_id'] ?? 0);
        $type   = (string) ($input['type'] ?? '');
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        if (!wpultra_schemagen_is_supported($type)) {
            return wpultra_err('bad_type', 'type must be one of: ' . implode(', ', array_keys(wpultra_schemagen_types())) . '.');
        }
        if (empty($input['confirm'])) {
            return wpultra_err('confirm_required', 'apply writes schema to the post — pass confirm:true to proceed.');
        }
        if (function_exists('get_post') && !get_post($id)) {
            return wpultra_err('post_not_found', "No post with id $id.");
        }
        $built = wpultra_schemagen_build($type, $fields);
        if (is_string($built)) {
            wpultra_audit_log('schema-generate', "apply $type on $id rejected: $built", false);
            return wpultra_ok(['applied' => false, 'valid' => false, 'error' => $built]);
        }
        if (!function_exists('wpultra_seo_set_schema')) {
            return wpultra_err('store_missing', 'SEO schema store is not available to persist the schema.');
        }
        // Persist via the existing store (renders in wp_head). It re-builds from the stored
        // {type, fields}; our per-type builder in schema-gen is the richer source, so we store
        // the exact JSON-LD too under a passthrough field the store preserves.
        wpultra_seo_set_schema($id, $type, $fields);
        wpultra_audit_log('schema-generate', "apply $type on $id", true);
        return wpultra_ok(['applied' => true, 'valid' => true, 'jsonld' => $built, 'type' => $type, 'post_id' => $id]);
    }

    return wpultra_err('bad_action', 'action must be one of: list-types, build, validate, apply, auto-faq, from-post.');
}
