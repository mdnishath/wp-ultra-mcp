<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Advanced schema.org rich-snippet generator (roadmap D1).
 *
 * PURE core (wpultra_schemagen_*) builds + validates typed JSON-LD for the rich
 * result types Google supports: Product, Recipe, Event, Review, FAQPage, HowTo,
 * JobPosting. WP-touching wrappers come after and reuse the existing schema store
 * in includes/seo/technical.php (wpultra_seo_set_schema / _get_schema) for
 * persistence + wp_head rendering — this file only produces the RICH, VALIDATED
 * per-type schema arrays.
 *
 * No storage/rendering is reimplemented here.
 */

/* =====================================================================
 * PURE — small validators / formatters
 * ===================================================================== */

/** PURE. Loose ISO-8601 date/datetime check (YYYY-MM-DD, optionally with time + offset). */
function wpultra_schemagen_is_iso_date($v): bool {
    if (!is_string($v) || $v === '') { return false; }
    // Date only, or date + T time (+ optional seconds, fractional, Z or +hh:mm offset).
    return (bool) preg_match(
        '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+\-]\d{2}:\d{2})?)?$/',
        $v
    );
}

/** PURE. ISO-8601 duration check, e.g. PT1H30M, P1DT2H, PT45M. */
function wpultra_schemagen_is_iso_duration($v): bool {
    if (!is_string($v) || $v === '') { return false; }
    if ($v === 'P' || $v === 'PT') { return false; }
    // P[nD]  optionally T[nH][nM][nS] — at least one component required.
    return (bool) preg_match('/^P(?:\d+D)?(?:T(?:\d+H)?(?:\d+M)?(?:\d+S)?)?$/', $v)
        && preg_match('/\d/', $v);
}

/** PURE. Loose URL shape check (http/https + host-ish). */
function wpultra_schemagen_is_url($v): bool {
    if (!is_string($v) || $v === '') { return false; }
    return (bool) preg_match('~^https?://[^\s/$.?#].[^\s]*$~i', $v);
}

/** PURE. True when $v is a finite number or a numeric string. */
function wpultra_schemagen_is_numeric($v): bool {
    return is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)));
}

/** PURE. Minutes -> ISO-8601 duration. 90 => 'PT1H30M', 45 => 'PT45M', 120 => 'PT2H'. */
function wpultra_schemagen_iso_duration(int $minutes): string {
    if ($minutes <= 0) { return 'PT0M'; }
    $days = intdiv($minutes, 1440);
    $rem  = $minutes % 1440;
    $h    = intdiv($rem, 60);
    $m    = $rem % 60;
    $out  = 'P';
    if ($days > 0) { $out .= $days . 'D'; }
    if ($h > 0 || $m > 0) {
        $out .= 'T';
        if ($h > 0) { $out .= $h . 'H'; }
        if ($m > 0) { $out .= $m . 'M'; }
    }
    // Pure days (e.g. exactly 1440 min) => 'P1D'. If nothing accumulated, PT0M.
    return $out === 'P' ? 'PT0M' : $out;
}

/* =====================================================================
 * PURE — the type catalog
 * ===================================================================== */

/**
 * PURE. Catalog of supported schema types.
 * type => [label, required_fields[], optional_fields[], example].
 * The AI reads this to learn what each type needs before building.
 */
function wpultra_schemagen_types(): array {
    return [
        'Product' => [
            'label'           => 'Product',
            'required_fields' => ['name'],
            'optional_fields' => ['image', 'description', 'brand', 'sku', 'price', 'priceCurrency', 'availability', 'ratingValue', 'reviewCount', 'url'],
            'example'         => [
                'name'         => 'Acme Anvil',
                'image'        => 'https://example.com/anvil.jpg',
                'description'  => 'A heavy, durable anvil.',
                'brand'        => 'Acme',
                'sku'          => 'ANV-100',
                'price'        => 199.99,
                'priceCurrency' => 'USD',
                'availability' => 'InStock',
                'ratingValue'  => 4.5,
                'reviewCount'  => 87,
            ],
        ],
        'Recipe' => [
            'label'           => 'Recipe',
            'required_fields' => ['name', 'recipeIngredient', 'recipeInstructions'],
            'optional_fields' => ['image', 'description', 'author', 'prepMinutes', 'cookMinutes', 'recipeYield', 'calories', 'recipeCategory', 'recipeCuisine'],
            'example'         => [
                'name'               => 'Chocolate Chip Cookies',
                'image'              => 'https://example.com/cookies.jpg',
                'recipeIngredient'   => ['2 cups flour', '1 cup sugar', '1 cup chocolate chips'],
                'recipeInstructions' => ['Mix dry ingredients.', 'Add wet ingredients.', 'Bake 12 minutes.'],
                'prepMinutes'        => 15,
                'cookMinutes'        => 12,
                'recipeYield'        => '24 cookies',
                'calories'           => '150 calories',
            ],
        ],
        'Event' => [
            'label'           => 'Event',
            'required_fields' => ['name', 'startDate', 'location'],
            'optional_fields' => ['endDate', 'description', 'image', 'address', 'price', 'priceCurrency', 'availability', 'url', 'eventStatus'],
            'example'         => [
                'name'      => 'Summer Music Festival',
                'startDate' => '2026-08-01T18:00',
                'endDate'   => '2026-08-01T23:00',
                'location'  => 'Central Park',
                'address'   => 'New York, NY',
                'price'     => 49.99,
                'priceCurrency' => 'USD',
            ],
        ],
        'Review' => [
            'label'           => 'Review',
            'required_fields' => ['itemReviewed', 'ratingValue', 'author'],
            'optional_fields' => ['bestRating', 'worstRating', 'reviewBody', 'datePublished', 'itemType'],
            'example'         => [
                'itemReviewed' => 'Acme Anvil',
                'ratingValue'  => 4.5,
                'bestRating'   => 5,
                'author'       => 'Jane Doe',
                'reviewBody'   => 'Very sturdy and well made.',
                'datePublished' => '2026-06-01',
            ],
        ],
        'FAQPage' => [
            'label'           => 'FAQ Page',
            'required_fields' => ['mainEntity'],
            'optional_fields' => [],
            'example'         => [
                'mainEntity' => [
                    ['question' => 'Do you ship internationally?', 'answer' => 'Yes, we ship worldwide.'],
                    ['question' => 'What is the return policy?', 'answer' => 'Returns accepted within 30 days.'],
                ],
            ],
        ],
        'HowTo' => [
            'label'           => 'How-To',
            'required_fields' => ['name', 'step'],
            'optional_fields' => ['description', 'image', 'totalMinutes', 'supply', 'tool', 'estimatedCost'],
            'example'         => [
                'name' => 'How to tie a tie',
                'step' => [
                    ['name' => 'Cross', 'text' => 'Cross the wide end over the narrow end.'],
                    ['name' => 'Loop', 'text' => 'Loop the wide end through the neck.'],
                    ['name' => 'Knot', 'text' => 'Pull down through the front knot.'],
                ],
                'totalMinutes' => 5,
            ],
        ],
        'JobPosting' => [
            'label'           => 'Job Posting',
            'required_fields' => ['title', 'description', 'datePosted', 'hiringOrganization'],
            'optional_fields' => ['jobLocation', 'address', 'baseSalary', 'salaryCurrency', 'salaryUnit', 'employmentType', 'validThrough', 'url'],
            'example'         => [
                'title'              => 'Senior WordPress Developer',
                'description'        => 'We are hiring a senior WordPress developer.',
                'datePosted'         => '2026-07-01',
                'hiringOrganization' => 'Acme Corp',
                'jobLocation'        => 'Remote',
                'address'            => 'New York, NY',
                'baseSalary'         => 120000,
                'salaryCurrency'     => 'USD',
                'salaryUnit'         => 'YEAR',
                'employmentType'     => 'FULL_TIME',
            ],
        ],
    ];
}

/** PURE. True when $type is a supported schema type. */
function wpultra_schemagen_is_supported(string $type): bool {
    return array_key_exists($type, wpultra_schemagen_types());
}

/* =====================================================================
 * PURE — validation
 * ===================================================================== */

/**
 * PURE. Validate typed input for a schema type.
 * @return true|string  true on success, or an error message describing what is wrong.
 */
function wpultra_schemagen_validate(string $type, array $data) {
    $catalog = wpultra_schemagen_types();
    if (!isset($catalog[$type])) {
        return "Unsupported schema type '$type'. Supported: " . implode(', ', array_keys($catalog)) . '.';
    }

    // Required-field presence (treat empty string / empty array as missing).
    $missing = [];
    foreach ($catalog[$type]['required_fields'] as $rf) {
        $v = $data[$rf] ?? null;
        if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) {
            $missing[] = $rf;
        }
    }
    if ($missing) {
        return "Missing required field(s) for $type: " . implode(', ', $missing) . '.';
    }

    // Shape checks per type.
    switch ($type) {
        case 'Product':
            if (isset($data['price']) && $data['price'] !== '' && !wpultra_schemagen_is_numeric($data['price'])) {
                return 'Product price must be numeric.';
            }
            if (isset($data['ratingValue']) && $data['ratingValue'] !== '') {
                $r = (float) $data['ratingValue'];
                if (!wpultra_schemagen_is_numeric($data['ratingValue']) || $r < 0 || $r > 5) {
                    return 'Product ratingValue must be a number between 0 and 5.';
                }
                if (empty($data['reviewCount'])) {
                    return 'Product aggregateRating requires reviewCount when ratingValue is set.';
                }
            }
            if (isset($data['image']) && $data['image'] !== '' && is_string($data['image']) && !wpultra_schemagen_is_url($data['image'])) {
                return 'Product image must be a valid URL.';
            }
            break;

        case 'Recipe':
            if (!is_array($data['recipeIngredient'])) {
                return 'Recipe recipeIngredient must be an array of strings.';
            }
            if (!is_array($data['recipeInstructions'])) {
                return 'Recipe recipeInstructions must be an array of steps.';
            }
            foreach (['prepMinutes', 'cookMinutes'] as $mk) {
                if (isset($data[$mk]) && $data[$mk] !== '' && !wpultra_schemagen_is_numeric($data[$mk])) {
                    return "Recipe $mk must be a number of minutes.";
                }
            }
            break;

        case 'Event':
            if (!wpultra_schemagen_is_iso_date($data['startDate'])) {
                return 'Event startDate must be an ISO-8601 date (e.g. 2026-08-01 or 2026-08-01T18:00).';
            }
            if (isset($data['endDate']) && $data['endDate'] !== '' && !wpultra_schemagen_is_iso_date($data['endDate'])) {
                return 'Event endDate must be an ISO-8601 date.';
            }
            if (isset($data['price']) && $data['price'] !== '' && !wpultra_schemagen_is_numeric($data['price'])) {
                return 'Event price must be numeric.';
            }
            break;

        case 'Review':
            if (!wpultra_schemagen_is_numeric($data['ratingValue'])) {
                return 'Review ratingValue must be numeric.';
            }
            $best = isset($data['bestRating']) && $data['bestRating'] !== '' ? (float) $data['bestRating'] : 5.0;
            $rv   = (float) $data['ratingValue'];
            if ($rv < 0 || $rv > $best) {
                return "Review ratingValue must be between 0 and bestRating ($best).";
            }
            break;

        case 'FAQPage':
            if (!is_array($data['mainEntity'])) {
                return 'FAQPage mainEntity must be an array of {question, answer} pairs.';
            }
            foreach ($data['mainEntity'] as $i => $qa) {
                if (!is_array($qa) || ($qa['question'] ?? '') === '' || ($qa['answer'] ?? '') === '') {
                    return "FAQPage mainEntity[$i] needs a non-empty question and answer.";
                }
            }
            break;

        case 'HowTo':
            if (!is_array($data['step'])) {
                return 'HowTo step must be an array of {name?, text} steps.';
            }
            foreach ($data['step'] as $i => $st) {
                $text = is_array($st) ? ($st['text'] ?? '') : (is_string($st) ? $st : '');
                if ($text === '') {
                    return "HowTo step[$i] needs non-empty text.";
                }
            }
            if (isset($data['totalMinutes']) && $data['totalMinutes'] !== '' && !wpultra_schemagen_is_numeric($data['totalMinutes'])) {
                return 'HowTo totalMinutes must be a number.';
            }
            break;

        case 'JobPosting':
            if (!wpultra_schemagen_is_iso_date($data['datePosted'])) {
                return 'JobPosting datePosted must be an ISO-8601 date.';
            }
            if (isset($data['validThrough']) && $data['validThrough'] !== '' && !wpultra_schemagen_is_iso_date($data['validThrough'])) {
                return 'JobPosting validThrough must be an ISO-8601 date.';
            }
            if (isset($data['baseSalary']) && $data['baseSalary'] !== '' && !wpultra_schemagen_is_numeric($data['baseSalary'])) {
                return 'JobPosting baseSalary must be numeric.';
            }
            break;
    }

    return true;
}

/* =====================================================================
 * PURE — builders
 * ===================================================================== */

/**
 * PURE. Build the JSON-LD assoc for a supported type from typed input.
 * @return array|string  the JSON-LD array, or an error string (missing required fields / bad shape).
 */
function wpultra_schemagen_build(string $type, array $input) {
    $valid = wpultra_schemagen_validate($type, $input);
    if ($valid !== true) { return $valid; }

    $base = ['@context' => 'https://schema.org', '@type' => $type];

    switch ($type) {
        case 'Product':
            return wpultra_schemagen_build_product($base, $input);
        case 'Recipe':
            return wpultra_schemagen_build_recipe($base, $input);
        case 'Event':
            return wpultra_schemagen_build_event($base, $input);
        case 'Review':
            return wpultra_schemagen_build_review($base, $input);
        case 'FAQPage':
            return wpultra_schemagen_build_faq($base, $input);
        case 'HowTo':
            return wpultra_schemagen_build_howto($base, $input);
        case 'JobPosting':
            return wpultra_schemagen_build_jobposting($base, $input);
    }
    // Unreachable (validate already gated), but be safe.
    return "Unsupported schema type '$type'.";
}

/** PURE. */
function wpultra_schemagen_build_product(array $base, array $f): array {
    $out = $base;
    $out['name'] = (string) $f['name'];
    foreach (['image' => 'image', 'description' => 'description', 'sku' => 'sku'] as $k => $prop) {
        if (!empty($f[$k])) { $out[$prop] = $f[$k]; }
    }
    if (!empty($f['brand'])) {
        $out['brand'] = ['@type' => 'Brand', 'name' => (string) $f['brand']];
    }
    if (isset($f['price']) && $f['price'] !== '') {
        $offer = [
            '@type'         => 'Offer',
            'price'         => (string) $f['price'],
            'priceCurrency' => (string) ($f['priceCurrency'] ?? 'USD'),
        ];
        if (!empty($f['availability'])) {
            $offer['availability'] = 'https://schema.org/' . wpultra_schemagen_norm_availability((string) $f['availability']);
        }
        if (!empty($f['url'])) { $offer['url'] = $f['url']; }
        $out['offers'] = $offer;
    }
    if (isset($f['ratingValue']) && $f['ratingValue'] !== '') {
        $out['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => (string) $f['ratingValue'],
            'reviewCount' => (string) ($f['reviewCount'] ?? 0),
        ];
    }
    return $out;
}

/** PURE. Normalize an availability token to a schema.org enum tail (InStock etc.). */
function wpultra_schemagen_norm_availability(string $v): string {
    $v = trim($v);
    // Accept already-qualified URLs or plain tokens.
    if (str_contains($v, '/')) { $v = (string) substr($v, (int) strrpos($v, '/') + 1); }
    $map = [
        'instock'        => 'InStock',
        'in_stock'       => 'InStock',
        'outofstock'     => 'OutOfStock',
        'out_of_stock'   => 'OutOfStock',
        'preorder'       => 'PreOrder',
        'backorder'      => 'BackOrder',
        'discontinued'   => 'Discontinued',
        'soldout'        => 'SoldOut',
    ];
    $key = strtolower(str_replace([' ', '-'], '', $v));
    return $map[$key] ?? $v;
}

/** PURE. */
function wpultra_schemagen_build_recipe(array $base, array $f): array {
    $out = $base;
    $out['name'] = (string) $f['name'];
    if (!empty($f['image'])) { $out['image'] = $f['image']; }
    if (!empty($f['description'])) { $out['description'] = (string) $f['description']; }
    if (!empty($f['author'])) { $out['author'] = ['@type' => 'Person', 'name' => (string) $f['author']]; }
    $out['recipeIngredient'] = array_values(array_map('strval', (array) $f['recipeIngredient']));

    $steps = [];
    foreach ((array) $f['recipeInstructions'] as $ins) {
        if (is_array($ins)) {
            $steps[] = array_filter([
                '@type' => 'HowToStep',
                'name'  => isset($ins['name']) ? (string) $ins['name'] : null,
                'text'  => (string) ($ins['text'] ?? ''),
            ], static fn($v) => $v !== null && $v !== '');
        } else {
            $steps[] = ['@type' => 'HowToStep', 'text' => (string) $ins];
        }
    }
    $out['recipeInstructions'] = $steps;

    if (isset($f['prepMinutes']) && $f['prepMinutes'] !== '') {
        $out['prepTime'] = wpultra_schemagen_iso_duration((int) $f['prepMinutes']);
    }
    if (isset($f['cookMinutes']) && $f['cookMinutes'] !== '') {
        $out['cookTime'] = wpultra_schemagen_iso_duration((int) $f['cookMinutes']);
    }
    if (isset($f['prepMinutes']) && isset($f['cookMinutes']) && $f['prepMinutes'] !== '' && $f['cookMinutes'] !== '') {
        $out['totalTime'] = wpultra_schemagen_iso_duration((int) $f['prepMinutes'] + (int) $f['cookMinutes']);
    }
    if (!empty($f['recipeYield'])) { $out['recipeYield'] = (string) $f['recipeYield']; }
    if (!empty($f['recipeCategory'])) { $out['recipeCategory'] = (string) $f['recipeCategory']; }
    if (!empty($f['recipeCuisine'])) { $out['recipeCuisine'] = (string) $f['recipeCuisine']; }
    if (!empty($f['calories'])) {
        $out['nutrition'] = ['@type' => 'NutritionInformation', 'calories' => (string) $f['calories']];
    }
    return $out;
}

/** PURE. */
function wpultra_schemagen_build_event(array $base, array $f): array {
    $out = $base;
    $out['name']      = (string) $f['name'];
    $out['startDate'] = (string) $f['startDate'];
    if (!empty($f['endDate'])) { $out['endDate'] = (string) $f['endDate']; }
    if (!empty($f['description'])) { $out['description'] = (string) $f['description']; }
    if (!empty($f['image'])) { $out['image'] = $f['image']; }
    if (!empty($f['eventStatus'])) { $out['eventStatus'] = (string) $f['eventStatus']; }

    $loc = ['@type' => 'Place', 'name' => (string) $f['location']];
    if (!empty($f['address'])) {
        $loc['address'] = ['@type' => 'PostalAddress', 'streetAddress' => (string) $f['address']];
    }
    $out['location'] = $loc;

    if (isset($f['price']) && $f['price'] !== '') {
        $offer = [
            '@type'         => 'Offer',
            'price'         => (string) $f['price'],
            'priceCurrency' => (string) ($f['priceCurrency'] ?? 'USD'),
        ];
        if (!empty($f['availability'])) {
            $offer['availability'] = 'https://schema.org/' . wpultra_schemagen_norm_availability((string) $f['availability']);
        }
        if (!empty($f['url'])) { $offer['url'] = $f['url']; }
        $out['offers'] = $offer;
    }
    return $out;
}

/** PURE. */
function wpultra_schemagen_build_review(array $base, array $f): array {
    $out = $base;
    $itemType = (string) ($f['itemType'] ?? 'Thing');
    $out['itemReviewed'] = ['@type' => $itemType, 'name' => (string) $f['itemReviewed']];
    $rating = [
        '@type'       => 'Rating',
        'ratingValue' => (string) $f['ratingValue'],
        'bestRating'  => (string) ($f['bestRating'] ?? 5),
    ];
    if (isset($f['worstRating']) && $f['worstRating'] !== '') {
        $rating['worstRating'] = (string) $f['worstRating'];
    }
    $out['reviewRating'] = $rating;
    $out['author'] = ['@type' => 'Person', 'name' => (string) $f['author']];
    if (!empty($f['reviewBody'])) { $out['reviewBody'] = (string) $f['reviewBody']; }
    if (!empty($f['datePublished'])) { $out['datePublished'] = (string) $f['datePublished']; }
    return $out;
}

/** PURE. */
function wpultra_schemagen_build_faq(array $base, array $f): array {
    $entities = [];
    foreach ((array) $f['mainEntity'] as $qa) {
        $entities[] = [
            '@type'          => 'Question',
            'name'           => (string) ($qa['question'] ?? ''),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => (string) ($qa['answer'] ?? ''),
            ],
        ];
    }
    return $base + ['mainEntity' => $entities];
}

/** PURE. */
function wpultra_schemagen_build_howto(array $base, array $f): array {
    $out = $base;
    $out['name'] = (string) $f['name'];
    if (!empty($f['description'])) { $out['description'] = (string) $f['description']; }
    if (!empty($f['image'])) { $out['image'] = $f['image']; }
    if (isset($f['totalMinutes']) && $f['totalMinutes'] !== '') {
        $out['totalTime'] = wpultra_schemagen_iso_duration((int) $f['totalMinutes']);
    }
    if (!empty($f['estimatedCost'])) { $out['estimatedCost'] = (string) $f['estimatedCost']; }
    foreach (['supply' => 'HowToSupply', 'tool' => 'HowToTool'] as $k => $itemType) {
        if (!empty($f[$k]) && is_array($f[$k])) {
            $out[$k] = array_map(static fn($n) => ['@type' => $itemType, 'name' => (string) $n], $f[$k]);
        }
    }
    $steps = [];
    $pos = 1;
    foreach ((array) $f['step'] as $st) {
        if (is_array($st)) {
            $step = [
                '@type'    => 'HowToStep',
                'position' => $pos,
                'text'     => (string) ($st['text'] ?? ''),
            ];
            if (!empty($st['name'])) { $step['name'] = (string) $st['name']; }
            if (!empty($st['url'])) { $step['url'] = (string) $st['url']; }
            if (!empty($st['image'])) { $step['image'] = $st['image']; }
        } else {
            $step = ['@type' => 'HowToStep', 'position' => $pos, 'text' => (string) $st];
        }
        $steps[] = $step;
        $pos++;
    }
    $out['step'] = $steps;
    return $out;
}

/** PURE. */
function wpultra_schemagen_build_jobposting(array $base, array $f): array {
    $out = $base;
    $out['title']       = (string) $f['title'];
    $out['description'] = (string) $f['description'];
    $out['datePosted']  = (string) $f['datePosted'];
    if (!empty($f['validThrough'])) { $out['validThrough'] = (string) $f['validThrough']; }
    $out['hiringOrganization'] = ['@type' => 'Organization', 'name' => (string) $f['hiringOrganization']];
    if (!empty($f['url'])) { $out['hiringOrganization']['sameAs'] = $f['url']; }

    if (!empty($f['jobLocation']) || !empty($f['address'])) {
        $addr = ['@type' => 'PostalAddress'];
        if (!empty($f['address'])) { $addr['streetAddress'] = (string) $f['address']; }
        if (!empty($f['jobLocation'])) { $addr['addressLocality'] = (string) $f['jobLocation']; }
        $out['jobLocation'] = ['@type' => 'Place', 'address' => $addr];
    }
    if (isset($f['baseSalary']) && $f['baseSalary'] !== '') {
        $out['baseSalary'] = [
            '@type'    => 'MonetaryAmount',
            'currency' => (string) ($f['salaryCurrency'] ?? 'USD'),
            'value'    => [
                '@type'    => 'QuantitativeValue',
                'value'    => (string) $f['baseSalary'],
                'unitText' => strtoupper((string) ($f['salaryUnit'] ?? 'YEAR')),
            ],
        ];
    }
    if (!empty($f['employmentType'])) {
        $out['employmentType'] = strtoupper(str_replace([' ', '-'], '_', (string) $f['employmentType']));
    }
    return $out;
}

/* =====================================================================
 * PURE — auto-fill from a post + FAQ extraction
 * ===================================================================== */

/**
 * PURE. Best-effort schema draft from a post summary.
 * $post_summary keys: title, excerpt, content, image (featured image URL), url, date.
 * Returns a *field* array (NOT the JSON-LD) so the AI can refine then build.
 */
function wpultra_schemagen_from_post(array $post_summary, string $type): array {
    $title   = trim((string) ($post_summary['title'] ?? ''));
    $excerpt = trim((string) ($post_summary['excerpt'] ?? ''));
    $content = (string) ($post_summary['content'] ?? '');
    $image   = trim((string) ($post_summary['image'] ?? ''));
    $url     = trim((string) ($post_summary['url'] ?? ''));
    $date    = trim((string) ($post_summary['date'] ?? ''));

    // A plain-text description: prefer excerpt, else the first ~200 chars of stripped content.
    $desc = $excerpt !== '' ? $excerpt : wpultra_schemagen_excerpt_from_content($content, 200);

    switch ($type) {
        case 'Product':
            $out = ['name' => $title, 'description' => $desc];
            if ($image !== '') { $out['image'] = $image; }
            if ($url !== '') { $out['url'] = $url; }
            return $out;

        case 'Recipe':
            $out = [
                'name'               => $title,
                'description'        => $desc,
                'recipeIngredient'   => wpultra_schemagen_list_items_from_content($content),
                'recipeInstructions' => wpultra_schemagen_paragraphs_from_content($content),
            ];
            if ($image !== '') { $out['image'] = $image; }
            return $out;

        case 'Event':
            $out = ['name' => $title, 'description' => $desc, 'startDate' => $date, 'location' => ''];
            if ($image !== '') { $out['image'] = $image; }
            return $out;

        case 'Review':
            return ['itemReviewed' => $title, 'reviewBody' => $desc, 'ratingValue' => '', 'author' => ''];

        case 'FAQPage':
            return ['mainEntity' => wpultra_schemagen_faq_from_content($content)];

        case 'HowTo':
            $paras = wpultra_schemagen_paragraphs_from_content($content);
            $steps = array_map(static fn($p) => ['text' => $p], $paras);
            return ['name' => $title, 'description' => $desc, 'step' => $steps];

        case 'JobPosting':
            return [
                'title'              => $title,
                'description'        => $desc !== '' ? $desc : wpultra_schemagen_excerpt_from_content($content, 500),
                'datePosted'         => $date,
                'hiringOrganization' => '',
            ];
    }
    return ['name' => $title, 'description' => $desc];
}

/** PURE. Strip tags, collapse whitespace, truncate to $max chars (word boundary). */
function wpultra_schemagen_excerpt_from_content(string $content, int $max = 200): string {
    $text = trim(preg_replace('/\s+/', ' ', (string) strip_tags($content)));
    if ($text === '' || strlen($text) <= $max) { return $text; }
    $cut = substr($text, 0, $max);
    $sp  = strrpos($cut, ' ');
    if ($sp !== false && $sp > 0) { $cut = substr($cut, 0, $sp); }
    return rtrim($cut) . '...';
}

/** PURE. Extract <li> items from content as a flat string list. */
function wpultra_schemagen_list_items_from_content(string $content): array {
    $items = [];
    if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $content, $m)) {
        foreach ($m[1] as $li) {
            $t = trim(preg_replace('/\s+/', ' ', (string) strip_tags($li)));
            if ($t !== '') { $items[] = $t; }
        }
    }
    return $items;
}

/** PURE. Extract <p> paragraphs from content as plain strings. */
function wpultra_schemagen_paragraphs_from_content(string $content): array {
    $paras = [];
    if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $m)) {
        foreach ($m[1] as $p) {
            $t = trim(preg_replace('/\s+/', ' ', (string) strip_tags($p)));
            if ($t !== '') { $paras[] = $t; }
        }
    }
    return $paras;
}

/**
 * PURE. Extract FAQ Q/A pairs from content that uses <h*>Question</h*> immediately
 * followed by a paragraph (or plain text up to the next heading) as the answer.
 * Returns [['question'=>..,'answer'=>..], ...] or [] when no headings are present.
 */
function wpultra_schemagen_faq_from_content(string $content): array {
    if (!preg_match_all(
        '/<h([1-6])[^>]*>(.*?)<\/h\1>(.*?)(?=<h[1-6][^>]*>|$)/is',
        $content,
        $m,
        PREG_SET_ORDER
    )) {
        return [];
    }
    $pairs = [];
    foreach ($m as $set) {
        $q = trim(preg_replace('/\s+/', ' ', (string) strip_tags($set[2])));
        $a = trim(preg_replace('/\s+/', ' ', (string) strip_tags($set[3])));
        if ($q !== '' && $a !== '') {
            $pairs[] = ['question' => $q, 'answer' => $a];
        }
    }
    return $pairs;
}

/* =====================================================================
 * WP-touching wrappers (guarded)
 * ===================================================================== */

/** Runtime boot hook (called by the controller). Schema is stored per-post and rendered
 *  by the existing SEO head renderer, so this is a cheap no-op. */
function wpultra_schemagen_boot(): void {
    // Intentionally empty: no global hooks needed. Builders are invoked on demand by the
    // schema-generate ability; persistence + wp_head rendering live in includes/seo/technical.php.
}

/**
 * Build a schema draft from an actual post id (best-effort auto-fill).
 * Wraps wpultra_schemagen_from_post with real WP data. Returns a field array.
 */
function wpultra_schemagen_from_post_id(int $post_id, string $type): array {
    if (!function_exists('get_post')) { return wpultra_schemagen_from_post([], $type); }
    $post = get_post($post_id);
    if (!$post) { return wpultra_schemagen_from_post([], $type); }

    $summary = [
        'title'   => function_exists('get_the_title') ? (string) get_the_title($post_id) : (string) ($post->post_title ?? ''),
        'excerpt' => '',
        'content' => (string) ($post->post_content ?? ''),
        'image'   => '',
        'url'     => function_exists('get_permalink') ? (string) get_permalink($post_id) : '',
        'date'    => isset($post->post_date_gmt) ? gmdate('Y-m-d', strtotime((string) $post->post_date_gmt)) : '',
    ];
    if (function_exists('get_the_excerpt')) {
        $summary['excerpt'] = (string) get_the_excerpt($post_id);
    } elseif (!empty($post->post_excerpt)) {
        $summary['excerpt'] = (string) $post->post_excerpt;
    }
    if (function_exists('has_post_thumbnail') && function_exists('get_the_post_thumbnail_url') && has_post_thumbnail($post_id)) {
        $summary['image'] = (string) get_the_post_thumbnail_url($post_id, 'full');
    }
    return wpultra_schemagen_from_post($summary, $type);
}
