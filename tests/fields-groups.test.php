<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$__fails = 0;
function ok($c, $m) { global $__fails; echo ($c ? "PASS" : "FAIL") . ": $m\n"; if (!$c) { $GLOBALS['__fails']++; } }
$__opt = [];
function get_option($k, $d = false) { return $GLOBALS['__opt'][$k] ?? $d; }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
    }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
require __DIR__ . '/../wp-ultra-mcp/includes/fields/groups.php';
$GLOBALS['__opt']['wpultra_mb_groups'] = ['grp1' => ['id' => 'grp1', 'title' => 'G1', 'post_types' => ['post'], 'fields' => [['id' => 'a', 'type' => 'text'], ['id' => 'b', 'type' => 'text']]]];
$stored = wpultra_fields_mb_stored_groups();
ok(isset($stored['grp1']), 'mb_stored_groups reads option');
$entry = wpultra_fields_mb_group_entry($stored['grp1']);
ok($entry['key'] === 'grp1' && $entry['field_count'] === 2 && $entry['provider'] === 'metabox', 'mb_group_entry shape correct');
$empty = wpultra_fields_mb_group_entry(['id' => 'x']);
ok($empty['field_count'] === 0, 'mb_group_entry no-fields → 0');

function update_option($k, $v, $a = null) { $GLOBALS['__opt'][$k] = $v; return true; }
$GLOBALS['__opt']['wpultra_mb_groups'] = [];
$r = wpultra_fields_mb_save_group(['id' => 'g2', 'title' => 'G2', 'post_types' => ['post'], 'fields' => [['id' => 'x', 'type' => 'text']]], 'upsert');
ok(is_array($r) && $r['id'] === 'g2' && $r['count'] >= 1, 'mb_save_group upsert stores');
$reg = wpultra_fields_mb_register_groups([]);
ok(count($reg) >= 1 && $reg[0]['id'] === 'g2', 'mb_register_groups appends stored group to filter output');
$bad = wpultra_fields_mb_save_group(['id' => 'BAD ID', 'title' => 'x', 'fields' => [['id' => 'a', 'type' => 'text']]], 'upsert');
ok(is_wp_error($bad), 'mb_save_group rejects invalid id');

echo "\n" . ($__fails === 0 ? 'ALL PASS' : "$__fails FAILED") . "\n";
exit($__fails === 0 ? 0 : 1);
