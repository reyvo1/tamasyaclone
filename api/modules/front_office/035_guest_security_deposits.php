<?php
/** TAMASYA V137 guest security deposit ledger. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaSecurityDepositKinds(): array {
    return ['security_deposit_received','security_deposit_refund','security_deposit_forfeit'];
}

function tamasyaSecurityDepositStatus(float $required, float $received, float $refunded, float $forfeited, string $current=''): string {
    $required=max(0.0,round($required,2));
    $received=max(0.0,round($received,2));
    $refunded=max(0.0,round($refunded,2));
    $forfeited=max(0.0,round($forfeited,2));
    $held=max(0.0,round($received-$refunded-$forfeited,2));
    if($required<=0.0&&$received<=0.0)return 'not_required';
    if($received<=0.0)return 'pending';
    if($held<=0.01){
        if($forfeited>0.01&&$refunded>0.01)return 'partially_forfeited';
        if($forfeited>0.01)return 'forfeited';
        return 'refunded';
    }
    if($current==='pending_inspection')return 'pending_inspection';
    if($refunded>0.01||$forfeited>0.01)return 'partially_refunded';
    if($required>0.0&&$received+0.01<$required)return 'partial';
    return 'held';
}

function tamasyaSecurityDepositPaymentAccount(PDO $pdo,string $method,string $bankAccountId=''): ?string {
    return tamasyaResolvePaymentAccount($pdo,$method,$bankAccountId,[
        'allowedMethods'=>['cash','transfer','qris'],
        'lock'=>true,
        'context'=>'Deposito jaminan'
    ]);
}

function tamasyaSecurityDepositSummary(PDO $pdo,string $bookingId,bool $forUpdate=false): array {
    $sql="SELECT * FROM guest_security_deposits WHERE booking_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$bookingId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)return [
        'id'=>null,'bookingId'=>$bookingId,'requiredAmount'=>0.0,'receivedAmount'=>0.0,
        'refundedAmount'=>0.0,'forfeitedAmount'=>0.0,'heldBalance'=>0.0,'status'=>'not_required','version'=>0
    ];
    return [
        'id'=>(string)$row['id'],'bookingId'=>(string)$row['booking_id'],
        'requiredAmount'=>(float)$row['required_amount'],'receivedAmount'=>(float)$row['received_amount'],
        'refundedAmount'=>(float)$row['refunded_amount'],'forfeitedAmount'=>(float)$row['forfeited_amount'],
        'heldBalance'=>(float)$row['held_balance'],'status'=>(string)$row['status'],'version'=>(int)$row['version'],
        'createdAt'=>$row['created_at']??null,'updatedAt'=>$row['updated_at']??null
    ];
}

function tamasyaSyncSecurityDepositProjection(PDO $pdo,string $bookingId,array $summary,array $actor,string $source): void {
    $required=max(0.0,round((float)($summary['requiredAmount']??0),2));
    $received=max(0.0,round((float)($summary['receivedAmount']??0),2));
    $refunded=max(0.0,round((float)($summary['refundedAmount']??0),2));
    $forfeited=max(0.0,round((float)($summary['forfeitedAmount']??0),2));
    $held=max(0.0,round((float)($summary['heldBalance']??($received-$refunded-$forfeited)),2));
    $status=(string)($summary['status']??tamasyaSecurityDepositStatus($required,$received,$refunded,$forfeited));
    $pdo->prepare("UPDATE bookings SET securityDepositRequired=?,securityDepositRequiredAmount=?,securityDepositReceived=?,securityDepositRefunded=?,securityDepositForfeited=?,securityDepositHeld=?,securityDepositStatus=?,version=version+1,updatedBy=?,updatedSource=? WHERE id=?")
        ->execute([$required>0?1:0,$required,$received,$refunded,$forfeited,$held,$status,$actor['id']??null,$source,$bookingId]);
}

function tamasyaEnsureSecurityDepositRow(PDO $pdo,string $bookingId,float $required,array $actor,string $source): array {
    $required=max(0.0,round($required,2));
    $existing=tamasyaSecurityDepositSummary($pdo,$bookingId,true);
    if(!empty($existing['id'])){
        if(!moneyMatches((float)$existing['requiredAmount'],$required,0.01)){
            if((float)$existing['receivedAmount']>0.01)throw new RuntimeException('Nominal wajib deposito tidak dapat diubah setelah deposito mulai diterima.');
            $status=tamasyaSecurityDepositStatus($required,0.0,0.0,0.0,(string)$existing['status']);
            $pdo->prepare("UPDATE guest_security_deposits SET required_amount=?,status=?,version=version+1,updated_by=?,updated_source=?,updated_at=CURRENT_TIMESTAMP WHERE booking_id=?")
                ->execute([$required,$status,$actor['id']??null,$source,$bookingId]);
        }
        return tamasyaSecurityDepositSummary($pdo,$bookingId,true);
    }
    $id='gsd_'.substr(hash('sha256',$bookingId),0,36);
    $status=$required>0?'pending':'not_required';
    $pdo->prepare("INSERT INTO guest_security_deposits(id,booking_id,required_amount,received_amount,refunded_amount,forfeited_amount,held_balance,status,version,created_by,updated_by,updated_source,created_at,updated_at) VALUES (?,?,?,0,0,0,0,?,1,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
        ->execute([$id,$bookingId,$required,$status,$actor['id']??null,$actor['id']??null,$source]);
    return tamasyaSecurityDepositSummary($pdo,$bookingId,true);
}

function tamasyaInsertSecurityDepositLedger(PDO $pdo,array $data): string {
    $operationId=trim((string)($data['operationId']??''));
    if($operationId===''||strlen($operationId)>100)throw new InvalidArgumentException('Operation ID deposito wajib dan maksimal 100 karakter.');
    $duplicate=$pdo->prepare("SELECT id,deposit_id,booking_id,entry_type,amount,transaction_id FROM guest_security_deposit_ledger WHERE operation_id=? LIMIT 1 FOR UPDATE");
    $duplicate->execute([$operationId]);$old=$duplicate->fetch(PDO::FETCH_ASSOC);
    if($old){
        if((string)$old['booking_id']!==(string)$data['bookingId']||(string)$old['entry_type']!==(string)$data['entryType']||!moneyMatches((float)$old['amount'],(float)$data['amount'],0.01))throw new RuntimeException('Operation ID deposito sudah dipakai untuk operasi berbeda.');
        return (string)$old['id'];
    }
    $id='gsdl_'.substr(hash('sha256',$operationId),0,34);
    $pdo->prepare("INSERT INTO guest_security_deposit_ledger(id,operation_id,deposit_id,booking_id,entry_type,amount,payment_method,bank_account_id,reason,reason_type,transaction_id,shift_session_id,created_by,created_by_name,source,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)")
        ->execute([$id,$operationId,$data['depositId'],$data['bookingId'],$data['entryType'],$data['amount'],$data['paymentMethod']??null,$data['bankAccountId']??null,$data['reason']??null,$data['reasonType']??null,$data['transactionId']??null,$data['shiftSessionId']??null,$data['actorId']??null,$data['actorName']??null,$data['source']??'web']);
    return $id;
}

function tamasyaReceiveGuestSecurityDepositInTransaction(PDO $pdo,array $actor,string $bookingId,array $payload,string $source='web',?string $resolvedShiftSessionId=null): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'penerimaan deposito jaminan tamu');
    if(!$pdo->inTransaction())throw new RuntimeException('Penerimaan deposito wajib berada dalam transaksi database.');
    $bookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$bookingStmt->execute([$bookingId]);$booking=$bookingStmt->fetch(PDO::FETCH_ASSOC);
    if(!$booking)throw new RuntimeException('Booking deposito tidak ditemukan.');
    if(!in_array(strtolower((string)($booking['status']??'')),['reserved','active'],true))throw new RuntimeException('Deposito hanya dapat diterima untuk reservasi atau booking aktif.');
    $amount=round((float)($payload['amount']??0),2);$required=round((float)($payload['requiredAmount']??($booking['securityDepositRequiredAmount']??$amount)),2);
    if($amount<=0||$amount>1000000000000)throw new InvalidArgumentException('Nominal deposito jaminan harus lebih dari nol dan dalam batas aman.');
    if($required<=0)$required=$amount;
    $method=strtolower(trim((string)($payload['paymentMethod']??'cash')));$bankAccountId=trim((string)($payload['bankAccountId']??''));
    $account=tamasyaSecurityDepositPaymentAccount($pdo,$method,$bankAccountId);
    $operationId=trim((string)($payload['operationId']??''));$date=trim((string)($payload['date']??date('Y-m-d')));$notes=trim((string)($payload['notes']??''));
    if($operationId===''||strlen($operationId)>90)throw new InvalidArgumentException('Operation ID penerimaan deposito wajib dan maksimal 90 karakter.');
    if(!validIsoDate($date))throw new InvalidArgumentException('Tanggal deposito tidak valid.');
    $summary=tamasyaEnsureSecurityDepositRow($pdo,$bookingId,$required,$actor,$source);
    $duplicate=$pdo->prepare("SELECT id,amount,sourceEntityId,transactionKind FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE");$duplicate->execute([$operationId]);$oldTx=$duplicate->fetch(PDO::FETCH_ASSOC);
    if($oldTx){
        if((string)$oldTx['transactionKind']!=='security_deposit_received'||(string)$oldTx['sourceEntityId']!==(string)$summary['id']||!moneyMatches((float)$oldTx['amount'],$amount,0.01))throw new RuntimeException('Operation ID sudah dipakai untuk transaksi berbeda.');
        return ['duplicate'=>true,'transactionId'=>(string)$oldTx['id'],'summary'=>tamasyaSecurityDepositSummary($pdo,$bookingId,true)];
    }
    $remaining=max(0.0,round((float)$summary['requiredAmount']-(float)$summary['heldBalance'],2));
    if($amount>$remaining+0.01)throw new RuntimeException('Penerimaan deposito melebihi saldo deposito yang masih wajib ditahan Rp '.number_format($remaining,0,',','.').'.');
    $depositRequiresShift=tamasyaManualTransactionRequiresOpenShift(['type'=>'income','amount'=>$amount,'bankAccountId'=>$account]);
    $shiftSessionId=$resolvedShiftSessionId?:resolveOpenShiftSessionId($pdo,$actor,$depositRequiresShift);
    $txId='tx_gsd_in_'.substr(hash('sha256',$operationId),0,28);$label=$method==='qris'?'QRIS':($method==='transfer'?'Transfer':'Tunai');
    $description='Deposito Jaminan Kamar '.(string)$booking['roomNumber'].' - '.(string)$booking['guestName'].' ('.$label.')'.($notes!==''?' - '.$notes:'');
    tamasyaPostFinancialTransaction($pdo,[
        'id'=>$txId,'type'=>'income','category'=>'Deposito Jaminan Tamu','subcategory'=>'Penerimaan Deposit','roomNumber'=>(string)$booking['roomNumber'],
        'amount'=>$amount,'date'=>$date,'description'=>$description,'createdBy'=>currentStaffLabel($actor),'bankAccountId'=>$account,
        'baseAmount'=>(float)$amount,'taxAmount'=>0,'taxRate'=>0,'taxSnapshotStatus'=>'not_applicable','taxSource'=>'security_deposit',
        'transactionKind'=>'security_deposit_received','sourceEntity'=>'guest_security_deposit','sourceEntityId'=>(string)$summary['id'],'isSystemGenerated'=>1,
        'operationId'=>$operationId,'shiftSessionId'=>$shiftSessionId,'updatedBy'=>$actor['id']??null,'updatedSource'=>$source,'version'=>1
    ],$actor,'guest_deposit',['source'=>$source]);
    tamasyaInsertSecurityDepositLedger($pdo,['operationId'=>$operationId,'depositId'=>$summary['id'],'bookingId'=>$bookingId,'entryType'=>'receive','amount'=>$amount,'paymentMethod'=>$method,'bankAccountId'=>$account,'reason'=>$notes,'transactionId'=>$txId,'shiftSessionId'=>$shiftSessionId,'actorId'=>$actor['id']??null,'actorName'=>currentStaffLabel($actor),'source'=>$source]);
    $received=round((float)$summary['receivedAmount']+$amount,2);$refunded=(float)$summary['refundedAmount'];$forfeited=(float)$summary['forfeitedAmount'];$held=max(0.0,round($received-$refunded-$forfeited,2));
    $status=tamasyaSecurityDepositStatus((float)$summary['requiredAmount'],$received,$refunded,$forfeited);
    $pdo->prepare("UPDATE guest_security_deposits SET received_amount=?,held_balance=?,status=?,version=version+1,updated_by=?,updated_source=?,updated_at=CURRENT_TIMESTAMP WHERE booking_id=?")
        ->execute([$received,$held,$status,$actor['id']??null,$source,$bookingId]);
    $final=tamasyaSecurityDepositSummary($pdo,$bookingId,true);tamasyaSyncSecurityDepositProjection($pdo,$bookingId,$final,$actor,$source);
    writeRequiredEnterpriseAudit($pdo,$actor,'Menerima deposito jaminan tamu','guest_security_deposit',(string)$summary['id'],$summary,['summary'=>$final,'transactionId'=>$txId],$source);
    return ['duplicate'=>false,'transactionId'=>$txId,'shiftSessionId'=>$shiftSessionId,'summary'=>$final];
}

function tamasyaSettleGuestSecurityDepositInTransaction(PDO $pdo,array $actor,string $bookingId,array $payload,string $source='web'): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'penyelesaian deposito jaminan tamu');
    if(!$pdo->inTransaction())throw new RuntimeException('Penyelesaian deposito wajib berada dalam transaksi database.');
    $bookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$bookingStmt->execute([$bookingId]);$booking=$bookingStmt->fetch(PDO::FETCH_ASSOC);
    if(!$booking)throw new RuntimeException('Booking deposito tidak ditemukan.');
    $summary=tamasyaSecurityDepositSummary($pdo,$bookingId,true);$held=round((float)$summary['heldBalance'],2);
    if($held<=0.01)return ['duplicate'=>false,'summary'=>$summary,'transactionIds'=>[]];
    $disposition=strtolower(trim((string)($payload['disposition']??'')));
    if($disposition==='hold'){
        $operationId=trim((string)($payload['operationId']??''));
        tamasyaInsertSecurityDepositLedger($pdo,['operationId'=>$operationId,'depositId'=>$summary['id'],'bookingId'=>$bookingId,'entryType'=>'hold','amount'=>$held,'reason'=>trim((string)($payload['reason']??'Menunggu pemeriksaan kamar/kunci')),'actorId'=>$actor['id']??null,'actorName'=>currentStaffLabel($actor),'source'=>$source]);
        $pdo->prepare("UPDATE guest_security_deposits SET status='pending_inspection',version=version+1,updated_by=?,updated_source=?,updated_at=CURRENT_TIMESTAMP WHERE booking_id=?")->execute([$actor['id']??null,$source,$bookingId]);
        $final=tamasyaSecurityDepositSummary($pdo,$bookingId,true);tamasyaSyncSecurityDepositProjection($pdo,$bookingId,$final,$actor,$source);
        return ['duplicate'=>false,'summary'=>$final,'transactionIds'=>[]];
    }
    if($disposition==='refund_full'){$refundAmount=$held;$forfeitAmount=0.0;}else{
        if($disposition!=='settle')throw new InvalidArgumentException('Pilih pengembalian penuh, penyelesaian sebagian, atau tahan deposito.');
        $refundAmount=max(0.0,round((float)($payload['refundAmount']??0),2));$forfeitAmount=max(0.0,round((float)($payload['forfeitAmount']??0),2));
        if(!moneyMatches($refundAmount+$forfeitAmount,$held,0.01))throw new InvalidArgumentException('Jumlah pengembalian dan potongan harus sama dengan saldo deposito yang ditahan.');
    }
    $operationId=trim((string)($payload['operationId']??''));if($operationId===''||strlen($operationId)>90)throw new InvalidArgumentException('Operation ID penyelesaian deposito wajib dan maksimal 90 karakter.');
    $transactionIds=[];$refundMethod=strtolower(trim((string)($payload['refundMethod']??'cash')));$refundBank=trim((string)($payload['refundBankAccountId']??''));
    if($refundAmount>0){
        $account=tamasyaSecurityDepositPaymentAccount($pdo,$refundMethod,$refundBank);$refundRequiresShift=tamasyaManualTransactionRequiresOpenShift(['type'=>'expense','amount'=>$refundAmount,'bankAccountId'=>$account]);$shift=resolveOpenShiftSessionId($pdo,$actor,$refundRequiresShift);
        $op=$operationId.':refund';$dup=$pdo->prepare("SELECT id,amount,transactionKind,sourceEntityId FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE");$dup->execute([$op]);$old=$dup->fetch(PDO::FETCH_ASSOC);
        if($old){if((string)$old['transactionKind']!=='security_deposit_refund'||(string)($old['sourceEntityId']??'')!==(string)$summary['id']||!moneyMatches((float)$old['amount'],$refundAmount,0.01))throw new RuntimeException('Operation ID refund deposito sudah dipakai untuk transaksi atau booking berbeda.');$txId=(string)$old['id'];}
        else{
            $txId='tx_gsd_out_'.substr(hash('sha256',$op),0,27);$label=$refundMethod==='qris'?'QRIS':($refundMethod==='transfer'?'Transfer':'Tunai');
            tamasyaPostFinancialTransaction($pdo,[
                'id'=>$txId,'type'=>'expense','category'=>'Deposito Jaminan Tamu','subcategory'=>'Pengembalian Deposit','roomNumber'=>(string)$booking['roomNumber'],
                'amount'=>$refundAmount,'date'=>date('Y-m-d'),'description'=>'Pengembalian Deposito Kamar '.$booking['roomNumber'].' - '.$booking['guestName'].' ('.$label.')',
                'createdBy'=>currentStaffLabel($actor),'bankAccountId'=>$account,'baseAmount'=>(float)$refundAmount,'taxAmount'=>0,'taxRate'=>0,
                'taxSnapshotStatus'=>'not_applicable','taxSource'=>'security_deposit','transactionKind'=>'security_deposit_refund','sourceEntity'=>'guest_security_deposit',
                'sourceEntityId'=>(string)$summary['id'],'isSystemGenerated'=>1,'operationId'=>$op,'shiftSessionId'=>$shift,
                'updatedBy'=>$actor['id']??null,'updatedSource'=>$source,'version'=>1
            ],$actor,'guest_deposit',['source'=>$source]);
            tamasyaInsertSecurityDepositLedger($pdo,['operationId'=>$op,'depositId'=>$summary['id'],'bookingId'=>$bookingId,'entryType'=>'refund','amount'=>$refundAmount,'paymentMethod'=>$refundMethod,'bankAccountId'=>$account,'transactionId'=>$txId,'shiftSessionId'=>$shift,'actorId'=>$actor['id']??null,'actorName'=>currentStaffLabel($actor),'source'=>$source]);
        }
        $transactionIds[]=$txId;
    }
    if($forfeitAmount>0){
        $reason=trim((string)($payload['forfeitReason']??''));$reasonType=strtolower(trim((string)($payload['forfeitType']??'other')));
        if(strlen($reason)<5)throw new InvalidArgumentException('Alasan potongan deposito wajib dijelaskan.');
        if(!in_array($reasonType,['key','damage','lost_item','other'],true))$reasonType='other';
        $op=$operationId.':forfeit';$dup=$pdo->prepare("SELECT id,amount,transactionKind,sourceEntityId FROM transactions WHERE operationId=? LIMIT 1 FOR UPDATE");$dup->execute([$op]);$old=$dup->fetch(PDO::FETCH_ASSOC);
        if($old){if((string)$old['transactionKind']!=='security_deposit_forfeit'||(string)($old['sourceEntityId']??'')!==(string)$summary['id']||!moneyMatches((float)$old['amount'],$forfeitAmount,0.01))throw new RuntimeException('Operation ID potongan deposito sudah dipakai untuk transaksi atau booking berbeda.');$txId=(string)$old['id'];}
        else{
            $txId='tx_gsd_forfeit_'.substr(hash('sha256',$op),0,23);$sub=['key'=>'Penggantian Kunci','damage'=>'Kerusakan Kamar','lost_item'=>'Barang Hotel Hilang','other'=>'Ganti Rugi Lainnya'][$reasonType];
            tamasyaPostFinancialTransaction($pdo,[
                'id'=>$txId,'type'=>'income','category'=>'Ganti Rugi Tamu','subcategory'=>$sub,'roomNumber'=>(string)$booking['roomNumber'],
                'amount'=>$forfeitAmount,'date'=>date('Y-m-d'),'description'=>'Potongan Deposito Kamar '.$booking['roomNumber'].' - '.$booking['guestName'].' - '.$reason,
                'createdBy'=>currentStaffLabel($actor),'baseAmount'=>(float)$forfeitAmount,'taxAmount'=>0,'taxRate'=>0,'taxSnapshotStatus'=>'not_applicable',
                'taxSource'=>'security_deposit_forfeit','transactionKind'=>'security_deposit_forfeit','sourceEntity'=>'guest_security_deposit','sourceEntityId'=>(string)$summary['id'],
                'isSystemGenerated'=>1,'operationId'=>$op,'lockedAt'=>date('Y-m-d H:i:s'),'recordOrigin'=>'live_operation','shiftExempt'=>1,
                'shiftExemptionReason'=>'Konversi kewajiban deposito menjadi pendapatan tanpa arus kas baru','updatedBy'=>$actor['id']??null,'updatedSource'=>$source,'version'=>1
            ],$actor,'guest_deposit',['source'=>$source]);
            tamasyaInsertSecurityDepositLedger($pdo,['operationId'=>$op,'depositId'=>$summary['id'],'bookingId'=>$bookingId,'entryType'=>'forfeit','amount'=>$forfeitAmount,'reason'=>$reason,'reasonType'=>$reasonType,'transactionId'=>$txId,'actorId'=>$actor['id']??null,'actorName'=>currentStaffLabel($actor),'source'=>$source]);
        }
        $transactionIds[]=$txId;
    }
    $newRefund=round((float)$summary['refundedAmount']+$refundAmount,2);$newForfeit=round((float)$summary['forfeitedAmount']+$forfeitAmount,2);$newHeld=max(0.0,round((float)$summary['receivedAmount']-$newRefund-$newForfeit,2));
    $status=tamasyaSecurityDepositStatus((float)$summary['requiredAmount'],(float)$summary['receivedAmount'],$newRefund,$newForfeit);
    $pdo->prepare("UPDATE guest_security_deposits SET refunded_amount=?,forfeited_amount=?,held_balance=?,status=?,version=version+1,updated_by=?,updated_source=?,updated_at=CURRENT_TIMESTAMP WHERE booking_id=?")
        ->execute([$newRefund,$newForfeit,$newHeld,$status,$actor['id']??null,$source,$bookingId]);
    $final=tamasyaSecurityDepositSummary($pdo,$bookingId,true);tamasyaSyncSecurityDepositProjection($pdo,$bookingId,$final,$actor,$source);
    writeRequiredEnterpriseAudit($pdo,$actor,'Menyelesaikan deposito jaminan tamu','guest_security_deposit',(string)$summary['id'],$summary,['summary'=>$final,'transactionIds'=>$transactionIds],$source);
    return ['duplicate'=>false,'summary'=>$final,'transactionIds'=>$transactionIds];
}
