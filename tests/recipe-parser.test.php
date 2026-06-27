<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', '/tmp/'); }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/recipes/parser.php';

$doc = "---\nname: woo-empty-cart\ndescription: Empty a cart\ncategory: woocommerce\nrun: wp-cli\n---\nNotes here.\n\n```json\n{ \"input\": { \"user_id\": { \"type\": \"integer\", \"required\": true } }, \"command\": [\"wc\", \"cart\", \"empty\", \"--user={user_id}\"] }\n```\n";

it('parses frontmatter + json recipe', function () use ($doc) {
    $r = wpultra_recipe_parse($doc);
    assert_true(!is_wp_error($r), 'parsed');
    assert_eq('woo-empty-cart', $r['name']);
    assert_eq('wp-cli', $r['run']);
    assert_eq('woocommerce', $r['category']);
    assert_eq(true, $r['input']['user_id']['required']);
    assert_eq(['wc', 'cart', 'empty', '--user={user_id}'], $r['recipe']['command']);
});
it('validate accepts a good wp-cli recipe', function () use ($doc) {
    assert_true(wpultra_recipe_validate(wpultra_recipe_parse($doc)) === true, 'valid');
});
it('validate rejects unknown run type', function () {
    $r = ['name' => 'x', 'description' => '', 'category' => '', 'run' => 'bogus', 'input' => [], 'recipe' => []];
    assert_wp_error(wpultra_recipe_validate($r), 'bad run');
});
it('validate rejects bad slug', function () {
    $r = ['name' => 'Bad Slug', 'description' => '', 'category' => '', 'run' => 'php', 'input' => [], 'recipe' => ['code' => '1;']];
    assert_wp_error(wpultra_recipe_validate($r), 'bad slug');
});
it('validate requires command for wp-cli', function () {
    $r = ['name' => 'x', 'description' => '', 'category' => '', 'run' => 'wp-cli', 'input' => [], 'recipe' => []];
    assert_wp_error(wpultra_recipe_validate($r), 'missing command');
});
it('parse errors on invalid json block', function () {
    $bad = "---\nname: x\nrun: php\n---\n```json\n{not json}\n```";
    assert_wp_error(wpultra_recipe_parse($bad), 'bad json');
});

run_tests();
