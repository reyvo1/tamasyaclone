<?php
/** Regression: shift gate follows the cash leg, not every live manual entry. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY', true);
$root = dirname(__DIR__);
require_once $root.'/api/modules/finance/025_financial_posting_authority.php';
require_once $root.'/api/support/060_shift_receipts.php';

function shiftPolicyCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$message);
    echo "PASS ".$message."\n";
}

shiftPolicyCheck(function_exists('tamasyaManualTransactionRequiresOpenShift'), 'cash-leg shift helper exists');

shiftPolicyCheck(tamasyaManualTransactionRequiresOpenShift([
    'type'=>'expense','amount'=>125000,'bankAccountId'=>null,
]) === true, 'cash expense requires shift');
shiftPolicyCheck(tamasyaManualTransactionRequiresOpenShift([
    'type'=>'expense','amount'=>125000,'bankAccountId'=>'cash',
]) === true, 'explicit cash account requires shift');
shiftPolicyCheck(tamasyaManualTransactionRequiresOpenShift([
    'type'=>'expense','amount'=>125000,'bankAccountId'=>'bank_bca',
]) === false, 'bank transfer expense does not require shift');
shiftPolicyCheck(tamasyaManualTransactionRequiresOpenShift([
    'type'=>'expense','amount'=>125000,'bankAccountId'=>'qris_bca',
]) === false, 'QRIS expense does not require shift');
shiftPolicyCheck(tamasyaManualTransactionRequiresOpenShift([
    'type'=>'income','amount'=>125000,'bankAccountId'=>'bank_bca',
]) === false, 'bank transfer income does not require shift');
shiftPolicyCheck(tamasyaManualTransactionRequiresOpenShift([
    'type'=>'income','amount'=>125000,'bankAccountId'=>null,
]) === true, 'cash income requires shift');
shiftPolicyCheck(tamasyaManualTransactionRequiresOpenShift([
    'type'=>'expense','amount'=>125000,'bankAccountId'=>null,
    'isSplitPayment'=>1,'splitCashAmount'=>50000,'splitTransferAmount'=>75000,
    'splitTransferBankAccountId'=>'bank_bca',
]) === true, 'split payment with cash requires shift');
shiftPolicyCheck(tamasyaManualTransactionRequiresOpenShift([
    'type'=>'expense','amount'=>125000,'bankAccountId'=>null,
    'isSplitPayment'=>1,'splitCashAmount'=>0,'splitTransferAmount'=>125000,
    'splitTransferBankAccountId'=>'bank_bca',
]) === false, 'split payment without cash does not require shift');

$route = file_get_contents($root.'/api/routes/040_transactions_sync.php');
shiftPolicyCheck(str_contains($route, 'tamasyaManualTransactionRequiresOpenShift'), 'route uses the cash-leg shift helper');
shiftPolicyCheck(str_contains($route, 'Transaksi tunai masih berstatus operasional live dan wajib memiliki shift aktif.'), 'cash-only rejection message is explicit');
shiftPolicyCheck(str_contains($route, 'Pilih akun transfer/QRIS'), 'rejection message offers the non-cash path');
shiftPolicyCheck(!str_contains($route, 'Transaksi masih berstatus operasional live dan wajib memiliki shift aktif.'), 'legacy all-live shift rejection is gone');

$financeUi = file_get_contents($root.'/assets/chunks/finance.js');
shiftPolicyCheck(str_contains($financeUi, 'Shift hanya wajib untuk transaksi tunai.'), 'form help states the cash-only shift rule');

echo "TOTAL: 14 PASS; 0 FAIL\n";
