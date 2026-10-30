<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$pass = 0; $fail = 0;
function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }

// Finance tolerance hardening
$src019 = file_get_contents($root . '/api/modules/finance/019_canonical_financial_semantics.php');
verify(is_string($src019), '019 readable');
verify(str_contains($src019, 'abs(($base+$tax)-$gross)<=0.01'), 'recognized revenue tolerance is 0.01');
verify(!str_contains($src019, 'isLiquidExternalIncome=$isIncome&&!$isOpeningBalance&&!$isDepositForfeit&&!$isTechnicalNonLiquid&&(!$isInternalTransfer||$isOtaTransfer)'), 'ota_transfer no longer treated as liquid external income');

// Telegram baseAmount no fabrication
$src080 = file_get_contents($root . '/api/routes/080_telegram_webhook.php');
verify(is_string($src080), '080 readable');
verify(!str_contains($src080, "baseAmount ?? \$amount"), 'Telegram no longer fabricates baseAmount from amount');
verify(str_contains($src080, "array_key_exists('baseAmount', \$tgTx)"), 'Telegram baseAmount uses source data');

// POS void partially paid guard
$src065 = file_get_contents($root . '/api/routes/065_pos_minibar.php');
verify(is_string($src065), '065 readable');
verify(str_contains($src065, "in_array(strtolower((string)(\$extra['paymentStatus']??'unpaid')),['paid','partial'],true)"), 'POS void blocks partially paid charges');

// Tenant billing pay existence/status
$src119 = file_get_contents($root . '/api/routes/119_tenant_billing.php');
verify(is_string($src119), '119 readable');
verify(str_contains($src119, "invoice tidak ditemukan"), 'tenant billing pay checks invoice existence');
verify(str_contains($src119, "invoice status tidak issued"), 'tenant billing pay checks invoice status');

// Facility slot conflict includes id
$src096 = file_get_contents($root . '/api/routes/096_facility_management.php');
verify(is_string($src096), '096 readable');
verify(str_contains($src096, 'SELECT id,start_time,end_time FROM facility_bookings'), 'facility slot conflict query includes id');

// Enterprise suite UI wiring
$srcUi = file_get_contents($root . '/assets/enterprise-suite.js');
verify(is_string($srcUi), 'enterprise-suite.js readable');
verify(str_contains($srcUi, "folio-allocation-delete"), 'folio allocation delete wired');
verify(str_contains($srcUi, "folio-invoice-void"), 'folio invoice void wired');
verify(str_contains($srcUi, "supplier-invoice-match"), 'supplier invoice match wired');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);