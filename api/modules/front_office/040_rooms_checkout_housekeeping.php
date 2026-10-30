<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }


/**
 * Alert kerusakan checkout dan tiket maintenance memakai suffix hash yang sama.
 * Mapping deterministik ini menjaga satu incident punya satu pasangan sumber kebenaran.
 */
function checkoutDamageTicketIdFromAlertId(string $alertId): string {
    $prefix='alert_room_damage_';
    if(!str_starts_with($alertId,$prefix))return '';
    $suffix=substr($alertId,strlen($prefix));
    return preg_match('/^[a-f0-9]{40}$/',$suffix) ? 'ticket_checkout_damage_'.$suffix : '';
}

function checkoutDamageAlertIdFromTicketId(string $ticketId): string {
    $prefix='ticket_checkout_damage_';
    if(!str_starts_with($ticketId,$prefix))return '';
    $suffix=substr($ticketId,strlen($prefix));
    return preg_match('/^[a-f0-9]{40}$/',$suffix) ? 'alert_room_damage_'.$suffix : '';
}

/** ID alert review pembatalan maintenance yang deterministik per tiket+kamar. */
function maintenanceCancellationAlertIdForTicket(string $ticketId, string $roomNumber): string {
    $ticketId=trim($ticketId);$roomNumber=trim($roomNumber);
    if($ticketId===''||$roomNumber==='')return '';
    return 'alert_maint_cancel_'.substr(hash('sha256',$ticketId.'|'.$roomNumber),0,40);
}

/**
 * Satu incident checkout-damage hanya boleh memiliki satu blocker operasional aktif.
 * - ticket completed   => alert room-damage dianggap terselesaikan.
 * - ticket cancelled   => alert room-damage disupersede oleh alert review
 *                         maintenance-cancelled yang deterministik.
 *
 * Dengan begitu cancellation tidak menghasilkan dua blocker untuk incident yang sama.
 * Jika alert review cancellation sudah diakui Manager/Admin pada data legacy, alert
 * room-damage lama juga dianggap selesai untuk keputusan kesiapan kamar.
 */
function resolvedCheckoutDamageAlertIds(PDO $pdo, array $alertRows, bool $forUpdate=false): array {
    $ticketToIncident=[];
    foreach($alertRows as $row){
        $source=(string)($row['source']??'');
        if(!str_starts_with($source,'room-damage:'))continue;
        $roomNumber=trim(substr($source,strlen('room-damage:')));
        $alertId=(string)($row['id']??'');
        $ticketId=checkoutDamageTicketIdFromAlertId($alertId);
        if($ticketId!==''&&$roomNumber!=='')$ticketToIncident[$ticketId]=['alertId'=>$alertId,'roomNumber'=>$roomNumber];
    }
    if(!$ticketToIncident)return [];

    $ids=array_keys($ticketToIncident);
    $ph=implode(',',array_fill(0,count($ids),'?'));
    // Tidak memakai FOR UPDATE: helper ini dipakai oleh read projection juga. Snapshot
    // lama selalu fail-safe karena incident tetap diblokir sampai transaksi resolver commit.
    $stmt=$pdo->prepare("SELECT id,status,asset_ref FROM maintenance_tickets WHERE id IN ($ph) AND status IN ('completed','cancelled')");
    $stmt->execute($ids);
    $resolved=[];$cancelCandidates=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $ticket){
        $ticketId=(string)($ticket['id']??'');
        $incident=$ticketToIncident[$ticketId]??null;
        if(!$incident)continue;
        $status=strtolower(trim((string)($ticket['status']??'')));
        if($status==='completed'){
            $resolved[(string)$incident['alertId']]=true;
            continue;
        }
        if($status!=='cancelled')continue;
        $roomNumber=(string)$incident['roomNumber'];
        $assetRef=trim((string)($ticket['asset_ref']??''));
        // Fail closed bila ownership tiket tidak lagi cocok dengan kamar incident.
        if(!in_array($assetRef,[$roomNumber,'room:'.$roomNumber],true))continue;
        $cancelAlertId=maintenanceCancellationAlertIdForTicket($ticketId,$roomNumber);
        if($cancelAlertId!=='')$cancelCandidates[$cancelAlertId]=['damageAlertId'=>(string)$incident['alertId'],'roomNumber'=>$roomNumber];
    }
    if(!$cancelCandidates)return $resolved;

    $cancelIds=array_keys($cancelCandidates);
    $cancelPh=implode(',',array_fill(0,count($cancelIds),'?'));
    $cancelStmt=$pdo->prepare("SELECT id,source,acknowledged_at FROM system_alerts WHERE id IN ($cancelPh)");
    $cancelStmt->execute($cancelIds);
    foreach($cancelStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $cancelAlert){
        $cancelId=(string)($cancelAlert['id']??'');
        $candidate=$cancelCandidates[$cancelId]??null;
        if(!$candidate)continue;
        if((string)($cancelAlert['source']??'')!=='maintenance-cancelled:'.(string)$candidate['roomNumber'])continue;
        // Selama alert cancellation masih open, alert itulah blocker tunggal. Setelah
        // review diakui, incident dianggap selesai untuk legacy projection; resolver
        // review juga menutup row damage alert secara fisik dalam transaksi yang sama.
        $resolved[(string)$candidate['damageAlertId']]=true;
    }
    return $resolved;
}

/**
 * Saat tiket kerusakan checkout selesai, alert pasangannya selesai dalam transaksi
 * yang sama. Return alert id hanya bila row open benar-benar diubah.
 */
function reconcileCompletedCheckoutDamageAlert(PDO $pdo, array $staff, string $ticketId, string $roomNumber, string $source='web'): string {
    $alertId=checkoutDamageAlertIdFromTicketId(trim($ticketId));
    $roomNumber=trim($roomNumber);
    if($alertId===''||$roomNumber==='')return '';
    $stmt=$pdo->prepare("SELECT * FROM system_alerts WHERE id=? AND source=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$alertId,'room-damage:'.$roomNumber]);
    $before=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$before||!empty($before['acknowledged_at']))return '';
    $staffId=(string)($staff['id']??'');
    $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=? AND acknowledged_at IS NULL")
        ->execute([$staffId,$alertId]);
    $after=$before;$after['acknowledged_by']=$staffId;$after['acknowledged_at']=date('Y-m-d H:i:s');
    writeRequiredEnterpriseAudit($pdo,$staff,'Menutup alert kerusakan checkout setelah maintenance selesai','system_alert',$alertId,$before,$after,$source);
    return $alertId;
}

/**
 * Sumber kebenaran tunggal untuk kesiapan operasional kamar. Semua jalur
 * penjualan, housekeeping, status manual, offline sync, dan Telegram wajib
 * memakai hasil fungsi ini agar rooms.status tidak berbeda dengan tabel
 * operasional lain.
 */
function getRoomOperationalBlockers(PDO $pdo, string $roomNumber, bool $forUpdate=false, string $excludeTaskId=''): array {
    $roomNumber=trim($roomNumber);
    if($roomNumber==='')throw new InvalidArgumentException('Nomor kamar wajib diisi.');
    $lock=$forUpdate&&$pdo->inTransaction()?' FOR UPDATE':'';
    $blockers=[];

    $bookingStmt=$pdo->prepare("SELECT id,status FROM bookings WHERE roomNumber=? AND status='active' ORDER BY createdAt,id".$lock);
    $bookingStmt->execute([$roomNumber]);
    foreach($bookingStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $blockers[]=['type'=>'active_booking','id'=>(string)$row['id'],'status'=>(string)$row['status'],'message'=>'reservasi aktif'];
    }

    $taskSql="SELECT id,status,assigned_to FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed')";
    $taskParams=[$roomNumber];
    $excludeTaskId=trim($excludeTaskId);
    if($excludeTaskId!==''){$taskSql.=" AND id<>?";$taskParams[]=$excludeTaskId;}
    $taskSql.=" ORDER BY created_at,id".$lock;
    $taskStmt=$pdo->prepare($taskSql);$taskStmt->execute($taskParams);
    foreach($taskStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $blockers[]=['type'=>'housekeeping','id'=>(string)$row['id'],'status'=>(string)$row['status'],'message'=>'tugas housekeeping belum selesai'];
    }

    $maintenanceStmt=$pdo->prepare("SELECT id,status FROM maintenance_tickets WHERE asset_ref IN (?,?) AND status NOT IN ('completed','cancelled') ORDER BY created_at,id".$lock);
    $maintenanceStmt->execute([$roomNumber,'room:'.$roomNumber]);
    foreach($maintenanceStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
        $blockers[]=['type'=>'maintenance','id'=>(string)$row['id'],'status'=>(string)$row['status'],'message'=>'tiket maintenance aktif'];
    }

    // R4 domain-first blockers. Alerts are notification/review surfaces, not the
    // primary lifecycle for Lost & Found, key control, or cancellation review.
    $hasLostFoundDomain=tamasyaTableExists($pdo,'lost_found_items');
    $lostFoundDomainAlertIds=[];
    if($hasLostFoundDomain){
        $lfStmt=$pdo->prepare("SELECT id,alert_id,custody_status,item_description FROM lost_found_items WHERE room_number=? ORDER BY found_at,id".$lock);
        $lfStmt->execute([$roomNumber]);
        foreach($lfStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $lfAlertId=trim((string)($row['alert_id']??''));
            if($lfAlertId!=='')$lostFoundDomainAlertIds[$lfAlertId]=true;
            if(strtolower(trim((string)($row['custody_status']??'')))==='found'){
                $blockers[]=['type'=>'lost_found','id'=>(string)$row['id'],'status'=>(string)$row['custody_status'],'message'=>'barang tamu belum diamankan dari kamar'];
            }
        }
    }

    $hasCancellationReviewDomain=tamasyaTableExists($pdo,'maintenance_cancellation_reviews');
    $cancellationReviewAlertIds=[];
    if($hasCancellationReviewDomain){
        $reviewStmt=$pdo->prepare("SELECT id,alert_id,ticket_id,status FROM maintenance_cancellation_reviews WHERE room_number=? ORDER BY created_at,id".$lock);
        $reviewStmt->execute([$roomNumber]);
        foreach($reviewStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $reviewAlertId=trim((string)($row['alert_id']??''));
            if($reviewAlertId!=='')$cancellationReviewAlertIds[$reviewAlertId]=true;
            if(strtolower(trim((string)($row['status']??'')))==='open'){
                $blockers[]=['type'=>'maintenance_review','id'=>(string)$row['id'],'status'=>'open','message'=>'pembatalan maintenance belum direview Manager/Admin'];
            }
        }
    }

    // Key register is the primary Key Control state. A legacy alert is only a
    // fallback when no register state can represent the old incident.
    $keyState=null;$keyStateBlocks=false;
    $keyStmt=$pdo->prepare("SELECT access_mode,physical_key_status,current_booking_id FROM room_access_control WHERE room_number=? LIMIT 1".$lock);
    $keyStmt->execute([$roomNumber]);$keyState=$keyStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if($keyState){
        $keyStatus=strtolower(trim((string)($keyState['physical_key_status']??'secured')));
        $keyMode=strtolower(trim((string)($keyState['access_mode']??'physical')));
        if(in_array($keyMode,['physical','hybrid'],true) && in_array($keyStatus,['issued','missing','override'],true)){
            $keyStateBlocks=true;
            $blockers[]=['type'=>'physical_key','id'=>$roomNumber,'status'=>$keyStatus,'bookingId'=>(string)($keyState['current_booking_id']??''),'message'=>$keyStatus==='missing'?'kunci fisik belum ditemukan/diamankan':'register kunci fisik belum kembali secured'];
        }
    }

    // Legacy/orphan alert fallback only. A paired maintenance ticket, dedicated
    // Lost & Found item, cancellation review, or key register owns the real state.
    $alertStmt=$pdo->prepare("SELECT id,source FROM system_alerts WHERE acknowledged_at IS NULL AND source IN (?,?,?,?) ORDER BY created_at,id".$lock);
    $alertStmt->execute(['lost-found:'.$roomNumber,'room-damage:'.$roomNumber,'maintenance-cancelled:'.$roomNumber,'key-missing:'.$roomNumber]);
    $alertRows=$alertStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $resolvedDamageAlerts=resolvedCheckoutDamageAlertIds($pdo,$alertRows,$forUpdate);
    foreach($alertRows as $row){
        $alertId=(string)($row['id']??'');$source=(string)($row['source']??'');
        if(isset($resolvedDamageAlerts[$alertId]))continue;
        if(str_starts_with($source,'lost-found:') && isset($lostFoundDomainAlertIds[$alertId]))continue;
        if(str_starts_with($source,'maintenance-cancelled:') && isset($cancellationReviewAlertIds[$alertId]))continue;
        if(str_starts_with($source,'key-missing:') && $keyStateBlocks)continue;
        if(str_starts_with($source,'room-damage:')){
            $ticketId=checkoutDamageTicketIdFromAlertId($alertId);
            if($ticketId!==''){
                $ticketCheck=$pdo->prepare("SELECT status FROM maintenance_tickets WHERE id=? LIMIT 1");$ticketCheck->execute([$ticketId]);
                if($ticketCheck->fetchColumn()!==false)continue;
            }
        }
        $message=str_starts_with($source,'lost-found:')?'barang tertinggal legacy belum mempunyai custody record':(str_starts_with($source,'maintenance-cancelled:')?'review pembatalan maintenance legacy belum mempunyai domain record':(str_starts_with($source,'key-missing:')?'alert kunci legacy belum direkonsiliasi':'temuan kerusakan legacy belum mempunyai work order'));
        $blockers[]=['type'=>'legacy_alert','id'=>$alertId,'status'=>'open','message'=>$message];
    }

    // Extensibility point for life-safety/security/manual operational holds.
    if(tamasyaTableExists($pdo,'room_operational_holds')){
        $holdStmt=$pdo->prepare("SELECT id,hold_type,severity,reason,status FROM room_operational_holds WHERE room_number=? AND status='open' ORDER BY created_at,id".$lock);
        $holdStmt->execute([$roomNumber]);
        foreach($holdStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $blockers[]=['type'=>'operational_hold','id'=>(string)$row['id'],'status'=>'open','message'=>trim((string)($row['reason']??''))?:('hold '.(string)($row['hold_type']??'operasional'))];
        }
    }

    if(tamasyaTableExists($pdo,'night_audit_items')){
        $nightStmt=$pdo->prepare("SELECT id,audit_id,discrepancy_type,resolution_status FROM night_audit_items WHERE room_number=? AND resolution_status<>'resolved' AND discrepancy_type IS NOT NULL AND discrepancy_type<>'' ORDER BY checked_at,id".$lock);
        $nightStmt->execute([$roomNumber]);
        foreach($nightStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $blockers[]=['type'=>'night_audit','id'=>(string)$row['id'],'status'=>(string)($row['resolution_status']??'open'),'message'=>'temuan Night Audit belum diselesaikan: '.(string)($row['discrepancy_type']??'discrepancy')];
        }
    }

    if(tamasyaTableExists($pdo,'smart_lock_jobs')){
        $smartLockStmt=$pdo->prepare("SELECT id,action,status FROM smart_lock_jobs WHERE room_number=? AND status NOT IN ('completed','cancelled') ORDER BY created_at,id".$lock);
        $smartLockStmt->execute([$roomNumber]);
        foreach($smartLockStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $blockers[]=['type'=>'smart_lock','id'=>(string)$row['id'],'status'=>(string)$row['status'],'message'=>strtolower((string)($row['action']??''))==='grant'?'pemberian smart-lock belum terkonfirmasi':'pencabutan smart-lock belum terkonfirmasi'];
        }
    }
    return $blockers;
}


/**
 * Read-only fast path untuk UI Telegram. Mutation tetap wajib memakai
 * getRoomOperationalBlockers() agar locking/safety canonical tidak berubah.
 * Query UNION tunggal menghindari beberapa round-trip DB saat operator membuka
 * detail kamar/housekeeping melalui Telegram.
 */
function getRoomOperationalBlockersFastRead(PDO $pdo, string $roomNumber, string $excludeTaskId=''): array {
    $roomNumber=trim($roomNumber);
    if($roomNumber==='')throw new InvalidArgumentException('Nomor kamar wajib diisi.');
    $excludeTaskId=trim($excludeTaskId);
    // R4 keeps a single UNION round-trip for Telegram/read surfaces, but the
    // branches now mirror the same domain ownership as the canonical blocker.
    $sql="
        SELECT 'active_booking' AS type,id,status,'reservasi aktif' AS message
          FROM bookings WHERE roomNumber=? AND status='active'
        UNION ALL
        SELECT 'housekeeping' AS type,id,status,'tugas housekeeping belum selesai' AS message
          FROM housekeeping_tasks
         WHERE room_number=? AND status NOT IN ('ready','completed') AND (?='' OR id<>?)
        UNION ALL
        SELECT 'maintenance' AS type,id,status,'tiket maintenance aktif' AS message
          FROM maintenance_tickets
         WHERE asset_ref IN (?,?) AND status NOT IN ('completed','cancelled')
        UNION ALL
        SELECT 'lost_found' AS type,id,custody_status AS status,'barang tamu belum diamankan dari kamar' AS message
          FROM lost_found_items
         WHERE room_number=? AND custody_status='found'
        UNION ALL
        SELECT 'maintenance_review' AS type,id,status,'pembatalan maintenance belum direview Manager/Admin' AS message
          FROM maintenance_cancellation_reviews
         WHERE room_number=? AND status='open'
        UNION ALL
        SELECT 'legacy_alert' AS type,sa.id,'open' AS status,
               CASE
                 WHEN sa.source LIKE 'lost-found:%' THEN 'barang tertinggal legacy belum mempunyai custody record'
                 WHEN sa.source LIKE 'maintenance-cancelled:%' THEN 'review pembatalan maintenance legacy belum mempunyai domain record'
                 WHEN sa.source LIKE 'key-missing:%' THEN 'alert kunci legacy belum direkonsiliasi'
                 ELSE 'temuan kerusakan legacy belum mempunyai work order'
               END AS message
          FROM system_alerts sa
         WHERE sa.acknowledged_at IS NULL
           AND sa.source IN (?,?,?,?)
           AND NOT (sa.source=? AND EXISTS (SELECT 1 FROM lost_found_items lf WHERE lf.alert_id=sa.id))
           AND NOT (sa.source=? AND EXISTS (SELECT 1 FROM maintenance_cancellation_reviews mcr WHERE mcr.alert_id=sa.id))
           AND NOT (sa.source=? AND EXISTS (
                 SELECT 1 FROM room_access_control rac
                  WHERE rac.room_number=? AND rac.access_mode IN ('physical','hybrid') AND rac.physical_key_status IN ('issued','missing','override')
           ))
           AND NOT (sa.source=? AND sa.id LIKE 'alert_room_damage_%' AND EXISTS (
                 SELECT 1 FROM maintenance_tickets mt
                  WHERE mt.id=CONCAT('ticket_checkout_damage_',SUBSTRING(sa.id,19))
           ))
        UNION ALL
        SELECT 'physical_key' AS type,room_number AS id,physical_key_status AS status,
               CASE WHEN physical_key_status='missing' THEN 'kunci fisik belum ditemukan/diamankan' ELSE 'register kunci fisik belum kembali secured' END AS message
          FROM room_access_control
         WHERE room_number=? AND access_mode IN ('physical','hybrid') AND physical_key_status IN ('issued','missing','override')
        UNION ALL
        SELECT 'operational_hold' AS type,id,status,COALESCE(NULLIF(reason,''),CONCAT('hold ',hold_type)) AS message
          FROM room_operational_holds
         WHERE room_number=? AND status='open'
        UNION ALL
        SELECT 'night_audit' AS type,id,resolution_status AS status,CONCAT('temuan Night Audit belum diselesaikan: ',COALESCE(discrepancy_type,'discrepancy')) AS message
          FROM night_audit_items
         WHERE room_number=? AND resolution_status<>'resolved' AND discrepancy_type IS NOT NULL AND discrepancy_type<>''
        UNION ALL
        SELECT 'smart_lock' AS type,id,status,
               CASE WHEN LOWER(action)='grant' THEN 'pemberian smart-lock belum terkonfirmasi' ELSE 'pencabutan smart-lock belum terkonfirmasi' END AS message
          FROM smart_lock_jobs
         WHERE room_number=? AND status NOT IN ('completed','cancelled')";
    $params=[
        $roomNumber,
        $roomNumber,$excludeTaskId,$excludeTaskId,
        $roomNumber,'room:'.$roomNumber,
        $roomNumber,
        $roomNumber,
        'lost-found:'.$roomNumber,'room-damage:'.$roomNumber,'maintenance-cancelled:'.$roomNumber,'key-missing:'.$roomNumber,
        'lost-found:'.$roomNumber,
        'maintenance-cancelled:'.$roomNumber,
        'key-missing:'.$roomNumber,$roomNumber,
        'room-damage:'.$roomNumber,
        $roomNumber,
        $roomNumber,
        $roomNumber,
        $roomNumber,
    ];
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

/**
 * Bulk variant for read-only room projections. The safety-critical mutation
 * paths still use getRoomOperationalBlockers(..., true) with FOR UPDATE.
 * This helper removes the N+1 query pattern from dashboard/full-data refreshes
 * without changing blocker semantics.
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function getRoomOperationalBlockersMap(PDO $pdo, array $roomNumbers): array {
    $normalized=[];
    foreach($roomNumbers as $roomNumber){$roomNumber=trim((string)$roomNumber);if($roomNumber!=='')$normalized[$roomNumber]=$roomNumber;}
    $numbers=array_values($normalized);if(!$numbers)return [];
    $map=array_fill_keys($numbers,[]);$ph=implode(',',array_fill(0,count($numbers),'?'));
    $append=static function(array &$map,string $room,array $item): void {if($room!==''&&isset($map[$room]))$map[$room][]=$item;};

    $stmt=$pdo->prepare("SELECT roomNumber,id,status FROM bookings WHERE roomNumber IN ({$ph}) AND status='active' ORDER BY roomNumber,createdAt,id");$stmt->execute($numbers);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$append($map,(string)$row['roomNumber'],['type'=>'active_booking','id'=>(string)$row['id'],'status'=>(string)$row['status'],'message'=>'reservasi aktif']);

    $stmt=$pdo->prepare("SELECT room_number,id,status FROM housekeeping_tasks WHERE room_number IN ({$ph}) AND status NOT IN ('ready','completed') ORDER BY room_number,created_at,id");$stmt->execute($numbers);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$append($map,(string)$row['room_number'],['type'=>'housekeeping','id'=>(string)$row['id'],'status'=>(string)$row['status'],'message'=>'tugas housekeeping belum selesai']);

    $assetRefs=[];$assetToRoom=[];foreach($numbers as $room){foreach([$room,'room:'.$room] as $ref){$assetRefs[]=$ref;$assetToRoom[$ref]=$room;}}
    $aph=implode(',',array_fill(0,count($assetRefs),'?'));
    $stmt=$pdo->prepare("SELECT asset_ref,id,status FROM maintenance_tickets WHERE asset_ref IN ({$aph}) AND status NOT IN ('completed','cancelled') ORDER BY asset_ref,created_at,id");$stmt->execute($assetRefs);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$room=$assetToRoom[(string)$row['asset_ref']]??'';$append($map,$room,['type'=>'maintenance','id'=>(string)$row['id'],'status'=>(string)$row['status'],'message'=>'tiket maintenance aktif']);}

    $lostFoundAlertIds=[];
    $stmt=$pdo->prepare("SELECT room_number,id,alert_id,custody_status FROM lost_found_items WHERE room_number IN ({$ph}) ORDER BY room_number,found_at,id");$stmt->execute($numbers);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$aid=trim((string)($row['alert_id']??''));if($aid!=='')$lostFoundAlertIds[$aid]=true;if(strtolower((string)$row['custody_status'])==='found')$append($map,(string)$row['room_number'],['type'=>'lost_found','id'=>(string)$row['id'],'status'=>'found','message'=>'barang tamu belum diamankan dari kamar']);}

    $reviewAlertIds=[];
    $stmt=$pdo->prepare("SELECT room_number,id,alert_id,status FROM maintenance_cancellation_reviews WHERE room_number IN ({$ph}) ORDER BY room_number,created_at,id");$stmt->execute($numbers);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$aid=trim((string)($row['alert_id']??''));if($aid!=='')$reviewAlertIds[$aid]=true;if(strtolower((string)$row['status'])==='open')$append($map,(string)$row['room_number'],['type'=>'maintenance_review','id'=>(string)$row['id'],'status'=>'open','message'=>'pembatalan maintenance belum direview Manager/Admin']);}

    $blockingKeyRooms=[];
    $stmt=$pdo->prepare("SELECT room_number,physical_key_status,current_booking_id FROM room_access_control WHERE room_number IN ({$ph}) AND access_mode IN ('physical','hybrid') AND physical_key_status IN ('issued','missing','override') ORDER BY room_number");$stmt->execute($numbers);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$room=(string)$row['room_number'];$blockingKeyRooms[$room]=true;$status=strtolower((string)$row['physical_key_status']);$append($map,$room,['type'=>'physical_key','id'=>$room,'status'=>$status,'bookingId'=>(string)($row['current_booking_id']??''),'message'=>$status==='missing'?'kunci fisik belum ditemukan/diamankan':'register kunci fisik belum kembali secured']);}

    $sources=[];$sourceToRoom=[];foreach($numbers as $room){foreach(['lost-found:'.$room,'room-damage:'.$room,'maintenance-cancelled:'.$room,'key-missing:'.$room] as $source){$sources[]=$source;$sourceToRoom[$source]=$room;}}
    $sph=implode(',',array_fill(0,count($sources),'?'));$stmt=$pdo->prepare("SELECT id,source FROM system_alerts WHERE acknowledged_at IS NULL AND source IN ({$sph}) ORDER BY source,created_at,id");$stmt->execute($sources);$alerts=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $resolvedDamage=resolvedCheckoutDamageAlertIds($pdo,$alerts,false);
    $damageTicketIds=[];foreach($alerts as $row){if(str_starts_with((string)$row['source'],'room-damage:')){$tid=checkoutDamageTicketIdFromAlertId((string)$row['id']);if($tid!=='')$damageTicketIds[$tid]=(string)$row['id'];}}
    $pairedDamage=[];if($damageTicketIds){$dph=implode(',',array_fill(0,count($damageTicketIds),'?'));$d=$pdo->prepare("SELECT id FROM maintenance_tickets WHERE id IN ({$dph})");$d->execute(array_keys($damageTicketIds));foreach($d->fetchAll(PDO::FETCH_COLUMN)?:[] as $tid)$pairedDamage[$damageTicketIds[(string)$tid]]=true;}
    foreach($alerts as $row){$id=(string)$row['id'];$source=(string)$row['source'];$room=$sourceToRoom[$source]??'';if($room===''||isset($resolvedDamage[$id]))continue;
        if(str_starts_with($source,'lost-found:')&&isset($lostFoundAlertIds[$id]))continue;
        if(str_starts_with($source,'maintenance-cancelled:')&&isset($reviewAlertIds[$id]))continue;
        if(str_starts_with($source,'key-missing:')&&isset($blockingKeyRooms[$room]))continue;
        if(str_starts_with($source,'room-damage:')&&isset($pairedDamage[$id]))continue;
        $message=str_starts_with($source,'lost-found:')?'barang tertinggal legacy belum mempunyai custody record':(str_starts_with($source,'maintenance-cancelled:')?'review pembatalan maintenance legacy belum mempunyai domain record':(str_starts_with($source,'key-missing:')?'alert kunci legacy belum direkonsiliasi':'temuan kerusakan legacy belum mempunyai work order'));
        $append($map,$room,['type'=>'legacy_alert','id'=>$id,'status'=>'open','message'=>$message]);
    }

    $stmt=$pdo->prepare("SELECT room_number,id,status,hold_type,reason FROM room_operational_holds WHERE room_number IN ({$ph}) AND status='open' ORDER BY room_number,created_at,id");$stmt->execute($numbers);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$append($map,(string)$row['room_number'],['type'=>'operational_hold','id'=>(string)$row['id'],'status'=>'open','message'=>trim((string)$row['reason'])?:('hold '.(string)$row['hold_type'])]);

    $stmt=$pdo->prepare("SELECT room_number,id,discrepancy_type,resolution_status FROM night_audit_items WHERE room_number IN ({$ph}) AND resolution_status<>'resolved' AND discrepancy_type IS NOT NULL AND discrepancy_type<>'' ORDER BY room_number,checked_at,id");$stmt->execute($numbers);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$append($map,(string)$row['room_number'],['type'=>'night_audit','id'=>(string)$row['id'],'status'=>(string)$row['resolution_status'],'message'=>'temuan Night Audit belum diselesaikan: '.(string)$row['discrepancy_type']]);

    $stmt=$pdo->prepare("SELECT room_number,id,action,status FROM smart_lock_jobs WHERE room_number IN ({$ph}) AND status NOT IN ('completed','cancelled') ORDER BY room_number,created_at,id");$stmt->execute($numbers);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$append($map,(string)$row['room_number'],['type'=>'smart_lock','id'=>(string)$row['id'],'status'=>(string)$row['status'],'message'=>strtolower((string)$row['action'])==='grant'?'pemberian smart-lock belum terkonfirmasi':'pencabutan smart-lock belum terkonfirmasi']);
    return $map;
}

/**
 * Derive one canonical physical room status from domain blockers.
 * rooms.status is a persisted projection/cache only; domain lifecycle rows are
 * authoritative.  This helper is intentionally pure so read and mutation paths
 * cannot drift apart again.
 */
function tamasyaDeriveRoomOperationalStatus(array $blockers): string {
    if(!$blockers)return 'available';
    $types=[];
    foreach($blockers as $blocker){
        $type=strtolower(trim((string)($blocker['type']??'')));
        if($type!=='')$types[$type]=true;
    }
    if(isset($types['active_booking']))return 'booked';
    // Housekeeping-only work means the room is physically dirty / in QC. Any
    // engineering, key, security, L&F, audit, smart-lock, or review blocker is a
    // stronger operational hold and remains projected as maintenance/servis.
    if($types && count(array_diff(array_keys($types),['housekeeping']))===0)return 'dirty';
    return 'maintenance';
}

/**
 * Reservation inventory is not the same thing as physical readiness right now.
 * An active stay or Housekeeping turnover may coexist with a future reservation
 * as long as the requested time window does not overlap. Unresolved exceptional
 * domains have no trustworthy release time, so they fail closed and temporarily
 * suppress new reservation inventory until their own resolver completes.
 */
function tamasyaReservationInventoryBlockers(array $blockers): array {
    $active=[];
    foreach($blockers as $b)if(($b['type']??'')==='active_booking')$active[(string)$b['id']]=true;
    return array_values(array_filter($blockers,static function($blocker) use($active): bool {
        $type=strtolower(trim((string)($blocker['type']??'')));
        if(in_array($type,['active_booking','housekeeping'],true))return false;
        // A normally issued key belongs to the current stay, not future inventory.
        // Only ignore it when its register is bound to that same active booking.
        // Missing/override/orphan keys still require their domain resolver.
        if($type==='physical_key'&&($blocker['status']??'')==='issued'&&isset($active[(string)($blocker['bookingId']??'')]))return false;
        return true;
    }));
}

function tamasyaRoomCanAcceptReservationInventory(array $blockers): bool {
    return tamasyaReservationInventoryBlockers($blockers)===[];
}

function tamasyaRoomOperationalStatusMap(PDO $pdo, array $roomNumbers): array {
    $numbers=array_values(array_unique(array_filter(array_map(static fn($n)=>trim((string)$n),$roomNumbers),static fn($n)=>$n!=='')));
    if(!$numbers)return [];
    $blockersByRoom=getRoomOperationalBlockersMap($pdo,$numbers);
    $status=[];
    foreach($numbers as $number)$status[$number]=tamasyaDeriveRoomOperationalStatus($blockersByRoom[$number]??[]);
    return $status;
}

function tamasyaOperationalBlockerGuidance(string $type): array {
    return [
        'active_booking'=>['owner'=>'Front Office','nextAction'=>'selesaikan Checkout atau Pindah Kamar booking aktif'],
        'housekeeping'=>['owner'=>'Housekeeping/QC','nextAction'=>'buka tugas Housekeeping kamar dan selesaikan sampai Siap'],
        'maintenance'=>['owner'=>'Engineering/Maintenance','nextAction'=>'selesaikan work order teknis; setelah itu Housekeeping melakukan QC'],
        'lost_found'=>['owner'=>'Lost & Found','nextAction'=>'catat dan amankan barang dari kamar (Barang sudah diamankan)'],
        'maintenance_review'=>['owner'=>'Manager/Admin','nextAction'=>'review pembatalan Maintenance dan pilih keputusan operasional'],
        'physical_key'=>['owner'=>'Key Control / Front Office','nextAction'=>'kembalikan/temukan/amankan kunci melalui workflow Key Control'],
        'operational_hold'=>['owner'=>'Manager/Security','nextAction'=>'selesaikan incident/safety lalu lepaskan Operational Hold'],
        'night_audit'=>['owner'=>'Night Audit / Manager','nextAction'=>'rekonsiliasi discrepancy Night Audit sampai resolved'],
        'smart_lock'=>['owner'=>'Front Office / Access Control','nextAction'=>'retry/selesaikan job smart-lock sampai terminal'],
        'legacy_alert'=>['owner'=>'Manager/Admin','nextAction'=>'rekonsiliasi alert legacy ke domain record yang benar sebelum kamar dilepas'],
        'alert'=>['owner'=>'Manager/Admin','nextAction'=>'review alert operasional sesuai domain pemiliknya'],
    ][$type]??['owner'=>'Operasional','nextAction'=>'selesaikan lifecycle domain terkait'];
}

function roomOperationalBlockerMessage(string $roomNumber, array $blockers): string {
    if(!$blockers)return '';
    if(count($blockers)===1 && (string)($blockers[0]['type']??'')==='active_booking'){
        $id=(string)($blockers[0]['id']??'-');
        return "Kamar {$roomNumber} sedang ditempati oleh booking {$id} (active). Pemilik: Front Office. Tindakan: selesaikan Checkout atau Pindah Kamar booking aktif.";
    }
    $labels=[];
    foreach($blockers as $blocker){
        $type=(string)($blocker['type']??'operasional');
        $id=(string)($blocker['id']??'-');
        $status=(string)($blocker['status']??'open');
        $typeLabel=[
            'active_booking'=>'booking','housekeeping'=>'housekeeping','maintenance'=>'maintenance',
            'alert'=>'alert','legacy_alert'=>'alert-legacy','lost_found'=>'lost-found','maintenance_review'=>'review-maintenance','operational_hold'=>'hold-operasional','physical_key'=>'kunci-fisik','night_audit'=>'night-audit','smart_lock'=>'smart-lock'
        ][$type]??$type;
        $detail=trim((string)($blocker['message']??''));
        $guide=tamasyaOperationalBlockerGuidance($type);
        $base=$detail!==''?"{$typeLabel} {$id} ({$status}: {$detail})":"{$typeLabel} {$id} ({$status})";
        $labels[]=$base.' → '.$guide['owner'].': '.$guide['nextAction'];
    }
    return "Kamar {$roomNumber} belum dapat tersedia karena masih ada blocker operasional: ".implode('; ',$labels).'.';
}

function assertRoomCanBecomeAvailable(PDO $pdo, string $roomNumber, bool $forUpdate=true, string $excludeTaskId=''): array {
    $blockers=getRoomOperationalBlockers($pdo,$roomNumber,$forUpdate,$excludeTaskId);
    if($blockers)throw new RuntimeException(roomOperationalBlockerMessage($roomNumber,$blockers));
    return $blockers;
}

/**
 * Menghasilkan daftar kamar yang benar-benar boleh dijual/dipindahkan. Query
 * rooms.status='available' saja tidak cukup karena status operasional juga
 * dipengaruhi housekeeping, maintenance, alert, smart-lock, dan booking aktif.
 */
function getOperationallySellableRooms(PDO $pdo, int $limit=0): array {
    // Do not trust the persisted rooms.status cache here. A stale `maintenance`
    // row with zero domain blockers must not disappear from sellable inventory.
    $stmt=$pdo->query("SELECT * FROM rooms ORDER BY CAST(number AS UNSIGNED),number");
    $rows=$stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC)?:[]) : [];
    $roomNumbers=array_values(array_filter(array_map(static fn($row)=>trim((string)($row['number']??'')),$rows),static fn($number)=>$number!==''));
    $blockersByRoom=getRoomOperationalBlockersMap($pdo,$roomNumbers);
    $sellable=[];
    foreach($rows as $row){
        $roomNumber=trim((string)($row['number']??''));
        if($roomNumber==='')continue;
        $blockers=$blockersByRoom[$roomNumber]??[];
        if(tamasyaDeriveRoomOperationalStatus($blockers)==='available'){
            $row['operationalStatus']='available';
            $row['isSellable']=true;
            $sellable[]=$row;
            if($limit>0 && count($sellable)>=max(1,$limit))break;
        }
    }
    return $sellable;
}

/** Source line 3073: lockAvailableRoomForNewBooking */
function lockAvailableRoomForNewBooking($pdo, $roomNumber) {
    $roomNumber = trim((string)$roomNumber);
    if ($roomNumber === '') throw new InvalidArgumentException('Nomor kamar wajib diisi.');
    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1 FOR UPDATE");
    $stmtRoom->execute([$roomNumber]);
    $room = $stmtRoom->fetch();
    if (!$room) throw new RuntimeException("Kamar {$roomNumber} tidak ditemukan.");
    $blockers=getRoomOperationalBlockers($pdo,$roomNumber,true);
    $derived=tamasyaDeriveRoomOperationalStatus($blockers);
    if($derived!=='available'){
        throw new RuntimeException($blockers?roomOperationalBlockerMessage($roomNumber,$blockers):"Kamar {$roomNumber} belum tersedia secara operasional.");
    }
    // Self-heal only through the canonical reconciler; no caller is allowed to
    // choose a persisted room status independently from domain blockers.
    if(strtolower((string)($room['status']??''))!=='available'){
        $projection=tamasyaReconcileRoomOperationalProjection($pdo,['id'=>'projection-reconcile','name'=>'Projection Reconcile'],$roomNumber,'booking-room-lock');
        if(($projection['roomStatus']??'')!=='available')throw new RuntimeException($blockers?roomOperationalBlockerMessage($roomNumber,$blockers):"Kamar {$roomNumber} belum tersedia secara operasional.");
        $room['status']='available';
    }
    return $room;
}

/**
 * Canonical post-resolver reconciliation for any non-financial operational domain.
 * Domain records own their lifecycle; rooms.status is only a projection.  Every
 * resolver can call this after changing its domain state instead of guessing
 * "available"/"maintenance" independently.
 */
function tamasyaReconcileRoomOperationalProjection(PDO $pdo, array $actor, string $roomNumber, string $source='operational-domain-resolver'): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Rekonsiliasi proyeksi kamar wajib berada di transaksi database.');
    $roomNumber=trim($roomNumber);if($roomNumber==='')throw new InvalidArgumentException('Nomor kamar rekonsiliasi wajib diisi.');
    $active=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1 FOR UPDATE");$active->execute([$roomNumber]);$activeBookingId=(string)($active->fetchColumn()?:'');
    if($activeBookingId!==''){
        updateRoomStatusSafely($pdo,$roomNumber,'booked',(string)($actor['id']??''),$source);
        return ['roomNumber'=>$roomNumber,'roomStatus'=>'booked','activeBookingId'=>$activeBookingId,'blockers'=>[]];
    }
    $blockers=getRoomOperationalBlockers($pdo,$roomNumber,true);
    $target=tamasyaDeriveRoomOperationalStatus($blockers);
    updateRoomStatusSafely($pdo,$roomNumber,$target,(string)($actor['id']??''),$source);
    return ['roomNumber'=>$roomNumber,'roomStatus'=>$target,'activeBookingId'=>null,'blockers'=>$blockers];
}

/**
 * Repair persisted room-status projections from domain blockers only.
 * Safe for Admin/Manager derived-state refresh; never accepts a requested status.
 */
function tamasyaReconcileAllRoomOperationalProjections(PDO $pdo, array $actor, string $source='room-projection-repair'): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Rekonsiliasi semua proyeksi kamar wajib berada di transaksi database.');
    $stmt=$pdo->query("SELECT number,status FROM rooms ORDER BY CAST(number AS UNSIGNED),number FOR UPDATE");
    $rows=$stmt?($stmt->fetchAll(PDO::FETCH_ASSOC)?:[]):[];
    $summary=['checked'=>0,'changed'=>0,'rooms'=>[]];
    foreach($rows as $row){
        $roomNumber=trim((string)($row['number']??''));if($roomNumber==='')continue;
        $before=strtolower(trim((string)($row['status']??'')));
        $projection=tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,$source);
        $after=strtolower(trim((string)($projection['roomStatus']??$before)));
        $summary['checked']++;
        if($before!==$after){$summary['changed']++;$summary['rooms'][]=['roomNumber'=>$roomNumber,'before'=>$before,'after'=>$after,'blockers'=>$projection['blockers']??[]];}
    }
    return $summary;
}


/**
 * Menyamakan seluruh channel check-in (web, Telegram, dan client lain) dengan
 * keadaan okupansi server. Fungsi ini sengaja hanya untuk booking ACTIVE;
 * reservasi mendatang tidak boleh mengubah status fisik kamar atau kunci.
 */
function finalizeActiveBookingOperationalState(PDO $pdo, string $bookingId, string $roomNumber, array $staff, string $source='web'): void {
    $bookingId=trim($bookingId);$roomNumber=trim($roomNumber);
    if($bookingId===''||$roomNumber==='')throw new InvalidArgumentException('Booking/kamar check-in tidak valid.');
    $bookingStmt=$pdo->prepare("SELECT id,status,checkIn,checkOut,isOpenEnded,stayMode,scheduledCheckInAt,scheduledCheckOutAt FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");
    $bookingStmt->execute([$bookingId]);$booking=$bookingStmt->fetch(PDO::FETCH_ASSOC);
    if(!$booking||strtolower((string)($booking['status']??''))!=='active')throw new RuntimeException('Finalisasi okupansi hanya untuk booking aktif.');

    $roomStmt=$pdo->prepare("SELECT number FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");
    $roomStmt->execute([$roomNumber]);
    if(!$roomStmt->fetchColumn())throw new RuntimeException('Kamar check-in tidak ditemukan.');

    $settings=getOperationalSettings($pdo);
    $checkinTime=substr((string)($settings['checkin_time']??'14:00:00'),0,8);
    $checkoutTime=substr((string)($settings['checkout_time']??'12:00:00'),0,8);
    try{$bookingWindow=resolveHotelBookingStayWindow($booking,$checkoutTime,$checkinTime);}
    catch(InvalidArgumentException $e){throw new RuntimeException('Jadwal booking aktif tidak valid: '.$e->getMessage());}
    $bookingStartAt=(string)$bookingWindow['startAt'];
    $bookingEndAt=(string)$bookingWindow['endAt'];
    tamasyaR3AssertStayWindowNoOverlap($pdo,$bookingId,$roomNumber,$bookingStartAt,$bookingEndAt,true);

    $checkoutDueAt=(bool)$bookingWindow['isOpenEnded'] ? null : (string)$bookingWindow['endAt'];
    $accessStmt=$pdo->prepare("SELECT access_mode FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");
    $accessStmt->execute([$roomNumber]);
    $accessMode=(string)($accessStmt->fetchColumn()?:'physical');
    if(!in_array($accessMode,['physical','smart','hybrid'],true))$accessMode='physical';

    $pdo->prepare("UPDATE bookings SET actualCheckInAt=COALESCE(actualCheckInAt,CURRENT_TIMESTAMP),checkoutDueAt=COALESCE(checkoutDueAt,?),accessMode=?,keyControlStatus=COALESCE(NULLIF(keyControlStatus,''),'not_issued') WHERE id=?")
        ->execute([$checkoutDueAt,$accessMode,$bookingId]);
    tamasyaReconcileRoomOperationalProjection($pdo,$staff,$roomNumber,$source.'-active-booking');
    $pdo->prepare("INSERT INTO room_access_control (room_number,physical_key_ref,physical_key_status,last_event_at,updated_by,updated_at) VALUES (?,CONCAT('KEY-',?),'secured',CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE physical_key_status='secured',last_event_at=CURRENT_TIMESTAMP,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
        ->execute([$roomNumber,$roomNumber,$staff['id']??null]);
}

/** Source line 3093: lockActiveBookingForRoom */
function lockActiveBookingForRoom($pdo, $roomNumber) {
    $roomNumber = trim((string)$roomNumber);
    $stmtBooking = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber = ? AND status = 'active' LIMIT 1 FOR UPDATE");
    $stmtBooking->execute([$roomNumber]);
    $booking = $stmtBooking->fetch();
    if (!$booking) throw new RuntimeException("Kamar {$roomNumber} tidak mempunyai reservasi aktif.");
    $stmtRoom = $pdo->prepare("SELECT id FROM rooms WHERE number = ? LIMIT 1 FOR UPDATE");
    $stmtRoom->execute([$roomNumber]);
    if (!$stmtRoom->fetchColumn()) throw new RuntimeException("Kamar asal {$roomNumber} tidak ditemukan.");
    return $booking;
}

/**
 * Persisted rooms.updatedSource is VARCHAR(30). Operational lifecycle callers may
 * compose richer source labels (for example room-transfer suffixes), so enforce
 * the schema boundary centrally instead of letting a valid lifecycle fail with
 * SQLSTATE 22001. Long labels retain a readable prefix plus a deterministic hash.
 */
function tamasyaNormalizeRoomUpdateSource(string $source): string {
    $source=trim($source);
    if($source==='')$source='system';
    $source=preg_replace('/[^A-Za-z0-9._:-]+/','-',$source)??$source;
    $source=trim($source,'-');
    if($source==='')$source='system';
    if(strlen($source)<=30)return $source;
    return substr($source,0,21).'-'.substr(hash('sha256',$source),0,8);
}

/** Source line 3105: updateRoomStatusSafely */
function updateRoomStatusSafely($pdo, $roomNumber, $newStatus, $staffId, $source = 'telegram') {
    $allowed = ['available','booked','maintenance','dirty','clean'];
    $roomNumber = trim((string)$roomNumber);
    $newStatus = strtolower(trim((string)$newStatus));
    if (!in_array($newStatus, $allowed, true)) throw new InvalidArgumentException('Status kamar tidak valid.');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1 FOR UPDATE");
        $stmtRoom->execute([$roomNumber]);
        $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);
        if (!$room) throw new RuntimeException("Kamar {$roomNumber} tidak ditemukan.");
        $stmtActive = $pdo->prepare("SELECT id FROM bookings WHERE roomNumber = ? AND status = 'active' LIMIT 1 FOR UPDATE");
        $stmtActive->execute([$roomNumber]);
        $hasActive = (bool)$stmtActive->fetchColumn();
        if ($newStatus === 'booked' && !$hasActive) throw new RuntimeException('Status booked hanya boleh berasal dari reservasi aktif.');
        if ($newStatus !== 'booked' && $hasActive) throw new RuntimeException('Kamar masih memiliki reservasi aktif dan harus tetap berstatus booked.');
        if ($newStatus === 'available') {
            // R3 incident self-heal: legacy checkout-damage yang tiketnya cancelled
            // harus mendapatkan satu review Manager/Admin, bukan terjebak selamanya
            // pada alert room-damage lama. Helper tidak pernah membuka kamar otomatis.
            if(function_exists('tamasyaR3EnsureCancelledCheckoutDamageReviewAlerts')){
                tamasyaR3EnsureCancelledCheckoutDamageReviewAlerts($pdo,[
                    'id'=>$staffId,'name'=>'Lifecycle reconciliation','role'=>null
                ],$roomNumber,$source);
            }
            assertRoomCanBecomeAvailable($pdo,$roomNumber,true);
        }
        $persistedSource=tamasyaNormalizeRoomUpdateSource((string)$source);
        $stmtUpdate = $pdo->prepare("UPDATE rooms SET status = ?, version = version + 1, updatedAt=CURRENT_TIMESTAMP, updatedBy = ?, updatedSource = ? WHERE number = ?");
        $stmtUpdate->execute([$newStatus, $staffId, $persistedSource, $roomNumber]);
        $room['status'] = $newStatus;
        $room['version']=(int)($room['version']??0)+1;
        $room['updatedBy']=$staffId;
        $room['updatedSource']=$persistedSource;
        if ($ownsTransaction) tamasyaFinancialCommit($pdo);
        return $room;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Canonical G0 contract for technical/physical damage creators.
 * Validation must happen before a workflow is allowed to create a maintenance
 * work order or block Housekeeping. This keeps Housekeeping and room-vacancy
 * findings on the same semantic contract without adding a new schema.
 */
function tamasyaRequireTechnicalDamageDetail(string $detail, string $context='Kerusakan'): string {
    $detail=trim((string)preg_replace('/\s+/u',' ',trim($detail)));
    $length=tamasyaStringLength($detail);
    if($length<4)throw new InvalidArgumentException($context.' belum cukup jelas. Tulis apa yang bermasalah dan kondisinya secara bebas, misalnya “AC tidak dingin” atau “kran wastafel bocor”.');
    if($length>1000)throw new InvalidArgumentException($context.' maksimal 1.000 karakter.');

    $normalized=function_exists('mb_strtolower')?mb_strtolower($detail,'UTF-8'):strtolower($detail);
    $normalized=trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',$normalized));
    $tokens=array_values(array_filter(preg_split('/\s+/u',$normalized)?:[],static fn($token)=>$token!==''));

    // R4: quality gate dinamis, BUKAN kamus komponen/gejala.
    // Sistem tidak perlu mengenal AC/TV/kran/sensor/model perangkat tertentu.
    // Ia hanya menolak input yang seluruhnya terdiri dari kata workflow generik.
    $genericOnly=array_fill_keys([
        'ada','ditemukan','indikasi','temuan','masalah','bermasalah','gangguan','kendala',
        'kerusakan','rusak','perbaikan','repair','maintenance','error','issue','problem','broken',
        'perlu','butuh','mohon','tolong','cek','check','periksa','segera','urgent',
        'ini','itu','sini','sana','yang','nya','sangat','cukup','agak','parah','berat','ringan',
        'kamar','room','unit','barang','alat','fasilitas','perangkat','equipment','device','komponen','mati','macet','bocor','tidak','normal','abnormal'
    ],true);
    $meaningful=[];
    foreach($tokens as $token){
        if(isset($genericOnly[$token])||preg_match('/^\d+$/u',$token))continue;
        $meaningful[]=$token;
    }

    // Domain guard: kunci/access dan barang tamu bukan Maintenance.
    if(preg_match('/\b(?:kunci|key)\b.*\b(?:hilang|missing|tidak\s+kembali)\b/u',$normalized)){
        throw new InvalidArgumentException($context.' adalah masalah kunci/access. Gunakan workflow Key Control; Maintenance hanya bila hardware lock benar-benar rusak.');
    }
    if(preg_match('/\b(?:tertinggal|ketinggalan|barang\s+tamu|lost\s+found)\b/u',$normalized)){
        throw new InvalidArgumentException($context.' terlihat seperti Lost & Found. Gunakan workflow barang tertinggal, bukan Maintenance.');
    }

    // Minimal harus berupa frasa dan punya setidaknya satu token bermakna.
    // Contoh yang ditolak: "rusak", "rusak ini", "ada kerusakan", "error parah".
    // Contoh yang diterima tanpa whitelist: "AC rusak", "sensor XJ9 intermittent",
    // "engsel lemari oblak", "panel vendor baru bunyi aneh".
    if(count($tokens)<2 || !$meaningful){
        throw new InvalidArgumentException($context.' terlalu umum. Wajib sebutkan objek/konteks yang bermasalah; deskripsinya bebas. Contoh: “AC rusak”, “TV tidak menyala”, “sensor XJ9 intermittent”, atau “engsel lemari oblak”.');
    }
    return $detail;
}

function tamasyaTechnicalDamageTicketTitle(string $roomNumber, string $detail, string $prefix='HK'): string {
    $short=function_exists('mb_substr')?mb_substr($detail,0,90,'UTF-8'):substr($detail,0,90);
    return trim($prefix.' Kamar '.$roomNumber.': '.$short);
}

/** Source line 3158: applyHousekeepingAction */
function tamasyaHousekeepingCanonicalNotification(array $result, array $staff): array {
    $roomNumber=trim((string)($result['roomNumber']??''));
    $taskStatus=strtolower(trim((string)($result['taskStatus']??'')));
    $staffName=currentStaffLabel($staff);
    if($taskStatus==='ready'){
        return ['message'=>"✅ [HOUSEKEEPING SELESAI] Kamar {$roomNumber} sudah siap jual. Petugas: {$staffName}.",'telegram'=>"✅ *HOUSEKEEPING SELESAI*\n\n🚪 Kamar: *{$roomNumber}*\n👤 Petugas: *{$staffName}*\n📌 Status: *SIAP JUAL*."];
    }
    if($taskStatus==='blocked'){
        return ['message'=>"⚠️ [HOUSEKEEPING] Kamar {$roomNumber} memiliki kerusakan teknis/fisik dan membutuhkan pemeriksaan/perbaikan Engineering. Petugas: {$staffName}.",'telegram'=>"⚠️ *KERUSAKAN / PERLU PERBAIKAN*\n\n🚪 Kamar: *{$roomNumber}*\n👤 Petugas: *{$staffName}*\n📌 Status: *TERBLOKIR — MENUNGGU ENGINEERING*."];
    }
    return ['message'=>"🧹 [HOUSEKEEPING] Pembersihan Kamar {$roomNumber} sedang berjalan. Petugas: {$staffName}.",'telegram'=>"🧹 *PEMBARUAN HOUSEKEEPING*\n\n🚪 Kamar: *{$roomNumber}*\n👤 Petugas: *{$staffName}*\n📌 Status: *PEMBERSIHAN*."];
}

function applyHousekeepingAction(PDO $pdo, array $staff, string $roomNumber, string $action, string $source='web', string $requestedTaskId='', string $damageDetail=''): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'housekeeping/ubah status kamar live');
    $roomNumber=trim($roomNumber);$action=strtolower(trim($action));
    if($roomNumber===''||!in_array($action,['cleaning','clean_available','need_maintenance'],true)){
        throw new InvalidArgumentException('Tindakan housekeeping tidak valid.');
    }
    if(!hasCapability($staff,'perform_housekeeping',['admin','manager','receptionist','cleaning_service','finance'])){
        throw new RuntimeException('Akun ini tidak memiliki izin menjalankan housekeeping.');
    }
    $staffId=trim((string)($staff['id']??''));
    if($staffId==='')throw new RuntimeException('Identitas staf housekeeping tidak valid.');
    // Penting: validasi detail dilakukan SEBELUM transaksi/lock. Menekan tombol
    // “Kerusakan / Perlu Perbaikan” tanpa menjelaskan kerusakan tidak boleh
    // mengubah task, kamar, atau membuat tiket generik.
    $damageDetail=$action==='need_maintenance'?tamasyaRequireTechnicalDamageDetail($damageDetail,'Detail kerusakan Housekeeping'):'';
    $staffName=currentStaffLabel($staff);
    $isHousekeepingSupervisor=in_array(strtolower((string)($staff['role']??'')),['admin','manager'],true);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        requireTelegramRoom($pdo,$roomNumber,true);
        $activeStmt=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' LIMIT 1 FOR UPDATE");
        $activeStmt->execute([$roomNumber]);
        if($activeStmt->fetchColumn())throw new RuntimeException('Kamar masih memiliki booking aktif dan tidak boleh diproses sebagai kamar kosong.');

        $requestedTaskId=trim($requestedTaskId);
        if($requestedTaskId!==''){
            $taskStmt=$pdo->prepare("SELECT * FROM housekeeping_tasks WHERE id=? AND room_number=? LIMIT 1 FOR UPDATE");
            $taskStmt->execute([$requestedTaskId,$roomNumber]);
        }else{
            // ready/completed adalah histori tertutup. Siklus housekeeping baru wajib
            // membuat task baru; jangan pernah membuka ulang task yang sudah siap jual.
            $taskStmt=$pdo->prepare("SELECT * FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed') ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
            $taskStmt->execute([$roomNumber]);
        }
        $task=$taskStmt->fetch(PDO::FETCH_ASSOC);
        $taskCurrentStatus=strtolower(trim((string)($task['status']??'')));
        if($task && in_array($taskCurrentStatus,['ready','completed'],true)){
            throw new RuntimeException('Tugas housekeeping ini sudah selesai/siap jual dan tidak boleh dibuka kembali. Buat tugas baru untuk siklus pembersihan berikutnya.');
        }
        if(!$task&&$action==='clean_available')throw new RuntimeException('Tugas housekeeping tidak ditemukan. Mulai pembersihan terlebih dahulu.');
        if(!$task){
            $taskId=ensureCheckoutHousekeepingTask($pdo,$roomNumber,$staff,$source,'Dibuat dari tindakan housekeeping lapangan.');
            $taskStmt=$pdo->prepare("SELECT * FROM housekeeping_tasks WHERE id=? LIMIT 1 FOR UPDATE");$taskStmt->execute([$taskId]);$task=$taskStmt->fetch(PDO::FETCH_ASSOC);
        }
        if(!$task)throw new RuntimeException('Tugas housekeeping gagal disiapkan.');
        $taskCurrentStatus=strtolower(trim((string)($task['status']??'')));
        $actionAllowedByState=[
            'cleaning'=>['dirty','assigned','cleaning','inspection'],
            'clean_available'=>['cleaning','inspection'],
            'need_maintenance'=>['dirty','assigned','cleaning','inspection','blocked'],
        ];
        if(!in_array($taskCurrentStatus,$actionAllowedByState[$action]??[],true)){
            throw new RuntimeException('Tindakan housekeeping '.$action.' tidak diperbolehkan dari status '.$taskCurrentStatus.'. Ikuti urutan kerja housekeeping atau selesaikan maintenance terlebih dahulu.');
        }
        $assignedStaffId=trim((string)($task['assigned_staff_id']??''));
        if($assignedStaffId!=='' && $assignedStaffId!==$staffId && !$isHousekeepingSupervisor){
            throw new RuntimeException('Tugas housekeeping ini sudah diambil staf lain. Hubungi Manager untuk pengalihan.');
        }

        $maintenanceTicketId=null;$reconciledTaskIds=[];
        if($action==='cleaning'){
            $pdo->prepare("UPDATE housekeeping_tasks SET status='cleaning',assigned_to=?,assigned_staff_id=?,last_action_by_staff_id=?,last_action_source=?,started_at=COALESCE(started_at,CURRENT_TIMESTAMP),completed_at=NULL,completed_by_staff_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$staffName,$staffId,$staffId,$source,(string)$task['id']]);
            $projection=tamasyaReconcileRoomOperationalProjection($pdo,$staff,$roomNumber,$source.'-housekeeping-cleaning');
            $roomStatus=(string)$projection['roomStatus'];$taskStatus='cleaning';
        }elseif($action==='need_maintenance'){
            // Satu temuan teknis Housekeeping adalah satu incident creator yang jelas.
            // Jangan pernah “menempelkan” temuan baru ke tiket aktif lain hanya karena
            // nomor kamarnya sama (mis. AC rusak tidak boleh dianggap sama dengan kran bocor).
            // ID deterministik menjaga retry request yang sama tetap idempotent.
            $maintenanceTicketId='ticket_hk_'.substr(hash('sha256',(string)$task['id'].'|'.$damageDetail),0,40);
            $ticketTitle=tamasyaTechnicalDamageTicketTitle($roomNumber,$damageDetail,'HK');
            $ticketDescription='Temuan Housekeeping: '.$damageDetail.' | Dilaporkan oleh '.currentStaffLabel($staff).'. Workflow ini khusus kerusakan teknis/fisik; bukan barang tertinggal, kunci hilang, atau permintaan amenitas.';
            $ticketStmt=$pdo->prepare("SELECT id,status,description FROM maintenance_tickets WHERE id=? LIMIT 1 FOR UPDATE");
            $ticketStmt->execute([$maintenanceTicketId]);
            $existingIncident=$ticketStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($existingIncident&&in_array(strtolower(trim((string)($existingIncident['status']??''))),['completed','cancelled'],true)){
                throw new RuntimeException('Temuan kerusakan Housekeeping ini sudah memiliki tiket terminal. Muat ulang task sebelum membuat temuan baru.');
            }
            if(!$existingIncident){
                $pdo->prepare("INSERT INTO maintenance_tickets (id,asset_ref,title,description,priority,status,work_type,origin_type,origin_id,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'open','corrective','housekeeping',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
                    ->execute([$maintenanceTicketId,'room:'.$roomNumber,$ticketTitle,$ticketDescription,'high',(string)$task['id'],(string)($staff['id']??'')]);
            }
            tamasyaOperationalLink($pdo,'housekeeping_task',(string)$task['id'],'maintenance_ticket',$maintenanceTicketId,'creates_technical_work_order',$staff);
            // Lock Housekeeping hanya terjadi SETELAH work order yang valid ada. Karena
            // semua berada dalam transaksi yang sama, kegagalan tiket tidak meninggalkan
            // task/kamar dalam status blocked tanpa resolver.
            $pdo->prepare("UPDATE housekeeping_tasks SET status='blocked',assigned_to=?,assigned_staff_id=?,last_action_by_staff_id=?,last_action_source=?,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END,?),completed_at=NULL,completed_by_staff_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$staffName,$staffId,$staffId,$source,'Temuan kerusakan: '.$damageDetail,(string)$task['id']]);
            $duplicateStmt=$pdo->prepare("SELECT id FROM housekeeping_tasks WHERE room_number=? AND id<>? AND status NOT IN ('ready','completed','blocked') ORDER BY created_at,id FOR UPDATE");
            $duplicateStmt->execute([$roomNumber,(string)$task['id']]);$reconciledTaskIds=array_values(array_filter(array_map('strval',$duplicateStmt->fetchAll(PDO::FETCH_COLUMN)?:[])));
            if($reconciledTaskIds){
                $ph=implode(',',array_fill(0,count($reconciledTaskIds),'?'));
                $params=array_merge([$staffId,$staffId,$source],$reconciledTaskIds);
                $pdo->prepare("UPDATE housekeeping_tasks SET status='completed',completed_at=CURRENT_TIMESTAMP,completed_by_staff_id=?,last_action_by_staff_id=?,last_action_source=?,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END,'Direkonsiliasi otomatis: tugas duplikat ditutup dan task blocked dipertahankan.'),updated_at=CURRENT_TIMESTAMP WHERE id IN ($ph)")->execute($params);
            }
            $projection=tamasyaReconcileRoomOperationalProjection($pdo,$staff,$roomNumber,$source.'-housekeeping-damage');
            $roomStatus=(string)$projection['roomStatus'];$taskStatus='blocked';
        }else{
            // Task ditandai ready di transaksi yang sama. Housekeeping hanya menyelesaikan lifecycle-nya sendiri;
            // projection canonical berikutnya menentukan apakah kamar available atau masih ditahan domain lain.
            $pdo->prepare("UPDATE housekeeping_tasks SET status='ready',assigned_to=?,assigned_staff_id=?,completed_by_staff_id=?,last_action_by_staff_id=?,last_action_source=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$staffName,$staffId,$staffId,$staffId,$source,(string)$task['id']]);
            $siblingStmt=$pdo->prepare("SELECT id,status FROM housekeeping_tasks WHERE room_number=? AND id<>? AND status NOT IN ('ready','completed') ORDER BY created_at,id FOR UPDATE");
            $siblingStmt->execute([$roomNumber,(string)$task['id']]);$siblings=$siblingStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
            $blockedSiblings=array_values(array_filter($siblings,static fn($row)=>(string)($row['status']??'')==='blocked'));
            if($blockedSiblings){
                throw new RuntimeException(roomOperationalBlockerMessage($roomNumber,array_map(static fn($row)=>['type'=>'housekeeping','id'=>(string)$row['id'],'status'=>(string)$row['status']],$blockedSiblings)));
            }
            if($siblings&&!$isHousekeepingSupervisor){
                throw new RuntimeException(roomOperationalBlockerMessage($roomNumber,array_map(static fn($row)=>['type'=>'housekeeping','id'=>(string)$row['id'],'status'=>(string)$row['status']],$siblings)));
            }
            if($siblings){
                $reconciledTaskIds=array_values(array_map(static fn($row)=>(string)$row['id'],$siblings));
                $ph=implode(',',array_fill(0,count($reconciledTaskIds),'?'));
                $params=array_merge([$staffId,$staffId,$source],$reconciledTaskIds);
                $pdo->prepare("UPDATE housekeeping_tasks SET status='completed',completed_at=CURRENT_TIMESTAMP,completed_by_staff_id=?,last_action_by_staff_id=?,last_action_source=?,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END,'Direkonsiliasi otomatis: tugas aktif ganda ditutup saat supervisor menetapkan tugas utama ready.'),updated_at=CURRENT_TIMESTAMP WHERE id IN ($ph)")
                    ->execute($params);
            }
            // Housekeeping owns only its own lifecycle. After it becomes ready,
            // derive the physical room projection from *all* domains. A remaining
            // key/L&F/audit/hold blocker keeps the room held without rolling HK back.
            $projection=tamasyaReconcileRoomOperationalProjection($pdo,$staff,$roomNumber,$source.'-housekeeping-ready');
            $roomStatus=(string)$projection['roomStatus'];$taskStatus='ready';
        }
        $housekeepingResult=['roomNumber'=>$roomNumber,'roomStatus'=>$roomStatus,'taskId'=>(string)$task['id'],'taskStatus'=>$taskStatus,'maintenanceTicketId'=>$maintenanceTicketId,'damageDetail'=>$damageDetail?:null,'reconciledTaskIds'=>$reconciledTaskIds];
        $housekeepingNotification=tamasyaHousekeepingCanonicalNotification($housekeepingResult,$staff);
        $housekeepingNotificationId='n_hk_'.substr(hash('sha256',(string)$task['id'].'|'.$taskStatus),0,32);
        $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'system') ON DUPLICATE KEY UPDATE id=id")
            ->execute([$housekeepingNotificationId,(string)$housekeepingNotification['message']]);
        writeRequiredEnterpriseAudit($pdo,$staff,'Memperbarui housekeeping kamar','housekeeping_task',(string)$task['id'],$task,[
            'roomNumber'=>$roomNumber,'action'=>$action,'taskStatus'=>$taskStatus,'roomStatus'=>$roomStatus,'maintenanceTicketId'=>$maintenanceTicketId,'damageDetail'=>$damageDetail?:null,'reconciledTaskIds'=>$reconciledTaskIds
        ],$source);
        logActivity($pdo,'HOUSEKEEPING_ACTION',"Housekeeping {$action} Kamar {$roomNumber}",(string)($staff['id']??''),currentStaffLabel($staff));
        bumpServerRevision($pdo);
        if($owns)tamasyaFinancialCommit($pdo);
        return $housekeepingResult;
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Normalize every Telegram operation id to the database contract (VARCHAR(100)). */
function normalizeTelegramMutationOperationId($operationId): string {
    $raw=trim((string)$operationId);
    if($raw==='')throw new InvalidArgumentException('Operation ID Telegram wajib tersedia.');
    if(strlen($raw)<=100)return $raw;
    $prefix=preg_replace('/[^A-Za-z0-9_-]+/','_',substr($raw,0,24));
    $prefix=trim((string)$prefix,'_')?:'operation';
    return 'tgop_'.$prefix.'_'.substr(hash('sha256',$raw),0,64);
}

/** Build a scoped and deterministic Telegram operation id without exceeding VARCHAR(100). */
function telegramScopedOperationId($baseOperationId, string $scope, $payload=null): string {
    $base=trim((string)$baseOperationId);
    $scope=preg_replace('/[^A-Za-z0-9_-]+/','_',trim($scope));
    $scope=substr(trim((string)$scope,'_')?:'operation',0,24);
    $payloadJson=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    if($payloadJson===false)$payloadJson=serialize($payload);
    return normalizeTelegramMutationOperationId($base.':'.$scope.':'.hash('sha256',(string)$payloadJson));
}

/** Source line 3235: claimTelegramMutation */
function claimTelegramMutation($pdo, $operationId, $staffId, $entityType, $entityId, $payload = null, $action = 'callback') {
    $operationId=normalizeTelegramMutationOperationId($operationId);
    $payloadJson=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    if($payloadJson===false)throw new InvalidArgumentException('Payload operasi Telegram tidak dapat dinormalisasi.');
    $payloadHash=hash('sha256',$payloadJson);
    $action=in_array($action,['callback','message'],true)?$action:'callback';
    try{
        $stmt=$pdo->prepare("INSERT INTO sync_operations (operation_id,staff_id,device_id,entity_type,entity_id,action,payload_hash,status,result_json,created_at,processed_at) VALUES (?,?,'telegram',?,?,?,?, 'processing',NULL,CURRENT_TIMESTAMP,NULL)");
        $stmt->execute([$operationId,(string)$staffId,(string)$entityType,(string)$entityId,$action,$payloadHash]);
        return true;
    }catch(PDOException $insertError){
        $sqlState=(string)$insertError->getCode();
        $driverCode=(int)($insertError->errorInfo[1]??0);
        if($sqlState!=='23000'&&$driverCode!==1062)throw $insertError;
    }
    $stmtExisting=$pdo->prepare("SELECT payload_hash,status,result_json,created_at FROM sync_operations WHERE operation_id=? LIMIT 1 FOR UPDATE");
    $stmtExisting->execute([$operationId]);
    $existing=$stmtExisting->fetch(PDO::FETCH_ASSOC);
    if(!$existing)throw new RuntimeException('Jurnal operasi Telegram tidak dapat diklaim.');
    $existingHash=(string)($existing['payload_hash']??'');
    if($existingHash!==''&&!hash_equals($existingHash,$payloadHash))throw new RuntimeException('ID operasi Telegram digunakan dengan payload berbeda.');
    $existingStatus=(string)($existing['status']??'');
    if($existingStatus==='processed')throw new TelegramDuplicateOperationException('Operasi Telegram ini sudah diproses.');
    if ($existingStatus === 'failed') {
        $stmtRetry=$pdo->prepare("UPDATE sync_operations SET status='processing',result_json=NULL,processed_at=NULL,created_at=CURRENT_TIMESTAMP WHERE operation_id=? AND status='failed'");
        $stmtRetry->execute([$operationId]);
        if($stmtRetry->rowCount()===1)return true;
    }
    if($existingStatus==='processing'){
        $createdAt=strtotime((string)($existing['created_at']??''));
        if($createdAt!==false&&$createdAt<=time()-900){
            $stmtRetry=$pdo->prepare("UPDATE sync_operations SET result_json=NULL,processed_at=NULL,created_at=CURRENT_TIMESTAMP WHERE operation_id=? AND status='processing' AND created_at=?");
            $stmtRetry->execute([$operationId,(string)$existing['created_at']]);
            if($stmtRetry->rowCount()===1)return true;
        }
    }
    throw new RuntimeException('Operasi Telegram yang sama masih sedang diproses.');
}

/** Source line 3257: completeTelegramMutation */
function completeTelegramMutation($pdo, $operationId, $result = null) {
    $operationId=normalizeTelegramMutationOperationId($operationId);
    $resultJson=$result===null?null:json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    if($result!==null&&$resultJson===false)throw new RuntimeException('Hasil operasi Telegram tidak dapat diserialisasi.');
    $stmt=$pdo->prepare("UPDATE sync_operations SET status='processed',result_json=?,processed_at=CURRENT_TIMESTAMP WHERE operation_id=? AND status='processing'");
    $stmt->execute([$resultJson,$operationId]);
    if($stmt->rowCount()===1)return;
    $verify=$pdo->prepare("SELECT status FROM sync_operations WHERE operation_id=? LIMIT 1");
    $verify->execute([$operationId]);
    if((string)$verify->fetchColumn()==='processed')return;
    throw new RuntimeException('Jurnal operasi Telegram gagal diselesaikan.');
}

/** Source line 3264: failTelegramMutation */
function failTelegramMutation($pdo, $operationId, Throwable $error): void {
    try{
        $operationId=normalizeTelegramMutationOperationId($operationId);
        $resultJson=json_encode(['error'=>clientExceptionMessage('Operasi gagal',$error)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$pdo->prepare("UPDATE sync_operations SET status='failed',result_json=?,processed_at=CURRENT_TIMESTAMP WHERE operation_id=? AND status='processing'");
        $stmt->execute([$resultJson,$operationId]);
    }catch(Throwable $ignored){
        error_log('[Telegram Mutation Journal] '.$ignored->getMessage());
    }
}

/** Source line 3275: createTelegramCashTransaction */
function createTelegramCashTransaction($pdo, $operationId, $loggedInStaff, array $transaction, $notificationMessage, $action = 'callback') {
    // Telegram help/binding/status stays available before READY; money does not.
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'transaksi uang via Telegram');
    $operationId = normalizeTelegramMutationOperationId($operationId);
    if (empty($loggedInStaff['id'])) throw new InvalidArgumentException('Identitas operasi Telegram tidak valid.');
    $transactionType = strtolower(trim((string)($transaction['type'] ?? '')));
    $transactionAmount = (float)($transaction['amount'] ?? 0);
    $transactionDate = (string)($transaction['date'] ?? date('Y-m-d'));
    if (!in_array($transactionType, ['income','expense'], true)) throw new InvalidArgumentException('Jenis transaksi Telegram tidak valid.');
    if (!is_finite($transactionAmount) || $transactionAmount <= 0) throw new InvalidArgumentException('Nominal transaksi Telegram harus lebih dari nol.');
    if (trim((string)($transaction['category'] ?? '')) === '' && trim((string)($transaction['categoryId'] ?? '')) === '') throw new InvalidArgumentException('Kategori transaksi Telegram wajib diisi.');
    if (!validIsoDate($transactionDate)) throw new InvalidArgumentException('Tanggal transaksi Telegram tidak valid.');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        // Resolve category/subcategory from the DB master while the write transaction
        // is open. A callback may carry stable IDs, but never becomes authority for
        // display labels or semantic behavior.
        $catalog=tamasyaResolveFinanceCatalogSelection($pdo,array_merge($transaction,['type'=>$transactionType]),true,true);
        $transaction['categoryId']=$catalog['categoryId'];
        $transaction['categorySystemKey']=$catalog['categorySystemKey']!==''?$catalog['categorySystemKey']:null;
        $transaction['category']=$catalog['categoryName'];
        $transaction['subcategoryId']=$catalog['subcategoryId'];
        $transaction['subcategorySystemKey']=$catalog['subcategorySystemKey']!==''?$catalog['subcategorySystemKey']:null;
        $transaction['subcategory']=$catalog['subcategoryName'];
        $transaction['type'] = $transactionType;
        $transaction['transactionKind']=normalizeTransactionKind((string)($transaction['transactionKind']??'manual'));
        if($transaction['transactionKind']==='manual'){
            // Telegram Log Kas is the same financial surface as Web Log Kas.
            // It must not bypass workflow-owned semantic categories (Booking,
            // POS, Payroll, Maintenance) merely because the write came from bot.
            tamasyaManualFinanceEntryPolicy([
                'id'=>$catalog['categoryId'],
                'name'=>$catalog['categoryName'],
                'type'=>$transactionType,
                'system_key'=>$catalog['categorySystemKey'],
            ],'live_operation',$transaction);
        }
        $transaction['amount'] = round($transactionAmount, 2);
        $transaction['date'] = $transactionDate;
        $stableSuffix = substr(hash('sha256', $operationId), 0, 24);
        $transaction['id'] = 't_tg_' . $stableSuffix;
        $transaction['operationId'] = $operationId;
        $transaction['createdBy'] = $transaction['createdBy'] ?? ($loggedInStaff['username'] ?? currentStaffLabel($loggedInStaff));
        $transaction['updatedBy'] = $transaction['updatedBy'] ?? ($loggedInStaff['id'] ?? null);
        $transaction['updatedSource'] = $transaction['updatedSource'] ?? 'telegram';
        $transaction['version'] = max(1, (int)($transaction['version'] ?? 1));
        // transactionKind was canonicalized before the shared Web/Telegram
        // manual-finance policy gate above.
        $transaction['isSystemGenerated'] = (int)($transaction['isSystemGenerated'] ?? 0);

        // Claim the immutable business intent BEFORE resolving current tax rules.
        // A replay of an already-committed Telegram update must return its old
        // receipt even if category display names or tax rules were changed later.
        // Derived/display fields (labels, description, date-at-processing, tax
        // snapshot) are intentionally excluded; operation IDs are generated by
        // the Telegram update/callback flow, not accepted from end-user input.
        $claimPayload=[
            'type'=>$transaction['type'],
            'categoryId'=>$transaction['categoryId'],
            'categorySystemKey'=>$transaction['categorySystemKey'],
            'subcategoryId'=>$transaction['subcategoryId'],
            'subcategorySystemKey'=>$transaction['subcategorySystemKey'],
            'roomNumber'=>trim((string)($transaction['roomNumber']??''))?:null,
            'amount'=>$transaction['amount'],
            'bookingId'=>trim((string)($transaction['bookingId']??''))?:null,
            'bookingSource'=>trim((string)($transaction['bookingSource']??'Direct'))?:'Direct',
            'bankAccountId'=>trim((string)($transaction['bankAccountId']??''))?:null,
            'transactionKind'=>$transaction['transactionKind'],
            'sourceEntity'=>trim((string)($transaction['sourceEntity']??''))?:null,
            'sourceEntityId'=>trim((string)($transaction['sourceEntityId']??''))?:null,
            'isSplitPayment'=>(int)($transaction['isSplitPayment']??0),
            'splitCashAmount'=>round((float)($transaction['splitCashAmount']??0),2),
            'splitTransferAmount'=>round((float)($transaction['splitTransferAmount']??0),2),
            'splitTransferBankAccountId'=>trim((string)($transaction['splitTransferBankAccountId']??''))?:null,
        ];
        claimTelegramMutation($pdo, $operationId, $loggedInStaff['id'], 'telegram_cash', $transaction['id'], $claimPayload, $action);

        // One tax authority for Telegram money mutations: resolve the live rule
        // here, inside the same DB transaction and only for a newly claimed
        // operation. Callers never supply authoritative tax numbers.
        $serverTax=resolveStandaloneTransactionTaxPolicy($pdo,$transaction,$transaction['amount']);
        $transaction['baseAmount']=round((float)$serverTax['baseAmount'],2);
        $transaction['taxAmount']=round((float)$serverTax['taxAmount'],2);
        $transaction['taxRate']=round((float)$serverTax['taxRate'],3);
        $transaction['taxSnapshotStatus']=$serverTax['taxSnapshotStatus'];
        $transaction['taxSource']=$serverTax['taxSource'];
        $transaction['taxRuleId']=$serverTax['taxRuleId'];
        $requiresOpenShift=tamasyaManualTransactionRequiresOpenShift($transaction);
        $shiftSessionId=resolveOpenShiftSessionId($pdo,$loggedInStaff,$requiresOpenShift);
        $transaction['shiftSessionId']=$shiftSessionId;
        tamasyaPostFinancialTransaction($pdo,$transaction,$loggedInStaff,'finance_manual',[
            'requireCatalog'=>true,'lockCatalog'=>true,'source'=>'telegram'
        ]);
        attachTransactionToActorShift($pdo,(string)$transaction['id'],$loggedInStaff,$requiresOpenShift);

        $notificationId = 'n_tg_' . $stableSuffix;
        $stmtNotif = $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,?,0,'finance')");
        $stmtNotif->execute([$notificationId, (string)$notificationMessage, date('Y-m-d H:i:s')]);
        bumpServerRevision($pdo);
        $mutationResult=[
            'transactionId'=>$transaction['id'],
            'baseAmount'=>$transaction['baseAmount'],
            'taxAmount'=>$transaction['taxAmount'],
            'taxRate'=>$transaction['taxRate'],
            'taxSnapshotStatus'=>$transaction['taxSnapshotStatus'],
            'taxSource'=>$transaction['taxSource'],
            'taxRuleId'=>$transaction['taxRuleId'],
        ];
        completeTelegramMutation($pdo, $operationId, $mutationResult);
        if ($ownsTransaction) tamasyaFinancialCommit($pdo);
        return ['duplicate' => false] + $mutationResult;
    } catch (TelegramDuplicateOperationException $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        $stmtExisting = $pdo->prepare('SELECT result_json FROM sync_operations WHERE operation_id = ? LIMIT 1');
        $stmtExisting->execute([$operationId]);
        $result = json_decode((string)$stmtExisting->fetchColumn(), true);
        if(!is_array($result))$result=[];
        $transactionId=(string)($result['transactionId'] ?? ('t_tg_' . $stableSuffix));
        // Older successful receipts may only contain transactionId. Read the
        // canonical row so retries still render the exact committed tax snapshot.
        if(!array_key_exists('baseAmount',$result) || !array_key_exists('taxAmount',$result) || !array_key_exists('taxRate',$result)){
            $stmtTx=$pdo->prepare('SELECT baseAmount,taxAmount,taxRate,taxSnapshotStatus,taxSource,taxRuleId FROM transactions WHERE id=? LIMIT 1');
            $stmtTx->execute([$transactionId]);
            $row=$stmtTx->fetch(PDO::FETCH_ASSOC)?:[];
            $result=array_merge($result,$row);
        }
        return [
            'duplicate'=>true,
            'transactionId'=>$transactionId,
            'baseAmount'=>(float)($result['baseAmount']??0),
            'taxAmount'=>(float)($result['taxAmount']??0),
            'taxRate'=>(float)($result['taxRate']??0),
            'taxSnapshotStatus'=>$result['taxSnapshotStatus']??null,
            'taxSource'=>$result['taxSource']??null,
            'taxRuleId'=>$result['taxRuleId']??null,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Source line 3359: requireTelegramFinancialCategoryPair */
function requireTelegramFinancialCategoryPair(PDO $pdo, string $categoryId, string $subcategoryId, string $expectedType): array {
    $expectedType = strtolower(trim($expectedType));
    if (!in_array($expectedType, ['income','expense'], true)) {
        throw new InvalidArgumentException('Jenis kategori transaksi tidak valid.');
    }
    $stmt = $pdo->prepare("SELECT c.id AS category_id,c.name AS category_name,c.type,
            s.id AS subcategory_id,s.name AS subcategory_name
        FROM categories c
        JOIN subcategories s ON s.id=? AND s.category_id=c.id AND s.is_active=1
        WHERE c.id=? AND c.type=? AND c.is_active=1 LIMIT 1");
    $stmt->execute([trim($subcategoryId), trim($categoryId), $expectedType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('Kategori/subkategori tidak cocok dengan jenis transaksi di database.');
    }
    return $row;
}

/**
 * FIX25 room-type master bridge. rooms.type remains the operational snapshot used
 * by Booking/Room/Telegram/reporting/sync, but new or changed labels come from
 * active subcategories under the Finance category bound to semantic room_rental.
 * The Finance category name is never inspected, so renaming the parent is safe.
 * No dedicated room_types table is required.
 */
function tamasyaRoomTypeMasterState(PDO $pdo, bool $forUpdate = false): array {
    $roomRoot=tamasyaFinanceSemanticBinding($pdo,'room_rental',$forUpdate);
    if(!$roomRoot)return ['binding'=>null,'configured'=>false,'active'=>false,'items'=>[]];
    $rootActive=(int)($roomRoot['is_active']??0)===1 && strtolower(trim((string)($roomRoot['type']??'')))==='income';
    $sql="SELECT id,name,is_active FROM subcategories WHERE category_id=? ORDER BY name,id".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute([(string)$roomRoot['category_id']]);
    $all=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $rows=[];
    if($rootActive){
        foreach($all as $row){
            if((int)($row['is_active']??0)!==1)continue;
            $name=trim((string)($row['name']??''));
            if($name==='' || mb_strlen($name)>50)continue;
            $rows[]=[
                'id'=>(string)($row['id']??''),
                'name'=>$name,
                'is_active'=>1,
                'category_id'=>(string)$roomRoot['category_id'],
                'source'=>'finance_room_revenue_subcategory'
            ];
        }
    }
    return [
        'binding'=>$roomRoot,
        // Once any child row exists, the room-type master is considered configured.
        // An inactive parent also fails closed instead of silently reopening free text.
        'configured'=>(count($all)>0 || !$rootActive),
        'active'=>$rootActive,
        'items'=>$rows,
    ];
}

function tamasyaRoomTypeMasterCatalog(PDO $pdo, bool $forUpdate = false): array {
    return tamasyaRoomTypeMasterState($pdo,$forUpdate)['items'];
}

function requireActiveRoomTypeCatalog(PDO $pdo, string $roomType, bool $forUpdate = false, bool $createIfMissing = false): array {
    $roomType=trim($roomType);
    if($roomType==='')throw new InvalidArgumentException('Tipe kamar wajib diisi.');
    if(mb_strlen($roomType)>50)throw new InvalidArgumentException('Tipe kamar maksimal 50 karakter.');

    $state=tamasyaRoomTypeMasterState($pdo,$forUpdate);
    $catalog=$state['items'];
    // Compatibility boundary: only a truly unconfigured semantic parent (no child
    // rows yet) may keep the legacy free-text setup. Once child rows exist, even
    // if every child is inactive, writes fail closed to active catalog choices.
    if(!$state['configured']){
        return ['id'=>'legacy_type_'.substr(hash('sha256',strtolower($roomType)),0,40),'name'=>$roomType,'is_active'=>1,'source'=>'legacy_rooms_type_fallback'];
    }
    if(!$state['active'])throw new InvalidArgumentException('Kategori yang dipakai sebagai Pendapatan kamar tidak aktif. Aktifkan kembali sebelum memilih tipe kamar.');
    foreach($catalog as $row){
        if(mb_strtolower(trim((string)$row['name']))===mb_strtolower($roomType))return $row;
    }
    throw new InvalidArgumentException('Tipe kamar tidak valid. Pilih subkategori aktif pada kategori yang dipakai sebagai Pendapatan kamar.');
}

/** Source line 3377: requireTelegramRoom */
function requireTelegramRoom(PDO $pdo, string $roomNumber, bool $forUpdate = false): array {
    $roomNumber = trim($roomNumber);
    if ($roomNumber === '') throw new InvalidArgumentException('Nomor kamar wajib diisi.');
    $sql = "SELECT * FROM rooms WHERE number=? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roomNumber]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) throw new RuntimeException("Kamar {$roomNumber} tidak ditemukan di database server.");
    return $room;
}

/** Source line 3390: checkoutAccessSnapshot */
function checkoutAccessSnapshot(PDO $pdo, array $booking, bool $forUpdate = true): array {
    $roomNumber = trim((string)($booking['roomNumber'] ?? ''));
    if ($roomNumber === '') throw new InvalidArgumentException('Nomor kamar checkout tidak valid.');
    $sql = "SELECT * FROM room_access_control WHERE room_number=? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roomNumber]);
    $access = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'room_number'=>$roomNumber,
        'access_mode'=>(string)($booking['accessMode'] ?? 'physical'),
        'physical_key_ref'=>'KEY-'.$roomNumber,
        'physical_key_status'=>'secured',
        'smart_lock_enabled'=>0
    ];
    $mode = in_array((string)($access['access_mode'] ?? ''), ['physical','smart','hybrid'], true)
        ? (string)$access['access_mode'] : 'physical';
    $physicalOutstanding = in_array($mode,['physical','hybrid'],true)
        && (in_array((string)($booking['keyControlStatus'] ?? 'not_issued'),['issued','missing','override'],true)
            || (string)($access['physical_key_status'] ?? 'secured') === 'issued');
    return ['access'=>$access,'mode'=>$mode,'physicalOutstanding'=>$physicalOutstanding];
}

/** Source line 3411: normalizeCheckoutKeyDisposition */
function normalizeCheckoutKeyDisposition(?string $value): string {
    $value = strtolower(trim((string)$value));
    return in_array($value,['returned','missing','not_required'],true) ? $value : 'unknown';
}

/** Source line 3416: ensureCheckoutHousekeepingTask */
function ensureCheckoutHousekeepingTask(PDO $pdo, string $roomNumber, array $staff, string $source, string $note = ''): string {
    $stmt = $pdo->prepare("SELECT id FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed') ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$roomNumber]);
    $existing = trim((string)$stmt->fetchColumn());
    if ($existing !== '') return $existing;
    $id = generateServerId('hk');
    $notes = trim($note) ?: ('Otomatis dibuat saat check-out melalui '.$source);
    $pdo->prepare("INSERT INTO housekeeping_tasks
        (id,room_number,priority,status,checklist,notes,created_by,created_at,updated_at)
        VALUES (?,?,'high','dirty','Seprai, kamar mandi, amenitas, lantai',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
        ->execute([$id,$roomNumber,$notes,(string)($staff['id'] ?? '')]);
    return $id;
}

/** Source line 3430: closeVacancyReportsForCheckout */
function closeVacancyReportsForCheckout(PDO $pdo, string $bookingId, string $operationId, array $staff): void {
    $pdo->prepare("UPDATE room_vacancy_reports
        SET status='checkout_completed',reviewed_by=COALESCE(reviewed_by,?),reviewed_by_name=COALESCE(reviewed_by_name,?),
            reviewed_at=COALESCE(reviewed_at,CURRENT_TIMESTAMP),checkout_operation_id=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP
        WHERE booking_id=? AND status IN ('pending','verified')")
        ->execute([(string)($staff['id'] ?? ''),currentStaffLabel($staff),$operationId,$bookingId]);

    // Satu booking dapat memiliki beberapa laporan lapangan. Setelah checkout final,
    // alert kamar kosong untuk booking tersebut selesai secara atomik.
    $alertId='alert_vacancy_'.substr(hash('sha256',$bookingId),0,40);
    $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([(string)($staff['id'] ?? ''),$alertId]);
}

/** Source line 3444: checkoutVacancyFindings */
function checkoutVacancyFindings(PDO $pdo, string $bookingId, bool $forUpdate=true): array {
    $sql="SELECT id,room_number,belongings_status,belongings_detail,damage_status,damage_detail,notes,reported_by_name,reported_at FROM room_vacancy_reports WHERE booking_id=? AND status IN ('pending','verified') ORDER BY reported_at,id".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$bookingId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $hasBelongings=false;$hasDamage=false;$notes=[];$belongingsDetails=[];$damageDetails=[];
    foreach($rows as $row){
        $rowBelongings=strtolower((string)($row['belongings_status']??''))==='found';
        $rowDamage=strtolower((string)($row['damage_status']??''))==='found';
        if($rowBelongings)$hasBelongings=true;if($rowDamage)$hasDamage=true;
        $note=trim((string)($row['notes']??''));if($note!=='')$notes[]=$note;
        if($rowBelongings){$detail=trim((string)($row['belongings_detail']??''));if($detail==='')$detail=$note;if($detail!=='')$belongingsDetails[]=$detail;}
        if($rowDamage){$detail=trim((string)($row['damage_detail']??''));if($detail==='')$detail=$note;if($detail!=='')$damageDetails[]=$detail;}
    }
    return [
        'rows'=>$rows,'hasBelongings'=>$hasBelongings,'hasDamage'=>$hasDamage,
        'notes'=>array_values(array_unique($notes)),
        'belongingsDetails'=>array_values(array_unique($belongingsDetails)),
        'damageDetails'=>array_values(array_unique($damageDetails)),
        // Compatibility aliases for older internal callers/tests only.
        'belongingsNotes'=>array_values(array_unique($belongingsDetails)),
        'damageNotes'=>array_values(array_unique($damageDetails)),
    ];
}

/** Source line 3457: createCheckoutFindingSafeguards */
function createCheckoutFindingSafeguards(PDO $pdo, array $staff, string $bookingId, string $roomNumber, array $findings, string $incidentRef=''): array {
    $result=['lostFoundAlertId'=>null,'lostFoundItemId'=>null,'damageAlertId'=>null,'maintenanceTicketId'=>null];
    $allNotes=implode(' | ',array_slice((array)($findings['notes']??[]),0,5));
    $belongingsDetails=implode(' | ',array_slice((array)($findings['belongingsDetails']??$findings['belongingsNotes']??[]),0,5));
    $damageDetails=implode(' | ',array_slice((array)($findings['damageDetails']??$findings['damageNotes']??[]),0,5));
    $incidentRef=trim($incidentRef);

    if(!empty($findings['hasBelongings'])){
        // Fresh R4 creators must provide a meaningful item description. Legacy data
        // migrated from R3 may only have notes, therefore the fallback is limited to
        // historical source data and still passes the canonical Lost & Found gate.
        $itemDescription=$belongingsDetails!==''?$belongingsDetails:$allNotes;
        $itemDescription=tamasyaRequireLostFoundItemDetail($itemDescription,'Detail barang Lost & Found');
        $hash=substr(hash('sha256',$bookingId),0,40);
        $baseCaseId='lf_checkout_'.$hash;
        if($incidentRef!=='' && tamasyaTableExists($pdo,'lost_found_items')){
            $existing=tamasyaLostFoundByIdentifier($pdo,$baseCaseId,true);
            $state=strtolower(trim((string)($existing['custody_status']??'')));
            if($existing && !in_array($state,['found','secured','guest_notified'],true))$hash=substr(hash('sha256',$bookingId.'|lostfound-followup|'.$incidentRef),0,40);
        }
        $caseId='lf_checkout_'.$hash;$alertId='alert_lost_found_'.$hash;
        $case=tamasyaUpsertLostFoundCase($pdo,$staff,$caseId,$alertId,$bookingId,$roomNumber,$itemDescription,'room_vacancy_report',$incidentRef,'checkout-lost-found');
        $message="Kamar {$roomNumber}: barang tamu ditemukan saat checkout. Barang: {$itemDescription}. Amankan, beri label/lokasi custody, lalu hubungi tamu.";
        $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,entity_type,entity_id,created_at) VALUES (?,'warning',?,'Barang tamu tertinggal',?,'lost_found_item',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE source=VALUES(source),message=VALUES(message),entity_type='lost_found_item',entity_id=VALUES(entity_id),acknowledged_by=NULL,acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
            ->execute([$alertId,'lost-found:'.$roomNumber,$message,$caseId]);
        foreach((array)($findings['rows']??[]) as $row){if(strtolower((string)($row['belongings_status']??''))==='found')tamasyaOperationalLink($pdo,'room_vacancy_report',(string)($row['id']??$incidentRef),'lost_found_item',$caseId,'creates_lost_found_case',$staff);}
        $result['lostFoundAlertId']=$alertId;$result['lostFoundItemId']=$caseId;
    }

    if(!empty($findings['hasDamage'])){
        $damageDetail=$damageDetails!==''?$damageDetails:$allNotes;
        $damageDetail=tamasyaRequireTechnicalDamageDetail($damageDetail,'Detail kerusakan checkout');
        $incidentHash=substr(hash('sha256',$bookingId),0,40);
        if($incidentRef!==''){
            $baseTicketId='ticket_checkout_damage_'.$incidentHash;
            $baseStmt=$pdo->prepare("SELECT status FROM maintenance_tickets WHERE id=? LIMIT 1 FOR UPDATE");$baseStmt->execute([$baseTicketId]);
            $baseStatus=strtolower(trim((string)($baseStmt->fetchColumn()?:'')));
            if(in_array($baseStatus,['completed','cancelled'],true))$incidentHash=substr(hash('sha256',$bookingId.'|damage-followup|'.$incidentRef),0,40);
        }
        $alertId='alert_room_damage_'.$incidentHash;$ticketId='ticket_checkout_damage_'.$incidentHash;
        $title=tamasyaTechnicalDamageTicketTitle($roomNumber,$damageDetail,'Checkout');
        $message="Kamar {$roomNumber}: kerusakan ditemukan saat checkout. Detail: {$damageDetail}. Kamar tetap maintenance sampai work order selesai dan Housekeeping/QC lulus.";
        $pdo->prepare("INSERT INTO maintenance_tickets (id,asset_ref,title,description,priority,status,work_type,origin_type,origin_id,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'open','corrective','vacancy_checkout',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),priority='urgent',work_type='corrective',origin_type='vacancy_checkout',origin_id=VALUES(origin_id),updated_at=CURRENT_TIMESTAMP")
            ->execute([$ticketId,'room:'.$roomNumber,$title,$damageDetail,'urgent',$incidentRef?:$bookingId,(string)($staff['id']??'')]);
        $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,entity_type,entity_id,created_at) VALUES (?,'critical',?,'Kerusakan kamar saat checkout',?,'maintenance_ticket',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE source=VALUES(source),message=VALUES(message),entity_type='maintenance_ticket',entity_id=VALUES(entity_id),acknowledged_by=NULL,acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
            ->execute([$alertId,'room-damage:'.$roomNumber,$message,$ticketId]);
        foreach((array)($findings['rows']??[]) as $row){if(strtolower((string)($row['damage_status']??''))==='found')tamasyaOperationalLink($pdo,'room_vacancy_report',(string)($row['id']??$incidentRef),'maintenance_ticket',$ticketId,'creates_technical_work_order',$staff);}
        $result['damageAlertId']=$alertId;$result['maintenanceTicketId']=$ticketId;
    }
    return $result;
}

/** Source line 3484: finalizeCheckoutOperationalLifecycle */
function finalizeCheckoutOperationalLifecycle(PDO $pdo, array $staff, array $booking, string $source, array $options = []): array {
    $bookingId = trim((string)($booking['id'] ?? ''));
    $roomNumber = trim((string)($booking['roomNumber'] ?? ''));
    if ($bookingId === '' || $roomNumber === '') throw new InvalidArgumentException('Booking checkout tidak lengkap.');
    if (!$pdo->inTransaction()) throw new RuntimeException('Lifecycle checkout wajib dijalankan di dalam transaksi database.');

    $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");
    $roomStmt->execute([$roomNumber]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) throw new RuntimeException('Kamar checkout tidak ditemukan.');

    $accessContext = checkoutAccessSnapshot($pdo,$booking,true);
    $access = $accessContext['access'];
    $accessMode = $accessContext['mode'];
    $physicalOutstanding = !empty($accessContext['physicalOutstanding']);
    $keyDisposition = normalizeCheckoutKeyDisposition($options['keyDisposition'] ?? null);
    $keyReason = trim((string)($options['keyReason'] ?? ''));
    $checkoutMode = strtolower(trim((string)($options['checkoutMode'] ?? 'normal')));
    if (!in_array($checkoutMode,['normal','without_notice','field_verified','manager_override'],true)) $checkoutMode='normal';
    $operationId = trim((string)($options['operationId'] ?? '')) ?: generateServerId('op_checkout');
    $finalStatus = strtolower(trim((string)($options['finalStatus'] ?? 'completed')));
    if (!in_array($finalStatus,['completed','cancelled'],true)) throw new InvalidArgumentException('Status final lifecycle kamar tidak valid.');
    $vacancyReportId = trim((string)($options['vacancyReportId'] ?? ''));
    if($checkoutMode==='field_verified'&&$vacancyReportId===''){
        throw new InvalidArgumentException('Checkout hasil laporan lapangan wajib membawa ID laporan kamar kosong.');
    }
    if($vacancyReportId!==''){
        $reportStmt=$pdo->prepare("SELECT id,booking_id,room_number,status FROM room_vacancy_reports WHERE id=? LIMIT 1 FOR UPDATE");
        $reportStmt->execute([$vacancyReportId]);
        $selectedReport=$reportStmt->fetch(PDO::FETCH_ASSOC);
        if(!$selectedReport)throw new RuntimeException('Laporan kamar kosong untuk checkout tidak ditemukan.');
        if(!hash_equals($bookingId,(string)$selectedReport['booking_id'])||trim((string)$selectedReport['room_number'])!==$roomNumber){
            throw new RuntimeException('Laporan kamar kosong tidak terkait dengan booking dan kamar yang sedang di-checkout.');
        }
        if(!in_array((string)$selectedReport['status'],['pending','verified'],true)){
            throw new RuntimeException('Laporan kamar kosong sudah selesai atau ditolak dan tidak dapat digunakan untuk checkout.');
        }
    }
    $vacancyFindings=checkoutVacancyFindings($pdo,$bookingId,true);

    if ($physicalOutstanding) {
        if ($keyDisposition === 'returned') {
            insertRoomKeyEvent($pdo,$staff,$roomNumber,$bookingId,'returned_at_checkout',$accessMode,$access['physical_key_ref']??null,$booking['smartLockCodeLast4']??null,'returned',$keyReason ?: 'Kunci diterima atau ditemukan saat check-out.');
        } elseif ($keyDisposition === 'missing') {
            if (tamasyaStringLength($keyReason) < 5) throw new InvalidArgumentException('Alasan kunci belum kembali/hilang minimal 5 karakter.');
            insertRoomKeyEvent($pdo,$staff,$roomNumber,$bookingId,'missing_at_checkout',$accessMode,$access['physical_key_ref']??null,$booking['smartLockCodeLast4']??null,'missing',$keyReason);
            $alertId='alert_key_missing_'.substr(hash('sha256',$bookingId),0,40);
            $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at)
                VALUES (?,'critical',?,'Kunci kamar belum kembali',?,CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE message=VALUES(message),acknowledged_by=NULL,acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                ->execute([$alertId,'key-missing:'.$roomNumber,"Kamar {$roomNumber}: {$keyReason}. Dicatat oleh ".currentStaffLabel($staff)]);
        } else {
            throw new RuntimeException('Status kunci fisik wajib dipilih: dikembalikan/ditemukan atau belum kembali/hilang.');
        }
    } else {
        $keyDisposition='not_required';
    }

    $cancelledGrantJobIds=cancelObsoleteSmartLockGrantJobs($pdo,$staff,$bookingId,$roomNumber,'Grant smart-lock dibatalkan karena booking memasuki checkout/pembatalan aktif.',$source);
    $smartLockJobId=null;
    if (in_array($accessMode,['smart','hybrid'],true) && !empty($booking['smartLockCodeHash'])) {
        // Hanya jurnal job yang dibuat di dalam transaksi. Panggilan jaringan ke
        // bridge dilakukan setelah commit agar DB lock tidak menunggu jaringan dan
        // revoke eksternal tidak terjadi bila transaksi checkout harus rollback.
        $smartLockJobId=queueSmartLockBridgeJob($pdo,$access,[
            'action'=>'revoke','roomNumber'=>$roomNumber,'bookingId'=>$bookingId,'deviceId'=>$access['smart_lock_device_id']??null,
            'accessMode'=>$accessMode,'physicalKeyRef'=>$access['physical_key_ref']??('KEY-'.$roomNumber),
            'credentialLast4'=>$booking['smartLockCodeLast4']??null,'bookingStateMode'=>'booking','physicalKeyDisposition'=>$keyDisposition,
            'reason'=>'Pencabutan PIN setelah checkout.','source'=>$source,'actor'=>tamasyaSmartLockActorSnapshot($staff)
        ]);
        insertRoomKeyEvent($pdo,$staff,$roomNumber,$bookingId,'smart_code_revoke_queued_at_checkout',$accessMode,$access['physical_key_ref']??null,$booking['smartLockCodeLast4']??null,'pending','Pencabutan PIN dijadwalkan setelah commit check-out.');
    }

    $bookingKeyStatus = $smartLockJobId
        ? 'pending_smart_revoke'
        : ($keyDisposition === 'missing'
            ? 'missing'
            : (($physicalOutstanding || ($booking['keyControlStatus']??'not_issued')!=='not_issued') ? 'returned' : 'not_issued'));
    $physicalKeyStatus = $keyDisposition === 'missing' ? 'missing' : 'secured';
    $keyReturnedAt = $bookingKeyStatus === 'returned' ? date('Y-m-d H:i:s') : null;
    $keyReturnedBy = in_array($bookingKeyStatus,['returned','pending_smart_revoke'],true) ? (string)($staff['id'] ?? '') : null;
    $clearCredential = $smartLockJobId ? 0 : 1;

    $stmtBooking=$pdo->prepare("UPDATE bookings SET status=?,actualCheckOutAt=CURRENT_TIMESTAMP,lateCheckoutStatus=?,
        keyControlStatus=?,keyReturnedAt=?,keyReturnedBy=?,
        smartLockCodeHash=IF(?=1,NULL,smartLockCodeHash),smartLockCodeLast4=IF(?=1,NULL,smartLockCodeLast4),
        smartLockValidFrom=IF(?=1,NULL,smartLockValidFrom),smartLockValidUntil=IF(?=1,NULL,smartLockValidUntil),
        version=version+1,updatedBy=?,updatedSource=? WHERE id=? AND status='active'");
    $stmtBooking->execute([$finalStatus,$finalStatus,$bookingKeyStatus,$keyReturnedAt,$keyReturnedBy,$clearCredential,$clearCredential,$clearCredential,$clearCredential,(string)($staff['id']??''),$source,$bookingId]);
    if($stmtBooking->rowCount()!==1) throw new RuntimeException('Booking sudah berubah sebelum checkout diselesaikan.');
    tamasyaSyncGuestServiceBookingLifecycle($pdo,$staff,$bookingId,$finalStatus,$roomNumber,$source.'-guest-service');

    $accessBookingId=$smartLockJobId ? $bookingId : null;
    $pdo->prepare("INSERT INTO room_access_control
        (room_number,access_mode,physical_key_ref,physical_key_status,current_booking_id,smart_lock_enabled,last_event_at,updated_by,updated_at)
        VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE physical_key_status=VALUES(physical_key_status),current_booking_id=VALUES(current_booking_id),last_event_at=CURRENT_TIMESTAMP,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
        ->execute([$roomNumber,$accessMode,$access['physical_key_ref']??('KEY-'.$roomNumber),$physicalKeyStatus,$accessBookingId,!empty($access['smart_lock_enabled'])?1:0,(string)($staff['id']??'')]);
    $housekeepingNote=$checkoutMode==='without_notice' ? 'Otomatis: tamu pergi tanpa melapor; periksa barang tertinggal dan kondisi kamar.' : ($finalStatus==='cancelled' ? 'Otomatis dibuat saat booking aktif dihentikan/dibatalkan.' : 'Otomatis dibuat saat check-out.');
    if(!empty($vacancyFindings['hasBelongings']))$housekeepingNote.=' ADA BARANG TERTINGGAL: amankan dan beri label sebelum tugas dinyatakan siap.';
    if(!empty($vacancyFindings['hasDamage']))$housekeepingNote.=' ADA INDIKASI KERUSAKAN: kamar tidak boleh dijual sampai tiket maintenance selesai.';
    $housekeepingTaskId=ensureCheckoutHousekeepingTask($pdo,$roomNumber,$staff,$source,$housekeepingNote);
    $findingSafeguards=createCheckoutFindingSafeguards($pdo,$staff,$bookingId,$roomNumber,$vacancyFindings);
    // Source-of-truth domain sudah lengkap (HK/L&F/Maintenance/Key/etc.).
    // rooms.status hanya projection dan wajib diturunkan dari blocker tersebut.
    $roomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$staff,$roomNumber,$source.'-checkout-projection');

    if ($vacancyReportId !== '') {
        $pdo->prepare("UPDATE room_vacancy_reports SET status='checkout_completed',reviewed_by=?,reviewed_by_name=?,reviewed_at=COALESCE(reviewed_at,CURRENT_TIMESTAMP),checkout_operation_id=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND booking_id=?")
            ->execute([(string)($staff['id']??''),currentStaffLabel($staff),$operationId,$vacancyReportId,$bookingId]);
    }
    closeVacancyReportsForCheckout($pdo,$bookingId,$operationId,$staff);
    writeRequiredEnterpriseAudit($pdo,$staff,$finalStatus==='cancelled' ? 'Pembatalan booking aktif terselesaikan' : 'Checkout operasional terselesaikan','booking',$bookingId,null,[
        'roomNumber'=>$roomNumber,'source'=>$source,'checkoutMode'=>$checkoutMode,'keyDisposition'=>$keyDisposition,
        'vacancyReportId'=>$vacancyReportId?:null,'housekeepingTaskId'=>$housekeepingTaskId,'cancelledGrantJobIds'=>$cancelledGrantJobIds
    ],$source);
    logActivity($pdo,$finalStatus==='cancelled' ? 'ACTIVE_BOOKING_CANCELLED' : 'CHECKOUT_OPERATIONAL_FINALIZED',($finalStatus==='cancelled' ? "Pembatalan aktif" : "Checkout")." Kamar {$roomNumber}; mode {$checkoutMode}; kunci {$keyDisposition}",(string)($staff['id']??''),currentStaffLabel($staff));

    return [
        'room'=>$room,'roomNumber'=>$roomNumber,'keyDisposition'=>$keyDisposition,'checkoutMode'=>$checkoutMode,'finalStatus'=>$finalStatus,
        'housekeepingTaskId'=>$housekeepingTaskId,'operationId'=>$operationId,'smartLockJobId'=>$smartLockJobId,'cancelledGrantJobIds'=>$cancelledGrantJobIds,
        'vacancyFindings'=>['hasBelongings'=>(bool)$vacancyFindings['hasBelongings'],'hasDamage'=>(bool)$vacancyFindings['hasDamage']],
        'findingSafeguards'=>$findingSafeguards,'roomProjection'=>$roomProjection
    ];
}

/**
 * Memindahkan tamu yang SUDAH check-in sebagai satu lifecycle operasional.
 *
 * Pindah kamar aktif tidak boleh diperlakukan sebagai edit field roomNumber
 * biasa. Kunci/PIN kamar lama, housekeeping, status fisik kedua kamar, dan
 * access-control harus berubah dalam transaksi database yang sama. Panggilan
 * jaringan smart-lock tetap diproses setelah commit melalui job tertunda.
 */
function finalizeActiveRoomTransferOperationalLifecycle(PDO $pdo, array $staff, array $oldBooking, string $newRoomNumber, string $source, array $options = []): array {
    if (!$pdo->inTransaction()) throw new RuntimeException('Lifecycle pindah kamar aktif wajib dijalankan di dalam transaksi database.');

    $bookingId=trim((string)($oldBooking['id']??''));
    $oldRoomNumber=trim((string)($oldBooking['roomNumber']??''));
    $newRoomNumber=trim($newRoomNumber);
    if($bookingId===''||$oldRoomNumber===''||$newRoomNumber==='')throw new InvalidArgumentException('Booking/kamar untuk perpindahan aktif tidak lengkap.');
    if($oldRoomNumber===$newRoomNumber)throw new InvalidArgumentException('Kamar tujuan harus berbeda dari kamar asal.');
    if(strtolower((string)($oldBooking['status']??''))!=='active')throw new RuntimeException('Lifecycle pindah kamar operasional hanya untuk tamu yang sudah check-in.');

    $oldRoomStmt=$pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");
    $oldRoomStmt->execute([$oldRoomNumber]);
    $oldRoom=$oldRoomStmt->fetch(PDO::FETCH_ASSOC);
    if(!$oldRoom)throw new RuntimeException('Kamar asal perpindahan tidak ditemukan.');
    $newRoomStmt=$pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");
    $newRoomStmt->execute([$newRoomNumber]);
    $newRoom=$newRoomStmt->fetch(PDO::FETCH_ASSOC);
    if(!$newRoom)throw new RuntimeException('Kamar tujuan perpindahan tidak ditemukan.');

    // Booking aktif sudah lebih dulu diubah ke kamar tujuan oleh workflow web/Telegram.
    // Jangan biarkan booking yang sedang dipindahkan memblokir kamar tujuannya sendiri,
    // tetapi tetap tolak blocker operasional lain atau booking aktif yang berbeda.
    $targetBlockers=array_values(array_filter(
        getRoomOperationalBlockers($pdo,$newRoomNumber,true),
        static fn(array $blocker): bool => !(
            (string)($blocker['type']??'')==='active_booking'
            && (string)($blocker['id']??'')===$bookingId
        )
    ));
    if($targetBlockers)throw new RuntimeException(roomOperationalBlockerMessage($newRoomNumber,$targetBlockers));

    $oldAccessContext=checkoutAccessSnapshot($pdo,$oldBooking,true);
    $oldAccess=$oldAccessContext['access'];
    $oldAccessMode=$oldAccessContext['mode'];
    $physicalOutstanding=!empty($oldAccessContext['physicalOutstanding']);
    $keyDisposition=normalizeCheckoutKeyDisposition($options['keyDisposition']??null);
    $keyReason=trim((string)($options['keyReason']??''));
    $operationId=trim((string)($options['operationId']??''))?:generateServerId('op_room_transfer');

    if($physicalOutstanding){
        if($keyDisposition==='returned'){
            insertRoomKeyEvent($pdo,$staff,$oldRoomNumber,$bookingId,'returned_at_room_transfer',$oldAccessMode,$oldAccess['physical_key_ref']??null,$oldBooking['smartLockCodeLast4']??null,'returned',$keyReason?:'Kunci kamar asal diterima saat tamu pindah kamar.');
        }elseif($keyDisposition==='missing'){
            if(tamasyaStringLength($keyReason)<5)throw new InvalidArgumentException('Alasan kunci kamar asal belum kembali/hilang minimal 5 karakter.');
            insertRoomKeyEvent($pdo,$staff,$oldRoomNumber,$bookingId,'missing_at_room_transfer',$oldAccessMode,$oldAccess['physical_key_ref']??null,$oldBooking['smartLockCodeLast4']??null,'missing',$keyReason);
            $alertId='alert_key_missing_transfer_'.substr(hash('sha256',$bookingId.'|'.$oldRoomNumber),0,40);
            $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at)
                VALUES (?,'critical',?,'Kunci kamar asal belum kembali',?,CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE message=VALUES(message),acknowledged_by=NULL,acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                ->execute([$alertId,'key-missing:'.$oldRoomNumber,"Pindah kamar {$oldRoomNumber} → {$newRoomNumber}: {$keyReason}. Dicatat oleh ".currentStaffLabel($staff)]);
        }else{
            throw new RuntimeException('Status kunci kamar asal wajib dipilih: dikembalikan/ditemukan atau belum kembali/hilang.');
        }
    }else{
        $keyDisposition='not_required';
    }

    $cancelledGrantJobIds=cancelObsoleteSmartLockGrantJobs($pdo,$staff,$bookingId,$oldRoomNumber,'Grant smart-lock kamar asal dibatalkan karena booking sedang pindah kamar.',$source);
    $smartLockJobId=null;
    if(in_array($oldAccessMode,['smart','hybrid'],true)&&!empty($oldBooking['smartLockCodeHash'])){
        $smartLockJobId=queueSmartLockBridgeJob($pdo,$oldAccess,[
            'action'=>'revoke','roomNumber'=>$oldRoomNumber,'bookingId'=>$bookingId,'deviceId'=>$oldAccess['smart_lock_device_id']??null,
            'accessMode'=>$oldAccessMode,'physicalKeyRef'=>$oldAccess['physical_key_ref']??('KEY-'.$oldRoomNumber),
            'credentialLast4'=>$oldBooking['smartLockCodeLast4']??null,'bookingStateMode'=>'none','physicalKeyDisposition'=>$keyDisposition,
            'reason'=>'Pencabutan PIN kamar asal setelah pindah kamar.','source'=>$source,'actor'=>tamasyaSmartLockActorSnapshot($staff)
        ]);
        insertRoomKeyEvent($pdo,$staff,$oldRoomNumber,$bookingId,'smart_code_revoke_queued_at_room_transfer',$oldAccessMode,$oldAccess['physical_key_ref']??null,$oldBooking['smartLockCodeLast4']??null,'pending','Pencabutan PIN kamar asal dijadwalkan setelah commit pindah kamar.');
    }

    $newAccessStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");
    $newAccessStmt->execute([$newRoomNumber]);
    $newAccess=$newAccessStmt->fetch(PDO::FETCH_ASSOC)?:[
        'room_number'=>$newRoomNumber,'access_mode'=>'physical','physical_key_ref'=>'KEY-'.$newRoomNumber,
        'physical_key_status'=>'secured','smart_lock_enabled'=>0
    ];
    $newAccessMode=in_array((string)($newAccess['access_mode']??''),['physical','smart','hybrid'],true)?(string)$newAccess['access_mode']:'physical';
    $oldPhysicalKeyStatus=$keyDisposition==='missing'?'missing':'secured';

    $oldAccessBookingId=$smartLockJobId ? $bookingId : null;
    $pdo->prepare("INSERT INTO room_access_control
        (room_number,access_mode,physical_key_ref,physical_key_status,current_booking_id,smart_lock_enabled,last_event_at,updated_by,updated_at)
        VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE physical_key_status=VALUES(physical_key_status),current_booking_id=VALUES(current_booking_id),last_event_at=CURRENT_TIMESTAMP,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
        ->execute([$oldRoomNumber,$oldAccessMode,$oldAccess['physical_key_ref']??('KEY-'.$oldRoomNumber),$oldPhysicalKeyStatus,$oldAccessBookingId,!empty($oldAccess['smart_lock_enabled'])?1:0,(string)($staff['id']??'')]);
    $pdo->prepare("INSERT INTO room_access_control
        (room_number,access_mode,physical_key_ref,physical_key_status,current_booking_id,smart_lock_enabled,last_event_at,updated_by,updated_at)
        VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE current_booking_id=VALUES(current_booking_id),physical_key_status='secured',last_event_at=CURRENT_TIMESTAMP,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
        ->execute([$newRoomNumber,$newAccessMode,$newAccess['physical_key_ref']??('KEY-'.$newRoomNumber),'secured',$bookingId,!empty($newAccess['smart_lock_enabled'])?1:0,(string)($staff['id']??'')]);

    $housekeepingTaskId=ensureCheckoutHousekeepingTask($pdo,$oldRoomNumber,$staff,$source,"Otomatis: tamu pindah dari Kamar {$oldRoomNumber} ke Kamar {$newRoomNumber}. Bersihkan dan periksa kamar asal sebelum tersedia kembali.");
    $oldRoomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$staff,$oldRoomNumber,$source.'-transfer-old-room');
    $newRoomProjection=tamasyaReconcileRoomOperationalProjection($pdo,$staff,$newRoomNumber,$source.'-transfer-new-room');

    // Kunci/PIN kamar baru belum boleh dianggap sudah diserahkan. Petugas harus
    // menjalankan workflow serah kunci/PIN terhadap kamar tujuan setelah transfer.
    $stmtBooking=$pdo->prepare("UPDATE bookings SET accessMode=?,keyControlStatus='not_issued',keyIssuedAt=NULL,keyIssuedBy=NULL,keyReturnedAt=NULL,keyReturnedBy=NULL,
        smartLockCodeHash=NULL,smartLockCodeLast4=NULL,smartLockValidFrom=NULL,smartLockValidUntil=NULL,
        version=version+1,updatedBy=?,updatedSource=? WHERE id=? AND status='active' AND roomNumber=?");
    $stmtBooking->execute([$newAccessMode,(string)($staff['id']??''),$source,$bookingId,$newRoomNumber]);
    if($stmtBooking->rowCount()!==1)throw new RuntimeException('Booking berubah sebelum lifecycle pindah kamar diselesaikan.');

    writeRequiredEnterpriseAudit($pdo,$staff,'Pindah kamar aktif terselesaikan','booking',$bookingId,[
        'roomNumber'=>$oldRoomNumber,'accessMode'=>$oldAccessMode,'keyControlStatus'=>$oldBooking['keyControlStatus']??null
    ],[
        'roomNumber'=>$newRoomNumber,'accessMode'=>$newAccessMode,'keyControlStatus'=>'not_issued','housekeepingTaskId'=>$housekeepingTaskId,'keyDisposition'=>$keyDisposition
    ],$source);
    logActivity($pdo,'ACTIVE_ROOM_TRANSFER_FINALIZED',"Pindah kamar aktif {$oldRoomNumber} → {$newRoomNumber}; kunci asal {$keyDisposition}; kamar asal maintenance",(string)($staff['id']??''),currentStaffLabel($staff));

    return [
        'bookingId'=>$bookingId,'oldRoomNumber'=>$oldRoomNumber,'newRoomNumber'=>$newRoomNumber,
        'keyDisposition'=>$keyDisposition,'operationId'=>$operationId,'housekeepingTaskId'=>$housekeepingTaskId,
        'smartLockJobId'=>$smartLockJobId,'cancelledGrantJobIds'=>$cancelledGrantJobIds,'newAccessMode'=>$newAccessMode,
        'oldRoomProjection'=>$oldRoomProjection,'newRoomProjection'=>$newRoomProjection
    ];
}


/**
 * Satu-satunya jalur mutasi pindah kamar melalui Telegram.
 * Menyatukan booking, ledger, shift, kunci, smart-lock, housekeeping,
 * activity log, audit before/after, idempotensi, dan revision dalam satu transaksi.
 */
function finalizeTelegramRoomTransfer(
    PDO $pdo,
    array $staff,
    string $roomNumber,
    string $targetRoomNumber,
    float $cost,
    string $paymentStatus,
    string $operationId,
    string $action = 'callback',
    string $keyDisposition = 'unknown',
    string $keyReason = ''
): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'pindah kamar live via Telegram');
    $roomNumber=trim($roomNumber);
    $targetRoomNumber=trim($targetRoomNumber);
    $paymentStatus=strtolower(trim($paymentStatus));
    $operationId=normalizeTelegramMutationOperationId($operationId);
    $action=in_array($action,['callback','message'],true)?$action:'callback';
    $keyDisposition=normalizeCheckoutKeyDisposition($keyDisposition);
    $keyReason=trim($keyReason);
    $role=strtolower(trim((string)($staff['role']??'')));
    // Selaras gate Telegram r_transfer: baseline role ATAU centangan
    // manage_stay_access (owner read-only tetap ditolak).
    if(function_exists('hasCapability')){
        if(!hasCapability($staff,'manage_stay_access',['admin','manager','receptionist']))throw new RuntimeException('Role ini tidak diizinkan memindahkan kamar tamu.');
    }
    elseif(!in_array($role,['admin','manager','receptionist'],true))throw new RuntimeException('Role ini tidak diizinkan memindahkan kamar tamu.');
    if($roomNumber===''||$targetRoomNumber===''||$roomNumber===$targetRoomNumber)throw new InvalidArgumentException('Kamar asal dan tujuan pindah tidak valid.');
    if(!is_finite($cost)||$cost<0||$cost>1000000000000)throw new InvalidArgumentException('Biaya pindah kamar tidak valid.');
    // Pindah kamar adalah mutasi folio NON-CASH. Callback lama yang pernah
    // menawarkan "Lunas" harus fail-closed agar surcharge tidak salah masuk Kas.
    // Nilai "folio" dan "unpaid" sama-sama berarti: tambahkan ke tagihan, pembayaran
    // dilakukan kemudian lewat workflow Booking Payment/Checkout canonical.
    if(!in_array($paymentStatus,['folio','unpaid'],true)){
        throw new InvalidArgumentException('Biaya pindah kamar tidak boleh langsung ditandai lunas. Tambahkan ke folio lalu catat pembayaran melalui workflow Pembayaran/Checkout.');
    }
    $paymentStatus='unpaid';
    if($pdo->inTransaction())throw new RuntimeException('Pindah kamar Telegram harus memulai transaksi atomiknya sendiri.');

    $previousOperationId=$GLOBALS['tamasya_request_operation_id']??null;
    $GLOBALS['tamasya_request_operation_id']=$operationId;
    $smartLockJobId=null;
    try{
        $pdo->beginTransaction();
        $booking=lockActiveBookingForRoom($pdo,$roomNumber);
        $stmtSourceRoom=$pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");
        $stmtSourceRoom->execute([$roomNumber]);
        $sourceRoom=$stmtSourceRoom->fetch(PDO::FETCH_ASSOC);
        if(!$sourceRoom)throw new RuntimeException("Kamar asal {$roomNumber} tidak ditemukan.");
        $targetRoom=lockAvailableRoomForNewBooking($pdo,$targetRoomNumber);
        $bookingId=(string)$booking['id'];
        $payload=[
            'bookingId'=>$bookingId,'roomNumber'=>$roomNumber,'targetRoomNumber'=>$targetRoomNumber,
            'cost'=>round($cost,2),'paymentStatus'=>'unpaid','financialMode'=>'folio_non_cash','source'=>'telegram',
            'keyDisposition'=>$keyDisposition
        ];
        claimTelegramMutation($pdo,$operationId,(string)$staff['id'],'booking_room_transfer',$bookingId,$payload,$action);

        $before=[
            'roomNumber'=>$roomNumber,'roomType'=>$booking['roomType']??null,'totalAmount'=>(float)($booking['totalAmount']??0),
            'vatRate'=>$booking['vatRate']??null,'vatAmount'=>$booking['vatAmount']??null,
            'amountPaid'=>(float)($booking['amountPaid']??0),'balanceDue'=>(float)($booking['balanceDue']??0),
            'paymentStatus'=>$booking['paymentStatus']??null,'keyControlStatus'=>$booking['keyControlStatus']??null
        ];
        $financialPlan=tamasyaBuildRoomTransferFinancialPlan(
            $pdo,$booking,$sourceRoom,$targetRoom,'keep',round($cost,2),date('Y-m-d')
        );
        $newTotal=(float)$financialPlan['newTotalAmount'];
        $newVatAmount=(float)$financialPlan['newVatAmount'];
        $newVatRate=$financialPlan['newVatRate'];
        $stmtUpdate=$pdo->prepare("UPDATE bookings SET roomNumber=?,roomType=?,totalAmount=?,vatRate=?,vatAmount=?,version=version+1,updatedBy=?,updatedSource='telegram' WHERE id=? AND status='active' AND roomNumber=?");
        $stmtUpdate->execute([
            $targetRoomNumber,(string)$targetRoom['type'],$newTotal,$newVatRate,$newVatAmount,
            (string)$staff['id'],$bookingId,$roomNumber
        ]);
        if($stmtUpdate->rowCount()!==1)throw new RuntimeException('Booking berubah sebelum pindah kamar Telegram diselesaikan.');

        // Sengaja tidak membuat transactions row di sini. Surcharge adalah tagihan
        // folio; settlement Tunai/Transfer/QRIS/OTA harus lewat authority pembayaran.
        $transactionId=null;

        $lifecycle=finalizeActiveRoomTransferOperationalLifecycle($pdo,$staff,$booking,$targetRoomNumber,'telegram-room-transfer',[
            'operationId'=>$operationId,
            'keyDisposition'=>$keyDisposition,
            'keyReason'=>$keyReason!==''?$keyReason:'Pindah kamar diproses melalui Telegram; status kunci kamar asal dikonfirmasi petugas.'
        ]);
        $smartLockJobId=$lifecycle['smartLockJobId']??null;
        recalculateBookingFinancials($pdo,$bookingId,true);
        assertBookingLedgerInvariant($pdo,$bookingId,$staff,'telegram',false);
        $stmtAfter=$pdo->prepare("SELECT roomNumber,roomType,totalAmount,vatRate,vatAmount,amountPaid,balanceDue,paymentStatus,keyControlStatus FROM bookings WHERE id=? LIMIT 1");
        $stmtAfter->execute([$bookingId]);
        $after=$stmtAfter->fetch(PDO::FETCH_ASSOC)?:[];
        $after['transferCost']=round($cost,2);
        $after['transferPaymentStatus']='unpaid';
        $after['financialMode']='folio_non_cash';
        $after['transactionId']=null;
        $after['housekeepingTaskId']=$lifecycle['housekeepingTaskId']??null;
        writeRequiredEnterpriseAudit($pdo,$staff,'Pindah kamar melalui Telegram','booking',$bookingId,$before,$after,'telegram');
        logActivity($pdo,'ROOM_TRANSFER_TELEGRAM',"Pindah kamar {$roomNumber} → {$targetRoomNumber} untuk Booking {$bookingId}; surcharge folio Rp ".number_format($cost,0,',','.'),(string)$staff['id'],currentStaffLabel($staff));

        $notificationId='n_tg_transfer_'.substr(hash('sha256',$operationId),0,24);
        $notification="Pindah Kamar dari {$roomNumber} ke {$targetRoomNumber} atas nama ".(string)($booking['guestName']??'Tamu')." sukses. Biaya tambahan Rp ".number_format($cost,0,',','.')." masuk folio dan belum merupakan pembayaran.";
        $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'booking')")
            ->execute([$notificationId,$notification]);
        bumpServerRevision($pdo);
        completeTelegramMutation($pdo,$operationId,[
            'bookingId'=>$bookingId,'oldRoomNumber'=>$roomNumber,'newRoomNumber'=>$targetRoomNumber,
            'transactionId'=>null,'financialMode'=>'folio_non_cash','paymentStatus'=>'unpaid','cost'=>round($cost,2),
            'housekeepingTaskId'=>$lifecycle['housekeepingTaskId']??null,
            'smartLockJobId'=>$smartLockJobId
        ]);
        tamasyaFinancialCommit($pdo);

        $smartLockResult=null;
        if($smartLockJobId){
            try{$smartLockResult=processDeferredCheckoutSmartLockJob($pdo,(string)$smartLockJobId,$staff,'telegram-room-transfer');}
            catch(Throwable $smartLockError){
                error_log('[Telegram Room Transfer Smart Lock] '.$smartLockError->getMessage());
                $smartLockResult=['jobId'=>$smartLockJobId,'status'=>'error','message'=>'Pencabutan akses kamar lama perlu ditinjau.'];
            }
        }
        return [
            'duplicate'=>false,'bookingId'=>$bookingId,'guestName'=>$booking['guestName']??'Tamu',
            'oldRoomNumber'=>$roomNumber,'newRoomNumber'=>$targetRoomNumber,'cost'=>round($cost,2),
            'paymentStatus'=>'unpaid','financialMode'=>'folio_non_cash','transactionId'=>null,
            'housekeepingTaskId'=>$lifecycle['housekeepingTaskId']??null,'smartLock'=>$smartLockResult
        ];
    }catch(TelegramDuplicateOperationException $duplicate){
        if($pdo->inTransaction())$pdo->rollBack();
        $stmt=$pdo->prepare("SELECT result_json FROM sync_operations WHERE operation_id=? LIMIT 1");
        $stmt->execute([$operationId]);
        $result=json_decode((string)$stmt->fetchColumn(),true)?:[];
        return array_merge(['duplicate'=>true,'cost'=>round($cost,2),'paymentStatus'=>'unpaid','financialMode'=>'folio_non_cash'],$result);
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }finally{
        if($previousOperationId===null)unset($GLOBALS['tamasya_request_operation_id']);
        else $GLOBALS['tamasya_request_operation_id']=$previousOperationId;
    }
}

/** Source line 3596: createOrRefreshRoomVacancyReport */
function createOrRefreshRoomVacancyReport(PDO $pdo, array $staff, array $payload, string $source): array {
    $roomNumber=trim((string)($payload['roomNumber']??''));
    $operationId=trim((string)($payload['operationId']??''));
    $requestedBookingId=trim((string)($payload['bookingId']??''));
    if($roomNumber===''||$operationId==='')throw new InvalidArgumentException('Nomor kamar dan operation ID laporan wajib diisi.');
    if($source==='web-offline'&&$requestedBookingId===''){
        throw new InvalidArgumentException('Laporan offline lama tidak memiliki booking ID dan tidak boleh ditempelkan ke tamu baru. Buat ulang laporan setelah memuat data booking terbaru.');
    }
    $keyObservation=in_array((string)($payload['keyObservation']??''),['returned','missing','unknown','not_seen'],true)?(string)$payload['keyObservation']:'unknown';
    $belongings=in_array((string)($payload['belongingsStatus']??''),['none','found','unknown'],true)?(string)$payload['belongingsStatus']:'unknown';
    $damageRaw=strtolower(trim((string)($payload['damageStatus']??'')));
    // Compatibility R2 Koneksi Lokal sempat mengirim `reported` untuk opsi
    // “Ada kerusakan”. Perlakukan sebagai canonical `found` agar antrean lama
    // tidak kehilangan incident saat disinkronkan setelah patch.
    if($damageRaw==='reported')$damageRaw='found';
    $damage=in_array($damageRaw,['none','found','unknown'],true)?$damageRaw:'unknown';
    $notes=trim((string)($payload['notes']??''));
    if(tamasyaStringLength($notes)>2000)throw new InvalidArgumentException('Catatan laporan maksimal 2.000 karakter.');
    $belongingsDetail=trim((string)($payload['belongingsDetail']??''));
    $damageDetail=trim((string)($payload['damageDetail']??''));
    // R4 stores each observation in its own field. The notes fallback exists only
    // for R2/R3 offline queues already persisted before structured fields existed.
    if($belongings==='found')$belongingsDetail=tamasyaRequireLostFoundItemDetail($belongingsDetail!==''?$belongingsDetail:$notes,'Detail barang Kamar Kosong');
    if($damage==='found')$damageDetail=tamasyaRequireTechnicalDamageDetail($damageDetail!==''?$damageDetail:$notes,'Detail kerusakan Kamar Kosong');
    $observedAt=trim((string)($payload['observedAt']??''));
    if($observedAt===''||strtotime($observedAt)===false)$observedAt=date('Y-m-d H:i:s');
    else $observedAt=date('Y-m-d H:i:s',strtotime($observedAt));

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $dup=$pdo->prepare("SELECT * FROM room_vacancy_reports WHERE operation_id=? LIMIT 1 FOR UPDATE");$dup->execute([$operationId]);$existingByOp=$dup->fetch(PDO::FETCH_ASSOC);
        if($existingByOp){if($owns)tamasyaFinancialCommit($pdo);return ['duplicate'=>true,'stale'=>!in_array((string)($existingByOp['status']??''),['pending','verified'],true),'report'=>$existingByOp];}

        if($requestedBookingId!==''){
            $bookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");
            $bookingStmt->execute([$requestedBookingId]);
            $booking=$bookingStmt->fetch(PDO::FETCH_ASSOC);
            if(!$booking)throw new RuntimeException('Booking yang terikat pada laporan offline tidak ditemukan.');
            if(trim((string)($booking['roomNumber']??''))!==$roomNumber){
                throw new RuntimeException('Booking laporan tidak cocok dengan nomor kamar yang diamati.');
            }
            requireTelegramRoom($pdo,$roomNumber,true);
        }else{
            // Jalur Telegram selalu online dan memilih booking aktif langsung dari server.
            $booking=lockActiveBookingForRoom($pdo,$roomNumber);
            requireTelegramRoom($pdo,$roomNumber,true);
        }

        $bookingStatus=strtolower(trim((string)($booking['status']??'')));
        $stale=$bookingStatus!=='active';
        if(!$stale){
            // Pastikan booking yang dipilih masih merupakan booking aktif saat ini untuk
            // kamar tersebut. Laporan tidak boleh berpindah ke booking pengganti.
            $activeStmt=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' ORDER BY id LIMIT 1 FOR UPDATE");
            $activeStmt->execute([$roomNumber]);
            $activeBookingId=(string)($activeStmt->fetchColumn()?:'');
            if($activeBookingId===''||!hash_equals((string)$booking['id'],$activeBookingId)){
                throw new RuntimeException('Booking kamar sudah berubah. Muat ulang data sebelum membuat laporan.');
            }
        }

        $reportStatus=$stale?($bookingStatus==='completed'?'checkout_completed':'rejected'):'pending';
        // Setiap observasi unik menjadi record audit sendiri. Retry dengan operation_id
        // yang sama tetap idempotent, sedangkan laporan staf lain tidak menimpa pelapor awal.
        $reportId=generateServerId('vacancy');
        $pdo->prepare("INSERT INTO room_vacancy_reports
            (id,operation_id,booking_id,room_number,status,key_observation,belongings_status,belongings_detail,damage_status,damage_detail,notes,observed_at,reported_by,reported_by_name,reported_role,source,reported_at,completed_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
            ->execute([$reportId,$operationId,(string)$booking['id'],$roomNumber,$reportStatus,$keyObservation,$belongings,$belongingsDetail?:null,$damage,$damageDetail?:null,$notes?:null,$observedAt,(string)$staff['id'],currentStaffLabel($staff),(string)($staff['role']??''),$source,$stale?date('Y-m-d H:i:s'):null]);

        $summary="Kamar {$roomNumber} dilaporkan kosong oleh ".currentStaffLabel($staff).". Kunci: {$keyObservation}; barang: {$belongings}; kerusakan: {$damage}.";
        if(!$stale){
            $alertId='alert_vacancy_'.substr(hash('sha256',(string)$booking['id']),0,40);
            $pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at) VALUES (?,'warning','vacancy-report','Kamar dilaporkan kosong',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE message=VALUES(message),acknowledged_by=NULL,acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP")
                ->execute([$alertId,$summary]);
            $notifId='n_vacancy_'.substr(hash('sha256',$reportId),0,32);
            $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'system') ON DUPLICATE KEY UPDATE message=VALUES(message),timestamp=CURRENT_TIMESTAMP,`read`=0")
                ->execute([$notifId,$summary]);
        }else{
            // Laporan yang baru terkirim setelah checkout/cancel tetap disimpan pada
            // booking lama sebagai bukti historis. Temuan barang/kerusakan tetap harus
            // ditindaklanjuti, tetapi tidak membuat kamar/tamu baru dianggap kosong.
            $historicalFindings=[
                'rows'=>[['id'=>$reportId,'room_number'=>$roomNumber,'belongings_status'=>$belongings,'belongings_detail'=>$belongingsDetail,'damage_status'=>$damage,'damage_detail'=>$damageDetail,'notes'=>$notes,'reported_by_name'=>currentStaffLabel($staff),'reported_at'=>date('Y-m-d H:i:s')]],
                'hasBelongings'=>$belongings==='found','hasDamage'=>$damage==='found','notes'=>$notes!==''?[$notes]:[],
                'belongingsDetails'=>$belongings==='found'&&$belongingsDetail!==''?[$belongingsDetail]:[],
                'damageDetails'=>$damage==='found'&&$damageDetail!==''?[$damageDetail]:[]
            ];
            createCheckoutFindingSafeguards($pdo,$staff,(string)$booking['id'],$roomNumber,$historicalFindings,$reportId);
            $summary.=" Laporan terlambat disimpan pada booking {$booking['id']} yang berstatus {$bookingStatus}; tidak dialihkan ke booking baru.";
        }
        writeRequiredEnterpriseAudit($pdo,$staff,$stale?'Menyimpan laporan kamar kosong terlambat':'Melaporkan kamar kosong','room_vacancy_report',$reportId,null,[
            'bookingId'=>(string)$booking['id'],'bookingStatus'=>$bookingStatus,'roomNumber'=>$roomNumber,'keyObservation'=>$keyObservation,
            'belongingsStatus'=>$belongings,'belongingsDetail'=>$belongingsDetail,'damageStatus'=>$damage,'damageDetail'=>$damageDetail,'source'=>$source,'stale'=>$stale
        ],$source);
        logActivity($pdo,$stale?'ROOM_VACANCY_REPORTED_LATE':'ROOM_VACANCY_REPORTED',$summary,(string)$staff['id'],currentStaffLabel($staff));
        bumpServerRevision($pdo);
        $fetch=$pdo->prepare("SELECT rvr.*,b.status AS booking_status,b.guestName FROM room_vacancy_reports rvr JOIN bookings b ON b.id=rvr.booking_id WHERE rvr.id=? LIMIT 1");$fetch->execute([$reportId]);$row=$fetch->fetch(PDO::FETCH_ASSOC)?:['id'=>$reportId,'room_number'=>$roomNumber,'booking_id'=>$booking['id'],'status'=>$reportStatus];
        if($owns)tamasyaFinancialCommit($pdo);
        return ['duplicate'=>false,'stale'=>$stale,'report'=>$row,'booking'=>$booking];
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Source line 3686: prepareTelegramCheckoutFinancialStep */
function prepareTelegramCheckoutFinancialStep(PDO $pdo, array $staff, array $booking, string $keyDisposition, string $keyReason = '', ?string $vacancyReportId = null): array {
    $roomNumber=trim((string)($booking['roomNumber']??''));
    $keyDisposition=normalizeCheckoutKeyDisposition($keyDisposition);
    if(!empty($booking['isOpenEnded'])){
        $ctx=json_encode(['flow'=>'checkout_total','bookingId'=>(string)$booking['id'],'roomNumber'=>$roomNumber,'keyDisposition'=>$keyDisposition,'keyReason'=>$keyReason,'vacancyReportId'=>$vacancyReportId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_checkout_total',telegram_context=? WHERE id=?")->execute([$ctx,(string)$staff['id']]);
        $ledger=bookingLedgerTotals($pdo,(string)$booking['id']);
        return ['text'=>"🧮 *FINALISASI TAGIHAN DURASI TERBUKA*\n\n👤 Tamu: *{$booking['guestName']}*\n🚪 Kamar: *{$roomNumber}*\n💵 Sudah diterima: *Rp ".number_format($ledger['net'],0,',','.')."*\n\nKetik *total tagihan aktual keseluruhan* sampai hari ini.",'markup'=>['inline_keyboard'=>[[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]],'alert'=>'Masukkan total aktual'];
    }
    $ledger=bookingLedgerTotals($pdo,(string)$booking['id']);$total=max(0.0,(float)$booking['totalAmount']);
    if($ledger['net']>$total+1.0)throw new RuntimeException('Penerimaan booking melebihi total tagihan. Jalankan rekonsiliasi sebelum checkout.');
    $remaining=max(0.0,round($total-$ledger['net'],2));
    $summary="🛎️ *CHECK-OUT KAMAR {$roomNumber}*\n\n👤 Tamu: *{$booking['guestName']}*\n💰 Total tagihan: *Rp ".number_format($total,0,',','.')."*\n✅ Sudah diterima: *Rp ".number_format($ledger['net'],0,',','.')."*\n⏳ Sisa tagihan: *Rp ".number_format($remaining,0,',','.')."*\n🔑 Status kunci: *".strtoupper($keyDisposition)."*\n\n";
    if($remaining>0){
        return ['text'=>$summary.'Pilih metode pelunasan.'.($vacancyReportId?' Bila tamu sudah benar-benar pergi, checkout operasional dapat melepas kamar dan mencatat sisa sebagai piutang.':' Bila tamu sudah pergi tanpa membayar, tekan tombol lapor kamar kosong di bawah, selesaikan verifikasi, lalu ulangi checkout untuk membuka opsi piutang.'),'markup'=>prepareTelegramCheckoutPaymentChoice($pdo,$staff,$booking,$remaining,$keyDisposition,$keyReason,$vacancyReportId),'alert'=>'Pilih pembayaran'];
    }
    $ctx=json_encode(['expectedTotalAmount'=>$total,'expectedRemaining'=>0,'expiresAt'=>time()+900,'flow'=>'checkout_ready','bookingId'=>(string)$booking['id'],'roomNumber'=>$roomNumber,'keyDisposition'=>$keyDisposition,'keyReason'=>$keyReason,'vacancyReportId'=>$vacancyReportId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_checkout_confirmation',telegram_context=? WHERE id=?")->execute([$ctx,(string)$staff['id']]);
    return ['text'=>$summary.'Ledger sudah lunas. Lanjutkan checkout?','markup'=>['inline_keyboard'=>[[['text'=>'✅ Ya, Proses Check-Out','callback_data'=>'r_checkout_confirm:'.$roomNumber]],[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]],'alert'=>'Konfirmasi checkout'];
}

/** Source line 3711: prepareTelegramCheckoutPaymentChoice */
function prepareTelegramCheckoutPaymentChoice(PDO $pdo, array $staff, array $booking, float $remaining, string $keyDisposition = 'not_required', string $keyReason = '', ?string $vacancyReportId = null): array {
    $roomNumber = trim((string)($booking['roomNumber'] ?? ''));
    if ($roomNumber === '' || empty($staff['id'])) throw new InvalidArgumentException('Konteks checkout tidak lengkap.');
    $bankMap = [];
    $buttons = [
        [["text" => "💵 Tunai - Rp " . number_format($remaining, 0, ',', '.'), "callback_data" => "r_checkout_pay:{$roomNumber}:cash:-"]]
    ];
    $stmt = $pdo->query("SELECT id,name,type FROM bank_accounts WHERE isActive=1 AND type IN ('bank','edc_qris') ORDER BY name ASC LIMIT 8");
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $account) {
        $method=tamasyaPaymentMethodForAccountType((string)($account['type']??''))??'';
        if(!in_array($method,['transfer','qris'],true))continue;
        $key = (string)count($bankMap);
        $bankMap[$key] = ['id'=>(string)$account['id'],'method'=>$method,'name'=>(string)$account['name']];
        $label = $method === 'qris' ? '📱 QRIS' : '🏦 Transfer';
        $buttons[] = [["text" => $label . ' - ' . (string)$account['name'], "callback_data" => "r_checkout_pay:{$roomNumber}:bank:{$key}"]];
    }
    if($bankMap){
        $buttons[] = [["text" => "🌓 Split Tunai + Transfer/QRIS", "callback_data" => "r_checkout_split:{$roomNumber}"]];
    }
    if (tamasyaIsExplicitOtaSourceLabel((string)($booking['bookingSource'] ?? ''))) {
        $buttons[] = [["text" => "🌐 Piutang/Settlement OTA", "callback_data" => "r_checkout_pay:{$roomNumber}:ota:-"]];
    }
    if(trim((string)$vacancyReportId)!==''){
        $buttons[] = [["text" => "🚪 Checkout operasional · catat piutang", "callback_data" => "r_checkout_defer:{$roomNumber}"]];
    }else{
        // Tamu kabur tanpa laporan = jalan buntu bila tidak diarahkan:
        // piutang wajib berasal dari laporan kamar kosong terverifikasi.
        // Arahkan langsung ke pelaporan agar alur tidak berhenti di sini.
        $buttons[] = [["text" => "🚪 Tamu sudah pergi? Laporkan kamar kosong dulu", "callback_data" => "vacancy_report_room:{$roomNumber}"]];
    }
    $negotiationNonce=bin2hex(random_bytes(10));
    $buttons[] = [['text'=>'✍️ Tetapkan Harga Nego Sebelum Bayar','callback_data'=>'r_checkout_nego:'.$roomNumber.':'.$negotiationNonce]];
    $buttons[] = [["text" => "❌ Batalkan", "callback_data" => "cancel_booking_process"]];
    $context = json_encode([
        'negotiationNonce'=>$negotiationNonce,'expiresAt'=>time()+900,
        'expectedTotalAmount'=>round((float)$booking['totalAmount'],2),'expectedRemaining'=>round($remaining,2),
        'flow'=>'checkout_payment',
        'bookingId'=>(string)$booking['id'],
        'roomNumber'=>$roomNumber,
        'bankMap'=>$bankMap,
        'keyDisposition'=>normalizeCheckoutKeyDisposition($keyDisposition),
        'keyReason'=>$keyReason,
        'vacancyReportId'=>$vacancyReportId
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_checkout_payment_choice',telegram_context=? WHERE id=?")
        ->execute([$context,(string)$staff['id']]);
    return ['inline_keyboard'=>$buttons];
}

/** Source line 3748: finalizeTelegramCheckout */
function finalizeTelegramCheckout(PDO $pdo, array $staff, string $roomNumber, string $operationId, ?string $paymentMethod = null, ?string $bankAccountId = null, string $keyDisposition = 'unknown', string $keyReason = '', ?string $vacancyReportId = null, string $financialDisposition = 'settle_now', string $financialClosureReason = '', ?array $splitPayment = null, array $expectedQuote = []): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'checkout live via Telegram');
    if (empty($staff['id'])) throw new InvalidArgumentException('Identitas staf Telegram tidak valid.');
    $roomNumber = trim($roomNumber);
    $operationId = normalizeTelegramMutationOperationId($operationId);
    if ($roomNumber === '') throw new InvalidArgumentException('Identitas checkout Telegram tidak lengkap.');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $booking = lockActiveBookingForRoom($pdo, $roomNumber);
        $room = requireTelegramRoom($pdo, $roomNumber, true);
        if (!empty($booking['isOpenEnded'])) {
            throw new RuntimeException('Total aktual booking durasi terbuka harus ditetapkan sebelum checkout.');
        }
        $splitPayment=is_array($splitPayment)?$splitPayment:[];
        $splitCashAmount=round((float)($splitPayment['splitCashAmount']??0),2);
        $splitTransferAmount=round((float)($splitPayment['splitTransferAmount']??0),2);
        $splitTransferBankAccountId=trim((string)($splitPayment['splitTransferBankAccountId']??''));
        $splitActive=!empty($splitPayment['isSplitPayment'])||($splitCashAmount>0&&$splitTransferAmount>0&&$splitTransferBankAccountId!=='');
        claimTelegramMutation($pdo,$operationId,(string)$staff['id'],'booking_checkout',(string)$booking['id'],[
            'roomNumber'=>$roomNumber,'paymentMethod'=>$paymentMethod,'bankAccountId'=>$bankAccountId,
            'isSplitPayment'=>$splitActive?1:0,'splitCashAmount'=>$splitCashAmount,'splitTransferAmount'=>$splitTransferAmount,
            'splitTransferBankAccountId'=>$splitTransferBankAccountId!==''?$splitTransferBankAccountId:null
        ],'callback');

        $total = max(0.0, round((float)($booking['totalAmount'] ?? 0), 2));
        if ($total <= 0) throw new RuntimeException('Total tagihan booking tidak valid.');
        $ledger = bookingLedgerTotals($pdo, (string)$booking['id']);
        if ($ledger['net'] > $total + 1.0) {
            throw new RuntimeException('Penerimaan booking melebihi tagihan; checkout dihentikan untuk mencegah kas ganda.');
        }
        $remaining = max(0.0, round($total - $ledger['net'], 2));
        if($expectedQuote){
            if((string)($expectedQuote['bookingId']??'')!==(string)$booking['id']
                || !isset($expectedQuote['expectedTotalAmount'],$expectedQuote['expectedRemaining'])
                || abs($total-(float)$expectedQuote['expectedTotalAmount'])>0.01
                || abs($remaining-(float)$expectedQuote['expectedRemaining'])>0.01
                || (int)($expectedQuote['expiresAt']??0)<time())throw new RuntimeException('Tagihan/pembayaran berubah atau pilihan checkout kedaluwarsa. Buka kembali checkout.');
        }
        $financialDisposition=strtolower(trim($financialDisposition));
        if(!in_array($financialDisposition,['settle_now','defer'],true))throw new InvalidArgumentException('Pilihan penutupan keuangan Telegram tidak valid.');
        $deferFinancialClosure=$financialDisposition==='defer'&&$remaining>0.01;
        if($deferFinancialClosure){
            if(trim((string)$vacancyReportId)==='')throw new RuntimeException('Checkout operasional dengan piutang wajib berasal dari laporan kamar kosong.');
            $financialClosureReason=trim($financialClosureReason)?:'Tamu ditemukan sudah meninggalkan kamar; sisa tagihan dicatat sebagai piutang.';
            if(tamasyaStringLength($financialClosureReason)<5)throw new InvalidArgumentException('Alasan piutang checkout minimal 5 karakter.');
        }
        $requireShiftForSale=(int)$pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetchColumn()===1;
        $checkoutShiftSessionId=($remaining>0&&!$deferFinancialClosure) ? resolveOpenShiftSessionId($pdo,$staff,$requireShiftForSale) : null;
        $serverTax = null;
        $method = $paymentMethod === null ? null : strtolower(trim($paymentMethod));
        $bankAccountId = $bankAccountId !== null ? trim($bankAccountId) : null;
        $bookingSource = (string)($booking['bookingSource'] ?? 'Direct');
        $roomCategoryName = getRoomRentalCategoryName($pdo);

        if ($remaining > 0 && !$deferFinancialClosure) {
            $splitTransferMethod=null;
            if($splitActive){
                if($splitCashAmount<=0||$splitTransferAmount<=0||$splitTransferBankAccountId===''||!moneyMatches($splitCashAmount+$splitTransferAmount,$remaining)){
                    throw new InvalidArgumentException('Nominal split checkout Telegram harus sama dengan sisa tagihan dan akun transfer/QRIS wajib dipilih.');
                }
                $splitTransferMethod=tamasyaInferPaymentMethodFromAccount($pdo,$splitTransferBankAccountId,true,['transfer','qris']);
                $method='split';
                $bankAccountId=null;
            }else{
                if ($method === 'ota' && !tamasyaIsExplicitOtaSourceLabel($bookingSource)) {
                    throw new InvalidArgumentException('Metode OTA hanya untuk booking eksternal.');
                }
                $bankAccountId=tamasyaResolvePaymentAccount($pdo,(string)$method,$bankAccountId,[
                    'allowedMethods'=>['cash','transfer','qris','ota'],'lock'=>true,'context'=>'Pelunasan checkout'
                ]);
            }

            // Pelunasan Telegram memakai alokasi snapshot PBJT booking yang sama
            // dengan panjar/pelunasan web. Refund/koreksi sebelumnya ikut dihitung,
            // sehingga pajak receipt terakhir tepat menutup total PBJT booking.
            $paymentTax = resolveBookingPaymentTaxAllocation(
                $pdo,$booking,$remaining,(float)$ledger['net'],$booking['checkIn']??date('Y-m-d')
            );
            $serverTax = $paymentTax['snapshot'];
            if(empty($serverTax['preserved'])){
                $pdo->prepare("UPDATE bookings SET vatRate=?,vatAmount=?,extras=? WHERE id=?")
                    ->execute([
                        $serverTax['taxRate'],$serverTax['taxAmount'],
                        json_encode($serverTax['extras']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                        (string)$booking['id']
                    ]);
                $booking['vatRate']=$serverTax['taxRate'];$booking['vatAmount']=$serverTax['taxAmount'];$booking['extras']=$serverTax['extras']??[];
            }
            $remainingTax = (float)$paymentTax['taxAmount'];
            $remainingBase = (float)$paymentTax['baseAmount'];
            $suffix = substr(hash('sha256',$operationId),0,28);
            $txId = 'tx_tg_settle_'.$suffix;
            $label = $splitActive ? ('Split Tunai + '.strtoupper((string)$splitTransferMethod)) : ($method === 'qris' ? 'QRIS' : ($method === 'transfer' ? 'Transfer' : ($method === 'ota' ? 'Piutang OTA' : 'Tunai')));
            $desc = "Pelunasan Kamar {$roomNumber} - " . (string)$booking['guestName'] . " ({$label}, Telegram)";
            // Telegram checkout settlement is also live booking income, so it needs the
            // same tax snapshot contract as the web checkout. The settlement amount is
            // an allocation of the booking's aggregate PBJT snapshot; the attach below
            // fills the final component rule evidence.
            tamasyaPostFinancialTransaction($pdo,[
                'id'=>$txId,'type'=>'income','category'=>$roomCategoryName,'categorySystemKey'=>'room_rental','subcategory'=>(string)$booking['roomType'],
                'roomNumber'=>$roomNumber,'amount'=>$remaining,'date'=>date('Y-m-d'),'description'=>$desc,'createdBy'=>currentStaffLabel($staff),
                'bankAccountId'=>$bankAccountId,'bookingId'=>(string)$booking['id'],'bookingSource'=>$bookingSource,'baseAmount'=>$remainingBase,
                'taxAmount'=>$remainingTax,'taxRate'=>$serverTax['taxRate'],
                'taxRuleId'=>null,
                'taxRuleName'=>null,
                'taxSource'=>'booking_component_allocation','taxSnapshotStatus'=>'confirmed','transactionKind'=>'settlement','sourceEntity'=>'booking','sourceEntityId'=>(string)$booking['id'],
                'isSystemGenerated'=>1,'operationId'=>$operationId,'shiftSessionId'=>$checkoutShiftSessionId,'updatedBy'=>(string)$staff['id'],
                'updatedSource'=>'telegram','version'=>1,
                'isSplitPayment'=>$splitActive?1:0,'splitCashAmount'=>$splitActive?$splitCashAmount:0,'splitTransferAmount'=>$splitActive?$splitTransferAmount:0,
                'splitTransferBankAccountId'=>$splitActive?$splitTransferBankAccountId:null
            ],$staff,'booking',['lockCatalog'=>true,'source'=>'telegram']);
            tamasyaAttachBookingReceiptRevenueAllocations($pdo,$booking,$txId,$remaining,(float)$ledger['net'],$operationId,$staff,'telegram',$booking['checkIn']??date('Y-m-d'));
            if($splitActive){
                $pdo->prepare("UPDATE bookings SET paymentStatus='paid',paymentMethod='split',bankAccountId=NULL,isSplitPayment=1,splitCashAmount=?,splitTransferAmount=?,splitTransferBankAccountId=? WHERE id=?")
                    ->execute([$splitCashAmount,$splitTransferAmount,$splitTransferBankAccountId,(string)$booking['id']]);
            }else{
                $pdo->prepare("UPDATE bookings SET paymentStatus='paid',paymentMethod=?,bankAccountId=?,isSplitPayment=0,splitCashAmount=NULL,splitTransferAmount=NULL,splitTransferBankAccountId=NULL WHERE id=?")
                    ->execute([$method,$bankAccountId,(string)$booking['id']]);
            }
        } elseif($remaining<=0.01) {
            $pdo->prepare("UPDATE bookings SET paymentStatus='paid',financialClosureStatus=CASE WHEN financialClosureStatus='pending' THEN 'settled' ELSE financialClosureStatus END,financialClosureBalance=0 WHERE id=?")->execute([(string)$booking['id']]);
        }
        $depositSummary=tamasyaSecurityDepositSummary($pdo,(string)$booking['id'],true);
        if((float)($depositSummary['heldBalance']??0)>0.01){
            tamasyaSettleGuestSecurityDepositInTransaction($pdo,$staff,(string)$booking['id'],[
                'disposition'=>'hold','reason'=>'Checkout Telegram: deposito ditahan menunggu pemeriksaan kamar/kunci.',
                'operationId'=>'gsd_hold_'.substr(hash('sha256',$operationId),0,70)
            ],'telegram');
        }
        $financialClosure=null;
        if($deferFinancialClosure){
            $financialClosure=tamasyaMarkBookingFinancialClosurePending($pdo,$staff,(string)$booking['id'],$operationId,$financialClosureReason,'telegram');
        }

        // Ledger harus seimbang atau memiliki piutang checkout operasional yang diaudit sebelum lifecycle menyentuh
        // kunci/PIN, status kamar, laporan lapangan, dan housekeeping.
        assertBookingLedgerInvariant($pdo,(string)$booking['id'],$staff,'telegram',false);
        $operational = finalizeCheckoutOperationalLifecycle($pdo,$staff,$booking,'telegram',[
            'keyDisposition'=>$keyDisposition,'keyReason'=>$keyReason,
            'checkoutMode'=>$keyDisposition==='missing'?'without_notice':($vacancyReportId?'field_verified':'normal'),
            'operationId'=>$operationId,'vacancyReportId'=>$vacancyReportId
        ]);
        assertBookingLedgerInvariant($pdo,(string)$booking['id'],$staff,'telegram',false);

        $notifId = 'n_tg_checkout_'.substr(hash('sha256',$operationId),0,24);
        $notifMsg = "Check-out Telegram: Kamar {$roomNumber} (".(string)$booking['guestName'].") selesai dan ledger telah direkonsiliasi.";
        $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'booking')")
            ->execute([$notifId,$notifMsg]);
        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([(string)$staff['id']]);
        completeTelegramMutation($pdo,$operationId,['bookingId'=>(string)$booking['id'],'remainingPaid'=>$deferFinancialClosure?0:$remaining,'financialClosure'=>$financialClosure]);
        bumpServerRevision($pdo);
        $bookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
        $bookingStmt->execute([(string)$booking['id']]);
        $updatedBooking=$bookingStmt->fetch(PDO::FETCH_ASSOC) ?: $booking;
        $roomStmt=$pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1");
        $roomStmt->execute([$roomNumber]);
        $updatedRoom=$roomStmt->fetch(PDO::FETCH_ASSOC) ?: $room;
        $smartLockResult=null;
        if ($ownsTransaction) {
            tamasyaFinancialCommit($pdo);
            try{$smartLockResult=processDeferredCheckoutSmartLockJob($pdo,(string)($operational['smartLockJobId']??''),$staff,'telegram');}
            catch(Throwable $postCommitError){
                error_log('[Telegram Checkout] post-commit smart-lock: '.$postCommitError->getMessage());
                $smartLockResult=['jobId'=>$operational['smartLockJobId']??null,'status'=>'check_required','message'=>'Checkout selesai, tetapi status smart-lock perlu diperiksa.'];
            }
        }
        return ['duplicate'=>false,'booking'=>$updatedBooking,'room'=>$updatedRoom,'remainingPaid'=>$deferFinancialClosure?0:$remaining,'financialClosure'=>$financialClosure,'operational'=>$operational,'smartLock'=>$smartLockResult];
    } catch (TelegramDuplicateOperationException $duplicate) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE roomNumber=? ORDER BY updatedAt DESC,createdAt DESC LIMIT 1");
        $stmt->execute([$roomNumber]);
        return ['duplicate'=>true,'booking'=>$stmt->fetch(PDO::FETCH_ASSOC) ?: ['roomNumber'=>$roomNumber],'remainingPaid'=>0.0];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * V137 canonical root workflow:
 * allocate an existing manual cash receipt to room/extension/extra without
 * converting or overwriting the original transaction. One receipt can have
 * several allocations, but the active allocation total may never exceed the
 * cash amount. This is the authoritative path for historical one-year entry.
 */
/**
 * Kunci dan rangkum seluruh rincian aktif milik satu transaksi.
 *
 * Aggregate SELECT ... FOR UPDATE tidak konsisten mengunci baris pada seluruh
 * varian MySQL/MariaDB shared-hosting. Karena itu baris allocation dibaca dan
 * dikunci satu per satu, lalu total serta booking unik dihitung di PHP.
 */
function tamasyaActiveTransactionAllocationState(PDO $pdo, string $transactionId, bool $lockRows=true): array {
    $sql = "SELECT amount, booking_id, transaction_booking_link_added, reporting_only FROM transaction_allocations WHERE transaction_id=? AND status='active'";
    if ($lockRows) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$transactionId]);
    $allocatedAmount = 0.0;
    $bookingIds = [];
    $operationalBookingIds = [];
    $reportingOnlyAmount = 0.0;
    $operationalAmount = 0.0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rowAmount=(float)($row['amount'] ?? 0);
        $allocatedAmount += $rowAmount;
        $bookingId = trim((string)($row['booking_id'] ?? ''));
        if ($bookingId !== '') $bookingIds[$bookingId] = true;
        if(!empty($row['reporting_only'])){
            $reportingOnlyAmount += $rowAmount;
        }else{
            $operationalAmount += $rowAmount;
            if($bookingId!=='')$operationalBookingIds[$bookingId]=true;
        }
    }
    $uniqueBookingIds = array_keys($bookingIds);
    $uniqueOperationalBookingIds=array_keys($operationalBookingIds);
    sort($uniqueOperationalBookingIds,SORT_STRING);
    sort($uniqueBookingIds, SORT_STRING);
    // Link booking yang dibuat oleh workflow allocation dibedakan dari transaksi
    // yang sejak awal sudah merupakan pembayaran booking. Riwayat semua status
    // dibaca karena allocation pertama dapat sudah di-void sementara allocation
    // lanjutan masih aktif.
    $workflowLinkStmt = $pdo->prepare("SELECT COALESCE(MAX(transaction_booking_link_added),0) FROM transaction_allocations WHERE transaction_id=?");
    $workflowLinkStmt->execute([$transactionId]);
    $workflowBookingLinkAdded = (int)$workflowLinkStmt->fetchColumn() === 1;
    return [
        'allocated_amount' => round($allocatedAmount, 2),
        'booking_id' => $uniqueBookingIds[0] ?? null,
        'booking_count' => count($uniqueBookingIds),
        'booking_ids' => $uniqueBookingIds,
        'operational_allocated_amount'=>round($operationalAmount,2),
        'reporting_only_allocated_amount'=>round($reportingOnlyAmount,2),
        'operational_booking_id'=>$uniqueOperationalBookingIds[0]??null,
        'operational_booking_count'=>count($uniqueOperationalBookingIds),
        'operational_booking_ids'=>$uniqueOperationalBookingIds,
        'workflow_booking_link_added' => $workflowBookingLinkAdded,
    ];
}

/** True only when one component carries an auditable gross=base+tax snapshot. */
function tamasyaAllocationTaxSnapshotComplete(array $row,float $amount): bool {
    $status=strtolower(trim((string)($row['tax_snapshot_status']??$row['taxSnapshotStatus']??'')));
    if(!in_array($status,['confirmed','complete','resolved','not_applicable'],true))return false;
    $base=$row['base_amount']??$row['baseAmount']??null;
    $tax=$row['tax_amount']??$row['taxAmount']??null;
    $rate=$row['tax_rate']??$row['taxRate']??null;
    return is_numeric($base)&&is_numeric($tax)&&is_numeric($rate)
        && (float)$base>=0 && (float)$tax>=0
        && moneyMatches((float)$base+(float)$tax,$amount,0.01);
}

/**
 * Resolve PBJT for ONE booking-allocation component.
 *
 * Historical receipts are intentionally component-aware: a parent receipt that
 * was initially classified as room/generic must not donate its tax rate to a
 * later `extra` allocation.  Rule-by-date is used for the target component.
 * A historical document snapshot may be proportionally reused only when the
 * source transaction and target allocation have the SAME tax kind; otherwise
 * there is no evidence that the document's aggregate tax belongs to that
 * component and the operation fails closed (reporting-only archive may remain
 * explicitly unresolved).
 */
function tamasyaResolveBookingAllocationTaxSnapshot(
    PDO $pdo,array $transaction,array $booking,string $allocationType,float $amount,bool $reportingOnly=false
): array {
    $amount=max(0.0,round($amount,2));
    $allocationType=strtolower(trim($allocationType));
    $taxKind=$allocationType==='room'?'room':$allocationType;
    if(!in_array($taxKind,['room','extension','extra'],true))throw new InvalidArgumentException('Jenis pajak alokasi booking tidak didukung.');
    $bookingSource=trim((string)($booking['bookingSource']??$transaction['bookingSource']??'Direct'))?:'Direct';
    $date=validIsoDate((string)($transaction['date']??''))?(string)$transaction['date']:date('Y-m-d');
    $historical=tamasyaTransactionIsHistorical($transaction);

    if(!$historical){
        $rule=resolveConfiguredTaxRule($pdo,$bookingSource,$taxKind,$date);
        if(empty($rule['matched']))throw new RuntimeException('Alokasi transaksi live ditolak karena tidak ada Aturan Pajak aktif untuk sumber '.$bookingSource.', jenis '.$taxKind.', tanggal '.$date.'. Rule 0%/tidak kena pajak harus dibuat eksplisit.');
        $breakdown=calculateInclusiveTaxBreakdown($amount,(float)$rule['rate']);
        return $breakdown+['taxSnapshotStatus'=>'confirmed','taxSource'=>'live_rule','taxRuleId'=>$rule['ruleId']??null,'taxRuleName'=>$rule['ruleName']??null];
    }

    $snapshotStatus=strtolower(trim((string)($transaction['taxSnapshotStatus']??'')));
    $snapshotSource=strtolower(trim((string)($transaction['taxSource']??'')));
    $sourceKind=tamasyaInferTransactionTaxKind($transaction);
    $explicitUnknown=$snapshotStatus==='unresolved'||$snapshotSource==='unresolved';
    if($explicitUnknown){
        if($reportingOnly)return ['baseAmount'=>$amount,'taxAmount'=>0.0,'taxRate'=>0.0,'taxSnapshotStatus'=>'unresolved','taxSource'=>'unresolved','taxRuleId'=>null,'taxRuleName'=>null];
        throw new RuntimeException('PBJT transaksi historis sumber masih belum diketahui. Selesaikan snapshot pajak transaksi terlebih dahulu sebelum mengalokasikannya ke booking.');
    }

    // Explicit paper/document evidence remains immutable only for the same
    // economic component. It cannot be silently copied from room to extra.
    if($snapshotSource==='historical_document' && $sourceKind===$taxKind){
        $sourceTax=tamasyaNormalizeHistoricalTaxSnapshot($transaction,(float)$transaction['amount'],true);
        $sourceAmount=max(0.01,(float)$transaction['amount']);
        $base=round((float)$sourceTax['baseAmount']*($amount/$sourceAmount),2);
        $tax=round(max(0.0,$amount-$base),2);
        return ['baseAmount'=>$base,'taxAmount'=>$tax,'taxRate'=>(float)$sourceTax['taxRate'],
            'taxSnapshotStatus'=>'confirmed','taxSource'=>'historical_document','taxRuleId'=>null,'taxRuleName'=>null];
    }

    // Canonical historical allocation is rule-first BY TARGET COMPONENT.
    // This is the critical guard for Layanan Extra: target kind `extra` must
    // resolve the explicit extra rule, not inherit a room/generic parent rate.
    $rule=resolveConfiguredTaxRule($pdo,$bookingSource,$taxKind,$date);
    if(!empty($rule['matched'])){
        $breakdown=calculateInclusiveTaxBreakdown($amount,(float)$rule['rate']);
        return $breakdown+['taxSnapshotStatus'=>'confirmed','taxSource'=>'historical_allocation_rule','taxRuleId'=>$rule['ruleId']??null,'taxRuleName'=>$rule['ruleName']??null];
    }

    // If the exact same component was snapshotted previously, preserving that
    // immutable snapshot is safer than inventing a new 0% when its old rule was
    // later disabled/removed.
    if($sourceKind===$taxKind){
        try{
            $sourceTax=tamasyaNormalizeHistoricalTaxSnapshot($transaction,(float)$transaction['amount'],true);
            $sourceAmount=max(0.01,(float)$transaction['amount']);
            $base=round((float)$sourceTax['baseAmount']*($amount/$sourceAmount),2);
            $tax=round(max(0.0,$amount-$base),2);
            return ['baseAmount'=>$base,'taxAmount'=>$tax,'taxRate'=>(float)$sourceTax['taxRate'],
                'taxSnapshotStatus'=>'confirmed','taxSource'=>$snapshotSource?:'historical_snapshot','taxRuleId'=>trim((string)($transaction['taxRuleId']??''))?:null,'taxRuleName'=>trim((string)($transaction['taxRuleName']??''))?:null];
        }catch(Throwable $ignored){}
    }

    if($reportingOnly)return ['baseAmount'=>$amount,'taxAmount'=>0.0,'taxRate'=>0.0,'taxSnapshotStatus'=>'unresolved','taxSource'=>'unresolved','taxRuleId'=>null,'taxRuleName'=>null];
    throw new RuntimeException('Alokasi historical_import sebagai '.$taxKind.' tidak mempunyai Aturan Pajak pada '.$date.' dan snapshot transaksi induk tidak dapat dipakai untuk jenis komponen yang berbeda. Buat rule historis eksplisit (termasuk 0%/tidak kena pajak) atau koreksi klasifikasi transaksi sumber.');
}

/**
 * Rebuild transaction-level tax from explicit operational allocations plus the
 * still-unallocated residual.  Tax reports read transactions.taxAmount, so the
 * parent snapshot must agree with component detail instead of keeping a stale
 * room/generic rate after a receipt is fully classified as Layanan Extra.
 */
function tamasyaReconcileTransactionTaxFromAllocations(PDO $pdo,string $transactionId,array $actor,string $source='web'): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Rekonsiliasi PBJT alokasi wajib berada dalam transaksi database.');
    $txStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
    $txStmt->execute([$transactionId]);
    $before=$txStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$before)throw new RuntimeException('Transaksi sumber rekonsiliasi PBJT tidak ditemukan.');
    $gross=max(0.0,round((float)($before['amount']??0),2));
    if($gross<=0)return ['before'=>$before,'after'=>$before,'changed'=>false,'allocated'=>0.0];

    // Reporting-only archives deliberately do not alter the transaction's
    // historical cash/tax snapshot.
    $a=$pdo->prepare("SELECT * FROM transaction_allocations WHERE transaction_id=? AND status='active' AND COALESCE(reporting_only,0)=0 ORDER BY created_at,id FOR UPDATE");
    $a->execute([$transactionId]);
    $rows=$a->fetchAll(PDO::FETCH_ASSOC)?:[];
    if(!$rows){
        if(strtolower(trim((string)($before['taxSource']??'')))!=='booking_component_allocation')return ['before'=>$before,'after'=>$before,'changed'=>false,'allocated'=>0.0];
        // A previously fully-classified receipt was unallocated. Restore the
        // transaction's own canonical historical/live rule; if historical rule
        // evidence no longer exists, fail safe to unresolved instead of keeping
        // the stale component aggregate.
        try{
            $payload=$before;
            if(tamasyaTransactionIsHistorical($before))$payload['historicalTaxMode']='rule_by_date';
            $restore=tamasyaResolveTransactionTaxSnapshot($pdo,$payload,$gross,tamasyaTransactionIsHistorical($before));
        }catch(Throwable $e){
            if(!tamasyaTransactionIsHistorical($before))throw $e;
            $restore=['baseAmount'=>null,'taxAmount'=>null,'taxRate'=>null,'taxSnapshotStatus'=>'unresolved','taxSource'=>'unresolved','taxRuleId'=>null];
        }
        tamasyaMutateFinancialTransaction($pdo,$transactionId,[
            'baseAmount'=>$restore['baseAmount'],'taxAmount'=>$restore['taxAmount'],'taxRate'=>$restore['taxRate'],
            'taxSnapshotStatus'=>$restore['taxSnapshotStatus'],'taxSource'=>$restore['taxSource'],'taxRuleId'=>$restore['taxRuleId']??null,
            'taxRuleName'=>$restore['taxRuleName']??tamasyaTaxRuleNameById($pdo,$restore['taxRuleId']??null),
        ],$actor,'tax_projection','tax_snapshot',['source'=>$source,'lockRows'=>true,'requireAll'=>true,'requireUnlocked'=>true]);
        $txStmt->execute([$transactionId]);$after=$txStmt->fetch(PDO::FETCH_ASSOC)?:$before;
        return ['before'=>$before,'after'=>$after,'changed'=>true,'allocated'=>0.0];
    }

    $allocated=0.0;$baseTotal=0.0;$taxTotal=0.0;$rates=[];$ruleIds=[];
    foreach($rows as $row){
        $amt=max(0.0,round((float)($row['amount']??0),2));
        if($amt<=0)continue;
        if(!tamasyaAllocationTaxSnapshotComplete($row,$amt))throw new RuntimeException('Snapshot PBJT rincian booking belum lengkap; transaksi induk tidak dapat direkonsiliasi.');
        $allocated=round($allocated+$amt,2);
        $baseTotal=round($baseTotal+(float)($row['base_amount']??0),2);
        $taxTotal=round($taxTotal+(float)($row['tax_amount']??0),2);
        $rates[]=round((float)($row['tax_rate']??0),4);
        $rid=trim((string)($row['tax_rule_id']??''));if($rid!=='')$ruleIds[]=$rid;
    }
    if($allocated>$gross+0.01)throw new RuntimeException('Total alokasi transaksi melebihi nominal receipt.');
    $remaining=round(max(0.0,$gross-$allocated),2);
    if($remaining>0.01){
        $residual=null;
        $historical=tamasyaTransactionIsHistorical($before);
        $sourceKind=tamasyaInferTransactionTaxKind($before);
        $sourceLabel=trim((string)($before['bookingSource']??'Direct'))?:'Direct';
        $date=validIsoDate((string)($before['date']??''))?(string)$before['date']:date('Y-m-d');
        $beforeSource=strtolower(trim((string)($before['taxSource']??'')));
        if($historical && $beforeSource==='historical_document'){
            try{
                $snap=tamasyaNormalizeHistoricalTaxSnapshot($before,$gross,true);
                $base=round((float)$snap['baseAmount']*($remaining/$gross),2);
                $residual=['baseAmount'=>$base,'taxAmount'=>round($remaining-$base,2),'taxRate'=>(float)$snap['taxRate'],'taxRuleId'=>null];
            }catch(Throwable $ignored){}
        }
        if($residual===null){
            $rule=resolveConfiguredTaxRule($pdo,$sourceLabel,$sourceKind,$date);
            if(!empty($rule['matched'])){
                $residual=calculateInclusiveTaxBreakdown($remaining,(float)$rule['rate'])+['taxRuleId'=>$rule['ruleId']??null];
            }elseif($historical){
                // Known allocations remain auditable, while the unclassified
                // residual is explicitly unknown. Do not publish a guessed total.
                tamasyaMutateFinancialTransaction($pdo,$transactionId,[
                    'baseAmount'=>null,'taxAmount'=>null,'taxRate'=>null,'taxSnapshotStatus'=>'unresolved','taxSource'=>'unresolved','taxRuleId'=>null,
                    'taxRuleName'=>null,
                ],$actor,'tax_projection','tax_snapshot',['source'=>$source,'lockRows'=>true,'requireAll'=>true,'requireUnlocked'=>true]);
                $txStmt->execute([$transactionId]);$after=$txStmt->fetch(PDO::FETCH_ASSOC)?:$before;
                return ['before'=>$before,'after'=>$after,'changed'=>true,'allocated'=>$allocated,'remaining'=>$remaining,'unresolvedResidual'=>true];
            }else{
                throw new RuntimeException('Sisa transaksi live tidak mempunyai Aturan Pajak canonical.');
            }
        }
        $baseTotal=round($baseTotal+(float)$residual['baseAmount'],2);
        $taxTotal=round($taxTotal+(float)$residual['taxAmount'],2);
        $rates[]=round((float)$residual['taxRate'],4);
        $rid=trim((string)($residual['taxRuleId']??''));if($rid!=='')$ruleIds[]=$rid;
    }
    if(!moneyMatches($baseTotal+$taxTotal,$gross,0.01))throw new RuntimeException('Agregat PBJT alokasi tidak sama dengan nominal transaksi.');
    $uniqueRates=array_values(array_unique(array_map(static fn($v)=>number_format((float)$v,4,'.',''),$rates)));
    $rate=count($uniqueRates)===1?(float)$rates[0]:null;
    $uniqueRules=array_values(array_unique($ruleIds));
    // A mixed component transaction has no single rule ID even when one of its
    // components is document-based/no-rule.
    $ruleId=(count($uniqueRules)===1 && count($ruleIds)===count($rates))?$uniqueRules[0]:null;
    tamasyaMutateFinancialTransaction($pdo,$transactionId,[
        'baseAmount'=>$baseTotal,'taxAmount'=>$taxTotal,'taxRate'=>$rate,
        'taxSnapshotStatus'=>'confirmed','taxSource'=>'booking_component_allocation','taxRuleId'=>$ruleId,
        'taxRuleName'=>$ruleId!==null?tamasyaTaxRuleNameById($pdo,$ruleId):null,
    ],$actor,'tax_projection','tax_snapshot',['source'=>$source,'lockRows'=>true,'requireAll'=>true,'requireUnlocked'=>true]);
    $txStmt->execute([$transactionId]);$after=$txStmt->fetch(PDO::FETCH_ASSOC)?:$before;
    $changed=!moneyMatches((float)($before['baseAmount']??0),(float)($after['baseAmount']??0),0.01)
        || !moneyMatches((float)($before['taxAmount']??0),(float)($after['taxAmount']??0),0.01)
        || (($before['taxRate']??null)!==($after['taxRate']??null))
        || (string)($before['taxSource']??'')!==(string)($after['taxSource']??'')
        || (string)($before['taxRuleId']??'')!==(string)($after['taxRuleId']??'');
    return ['before'=>$before,'after'=>$after,'changed'=>$changed,'allocated'=>$allocated,'remaining'=>$remaining];
}

/** Refresh tax snapshots on active allocation rows after an audited transaction edit. */
function tamasyaRefreshTransactionAllocationTaxSnapshots(PDO $pdo,string $transactionId,array $snapshot,array $actor=[],string $source='web'): array {
    if(!$pdo->inTransaction()) throw new RuntimeException('Refresh snapshot alokasi wajib berada dalam transaksi database.');
    $txStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
    $txStmt->execute([$transactionId]);
    $transaction=$txStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$transaction)return [];
    $stmt=$pdo->prepare("SELECT * FROM transaction_allocations WHERE transaction_id=? AND status='active' FOR UPDATE");
    $stmt->execute([$transactionId]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    if(!$rows)return [];
    $sourceAmount=max(0.01,(float)($transaction['amount']??1));
    $bookingIds=[];$bookingCache=[];
    $update=$pdo->prepare("UPDATE transaction_allocations SET base_amount=?,tax_amount=?,tax_rate=?,tax_snapshot_status=?,tax_source=?,tax_rule_id=? WHERE id=?");
    foreach($rows as $row){
        $allocationAmount=round((float)($row['amount']??0),2);
        if($allocationAmount<=0)continue;
        $bookingId=trim((string)($row['booking_id']??''));
        if($bookingId!==''&&!isset($bookingCache[$bookingId])){
            $b=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$b->execute([$bookingId]);$bookingCache[$bookingId]=$b->fetch(PDO::FETCH_ASSOC)?:[];
        }
        $reportingOnly=!empty($row['reporting_only']);
        if($reportingOnly){
            // Reporting-only archive is a classification overlay and must not
            // change the old PBJT snapshot. Keep its proportional share.
            $status=(string)($snapshot['taxSnapshotStatus']??'unresolved');
            if($status==='confirmed' && $snapshot['baseAmount']!==null && $snapshot['taxAmount']!==null && $snapshot['taxRate']!==null){
                $base=round((float)$snapshot['baseAmount']*($allocationAmount/$sourceAmount),2);
                $tax=round($allocationAmount-$base,2);$rate=(float)$snapshot['taxRate'];
            }else{$base=$allocationAmount;$tax=0.0;$rate=0.0;}
            $taxSnapshot=['baseAmount'=>$base,'taxAmount'=>$tax,'taxRate'=>$rate,'taxSnapshotStatus'=>$status,
                'taxSource'=>(string)($snapshot['taxSource']??'unresolved'),'taxRuleId'=>trim((string)($snapshot['taxRuleId']??''))?:null];
        }elseif(strtolower(trim((string)($row['tax_source']??'')))==='booking_extra_snapshot' && tamasyaAllocationTaxSnapshotComplete($row,$allocationAmount)){
            $taxSnapshot=['baseAmount'=>(float)$row['base_amount'],'taxAmount'=>(float)$row['tax_amount'],'taxRate'=>(float)$row['tax_rate'],
                'taxSnapshotStatus'=>(string)$row['tax_snapshot_status'],'taxSource'=>(string)$row['tax_source'],'taxRuleId'=>trim((string)($row['tax_rule_id']??''))?:null];
        }else{
            $taxSnapshot=tamasyaResolveBookingAllocationTaxSnapshot($pdo,$transaction,$bookingCache[$bookingId]??[],(string)($row['allocation_type']??'room'),$allocationAmount,false);
        }
        $update->execute([$taxSnapshot['baseAmount'],$taxSnapshot['taxAmount'],$taxSnapshot['taxRate'],$taxSnapshot['taxSnapshotStatus'],$taxSnapshot['taxSource'],$taxSnapshot['taxRuleId']??null,$row['id']]);
        if(!$reportingOnly&&$bookingId!=='')$bookingIds[$bookingId]=true;
    }
    tamasyaReconcileTransactionTaxFromAllocations($pdo,$transactionId,$actor,$source);
    return array_keys($bookingIds);
}

/**
 * OFFLINE OPERATIONAL MODE — canonical replay of one queued lifecycle operation.
 *
 * A device that performed check-in or fully-paid checkout while the server was
 * unreachable queues an entry {operationId, bookingId, kind, actorName, capturedAt,
 * paymentSnapshot}. On reconnect the sync endpoint feeds each entry here. This
 * function NEVER bypasses canonical gates: check-in goes through
 * tamasyaR3EvaluateCheckInEligibility (FIX28 shift invariant included) and checkout
 * goes through exactly the same validations the online bookings-status workflow uses
 * (ledger totals, paid-only guard, operational lifecycle). The caller owns the DB
 * transaction/savepoint; this function only performs the mutation.
 *
 * Returns ['status'=>'applied'|'duplicate'|'error', ...].
 */
function tamasyaApplyQueuedLifecycleOp(PDO $pdo, array $loggedInStaff, array $op): array {
    $kind = strtolower(trim((string)($op['kind'] ?? '')));
    $bookingId = trim((string)($op['bookingId'] ?? ''));
    $operationId = trim((string)($op['operationId'] ?? ''));
    if (!in_array($kind, ['check_in', 'checkout'], true)) {
        return ['status' => 'error', 'error' => 'Jenis operasi offline tidak dikenal. Hanya check_in atau checkout yang dapat direplay.'];
    }
    // Offline check-in of a reservation created while disconnected intentionally
    // has no server bookingId yet. It is paired later through pendingBookingKey.
    // Checkout, however, must always carry a canonical bookingId.
    if ($bookingId === '' && $kind !== 'check_in') {
        return ['status' => 'error', 'error' => 'Operasi offline tanpa booking ID tidak dapat diproses.'];
    }
    // A locally-created reservation may still have no server ID (the current web
    // client queues bookingId=null) or may carry its temporary b_pending_* ID.
    // Resolve the stable pendingBookingKey BEFORE looking up bookingId.
    if ($kind === 'check_in' && ($bookingId === '' || str_starts_with($bookingId, 'b_pending_'))) {
        $pendingKey = trim((string)($op['pendingBookingKey'] ?? ''));
        if ($pendingKey === '') {
            return ['status' => 'error', 'error' => 'Operasi check-in offline tanpa booking final dan tanpa pendingBookingKey tidak dapat dipasangkan ke reservasi manapun.'];
        }
        $keyParts = explode('::', $pendingKey);
        if (count($keyParts) < 3 || trim((string)$keyParts[0]) === '' || trim((string)$keyParts[1]) === '' || !validIsoDate(trim((string)$keyParts[2]))) {
            return ['status' => 'error', 'error' => 'pendingBookingKey tidak valid untuk pencocokan reservasi offline.'];
        }
        $lookup = $pdo->prepare("SELECT * FROM bookings WHERE guestName=? AND roomNumber=? AND checkIn=? ORDER BY createdAt DESC LIMIT 1 FOR UPDATE");
        $lookup->execute([trim((string)$keyParts[0]), trim((string)$keyParts[1]), trim((string)$keyParts[2])]);
        $resolvedBooking = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$resolvedBooking) {
            return ['status' => 'error', 'error' => 'Reservasi offline belum tersinkron ke server; coba sinkron ulang sebelum replay check-in.'];
        }
        $booking = $resolvedBooking;
        $bookingId = (string)$booking['id'];
    } else {
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            return ['status' => 'error', 'error' => 'Booking untuk operasi offline tidak ditemukan di server.'];
        }
    }
    $currentStatus = strtolower((string)($booking['status'] ?? ''));
    $capturedAt = trim((string)($op['capturedAt'] ?? ''));
    $actorNote = trim((string)($op['actorName'] ?? '')) ?: currentStaffLabel($loggedInStaff);

    if ($kind === 'check_in') {
        if ($currentStatus === 'active') {
            return ['status' => 'duplicate', 'message' => 'Check-in sudah pernah diproses sebelumnya; tidak ada perubahan ganda.', 'bookingId' => $bookingId];
        }
        if ($currentStatus !== 'reserved') {
            return ['status' => 'error', 'error' => 'Booking tidak lagi berstatus reserved sehingga check-in tertunda tidak dapat diterapkan. Status saat ini: ' . $currentStatus];
        }
        // Canonical FIX28 gate: shift policy, stay window, deposit, blockers, overlap.
        $eligibility = tamasyaR3EvaluateCheckInEligibility($pdo, $loggedInStaff, $booking);
        $stay = $eligibility['stayWindow'];
        $update = $pdo->prepare("UPDATE bookings SET status='active',actualCheckInAt=CURRENT_TIMESTAMP,checkoutDueAt=?,version=version+1,updatedBy=?,updatedSource='offline-replay' WHERE id=? AND status='reserved'");
        $update->execute([$stay['checkoutDueAt'],(string)$loggedInStaff['id'],$bookingId]);
        if ($update->rowCount() !== 1) {
            return ['status' => 'error', 'error' => 'Status reservasi berubah saat replay check-in offline; operasi ditahan untuk tinjauan.'];
        }
        $roomNumber = (string)$booking['roomNumber'];
        tamasyaReconcileRoomOperationalProjection($pdo,$loggedInStaff,$roomNumber,'offline-replay-checkin');
        $pdo->prepare("UPDATE room_access_control SET current_booking_id=?,physical_key_status='secured',last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
            ->execute([$bookingId,(string)$loggedInStaff['id'],$roomNumber]);
        tamasyaSyncGuestServiceBookingLifecycle($pdo,$loggedInStaff,(string)$bookingId,'active',$roomNumber,'offline-replay-checkin');
        writeRequiredEnterpriseAudit(
            $pdo,$loggedInStaff,
            'Replay check-in offline'.($capturedAt!==''?' (dicatat perangkat '.$capturedAt.' oleh '.$actorNote.')':''),
            'booking',(string)$bookingId,
            tamasyaBookingAuditSnapshot($booking),
            tamasyaBookingAuditSnapshot($pdo->query("SELECT * FROM bookings WHERE id=".$pdo->quote($bookingId)." LIMIT 1")->fetch(PDO::FETCH_ASSOC)),
            'offline-sync'
        );
        return ['status' => 'applied', 'kind' => 'check_in', 'bookingId' => $bookingId];
    }

    // kind === 'checkout'
    if ($currentStatus === 'completed') {
        return ['status' => 'duplicate', 'message' => 'Checkout sudah pernah diproses sebelumnya; tidak ada perubahan ganda.', 'bookingId' => $bookingId];
    }
    if ($currentStatus !== 'active') {
        return ['status' => 'error', 'error' => 'Checkout tertunda hanya berlaku untuk tamu yang sudah check-in. Status booking: ' . $currentStatus];
    }
    $__f = tamasyaResolveDomainSupportFile('030_booking_finance.php'); if ($__f) { require_once $__f; } unset($__f);
    $totals = bookingLedgerTotals($pdo, $bookingId);
    $totalAmount = round(max(0.0,(float)($booking['totalAmount'] ?? 0)),2);
    $remaining = max(0.0, round($totalAmount - round((float)($totals['net'] ?? 0),2),2));
    if ($remaining > 0.01) {
        return ['status' => 'conflict', 'conflict' => [
            'id' => 'lifecycle_op:' . ($operationId !== '' ? $operationId : $bookingId . ':checkout'),
            'entityType' => 'lifecycle_op','entityId' => $operationId !== '' ? $operationId : $bookingId,
            'localData' => $op,'serverData' => ['bookingId'=>$bookingId,'remaining'=>$remaining],
            'message' => 'Checkout offline ditolak karena masih ada sisa tagihan Rp '.number_format($remaining,0,',','.').'. Selesaikan pembayaran online pada shift aktif.'
        ]];
    }
    finalizeCheckoutOperationalLifecycle($pdo,$loggedInStaff,$booking,'offline-sync',[
        'keyDisposition'=>normalizeCheckoutKeyDisposition($op['keyDisposition'] ?? null),
        'keyReason'=>trim((string)($op['keyReason'] ?? '')),
        'checkoutMode'=>'normal',
        'operationId'=>$operationId !== '' ? $operationId : generateServerId('op_offline_checkout')
    ]);
    $pdo->prepare("UPDATE bookings SET status='completed',actualCheckOutAt=CURRENT_TIMESTAMP,paymentStatus='paid',financialClosureStatus=CASE WHEN financialClosureStatus='pending' THEN 'settled' ELSE financialClosureStatus END,financialClosureBalance=0,version=version+1,updatedBy=?,updatedSource='offline-replay' WHERE id=? AND status='active'")
        ->execute([(string)$loggedInStaff['id'],$bookingId]);
    recalculateBookingFinancials($pdo,$bookingId,true);
    writeRequiredEnterpriseAudit(
        $pdo,$loggedInStaff,
        'Replay checkout lunas offline'.($capturedAt!==''?' (dicatat perangkat '.$capturedAt.' oleh '.$actorNote.')':''),
        'booking',(string)$bookingId,
        tamasyaBookingAuditSnapshot($booking),
        tamasyaBookingAuditSnapshot($pdo->query("SELECT * FROM bookings WHERE id=".$pdo->quote($bookingId)." LIMIT 1")->fetch(PDO::FETCH_ASSOC)),
        'offline-sync'
    );
    return ['status' => 'applied', 'kind' => 'checkout', 'bookingId' => $bookingId];
}

function processManualBookingAction($pdo, $loggedInStaff, $input, $updatedSource = 'web', $resolutionChoice = null) {
    if (!$pdo instanceof PDO) throw new RuntimeException('Database workflow booking tidak valid.');
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'alokasi transaksi ke booking');
    if (!$pdo->inTransaction()) throw new RuntimeException('Workflow booking manual/historical wajib berada dalam transaksi database.');
    $transactionId = trim((string)($input['transactionId'] ?? ''));
    $bookingId = trim((string)($input['bookingId'] ?? ''));
    $bookingAction = strtolower(trim((string)($input['action'] ?? '')));
    $operationId = trim((string)($input['operationId'] ?? ($GLOBALS['tamasya_request_operation_id'] ?? ''))) ?: generateServerId('op_booking_allocation');
    $amount = round((float)($input['amount'] ?? 0), 2);
    $date = (string)($input['date'] ?? date('Y-m-d'));

    if ($transactionId === '' || $bookingId === '' || !in_array($bookingAction, ['room','extension','extra'], true) || $amount <= 0 || !validIsoDate($date)) {
        return ['status' => 'error', 'http' => 400, 'error' => 'Data alokasi kamar/layanan tidak lengkap atau tidak valid.'];
    }

    $existingAllocation = $pdo->prepare("SELECT * FROM transaction_allocations WHERE operation_id=? LIMIT 1 FOR UPDATE");
    $existingAllocation->execute([$operationId]);
    if ($allocated = $existingAllocation->fetch(PDO::FETCH_ASSOC)) {
        // A retry must refer to the same receipt, booking, component and amount.
        // Do not acknowledge a different financial instruction as already applied.
        if ((string)$allocated['transaction_id'] !== $transactionId
            || (string)$allocated['booking_id'] !== $bookingId
            || (string)$allocated['allocation_type'] !== $bookingAction
            || !moneyMatches((float)$allocated['amount'], $amount, 0.001)
            || (array_key_exists('newCheckOutDate', $input)
                && trim((string)$input['newCheckOutDate']) !== (string)($allocated['new_check_out'] ?? ''))) {
            return ['status'=>'error','http'=>409,'error'=>'Operation ID alokasi sudah dipakai untuk rincian finansial berbeda.'];
        }
        return [
            'status'=>'duplicate','operationId'=>$operationId,'transactionId'=>$transactionId,
            'bookingId'=>$bookingId,'allocationId'=>$allocated['id'],'action'=>$allocated['allocation_type'],
            'amount'=>(float)$allocated['amount']
        ];
    }

    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$transactionId]);
    $oldTx = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmtBooking = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmtBooking->execute([$bookingId]);
    $booking = $stmtBooking->fetch(PDO::FETCH_ASSOC);
    if (!$booking) return ['status'=>'error','http'=>404,'error'=>'Booking tidak ditemukan.'];

    // An offline-created historical receipt may be allocated atomically before
    // its generic sync runs. Live money movements never enter this fallback.
    if (!$oldTx && is_array($input['originalTransaction'] ?? null)) {
        $original = $input['originalTransaction'];
        $originalAmount = round(max(0, (float)($original['amount'] ?? $amount)), 2);
        $origin=strtolower(trim((string)($original['recordOrigin']??'')));
        $reason=trim((string)($original['shiftExemptionReason']??''));
        $originalOperationId=trim((string)($original['operationId']??''));
        if ($origin!=='historical_import' || $originalAmount<=0 || strtolower((string)($original['type']??''))!=='income' || strlen($reason)<10 || $originalOperationId==='') {
            return ['status'=>'error','http'=>422,'error'=>'Snapshot offline untuk alokasi wajib berupa historical_import pemasukan dengan operationId dan alasan backfill yang valid.'];
        }
        $originalDate=validIsoDate((string)($original['date']??''))?(string)$original['date']:$date;
        if($originalDate>date('Y-m-d'))return ['status'=>'error','http'=>422,'error'=>'Tanggal historical_import tidak boleh berada di masa depan.'];
        // Normalize the offline historical settlement with the exact same rules as
        // generic /api/sync before creating the fallback row.  This path runs when
        // an offline backfill is allocated to a booking before its normal queue item
        // reaches the server, so dropping the split snapshot here would permanently
        // collapse cash+transfer into one settlement method.
        $originalBank=tamasyaValidateFinanceBankAccount($pdo,$original['bankAccountId']??null,true,true);
        $originalSplitCash=max(0.0,round((float)($original['splitCashAmount']??0),2));
        $originalSplitTransfer=max(0.0,round((float)($original['splitTransferAmount']??0),2));
        $originalSplitActive=!empty($original['isSplitPayment'])
            || strtolower(trim((string)($original['paymentMethod']??'')))==='split'
            || ($originalSplitCash>0 && $originalSplitTransfer>0);
        $originalSplitBank=trim((string)($original['splitTransferBankAccountId']??''));
        if($originalSplitActive && $originalSplitBank==='')$originalSplitBank=trim((string)($originalBank??''));
        if($originalSplitActive){
            if($originalSplitCash<=0 || $originalSplitTransfer<=0 || $originalSplitBank==='' || !moneyMatches($originalSplitCash+$originalSplitTransfer,$originalAmount,0.01)){
                return ['status'=>'error','http'=>422,'error'=>'Snapshot split offline tidak valid: tunai dan transfer harus keduanya lebih dari 0, mempunyai akun transfer/QRIS, dan jumlahnya sama dengan nominal transaksi.'];
            }
            tamasyaInferPaymentMethodFromAccount($pdo,$originalSplitBank,true,['transfer','qris']);
            $originalSplitBank=tamasyaValidateFinanceBankAccount($pdo,$originalSplitBank,true,true);
            $originalBank=null;
        }else{
            $originalSplitCash=0.0;$originalSplitTransfer=0.0;$originalSplitBank=null;
        }

        $taxPayload=$original;
        $taxPayload['date']=$originalDate;
        $taxPayload['bookingAction']=$bookingAction;
        $taxPayload['action']=$bookingAction;
        $taxPayload['bookingSource']=trim((string)($original['bookingSource']??'')) ?: (trim((string)($booking['bookingSource']??'')) ?: 'Direct');
        $taxPayload['bankAccountId']=$originalBank;
        $taxPayload['isSplitPayment']=$originalSplitActive?1:0;
        $taxPayload['splitCashAmount']=$originalSplitCash;
        $taxPayload['splitTransferAmount']=$originalSplitTransfer;
        $taxPayload['splitTransferBankAccountId']=$originalSplitBank;
        try{
            tamasyaValidateManualOtaReceivableContext(['type'=>'income','bankAccountId'=>$originalBank,'bookingSource'=>$taxPayload['bookingSource']],'historical_import');
            $historicalTax=tamasyaResolveTransactionTaxSnapshot($pdo,$taxPayload,$originalAmount,true);
        }
        catch(Throwable $taxError){return ['status'=>'error','http'=>tamasyaExceptionHttpStatus($taxError,422),'error'=>clientExceptionMessage('Validasi pajak/alokasi historis gagal',$taxError)];}
        $proof = !empty($original['proofUrl']) && !isPlaceholderProof($original['proofUrl']) ? $original['proofUrl'] : null;
        tamasyaPostFinancialTransaction($pdo,[
            'id'=>$transactionId,'type'=>'income','category'=>trim((string)($original['category']??'Pemasukan'))?:'Pemasukan',
            'categoryId'=>trim((string)($original['categoryId']??''))?:null,'categorySystemKey'=>trim((string)($original['categorySystemKey']??''))?:null,
            'subcategory'=>!empty($original['subcategory'])?$original['subcategory']:null,'subcategoryId'=>trim((string)($original['subcategoryId']??''))?:null,
            'subcategorySystemKey'=>trim((string)($original['subcategorySystemKey']??''))?:null,'roomNumber'=>!empty($original['roomNumber'])?$original['roomNumber']:null,
            'amount'=>$originalAmount,'date'=>$originalDate,'description'=>trim((string)($original['description']??'Transaksi historis manual'))?:'Transaksi historis manual',
            'proofUrl'=>$proof,'createdBy'=>currentStaffLabel($loggedInStaff),'bankAccountId'=>$originalBank,
            'isSplitPayment'=>$originalSplitActive?1:0,'splitCashAmount'=>$originalSplitCash,'splitTransferAmount'=>$originalSplitTransfer,'splitTransferBankAccountId'=>$originalSplitBank,
            'bookingId'=>null,'bookingSource'=>$taxPayload['bookingSource'],'baseAmount'=>$historicalTax['baseAmount'],'taxAmount'=>$historicalTax['taxAmount'],
            'taxRate'=>$historicalTax['taxRate'],'taxSnapshotStatus'=>$historicalTax['taxSnapshotStatus'],'taxSource'=>$historicalTax['taxSource'],
            'taxRuleId'=>$historicalTax['taxRuleId'],'taxNote'=>trim((string)($original['taxNote']??''))?:null,'transactionKind'=>'manual','isSystemGenerated'=>0,
            'operationId'=>$originalOperationId,'documentNumber'=>trim((string)($original['documentNumber']??''))?:nextDocumentNumber($pdo,'PAY',$originalDate),
            'recordOrigin'=>'historical_import','shiftExempt'=>1,'shiftExemptionReason'=>$reason,'importBatchId'=>trim((string)($original['importBatchId']??''))?:null,
            'sourceEntity'=>null,'sourceEntityId'=>null,'updatedBy'=>$loggedInStaff['id']??null,'updatedSource'=>$updatedSource,'version'=>max(1,(int)($original['version']??1))
        ],$loggedInStaff,'finance_manual',['source'=>$updatedSource]);
        $stmt->execute([$transactionId]);
        $oldTx=$stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$oldTx) return ['status'=>'error','http'=>404,'error'=>'Transaksi manual tidak ditemukan.'];
    $bookingTotalBefore = round(max(0.0, (float)($booking['totalAmount'] ?? 0)), 2);
    $cashTransactionAmount = round(max(0.0, (float)($oldTx['amount'] ?? 0)), 2);
    if (strtolower((string)($oldTx['type'] ?? '')) !== 'income') return ['status'=>'error','http'=>409,'error'=>'Hanya transaksi pemasukan yang dapat dialokasikan ke tagihan kamar.'];
    $bookingLifecycleStatus = strtolower((string)($booking['status'] ?? ''));
    $historicalTransaction=tamasyaTransactionIsHistorical($oldTx);
    $historicalReportingOnlyRequested=!empty($input['historicalReportingOnly']);
    $legacySnapshotBooking=strtolower((string)($booking['financialProjectionMode']??'live_ledger'))==='legacy_snapshot';
    $reportingOnlyHistoricalAllocation=$historicalTransaction
        && $historicalReportingOnlyRequested
        && ($bookingLifecycleStatus==='cancelled' || $legacySnapshotBooking);
    if($historicalReportingOnlyRequested && !$reportingOnlyHistoricalAllocation){
        return ['status'=>'error','http'=>409,'error'=>'Mode arsip laporan hanya berlaku untuk historical_import pada reservasi cancelled atau snapshot historis yang dikunci saat cutover.'];
    }
    if($historicalTransaction && $legacySnapshotBooking && !$reportingOnlyHistoricalAllocation){
        return ['status'=>'error','http'=>409,'error'=>'Reservasi ini merupakan snapshot historis produksi. Gunakan Mode Arsip/Reklasifikasi Receipt agar rincian tampil pada Log Kas tanpa mengubah total booking, arus kas, atau PBJT lama.'];
    }
    // Legacy operational list before reporting-only archive: ['reserved','active','completed']
    $allowedLifecycle=$historicalTransaction?['reserved','active','completed','cancelled']:['reserved','active'];
    if (!in_array($bookingLifecycleStatus, $allowedLifecycle, true) || ($bookingLifecycleStatus==='cancelled' && !$reportingOnlyHistoricalAllocation)) {
        return ['status'=>'error','http'=>409,'error'=>$historicalTransaction
            ? 'Reservasi cancelled hanya dapat menerima alokasi historical_import dalam mode arsip laporan tanpa mutasi operasional.'
            : 'Rincian pembayaran live hanya dapat diproses pada reservasi reserved/active.'];
    }

    // Satu penerimaan kas hanya boleh dialokasikan ke satu booking. Ini mencegah
    // uang tamu A tercampur ke booking tamu B pada penggunaan multi-user/offline.
    $allocationState = tamasyaActiveTransactionAllocationState($pdo, $transactionId, true);
    $existingAllocationBookings = $allocationState['booking_ids'];
    if ($existingAllocationBookings && ($existingAllocationBookings[0] ?? '') !== $bookingId) {
        return ['status'=>'error','http'=>409,'error'=>'Transaksi ini sudah dialokasikan ke booking lain. Batalkan alokasi lama terlebih dahulu.'];
    }
    $transactionBookingId=trim((string)($oldTx['bookingId'] ?? ''));
    if($transactionBookingId!=='' && $transactionBookingId!==$bookingId){
        return ['status'=>'error','http'=>409,'error'=>'Transaksi sudah terhubung ke booking lain. Perbaiki relasi transaksi sebelum membuat rincian baru.'];
    }
    // Transaksi yang sejak awal sudah tertaut ke booking adalah penerimaan/pembayaran
    // booking asal. Nominalnya tidak boleh dikuras menjadi extra/perpanjangan melalui
    // allocation. Allocation hanya untuk receipt manual generik yang belum tertaut;
    // setelah allocation pertama, link booking dikenali lewat flag workflow di atas.
    if($transactionBookingId!=='' && empty($allocationState['workflow_booking_link_added'])){
        return [
            'status'=>'error','http'=>409,
            'error'=>'Transaksi ini sudah merupakan pembayaran booking asal dan tidak boleh dialokasikan ulang. Tambahkan layanan dari menu Pemesanan Kamar; bila layanan dibayar, catat sebagai transaksi pembayaran baru.'
        ];
    }
    if (transactionIsProtected($oldTx)) return ['status'=>'error','http'=>409,'error'=>'Transaksi otomatis tidak dapat dialokasikan ulang. Gunakan modul sumber atau koreksi audit.'];
    // Simpan jejak kolom yang diisi otomatis oleh alokasi pertama. Bila seluruh
    // alokasi dibatalkan, hanya kolom yang memang ditambahkan workflow ini yang
    // dikembalikan; nilai manual/orisinal pengguna tidak disentuh.
    $transactionBookingLinkAdded=(!$reportingOnlyHistoricalAllocation && $transactionBookingId==='')?1:0;
    $transactionRoomLinkAdded=(!$reportingOnlyHistoricalAllocation && trim((string)($oldTx['roomNumber'] ?? ''))==='')?1:0;
    $transactionSourceLinkAdded=(!$reportingOnlyHistoricalAllocation && trim((string)($oldTx['bookingSource'] ?? ''))==='')?1:0;

    $allocatedTotal=(float)$allocationState['allocated_amount'];
    $available=round(max(0,(float)$oldTx['amount']-$allocatedTotal),2);
    if ($amount > $available + 0.01) {
        return ['status'=>'error','http'=>409,'error'=>'Nominal alokasi melebihi sisa transaksi. Sisa yang dapat dialokasikan Rp '.number_format($available,0,',','.') . '.'];
    }

    $parseTimestamp=static function($value){if(!$value)return 0;$ts=strtotime((string)$value);return $ts===false?0:$ts;};
    $bookingBaseTs=$parseTimestamp($input['bookingBaseUpdatedAt'] ?? null);
    $txBaseTs=$parseTimestamp($input['transactionBaseUpdatedAt'] ?? null);
    $hasConflict=(($bookingBaseTs>0 && $parseTimestamp($booking['updatedAt'] ?? null)>$bookingBaseTs+1)
        || ($txBaseTs>0 && $parseTimestamp($oldTx['updatedAt'] ?? null)>$txBaseTs+1));
    if($hasConflict){
        if($resolutionChoice==='server')return ['status'=>'server','operationId'=>$operationId,'transactionId'=>$transactionId,'bookingId'=>$bookingId];
        // Keuangan tidak boleh memakai pilihan "pakai lokal" untuk menimpa
        // booking/receipt yang sudah berubah di server. Konflik wajib ditinjau.
        return ['status'=>'conflict','operationId'=>$operationId,'conflict'=>[
            'id'=>'booking_allocation:'.$operationId,'entityType'=>'booking_allocation','entityId'=>$operationId,
            'localData'=>$input,'serverData'=>['booking'=>$booking,'transaction'=>$oldTx],
            'message'=>'Booking atau transaksi berubah di server sebelum alokasi diterapkan.'
        ]];
    }

    $bookingSource=trim((string)($booking['bookingSource'] ?? $input['bookingSource'] ?? 'Direct')) ?: 'Direct';
    $roomNumber=(string)$booking['roomNumber'];
    $transactionRoomNumber=trim((string)($oldTx['roomNumber'] ?? ''));
    if($transactionRoomNumber!=='' && $roomNumber!=='' && $transactionRoomNumber!==$roomNumber){
        return ['status'=>'error','http'=>409,'error'=>'Nomor kamar transaksi ('.$transactionRoomNumber.') berbeda dengan reservasi tujuan ('.$roomNumber.'). Koreksi kamar transaksi atau pilih reservasi yang benar sebelum membuat rincian.'];
    }
    $serviceDate=trim((string)($input['serviceDate'] ?? $oldTx['serviceDate'] ?? $date));
    if(!validIsoDate($serviceDate))$serviceDate=$date;
    $checkIn=trim((string)($booking['checkIn'] ?? ''));
    $checkOut=trim((string)($booking['checkOut'] ?? ''));
    $dateWithinStay=($serviceDate!=='' && ($checkIn==='' || $checkIn<=$serviceDate) && ($checkOut==='' || $serviceDate<=$checkOut));
    if(in_array($bookingAction,['extra','extension'],true) && !$dateWithinStay){
        $confirmed=$historicalTransaction && !empty($input['historicalDateMismatchConfirmed']);
        $linkReason=trim((string)($input['historicalLinkReason'] ?? ''));
        if(!$confirmed || strlen($linkReason)<12){
            return ['status'=>'error','http'=>409,'error'=>'Tanggal layanan '.$serviceDate.' tidak berada dalam periode reservasi '.$checkIn.' s/d '.$checkOut.'. Untuk data historis, pilih manual setelah memeriksa bukti dan konfirmasi perbedaan tanggal.'];
        }
    }
    $manualBookingLinkConfirmed=!empty($input['manualBookingLinkConfirmed']);
    if($transactionBookingId==='' && !$manualBookingLinkConfirmed){
        return ['status'=>'error','http'=>409,'error'=>'Hubungan transaksi manual ke reservasi belum dikonfirmasi. Periksa nama tamu, kamar, tanggal layanan, dan periode menginap lalu konfirmasi ulang.'];
    }
    // Audit summary dibangun ulang dari baris transaksi/reservasi yang sudah dikunci.
    // Jangan mempercayai ringkasan teks dari browser sebagai bukti identitas.
    $manualBookingLinkSummary=$transactionBookingId==='' ? sprintf(
        'Transaksi %s; %s; layanan %s; booking %s; tamu %s; kamar %s; periode %s s/d %s',
        $transactionId,
        trim((string)($oldTx['description'] ?? '')) ?: '-',
        $serviceDate,
        $bookingId,
        trim((string)($booking['guestName'] ?? '')) ?: '-',
        $roomNumber ?: '-',
        $checkIn ?: '-',
        $checkOut ?: '-'
    ) : '';
    $description=trim((string)($input['description'] ?? ''));
    $historicalLinkReason=trim((string)($input['historicalLinkReason'] ?? ''));
    if($historicalLinkReason!=='' && $historicalTransaction){
        $description=trim($description.' | Verifikasi relasi historis: '.$historicalLinkReason);
    }
    $category=trim((string)($input['category'] ?? ''));
    $categoryId=trim((string)($input['categoryId'] ?? ''));
    $subcategory=trim((string)($input['subcategory'] ?? ''));
    $subcategoryId=trim((string)($input['subcategoryId'] ?? ''));
    $taxKind=$bookingAction==='room'?'room':$bookingAction;
    if($historicalTransaction){
        try{
            $tax=tamasyaResolveBookingAllocationTaxSnapshot($pdo,$oldTx,$booking,$bookingAction,$amount,$reportingOnlyHistoricalAllocation);
        }catch(Throwable $taxError){
            return ['status'=>'error','http'=>tamasyaExceptionHttpStatus($taxError,422),'error'=>clientExceptionMessage('Validasi PBJT rincian historical_import gagal',$taxError)];
        }
    }else{
        $preexistingTax=null;
        if($bookingAction==='extra'){
            $requestedPreexistingId=trim((string)($input['bookingExtraId'] ?? (($input['extra']['id']??''))));
            if($requestedPreexistingId!==''){
                $preExtras=!empty($booking['extras'])?json_decode((string)$booking['extras'],true):[];
                if(is_array($preExtras))foreach($preExtras as $preExtra){
                    if((string)($preExtra['id']??'')!==$requestedPreexistingId)continue;
                    if(is_numeric($preExtra['baseAmount']??null)&&is_numeric($preExtra['taxAmount']??null)&&is_numeric($preExtra['taxRate']??null)){
                        $preBase=round((float)$preExtra['baseAmount'],2);$preTax=round((float)$preExtra['taxAmount'],2);
                        if($preBase>=0&&$preTax>=0&&moneyMatches($preBase+$preTax,$amount,1.0)){
                            $preTax=min($amount,$preTax);
                            $preexistingTax=['baseAmount'=>round(max(0.0,$amount-$preTax),2),'taxAmount'=>$preTax,'taxRate'=>(float)$preExtra['taxRate'],
                                'taxSnapshotStatus'=>'confirmed','taxSource'=>'booking_extra_snapshot','taxRuleId'=>trim((string)($preExtra['taxRuleId']??''))?:null];
                        }
                    }
                    break;
                }
            }
        }
        if($preexistingTax!==null){
            $tax=$preexistingTax;
        }else{
            $rule=resolveConfiguredTaxRule($pdo,$bookingSource,$taxKind,$date);
            if(empty($rule['matched'])){
                return ['status'=>'error','http'=>409,'error'=>'Alokasi transaksi live ditolak karena tidak ada Aturan Pajak aktif untuk sumber '.$bookingSource.', jenis '.$taxKind.', tanggal '.$date.'. Rule 0%/tidak kena pajak harus dibuat eksplisit.'];
            }
            $breakdown=calculateInclusiveTaxBreakdown($amount,(float)$rule['rate']);
            $tax=$breakdown+['taxSnapshotStatus'=>'confirmed','taxSource'=>'live_rule','taxRuleId'=>$rule['ruleId']??null,'taxRuleName'=>$rule['ruleName']??null];
        }
    }
    $allocationId=generateServerId('alloc');
    $bookingExtraId=null;
    $bookingTotalDelta=0.0;
    $previousCheckOut=null;
    $appliedNewCheckOut=null;
    $smartLockRefreshJobId=null;
    $extraWasExisting=0;
    $increaseBookingTotal=$reportingOnlyHistoricalAllocation
        ? false
        : (array_key_exists('increaseBookingTotal',$input)
            ? !empty($input['increaseBookingTotal'])
            : in_array($bookingAction,['extension','extra'],true));
    if ($bookingAction === 'extension' && !$increaseBookingTotal && !$reportingOnlyHistoricalAllocation) {
        return ['status'=>'error','http'=>409,'error'=>'Perpanjangan baru wajib menambah total tagihan booking. Koreksi arsip lama harus melalui workflow audit, bukan alokasi receipt.'];
    }

    if($bookingAction==='room'){
        $roomRoot=tamasyaRequireSystemFinanceCategory($pdo,'room_rental','income',true);
        $category=(string)$roomRoot['name'];$categoryId=(string)$roomRoot['id'];
        // Room type is an operational dimension, not a Finance subcategory.
        $subcategory='';$subcategoryId='';
        if(trim((string)($booking['roomType'] ?? ''))==='') return ['status'=>'error','http'=>409,'error'=>'Tipe kamar booking kosong. Perbaiki data booking sebelum alokasi pembayaran kamar.'];
        if($description==='')$description=$reportingOnlyHistoricalAllocation
            ? "Arsip pembayaran kamar {$roomNumber} - {$booking['guestName']} (reservasi dibatalkan)"
            : "Alokasi pembayaran kamar {$roomNumber} - {$booking['guestName']}";
    }elseif($bookingAction==='extension'){
        $roomRoot=tamasyaRequireSystemFinanceCategory($pdo,'room_rental','income',true);
        $category=(string)$roomRoot['name'];$categoryId=(string)$roomRoot['id'];
        $subcategory='';$subcategoryId='';
        if(trim((string)($booking['roomType'] ?? ''))==='') return ['status'=>'error','http'=>409,'error'=>'Tipe kamar booking kosong. Perbaiki data booking sebelum alokasi perpanjangan.'];
        if($description==='')$description=$reportingOnlyHistoricalAllocation
            ? "Arsip perpanjangan Kamar {$roomNumber} - {$booking['guestName']} (reservasi dibatalkan)"
            : "Alokasi perpanjangan Kamar {$roomNumber} - {$booking['guestName']}";
        if(!$reportingOnlyHistoricalAllocation){
            $newCheckOutDate=trim((string)($input['newCheckOutDate'] ?? ''));
            $previousCheckOut=(string)($booking['checkOut'] ?? '');
            if($newCheckOutDate!=='' && (!validIsoDate($newCheckOutDate) || strtotime($newCheckOutDate)<=strtotime((string)$booking['checkOut']))){
                return ['status'=>'error','http'=>400,'error'=>'Tanggal check-out baru harus lebih lambat dari tanggal sebelumnya.'];
            }
            if($newCheckOutDate!=='' && $updatedSource==='offline-sync'){
                return ['status'=>'error','http'=>409,'error'=>'Perpanjangan tanggal menginap wajib online agar overlap reservasi, checkoutDueAt, dan smart-lock diproses melalui workflow canonical.'];
            }
            $sets=[];$params=[];
            if($newCheckOutDate!==''){
                $stayExtension=tamasyaR3ExtendStay($pdo,(array)$loggedInStaff,$bookingId,$newCheckOutDate,$updatedSource.'-extension');
                $smartLockRefreshJobId=$stayExtension['smartLockRefreshJobId']??null;
                $appliedNewCheckOut=$newCheckOutDate;
            }
            if($increaseBookingTotal){
                $oldGross=max(0.0,(float)($booking['totalAmount']??0));
                $oldRate=is_numeric($booking['vatRate']??null)?(float)$booking['vatRate']:null;
                $componentRate=(float)$tax['taxRate'];
                $newAggregateRate=$oldGross<=0.0001?$componentRate:(($oldRate!==null&&abs($oldRate-$componentRate)<0.0001)?$oldRate:null);
                $sets[]='totalAmount=totalAmount+?';$params[]=$amount;
                $sets[]='vatAmount=COALESCE(vatAmount,0)+?';$params[]=$tax['taxAmount'];
                $sets[]='vatRate=?';$params[]=$newAggregateRate;
                $bookingTotalDelta=$amount;
            }
            if($sets){$sets[]='version=version+1';$sets[]='updatedAt=CURRENT_TIMESTAMP';$sets[]='updatedBy=?';$params[]=$loggedInStaff['id'] ?? null;$sets[]='updatedSource=?';$params[]=$updatedSource;$params[]=$bookingId;
                $pdo->prepare('UPDATE bookings SET '.implode(',',$sets).' WHERE id=?')->execute($params);}
        }
    }else{
        $extra=is_array($input['extra'] ?? null)?$input['extra']:[];
        $name=trim((string)($extra['name'] ?? '')) ?: 'Layanan tambahan';
        $qty=max(1,(int)($extra['qty'] ?? 1));
        $unitPrice=round((float)($extra['price'] ?? ((float)$tax['baseAmount']/$qty)),2);
        // Guest-service revenue always resolves its parent by immutable semantic key.
        // Product/service names themselves remain property-owned catalog data.
        $extraRoot=tamasyaRequireSystemFinanceCategory($pdo,'extra_service','income',true);
        $category=(string)$extraRoot['name'];$categoryId=(string)$extraRoot['id'];
        $subcategory=$subcategory!==''?$subcategory:(trim((string)($extra['subcategory']??''))?:'');
        $subcategoryId=$subcategoryId!==''?$subcategoryId:trim((string)($extra['subcategoryId']??''));
        if($description==='')$description=$reportingOnlyHistoricalAllocation
            ? "Arsip {$name} Kamar {$roomNumber} - {$booking['guestName']} ({$qty}x; reservasi dibatalkan)"
            : "Alokasi {$name} Kamar {$roomNumber} - {$booking['guestName']} ({$qty}x)";
        if(!$reportingOnlyHistoricalAllocation){
            $extras=!empty($booking['extras'])?json_decode((string)$booking['extras'],true):[];
            if(!is_array($extras))$extras=[];
            $requestedExtraId=trim((string)($input['bookingExtraId'] ?? $extra['id'] ?? ''));
            $foundIndex=null;
            if($requestedExtraId!=='')foreach($extras as $i=>$existingExtra){if((string)($existingExtra['id'] ?? '')===$requestedExtraId){$foundIndex=$i;break;}}
            if($foundIndex!==null){
                $bookingExtraId=(string)$extras[$foundIndex]['id'];
                $storedExtra=$extras[$foundIndex];
                $storedCategory=trim((string)($storedExtra['category']??''));
                $storedCategoryId=trim((string)($storedExtra['categoryId']??$storedExtra['category_id']??''));
                $storedSubcategory=trim((string)($storedExtra['subcategory']??''));
                $storedSubcategoryId=trim((string)($storedExtra['subcategoryId']??$storedExtra['subcategory_id']??''));
                if($storedCategory!=='') $category=$storedCategory;
                if($storedCategoryId!=='') $categoryId=$storedCategoryId;
                if($storedSubcategory!=='') $subcategory=$storedSubcategory;
                if($storedSubcategoryId!=='') $subcategoryId=$storedSubcategoryId;
                // Existing folio extras (especially POS room-charge) already own an
                // immutable tax snapshot. Payment allocation must reuse it instead
                // of recalculating with today's rule.
                if(is_numeric($storedExtra['baseAmount']??null) && is_numeric($storedExtra['taxAmount']??null) && is_numeric($storedExtra['taxRate']??null)){
                    $storedBase=round((float)$storedExtra['baseAmount'],2);
                    $storedTax=round((float)$storedExtra['taxAmount'],2);
                    if($storedBase>=0 && $storedTax>=0 && moneyMatches($storedBase+$storedTax,$amount,1.0)){
                        $storedTax=min($amount,$storedTax);
                        $tax=[
                            'baseAmount'=>round(max(0.0,$amount-$storedTax),2),
                            'taxAmount'=>$storedTax,
                            'taxRate'=>(float)$storedExtra['taxRate'],
                            'taxSnapshotStatus'=>'confirmed','taxSource'=>'booking_extra_snapshot','taxRuleId'=>trim((string)($storedExtra['taxRuleId']??''))?:null,
                        ];
                    }
                }
                if(!empty($extras[$foundIndex]['sourceTransactionId']) || strtolower((string)($extras[$foundIndex]['paymentStatus'] ?? 'unpaid'))==='paid'){
                    return ['status'=>'error','http'=>409,'error'=>'Layanan booking tersebut sudah mempunyai pembayaran aktif.'];
                }
                $expectedExtraAmount=isset($extras[$foundIndex]['total'])&&is_numeric($extras[$foundIndex]['total'])
                    ? round((float)$extras[$foundIndex]['total'],2)
                    : round((float)($extras[$foundIndex]['price'] ?? 0)*max(1,(int)($extras[$foundIndex]['qty'] ?? 1)),2);
                if($expectedExtraAmount>0 && !moneyMatches($expectedExtraAmount,$amount)){
                    return ['status'=>'error','http'=>409,'error'=>'Nominal alokasi harus sama dengan tagihan layanan yang dipilih: Rp '.number_format($expectedExtraAmount,0,',','.') . '.'];
                }
                $extraWasExisting=1;
                $extras[$foundIndex]['paymentStatus']='paid';
                $extras[$foundIndex]['sourceTransactionId']=$transactionId;
                $extras[$foundIndex]['sourceAllocationId']=$allocationId;
                $extras[$foundIndex]['paymentOperationId']=$operationId;
                $extras[$foundIndex]['paidAt']=date('c');
                $extras[$foundIndex]['total']=$expectedExtraAmount;
                if(!isset($extras[$foundIndex]['baseAmount']))$extras[$foundIndex]['baseAmount']=(float)$tax['baseAmount'];
                if(!isset($extras[$foundIndex]['taxAmount']))$extras[$foundIndex]['taxAmount']=(float)$tax['taxAmount'];
                if(!isset($extras[$foundIndex]['taxRate']))$extras[$foundIndex]['taxRate']=$tax['taxRate'];
                $increaseBookingTotal=false;
            }else{
                // Extra baru selalu merupakan tagihan baru. Browser tidak boleh membuat
                // layanan paid tanpa menaikkan total booking; itu membuat room charge
                // turun dan laporan seolah pembayaran berlebih.
                $increaseBookingTotal=true;
                $bookingExtraId=$requestedExtraId!==''?$requestedExtraId:generateServerId('ex');
                $extras[]=[
                    'id'=>$bookingExtraId,'name'=>$name,'price'=>$unitPrice,'qty'=>$qty,'total'=>$amount,
                    'baseAmount'=>(float)$tax['baseAmount'],'taxAmount'=>(float)$tax['taxAmount'],'taxRate'=>$tax['taxRate'],
                    'category'=>$category,'categoryId'=>$categoryId?:null,'categorySystemKey'=>'extra_service',
                    'subcategory'=>$subcategory?:null,'subcategoryId'=>$subcategoryId?:null,
                    'createdAt'=>(string)($extra['createdAt'] ?? date('c')),'paymentStatus'=>'paid',
                    'sourceTransactionId'=>$transactionId,'sourceAllocationId'=>$allocationId,'paymentOperationId'=>$operationId,'paidAt'=>date('c')
                ];
            }
            $bookingTotalDelta=$increaseBookingTotal?$amount:0.0;
            $oldGross=max(0.0,(float)($booking['totalAmount']??0));
            $newTotal=$oldGross+$bookingTotalDelta;
            $newVat=(float)($booking['vatAmount'] ?? 0)+($increaseBookingTotal?(float)$tax['taxAmount']:0);
            $oldRate=is_numeric($booking['vatRate']??null)?(float)$booking['vatRate']:null;
            $componentRate=(float)$tax['taxRate'];
            $newAggregateRate=$increaseBookingTotal
                ? ($oldGross<=0.0001?$componentRate:(($oldRate!==null&&abs($oldRate-$componentRate)<0.0001)?$oldRate:null))
                : $oldRate;
            $pdo->prepare("UPDATE bookings SET extras=?,totalAmount=?,vatAmount=?,vatRate=?,version=version+1,updatedAt=CURRENT_TIMESTAMP,updatedBy=?,updatedSource=? WHERE id=?")
                ->execute([json_encode($extras,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$newTotal,$newVat,$newAggregateRate,$loggedInStaff['id'] ?? null,$updatedSource,$bookingId]);
        }
    }

    $allocationCatalog=tamasyaResolveFinanceCatalogSelection($pdo,[
        'type'=>'income','categoryId'=>$categoryId?:null,'category'=>$category,
        'subcategoryId'=>$subcategoryId?:null,'subcategory'=>$subcategory?:null,
    ],true,true);
    $category=$allocationCatalog['categoryName'];$categoryId=$allocationCatalog['categoryId'];$categorySystemKey=$allocationCatalog['categorySystemKey']?:null;
    $subcategory=$allocationCatalog['subcategoryName'];$subcategoryId=$allocationCatalog['subcategoryId'];$subcategorySystemKey=$allocationCatalog['subcategorySystemKey']?:null;

    $insertAllocation=$pdo->prepare("INSERT INTO transaction_allocations
        (id,operation_id,transaction_id,booking_id,allocation_type,amount,base_amount,tax_amount,tax_rate,tax_snapshot_status,tax_source,tax_rule_id,
         category,category_id,category_system_key,subcategory,subcategory_id,subcategory_system_key,booking_extra_id,description,
         booking_total_delta,previous_check_out,new_check_out,extra_was_existing,reporting_only,transaction_booking_link_added,transaction_room_link_added,
         transaction_source_link_added,status,created_by,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?,CURRENT_TIMESTAMP)");
    $insertAllocation->execute([
        $allocationId,$operationId,$transactionId,$bookingId,$bookingAction,$amount,$tax['baseAmount'],$tax['taxAmount'],$tax['taxRate'],
        $tax['taxSnapshotStatus']??'unresolved',$tax['taxSource']??null,$tax['taxRuleId']??null,
        $category?:null,$categoryId?:null,$categorySystemKey,$subcategory?:null,$subcategoryId?:null,$subcategorySystemKey,$bookingExtraId,$description?:null,$bookingTotalDelta,$previousCheckOut?:null,$appliedNewCheckOut?:null,$extraWasExisting,
        $reportingOnlyHistoricalAllocation?1:0,$transactionBookingLinkAdded,$transactionRoomLinkAdded,$transactionSourceLinkAdded,$loggedInStaff['id'] ?? null
    ]);

    // Preserve cash identity and the source cash row. A reporting-only archive only bumps its
    // version so projections refresh; even booking/room/source metadata remains
    // untouched. Normal active allocations may add missing linkage metadata but
    // never overwrite an existing value.
    if($reportingOnlyHistoricalAllocation){
        tamasyaTouchTransactionAllocationMetadata($pdo,$transactionId,$loggedInStaff,$updatedSource);
    }else{
        tamasyaFillTransactionAllocationMetadata($pdo,$transactionId,$bookingId,$bookingSource,$roomNumber,$loggedInStaff,$updatedSource);
    }
    $allocationTaxReconciliation=null;
    if(!$reportingOnlyHistoricalAllocation){
        $allocationTaxReconciliation=tamasyaReconcileTransactionTaxFromAllocations($pdo,$transactionId,$loggedInStaff,$updatedSource.'-allocation-tax');
        if($historicalTransaction && !empty($allocationTaxReconciliation['changed'])){
            tamasyaRegisterHistoricalBackfillEditAdjustments(
                $pdo,
                (array)($allocationTaxReconciliation['before']??$oldTx),
                (array)($allocationTaxReconciliation['after']??$oldTx),
                $loggedInStaff,
                'Reklasifikasi PBJT historical_import berdasarkan rincian '.$bookingAction.' booking '.$bookingId,
                'alloc_tax_'.substr(hash('sha256',$operationId),0,40)
            );
        }
        recalculateBookingFinancials($pdo,$bookingId,true);
        assertBookingLedgerInvariant($pdo,$bookingId,(array)$loggedInStaff,$updatedSource,false);
    }
    $txAfterAllocationStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1");
    $txAfterAllocationStmt->execute([$transactionId]);
    $txAfterAllocation=$txAfterAllocationStmt->fetch(PDO::FETCH_ASSOC)?:null;
    $bookingAfterAllocationStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
    $bookingAfterAllocationStmt->execute([$bookingId]);
    $bookingAfterAllocation=$bookingAfterAllocationStmt->fetch(PDO::FETCH_ASSOC)?:null;
    $allocationAuditBefore=[
        'transaction'=>tamasyaTransactionAuditSnapshot($oldTx),
        'bookingTotal'=>(float)($booking['totalAmount']??0),
        'bookingCheckOut'=>$booking['checkOut']??null,
    ];
    $allocationAuditAfter=[
        'transaction'=>tamasyaTransactionAuditSnapshot($txAfterAllocation),
        'allocation'=>[
            'id'=>$allocationId,'operationId'=>$operationId,'action'=>$bookingAction,'amount'=>$amount,
            'category'=>$category?:null,'subcategory'=>$subcategory?:null,'description'=>$description?:null,
            'bookingId'=>$bookingId,'roomNumber'=>$roomNumber,'bookingExtraId'=>$bookingExtraId,
            'bookingTotalDelta'=>$bookingTotalDelta,'reportingOnly'=>$reportingOnlyHistoricalAllocation,'smartLockRefreshJobId'=>$smartLockRefreshJobId,
        ],
        'booking'=>[
            'id'=>$bookingId,'roomNumber'=>$bookingAfterAllocation['roomNumber']??$roomNumber,
            'totalAmount'=>(float)($bookingAfterAllocation['totalAmount']??0),
            'checkOut'=>$bookingAfterAllocation['checkOut']??null,
            'extrasGross'=>tamasyaBookingExtrasTotal($bookingAfterAllocation['extras']??null),
        ],
        'auditContext'=>[
            'reason'=>$description?:('Merinci receipt manual sebagai '.$bookingAction),
            'manualBookingLinkSummary'=>$manualBookingLinkSummary?:null,
            'cashDelta'=>0,
            'sourceCashAmount'=>$cashTransactionAmount,
        ],
    ];
    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Merinci transaksi manual pada booking','transaction_allocation',$allocationId,$allocationAuditBefore,$allocationAuditAfter,$updatedSource);
    logActivity($pdo,'allocate_manual_transaction',
        ($reportingOnlyHistoricalAllocation?'Menyimpan arsip laporan ':'Mengalokasikan ').'Rp '.number_format($amount,0,',','.').' dari transaksi '.$transactionId.' sebagai '.$bookingAction.' untuk booking '.$bookingId,
        (string)($loggedInStaff['id'] ?? ''),currentStaffLabel($loggedInStaff));
    $notifId=generateServerId('n');
    $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'finance')")
        ->execute([$notifId,$reportingOnlyHistoricalAllocation
            ? 'Arsip transaksi kas '.$transactionId.' dirinci sebagai '.$bookingAction.' Kamar '.$roomNumber.' untuk laporan; reservasi cancelled tidak diubah.'
            : 'Transaksi kas '.$transactionId.' dialokasikan sebagai '.$bookingAction.' Kamar '.$roomNumber.' tanpa menimpa transaksi asli.']);

    return [
        'status'=>'applied','operationId'=>$operationId,'allocationId'=>$allocationId,'transactionId'=>$transactionId,
        'bookingId'=>$bookingId,'roomNumber'=>$roomNumber,'guestName'=>$booking['guestName'],'action'=>$bookingAction,
        'label'=>$bookingAction==='extra'?'layanan extra':($bookingAction==='extension'?'perpanjangan':'pembayaran kamar'),
        'reportingOnly'=>$reportingOnlyHistoricalAllocation,
        'amount'=>$amount,
        'cashTransactionAmount'=>$cashTransactionAmount,
        'cashDelta'=>0.0,
        'bookingTotalBefore'=>$bookingTotalBefore,
        'bookingTotalDelta'=>round($bookingTotalDelta,2),
        'bookingTotalAfter'=>round($bookingTotalBefore+$bookingTotalDelta,2),
        'smartLockRefreshJobId'=>$smartLockRefreshJobId,
        'remainingAmount'=>round(max(0,$available-$amount),2),'tax'=>$tax
    ];
}

/**
 * Void a booking allocation without deleting or rewriting the source cash row.
 * Any booking mutation created by the allocation is reversed atomically.
 */
function voidTransactionAllocation(PDO $pdo, array $loggedInStaff, array $input, string $updatedSource='web'): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'pembatalan alokasi transaksi');
    if(!$pdo->inTransaction()) throw new RuntimeException('Pembatalan alokasi wajib berada dalam transaksi database.');
    $allocationId=trim((string)($input['allocationId']??''));
    $reason=trim((string)($input['reason']??''));
    $confirmation=trim((string)($input['confirmation']??''));
    $operationId=substr(trim((string)($input['operationId']??'')),0,100);
    if($allocationId==='' || $operationId==='' || tamasyaStringLength($reason)<8 || $confirmation!=='BATALKAN ALOKASI'){
        return ['status'=>'error','http'=>400,'error'=>'ID alokasi, operationId, alasan minimal 8 karakter, dan konfirmasi BATALKAN ALOKASI wajib diisi.'];
    }

    $stmt=$pdo->prepare("SELECT * FROM transaction_allocations WHERE id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$allocationId]);
    $allocation=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$allocation)return ['status'=>'error','http'=>404,'error'=>'Alokasi transaksi tidak ditemukan.'];
    if(strtolower((string)($allocation['status']??''))==='voided'){
        return ['status'=>'duplicate','allocationId'=>$allocationId,'transactionId'=>$allocation['transaction_id'],'bookingId'=>$allocation['booking_id']];
    }

    $txStmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
    $txStmt->execute([(string)$allocation['transaction_id']]);
    $tx=$txStmt->fetch(PDO::FETCH_ASSOC);
    $bookingStmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");
    $bookingStmt->execute([(string)$allocation['booking_id']]);
    $booking=$bookingStmt->fetch(PDO::FETCH_ASSOC);
    if(!$tx || !$booking)return ['status'=>'error','http'=>409,'error'=>'Transaksi atau booking sumber alokasi tidak lagi tersedia.'];

    $allocationType=strtolower((string)($allocation['allocation_type']??''));
    $reportingOnly=!empty($allocation['reporting_only']);
    $delta=max(0,(float)($allocation['booking_total_delta']??0));
    $taxDelta=max(0,(float)($allocation['tax_amount']??0));
    $sets=[];$params=[];
    $smartLockRefreshJobId=null;

    if(!$reportingOnly){
        if($allocationType==='extension'){
            $newCheckOut=(string)($allocation['new_check_out']??'');
            $previousCheckOut=(string)($allocation['previous_check_out']??'');
            if($newCheckOut!=='' && (string)($booking['checkOut']??'')!==$newCheckOut){
                return ['status'=>'error','http'=>409,'error'=>'Tanggal checkout booking sudah berubah setelah alokasi. Koreksi booking tersebut lebih dahulu agar histori tidak ditimpa.'];
            }
            if($newCheckOut!=='' && $previousCheckOut!==''){
                if(!in_array(strtolower((string)($booking['status']??'')),['reserved','active'],true))return ['status'=>'error','http'=>409,'error'=>'Perpanjangan pada booking terminal tidak boleh dibalik melalui void alokasi. Gunakan Koreksi Audit agar histori checkout tetap utuh.'];
                $reversal=tamasyaR3ChangeStayEnd($pdo,$loggedInStaff,(string)$booking['id'],$previousCheckOut,$updatedSource.'-allocation-void',true);
                $smartLockRefreshJobId=$reversal['smartLockRefreshJobId']??null;
            }
        }elseif($allocationType==='extra'){
            $extras=tamasyaDecodeBookingExtras($booking['extras']??null);
            $extraId=(string)($allocation['booking_extra_id']??'');
            $found=false;
            foreach($extras as $index=>&$extra){
                if((string)($extra['id']??'')!==$extraId)continue;
                $found=true;
                if(!empty($allocation['extra_was_existing'])){
                    if((string)($extra['sourceAllocationId']??'')!==$allocationId){
                        return ['status'=>'error','http'=>409,'error'=>'Layanan tersebut sudah dikaitkan dengan pembayaran lain.'];
                    }
                    $extra['paymentStatus']='unpaid';
                    $extra['sourceTransactionId']=null;
                    $extra['sourceAllocationId']=null;
                    $extra['paymentOperationId']=null;
                    $extra['paidAt']=null;
                }else{
                    if((string)($extra['sourceAllocationId']??'')!==$allocationId){
                        return ['status'=>'error','http'=>409,'error'=>'Rincian layanan sudah berubah dan tidak aman dibatalkan otomatis.'];
                    }
                    array_splice($extras,$index,1);
                }
                break;
            }
            unset($extra);
            if(!$found)return ['status'=>'error','http'=>409,'error'=>'Rincian layanan sumber alokasi tidak ditemukan pada booking.'];
            $sets[]='extras=?';
            $params[]=json_encode($extras,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }elseif($allocationType!=='room'){
            return ['status'=>'error','http'=>409,'error'=>'Jenis alokasi tidak didukung untuk pembatalan.'];
        }

        if($delta>0){
            $sets[]='totalAmount=?';
            $params[]=max(0,(float)$booking['totalAmount']-$delta);
            $sets[]='vatAmount=?';
            $params[]=max(0,(float)($booking['vatAmount']??0)-$taxDelta);
        }
        if($sets){
            $sets[]='version=version+1';
            $sets[]='updatedAt=CURRENT_TIMESTAMP';
            $sets[]='updatedBy=?';$params[]=$loggedInStaff['id']??null;
            $sets[]='updatedSource=?';$params[]=$updatedSource;
            $params[]=(string)$booking['id'];
            $pdo->prepare('UPDATE bookings SET '.implode(',',$sets).' WHERE id=?')->execute($params);
        }
    }

    $pdo->prepare("UPDATE transaction_allocations SET status='voided',voided_by=?,voided_at=CURRENT_TIMESTAMP,void_reason=? WHERE id=? AND status='active'")
        ->execute([$loggedInStaff['id']??null,$reason,$allocationId]);

    $remainingAllocationState=tamasyaActiveTransactionAllocationState($pdo,(string)$tx['id'],true);
    $restoreBookingLink=false;
    $restoreRoomLink=false;
    $restoreSourceLink=false;
    if((int)$remainingAllocationState['booking_count']===0){
        $linkStmt=$pdo->prepare("SELECT
            MAX(transaction_booking_link_added) AS booking_link_added,
            MAX(transaction_room_link_added) AS room_link_added,
            MAX(transaction_source_link_added) AS source_link_added
            FROM transaction_allocations WHERE transaction_id=?");
        $linkStmt->execute([(string)$tx['id']]);
        $linkState=$linkStmt->fetch(PDO::FETCH_ASSOC)?:[];
        $restoreBookingLink=!empty($linkState['booking_link_added']);
        $restoreRoomLink=!empty($linkState['room_link_added']);
        $restoreSourceLink=!empty($linkState['source_link_added']);
    }
    $clearFields=[];
    if($restoreBookingLink)$clearFields[]='bookingId';
    if($restoreRoomLink)$clearFields[]='roomNumber';
    if($restoreSourceLink)$clearFields[]='bookingSource';
    tamasyaClearTransactionAllocationMetadata($pdo,(string)$tx['id'],$clearFields,$loggedInStaff,$updatedSource);
    if(!$reportingOnly){
        $voidTaxReconciliation=tamasyaReconcileTransactionTaxFromAllocations($pdo,(string)$tx['id'],$loggedInStaff,$updatedSource.'-allocation-void-tax');
        if(tamasyaTransactionIsHistorical($tx) && !empty($voidTaxReconciliation['changed'])){
            tamasyaRegisterHistoricalBackfillEditAdjustments(
                $pdo,
                (array)($voidTaxReconciliation['before']??$tx),
                (array)($voidTaxReconciliation['after']??$tx),
                $loggedInStaff,
                'Pembatalan rincian booking mengubah agregat PBJT historical_import',
                'alloc_void_tax_'.substr(hash('sha256',$operationId),0,36)
            );
        }
        recalculateBookingFinancials($pdo,(string)$booking['id'],true);
        assertBookingLedgerInvariant($pdo,(string)$booking['id'],(array)$loggedInStaff,$updatedSource,false);
    }
    logActivity($pdo,'void_transaction_allocation','Membatalkan alokasi '.$allocationId.' dari transaksi '.$tx['id'].'. Alasan: '.$reason,(string)($loggedInStaff['id'] ?? ''),currentStaffLabel($loggedInStaff));
    $notifId='n_alloc_void_'.substr(hash('sha256',$operationId),0,24);
    $pdo->prepare("INSERT INTO notifications(id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'finance') ON DUPLICATE KEY UPDATE id=id")
        ->execute([$notifId,'Alokasi transaksi '.$tx['id'].' untuk Kamar '.$booking['roomNumber'].' dibatalkan. Alasan: '.$reason]);
    return ['status'=>'voided','allocationId'=>$allocationId,'transactionId'=>$tx['id'],'bookingId'=>$booking['id'],'smartLockRefreshJobId'=>$smartLockRefreshJobId];
}
