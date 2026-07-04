<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_schemagen/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/seo/schema-gen.php';

/* ============================================================
 * types() catalog shape
 * ============================================================ */

it('types() returns all 7 supported types', function () {
    $t = wpultra_schemagen_types();
    foreach (['Product', 'Recipe', 'Event', 'Review', 'FAQPage', 'HowTo', 'JobPosting'] as $type) {
        assert_true(isset($t[$type]), "$type present");
    }
    assert_eq(7, count($t));
});

it('every type has required_fields + optional_fields + label + example', function () {
    foreach (wpultra_schemagen_types() as $type => $spec) {
        assert_true(isset($spec['label']) && $spec['label'] !== '', "$type label");
        assert_true(isset($spec['required_fields']) && is_array($spec['required_fields']), "$type required_fields");
        assert_true(isset($spec['optional_fields']) && is_array($spec['optional_fields']), "$type optional_fields");
        assert_true(isset($spec['example']) && is_array($spec['example']) && count($spec['example']) > 0, "$type example");
    }
});

it('is_supported gates unknown types', function () {
    assert_true(wpultra_schemagen_is_supported('Product'));
    assert_true(wpultra_schemagen_is_supported('JobPosting'));
    assert_eq(false, wpultra_schemagen_is_supported('Nonsense'));
});

/* ============================================================
 * iso_duration
 * ============================================================ */

it('iso_duration converts minutes correctly', function () {
    assert_eq('PT1H30M', wpultra_schemagen_iso_duration(90));
    assert_eq('PT45M', wpultra_schemagen_iso_duration(45));
    assert_eq('PT2H', wpultra_schemagen_iso_duration(120));
    assert_eq('PT1H', wpultra_schemagen_iso_duration(60));
    assert_eq('PT0M', wpultra_schemagen_iso_duration(0));
    assert_eq('P1D', wpultra_schemagen_iso_duration(1440));
    assert_eq('P1DT1H', wpultra_schemagen_iso_duration(1500));
});

/* ============================================================
 * ISO date / duration / url validators
 * ============================================================ */

it('is_iso_date accepts dates + datetimes, rejects junk', function () {
    assert_true(wpultra_schemagen_is_iso_date('2026-08-01'));
    assert_true(wpultra_schemagen_is_iso_date('2026-08-01T18:00'));
    assert_true(wpultra_schemagen_is_iso_date('2026-08-01T18:00:00Z'));
    assert_true(wpultra_schemagen_is_iso_date('2026-08-01T18:00:00+02:00'));
    assert_eq(false, wpultra_schemagen_is_iso_date('Aug 1 2026'));
    assert_eq(false, wpultra_schemagen_is_iso_date('2026/08/01'));
    assert_eq(false, wpultra_schemagen_is_iso_date(''));
});

it('is_iso_duration validates ISO-8601 durations', function () {
    assert_true(wpultra_schemagen_is_iso_duration('PT1H30M'));
    assert_true(wpultra_schemagen_is_iso_duration('PT45M'));
    assert_true(wpultra_schemagen_is_iso_duration('P1DT2H'));
    assert_eq(false, wpultra_schemagen_is_iso_duration('P'));
    assert_eq(false, wpultra_schemagen_is_iso_duration('PT'));
    assert_eq(false, wpultra_schemagen_is_iso_duration('1H30M'));
});

it('is_url validates http/https urls', function () {
    assert_true(wpultra_schemagen_is_url('https://example.com/x.jpg'));
    assert_true(wpultra_schemagen_is_url('http://foo.test'));
    assert_eq(false, wpultra_schemagen_is_url('not a url'));
    assert_eq(false, wpultra_schemagen_is_url('ftp://x.com'));
});

/* ============================================================
 * build — Product
 * ============================================================ */

it('build Product nests offers + aggregateRating + brand', function () {
    $out = wpultra_schemagen_build('Product', [
        'name'         => 'Acme Anvil',
        'image'        => 'https://example.com/anvil.jpg',
        'brand'        => 'Acme',
        'sku'          => 'ANV-100',
        'price'        => 199.99,
        'priceCurrency' => 'USD',
        'availability' => 'InStock',
        'ratingValue'  => 4.5,
        'reviewCount'  => 87,
    ]);
    assert_true(is_array($out), 'built');
    assert_eq('https://schema.org', $out['@context']);
    assert_eq('Product', $out['@type']);
    assert_eq('Acme Anvil', $out['name']);
    assert_eq('Brand', $out['brand']['@type']);
    assert_eq('Acme', $out['brand']['name']);
    assert_eq('Offer', $out['offers']['@type']);
    assert_eq('199.99', $out['offers']['price']);
    assert_eq('USD', $out['offers']['priceCurrency']);
    assert_eq('https://schema.org/InStock', $out['offers']['availability']);
    assert_eq('AggregateRating', $out['aggregateRating']['@type']);
    assert_eq('4.5', $out['aggregateRating']['ratingValue']);
    assert_eq('87', $out['aggregateRating']['reviewCount']);
});

it('build Product normalizes availability tokens', function () {
    $out = wpultra_schemagen_build('Product', ['name' => 'X', 'price' => 5, 'availability' => 'outofstock']);
    assert_eq('https://schema.org/OutOfStock', $out['offers']['availability']);
});

/* ============================================================
 * build — Recipe
 * ============================================================ */

it('build Recipe has ingredients + ISO times + HowToStep instructions', function () {
    $out = wpultra_schemagen_build('Recipe', [
        'name'               => 'Cookies',
        'recipeIngredient'   => ['2 cups flour', '1 cup sugar'],
        'recipeInstructions' => ['Mix.', 'Bake.'],
        'prepMinutes'        => 15,
        'cookMinutes'        => 12,
        'recipeYield'        => '24 cookies',
        'calories'           => '150 calories',
    ]);
    assert_eq('Recipe', $out['@type']);
    assert_eq(['2 cups flour', '1 cup sugar'], $out['recipeIngredient']);
    assert_eq('HowToStep', $out['recipeInstructions'][0]['@type']);
    assert_eq('Mix.', $out['recipeInstructions'][0]['text']);
    assert_eq('PT15M', $out['prepTime']);
    assert_eq('PT12M', $out['cookTime']);
    assert_eq('PT27M', $out['totalTime']);
    assert_eq('24 cookies', $out['recipeYield']);
    assert_eq('NutritionInformation', $out['nutrition']['@type']);
    assert_eq('150 calories', $out['nutrition']['calories']);
});

/* ============================================================
 * build — Event
 * ============================================================ */

it('build Event nests location + address + offers, keeps dates', function () {
    $out = wpultra_schemagen_build('Event', [
        'name'      => 'Festival',
        'startDate' => '2026-08-01T18:00',
        'endDate'   => '2026-08-01T23:00',
        'location'  => 'Central Park',
        'address'   => 'New York, NY',
        'price'     => 49.99,
        'priceCurrency' => 'USD',
    ]);
    assert_eq('Event', $out['@type']);
    assert_eq('2026-08-01T18:00', $out['startDate']);
    assert_eq('2026-08-01T23:00', $out['endDate']);
    assert_eq('Place', $out['location']['@type']);
    assert_eq('Central Park', $out['location']['name']);
    assert_eq('PostalAddress', $out['location']['address']['@type']);
    assert_eq('New York, NY', $out['location']['address']['streetAddress']);
    assert_eq('49.99', $out['offers']['price']);
});

/* ============================================================
 * build — Review
 * ============================================================ */

it('build Review nests itemReviewed + reviewRating + author', function () {
    $out = wpultra_schemagen_build('Review', [
        'itemReviewed' => 'Acme Anvil',
        'ratingValue'  => 4.5,
        'bestRating'   => 5,
        'author'       => 'Jane Doe',
        'reviewBody'   => 'Sturdy.',
        'datePublished' => '2026-06-01',
    ]);
    assert_eq('Review', $out['@type']);
    assert_eq('Acme Anvil', $out['itemReviewed']['name']);
    assert_eq('Rating', $out['reviewRating']['@type']);
    assert_eq('4.5', $out['reviewRating']['ratingValue']);
    assert_eq('5', $out['reviewRating']['bestRating']);
    assert_eq('Person', $out['author']['@type']);
    assert_eq('Jane Doe', $out['author']['name']);
    assert_eq('Sturdy.', $out['reviewBody']);
});

/* ============================================================
 * build — FAQPage
 * ============================================================ */

it('build FAQPage produces mainEntity Question/acceptedAnswer', function () {
    $out = wpultra_schemagen_build('FAQPage', [
        'mainEntity' => [
            ['question' => 'Ship worldwide?', 'answer' => 'Yes.'],
            ['question' => 'Returns?', 'answer' => '30 days.'],
        ],
    ]);
    assert_eq('FAQPage', $out['@type']);
    assert_eq(2, count($out['mainEntity']));
    assert_eq('Question', $out['mainEntity'][0]['@type']);
    assert_eq('Ship worldwide?', $out['mainEntity'][0]['name']);
    assert_eq('Answer', $out['mainEntity'][0]['acceptedAnswer']['@type']);
    assert_eq('Yes.', $out['mainEntity'][0]['acceptedAnswer']['text']);
});

/* ============================================================
 * build — HowTo
 * ============================================================ */

it('build HowTo produces positioned steps + totalTime', function () {
    $out = wpultra_schemagen_build('HowTo', [
        'name' => 'Tie a tie',
        'step' => [
            ['name' => 'Cross', 'text' => 'Cross over.'],
            ['text' => 'Loop through.'],
        ],
        'totalMinutes' => 5,
    ]);
    assert_eq('HowTo', $out['@type']);
    assert_eq('PT5M', $out['totalTime']);
    assert_eq('HowToStep', $out['step'][0]['@type']);
    assert_eq(1, $out['step'][0]['position']);
    assert_eq('Cross', $out['step'][0]['name']);
    assert_eq('Cross over.', $out['step'][0]['text']);
    assert_eq(2, $out['step'][1]['position']);
    assert_eq('Loop through.', $out['step'][1]['text']);
});

/* ============================================================
 * build — JobPosting
 * ============================================================ */

it('build JobPosting nests hiringOrganization + baseSalary + jobLocation', function () {
    $out = wpultra_schemagen_build('JobPosting', [
        'title'              => 'WP Dev',
        'description'        => 'Hiring.',
        'datePosted'         => '2026-07-01',
        'hiringOrganization' => 'Acme Corp',
        'jobLocation'        => 'Remote',
        'address'            => 'NYC',
        'baseSalary'         => 120000,
        'salaryCurrency'     => 'USD',
        'salaryUnit'         => 'YEAR',
        'employmentType'     => 'full-time',
    ]);
    assert_eq('JobPosting', $out['@type']);
    assert_eq('WP Dev', $out['title']);
    assert_eq('2026-07-01', $out['datePosted']);
    assert_eq('Organization', $out['hiringOrganization']['@type']);
    assert_eq('Acme Corp', $out['hiringOrganization']['name']);
    assert_eq('Place', $out['jobLocation']['@type']);
    assert_eq('NYC', $out['jobLocation']['address']['streetAddress']);
    assert_eq('Remote', $out['jobLocation']['address']['addressLocality']);
    assert_eq('MonetaryAmount', $out['baseSalary']['@type']);
    assert_eq('USD', $out['baseSalary']['currency']);
    assert_eq('120000', $out['baseSalary']['value']['value']);
    assert_eq('YEAR', $out['baseSalary']['value']['unitText']);
    assert_eq('FULL_TIME', $out['employmentType']);
});

/* ============================================================
 * validate — missing required + shape errors
 * ============================================================ */

it('validate reports missing required fields', function () {
    $res = wpultra_schemagen_validate('Product', []);
    assert_true(is_string($res), 'error string');
    assert_contains('name', $res);
    assert_contains('Missing required', $res);
});

it('validate lists ALL missing required fields for Recipe', function () {
    $res = wpultra_schemagen_validate('Recipe', ['name' => 'X']);
    assert_contains('recipeIngredient', $res);
    assert_contains('recipeInstructions', $res);
});

it('validate rejects non-numeric price', function () {
    $res = wpultra_schemagen_validate('Product', ['name' => 'X', 'price' => 'cheap']);
    assert_contains('price', $res);
});

it('validate rejects rating out of 0..5 range', function () {
    $res = wpultra_schemagen_validate('Product', ['name' => 'X', 'ratingValue' => 9, 'reviewCount' => 3]);
    assert_contains('ratingValue', $res);
});

it('validate requires reviewCount when ratingValue present', function () {
    $res = wpultra_schemagen_validate('Product', ['name' => 'X', 'ratingValue' => 4]);
    assert_contains('reviewCount', $res);
});

it('validate rejects bad ISO date on Event', function () {
    $res = wpultra_schemagen_validate('Event', ['name' => 'X', 'startDate' => 'August 1', 'location' => 'Park']);
    assert_contains('startDate', $res);
});

it('validate rejects Review rating above bestRating', function () {
    $res = wpultra_schemagen_validate('Review', ['itemReviewed' => 'X', 'ratingValue' => 6, 'bestRating' => 5, 'author' => 'Y']);
    assert_contains('ratingValue', $res);
});

it('validate rejects bad datePosted on JobPosting', function () {
    $res = wpultra_schemagen_validate('JobPosting', ['title' => 'X', 'description' => 'Y', 'datePosted' => 'yesterday', 'hiringOrganization' => 'Z']);
    assert_contains('datePosted', $res);
});

it('validate passes a fully valid Product', function () {
    $res = wpultra_schemagen_validate('Product', ['name' => 'X', 'price' => 5, 'ratingValue' => 4, 'reviewCount' => 2]);
    assert_true($res === true, 'valid');
});

it('build returns error string (not array) for invalid input', function () {
    $out = wpultra_schemagen_build('Product', []);
    assert_true(is_string($out), 'error string returned');
    assert_contains('Missing required', $out);
});

it('validate rejects unsupported type', function () {
    $res = wpultra_schemagen_validate('Nonsense', ['x' => 1]);
    assert_contains('Unsupported', $res);
});

/* ============================================================
 * faq_from_content
 * ============================================================ */

it('faq_from_content extracts h2 + following paragraph pairs', function () {
    $html = '<h2>Do you ship?</h2><p>Yes, worldwide.</p><h2>Returns?</h2><p>Within 30 days.</p>';
    $pairs = wpultra_schemagen_faq_from_content($html);
    assert_eq(2, count($pairs));
    assert_eq('Do you ship?', $pairs[0]['question']);
    assert_eq('Yes, worldwide.', $pairs[0]['answer']);
    assert_eq('Returns?', $pairs[1]['question']);
    assert_eq('Within 30 days.', $pairs[1]['answer']);
});

it('faq_from_content works with mixed heading levels', function () {
    $html = '<h3>Q1</h3><p>A1</p><h2>Q2</h2><div>A2</div>';
    $pairs = wpultra_schemagen_faq_from_content($html);
    assert_eq(2, count($pairs));
    assert_eq('Q1', $pairs[0]['question']);
    assert_eq('A2', $pairs[1]['answer']);
});

it('faq_from_content returns [] with no headings', function () {
    assert_eq([], wpultra_schemagen_faq_from_content('<p>Just a paragraph.</p>'));
    assert_eq([], wpultra_schemagen_faq_from_content('Plain text only.'));
});

it('faq_from_content skips headings with empty answers', function () {
    $html = '<h2>Q with answer</h2><p>Answer here.</p><h2>Q no answer</h2>';
    $pairs = wpultra_schemagen_faq_from_content($html);
    assert_eq(1, count($pairs));
    assert_eq('Q with answer', $pairs[0]['question']);
});

/* ============================================================
 * from_post auto-fill
 * ============================================================ */

it('from_post auto-fills Product from summary', function () {
    $fields = wpultra_schemagen_from_post([
        'title'   => 'Cool Widget',
        'excerpt' => 'A widget that is cool.',
        'image'   => 'https://example.com/w.jpg',
        'url'     => 'https://example.com/w',
    ], 'Product');
    assert_eq('Cool Widget', $fields['name']);
    assert_eq('A widget that is cool.', $fields['description']);
    assert_eq('https://example.com/w.jpg', $fields['image']);
    assert_eq('https://example.com/w', $fields['url']);
});

it('from_post derives description from content when no excerpt', function () {
    $fields = wpultra_schemagen_from_post([
        'title'   => 'Post',
        'content' => '<p>The quick brown fox jumps over the lazy dog.</p>',
    ], 'Product');
    assert_contains('quick brown fox', $fields['description']);
});

it('from_post builds Recipe ingredients + instructions from content', function () {
    $fields = wpultra_schemagen_from_post([
        'title'   => 'Soup',
        'content' => '<ul><li>Water</li><li>Salt</li></ul><p>Boil the water.</p><p>Add salt.</p>',
    ], 'Recipe');
    assert_eq('Soup', $fields['name']);
    assert_eq(['Water', 'Salt'], $fields['recipeIngredient']);
    assert_eq(['Boil the water.', 'Add salt.'], $fields['recipeInstructions']);
});

it('from_post builds FAQPage draft from content headings', function () {
    $fields = wpultra_schemagen_from_post([
        'content' => '<h2>Q?</h2><p>A.</p>',
    ], 'FAQPage');
    assert_eq(1, count($fields['mainEntity']));
    assert_eq('Q?', $fields['mainEntity'][0]['question']);
});

it('from_post Recipe draft round-trips through build', function () {
    $fields = wpultra_schemagen_from_post([
        'title'   => 'Soup',
        'excerpt' => 'Warm soup.',
        'content' => '<ul><li>Water</li></ul><p>Boil.</p>',
    ], 'Recipe');
    $built = wpultra_schemagen_build('Recipe', $fields);
    assert_true(is_array($built), 'valid recipe built from auto-fill');
    assert_eq('Recipe', $built['@type']);
});

run_tests();
