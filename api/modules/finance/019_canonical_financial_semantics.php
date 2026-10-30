<?php
/**
 * TAMASYA V137 canonical financial semantics.
 *
 * FINAL11 root hardening: single backend authority for recognized revenue,
 * PBJT/PPh settlement, POS/room/extra buckets, refunds/reversals, and P&L deltas.
 * Server projections carry these semantics to the UI; JavaScript is an offline
 * compatibility fallback only.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Bump whenever journal-account projection semantics change. Existing rows are
 * automatically reprojected once even when the transaction version itself did
 * not change. */
function tamasyaJournalProjectionVersion(): string { return 'transaction_projection_20260910_r2'; }
require_once __DIR__ . '/0185_finance_catalog_identity.php';

/** Source line 1587: transactionJournalAccounts */
function tamasyaIsPbjtSettlementTransaction(array $tx): bool {
    if (strtolower((string)($tx['type'] ?? '')) !== 'expense') return false;
    $kind = strtolower(trim((string)($tx['transactionKind'] ?? '')));
    $systemKey=tamasyaTransactionCategorySystemKey($tx);
    if(in_array($kind,['pbjt_payment','pbjt_settlement'],true) || $systemKey==='pbjt_settlement') return true;
    if(!tamasyaLegacyCatalogTextFallbackAllowed($tx)) return false;
    $category = strtolower(trim((string)($tx['category'] ?? '')));
    $subcategory = strtolower(trim((string)($tx['subcategory'] ?? '')));
    $description = strtolower(trim((string)($tx['description'] ?? '')));
    return str_contains($category,'pbjt') || str_contains($subcategory,'pbjt')
        || str_contains($description,'pbjt') || str_contains($description,'pajak hotel')
        || str_contains($description,'pajak jasa perhotelan');
}

function tamasyaIsIncomeTaxSettlementTransaction(array $tx): bool {
    if (strtolower((string)($tx['type'] ?? '')) !== 'expense' || tamasyaIsPbjtSettlementTransaction($tx)) return false;
    $kind = strtolower(trim((string)($tx['transactionKind'] ?? '')));
    if(in_array($kind,['pph_payment','income_tax_payment','corporate_income_tax_payment'],true)) return true;
    if(!tamasyaLegacyCatalogTextFallbackAllowed($tx)) return false;
    $category = strtolower(trim((string)($tx['category'] ?? '')));
    $subcategory = strtolower(trim((string)($tx['subcategory'] ?? '')));
    $description = strtolower(trim((string)($tx['description'] ?? '')));
    return str_contains($subcategory,'pph') || str_contains($description,'pph')
        || str_contains($description,'pajak penghasilan')
        || (str_contains($category,'pajak') && (str_contains($subcategory,'ssp') || str_contains($description,'ssp')));
}


/** Canonical accounting rule: saldo pembuka adalah mutasi neraca, bukan pendapatan periode. */
function tamasyaIsOpeningBalanceTransaction(array $tx): bool {
    $kind = strtolower(trim((string)($tx['transactionKind'] ?? '')));
    return in_array($kind, ['opening_balance_cash','opening_balance_bank'], true);
}

/** Canonical accounting rule: pendapatan yang diakui mengikuti DPP/base snapshot, bukan kas bruto. */
function tamasyaRecognizedRevenueAmount(array $tx): float {
    $gross=max(0.0,round((float)($tx['amount']??0),2));
    if($gross<=0.0)return 0.0;
    // FIRST: Check if explicit tax split is provided and valid (baseAmount + taxAmount = gross)
    $baseRaw=$tx['baseAmount']??$tx['base_amount']??null;
    $taxRaw=$tx['taxAmount']??$tx['tax_amount']??null;
    if(is_numeric($baseRaw)&&is_numeric($taxRaw)){
        $base=max(0.0,round((float)$baseRaw,2));
        $tax=max(0.0,round((float)$taxRaw,2));
        if(abs(($base+$tax)-$gross)<=0.01){
            return round(max(0.0,$gross-min($gross,$tax)),2);
        }
    }
    // SECOND: Fall back to taxSnapshotStatus
    $status=strtolower(trim((string)($tx['taxSnapshotStatus']??$tx['tax_snapshot_status']??'unresolved')));
    if($status==='unresolved')return 0.0;
    // Missing tax evidence is fail-closed. Only rows explicitly marked not_applicable
    // may use gross as base without a tax split. Unknown historical tax stays suspense.
    if($status==='not_applicable') return $gross;
    return 0.0;
}


/** Canonical semantic flags used by every financial report surface. */
function tamasyaTransactionSemantics(array $tx): array {
    $type=strtolower(trim((string)($tx['type']??'')));
    $kind=strtolower(trim((string)($tx['transactionKind']??$tx['transaction_kind']??'manual')));
    $category=strtolower(trim((string)($tx['category']??'')));
    $subcategory=strtolower(trim((string)($tx['subcategory']??'')));
    $description=strtolower(trim((string)($tx['description']??'')));
    $categorySystemKey=tamasyaTransactionCategorySystemKey($tx);
    $subcategorySystemKey=tamasyaTransactionSubcategorySystemKey($tx);
    $legacyCatalogFallback=tamasyaLegacyCatalogTextFallbackAllowed($tx);
    $sourceEntity=strtolower(trim((string)($tx['sourceEntity']??$tx['source_entity']??'')));
    $bookingSource=strtolower(trim((string)($tx['bookingSource']??$tx['booking_source']??'')));
    $bank=strtolower(trim((string)($tx['bankAccountId']??$tx['bank_account_id']??'')));
    $updatedSource=strtolower(trim((string)($tx['updatedSource']??$tx['updated_source']??'')));
    $isIncome=$type==='income';
    $isExpense=$type==='expense';
    $isInternalTransfer=in_array($kind,['internal_transfer','ota_transfer'],true) || $sourceEntity==='ota_disbursement'
        || ($legacyCatalogFallback && (str_contains($category,'mutasi internal') || str_starts_with($description,'[mutasi]')));
    $isOtaTransfer=$kind==='ota_transfer'||$sourceEntity==='ota_disbursement';
    $isDepositReceipt=$kind==='security_deposit_received';
    $isDepositRefund=$kind==='security_deposit_refund';
    $isDepositForfeit=$kind==='security_deposit_forfeit';
    $isSecurityDeposit=in_array($kind,['security_deposit_received','security_deposit_refund','security_deposit_forfeit'],true);
    $isOpeningBalance=in_array($kind,['opening_balance_cash','opening_balance_bank'],true);
    $isExpenseReversal=$isIncome&&in_array($kind,['salary_reversal','pos_cogs_reversal'],true);
    $isRevenueRefund=$isExpense&&!$isInternalTransfer&&(in_array($kind,['refund','pos_refund'],true) || ($kind==='booking_charge'&&$updatedSource==='audit-correction'));
    $isPbjtSettlement=tamasyaIsPbjtSettlementTransaction($tx);
    $isIncomeTaxSettlement=tamasyaIsIncomeTaxSettlementTransaction($tx);
    $isApPayment=$kind==='supplier_ap_payment';
    $isNonPnlSupplierAccrual=in_array($kind,['supplier_inventory_accrual','supplier_asset_accrual'],true);
    $isTechnicalNonLiquid=in_array($bank,['ota_receivable','inventory_asset','guest_receivable','accounts_payable'],true);
    $taxStatus=strtolower(trim((string)($tx['taxSnapshotStatus']??$tx['tax_snapshot_status']??'unresolved')));
    $isTaxUnresolvedReceipt=$isIncome&&$taxStatus==='unresolved';
    $recognizedRevenue=tamasyaRecognizedRevenueAmount($tx);
    // Tax suspense affects recognized revenue, not the receipt of actual cash.
    $isLiquidExternalIncome=$isIncome&&!$isOpeningBalance&&!$isDepositForfeit&&!$isTechnicalNonLiquid&&!$isInternalTransfer;
    $isLiquidExternalExpense=$isExpense&&!$isTechnicalNonLiquid&&!$isInternalTransfer;

    if($isSecurityDeposit)$bucket='security_deposit';
    elseif($isInternalTransfer)$bucket='internal_transfer';
    elseif(in_array($kind,['pos_sale','pos_refund','pos_room_charge','pos_cogs','pos_cogs_reversal'],true) || $bookingSource==='pos'
        || ($legacyCatalogFallback && (str_contains($category,'pos')||str_contains($category,'minibar')||str_contains($category,'mini bar')||str_contains($subcategory,'pos'))))$bucket='pos';
    elseif($kind==='extension' || ($legacyCatalogFallback && (str_contains($subcategory,'perpanjang')||str_contains($description,'perpanjang'))))$bucket='extension';
    elseif($categorySystemKey==='extra_service' || in_array($kind,['extra','service'],true) || str_contains($kind,'extra') || str_contains($kind,'service')
        || ($kind==='booking_charge' && tamasyaInferBookingChargeAction($tx)==='extra')
        || ($legacyCatalogFallback && (str_contains($category,'layanan')||str_contains($category,'laundry')||str_contains($category,'makanan')
            ||str_contains($subcategory,'tambahan')||str_contains($subcategory,'sarapan')||str_contains($subcategory,'mini bar')
            ||str_contains($subcategory,'laundry')||str_contains($subcategory,'layanan')||str_contains($description,'tambahan')
            ||str_contains($description,'layanan')||str_contains($description,'extra'))))$bucket='extra';
    elseif($categorySystemKey==='room_rental' || in_array($kind,['booking_payment','down_payment','settlement','refund','booking_charge'],true)
        ||trim((string)($tx['bookingId']??$tx['booking_id']??''))!==''||$sourceEntity==='booking'
        ||($legacyCatalogFallback&&str_contains($category,'kamar')))$bucket='room';
    else $bucket=$kind?:'manual';

    $incomeDelta=0.0;
    if(!$isInternalTransfer&&!$isOpeningBalance&&!$isTaxUnresolvedReceipt&&!$isDepositReceipt&&!$isDepositRefund&&!$isExpenseReversal){
        if($isIncome)$incomeDelta=$recognizedRevenue;
        elseif($isRevenueRefund)$incomeDelta=-$recognizedRevenue;
    }
    $expenseDelta=0.0;
    if(!$isInternalTransfer&&!$isSecurityDeposit&&!$isRevenueRefund&&!$isPbjtSettlement&&!$isIncomeTaxSettlement&&!$isApPayment&&!$isNonPnlSupplierAccrual){
        if($isExpense)$expenseDelta=max(0.0,round((float)($tx['amount']??0),2));
        elseif($isExpenseReversal)$expenseDelta=-max(0.0,round((float)($tx['amount']??0),2));
    }

    $result=compact('type','kind','category','subcategory','description','categorySystemKey','subcategorySystemKey','sourceEntity','bookingSource','bank','updatedSource',
        'isIncome','isExpense','isInternalTransfer','isOtaTransfer','isDepositReceipt','isDepositRefund','isDepositForfeit','isSecurityDeposit','isOpeningBalance',
        'isExpenseReversal','isRevenueRefund','isPbjtSettlement','isIncomeTaxSettlement','isApPayment','isNonPnlSupplierAccrual','isTechnicalNonLiquid',
        'isTaxUnresolvedReceipt','recognizedRevenue','bucket','incomeDelta','expenseDelta','isLiquidExternalIncome','isLiquidExternalExpense');
    $result['policyVersion']=tamasyaBusinessPolicyVersion();
    return $result;
}

function tamasyaRecognizedIncomeDelta(array $tx): float {
    return (float)tamasyaTransactionSemantics($tx)['incomeDelta'];
}

function tamasyaRecognizedExpenseDelta(array $tx): float {
    return (float)tamasyaTransactionSemantics($tx)['expenseDelta'];
}

function tamasyaRevenueSemanticBucket(array $tx): string {
    return (string)tamasyaTransactionSemantics($tx)['bucket'];
}

/** FINAL11 canonical journal/projection engine. Moved out of the legacy identity/audit module. */
/** Canonical semantic classifier shared by journal and booking code. */
function tamasyaInferBookingChargeAction(array $tx): string {
    $explicit = strtolower(trim((string)($tx['bookingAction'] ?? $tx['action'] ?? '')));
    if (in_array($explicit, ['extension','extra'], true)) return $explicit;
    if(tamasyaTransactionCategorySystemKey($tx)==='extra_service') return 'extra';
    $kind=strtolower(trim((string)($tx['transactionKind']??'')));
    if($kind==='extension') return 'extension';
    if(str_contains($kind,'extra')||str_contains($kind,'service')) return 'extra';
    if(!tamasyaLegacyCatalogTextFallbackAllowed($tx)) return 'standard';
    $subcategory = strtolower(trim((string)($tx['subcategory'] ?? '')));
    $category = strtolower(trim((string)($tx['category'] ?? '')));
    $description = strtolower(trim((string)($tx['description'] ?? '')));
    if (str_contains($subcategory, 'perpanjang') || str_contains($description, 'perpanjang')) return 'extension';
    if (str_contains($category, 'layanan') || str_contains($category, 'laundry') || str_contains($category, 'makanan') ||
        str_contains($subcategory, 'tambahan') || str_contains($subcategory, 'sarapan') || str_contains($subcategory, 'mini bar') ||
        str_contains($subcategory, 'laundry') || str_contains($subcategory, 'layanan') || str_contains($description, 'tambahan') ||
        str_contains($description, 'layanan') || str_contains($description, 'extra')) return 'extra';
    return 'standard';
}

function transactionJournalAccounts($tx) {
    $kind = strtolower((string)($tx['transactionKind'] ?? 'manual'));
    $category = strtolower((string)($tx['category'] ?? ''));
    $categorySystemKey=tamasyaTransactionCategorySystemKey((array)$tx);
    $legacyCatalogFallback=tamasyaLegacyCatalogTextFallbackAllowed((array)$tx);
    $source = (string)($tx['bookingSource'] ?? '');
    $bank = strtolower((string)($tx['bankAccountId'] ?? ''));
    if ($kind === 'opening_balance_bank') $asset = ['1102','Bank / QRIS'];
    elseif ($kind === 'opening_balance_cash') $asset = ['1101','Kas Tunai'];
    elseif ($kind === 'supplier_ap_payment') $asset = ($bank === '' || $bank === 'cash' || str_contains($bank,'kas')) ? ['1101','Kas Tunai'] : ['1102','Bank / QRIS'];
    elseif (in_array($kind,['supplier_invoice_accrual','supplier_inventory_accrual','supplier_asset_accrual'],true) || $bank === 'accounts_payable') $asset = ['2103','Utang Usaha'];
    elseif (in_array($kind,['pos_cogs','pos_cogs_reversal'],true) || $bank === 'inventory_asset') $asset = ['1201','Persediaan Barang'];
    elseif ($kind === 'pos_room_charge' || $bank === 'guest_receivable') $asset = ['1104','Piutang Tamu / Folio Kamar'];
    elseif ($kind === 'security_deposit_forfeit') $asset = ['2101','Utang Deposito Tamu'];
    // Asset account follows the settlement account, never free-text bookingSource.
    // An OTA booking can still be paid cash/transfer; only ota_receivable is A/R.
    elseif ($bank === 'ota_receivable') $asset = ['1103','Piutang OTA'];
    elseif ($bank === '' || $bank === 'cash' || str_contains($bank,'kas')) $asset = ['1101','Kas Tunai'];
    else $asset = ['1102','Bank / QRIS'];

    if (in_array($kind,['opening_balance_cash','opening_balance_bank'],true)) $counter=['3001','Saldo Awal / Ekuitas Pembuka'];
    elseif (in_array($kind,['security_deposit_received','security_deposit_refund'],true)) $counter=['2101','Utang Deposito Tamu'];
    elseif ($kind === 'security_deposit_forfeit') $counter=['4198','Pendapatan Ganti Rugi Tamu'];
    elseif (str_contains($kind,'ota_transfer') || str_contains($kind,'internal_transfer') || ($legacyCatalogFallback&&str_contains($category,'mutasi internal'))) $counter=['1199','Akun Kliring Mutasi Internal'];
    elseif ($kind === 'salary_reversal') $counter=['5101','Beban Gaji'];
    elseif ($kind === 'supplier_ap_payment') $counter=['2103','Utang Usaha'];
    elseif ($kind === 'supplier_inventory_accrual') $counter=['1201','Persediaan Barang'];
    elseif ($kind === 'supplier_asset_accrual') $counter=['1301','Aset Tetap / Peralatan'];
    elseif ($kind === 'supplier_invoice_accrual') {
        if ($legacyCatalogFallback && (str_contains($category,'pemeliharaan') || str_contains($category,'maintenance'))) $counter=['5201','Beban Pemeliharaan'];
        elseif ($categorySystemKey==='payroll_expense' || ($legacyCatalogFallback&&(str_contains($category,'gaji') || str_contains($category,'tenaga kerja')))) $counter=['5101','Beban Gaji'];
        else $counter=['5900','Beban Operasional'];
    } elseif (($tx['type'] ?? '') === 'income') {
        if ($kind === 'pos_cogs_reversal') $counter=['5301','Harga Pokok Penjualan POS'];
        elseif ($kind === 'pos_sale' || $categorySystemKey==='pos_revenue') $counter=['4103','Pendapatan POS / Minibar'];
        elseif ($kind === 'booking_charge' && tamasyaInferBookingChargeAction((array)$tx)==='extra') $counter=['4102','Pendapatan Layanan'];
        elseif (in_array($kind,['down_payment','booking_payment','settlement','extension'],true)) $counter=['4101','Pendapatan Kamar'];
        elseif ($categorySystemKey==='extra_service' || str_contains($kind,'extra') || str_contains($kind,'service') || ($legacyCatalogFallback&&str_contains($category,'layanan'))) $counter=['4102','Pendapatan Layanan'];
        elseif ($categorySystemKey==='room_rental' || str_contains($kind,'room') || str_contains($kind,'booking') || ($legacyCatalogFallback&&str_contains($category,'kamar'))) $counter=['4101','Pendapatan Kamar'];
        else $counter=['4199','Pendapatan Lain-lain'];
    } else {
        $isAuditBookingReversal = $kind === 'booking_charge' && strtolower((string)($tx['updatedSource'] ?? '')) === 'audit-correction';
        if (tamasyaIsPbjtSettlementTransaction((array)$tx)) $counter=['2102','Utang PBJT'];
        elseif (tamasyaIsIncomeTaxSettlementTransaction((array)$tx)) $counter=['6101','Beban Pajak Penghasilan'];
        elseif ($kind === 'pos_cogs') $counter=['5301','Harga Pokok Penjualan POS'];
        elseif (str_contains($kind,'refund') || $isAuditBookingReversal) $counter=['4201','Retur dan Refund'];
        elseif ($categorySystemKey==='payroll_expense' || str_contains($kind,'salary') || ($legacyCatalogFallback&&str_contains($category,'gaji'))) $counter=['5101','Beban Gaji'];
        elseif (str_contains($kind,'maintenance') || ($legacyCatalogFallback&&str_contains($category,'pemeliharaan'))) $counter=['5201','Beban Pemeliharaan'];
        else $counter=['5900','Beban Operasional'];
    }
    return [$asset,$counter];
}

/** Normalize a gross/base/PBJT snapshot without guessing an unknown tax amount. */
function tamasyaJournalTaxSplit(array $row, float $gross): array {
    $gross=max(0.0,round($gross,2));
    $baseRaw=$row['base_amount']??$row['baseAmount']??null;
    $taxRaw=$row['tax_amount']??$row['taxAmount']??null;
    $status=strtolower(trim((string)($row['tax_snapshot_status']??$row['taxSnapshotStatus']??'unresolved')));
    if(is_numeric($baseRaw)&&is_numeric($taxRaw)){
        $base=max(0.0,round((float)$baseRaw,2));
        $tax=max(0.0,round((float)$taxRaw,2));
        if(abs(($base+$tax)-$gross)<=0.01){
            // tax is the immutable amount; base absorbs only cent-level rounding residue.
            $tax=min($gross,$tax);
            $base=round(max(0.0,$gross-$tax),2);
            return ['gross'=>$gross,'base'=>$base,'tax'=>$tax,'complete'=>true,'status'=>$status];
        }
        // A confirmed/complete/resolved snapshot that does not reconcile is treated
        // as incomplete instead of aborting projection. This keeps confirmed-but-
        // incomplete rows in the suspense lane for explicit revalidation.
    }
    return ['gross'=>$gross,'base'=>$gross,'tax'=>0.0,'complete'=>false,'status'=>$status?:'unresolved'];
}

/** Booking receipts must not be posted as revenue while their tax snapshot is unresolved. */
function tamasyaJournalRequiresTaxSnapshot(array $tx): bool {
    if(($tx['type']??'')!=='income' || tamasyaIsOpeningBalanceTransaction($tx))return false;
    $kind=strtolower((string)($tx['transactionKind']??''));
    $snapshotStatus=strtolower(trim((string)($tx['tax_snapshot_status']??$tx['taxSnapshotStatus']??'')));
    $taxSource=strtolower(trim((string)($tx['tax_source']??$tx['taxSource']??'')));
    if(in_array($kind,['security_deposit_received','security_deposit_forfeit','salary_reversal'],true) || str_contains($kind,'ota_transfer') || str_contains($kind,'internal_transfer')) return false;
    if($snapshotStatus==='unresolved' && $taxSource!=='not_applicable') return true;
    $systemKey=tamasyaTransactionCategorySystemKey($tx);
    if(in_array($systemKey,['room_rental','extra_service'],true)) return true;
    if(in_array($kind,['booking_payment','settlement','down_payment','booking_charge','room','extra','service'],true)
        || str_contains($kind,'booking') || str_contains($kind,'room') || str_contains($kind,'extra') || str_contains($kind,'service')) return true;
    if(!tamasyaLegacyCatalogTextFallbackAllowed($tx)) return false;
    $category=strtolower((string)($tx['category']??''));
    $description=strtolower((string)($tx['description']??''));
    $legacyGuestServiceHint = str_contains($description,'extra bed') || str_contains($description,'extra charge') || str_contains($description,'extra person')
        || (str_contains($description,'tambahan') && str_contains($description,'kamar'));
    return str_contains($category,'kamar') || str_contains($category,'layanan') || $legacyGuestServiceHint;
}

/** Build balanced journal rows as a pure function so tax/accounting logic can be regression-tested. */
function buildTransactionJournalLines(array $tx, array $allocations=[]): array {
    [$asset,$counter]=transactionJournalAccounts($tx);
    $amount=max(0.0,round((float)($tx['amount']??0),2));
    if($amount<=0.0)return [];
    $pbjt=['2102','Utang PBJT'];
    $unresolvedReceipt=['2199','Penerimaan Belum Teridentifikasi Pajak'];
    $unresolvedRefund=['1299','Suspense Refund Belum Direkonsiliasi'];
    $lines=[];
    $append=static function(array $account,float $debit,float $credit)use(&$lines):void{
        $debit=round(max(0.0,$debit),2);$credit=round(max(0.0,$credit),2);
        if($debit<=0.0&&$credit<=0.0)return;
        $key=$account[0].'|'.$account[1];
        if(!isset($lines[$key]))$lines[$key]=['account_code'=>$account[0],'account_name'=>$account[1],'debit'=>0.0,'credit'=>0.0];
        $lines[$key]['debit']=round($lines[$key]['debit']+$debit,2);
        $lines[$key]['credit']=round($lines[$key]['credit']+$credit,2);
    };

    $kind=strtolower((string)($tx['transactionKind']??'manual'));
    $isIncome=(($tx['type']??'')==='income');
    if($isIncome){
        // A canonical split receipt is stored as one transaction snapshot but
        // expands into two settlement debit legs (Kas Tunai + Bank/QRIS). This
        // keeps online/offline backfill representation identical while preserving
        // the correct balances for both cash locations.
        $isSplitPayment=!empty($tx['isSplitPayment']) || strtolower(trim((string)($tx['paymentMethod'] ?? ''))) === 'split';
        $splitCash=max(0.0,round((float)($tx['splitCashAmount']??0),2));
        $splitTransfer=max(0.0,round((float)($tx['splitTransferAmount']??0),2));
        if($isSplitPayment && $splitCash>0 && $splitTransfer>0 && moneyMatches($splitCash+$splitTransfer,$amount,0.01)){
            $append(['1101','Kas Tunai'],$splitCash,0.0);
            $append(['1102','Bank / QRIS'],$splitTransfer,0.0);
        }else{
            $append($asset,$amount,0.0);
        }
        // Deposits, internal transfers, forfeitures and salary reversals follow their
        // dedicated liability/clearing/expense treatment and are not PBJT revenue splits.
        if(tamasyaIsOpeningBalanceTransaction($tx)
            || in_array($kind,['security_deposit_received','security_deposit_forfeit','salary_reversal'],true)
            || str_contains($kind,'ota_transfer') || str_contains($kind,'internal_transfer')){
            $append($counter,0.0,$amount);
        }else{
            $txSplit=tamasyaJournalTaxSplit($tx,$amount);
            $segments=[];$remaining=$amount;$completeAllocationGross=0.0;
            foreach($allocations as $allocation){
                    if($remaining<=0.0001)break;
                    $original=max(0.0,round((float)($allocation['amount']??0),2));
                    if($original<=0.0)continue;
                    $gross=min($remaining,$original);
                    $pseudo=$tx;
                    $allocationType=strtolower((string)($allocation['allocation_type']??$allocation['allocationType']??'room'));
                    $pseudo['type']='income';
                    $pseudo['transactionKind']=$allocationType==='room'?'booking_payment':$allocationType;
                    $pseudo['category']=$allocation['category']??($tx['category']??'');
                    $allocationSystemKey=tamasyaNormalizeCatalogKey($allocation['category_system_key']??$allocation['categorySystemKey']??'');
                    $pseudo['categoryId']=$allocation['category_id']??$allocation['categoryId']??null;
                    $pseudo['categorySystemKey']=$allocationSystemKey!==''?$allocationSystemKey:($allocationType==='extra'?'extra_service':($allocationType==='room'?'room_rental':($tx['categorySystemKey']??null)));
                    $pseudo['subcategory']=$allocation['subcategory']??null;
                    $pseudo['subcategoryId']=$allocation['subcategory_id']??$allocation['subcategoryId']??null;
                    $pseudo['subcategorySystemKey']=$allocation['subcategory_system_key']??$allocation['subcategorySystemKey']??null;
                    [, $segmentCounter]=transactionJournalAccounts($pseudo);
                    $allocationSplit=tamasyaJournalTaxSplit((array)$allocation,$original);
                    $requestedTax=$allocationSplit['complete']?round($allocationSplit['tax']*($gross/$original),2):null;
                    if($allocationSplit['complete'])$completeAllocationGross=round($completeAllocationGross+$gross,2);
                    $segments[]=['gross'=>$gross,'counter'=>$segmentCounter,'requestedTax'=>$requestedTax];
                    $remaining=round(max(0.0,$remaining-$gross),2);
                }
                if($remaining>0.0001||!$segments)$segments[]=['gross'=>$remaining>0.0001?$remaining:$amount,'counter'=>$counter,'requestedTax'=>null];

                if(!$txSplit['complete']&&tamasyaJournalRequiresTaxSnapshot($tx)&&$completeAllocationGross<$amount-0.01){
                    $append($unresolvedReceipt,0.0,$amount);
                }else{
                $targetTax=$txSplit['complete']?(float)$txSplit['tax']:0.0;
                if(!$txSplit['complete']){
                    foreach($segments as $segment)if($segment['requestedTax']!==null)$targetTax+=max(0.0,(float)$segment['requestedTax']);
                    $targetTax=min($amount,round($targetTax,2));
                }
                $fixedTax=0.0;$flexIndexes=[];
                foreach($segments as $idx=>$segment){
                    if($segment['requestedTax']===null)$flexIndexes[]=$idx;
                    else $fixedTax+=max(0.0,(float)$segment['requestedTax']);
                }
                // Transaction-level snapshot is authoritative. Preserve complete
                // allocation snapshots only when their residual can fit entirely inside
                // unresolved segments. Otherwise reallocate the transaction tax across
                // all segments instead of silently understating PBJT.
                $flexGross=0.0;
                foreach($flexIndexes as $idx)$flexGross+=(float)$segments[$idx]['gross'];
                $residualCandidate=round($targetTax-$fixedTax,2);
                $mustReallocateAll=$fixedTax>$targetTax+0.01
                    || ($txSplit['complete']&&count($flexIndexes)===0&&abs($residualCandidate)>0.01)
                    || $residualCandidate>$flexGross+0.01;
                if($mustReallocateAll){
                    $fixedTax=0.0;$flexIndexes=[];$flexGross=0.0;
                    foreach($segments as $idx=>$_){
                        $segments[$idx]['requestedTax']=null;
                        $flexIndexes[]=$idx;
                        $flexGross+=(float)$segments[$idx]['gross'];
                    }
                }
                $residual=round(max(0.0,$targetTax-$fixedTax),2);
                if($residual>0.0){
                    if(!$flexIndexes){
                        $last=array_key_last($segments);
                        $segments[$last]['requestedTax']=round((float)($segments[$last]['requestedTax']??0)+$residual,2);
                    }else{
                        $flexGross=0.0;foreach($flexIndexes as $idx)$flexGross+=(float)$segments[$idx]['gross'];
                        $assigned=0.0;
                        foreach($flexIndexes as $position=>$idx){
                            $tax=($position===count($flexIndexes)-1)
                                ? round($residual-$assigned,2)
                                : round($residual*((float)$segments[$idx]['gross']/max(0.01,$flexGross)),2);
                            $tax=min((float)$segments[$idx]['gross'],max(0.0,$tax));
                            $segments[$idx]['requestedTax']=$tax;$assigned=round($assigned+$tax,2);
                        }
                    }
                }
                $postedTax=0.0;
                foreach($segments as $segment){
                    $tax=min((float)$segment['gross'],max(0.0,round((float)($segment['requestedTax']??0),2)));
                    $base=round(max(0.0,(float)$segment['gross']-$tax),2);
                    $append($segment['counter'],0.0,$base);
                    $postedTax=round($postedTax+$tax,2);
                }
                if(abs($postedTax-$targetTax)>0.01){
                    throw new RuntimeException('PBJT jurnal tidak sama dengan snapshot transaksi.');
                }
                if($postedTax>0.0)$append($pbjt,0.0,$postedTax);
                }
            }
        }else{
        $isAuditBookingReversal=$kind==='booking_charge'&&strtolower((string)($tx['updatedSource']??''))==='audit-correction';
        $isRefund=str_contains($kind,'refund')||$isAuditBookingReversal;
        if($isRefund&&!in_array($kind,['security_deposit_refund'],true)){
            $split=tamasyaJournalTaxSplit($tx,$amount);
            if(!$split['complete']&&tamasyaJournalRequiresTaxSnapshot(['type'=>'income']+$tx)){
                $append($unresolvedRefund,$amount,0.0);
            }else{
                $append($counter,(float)$split['base'],0.0);
                if((float)$split['tax']>0.0)$append($pbjt,(float)$split['tax'],0.0);
            }
            $expenseSplit=!empty($tx['isSplitPayment']);
            $expenseSplitCash=max(0.0,round((float)($tx['splitCashAmount']??0),2));
            $expenseSplitTransfer=max(0.0,round((float)($tx['splitTransferAmount']??0),2));
            if($expenseSplit && $expenseSplitCash>0 && $expenseSplitTransfer>0 && moneyMatches($expenseSplitCash+$expenseSplitTransfer,$amount,0.01)){
                // Controlled audit reversal of a historical split receipt must
                // credit the same asset legs that the original receipt debited.
                $append(['1101','Kas Tunai'],0.0,$expenseSplitCash);
                $append(['1102','Bank / QRIS'],0.0,$expenseSplitTransfer);
            }else{
                $append($asset,0.0,$amount);
            }
        }else{
            $append($counter,$amount,0.0);
            $append($asset,0.0,$amount);
        }
    }
    $result=array_values($lines);
    $debit=0.0;$credit=0.0;
    foreach($result as $row){$debit+=(float)$row['debit'];$credit+=(float)$row['credit'];}
    if(abs($debit-$credit)>0.01)throw new RuntimeException('Proyeksi jurnal tidak seimbang.');
    return $result;
}

/** Financial projection health used by commit guards and enterprise health. */
function tamasyaFinancialIntegrityMetrics(PDO $pdo): array {
    $scalar=static function(PDO $pdo,string $sql){$v=$pdo->query($sql)->fetchColumn();return is_numeric($v)?(float)$v:0.0;};
    $missing=(int)$scalar($pdo,"SELECT COUNT(*) FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id=t.id WHERE j.id IS NULL");
    $projectionSource=tamasyaJournalProjectionVersion();
    $projectionSourceSql=$pdo->quote($projectionSource);
    $stale=(int)$scalar($pdo,"SELECT COUNT(*) FROM transactions t JOIN journal_entries j ON j.transaction_id=t.id WHERE j.source_version<>COALESCE(t.version,1) OR COALESCE(j.source,'')<>".$projectionSourceSql);
    $orphanEntries=(int)$scalar($pdo,"SELECT COUNT(*) FROM journal_entries j LEFT JOIN transactions t ON t.id=j.transaction_id WHERE t.id IS NULL");
    $orphanLines=(int)$scalar($pdo,"SELECT COUNT(*) FROM journal_lines jl LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.id IS NULL");
    $unbalanced=(int)$scalar($pdo,"SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id GROUP BY je.id HAVING COUNT(jl.id)=0 OR ABS(ROUND(COALESCE(SUM(jl.debit),0)-COALESCE(SUM(jl.credit),0),2))>0.01) q");
    $amountMismatch=(int)$scalar($pdo,"SELECT COUNT(*) FROM (SELECT je.id,t.amount,COALESCE(SUM(jl.debit),0) debit_total,COALESCE(SUM(jl.credit),0) credit_total FROM journal_entries je JOIN transactions t ON t.id=je.transaction_id LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id GROUP BY je.id,t.amount HAVING ABS(ROUND(debit_total-t.amount,2))>0.01 OR ABS(ROUND(credit_total-t.amount,2))>0.01) q");
    $orphanAllocTx=(int)$scalar($pdo,"SELECT COUNT(*) FROM transaction_allocations a LEFT JOIN transactions t ON t.id=a.transaction_id WHERE a.status='active' AND t.id IS NULL");
    $orphanAllocBooking=(int)$scalar($pdo,"SELECT COUNT(*) FROM transaction_allocations a LEFT JOIN bookings b ON b.id=a.booking_id WHERE a.status='active' AND a.reporting_only=0 AND b.id IS NULL");
    $badAllocationTax=(int)$scalar($pdo,"SELECT COUNT(*) FROM transaction_allocations a WHERE a.status='active' AND (a.amount<0 OR a.base_amount<0 OR a.tax_amount<0 OR ABS((a.base_amount+a.tax_amount)-a.amount)>0.01 OR a.tax_snapshot_status NOT IN ('confirmed','complete','resolved','not_applicable','unresolved'))");
    $overAllocated=(int)$scalar($pdo,"SELECT COUNT(*) FROM (SELECT t.id,t.amount,COALESCE(SUM(CASE WHEN a.status='active' THEN a.amount ELSE 0 END),0) allocated FROM transactions t JOIN transaction_allocations a ON a.transaction_id=t.id GROUP BY t.id,t.amount HAVING allocated>t.amount+0.01) q");
    $fullAllocationTaxMismatch=(int)$scalar($pdo,"SELECT COUNT(*) FROM (SELECT t.id,t.amount,t.baseAmount,t.taxAmount,COALESCE(SUM(CASE WHEN a.status='active' THEN a.amount ELSE 0 END),0) allocated,COALESCE(SUM(CASE WHEN a.status='active' THEN a.base_amount ELSE 0 END),0) alloc_base,COALESCE(SUM(CASE WHEN a.status='active' THEN a.tax_amount ELSE 0 END),0) alloc_tax FROM transactions t JOIN transaction_allocations a ON a.transaction_id=t.id GROUP BY t.id,t.amount,t.baseAmount,t.taxAmount HAVING ABS(allocated-t.amount)<=0.01 AND (ABS(alloc_base-COALESCE(t.baseAmount,alloc_base))>0.01 OR ABS(alloc_tax-COALESCE(t.taxAmount,alloc_tax))>0.01)) q");
    $unresolvedLiveAllocationTax=(int)$scalar($pdo,"SELECT COUNT(*) FROM transaction_allocations a JOIN transactions t ON t.id=a.transaction_id WHERE a.status='active' AND t.recordOrigin='live_operation' AND t.type='income' AND a.tax_snapshot_status NOT IN ('confirmed','complete','resolved','not_applicable')");
    $difference=round((float)$scalar($pdo,"SELECT COALESCE(SUM(debit),0)-COALESCE(SUM(credit),0) FROM journal_lines"),2);
    $unresolvedLive=(int)$scalar($pdo,"SELECT COUNT(*) FROM transactions t WHERE t.recordOrigin='live_operation' AND t.type='income' AND (t.transactionKind IN ('booking_payment','down_payment','settlement','booking_charge','room','extra','service','pos_sale') OR (COALESCE(t.transactionKind,'manual')='manual' AND COALESCE(t.categorySystemKey,'') IN ('room_rental','extra_service','pos_revenue'))) AND (t.taxSnapshotStatus NOT IN ('confirmed','complete','resolved') OR t.baseAmount IS NULL OR t.taxAmount IS NULL OR ABS((t.baseAmount+t.taxAmount)-t.amount)>0.01 OR NOT ((t.taxSource='live_rule' AND t.taxRuleId IS NOT NULL AND TRIM(t.taxRuleId)<>'') OR (t.taxSource='booking_component_allocation' AND EXISTS(SELECT 1 FROM transaction_allocations ax WHERE ax.transaction_id=t.id AND ax.status='active')) OR (t.transactionKind='pos_sale' AND t.taxSource='pos_item_snapshot' AND t.sourceEntity='pos_sale' AND t.sourceEntityId IS NOT NULL AND TRIM(t.sourceEntityId)<>'' AND EXISTS(SELECT 1 FROM pos_sales ps WHERE ps.id=t.sourceEntityId AND ps.transaction_id=t.id AND ps.status='posted' AND ABS(ps.gross_amount-t.amount)<=0.01 AND ABS(ps.base_amount-t.baseAmount)<=0.01 AND ABS(ps.tax_amount-t.taxAmount)<=0.01) AND EXISTS(SELECT 1 FROM pos_sale_items psi WHERE psi.sale_id=t.sourceEntityId) AND NOT EXISTS(SELECT 1 FROM pos_sale_items psi WHERE psi.sale_id=t.sourceEntityId AND (psi.tax_rule_id IS NULL OR TRIM(psi.tax_rule_id)='' OR psi.gross_amount<0 OR psi.base_amount<0 OR psi.tax_amount<0 OR ABS((psi.base_amount+psi.tax_amount)-psi.gross_amount)>0.01)) AND ABS((SELECT COALESCE(SUM(psi.gross_amount),0) FROM pos_sale_items psi WHERE psi.sale_id=t.sourceEntityId)-t.amount)<=0.01 AND ABS((SELECT COALESCE(SUM(psi.base_amount),0) FROM pos_sale_items psi WHERE psi.sale_id=t.sourceEntityId)-t.baseAmount)<=0.01 AND ABS((SELECT COALESCE(SUM(psi.tax_amount),0) FROM pos_sale_items psi WHERE psi.sale_id=t.sourceEntityId)-t.taxAmount)<=0.01)));");
    return [
        'missingJournals'=>$missing,'staleJournals'=>$stale,'orphanJournalEntries'=>$orphanEntries,
        'orphanJournalLines'=>$orphanLines,'unbalancedJournals'=>$unbalanced,'journalAmountMismatch'=>$amountMismatch,'journalDifference'=>$difference,
        'orphanAllocationTransactions'=>$orphanAllocTx,'orphanAllocationBookings'=>$orphanAllocBooking,'badAllocationTax'=>$badAllocationTax,
        'overAllocatedTransactions'=>$overAllocated,'fullAllocationTaxMismatch'=>$fullAllocationTaxMismatch,
        'unresolvedLiveAllocationTax'=>$unresolvedLiveAllocationTax,'unresolvedLiveTax'=>$unresolvedLive,
        'healthy'=>$missing===0&&$stale===0&&$orphanEntries===0&&$orphanLines===0&&$unbalanced===0&&$amountMismatch===0
            &&$orphanAllocTx===0&&$orphanAllocBooking===0&&$badAllocationTax===0&&$overAllocated===0&&$fullAllocationTaxMismatch===0
            &&$unresolvedLiveAllocationTax===0&&abs($difference)<0.01&&$unresolvedLive===0,
    ];
}

/** Cheap check used on every API commit so non-financial endpoints stay light. */
function tamasyaFinancialProjectionPending(PDO $pdo): bool {
    $projectionSourceSql=$pdo->quote(tamasyaJournalProjectionVersion());
    $sql="SELECT
        EXISTS(SELECT 1 FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id=t.id WHERE j.id IS NULL OR j.source_version<>COALESCE(t.version,1) OR COALESCE(j.source,'')<>".$projectionSourceSql." LIMIT 1)
        OR EXISTS(SELECT 1 FROM journal_entries je LEFT JOIN transactions t ON t.id=je.transaction_id WHERE t.id IS NULL LIMIT 1)
        OR EXISTS(SELECT 1 FROM journal_lines jl LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.id IS NULL LIMIT 1)";
    return (bool)$pdo->query($sql)->fetchColumn();
}

/** Fast fail-closed financial assertion used inside live commits.
 * Uses bounded EXISTS checks instead of full-ledger COUNT/SUM so reception and
 * housekeeping screens stay responsive even after a year of historical data.
 */
function tamasyaFinancialFastAssert(PDO $pdo): void {
    if(tamasyaFinancialProjectionPending($pdo)){
        throw new RuntimeException('Masih ada transaksi yang belum/sudah kedaluwarsa proyeksi jurnalnya.');
    }
    $badJournal=(bool)$pdo->query("SELECT EXISTS(
        SELECT je.id FROM journal_entries je
        JOIN transactions t ON t.id=je.transaction_id
        LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id
        GROUP BY je.id,t.amount
        HAVING COUNT(jl.id)=0
            OR ABS(ROUND(COALESCE(SUM(jl.debit),0)-COALESCE(SUM(jl.credit),0),2))>0.01
            OR ABS(ROUND(COALESCE(SUM(jl.debit),0)-t.amount,2))>0.01
            OR ABS(ROUND(COALESCE(SUM(jl.credit),0)-t.amount,2))>0.01
        LIMIT 1
    )")->fetchColumn();
    if($badJournal) throw new RuntimeException('Jurnal transaksi tidak balance atau nominalnya tidak sama dengan transaksi.');

    $badAllocation=(bool)$pdo->query("SELECT EXISTS(
        SELECT 1 FROM transaction_allocations a
        LEFT JOIN transactions t ON t.id=a.transaction_id
        LEFT JOIN bookings b ON b.id=a.booking_id
        WHERE a.status='active' AND (
            t.id IS NULL
            OR (a.reporting_only=0 AND b.id IS NULL)
            OR a.amount<0 OR a.base_amount<0 OR a.tax_amount<0
            OR ABS((a.base_amount+a.tax_amount)-a.amount)>0.01
            OR a.tax_snapshot_status NOT IN ('confirmed','complete','resolved','not_applicable','unresolved')
            OR (t.recordOrigin='live_operation' AND t.type='income' AND a.tax_snapshot_status NOT IN ('confirmed','complete','resolved','not_applicable'))
        )
        LIMIT 1
    ) OR EXISTS(
        SELECT t.id FROM transactions t JOIN transaction_allocations a ON a.transaction_id=t.id AND a.status='active'
        GROUP BY t.id,t.amount HAVING SUM(a.amount)>t.amount+0.01 LIMIT 1
    ) OR EXISTS(
        SELECT t.id FROM transactions t JOIN transaction_allocations a ON a.transaction_id=t.id AND a.status='active'
        GROUP BY t.id,t.amount,t.baseAmount,t.taxAmount
        HAVING ABS(SUM(a.amount)-t.amount)<=0.01
           AND (ABS(SUM(a.base_amount)-COALESCE(t.baseAmount,SUM(a.base_amount)))>0.01
                OR ABS(SUM(a.tax_amount)-COALESCE(t.taxAmount,SUM(a.tax_amount)))>0.01)
        LIMIT 1
    )")->fetchColumn();
    if($badAllocation) throw new RuntimeException('Alokasi transaksi tidak valid atau melebihi nominal receipt.');

    $badLiveTax=(bool)$pdo->query("SELECT EXISTS(
        SELECT 1 FROM transactions
        WHERE recordOrigin='live_operation' AND type='income'
          AND (transactionKind IN ('booking_payment','down_payment','settlement','booking_charge','room','extra','service','pos_sale')
               OR (COALESCE(transactionKind,'manual')='manual' AND COALESCE(categorySystemKey,'') IN ('room_rental','extra_service')))
          AND (taxSnapshotStatus NOT IN ('confirmed','complete','resolved')
               OR baseAmount IS NULL OR taxAmount IS NULL
               OR ABS((baseAmount+taxAmount)-amount)>0.01
               OR NOT (
                    (taxSource='live_rule' AND taxRuleId IS NOT NULL AND TRIM(taxRuleId)<>'')
                    OR (taxSource='booking_component_allocation' AND EXISTS(
                        SELECT 1 FROM transaction_allocations ax
                        WHERE ax.transaction_id=transactions.id AND ax.status='active'
                    ))
                    OR (transactionKind='pos_sale' AND taxSource='pos_item_snapshot'
                        AND sourceEntity='pos_sale' AND sourceEntityId IS NOT NULL AND TRIM(sourceEntityId)<>''
                        AND EXISTS(
                            SELECT 1 FROM pos_sales ps
                            WHERE ps.id=transactions.sourceEntityId AND ps.transaction_id=transactions.id
                              AND ps.status IN ('posted','voided')
                              AND ABS(ps.gross_amount-transactions.amount)<=0.01
                              AND ABS(ps.base_amount-transactions.baseAmount)<=0.01
                              AND ABS(ps.tax_amount-transactions.taxAmount)<=0.01
                        )
                        AND EXISTS(SELECT 1 FROM pos_sale_items psi WHERE psi.sale_id=transactions.sourceEntityId)
                        AND NOT EXISTS(
                            SELECT 1 FROM pos_sale_items psi
                            WHERE psi.sale_id=transactions.sourceEntityId
                              AND (psi.tax_rule_id IS NULL OR TRIM(psi.tax_rule_id)=''
                                   OR psi.gross_amount<0 OR psi.base_amount<0 OR psi.tax_amount<0
                                   OR ABS((psi.base_amount+psi.tax_amount)-psi.gross_amount)>0.01)
                        )
                        AND ABS((SELECT COALESCE(SUM(psi.gross_amount),0) FROM pos_sale_items psi WHERE psi.sale_id=transactions.sourceEntityId)-transactions.amount)<=0.01
                        AND ABS((SELECT COALESCE(SUM(psi.base_amount),0) FROM pos_sale_items psi WHERE psi.sale_id=transactions.sourceEntityId)-transactions.baseAmount)<=0.01
                        AND ABS((SELECT COALESCE(SUM(psi.tax_amount),0) FROM pos_sale_items psi WHERE psi.sale_id=transactions.sourceEntityId)-transactions.taxAmount)<=0.01
                    )
               ))
        LIMIT 1
    )")->fetchColumn();
    if($badLiveTax) throw new RuntimeException('Ada transaksi live dengan snapshot pajak yang belum valid.');
}

/** Source line 1618: syncJournalProjections
 * Projects every pending/stale transaction in bounded batches. When invoked
 * inside an existing database transaction, journal rows participate in that
 * same commit/rollback boundary.
 */
function syncJournalProjections($pdo, bool $strict = false): bool {
    if (!$pdo instanceof PDO) {
        if($strict) throw new RuntimeException('Database jurnal tidak tersedia.');
        return false;
    }
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $projectionSource=tamasyaJournalProjectionVersion();
        $entry=$pdo->prepare("INSERT INTO journal_entries (id,transaction_id,document_number,entry_date,description,source,source_version,status) VALUES (?,?,?,?,?,?,?,'posted') ON DUPLICATE KEY UPDATE document_number=VALUES(document_number),entry_date=VALUES(entry_date),description=VALUES(description),source=VALUES(source),source_version=VALUES(source_version),status='posted'");
        $deleteLines=$pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id=?");
        $line=$pdo->prepare("INSERT INTO journal_lines (id,journal_entry_id,account_code,account_name,debit,credit) VALUES (?,?,?,?,?,?)");
        $allocationStmt=$pdo->prepare("SELECT allocation_type,amount,base_amount,tax_amount,tax_rate,tax_snapshot_status,tax_source,tax_rule_id,category,subcategory,booking_id FROM transaction_allocations WHERE transaction_id=? AND status='active' ORDER BY created_at,id");
        $batches=0;
        do {
            $projectionSourceSql=$pdo->quote($projectionSource);
            $rows=$pdo->query("SELECT t.* FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id=t.id WHERE j.id IS NULL OR j.source_version<>COALESCE(t.version,1) OR COALESCE(j.source,'')<>".$projectionSourceSql." ORDER BY t.date,t.id LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC)?:[];
            foreach($rows as $tx){
                $tx=tamasyaEnrichTransactionCatalogIdentity($pdo,(array)$tx);
                $entryId='je_'.substr((string)$tx['id'],0,90);
                $entry->execute([$entryId,$tx['id'],$tx['documentNumber']??null,$tx['date'],$tx['description'],$projectionSource,(int)($tx['version']??1)]);
                $deleteLines->execute([$entryId]);
                $allocationStmt->execute([(string)$tx['id']]);
                $journalLines=buildTransactionJournalLines((array)$tx,$allocationStmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
                foreach($journalLines as $index=>$journalLine){
                    $line->execute([
                        $entryId.'_l'.($index+1),$entryId,$journalLine['account_code'],$journalLine['account_name'],
                        $journalLine['debit'],$journalLine['credit']
                    ]);
                }
            }
            $batches++;
            if($batches>200) throw new RuntimeException('Backlog jurnal melebihi batas aman satu request. Jalankan pemeriksaan integritas sebelum melanjutkan operasi.');
        } while(count($rows)===1000);

        // Never auto-delete financial corruption. Orphan journal rows are evidence
        // of an integrity problem and must fail closed so the read-only audit can
        // surface them explicitly instead of silently erasing the anomaly.
        if($strict) tamasyaFinancialFastAssert($pdo);
        if ($ownsTransaction) $pdo->commit();
        return true;
    } catch(Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        error_log(clientExceptionMessage('[api.php] journal projection failed', $e));
        if ($strict) throw $e;
        return false;
    }
}

/** Canonical commit guard for API mutations. Financial rows and their journal
 * projection commit atomically. Non-financial commits pay only one cheap
 * EXISTS check when no projection work is pending.
 */
function tamasyaFinancialCommit(PDO $pdo): void {
    if(!$pdo->inTransaction()) throw new RuntimeException('Commit finansial membutuhkan transaksi database aktif.');
    // Standby authentication/presence is deliberately local and never projects hotel journals.
    $localAction=(string)($GLOBALS['action']??'');
    $localCommand=(string)($GLOBALS['input']['command']??'');
    $localSession=in_array($localAction,['login','verify-2fa','refresh-session'],true)||($localAction==='operations-center'&&in_array($localCommand,['device-heartbeat','logout-current'],true));
    if(function_exists('tamasyaClusterEnabled')&&tamasyaClusterEnabled()&&tamasyaNodeRole()==='local_backup'&&$localSession){$pdo->commit();return;}
    $financialPending=tamasyaFinancialProjectionPending($pdo);
    // Readiness is decided from the routed mutation policy, not merely from a
    // pre-existing journal backlog. This avoids deadlocking Setup Wizard on an
    // imported/legacy DB that needs projection repair while still making every
    // declared live-money route fail closed before it starts.
    if(!empty($GLOBALS['tamasya_financial_mutation_requires_ready']) && function_exists('tamasyaPropertySetupSnapshot')){
        $setup=tamasyaPropertySetupSnapshot($pdo);
        if(empty($setup['ready'])){
            throw new DomainException('Property belum READY. Mutasi finansial ditolak sampai Wizard Setup Hotel selesai dan status READY ditetapkan.');
        }
    }
    if(function_exists('tamasyaMultiRoomSyncFolios'))foreach(array_keys($GLOBALS['tamasya_multi_room_events']??[]) as $groupId){tamasyaMultiRoomSyncState($pdo,$groupId);tamasyaMultiRoomSyncFolios($pdo,$groupId,(array)($GLOBALS['loggedInStaff']??['id'=>null]),(string)($GLOBALS['tamasya_request_operation_id']??'group-refresh'));}
    if($financialPending) syncJournalProjections($pdo,true);
    if(function_exists('tamasyaClusterAssertCommitAuthority'))tamasyaClusterAssertCommitAuthority($pdo);
    $pdo->commit();
}
