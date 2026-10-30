<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$module = file_get_contents($root . '/api/support/120_promotion_codes.php');
$route = file_get_contents($root . '/api/routes/120_promotion_codes.php');
$router = file_get_contents($root . '/api/router.php');
$db = file_get_contents($root . '/database_setup.sql');
$migration = file_get_contents($root . '/migrations/V138_PROMOTION_CODES.sql');
$sync = file_get_contents($root . '/node_sync_support.php');
$smart = file_get_contents($root . '/api/support/090_security_cluster_smartlock.php');

function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }
$pass = 0; $fail = 0;

verify(is_string($module) && $module !== '', 'module readable');
verify(str_contains($module, 'function tamasyaPromotionCodesEnabled'), 'enabled helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodesSchemaReady'), 'schema helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodeNormalize'), 'normalize helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodeCreate'), 'create helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodeUpdate'), 'update helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodesList'), 'list helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodeValidate'), 'validate helper exists');
verify(str_contains($module, 'function tamasyaPromotionCodeMarkUsed'), 'mark used helper exists');
verify(str_contains($module, 'operation_id'), 'idempotency field present');
verify(str_contains($module, "['fixed', 'percent']") || str_contains($module, "in_array(\$type, ['fixed', 'percent']"), 'discount types supported');
verify(str_contains($module, "['booking', 'pos', 'both']") || str_contains($module, "in_array(\$applies, ['booking', 'pos', 'both']"), 'applies_to supported');

verify(is_string($route) && $route !== '', 'route readable');
verify(str_contains($route, "action ?? ''") && str_contains($route, "promotion-codes"), 'route action registered');
verify(str_contains($route, "case 'save'"), 'save command exists');
verify(str_contains($route, "case 'validate'"), 'validate command exists');
verify(str_contains($route, "case 'list'"), 'list command exists');

verify(str_contains($router, "routes/120_promotion_codes.php"), 'router includes promotion route');

verify(str_contains($db, 'CREATE TABLE `promotion_codes`'), 'schema table present');
verify(str_contains($migration, 'CREATE TABLE IF NOT EXISTS promotion_codes'), 'migration present');

verify(str_contains($sync, "'promotion_codes'=>['pk'=>['id']"), 'sync allowlist present');
verify(str_contains($smart, "'promotion_codes'"), 'smartlock allowlist present');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);