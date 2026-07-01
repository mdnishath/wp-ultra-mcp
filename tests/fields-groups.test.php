<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$__fails = 0;
function ok($c, $m) { global $__fails; echo ($c ? "PASS" : "FAIL") . ": $m\n"; if (!$c) { $GLOBALS['__fails']++; } }
$__opt = [];
function get_option($k, $d = false) { return $GLOBALS['__opt'][$k] ?? $d; }
require __DIR__ . '/../wp-ultra-mcp/includes/fields/groups.php';
$GLOBALS['__opt']['wpultra_mb_groups'] = ['grp1' => ['id' => 'grp1', 'title' => 'G1', 'post_types' => ['post'], 'fields' => [['id' => 'a', 'type' => 'text'], ['id' => 'b', 'type' => 'text']]]];
$stored = wpultra_fields_mb_stored_groups();
ok(isset($stored['grp1']), 'mb_stored_groups reads option');
$entry = wpultra_fields_mb_group_entry($stored['grp1']);
ok($entry['key'] === 'grp1' && $entry['field_count'] === 2 && $entry['provider'] === 'metabox', 'mb_group_entry shape correct');
$empty = wpultra_fields_mb_group_entry(['id' => 'x']);
ok($empty['field_count'] === 0, 'mb_group_entry no-fields → 0');
echo "\n" . ($__fails === 0 ? 'ALL PASS' : "$__fails FAILED") . "\n";
exit($__fails === 0 ? 0 : 1);
