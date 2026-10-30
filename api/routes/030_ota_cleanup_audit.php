<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), array (
  0 => 'ota-receivables',
  1 => 'ota-disbursements',
  2 => 'data-cleanup-mode',
  3 => 'data-cleanup-history',
  4 => 'data-cleanup-preview',
  5 => 'data-cleanup-execute',
  6 => 'test-data-purge-preview',
  7 => 'test-data-purge',
  8 => 'booking-audit-correction',
), true)) { return; }
$routeHandled = true;
/** Keep :out and :in distinct even when the external operation ID fills the
 * 100-character financial operation_id column. Previously substr(...,0,100)
 * silently discarded the direction suffix for a 100-character operation ID.
 */
function tamasyaOtaLedgerOperationId(string $operationId,string $direction): string {
    if(!in_array($direction,['out','in'],true))throw new InvalidArgumentException('Arah mutasi OTA tidak valid.');
    if($operationId===''||strlen($operationId)>100)throw new InvalidArgumentException('ID pencairan OTA tidak valid atau melebihi 100 byte.');
    $suffix=':'.$direction;
    return strlen($operationId)+strlen($suffix)<=100
        ? $operationId.$suffix
        : 'ota_'.hash('sha256',$operationId).$suffix;
}
/** Allocate financial cents, never a fractional floating remainder. */
function tamasyaOtaAllocateCents(array $items,float $amount): array {
    if(!is_finite($amount)||$amount<=0||$amount>9999999999999.99)throw new InvalidArgumentException('Nominal pencairan OTA tidak valid.');
    $remaining=(int)round($amount*100);
    if($remaining<1)throw new InvalidArgumentException('Nominal pencairan OTA harus minimal satu sen.');
    $out=[];
    foreach($items as $item){
        $open=(float)($item['availableAmount']??0);
        if(!is_finite($open)||$open<0)throw new RuntimeException('Saldo piutang OTA tidak valid.');
        $openCents=(int)round($open*100);
        if($openCents<=0)continue;
        $take=min($remaining,$openCents);
        if($take<=0)break;
        $out[]=['transactionId'=>$item['transactionId'],'bookingId'=>$item['bookingId'],'amount'=>$take/100];
        $remaining-=$take;
        if($remaining===0)break;
    }
    if($remaining!==0)throw new RuntimeException('Alokasi Piutang OTA tidak mencukupi setelah validasi server.');
    return $out;
}
switch ($action) {
    case 'ota-receivables':
        requireRoles($loggedInStaff,['admin','manager','finance']);
        if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        echo json_encode(['success'=>true,'receivables'=>otaReceivablePublicState($pdo)]);
        break;

    case 'ota-disbursements':
        requireRoles($loggedInStaff,['admin','manager','finance']);
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        try{
            // `amount` is the NET remittance the OTA actually transferred to the bank.
            // `commissionAmount` is the channel deduction from the gross receivable.
            // commission 0 reproduces the previous two-transaction settlement exactly.
            $amount=round((float)($input['amount']??0),2);
            $commissionAmount=round((float)($input['commissionAmount']??0),2);
            $date=trim((string)($input['date']??date('Y-m-d')));
            $bankAccountId=trim((string)($input['bankAccountId']??''));
            $notes=trim((string)($input['notes']??''));
            $operationId=trim((string)($input['operationId']??($GLOBALS['tamasya_request_operation_id']??'')))?:generateServerId('ota_disb_op');
            if(!is_finite($amount)||$amount<=0 || !validIsoDate($date))throw new InvalidArgumentException('Nominal dan tanggal pencairan OTA tidak valid.');
            if(!is_finite($commissionAmount)||$commissionAmount<0)throw new InvalidArgumentException('Komisi OTA tidak valid. Isi 0 bila channel tidak memotong komisi.');
            if(strlen($operationId)>100)throw new InvalidArgumentException('ID pencairan OTA melebihi 100 byte.');
            if($bankAccountId==='' || strtolower($bankAccountId)==='ota_receivable')throw new InvalidArgumentException('Pilih rekening bank tujuan yang valid.');
            $pdo->beginTransaction();
            $bankAccountId=(string)tamasyaResolvePaymentAccount($pdo,'transfer',$bankAccountId,[
                'allowedMethods'=>['transfer'],'lock'=>true,'context'=>'Pencairan OTA'
            ]);
            // Type/active validation is canonical above; this lookup is display metadata only.
            $account=$pdo->prepare("SELECT id,name FROM bank_accounts WHERE id=? LIMIT 1");
            $account->execute([$bankAccountId]);$accountRow=$account->fetch(PDO::FETCH_ASSOC);
            if(!$accountRow)throw new RuntimeException('Metadata rekening bank tujuan tidak ditemukan setelah validasi.');
            $existing=$pdo->prepare("SELECT id,source_transaction_id,destination_transaction_id,amount,commission_amount,disbursement_date,bank_account_id,notes,created_by FROM ota_disbursements WHERE operation_id=? LIMIT 1 FOR UPDATE");$existing->execute([$operationId]);$existingRow=$existing->fetch(PDO::FETCH_ASSOC);
            if($existingRow){
                if(!moneyMatches((float)$existingRow['amount'],$amount,0.001)
                    || !moneyMatches((float)($existingRow['commission_amount']??0),$commissionAmount,0.001)
                    || (string)$existingRow['disbursement_date']!==$date
                    || (string)$existingRow['bank_account_id']!==$bankAccountId
                    || trim((string)($existingRow['notes']??''))!==$notes
                    || (string)$existingRow['created_by']!==(string)($loggedInStaff['id']??'')){
                    throw new DomainException('Operation ID pencairan OTA sudah dipakai untuk rincian finansial berbeda.');
                }
                tamasyaFinancialCommit($pdo);echo json_encode(['success'=>true,'duplicate'=>true,'disbursementId'=>$existingRow['id'],'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);break;}
            $state=buildOtaReceivableState($pdo,true);$available=(float)$state['availableTotal'];
            // The receivable is settled in full by net + commission. Guarding only the
            // net let a gross amount reach the bank and overstate cash by exactly the
            // commission the channel never remitted.
            $otaSettlementTotal=round($amount+$commissionAmount,2);
            if($otaSettlementTotal>$available+0.005)throw new RuntimeException('Diterima + komisi melebihi Piutang OTA yang belum dialokasikan. Tersedia Rp '.number_format($available,0,',','.').'. Diterima Rp '.number_format($amount,0,',','.').' + komisi Rp '.number_format($commissionAmount,0,',','.').' = Rp '.number_format($otaSettlementTotal,0,',','.').'.');
            // Decimal ledger allocations must total exactly the posted amount.
            // Rounded floats plus epsilon previously allowed one-cent drift.
            $allocations=tamasyaOtaAllocateCents($state['items'],$otaSettlementTotal);
            $id=generateServerId('ota_disb');$sourceTx=generateServerId('tx_ota_out');$destTx=generateServerId('tx_ota_in');$commissionTx=$commissionAmount>0?generateServerId('tx_ota_fee'):null;$actor=currentStaffLabel($loggedInStaff);
            $descriptionOut='[Mutasi] Pengurangan Piutang OTA untuk pencairan ke '.(string)$accountRow['name'];
            $descriptionIn=$notes!==''?$notes:'[Mutasi] Penerimaan pencairan dana Piutang OTA';
            tamasyaPostFinancialTransaction($pdo,[
                'id'=>$sourceTx,'type'=>'expense','category'=>'Mutasi Internal (Pencairan OTA)','subcategory'=>'OTA','amount'=>$amount,'date'=>$date,
                'description'=>$descriptionOut,'createdBy'=>$actor,'bankAccountId'=>'ota_receivable','baseAmount'=>$amount,'taxAmount'=>0,'taxRate'=>0,
                'taxSnapshotStatus'=>'not_applicable','taxSource'=>'ota_settlement','transactionKind'=>'ota_transfer','sourceEntity'=>'ota_disbursement',
                'sourceEntityId'=>$id,'isSystemGenerated'=>1,'operationId'=>tamasyaOtaLedgerOperationId($operationId,'out'),'updatedBy'=>$loggedInStaff['id']??null,'updatedSource'=>'web','version'=>1
            ],$loggedInStaff,'ota',['source'=>'web']);
            tamasyaPostFinancialTransaction($pdo,[
                'id'=>$destTx,'type'=>'income','category'=>'Mutasi Internal (Pencairan OTA)','subcategory'=>'OTA','amount'=>$amount,'date'=>$date,
                'description'=>$descriptionIn,'createdBy'=>$actor,'bankAccountId'=>$bankAccountId,'baseAmount'=>$amount,'taxAmount'=>0,'taxRate'=>0,
                'taxSnapshotStatus'=>'not_applicable','taxSource'=>'ota_settlement','transactionKind'=>'ota_transfer','sourceEntity'=>'ota_disbursement',
                'sourceEntityId'=>$id,'isSystemGenerated'=>1,'operationId'=>tamasyaOtaLedgerOperationId($operationId,'in'),'updatedBy'=>$loggedInStaff['id']??null,'updatedSource'=>'web','version'=>1
            ],$loggedInStaff,'ota',['source'=>'web']);
            if($commissionTx!==null){
                // The commission settles against the OTA receivable, not the drawer: no
                // money leaves the bank account, the hotel simply never received it.
                // Posting it on 'ota_receivable' keeps it out of cash-expense totals while
                // still recognising the cost in the profit and loss statement.
                $commissionCategory=tamasyaRequireSystemFinanceCategory($pdo,'ota_commission','expense',true);
                $commissionDescription='Komisi channel OTA atas pencairan ke '.(string)$accountRow['name'];
                tamasyaPostFinancialTransaction($pdo,[
                    'id'=>$commissionTx,'type'=>'expense','category'=>(string)$commissionCategory['name'],'categoryId'=>(string)$commissionCategory['id'],
                    'categorySystemKey'=>'ota_commission','subcategory'=>'OTA','amount'=>$commissionAmount,'date'=>$date,
                    'description'=>$commissionDescription,'createdBy'=>$actor,'bankAccountId'=>'ota_receivable','baseAmount'=>$commissionAmount,'taxAmount'=>0,'taxRate'=>0,
                    'taxSnapshotStatus'=>'not_applicable','taxSource'=>'ota_settlement','transactionKind'=>'ota_commission','sourceEntity'=>'ota_disbursement',
                    'sourceEntityId'=>$id,'isSystemGenerated'=>1,'operationId'=>tamasyaOtaLedgerOperationId($operationId,'fee'),'updatedBy'=>$loggedInStaff['id']??null,'updatedSource'=>'web','version'=>1
                ],$loggedInStaff,'ota',['source'=>'web']);
            }
            $pdo->prepare("INSERT INTO ota_disbursements(id,operation_id,disbursement_date,bank_account_id,amount,commission_amount,notes,source_transaction_id,destination_transaction_id,status,created_by,created_by_name,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?, 'completed',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
                ->execute([$id,$operationId,$date,$bankAccountId,$amount,$commissionAmount,$notes?:null,$sourceTx,$destTx,$loggedInStaff['id'],$actor]);
            $insertItem=$pdo->prepare("INSERT INTO ota_disbursement_items(id,disbursement_id,receivable_transaction_id,booking_id,allocated_amount,created_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)");
            foreach($allocations as $allocation){$insertItem->execute(['ota_item_'.substr(hash('sha256',$id.'|'.$allocation['transactionId']),0,64),$id,$allocation['transactionId'],$allocation['bookingId'],$allocation['amount']]);}
            $otaAfterStmt=$pdo->prepare("SELECT * FROM ota_disbursements WHERE id=? LIMIT 1");$otaAfterStmt->execute([$id]);$otaAfter=$otaAfterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'amount'=>$amount,'bank_account_id'=>$bankAccountId,'status'=>'completed'];
            $otaAfter['allocations']=$allocations;$otaAfter['sourceTransactionId']=$sourceTx;$otaAfter['destinationTransactionId']=$destTx;$otaAfter['commissionTransactionId']=$commissionTx;
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Pencairan Piutang OTA','ota_disbursement',$id,null,$otaAfter,'web');
            $notifId=generateServerId('n');$pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'finance')")
                ->execute([$notifId,'Pencairan Piutang OTA Rp '.number_format($amount,0,',','.').($commissionAmount>0?' (komisi Rp '.number_format($commissionAmount,0,',','.').')':'').' ke '.$accountRow['name'].' dicatat secara atomik.']);
            bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            broadcastTelegramNotification($pdo,"🌐 *PENCAIRAN PIUTANG OTA*\n\n💰 Diterima: *Rp ".number_format($amount,0,',','.').($commissionAmount>0?"*\n💸 Komisi OTA: *Rp ".number_format($commissionAmount,0,',','.')."*":"") . "*\n🏦 Tujuan: *{$accountRow['name']}*\n👤 Oleh: *{$actor}*",true);
            echo json_encode(['success'=>true,'message'=>'Pencairan Piutang OTA berhasil dicatat dan dialokasikan ke booking secara atomik.','disbursementId'=>$id,'netAmount'=>$amount,'commissionAmount'=>$commissionAmount,'allocations'=>$allocations,'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code($e instanceof InvalidArgumentException?400:409);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal mencatat pencairan OTA',$e)]);}
        break;

    case 'data-cleanup-mode':
        requireRoles($loggedInStaff,['admin']);
        if($_SERVER['REQUEST_METHOD']==='GET'){
            echo json_encode(['success'=>true,'mode'=>getImplementationCleanupMode($pdo)]);break;
        }
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        try{
            verifyActiveAdminPassword($pdo,$loggedInStaff,(string)($input['currentPassword']??''));
            $enabled=!empty($input['enabled']);
            $note=trim((string)($input['note']??''));
            if($enabled && !tamasyaImplementationCleanupEnabled())throw new RuntimeException('Pembersihan implementasi dinonaktifkan pada deployment ini. Production harus mengaktifkan TAMASYA_IMPLEMENTATION_CLEANUP_ENABLED=1 hanya selama maintenance window.');
            if($enabled && strlen($note)<10)throw new InvalidArgumentException('Alasan mengaktifkan mode pembersihan minimal 10 karakter.');
            $minutes=max(15,min(120,(int)($input['durationMinutes']??60)));
            $pdo->beginTransaction();
            $cleanupModeBeforeStmt=$pdo->query("SELECT cleanup_mode_until,cleanup_mode_enabled_by,cleanup_mode_note FROM config WHERE id='system_default' FOR UPDATE");$cleanupModeBefore=$cleanupModeBeforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($enabled){
                $until=date('Y-m-d H:i:s',time()+$minutes*60);
                $pdo->prepare("UPDATE config SET cleanup_mode_until=?,cleanup_mode_enabled_by=?,cleanup_mode_note=? WHERE id='system_default'")
                    ->execute([$until,$loggedInStaff['id'],$note]);
                $cleanupAction='Mengaktifkan mode pembersihan implementasi';
            }else{
                $pdo->prepare("UPDATE config SET cleanup_mode_until=NULL,cleanup_mode_enabled_by=NULL,cleanup_mode_note=NULL WHERE id='system_default'")->execute();
                $cleanupAction='Menonaktifkan mode pembersihan implementasi';
            }
            $cleanupModeAfterStmt=$pdo->query("SELECT cleanup_mode_until,cleanup_mode_enabled_by,cleanup_mode_note FROM config WHERE id='system_default'");$cleanupModeAfter=$cleanupModeAfterStmt->fetch(PDO::FETCH_ASSOC)?:null;
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$cleanupAction,'data_cleanup_mode','system_default',$cleanupModeBefore,$cleanupModeAfter,'web');
            bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'mode'=>getImplementationCleanupMode($pdo)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code($e instanceof InvalidArgumentException?400:403);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal mengubah mode pembersihan',$e)]);}
        break;

    case 'data-cleanup-history':
        requireRoles($loggedInStaff,['admin']);
        if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        try{
            $rows=$pdo->query("SELECT id,status,reason_category,reason,backup_confirmed,force_mode,booking_count,standalone_transaction_count,transaction_count,gross_income,gross_expense,tax_amount,affected_shift_count,affected_reconciliation_count,created_by_name,created_at,completed_at FROM data_purge_batches ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC)?:[];
            echo json_encode(['success'=>true,'batches'=>$rows,'mode'=>getImplementationCleanupMode($pdo)]);
        }catch(Throwable $e){tamasyaApplyExceptionHttpStatus($e, 500);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal memuat riwayat pembersihan',$e)]);}
        break;

    case 'data-cleanup-preview':
        requireRoles($loggedInStaff,['admin']);
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        try{
            $preview=buildImplementationCleanupPreview($pdo,(array)($input['bookingIds']??[]),(array)($input['transactionIds']??[]),!empty($input['forceMode']),false);
            echo json_encode(['success'=>true,'preview'=>$preview]);
        }catch(Throwable $e){http_response_code($e instanceof RuntimeException?404:400);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal memeriksa dampak pembersihan',$e)]);}
        break;

    case 'data-cleanup-execute':
        requireRoles($loggedInStaff,['admin']);
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $reasonCategory=trim((string)($input['reasonCategory']??''));
        $allowedReasons=['trial_data','duplicate','wrong_input','admin_request','implementation_reset'];
        $reason=trim((string)($input['reason']??''));
        $phrase=trim((string)($input['confirmationPhrase']??''));
        $previewHash=trim((string)($input['previewHash']??''));
        $forceMode=!empty($input['forceMode']);
        $backupConfirmed=!empty($input['backupConfirmed']);
        if(!in_array($reasonCategory,$allowedReasons,true)){http_response_code(400);echo json_encode(['success'=>false,'error'=>'Kategori alasan pembersihan tidak valid.']);break;}
        if(strlen($reason)<10 || !$backupConfirmed){http_response_code(400);echo json_encode(['success'=>false,'error'=>'Alasan minimal 10 karakter dan konfirmasi backup wajib diisi.']);break;}
        try{
            verifyActiveAdminPassword($pdo,$loggedInStaff,(string)($input['currentPassword']??''));
            $pdo->beginTransaction();
            $cleanupGate=tamasyaImplementationCleanupGate($pdo,true);
            $preview=buildImplementationCleanupPreview($pdo,(array)($input['bookingIds']??[]),(array)($input['transactionIds']??[]),$forceMode,true);
            if(!$preview['canPurge']){
                $pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Pembersihan ditolak: '.implode(' ',(array)$preview['blockers']),'preview'=>$preview]);break;
            }
            if($phrase!==$preview['confirmationPhrase'] || $previewHash==='' || !hash_equals((string)$preview['previewHash'],$previewHash)){
                $pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'error'=>'Preview sudah berubah atau frasa konfirmasi tidak cocok. Jalankan preview ulang.','preview'=>$preview]);break;
            }
            tamasyaR164AssertCleanupCustodySafe($pdo,(array)$preview['bookingIds'],true);
            $bookingIds=(array)$preview['bookingIds'];$txIds=(array)$preview['transactionIds'];$vacancyReportIds=(array)($preview['vacancyReportIds']??[]);$batchId=generateServerId('cleanup');
            $impact=[
                'bookingIds'=>$bookingIds,'transactionIds'=>$txIds,'vacancyReportIds'=>$vacancyReportIds,'shiftIds'=>$preview['shiftIds'],
                'grossIncome'=>$preview['grossIncome'],'grossExpense'=>$preview['grossExpense'],'taxAmount'=>$preview['taxAmount'],
                'reasonCategory'=>$reasonCategory,'reason'=>$reason,
                'backup'=>$cleanupGate['backup']
            ];
            $snapshotHash=hash('sha256',json_encode($impact,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $pdo->prepare("INSERT INTO data_purge_batches(id,status,reason_category,reason,backup_confirmed,force_mode,booking_count,standalone_transaction_count,transaction_count,gross_income,gross_expense,tax_amount,affected_shift_count,affected_reconciliation_count,impact_json,snapshot_hash,created_by,created_by_name,created_at) VALUES (?,'processing',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)")
                ->execute([$batchId,$reasonCategory,$reason,1,$forceMode?1:0,(int)$preview['bookingCount'],(int)$preview['standaloneTransactionCount'],(int)$preview['transactionCount'],(float)$preview['grossIncome'],(float)$preview['grossExpense'],(float)$preview['taxAmount'],count((array)$preview['shiftIds']),(int)$preview['reconciliationCount'],json_encode($impact,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$snapshotHash,$loggedInStaff['id'],currentStaffLabel($loggedInStaff)]);

            $insertItem=$pdo->prepare("INSERT INTO data_purge_batch_items(id,batch_id,entity_type,entity_id,related_booking_id,amount,item_hash,created_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP)");
            foreach((array)$preview['bookings'] as $row){$id=(string)$row['id'];$insertItem->execute(['pi_'.substr(hash('sha256',$batchId.'|booking|'.$id),0,64),$batchId,'booking',$id,$id,(float)($row['totalAmount']??0),hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))]);}
            $txRows=[];
            if($txIds){$ph=cleanupPlaceholders($txIds);$q=$pdo->prepare("SELECT id,bookingId,amount,type,transactionKind FROM transactions WHERE id IN ($ph)");$q->execute($txIds);$txRows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}
            foreach($txRows as $row){$id=(string)$row['id'];$insertItem->execute(['pi_'.substr(hash('sha256',$batchId.'|transaction|'.$id),0,64),$batchId,'transaction',$id,trim((string)($row['bookingId']??''))?:null,(float)($row['amount']??0),hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))]);}
            if($vacancyReportIds){
                $vph=cleanupPlaceholders($vacancyReportIds);
                $vq=$pdo->prepare("SELECT id,booking_id,room_number,status,reported_by,reported_at FROM room_vacancy_reports WHERE id IN ($vph)");
                $vq->execute($vacancyReportIds);
                foreach($vq->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$id=(string)$row['id'];$insertItem->execute(['pi_'.substr(hash('sha256',$batchId.'|room_vacancy_report|'.$id),0,64),$batchId,'room_vacancy_report',$id,trim((string)($row['booking_id']??''))?:null,0,hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))]);}
            }

            $otaAdjustments=(array)($preview['otaDisbursementAdjustments']??[]);
            $otaDeletedTransactionIds=[];
            foreach($otaAdjustments as $adj){
                $disbursementId=(string)($adj['disbursementId']??'');if($disbursementId==='')continue;
                $sourceTx=(string)($adj['sourceTransactionId']??'');$destTx=(string)($adj['destinationTransactionId']??'');
                $commissionTx=(string)($adj['commissionTransactionId']??'');
                $receivableIds=(array)($adj['receivableTransactionIds']??[]);
                if(($adj['action']??'')==='delete'){
                    // Seluruh disbursement dihapus, maka seluruh allocation row
                    // harus ikut dibersihkan, termasuk baris yang tidak berada
                    // dalam selection tetapi tidak mungkin tersisa secara sah.
                    $pdo->prepare("DELETE FROM ota_disbursement_items WHERE disbursement_id=?")->execute([$disbursementId]);
                    $pairIds=array_values(array_filter([$sourceTx,$destTx,$commissionTx]));
                    if($pairIds){$pph=cleanupPlaceholders($pairIds);if(tamasyaTableExists($pdo,'reconciliation_items'))$pdo->prepare("DELETE FROM reconciliation_items WHERE transaction_id IN ($pph)")->execute($pairIds);if(tamasyaTableExists($pdo,'journal_entries')){$q=$pdo->prepare("SELECT id FROM journal_entries WHERE transaction_id IN ($pph)");$q->execute($pairIds);$jids=$q->fetchAll(PDO::FETCH_COLUMN)?:[];if($jids&&tamasyaTableExists($pdo,'journal_lines')){$jph=cleanupPlaceholders($jids);$pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id IN ($jph)")->execute($jids);}$pdo->prepare("DELETE FROM journal_entries WHERE transaction_id IN ($pph)")->execute($pairIds);}if(tamasyaTableExists($pdo,'transaction_allocations'))$pdo->prepare("DELETE FROM transaction_allocations WHERE transaction_id IN ($pph)")->execute($pairIds);tamasyaDeleteFinancialTransactions($pdo,$pairIds,$loggedInStaff,'ota_cleanup','ota_cleanup_delete');$otaDeletedTransactionIds=array_merge($otaDeletedTransactionIds,$pairIds);}
                    $pdo->prepare("DELETE FROM ota_disbursements WHERE id=?")->execute([$disbursementId]);
                }else{
                    if($receivableIds){
                        $rph=cleanupPlaceholders($receivableIds);
                        $pdo->prepare("DELETE FROM ota_disbursement_items WHERE disbursement_id=? AND receivable_transaction_id IN ($rph)")
                            ->execute(array_merge([$disbursementId],$receivableIds));
                    }
                    $remaining=round((float)($adj['remainingAmount']??0),2);
                    if($remaining<=0)throw new RuntimeException('Sisa pencairan OTA tidak valid saat cleanup.');
                    if(tamasyaTableExists($pdo,'reconciliation_items')){$pairIds=array_values(array_filter([$sourceTx,$destTx]));if($pairIds){$pph=cleanupPlaceholders($pairIds);$pdo->prepare("DELETE FROM reconciliation_items WHERE transaction_id IN ($pph)")->execute($pairIds);}}
                    $pdo->prepare("UPDATE ota_disbursements SET amount=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$remaining,$disbursementId]);
                    // The rows are reduced and then their journal is deleted, so they must
                    // be locked first. Reading them without a row lock left a window where
                    // a concurrent posting could read a journal that this transaction
                    // goes on to delete, or have its amount overwritten by this reduction.
                    tamasyaMutateFinancialTransactions($pdo,array_values(array_filter([$sourceTx,$destTx])),[
                        'amount'=>$remaining,'baseAmount'=>$remaining,'reconciliationStatus'=>'disputed','reconciliationReference'=>$batchId,
                    ],$loggedInStaff,'ota_cleanup','ota_cleanup_adjust',['source'=>'data-cleanup','lockRows'=>true]);
                }
            }

            if($txIds){
                $ph=cleanupPlaceholders($txIds);
                if(tamasyaTableExists($pdo,'journal_entries')){
                    $q=$pdo->prepare("SELECT id FROM journal_entries WHERE transaction_id IN ($ph)");$q->execute($txIds);$journalIds=$q->fetchAll(PDO::FETCH_COLUMN)?:[];
                    if($journalIds && tamasyaTableExists($pdo,'journal_lines')){$jph=cleanupPlaceholders($journalIds);$pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id IN ($jph)")->execute($journalIds);}
                    $pdo->prepare("DELETE FROM journal_entries WHERE transaction_id IN ($ph)")->execute($txIds);
                }
                if(tamasyaTableExists($pdo,'reconciliation_items'))$pdo->prepare("DELETE FROM reconciliation_items WHERE transaction_id IN ($ph)")->execute($txIds);
                if(tamasyaTableExists($pdo,'approval_requests')){
                    $parts=[];$params=[];
                    if($bookingIds){$parts[]="(LOWER(entity_type) IN ('booking','bookings') AND entity_id IN (".cleanupPlaceholders($bookingIds)."))";$params=array_merge($params,$bookingIds);}
                    $parts[]="(LOWER(entity_type) IN ('transaction','transactions') AND entity_id IN ($ph))";$params=array_merge($params,$txIds);
                    $pdo->prepare("DELETE FROM approval_requests WHERE ".implode(' OR ',$parts))->execute($params);
                }
                if(tamasyaTableExists($pdo,'sync_operations')){
                    $parts=[];$params=[];
                    if($bookingIds){$parts[]="(LOWER(entity_type) IN ('booking','bookings') AND entity_id IN (".cleanupPlaceholders($bookingIds)."))";$params=array_merge($params,$bookingIds);}
                    $parts[]="(LOWER(entity_type) IN ('transaction','transactions') AND entity_id IN ($ph))";$params=array_merge($params,$txIds);
                    $pdo->prepare("DELETE FROM sync_operations WHERE ".implode(' OR ',$parts))->execute($params);
                }
                if(tamasyaTableExists($pdo,'transaction_allocations'))$pdo->prepare("DELETE FROM transaction_allocations WHERE transaction_id IN ($ph)")->execute($txIds);
                tamasyaDeleteFinancialTransactions($pdo,$txIds,$loggedInStaff,'ota_cleanup','ota_cleanup_delete');
            }
            if($bookingIds){
                $ph=cleanupPlaceholders($bookingIds);
                if(tamasyaTableExists($pdo,'room_vacancy_reports'))$pdo->prepare("DELETE FROM room_vacancy_reports WHERE booking_id IN ($ph)")->execute($bookingIds);
                if(tamasyaTableExists($pdo,'room_key_events'))$pdo->prepare("DELETE FROM room_key_events WHERE booking_id IN ($ph)")->execute($bookingIds);
                if(tamasyaTableExists($pdo,'smart_lock_jobs'))$pdo->prepare("DELETE FROM smart_lock_jobs WHERE booking_id IN ($ph)")->execute($bookingIds);
                if(tamasyaTableExists($pdo,'room_access_control'))$pdo->prepare("UPDATE room_access_control SET current_booking_id=NULL,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE current_booking_id IN ($ph)")->execute(array_merge([$loggedInStaff['id']],$bookingIds));
                if(tamasyaTableExists($pdo,'night_audit_items'))$pdo->prepare("UPDATE night_audit_items SET active_booking_id=NULL,guest_name=NULL WHERE active_booking_id IN ($ph)")->execute($bookingIds);
                $rooms=[];$q=$pdo->prepare("SELECT DISTINCT roomNumber FROM bookings WHERE id IN ($ph)");$q->execute($bookingIds);$rooms=$q->fetchAll(PDO::FETCH_COLUMN)?:[];
                if(tamasyaTableExists($pdo,'transaction_allocations'))$pdo->prepare("DELETE FROM transaction_allocations WHERE booking_id IN ($ph)")->execute($bookingIds);
                $pdo->prepare("DELETE FROM bookings WHERE id IN ($ph)")->execute($bookingIds);
                foreach($rooms as $room){
                    $q=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE roomNumber=? AND status='active'");$q->execute([$room]);
                    if((int)$q->fetchColumn()===0){
                        tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,(string)$room,'data-cleanup');
                    }
                }
            }
            // Rebuild journal lines after OTA pair reduction/deletion and after
            // selected ledger rows have been removed.
            // Strict mode is mandatory here: the rebuild deletes every journal_line
            // of an entry before regenerating it, so a failure while rebuilding
            // would otherwise be swallowed (this batch owns an outer transaction,
            // so syncJournalProjections cannot roll it back) and the batch would
            // commit a journal entry with zero lines. Throwing lets the catch below
            // roll the whole purge back instead of committing unbalanced data.
            syncJournalProjections($pdo,true);
            recalculateCleanupShiftSessions($pdo,(array)$preview['shiftIds'],$batchId);
            $tombstones=[];foreach($bookingIds as $id)$tombstones[]=['type'=>'bookings','id'=>$id];foreach(array_merge($txIds,$otaDeletedTransactionIds) as $id)$tombstones[]=['type'=>'transactions','id'=>$id];foreach($vacancyReportIds as $id)$tombstones[]=['type'=>'room_vacancy_reports','id'=>$id];foreach($otaAdjustments as $adj){if(($adj['action']??'')==='delete')$tombstones[]=['type'=>'ota_disbursements','id'=>(string)$adj['disbursementId']];}
            insertSyncTombstones($pdo,$tombstones,$batchId,(string)$loggedInStaff['id']);
            $pdo->prepare("UPDATE data_purge_batches SET status='completed',completed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$batchId]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Pembersihan permanen data masa implementasi','data_purge_batch',$batchId,null,['bookingIds'=>$bookingIds,'transactionIds'=>$txIds,'reasonCategory'=>$reasonCategory,'reason'=>$reason,'forceMode'=>$forceMode,'backup'=>$cleanupGate['backup']]);
            $notifId=generateServerId('n');$pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'system')")->execute([$notifId,'Admin '.currentStaffLabel($loggedInStaff).' menyelesaikan pembersihan data batch '.$batchId.'. Backup referensi: '.$cleanupGate['backup']['id'].'.']);
            bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Data terpilih dan seluruh relasi operasionalnya berhasil dibersihkan.','batchId'=>$batchId,'backupReference'=>$cleanupGate['backup']['id'],'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code($e instanceof RuntimeException?403:500);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal membersihkan data implementasi',$e)]);}
        break;

    case 'test-data-purge-preview':
        requireRoles($loggedInStaff,['admin']);
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        try{
            $preview=testDataPurgePreview($pdo,(string)($input['bookingId']??''),false);
            echo json_encode(['success'=>true,'preview'=>$preview]);
        }catch(Throwable $e){
            http_response_code($e instanceof RuntimeException?404:400);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal memeriksa dampak hapus permanen',$e)]);
        }
        break;

    case 'test-data-purge':
        requireRoles($loggedInStaff,['admin']);
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $bookingId=trim((string)($input['bookingId']??''));
        $reason=trim((string)($input['reason']??''));
        $phrase=trim((string)($input['confirmationPhrase']??''));
        $password=(string)($input['currentPassword']??'');
        if($bookingId==='' || strlen($reason)<10){http_response_code(400);echo json_encode(['success'=>false,'error'=>'Booking dan alasan data uji minimal 10 karakter wajib diisi.']);break;}
        if($phrase!=='HAPUS DATA UJI '.$bookingId){http_response_code(400);echo json_encode(['success'=>false,'error'=>'Frasa konfirmasi tidak cocok. Ketik persis sesuai preview.']);break;}
        try{
            verifyActiveAdminPassword($pdo,$loggedInStaff,$password);
            $pdo->beginTransaction();
            $cleanupGate=tamasyaImplementationCleanupGate($pdo,true);
            $preview=testDataPurgePreview($pdo,$bookingId,true);
            if(!$preview['canPurge']){
                $pdo->rollBack();http_response_code(409);
                echo json_encode(['success'=>false,'error'=>'Data belum aman dipurge: '.implode(' ',(array)$preview['blockers']),'preview'=>$preview]);break;
            }
            tamasyaR164AssertCleanupCustodySafe($pdo,[$bookingId],true);
            $txIds=(array)$preview['transactionIds'];
            $vacancyReportIds=(array)($preview['vacancyReportIds']??[]);
            $purgeId=generateServerId('purge');
            $shiftIds=[];
            if($txIds){
                $ph=implode(',',array_fill(0,count($txIds),'?'));
                $q=$pdo->prepare("SELECT DISTINCT shiftSessionId FROM transactions WHERE id IN ($ph) AND shiftSessionId IS NOT NULL AND shiftSessionId<>''");$q->execute($txIds);$shiftIds=$q->fetchAll(PDO::FETCH_COLUMN)?:[];
                if(tamasyaTableExists($pdo,'journal_entries')){
                    $q=$pdo->prepare("SELECT id FROM journal_entries WHERE transaction_id IN ($ph)");$q->execute($txIds);$journalIds=$q->fetchAll(PDO::FETCH_COLUMN)?:[];
                    if($journalIds && tamasyaTableExists($pdo,'journal_lines')){$jph=implode(',',array_fill(0,count($journalIds),'?'));$pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id IN ($jph)")->execute($journalIds);}
                    $pdo->prepare("DELETE FROM journal_entries WHERE transaction_id IN ($ph)")->execute($txIds);
                }
                if(tamasyaTableExists($pdo,'reconciliation_items'))$pdo->prepare("DELETE FROM reconciliation_items WHERE transaction_id IN ($ph)")->execute($txIds);
                if(tamasyaTableExists($pdo,'approval_requests')){$params=array_merge([$bookingId],$txIds);$pdo->prepare("DELETE FROM approval_requests WHERE (LOWER(entity_type) IN ('booking','bookings') AND entity_id=?) OR (LOWER(entity_type) IN ('transaction','transactions') AND entity_id IN ($ph))")->execute($params);}
                if(tamasyaTableExists($pdo,'sync_operations')){$params=array_merge([$bookingId],$txIds);$pdo->prepare("DELETE FROM sync_operations WHERE (LOWER(entity_type) IN ('booking','bookings') AND entity_id=?) OR (LOWER(entity_type) IN ('transaction','transactions') AND entity_id IN ($ph))")->execute($params);}
                if(tamasyaTableExists($pdo,'transaction_allocations'))$pdo->prepare("DELETE FROM transaction_allocations WHERE transaction_id IN ($ph)")->execute($txIds);
                tamasyaDeleteFinancialTransactions($pdo,$txIds,$loggedInStaff,'ota_cleanup','ota_cleanup_delete');
            }else{
                if(tamasyaTableExists($pdo,'approval_requests'))$pdo->prepare("DELETE FROM approval_requests WHERE LOWER(entity_type) IN ('booking','bookings') AND entity_id=?")->execute([$bookingId]);
                if(tamasyaTableExists($pdo,'sync_operations'))$pdo->prepare("DELETE FROM sync_operations WHERE LOWER(entity_type) IN ('booking','bookings') AND entity_id=?")->execute([$bookingId]);
            }
            if(tamasyaTableExists($pdo,'room_vacancy_reports'))$pdo->prepare("DELETE FROM room_vacancy_reports WHERE booking_id=?")->execute([$bookingId]);
            if(tamasyaTableExists($pdo,'room_key_events'))$pdo->prepare("DELETE FROM room_key_events WHERE booking_id=?")->execute([$bookingId]);
            if(tamasyaTableExists($pdo,'smart_lock_jobs'))$pdo->prepare("DELETE FROM smart_lock_jobs WHERE booking_id=?")->execute([$bookingId]);
            if(tamasyaTableExists($pdo,'room_access_control'))$pdo->prepare("UPDATE room_access_control SET current_booking_id=NULL,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE current_booking_id=?")->execute([$loggedInStaff['id']??null,$bookingId]);
            if(tamasyaTableExists($pdo,'night_audit_items'))$pdo->prepare("UPDATE night_audit_items SET active_booking_id=NULL,guest_name=NULL WHERE active_booking_id=?")->execute([$bookingId]);
            if(tamasyaTableExists($pdo,'transaction_allocations'))$pdo->prepare("DELETE FROM transaction_allocations WHERE booking_id=?")->execute([$bookingId]);
            $pdo->prepare("DELETE FROM bookings WHERE id=?")->execute([$bookingId]);
            $room=(string)($preview['booking']['roomNumber']??'');
            if($room!==''){
                $q=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE roomNumber=? AND status='active'");$q->execute([$room]);
                if((int)$q->fetchColumn()===0){
                    // Purge may remove the last blocker. Reconcile regardless of the
                    // previous cache value so phantom Servis/Housekeeping cannot stay.
                    tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$room,'test-data-purge');
                }
            }
            recalculatePurgedShiftSessions($pdo,$shiftIds);
            $tombstones=[['type'=>'bookings','id'=>$bookingId]];
            foreach($txIds as $id)$tombstones[]=['type'=>'transactions','id'=>$id];
            foreach($vacancyReportIds as $id)$tombstones[]=['type'=>'room_vacancy_reports','id'=>$id];
            insertSyncTombstones($pdo,$tombstones,$purgeId,(string)($loggedInStaff['id']??''));
            $snapshotHash=hash('sha256',json_encode(['bookingId'=>$bookingId,'transactions'=>$txIds,'vacancyReports'=>$vacancyReportIds,'income'=>$preview['grossIncome'],'expense'=>$preview['grossExpense'],'tax'=>$preview['taxAmount']],JSON_UNESCAPED_UNICODE));
            $pdo->prepare("INSERT INTO test_data_purge_logs(id,entity_type,entity_id,room_number,transaction_count,gross_income,gross_expense,tax_amount,reason,snapshot_hash,purged_by,purged_by_name,purged_at) VALUES (?,'booking',?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)")
                ->execute([$purgeId,$bookingId,$room,(int)$preview['transactionCount'],(float)$preview['grossIncome'],(float)$preview['grossExpense'],(float)$preview['taxAmount'],$reason,$snapshotHash,$loggedInStaff['id'],currentStaffLabel($loggedInStaff)]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Purge permanen data uji','test_data_purge',$purgeId,null,['bookingId'=>$bookingId,'roomNumber'=>$room,'transactionCount'=>(int)$preview['transactionCount'],'reason'=>$reason,'backup'=>$cleanupGate['backup']]);
            $notifId=generateServerId('n');
            $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'system')")
                ->execute([$notifId,'Admin '.currentStaffLabel($loggedInStaff).' menghapus permanen data uji booking '.$bookingId.'. Backup referensi: '.$cleanupGate['backup']['id'].'.']);
            bumpServerRevision($pdo);
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Data uji booking dan relasi keuangannya berhasil dihapus permanen.','purgeId'=>$purgeId,'backupReference'=>$cleanupGate['backup']['id'],'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            http_response_code($e instanceof RuntimeException?403:500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal menghapus permanen data uji',$e)]);
        }
        break;

    case 'booking-audit-correction':
        // Hak koreksi audit mengikuti capability kustom. Data permission lama yang
        // belum memiliki key ini tetap kompatibel melalui fallback role.
        requireDesktopTabAccess($loggedInStaff, 'finance', ['admin', 'manager', 'finance']);
        requireCapability($loggedInStaff, 'correct_booking_audit', ['admin', 'manager', 'finance']);
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['POST', 'PUT'], true)) {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
            break;
        }

        $bookingId = trim((string)($input['bookingId'] ?? ''));
        $transactionId = trim((string)($input['transactionId'] ?? ''));
        $reason = trim((string)($input['reason'] ?? ''));
        $operationId = trim((string)($input['operationId'] ?? ($GLOBALS['tamasya_request_operation_id'] ?? ''))) ?: generateServerId('audit_booking_op');
        $bookingPatch = is_array($input['booking'] ?? null) ? $input['booking'] : [];
        $transactionPatch = is_array($input['transaction'] ?? null) ? $input['transaction'] : [];
        $activityPatch = is_array($input['activity'] ?? null) ? $input['activity'] : [];
        $auditSmartLockRefreshJobId = null;
        $auditSmartLockRefreshResult = null;
        $activityPurpose = strtolower(trim((string)($activityPatch['purpose'] ?? 'standard')));
        if (!in_array($activityPurpose, ['standard', 'extension', 'extra', 'transfer'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Aktivitas / Fitur Khusus tidak valid.']);
            break;
        }

        if ($bookingId === '' || strlen($reason) < 5) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Booking dan alasan koreksi minimal 5 karakter wajib diisi.']);
            break;
        }

        // A transaction correction is meaningless without the transaction it targets.
        // Without this check \$oldTx stays null, \$correctionMode falls back to
        // 'booking-only' at :797, and every field the operator typed into the
        // "Transaksi Arus Kas Terpilih" panel is silently discarded while the
        // response still reports success.
        if ($transactionPatch && $transactionId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Transaksi arus kas yang dikoreksi belum dipilih. Pilih transaksi asal lebih dulu, atau kosongkan bagian C bila hanya mengoreksi reservasi.']);
            break;
        }

        try {
            $pdo->beginTransaction();

            $stmtBooking = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmtBooking->execute([$bookingId]);
            $oldBooking = $stmtBooking->fetch();
            if (!$oldBooking) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Reservasi tidak ditemukan.']);
                break;
            }

            $oldTx = null;
            if ($transactionId !== '') {
                $stmtTx = $pdo->prepare('SELECT * FROM transactions WHERE id = ? LIMIT 1 FOR UPDATE');
                $stmtTx->execute([$transactionId]);
                $oldTx = $stmtTx->fetch();
                if (!$oldTx) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Transaksi reservasi tidak ditemukan.']);
                    break;
                }
                $linkedId = trim((string)($oldTx['bookingId'] ?? ''));
                if ($linkedId === '') $linkedId = trim((string)($oldTx['sourceEntityId'] ?? ''));
                if ($linkedId !== $bookingId) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => 'Transaksi tidak terhubung ke reservasi yang dipilih.']);
                    break;
                }
            }

            $sensitiveAmount = max(
                (float)($bookingPatch['totalAmount'] ?? $oldBooking['totalAmount'] ?? 0),
                (float)($transactionPatch['amount'] ?? $oldTx['amount'] ?? 0)
            );
            $approvalId = requireSensitiveApproval(
                $pdo,
                $loggedInStaff,
                'correct_booking_audit',
                'booking',
                $bookingId,
                $sensitiveAmount,
                ['reason' => $reason, 'activity' => $activityPatch, 'booking' => $bookingPatch, 'transaction' => $transactionPatch]
            );
            if ($approvalId === false) {
                tamasyaFinancialCommit($pdo);
                break;
            }

            // Idempotensi: retry browser tidak boleh membuat pembalik/pengganti ganda.
            $payloadHash = hash('sha256', json_encode([
                'bookingId' => $bookingId,
                'transactionId' => $transactionId,
                'reason' => $reason,
                'activity' => $activityPatch,
                'booking' => $bookingPatch,
                'transaction' => $transactionPatch
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $stmtExistingOp = $pdo->prepare('SELECT status,payload_hash,result_json FROM sync_operations WHERE operation_id = ? LIMIT 1 FOR UPDATE');
            $stmtExistingOp->execute([$operationId]);
            $existingOp = $stmtExistingOp->fetch();
            if ($existingOp) {
                if (!empty($existingOp['payload_hash']) && !hash_equals((string)$existingOp['payload_hash'], $payloadHash)) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => 'operationId koreksi telah dipakai untuk payload berbeda.']);
                    break;
                }
                if (($existingOp['status'] ?? '') === 'processed') {
                    tamasyaFinancialCommit($pdo);
                    echo json_encode([
                        'success' => true,
                        'duplicate' => true,
                        'result' => json_decode((string)($existingOp['result_json'] ?? '{}'), true),
                        'db' => getRoleScopedHotelData($pdo, $loggedInStaff)
                    ]);
                    break;
                }
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Koreksi yang sama masih diproses. Coba muat ulang beberapa detik lagi.']);
                break;
            }
            $stmtClaim = $pdo->prepare("INSERT INTO sync_operations
                (operation_id,staff_id,device_id,entity_type,entity_id,action,payload_hash,status,result_json,created_at,processed_at)
                VALUES (?,?,?,?,?,'audit_correct',?,'processing',NULL,CURRENT_TIMESTAMP,NULL)");
            $stmtClaim->execute([
                $operationId,
                (string)($loggedInStaff['id'] ?? ''),
                currentDeviceId(),
                'booking',
                $bookingId,
                $payloadHash
            ]);

            $newGuestName = trim((string)($bookingPatch['guestName'] ?? $oldBooking['guestName']));
            $newGuestEmail = trim((string)($bookingPatch['guestEmail'] ?? $oldBooking['guestEmail'] ?? ''));
            $newGuestPhone = trim((string)($bookingPatch['guestPhone'] ?? $oldBooking['guestPhone'] ?? ''));
            $newRoomNumber = trim((string)($bookingPatch['roomNumber'] ?? $oldBooking['roomNumber']));
            $newCheckIn = (string)($bookingPatch['checkIn'] ?? $oldBooking['checkIn']);
            $newCheckOut = (string)($bookingPatch['checkOut'] ?? $oldBooking['checkOut']);
            $newIsOpenEnded = (int)($bookingPatch['isOpenEnded'] ?? $oldBooking['isOpenEnded'] ?? 0) === 1;
            $newStayMode=strtolower(trim((string)($bookingPatch['stayMode']??$oldBooking['stayMode']??($newIsOpenEnded?'open_ended':'overnight'))));
            if($newIsOpenEnded)$newStayMode='open_ended';
            $newScheduledCheckInAt=array_key_exists('scheduledCheckInAt',$bookingPatch)?($bookingPatch['scheduledCheckInAt']?:null):($oldBooking['scheduledCheckInAt']??null);
            $newScheduledCheckOutAt=array_key_exists('scheduledCheckOutAt',$bookingPatch)?($bookingPatch['scheduledCheckOutAt']?:null):($oldBooking['scheduledCheckOutAt']??null);
            $newTotal = round((float)($bookingPatch['totalAmount'] ?? $oldBooking['totalAmount'] ?? 0), 2);
            $newPaymentMethod = strtolower(trim((string)($bookingPatch['paymentMethod'] ?? $oldBooking['paymentMethod'] ?? 'cash')));
            $newBankAccountId = array_key_exists('bankAccountId', $bookingPatch)
                ? (trim((string)$bookingPatch['bankAccountId']) ?: null)
                : ($oldBooking['bankAccountId'] ?? null);
            $newBookingSource = trim((string)($bookingPatch['bookingSource'] ?? $oldBooking['bookingSource'] ?? 'Direct')) ?: 'Direct';
            // Audit correction owns the booking settlement snapshot too.  A
            // switch from split -> cash/bank must clear old split legs, while a
            // split correction must carry both legs explicitly so stale OTA/bank
            // metadata can never survive behind a new payment-method label.
            $newBookingIsSplit = $newPaymentMethod === 'split';
            $newBookingSplitCash = round((float)($bookingPatch['splitCashAmount'] ?? $oldBooking['splitCashAmount'] ?? 0), 2);
            $newBookingSplitTransfer = round((float)($bookingPatch['splitTransferAmount'] ?? $oldBooking['splitTransferAmount'] ?? 0), 2);
            $newBookingSplitBank = array_key_exists('splitTransferBankAccountId', $bookingPatch)
                ? (trim((string)$bookingPatch['splitTransferBankAccountId']) ?: null)
                : (trim((string)($oldBooking['splitTransferBankAccountId'] ?? '')) ?: null);
            // Nilai pajak koreksi tidak pernah diambil dari form. Server akan
            // menghitungnya dari tax_rules setelah sumber/tanggal/nominal final.
            $newVatRate = (float)($oldBooking['vatRate'] ?? 0);
            $newVatAmount = (float)($oldBooking['vatAmount'] ?? 0);

            $periodValid=true;
            if(!$newIsOpenEnded){
                if($newStayMode==='short_time'){
                    $periodValid=$newCheckOut===$newCheckIn && $newScheduledCheckInAt && $newScheduledCheckOutAt
                        && strtotime((string)$newScheduledCheckOutAt)>strtotime((string)$newScheduledCheckInAt);
                }else{
                    $periodValid=strtotime($newCheckOut)>strtotime($newCheckIn);
                }
            }
            if ($newGuestName === '' || $newRoomNumber === '' || !validIsoDate($newCheckIn) || !validIsoDate($newCheckOut) || !$periodValid || $newTotal < 0) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Data koreksi reservasi tidak valid. Short time wajib tanggal sama dengan jam selesai setelah jam mulai.']);
                break;
            }
            if (!in_array($newPaymentMethod, ['cash', 'transfer', 'qris', 'split', 'ota'], true)) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Metode pembayaran reservasi tidak valid.']);
                break;
            }
            if ($newBookingIsSplit) {
                if ($newBookingSplitCash <= 0 || $newBookingSplitTransfer <= 0 || $newBookingSplitBank === null) {
                    $pdo->rollBack();
                    http_response_code(422);
                    echo json_encode(['success'=>false,'error'=>'Koreksi pembayaran Split wajib mengisi nominal Tunai, nominal Transfer/QRIS, dan akun transfer.']);
                    break;
                }
                try {
                    tamasyaInferPaymentMethodFromAccount($pdo,$newBookingSplitBank,true,['transfer','qris']);
                    $newBookingSplitBank=tamasyaValidateFinanceBankAccount($pdo,$newBookingSplitBank,true,false);
                } catch (Throwable $splitBookingError) {
                    $pdo->rollBack();http_response_code(tamasyaExceptionHttpStatus($splitBookingError,422));
                    echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Akun split reservasi tidak valid',$splitBookingError)]);break;
                }
                $newBankAccountId=null;
            } else {
                $newBookingSplitCash=0.0;$newBookingSplitTransfer=0.0;$newBookingSplitBank=null;
                try {
                    $newBankAccountId=tamasyaResolvePaymentAccount($pdo,$newPaymentMethod,$newBankAccountId,[
                        'allowedMethods'=>['cash','transfer','qris','ota'],'lock'=>true,'context'=>'Koreksi audit reservasi'
                    ]);
                } catch (Throwable $bookingAccountError) {
                    $pdo->rollBack();http_response_code(tamasyaExceptionHttpStatus($bookingAccountError,422));
                    echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Akun pembayaran reservasi tidak valid',$bookingAccountError)]);break;
                }
                if($newPaymentMethod==='ota' && !tamasyaIsExplicitOtaSourceLabel($newBookingSource)){
                    $pdo->rollBack();http_response_code(422);
                    echo tamasyaJsonEncode(['success'=>false,'error'=>'Metode OTA hanya boleh dipakai bila sumber booking adalah OTA yang eksplisit.']);break;
                }
            }

            $stmtRoom = $pdo->prepare('SELECT * FROM rooms WHERE number = ? LIMIT 1 FOR UPDATE');
            $stmtRoom->execute([$newRoomNumber]);
            $newRoom = $stmtRoom->fetch();
            if (!$newRoom) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nomor kamar koreksi tidak ditemukan pada master kamar.']);
                break;
            }

            $oldLifecycleStatus = strtolower((string)($oldBooking['status'] ?? ''));
            $roomChanged = $newRoomNumber !== (string)$oldBooking['roomNumber'];
            if ($roomChanged && $oldLifecycleStatus === 'active') {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Tamu aktif tidak boleh dipindahkan lewat Koreksi Audit. Gunakan workflow Pindah Kamar agar kunci, smart-lock, housekeeping, status kamar, ledger, dan audit diproses atomik.']);
                break;
            }
            $checkinTimeAudit = '14:00:00';
            $checkoutTimeAudit = '12:00:00';
            try {
                $auditClockStmt=$pdo->query("SELECT checkin_time,checkout_time FROM hotel_operational_settings WHERE id='system_default' LIMIT 1");
                $auditClocks=$auditClockStmt?$auditClockStmt->fetch(PDO::FETCH_ASSOC):false;
                if($auditClocks){
                    $checkinTimeAudit=substr((string)($auditClocks['checkin_time']??'14:00:00'),0,8);
                    $checkoutTimeAudit=substr((string)($auditClocks['checkout_time']??'12:00:00'),0,8);
                }
            } catch (Throwable $ignoredOperationalClockSetting) {}
            try {
                $auditStayWindow=resolveHotelBookingStayWindow(array_merge($oldBooking,[
                    'checkIn'=>$newCheckIn,'checkOut'=>$newCheckOut,'isOpenEnded'=>$newIsOpenEnded?1:0,'stayMode'=>$newStayMode,
                    'scheduledCheckInAt'=>$newScheduledCheckInAt,'scheduledCheckOutAt'=>$newScheduledCheckOutAt
                ]),$checkoutTimeAudit,$checkinTimeAudit);
            } catch (InvalidArgumentException $auditStayError) {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode(['success'=>false,'error'=>$auditStayError->getMessage()]);
                break;
            }
            $newScheduledCheckInAt=$auditStayWindow['scheduledCheckInAt'];
            $newScheduledCheckOutAt=$auditStayWindow['scheduledCheckOutAt'];
            if (in_array($oldLifecycleStatus, ['reserved','active'], true)) {
                if ($oldLifecycleStatus === 'reserved') {
                    $reservationBlockers=tamasyaReservationInventoryBlockers(getRoomOperationalBlockers($pdo,$newRoomNumber,true));
                    if($reservationBlockers){
                        $pdo->rollBack();
                        http_response_code(409);
                        echo json_encode(['success' => false, 'error' => 'Kamar tujuan belum dapat menerima reservasi baru. '.roomOperationalBlockerMessage($newRoomNumber,$reservationBlockers)]);
                        break;
                    }
                }
                tamasyaR3AssertStayWindowNoOverlap($pdo,(string)$bookingId,$newRoomNumber,(string)$auditStayWindow['startAt'],(string)$auditStayWindow['endAt'],true);
            }

            if ($newPaymentMethod === 'ota' && !$newBankAccountId) {
                // Explicit OTA source was validated above; this only canonicalizes the pseudo-account.
                $newBankAccountId = 'ota_receivable';
            }
            // Aktivitas/Fitur Khusus adalah klasifikasi koreksi, bukan operasi baru.
            // Untuk extra, daftar extras booking yang sama di-upsert berdasarkan transactionId
            // agar koreksi tidak membuat booking atau biaya ganda.
            $newExtrasJson = $oldBooking['extras'] ?? null;
            if ($activityPurpose === 'extra') {
                $extraPatch = is_array($activityPatch['extra'] ?? null) ? $activityPatch['extra'] : [];
                $extraName = trim((string)($extraPatch['name'] ?? ''));
                $extraQty = max(1, (int)($extraPatch['qty'] ?? 1));
                $extraUnitPrice = max(0, (float)($extraPatch['unitPrice'] ?? 0));
                $extraRoot=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income');
                $extraCategory=(string)$extraRoot['name'];
                $extraSubcategory=trim((string)($extraPatch['subcategory'] ?? $extraPatch['name'] ?? '')) ?: null;
                if ($extraName === '' || $extraUnitPrice < 0) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Data layanan extra pada koreksi audit tidak valid.']);
                    break;
                }
                $extraBaseAmount=round($extraUnitPrice*$extraQty,2);
                $extraTaxRate=resolveConfiguredTaxRate($pdo,$newBookingSource,'extra',0,$newCheckIn);
                $extraTaxAmount=round($extraBaseAmount*($extraTaxRate/100),2);
                $extraGrossAmount=round($extraBaseAmount+$extraTaxAmount,2);
                $extras = !empty($oldBooking['extras']) ? json_decode((string)$oldBooking['extras'], true) : [];
                if (!is_array($extras)) $extras = [];
                $matchedIndex = null;
                foreach ($extras as $idx => $existingExtra) {
                    if (!is_array($existingExtra)) continue;
                    if ($transactionId !== '' && (string)($existingExtra['sourceTransactionId'] ?? '') === $transactionId) {
                        $matchedIndex = $idx;
                        break;
                    }
                    $existingTotal = isset($existingExtra['total'])&&is_numeric($existingExtra['total'])
                        ? (float)$existingExtra['total']
                        : (float)($existingExtra['price'] ?? 0) * max(1, (int)($existingExtra['qty'] ?? 1));
                    if (
                        $matchedIndex === null && $oldTx &&
                        strcasecmp(trim((string)($existingExtra['subcategory'] ?? '')), trim((string)($oldTx['subcategory'] ?? ''))) === 0 &&
                        abs($existingTotal - (float)($oldTx['amount'] ?? 0)) <= 0.5
                    ) {
                        $matchedIndex = $idx;
                    }
                }
                $extraRecord = [
                    'id' => $matchedIndex !== null ? (string)($extras[$matchedIndex]['id'] ?? generateServerId('ex_audit')) : generateServerId('ex_audit'),
                    'name' => $extraName,
                    'price' => $extraUnitPrice,
                    'qty' => $extraQty,
                    'total' => $extraGrossAmount,
                    'baseAmount' => $extraBaseAmount,
                    'taxAmount' => $extraTaxAmount,
                    'taxRate' => $extraTaxRate,
                    'category' => $extraCategory,
                    'subcategory' => $extraSubcategory,
                    'paymentStatus' => $transactionId !== '' ? 'paid' : ($matchedIndex !== null ? (string)($extras[$matchedIndex]['paymentStatus'] ?? 'unpaid') : 'unpaid'),
                    'sourceTransactionId' => $transactionId ?: null,
                    'paidAt' => $transactionId !== '' ? ($matchedIndex !== null ? ($extras[$matchedIndex]['paidAt'] ?? date(DATE_ATOM)) : date(DATE_ATOM)) : null,
                    'createdAt' => $matchedIndex !== null ? ($extras[$matchedIndex]['createdAt'] ?? date(DATE_ATOM)) : date(DATE_ATOM),
                    'updatedAt' => date(DATE_ATOM)
                ];
                if ($matchedIndex !== null) $extras[$matchedIndex] = $extraRecord;
                else $extras[] = $extraRecord;
                $newExtrasJson = json_encode(array_values($extras), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $correctedTax=resolveBookingAggregateTaxSnapshot($pdo,array_merge($oldBooking,[
                'bookingSource'=>$newBookingSource,'checkIn'=>$newCheckIn,'extras'=>$newExtrasJson
            ]),(float)$newTotal,$newCheckIn);
            $newVatRate=$correctedTax['taxRate'];
            $newVatAmount=(float)$correctedTax['taxAmount'];
            $newExtrasJson=json_encode($correctedTax['extras'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

            $newCheckoutDueAt = $auditStayWindow['checkoutDueAt'];
            if($oldLifecycleStatus==='active' && !$roomChanged) {
                $oldDue=trim((string)($oldBooking['checkoutDueAt']??''));
                $newDue=trim((string)($newCheckoutDueAt??''));
                $accessMode=strtolower(trim((string)($oldBooking['accessMode']??'')));
                if($newDue==='' && in_array($accessMode,['smart','hybrid'],true) && strtolower((string)($oldBooking['keyControlStatus']??''))==='issued') {
                    throw new RuntimeException('Koreksi audit tidak boleh membuat booking aktif smart-lock menjadi open-ended tanpa masa berlaku akses yang pasti.');
                }
                if($newDue!=='' && ($oldDue==='' || strtotime($oldDue)!==strtotime($newDue))) {
                    $auditSmartLockRefreshJobId=tamasyaR3QueueSmartLockValidityRefresh($pdo,$loggedInStaff,$oldBooking,$newDue,'audit-correction');
                }
            }
            $stmtUpdateBooking = $pdo->prepare("UPDATE bookings SET
                guestName=?, guestEmail=?, guestPhone=?, roomNumber=?, roomType=?, checkIn=?, checkOut=?, stayMode=?, scheduledCheckInAt=?, scheduledCheckOutAt=?, isOpenEnded=?, checkoutDueAt=?, totalAmount=?, extras=?,
                paymentMethod=?, bankAccountId=?, isSplitPayment=?, splitCashAmount=?, splitTransferAmount=?, splitTransferBankAccountId=?, bookingSource=?, vatRate=?, vatAmount=?,
                version=version+1, updatedAt=CURRENT_TIMESTAMP, updatedBy=?, updatedSource='audit-correction'
                WHERE id=?");
            $stmtUpdateBooking->execute([
                $newGuestName, $newGuestEmail, $newGuestPhone, $newRoomNumber, (string)$newRoom['type'],
                $newCheckIn, $newCheckOut, $newStayMode, $newScheduledCheckInAt, $newScheduledCheckOutAt, $newIsOpenEnded ? 1 : 0, $newCheckoutDueAt, $newTotal, $newExtrasJson, $newPaymentMethod, $newBankAccountId,
                $newBookingIsSplit?1:0,$newBookingSplitCash,$newBookingSplitTransfer,$newBookingSplitBank,
                $newBookingSource, $newVatRate, $newVatAmount,
                $loggedInStaff['id'] ?? null, $bookingId
            ]);

            // Metadata transaksi sumber mengikuti kamar/sumber reservasi. Transaksi
            // shift terkunci tidak pernah ditimpa.
            tamasyaUpdateBookingLinkedTransactionMetadata(
                $pdo,$bookingId,$newRoomNumber,$newBookingSource,$loggedInStaff,$transactionId!==''?$transactionId:null
            );

            $correctionMode = 'booking-only';
            $createdAdjustmentIds = [];
            if ($oldTx) {
                $targetType = strtolower(trim((string)($transactionPatch['type'] ?? $oldTx['type'] ?? 'income')));
                $targetAmount = round((float)($transactionPatch['amount'] ?? $oldTx['amount'] ?? 0), 2);
                $targetDate = (string)($transactionPatch['date'] ?? $oldTx['date']);
                $targetDescription = trim((string)($transactionPatch['description'] ?? $oldTx['description']));
                $targetCategory = trim((string)($transactionPatch['category'] ?? $oldTx['category']));
                $targetSubcategory = array_key_exists('subcategory', $transactionPatch)
                    ? (trim((string)$transactionPatch['subcategory']) ?: null)
                    : ($oldTx['subcategory'] ?? null);
                $targetBank = array_key_exists('bankAccountId', $transactionPatch)
                    ? (trim((string)$transactionPatch['bankAccountId']) ?: null)
                    : ($oldTx['bankAccountId'] ?? $newBankAccountId);
                $targetSplitExplicit=array_key_exists('isSplitPayment',$transactionPatch)
                    || array_key_exists('splitCashAmount',$transactionPatch)
                    || array_key_exists('splitTransferAmount',$transactionPatch)
                    || array_key_exists('splitTransferBankAccountId',$transactionPatch);
                $targetIsSplit=$targetSplitExplicit
                    ? (!empty($transactionPatch['isSplitPayment']) || ((float)($transactionPatch['splitCashAmount']??0)>0 && (float)($transactionPatch['splitTransferAmount']??0)>0))
                    : (!empty($oldTx['isSplitPayment']) || ((float)($oldTx['splitCashAmount']??0)>0 && (float)($oldTx['splitTransferAmount']??0)>0));
                $targetSplitCash=round((float)($transactionPatch['splitCashAmount'] ?? $oldTx['splitCashAmount'] ?? 0),2);
                $targetSplitTransfer=round((float)($transactionPatch['splitTransferAmount'] ?? $oldTx['splitTransferAmount'] ?? 0),2);
                $targetSplitBank=array_key_exists('splitTransferBankAccountId',$transactionPatch)
                    ? (trim((string)$transactionPatch['splitTransferBankAccountId']) ?: null)
                    : (trim((string)($oldTx['splitTransferBankAccountId']??'')) ?: null);
                $targetKind = normalizeTransactionKind((string)($oldTx['transactionKind'] ?? inferTransactionKind($oldTx)));
                $oldType = strtolower(trim((string)($oldTx['type'] ?? 'income')));
                // A type correction cannot keep an income-only kind such as
                // booking_charge/settlement on an expense row (or an expense-only
                // kind on an income row). The old kind was the source of the
                // mismatch that made replacement postings fail authority/tax
                // validation while direct updates persisted an inconsistent row.
                if ($targetType !== $oldType && $activityPurpose === '') {
                    $targetKind = $targetType === 'expense' ? 'manual' : 'booking_charge';
                }
                if ($activityPurpose === 'extension') {
                    $targetType = 'income';
                    $targetCategory = getRoomRentalCategoryName($pdo);
                    $targetSubcategory = (string)($newRoom['type'] ?? ($oldBooking['roomType'] ?? ''));
                    $targetKind = 'booking_charge';
                } elseif ($activityPurpose === 'extra') {
                    $extraPatch = is_array($activityPatch['extra'] ?? null) ? $activityPatch['extra'] : [];
                    $extraQty = max(1, (int)($extraPatch['qty'] ?? 1));
                    $extraUnitPrice = max(0, (float)($extraPatch['unitPrice'] ?? 0));
                    $targetExtraBase=round($extraQty*$extraUnitPrice,2);
                    $targetExtraRate=resolveConfiguredTaxRate($pdo,$newBookingSource,'extra',0,$targetDate);
                    $targetAmount=(int)round($targetExtraBase+round($targetExtraBase*($targetExtraRate/100),2));
                    $targetType = 'income';
                    $extraRoot=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income');
                    $targetCategory=(string)$extraRoot['name'];
                    $targetSubcategory=trim((string)($extraPatch['subcategory'] ?? $extraPatch['name'] ?? '')) ?: null;
                    $targetKind = 'booking_charge';
                } elseif ($activityPurpose === 'transfer') {
                    $targetType = 'income';
                    $targetCategory = getRoomRentalCategoryName($pdo);
                    $targetSubcategory = (string)($newRoom['type'] ?? ($oldBooking['roomType'] ?? ''));
                    $targetKind = 'booking_charge';
                }
                if($targetIsSplit){
                    if($targetType!=='income' || $targetSplitCash<=0 || $targetSplitTransfer<=0 || $targetSplitBank===null
                        || !moneyMatches($targetSplitCash+$targetSplitTransfer,$targetAmount,0.01)){
                        $pdo->rollBack();http_response_code(422);
                        echo tamasyaJsonEncode(['success'=>false,'error'=>'Koreksi transaksi Split wajib berupa pemasukan; Tunai + Transfer harus sama dengan nominal transaksi dan akun transfer wajib dipilih.']);break;
                    }
                    try{
                        tamasyaInferPaymentMethodFromAccount($pdo,$targetSplitBank,true,['transfer','qris']);
                        $targetSplitBank=tamasyaValidateFinanceBankAccount($pdo,$targetSplitBank,true,false);
                    }catch(Throwable $targetSplitError){$pdo->rollBack();http_response_code(tamasyaExceptionHttpStatus($targetSplitError,422));echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Akun split transaksi tidak valid',$targetSplitError)]);break;}
                    $targetBank=null;
                }else{
                    $targetSplitCash=0.0;$targetSplitTransfer=0.0;$targetSplitBank=null;
                    try{$targetBank=tamasyaValidateFinanceBankAccount($pdo,$targetBank,true,true);}
                    catch(Throwable $targetBankError){$pdo->rollBack();http_response_code(tamasyaExceptionHttpStatus($targetBankError,422));echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Akun koreksi transaksi tidak valid',$targetBankError)]);break;}
                    if(strtolower((string)$targetBank)==='ota_receivable' && !tamasyaIsExplicitOtaSourceLabel($newBookingSource)){
                        $pdo->rollBack();http_response_code(422);echo tamasyaJsonEncode(['success'=>false,'error'=>'Piutang OTA pada koreksi transaksi wajib memakai sumber OTA yang eksplisit.']);break;
                    }
                }
                if (!in_array($targetType, ['income', 'expense'], true) || $targetAmount <= 0 || !validIsoDate($targetDate) || $targetDescription === '' || $targetCategory === '') {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Data koreksi transaksi tidak valid.']);
                    break;
                }
                $targetCatalog=tamasyaResolveFinanceCatalogSelection($pdo,[
                    'type'=>$targetType,
                    'categoryId'=>$transactionPatch['categoryId'] ?? ($oldTx['categoryId'] ?? null),
                    'category'=>$targetCategory,
                    'subcategoryId'=>$transactionPatch['subcategoryId'] ?? ($oldTx['subcategoryId'] ?? null),
                    'subcategory'=>$targetSubcategory,
                ]);
                // tamasyaResolveFinanceCatalogSelection() returns 'category' and
                // 'subcategory' as the resolved master rows, not their labels.
                // Casting a row to string raised "Array to string conversion",
                // which surfaced as a 500 on every audit correction that targeted
                // a transaction. The label fields are categoryName/subcategoryName.
                $targetCategory=(string)($targetCatalog['categoryName'] ?? '');
                $targetSubcategory=$targetCatalog['subcategoryName'] ?? null;

                $historicalSource=tamasyaTransactionIsHistorical($oldTx);
                if($targetType==='expense'){
                    // Expense corrections are not taxable sales. Reusing the
                    // booking/room tax resolver here would copy an income PBJT
                    // snapshot onto an expense row.
                    $tax=[
                        'baseAmount'=>(float)$targetAmount,'taxAmount'=>0.0,'taxRate'=>0.0,
                        'taxSnapshotStatus'=>'not_applicable','taxSource'=>'not_applicable',
                        'taxRuleId'=>null,'taxRuleName'=>null
                    ];
                }elseif($historicalSource){
                    $taxPayload=array_merge($oldTx,array_intersect_key($transactionPatch,array_flip(['baseAmount','taxAmount','taxRate'])));
                    try{$tax=tamasyaNormalizeHistoricalTaxSnapshot($taxPayload,(float)$targetAmount,true);}
                    catch(Throwable $taxError){$pdo->rollBack();http_response_code(tamasyaExceptionHttpStatus($taxError,422));echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Normalisasi pajak transaksi historis gagal',$taxError)]);break;}
                }else{
                    $tax = resolveBookingChargeTaxPolicy(
                        array_merge($oldBooking, [
                            'bookingSource' => $newBookingSource,
                            'paymentMethod' => $newPaymentMethod,
                            'bankAccountId' => $newBankAccountId,
                            'vatRate' => $newVatRate,
                            'vatAmount' => $newVatAmount
                        ]),
                        [
                            'category' => $targetCategory,
                            'subcategory' => $targetSubcategory,
                            'description' => $targetDescription,
                            'bookingSource' => $newBookingSource,
                            'bankAccountId' => $targetBank,
                            'taxRate' => $oldTx['taxRate'] ?? 0,
                            'transactionKind' => $targetKind
                        ],
                        $targetAmount
                    );
                }

                if (!empty($oldTx['lockedAt']) || $historicalSource) {
                    // A correction reversal is idempotent only by operationId, which is
                    // derived from the current request. Re-running the same correction
                    // with a different reason or patch therefore produced a second full
                    // reversal of the same original amount. The reversal marks the
                    // original as disputed, so refuse to reverse it again.
                    if ((string)($oldTx['reconciliationStatus'] ?? '') === 'disputed') {
                        throw new DomainException('Transaksi ini sudah pernah dibalik oleh koreksi audit sebelumnya. Koreksi ulang harus targeting transaksi pengganti, bukan transaksi asli yang sudah dibalik.');
                    }
                    $correctionMode = 'reversal-and-replacement';
                    $reverseId = generateServerId('tx_audit_reverse');
                    $replacementId = generateServerId('tx_audit_replace');
                    $reverseType = ($oldTx['type'] ?? 'income') === 'income' ? 'expense' : 'income';
                    $oldKind = normalizeTransactionKind((string)($oldTx['transactionKind'] ?? inferTransactionKind($oldTx)));
                    // Pembalik booking_charge tetap memakai jenis historis yang sama agar
                    // jejak audit dan proyeksi refund tidak kehilangan relasi ke reservasi.
                    // Nilai extraCharge sendiri tetap berasal dari bookings.extras.
                    $reverseKind = $oldKind === 'booking_charge'
                        ? 'booking_charge'
                        : ($reverseType === 'expense' ? 'refund' : 'manual');
                    $reverseOperation = substr($operationId . ':reverse', 0, 100);
                    $replacementOperation = substr($operationId . ':replacement', 0, 100);
                    $reverseDescription = 'Pembalik audit [' . $reason . '] — ' . (string)$oldTx['description'];
                    $replacementDescription = $targetDescription . ' [Koreksi audit: ' . $reason . ']';

                    $reverseTx=[
                        'id'=>$reverseId,'type'=>$reverseType,'category'=>$oldTx['category'],'subcategory'=>$oldTx['subcategory']??null,
                        'categoryId'=>$oldTx['categoryId']??null,'categorySystemKey'=>$oldTx['categorySystemKey']??null,
                        'subcategoryId'=>$oldTx['subcategoryId']??null,'subcategorySystemKey'=>$oldTx['subcategorySystemKey']??null,
                        'roomNumber'=>$oldTx['roomNumber']??$newRoomNumber,'amount'=>round((float)$oldTx['amount'],2),'date'=>$targetDate,
                        'description'=>$reverseDescription,'createdBy'=>currentStaffLabel($loggedInStaff),'bankAccountId'=>$oldTx['bankAccountId']??null,
                        'bookingId'=>$bookingId,'bookingSource'=>$oldTx['bookingSource']??$newBookingSource,
                        'baseAmount'=>$oldTx['baseAmount']??null,'taxAmount'=>$oldTx['taxAmount']??null,'taxRate'=>$oldTx['taxRate']??null,
                        'taxRuleId'=>$oldTx['taxRuleId']??null,'taxRuleName'=>$oldTx['taxRuleName']??null,
                        'taxSource'=>$oldTx['taxSource']??'live_rule','taxSnapshotStatus'=>$oldTx['taxSnapshotStatus']??'confirmed',
                        'transactionKind'=>$reverseKind,'sourceEntity'=>'booking','sourceEntityId'=>$bookingId,'isSystemGenerated'=>1,
                        'operationId'=>$reverseOperation,'recordOrigin'=>$historicalSource?'historical_import':'live_operation','shiftExempt'=>1,
                        'shiftExemptionReason'=>'Jurnal pembalik koreksi audit tanpa pergerakan kas','importBatchId'=>$historicalSource?($oldTx['importBatchId']??null):null,
                        'updatedBy'=>$loggedInStaff['id']??null,'updatedSource'=>'audit-correction',
                        'isSplitPayment'=>!empty($oldTx['isSplitPayment'])?1:0,
                        'splitCashAmount'=>round((float)($oldTx['splitCashAmount']??0),2),
                        'splitTransferAmount'=>round((float)($oldTx['splitTransferAmount']??0),2),
                        'splitTransferBankAccountId'=>$oldTx['splitTransferBankAccountId']??null,
                    ];
                    $reverseAuthority=tamasyaFinancialPostingOwnerForKind($reverseKind);
                    if($reverseAuthority===null)throw new DomainException('Jenis transaksi pembalik audit tidak mempunyai posting authority.');
                    $reverseId=tamasyaPostFinancialTransaction($pdo,$reverseTx,$loggedInStaff,$reverseAuthority,[
                        'source'=>'audit-correction','allowSemanticTypeMismatchForReversal'=>true,'allowSplitExpenseReversal'=>true,'idempotentByOperationId'=>true
                    ]);
                    $replacementTx=[
                        'id'=>$replacementId,'type'=>$targetType,'category'=>$targetCategory,'subcategory'=>$targetSubcategory,
                        'categoryId'=>$targetCatalog['categoryId'],'categorySystemKey'=>$targetCatalog['categorySystemKey'],
                        'subcategoryId'=>$targetCatalog['subcategoryId'],'subcategorySystemKey'=>$targetCatalog['subcategorySystemKey'],
                        'roomNumber'=>$newRoomNumber,'amount'=>$targetAmount,'date'=>$targetDate,'description'=>$replacementDescription,
                        'createdBy'=>currentStaffLabel($loggedInStaff),'bankAccountId'=>$targetBank,'bookingId'=>$bookingId,'bookingSource'=>$newBookingSource,
                        'baseAmount'=>$tax['baseAmount'],'taxAmount'=>$tax['taxAmount'],'taxRate'=>$tax['taxRate'],'transactionKind'=>$targetKind,
                        // The replacement is a live booking receipt and must carry the same
                        // tax snapshot contract as the original, otherwise it fails
                        // tamasyaAssertLiveTaxSnapshotIntegrity() and becomes a poison row
                        // that blocks every later posting.
                        'taxRuleId'=>$tax['taxRuleId']??null,'taxRuleName'=>$tax['taxRuleName']??null,
                        'taxSource'=>$tax['taxSource']??'live_rule','taxSnapshotStatus'=>$tax['taxSnapshotStatus']??'confirmed',
                        'sourceEntity'=>'booking','sourceEntityId'=>$bookingId,'isSystemGenerated'=>1,'operationId'=>$replacementOperation,
                        'recordOrigin'=>$historicalSource?'historical_import':'live_operation','shiftExempt'=>1,
                        'shiftExemptionReason'=>'Jurnal pengganti koreksi audit tanpa pergerakan kas','importBatchId'=>$historicalSource?($oldTx['importBatchId']??null):null,
                        'updatedBy'=>$loggedInStaff['id']??null,'updatedSource'=>'audit-correction',
                        'isSplitPayment'=>$targetIsSplit?1:0,'splitCashAmount'=>$targetSplitCash,'splitTransferAmount'=>$targetSplitTransfer,
                        'splitTransferBankAccountId'=>$targetSplitBank,
                    ];
                    $replacementAuthority=tamasyaFinancialPostingOwnerForKind($targetKind);
                    if($replacementAuthority===null)throw new DomainException('Jenis transaksi pengganti audit tidak mempunyai posting authority.');
                    $replacementId=tamasyaPostFinancialTransaction($pdo,$replacementTx,$loggedInStaff,$replacementAuthority,[
                        'source'=>'audit-correction','idempotentByOperationId'=>true
                    ]);
                    $createdAdjustmentIds = [$reverseId, $replacementId];
                    tamasyaMutateFinancialTransaction($pdo,$transactionId,[
                        'reconciliationStatus'=>'disputed','reconciliationReference'=>$operationId,
                    ],$loggedInStaff,'audit_correction','audit_correction',['source'=>'audit-correction','lockRows'=>true,'requireAll'=>true]);
                } else {
                    $correctionMode = 'direct-source-update';
                    $projectedCorrectionTx=array_merge($oldTx,[
                        'type'=>$targetType,'category'=>$targetCategory,'subcategory'=>$targetSubcategory,
                        'categoryId'=>$targetCatalog['categoryId'],'categorySystemKey'=>$targetCatalog['categorySystemKey'],
                        'subcategoryId'=>$targetCatalog['subcategoryId'],'subcategorySystemKey'=>$targetCatalog['subcategorySystemKey'],
                        'roomNumber'=>$newRoomNumber,'amount'=>$targetAmount,'date'=>$targetDate,'description'=>$targetDescription,
                        'bankAccountId'=>$targetBank,'bookingSource'=>$newBookingSource,'baseAmount'=>$tax['baseAmount'],
                        'taxAmount'=>$tax['taxAmount'],'taxRate'=>$tax['taxRate'],'transactionKind'=>$targetKind,
                        'taxSnapshotStatus'=>$tax['taxSnapshotStatus']??($oldTx['taxSnapshotStatus']??'confirmed'),
                        'taxSource'=>$tax['taxSource']??($oldTx['taxSource']??'live_rule'),
                        'taxRuleId'=>$tax['taxRuleId']??($oldTx['taxRuleId']??null),
                        'taxRuleName'=>$tax['taxRuleName']??($oldTx['taxRuleName']??null),
                        'isSplitPayment'=>$targetIsSplit?1:0,'splitCashAmount'=>$targetSplitCash,'splitTransferAmount'=>$targetSplitTransfer,
                        'splitTransferBankAccountId'=>$targetSplitBank,
                    ]);
                    if(empty($historicalSource)){
                        if(trim((string)($projectedCorrectionTx['shiftSessionId']??''))===''){
                            $oldCashIncome=strtolower(trim((string)($oldTx['type']??'')))==='income' && tamasyaCanonicalCashLegAmount($oldTx)>0.009;
                            $projectedCorrectionTx['shiftSessionId']=resolveOpenShiftSessionId($pdo,$loggedInStaff,$oldCashIncome);
                        }
                        if($targetType==='expense') tamasyaAssertOpenShiftCashCanCoverExpenseExcluding($pdo,$projectedCorrectionTx,$transactionId);
                        if(strtolower(trim((string)($oldTx['type']??'')))==='income' || $targetType==='income') tamasyaAssertOpenShiftCashProjection($pdo,$projectedCorrectionTx,$transactionId);
                    }
                    tamasyaMutateFinancialTransaction($pdo,$transactionId,[
                        'type'=>$targetType,'category'=>$targetCategory,'subcategory'=>$targetSubcategory,
                        'categoryId'=>$targetCatalog['categoryId'],'categorySystemKey'=>$targetCatalog['categorySystemKey'],
                        'subcategoryId'=>$targetCatalog['subcategoryId'],'subcategorySystemKey'=>$targetCatalog['subcategorySystemKey'],
                        'roomNumber'=>$newRoomNumber,'amount'=>$targetAmount,'date'=>$targetDate,'description'=>$targetDescription,
                        'bankAccountId'=>$targetBank,'bookingSource'=>$newBookingSource,'baseAmount'=>$tax['baseAmount'],
                        'taxAmount'=>$tax['taxAmount'],'taxRate'=>$tax['taxRate'],'transactionKind'=>$targetKind,
                        'isSplitPayment'=>$targetIsSplit?1:0,'splitCashAmount'=>$targetSplitCash,'splitTransferAmount'=>$targetSplitTransfer,
                        'splitTransferBankAccountId'=>$targetSplitBank,
                    ],$loggedInStaff,'audit_correction','audit_correction',['source'=>'audit-correction','lockRows'=>true,'requireAll'=>true]);
                }
            }

            // Strict mode is mandatory. recalculateBookingFinancials() swallows any failure when
            // $strict is false: it logs and returns normally, so this route could commit a
            // corrected booking whose derived financial state was never rebuilt. This was
            // the only one of 25 call sites in the codebase that omitted the flag.
            recalculateBookingFinancials($pdo, $bookingId, true);
            $stmtCorrectedBooking = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
            $stmtCorrectedBooking->execute([$bookingId]);
            $correctedBooking = $stmtCorrectedBooking->fetch() ?: [];
            $derivedPaymentStatus = (float)($correctedBooking['balanceDue'] ?? 0) <= 0.5 ? 'paid' : 'unpaid';
            $pdo->prepare('UPDATE bookings SET paymentStatus=? WHERE id=?')->execute([$derivedPaymentStatus, $bookingId]);
            $correctedBooking['paymentStatus'] = $derivedPaymentStatus;

            markApprovalUsed($pdo, $approvalId, $loggedInStaff);
            $notifId = generateServerId('n_audit_booking');
            $notifMessage = 'Koreksi audit reservasi Kamar ' . $newRoomNumber . ' (' . $newGuestName . ') oleh ' . currentStaffLabel($loggedInStaff) . '. Alasan: ' . $reason;
            $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'finance')")
                ->execute([$notifId, $notifMessage]);

            $auditResult = [
                'operationId' => $operationId,
                'bookingId' => $bookingId,
                'transactionId' => $transactionId ?: null,
                'mode' => $correctionMode,
                'adjustmentTransactionIds' => $createdAdjustmentIds,
                'activityPurpose' => $activityPurpose,
                'reason' => $reason,
                'smartLockRefreshJobId' => $auditSmartLockRefreshJobId
            ];
            $correctedTransaction=null;
            if($transactionId!==''){$correctedTransactionStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1");$correctedTransactionStmt->execute([$transactionId]);$correctedTransaction=$correctedTransactionStmt->fetch(PDO::FETCH_ASSOC)?:null;}
            writeRequiredEnterpriseAudit(
                $pdo,
                $loggedInStaff,
                'CORRECT BOOKING AUDIT',
                'booking',
                $bookingId,
                ['booking'=>tamasyaBookingAuditSnapshot($oldBooking),'transaction'=>tamasyaTransactionAuditSnapshot($oldTx)],
                ['booking'=>tamasyaBookingAuditSnapshot($correctedBooking),'transaction'=>tamasyaTransactionAuditSnapshot($correctedTransaction),'result'=>$auditResult],
                'web'
            );
            bumpServerRevision($pdo);
            $stmtFinishOp = $pdo->prepare("UPDATE sync_operations SET status='processed', result_json=?, processed_at=CURRENT_TIMESTAMP WHERE operation_id=?");
            $stmtFinishOp->execute([json_encode($auditResult, JSON_UNESCAPED_UNICODE), $operationId]);
            tamasyaFinancialCommit($pdo);

            if($auditSmartLockRefreshJobId) {
                try {
                    $auditSmartLockRefreshResult=processSmartLockBridgeJobById($pdo,$auditSmartLockRefreshJobId,$loggedInStaff,'audit-stay-window-refresh');
                } catch (Throwable $smartLockError) {
                    error_log('[Audit Smart Lock Refresh] '.$smartLockError->getMessage());
                    $auditSmartLockRefreshResult=['jobId'=>$auditSmartLockRefreshJobId,'status'=>'error','message'=>'Refresh smart-lock pasca koreksi audit perlu ditinjau dari log pekerjaan.'];
                }
            }

            broadcastTelegramNotification(
                $pdo,
                "🧾 *KOREKSI AUDIT RESERVASI*\n\n🏨 Kamar: *{$newRoomNumber}*\n👤 Tamu: *{$newGuestName}*\n📝 Alasan: {$reason}\n🔧 Mode: {$correctionMode}\n👮 Oleh: " . currentStaffLabel($loggedInStaff),
                true
            );
            echo json_encode([
                'success' => true,
                'message' => $correctionMode === 'reversal-and-replacement'
                    ? 'Koreksi tersimpan. Transaksi shift terkunci dibalik dan dibuat pengganti agar audit tetap utuh.'
                    : 'Koreksi reservasi dan arus kas berhasil disinkronkan.',
                'result' => $auditResult,
                'smartLockRefresh' => $auditSmartLockRefreshResult,
                'db' => getRoleScopedHotelData($pdo, $loggedInStaff)
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaRuntimeCaptureThrowable($e, 'booking-audit-correction');
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(['success' => false, 'error' => clientExceptionMessage('Gagal menerapkan koreksi audit reservasi', $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // GET/POST/PUT/DELETE /api/transactions ATAU api.php?action=transactions
    // Manajemen Transaksi Keuangan (Daftar, Tambah, Edit, Hapus)
    // ----------------------------------------------------------------
}
