<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$module = file_get_contents($root . '/api/support/120_promotion_codes.php');
$route = file_get_contents($root . '/api/routes/120_promotion_codes.php');
$db = file_get_contents($root . '/database_setup.sql');
$migration138 = file_get_contents($root . '/migrations/V138_PROMOTION_CODES.sql');
$migration139 = @file_get_contents($root . '/migrations/V139_PROMOTION_CODE_USAGES.sql');
$sync = file_get_contents($root . '/node_sync_support.php');
$smart = file_get_contents($root . '/api/support/090_security_cluster_smartlock.php');

function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }
$pass = 0; $fail = 0;

verify(is_string($module) && $module !== '', 'module readable');
verify(str_contains($module, 'function tamasyaPromotionCodeUsageSchemaReady'), 'usage schema helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodeLogUsage'), 'log usage helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodeMarkUsed'), 'mark used helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodeUsagesList'), 'usage list helper exists');
verify(str_contains($module, 'promotion_code_usages'), 'usage table referenced');
verify(str_contains($module, 'operation_id'), 'operation id present');

verify(is_string($route) && $route !== '', 'route readable');
verify(str_contains($route, "case 'usage-list'"), 'usage-list command exists');

verify(str_contains($db, 'CREATE TABLE `promotion_code_usages`'), 'schema usage table present');
verify(is_string($migration138) && str_contains($migration138, 'promotion_code_usages'), 'V138 usage table present');
verify(is_string($migration139) && str_contains($migration139, 'promotion_code_usages'), 'V139 usage table present');

verify(str_contains($sync, "'promotion_code_usages'=>['pk'=>['id']"), 'sync allowlist present');
verify(str_contains($smart, "'promotion_code_usages'"), 'smartlock allowlist present');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);
