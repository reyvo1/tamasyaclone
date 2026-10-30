<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Prevent destructive cleanup from silently reporting a key as returned.
 * This invariant is NOT bypassable by implementation force mode. All queries
 * execute in the same transaction as the delete when $lock is true.
 */
function tamasyaR164AssertCleanupCustodySafe(PDO $pdo,array $bookingIds,bool $lock=false): void {
    $ids=array_values(array_unique(array_filter(array_map('strval',$bookingIds))));
    if(!$ids)return;
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $lockSql=$lock?' FOR UPDATE':'';
    if(tamasyaTableExists($pdo,'room_access_control')){
        $q=$pdo->prepare("SELECT rac.room_number,rac.physical_key_status,rac.current_booking_id
            FROM room_access_control rac
            WHERE rac.current_booking_id IN ($ph)
                OR rac.room_number IN (SELECT b.roomNumber FROM bookings b WHERE b.id IN ($ph))".$lockSql);
        $q->execute(array_merge($ids,$ids));
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            if(strtolower((string)($row['physical_key_status']??''))!=='secured'
                || trim((string)($row['current_booking_id']??''))!==''){
                throw new RuntimeException('Hapus data ditolak: register kunci/PIN kamar '.(string)$row['room_number'].' belum bebas custody. Selesaikan serah kembali/revoke melalui workflow resmi.');
            }
        }
    }
    if(tamasyaTableExists($pdo,'smart_lock_jobs')){
        $q=$pdo->prepare("SELECT id,status FROM smart_lock_jobs WHERE booking_id IN ($ph)
            AND COALESCE(status,'unknown') NOT IN ('completed','cancelled') LIMIT 1".$lockSql);
        $q->execute($ids);
        if($q->fetch(PDO::FETCH_ASSOC))throw new RuntimeException('Hapus data ditolak: masih ada job smart-lock yang belum final.');
    }
    $q=$pdo->prepare("SELECT id,keyControlStatus FROM bookings WHERE id IN ($ph)
        AND keyControlStatus IN ('issued','missing','override','pending_smart_issue','pending_smart_revoke') LIMIT 1".$lockSql);
    $q->execute($ids);
    if($q->fetch(PDO::FETCH_ASSOC))throw new RuntimeException('Hapus data ditolak: booking masih memiliki custody kunci/PIN yang belum selesai.');
}

/** Source line 7538: testDataPurgePreview */
function testDataPurgePreview(PDO $pdo, string $bookingId, bool $lock=false): array {
    $bookingId=trim($bookingId);
    if($bookingId==='') throw new InvalidArgumentException('ID booking wajib diisi.');
    $sql="SELECT * FROM bookings WHERE id=? LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$bookingId]);$booking=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$booking) throw new RuntimeException('Booking tidak ditemukan.');

    $stmtTx=$pdo->prepare("SELECT id,type,amount,taxAmount,transactionKind,shiftSessionId,lockedAt,reconciliationStatus FROM transactions WHERE bookingId=? OR (sourceEntity='booking' AND sourceEntityId=?) ORDER BY `date`,id".($lock?' FOR UPDATE':''));
    $stmtTx->execute([$bookingId,$bookingId]);$transactions=$stmtTx->fetchAll(PDO::FETCH_ASSOC)?:[];
    $txIds=array_values(array_unique(array_filter(array_map(static fn($row)=>trim((string)($row['id']??'')),$transactions))));
    $income=0.0;$expense=0.0;$tax=0.0;$closedShiftIds=[];
    $hasLockedTransaction=false;
    foreach($transactions as $tx){
        $amount=(float)($tx['amount']??0);$tax+=(float)($tx['taxAmount']??0);
        if(($tx['type']??'')==='income')$income+=$amount;else $expense+=$amount;
        if(!empty($tx['shiftSessionId']))$closedShiftIds[]=(string)$tx['shiftSessionId'];
        if(!empty($tx['lockedAt']))$hasLockedTransaction=true;
    }
    $closedShiftIds=array_values(array_unique($closedShiftIds));
    $blockers=[];$warnings=[];
    if(($booking['status']??'')==='active')$blockers[]='Reservasi masih aktif. Checkout atau batalkan terlebih dahulu.';
    if($hasLockedTransaction)$blockers[]='Ada transaksi yang sudah dikunci. Gunakan Koreksi Audit agar laporan terkunci tidak berubah.';
    if(in_array((string)($booking['keyControlStatus']??'not_issued'),['issued','missing','override'],true))$blockers[]='Kunci/PIN reservasi belum dinyatakan kembali atau dicabut.';

    if($txIds){
        $ph=implode(',',array_fill(0,count($txIds),'?'));
        if(tamasyaTableExists($pdo,'maintenance_tickets')){
            $q=$pdo->prepare("SELECT COUNT(*) FROM maintenance_tickets WHERE transaction_id IN ($ph)");$q->execute($txIds);
            if((int)$q->fetchColumn()>0)$blockers[]='Ada tiket maintenance yang memakai transaksi reservasi ini.';
        }
        if(tamasyaTableExists($pdo,'reconciliation_items')){
            $q=$pdo->prepare("SELECT COUNT(*) FROM reconciliation_items WHERE transaction_id IN ($ph) AND status IN ('matched','reconciled','completed')");$q->execute($txIds);
            if((int)$q->fetchColumn()>0)$blockers[]='Ada transaksi yang sudah direkonsiliasi dengan kas/bank.';
        }
        if(tamasyaTableExists($pdo,'approval_requests')){
            $params=array_merge([$bookingId],$txIds);
            $q=$pdo->prepare("SELECT COUNT(*) FROM approval_requests WHERE status='pending' AND (entity_id=? OR entity_id IN ($ph))");$q->execute($params);
            if((int)$q->fetchColumn()>0)$blockers[]='Masih ada permintaan persetujuan yang belum diputuskan.';
        }
    }
    if($closedShiftIds && tamasyaTableExists($pdo,'shift_sessions')){
        $ph=implode(',',array_fill(0,count($closedShiftIds),'?'));
        $q=$pdo->prepare("SELECT COUNT(*) FROM shift_sessions WHERE id IN ($ph) AND status='closed'");$q->execute($closedShiftIds);
        if((int)$q->fetchColumn()>0)$blockers[]='Transaksi sudah masuk shift tertutup. Gunakan Koreksi Audit agar laporan shift tidak berubah.';
    }
    if(tamasyaTableExists($pdo,'smart_lock_jobs')){
        $q=$pdo->prepare("SELECT COUNT(*) FROM smart_lock_jobs WHERE booking_id=? AND status IN ('pending','processing','retry')");$q->execute([$bookingId]);
        if((int)$q->fetchColumn()>0)$blockers[]='Masih ada pekerjaan smart-lock yang belum selesai.';
    }
    $vacancyReportIds=[];
    if(tamasyaTableExists($pdo,'room_vacancy_reports')){
        $q=$pdo->prepare("SELECT id FROM room_vacancy_reports WHERE booking_id=? ORDER BY reported_at,id".($lock?' FOR UPDATE':''));
        $q->execute([$bookingId]);
        $vacancyReportIds=array_values(array_unique(array_filter(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]))));
        if($vacancyReportIds)$warnings[]=count($vacancyReportIds).' laporan kamar kosong terkait akan ikut dihapus dan diberi tombstone.';
    }
    if(!$transactions)$warnings[]='Booking tidak memiliki transaksi keuangan terkait.';
    if(($booking['status']??'')==='completed')$warnings[]='Reservasi sudah checkout; penghapusan akan menghilangkan histori booking dan ledger uji secara permanen.';
    return [
        'booking'=>[
            'id'=>$bookingId,'guestName'=>(string)($booking['guestName']??''),'roomNumber'=>(string)($booking['roomNumber']??''),
            'status'=>(string)($booking['status']??''),'checkIn'=>(string)($booking['checkIn']??''),'checkOut'=>(string)($booking['checkOut']??''),
            'totalAmount'=>(float)($booking['totalAmount']??0)
        ],
        'transactionIds'=>$txIds,
        'transactionCount'=>count($transactions),
        'vacancyReportIds'=>$vacancyReportIds,
        'vacancyReportCount'=>count($vacancyReportIds),
        'grossIncome'=>$income,'grossExpense'=>$expense,'taxAmount'=>$tax,
        'blockers'=>$blockers,'warnings'=>$warnings,'canPurge'=>count($blockers)===0,
        'confirmationPhrase'=>'HAPUS DATA UJI '.$bookingId
    ];
}

/** Source line 7612: verifyActiveAdminPassword */
function verifyActiveAdminPassword(PDO $pdo, array $actor, string $password): void {
    if(trim($password)==='') throw new InvalidArgumentException('Password Admin wajib diisi.');
    $stmt=$pdo->prepare("SELECT password FROM staff WHERE id=? AND role='admin' AND status='active' LIMIT 1");
    $stmt->execute([(string)($actor['id']??'')]);$hash=(string)($stmt->fetchColumn()?:'');
    if($hash==='' || !password_verify($password,$hash)) throw new RuntimeException('Password Admin tidak valid.');
}

/** Source line 7619: recalculatePurgedShiftSessions */
function recalculatePurgedShiftSessions(PDO $pdo, array $shiftIds): void {
    foreach(array_values(array_unique(array_filter($shiftIds))) as $shiftId){
        $stmt=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$shiftId]);$shift=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$shift || ($shift['status']??'')==='closed') continue;
        $sum=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' AND COALESCE(transactionKind,'manual')<>'security_deposit_forfeit' THEN CASE WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0 AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01 THEN COALESCE(splitCashAmount,0) WHEN (bankAccountId IS NULL OR bankAccountId='' OR bankAccountId='cash') THEN amount ELSE 0 END ELSE 0 END),0) cash_income, COALESCE(SUM(CASE WHEN type='expense' THEN CASE WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0 AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01 THEN COALESCE(splitCashAmount,0) WHEN (bankAccountId IS NULL OR bankAccountId='' OR bankAccountId='cash') THEN amount ELSE 0 END ELSE 0 END),0) cash_expense FROM transactions WHERE shiftSessionId=?");
        $sum->execute([$shiftId]);$tot=$sum->fetch(PDO::FETCH_ASSOC)?:[];
        $income=(float)($tot['cash_income']??0);$expense=(float)($tot['cash_expense']??0);$expected=(float)($shift['opening_cash']??0)+$income-$expense;
        $actual=$shift['actual_cash']===null?null:(float)$shift['actual_cash'];$variance=$actual===null?null:$actual-$expected;
        $pdo->prepare("UPDATE shift_sessions SET cash_income=?,cash_expense=?,expected_cash=?,variance=? WHERE id=?")
            ->execute([$income,$expense,$expected,$variance,$shiftId]);
    }
}

/** Source line 7633: normalizeCleanupIds */
function normalizeCleanupIds($value, int $max=100): array {
    if(!is_array($value)) return [];
    $out=[];
    foreach($value as $item){
        $id=trim((string)$item);
        if($id==='' || isset($out[$id])) continue;
        $out[$id]=true;
        if(count($out)>=$max) break;
    }
    return array_keys($out);
}

/** Source line 7645: getImplementationCleanupMode */
function getImplementationCleanupMode(PDO $pdo): array {
    $row=['cleanup_mode_until'=>null,'cleanup_mode_enabled_by'=>null,'cleanup_mode_note'=>null];
    try{
        $stmt=$pdo->query("SELECT cleanup_mode_until,cleanup_mode_enabled_by,cleanup_mode_note FROM config WHERE id='system_default' LIMIT 1");
        $row=$stmt->fetch(PDO::FETCH_ASSOC)?:$row;
    }catch(Throwable $e){}
    $until=(string)($row['cleanup_mode_until']??'');
    $active=$until!=='' && strtotime($until)!==false && strtotime($until)>time();
    return [
        'active'=>$active,
        'until'=>$until?:null,
        'enabledBy'=>$row['cleanup_mode_enabled_by']??null,
        'note'=>$row['cleanup_mode_note']??null
    ];
}

/** Source line 7661: cleanupPlaceholders */
function cleanupPlaceholders(array $ids): string {
    return implode(',',array_fill(0,count($ids),'?'));
}

/** Source line 7667: buildOtaReceivableState */
function buildOtaReceivableState(PDO $pdo, bool $lock=false): array {
    if(!tamasyaTableExists($pdo,'ota_disbursement_items')) return ['items'=>[],'legacyTransferAmount'=>0.0,'availableTotal'=>0.0];
    $lockSql=$lock?' FOR UPDATE':'';
    $sql="SELECT t.*,
                 COALESCE((SELECT SUM(odi.allocated_amount) FROM ota_disbursement_items odi WHERE odi.receivable_transaction_id=t.id),0) explicit_allocated
          FROM transactions t
          WHERE t.type='income' AND t.bankAccountId='ota_receivable'
            AND COALESCE(t.transactionKind,'manual')<>'ota_transfer'
            AND COALESCE(t.sourceEntity,'')<>'ota_disbursement'
          ORDER BY t.`date`,t.createdAt,t.id".$lockSql;
    $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];
    $legacyStmt=$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions
        WHERE type='expense' AND bankAccountId='ota_receivable'
          AND COALESCE(sourceEntity,'')<>'ota_disbursement'
          AND COALESCE(transactionKind,'manual') IN ('ota_transfer','internal_transfer')");
    $legacyRemaining=max(0.0,(float)($legacyStmt->fetchColumn()?:0));
    $items=[];$availableTotal=0.0;
    foreach($rows as $row){
        $amount=max(0.0,(float)($row['amount']??0));
        $explicit=max(0.0,(float)($row['explicit_allocated']??0));
        $afterExplicit=max(0.0,$amount-$explicit);
        $legacyAllocated=min($afterExplicit,$legacyRemaining);
        $legacyRemaining=max(0.0,$legacyRemaining-$legacyAllocated);
        $available=max(0.0,$afterExplicit-$legacyAllocated);
        $availableTotal+=$available;
        $items[]=[
            'transactionId'=>(string)$row['id'],'bookingId'=>trim((string)($row['bookingId']??''))?:null,
            'date'=>(string)($row['date']??''),'description'=>(string)($row['description']??''),
            'amount'=>$amount,'explicitAllocated'=>$explicit,'legacyAllocated'=>$legacyAllocated,
            'availableAmount'=>$available,'version'=>(int)($row['version']??1),'updatedAt'=>$row['updatedAt']??null
        ];
    }
    $legacyTotal=array_sum(array_map(static fn($item)=>(float)$item['legacyAllocated'],$items))+$legacyRemaining;
    return ['items'=>$items,'legacyTransferAmount'=>$legacyTotal,'unmatchedLegacyAmount'=>$legacyRemaining,'availableTotal'=>$availableTotal];
}

/** Source line 7704: otaReceivablePublicState */
function otaReceivablePublicState(PDO $pdo): array {
    $state=buildOtaReceivableState($pdo,false);
    $items=array_values(array_filter($state['items'],static fn($item)=>(float)$item['availableAmount']>0.005));
    return ['items'=>$items,'availableTotal'=>array_sum(array_column($items,'availableAmount')),'hasLegacyAllocations'=>count(array_filter($state['items'],static fn($item)=>(float)$item['legacyAllocated']>0.005))>0];
}

/** Source line 7711: buildOtaCleanupAdjustments */
function buildOtaCleanupAdjustments(PDO $pdo, array $receivableTransactionIds, bool $lock=false): array {
    $receivableTransactionIds=array_values(array_unique(array_filter(array_map('strval',$receivableTransactionIds))));
    if(!$receivableTransactionIds || !tamasyaTableExists($pdo,'ota_disbursement_items')) return ['adjustments'=>[],'affectedTransactionIds'=>[],'legacyBlocked'=>[]];
    $state=buildOtaReceivableState($pdo,$lock);
    $stateById=[];foreach($state['items'] as $item)$stateById[(string)$item['transactionId']]=$item;
    $legacyBlocked=[];
    foreach($receivableTransactionIds as $id){if((float)($stateById[$id]['legacyAllocated']??0)>0.005)$legacyBlocked[]=$id;}
    $ph=cleanupPlaceholders($receivableTransactionIds);
    $sql="SELECT odi.*,od.amount disbursement_amount,od.source_transaction_id,od.destination_transaction_id,od.bank_account_id,od.disbursement_date,od.status
          FROM ota_disbursement_items odi JOIN ota_disbursements od ON od.id=odi.disbursement_id
          WHERE odi.receivable_transaction_id IN ($ph) ORDER BY odi.disbursement_id,odi.id".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute($receivableTransactionIds);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $groups=[];
    foreach($rows as $row){$groups[(string)$row['disbursement_id']][]=$row;}
    $commissionStmt=$pdo->prepare("SELECT id FROM transactions WHERE sourceEntity='ota_disbursement' AND sourceEntityId=? AND transactionKind='ota_commission' ORDER BY createdAt,id LIMIT 1".($lock?' FOR UPDATE':''));
    $adjustments=[];$affected=[];
    foreach($groups as $disbursementId=>$items){
        $selectedAmount=array_sum(array_map(static fn($r)=>(float)$r['allocated_amount'],$items));
        $total=(float)$items[0]['disbursement_amount'];$remaining=max(0.0,$total-$selectedAmount);
        $source=(string)$items[0]['source_transaction_id'];$dest=(string)$items[0]['destination_transaction_id'];
        $commissionStmt->execute([$disbursementId]);
        $commission=trim((string)($commissionStmt->fetchColumn()?:''));
        $affected[]=$source;$affected[]=$dest;
        if($commission!=='')$affected[]=$commission;
        $adjustments[]=[
            'disbursementId'=>$disbursementId,'selectedAmount'=>$selectedAmount,'originalAmount'=>$total,'remainingAmount'=>$remaining,
            'action'=>$remaining<=0.005?'delete':'reduce','sourceTransactionId'=>$source,'destinationTransactionId'=>$dest,
            'commissionTransactionId'=>$commission!==''?$commission:null,
            'receivableTransactionIds'=>array_values(array_unique(array_map(static fn($r)=>(string)$r['receivable_transaction_id'],$items)))
        ];
    }
    return ['adjustments'=>$adjustments,'affectedTransactionIds'=>array_values(array_unique(array_filter($affected))),'legacyBlocked'=>$legacyBlocked];
}

/**
 * Destructive implementation cleanup is intentionally high-friction.
 * - Production is disabled unless explicitly enabled in the deployment ENV.
 * - Every destructive run requires the temporary cleanup mode to be active.
 * - A recent, completed backup with a real SHA256 and non-zero size must exist.
 *
 * This gate does not claim that the backup has been restore-tested; it only
 * proves there is a recent restore-grade candidate recorded by the system.
 */
function tamasyaImplementationCleanupEnabled(): bool {
    $appEnv=strtolower(trim((string)(getenv('APP_ENV')?:'production')));
    $raw=getenv('TAMASYA_IMPLEMENTATION_CLEANUP_ENABLED');
    if($raw!==false && trim((string)$raw)!=='') return filter_var($raw,FILTER_VALIDATE_BOOLEAN);
    return !in_array($appEnv,['production','prod'],true);
}

function tamasyaImplementationCleanupGate(PDO $pdo, bool $lockBackup=false): array {
    if(!tamasyaImplementationCleanupEnabled()){
        throw new RuntimeException('Pembersihan implementasi dinonaktifkan pada deployment ini. Untuk production, aktifkan TAMASYA_IMPLEMENTATION_CLEANUP_ENABLED=1 hanya pada maintenance window yang terkontrol, lalu matikan kembali.');
    }
    $mode=getImplementationCleanupMode($pdo);
    if(empty($mode['active'])){
        throw new RuntimeException('Mode Pembersihan Implementasi belum aktif atau sudah kedaluwarsa. Aktifkan sementara dengan password Admin dan alasan yang jelas.');
    }

    // A backup_runs row by itself is not sufficient. In a two-node property the
    // database metadata may have been copied historically while the SQL file is
    // strictly node-local. Destructive cleanup therefore proves that the backup
    // file exists on THIS node and that size + SHA256 still match the DB record.
    $backupDir=trim((string)(getenv('BACKUP_DIR')?:''));
    if($backupDir==='') throw new RuntimeException('Pembersihan permanen membutuhkan BACKUP_DIR lokal yang valid.');
    if(function_exists('tamasyaPathIsAbsolute')?!tamasyaPathIsAbsolute($backupDir):(!str_starts_with($backupDir,'/') && !preg_match('~^[A-Za-z]:[\\/]~',$backupDir))){
        throw new RuntimeException('BACKUP_DIR wajib path absolut sebelum pembersihan permanen.');
    }
    $backupReal=realpath($backupDir);
    if($backupReal===false || !is_dir($backupReal) || !is_readable($backupReal)){
        throw new RuntimeException('BACKUP_DIR lokal tidak ada atau tidak dapat dibaca. Pembersihan permanen dibatalkan.');
    }
    if(function_exists('tamasyaPathIsInsideDocumentRoot') && tamasyaPathIsInsideDocumentRoot($backupReal, defined('TAMASYA_APP_ROOT')?TAMASYA_APP_ROOT:dirname(__DIR__,2))){
        throw new RuntimeException('BACKUP_DIR berada di dalam public/document root. Pembersihan permanen dibatalkan.');
    }

    $maxAge=max(1,min(168,(int)(getenv('TAMASYA_CLEANUP_MAX_BACKUP_AGE_HOURS')?:24)));
    $sql="SELECT id,backup_type,file_name,status,size_bytes,checksum,created_at,restore_tested_at
          FROM backup_runs
          WHERE status='completed'
            AND COALESCE(size_bytes,0)>0
            AND checksum REGEXP '^[A-Fa-f0-9]{64}$'
            AND file_name IS NOT NULL AND TRIM(file_name)<>''
            AND created_at>=DATE_SUB(NOW(),INTERVAL {$maxAge} HOUR)
          ORDER BY created_at DESC,id DESC LIMIT 25".($lockBackup?' FOR UPDATE':'');
    $candidates=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];
    $row=null;$verifiedPath=null;$verificationErrors=[];
    foreach($candidates as $candidate){
        $fileName=trim((string)($candidate['file_name']??''));
        if($fileName==='' || basename($fileName)!==$fileName){
            $verificationErrors[]=(string)($candidate['id']??'?').': nama file tidak aman';
            continue;
        }
        $path=$backupReal.DIRECTORY_SEPARATOR.$fileName;
        $realPath=realpath($path);
        if($realPath===false || !is_file($realPath) || !is_readable($realPath)){
            $verificationErrors[]=(string)($candidate['id']??'?').': file tidak ada di node ini';
            continue;
        }
        $prefix=rtrim(str_replace('\\','/',$backupReal),'/').'/';
        $candidateNormalized=str_replace('\\','/',$realPath);
        if(!str_starts_with($candidateNormalized,$prefix)){
            $verificationErrors[]=(string)($candidate['id']??'?').': file keluar dari BACKUP_DIR';
            continue;
        }
        $actualSize=@filesize($realPath);
        if($actualSize===false || (int)$actualSize!==(int)($candidate['size_bytes']??0)){
            $verificationErrors[]=(string)($candidate['id']??'?').': ukuran file tidak cocok';
            continue;
        }
        $actualHash=@hash_file('sha256',$realPath);
        $storedHash=strtolower(trim((string)($candidate['checksum']??'')));
        if(!is_string($actualHash) || !hash_equals($storedHash,strtolower($actualHash))){
            $verificationErrors[]=(string)($candidate['id']??'?').': SHA256 tidak cocok';
            continue;
        }
        $row=$candidate;$verifiedPath=$realPath;break;
    }
    if(!$row){
        $detail=$verificationErrors?' Detail: '.implode('; ',array_slice($verificationErrors,0,5)).'.':'';
        throw new RuntimeException('Pembersihan permanen membutuhkan backup restore-grade LOKAL yang completed, masih ada, dan lolos verifikasi size + SHA256 dalam '.$maxAge.' jam terakhir. Jalankan backup pada node ini terlebih dahulu.'.$detail);
    }
    return [
        'mode'=>$mode,
        'backup'=>[
            'id'=>(string)$row['id'],
            'type'=>(string)$row['backup_type'],
            'fileName'=>(string)$row['file_name'],
            'sizeBytes'=>(int)$row['size_bytes'],
            'sha256'=>strtolower((string)$row['checksum']),
            'createdAt'=>(string)$row['created_at'],
            'restoreTested'=>!empty($row['restore_tested_at']),
            'verifiedOnThisNode'=>true,
            'verifiedPathHash'=>$verifiedPath!==null?hash('sha256',$verifiedPath):null,
        ],
        'maxBackupAgeHours'=>$maxAge,
    ];
}

/** Source line 7745: buildImplementationCleanupPreview */
function buildImplementationCleanupPreview(PDO $pdo, array $bookingIds, array $transactionIds, bool $forceMode=false, bool $lock=false): array {
    $bookingIds=normalizeCleanupIds($bookingIds,100);
    $transactionIds=normalizeCleanupIds($transactionIds,200);
    if(!$bookingIds && !$transactionIds) throw new InvalidArgumentException('Pilih minimal satu booking atau transaksi.');

    $lockSql=$lock?' FOR UPDATE':'';
    $selectedTransactions=[];
    if($transactionIds){
        $ph=cleanupPlaceholders($transactionIds);
        $stmt=$pdo->prepare("SELECT * FROM transactions WHERE id IN ($ph) ORDER BY `date`,id".$lockSql);
        $stmt->execute($transactionIds);
        $selectedTransactions=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $found=array_column($selectedTransactions,'id');
        $missing=array_values(array_diff($transactionIds,$found));
        if($missing) throw new RuntimeException('Transaksi tidak ditemukan: '.implode(', ',$missing));
        foreach($selectedTransactions as $tx){
            $bookingId=trim((string)($tx['bookingId']??''));
            if($bookingId!=='') $bookingIds[]=$bookingId;
        }
        $bookingIds=array_values(array_unique($bookingIds));
    }

    $bookings=[];
    if($bookingIds){
        $ph=cleanupPlaceholders($bookingIds);
        $stmt=$pdo->prepare("SELECT * FROM bookings WHERE id IN ($ph) ORDER BY checkIn,id".$lockSql);
        $stmt->execute($bookingIds);
        $bookings=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $found=array_column($bookings,'id');
        $missing=array_values(array_diff($bookingIds,$found));
        if($missing) throw new RuntimeException('Booking tidak ditemukan: '.implode(', ',$missing));
    }

    $allTransactions=[];
    if($bookingIds){
        $ph=cleanupPlaceholders($bookingIds);
        $params=array_merge($bookingIds,$bookingIds);
        $stmt=$pdo->prepare("SELECT * FROM transactions WHERE bookingId IN ($ph) OR (sourceEntity='booking' AND sourceEntityId IN ($ph)) ORDER BY `date`,id".$lockSql);
        $stmt->execute($params);
        $allTransactions=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    $bookingTxIds=array_fill_keys(array_map(static fn($r)=>(string)$r['id'],$allTransactions),true);
    foreach($selectedTransactions as $tx){
        $id=(string)$tx['id'];
        if(!isset($bookingTxIds[$id])){$allTransactions[]=$tx;$bookingTxIds[$id]=true;}
    }
    $transactionIds=array_values(array_keys($bookingTxIds));
    $otaReceivableIds=[];
    foreach($allTransactions as $tx){
        if(($tx['type']??'')==='income' && strtolower(trim((string)($tx['bankAccountId']??'')))==='ota_receivable'
            && normalizeTransactionKind((string)($tx['transactionKind']??inferTransactionKind($tx)))!=='ota_transfer') $otaReceivableIds[]=(string)$tx['id'];
    }
    $otaCleanup=buildOtaCleanupAdjustments($pdo,$otaReceivableIds,$lock);
    $impactTransactionIds=array_values(array_unique(array_merge($transactionIds,(array)$otaCleanup['affectedTransactionIds'])));

    $standalone=[];$blockers=[];$warnings=[];
    $protectedKinds=['salary_payment','maintenance_cost','internal_transfer','ota_transfer','security_deposit_received','security_deposit_refund','security_deposit_forfeit'];
    foreach($allTransactions as $tx){
        $bid=trim((string)($tx['bookingId']??''));
        $source=strtolower(trim((string)($tx['sourceEntity']??'')));
        $kind=normalizeTransactionKind((string)($tx['transactionKind']??inferTransactionKind($tx)));
        if($bid==='' && $source!=='booking'){
            $standalone[]=$tx;
            if(in_array($kind,$protectedKinds,true) || ($source!=='' && !in_array($source,['manual','cash',''],true))){
                $blockers[]='Transaksi '.$tx['id'].' berasal dari workflow '.$kind.'/'.($source?:'system').' dan harus dibersihkan dari modul sumbernya.';
            }
        }
    }

    if(!empty($otaCleanup['legacyBlocked']))$blockers[]='Piutang OTA terpilih sudah tersentuh pencairan lama tanpa peta alokasi. Cocokkan/migrasikan pencairan OTA lama terlebih dahulu agar bank dan piutang tidak salah.';
    foreach((array)$otaCleanup['adjustments'] as $adj){
        $warnings[]=$adj['action']==='delete'
            ? 'Pencairan OTA '.$adj['disbursementId'].' akan dihapus seluruhnya karena seluruh alokasinya masuk batch.'
            : 'Pencairan OTA '.$adj['disbursementId'].' akan dikurangi Rp '.number_format((float)$adj['selectedAmount'],0,',','.').' dan sisanya dipertahankan.';
    }

    $income=0.0;$expense=0.0;$tax=0.0;$shiftIds=[];$lockedCount=0;
    foreach($allTransactions as $tx){
        $amount=(float)($tx['amount']??0);
        if(($tx['type']??'')==='income')$income+=$amount;else$expense+=$amount;
        $tax+=(float)($tx['taxAmount']??0);
        if(!empty($tx['shiftSessionId']))$shiftIds[]=(string)$tx['shiftSessionId'];
        if(!empty($tx['lockedAt']))$lockedCount++;
    }
    // Pencairan OTA yang ikut dihapus/dikurangi juga merupakan bagian dampak kas.
    // Masukkan shift dan lock pair OTA ke pemeriksaan preview, tetapi jangan
    // menambah grossIncome/grossExpense karena transfer internal bernilai netral.
    $otaPairRows=[];
    $otaPairIds=(array)($otaCleanup['affectedTransactionIds']??[]);
    if($otaPairIds){
        $oph=cleanupPlaceholders($otaPairIds);
        $oq=$pdo->prepare("SELECT id,shiftSessionId,lockedAt FROM transactions WHERE id IN ($oph)".$lockSql);
        $oq->execute($otaPairIds);
        $otaPairRows=$oq->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($otaPairRows as $pair){
            if(!empty($pair['shiftSessionId']))$shiftIds[]=(string)$pair['shiftSessionId'];
            if(!empty($pair['lockedAt']))$lockedCount++;
        }
    }
    $shiftIds=array_values(array_unique($shiftIds));
    $closedShiftIds=[];
    if($shiftIds && tamasyaTableExists($pdo,'shift_sessions')){
        $ph=cleanupPlaceholders($shiftIds);
        $stmt=$pdo->prepare("SELECT id FROM shift_sessions WHERE id IN ($ph) AND status='closed'");$stmt->execute($shiftIds);
        $closedShiftIds=$stmt->fetchAll(PDO::FETCH_COLUMN)?:[];
    }

    $reconciliationCount=0;$pendingApprovalCount=0;$maintenanceReferenceCount=0;
    if($impactTransactionIds){
        $ph=cleanupPlaceholders($impactTransactionIds);
        if(tamasyaTableExists($pdo,'reconciliation_items')){
            $q=$pdo->prepare("SELECT COUNT(*) FROM reconciliation_items WHERE transaction_id IN ($ph) AND status IN ('matched','reconciled','completed')");$q->execute($impactTransactionIds);$reconciliationCount=(int)$q->fetchColumn();
        }
        if(tamasyaTableExists($pdo,'maintenance_tickets')){
            $q=$pdo->prepare("SELECT COUNT(*) FROM maintenance_tickets WHERE transaction_id IN ($ph)");$q->execute($impactTransactionIds);$maintenanceReferenceCount=(int)$q->fetchColumn();
        }
        if(tamasyaTableExists($pdo,'approval_requests')){
            $params=array_merge($bookingIds,$impactTransactionIds);
            $parts=[];
            if($bookingIds)$parts[]="(LOWER(entity_type) IN ('booking','bookings') AND entity_id IN (".cleanupPlaceholders($bookingIds)."))";
            if($impactTransactionIds)$parts[]="(LOWER(entity_type) IN ('transaction','transactions') AND entity_id IN (".cleanupPlaceholders($impactTransactionIds)."))";
            $q=$pdo->prepare("SELECT COUNT(*) FROM approval_requests WHERE status='pending' AND (".implode(' OR ',$parts).")");$q->execute($params);$pendingApprovalCount=(int)$q->fetchColumn();
        }
    }

    $pendingSmartLockCount=0;
    if($bookingIds && tamasyaTableExists($pdo,'smart_lock_jobs')){
        $ph=cleanupPlaceholders($bookingIds);
        $q=$pdo->prepare("SELECT COUNT(*) FROM smart_lock_jobs WHERE booking_id IN ($ph) AND status IN ('pending','processing','retry')");$q->execute($bookingIds);$pendingSmartLockCount=(int)$q->fetchColumn();
    }

    $vacancyReportIds=[];
    if($bookingIds && tamasyaTableExists($pdo,'room_vacancy_reports')){
        $ph=cleanupPlaceholders($bookingIds);
        $q=$pdo->prepare("SELECT id FROM room_vacancy_reports WHERE booking_id IN ($ph) ORDER BY booking_id,reported_at,id".$lockSql);
        $q->execute($bookingIds);
        $vacancyReportIds=array_values(array_unique(array_filter(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]))));
        if($vacancyReportIds)$warnings[]=count($vacancyReportIds).' laporan kamar kosong terkait akan ikut dihapus dan diberi tombstone.';
    }

    $activeBookingCount=0;$openKeyCount=0;
    foreach($bookings as $booking){
        if(($booking['status']??'')==='active')$activeBookingCount++;
        if(in_array((string)($booking['keyControlStatus']??'not_issued'),['issued','missing','override'],true))$openKeyCount++;
    }

    $mode=getImplementationCleanupMode($pdo);
    $forceAllowed=$forceMode && !empty($mode['active']);
    $soft=[];
    if($activeBookingCount)$soft[]=$activeBookingCount.' booking masih aktif.';
    if($lockedCount)$soft[]=$lockedCount.' transaksi sudah dikunci.';
    if($closedShiftIds)$soft[]=count($closedShiftIds).' shift tertutup akan dihitung ulang.';
    if($reconciliationCount)$soft[]=$reconciliationCount.' item rekonsiliasi final akan dilepas.';
    if($openKeyCount)$soft[]=$openKeyCount.' booking masih memiliki status kunci/PIN terbuka.';
    if($soft){
        if($forceAllowed)$warnings=array_merge($warnings,array_map(static fn($m)=>'MODE IMPLEMENTASI: '.$m,$soft));
        else$blockers=array_merge($blockers,$soft,['Aktifkan Mode Pembersihan Implementasi dan pilih Mode Paksa hanya bila data tersebut memang harus dibuang.']);
    }
    if($pendingApprovalCount)$blockers[]=$pendingApprovalCount.' approval masih pending. Putuskan atau batalkan approval terlebih dahulu.';
    if($pendingSmartLockCount)$blockers[]=$pendingSmartLockCount.' pekerjaan smart-lock masih berjalan. Selesaikan/cancel dahulu.';
    if($maintenanceReferenceCount)$blockers[]=$maintenanceReferenceCount.' tiket maintenance memakai transaksi terpilih.';
    if(!$allTransactions)$warnings[]='Pilihan tidak mempunyai transaksi kas terkait.';

    $bookingSummary=array_map(static fn($b)=>[
        'id'=>(string)$b['id'],'guestName'=>(string)($b['guestName']??''),'roomNumber'=>(string)($b['roomNumber']??''),
        'status'=>(string)($b['status']??''),'checkIn'=>(string)($b['checkIn']??''),'checkOut'=>(string)($b['checkOut']??''),
        'totalAmount'=>(float)($b['totalAmount']??0),'version'=>(int)($b['version']??1),'updatedAt'=>$b['updatedAt']??null
    ],$bookings);
    $standaloneSummary=array_map(static fn($t)=>[
        'id'=>(string)$t['id'],'type'=>(string)($t['type']??''),'amount'=>(float)($t['amount']??0),'date'=>(string)($t['date']??''),
        'description'=>(string)($t['description']??''),'transactionKind'=>(string)($t['transactionKind']??''),'version'=>(int)($t['version']??1),'updatedAt'=>$t['updatedAt']??null
    ],$standalone);
    $versionMaterial=['bookings'=>$bookingSummary,'transactions'=>array_map(static fn($t)=>[(string)$t['id'],(int)($t['version']??1),$t['updatedAt']??null],$allTransactions),'vacancyReports'=>$vacancyReportIds,'otaAdjustments'=>$otaCleanup['adjustments']];
    $previewHash=hash('sha256',json_encode($versionMaterial,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $phrase='HAPUS BATCH '.strtoupper(substr(hash('sha256',implode('|',array_merge($bookingIds,$transactionIds))),0,12));
    return [
        'bookingIds'=>$bookingIds,'transactionIds'=>$transactionIds,
        'bookings'=>$bookingSummary,'standaloneTransactions'=>$standaloneSummary,
        'bookingCount'=>count($bookings),'standaloneTransactionCount'=>count($standalone),
        'transactionCount'=>count($allTransactions),'grossIncome'=>$income,'grossExpense'=>$expense,'taxAmount'=>$tax,
        'shiftIds'=>$shiftIds,'closedShiftIds'=>$closedShiftIds,'reconciliationCount'=>$reconciliationCount,
        'pendingApprovalCount'=>$pendingApprovalCount,'pendingSmartLockCount'=>$pendingSmartLockCount,
        'vacancyReportIds'=>$vacancyReportIds,'vacancyReportCount'=>count($vacancyReportIds),
        'otaDisbursementAdjustments'=>$otaCleanup['adjustments'],'otaAffectedTransactionIds'=>$otaCleanup['affectedTransactionIds'],
        'blockers'=>array_values(array_unique($blockers)),'warnings'=>array_values(array_unique($warnings)),
        'canPurge'=>count($blockers)===0,'forceMode'=>$forceMode,'cleanupMode'=>$mode,
        'confirmationPhrase'=>$phrase,'previewHash'=>$previewHash
    ];
}

/** Source line 7935: recalculateCleanupShiftSessions */
function recalculateCleanupShiftSessions(PDO $pdo, array $shiftIds, string $batchId): void {
    foreach(array_values(array_unique(array_filter($shiftIds))) as $shiftId){
        $stmt=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$shiftId]);$shift=$stmt->fetch(PDO::FETCH_ASSOC);
        // A closed shift is a signed record: its expected_cash, variance and issued
        // shift report were frozen at close time. Rewriting them after a purge would
        // make the stored report disagree with what the cashier signed off on, and
        // would erase the variance history. The sibling recalculatePurgedShiftSessions()
        // already skips closed shifts; this one did not, so the purge silently
        // restated signed figures. Skipped shifts are logged rather than silently dropped.
        if(!$shift)continue;
        if(($shift['status']??'')==='closed'){
            error_log('[DATA CLEANUP '.$batchId.'] shift '.$shiftId.' sudah ditutup; expected_cash, variance, dan laporan shift tidak ditulis ulang.');
            continue;
        }
        $sum=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' AND COALESCE(transactionKind,'manual')<>'security_deposit_forfeit' THEN CASE WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0 AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01 THEN COALESCE(splitCashAmount,0) WHEN (bankAccountId IS NULL OR bankAccountId='' OR bankAccountId='cash') THEN amount ELSE 0 END ELSE 0 END),0) cash_income, COALESCE(SUM(CASE WHEN type='expense' THEN CASE WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0 AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01 THEN COALESCE(splitCashAmount,0) WHEN (bankAccountId IS NULL OR bankAccountId='' OR bankAccountId='cash') THEN amount ELSE 0 END ELSE 0 END),0) cash_expense, COALESCE(SUM(CASE WHEN type='income' AND COALESCE(transactionKind,'manual') NOT IN ('internal_transfer','ota_transfer','security_deposit_forfeit') THEN CASE WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0 AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01 THEN COALESCE(splitTransferAmount,0) WHEN bankAccountId IS NOT NULL AND bankAccountId<>'' AND bankAccountId<>'cash' AND LOWER(bankAccountId) NOT IN ('ota_receivable','inventory_asset','guest_receivable','accounts_payable') THEN amount ELSE 0 END ELSE 0 END),0) digital_income, COUNT(*) tx_count FROM transactions WHERE shiftSessionId=?");
        $sum->execute([$shiftId]);$tot=$sum->fetch(PDO::FETCH_ASSOC)?:[];
        $income=(float)($tot['cash_income']??0);$expense=(float)($tot['cash_expense']??0);$digital=(float)($tot['digital_income']??0);$txCount=(int)($tot['tx_count']??0);$expected=(float)($shift['opening_cash']??0)+$income-$expense;
        $actual=$shift['actual_cash']===null?null:(float)$shift['actual_cash'];$variance=$actual===null?null:$actual-$expected;
        $note='[DATA CLEANUP '.$batchId.' '.date('Y-m-d H:i:s').'] saldo shift dihitung ulang setelah purge implementasi.';
        $pdo->prepare("UPDATE shift_sessions SET cash_income=?,cash_expense=?,expected_cash=?,variance=?,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END,?) WHERE id=?")
            ->execute([$income,$expense,$expected,$variance,$note,$shiftId]);
        if(tamasyaTableExists($pdo,'shift_reports') && !empty($shift['shift_date']) && !empty($shift['shift_time'])){
            $report=$pdo->prepare("SELECT id FROM shift_reports WHERE shiftSessionId=? LIMIT 1 FOR UPDATE");
            $report->execute([(string)$shift['id']]);$reportId=$report->fetchColumn();
            if($reportId)$pdo->prepare("UPDATE shift_reports SET expectedCash=?,variance=?,digitalRevenue=?,transactionsCount=?,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END,?) WHERE id=?")
                ->execute([$expected,$variance,$digital,$txCount,$note,$reportId]);
        }
    }
}

/** Source line 7955: insertSyncTombstones */
function insertSyncTombstones(PDO $pdo, array $entities, string $batchId, string $staffId): void {
    if(!tamasyaTableExists($pdo,'sync_tombstones'))return;
    // Tombstones are durable deletion authority, not a cache.  This system has
    // no per-device acknowledgement watermark proving that every offline node
    // has observed a deletion, so expiring them after an arbitrary 365 days can
    // let a very stale device resurrect a deleted booking/transaction/inventory
    // row. Keep them permanent (expires_at=NULL); a future compactor may remove
    // only tombstones proven acknowledged by every registered sync device.
    $stmt=$pdo->prepare("INSERT INTO sync_tombstones(id,entity_type,entity_id,purge_batch_id,deleted_by,deleted_at,expires_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,NULL) ON DUPLICATE KEY UPDATE purge_batch_id=VALUES(purge_batch_id),deleted_by=VALUES(deleted_by),deleted_at=CURRENT_TIMESTAMP,expires_at=NULL");
    foreach($entities as $entity){
        $type=(string)($entity['type']??'');$id=(string)($entity['id']??'');if($type===''||$id==='')continue;
        $stmt->execute(['tomb_'.substr(hash('sha256',$type.'|'.$id),0,64),$type,$id,$batchId,$staffId]);
    }
}

/** Source line 7964: syncEntityHasTombstone */
function syncEntityHasTombstone(PDO $pdo, string $entityType, string $entityId): bool {
    if($entityId==='' || !tamasyaTableExists($pdo,'sync_tombstones'))return false;
    $stmt=$pdo->prepare("SELECT 1 FROM sync_tombstones WHERE entity_type=? AND entity_id=? AND (expires_at IS NULL OR expires_at>NOW()) LIMIT 1");
    $stmt->execute([$entityType,$entityId]);return (bool)$stmt->fetchColumn();
}

/** Source line 7971: bookingOperationalDeletionBlockers */
function bookingOperationalDeletionBlockers(PDO $pdo, string $bookingId): array {
    $bookingId=trim($bookingId);if($bookingId==='')return [];
    $checks=[
        'transaction_allocations'=>['booking_id','rincian alokasi transaksi'],
        'room_vacancy_reports'=>['booking_id','laporan kamar kosong'],
        'smart_lock_jobs'=>['booking_id','pekerjaan smart-lock'],
        'room_key_events'=>['booking_id','histori kunci']
    ];
    $blockers=[];
    foreach($checks as $table=>$meta){
        if(!tamasyaTableExists($pdo,$table))continue;
        [$column,$label]=$meta;
        $q=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=?");$q->execute([$bookingId]);
        if((int)$q->fetchColumn()>0)$blockers[]=$label;
    }
    return $blockers;
}

