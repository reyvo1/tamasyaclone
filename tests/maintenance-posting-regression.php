<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$src = file_get_contents($root . '/api/modules/hr_staff/020_identity_access_audit.php');
$pass = 0; $fail = 0;
function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }

verify(is_string($src) && $src !== '', '020_identity_access_audit.php readable');
verify(str_contains($src, 'function createMaintenanceExpense'), 'createMaintenanceExpense exists');
verify(str_contains($src, 'function createInventoryMaintenanceExpense'), 'createInventoryMaintenanceExpense exists');
verify(str_contains($src, "'transactionKind'=>'maintenance_cost'"), 'maintenance uses canonical maintenance_cost kind');
verify(str_contains($src, "'taxSnapshotStatus'=>'not_applicable'"), 'maintenance posts not_applicable tax snapshot');
verify(str_contains($src, "'taxSource'=>'maintenance'"), 'maintenance posts explicit tax source');
verify(str_contains($src, "tamasyaPostFinancialTransaction"), 'maintenance uses canonical posting authority');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);
