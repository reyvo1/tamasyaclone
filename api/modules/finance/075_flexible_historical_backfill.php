<?php
/**
 * Flexible historical/backfill ledger support.
 *
 * Financial principle:
 * - transactions.amount/baseAmount/taxAmount remain the cash/tax source of truth;
 * - createdAt is the immutable application-entry timestamp;
 * - date is the actual cash transaction date used by reports;
 * - serviceDate is the actual stay/service date when different;
 * - reported/closed periods never block data capture, but are flagged for review/amendment.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaHistoricalSourceTypes(): array {
    return ['guest_book','receipt','bank_statement','combined_records','staff_note','legacy_system','other'];
}

function tamasyaNormalizeHistoricalSourceType($value): string {
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, tamasyaHistoricalSourceTypes(), true) ? $normalized : 'other';
}

function tamasyaHistoricalSourceLabel($value): string {
    return match (tamasyaNormalizeHistoricalSourceType($value)) {
        'guest_book' => 'Buku tamu',
        'receipt' => 'Nota/struk',
        'bank_statement' => 'Mutasi bank/QRIS',
        'combined_records' => 'Gabungan buku tamu dan nota',
        'staff_note' => 'Catatan petugas',
        'legacy_system' => 'Data sistem lama/cutover',
        default => 'Sumber lainnya',
    };
}

function tamasyaReportingPeriodKey(string $date): string {
    if (!validIsoDate($date)) throw new InvalidArgumentException('Tanggal periode laporan tidak valid.');
    return substr($date, 0, 7);
}

function tamasyaReportingStatusRank(string $status): int {
    return match (strtolower(trim($status))) {
        'closed' => 3,
        'reported' => 2,
        'open' => 1,
        default => 1,
    };
}

function tamasyaGetReportingPeriod(PDO $pdo, string $periodKey, bool $forUpdate = false): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) throw new InvalidArgumentException('Format periode laporan tidak valid.');
    $sql = "SELECT * FROM financial_reporting_periods WHERE period_key=? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$periodKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) {
        return [
            'period_key'=>$periodKey,
            'cash_status'=>'open',
            'tax_status'=>'open',
            'report_reference'=>null,
            'notes'=>null,
            'status_source'=>'implicit_open',
        ];
    }
    return $row;
}

/**
 * Resolve the control impact without blocking historical capture.
 * A reported/closed period is still writable, but the entry is explicitly
 * registered as an adjustment/amendment candidate.
 */
function tamasyaResolveHistoricalPeriodImpact(PDO $pdo, string $date, string $type, bool $isHistorical, bool $forUpdate = false): array {
    $periodKey = tamasyaReportingPeriodKey($date);
    if (!$isHistorical) {
        return [
            'periodKey'=>$periodKey,
            'cashStatus'=>'open',
            'taxStatus'=>'open',
            'periodStatusAtEntry'=>'open',
            'periodImpactStatus'=>'normal',
            'requiresTaxAmendment'=>false,
            'historicalReviewStatus'=>'not_required',
            'warning'=>null,
            'reportReference'=>null,
        ];
    }

    $period = tamasyaGetReportingPeriod($pdo, $periodKey, $forUpdate);
    $cashStatus = strtolower(trim((string)($period['cash_status'] ?? 'open'))) ?: 'open';
    $taxStatus = strtolower(trim((string)($period['tax_status'] ?? 'open'))) ?: 'open';
    $effectiveStatus = tamasyaReportingStatusRank($cashStatus) >= tamasyaReportingStatusRank($taxStatus)
        ? $cashStatus : $taxStatus;
    $isIncome = strtolower(trim($type)) === 'income';

    if ($effectiveStatus === 'closed') {
        $impact = 'closed_period_amendment';
        $review = 'pending_approval';
        $warning = 'Transaksi berhasil dicatat pada periode yang sudah ditutup. Data tetap masuk sesuai tanggal kejadian, tetapi koreksi laporan dan persetujuan supervisor wajib diselesaikan.';
    } elseif ($effectiveStatus === 'reported') {
        $impact = 'reported_period_adjustment';
        $review = 'pending_review';
        $warning = 'Transaksi berhasil dicatat pada periode yang sudah dilaporkan. Sistem menandainya sebagai penyesuaian dan periode tersebut perlu direkonsiliasi kembali.';
    } else {
        $impact = 'late_entry_open_period';
        $review = 'recorded';
        $warning = 'Data historis berhasil dicatat pada periode terbuka sesuai tanggal transaksi sebenarnya.';
    }

    return [
        'periodKey'=>$periodKey,
        'cashStatus'=>$cashStatus,
        'taxStatus'=>$taxStatus,
        'periodStatusAtEntry'=>$effectiveStatus,
        'periodImpactStatus'=>$impact,
        'requiresTaxAmendment'=>$isIncome && in_array($taxStatus, ['reported','closed'], true),
        'historicalReviewStatus'=>$review,
        'warning'=>$warning,
        'reportReference'=>trim((string)($period['report_reference'] ?? '')) ?: null,
    ];
}

function tamasyaValidateHistoricalBackfillMetadata(array $input, string $transactionDate): array {
    $today = date('Y-m-d');
    $serviceDate = trim((string)($input['serviceDate'] ?? $transactionDate));
    if (!validIsoDate($serviceDate) || $serviceDate > $today) {
        throw new InvalidArgumentException('Tanggal layanan historis wajib valid dan tidak boleh melewati hari ini.');
    }
    $sourceType = tamasyaNormalizeHistoricalSourceType($input['historicalSourceType'] ?? 'guest_book');
    $sourceReference = trim((string)($input['historicalSourceReference'] ?? ''));
    $sourceReportedBy = trim((string)($input['sourceReportedBy'] ?? ''));
    $periodCorrectionReason = trim((string)($input['periodCorrectionReason'] ?? ''));
    if (tamasyaStringLength($sourceReference) > 160) throw new InvalidArgumentException('Referensi sumber historis maksimal 160 karakter.');
    if (tamasyaStringLength($sourceReportedBy) > 120) throw new InvalidArgumentException('Nama petugas/sumber laporan maksimal 120 karakter.');
    if (tamasyaStringLength($periodCorrectionReason) > 255) throw new InvalidArgumentException('Catatan koreksi periode maksimal 255 karakter.');
    return [
        'serviceDate'=>$serviceDate,
        'historicalSourceType'=>$sourceType,
        'historicalSourceReference'=>$sourceReference !== '' ? $sourceReference : null,
        'sourceReportedBy'=>$sourceReportedBy !== '' ? $sourceReportedBy : null,
        'periodCorrectionReason'=>$periodCorrectionReason !== '' ? $periodCorrectionReason : null,
    ];
}

/**
 * Historical cash-period delta follows liquidity, not gross transaction value.
 * Pseudo accounts such as Piutang OTA are balance-sheet positions and must not
 * inflate cash/bank adjustment totals before an actual disbursement occurs.
 */
function tamasyaHistoricalLiquidAmount(array $transaction): float {
    $bank=strtolower(trim((string)($transaction['bankAccountId']??$transaction['bank_account_id']??'')));
    if(in_array($bank,['ota_receivable','inventory_asset','guest_receivable','accounts_payable'],true))return 0.0;
    $sign=strtolower(trim((string)($transaction['type']??'')))==='income'?1.0:-1.0;
    return $sign*(float)($transaction['amount']??0);
}

function tamasyaBackfillInputDelayDays(string $transactionDate, ?string $createdAt = null): int {
    $entryDate = $createdAt && preg_match('/^\d{4}-\d{2}-\d{2}/', $createdAt) ? substr($createdAt, 0, 10) : date('Y-m-d');
    try {
        $from = new DateTimeImmutable($transactionDate . ' 00:00:00');
        $to = new DateTimeImmutable($entryDate . ' 00:00:00');
        return max(0, (int)$from->diff($to)->format('%r%a'));
    } catch (Throwable $ignored) {
        return 0;
    }
}

function tamasyaRegisterHistoricalBackfillAdjustment(
    PDO $pdo,
    array $transaction,
    array $impact,
    array $actor,
    string $reason,
    ?string $operationId = null
): void {
    if (($transaction['recordOrigin'] ?? '') !== 'historical_import') return;
    $impactStatus = (string)($impact['periodImpactStatus'] ?? 'late_entry_open_period');
    if (!in_array($impactStatus, ['reported_period_adjustment','closed_period_amendment'], true)) return;
    $txId = trim((string)($transaction['id'] ?? ''));
    if ($txId === '') return;
    $operationId = trim((string)$operationId);
    if ($operationId === '') $operationId = 'backfill_adjustment_' . $txId . '_v' . max(1, (int)($transaction['version'] ?? 1));
    $id = 'bfa_' . substr(hash('sha256', $operationId), 0, 24);
    $stmt = $pdo->prepare("INSERT INTO historical_backfill_adjustments
        (id,operation_id,transaction_id,period_key,impact_status,cash_delta,tax_base_delta,tax_delta,requires_tax_amendment,review_status,reason,source_type,source_reference,created_by,created_by_name,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
          impact_status=VALUES(impact_status),cash_delta=VALUES(cash_delta),tax_base_delta=VALUES(tax_base_delta),tax_delta=VALUES(tax_delta),
          requires_tax_amendment=VALUES(requires_tax_amendment),review_status=VALUES(review_status),reason=VALUES(reason),
          source_type=VALUES(source_type),source_reference=VALUES(source_reference)");
    $sign = strtolower((string)($transaction['type'] ?? '')) === 'income' ? 1.0 : -1.0;
    $baseRaw = $transaction['baseAmount'] ?? $transaction['base_amount'] ?? null;
    $taxRaw = $transaction['taxAmount'] ?? $transaction['tax_amount'] ?? null;
    $taxBaseUnresolved = ($baseRaw === null || $baseRaw === '');
    $cashDelta = array_key_exists('cashDelta',$impact) ? (float)$impact['cashDelta'] : tamasyaHistoricalLiquidAmount($transaction);
    $taxBaseDelta = array_key_exists('taxBaseDelta',$impact) ? (float)$impact['taxBaseDelta'] : ($taxBaseUnresolved ? 0.0 : $sign*(float)$baseRaw);
    $taxDelta = array_key_exists('taxDelta',$impact) ? (float)$impact['taxDelta'] : $sign*(float)($taxRaw ?? 0);
    $requiresTaxAmendment = !empty($impact['requiresTaxAmendment']) || $taxBaseUnresolved;
    if (abs($cashDelta)<0.005 && abs($taxBaseDelta)<0.005 && abs($taxDelta)<0.005 && !$requiresTaxAmendment) return;
    $stmt->execute([
        $id,$operationId,$txId,(string)($impact['periodKey'] ?? substr((string)($transaction['date'] ?? ''),0,7)),$impactStatus,
        $cashDelta,$taxBaseDelta,$taxDelta,$requiresTaxAmendment?1:0,
        (string)($impact['historicalReviewStatus'] ?? 'pending_review'),$reason,
        (string)($transaction['historicalSourceType'] ?? 'other'),$transaction['historicalSourceReference'] ?? null,
        $actor['id'] ?? null,currentStaffLabel($actor)
    ]);
}

/**
 * Register the true financial delta of an audited historical edit.
 * - Same period: one net delta row.
 * - Date moved across periods: removal from the old period and addition to the new period.
 * Open periods do not require an amendment register.
 */
function tamasyaRegisterHistoricalBackfillEditAdjustments(
    PDO $pdo,
    array $before,
    array $after,
    array $actor,
    string $reason,
    string $operationId
): array {
    $oldDate=(string)($before['date']??'');
    $newDate=(string)($after['date']??'');
    $oldType=strtolower((string)($before['type']??'income'));
    $newType=strtolower((string)($after['type']??'income'));
    $oldImpact=tamasyaResolveHistoricalPeriodImpact($pdo,$oldDate,$oldType,true,true);
    $newImpact=tamasyaResolveHistoricalPeriodImpact($pdo,$newDate,$newType,true,true);
    $oldPeriod=(string)$oldImpact['periodKey'];
    $newPeriod=(string)$newImpact['periodKey'];
    $oldSign=$oldType==='income'?1.0:-1.0;
    $newSign=$newType==='income'?1.0:-1.0;
    $oldBaseUnresolved = ($before['baseAmount'] ?? $before['base_amount'] ?? null) === null || ($before['baseAmount'] ?? $before['base_amount'] ?? '') === '';
    $newBaseUnresolved = ($after['baseAmount'] ?? $after['base_amount'] ?? null) === null || ($after['baseAmount'] ?? $after['base_amount'] ?? '') === '';
    $oldCash=tamasyaHistoricalLiquidAmount($before);
    $newCash=tamasyaHistoricalLiquidAmount($after);
    $oldBase=$oldSign*(float)($before['baseAmount'] ?? $before['base_amount'] ?? 0);
    $newBase=$newSign*(float)($after['baseAmount'] ?? $after['base_amount'] ?? 0);
    $oldTax=$oldSign*(float)($before['taxAmount'] ?? $before['tax_amount'] ?? 0);
    $newTax=$newSign*(float)($after['taxAmount'] ?? $after['tax_amount'] ?? 0);
    $oldNeeds=in_array((string)$oldImpact['periodImpactStatus'],['reported_period_adjustment','closed_period_amendment'],true);
    $newNeeds=in_array((string)$newImpact['periodImpactStatus'],['reported_period_adjustment','closed_period_amendment'],true);
    $warnings=[];

    if($oldPeriod===$newPeriod){
        if($oldNeeds||$newNeeds){
            $impact=tamasyaReportingStatusRank((string)$oldImpact['periodStatusAtEntry'])>=tamasyaReportingStatusRank((string)$newImpact['periodStatusAtEntry'])?$oldImpact:$newImpact;
            $impact['cashDelta']=$newCash-$oldCash;
            $impact['taxBaseDelta']=$newBase-$oldBase;
            $impact['taxDelta']=$newTax-$oldTax;
            $impact['requiresTaxAmendment']=($oldType==='income'||$newType==='income')&&in_array((string)$impact['taxStatus'],['reported','closed'],true) || $oldBaseUnresolved || $newBaseUnresolved;
            tamasyaRegisterHistoricalBackfillAdjustment($pdo,$after,$impact,$actor,$reason,$operationId.'_delta');
            if(!empty($impact['warning']))$warnings[]=$impact['warning'];
        }
    }else{
        if($oldNeeds){
            $impact=$oldImpact;
            $impact['cashDelta']=-$oldCash;
            $impact['taxBaseDelta']=-$oldBase;
            $impact['taxDelta']=-$oldTax;
            $impact['requiresTaxAmendment']=$oldType==='income'&&in_array((string)$impact['taxStatus'],['reported','closed'],true) || $oldBaseUnresolved;
            $oldPseudo=$before;
            $oldPseudo['id']=$after['id']??$before['id']??'';
            tamasyaRegisterHistoricalBackfillAdjustment($pdo,$oldPseudo,$impact,$actor,'Pemindahan dari '.$oldPeriod.': '.$reason,$operationId.'_from_'.$oldPeriod);
            $warnings[]='Periode lama '.$oldPeriod.' harus dikoreksi karena transaksi dipindahkan ke '.$newPeriod.'.';
        }
        if($newNeeds){
            $impact=$newImpact;
            $impact['cashDelta']=$newCash;
            $impact['taxBaseDelta']=$newBase;
            $impact['taxDelta']=$newTax;
            $impact['requiresTaxAmendment']=$newType==='income'&&in_array((string)$impact['taxStatus'],['reported','closed'],true) || $newBaseUnresolved;
            tamasyaRegisterHistoricalBackfillAdjustment($pdo,$after,$impact,$actor,'Pemindahan ke '.$newPeriod.': '.$reason,$operationId.'_to_'.$newPeriod);
            if(!empty($impact['warning']))$warnings[]=$impact['warning'];
        }
    }

    $closed = (string)$oldImpact['periodStatusAtEntry']==='closed' || (string)$newImpact['periodStatusAtEntry']==='closed';
    $requiresReview=$oldNeeds||$newNeeds;
    return [
        'requiresReview'=>$requiresReview,
        'reviewStatus'=>$requiresReview?($closed?'pending_approval':'pending_review'):(string)($newImpact['historicalReviewStatus']??'recorded'),
        'requiresTaxAmendment'=>!empty($oldImpact['requiresTaxAmendment'])||!empty($newImpact['requiresTaxAmendment']),
        'crossPeriod'=>$oldPeriod!==$newPeriod&&$requiresReview,
        'warning'=>$warnings?implode(' ',array_values(array_unique($warnings))):null,
    ];
}

