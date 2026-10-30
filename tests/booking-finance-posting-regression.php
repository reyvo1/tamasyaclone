<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$src = file_get_contents($root . '/api/modules/finance/030_booking_finance.php');
$pass = 0; $fail = 0;
function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }

verify(is_string($src) && $src !== '', '030_booking_finance.php readable');
verify(str_contains($src, 'function recordBookingDeposit'), 'recordBookingDeposit exists');
verify(str_contains($src, 'function createBookingRefundTransactions'), 'createBookingRefundTransactions exists');
verify(str_contains($src, 'function tamasyaRefundShareForBooking'), 'tamasyaRefundShareForBooking exists');
// Fabrication removal: no fallback to amount when baseAmount/taxAmount missing.
verify(!str_contains($src, "(\$income['baseAmount'] ?? \$incomeAmount)"), 'refund share no longer fabricates baseAmount from amount');
verify(!str_contains($src, "(\$share['baseAmount']??\$amount)"), 'refund tx no longer fabricates baseAmount from amount');
verify(str_contains($src, "array_key_exists('baseAmount', \$income) && \$income['baseAmount'] !== null"), 'refund share no longer fabricates baseAmount without source data');
verify(str_contains($src, "array_key_exists('baseAmount',\$share) && \$share['baseAmount'] !== null"), 'refund tx no longer fabricates baseAmount without source data');
// Posting path carries tax snapshot based on source data (confirmed only when baseAmount exists).
verify(str_contains($src, "'taxSnapshotStatus'=> (array_key_exists('baseAmount',\$share)") || str_contains($src, "'taxSnapshotStatus'=>'confirmed'"), 'deposit/refund posts tax snapshot based on source data');
verify(str_contains($src, "'taxSource'=>'live_rule'") || str_contains($src, "'taxSource'=>'booking_refund_snapshot'"), 'deposit/refund posts explicit tax source');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);
