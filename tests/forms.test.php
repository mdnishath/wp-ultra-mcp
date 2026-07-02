<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }

// Load the real forms domain — setup + every adapter — so we exercise the ACTUAL
// function names the ability router dispatches to, not stubs. Requiring these under the
// bare harness must never fatal (no WP beyond the harness stubs is loaded).
require __DIR__ . '/../wp-ultra-mcp/includes/forms/setup.php';
require __DIR__ . '/../wp-ultra-mcp/includes/forms/adapters/cf7.php';
require __DIR__ . '/../wp-ultra-mcp/includes/forms/adapters/wpforms.php';
require __DIR__ . '/../wp-ultra-mcp/includes/forms/adapters/gravity.php';
require __DIR__ . '/../wp-ultra-mcp/includes/forms/adapters/fluent.php';

// A representative unified fields[] fixture reused across adapters.
function forms_fixture(): array {
    return [
        ['type' => 'text',     'label' => 'Your Name', 'required' => true],
        ['type' => 'email',    'label' => 'Email Address', 'required' => true],
        ['type' => 'textarea', 'label' => 'Message'],
        ['type' => 'select',   'label' => 'Topic', 'options' => ['Sales', 'Support', 'Other']],
    ];
}

/* ---------------- driver resolution (pure over a detection map) ---------------- */

it('driver resolution honours explicit choice when installed', function () {
    $detected = ['cf7' => '5.9', 'wpforms' => null, 'gravity' => null, 'fluent' => null];
    assert_eq('cf7', wpultra_forms_driver('cf7', $detected));
});

it('driver resolution errors when explicit plugin is not installed', function () {
    $detected = ['cf7' => null, 'wpforms' => null, 'gravity' => null, 'fluent' => null];
    assert_wp_error(wpultra_forms_driver('cf7', $detected));
    $err = wpultra_forms_driver('gravity', $detected);
    assert_eq('forms_unavailable', $err->get_error_code());
});

it('driver resolution errors on an unknown plugin key', function () {
    $err = wpultra_forms_driver('typeform', ['cf7' => '5.9']);
    assert_eq('forms_unknown_plugin', $err->get_error_code());
});

it('driver resolution auto-picks first detected in canonical order', function () {
    $detected = ['cf7' => null, 'wpforms' => null, 'gravity' => '2.8', 'fluent' => '5.1'];
    assert_eq('gravity', wpultra_forms_driver('', $detected));
});

it('driver resolution errors when nothing is installed', function () {
    $detected = ['cf7' => null, 'wpforms' => null, 'gravity' => null, 'fluent' => null];
    $err = wpultra_forms_driver('', $detected);
    assert_wp_error($err);
    assert_eq('forms_unavailable', $err->get_error_code());
});

it('detection never fatals with no plugins present and returns all four keys null', function () {
    $d = wpultra_forms_detect();
    assert_eq(['cf7', 'wpforms', 'gravity', 'fluent'], array_keys($d));
    assert_eq(null, $d['cf7']);
    assert_eq(null, $d['fluent']);
});

/* ---------------- CF7 markup builder (the test file's core) ---------------- */

it('cf7 markup builds a required text tag with a star and label wrapper', function () {
    $markup = wpultra_forms_cf7_markup(forms_fixture());
    assert_contains('[text* your-name-1]', $markup);
    assert_contains('[email* email-address-2]', $markup);
    assert_contains('[textarea message-3]', $markup); // not required -> no star
    assert_contains('<label>Your Name (required)', $markup);
    assert_contains('[submit "Send"]', $markup);
});

it('cf7 markup inlines select options as quoted pipes', function () {
    $markup = wpultra_forms_cf7_markup(forms_fixture());
    assert_contains('[select topic-4 "Sales" "Support" "Other"]', $markup);
});

it('cf7 markup strips double quotes from option values', function () {
    $markup = wpultra_forms_cf7_markup([
        ['type' => 'radio', 'label' => 'Pick', 'options' => ['A "quoted" opt', 'B']],
    ]);
    assert_contains('[radio pick-1 "A quoted opt" "B"]', $markup);
});

it('cf7 name derivation slugifies and appends the index', function () {
    assert_eq('your-name-1', wpultra_forms_cf7_name('Your Name!!', 1));
    assert_eq('field-3', wpultra_forms_cf7_name('###', 3)); // empty slug -> field
});

it('cf7 entry flattener joins multi-value fields and casts id', function () {
    $flat = wpultra_forms_cf7_flatten_entry([
        'id' => '42', 'date' => '2026-07-02',
        'fields' => ['your-name' => 'Bob', 'topics' => ['Sales', 'Support']],
    ]);
    assert_eq(42, $flat['id']);
    assert_eq('Bob', $flat['fields']['your-name']);
    assert_eq('Sales, Support', $flat['fields']['topics']);
});

/* ---------------- WPForms field-JSON builder ---------------- */

it('wpforms field builder maps types and id-keys the map', function () {
    $map = wpultra_forms_wpforms_fields(forms_fixture());
    assert_eq('text', $map['1']['type']);
    assert_eq('email', $map['2']['type']);
    assert_eq('textarea', $map['3']['type']);
    assert_eq('select', $map['4']['type']);
    assert_eq('1', $map['1']['required']); // required -> '1'
    assert_eq('', $map['3']['required']);  // optional -> ''
});

it('wpforms select field carries indexed choices', function () {
    $map = wpultra_forms_wpforms_fields(forms_fixture());
    assert_eq('Sales', $map['4']['choices']['1']['label']);
    assert_eq('Other', $map['4']['choices']['3']['label']);
});

it('wpforms form_data wraps fields with settings + field_id', function () {
    $data = wpultra_forms_wpforms_form_data('Contact', forms_fixture());
    assert_eq(5, $data['field_id']); // 4 fields + 1
    assert_eq('Contact', $data['settings']['form_title']);
    assert_true(isset($data['fields']['1']));
});

it('wpforms entries SQL adds a fields LIKE only when search is set (search filters before LIMIT)', function () {
    $with = wpultra_forms_wpforms_entries_sql('wp_wpforms_entries', true);
    assert_contains('fields LIKE %s', $with);
    assert_contains('LIMIT %d OFFSET %d', $with);
    // placeholder order: form_id, search, per_page, offset
    assert_true(strpos($with, '%s') < strpos($with, 'LIMIT'));
    $without = wpultra_forms_wpforms_entries_sql('wp_wpforms_entries', false);
    assert_true(!str_contains($without, 'LIKE'));
});

it('wpforms entry flattener decodes the JSON fields column to name=>value', function () {
    $row = [
        'entry_id' => '7', 'date' => '2026-07-01',
        'fields' => json_encode([
            '1' => ['name' => 'Name', 'value' => 'Alice', 'id' => '1'],
            '2' => ['name' => 'Email', 'value' => 'a@b.co', 'id' => '2'],
        ]),
    ];
    $flat = wpultra_forms_wpforms_flatten_entry($row);
    assert_eq(7, $flat['id']);
    assert_eq('Alice', $flat['fields']['Name']);
    assert_eq('a@b.co', $flat['fields']['Email']);
});

/* ---------------- Gravity form builder + entry flattener ---------------- */

it('gravity form builder produces sequential field ids and typed fields', function () {
    $form = wpultra_forms_gravity_form('Contact', forms_fixture());
    assert_eq('Contact', $form['title']);
    assert_eq(1, $form['fields'][0]['id']);
    assert_eq('email', $form['fields'][1]['type']);
    assert_true($form['fields'][0]['isRequired']);
    assert_true(!$form['fields'][2]['isRequired']);
});

it('gravity select field carries text/value choices', function () {
    $form = wpultra_forms_gravity_form('Contact', forms_fixture());
    $select = $form['fields'][3];
    assert_eq('select', $select['type']);
    assert_eq('Sales', $select['choices'][0]['text']);
});

it('gravity checkbox field adds an inputs[] entry per choice', function () {
    $form = wpultra_forms_gravity_form('X', [
        ['type' => 'checkbox', 'label' => 'Days', 'options' => ['Mon', 'Tue']],
    ]);
    $cb = $form['fields'][0];
    assert_eq('1.1', $cb['inputs'][0]['id']);
    assert_eq('1.2', $cb['inputs'][1]['id']);
});

it('gravity entry flattener maps numeric keys to labels and skips meta', function () {
    $form = wpultra_forms_gravity_form('Contact', forms_fixture());
    $entry = [
        'id' => '99', 'date_created' => '2026-06-30', 'form_id' => '3',
        '1' => 'Bob', '2' => 'bob@x.co', '4' => 'Sales',
    ];
    $flat = wpultra_forms_gravity_flatten_entry($entry, $form['fields']);
    assert_eq(99, $flat['id']);
    assert_eq('Bob', $flat['fields']['Your Name']);
    assert_eq('Sales', $flat['fields']['Topic']);
    assert_true(!isset($flat['fields']['form_id'])); // meta key skipped
});

it('gravity entry flattener merges composite id.index values under the base label', function () {
    $flat = wpultra_forms_gravity_flatten_entry(
        ['id' => '1', '3.1' => 'A', '3.2' => 'B'],
        [['id' => 3, 'label' => 'Days']]
    );
    assert_eq('A, B', $flat['fields']['Days']);
});

/* ---------------- Fluent field builder + entry flattener ---------------- */

it('fluent field builder maps elements and appends a submit button', function () {
    $data = wpultra_forms_fluent_fields(forms_fixture());
    assert_eq('input_text', $data['fields'][0]['element']);
    assert_eq('input_email', $data['fields'][1]['element']);
    assert_eq('select', $data['fields'][3]['element']);
    // last element is the submit button.
    $last = end($data['fields']);
    assert_eq('button', $last['element']);
});

it('fluent field builder sets required validation and slug names', function () {
    $data = wpultra_forms_fluent_fields(forms_fixture());
    assert_eq('your_name_1', $data['fields'][0]['attributes']['name']);
    assert_true($data['fields'][0]['settings']['validation_rules']['required']['value']);
    assert_true(!$data['fields'][2]['settings']['validation_rules']['required']['value']);
});

it('fluent select field carries advanced_options', function () {
    $data = wpultra_forms_fluent_fields(forms_fixture());
    $opts = $data['fields'][3]['settings']['advanced_options'];
    assert_eq('Sales', $opts[0]['label']);
    assert_eq('Other', $opts[2]['value']);
});

it('fluent entries SQL adds a response LIKE only when search is set (search filters before LIMIT)', function () {
    $with = wpultra_forms_fluent_entries_sql('wp_fluentform_submissions', true);
    assert_contains('response LIKE %s', $with);
    assert_contains('LIMIT %d OFFSET %d', $with);
    // placeholder order: form_id, search, per_page, offset
    assert_true(strpos($with, '%s') < strpos($with, 'LIMIT'));
    $without = wpultra_forms_fluent_entries_sql('wp_fluentform_submissions', false);
    assert_true(!str_contains($without, 'LIKE'));
});

it('fluent entry flattener decodes response JSON and flattens nested groups', function () {
    $row = [
        'id' => '5', 'created_at' => '2026-06-29',
        'response' => json_encode([
            'your_name_1' => 'Carol',
            'names'       => ['first' => 'Carol', 'last' => 'Smith'],
        ]),
    ];
    $flat = wpultra_forms_fluent_flatten_entry($row);
    assert_eq(5, $flat['id']);
    assert_eq('Carol', $flat['fields']['your_name_1']);
    assert_eq('Carol, Smith', $flat['fields']['names']);
});

/* ---------------- shared entry search matcher ---------------- */

it('entry matcher is case-insensitive across field values', function () {
    $entry = ['fields' => ['Name' => 'Bob', 'Topic' => 'Support']];
    assert_true(wpultra_forms_entry_matches($entry, 'sup'));
    assert_true(wpultra_forms_entry_matches($entry, 'BOB'));
    assert_true(!wpultra_forms_entry_matches($entry, 'zzz'));
    assert_true(wpultra_forms_entry_matches($entry, '')); // empty search matches all
});

/* ---------------- status shaping (pure over detection) ---------------- */

it('plugin label + entries_supported degrade correctly with nothing installed', function () {
    $detected = ['cf7' => null, 'wpforms' => null, 'gravity' => null, 'fluent' => null];
    assert_eq('Contact Form 7', wpultra_forms_plugin_label('cf7'));
    assert_true(!wpultra_forms_entries_supported('cf7', $detected)); // no Flamingo
    assert_true(!wpultra_forms_entries_supported('gravity', $detected));
});

run_tests();
