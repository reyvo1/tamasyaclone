<?php
/**
 * TAMASYA FIX24 — Core financial mutation / correction authority.
 *
 * FIX23 consolidated normal CREATE/posting. FIX24 owns every application-level
 * UPDATE/DELETE of `transactions`: manual correction, reconciliation, shift
 * metadata, tax snapshot repair, allocation linkage, OTA/data-cleanup repair,
 * historical review, and offline historical sync patches.
 *
 * Primary/standby node replication remains a transport exception in
 * node_sync_agent.php; it must never be copied as a business-command pattern.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaFinancialMutationAuthorityVersion(): string { return 'FIX24-core-mutation-authority-1'; }

function tamasyaFinancialMutationOperationDefinitions(): array {
    $manualEdit=[
        'type','category','categoryId','categorySystemKey','subcategory','subcategoryId','subcategorySystemKey',
        'amount','date','description','bookingSource','baseAmount','taxAmount','taxRate','taxSnapshotStatus','taxSource','taxRuleId','taxRuleName','taxNote',
        'transactionKind','serviceDate','historicalSourceType','historicalSourceReference','sourceReportedBy','reportingPeriod','periodStatusAtEntry',
        'periodImpactStatus','requiresTaxAmendment','historicalReviewStatus','periodCorrectionReason','inputDelayDays',
        'roomNumber','bankAccountId','bookingId','proofUrl',
        // FIX50: manual edit owns the split snapshot consistency of canonical rows.
        'isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId'
    ];
    $auditCorrection=[
        'type','category','subcategory','categoryId','categorySystemKey','subcategoryId','subcategorySystemKey','roomNumber','amount','date','description',
        'bankAccountId','bookingSource','baseAmount','taxAmount','taxRate','taxSnapshotStatus','taxSource','taxRuleId','taxRuleName','transactionKind','reconciliationStatus','reconciliationReference',
        // Audit correction must preserve/replace the same one-row split snapshot
        // used by manual create/edit and offline sync. Otherwise a locked split
        // transaction can be reversed as if the whole amount were cash.
        'isSplitPayment','splitCashAmount','splitTransferAmount','splitTransferBankAccountId'
    ];
    $syncPatch=array_values(array_diff(tamasyaCanonicalTransactionColumns(),['id']));
    return [
        'manual_edit'=>['owner'=>'finance_correction','allowed'=>$manualEdit,'touchVersion'=>true,'updatedBy'=>true,'updatedSource'=>true,'splitSnapshot'=>true],
        'manual_delete'=>['owner'=>'finance_correction','delete'=>true],
        'document_number'=>['owner'=>'finance_correction','allowed'=>['documentNumber']],
        'historical_period_adjustment'=>['owner'=>'historical_review','allowed'=>['periodImpactStatus','historicalReviewStatus','requiresTaxAmendment']],
        'historical_review'=>['owner'=>'historical_review','allowed'=>['historicalReviewStatus','historicalReviewedBy','historicalReviewedAt','periodCorrectionReason'],'touchVersion'=>true,'updatedBy'=>true,'updatedSource'=>true],
        'reconciliation'=>['owner'=>'reconciliation','allowed'=>['reconciliationStatus','reconciliationReference']],
        'shift_assign'=>['owner'=>'shift','allowed'=>['shiftSessionId']],
        'shift_lock'=>['owner'=>'shift','allowed'=>['lockedAt']],
        'tax_snapshot'=>['owner'=>'tax_projection','allowed'=>['baseAmount','taxAmount','taxRate','taxSnapshotStatus','taxSource','taxRuleId','taxRuleName'],'touchVersion'=>true,'touchUpdatedAt'=>true,'updatedBy'=>true,'updatedSource'=>true],
        'allocation_touch'=>['owner'=>'booking_allocation','allowed'=>[],'touchVersion'=>true,'touchUpdatedAt'=>true,'updatedBy'=>true,'updatedSource'=>true],
        'allocation_link'=>['owner'=>'booking_allocation','allowed'=>['bookingId','bookingSource','roomNumber'],'touchVersion'=>true,'touchUpdatedAt'=>true,'updatedBy'=>true,'updatedSource'=>true],
        'allocation_unlink'=>['owner'=>'booking_allocation','allowed'=>['bookingId','bookingSource','roomNumber'],'touchVersion'=>true,'touchUpdatedAt'=>true,'updatedBy'=>true,'updatedSource'=>true],
        'ota_cleanup_adjust'=>['owner'=>'ota_cleanup','allowed'=>['amount','baseAmount','reconciliationStatus','reconciliationReference','roomNumber','bookingSource'],'touchVersion'=>true,'touchUpdatedAt'=>true,'updatedBy'=>true,'updatedSource'=>true],
        'ota_cleanup_delete'=>['owner'=>'ota_cleanup','delete'=>true],
        'audit_correction'=>['owner'=>'audit_correction','allowed'=>$auditCorrection,'touchVersion'=>true,'touchUpdatedAt'=>true,'updatedBy'=>true,'updatedSource'=>true,'splitSnapshot'=>true],
        'sync_patch'=>['owner'=>'offline_sync','allowed'=>$syncPatch],
    ];
}

function tamasyaFinancialMutationAuthorityProjection(): array {
    return ['version'=>tamasyaFinancialMutationAuthorityVersion(),'operations'=>tamasyaFinancialMutationOperationDefinitions()];
}

function tamasyaRequireFinancialMutationOperation(string $operation,string $owner): array {
    $defs=tamasyaFinancialMutationOperationDefinitions();
    if(!isset($defs[$operation]))throw new DomainException('Operasi mutasi transaksi tidak terdaftar: '.$operation);
    $def=$defs[$operation];
    if((string)($def['owner']??'')!==$owner)throw new DomainException('Authority '.$owner.' tidak berhak menjalankan mutasi '.$operation.'.');
    return $def;
}

function tamasyaNormalizeFinancialMutationIds(array $ids): array {
    $out=[];
    foreach($ids as $id){$id=trim((string)$id);if($id!=='' && strlen($id)<=50)$out[$id]=true;}
    return array_keys($out);
}

function tamasyaAssertFinancialMutationChanges(array $def,array $changes): void {
    $allowed=array_flip((array)($def['allowed']??[]));
    foreach($changes as $column=>$value){
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/',(string)$column))throw new InvalidArgumentException('Nama kolom mutasi transaksi tidak valid.');
        if(!isset($allowed[$column]))throw new DomainException('Kolom '.$column.' tidak diizinkan pada operasi mutasi transaksi ini.');
    }
}

/** FIX51: manual edit owns split snapshot consistency — pembayaran split wajib membawa kedua kaki akun (tunai + bank). */
function tamasyaNormalizeSplitSnapshotChanges(array $def,array $changes): array {
    $allowed=array_flip((array)($def['allowed']??[]));
    if(!isset($allowed['isSplitPayment'],$allowed['splitCashAmount'],$allowed['splitTransferAmount'],$allowed['splitTransferBankAccountId']))return $changes;
    $hasFlag=array_key_exists('isSplitPayment',$changes);
    $hasParts=array_key_exists('splitCashAmount',$changes)&&array_key_exists('splitTransferAmount',$changes);
    if(!$hasFlag&&!$hasParts)return $changes;
    $cash=array_key_exists('splitCashAmount',$changes)?$changes['splitCashAmount']:null;
    $transfer=array_key_exists('splitTransferAmount',$changes)?$changes['splitTransferAmount']:null;
    $isSplit=$hasFlag?(int)(bool)$changes['isSplitPayment']:(((float)($cash??0)>0&&(float)($transfer??0)>0)?1:0);
    if(!$isSplit){
        if($hasFlag){
            $changes['isSplitPayment']=0;
            $changes['splitCashAmount']=0;
            $changes['splitTransferAmount']=0;
            $changes['splitTransferBankAccountId']=null;
        }
        return $changes;
    }
    $bank=array_key_exists('splitTransferBankAccountId',$changes)?$changes['splitTransferBankAccountId']:null;
    if($bank===null||in_array(trim((string)$bank),['','0'],true))$bank=array_key_exists('bankAccountId',$changes)?$changes['bankAccountId']:null;
    $hasTransferLeg=(float)($transfer??0)>0;
    if($hasTransferLeg&&($bank===null||in_array(trim((string)$bank),['','0'],true)))throw new InvalidArgumentException('Pembayaran split wajib memilih akun bank untuk bagian QRIS/transfer.');
    $changes['isSplitPayment']=1;
    if($hasTransferLeg){
        $changes['splitTransferBankAccountId']=$bank;
        // One-row split receipts use the dedicated transfer-leg account. Keeping
        // the same bank in bankAccountId would make edit persistence differ from
        // create/offline sync and lets non-split readers double-classify the row.
        if(isset($allowed['bankAccountId']))$changes['bankAccountId']=null;
    }
    if($cash!==null&&$transfer!==null&&isset($allowed['amount']))$changes['amount']=(float)$cash+(float)$transfer;
    return $changes;
}

/** Internal exact-ID mutation grammar. Callers choose a registered operation only. */
function tamasyaMutateFinancialTransactions(PDO $pdo,array $ids,array $changes,array $actor,string $owner,string $operation,array $options=[]): int {
    if(!$pdo->inTransaction())throw new RuntimeException('Mutasi transaksi canonical wajib berada dalam transaksi database aktif.');
    $ids=tamasyaNormalizeFinancialMutationIds($ids);
    if(!$ids)throw new InvalidArgumentException('ID transaksi mutasi wajib diisi.');
    $def=tamasyaRequireFinancialMutationOperation($operation,$owner);
    if(!empty($def['delete']))throw new DomainException('Operasi delete wajib memakai tamasyaDeleteFinancialTransactions().');
    if(!empty($def['splitSnapshot']))$changes=tamasyaNormalizeSplitSnapshotChanges($def,$changes);
    tamasyaAssertFinancialMutationChanges($def,$changes);

    $ph=implode(',',array_fill(0,count($ids),'?'));
    if(!empty($options['lockRows'])){
        $lock=$pdo->prepare("SELECT id,lockedAt FROM transactions WHERE id IN ($ph) FOR UPDATE");$lock->execute($ids);
        $lockedRows=$lock->fetchAll(PDO::FETCH_ASSOC)?:[];
        $found=array_map(static fn($row)=>strval($row['id']),$lockedRows);
        if(!empty($options['requireAll']) && count(array_unique($found))!==count($ids))throw new RuntimeException('Salah satu transaksi target mutasi tidak ditemukan.');
        if(!empty($options['requireUnlocked'])){
            $lockedIds=[];
            foreach($lockedRows as $row)if(trim((string)($row['lockedAt']??''))!=='')$lockedIds[]=(string)$row['id'];
            if($lockedIds)throw new DomainException('Transaksi terkunci tidak dapat diubah: '.implode(', ',array_unique($lockedIds)).'.');
        }
    }

    $sets=[];$params=[];
    $fillIfEmpty=array_flip((array)($options['fillIfEmpty']??[]));
    $currentTimestamp=array_flip((array)($options['currentTimestampFields']??[]));
    $coalesceNonEmpty=array_flip((array)($options['coalesceNonEmpty']??[]));
    foreach($changes as $column=>$value){
        $quoted='`'.$column.'`';
        if(isset($currentTimestamp[$column])){$sets[]=$quoted.'=CURRENT_TIMESTAMP';continue;}
        if(isset($fillIfEmpty[$column])){$sets[]=$quoted."=CASE WHEN $quoted IS NULL OR $quoted='' THEN ? ELSE $quoted END";$params[]=$value;continue;}
        if(isset($coalesceNonEmpty[$column])){$sets[]=$quoted.'=COALESCE(NULLIF(?,\'\'),'.$quoted.')';$params[]=$value;continue;}
        $sets[]=$quoted.'=?';$params[]=$value;
    }
    if(!empty($def['touchVersion']))$sets[]='version=version+1';
    if(!empty($def['touchUpdatedAt']))$sets[]='updatedAt=CURRENT_TIMESTAMP';
    if(!empty($def['updatedBy'])){$sets[]='updatedBy=?';$params[]=$actor['id']??null;}
    if(!empty($def['updatedSource'])){$sets[]='updatedSource=?';$params[]=trim((string)($options['source']??'web'))?:'web';}
    if(!$sets)throw new InvalidArgumentException('Tidak ada perubahan transaksi canonical untuk diterapkan.');

    $where="id IN ($ph)";
    $whereParams=$ids;
    if(!empty($options['requireUnlocked']))$where.=' AND lockedAt IS NULL';
    if(!empty($options['requireLive']))$where.=" AND COALESCE(recordOrigin,'live_operation')='live_operation'";
    if(!empty($options['requireShiftEligible']))$where.=" AND COALESCE(shiftExempt,0)=0";
    $stmt=$pdo->prepare('UPDATE transactions SET '.implode(',',$sets).' WHERE '.$where);
    $stmt->execute(array_merge($params,$whereParams));
    return $stmt->rowCount();
}

function tamasyaMutateFinancialTransaction(PDO $pdo,string $id,array $changes,array $actor,string $owner,string $operation,array $options=[]): int {
    return tamasyaMutateFinancialTransactions($pdo,[$id],$changes,$actor,$owner,$operation,$options);
}

function tamasyaDeleteFinancialTransactions(PDO $pdo,array $ids,array $actor,string $owner,string $operation,array $options=[]): int {
    if(!$pdo->inTransaction())throw new RuntimeException('Penghapusan transaksi canonical wajib berada dalam transaksi database aktif.');
    $ids=tamasyaNormalizeFinancialMutationIds($ids);
    if(!$ids)throw new InvalidArgumentException('ID transaksi penghapusan wajib diisi.');
    $def=tamasyaRequireFinancialMutationOperation($operation,$owner);
    if(empty($def['delete']))throw new DomainException('Operasi mutasi ini bukan operasi delete canonical.');
    $ph=implode(',',array_fill(0,count($ids),'?'));
    if(!empty($options['lockRows'])){
        $lock=$pdo->prepare("SELECT id FROM transactions WHERE id IN ($ph) FOR UPDATE");$lock->execute($ids);
        $found=array_map('strval',$lock->fetchAll(PDO::FETCH_COLUMN)?:[]);
        if(!empty($options['requireAll']) && count(array_unique($found))!==count($ids))throw new RuntimeException('Salah satu transaksi target penghapusan tidak ditemukan.');
    }
    $stmt=$pdo->prepare("DELETE FROM transactions WHERE id IN ($ph)");$stmt->execute($ids);
    return $stmt->rowCount();
}

function tamasyaEnsureTransactionDocumentNumber(PDO $pdo,string $id,string $documentNumber,array $actor=[]): int {
    if(!$pdo->inTransaction())throw new RuntimeException('Penomoran dokumen transaksi wajib berada dalam transaksi database aktif.');
    $id=trim($id);$documentNumber=trim($documentNumber);
    if($id===''||$documentNumber==='')throw new InvalidArgumentException('ID transaksi dan nomor dokumen wajib diisi.');
    tamasyaRequireFinancialMutationOperation('document_number','finance_correction');
    $stmt=$pdo->prepare("UPDATE transactions SET documentNumber=? WHERE id=? AND (documentNumber IS NULL OR documentNumber='')");
    $stmt->execute([$documentNumber,$id]);return $stmt->rowCount();
}

function tamasyaSetTransactionReconciliation(PDO $pdo,string $id,string $status,?string $reference,array $actor=[]): int {
    return tamasyaMutateFinancialTransaction($pdo,$id,['reconciliationStatus'=>$status,'reconciliationReference'=>$reference],$actor,'reconciliation','reconciliation',['lockRows'=>true,'requireAll'=>true]);
}

function tamasyaAssignTransactionShiftIfEligible(PDO $pdo,string $id,string $shiftId,array $actor=[]): int {
    tamasyaRequireFinancialMutationOperation('shift_assign','shift');
    if(!$pdo->inTransaction())throw new RuntimeException('Pengaitan shift wajib berada dalam transaksi database aktif.');
    $stmt=$pdo->prepare("UPDATE transactions SET shiftSessionId=? WHERE id=? AND COALESCE(recordOrigin,'live_operation')='live_operation' AND COALESCE(shiftExempt,0)=0 AND (shiftSessionId IS NULL OR shiftSessionId='')");
    $stmt->execute([$shiftId,$id]);return $stmt->rowCount();
}

function tamasyaClaimEligibleTransactionsForShift(PDO $pdo,string $shiftId,string $openedAt,array $participantRows): int {
    tamasyaRequireFinancialMutationOperation('shift_assign','shift');
    if(!$pdo->inTransaction())throw new RuntimeException('Klaim transaksi shift wajib berada dalam transaksi database aktif.');
    $checks=[];$params=[];
    foreach($participantRows as $participant){
        $pid=trim((string)($participant['id']??''));$username=trim((string)($participant['username']??''));
        if($pid!==''){$checks[]='updatedBy=?';$params[]=$pid;$checks[]='createdBy=?';$params[]=$pid;}
        if($username!==''){$checks[]="LOWER(COALESCE(createdBy,''))=LOWER(?)";$params[]=$username;}
        // Never fall back to a display name: names are not unique identities and
        // can assign another employee's cash movement to the wrong shift. Legacy
        // rows lacking staff ID/username stay unassigned for explicit reconciliation.
    }
    if(!$checks)return 0;
    $sql="UPDATE transactions SET shiftSessionId=?
          WHERE shiftSessionId IS NULL AND lockedAt IS NULL
            AND COALESCE(recordOrigin,'live_operation')='live_operation'
            AND COALESCE(shiftExempt,0)=0
            AND COALESCE(createdAt, CONCAT(`date`,' 00:00:00')) >= ?
            AND COALESCE(createdAt, CONCAT(`date`,' 23:59:59')) <= NOW()
            AND (".implode(' OR ',$checks).")";
    $stmt=$pdo->prepare($sql);$stmt->execute(array_merge([$shiftId,$openedAt],$params));return $stmt->rowCount();
}

function tamasyaLockTransactionsForShift(PDO $pdo,string $shiftId,array $actor=[]): int {
    tamasyaRequireFinancialMutationOperation('shift_lock','shift');
    if(!$pdo->inTransaction())throw new RuntimeException('Penguncian transaksi shift wajib berada dalam transaksi database aktif.');
    $stmt=$pdo->prepare("UPDATE transactions SET lockedAt=CURRENT_TIMESTAMP WHERE shiftSessionId=? AND lockedAt IS NULL");$stmt->execute([$shiftId]);return $stmt->rowCount();
}

function tamasyaTouchTransactionAllocationMetadata(PDO $pdo,string $id,array $actor,string $source): int {
    return tamasyaMutateFinancialTransaction($pdo,$id,[],$actor,'booking_allocation','allocation_touch',['source'=>$source,'lockRows'=>true,'requireAll'=>true]);
}

function tamasyaFillTransactionAllocationMetadata(PDO $pdo,string $id,?string $bookingId,?string $bookingSource,?string $roomNumber,array $actor,string $source): int {
    return tamasyaMutateFinancialTransaction($pdo,$id,['bookingId'=>$bookingId,'bookingSource'=>$bookingSource,'roomNumber'=>$roomNumber],$actor,'booking_allocation','allocation_link',[
        'source'=>$source,'lockRows'=>true,'requireAll'=>true,'fillIfEmpty'=>['bookingId','bookingSource','roomNumber']
    ]);
}

function tamasyaClearTransactionAllocationMetadata(PDO $pdo,string $id,array $fields,array $actor,string $source): int {
    $changes=[];foreach(['bookingId','bookingSource','roomNumber'] as $field)if(in_array($field,$fields,true))$changes[$field]=null;
    if(!$changes)return tamasyaTouchTransactionAllocationMetadata($pdo,$id,$actor,$source);
    return tamasyaMutateFinancialTransaction($pdo,$id,$changes,$actor,'booking_allocation','allocation_unlink',['source'=>$source,'lockRows'=>true,'requireAll'=>true]);
}

function tamasyaUpdateBookingLinkedTransactionMetadata(PDO $pdo,string $bookingId,string $roomNumber,string $bookingSource,array $actor,?string $excludeTransactionId=null): int {
    tamasyaRequireFinancialMutationOperation('audit_correction','audit_correction');
    if(!$pdo->inTransaction())throw new RuntimeException('Koreksi metadata transaksi booking wajib berada dalam transaksi database aktif.');
    $sql="UPDATE transactions SET roomNumber=?, bookingSource=?, version=version+1, updatedAt=CURRENT_TIMESTAMP, updatedBy=?, updatedSource='audit-correction'
          WHERE bookingId=? AND lockedAt IS NULL AND COALESCE(recordOrigin,'live_operation')='live_operation' AND COALESCE(shiftExempt,0)=0";
    $params=[$roomNumber,$bookingSource,$actor['id']??null,$bookingId];
    if($excludeTransactionId!==null && trim($excludeTransactionId)!==''){$sql.=' AND id<>?';$params[]=trim($excludeTransactionId);}
    $stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->rowCount();
}

function tamasyaApplySyncedTransactionPatch(PDO $pdo,string $id,array $patch,array $actor,string $source): int {
    unset($patch['id']);
    if(!$patch)return 0;
    return tamasyaMutateFinancialTransaction($pdo,$id,$patch,$actor,'offline_sync','sync_patch',['lockRows'=>true,'requireAll'=>true,'source'=>$source]);
}
