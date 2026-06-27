<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('wp_register_ability')) { function wp_register_ability($n, $a) {} }
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/abilities/execute-wp-query.php';

// Fake $wpdb capturing prepare() + get_results()/query().
class FakeWpdb {
    public string $last_sql = '';
    public ?array $last_params = null;
    public $insert_id = 7;
    public function prepare($sql, ...$args) { $this->last_sql = $sql; $this->last_params = $args ? (is_array($args[0]) ? $args[0] : $args) : []; return $sql; }
    public function get_results($sql, $output) { return [['ID' => 1]]; }
    public function query($sql) { return 2; }
}

it('SELECT returns rows', function () {
    $GLOBALS['wpdb'] = new FakeWpdb();
    $r = wpultra_execute_wp_query(['sql' => 'SELECT * FROM wp_posts WHERE ID = %d', 'params' => [1]]);
    assert_true($r['success'], 'ok');
    assert_eq('SELECT', $r['verb'], 'verb');
    assert_eq([['ID' => 1]], $r['rows'], 'rows');
});
it('destructive query without confirm is rejected', function () {
    $GLOBALS['wpdb'] = new FakeWpdb();
    $r = wpultra_execute_wp_query(['sql' => 'DROP TABLE wp_x']);
    assert_wp_error($r, 'blocked');
    assert_contains('confirm', $r->get_error_message(), 'hint');
});
it('destructive query with confirm runs', function () {
    $GLOBALS['wpdb'] = new FakeWpdb();
    $r = wpultra_execute_wp_query(['sql' => 'DELETE FROM wp_posts', 'confirm' => true]);
    assert_true($r['success'], 'ran');
    assert_eq(2, $r['rows_affected'], 'affected');
});

run_tests();
